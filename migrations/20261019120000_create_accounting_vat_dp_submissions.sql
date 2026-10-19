-- Fecho da tarefa "Envio DP IVA" por empresa/periodo (fase 8 da intranet
-- legacy, wkflow_cab com tarefa=8). Ver contabilidade/APURAMENTO_IVA.md,
-- secao "Tarefa Envio DP IVA". Tal como em accounting_vat_settlements, os
-- valores ficam em colunas nomeadas em vez do blob serialize() do legado.
CREATE TABLE IF NOT EXISTS accounting_vat_dp_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    period_type ENUM('mensal', 'trimestral') NOT NULL,
    period_year INT NOT NULL,
    period_ref INT NOT NULL COMMENT 'mes (1-12) quando mensal, trimestre (1-4) quando trimestral',
    period_label VARCHAR(20) NOT NULL COMMENT 'ex: 2026-03 ou 2026-T1',
    result_type ENUM('pagar', 'credito') NOT NULL,
    valor DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'IVA a pagar ou em credito, consoante result_type',
    valor_reembolso DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sent_to VARCHAR(500) NULL COMMENT 'destinatarios do email ao cliente (NULL = fechado sem envio)',
    email_subject VARCHAR(255) NULL,
    attachment_filename VARCHAR(255) NULL,
    observacao TEXT NULL,
    closed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_vat_dp_submission_period (accounting_entity_id, period_label),
    KEY idx_accounting_vat_dp_submission_entity (accounting_entity_id),
    KEY idx_accounting_vat_dp_submission_closed_by (closed_by),
    CONSTRAINT fk_accounting_vat_dp_submission_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_vat_dp_submission_user
        FOREIGN KEY (closed_by) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
