<?php
/**
 * Painel principal do CMS.
 *
 * Página temporariamente em branco. Requer que o utilizador esteja
 * autenticado para aceder.
 */

// Usar as funções comuns
require_once __DIR__ . '/functions.php';

// Inicia sessão e verifica se o utilizador está autenticado
startSession();
requireLogin();

// Inclui o cabeçalho comum do template
require_once __DIR__ . '/header.php';

$importDocsCount = 0;
$classifDocsCount = 0;
$acquirerCompanyCount = 0;
$showImportCard = isModuleActive('contabilidade') && hasTable('accounting_imports');
$showClassifCard = $showImportCard;
$showCompaniesCard = isModuleActive('contabilidade') && hasTable('accounting_entities');
if ($showImportCard || $showClassifCard || $showCompaniesCard) {
    if ($showImportCard || $showClassifCard) {
        $displayErrors = ini_get('display_errors');
        $displayStartupErrors = ini_get('display_startup_errors');
        $errorReporting = error_reporting();
        require_once __DIR__ . '/contabilidade/functions.php';
        ini_set('display_errors', (string) $displayErrors);
        ini_set('display_startup_errors', (string) $displayStartupErrors);
        error_reporting($errorReporting);
    }
    $pdo = getPDO();
    if ($showImportCard) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_imports WHERE import_type = :importType');
        $stmt->execute([':importType' => 1]);
        $importDocsCount = (int) $stmt->fetchColumn();
    }
    if ($showClassifCard) {
        $stmt = $pdo->prepare(
            'SELECT account, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8, field_N, field_O '
            . 'FROM accounting_imports WHERE import_type = :importType'
        );
        $stmt->execute([':importType' => 1]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $classifDocsCount = 0;
        foreach ($rows as $row) {
            $accounts = normalizeAccountingAccounts($row['account'] ?? '');
            $accountMetadata = normalizeAccountingMetadata($row['account'] ?? '');
            $summaries = computeImportRateSummaries($row);
            [$payload, $requirements] = buildRatePayload($summaries, $accounts);
            $btnClass = determineClassificationButtonClass($requirements, $payload, $accountMetadata);
            if ($btnClass !== 'btn-success') {
                $classifDocsCount++;
            }
        }
    }
    if ($showCompaniesCard) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_entities WHERE entity_type = 'acquirer' AND erp_database IS NOT NULL AND erp_database <> ''"
        );
        $stmt->execute();
        $acquirerCompanyCount = (int) $stmt->fetchColumn();
    }
}

?>
<div class="container-fluid">
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-dashboard"></i> Dashboard <small>Resumo</small></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <?php if ($showImportCard || $showClassifCard || $showCompaniesCard): ?>
                <h4 class="text-muted">Contabilidade</h4>
                <div class="row tile_count">
                    <?php if ($showImportCard): ?>
                    <div class="col-md-4 col-sm-6 col-xs-12 tile_stats_count">
                        <span class="count_top"><i class="fa fa-file-text-o"></i> Documentos para importação</span>
                        <div class="count"><?= number_format($importDocsCount, 0, ',', '.'); ?></div>
                        <span class="count_bottom">
                            <a class="btn btn-xs btn-primary" href="<?= BASE_URL ?>contabilidade/classificacao-importacao?import_type=1">
                                <i class="fa fa-search"></i> Ver documentos
                            </a>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($showClassifCard): ?>
                    <div class="col-md-4 col-sm-6 col-xs-12 tile_stats_count">
                        <span class="count_top"><i class="fa fa-tags"></i> Documentos p/ Classif</span>
                        <div class="count"><?= number_format($classifDocsCount, 0, ',', '.'); ?></div>
                        <span class="count_bottom">
                            <a class="btn btn-xs btn-warning" href="<?= BASE_URL ?>contabilidade/classificacao-importacao?import_type=1">
                                <i class="fa fa-search"></i> Ver documentos
                            </a>
                        </span>
                    </div>
                    <?php endif; ?>
                    <?php if ($showCompaniesCard): ?>
                    <div class="col-md-4 col-sm-6 col-xs-12 tile_stats_count">
                        <span class="count_top"><i class="fa fa-building"></i> Empresas (acquirer)</span>
                        <div class="count"><?= number_format($acquirerCompanyCount, 0, ',', '.'); ?></div>
                        <span class="count_bottom text-muted">Empresas integradas</span>
                    </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">Sem dados disponíveis para importação.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
// Inclui o rodapé comum do template
require_once __DIR__ . '/footer.php';
