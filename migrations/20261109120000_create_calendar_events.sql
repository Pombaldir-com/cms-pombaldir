-- Calendario do dashboard (porta da "Agenda" da intranet legacy, com a
-- implementacao da intranet PombalData como referencia). Ver docs/calendario.md.
--
-- calendar_events: eventos criados no calendario (ou no telemovel via CalDAV),
-- com cliente opcional (accounting_entities, com copia do nome/NIF/codigo para
-- nao perder a referencia se a entidade mudar) e lembrete antes do inicio.
-- caldav_uri/caldav_uid guardam o nome e o UID escolhidos pelo telemovel nos
-- eventos criados la.
CREATE TABLE IF NOT EXISTS calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    location VARCHAR(190) NOT NULL DEFAULT '',
    start_at DATETIME NOT NULL,
    end_at DATETIME NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    reminder_minutes INT UNSIGNED NOT NULL DEFAULT 60,
    accounting_entity_id INT NULL,
    client_name VARCHAR(255) NOT NULL DEFAULT '',
    client_nif VARCHAR(20) NOT NULL DEFAULT '',
    client_code VARCHAR(50) NOT NULL DEFAULT '',
    caldav_uri VARCHAR(255) NULL,
    caldav_uid VARCHAR(255) NULL,
    created_by INT NOT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_calendar_events_uuid (uuid),
    UNIQUE KEY uq_calendar_events_caldav_uri (caldav_uri),
    KEY idx_calendar_events_start (start_at),
    KEY idx_calendar_events_created_by (created_by),
    KEY idx_calendar_events_entity (accounting_entity_id),
    CONSTRAINT fk_calendar_events_entity
        FOREIGN KEY (accounting_entity_id) REFERENCES accounting_entities(id)
        ON DELETE SET NULL,
    CONSTRAINT fk_calendar_events_creator
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Utilizadores convidados (agenda_user no legacy). O criador nao entra aqui.
CREATE TABLE IF NOT EXISTS calendar_event_members (
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, user_id),
    KEY idx_calendar_event_members_user (user_id),
    CONSTRAINT fk_calendar_event_members_event
        FOREIGN KEY (event_id) REFERENCES calendar_events(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_calendar_event_members_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Avisos por utilizador: convite (kind=invite) e lembrete (kind=reminder).
-- Um aviso por evento + utilizador + tipo + inicio: mudar a hora gera outro
-- lembrete. delivered_at fica preenchido quando o browser o mostra
-- (assets/js/calendario-avisos.js), para nao aparecer duas vezes.
CREATE TABLE IF NOT EXISTS calendar_event_notices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    kind VARCHAR(20) NOT NULL,
    event_start DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME NULL,
    UNIQUE KEY uq_calendar_event_notices (event_id, user_id, kind, event_start),
    KEY idx_calendar_event_notices_pending (user_id, delivered_at),
    CONSTRAINT fk_calendar_event_notices_event
        FOREIGN KEY (event_id) REFERENCES calendar_events(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_calendar_event_notices_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Acessos CalDAV (calendario no telemovel): uma password por dispositivo,
-- so o hash, criada e revogada no perfil do utilizador.
CREATE TABLE IF NOT EXISTS caldav_access_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NOT NULL DEFAULT '',
    KEY idx_caldav_access_tokens_user (user_id),
    CONSTRAINT fk_caldav_access_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
