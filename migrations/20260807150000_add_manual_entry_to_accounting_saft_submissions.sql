-- Permite marcar uma empresa/periodo como "tratado" sem ficheiro SAF-T
-- (ex.: o proprio cliente ja enviou diretamente a AT, ou nao houve vendas
-- nesse periodo), para deixar de aparecer como pendente na tarefa de envio.
ALTER TABLE accounting_saft_submissions
    ADD COLUMN is_manual_entry TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN manual_reason VARCHAR(30) DEFAULT NULL AFTER is_manual_entry,
    MODIFY COLUMN file_path VARCHAR(500) NOT NULL DEFAULT '',
    MODIFY COLUMN original_filename VARCHAR(255) NOT NULL DEFAULT '';
