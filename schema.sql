-- Schema for CMS using Gentelella interface
-- This file can be executed using a MySQL client to create the necessary tables.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    photo VARCHAR(255) DEFAULT NULL,
    role TINYINT NOT NULL DEFAULT 3 COMMENT '1=superadmin,2=administrator,3=user',
    ai_chat_floating TINYINT(1) NOT NULL DEFAULT 0,
    ai_read_only TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    entity VARCHAR(100) NOT NULL,
    entity_id INT DEFAULT NULL,
    meta JSON DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user_id (user_id),
    INDEX idx_audit_entity (entity, entity_id),
    CONSTRAINT fk_audit_user_id
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS content_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    label VARCHAR(100) NOT NULL,
    icon VARCHAR(100) NOT NULL DEFAULT 'fa fa-file-text',
    sort_order INT NOT NULL DEFAULT 0,
    show_author TINYINT(1) NOT NULL DEFAULT 0,
    show_date TINYINT(1) NOT NULL DEFAULT 0,
    show_taxonomies TINYINT(1) NOT NULL DEFAULT 1,
    api_enabled TINYINT(1) NOT NULL DEFAULT 0,
    title_grid_row INT NOT NULL DEFAULT 0,
    title_grid_col INT NOT NULL DEFAULT 0,
    title_grid_width INT NOT NULL DEFAULT 12,
    body_grid_row INT NOT NULL DEFAULT 0,
    body_grid_col INT NOT NULL DEFAULT 0,
    body_grid_width INT NOT NULL DEFAULT 12,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_type_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    label VARCHAR(100) NOT NULL,
    type ENUM('text','textarea','number','date','datetime','select','taxonomy','content','image','multitaxonomy','multicontent') NOT NULL,
    options TEXT,
    required TINYINT(1) NOT NULL DEFAULT 0,
    show_in_list TINYINT(1) NOT NULL DEFAULT 0,
    sortable TINYINT(1) NOT NULL DEFAULT 1,
    grid_row INT NOT NULL DEFAULT 0,
    grid_col INT NOT NULL DEFAULT 0,


    grid_width INT NOT NULL DEFAULT 12,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS taxonomies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    label VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS taxonomy_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taxonomy_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS user_taxonomy_terms (
    user_id INT NOT NULL,
    term_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, term_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES taxonomy_terms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS content (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_type_id INT NOT NULL,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS custom_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    content_id INT NOT NULL,
    field_id INT NOT NULL,
    value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS content_type_taxonomy (
    content_type_id INT NOT NULL,
    taxonomy_id INT NOT NULL,
    grid_row INT NOT NULL DEFAULT 0,
    grid_col INT NOT NULL DEFAULT 0,
    grid_width INT NOT NULL DEFAULT 12,
    PRIMARY KEY (content_type_id, taxonomy_id),
    FOREIGN KEY (content_type_id) REFERENCES content_types(id) ON DELETE CASCADE,
    FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS content_taxonomy (
    content_id INT NOT NULL,
    taxonomy_id INT NOT NULL,
    term_id INT NOT NULL,
    PRIMARY KEY (content_id, taxonomy_id, term_id),
    FOREIGN KEY (content_id) REFERENCES content(id) ON DELETE CASCADE,
    FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES taxonomy_terms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS accounting_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_path VARCHAR(255) NOT NULL,
    field1 VARCHAR(255) NOT NULL,
    field2 VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS accounting_classifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emitter VARCHAR(255) NOT NULL,
    acquirer VARCHAR(255) NOT NULL,
    doc_type VARCHAR(50) NOT NULL,
    account VARCHAR(255) NOT NULL,
    skip_ocr_lines TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY unique_classification (emitter, acquirer, doc_type)
);

CREATE TABLE IF NOT EXISTS supplier_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emitter VARCHAR(255) NOT NULL,
    acquirer VARCHAR(255) NOT NULL,
    doc_codigo VARCHAR(255) NOT NULL,
    erp_codigo VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_supplier_document (emitter, acquirer, doc_codigo)
);

CREATE TABLE IF NOT EXISTS accounting_entities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nif VARCHAR(30) NOT NULL,
    name VARCHAR(255) NOT NULL,
    erp_database VARCHAR(255) DEFAULT '',
    erp_client_code VARCHAR(50) DEFAULT '',
    entity_type VARCHAR(50) NOT NULL DEFAULT 'adquirente',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_accounting_entity_nif (nif)
);

CREATE TABLE IF NOT EXISTS accounting_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_A VARCHAR(255) DEFAULT '',
    field_B VARCHAR(255) DEFAULT '',
    field_C VARCHAR(255) DEFAULT '',
    field_D VARCHAR(255) DEFAULT '',
    field_E VARCHAR(255) DEFAULT '',
    field_F VARCHAR(255) DEFAULT '',
    field_G VARCHAR(255) DEFAULT '',
    field_H VARCHAR(255) DEFAULT '',
    field_I1 VARCHAR(255) DEFAULT '',
    field_I3 VARCHAR(255) DEFAULT '',
    field_I4 VARCHAR(255) DEFAULT '',
    field_I5 VARCHAR(255) DEFAULT '',
    field_I6 VARCHAR(255) DEFAULT '',
    field_I7 VARCHAR(255) DEFAULT '',
    field_I8 VARCHAR(255) DEFAULT '',
    field_N VARCHAR(255) DEFAULT '',
    field_O VARCHAR(255) DEFAULT '',
    field_Q VARCHAR(255) DEFAULT '',
    field_R VARCHAR(255) DEFAULT '',
    account VARCHAR(255) DEFAULT '',
    account_original LONGTEXT DEFAULT NULL,
    cost_center VARCHAR(255) DEFAULT '',
    line_items LONGTEXT DEFAULT NULL,
    cab_id VARCHAR(100) DEFAULT '',
    filename VARCHAR(255) DEFAULT '',
    import_type TINYINT DEFAULT 1,
    approval_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    approval_note TEXT DEFAULT NULL,
    approved_by INT DEFAULT NULL,
    approved_at TIMESTAMP NULL DEFAULT NULL,
    dte_add TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ai_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'open',
    assigned_to INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS ai_assistant_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    session_id VARCHAR(64) DEFAULT NULL,
    summary TEXT DEFAULT NULL,
    actions JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert a sample admin user. Replace the password hash with a secure hash generated by PHP's password_hash()
-- For example, to generate a password hash for 'admin123', run in PHP: echo password_hash('admin123', PASSWORD_DEFAULT);
INSERT INTO users (username, password, role) VALUES ('admin', '$2y$10$ht6AbcYORJTbktFyX2Jv/eMIF8eKnT/VkGAQxtKUdXRAXNdnjTSfG', 1);

-- Verification queries (optional)
-- Run the statements below to confirm that all tables and columns were created.
SHOW TABLES;
SHOW COLUMNS FROM users;
SHOW COLUMNS FROM content_types;
SHOW COLUMNS FROM custom_fields;
SHOW COLUMNS FROM taxonomies;
SHOW COLUMNS FROM taxonomy_terms;
SHOW COLUMNS FROM content;
SHOW COLUMNS FROM custom_values;
SHOW COLUMNS FROM content_taxonomy;
SHOW COLUMNS FROM accounting_documents;
SHOW COLUMNS FROM accounting_classifications;
SHOW COLUMNS FROM accounting_imports;
