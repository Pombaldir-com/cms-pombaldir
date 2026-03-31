CREATE TABLE IF NOT EXISTS internal_chat_channels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) DEFAULT NULL,
    name VARCHAR(150) NOT NULL,
    channel_type VARCHAR(20) NOT NULL DEFAULT 'group',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_internal_chat_channels_slug (slug),
    KEY idx_internal_chat_channels_type (channel_type),
    CONSTRAINT fk_internal_chat_channels_created_by
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS internal_chat_channel_members (
    channel_id INT NOT NULL,
    user_id INT NOT NULL,
    added_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id, user_id),
    KEY idx_internal_chat_members_user (user_id),
    CONSTRAINT fk_internal_chat_members_channel
        FOREIGN KEY (channel_id) REFERENCES internal_chat_channels(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_internal_chat_members_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_internal_chat_members_added_by
        FOREIGN KEY (added_by) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS internal_chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    channel_id INT NOT NULL,
    user_id INT DEFAULT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_internal_chat_messages_channel (channel_id, id),
    KEY idx_internal_chat_messages_user (user_id),
    CONSTRAINT fk_internal_chat_messages_channel
        FOREIGN KEY (channel_id) REFERENCES internal_chat_channels(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_internal_chat_messages_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);

INSERT INTO internal_chat_channels (slug, name, channel_type, created_by)
SELECT 'public', 'Canal Publico', 'public', NULL
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1
    FROM internal_chat_channels
    WHERE slug = 'public'
);
