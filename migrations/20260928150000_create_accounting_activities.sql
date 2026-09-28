-- Registo de atividade (eventos) por cliente. Porta o "Criar novo evento" da
-- intranet legacy (workflow.php?act=novo -> tabelas workflow, workflow_lin,
-- workflow_cat). Ao contrario do legado (workflow_cat.extrafield1 com um
-- blob serialize() e "Imobilizado" fixo na categoria 1), a configuracao do
-- campo adicional fica em colunas nomeadas.
CREATE TABLE IF NOT EXISTS accounting_activity_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    extra_field_type ENUM('none', 'month', 'date', 'text', 'number') NOT NULL DEFAULT 'none',
    extra_field_label VARCHAR(100) NOT NULL DEFAULT '',
    asks_fixed_assets TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'pergunta se houve venda/alienacao de imobilizado',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'ex: W-26123 (formato legado)',
    accounting_entity_id INT NULL,
    client_name VARCHAR(255) NOT NULL,
    client_code VARCHAR(50) NOT NULL DEFAULT '',
    client_nif VARCHAR(30) NOT NULL DEFAULT '',
    responsible_user_id INT NULL,
    notes MEDIUMTEXT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1=ativo, 0=anulado',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_accounting_activity_entity (accounting_entity_id),
    KEY idx_accounting_activity_responsible (responsible_user_id),
    KEY idx_accounting_activity_created_at (created_at),
    CONSTRAINT fk_accounting_activity_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_accounting_activity_responsible
        FOREIGN KEY (responsible_user_id) REFERENCES users(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_accounting_activity_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_activity_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    line_no INT NOT NULL,
    category_id INT NOT NULL,
    value VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'valor do campo adicional (mes em YYYY-MM, data em YYYY-MM-DD)',
    fixed_assets_sold TINYINT(1) NULL COMMENT 'NULL quando a categoria nao pergunta imobilizado',
    KEY idx_accounting_activity_line_activity (activity_id),
    KEY idx_accounting_activity_line_category (category_id),
    CONSTRAINT fk_accounting_activity_line_activity
        FOREIGN KEY (activity_id) REFERENCES accounting_activities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_activity_line_category
        FOREIGN KEY (category_id) REFERENCES accounting_activity_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
