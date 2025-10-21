ALTER TABLE accounting_imports
    ADD COLUMN account_original LONGTEXT DEFAULT NULL AFTER account;
