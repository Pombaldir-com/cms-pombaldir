ALTER TABLE accounting_imports
    ADD COLUMN field_I2 VARCHAR(255) DEFAULT '' AFTER field_I1,
    ADD COLUMN field_M VARCHAR(255) DEFAULT '' AFTER field_I8;
