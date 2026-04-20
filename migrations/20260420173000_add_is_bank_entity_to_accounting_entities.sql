ALTER TABLE accounting_entities
    ADD COLUMN is_bank_entity TINYINT(1) NOT NULL DEFAULT 0 AFTER erp_client_code;
