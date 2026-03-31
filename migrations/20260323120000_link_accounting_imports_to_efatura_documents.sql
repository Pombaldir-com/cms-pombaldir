SET @accounting_imports_exists := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'accounting_imports'
);

SET @accounting_imports_link_sql := IF(
    @accounting_imports_exists > 0,
    'ALTER TABLE accounting_imports
        ADD COLUMN efatura_document_id INT NULL DEFAULT NULL AFTER cab_id,
        ADD COLUMN efatura_match_method VARCHAR(30) NOT NULL DEFAULT '''' AFTER efatura_document_id,
        ADD COLUMN efatura_matched_at DATETIME NULL DEFAULT NULL AFTER efatura_match_method,
        ADD KEY idx_accounting_imports_efatura_document_id (efatura_document_id)',
    'SELECT 1'
);

PREPARE accounting_imports_link_stmt FROM @accounting_imports_link_sql;
EXECUTE accounting_imports_link_stmt;
DEALLOCATE PREPARE accounting_imports_link_stmt;
