#!/usr/bin/env python3
"""Run AWS Textract and export parsed invoice lines to CSV."""
import argparse
import boto3
import csv
import os
import re
import sys
import time
import uuid


# Regular expression to capture invoice line fields
LINE_ITEM_RE = re.compile(
    r"""
    (?P<arm>\d+)\s+
    (?P<codigo_artigo>[A-Z0-9-]+)\s+
    (?P<descricao>.+?)\s+
    (?P<quantidade>\d+(?:,\d+)?)\s+
    (?P<unidade>\w+)\s+
    (?P<preco_unitario>\d+(?:,\d+)?)\s+
    (?P<percentagem_desconto>\d+(?:,\d+)?)\s+
    (?P<desconto_valor>\d+(?:,\d+)?)\s+
    (?P<valor_liquido>\d+(?:,\d+)?)\s+
    (?P<imposto>\d+(?:,\d+)?)
    """,
    re.VERBOSE,
)


def parse_invoice_line_text(text: str) -> dict:
    """Parse a single invoice line using regular expressions."""
    match = LINE_ITEM_RE.search(text.strip())
    if not match:
        raise ValueError("Unexpected OCR output: %s" % text)
    groups = match.groupdict()
    to_float = lambda v: float(v.replace(',', '.')) if v else 0.0
    return {
        "arm": int(groups["arm"]) if groups["arm"].isdigit() else groups["arm"],
        "codigo_artigo": groups["codigo_artigo"],
        "descricao": groups["descricao"].strip(),
        "quantidade": to_float(groups["quantidade"]),
        "unidade": groups["unidade"],
        "preco_unitario": to_float(groups["preco_unitario"]),
        "percentagem_desconto": to_float(groups["percentagem_desconto"]),
        "desconto_valor": to_float(groups["desconto_valor"]),
        "valor_liquido": to_float(groups["valor_liquido"]),
        "imposto": to_float(groups["imposto"]),
    }


def write_csv(lines, fp):
    """Write invoice lines to CSV using a fixed column order."""
    fieldnames = [
        "arm",
        "codigo_artigo",
        "descricao",
        "quantidade",
        "unidade",
        "preco_unitario",
        "percentagem_desconto",
        "desconto_valor",
        "valor_liquido",
        "imposto",
    ]
    writer = csv.DictWriter(fp, fieldnames=fieldnames, extrasaction="ignore")
    writer.writeheader()
    writer.writerows(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Process an invoice file with AWS Textract")
    parser.add_argument("file", help="Path to the document to analyse")
    parser.add_argument("--bucket", default=os.environ.get("AWS_TEXTRACT_BUCKET"), help="S3 bucket")
    parser.add_argument("--region", default=os.environ.get("AWS_REGION", "us-east-1"), help="AWS region")
    parser.add_argument("-o", "--output", help="CSV file to write (default: stdout)")
    args = parser.parse_args()

    if not args.bucket:
        print("S3 bucket not configured", file=sys.stderr)
        return 2

    session = boto3.Session(region_name=args.region)
    s3 = session.client("s3")
    textract = session.client("textract")
    key = None
    out = open(args.output, "w", newline="", encoding="utf-8") if args.output else sys.stdout
    try:
        try:
            s3.head_bucket(Bucket=args.bucket)
        except Exception:
            s3.create_bucket(Bucket=args.bucket)
            s3.get_waiter("bucket_exists").wait(Bucket=args.bucket)
        key = f"textract/{uuid.uuid4()}" + os.path.splitext(args.file)[1]
        s3.upload_file(args.file, args.bucket, key)

        # Attempt ExpenseAnalysis first
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
                lines = []
                for group in groups:
                    for item in group.get("LineItems", []):
                        line = {
                            "arm": "",
                            "codigo_artigo": "",
                            "descricao": "",
                            "quantidade": "",
                            "unidade": "",
                            "preco_unitario": "",
                            "percentagem_desconto": "",
                            "desconto_valor": "",
                            "valor_liquido": "",
                            "imposto": "",
                            "text": "",
                        }
                        for field in item.get("LineItemExpenseFields", []):
                            t = field.get("Type", {}).get("Text", "")
                            val = field.get("ValueDetection", {}).get("Text", "")
                            num = float(val.replace(',', '.')) if val else 0.0
                            if t == "ITEM":
                                line["descricao"] = val
                            elif t == "QUANTITY":
                                line["quantidade"] = num
                            elif t in ("UNIT_PRICE", "PRICE"):
                                line["preco_unitario"] = num
                            elif t == "UNIT":
                                line["unidade"] = val
                            elif t == "AMOUNT":
                                line["valor_liquido"] = num
                            elif t == "TAX_RATE":
                                line["imposto"] = num
                            elif t == "TAX":
                                if "PERCENTAGE" in field.get("EntityTypes", []) or "%" in val:
                                    line["imposto"] = num
                            elif t in ("PRODUCT_CODE", "ITEM_CODE", "SKU"):
                                line["codigo_artigo"] = val
                            elif t == "DISCOUNT":
                                line["desconto_valor"] = num
                        line["text"] = line["descricao"].strip()
                        lines.append(line)
                if lines:
                    write_csv(lines, out)
                    return 0
        except Exception:
            pass  # fallback to DocumentTextDetection

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
        lines = []
        for block in blocks:
            if block.get("BlockType") != "LINE":
                continue
            text = block.get("Text", "")
            try:
                fields = parse_invoice_line_text(text)
            except Exception:
                fields = {
                    "arm": "",
                    "codigo_artigo": "",
                    "descricao": "",
                    "quantidade": "",
                    "unidade": "",
                    "preco_unitario": "",
                    "percentagem_desconto": "",
                    "desconto_valor": "",
                    "valor_liquido": "",
                    "imposto": "",
                }
            fields["text"] = text
            lines.append(fields)
        write_csv(lines, out)
        return 0
    finally:
        if out is not sys.stdout:
            out.close()
        if key:
            try:
                s3.delete_object(Bucket=args.bucket, Key=key)
            except Exception:
                pass


if __name__ == "__main__":
    sys.exit(main())
