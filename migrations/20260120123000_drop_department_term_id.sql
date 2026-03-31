ALTER TABLE users
    DROP FOREIGN KEY fk_users_department_term_id,
    DROP INDEX idx_users_department_term_id,
    DROP COLUMN department_term_id;
