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
$showImportCard = isModuleActive('contabilidade') && hasTable('accounting_imports');
if ($showImportCard) {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_imports WHERE import_type = :importType');
    $stmt->execute([':importType' => 1]);
    $importDocsCount = (int) $stmt->fetchColumn();
}

?>
<div class="container-fluid">
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-dashboard"></i> Dashboard</h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <?php if ($showImportCard): ?>
                <div class="row tile_count">
                    <div class="col-md-4 col-sm-6 col-xs-12 tile_stats_count">
                        <span class="count_top"><i class="fa fa-file-text-o"></i> Documentos para importação</span>
                        <div class="count"><?= number_format($importDocsCount, 0, ',', '.'); ?></div>
                        <span class="count_bottom">
                            <a href="<?= BASE_URL ?>contabilidade/classificacao-importacao?import_type=1">Ver documentos</a>
                        </span>
                    </div>
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
