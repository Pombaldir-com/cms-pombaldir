CREATE TABLE IF NOT EXISTS accounting_entity_ai_instructions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    acquirer_nif VARCHAR(30) NOT NULL,
    emitter_nif VARCHAR(30) NOT NULL,
    instructions TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_accounting_entity_ai_pair (acquirer_nif, emitter_nif)
);
