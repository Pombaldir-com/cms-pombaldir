#!/usr/bin/env python3
"""Decode QR codes from images or PDFs.

This script reads an image file or a PDF and outputs every QR code detected.
The result is printed to stdout and the process exits with code 0 on success.
Non‑zero exit codes indicate failure to read or decode the file.

The detection relies primarily on OpenCV but applies additional image
pre‑processing steps and falls back to ``pyzbar`` when available, improving
the chances of decoding less than ideal scans.

When converting from PDF, the resolution can be controlled with the ``--dpi``
command‑line option (default: 300).
"""
import sys
import os
import re
import json
import argparse
import shutil
from typing import Callable, Dict, List, Optional, Tuple

try:
    from pdf2image import convert_from_path, pdfinfo_from_path
except Exception:
    convert_from_path = None
    pdfinfo_from_path = None

from PIL import Image

Image.MAX_IMAGE_PIXELS = None

import cv2
import numpy as np

try:
    from pyzbar.pyzbar import decode as pyzbar_decode
except Exception:
    pyzbar_decode = None

DETECTOR = cv2.QRCodeDetector()


def _find_poppler_path() -> Optional[str]:
    env_path = os.getenv("POPPLER_PATH")
    if env_path:
        return env_path
    for candidate in ("/usr/bin", "/usr/local/bin", "/opt/homebrew/bin"):
        if os.path.exists(os.path.join(candidate, "pdfinfo")):
            return candidate
    pdfinfo_path = shutil.which("pdfinfo")
    if pdfinfo_path:
        return os.path.dirname(pdfinfo_path)
    return None


def _pdf_page_cache_path(path: str, page: int, dpi: int) -> str:
    return f"{path}.qr-cache-p{page}-d{dpi}.png"


def _prepare_base(image: np.ndarray) -> np.ndarray:
    if image.ndim == 3 and image.shape[2] == 4:
        bgr = image[:, :, :3]
        alpha = image[:, :, 3]
        white = np.full_like(bgr, 255)
        bgr[alpha == 0] = white[alpha == 0]
        image = bgr
    if image.ndim == 3:
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    else:
        gray = image
    gray = cv2.normalize(gray, None, 0, 255, cv2.NORM_MINMAX)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    gray = clahe.apply(gray)
    return gray


def _unsharp(img: np.ndarray, sigma: float = 1.0) -> np.ndarray:
    blurred = cv2.GaussianBlur(img, (0, 0), sigma)
    return cv2.addWeighted(img, 1.5, blurred, -0.5, 0)


def _strategy_clean(img: np.ndarray) -> np.ndarray:
    return img


def _strategy_unsharp(img: np.ndarray) -> np.ndarray:
    return _unsharp(img)


def _strategy_adaptive_threshold_soft(img: np.ndarray) -> np.ndarray:
    return cv2.adaptiveThreshold(
        img, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY, 15, 5
    )


def _strategy_adaptive_threshold_strong(img: np.ndarray) -> np.ndarray:
    return cv2.adaptiveThreshold(
        img, 255, cv2.ADAPTIVE_THRESH_MEAN_C, cv2.THRESH_BINARY, 25, 10
    )


def _strategy_despeckle_unsharp(img: np.ndarray) -> np.ndarray:
    despeckled = cv2.medianBlur(img, 3)
    return _unsharp(despeckled, sigma=1.5)


def _strategy_morph_open(img: np.ndarray) -> np.ndarray:
    kernel = np.ones((3, 3), np.uint8)
    return cv2.morphologyEx(img, cv2.MORPH_OPEN, kernel)


STRATEGIES: Dict[str, Callable[[np.ndarray], np.ndarray]] = {
    "clean": _strategy_clean,
    "unsharp": _strategy_unsharp,
    "adaptive_threshold_soft": _strategy_adaptive_threshold_soft,
    "adaptive_threshold_strong": _strategy_adaptive_threshold_strong,
    "despeckle_unsharp": _strategy_despeckle_unsharp,
    "morph_open": _strategy_morph_open,
}

