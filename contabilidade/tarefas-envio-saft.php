<?php
// Tarefa "Envio de SAF-T": colaboradores enviam o ficheiro SAF-T das empresas
// que lhes foram atribuidas (permissao ctb_envio_saft, gerida na ficha da
// empresa em Entidades > Empresas > Admin). Administradores veem todas as
// empresas e todos os envios.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/saft-envio-functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_envio_saft')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();

if (!hasTable('accounting_saft_submissions')) {
    http_response_code(500);
    echo 'A tabela accounting_saft_submissions ainda nao existe. Execute as migracoes.';
    exit;
}

/**
 * Empresas (entidades adquirentes) a que o utilizador tem acesso nesta tarefa.
 */
function getSaftTaskEntities(PDO $pdo, bool $isAdmin, int $userId): array {
    if ($isAdmin) {
        $stmt = $pdo->query(
            "SELECT id, nif, name FROM accounting_entities
             WHERE entity_type = 'acquirer'
             ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $stmt = $pdo->prepare(
        "SELECT ae.id, ae.nif, ae.name
         FROM accounting_entities ae
         INNER JOIN accounting_entity_admin_task_permissions aep
             ON aep.accounting_entity_id = ae.id
         WHERE ae.entity_type = 'acquirer'
           AND aep.permission_key = 'ctb_envio_saft'
           AND aep.user_id = ?
         ORDER BY ae.name ASC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$entities = getSaftTaskEntities($pdo, $isAdmin, $userId);
$allowedEntityIds = array_map(static fn($row) => (int) $row['id'], $entities);

if (($_GET['action'] ?? '') === 'invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $submissionId = (int) ($_GET['submission_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT accounting_entity_id FROM accounting_saft_submissions WHERE id = ? LIMIT 1');
    $stmt->execute([$submissionId]);
    $entityId = (int) ($stmt->fetchColumn() ?: 0);
    if ($entityId <= 0 || (!$isAdmin && !in_array($entityId, $allowedEntityIds, true))) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão.']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT invoice_no, invoice_type, invoice_status, invoice_date, customer_id, tax_payable, net_total, gross_total
         FROM accounting_saft_invoices WHERE submission_id = ? ORDER BY invoice_date ASC, id ASC'
    );
    $stmt->execute([$submissionId]);
    echo json_encode(['invoices' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
    exit;
}

$feedback = null; // ['type' => 'success'|'danger', 'message' => string]
$foreignSales = [];
$foreignSalesEntity = null;
$foreignSalesPeriodYear = null;
$foreignSalesPeriodMonth = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }

    $action = $_POST['action'] ?? 'upload';

    if ($action === 'delete') {
        $submissionId = (int) ($_POST['submission_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, accounting_entity_id, user_id, file_path FROM accounting_saft_submissions WHERE id = ? LIMIT 1');
        $stmt->execute([$submissionId]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$submission) {
            $feedback = ['type' => 'danger', 'message' => 'Envio não encontrado.'];
        } elseif (!$isAdmin && !in_array((int) $submission['accounting_entity_id'], $allowedEntityIds, true)) {
            $feedback = ['type' => 'danger', 'message' => 'Sem permissão para eliminar este envio.'];
        } else {
            $fullPath = dirname(__DIR__) . '/' . ltrim((string) $submission['file_path'], '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
            $pdo->prepare('DELETE FROM accounting_saft_submissions WHERE id = ?')->execute([$submissionId]);
            logAuditAction('delete', 'accounting_saft_submission', $submissionId, [
                'accounting_entity_id' => (int) $submission['accounting_entity_id'],
            ]);
            $feedback = ['type' => 'success', 'message' => 'Envio eliminado.'];
        }
    } else {
        // A empresa (NIF) e o periodo (ano/mes) sao determinados a partir do
        // proprio cabecalho do ficheiro SAF-T, nao por seleccao manual.
        $resolveEntity = function (string $fileNif) use ($pdo, $isAdmin, $allowedEntityIds): array {
            $matchedEntity = saftResolveEntityByNif($pdo, $fileNif);
            if ($matchedEntity && !$isAdmin && !in_array((int) $matchedEntity['id'], $allowedEntityIds, true)) {
                return ['entity' => null, 'error' => 'Não tem permissão para enviar SAF-T da empresa ' . htmlspecialchars($matchedEntity['name']) . ' (NIF ' . htmlspecialchars($matchedEntity['nif']) . ').'];
            }
            return ['entity' => $matchedEntity, 'error' => null];
        };

        $result = saftHandleUpload($pdo, $_FILES['saft_file'] ?? [], $resolveEntity, $userId);
        $feedback = $result['feedback'];
        $foreignSales = $result['foreign_sales'] ?? [];
        $foreignSalesEntity = $result['entity'] ?? null;
        $foreignSalesPeriodYear = $result['period_year'] ?? null;
        $foreignSalesPeriodMonth = $result['period_month'] ?? null;
    }
}

$csrfToken = generateCsrfToken();

// Seletor de empresa no topbar (mesmo padrao do e-fatura): filtra a listagem
// de envios por uma das empresas a que o utilizador tem acesso nesta tarefa,
// ou todas quando nao ha seleção.
$saftSelectionSessionKey = 'saft_tarefas_selected_entity';
if (array_key_exists('empresa', $_GET)) {
    $selectedEntityFilter = (int) $_GET['empresa'];
    $_SESSION[$saftSelectionSessionKey] = $selectedEntityFilter;
} else {
    $selectedEntityFilter = (int) ($_SESSION[$saftSelectionSessionKey] ?? 0);
}
if ($selectedEntityFilter > 0 && !in_array($selectedEntityFilter, $allowedEntityIds, true)) {
    $selectedEntityFilter = 0;
}

$efaturaTopbarSelector = [
    'enabled' => !empty($entities),
    'action' => BASE_URL . 'contabilidade/tarefas/envio-saft',
    'selected_entity_id' => $selectedEntityFilter,
    'entities' => array_merge(
        [['value' => '0', 'label' => 'Todas as empresas']],
        array_map(static function (array $entity): array {
            return [
                'value' => (string) $entity['id'],
                'label' => (string) $entity['name'] . (trim((string) $entity['nif']) !== '' ? ' (' . $entity['nif'] . ')' : ''),
            ];
        }, $entities)
    ),
];

// Filtro de periodo (ano/mes) da listagem "Envios efetuados": os mesmos
// selects de Ano/Mês do formulário de envio, guardados em sessão.
$saftPeriodFilterSessionKey = 'saft_tarefas_period_filter';
if (array_key_exists('filtro_ano', $_GET) || array_key_exists('filtro_mes', $_GET)) {
    $filterYear = (int) ($_GET['filtro_ano'] ?? 0);
    $filterMonth = (int) ($_GET['filtro_mes'] ?? 0);
    $_SESSION[$saftPeriodFilterSessionKey] = ['year' => $filterYear, 'month' => $filterMonth];
} else {
    $storedFilter = $_SESSION[$saftPeriodFilterSessionKey] ?? ['year' => 0, 'month' => 0];
    $filterYear = (int) ($storedFilter['year'] ?? 0);
    $filterMonth = (int) ($storedFilter['month'] ?? 0);
}

$monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$currentYear = (int) date('Y');

// Listagem: admin ve todos os envios; colaborador so os das suas empresas.
// Filtros aplicados: empresa (topbar) e periodo ano/mes (selects abaixo).
$listEntityIds = $selectedEntityFilter > 0 ? [$selectedEntityFilter] : $allowedEntityIds;
$listConditions = [];
$listParams = [];
if (!$isAdmin || $selectedEntityFilter > 0) {
    if (!$listEntityIds) {
        $submissions = [];
    } else {
        $placeholders = implode(',', array_fill(0, count($listEntityIds), '?'));
        $listConditions[] = 's.accounting_entity_id IN (' . $placeholders . ')';
        $listParams = array_merge($listParams, $listEntityIds);
    }
}
if ($filterYear > 0) {
    $listConditions[] = 's.period_year = ?';
    $listParams[] = $filterYear;
}
if ($filterMonth > 0) {
    $listConditions[] = 's.period_month = ?';
    $listParams[] = $filterMonth;
}

if (!isset($submissions)) {
    $whereSql = $listConditions ? ' WHERE ' . implode(' AND ', $listConditions) : '';
    $stmt = $pdo->prepare(
        'SELECT s.*, ae.name AS entity_name, ae.nif AS entity_nif,
                COALESCE(NULLIF(TRIM(u.name), ""), u.username) AS user_label
         FROM accounting_saft_submissions s
         INNER JOIN accounting_entities ae ON ae.id = s.accounting_entity_id
         LEFT JOIN users u ON u.id = s.user_id'
        . $whereSql .
        ' ORDER BY s.created_at DESC'
    );
    $stmt->execute($listParams);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Agrupamento por empresa: uma linha de cabecalho por empresa atribuida ao
// utilizador nesta tarefa (accordion, fechado por defeito), com a tabela de
// detalhe atual (envios) por baixo. O periodo de referencia para saber se o
// SAF-T "do mes em questao" ja foi enviado e o do filtro Ano/Mes; quando o
// filtro esta em "Todos" assume-se o mes atual.
$refYear = $filterYear > 0 ? $filterYear : $currentYear;
$refMonth = $filterMonth > 0 ? $filterMonth : (int) date('n');

$entitiesForList = $selectedEntityFilter > 0
    ? array_values(array_filter($entities, static fn(array $e): bool => (int) $e['id'] === $selectedEntityFilter))
    : $entities;

$entityIdsWithRefSubmission = [];
if ($entitiesForList) {
    $refEntityIds = array_map(static fn(array $e): int => (int) $e['id'], $entitiesForList);
    $placeholders = implode(',', array_fill(0, count($refEntityIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT accounting_entity_id FROM accounting_saft_submissions
         WHERE accounting_entity_id IN ({$placeholders}) AND period_year = ? AND period_month = ?"
    );
    $stmt->execute(array_merge($refEntityIds, [$refYear, $refMonth]));
    $entityIdsWithRefSubmission = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

$submissionsByEntity = [];
foreach ($submissions as $submission) {
    $submissionsByEntity[(int) $submission['accounting_entity_id']][] = $submission;
}

$useDataTables = false;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Envio de SAF-T</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($feedback['message']); ?>
</div>
<?php endif; ?>


<?php if (!$entities): ?>
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            Não tem empresas atribuídas para esta tarefa. Contacte o administrador.
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-building"></i> Empresas — Envio de SAF-T<?= $isAdmin && $selectedEntityFilter === 0 ? ' (todas as empresas)' : ''; ?></h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <style>
                    .saft-filter-row {
                        display: flex;
                        align-items: center;
                        flex-wrap: wrap;
                        justify-content: space-between;
                        gap: 12px;
                        padding: 6px 0 14px;
                        border-bottom: 1px solid #e6e9ed;
                        margin-bottom: 14px !important;
                    }
                    .saft-period-filter { display: flex; align-items: center; flex-wrap: wrap; gap: 22px; }
                    .saft-period-filter .saft-period-field { display: flex; align-items: center; gap: 8px; }
                    .saft-period-filter .control-label { margin-bottom: 0; white-space: nowrap; }
                    .saft-period-filter select { min-width: 110px; }
                    .saft-actions {
                        display: flex !important;
                        align-items: center;
                        justify-content: flex-end;
                        gap: 6px;
                    }
                    .saft-actions-form {
                        display: contents;
                    }
                    .saft-icon-btn {
                        display: inline-flex !important;
                        align-items: center;
                        justify-content: center;
                        box-sizing: border-box;
                        width: 26px !important;
                        height: 26px !important;
                        padding: 0 !important;
                        line-height: 1;
                        margin: 0 !important;
                        vertical-align: middle;
                    }
                    .saft-entity-panel { border: 1px solid #e6e9ed; border-radius: 3px; margin-bottom: 10px; overflow: hidden; }
                    .saft-entity-header {
                        display: flex; align-items: center; gap: 10px; width: 100%;
                        padding: 12px 16px; background: #f7f9fb; border: none; text-align: left;
                    }
                    button.saft-entity-header { cursor: pointer; }
                    button.saft-entity-header:hover { background: #eef1f5; }
                    .saft-entity-header-pending { background: #fdf3e6; }
                    .saft-entity-name { font-weight: 600; color: #2a3f54; }
                    .saft-entity-chevron { transition: transform .15s ease; color: #73879c; }
                    .saft-entity-header[aria-expanded="true"] .saft-entity-chevron { transform: rotate(90deg); }
                    .saft-entity-count { margin-left: auto; font-size: 12px; color: #73879c; }
                    .saft-entity-header-pending .saft-entity-count { display: none; }
                </style>
                <div class="saft-filter-row">
                    <form method="get" class="saft-period-filter">
                        <input type="hidden" name="empresa" value="<?= (int) $selectedEntityFilter; ?>">
                        <div class="saft-period-field">
                            <label class="control-label">Ano</label>
                            <select class="form-control input-sm" name="filtro_ano" onchange="this.form.submit()">
                                <option value="0" <?= $filterYear === 0 ? 'selected' : ''; ?>>Todos</option>
                                <?php for ($y = $currentYear; $y >= $currentYear - 5; $y--): ?>
                                    <option value="<?= $y; ?>" <?= $y === $filterYear ? 'selected' : ''; ?>><?= $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="saft-period-field">
                            <label class="control-label">Mês</label>
                            <select class="form-control input-sm" name="filtro_mes" onchange="this.form.submit()">
                                <option value="0" <?= $filterMonth === 0 ? 'selected' : ''; ?>>Todos</option>
                                <?php foreach ($monthNames as $num => $label): ?>
                                    <option value="<?= $num; ?>" <?= $num === $filterMonth ? 'selected' : ''; ?>><?= htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <noscript><button type="submit" class="btn btn-default btn-sm">Filtrar</button></noscript>
                    </form>
                    <?php if ($entities): ?>
                    <form method="post" enctype="multipart/form-data" id="saft-upload-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="upload">
                        <input type="file" name="saft_file" accept=".xml,.zip,.gz" id="saft-file-input" style="display: none;">
                        <button type="button" class="btn btn-primary btn-sm" id="saft-upload-trigger">
                            <i class="fa fa-upload"></i> Enviar SAF-T
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if (!$entitiesForList): ?>
                    <div class="alert alert-info">Nenhuma empresa para os filtros atuais.</div>
                <?php endif; ?>

                <div class="saft-entity-accordion">
                <?php foreach ($entitiesForList as $entity):
                    $entityId = (int) $entity['id'];
                    $isPending = !in_array($entityId, $entityIdsWithRefSubmission, true);
                    $entitySubmissions = $submissionsByEntity[$entityId] ?? [];
                    $collapseId = 'saft-entity-detail-' . $entityId;
                    $entityLabel = htmlspecialchars((string) $entity['name'])
                        . (trim((string) $entity['nif']) !== '' ? ' <small class="text-muted">(' . htmlspecialchars((string) $entity['nif']) . ')</small>' : '');
                ?>
                    <div class="saft-entity-panel">
                    <?php if ($isPending): ?>
                        <div class="saft-entity-header saft-entity-header-pending">
                            <i class="fa fa-exclamation-triangle text-warning"></i>
                            <span class="saft-entity-name"><?= $entityLabel; ?></span>
                            <span class="label label-warning">
                                SAF-T de <?= htmlspecialchars($monthNames[$refMonth] ?? $refMonth); ?>/<?= $refYear; ?> ainda não enviado
                            </span>
                        </div>
                    <?php else: ?>
                        <button type="button" class="saft-entity-header" data-bs-toggle="collapse" data-bs-target="#<?= $collapseId; ?>" aria-expanded="false" aria-controls="<?= $collapseId; ?>">
                            <i class="fa fa-chevron-right saft-entity-chevron"></i>
                            <span class="saft-entity-name"><?= $entityLabel; ?></span>
                            <span class="label label-success">Enviado</span>
                            <span class="saft-entity-count"><?= count($entitySubmissions); ?> envio<?= count($entitySubmissions) === 1 ? '' : 's'; ?></span>
                        </button>
                        <div class="collapse" id="<?= $collapseId; ?>">
                            <div class="table-responsive">
                                <table class="table table-striped" style="margin-bottom: 0;">
                                    <thead>
                                        <tr>
                                            <th>Data de envio</th>
                                            <th>Período</th>
                                            <th>Ficheiro</th>
                                            <th>Tamanho</th>
                                            <th>Estado</th>
                                            <th>Resposta AT</th>
                                            <th>Valores extraídos</th>
                                            <th class="text-right">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($entitySubmissions as $submission): ?>
                                        <tr>
                                            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $submission['created_at']))); ?></td>
                                            <td>
                                                <?= htmlspecialchars(($monthNames[(int) $submission['period_month']] ?? $submission['period_month']) . ' ' . $submission['period_year']); ?>
                                            </td>
                                            <td><?= htmlspecialchars($submission['original_filename']); ?></td>
                                            <td>
                                                <?php
                                                    $size = (int) $submission['file_size'];
                                                    echo $size >= 1048576
                                                        ? htmlspecialchars(number_format($size / 1048576, 1, ',', '.')) . ' MB'
                                                        : htmlspecialchars(number_format(max(1, round($size / 1024)), 0, ',', '.')) . ' KB';
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $status = (string) ($submission['status'] ?? 'registado');
                                                    $statusClass = $status === 'enviado' ? 'label-success'
                                                        : ($status === 'erro' ? 'label-danger'
                                                        : ($status === 'teste' ? 'label-warning' : 'label-default'));
                                                ?>
                                                <span class="label <?= $statusClass; ?>"><?= htmlspecialchars(ucfirst($status)); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                    $atCode = trim((string) ($submission['at_response_code'] ?? ''));
                                                    $atDetails = [];
                                                    if ($atCode !== '') {
                                                        $atDetails[] = 'Código: ' . $atCode;
                                                    }
                                                    if (trim((string) ($submission['at_id_ficheiro'] ?? '')) !== '') {
                                                        $atDetails[] = 'Ficheiro AT n.º ' . trim((string) $submission['at_id_ficheiro']);
                                                    }
                                                    if (trim((string) ($submission['at_total_faturas'] ?? '')) !== '') {
                                                        $atDetails[] = 'Faturas: ' . trim((string) $submission['at_total_faturas'])
                                                            . ' | Créditos: ' . trim((string) ($submission['at_total_creditos'] ?? '—'))
                                                            . ' | Débitos: ' . trim((string) ($submission['at_total_debitos'] ?? '—'));
                                                    }
                                                    $atTooltipParts = [];
                                                    if (trim((string) ($submission['at_warning'] ?? '')) !== '') {
                                                        $atTooltipParts[] = 'Aviso: ' . trim((string) $submission['at_warning']);
                                                    }
                                                    if (trim((string) ($submission['at_errors'] ?? '')) !== '') {
                                                        $atTooltipParts[] = 'Erros: ' . trim((string) $submission['at_errors']);
                                                    }
                                                ?>
                                                <?php if ($atDetails): ?>
                                                    <small<?= $atTooltipParts ? ' title="' . htmlspecialchars(implode("\n", $atTooltipParts), ENT_QUOTES) . '"' : ''; ?>>
                                                        <?= htmlspecialchars(implode(' · ', $atDetails)); ?>
                                                        <?php if ($atTooltipParts): ?><i class="fa fa-info-circle text-warning"></i><?php endif; ?>
                                                    </small>
                                                <?php elseif ($atTooltipParts): ?>
                                                    <small class="text-danger" title="<?= htmlspecialchars(implode("\n", $atTooltipParts), ENT_QUOTES); ?>">
                                                        <?= htmlspecialchars(mb_strimwidth(implode(' ', $atTooltipParts), 0, 80, '…')); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $extractionError = trim((string) ($submission['saft_extraction_error'] ?? ''));
                                                    $numberOfEntries = $submission['saft_number_of_entries'] ?? null;
                                                ?>
                                                <?php if ($extractionError !== ''): ?>
                                                    <small class="text-danger" title="<?= htmlspecialchars($extractionError, ENT_QUOTES); ?>">
                                                        <i class="fa fa-exclamation-triangle"></i> Falha na extração
                                                    </small>
                                                <?php elseif ($numberOfEntries !== null): ?>
                                                    <small>
                                                        <?= (int) $numberOfEntries; ?> faturas
                                                        · Débito: <?= htmlspecialchars(number_format((float) ($submission['saft_total_debit'] ?? 0), 2, ',', '.')); ?>
                                                        · Crédito: <?= htmlspecialchars(number_format((float) ($submission['saft_total_credit'] ?? 0), 2, ',', '.')); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-right" style="white-space: nowrap;">
                                                <div class="saft-actions">
                                                    <?php if (($submission['saft_number_of_entries'] ?? null) !== null): ?>
                                                    <button type="button" class="btn btn-xs btn-default saft-icon-btn saft-view-invoices"
                                                        title="Ver faturas"
                                                        data-submission-id="<?= (int) $submission['id']; ?>"
                                                        data-entity-name="<?= htmlspecialchars((string) $submission['entity_name'], ENT_QUOTES); ?>"
                                                        data-period="<?= htmlspecialchars(($monthNames[(int) $submission['period_month']] ?? '') . ' ' . $submission['period_year'], ENT_QUOTES); ?>">
                                                        <i class="fa fa-list"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                    <a class="btn btn-xs btn-default saft-icon-btn" href="<?= htmlspecialchars(BASE_URL . ltrim((string) $submission['file_path'], '/')); ?>" download="<?= htmlspecialchars($submission['original_filename'], ENT_QUOTES); ?>" title="Transferir">
                                                        <i class="fa fa-download"></i>
                                                    </a>
                                                    <form method="post" class="saft-actions-form" onsubmit="return confirm('Eliminar este envio de SAF-T?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="submission_id" value="<?= (int) $submission['id']; ?>">
                                                        <button type="submit" class="btn btn-xs btn-danger saft-icon-btn" title="Eliminar">
                                                            <i class="fa fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($foreignSales && $foreignSalesEntity):
    $foreignInvoiceCount = 0;
    foreach ($foreignSales as $sale) {
        $foreignInvoiceCount += count($sale['invoices']);
    }
    $foreignCustomerCount = count($foreignSales);
    $drRows = [];
    foreach ($foreignSales as $customerId => $sale) {
        $drRows[] = [
            'customer_id' => (string) $customerId,
            'country' => strtoupper((string) ($sale['country'] ?? substr((string) $customerId, 0, 2))),
            'nif' => (string) ($sale['tax_id'] ?? ''),
            'value' => round((float) ($sale['value'] ?? 0), 2),
            'type' => '5',
            'invoices' => $sale['invoices'],
        ];
    }
?>
<div class="modal fade" id="saft-foreign-sales-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content saft-foreign-sales-modal-content">
            <div class="modal-header saft-foreign-sales-header">
                <div class="saft-foreign-sales-icon"><i class="fa fa-exclamation-triangle"></i></div>
                <div class="saft-foreign-sales-heading">
                    <h5 class="modal-title">Alerta! Vendas intracomunitárias e/ou para países terceiros</h5>
                    <span class="saft-foreign-sales-subtitle">
                        <?= (int) $foreignCustomerCount; ?> cliente<?= $foreignCustomerCount === 1 ? '' : 's'; ?>
                        &middot; <?= (int) $foreignInvoiceCount; ?> fatura<?= $foreignInvoiceCount === 1 ? '' : 's'; ?>
                    </span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" style="padding: 20px;">
                <p class="text-muted" style="font-size: 12.5px;">
                    Dados de partida para a Declaração Recapitulativa de IVA (período
                    <strong><?= str_pad((string) $foreignSalesPeriodMonth, 2, '0', STR_PAD_LEFT); ?>/<?= (int) $foreignSalesPeriodYear; ?></strong>).
                    O NIF do adquirente e o tipo de operação (bens/triangular/serviços) vêm por defeito do SAF-T
                    e devem ser confirmados/corrigidos antes de gerar o ficheiro.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered" id="saft-dr-table" style="margin-bottom: 10px;">
                        <thead style="background: #f7f9fb;">
                            <tr>
                                <th style="width: 90px;">País</th>
                                <th>NIF Adquirente</th>
                                <th style="width: 140px;">Valor (€)</th>
                                <th style="width: 180px;">Tipo de operação</th>
                                <th style="width: 40px;"></th>
                            </tr>
                        </thead>
                        <tbody id="saft-dr-table-body">
                            <?php foreach ($drRows as $i => $row): ?>
                            <tr title="Faturas: <?= htmlspecialchars(implode(', ', $row['invoices'])); ?>">
                                <td><input type="text" class="form-control input-sm dr-country" maxlength="2" value="<?= htmlspecialchars($row['country']); ?>"></td>
                                <td><input type="text" class="form-control input-sm dr-nif" value="<?= htmlspecialchars($row['nif']); ?>" placeholder="NIF do adquirente"></td>
                                <td><input type="number" step="0.01" min="0" class="form-control input-sm dr-value" value="<?= htmlspecialchars((string) $row['value']); ?>"></td>
                                <td>
                                    <select class="form-control input-sm dr-type">
                                        <option value="1">1 - Transmissão de bens</option>
                                        <option value="4">4 - Operação triangular</option>
                                        <option value="5" selected>5 - Prestação de serviços</option>
                                    </select>
                                </td>
                                <td class="text-center"><button type="button" class="btn btn-xs btn-link text-danger dr-remove-row" title="Remover linha"><i class="fa fa-trash"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-xs btn-default" id="saft-dr-add-row"><i class="fa fa-plus"></i> Adicionar linha</button>

                <div class="row" style="margin-top: 16px;">
                    <div class="col-sm-6 form-group">
                        <label style="font-size: 12px;">Email de destino (para "Enviar Ficheiro")</label>
                        <input type="email" class="form-control input-sm" id="saft-dr-dest-email">
                    </div>
                </div>
                <div id="saft-dr-feedback" class="alert d-none" style="font-size: 12.5px; padding: 8px 12px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-primary" id="saft-dr-download"><i class="fa fa-paperclip"></i> Download Ficheiro</button>
                <button type="button" class="btn btn-primary" id="saft-dr-send"><i class="fa fa-envelope"></i> Enviar Ficheiro</button>
            </div>
        </div>
    </div>
</div>
<form id="saft-dr-download-form" method="post" action="<?= htmlspecialchars(BASE_URL . 'contabilidade/saft-dr-xml.php'); ?>" style="display:none;" target="_blank">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="download">
    <input type="hidden" name="entity_id" value="<?= (int) $foreignSalesEntity['id']; ?>">
    <input type="hidden" name="year" value="<?= (int) $foreignSalesPeriodYear; ?>">
    <input type="hidden" name="month" value="<?= (int) $foreignSalesPeriodMonth; ?>">
    <input type="hidden" name="rows" id="saft-dr-download-rows">
</form>
<style>
.saft-foreign-sales-header{align-items:flex-start;gap:14px;}
.saft-foreign-sales-icon{flex:0 0 auto;width:42px;height:42px;border-radius:50%;background:#fdecea;color:#d9534f;display:flex;align-items:center;justify-content:center;font-size:18px;}
.saft-foreign-sales-heading{flex:1 1 auto;min-width:0;}
.saft-foreign-sales-heading .modal-title{color:#c0392b;font-size:16px;line-height:1.35;margin-bottom:2px;}
.saft-foreign-sales-subtitle{display:block;font-size:12px;color:#73879c;}
#saft-dr-table input.form-control, #saft-dr-table select.form-control{font-size:12.5px;height:30px;padding:4px 8px;}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalElement = document.getElementById('saft-foreign-sales-modal');
    if (modalElement && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    }

    var tableBody = document.getElementById('saft-dr-table-body');
    var feedbackEl = document.getElementById('saft-dr-feedback');
    var csrfToken = <?= json_encode($csrfToken); ?>;
    var entityId = <?= (int) $foreignSalesEntity['id']; ?>;
    var year = <?= (int) $foreignSalesPeriodYear; ?>;
    var month = <?= (int) $foreignSalesPeriodMonth; ?>;

    var DR_LAST_EMAIL_KEY = 'saftDrLastDestEmail';
    var destEmailInput = document.getElementById('saft-dr-dest-email');
    var lastDestEmail = window.localStorage ? localStorage.getItem(DR_LAST_EMAIL_KEY) : null;
    if (lastDestEmail) {
        destEmailInput.value = lastDestEmail;
    }

    document.getElementById('saft-dr-add-row').addEventListener('click', function () {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input type="text" class="form-control input-sm dr-country" maxlength="2"></td>'
            + '<td><input type="text" class="form-control input-sm dr-nif" placeholder="NIF do adquirente"></td>'
            + '<td><input type="number" step="0.01" min="0" class="form-control input-sm dr-value" value="0"></td>'
            + '<td><select class="form-control input-sm dr-type"><option value="1">1 - Transmissão de bens</option><option value="4">4 - Operação triangular</option><option value="5" selected>5 - Prestação de serviços</option></select></td>'
            + '<td class="text-center"><button type="button" class="btn btn-xs btn-link text-danger dr-remove-row" title="Remover linha"><i class="fa fa-trash"></i></button></td>';
        tableBody.appendChild(tr);
    });

    tableBody.addEventListener('click', function (e) {
        var btn = e.target.closest('.dr-remove-row');
        if (btn) {
            btn.closest('tr').remove();
        }
    });

    function collectRows() {
        var rows = [];
        tableBody.querySelectorAll('tr').forEach(function (tr) {
            rows.push({
                country: tr.querySelector('.dr-country').value.trim(),
                nif: tr.querySelector('.dr-nif').value.trim(),
                value: parseFloat(tr.querySelector('.dr-value').value) || 0,
                type: tr.querySelector('.dr-type').value
            });
        });
        return rows;
    }

    function showFeedback(type, message) {
        feedbackEl.className = 'alert alert-' + type;
        feedbackEl.textContent = message;
    }

    document.getElementById('saft-dr-download').addEventListener('click', function () {
        document.getElementById('saft-dr-download-rows').value = JSON.stringify(collectRows());
        document.getElementById('saft-dr-download-form').submit();
    });

    document.getElementById('saft-dr-send').addEventListener('click', function () {
        var destEmail = destEmailInput.value.trim();
        if (!destEmail) {
            showFeedback('warning', 'Indique o email de destino.');
            return;
        }
        if (window.localStorage) {
            localStorage.setItem(DR_LAST_EMAIL_KEY, destEmail);
        }
        var sendBtn = this;
        sendBtn.disabled = true;
        var params = new URLSearchParams();
        params.set('csrf_token', csrfToken);
        params.set('action', 'send');
        params.set('entity_id', entityId);
        params.set('year', year);
        params.set('month', month);
        params.set('dest_email', destEmail);
        params.set('rows', JSON.stringify(collectRows()));

        fetch('<?= htmlspecialchars(BASE_URL . 'contabilidade/saft-dr-xml.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        }).then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.success) {
                    showFeedback('success', 'Ficheiro enviado para ' + destEmail + '.');
                } else {
                    showFeedback('danger', 'Falha ao enviar: ' + (data.error || 'erro desconhecido.'));
                }
            })
            .catch(function () {
                showFeedback('danger', 'Falha ao enviar o ficheiro.');
            })
            .finally(function () {
                sendBtn.disabled = false;
            });
    });
});
</script>
<?php endif; ?>

<div class="modal fade" id="saft-invoices-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-list"></i> Faturas extraídas do SAF-T</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" style="padding: 0;">
                <div id="saft-invoices-modal-summary" class="d-flex flex-wrap justify-content-between align-items-center" style="padding: 12px 20px; background: #f7f9fb; border-bottom: 1px solid #e6e9ed; font-size: 13px; color: #73879c;"></div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm" style="margin-bottom: 0;">
                        <thead style="background: #f7f9fb;">
                            <tr>
                                <th style="padding: 10px 20px;">N.º Fatura</th>
                                <th>Tipo</th>
                                <th>Data</th>
                                <th>Cliente</th>
                                <th class="text-right">Total s/IVA</th>
                                <th class="text-right">IVA</th>
                                <th class="text-right" style="padding-right: 20px;">Total c/IVA</th>
                            </tr>
                        </thead>
                        <tbody id="saft-invoices-modal-body">
                            <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;"><i class="fa fa-spinner fa-spin"></i> A carregar…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var saftUploadTrigger = document.getElementById('saft-upload-trigger');
    var saftFileInput = document.getElementById('saft-file-input');
    if (saftUploadTrigger && saftFileInput) {
        saftUploadTrigger.addEventListener('click', function () {
            saftFileInput.click();
        });
        saftFileInput.addEventListener('change', function () {
            if (saftFileInput.files.length) {
                document.getElementById('saft-upload-form').submit();
            }
        });
    }

    document.querySelectorAll('.saft-view-invoices').forEach(function (button) {
        button.addEventListener('click', function () {
            var submissionId = button.getAttribute('data-submission-id');
            var body = document.getElementById('saft-invoices-modal-body');
            var summary = document.getElementById('saft-invoices-modal-summary');
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding: 30px;"><i class="fa fa-spinner fa-spin"></i> A carregar…</td></tr>';
            summary.innerHTML = '<strong>' + escapeHtml(button.getAttribute('data-entity-name') || '') + '</strong>'
                + '<span>' + escapeHtml(button.getAttribute('data-period') || '') + '</span>';
            if (window.jQuery) {
                $('#saft-invoices-modal').modal('show');
            }
            fetch('<?= htmlspecialchars(BASE_URL . 'contabilidade/tarefas/envio-saft'); ?>?action=invoices&submission_id=' + encodeURIComponent(submissionId))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    var invoices = data.invoices || [];
                    if (!invoices.length) {
                        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">Sem faturas extraídas.</td></tr>';
                        return;
                    }
                    var totalNet = 0, totalTax = 0, totalGross = 0;
                    body.innerHTML = invoices.map(function (inv) {
                        totalNet += parseFloat(inv.net_total) || 0;
                        totalTax += parseFloat(inv.tax_payable) || 0;
                        totalGross += parseFloat(inv.gross_total) || 0;
                        return '<tr>'
                            + '<td style="padding-left: 20px;">' + escapeHtml(inv.invoice_no || '') + '</td>'
                            + '<td>' + escapeHtml(inv.invoice_type || '') + '</td>'
                            + '<td>' + formatDate(inv.invoice_date) + '</td>'
                            + '<td>' + escapeHtml(inv.customer_id || '') + '</td>'
                            + '<td class="text-right">' + formatMoney(inv.net_total) + '</td>'
                            + '<td class="text-right">' + formatMoney(inv.tax_payable) + '</td>'
                            + '<td class="text-right" style="padding-right: 20px;">' + formatMoney(inv.gross_total) + '</td>'
                            + '</tr>';
                    }).join('');
                    summary.innerHTML += '<span><strong>' + invoices.length + '</strong> faturas &nbsp;·&nbsp; Total c/IVA: <strong>' + formatMoney(totalGross) + '</strong></span>';
                })
                .catch(function () {
                    body.innerHTML = '<tr><td colspan="7" class="text-center text-danger" style="padding: 30px;">Erro ao carregar faturas.</td></tr>';
                });
        });
    });

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function formatMoney(value) {
        var num = parseFloat(value);
        if (isNaN(num)) { return '—'; }
        return num.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDate(value) {
        if (!value) { return '—'; }
        var parts = String(value).split('-');
        if (parts.length === 3) {
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }
        return escapeHtml(value);
    }
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
