ALTER TABLE accounting_additional_fields
    MODIFY COLUMN type ENUM('text', 'textarea', 'password', 'integer', 'decimal', 'select', 'multiselect', 'boolean_select', 'taxonomy') NOT NULL DEFAULT 'text';
