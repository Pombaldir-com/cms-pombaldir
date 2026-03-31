ALTER TABLE accounting_classifications
    ADD COLUMN skip_ocr_lines TINYINT(1) NOT NULL DEFAULT 0 AFTER account;
