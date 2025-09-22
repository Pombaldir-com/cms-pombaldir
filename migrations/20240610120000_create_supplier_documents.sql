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
