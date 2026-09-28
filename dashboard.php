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
$accountingCards = [];
if ($showImportCard || $showClassifCard || $showCompaniesCard) {
    if ($showImportCard || $showClassifCard) {
        require_once __DIR__ . '/contabilidade/functions.php';
    }
    $pdo = getPDO();
    if ($showImportCard) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM accounting_imports '
            . 'WHERE import_type = :importType AND (cab_id IS NULL OR cab_id = \'\')'
        );
        $stmt->execute([':importType' => 1]);
        $importDocsCount = (int) $stmt->fetchColumn();
    }
    if ($showClassifCard) {
        $stmt = $pdo->prepare(
            'SELECT account, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8, field_N, field_O '
            . 'FROM accounting_imports '
            . 'WHERE import_type = :importType AND (cab_id IS NULL OR cab_id = \'\')'
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

    if ($showImportCard) {
        $accountingCards[] = [
            'title' => 'Documentos para importacao',
            'value' => $importDocsCount,
            'icon' => 'fa-file-text-o',
            'accent' => 'primary',
            'description' => 'Documentos pendentes e ainda disponiveis para tratamento.',
            'link' => BASE_URL . 'contabilidade/classificacao-importacao?import_type=1',
            'link_label' => 'Ver documentos',
        ];
    }

    if ($showClassifCard) {
        $accountingCards[] = [
            'title' => 'Documentos p/ Classif.',
            'value' => $classifDocsCount,
            'icon' => 'fa-tags',
            'accent' => 'warning',
            'description' => 'Linhas que ainda precisam de classificacao antes da importacao.',
            'link' => BASE_URL . 'contabilidade/classificacao-importacao?import_type=1',
            'link_label' => 'Abrir classificacao',
        ];
    }

    if ($showCompaniesCard) {
        $accountingCards[] = [
            'title' => 'Empresas integradas',
            'value' => $acquirerCompanyCount,
            'icon' => 'fa-building',
            'accent' => 'success',
            'description' => 'Entidades com base de dados ERP associada e pronta a integrar.',
            'link' => null,
            'link_label' => 'Empresas integradas',
        ];
    }
}

$recentActivities = [];
$showRecentActivities = false;
if (isModuleActive('contabilidade')) {
    require_once __DIR__ . '/contabilidade/atividades-functions.php';
    if (hasAccountingActivityTables()) {
        $showRecentActivities = true;
        $recentActivities = getRecentActivities(getPDO(), 8);
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
            <?php if (!empty($accountingCards)): ?>
                <style>
                    .dashboard-section-title {
                        margin: 0 0 18px;
                        color: #2a3f54;
                        font-weight: 600;
                    }
                    .dashboard-summary-grid {
                        display: flex;
                        flex-wrap: wrap;
                        margin: 0 -10px;
                    }
                    .dashboard-summary-col {
                        width: 33.3333%;
                        padding: 0 10px 20px;
                        display: flex;
                    }
                    .dashboard-summary-card {
                        position: relative;
                        width: 100%;
                        display: flex;
                        flex-direction: column;
                        min-height: 220px;
                        padding: 22px 22px 18px;
                        border: 1px solid #e6ecf3;
                        border-radius: 12px;
                        background: #fff;
                        box-shadow: 0 10px 24px rgba(32, 45, 64, 0.08);
                    }
                    .dashboard-summary-card:before {
                        content: "";
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        height: 4px;
                        border-radius: 12px 12px 0 0;
                        background: #337ab7;
                    }
                    .dashboard-summary-card.accent-warning:before {
                        background: #f0ad4e;
                    }
                    .dashboard-summary-card.accent-success:before {
                        background: #26b99a;
                    }
                    .dashboard-summary-head {
                        display: flex;
                        align-items: flex-start;
                        justify-content: space-between;
                        gap: 12px;
                        margin-bottom: 18px;
                    }
                    .dashboard-summary-label {
                        display: block;
                        margin-bottom: 6px;
                        color: #7b8a9a;
                        font-size: 12px;
                        font-weight: 700;
                        letter-spacing: 0.04em;
                        text-transform: uppercase;
                    }
                    .dashboard-summary-title {
                        margin: 0;
                        color: #2a3f54;
                        font-size: 21px;
                        line-height: 1.25;
                        font-weight: 600;
                    }
                    .dashboard-summary-icon {
                        width: 52px;
                        height: 52px;
                        flex: 0 0 52px;
                        border-radius: 14px;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        background: rgba(51, 122, 183, 0.12);
                        color: #337ab7;
                        font-size: 22px;
                    }
                    .dashboard-summary-card.accent-warning .dashboard-summary-icon {
                        background: rgba(240, 173, 78, 0.16);
                        color: #f0ad4e;
                    }
                    .dashboard-summary-card.accent-success .dashboard-summary-icon {
                        background: rgba(38, 185, 154, 0.16);
                        color: #26b99a;
                    }
                    .dashboard-summary-value {
                        margin: 0 0 10px;
                        color: #2a3f54;
                        font-size: 42px;
                        line-height: 1;
                        font-weight: 700;
                    }
                    .dashboard-summary-description {
                        min-height: 42px;
                        margin: 0 0 18px;
                        color: #73879c;
                        font-size: 14px;
                        line-height: 1.55;
                    }
                    .dashboard-summary-footer {
                        margin-top: auto;
                    }
                    .dashboard-summary-card .btn {
                        border-radius: 20px;
                        padding-left: 14px;
                        padding-right: 14px;
                    }
                    @media (max-width: 991px) {
                        .dashboard-summary-col {
                            width: 50%;
                        }
                    }
                    @media (max-width: 767px) {
                        .dashboard-summary-col {
                            width: 100%;
                        }
                        .dashboard-summary-card {
                            min-height: 0;
                        }
                    }
                </style>
                <h4 class="dashboard-section-title">Contabilidade</h4>
                <div class="dashboard-summary-grid">
                    <?php foreach ($accountingCards as $card): ?>
                    <div class="dashboard-summary-col">
                        <div class="dashboard-summary-card accent-<?= htmlspecialchars($card['accent'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="dashboard-summary-head">
                                <div>
                                    <span class="dashboard-summary-label">Resumo</span>
                                    <h3 class="dashboard-summary-title"><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                </div>
                                <span class="dashboard-summary-icon">
                                    <i class="fa <?= htmlspecialchars($card['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                                </span>
                            </div>
                            <div class="dashboard-summary-body">
                                <p class="dashboard-summary-value"><?= number_format((int) $card['value'], 0, ',', '.'); ?></p>
                                <p class="dashboard-summary-description"><?= htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="dashboard-summary-footer">
                                <?php if (!empty($card['link'])): ?>
                                <a class="btn btn-sm btn-<?= htmlspecialchars($card['accent'], ENT_QUOTES, 'UTF-8'); ?>" href="<?= htmlspecialchars((string) $card['link'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fa fa-arrow-right"></i> <?= htmlspecialchars($card['link_label'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted"><?= htmlspecialchars($card['link_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">Sem dados disponíveis para importação.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($showRecentActivities): ?>
    <style>
        .dash-act-list { list-style: none; margin: 0; padding: 0; }
        .dash-act-item { display: flex; align-items: center; gap: 14px; padding: 12px 6px; border-bottom: 1px solid #eef0f2; border-radius: 6px; color: inherit; }
        .dash-act-item:hover { background: #f7f9fb; color: inherit; }
        .dash-act-list li:last-child .dash-act-item { border-bottom: 0; }
        .dash-act-avatar { flex: 0 0 40px; width: 40px; height: 40px; border-radius: 50%; background: #26b99a; color: #fff; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; }
        .dash-act-main { flex: 1 1 auto; min-width: 0; }
        .dash-act-client { font-weight: 600; color: #2a3f54; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dash-act-client small { font-weight: 500; color: #8a949e; margin-left: 6px; }
        .dash-act-cats { margin-top: 3px; }
        .dash-act-cat { display: inline-block; background: #eef3f8; color: #2f5f8f; border-radius: 12px; padding: 1px 8px; font-size: 11px; margin: 1px 3px 1px 0; white-space: nowrap; }
        .dash-act-side { flex: 0 0 auto; text-align: right; font-size: 12px; color: #8a949e; }
        .dash-act-side strong { display: block; color: #2a3f54; font-weight: 600; font-size: 13px; }
        .dash-act-empty { text-align: center; padding: 26px 10px; color: #8a949e; }
        .dash-act-empty i { font-size: 34px; color: #cfd6dd; display: block; margin-bottom: 8px; }
        @media (max-width: 575px) { .dash-act-side { display: none; } }
    </style>
    <div class="x_panel">
        <div class="x_title d-flex align-items-center justify-content-between">
            <h2><i class="fa fa-calendar-check-o"></i> Últimas atividades</h2>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>contabilidade/atividades/novo" class="btn btn-sm btn-primary mb-0"><i class="fa fa-plus"></i> Registar</a>
                <a href="<?= BASE_URL ?>contabilidade/atividades" class="btn btn-sm btn-outline-secondary mb-0">Ver todas</a>
            </div>
        </div>
        <div class="x_content">
            <?php if (!$recentActivities): ?>
            <div class="dash-act-empty">
                <i class="fa fa-calendar-o"></i>
                Ainda não existem atividades registadas.
            </div>
            <?php else: ?>
            <ul class="dash-act-list">
                <?php foreach ($recentActivities as $recent): ?>
                <li>
                    <a class="dash-act-item text-decoration-none" href="<?= BASE_URL ?>contabilidade/atividades/<?= htmlspecialchars((string) $recent['uuid'], ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="dash-act-avatar"><?= htmlspecialchars(activityInitials((string) $recent['client_name']), ENT_QUOTES, 'UTF-8'); ?></span>
                        <div class="dash-act-main">
                            <div class="dash-act-client">
                                <?= htmlspecialchars((string) $recent['client_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <small><?= htmlspecialchars((string) $recent['code'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <div class="dash-act-cats">
                                <?php foreach ($recent['lines'] as $recentLine): ?>
                                <span class="dash-act-cat"><?= htmlspecialchars($recentLine['title'] . ($recentLine['value'] !== '' ? ' · ' . $recentLine['value'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="dash-act-side" title="<?= htmlspecialchars(formatActivityDateTime((string) $recent['created_at']), ENT_QUOTES, 'UTF-8'); ?>">
                            <strong><?= htmlspecialchars(formatActivityRelative((string) $recent['created_at']), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?= htmlspecialchars((string) $recent['responsible_label'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
// Inclui o rodapé comum do template
require_once __DIR__ . '/footer.php';
