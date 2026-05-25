CREATE TABLE IF NOT EXISTS accounting_entity_extranet_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    erp_software VARCHAR(30) NOT NULL DEFAULT '',
    erp_api_url VARCHAR(255) NOT NULL DEFAULT '',
    erp_api_username VARCHAR(120) NOT NULL DEFAULT '',
    erp_api_password VARCHAR(255) NOT NULL DEFAULT '',
    erp_api_token VARCHAR(255) NOT NULL DEFAULT '',
    support_enabled TINYINT(1) NOT NULL DEFAULT 0,
    support_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_entity_extranet_settings_entity (accounting_entity_id),
    KEY idx_accounting_entity_extranet_settings_support_user (support_user_id),
    CONSTRAINT fk_accounting_entity_extranet_settings_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_entity_extranet_settings_support_user
        FOREIGN KEY (support_user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
