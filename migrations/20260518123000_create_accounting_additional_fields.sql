CREATE TABLE IF NOT EXISTS accounting_additional_field_taxonomies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    label VARCHAR(150) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_accounting_additional_field_taxonomies_name (name)
);

CREATE TABLE IF NOT EXISTS accounting_additional_field_taxonomy_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taxonomy_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_accounting_additional_field_taxonomy_terms_taxonomy_id (taxonomy_id),
    CONSTRAINT fk_accounting_additional_field_taxonomy_terms_taxonomy
        FOREIGN KEY (taxonomy_id) REFERENCES accounting_additional_field_taxonomies(id)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS accounting_additional_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope ENUM('client', 'supplier') NOT NULL,
    name VARCHAR(100) NOT NULL,
    label VARCHAR(150) NOT NULL,
    type ENUM('text', 'textarea', 'password', 'integer', 'decimal', 'select', 'multiselect', 'boolean_select', 'taxonomy') NOT NULL DEFAULT 'text',
    options TEXT DEFAULT NULL,
    taxonomy_id INT DEFAULT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    bootstrap_col TINYINT NOT NULL DEFAULT 6,
    bootstrap_offset TINYINT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_accounting_additional_fields_scope_name (scope, name),
    KEY idx_accounting_additional_fields_scope (scope),
    KEY idx_accounting_additional_fields_taxonomy_id (taxonomy_id),
    CONSTRAINT fk_accounting_additional_fields_taxonomy
        FOREIGN KEY (taxonomy_id) REFERENCES accounting_additional_field_taxonomies(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS accounting_entity_additional_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    field_id INT NOT NULL,
    value TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_accounting_entity_additional_values_entity_field (entity_id, field_id),
    KEY idx_accounting_entity_additional_values_entity_id (entity_id),
    KEY idx_accounting_entity_additional_values_field_id (field_id),
    CONSTRAINT fk_accounting_entity_additional_values_entity
        FOREIGN KEY (entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_accounting_entity_additional_values_field
        FOREIGN KEY (field_id) REFERENCES accounting_additional_fields(id)
        ON DELETE CASCADE
);
