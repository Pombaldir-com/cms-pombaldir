-- Mapeamento campo da Declaracao Periodica de IVA -> formula de contas do
-- balancete (equivalente a "planos_contas", aba "DP IVA" da intranet
-- legacy). Gerido no modal de configuracoes da tarefa "Apuramento de IVA".
-- Ver contabilidade/APURAMENTO_IVA.md.
CREATE TABLE IF NOT EXISTS accounting_vat_field_formulas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    field_number INT NOT NULL,
    formula VARCHAR(500) NOT NULL DEFAULT '',
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_accounting_vat_field_formula_number (field_number),
    CONSTRAINT fk_accounting_vat_field_formula_user
        FOREIGN KEY (updated_by) REFERENCES users(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