ANGLES = [0, 5]


def _parse_max_image_pixels(value: str) -> Optional[int]:
    lowered = value.strip().lower()
    if lowered in {"none", "disable", "disabled"}:
        return None
    try:
        limit = int(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(
            "max image pixels must be an integer or 'none'"
        ) from exc
    if limit <= 0:
        raise argparse.ArgumentTypeError("max image pixels must be positive")
    return limit


def _decode_cv(image: np.ndarray) -> List[str]:
    texts: List[str] = []
    gray = image if image.ndim == 2 else cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    data, _, _ = DETECTOR.detectAndDecode(gray)
    if data:
        texts.append(data)
    ok, decoded_info, _, _ = DETECTOR.detectAndDecodeMulti(gray)
    if ok and decoded_info:
        texts.extend([t for t in decoded_info if t])

    if not texts:
        _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        data, _, _ = DETECTOR.detectAndDecode(thresh)
        if data:
            texts.append(data)
        ok, decoded_info, _, _ = DETECTOR.detectAndDecodeMulti(thresh)
        if ok and decoded_info:
            texts.extend([t for t in decoded_info if t])

        if not texts and pyzbar_decode is not None:
            for candidate in (thresh, gray, image):
                try:
                    decoded = pyzbar_decode(candidate)
                except Exception:
                    continue
                for item in decoded:
                    data_bytes = getattr(item, "data", b"")
                    if data_bytes:
                        try:
                            texts.append(data_bytes.decode("utf-8"))
                        except Exception:
                            texts.append(data_bytes.decode("latin-1"))
                if decoded:
                    break

    seen = set()
    unique_texts = [t for t in texts if not (t in seen or seen.add(t))]
    return unique_texts


def _decode_with_strategies(
    image: np.ndarray, max_attempts: int = 12, stop_on_first: bool = False
) -> List[str]:
    base = _prepare_base(image)
    h, w = base.shape[:2]
    min_side = min(h, w)
    # Upscaling so' ajuda imagens pequenas (QR com poucos pixeis). Em paginas grandes
    # (ex.: A4 @300dpi ~ 2480x3508) reescalar para 1.5x/2x e' contraproducente e MUITO
    # lento (cada detectAndDecodeMulti cresce com a area). Limita as escalas ao util.
    if min_side < 900:
        boost = 1200 / max(1, min_side)
        scales = sorted({round(min(3.5, boost * m), 2) for m in (1.0, 1.5, 2.0)})
    elif min_side < 1500:
        scales = [1.0, 1.5]
    else:
        scales = [1.0]

    attempts = 0
    results: List[str] = []
    for scale in scales:
        # Evita reescalar quando scale==1.0 (caso mais comum): poupa uma copia/resize
        # de uma imagem potencialmente enorme (A4 @300dpi ~ 2480x3508).
        if abs(scale - 1.0) < 1e-3:
            resized = base
        else:
            resized = cv2.resize(base, None, fx=scale, fy=scale, interpolation=cv2.INTER_LANCZOS4)
        for fn in STRATEGIES.values():
            if attempts >= max_attempts:
                return results
            proc = fn(resized.copy())
            # Tenta primeiro a imagem direita (angulo 0): e' o caso normal e o mais
            # barato; so' roda se nao decodificar.
            for ang in (0, -5, 5, -10, 10):
                if attempts >= max_attempts:
                    return results
                if ang != 0:
                    h2, w2 = proc.shape[:2]
                    m = cv2.getRotationMatrix2D((w2 / 2, h2 / 2), ang, 1.0)
                    rotated = cv2.warpAffine(proc, m, (w2, h2), borderValue=255)
                else:
                    rotated = proc
                bordered = cv2.copyMakeBorder(rotated, 10, 10, 10, 10, cv2.BORDER_CONSTANT, value=255)
                texts = _decode_cv(bordered)
                attempts += 1
                for t in texts:
                    if t not in results:
                        results.append(t)
                # Assim que ha' leitura valida, para (o detectAndDecodeMulti ja' apanha
                # varios QR numa so' tentativa). Acelera muito o caso comum.
                if results and stop_on_first:
                    return results
    return results


def _decode_tiles(image: np.ndarray) -> List[str]:
    """Decode QR codes by scanning the whole image and its quadrants."""
    texts: List[str] = []
    full = _decode_with_strategies(image, max_attempts=14)
    texts.extend(full)

    h, w = image.shape[:2]
    half_h, half_w = h // 2, w // 2
    tiles = [
        image[0:half_h, 0:half_w],
        image[0:half_h, half_w:w],
        image[half_h:h, 0:half_w],
        image[half_h:h, half_w:w],
    ]
    seen = set(texts)
    for tile in tiles:
        for t in _decode_with_strategies(tile, max_attempts=10):
            if t not in seen:
                texts.append(t)
                seen.add(t)

    return texts


def _is_receipt_like(image: np.ndarray) -> bool:
    height, width = image.shape[:2]
    if width <= 0 or height <= 0:
        return False
    ratio = height / float(width)
    return ratio >= 2.2 and width <= 1800


def _receipt_candidate_regions(image: np.ndarray) -> List[np.ndarray]:
    height, width = image.shape[:2]
    candidates: List[np.ndarray] = []
    windows = [
        (0.05, 0.58, 0.90, 0.32),
        (0.08, 0.64, 0.84, 0.24),
        (0.10, 0.70, 0.80, 0.20),
        (0.12, 0.76, 0.76, 0.16),
    ]
    for x_ratio, y_ratio, w_ratio, h_ratio in windows:
        x0 = max(0, min(width - 1, int(round(x_ratio * width))))
        y0 = max(0, min(height - 1, int(round(y_ratio * height))))
        x1 = max(x0 + 1, min(width, int(round((x_ratio + w_ratio) * width))))
        y1 = max(y0 + 1, min(height, int(round((y_ratio + h_ratio) * height))))
        candidates.append(image[y0:y1, x0:x1])
    return candidates


def _decode_receipt_candidates(image: np.ndarray, max_attempts: int, stop_on_first: bool = False) -> List[str]:
    if not _is_receipt_like(image):
        return []
    results: List[str] = []
    for candidate in _receipt_candidate_regions(image):
        for text in _decode_with_strategies(
            candidate, max_attempts=max(4, min(max_attempts, 8)), stop_on_first=stop_on_first
        ):
            if text not in results:
                results.append(text)
        if results:
            break
    return results


def _load_page_image(path: str, dpi: int = 300, page: int = 1) -> Tuple[np.ndarray, int]:
    ext = os.path.splitext(path)[1].lower()
    if ext == ".pdf":
        if convert_from_path is None:
            raise RuntimeError("pdf2image not available")

        poppler_dir = _find_poppler_path()
        kwargs = {"dpi": dpi}
        if poppler_dir:
            kwargs["poppler_path"] = poppler_dir

        info: Dict[str, int] = {"Pages": 1}
        if pdfinfo_from_path is not None:
            try:
                if poppler_dir:
                    info = pdfinfo_from_path(path, poppler_path=poppler_dir)
                else:
                    info = pdfinfo_from_path(path)
            except TypeError:
                info = pdfinfo_from_path(path)  # type: ignore[arg-type]
        total_pages = int(info.get("Pages", 1))

        # Limita o tamanho do render: algumas digitalizacoes definem paginas enormes
        # (ex.: 2205x3116 pts ~ 43"!). A 300 DPI dariam ~119 MP e tornariam a decode
        # absurdamente lenta. Limita o lado maior a ~ (dpi * 11.7") = um A4 ao mesmo
        # DPI, mantendo resolucao mais que suficiente para ler QR.
        page_size = str(info.get("Page size", "")) if isinstance(info, dict) else ""
        m = re.match(r"\s*([\d.]+)\s*x\s*([\d.]+)\s*pts", page_size)
        if m:
            w_pts, h_pts = float(m.group(1)), float(m.group(2))
            long_pts = max(w_pts, h_pts)
            long_in = long_pts / 72.0
            cap_px = int(round(dpi * 11.7))
            if long_in * dpi > cap_px * 1.05 and cap_px > 0:
                if h_pts >= w_pts:
                    kwargs["size"] = (None, cap_px)
                else:
                    kwargs["size"] = (cap_px, None)

        selected_page = max(1, page)
        if selected_page > total_pages:
            selected_page = total_pages

        cache_path = _pdf_page_cache_path(path, selected_page, dpi)
        if os.path.exists(cache_path):
            cached = cv2.imread(cache_path, cv2.IMREAD_UNCHANGED)
            if cached is not None:
                return cached, total_pages

        pages = convert_from_path(path, first_page=selected_page, last_page=selected_page, **kwargs)
        if not pages:
            raise RuntimeError("unable to render pdf page")

        img = cv2.cvtColor(np.array(pages[0]), cv2.COLOR_RGB2BGR)
        try:
            cv2.imwrite(cache_path, img)
        except Exception:
            pass
        return img, total_pages
    img = cv2.imread(path, cv2.IMREAD_UNCHANGED)
    if img is None:
        raise RuntimeError("unable to read image")
    return img, 1


def _crop_by_ratios(image: np.ndarray, crop_ratios: Tuple[float, float, float, float]) -> np.ndarray:
    height, width = image.shape[:2]
    x_ratio, y_ratio, w_ratio, h_ratio = crop_ratios
    x0 = max(0, min(width - 1, int(round(x_ratio * width))))
    y0 = max(0, min(height - 1, int(round(y_ratio * height))))
    x1 = max(x0 + 1, min(width, int(round((x_ratio + w_ratio) * width))))
    y1 = max(y0 + 1, min(height, int(round((y_ratio + h_ratio) * height))))
    return image[y0:y1, x0:x1]


def decode_file(
    path: str,
    dpi: int = 300,
    page: int = 1,
    crop_ratios: Optional[Tuple[float, float, float, float]] = None,
    max_pages: int = 0,
    max_attempts: int = 12,
    receipt_priority: bool = False,
    single_page_scan: bool = False,
    stop_on_first: bool = False,
    receipt_only: bool = False,
) -> List[str]:
    # receipt_only: so' tenta as regioes tipicas de talao/POS, sem o scan completo de
    # fallback. Util como passagem extra barata depois de um scan completo ja' falhar
    # (evita varrer o documento inteiro outra vez em A4 sem QR).
    def _decode_page(im: np.ndarray) -> List[str]:
        if receipt_priority:
            found = _decode_receipt_candidates(im, max_attempts=max_attempts, stop_on_first=stop_on_first)
            if found or receipt_only:
                return found
        return _decode_with_strategies(im, max_attempts=max_attempts, stop_on_first=stop_on_first)

    ext = os.path.splitext(path)[1].lower()
    if crop_ratios is not None:
        image, _ = _load_page_image(path, dpi=dpi, page=page)
        cropped = _crop_by_ratios(image, crop_ratios)
        return _decode_with_strategies(cropped, max_attempts=max_attempts, stop_on_first=stop_on_first)

    if ext == ".pdf":
        start_page = page if single_page_scan else 1
        image, total_pages = _load_page_image(path, dpi=dpi, page=start_page)
        texts: List[str] = _decode_page(image)

        if total_pages == 1 or single_page_scan:
            return texts

        if max_pages > 0:
            total_pages = min(total_pages, max_pages)

        seen = set(texts)
        for page_num in range(2, total_pages + 1):
            img, _ = _load_page_image(path, dpi=dpi, page=page_num)
            for t in _decode_page(img):
                if t not in seen:
                    texts.append(t)
                    seen.add(t)
        return texts

    img, _ = _load_page_image(path, dpi=dpi, page=page)
    return _decode_page(img)


def render_preview(path: str, output_path: str, dpi: int = 150, page: int = 1) -> Dict[str, int]:
    image, page_count = _load_page_image(path, dpi=dpi, page=page)
    ok = cv2.imwrite(output_path, image)
    if not ok:
        raise RuntimeError("unable to write preview image")
    height, width = image.shape[:2]
    selected_page = max(1, min(int(page), int(page_count)))
    return {
        "ok": 1,
        "width": int(width),
        "height": int(height),
        "page": selected_page,
        "page_count": int(page_count),
    }


def _parse_crop_ratios(value: str) -> Tuple[float, float, float, float]:
    parts = [part.strip() for part in value.split(",")]
    if len(parts) != 4:
        raise argparse.ArgumentTypeError("crop ratios must have 4 comma-separated values")
    try:
        ratios = tuple(float(part) for part in parts)
    except ValueError as exc:
        raise argparse.ArgumentTypeError("crop ratios must be numeric") from exc
    for ratio in ratios:
        if ratio < 0 or ratio > 1:
            raise argparse.ArgumentTypeError("crop ratios must be between 0 and 1")
    return ratios


def main() -> int:
    parser = argparse.ArgumentParser(description="Decode QR codes from images or PDFs")
    parser.add_argument("file", help="path to image or PDF")
    parser.add_argument(
        "--dpi",
        type=int,
        default=300,
        help="resolution to use when converting PDFs (default: 300)",
    )
    parser.add_argument(
        "--max-image-pixels",
        type=_parse_max_image_pixels,
        default=None,
        metavar="VALUE",
        help="override Pillow's MAX_IMAGE_PIXELS (use 'none' to disable the limit)",
    )
    parser.add_argument("--page", type=int, default=1, help="page number to use in single-page operations")
    parser.add_argument("--max-pages", type=int, default=0, help="maximum PDF pages to scan (0 = all)")
    parser.add_argument("--max-attempts", type=int, default=12, help="maximum image-processing attempts per page")
    parser.add_argument("--receipt-priority", action="store_true", help="prioritize likely QR regions for POS/receipt documents before global scan")
    parser.add_argument("--receipt-only", action="store_true", help="only scan POS/receipt candidate regions (no full-page fallback); cheap extra pass")
    parser.add_argument("--single-page-scan", action="store_true", help="scan only the page selected in --page")
    parser.add_argument("--stop-on-first", action="store_true", help="return as soon as a QR is decoded on a page (faster first pass; lower recall for multi-QR pages)")
    parser.add_argument("--crop-ratios", type=_parse_crop_ratios, help="crop rectangle as x,y,w,h ratios")
    parser.add_argument("--render-preview", action="store_true", help="render the selected page to an image")
    parser.add_argument("--output", help="output image path for --render-preview")
    parser.add_argument("--json", action="store_true", help="emit JSON metadata for preview rendering")
    args = parser.parse_args()

    Image.MAX_IMAGE_PIXELS = args.max_image_pixels

    file_path = args.file
    if not os.path.exists(file_path):
        print("file not found", file=sys.stderr)
        return 2
    try:
        if args.render_preview:
            if not args.output:
                print("missing output path", file=sys.stderr)
                return 2
            preview = render_preview(file_path, args.output, dpi=args.dpi, page=args.page)
            if args.json:
                print(json.dumps(preview, ensure_ascii=True))
            else:
                print(args.output)
            return 0

        texts = decode_file(
            file_path,
            dpi=args.dpi,
            page=args.page,
            crop_ratios=args.crop_ratios,
            max_pages=args.max_pages,
            max_attempts=args.max_attempts,
            receipt_priority=args.receipt_priority,
            single_page_scan=args.single_page_scan,
            stop_on_first=args.stop_on_first,
            receipt_only=args.receipt_only,
        )
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        return 3
    if texts:
        for t in texts:
            print(t)
        return 0
    return 4


if __name__ == "__main__":
    raise SystemExit(main())
