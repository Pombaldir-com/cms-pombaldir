ALTER TABLE accounting_saft_submissions
    ADD UNIQUE KEY uq_saft_submissions_entity_period (accounting_entity_id, period_year, period_month);
