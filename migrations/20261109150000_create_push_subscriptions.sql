-- Notificacoes push (Web Push) para a app instalada no telemovel (PWA) e para
-- o browser, mesmo com o CRM fechado. Ver docs/calendario.md, "Notificacoes push".
--
-- push_subscriptions: uma linha por browser/dispositivo (o endpoint dado pelo
-- servico de push). Pertence ao utilizador, nao a sessao: continua a receber
-- depois de a sessao terminar, ate ser desligada no perfil ou o servico de
-- push dizer que expirou (404/410).
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint VARCHAR(1000) NOT NULL,
    p256dh VARCHAR(200) NOT NULL,
    auth VARCHAR(100) NOT NULL,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_ok_at DATETIME NULL,
    failures INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_push_subscriptions_endpoint (endpoint_hash),
    KEY idx_push_subscriptions_user (user_id),
    CONSTRAINT fk_push_subscriptions_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avisos do calendario ja enviados por push (independente de delivered_at,
-- que marca o aviso mostrado na pagina aberta).
ALTER TABLE calendar_event_notices
    ADD COLUMN pushed_at DATETIME NULL AFTER delivered_at,
    ADD KEY idx_calendar_event_notices_push (pushed_at, created_at);
