-- Periodicidade de IVA da empresa (mensal ou trimestral), necessaria para a
-- tarefa "Apuramento de IVA" definir o tipo de periodo (mes vs. trimestre)
-- a apurar. Ver contabilidade/APURAMENTO_IVA.md.
ALTER TABLE accounting_entities
    ADD COLUMN vat_periodicity ENUM('mensal', 'trimestral') NOT NULL DEFAULT 'mensal' AFTER entity_type;
