CREATE TABLE IF NOT EXISTS ai_assistant_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    session_id VARCHAR(64) DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    actions JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_logs_user_id (user_id),
    INDEX idx_ai_logs_session_id (session_id),
    CONSTRAINT fk_ai_logs_user_id
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);
