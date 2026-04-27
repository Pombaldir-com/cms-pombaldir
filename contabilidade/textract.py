#!/usr/bin/env python3
"""Run AWS Textract and output parsed invoice article lines as JSON
(auto header detection: usa os cabeçalhos originais da tabela do PDF,
sem mapping fixo, sem duplicados, sem subtotais).
Opcional: --save-headers <ficheiro.json> guarda os cabeçalhos detetados."""

import argparse
import boto3
import json
import os
import re
import sys
import time
import uuid


# ---------- HELPERS ----------

def parse_table_with_headers(rows: dict[int, dict[int, str]]) -> list[dict]:
    """Transforma uma tabela do Textract em lista de linhas usando os cabeçalhos originais."""
    result = []
    header_row = None

    for r_idx in sorted(rows.keys()):
        row_cells = [rows[r_idx].get(c, "") for c in sorted(rows[r_idx].keys())]
        if not any(row_cells):
            continue

        if header_row is None:
            # Primeira linha = cabeçalho original
            header_row = row_cells
            continue

        line = {}
        for idx, val in enumerate(row_cells):
            if idx < len(header_row):
                col_name = header_row[idx].strip()
                if col_name:  # ignora colunas vazias
                    line[col_name] = val.strip()
        if any(v for v in line.values()):
            result.append(line)

    return result, header_row


LINE_START_RE = re.compile(
    r"^\s*\(?(?P<iva>[A-Z])\)?\s*[-:]?\s*(?P<desc>[^\d].*?)(?:\s+(?P<price>-?\d+[.,]\d{2}(?:€)?)\s*)?$"
)
BANK_DETAIL_LINE_RE = re.compile(
    r"^\s*(?P<date>\d{4}[-/]\d{2}[-/]\d{2}|\d{2}[-/]\d{2}[-/]\d{4})\s+"
    r"(?P<desc>.*?)\s+"
    r"(?P<qty>\d+(?:[.,]\d+)?)\s+"
    r"(?P<base>-?\d+[.,]\d{2})\s+"
    r"(?:I\.?\s*S\.?|IS)\s+"
    r"(?P<tax_rate>\d+[.,]\d+%?)\s+"
    r"(?P<tax>-?\d+[.,]\d{2})\s+"
    r"(?P<total>-?\d+[.,]\d{2})"
    r"(?:\s+\w+)?\s*$",
    re.IGNORECASE,
)
ITEM_MARKER_SPLIT_RE = re.compile(r"(?=\([A-Z]\))")
QTY_LINE_RE = re.compile(r"^\s*(?P<qty>[\d.,]+)\s*[xX]\s*(?P<unit>[\d.,]+)")
PRICE_VALUE_RE = re.compile(r"(-?\d+[.,]\d{2})")
NON_NUMBER_RE = re.compile(r"[^\d,.-]")
SECTION_SUFFIX_RE = re.compile(r":\s*$")
TRAILING_AMOUNT_RE = re.compile(r"[-\s]*(?:-?\d+[.,]\d{2})(?:€)?\s*$")
ALPHA_ONLY_RE = re.compile(r"[^A-ZÀ-Ü ]+")
IGNORED_KEYWORDS = {
    "POUPANCA",
    "UTILIZOU DO SEU CARTAO",
    "ACUMULOU NO SEU CARTAO",
    "SALDO NO SEU CARTAO",
    "SALDO NO CARTAO",
    "SOFT DRINKS",
    "FRUTAS E LEGUMES",
    "TALHO",
    "TOTAL",
    "SUBTOTAL",
    "VALOR",
    "IVA DESCRICAO",
}


def clean_numeric_string(value: str) -> str:
    stripped = value.strip().replace("€", "")
    stripped = NON_NUMBER_RE.sub("", stripped)
    if not stripped:
        return ""
    # Preserve decimal separator by converting to float then formatting
    normalized = stripped.replace(".", "").replace(",", ".")
    try:
        number = float(normalized)
    except ValueError:
        return stripped
    return f"{number:.2f}".replace(".", ",")


def parse_price_from_text(text: str) -> str:
    matches = PRICE_VALUE_RE.findall(text)
    if not matches:
        return ""
    return clean_numeric_string(matches[-1])


def should_ignore_text_line(text: str) -> bool:
    stripped = text.strip()
    if not stripped:
        return True
    upper = stripped.upper()
    if SECTION_SUFFIX_RE.search(stripped):
        return True
    candidate = TRAILING_AMOUNT_RE.sub("", upper).strip()
    candidate_alpha = ALPHA_ONLY_RE.sub(" ", candidate).strip()
    if candidate_alpha in IGNORED_KEYWORDS or candidate in IGNORED_KEYWORDS or upper in IGNORED_KEYWORDS:
        return True
    return False


