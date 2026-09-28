-- Os eventos passam a ser identificados no URL por GUID
-- (contabilidade/atividades/{uuid}) em vez do id numerico.
ALTER TABLE accounting_activities
    ADD COLUMN uuid VARCHAR(36) NULL AFTER id;

UPDATE accounting_activities SET uuid = UUID() WHERE uuid IS NULL OR uuid = '';

ALTER TABLE accounting_activities
    ADD UNIQUE KEY unique_accounting_activity_uuid (uuid);
