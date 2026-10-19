<?php
// Ecra "Conferir" da tarefa "Confirmacao de Pasta": por mes, compara os
// valores da fita (introduzidos pelo colaborador) com os lancamentos a
// credito de cada diario no ERP, e os totais do SAF-T com o balancete, e
// fecha o mes. Com ?journal=N mostra a "Conferencia de valores" desse diario
// (fita vs documentos), onde a fita e editada. Equivalente a
// window.php?act=wkfloproc&task=4 + ifrm_utils.php?act=diariosmov da
// intranet legacy. Ver contabilidade/CONFIRMACAO_PASTA.md.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/apuramento-iva-functions.php';
require_once __DIR__ . '/confirmacao-pasta-functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_confirmacao_pasta')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();

if (!hasTable('accounting_folder_confirmations') || !hasTable('accounting_folder_tape_values')) {
    http_response_code(500);
    echo 'As tabelas da Confirmação de Pasta ainda não existem. Execute as migrações.';
    exit;
}

$entities = getIvaTaskEntities($pdo, $isAdmin, $userId, 'ctb_confirmacao_pasta');
$entity = null;
$entityId = (int) ($_GET['entity_id'] ?? $_POST['entity_id'] ?? 0);
foreach ($entities as $entityRow) {
    if ((int) $entityRow['id'] === $entityId) {
        $entity = $entityRow;
        break;
    }
}
if (!$entity) {
    http_response_code(404);
    echo 'Empresa inválida ou sem permissão.';
    exit;
}

// Por defeito o mes anterior (a pasta do mes corrente ainda esta aberta),
// como o seletor do legacy, que comecava em "-1 months".
$defaultPeriod = strtotime(date('Y-m-01') . ' -1 month');
$currentYear = (int) date('Y');
$periodYear = (int) ($_GET['period_year'] ?? $_POST['period_year'] ?? date('Y', $defaultPeriod));
$periodMonth = (int) ($_GET['period_ref'] ?? $_POST['period_ref'] ?? date('n', $defaultPeriod));
if ($periodYear < 2000 || $periodYear > 2100) {
    $periodYear = (int) date('Y', $defaultPeriod);
}
if ($periodMonth < 1 || $periodMonth > 12) {
    $periodMonth = (int) date('n', $defaultPeriod);
}
$periodLabel = buildFolderPeriodLabel($periodYear, $periodMonth);
$journalView = isset($_GET['journal']) || isset($_POST['journal']) ? (int) ($_GET['journal'] ?? $_POST['journal']) : null;