def parse_textual_lines(text_lines: list[str]) -> list[dict]:
    items = []
    current = None

    def finalize_current():
        nonlocal current
        if current and (current.get("ITEM") or current.get("PRICE")):
            items.append(current)
        current = None

    for raw in text_lines:
        segments = ITEM_MARKER_SPLIT_RE.split(raw)
        for segment in segments:
            text = segment.strip()
            if not text:
                continue
            if should_ignore_text_line(text):
                finalize_current()
                continue
            bank_match = BANK_DETAIL_LINE_RE.match(text)
            if bank_match:
                finalize_current()
                items.append({
                    "IVA_TAXA": "IS",
                    "ITEM": bank_match.group("desc").strip(),
                    "QUANTITY": bank_match.group("qty").strip(),
                    "UNIT_PRICE": clean_numeric_string(bank_match.group("base")),
                    "PRICE": clean_numeric_string(bank_match.group("total")),
                    "TAX": clean_numeric_string(bank_match.group("tax")),
                })
                continue
            start_match = LINE_START_RE.match(text)
            if start_match:
                finalize_current()
                price = start_match.group("price") or ""
                current = {
                    "IVA_TAXA": start_match.group("iva"),
                    "ITEM": start_match.group("desc").strip(),
                    "QUANTITY": "",
                    "UNIT_PRICE": "",
                    "PRICE": clean_numeric_string(price) if price else "",
                }
                continue
            if current is None:
                continue
            qty_match = QTY_LINE_RE.match(text)
            if qty_match:
                current["QUANTITY"] = qty_match.group("qty").strip()
                current["UNIT_PRICE"] = qty_match.group("unit").strip()
                if not current.get("PRICE"):
                    qty_float = None
                    unit_float = None
                    try:
                        qty_float = float(qty_match.group("qty").replace(".", "").replace(",", "."))
                        unit_float = float(qty_match.group("unit").replace(".", "").replace(",", "."))
                    except ValueError:
                        qty_float = None
                    if qty_float is not None and unit_float is not None:
                        total = qty_float * unit_float
                        current["PRICE"] = f"{total:.2f}".replace(".", ",")
                continue
            if not current.get("PRICE"):
                price_candidate = parse_price_from_text(text)
                if price_candidate:
                    current["PRICE"] = price_candidate
                    continue
            # Treat as description continuation
            current["ITEM"] = (current.get("ITEM", "") + " " + text).strip()

    finalize_current()
    return items


def normalize_structured_entry(entry: dict) -> dict:
    if "Texto" in entry and len(entry) == 1:
        # handled elsewhere
        return {}
    normalized_keys = {normalize_header_key(key): value for key, value in entry.items()}

    def first_value(*keys: str) -> str:
        for key in keys:
            value = entry.get(key)
            if value:
                return value
        return ""

    def first_normalized(*keys: str) -> str:
        for key in keys:
            value = normalized_keys.get(key)
            if value:
                return value
        return ""

    normalized = {
        "ERP": first_value("ERP"),
        "IVA_TAXA": first_value("IVA_TAXA", "IVA", "TAX") or first_normalized("TIPO", "TIPOIMPOSTO", "IMPOSTO"),
        "PRODUCT_CODE": first_value("PRODUCT_CODE", "ITEM_CODE"),
        "ITEM": first_value("ITEM", "ITEMDESCRIPTION", "DESCRICAO", "Descrição")
            or first_normalized("DESCRICAO", "DESCRICAOARTIGO", "ARTIGO"),
        "QUANTITY": first_value("QUANTITY", "QTY", "Qt.", "QT")
            or first_normalized("QT", "QTD", "QUANTIDADE"),
        "UNIT_PRICE": first_value("UNIT_PRICE", "UNITPRICE", "PRICE_UNIT")
            or first_normalized("BASEINCID", "BASEINCIDENCIA", "VALORBASE"),
        "PRICE": first_value("PRICE", "AMOUNT", "Total", "TOTAL")
            or first_normalized("TOTALMD", "TOTAL", "VALOR"),
    }
    sanitized_item = sanitize_item_label(normalized.get("ITEM", ""))
    if sanitized_item in IGNORED_KEYWORDS:
        return {}
    if not any(normalized.values()):
        return {}
    return normalized


