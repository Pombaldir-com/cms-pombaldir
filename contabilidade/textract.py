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
                    json.dump(lines, sys.stdout, ensure_ascii=False, indent=2)
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
                    json.dump(lines, sys.stdout, ensure_ascii=False, indent=2)
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
            json.dump(lines, sys.stdout, ensure_ascii=False, indent=2)
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
