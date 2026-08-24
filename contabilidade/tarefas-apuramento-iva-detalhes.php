<?php
// Ecra "Ver detalhes" da tarefa "Apuramento de IVA": comparacao
// campo-a-campo (C{n}-DP vs. Ctr Ctb) por empresa/periodo, equivalente ao
// ecra de window.php?act=wkfloproc (task=6) da intranet legacy.
//
// "Ctr Ctb" e calculado com evaluateAccountingVatFieldFormula() a partir
// das formulas configuradas em accounting_vat_field_formulas, mas SEM
// dados reais de balancete (endpoint ERP-SINC ainda nao existe) — fica
// sempre 0.00 ate essa integracao ser feita. "C{n}-DP" e, por agora,
// introduzido e guardado manualmente por campo/periodo. Ver
// contabilidade/APURAMENTO_IVA.md.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/apuramento-iva-functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_apuramento_iva')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();

$entities = getIvaTaskEntities($pdo, $isAdmin, $userId);
$entitiesById = [];
foreach ($entities as $entityRow) {
    $entitiesById[(int) $entityRow['id']] = $entityRow;
}

$entityId = (int) ($_GET['entity_id'] ?? $_POST['entity_id'] ?? 0);
$entity = $entitiesById[$entityId] ?? null;

if (!$entity) {
    http_response_code(404);
    echo 'Empresa inválida ou sem permissão.';
    exit;
}

$periodType = ((string) $entity['vat_periodicity']) === 'trimestral' ? 'trimestral' : 'mensal';
$currentYear = (int) date('Y');
$periodYear = (int) ($_GET['period_year'] ?? $_POST['period_year'] ?? $currentYear);
$defaultRef = $periodType === 'trimestral' ? (int) ceil(((int) date('n')) / 3) : (int) date('n');
$periodRef = (int) ($_GET['period_ref'] ?? $_POST['period_ref'] ?? $defaultRef);
$maxRef = $periodType === 'trimestral' ? 4 : 12;
if ($periodYear < 2000 || $periodYear > 2100) {
    $periodYear = $currentYear;
}
if ($periodRef < 1 || $periodRef > $maxRef) {
    $periodRef = $defaultRef;
}
$periodLabel = buildVatPeriodLabel($periodType, $periodYear, $periodRef);

$feedback = null;
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strcasecmp((string) $_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }

    if (($_POST['action'] ?? '') === 'save_field_values') {
        if (!hasTable('accounting_vat_settlement_field_values')) {
            $feedback = ['type' => 'danger', 'message' => 'A tabela accounting_vat_settlement_field_values ainda não existe. Execute as migrações.'];
        } else {
            $dpValues = is_array($_POST['dp_value'] ?? null) ? $_POST['dp_value'] : [];
            $stmt = $pdo->prepare(
                'INSERT INTO accounting_vat_settlement_field_values (accounting_entity_id, period_label, field_number, dp_value, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE dp_value = VALUES(dp_value), updated_by = VALUES(updated_by)'
            );
            foreach ($dpValues as $fieldNumber => $rawValue) {
                $fieldNumber = (int) $fieldNumber;
                if ($fieldNumber <= 0) {
                    continue;
                }
                $value = (float) str_replace(',', '.', (string) $rawValue);
                $stmt->execute([$entityId, $periodLabel, $fieldNumber, $value, $userId]);
            }
            logAuditAction('update', 'accounting_vat_settlement_field_values', $entityId, [
                'accounting_entity_id' => $entityId,
                'period_label' => $periodLabel,
                'changed_by' => $userId,
            ]);
            $feedback = ['type' => 'success', 'message' => 'Valores do período ' . $periodLabel . ' guardados.'];
        }
    }
}