def normalize_ticket_lines(raw_lines: list[dict]) -> list[dict]:
    normalized = []
    buffer_text = []

    def flush_text_buffer():
        nonlocal buffer_text
        if buffer_text:
            normalized.extend(parse_textual_lines(buffer_text))
            buffer_text = []

    for entry in raw_lines:
        if isinstance(entry, dict) and "Texto" in entry and len(entry) == 1:
            buffer_text.append(entry["Texto"])
            continue
        flush_text_buffer()
        normalized_entry = normalize_structured_entry(entry)
        if normalized_entry:
            normalized.append(normalized_entry)
    flush_text_buffer()

    merged = merge_quantity_only_lines([
        line for line in normalized
        if sanitize_item_label(line.get("ITEM", "")) not in IGNORED_KEYWORDS
    ])

    filtered = []
    seen = set()
    for line in merged:
        key = (
            sanitize_item_label(line.get("ITEM", "")),
            line.get("QUANTITY", "") or "",
            line.get("UNIT_PRICE", "") or "",
            line.get("PRICE", "") or "",
        )
        if key == ("", "", "", ""):
            continue
        if key in seen:
            continue
        seen.add(key)
        filtered.append(line)

    for idx, line in enumerate(filtered, 1):
        if not str(line.get("ERP", "")).strip():
            line["ERP"] = str(idx)
    return filtered


def sanitize_item_label(label: str) -> str:
    if not label:
        return ""
    cleaned = ALPHA_ONLY_RE.sub(" ", label.upper()).strip()
    cleaned = re.sub(r"\s+", " ", cleaned)
    return cleaned


def normalize_header_key(label: str) -> str:
    cleaned = sanitize_item_label(label)
    return cleaned.replace(" ", "")


def merge_quantity_only_lines(lines: list[dict]) -> list[dict]:
    merged = []
    for line in lines:
        has_item = bool(line.get("ITEM"))
        has_qty = bool(line.get("QUANTITY"))
        has_unit = bool(line.get("UNIT_PRICE"))
        if (not has_item) and (has_qty or has_unit) and merged:
            target = merged[-1]
            if has_qty:
                target["QUANTITY"] = line["QUANTITY"]
            if has_unit:
                target["UNIT_PRICE"] = line["UNIT_PRICE"]
            if not target.get("PRICE"):
                try:
                    qty_float = float(target.get("QUANTITY", "0").replace(".", "").replace(",", "."))
                    unit_float = float(target.get("UNIT_PRICE", "0").replace(".", "").replace(",", "."))
                    total = qty_float * unit_float
                    if total:
                        target["PRICE"] = f"{total:.2f}".replace(".", ",")
                except Exception:
                    pass
            continue
        merged.append(line)
    return merged


# ---------- MAIN ----------

