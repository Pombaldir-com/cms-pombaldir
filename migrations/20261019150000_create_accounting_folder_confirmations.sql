-- Tarefa "Confirmacao de Pasta" (fase 4 "Lancamento de docs" da intranet
-- legacy). Ver contabilidade/CONFIRMACAO_PASTA.md.
--
-- Valores da fita (somas de talao conferidas a mao) por empresa / mes /
-- diario. No legacy ficavam em mov_fita_calc como uma expressao
-- serialize()'d ("10.5+20+3"); aqui cada valor e uma linha.
CREATE TABLE IF NOT EXISTS accounting_folder_tape_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    period_year INT NOT NULL,
    period_month TINYINT NOT NULL,
    journal_code INT NOT NULL COMMENT 'intCodDiario do ERP',
    valor DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_folder_tape_period (accounting_entity_id, period_year, period_month, journal_code),
    CONSTRAINT fk_folder_tape_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_folder_tape_user
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fecho da tarefa por empresa/mes (wkflow_cab com tarefa=4 no legacy).
-- summary_json guarda o estado da conferencia no momento do fecho (totais
-- por diario ERP vs fita e SAF-T vs contabilidade).
CREATE TABLE IF NOT EXISTS accounting_folder_confirmations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    period_year INT NOT NULL,
    period_month TINYINT NOT NULL,
    period_label VARCHAR(20) NOT NULL COMMENT 'ex: 2026-09',
    divergences_count INT NOT NULL DEFAULT 0,
    summary_json LONGTEXT NULL,
    observacao TEXT NULL,
    closed_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_folder_confirmation_period (accounting_entity_id, period_label),
    KEY idx_folder_confirmation_closed_by (closed_by),
    CONSTRAINT fk_folder_confirmation_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_folder_confirmation_user
        FOREIGN KEY (closed_by) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
