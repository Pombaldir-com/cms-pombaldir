ALTER TABLE accounting_entities
    ADD COLUMN uuid VARCHAR(36) DEFAULT NULL;

ALTER TABLE accounting_entities
    ADD UNIQUE KEY unique_accounting_entity_uuid (uuid);
