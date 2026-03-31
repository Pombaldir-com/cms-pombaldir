CREATE TABLE IF NOT EXISTS efatura_company_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    credential_label VARCHAR(150) NOT NULL DEFAULT '',
    portal_username VARCHAR(120) NOT NULL,
    portal_password_encrypted TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_ok_at DATETIME NULL,
    last_login_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_efatura_company_credentials_entity (entity_id),
    KEY idx_efatura_company_credentials_active (is_active)
);

CREATE TABLE IF NOT EXISTS efatura_sync_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    credential_id INT NULL,
    requested_by INT NULL,
    sync_mode VARCHAR(20) NOT NULL DEFAULT 'manual',
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    pages_total INT NOT NULL DEFAULT 0,
    pages_done INT NOT NULL DEFAULT 0,
    documents_found INT NOT NULL DEFAULT 0,
    documents_saved INT NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    debug_artifact VARCHAR(255) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_efatura_sync_jobs_entity (entity_id),
    KEY idx_efatura_sync_jobs_status (status),
    KEY idx_efatura_sync_jobs_period (period_start, period_end)
);

CREATE TABLE IF NOT EXISTS efatura_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    sync_job_id INT NULL,
    issuer_vat VARCHAR(30) NOT NULL DEFAULT '',
    issuer_name VARCHAR(255) NOT NULL DEFAULT '',
    customer_vat VARCHAR(30) NOT NULL DEFAULT '',
    invoice_no VARCHAR(60) NOT NULL,
    atcud VARCHAR(100) NOT NULL DEFAULT '',
    invoice_date DATE NOT NULL,
    invoice_type VARCHAR(10) NOT NULL DEFAULT '',
    document_status VARCHAR(10) NOT NULL DEFAULT '',
    sector VARCHAR(10) NOT NULL DEFAULT '',
    tax_payable DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    gross_total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    source_hash VARCHAR(64) NOT NULL DEFAULT '',
    raw_payload_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_efatura_documents_source (entity_id, source_hash),
    KEY idx_efatura_documents_invoice_date (invoice_date),
    KEY idx_efatura_documents_issuer_vat (issuer_vat),
    KEY idx_efatura_documents_sync_job (sync_job_id)
);

CREATE TABLE IF NOT EXISTS efatura_document_lines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_id INT NOT NULL,
    tax_point_date DATE NULL,
    debit_credit_indicator VARCHAR(2) NOT NULL DEFAULT '',
    tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    gross_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    total_tax_base DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_type VARCHAR(10) NOT NULL DEFAULT '',
    tax_country_region VARCHAR(10) NOT NULL DEFAULT '',
    tax_code VARCHAR(20) NOT NULL DEFAULT '',
    tax_percentage DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    total_tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    tax_exemption_code VARCHAR(20) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_efatura_document_lines_document (document_id)
);

CREATE TABLE IF NOT EXISTS efatura_sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_job_id INT NOT NULL,
    level VARCHAR(20) NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    context_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_efatura_sync_logs_job (sync_job_id)
);
