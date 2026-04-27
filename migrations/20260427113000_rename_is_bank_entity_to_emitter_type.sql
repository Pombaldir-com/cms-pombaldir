ALTER TABLE accounting_entities
    CHANGE COLUMN is_bank_entity emitter_type TINYINT(1) NOT NULL DEFAULT 0;
