UPDATE accounting_entities
SET erp_database = LOWER(REPLACE(TRIM(erp_client_code), '-', '_'))
WHERE LOWER(REPLACE(TRIM(COALESCE(erp_client_code, '')), '-', '_')) REGEXP '^emp_[0-9]+$'
  AND (
        COALESCE(NULLIF(TRIM(erp_database), ''), '') = ''
        OR LOWER(REPLACE(TRIM(COALESCE(erp_database, '')), '-', '_')) NOT REGEXP '^emp_[0-9]+$'
      );

UPDATE accounting_entities
SET erp_database = LOWER(REPLACE(TRIM(erp_database), '-', '_'))
WHERE LOWER(REPLACE(TRIM(COALESCE(erp_database, '')), '-', '_')) REGEXP '^emp_[0-9]+$';

UPDATE accounting_entities
SET erp_client_code = ''
WHERE LOWER(REPLACE(TRIM(COALESCE(erp_client_code, '')), '-', '_')) REGEXP '^emp_[0-9]+$';
