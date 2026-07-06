CREATE TABLE IF NOT EXISTS accounting_saft_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    accounting_entity_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    period_year SMALLINT NOT NULL,
    period_month TINYINT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_saft_submissions_entity (accounting_entity_id),
    INDEX idx_saft_submissions_user (user_id),
    INDEX idx_saft_submissions_period (period_year, period_month),
    CONSTRAINT fk_saft_submissions_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_saft_submissions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
