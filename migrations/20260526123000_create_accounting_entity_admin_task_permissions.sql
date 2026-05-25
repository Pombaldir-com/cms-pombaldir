CREATE TABLE IF NOT EXISTS accounting_entity_admin_task_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    permission_key VARCHAR(120) NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_entity_admin_task_permission (accounting_entity_id, permission_key, user_id),
    KEY idx_accounting_entity_admin_task_permission_entity (accounting_entity_id),
    KEY idx_accounting_entity_admin_task_permission_user (user_id),
    KEY idx_accounting_entity_admin_task_permission_key (permission_key),
    CONSTRAINT fk_accounting_entity_admin_task_permission_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_entity_admin_task_permission_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
