ALTER TABLE accounting_imports
    ADD COLUMN cab_id VARCHAR(100) DEFAULT '' AFTER line_items;
