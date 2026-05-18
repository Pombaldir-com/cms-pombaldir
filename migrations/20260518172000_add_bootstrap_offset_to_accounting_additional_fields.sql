ALTER TABLE accounting_additional_fields
    ADD COLUMN bootstrap_offset TINYINT NOT NULL DEFAULT 0 AFTER bootstrap_col;
