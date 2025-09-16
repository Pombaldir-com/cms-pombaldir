ALTER TABLE accounting_entities
    ADD COLUMN erp_client_code VARCHAR(50) DEFAULT '' AFTER erp_database;

UPDATE accounting_entities
SET erp_client_code = erp_database
WHERE erp_database <> '';

UPDATE accounting_entities
SET erp_database = ''
WHERE erp_database <> '';
