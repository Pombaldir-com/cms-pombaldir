-- Permite varios ficheiros distintos por empresa/periodo (ex.: exportacoes
-- separadas do mesmo mes); so o mesmo ficheiro (igual nome original) e
-- unico por empresa/periodo, tratando um reenvio com o mesmo nome como
-- atualizacao (ver saftHandleUpload() em contabilidade/saft-envio-functions.php).
ALTER TABLE accounting_saft_submissions
    DROP INDEX uq_saft_submissions_entity_period;

ALTER TABLE accounting_saft_submissions
    ADD UNIQUE KEY uq_saft_submissions_entity_period_file (accounting_entity_id, period_year, period_month, original_filename(191));
