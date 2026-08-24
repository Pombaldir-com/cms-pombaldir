-- Fecho da tarefa "Apuramento de IVA" por empresa/periodo. Ver
-- contabilidade/APURAMENTO_IVA.md. Ao contrario do legado (wkflow_cab com um
-- blob serialize()), o resultado fica em colunas nomeadas.
CREATE TABLE IF NOT EXISTS accounting_vat_settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    period_type ENUM('mensal', 'trimestral') NOT NULL,
    period_year INT NOT NULL,
    period_ref INT NOT NULL COMMENT 'mes (1-12) quando mensal, trimestre (1-4) quando trimestral',
    period_label VARCHAR(20) NOT NULL COMMENT 'ex: 2026-03 ou 2026-T1',
    result_type ENUM('pagar', 'credito') NOT NULL,
    valor_pagar DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    valor_recuperar DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    observacao TEXT NULL,
    closed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_vat_settlement_period (accounting_entity_id, period_label),
    KEY idx_accounting_vat_settlement_entity (accounting_entity_id),
    KEY idx_accounting_vat_settlement_closed_by (closed_by),
    CONSTRAINT fk_accounting_vat_settlement_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_vat_settlement_user
        FOREIGN KEY (closed_by) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
