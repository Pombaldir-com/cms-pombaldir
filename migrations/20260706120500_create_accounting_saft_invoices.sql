CREATE TABLE IF NOT EXISTS accounting_saft_invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    invoice_no VARCHAR(60) NOT NULL,
    atcud VARCHAR(60) DEFAULT NULL,
    invoice_type VARCHAR(10) DEFAULT NULL,
    invoice_status VARCHAR(5) DEFAULT NULL,
    invoice_date DATE DEFAULT NULL,
    system_entry_date VARCHAR(30) DEFAULT NULL,
    customer_id VARCHAR(60) DEFAULT NULL,
    source_id VARCHAR(60) DEFAULT NULL,
    tax_payable DECIMAL(15,2) DEFAULT NULL,
    net_total DECIMAL(15,2) DEFAULT NULL,
    gross_total DECIMAL(15,2) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_saft_invoices_submission (submission_id),
    INDEX idx_saft_invoices_invoice_no (invoice_no),
    INDEX idx_saft_invoices_invoice_date (invoice_date),
    CONSTRAINT fk_saft_invoices_submission
        FOREIGN KEY (submission_id) REFERENCES accounting_saft_submissions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
