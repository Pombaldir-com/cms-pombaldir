CREATE TABLE IF NOT EXISTS accounting_entity_admin_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    user_id INT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    can_manage_extranet TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_documents TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_ai TINYINT(1) NOT NULL DEFAULT 0,
    can_manage_users TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_entity_admin_users_entity_user (accounting_entity_id, user_id),
    KEY idx_accounting_entity_admin_users_user (user_id),
    CONSTRAINT fk_accounting_entity_admin_users_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_entity_admin_users_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
