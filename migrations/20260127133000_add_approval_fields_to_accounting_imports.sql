ALTER TABLE accounting_imports
    ADD COLUMN approval_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    ADD COLUMN approval_note TEXT DEFAULT NULL,
    ADD COLUMN approved_by INT DEFAULT NULL,
    ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL,
    ADD INDEX idx_accounting_imports_approval_status (approval_status),
    ADD CONSTRAINT fk_accounting_imports_approved_by
        FOREIGN KEY (approved_by) REFERENCES users(id)
        ON DELETE SET NULL;