$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strcasecmp((string) $_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;

$feedback = null;
$periodJustClosed = false;
$confirmation = findFolderConfirmation($pdo, $entityId, $periodLabel);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sem consumir o token: a fita e editada com muitos pedidos AJAX seguidos
    // e o modal de configuracoes da pagina principal usa o mesmo token.
    if (!validateCsrfToken($_POST['csrf_token'] ?? '', false)) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }
    $postAction = (string) ($_POST['action'] ?? '');

    if (in_array($postAction, ['add_tape_values', 'update_tape_value', 'delete_tape_value'], true)) {
        if ($confirmation) {
            $feedback = ['type' => 'danger', 'message' => 'O mês ' . $periodLabel . ' já está fechado; a fita já não pode ser alterada.'];
        } elseif ($postAction === 'add_tape_values') {
            $parsed = parseFolderTapeInput((string) ($_POST['values'] ?? ''));
            if ($parsed['invalid'] !== null) {
                $feedback = ['type' => 'danger', 'message' => 'Valor inválido: ' . $parsed['invalid'] . '.'];
            } elseif ($parsed['values'] && $journalView !== null) {
                $stmt = $pdo->prepare(
                    'INSERT INTO accounting_folder_tape_values (accounting_entity_id, period_year, period_month, journal_code, valor, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($parsed['values'] as $value) {
                    $stmt->execute([$entityId, $periodYear, $periodMonth, $journalView, $value, $userId ?: null]);
                }
            }
        } else {
            $tapeId = (int) ($_POST['tape_id'] ?? 0);
            if ($postAction === 'delete_tape_value') {
                $stmt = $pdo->prepare('DELETE FROM accounting_folder_tape_values WHERE id = ? AND accounting_entity_id = ? AND period_year = ? AND period_month = ?');
                $stmt->execute([$tapeId, $entityId, $periodYear, $periodMonth]);
            } else {
                $parsed = parseFolderTapeInput((string) ($_POST['valor'] ?? ''));
                if ($parsed['invalid'] !== null || count($parsed['values']) !== 1) {
                    $feedback = ['type' => 'danger', 'message' => 'Valor inválido.'];
                } else {
                    $stmt = $pdo->prepare('UPDATE accounting_folder_tape_values SET valor = ? WHERE id = ? AND accounting_entity_id = ? AND period_year = ? AND period_month = ?');
                    $stmt->execute([$parsed['values'][0], $tapeId, $entityId, $periodYear, $periodMonth]);
                }
            }
        }
    } elseif ($postAction === 'close_period') {
        if ($confirmation) {
            $feedback = ['type' => 'danger', 'message' => 'O mês ' . $periodLabel . ' já se encontra fechado para esta empresa.'];
        } else {
            $overviewForClose = buildFolderConfirmationOverview($pdo, $entity, $periodYear, $periodMonth);
            $summary = [
                'journals' => array_values(array_map(static fn ($j) => [
                    'code' => $j['code'], 'name' => $j['name'], 'erp' => $j['erp_total'], 'fita' => $j['tape_total'],
                ], $overviewForClose['journals'])),
                'saft' => $overviewForClose['saft_compare'],
                'erp_loaded' => $overviewForClose['erp_loaded'],
            ];
            $observacao = trim((string) ($_POST['observacao'] ?? ''));
            $stmt = $pdo->prepare(
                'INSERT INTO accounting_folder_confirmations
                    (accounting_entity_id, period_year, period_month, period_label, divergences_count, summary_json, observacao, closed_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $entityId,
                $periodYear,
                $periodMonth,
                $periodLabel,
                (int) $overviewForClose['divergences'],
                json_encode($summary, JSON_UNESCAPED_UNICODE),
                $observacao !== '' ? $observacao : null,
                $userId,
            ]);
            logAuditAction('create', 'accounting_folder_confirmation', (int) $pdo->lastInsertId(), [
                'accounting_entity_id' => $entityId,
                'period_label' => $periodLabel,
                'divergences' => (int) $overviewForClose['divergences'],
            ]);
            $feedback = ['type' => 'success', 'message' => 'Mês ' . $periodLabel . ' fechado.'];
            $periodJustClosed = true;
            $confirmation = findFolderConfirmation($pdo, $entityId, $periodLabel);
        }
    }
}

$overview = buildFolderConfirmationOverview($pdo, $entity, $periodYear, $periodMonth);
$monthNames = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$fmt = static fn (float $v): string => number_format($v, 2, ',', '.');
$csrfToken = generateCsrfToken();
$hiddenFields = static function () use ($csrfToken, $entityId, $periodYear, $periodMonth): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">'
        . '<input type="hidden" name="entity_id" value="' . $entityId . '">'
        . '<input type="hidden" name="period_year" value="' . $periodYear . '">'
        . '<input type="hidden" name="period_ref" value="' . $periodMonth . '">';
};

