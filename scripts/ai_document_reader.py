#!/usr/bin/env python3
import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
from typing import Dict, Any, Optional

try:
    from pdf2image import convert_from_path
except Exception:
    convert_from_path = None


def first_match(pattern: str, text: str, flags: int = 0) -> str:
    match = re.search(pattern, text, flags)
    if not match:
        return ""
    for idx in range(1, len(match.groups()) + 1):
        value = (match.group(idx) or "").strip()
        if value:
            return value
    return (match.group(0) or "").strip()


def to_decimal_string(value: str) -> str:
    raw = (value or "").strip()
    if not raw:
        return ""
    cleaned = re.sub(r"[^\d,.\-]", "", raw)
    if not cleaned:
        return ""
    if "," in cleaned and "." in cleaned:
        if cleaned.rfind(",") > cleaned.rfind("."):
            cleaned = cleaned.replace(".", "")
            cleaned = cleaned.replace(",", ".")
        else:
            cleaned = cleaned.replace(",", "")
    elif "," in cleaned:
        cleaned = cleaned.replace(",", ".")
    try:
        num = float(cleaned)
    except ValueError:
        return ""
    return f"{num:.2f}"


def normalize_text(text: str, max_chars: int) -> str:
    compact = re.sub(r"\s+", " ", (text or "").strip())
    if max_chars > 0:
        return compact[:max_chars]
    return compact


def read_text_file(path: str, max_chars: int) -> Dict[str, Any]:
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as fh:
            content = fh.read()
        return {"ok": True, "method": "text", "text_excerpt": normalize_text(content, max_chars)}
    except Exception as exc:
        return {"ok": False, "error": f"text_read_failed: {exc}"}


def extract_pdf_text_with_pypdf(path: str) -> Dict[str, Any]:
    try:
        from pypdf import PdfReader  # type: ignore
    except Exception:
        return {"ok": False, "error": "pypdf_unavailable"}

    try:
        reader = PdfReader(path)
        chunks = []
        for page in reader.pages:
            page_text = page.extract_text() or ""
            if page_text:
                chunks.append(page_text)
        return {
            "ok": True,
            "method": "pypdf",
            "pages": len(reader.pages),
            "text": "\n".join(chunks),
        }
    except Exception as exc:
        return {"ok": False, "error": f"pypdf_failed: {exc}"}


def extract_pdf_text_with_pdftotext(path: str) -> Dict[str, Any]:
    if subprocess.call(["/usr/bin/env", "bash", "-lc", "command -v pdftotext >/dev/null 2>&1"]) != 0:
        return {"ok": False, "error": "pdftotext_unavailable"}

    txt_path = path + ".txt"
    try:
        proc = subprocess.run(
            ["pdftotext", "-q", "-enc", "UTF-8", "-nopgbrk", path, txt_path],
            capture_output=True,
            text=True,
            timeout=60,
        )
        if proc.returncode != 0:
            return {"ok": False, "error": f"pdftotext_failed: {proc.stderr.strip()}"}
        if not os.path.isfile(txt_path):
            return {"ok": False, "error": "pdftotext_no_output"}
        with open(txt_path, "r", encoding="utf-8", errors="ignore") as fh:
            content = fh.read()
        return {"ok": True, "method": "pdftotext", "text": content}
    except Exception as exc:
        return {"ok": False, "error": f"pdftotext_failed: {exc}"}
    finally:
        try:
            if os.path.isfile(txt_path):
                os.remove(txt_path)
        except Exception:
            pass


def find_poppler_path() -> str:
    for candidate in ("/usr/bin", "/usr/local/bin", "/opt/homebrew/bin"):
        if os.path.exists(os.path.join(candidate, "pdfinfo")):
            return candidate
    return ""