def main() -> int:
    parser = argparse.ArgumentParser(description="Process an invoice file with AWS Textract")
    parser.add_argument("file", help="Path to the document to analyse")
    parser.add_argument("--bucket", default=os.environ.get("AWS_TEXTRACT_BUCKET"), help="S3 bucket")
    parser.add_argument("--region", default=os.environ.get("AWS_REGION", "us-east-1"), help="AWS region")
    parser.add_argument("--save-headers", help="Guardar cabeçalhos detetados em ficheiro JSON")
    args = parser.parse_args()

    if not args.bucket:
        print("S3 bucket not configured", file=sys.stderr)
        return 2

    session = boto3.Session(region_name=args.region)
    s3 = session.client("s3")
    textract = session.client("textract")
    key = None
    try:
        # garante bucket
        try:
            s3.head_bucket(Bucket=args.bucket)
        except Exception:
            s3.create_bucket(Bucket=args.bucket)
            s3.get_waiter("bucket_exists").wait(Bucket=args.bucket)

        key = f"textract/{uuid.uuid4()}" + os.path.splitext(args.file)[1]
        s3.upload_file(args.file, args.bucket, key)

        lines = []
        seen = set()
        headers_saved = False

        # --- 1. Try AnalyzeExpense ---
        try:
            start = textract.start_expense_analysis(
                DocumentLocation={"S3Object": {"Bucket": args.bucket, "Name": key}}
            )
            job_id = start["JobId"]
            while True:
                res = textract.get_expense_analysis(JobId=job_id)
                status = res.get("JobStatus")
                if status != "IN_PROGRESS":
                    break
                time.sleep(1)
            if status == "SUCCEEDED":
                expense_docs = res.get("ExpenseDocuments", [])
                groups = expense_docs[0].get("LineItemGroups", []) if expense_docs else []
                for group in groups:
                    for item in group.get("LineItems", []):
                        line = {}
                        for field in item.get("LineItemExpenseFields", []):
                            t = field.get("Type", {}).get("Text", "")
                            val = field.get("ValueDetection", {}).get("Text", "")
                            if t and val:
                                line[t] = val
                        if line:
                            key_line = tuple(line.values())
                            if key_line not in seen:
                                lines.append(line)
                                seen.add(key_line)
                if lines:
                    normalized = normalize_ticket_lines(lines)
                    if not normalized:
                        normalized = lines
                    json.dump(normalized, sys.stdout, ensure_ascii=False, indent=2)
                    return 0
        except Exception:
            pass

        # --- 2. Fallback DocumentAnalysis TABLES ---
        try:
            start = textract.start_document_analysis(
                DocumentLocation={"S3Object": {"Bucket": args.bucket, "Name": key}},
                FeatureTypes=["TABLES"],
            )
            job_id = start["JobId"]
            blocks = []
            token = None
            while True:
                params = {"JobId": job_id}
                if token:
                    params["NextToken"] = token
                res = textract.get_document_analysis(**params)
                blocks.extend(res.get("Blocks", []))
                token = res.get("NextToken")
                status = res.get("JobStatus")
                if status != "IN_PROGRESS" and not token:
                    break
                time.sleep(1)

            if status == "SUCCEEDED":
                block_map = {b["Id"]: b for b in blocks}
                for block in blocks:
                    if block.get("BlockType") != "TABLE":
                        continue
                    rows = {}
                    for rel in block.get("Relationships", []):
                        if rel.get("Type") != "CHILD":
                            continue
                        for cid in rel.get("Ids", []):
                            cell = block_map.get(cid, {})
                            if cell.get("BlockType") != "CELL":
                                continue
                            row = cell.get("RowIndex", 0)
                            col = cell.get("ColumnIndex", 0)
                            text_parts = []
                            for r in cell.get("Relationships", []):
                                if r.get("Type") != "CHILD":
                                    continue
                                for wid in r.get("Ids", []):
                                    word = block_map.get(wid, {})
                                    if word.get("BlockType") == "WORD":
                                        text_parts.append(word.get("Text", ""))
                            rows.setdefault(row, {})[col] = " ".join(text_parts)

                    table_lines, header_row = parse_table_with_headers(rows)

                    # guardar cabeçalhos se pedido
                    if args.save_headers and header_row and not headers_saved:
                        header_file = args.save_headers
                        with open(header_file, "w", encoding="utf-8") as f:
                            json.dump([h.strip() for h in header_row if h.strip()],
                                      f, ensure_ascii=False, indent=2)
                        headers_saved = True

                    for line in table_lines:
                        key_line = tuple(line.values())
                        if key_line not in seen:
                            lines.append(line)
                            seen.add(key_line)

                if lines:
                    normalized = normalize_ticket_lines(lines)
                    if not normalized:
                        normalized = lines
                    json.dump(normalized, sys.stdout, ensure_ascii=False, indent=2)
                    return 0
        except Exception:
            pass

        # --- 3. Fallback DocumentTextDetection ---
        start = textract.start_document_text_detection(
            DocumentLocation={"S3Object": {"Bucket": args.bucket, "Name": key}}
        )
        job_id = start["JobId"]
        blocks = []
        token = None
        while True:
            params = {"JobId": job_id}
            if token:
                params["NextToken"] = token
            res = textract.get_document_text_detection(**params)
            blocks.extend(res.get("Blocks", []))
            token = res.get("NextToken")
            status = res.get("JobStatus")
            if status != "IN_PROGRESS" and not token:
                break
            time.sleep(1)

        if status != "SUCCEEDED":
            print("Textract job failed", file=sys.stderr)
            return 3

        for block in blocks:
            if block.get("BlockType") != "LINE":
                continue
            text = block.get("Text", "")
            if text:
                line = {"Texto": text}
                if line not in lines:
                    lines.append(line)

        if lines:
            normalized = normalize_ticket_lines(lines)
            if not normalized:
                normalized = lines
            json.dump(normalized, sys.stdout, ensure_ascii=False, indent=2)
            return 0

        return 1

    finally:
        if key:
            try:
                s3.delete_object(Bucket=args.bucket, Key=key)
            except Exception:
                pass


if __name__ == "__main__":
    sys.exit(main())
