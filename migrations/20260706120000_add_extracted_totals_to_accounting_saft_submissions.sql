ALTER TABLE accounting_saft_submissions
    ADD COLUMN saft_fiscal_year VARCHAR(10) DEFAULT NULL AFTER at_response_raw,
    ADD COLUMN saft_start_date VARCHAR(20) DEFAULT NULL AFTER saft_fiscal_year,
    ADD COLUMN saft_end_date VARCHAR(20) DEFAULT NULL AFTER saft_start_date,
    ADD COLUMN saft_number_of_entries INT DEFAULT NULL AFTER saft_end_date,
    ADD COLUMN saft_total_debit DECIMAL(15,2) DEFAULT NULL AFTER saft_number_of_entries,
    ADD COLUMN saft_total_credit DECIMAL(15,2) DEFAULT NULL AFTER saft_total_debit,
    ADD COLUMN saft_extraction_error TEXT DEFAULT NULL AFTER saft_total_credit;