$fieldFormulas = [];
if (hasTable('accounting_vat_field_formulas')) {
    $stmt = $pdo->query('SELECT field_number, formula FROM accounting_vat_field_formulas ORDER BY field_number ASC');
    $fieldFormulas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$dpValuesByField = [];
if (hasTable('accounting_vat_settlement_field_values')) {
    $stmt = $pdo->prepare(
        'SELECT field_number, dp_value FROM accounting_vat_settlement_field_values WHERE accounting_entity_id = ? AND period_label = ?'
    );
    $stmt->execute([$entityId, $periodLabel]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dpValuesByField[(int) $row['field_number']] = (float) $row['dp_value'];
    }
}

// Sem fonte real de balancete (ERP-SINC) ainda: accountBalances fica vazio,
// pelo que evaluateAccountingVatFieldFormula() devolve sempre 0.00. Assim
// que existir o endpoint, substituir [] pelos saldos reais por conta.
$accountBalances = [];

$fieldRows = [];
$hasError = false;
foreach ($fieldFormulas as $formulaRow) {
    $fieldNumber = (int) $formulaRow['field_number'];
    $dpValue = $dpValuesByField[$fieldNumber] ?? 0.0;
    try {
        $terms = parseAccountingVatFieldFormula((string) $formulaRow['formula']);
        $ctbValue = evaluateAccountingVatFieldFormula($terms, $accountBalances);
    } catch (InvalidArgumentException $e) {
        $ctbValue = 0.0;
    }
    $diff = round($dpValue - $ctbValue, 2);
    $ok = abs($diff) <= 0.01;
    if (!$ok) {
        $hasError = true;
    }
    $fieldRows[] = [
        'field_number' => $fieldNumber,
        'dp_value' => $dpValue,
        'ctb_value' => $ctbValue,
        'diff' => $diff,
        'ok' => $ok,
    ];
}

ob_start();
?>
<style>
    .iva-detail-warning {
        background: #fdf3e6; border: 1px solid #f8e2bd; color: #8a6417;
        border-radius: 4px; padding: 10px 14px; font-size: 12.5px; line-height: 1.5; margin-bottom: 16px;
    }
    .iva-detail-warning strong { color: #a9720f; }
    .iva-detail-period-form { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
    .iva-detail-period-form label { margin: 0; font-weight: 600; color: #2a3f54; white-space: nowrap; }
    .iva-detail-period-form select {
        height: 30px; padding: 3px 8px; font-size: 13px; line-height: 1.4; width: auto; box-sizing: border-box;
    }
    .iva-detail-table { width: 100%; border-collapse: collapse; }
    .iva-detail-table th {
        text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .03em;
        color: #979797; font-weight: 600; padding: 0 10px 6px;
    }
    .iva-detail-table th.iva-detail-col-status { text-align: center; }
    .iva-detail-table td { padding: 4px 10px; vertical-align: middle; }
    .iva-detail-table tr + tr td { border-top: 1px solid #f0f0f0; }
    .iva-detail-table tr { }
    .iva-detail-table tbody tr:hover td { background: #f9fafc; }
    .iva-detail-field-number { font-weight: 700; color: #2a3f54; white-space: nowrap; width: 56px; }
    .iva-detail-table .form-control {
        height: 32px; padding: 4px 8px; font-size: 13px; text-align: right;
    }
    .iva-detail-input-group { display: flex; align-items: stretch; }
    .iva-detail-input-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .iva-detail-input-group .iva-detail-currency {
        display: flex; align-items: center; padding: 0 8px; background: #eef1f5; color: #73879c;
        border: 1px solid #e5e6e7; border-left: none; border-top-right-radius: 4px; border-bottom-right-radius: 4px; font-size: 12.5px;
    }
    .iva-detail-col-status { width: 46px; text-align: center; }
    .iva-detail-status {
        width: 30px; height: 30px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center;
        color: #fff; font-size: 13px;
    }
    .iva-detail-status.ok { background: #26b99a; }
    .iva-detail-status.error { background: #e04b4a; }
    .iva-detail-actions { display: flex; align-items: center; gap: 12px; margin-top: 16px; }
</style>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($feedback['message']); ?>
</div>
<?php endif; ?>

<div class="iva-detail-warning">
    <strong>Sem dados reais do ERP-SINC ainda.</strong>
    "Ctr Ctb" está preparado para calcular a partir do balancete real
    (fórmulas em Configurações da tarefa), mas mostra sempre 0,00 até esse
    endpoint existir. "C{n}-DP" é, por agora, introduzido manualmente.
</div>

<form method="get" class="iva-detail-period-form" data-entity-id="<?= $entityId; ?>">
    <label><?= $periodType === 'trimestral' ? 'Trimestre:' : 'Mês:'; ?></label>
    <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
    <select name="period_year" class="form-control">
        <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
            <option value="<?= $y; ?>" <?= $y === $periodYear ? 'selected' : ''; ?>><?= $y; ?></option>
        <?php endfor; ?>
    </select>
    <select name="period_ref" class="form-control">
        <?php if ($periodType === 'trimestral'): ?>
            <?php foreach ([1, 2, 3, 4] as $q): ?>
                <option value="<?= $q; ?>" <?= $q === $periodRef ? 'selected' : ''; ?>><?= $q; ?>º Trimestre</option>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach (range(1, 12) as $m): ?>
                <option value="<?= $m; ?>" <?= $m === $periodRef ? 'selected' : ''; ?>><?= sprintf('%02d', $m); ?></option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <noscript><button type="submit" class="btn btn-default btn-sm">Filtrar</button></noscript>
</form>

<?php if (!$fieldFormulas): ?>
<div class="alert alert-info">
    Ainda não há campos configurados. Configura o mapeamento em
    <strong>Apuramento de IVA &gt; Configurações da tarefa</strong> (admin).
</div>
<?php else: ?>
<form method="post" class="iva-detail-form" data-entity-id="<?= $entityId; ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
    <input type="hidden" name="action" value="save_field_values">
    <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
    <input type="hidden" name="period_year" value="<?= $periodYear; ?>">
    <input type="hidden" name="period_ref" value="<?= $periodRef; ?>">

    <table class="iva-detail-table">
        <thead>
            <tr>
                <th>Campo</th>
                <th>DP</th>
                <th>Ctr Ctb</th>
                <th class="iva-detail-col-status">Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fieldRows as $row): ?>
            <tr>
                <td class="iva-detail-field-number">C<?= $row['field_number']; ?></td>
                <td>
                    <div class="iva-detail-input-group">
                        <input type="text" name="dp_value[<?= $row['field_number']; ?>]" class="form-control" value="<?= number_format($row['dp_value'], 2, ',', ''); ?>">
                        <span class="iva-detail-currency">€</span>
                    </div>
                </td>
                <td>
                    <div class="iva-detail-input-group">
                        <input type="text" class="form-control" value="<?= number_format($row['ctb_value'], 2, ',', ''); ?>" readonly>
                        <span class="iva-detail-currency">€</span>
                    </div>
                </td>
                <td class="iva-detail-col-status">
                    <span class="iva-detail-status <?= $row['ok'] ? 'ok' : 'error'; ?>" title="diferença: <?= number_format($row['diff'], 2, ',', '.'); ?>">
                        <i class="fa <?= $row['ok'] ? 'fa-check' : 'fa-exclamation-triangle'; ?>"></i>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="iva-detail-actions">
        <button type="submit" class="btn btn-primary">Guardar valores DP</button>
        <?php if ($hasError): ?>
        <span class="text-danger">Existem campos com diferença entre DP e Ctr Ctb.</span>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>
<?php
$ivaDetailFragment = ob_get_clean();

if ($isAjaxRequest) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h4 style="margin-top: 0;">Apuramento IVA - ' . htmlspecialchars((string) $entity['name']) . '</h4>';
    echo $ivaDetailFragment;
    exit;
}

$useDataTables = false;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Apuramento de IVA — Detalhes</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-list-alt"></i> Tarefa: Apuramento IVA - <?= htmlspecialchars((string) $entity['name']); ?></h2>
                <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                    <li>
                        <a href="<?= BASE_URL; ?>contabilidade/tarefas/apuramento-iva" class="btn btn-default btn-sm">
                            <i class="fa fa-arrow-left"></i> Voltar
                        </a>
                    </li>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?= $ivaDetailFragment; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
