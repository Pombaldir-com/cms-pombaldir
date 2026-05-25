CREATE TABLE IF NOT EXISTS client_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    tenant_slug VARCHAR(120) NOT NULL,
    username VARCHAR(120) NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(180) DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_client_users_tenant_username (tenant_slug, username),
    UNIQUE KEY uq_client_users_tenant_email (tenant_slug, email),
    KEY idx_client_users_entity (accounting_entity_id),
    CONSTRAINT fk_client_users_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
