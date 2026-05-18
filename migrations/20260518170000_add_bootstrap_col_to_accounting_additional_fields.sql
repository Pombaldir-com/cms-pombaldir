ALTER TABLE accounting_additional_fields
    ADD COLUMN bootstrap_col TINYINT NOT NULL DEFAULT 6 AFTER sort_order;