def extract_pdf_text_with_ocr(path: str, max_pages: int = 4) -> Dict[str, Any]:
    if convert_from_path is None:
        return {"ok": False, "error": "pdf2image_unavailable"}

    has_tesseract = subprocess.call(
        ["/usr/bin/env", "bash", "-lc", "command -v tesseract >/dev/null 2>&1"]
    ) == 0
    if not has_tesseract:
        return {"ok": False, "error": "tesseract_unavailable"}

    poppler_path = find_poppler_path() or None
    kwargs: Dict[str, Any] = {"dpi": 300}
    if poppler_path:
        kwargs["poppler_path"] = poppler_path

    try:
        pages = convert_from_path(path, first_page=1, last_page=max_pages, **kwargs)
    except Exception as exc:
        return {"ok": False, "error": f"pdf2image_failed: {exc}"}

    chunks = []
    for idx, page in enumerate(pages, start=1):
        tmp_file = None
        try:
            with tempfile.NamedTemporaryFile(suffix=f"_p{idx}.png", delete=False) as fh:
                tmp_file = fh.name
            page.save(tmp_file, format="PNG")
            proc = subprocess.run(
                ["tesseract", tmp_file, "stdout", "-l", "por+eng", "--psm", "6"],
                capture_output=True,
                text=True,
                timeout=90,
            )
            if proc.returncode == 0:
                text = (proc.stdout or "").strip()
                if text:
                    chunks.append(text)
        except Exception:
            continue
        finally:
            if tmp_file and os.path.isfile(tmp_file):
                try:
                    os.remove(tmp_file)
                except Exception:
                    pass

    if not chunks:
        return {"ok": False, "error": "ocr_no_text"}

    return {"ok": True, "method": "ocr_tesseract", "text": "\n".join(chunks), "pages": len(pages)}


def read_pdf(path: str, max_chars: int) -> Dict[str, Any]:
    failures = []

    for extractor in (extract_pdf_text_with_pypdf, extract_pdf_text_with_pdftotext):
        result = extractor(path)
        if result.get("ok"):
            text = normalize_text(result.get("text", ""), max_chars)
            return {
                "ok": True,
                "method": result.get("method", "pdf"),
                "pages": result.get("pages"),
                "text_excerpt": text,
            }
        failures.append(result.get("error", "unknown_error"))

    ocr_result = extract_pdf_text_with_ocr(path)
    if ocr_result.get("ok"):
        text = normalize_text(ocr_result.get("text", ""), max_chars)
        return {
            "ok": True,
            "method": ocr_result.get("method", "ocr_tesseract"),
            "pages": ocr_result.get("pages"),
            "text_excerpt": text,
        }
    failures.append(ocr_result.get("error", "ocr_unknown_error"))

    return {"ok": False, "error": "pdf_extract_failed", "details": failures}


def run_qr_detector(path: str, dpi: int = 200) -> Dict[str, Any]:
    script_path = os.path.realpath(
        os.path.join(os.path.dirname(__file__), "..", "contabilidade", "detectar_qr.py")
    )
    if not os.path.isfile(script_path):
        return {"ok": False, "error": "qr_detector_script_missing", "texts": []}
    try:
        proc = subprocess.run(
            [sys.executable, script_path, path, "--dpi", str(dpi)],
            capture_output=True,
            text=True,
            timeout=90,
        )
    except Exception as exc:
        return {"ok": False, "error": f"qr_detector_exec_failed: {exc}", "texts": []}

    stdout_lines = [line.strip() for line in (proc.stdout or "").splitlines() if line.strip()]
    return {
        "ok": proc.returncode == 0 and len(stdout_lines) > 0,
        "exit_code": proc.returncode,
        "texts": stdout_lines,
        "stderr": (proc.stderr or "").strip(),
    }


def parse_portuguese_qr_payload(payload: str) -> Dict[str, str]:
    data = {}
    if not payload:
        return data

    for chunk in payload.split("*"):
        piece = chunk.strip()
        if not piece or ":" not in piece:
            continue
        key, value = piece.split(":", 1)
        key = key.strip().upper()
        value = value.strip()
        if key:
            data[key] = value
    return data


def guess_doc_type(filename: str, text: str) -> str:
    blob = f"{filename} {text}".lower()
    if "fatura" in blob or "invoice" in blob:
        return "fatura"
    if "fatura-recibo" in blob:
        return "fatura_recibo"
    if "guia" in blob:
        return "guia"
    if "recibo" in blob:
        return "recibo"
    if "nota de credito" in blob or "nota crédito" in blob:
        return "nota_credito"
    if "nota de debito" in blob or "nota débito" in blob:
        return "nota_debito"
    return "desconhecido"


