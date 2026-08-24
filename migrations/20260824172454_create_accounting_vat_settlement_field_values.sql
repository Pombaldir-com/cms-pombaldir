-- Valores por campo da Declaracao Periodica de IVA (C{n}-DP), introduzidos
-- manualmente por empresa/periodo no ecra "Ver detalhes" do apuramento
-- (equivalente ao ecra campo-a-campo de window.php?act=wkfloproc da
-- intranet legacy). O valor "Ctr Ctb" continua calculado em tempo real a
-- partir de accounting_vat_field_formulas (fica 0.00 ate existir fonte real
-- de balancete do ERP-SINC) e nao e persistido. Ver
-- contabilidade/APURAMENTO_IVA.md.
CREATE TABLE IF NOT EXISTS accounting_vat_settlement_field_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    period_label VARCHAR(20) NOT NULL COMMENT 'ex: 2026-03 ou 2026-T1',
    field_number INT NOT NULL,
    dp_value DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_vat_settlement_field_value (accounting_entity_id, period_label, field_number),
    KEY idx_accounting_vat_settlement_field_value_entity (accounting_entity_id),
    CONSTRAINT fk_accounting_vat_settlement_field_value_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_vat_settlement_field_value_user
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