ob_start();
?>
<style>
    .cpasta-warning {
        background: #fdf3e6; border: 1px solid #f8e2bd; color: #8a6417;
        border-radius: 4px; padding: 10px 14px; font-size: 12.5px; line-height: 1.5; margin-bottom: 12px;
    }
    .cpasta-period-form { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
    .cpasta-period-form label { margin: 0; font-weight: 600; color: #2a3f54; white-space: nowrap; }
    .cpasta-period-form select { height: 30px; padding: 3px 8px; font-size: 13px; width: auto; }
    .cpasta-section-title { font-size: 13px; font-weight: 600; color: #2a3f54; margin: 18px 0 8px; text-transform: uppercase; letter-spacing: .3px; }
    .cpasta-journals td, .cpasta-journals th { vertical-align: middle !important; font-size: 13px; }
    .cpasta-journals .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .cpasta-journals tr.cpasta-row-error td { background: #fdf0ef; }
    .cpasta-status-ok { color: #26b99a; }
    .cpasta-status-error { color: #d9534f; }
    .cpasta-saft { display: flex; gap: 12px; flex-wrap: wrap; }
    .cpasta-saft .alert { flex: 1 1 220px; margin: 0; font-size: 13px; line-height: 1.6; }
    .cpasta-close-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-top: 18px; padding-top: 14px; border-top: 1px solid #e6e9ed; }
    .cpasta-closed { padding: 14px 16px; background: #eafaf6; border: 1px solid #cdeee5; border-radius: 4px; color: #2a3f54; margin-top: 18px; }
    .cpasta-closed dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 10px 0 0; font-size: 13px; }
    .cpasta-closed dt { color: #73879c; font-weight: 600; }
    .cpasta-closed dd { margin: 0; }
    .cpasta-compare { display: flex; gap: 16px; flex-wrap: wrap; }
    .cpasta-compare > div { flex: 1 1 300px; min-width: 0; }
    .cpasta-compare table { font-size: 13px; margin-bottom: 0; }
    .cpasta-compare .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .cpasta-compare .cpasta-unmatched { color: #d9534f; font-weight: 600; }
    .cpasta-compare .cpasta-list { max-height: 360px; overflow-y: auto; border: 1px solid #e6e9ed; border-radius: 3px; }
    .cpasta-tape-input { width: 110px; height: 26px; padding: 2px 6px; font-size: 13px; text-align: right; }
    .cpasta-add-form { display: flex; gap: 8px; margin-top: 10px; align-items: flex-start; }
    .cpasta-add-form textarea { flex: 1; font-size: 13px; resize: vertical; }
</style>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($feedback['message']); ?>
</div>
<?php endif; ?>

<?php if ($periodJustClosed): ?>
<div data-cpasta-period-closed="1" hidden></div>
<?php endif; ?>

<?php if ($journalView !== null):
    $journal = $overview['journals'][$journalView] ?? ['code' => $journalView, 'name' => 'Diário ' . $journalView, 'erp_total' => 0.0, 'tape_total' => 0.0];
    $tapeRows = $overview['tape'][$journalView] ?? [];
    $docs = $overview['erp_journals'][$journalView]['docs'] ?? [];
    $match = matchFolderTapeValues($tapeRows, $docs);
    $readOnly = (bool) $confirmation;
?>
<div class="cpasta-period-form">
    <button type="button" class="btn btn-default btn-sm cpasta-journal-link" data-entity-id="<?= $entityId; ?>" data-period-year="<?= $periodYear; ?>" data-period-ref="<?= $periodMonth; ?>" data-journal="">
        <i class="fa fa-arrow-left"></i> Voltar
    </button>
    <strong>Conferência de valores — <?= (int) $journal['code']; ?> - <?= htmlspecialchars((string) $journal['name']); ?></strong>
    <span class="text-muted" style="font-size: 12px;"><?= htmlspecialchars($monthNames[$periodMonth] . ' ' . $periodYear); ?></span>
</div>

<?php foreach ($overview['warnings'] as $warning): ?>
<div class="cpasta-warning"><?= htmlspecialchars($warning); ?></div>
<?php endforeach; ?>

<div class="cpasta-compare">
    <div>
        <span class="label label-info">Valores da Fita</span>
        <span class="text-muted" style="font-size: 12px;"> · total <?= $fmt((float) $journal['tape_total']); ?> €</span>
        <div class="cpasta-list" style="margin-top: 6px;">
            <table class="table table-hover table-condensed">
                <tbody>
                <?php if (!$tapeRows): ?>
                    <tr><td class="text-muted">Não existem registos a apresentar.</td></tr>
                <?php endif; ?>
                <?php foreach ($tapeRows as $index => $tapeRow):
                    $unmatched = !$match['tape'][$index];
                ?>
                    <tr>
                        <td class="num">
                            <?php if ($readOnly): ?>
                                <span class="<?= $unmatched ? 'cpasta-unmatched' : ''; ?>"><?= $fmt($tapeRow['valor']); ?></span>
                            <?php else: ?>
                                <form method="post" class="cpasta-ajax-form" style="display: inline;">
                                    <?= $hiddenFields(); ?>
                                    <input type="hidden" name="journal" value="<?= $journalView; ?>">
                                    <input type="hidden" name="action" value="update_tape_value">
                                    <input type="hidden" name="tape_id" value="<?= $tapeRow['id']; ?>">
                                    <input type="text" name="valor" class="form-control cpasta-tape-input cpasta-autosubmit<?= $unmatched ? ' cpasta-unmatched' : ''; ?>" style="display: inline-block;" value="<?= number_format($tapeRow['valor'], 2, ',', ''); ?>">
                                </form>
                            <?php endif; ?>
                        </td>
                        <td style="width: 40px; text-align: center;">
                            <?php if (!$readOnly): ?>
                            <form method="post" class="cpasta-ajax-form" style="display: inline;">
                                <?= $hiddenFields(); ?>
                                <input type="hidden" name="journal" value="<?= $journalView; ?>">
                                <input type="hidden" name="action" value="delete_tape_value">
                                <input type="hidden" name="tape_id" value="<?= $tapeRow['id']; ?>">
                                <button type="submit" class="btn btn-xs btn-danger" title="Eliminar" style="margin: 0;"><i class="fa fa-times"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$readOnly): ?>
        <form method="post" class="cpasta-ajax-form cpasta-add-form">
            <?= $hiddenFields(); ?>
            <input type="hidden" name="journal" value="<?= $journalView; ?>">
            <input type="hidden" name="action" value="add_tape_values">
            <textarea name="values" rows="1" class="form-control cpasta-add-input" placeholder="Valor (Enter adiciona). Pode colar vários separados por + ou por linha." autocomplete="off"></textarea>
            <button type="submit" class="btn btn-primary btn-sm" style="margin: 0;"><i class="fa fa-plus"></i> Adicionar</button>
        </form>
        <?php endif; ?>
    </div>
    <div>
        <span class="label label-info">Valores dos movimentos / Nº Doc</span>
        <span class="text-muted" style="font-size: 12px;"> · total <?= $fmt((float) $journal['erp_total']); ?> €</span>
        <div class="cpasta-list" style="margin-top: 6px;">
            <table class="table table-hover table-condensed">
                <tbody>
                <?php if (!$docs): ?>
                    <tr><td class="text-muted">Não existem registos a apresentar.</td></tr>
                <?php endif; ?>
                <?php foreach ($docs as $index => $doc): ?>
                    <tr>
                        <td class="num<?= $match['docs'][$index] ? '' : ' cpasta-unmatched'; ?>"><?= $fmt($doc['valor']); ?></td>
                        <td><?= htmlspecialchars($doc['doc']); ?></td>
                        <td class="text-muted" style="white-space: nowrap;"><?= htmlspecialchars($doc['date'] ? substr((string) $doc['date'], 0, 10) : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($match['unmatched_tape'] || $match['unmatched_docs']): ?>
<div class="alert alert-danger" style="margin-top: 14px; font-size: 13px;">
    <?php if ($match['unmatched_tape']): ?>
    <div><strong>Valores divergentes (fita sem documento):</strong> <?= htmlspecialchars(implode('  ', array_map($fmt, $match['unmatched_tape']))); ?></div>
    <?php endif; ?>
    <?php if ($match['unmatched_docs']): ?>
    <div><strong>Documentos sem valor na fita:</strong> <?= htmlspecialchars(implode('  ', array_map($fmt, $match['unmatched_docs']))); ?></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php else: ?>

<form method="get" class="cpasta-period-form" data-entity-id="<?= $entityId; ?>">
    <label>Mês:</label>
    <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
    <select name="period_year" class="form-control">
        <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
            <option value="<?= $y; ?>" <?= $y === $periodYear ? 'selected' : ''; ?>><?= $y; ?></option>
        <?php endfor; ?>
    </select>
    <select name="period_ref" class="form-control">
        <?php foreach (range(1, 12) as $m): ?>
            <option value="<?= $m; ?>" <?= $m === $periodMonth ? 'selected' : ''; ?>><?= sprintf('%02d', $m); ?> - <?= $monthNames[$m]; ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($confirmation): ?>
        <span class="label label-success"><i class="fa fa-check"></i> Fechado</span>
    <?php endif; ?>
    <noscript><button type="submit" class="btn btn-default btn-sm">Filtrar</button></noscript>
</form>

<?php foreach ($overview['warnings'] as $warning): ?>
<div class="cpasta-warning"><?= htmlspecialchars($warning); ?></div>
<?php endforeach; ?>

<div class="cpasta-section-title">Diários — lançado a crédito vs. fita</div>
<div class="table-responsive">
    <table class="table table-bordered cpasta-journals" style="margin-bottom: 0;">
        <thead>
            <tr>
                <th>Diário</th>
                <th class="num">Lançado (ERP)</th>
                <th class="num">Fita</th>
                <th class="num">Diferença</th>
                <th style="width: 60px; text-align: center;">Estado</th>
                <th style="width: 110px;"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($overview['journals'] as $journal): ?>
            <tr class="<?= $journal['ok'] ? '' : 'cpasta-row-error'; ?>">
                <td><strong><?= (int) $journal['code']; ?></strong> - <?= htmlspecialchars((string) $journal['name']); ?></td>
                <td class="num"><?= $fmt((float) $journal['erp_total']); ?> <small class="text-muted">(<?= (int) $journal['erp_docs']; ?> doc.)</small></td>
                <td class="num"><?= $fmt((float) $journal['tape_total']); ?> <small class="text-muted">(<?= (int) $journal['tape_count']; ?>)</small></td>
                <td class="num"><?= abs((float) $journal['diff']) >= 0.005 ? $fmt((float) $journal['diff']) : '—'; ?></td>
                <td style="text-align: center;">
                    <?php if ($journal['ok']): ?>
                        <i class="fa fa-check-circle fa-lg cpasta-status-ok" title="Valores coincidem"></i>
                    <?php else: ?>
                        <i class="fa fa-exclamation-triangle fa-lg cpasta-status-error" title="Diferença: <?= $fmt((float) $journal['diff']); ?>"></i>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button" class="btn btn-default btn-xs cpasta-journal-link" style="margin: 0;" data-entity-id="<?= $entityId; ?>" data-period-year="<?= $periodYear; ?>" data-period-ref="<?= $periodMonth; ?>" data-journal="<?= (int) $journal['code']; ?>">
                        <i class="fa fa-search"></i> Conferir
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="cpasta-section-title">SAF-T vs. contabilidade</div>
<?php if (!$overview['saft']['found']): ?>
<div class="cpasta-warning">Não há SAF-T de <?= htmlspecialchars($periodLabel); ?> com totais extraídos (tarefa "Envio de SAF-T"). Os totais do SAF-T contam como 0,00.</div>
<?php endif; ?>
<?php if ($overview['saft_compare']): ?>
<div class="cpasta-saft">
    <?php foreach (['debit' => 'Total Débitos', 'credit' => 'Total Créditos'] as $sideKey => $sideLabel):
        $side = $overview['saft_compare'][$sideKey];
        $sideOk = abs($side['diff']) < 0.005;
    ?>
    <div class="alert alert-<?= $sideOk ? 'success' : 'danger'; ?>">
        <b><?= $sideLabel; ?>:</b><br>
        SAF-T: <?= $fmt((float) $side['saft']); ?> €<br>
        Ctb: <?= $fmt((float) $side['ctb']); ?> €<br>
        <strong><?= $sideOk ? 'Sem diferenças' : 'Diferença: ' . $fmt((float) $side['diff']) . ' €'; ?></strong>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($confirmation): ?>
<div class="cpasta-closed">
    <strong><i class="fa fa-check"></i> Mês fechado</strong>
    em <?= htmlspecialchars((string) $confirmation['created_at']); ?>
    por <?= htmlspecialchars((string) ($confirmation['closed_by_name'] ?: $confirmation['closed_by_username'] ?: '—')); ?>
    <dl>
        <dt>Divergências no fecho</dt>
        <dd><?= (int) $confirmation['divergences_count']; ?></dd>
        <?php if (!empty($confirmation['observacao'])): ?>
        <dt>Observação</dt>
        <dd><?= htmlspecialchars((string) $confirmation['observacao']); ?></dd>
        <?php endif; ?>
    </dl>
</div>
<?php else: ?>
<form method="post" class="cpasta-ajax-form cpasta-close-form" data-divergences="<?= (int) $overview['divergences']; ?>">
    <?= $hiddenFields(); ?>
    <input type="hidden" name="action" value="close_period">
    <div style="flex: 1 1 260px;">
        <label class="control-label">Observação</label>
        <input type="text" name="observacao" class="form-control" placeholder="Opcional">
    </div>
    <?php if ($overview['divergences'] > 0): ?>
    <span class="text-danger" style="font-size: 12.5px; align-self: center;">
        <i class="fa fa-exclamation-triangle"></i> <?= (int) $overview['divergences']; ?> divergência<?= $overview['divergences'] === 1 ? '' : 's'; ?>
    </span>
    <?php endif; ?>
    <button type="submit" class="btn btn-success" style="margin: 0;"><i class="fa fa-check"></i> Fechar etapa</button>
</form>
<?php endif; ?>

<?php endif; ?>
<?php
$detailFragment = ob_get_clean();

if ($isAjaxRequest) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h4 style="margin-top: 0;">Confirmação de Pasta - ' . htmlspecialchars((string) $entity['name']) . '</h4>';
    echo $detailFragment;
    exit;
}

$useDataTables = false;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Confirmação de Pasta</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-folder-open"></i> Tarefa: Confirmação de Pasta - <?= htmlspecialchars((string) $entity['name']); ?></h2>
                <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                    <li>
                        <a href="<?= BASE_URL; ?>contabilidade/tarefas/confirmacao-pasta" class="btn btn-default btn-sm">
                            <i class="fa fa-arrow-left"></i> Voltar
                        </a>
                    </li>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?= $detailFragment; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
