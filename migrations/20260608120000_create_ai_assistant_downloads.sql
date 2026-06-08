-- Ficheiros gerados pelo assistente AI (ex.: balancete CSV, exportacoes TXT/PDF)
-- disponibilizados ao utilizador via link de download autenticado. Guardados em
-- BD (e nao em uploads/ ou data/, que sao servidos diretamente) para garantir
-- verificacao de dono e expiracao. Servidos por assistant-download.php.
CREATE TABLE IF NOT EXISTS ai_assistant_downloads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token CHAR(48) NOT NULL,
    user_id INT NOT NULL,
    session_id VARCHAR(64) NOT NULL DEFAULT '',
    filename VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    content LONGBLOB NOT NULL,
    size_bytes INT NOT NULL DEFAULT 0,
    created_at INT NOT NULL,
    expires_at INT NULL,
    UNIQUE KEY uniq_ai_downloads_token (token),
    KEY idx_ai_downloads_user (user_id),
    KEY idx_ai_downloads_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
