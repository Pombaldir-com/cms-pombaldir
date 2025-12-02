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
import argparse
from typing import Callable, Dict, List, Optional

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


def _decode_with_strategies(image: np.ndarray, max_attempts: int = 12) -> List[str]:
    base = _prepare_base(image)
    h, w = base.shape[:2]
    min_side = min(h, w)
    scale_boost = 1.0
    if min_side < 900:
        scale_boost = 1200 / max(1, min_side)
    escala_inicial = max(1.0, scale_boost)
    scales = []
    for m in (1.0, 1.5, 2.0):
        val = min(3.5, escala_inicial * m)
        scales.append(round(val, 2))
    seen = set()
    scales = [s for s in scales if not (s in seen or seen.add(s))]

    attempts = 0
    results: List[str] = []
    for scale in scales:
        resized = cv2.resize(base, None, fx=scale, fy=scale, interpolation=cv2.INTER_LANCZOS4)
        for fn in STRATEGIES.values():
            if attempts >= max_attempts:
                return results
            proc = fn(resized.copy())
            for ang in (-10, -5, 0, 5, 10):
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


def decode_file(path: str, dpi: int = 300) -> List[str]:
    ext = os.path.splitext(path)[1].lower()
    if ext == ".pdf":
        if convert_from_path is None:
            raise RuntimeError("pdf2image not available")

        # Caminho fixo para o Poppler
        poppler_dir = "/usr/local/bin"
        kwargs = {"dpi": dpi, "poppler_path": poppler_dir}

        info: Dict[str, int] = {"Pages": 1}
        if pdfinfo_from_path is not None:
            try:
                info = pdfinfo_from_path(path, poppler_path=poppler_dir)
            except TypeError:
                info = pdfinfo_from_path(path)  # type: ignore[arg-type]
        total_pages = int(info.get("Pages", 1))

        pages = convert_from_path(path, first_page=1, last_page=1, **kwargs)
        if not pages:
            return []

        first_img = cv2.cvtColor(np.array(pages[0]), cv2.COLOR_RGB2BGR)
        texts = _decode_with_strategies(first_img)

        if total_pages == 1:
            return texts

        seen = set(texts)
        for page_num in range(2, total_pages + 1):
            page = convert_from_path(
                path, first_page=page_num, last_page=page_num, **kwargs
            )[0]
            img = cv2.cvtColor(np.array(page), cv2.COLOR_RGB2BGR)
            for t in _decode_with_strategies(img):
                if t not in seen:
                    texts.append(t)
                    seen.add(t)
        return texts
    else:
        img = cv2.imread(path, cv2.IMREAD_UNCHANGED)
        if img is None:
            raise RuntimeError("unable to read image")
        return _decode_with_strategies(img)


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
    args = parser.parse_args()

    Image.MAX_IMAGE_PIXELS = args.max_image_pixels

    file_path = args.file
    if not os.path.exists(file_path):
        print("file not found", file=sys.stderr)
        return 2
    try:
        texts = decode_file(file_path, dpi=args.dpi)
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
