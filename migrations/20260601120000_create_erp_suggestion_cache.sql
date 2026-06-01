-- Cache for ERP webservice suggestion/lookup responses (e.g. LigacaoCteTipoDoc,
-- plano de contas) used while listing/classifying documents. Avoids repeating
-- slow synchronous ERP HTTP calls on every DataTable render and company switch.
CREATE TABLE IF NOT EXISTS erp_suggestion_cache (
    cache_key CHAR(40) NOT NULL,
    response_json MEDIUMTEXT NOT NULL,
    created_at INT NOT NULL,
    PRIMARY KEY (cache_key),
    KEY idx_erp_suggestion_cache_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
