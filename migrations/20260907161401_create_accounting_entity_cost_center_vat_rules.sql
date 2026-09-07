-- Parametrizacao, por empresa cliente + centro de custo (ERP), de saber se o
-- IVA autoliquidado (reverse charge) e dedutivel ou nao, e quais as contas a
-- usar em cada caso na classificacao (ver contabilidade/entidades.php,
-- separador "IVA Autoliquidacao").
CREATE TABLE IF NOT EXISTS accounting_entity_cost_center_vat_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_id INT NOT NULL,
    cost_center_code VARCHAR(50) NOT NULL,
    cost_center_label VARCHAR(255) NOT NULL DEFAULT '',
    vat_deductible TINYINT(1) NOT NULL DEFAULT 1,
    deductible_account VARCHAR(50) NOT NULL DEFAULT '',
    non_deductible_account VARCHAR(50) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_entity_cost_center (entity_id, cost_center_code),
    CONSTRAINT fk_cc_vat_rule_entity
        FOREIGN KEY (entity_id) REFERENCES accounting_entities(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
