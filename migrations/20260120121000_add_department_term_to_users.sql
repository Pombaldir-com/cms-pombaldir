ALTER TABLE users
    ADD COLUMN department_term_id INT DEFAULT NULL,
    ADD INDEX idx_users_department_term_id (department_term_id),
    ADD CONSTRAINT fk_users_department_term_id
        FOREIGN KEY (department_term_id) REFERENCES taxonomy_terms(id)
        ON DELETE SET NULL;
