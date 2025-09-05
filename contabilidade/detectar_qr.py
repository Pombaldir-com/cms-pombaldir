#!/usr/bin/env python3
"""Decode QR codes from images or PDFs.

This script reads an image file or a PDF and tries to decode the first QR
code found. The result is printed to stdout and the process exits with code 0
on success. Non‑zero exit codes indicate failure to read or decode the file.

The detection relies primarily on OpenCV but applies additional image
pre‑processing steps and falls back to ``pyzbar`` when available, improving
the chances of decoding less than ideal scans.
"""
import sys
import os
from typing import Optional

try:
    from pdf2image import convert_from_path
except Exception:  # pragma: no cover - library missing
    convert_from_path = None  # type: ignore

import cv2  # type: ignore
import numpy as np  # type: ignore

try:
    from pyzbar.pyzbar import decode as pyzbar_decode
except Exception:  # pragma: no cover - optional dependency missing
    pyzbar_decode = None  # type: ignore

def _decode_cv(image) -> Optional[str]:
    """Return decoded text from a cv2 image array or ``None``.

    The standard :func:`detectAndDecode` call fails with certain QR codes,
    especially when multiple codes are present or the image is in color.
    To make the detection more robust we convert the frame to grayscale and
    fall back to :func:`detectAndDecodeMulti` when the first attempt returns
    nothing.
    """
    detector = cv2.QRCodeDetector()

    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    data, _, _ = detector.detectAndDecode(gray)
    if data:
        return data

    ok, decoded_info, _, _ = detector.detectAndDecodeMulti(gray)
    if ok and decoded_info:
        for text in decoded_info:
            if text:
                return text

    # try again with Otsu thresholding to enhance contrast
    _, thresh = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    data, _, _ = detector.detectAndDecode(thresh)
    if data:
        return data
    ok, decoded_info, _, _ = detector.detectAndDecodeMulti(thresh)
    if ok and decoded_info:
        for text in decoded_info:
            if text:
                return text

    # final fallback: use pyzbar if available
    if pyzbar_decode is not None:
        decoded = pyzbar_decode(thresh)
        if not decoded:
            decoded = pyzbar_decode(image)
        for item in decoded:
            data_bytes = getattr(item, "data", b"")
            if data_bytes:
                try:
                    return data_bytes.decode("utf-8")
                except Exception:
                    return data_bytes.decode("latin-1")

    return None

def decode_file(path: str) -> Optional[str]:
    ext = os.path.splitext(path)[1].lower()
    if ext == ".pdf":
        if convert_from_path is None:
            raise RuntimeError("pdf2image not available")
        pages = convert_from_path(path, dpi=200, poppler_path="/usr/local/bin" )
        for page in pages:
            img = cv2.cvtColor(np.array(page), cv2.COLOR_RGB2BGR)
            text = _decode_cv(img)
            if text:
                return text
        return None
    else:
        img = cv2.imread(path, cv2.IMREAD_COLOR)
        if img is None:
            raise RuntimeError("unable to read image")
        return _decode_cv(img)

def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: detectar_qr.py <file>", file=sys.stderr)
        return 1
    file_path = sys.argv[1]
    if not os.path.exists(file_path):
        print("file not found", file=sys.stderr)
        return 2
    try:
        text = decode_file(file_path)
    except Exception as exc:  # pragma: no cover
        print(str(exc), file=sys.stderr)
        return 3
    if text:
        print(text)
        return 0
    return 4

if __name__ == "__main__":
    raise SystemExit(main())