def extract_structured_fields(filename: str, text: str, qr_payload: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
    lines = [line.strip() for line in (text or "").splitlines() if line.strip()]
    text_flat = "\n".join(lines)

    nif_candidates = re.findall(r"\b\d{9}\b", text_flat)
    unique_nifs = []
    seen_nifs = set()
    for nif in nif_candidates:
        if nif not in seen_nifs:
            seen_nifs.add(nif)
            unique_nifs.append(nif)

    emitente_nif = first_match(r"(?i)(?:nif\s*(?:emitente|fornecedor)|fornecedor\s*nif|emitente\s*nif)\s*[:\-]?\s*(\d{9})", text_flat)
    adquirente_nif = first_match(r"(?i)(?:nif\s*(?:adquirente|cliente)|cliente\s*nif|adquirente\s*nif)\s*[:\-]?\s*(\d{9})", text_flat)

    if not emitente_nif and unique_nifs:
        emitente_nif = unique_nifs[0]
    if not adquirente_nif and len(unique_nifs) > 1:
        adquirente_nif = unique_nifs[1]

    emitente_nome = first_match(r"(?i)(?:emitente|fornecedor)\s*[:\-]\s*(.+)", text_flat)
    adquirente_nome = first_match(r"(?i)(?:adquirente|cliente)\s*[:\-]\s*(.+)", text_flat)
    doc_numero = first_match(r"(?i)(?:doc(?:umento)?|fatura|factura|recibo|guia|n[.]?\s*doc)[\s#:.-]*([A-Z0-9][A-Z0-9\/\-.]{2,})", text_flat)
    doc_data = first_match(r"\b(\d{2}[\/\-]\d{2}[\/\-]\d{4}|\d{4}[\/\-]\d{2}[\/\-]\d{2})\b", text_flat)

    total_raw = first_match(r"(?i)(?:total\s*(?:a\s*pagar|documento|final)?|valor\s*total)\s*[:\-]?\s*([0-9][0-9., ]+)", text_flat)
    subtotal_raw = first_match(r"(?i)(?:subtotal|base\s*tributavel|base\s*tribut[aá]vel)\s*[:\-]?\s*([0-9][0-9., ]+)", text_flat)
    iva_total_raw = first_match(r"(?i)(?:total\s*iva|iva\s*total|imposto\s*iva)\s*[:\-]?\s*([0-9][0-9., ]+)", text_flat)

    # fallback: choose the largest numeric amount found as total if explicit total is missing
    if not total_raw:
        amount_candidates = re.findall(r"\b\d{1,3}(?:[ .]\d{3})*(?:[,.]\d{2})\b", text_flat)
        parsed = []
        for amount in amount_candidates:
            value = to_decimal_string(amount)
            if value:
                parsed.append((float(value), value))
        if parsed:
            parsed.sort(key=lambda item: item[0], reverse=True)
            total_raw = parsed[0][1]

    doc_type_guess = guess_doc_type(filename, text_flat)
    confidence = 0.2
    if doc_type_guess != "desconhecido":
        confidence += 0.2
    if emitente_nif:
        confidence += 0.2
    if adquirente_nif:
        confidence += 0.15
    if total_raw:
        confidence += 0.15
    if doc_numero:
        confidence += 0.1
    if doc_data:
        confidence += 0.1

    # Enrich with PT fiscal QR payload when available.
    qr_payload = qr_payload or {}
    if qr_payload:
        # Common mappings found in AT QR payload.
        # A: issuer NIF, B: acquirer NIF, D: document type, E/F: document date/number (can vary by producer).
        emitente_nif = qr_payload.get("A", emitente_nif) or emitente_nif
        adquirente_nif = qr_payload.get("B", adquirente_nif) or adquirente_nif
        doc_type_from_qr = (qr_payload.get("D", "") or "").strip().lower()
        if doc_type_from_qr:
            doc_type_guess = doc_type_from_qr
        doc_numero = qr_payload.get("F", doc_numero) or doc_numero
        doc_data = qr_payload.get("E", doc_data) or doc_data
        # Frequent numeric fields in QR payloads used by producers.
        total_from_qr = qr_payload.get("O", "") or qr_payload.get("Q", "") or qr_payload.get("N", "")
        iva_from_qr = qr_payload.get("R", "") or qr_payload.get("I8", "")
        if total_from_qr and not total_raw:
            total_raw = total_from_qr
        if iva_from_qr and not iva_total_raw:
            iva_total_raw = iva_from_qr
        confidence += 0.2

    confidence = min(confidence, 0.99)

    return {
        "schema_version": 1,
        "document_type_guess": doc_type_guess,
        "confidence": round(confidence, 2),
        "document_number": doc_numero,
        "document_date": doc_data,
        "issuer": {
            "name": emitente_nome,
            "nif": emitente_nif,
        },
        "buyer": {
            "name": adquirente_nome,
            "nif": adquirente_nif,
        },
        "totals": {
            "subtotal": to_decimal_string(subtotal_raw),
            "iva_total": to_decimal_string(iva_total_raw),
            "total": to_decimal_string(total_raw),
        },
        "nif_candidates": unique_nifs[:10],
        "qr_payload": qr_payload,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--path", required=True)
    parser.add_argument("--mime", default="")
    parser.add_argument("--filename", default="")
    parser.add_argument("--max-chars", type=int, default=5000)
    args = parser.parse_args()

    path = os.path.realpath(args.path)
    if not os.path.isfile(path):
        print(json.dumps({"ok": False, "error": "file_not_found"}))
        return 1

    mime = (args.mime or "").lower().strip()
    filename = args.filename or os.path.basename(path)
    ext = os.path.splitext(filename)[1].lower()

    is_pdf = mime == "application/pdf" or ext == ".pdf"
    is_text = mime.startswith("text/") or mime in {"application/json", "application/xml"} or ext in {".txt", ".md", ".csv", ".json", ".xml"}

    if is_pdf:
        result = read_pdf(path, args.max_chars)
    elif is_text:
        result = read_text_file(path, args.max_chars)
    else:
        result = {"ok": False, "error": "unsupported_binary_type"}

    # Always attempt fiscal QR detection, even when text extraction fails.
    qr_detection = run_qr_detector(path)
    qr_texts = qr_detection.get("texts", []) if isinstance(qr_detection, dict) else []
    qr_payload = {}
    if qr_texts:
        qr_payload = parse_portuguese_qr_payload(qr_texts[0])

    if result.get("ok"):
        excerpt = result.get("text_excerpt", "")
        result["doc_type_guess"] = guess_doc_type(filename, excerpt)
        result["qr"] = {
            "ok": bool(qr_detection.get("ok")) if isinstance(qr_detection, dict) else False,
            "texts": qr_texts,
            "payload": qr_payload,
            "exit_code": qr_detection.get("exit_code") if isinstance(qr_detection, dict) else None,
        }
        result["structured"] = extract_structured_fields(filename, excerpt, qr_payload)
        result["filename"] = filename
        result["size"] = os.path.getsize(path)
        print(json.dumps(result, ensure_ascii=False))
        return 0

    # Fallback: QR-only structured response.
    if qr_texts or qr_payload:
        qr_only = {
            "ok": True,
            "method": "qr_only",
            "text_excerpt": "",
            "doc_type_guess": guess_doc_type(filename, ""),
            "qr": {
                "ok": bool(qr_detection.get("ok")) if isinstance(qr_detection, dict) else False,
                "texts": qr_texts,
                "payload": qr_payload,
                "exit_code": qr_detection.get("exit_code") if isinstance(qr_detection, dict) else None,
            },
            "structured": extract_structured_fields(filename, "", qr_payload),
            "filename": filename,
            "size": os.path.getsize(path),
            "warnings": {
                "text_extraction_error": result.get("error", "text_extract_failed"),
                "text_extraction_details": result.get("details", []),
            },
        }
        print(json.dumps(qr_only, ensure_ascii=False))
        return 0

    # Complete failure.
    result["qr"] = {
        "ok": bool(qr_detection.get("ok")) if isinstance(qr_detection, dict) else False,
        "texts": qr_texts,
        "payload": qr_payload,
        "exit_code": qr_detection.get("exit_code") if isinstance(qr_detection, dict) else None,
        "error": qr_detection.get("error") if isinstance(qr_detection, dict) else "qr_unavailable",
    }
    result["filename"] = filename
    result["size"] = os.path.getsize(path)
    print(json.dumps(result, ensure_ascii=False))
    return 2


if __name__ == "__main__":
    sys.exit(main())
