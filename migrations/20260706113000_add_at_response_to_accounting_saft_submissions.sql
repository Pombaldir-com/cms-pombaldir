ALTER TABLE accounting_saft_submissions
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'registado' AFTER file_size,
    ADD COLUMN at_response_code VARCHAR(10) DEFAULT NULL AFTER status,
    ADD COLUMN at_total_faturas VARCHAR(30) DEFAULT NULL AFTER at_response_code,
    ADD COLUMN at_total_creditos VARCHAR(30) DEFAULT NULL AFTER at_total_faturas,
    ADD COLUMN at_total_debitos VARCHAR(30) DEFAULT NULL AFTER at_total_creditos,
    ADD COLUMN at_warning TEXT DEFAULT NULL AFTER at_total_debitos,
    ADD COLUMN at_id_ficheiro VARCHAR(50) DEFAULT NULL AFTER at_warning,
    ADD COLUMN at_nome_ficheiro VARCHAR(255) DEFAULT NULL AFTER at_id_ficheiro,
    ADD COLUMN at_created_date VARCHAR(30) DEFAULT NULL AFTER at_nome_ficheiro,
    ADD COLUMN at_errors TEXT DEFAULT NULL AFTER at_created_date,
    ADD COLUMN at_response_raw MEDIUMTEXT DEFAULT NULL AFTER at_errors;
