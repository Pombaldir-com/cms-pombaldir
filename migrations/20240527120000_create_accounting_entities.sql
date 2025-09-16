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
