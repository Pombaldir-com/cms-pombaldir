-- Regime de IVA da empresa. 'isento' cobre as empresas enquadradas no
-- art. 9.o do CIVA (atividade isenta, sem direito a deducao): na classificacao
-- de documentos o IVA suportado nunca vai para contas de IVA, e levado a gasto
-- juntamente com a base (conta geral).
ALTER TABLE accounting_entities
    ADD COLUMN vat_regime ENUM('normal', 'isento') NOT NULL DEFAULT 'normal' AFTER vat_periodicity;
