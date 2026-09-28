<?php
// Registo de atividade (eventos) por cliente. Porta o "Criar novo evento" da
// intranet legacy (intranet/workflow.php?act=novo + data/workflow.php
// accaop=adiciona). Vistas:
//   contabilidade/atividades             listagem (filtros + DataTables server-side)
//   contabilidade/atividades/novo        formulario (Cliente / Tipo de evento / Finalizar)
//   contabilidade/atividades/{uuid}      visualizar evento (imprimir, anular/reativar, historico)
//   contabilidade/atividades/categorias  gestao das categorias (administradores)

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/atividades-functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!isModuleActive('contabilidade')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();
$tablesReady = hasAccountingActivityTables();

$view = (string) ($_GET['view'] ?? 'lista');
if (!in_array($view, ['lista', 'novo', 'ver', 'categorias'], true)) {
    $view = 'lista';
}
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

// Helpers partilhados com o dashboard em contabilidade/atividades-functions.php.
function activityJson(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// AJAX: dados da listagem (DataTables server-side)
// ---------------------------------------------------------------------------
if ($action === 'list-data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $draw = (int) ($_GET['draw'] ?? 1);
    if (!$tablesReady) {
        activityJson(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
    }
    $start = max(0, (int) ($_GET['start'] ?? 0));
    $length = (int) ($_GET['length'] ?? 25);
    $length = $length <= 0 ? 1000 : min($length, 1000);

    $where = [];
    $params = [];
    $status = (string) ($_GET['status'] ?? 'active');
    if ($status === 'active') {
        $where[] = 'a.status = 1';
    } elseif ($status === 'cancelled') {
        $where[] = 'a.status = 0';
    }
    $responsibleFilter = (int) ($_GET['responsible'] ?? 0);
    if ($responsibleFilter > 0) {
        $where[] = 'a.responsible_user_id = ?';
        $params[] = $responsibleFilter;
    }
    $categoryFilter = (int) ($_GET['category'] ?? 0);
    if ($categoryFilter > 0) {
        $where[] = 'EXISTS (SELECT 1 FROM accounting_activity_lines fl WHERE fl.activity_id = a.id AND fl.category_id = ?)';
        $params[] = $categoryFilter;
    }
    foreach (['date_from' => '>=', 'date_to' => '<'] as $key => $operator) {
        $raw = trim((string) ($_GET[$key] ?? ''));
        $date = $raw !== '' ? DateTime::createFromFormat('!Y-m-d', $raw) : false;
        if ($date && $date->format('Y-m-d') === $raw) {
            if ($key === 'date_to') {
                $date->modify('+1 day');
            }
            $where[] = "a.created_at $operator ?";
            $params[] = $date->format('Y-m-d H:i:s');
        }
    }
    $search = trim((string) ($_GET['search']['value'] ?? ''));
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(a.code LIKE ? OR a.client_name LIKE ? OR a.client_code LIKE ? OR a.client_nif LIKE ?)';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $orderColumns = [0 => 'a.id', 1 => 'a.created_at', 2 => 'a.client_name', 4 => 'responsible_sort', 5 => 'a.status'];
    $orderIndex = (int) ($_GET['order'][0]['column'] ?? 1);
    $orderSql = $orderColumns[$orderIndex] ?? 'a.created_at';
    $orderDir = strtolower((string) ($_GET['order'][0]['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $recordsTotal = (int) $pdo->query('SELECT COUNT(*) FROM accounting_activities')->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM accounting_activities a $whereSql");
    $stmt->execute($params);
    $recordsFiltered = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT a.id, a.uuid, a.code, a.client_name, a.client_code, a.client_nif, a.status, a.created_at,
                ru.name AS responsible_name, ru.username AS responsible_username,
                COALESCE(NULLIF(TRIM(ru.name), ''), ru.username, '') AS responsible_sort
         FROM accounting_activities a
         LEFT JOIN users ru ON ru.id = a.responsible_user_id
         $whereSql
         ORDER BY $orderSql $orderDir, a.id DESC
         LIMIT $length OFFSET $start"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $linesByActivity = getActivityLinesSummary($pdo, array_column($rows, 'id'));

    $data = [];
    foreach ($rows as $row) {
        $data[] = [
            'uuid' => (string) $row['uuid'],
            'code' => (string) $row['code'],
            'created_at' => formatActivityDateTime((string) $row['created_at']),
            'created_relative' => formatActivityRelative((string) $row['created_at']),
            'client_name' => (string) $row['client_name'],
            'client_code' => (string) $row['client_code'],
            'client_nif' => (string) $row['client_nif'],
            'client_initials' => activityInitials((string) $row['client_name']),
            'lines' => $linesByActivity[(int) $row['id']] ?? [],
            'responsible' => activityUserLabel(['name' => $row['responsible_name'], 'username' => $row['responsible_username']]),
            'status' => (int) $row['status'],
            'view_url' => BASE_URL . 'contabilidade/atividades/' . $row['uuid'],
        ];
    }
    activityJson([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data,
    ]);
}

// ---------------------------------------------------------------------------
// AJAX: pesquisa de clientes (entidades adquirentes)
// ---------------------------------------------------------------------------
if ($action === 'search-clients' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $term = trim((string) ($_GET['q'] ?? ''));
    $exact = trim((string) ($_GET['exact'] ?? ''));
    if ($exact !== '') {
        // Procura exata por codigo ou NIF (campos Codigo/NIF do formulario).
        $stmt = $pdo->prepare(
            "SELECT id, name, nif, erp_client_code FROM accounting_entities
             WHERE entity_type = 'acquirer' AND (erp_client_code = ? OR nif = ?)
             ORDER BY name ASC LIMIT 2"
        );
        $stmt->execute([$exact, $exact]);
    } elseif (mb_strlen($term) >= 2) {
        $like = '%' . $term . '%';
        $stmt = $pdo->prepare(
            "SELECT id, name, nif, erp_client_code FROM accounting_entities
             WHERE entity_type = 'acquirer' AND (name LIKE ? OR nif LIKE ? OR erp_client_code LIKE ?)
             ORDER BY name ASC LIMIT 30"
        );
        $stmt->execute([$like, $like, $like]);
    } else {
        activityJson(['results' => []]);
    }
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $code = trim((string) ($row['erp_client_code'] ?? ''));
        $results[] = [
            'id' => (int) $row['id'],
            'text' => (string) $row['name'] . ($code !== '' ? ' (' . $code . ')' : ''),
            'name' => (string) $row['name'],
            'nif' => (string) ($row['nif'] ?? ''),
            'code' => $code,
        ];
    }
    activityJson(['results' => $results]);
}

// ---------------------------------------------------------------------------
// POST: gravar evento
// ---------------------------------------------------------------------------
if ($action === 'save-activity' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        activityJson(['ok' => false, 'message' => 'Sessão expirada. Recarregue a página.', 'csrf_token' => generateCsrfToken(true)], 400);
    }
    $fail = static function (string $message): void {
        activityJson(['ok' => false, 'message' => $message, 'csrf_token' => generateCsrfToken(true)], 422);
    };
    if (!$tablesReady) {
        $fail('As tabelas de atividades ainda não existem. Execute as migrações.');
    }

    $entityId = (int) ($_POST['entity_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, name, nif, erp_client_code FROM accounting_entities WHERE id = ? AND entity_type = 'acquirer' LIMIT 1");
    $stmt->execute([$entityId]);
    $entity = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entity) {
        $fail('Selecione o cliente.');
    }

    $responsibleId = (int) ($_POST['responsible_user_id'] ?? 0);
    if ($responsibleId <= 0 || !getUserById($responsibleId)) {
        $fail('Selecione o colaborador.');
    }

    $categories = [];
    foreach (getActivityCategories($pdo, true) as $category) {
        $categories[(int) $category['id']] = $category;
    }

    $lines = [];
    $postedLines = $_POST['lines'] ?? [];
    if (!is_array($postedLines)) {
        $postedLines = [];
    }
    foreach ($postedLines as $postedLine) {
        if (!is_array($postedLine)) {
            continue;
        }
        $categoryId = (int) ($postedLine['category_id'] ?? 0);
        if ($categoryId <= 0) {
            continue;
        }
        if (!isset($categories[$categoryId])) {
            $fail('Categoria inválida ou inativa.');
        }
        $category = $categories[$categoryId];
        $label = trim((string) $category['extra_field_label']) !== '' ? (string) $category['extra_field_label'] : (string) $category['title'];
        [$value, $error] = normalizeActivityExtraValue((string) $category['extra_field_type'], (string) ($postedLine['value'] ?? ''), $label);
        if ($error !== null) {
            $fail($error);
        }
        $fixedAssets = null;
        if ((int) $category['asks_fixed_assets'] === 1) {
            $rawFixedAssets = (string) ($postedLine['fixed_assets_sold'] ?? '');
            if ($rawFixedAssets !== '0' && $rawFixedAssets !== '1') {
                $fail('Por favor confirme se houve ou não vendas de imobilizado (' . $category['title'] . ').');
            }
            $fixedAssets = (int) $rawFixedAssets;
        }
        $lines[] = ['category_id' => $categoryId, 'value' => $value, 'fixed_assets_sold' => $fixedAssets];
    }
    if (!$lines) {
        $fail('Introduza a categoria no Tipo de evento.');
    }

    $notes = sanitizeActivityNotes((string) ($_POST['notes'] ?? ''));

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO accounting_activities
                (uuid, accounting_entity_id, client_name, client_code, client_nif, responsible_user_id, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $activityUuid = generateAccountingEntityUuid();
        $stmt->execute([
            $activityUuid,
            (int) $entity['id'],
            (string) $entity['name'],
            (string) ($entity['erp_client_code'] ?? ''),
            (string) ($entity['nif'] ?? ''),
            $responsibleId,
            $notes !== '' ? $notes : null,
            $userId > 0 ? $userId : null,
        ]);
        $activityId = (int) $pdo->lastInsertId();
        // Codigo no formato legado: W-<ano 2 digitos><id>.
        $code = 'W-' . date('y') . $activityId;
        $pdo->prepare('UPDATE accounting_activities SET code = ? WHERE id = ?')->execute([$code, $activityId]);

        $lineStmt = $pdo->prepare(
            'INSERT INTO accounting_activity_lines (activity_id, line_no, category_id, value, fixed_assets_sold)
             VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($lines as $index => $line) {
            $lineStmt->execute([$activityId, $index + 1, $line['category_id'], $line['value'], $line['fixed_assets_sold']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $fail('Falha ao gravar o evento: ' . $e->getMessage());
    }

    logAuditAction('create', 'accounting_activity', $activityId, [
        'code' => $code,
        'uuid' => $activityUuid,
        'accounting_entity_id' => (int) $entity['id'],
        'responsible_user_id' => $responsibleId,
        'lines' => count($lines),
    ]);

    activityJson([
        'ok' => true,
        'message' => 'O evento ' . $code . ' foi criado com sucesso.',
        'code' => $code,
        'view_url' => BASE_URL . 'contabilidade/atividades/' . $activityUuid,
        'csrf_token' => generateCsrfToken(true),
    ]);
}

// ---------------------------------------------------------------------------
// POST: anular / reativar evento (administradores)
// ---------------------------------------------------------------------------
if (in_array($action, ['cancel-activity', 'reactivate-activity'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $activityUuid = strtolower(trim((string) ($_POST['activity_uuid'] ?? '')));
    if (!isValidAccountingEntityUuid($activityUuid)) {
        http_response_code(400);
        echo 'Evento inválido.';
        exit;
    }
    $returnUrl = BASE_URL . 'contabilidade/atividades/' . $activityUuid;
    if (!$isAdmin) {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        setSessionFlash('accounting_activities', ['type' => 'danger', 'message' => 'Sessão expirada. Tente novamente.']);
    } elseif ($tablesReady) {
        $status = $action === 'cancel-activity' ? 0 : 1;
        $stmt = $pdo->prepare('SELECT id FROM accounting_activities WHERE uuid = ? LIMIT 1');
        $stmt->execute([$activityUuid]);
        $activityId = (int) ($stmt->fetchColumn() ?: 0);
        $pdo->prepare('UPDATE accounting_activities SET status = ? WHERE id = ?')->execute([$status, $activityId]);
        $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 500);
        logAuditAction($status ? 'reactivate' : 'cancel', 'accounting_activity', $activityId, $reason !== '' ? ['reason' => $reason] : []);
        setSessionFlash('accounting_activities', [
            'type' => 'success',
            'message' => $status ? 'Evento ativado com sucesso.' : 'Evento anulado com sucesso.',
        ]);
    }
    header('Location: ' . $returnUrl);
    exit;
}

// ---------------------------------------------------------------------------
// POST: categorias (administradores)
// ---------------------------------------------------------------------------
if (in_array($action, ['save-category', 'toggle-category'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnUrl = BASE_URL . 'contabilidade/atividades/categorias';
    if (!$isAdmin) {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        setSessionFlash('accounting_activities', ['type' => 'danger', 'message' => 'Sessão expirada. Tente novamente.']);
        header('Location: ' . $returnUrl);
        exit;
    }
    if (!$tablesReady) {
        setSessionFlash('accounting_activities', ['type' => 'danger', 'message' => 'Execute as migrações antes de gerir categorias.']);
        header('Location: ' . $returnUrl);
        exit;
    }

    $categoryId = (int) ($_POST['category_id'] ?? 0);
    if ($action === 'toggle-category') {
        $pdo->prepare('UPDATE accounting_activity_categories SET is_active = 1 - is_active WHERE id = ?')->execute([$categoryId]);
        logAuditAction('toggle', 'accounting_activity_category', $categoryId);
        setSessionFlash('accounting_activities', ['type' => 'success', 'message' => 'Estado da categoria atualizado.']);
        header('Location: ' . $returnUrl);
        exit;
    }

    $title = trim((string) ($_POST['title'] ?? ''));
    $extraType = (string) ($_POST['extra_field_type'] ?? 'none');
    if (!array_key_exists($extraType, ACTIVITY_EXTRA_FIELD_TYPES)) {
        $extraType = 'none';
    }
    $extraLabel = $extraType === 'none' ? '' : mb_substr(trim((string) ($_POST['extra_field_label'] ?? '')), 0, 100);
    $asksFixedAssets = !empty($_POST['asks_fixed_assets']) ? 1 : 0;

    if ($title === '') {
        setSessionFlash('accounting_activities', ['type' => 'danger', 'message' => 'Indique o título da categoria.']);
        header('Location: ' . $returnUrl . ($categoryId > 0 ? '?edit=' . $categoryId : ''));
        exit;
    }
    $title = mb_substr($title, 0, 150);

    if ($categoryId > 0) {
        $pdo->prepare(
            'UPDATE accounting_activity_categories
             SET title = ?, extra_field_type = ?, extra_field_label = ?, asks_fixed_assets = ?
             WHERE id = ?'
        )->execute([$title, $extraType, $extraLabel, $asksFixedAssets, $categoryId]);
        logAuditAction('update', 'accounting_activity_category', $categoryId, ['title' => $title]);
        $message = 'Categoria atualizada.';
    } else {
        $pdo->prepare(
            'INSERT INTO accounting_activity_categories (title, extra_field_type, extra_field_label, asks_fixed_assets)
             VALUES (?, ?, ?, ?)'
        )->execute([$title, $extraType, $extraLabel, $asksFixedAssets]);
        $categoryId = (int) $pdo->lastInsertId();
        logAuditAction('create', 'accounting_activity_category', $categoryId, ['title' => $title]);
        $message = 'Categoria criada.';
    }
    setSessionFlash('accounting_activities', ['type' => 'success', 'message' => $message]);
    header('Location: ' . $returnUrl);
    exit;
}

// ---------------------------------------------------------------------------
// Dados das vistas
// ---------------------------------------------------------------------------
if ($view === 'categorias' && !$isAdmin) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$feedback = pullSessionFlash('accounting_activities');
$csrfToken = generateCsrfToken();

$activity = null;
$activityLines = [];
$activityHistory = [];
$activityClientUrl = '';
if ($view === 'ver') {
    $activityUuid = strtolower(trim((string) ($_GET['uuid'] ?? '')));
    if ($tablesReady && isValidAccountingEntityUuid($activityUuid)) {
        $stmt = $pdo->prepare(
            'SELECT a.*, e.uuid AS entity_uuid, e.entity_type,
                    ru.name AS responsible_name, ru.username AS responsible_username, ru.email AS responsible_email,
                    cu.name AS created_by_name, cu.username AS created_by_username
             FROM accounting_activities a
             LEFT JOIN accounting_entities e ON e.id = a.accounting_entity_id
             LEFT JOIN users ru ON ru.id = a.responsible_user_id
             LEFT JOIN users cu ON cu.id = a.created_by
             WHERE a.uuid = ? LIMIT 1'
        );
        $stmt->execute([$activityUuid]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$activity) {
        http_response_code(404);
    } else {
        $activityId = (int) $activity['id'];
        $stmt = $pdo->prepare(
            'SELECT l.line_no, l.value, l.fixed_assets_sold, c.title, c.extra_field_type, c.extra_field_label
             FROM accounting_activity_lines l
             INNER JOIN accounting_activity_categories c ON c.id = l.category_id
             WHERE l.activity_id = ?
             ORDER BY l.line_no ASC'
        );
        $stmt->execute([$activityId]);
        $activityLines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ((int) ($activity['accounting_entity_id'] ?? 0) > 0) {
            $activityClientUrl = BASE_URL . 'contabilidade/entidades/empresas/' . rawurlencode(getAccountingEntityRouteKey([
                'id' => $activity['accounting_entity_id'],
                'uuid' => $activity['entity_uuid'] ?? '',
            ]));
        }

        if (hasTable('audit_logs')) {
            $stmt = $pdo->prepare(
                "SELECT l.action, l.meta, l.created_at, u.name, u.username
                 FROM audit_logs l
                 LEFT JOIN users u ON u.id = l.user_id
                 WHERE l.entity = 'accounting_activity' AND l.entity_id = ?
                 ORDER BY l.created_at ASC, l.id ASC"
            );
            $stmt->execute([$activityId]);
            $activityHistory = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if (!$activityHistory) {
            $activityHistory[] = [
                'action' => 'create',
                'meta' => null,
                'created_at' => $activity['created_at'],
                'name' => $activity['created_by_name'] ?? '',
                'username' => $activity['created_by_username'] ?? '',
            ];
        }
    }
}

$categoriesAll = $tablesReady ? getActivityCategories($pdo, $view !== 'categorias') : [];
$editCategory = null;
if ($view === 'categorias' && isset($_GET['edit'])) {
    foreach ($categoriesAll as $category) {
        if ((int) $category['id'] === (int) $_GET['edit']) {
            $editCategory = $category;
            break;
        }
    }
}

$listStats = ['today' => 0, 'month' => 0, 'mine_month' => 0, 'cancelled_month' => 0];
if ($view === 'lista' && $tablesReady) {
    $stmt = $pdo->prepare(
        "SELECT
            SUM(status = 1 AND created_at >= CURDATE()) AS today,
            SUM(status = 1 AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month,
            SUM(status = 1 AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND responsible_user_id = ?) AS mine_month,
            SUM(status = 0 AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS cancelled_month
         FROM accounting_activities"
    );
    $stmt->execute([$userId]);
    $listStats = array_map('intval', $stmt->fetch(PDO::FETCH_ASSOC) ?: $listStats);
}

$users = [];
if ($view === 'novo' || $view === 'lista') {
    foreach (getUsers() as $row) {
        $users[] = ['id' => (int) $row['id'], 'label' => activityUserLabel($row)];
    }
    usort($users, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
}

$useSelect2 = $view === 'novo';
$useDataTables = $view === 'lista';
require_once __DIR__ . '/../header.php';
?>
<?php $pageSubtitles = ['lista' => 'Listagem', 'novo' => 'Registar atividade', 'ver' => 'Evento', 'categorias' => 'Categorias']; ?>
<div class="page-title d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="title_left w-auto">
        <h3>Atividades <small><?= $pageSubtitles[$view]; ?></small></h3>
    </div>
    <?php if ($view === 'lista'): ?>
    <a href="<?= BASE_URL ?>contabilidade/atividades/novo" class="btn btn-primary"><i class="fa fa-plus"></i> Registar atividade</a>
    <?php else: ?>
    <a href="<?= BASE_URL ?>contabilidade/atividades" class="btn btn-link text-muted d-print-none"><i class="fa fa-arrow-left"></i> Listagem</a>
    <?php endif; ?>
</div>
<div class="clearfix"></div>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars((string) ($feedback['type'] ?? 'info')); ?> alert-dismissible" role="alert">
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
    <?= htmlspecialchars((string) ($feedback['message'] ?? '')); ?>
</div>
<?php endif; ?>

<?php if (!$tablesReady): ?>
<div class="alert alert-warning">As tabelas de atividades ainda não existem. Execute as migrações.</div>
<?php endif; ?>

<?php if ($view === 'lista'): ?>
<?php include __DIR__ . '/partials/atividades-lista.php'; ?>

<?php elseif ($view === 'novo'): ?>
<style>
    .act-step-title { display: flex; align-items: center; gap: 12px; }
    .act-step-title h2 { float: none; margin: 0; white-space: normal; overflow: visible; }
    .act-step-title small { display: block; color: #8a949e; font-size: 12px; margin-top: 2px; }
    .act-step-num {
        flex: 0 0 30px; width: 30px; height: 30px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center;
        background: #e8edf2; color: #2a3f54; font-weight: 700; font-size: 14px;
        transition: background .2s, color .2s;
    }
    .act-step.is-done .act-step-num { background: #26b99a; color: #fff; }
    .act-step .x_content { padding-top: 6px; }
    .act-field-label { font-weight: 600; color: #4b5563; margin-bottom: 5px; display: block; font-size: 13px; }
    .activity-form .select2-container { width: 100% !important; }
    .activity-form .select2-container .select2-selection--single { height: 42px; padding-top: 6px; border-color: #ced4da; }
    .activity-form .select2-container .select2-selection__arrow { height: 40px; }
    .act-client-card {
        display: flex; align-items: center; gap: 14px; margin-top: 12px; padding: 12px 14px;
        border: 1px solid #d7ece6; border-radius: 6px; background: #f3fbf8;
    }
    .act-client-avatar {
        flex: 0 0 44px; width: 44px; height: 44px; border-radius: 50%;
        background: #26b99a; color: #fff; font-weight: 700;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .act-client-name { font-weight: 600; color: #2a3f54; line-height: 1.3; }
    .act-client-meta .badge { font-weight: 500; margin-right: 4px; }
    .activity-line {
        position: relative; border: 1px solid #e3e7eb; border-radius: 6px;
        padding: 14px 44px 14px 14px; background: #fafbfc;
    }
    .activity-line + .activity-line { margin-top: 10px; }
    .activity-line-remove { position: absolute; top: 8px; right: 8px; color: #b0b7bf; border: 0; background: none; font-size: 16px; }
    .activity-line-remove:hover { color: #d9534f; }
    .activity-line-empty { color: #b0b7bf; font-size: 12px; padding-top: 10px; }
    .act-notes-toolbar .btn { color: #5a6570; }
    .act-notes-toolbar .btn:hover { background: #eef1f4; }
    .activity-notes-editor {
        min-height: 160px; max-height: 420px; overflow-y: auto;
        border: 1px solid #ced4da; border-radius: 0 0 6px 6px; padding: 12px 14px; background: #fff;
    }
    .act-notes-toolbar { border: 1px solid #ced4da; border-bottom: 0; border-radius: 6px 6px 0 0; background: #f7f8fa; padding: 4px; }
    .activity-notes-editor:empty:before { content: attr(data-placeholder); color: #aab2ba; }
    .activity-notes-editor:focus { outline: none; border-color: #86b7fe; box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .12); }
    .act-summary { position: sticky; top: 70px; }
    .act-summary dl { margin-bottom: 0; }
    .act-summary dt { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #8a949e; font-weight: 600; }
    .act-summary dd { color: #2a3f54; margin-bottom: 12px; word-break: break-word; }
    .act-summary .act-empty { color: #b0b7bf; font-style: italic; }
    .act-summary-lines { list-style: none; padding: 0; margin: 0; }
    .act-summary-lines li { padding: 3px 0; }
    .act-summary-lines li span { color: #8a949e; }
    .act-checklist { list-style: none; padding: 12px 0 0; margin: 12px 0 16px; border-top: 1px solid #eef0f2; }
    .act-checklist li { padding: 3px 0; color: #8a949e; }
    .act-checklist li i { width: 18px; color: #c9ced4; }
    .act-checklist li.ok { color: #2a3f54; }
    .act-checklist li.ok i { color: #26b99a; }
    .act-success { text-align: center; padding: 10px 0 4px; }
    .act-success > .fa { font-size: 46px; color: #26b99a; }
    .act-success h4 { margin: 10px 0 4px; color: #2a3f54; }
</style>

<?php if ($tablesReady && !$categoriesAll): ?>
<div class="alert alert-info">
    Ainda não existem categorias de evento.
    <?php if ($isAdmin): ?><a href="<?= BASE_URL ?>contabilidade/atividades/categorias">Criar categorias</a>.<?php else: ?>Contacte o administrador.<?php endif; ?>
</div>
<?php endif; ?>

<form id="activity-form" class="activity-form" autocomplete="off" novalidate>
    <input type="hidden" name="action" value="save-activity">
    <input type="hidden" name="csrf_token" id="activity-csrf" value="<?= htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="notes" id="activity-notes-input" value="">

    <div class="row">
        <div class="col-lg-8">
            <!-- 1. Cliente -->
            <div class="x_panel act-step" id="act-step-client">
                <div class="x_title">
                    <div class="act-step-title">
                        <span class="act-step-num">1</span>
                        <div><h2>Cliente</h2><small>Para quem é esta atividade e quem a acompanha</small></div>
                    </div>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="act-field-label" for="activity-client">Cliente</label>
                            <select id="activity-client" name="entity_id" class="form-control"></select>
                            <div id="activity-client-card" class="act-client-card d-none">
                                <span class="act-client-avatar" id="activity-client-avatar"></span>
                                <div>
                                    <div class="act-client-name" id="activity-client-name"></div>
                                    <div class="act-client-meta" id="activity-client-meta"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="act-field-label" for="activity-responsible">Colaborador</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa fa-user"></i></span>
                                <select id="activity-responsible" name="responsible_user_id" class="form-select">
                                    <?php foreach ($users as $option): ?>
                                    <option value="<?= $option['id']; ?>"<?= $option['id'] === $userId ? ' selected' : ''; ?>><?= htmlspecialchars($option['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2. Tipo de evento -->
            <div class="x_panel act-step" id="act-step-type">
                <div class="x_title">
                    <div class="act-step-title">
                        <span class="act-step-num">2</span>
                        <div><h2>Tipo de evento</h2><small>Uma ou mais categorias tratadas nesta atividade</small></div>
                    </div>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div id="activity-lines"></div>
                    <button type="button" id="activity-line-add" class="btn btn-sm btn-outline-primary mt-3">
                        <i class="fa fa-plus"></i> Adicionar categoria
                    </button>
                </div>
            </div>

            <!-- 3. Observacoes -->
            <div class="x_panel act-step" id="act-step-notes">
                <div class="x_title">
                    <div class="act-step-title">
                        <span class="act-step-num">3</span>
                        <div><h2>Observações</h2><small>Opcional</small></div>
                    </div>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div class="btn-toolbar act-notes-toolbar" role="toolbar">
                        <div class="btn-group me-1">
                            <button type="button" class="btn btn-sm dropdown-toggle" data-bs-toggle="dropdown" title="Formato"><i class="fa fa-header"></i></button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" data-cmd="formatBlock" data-value="p">Texto normal</a></li>
                                <li><a class="dropdown-item" href="#" data-cmd="formatBlock" data-value="h3">Título</a></li>
                                <li><a class="dropdown-item" href="#" data-cmd="formatBlock" data-value="h4">Subtítulo</a></li>
                                <li><a class="dropdown-item" href="#" data-cmd="formatBlock" data-value="blockquote">Citação</a></li>
                            </ul>
                        </div>
                        <div class="btn-group me-1">
                            <button type="button" class="btn btn-sm" data-cmd="bold" title="Negrito"><i class="fa fa-bold"></i></button>
                            <button type="button" class="btn btn-sm" data-cmd="italic" title="Itálico"><i class="fa fa-italic"></i></button>
                            <button type="button" class="btn btn-sm" data-cmd="underline" title="Sublinhado"><i class="fa fa-underline"></i></button>
                        </div>
                        <div class="btn-group me-1">
                            <button type="button" class="btn btn-sm" data-cmd="insertUnorderedList" title="Lista"><i class="fa fa-list-ul"></i></button>
                            <button type="button" class="btn btn-sm" data-cmd="insertOrderedList" title="Lista numerada"><i class="fa fa-list-ol"></i></button>
                            <button type="button" class="btn btn-sm" data-cmd="outdent" title="Diminuir avanço"><i class="fa fa-outdent"></i></button>
                            <button type="button" class="btn btn-sm" data-cmd="indent" title="Aumentar avanço"><i class="fa fa-indent"></i></button>
                        </div>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm" data-cmd="undo" title="Anular"><i class="fa fa-undo"></i></button>
                            <button type="button" class="btn btn-sm" data-cmd="redo" title="Refazer"><i class="fa fa-repeat"></i></button>
                        </div>
                    </div>
                    <div id="activity-notes-editor" class="activity-notes-editor" contenteditable="true"
                         data-placeholder="Notas sobre a atividade, documentos entregues, pendências..."></div>
                </div>
            </div>
        </div>

        <!-- Resumo -->
        <div class="col-lg-4">
            <div class="x_panel act-summary">
                <div class="x_title">
                    <h2><i class="fa fa-clipboard"></i> Resumo</h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content" id="activity-summary">
                    <dl>
                        <dt>Cliente</dt>
                        <dd id="sum-client"><span class="act-empty">Por selecionar</span></dd>
                        <dt>Colaborador</dt>
                        <dd id="sum-responsible"></dd>
                        <dt>Categorias</dt>
                        <dd id="sum-lines"><span class="act-empty">Nenhuma</span></dd>
                    </dl>
                    <ul class="act-checklist">
                        <li id="chk-client"><i class="fa fa-check-circle"></i> Cliente selecionado</li>
                        <li id="chk-category"><i class="fa fa-check-circle"></i> Pelo menos uma categoria</li>
                        <li id="chk-fields"><i class="fa fa-check-circle"></i> Campos obrigatórios preenchidos</li>
                    </ul>
                    <button type="submit" id="activity-submit" class="btn btn-success btn-lg w-100" disabled>
                        <i class="fa fa-check"></i> Registar evento
                    </button>
                    <div class="text-muted small text-center mt-2" id="activity-submit-hint"></div>
                </div>
                <div class="x_content d-none" id="activity-success">
                    <div class="act-success">
                        <i class="fa fa-check-circle"></i>
                        <h4 id="activity-success-title"></h4>
                        <p class="text-muted">O evento foi registado com sucesso.</p>
                        <a href="#" id="activity-success-view" class="btn btn-success w-100 mb-2"><i class="fa fa-eye"></i> Visualizar</a>
                        <a href="<?= BASE_URL ?>contabilidade/atividades/novo" class="btn btn-outline-secondary w-100"><i class="fa fa-plus"></i> Registar outro</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script src="<?= BASE_URL; ?>vendors/sweetalert2/dist/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var endpoint = <?= json_encode(BASE_URL . 'contabilidade/atividades', JSON_UNESCAPED_SLASHES); ?>;
    var tablesReady = <?= $tablesReady && $categoriesAll ? 'true' : 'false'; ?>;
    var categories = <?= json_encode(array_map(static fn($c) => [
        'id' => (int) $c['id'],
        'title' => (string) $c['title'],
        'type' => (string) $c['extra_field_type'],
        'label' => (string) $c['extra_field_label'],
        'fixed_assets' => (int) $c['asks_fixed_assets'] === 1,
    ], $categoriesAll), JSON_UNESCAPED_UNICODE); ?>;
    var categoriesById = {};
    categories.forEach(function (c) { categoriesById[c.id] = c; });
    var monthNames = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    var form = document.getElementById('activity-form');
    var $client = $('#activity-client');
    var responsibleSelect = document.getElementById('activity-responsible');
    var linesBox = document.getElementById('activity-lines');
    var editor = document.getElementById('activity-notes-editor');
    var submitBtn = document.getElementById('activity-submit');
    var submitHint = document.getElementById('activity-submit-hint');
    var csrfInput = document.getElementById('activity-csrf');
    var selectedClient = null;
    var lineSeq = 0;

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function notify(type, message) {
        if (window.Swal) {
            Swal.fire({ icon: type, text: message });
        } else {
            alert(message);
        }
    }

    function initials(name) {
        var words = String(name || '').replace(/[^A-Za-zÀ-ÿ0-9 ]/g, ' ').split(/\s+/).filter(Boolean);
        return ((words[0] || '?').charAt(0) + (words[1] ? words[1].charAt(0) : '')).toUpperCase();
    }

    // ---- 1. Cliente ----------------------------------------------------
    $client.select2({
        placeholder: 'Pesquisar por nome, código ou NIF',
        allowClear: true,
        minimumInputLength: 2,
        language: {
            inputTooShort: function () { return 'Introduza pelo menos 2 caracteres'; },
            noResults: function () { return 'Nenhum cliente encontrado'; },
            searching: function () { return 'A pesquisar...'; }
        },
        templateResult: function (item) {
            if (!item.id) {
                return item.text;
            }
            var meta = [item.code ? 'Cód. ' + item.code : '', item.nif ? 'NIF ' + item.nif : ''].filter(Boolean).join(' · ');
            return $('<div><div>' + escapeHtml(item.name || item.text) + '</div>'
                + (meta ? '<small class="text-muted">' + escapeHtml(meta) + '</small>' : '') + '</div>');
        },
        templateSelection: function (item) { return item.name || item.text; },
        ajax: {
            url: endpoint,
            dataType: 'json',
            delay: 250,
            data: function (params) { return { action: 'search-clients', q: params.term || '' }; },
            processResults: function (data) { return { results: (data && data.results) || [] }; }
        }
    });

    function applyClient(item) {
        selectedClient = item || null;
        var card = document.getElementById('activity-client-card');
        card.classList.toggle('d-none', !item);
        if (item) {
            document.getElementById('activity-client-avatar').textContent = initials(item.name);
            document.getElementById('activity-client-name').textContent = item.name;
            document.getElementById('activity-client-meta').innerHTML =
                (item.code ? '<span class="badge bg-secondary">Cód. ' + escapeHtml(item.code) + '</span>' : '')
                + (item.nif ? '<span class="badge bg-light text-dark border">NIF ' + escapeHtml(item.nif) + '</span>' : '');
        }
        refresh();
    }
    $client.on('select2:select', function (e) { applyClient(e.params.data); });
    $client.on('select2:clear', function () { applyClient(null); });
    responsibleSelect.addEventListener('change', refresh);

    // ---- 2. Tipo de evento ---------------------------------------------
    function currentMonth() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    }

    function formatValue(type, value) {
        var m;
        if (type === 'month' && (m = /^(\d{4})-(\d{2})$/.exec(value))) {
            return monthNames[parseInt(m[2], 10) - 1] + ' ' + m[1];
        }
        if (type === 'date' && (m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value))) {
            return m[3] + '-' + m[2] + '-' + m[1];
        }
        return value;
    }

    function renderLineFields(row) {
        var category = categoriesById[row.querySelector('.activity-line-category').value];
        var idx = row.dataset.idx;
        var extraBox = row.querySelector('.activity-line-extra');
        var fixedBox = row.querySelector('.activity-line-fixed');
        extraBox.innerHTML = '';
        fixedBox.innerHTML = '';

        if (!category) {
            extraBox.innerHTML = '<div class="activity-line-empty">Escolha uma categoria</div>';
            return;
        }
        if (category.type !== 'none') {
            var inputType = { month: 'month', date: 'date', number: 'number' }[category.type] || 'text';
            extraBox.innerHTML = '<label class="act-field-label">' + escapeHtml(category.label || 'Valor') + '</label>'
                + '<input type="' + inputType + '" class="form-control activity-line-value" name="lines[' + idx + '][value]"'
                + (inputType === 'number' ? ' step="any"' : '')
                + ' value="' + (category.type === 'month' ? currentMonth() : '') + '">';
        }
        if (category.fixed_assets) {
            var name = 'lines[' + idx + '][fixed_assets_sold]';
            fixedBox.innerHTML = '<label class="act-field-label">Venda de imobilizado?</label>'
                + '<div class="btn-group w-100" role="group">'
                + '<input type="radio" class="btn-check" name="' + name + '" id="fa-' + idx + '-0" value="0">'
                + '<label class="btn btn-outline-secondary" for="fa-' + idx + '-0">Não</label>'
                + '<input type="radio" class="btn-check" name="' + name + '" id="fa-' + idx + '-1" value="1">'
                + '<label class="btn btn-outline-secondary" for="fa-' + idx + '-1">Sim</label>'
                + '</div>';
        }
    }

    function addLine() {
        var idx = lineSeq++;
        var row = document.createElement('div');
        row.className = 'activity-line';
        row.dataset.idx = idx;
        row.innerHTML =
            '<button type="button" class="activity-line-remove" title="Remover"><i class="fa fa-times"></i></button>'
            + '<div class="row g-3">'
            + '<div class="col-md-5"><label class="act-field-label">Categoria</label>'
            + '<select class="form-select activity-line-category" name="lines[' + idx + '][category_id]"><option value="">Selecione...</option>'
            + categories.map(function (c) { return '<option value="' + c.id + '">' + escapeHtml(c.title) + '</option>'; }).join('')
            + '</select></div>'
            + '<div class="col-md-4 activity-line-extra"></div>'
            + '<div class="col-md-3 activity-line-fixed"></div>'
            + '</div>';
        linesBox.appendChild(row);
        renderLineFields(row);
        refresh();
        return row;
    }

    linesBox.addEventListener('change', function (e) {
        if (e.target.classList.contains('activity-line-category')) {
            renderLineFields(e.target.closest('.activity-line'));
        }
        refresh();
    });
    linesBox.addEventListener('input', refresh);
    linesBox.addEventListener('click', function (e) {
        var removeBtn = e.target.closest('.activity-line-remove');
        if (!removeBtn) {
            return;
        }
        removeBtn.closest('.activity-line').remove();
        if (!linesBox.querySelector('.activity-line')) {
            addLine();
        }
        refresh();
    });
    document.getElementById('activity-line-add').addEventListener('click', function () {
        addLine().querySelector('.activity-line-category').focus();
    });

    // ---- 3. Observacoes ------------------------------------------------
    document.querySelectorAll('.act-notes-toolbar [data-cmd]').forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            editor.focus();
            var cmd = btn.dataset.cmd;
            var value = btn.dataset.value || null;
            document.execCommand(cmd, false, cmd === 'formatBlock' ? '<' + value + '>' : value);
        });
    });
    editor.addEventListener('input', function () {
        // Mantem o placeholder visivel quando o editor fica vazio.
        if (editor.textContent.trim() === '' && !editor.querySelector('li,img')) {
            editor.innerHTML = '';
        }
    });

    // ---- Estado / resumo -----------------------------------------------
    function collectLines() {
        var result = [];
        linesBox.querySelectorAll('.activity-line').forEach(function (row) {
            var category = categoriesById[row.querySelector('.activity-line-category').value];
            if (!category) {
                return;
            }
            var input = row.querySelector('.activity-line-value');
            var fixed = row.querySelector('.activity-line-fixed input:checked');
            result.push({
                row: row,
                category: category,
                input: input,
                value: input ? input.value.trim() : '',
                fixedInput: row.querySelector('.activity-line-fixed input'),
                fixed: fixed ? fixed.value : null
            });
        });
        return result;
    }

    function firstProblem(lines) {
        if (!selectedClient) {
            return { message: 'Selecione o cliente.', focus: function () { $client.select2('open'); } };
        }
        if (!lines.length) {
            return { message: 'Escolha pelo menos uma categoria.', focus: function () { var s = linesBox.querySelector('.activity-line-category'); if (s) { s.focus(); } } };
        }
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i];
            if (line.category.type !== 'none' && line.value === '') {
                return { message: 'Preencha ' + (line.category.label || 'o valor') + ' em ' + line.category.title + '.', focus: function (el) { return function () { el.focus(); }; }(line.input) };
            }
            if (line.category.fixed_assets && line.fixed === null) {
                return { message: 'Indique se houve venda de imobilizado em ' + line.category.title + '.', focus: function (el) { return function () { el.focus(); }; }(line.fixedInput) };
            }
        }
        return null;
    }

    function setDone(id, done) {
        document.getElementById(id).classList.toggle('ok', done);
    }

    function refresh() {
        var lines = collectLines();
        var clientOk = !!selectedClient;
        var fieldsOk = lines.length > 0 && lines.every(function (l) {
            return (l.category.type === 'none' || l.value !== '') && (!l.category.fixed_assets || l.fixed !== null);
        });

        document.getElementById('sum-client').innerHTML = clientOk
            ? escapeHtml(selectedClient.name) + (selectedClient.code ? ' <span class="text-muted">(' + escapeHtml(selectedClient.code) + ')</span>' : '')
            : '<span class="act-empty">Por selecionar</span>';
        var responsibleOption = responsibleSelect.options[responsibleSelect.selectedIndex];
        document.getElementById('sum-responsible').textContent = responsibleOption ? responsibleOption.text : '';
        document.getElementById('sum-lines').innerHTML = lines.length
            ? '<ul class="act-summary-lines">' + lines.map(function (l) {
                var extra = [];
                if (l.value !== '') {
                    extra.push(formatValue(l.category.type, l.value));
                }
                if (l.fixed !== null) {
                    extra.push(l.fixed === '1' ? 'com venda de imobilizado' : 'sem venda de imobilizado');
                }
                return '<li><i class="fa fa-tag text-muted"></i> ' + escapeHtml(l.category.title)
                    + (extra.length ? ' <span>— ' + escapeHtml(extra.join(', ')) + '</span>' : '') + '</li>';
            }).join('') + '</ul>'
            : '<span class="act-empty">Nenhuma</span>';

        setDone('chk-client', clientOk);
        setDone('chk-category', lines.length > 0);
        setDone('chk-fields', fieldsOk);
        document.getElementById('act-step-client').classList.toggle('is-done', clientOk);
        document.getElementById('act-step-type').classList.toggle('is-done', fieldsOk);
        document.getElementById('act-step-notes').classList.toggle('is-done', editor.textContent.trim() !== '');

        var problem = firstProblem(lines);
        submitBtn.disabled = !tablesReady || !!problem;
        submitHint.textContent = problem ? problem.message : '';
    }
    editor.addEventListener('input', refresh);

    // ---- Submeter ------------------------------------------------------
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var problem = firstProblem(collectLines());
        if (problem) {
            notify('warning', problem.message);
            problem.focus();
            return;
        }
        document.getElementById('activity-notes-input').value = editor.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> A gravar...';
        fetch(endpoint, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.csrf_token) {
                    csrfInput.value = data.csrf_token;
                }
                if (data && data.ok) {
                    document.getElementById('activity-summary').classList.add('d-none');
                    document.getElementById('activity-success').classList.remove('d-none');
                    document.getElementById('activity-success-title').textContent = 'Evento ' + data.code;
                    document.getElementById('activity-success-view').href = data.view_url;
                    form.querySelectorAll('input, select, button').forEach(function (el) {
                        if (!el.closest('#activity-success')) {
                            el.disabled = true;
                        }
                    });
                    $client.prop('disabled', true);
                    editor.contentEditable = 'false';
                    return;
                }
                throw new Error((data && data.message) || 'Não foi possível gravar o evento.');
            })
            .catch(function (err) {
                submitBtn.innerHTML = '<i class="fa fa-check"></i> Registar evento';
                refresh();
                notify('error', err && err.message ? err.message : 'Erro de comunicação ao gravar o evento.');
            });
    });

    addLine();
    refresh();
});
</script>

<?php elseif ($view === 'ver'): ?>
<?php if (!$activity): ?>
<div class="x_panel">
    <div class="x_content text-center py-5">
        <i class="fa fa-search fa-3x text-muted"></i>
        <h4 class="mt-3">Evento não encontrado</h4>
        <p class="text-muted">O endereço pode estar incorreto ou o evento pode ter sido removido.</p>
        <a href="<?= BASE_URL ?>contabilidade/atividades/novo" class="btn btn-primary"><i class="fa fa-plus"></i> Registar atividade</a>
    </div>
</div>
<?php else:
    $isCancelled = (int) $activity['status'] === 0;
    $responsibleName = activityUserLabel(['name' => $activity['responsible_name'] ?? '', 'username' => $activity['responsible_username'] ?? '']);
    $createdByName = activityUserLabel(['name' => $activity['created_by_name'] ?? '', 'username' => $activity['created_by_username'] ?? '']);
    $cancelEntry = null;
    foreach ($activityHistory as $entry) {
        if ($entry['action'] === 'cancel') {
            $cancelEntry = $entry;
        } elseif ($entry['action'] === 'reactivate') {
            $cancelEntry = null;
        }
    }
    $historyLabels = [
        'create' => ['Evento registado', 'fa-plus', 'act-tl-green'],
        'cancel' => ['Evento anulado', 'fa-ban', 'act-tl-red'],
        'reactivate' => ['Evento reativado', 'fa-undo', 'act-tl-blue'],
    ];
    $notesHtml = (string) ($activity['notes'] ?? '') !== '' ? sanitizeActivityNotes((string) $activity['notes']) : '';
?>
<style>
    .act-hero { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; }
    .act-hero-overline { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #8a949e; font-weight: 600; }
    .act-hero-code { font-size: 30px; font-weight: 700; color: #2a3f54; line-height: 1.1; display: flex; align-items: center; gap: 12px; }
    .act-hero-meta { color: #73879c; margin-top: 6px; }
    .act-hero-meta i { margin-right: 4px; }
    .act-hero-meta span + span:before { content: '·'; margin: 0 8px; color: #c3cad2; }
    .act-pill { font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 20px; }
    .act-pill-active { background: #e3f6f0; color: #1a8a72; }
    .act-pill-cancelled { background: #fdeaea; color: #c9302c; }
    .act-cancelled-banner { border-left: 4px solid #d9534f; background: #fdf3f3; padding: 12px 16px; border-radius: 4px; margin-bottom: 14px; color: #8a2a27; }
    .act-avatar {
        flex: 0 0 auto; width: 48px; height: 48px; border-radius: 50%;
        background: #26b99a; color: #fff; font-weight: 700; font-size: 16px;
        display: inline-flex; align-items: center; justify-content: center;
    }
    .act-avatar-sm { width: 34px; height: 34px; font-size: 13px; background: #3b82c4; }
    .act-client { display: flex; align-items: center; gap: 16px; }
    .act-client-body { flex: 1 1 auto; min-width: 0; }
    .act-client-name { font-size: 17px; font-weight: 600; color: #2a3f54; line-height: 1.3; }
    .act-client .badge { font-weight: 500; margin-right: 4px; }
    .act-lines { list-style: none; margin: 0; padding: 0; }
    .act-lines li { display: flex; align-items: center; gap: 14px; padding: 14px 0; border-bottom: 1px solid #eef0f2; }
    .act-lines li:last-child { border-bottom: 0; }
    .act-line-icon {
        flex: 0 0 36px; width: 36px; height: 36px; border-radius: 8px;
        background: #eef3f8; color: #3b82c4; display: inline-flex; align-items: center; justify-content: center;
    }
    .act-line-title { font-weight: 600; color: #2a3f54; }
    .act-line-sub { font-size: 12px; color: #8a949e; }
    .act-line-value { margin-left: auto; text-align: right; }
    .act-line-value strong { display: block; color: #2a3f54; font-size: 15px; }
    .act-notes { color: #444; line-height: 1.6; }
    .act-notes > :last-child { margin-bottom: 0; }
    .act-empty-note { color: #aab2ba; font-style: italic; }
    .act-details dt { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #8a949e; font-weight: 600; }
    .act-details dd { color: #2a3f54; margin-bottom: 14px; }
    .act-details dd:last-child { margin-bottom: 0; }
    .act-person { display: flex; align-items: center; gap: 10px; }
    .act-uuid { font-family: SFMono-Regular, Menlo, Consolas, monospace; font-size: 11px; color: #73879c; word-break: break-all; }
    .act-timeline { list-style: none; margin: 0; padding: 0; position: relative; }
    .act-timeline:before { content: ''; position: absolute; left: 13px; top: 6px; bottom: 6px; width: 2px; background: #eef0f2; }
    .act-timeline li { position: relative; padding: 0 0 16px 40px; }
    .act-timeline li:last-child { padding-bottom: 0; }
    .act-tl-dot {
        position: absolute; left: 0; top: 0; width: 28px; height: 28px; border-radius: 50%;
        display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 12px;
    }
    .act-tl-green { background: #26b99a; }
    .act-tl-red { background: #d9534f; }
    .act-tl-blue { background: #3b82c4; }
    .act-tl-title { font-weight: 600; color: #2a3f54; }
    .act-tl-meta { font-size: 12px; color: #8a949e; }
    .act-tl-reason { font-size: 13px; background: #f7f8fa; border-radius: 4px; padding: 6px 10px; margin-top: 4px; color: #555; }
    .act-signatures { display: none; }
    @media (max-width: 575px) { .act-client { flex-wrap: wrap; } }
    @media print {
        .left_col, .top_nav, footer, .page-title, .d-print-none, #internal-chat-floating, #ai-float-btn, .alert { display: none !important; }
        .right_col { margin-left: 0 !important; padding: 0 !important; }
        body, .container.body, .main_container, .right_col { background: #fff !important; }
        .x_panel { border: 1px solid #ddd !important; box-shadow: none !important; break-inside: avoid; }
        .act-print-col { width: 100% !important; flex: 0 0 100% !important; max-width: 100% !important; }
        .act-signatures { display: flex; gap: 40px; margin-top: 50px; }
        .act-signatures div { flex: 1; border-top: 1px solid #999; padding-top: 6px; font-size: 12px; color: #555; }
    }
</style>

<?php if ($isCancelled): ?>
<div class="act-cancelled-banner">
    <i class="fa fa-ban"></i> <strong>Este evento foi anulado</strong>
    <?php if ($cancelEntry): ?>
        em <?= htmlspecialchars(formatActivityDateTime((string) $cancelEntry['created_at'])); ?>
        <?php $cancelBy = activityUserLabel($cancelEntry); if ($cancelBy !== ''): ?>por <?= htmlspecialchars($cancelBy); ?><?php endif; ?>.
        <?php $cancelMeta = json_decode((string) ($cancelEntry['meta'] ?? ''), true); if (!empty($cancelMeta['reason'])): ?>
        <div class="mt-1">Motivo: <?= htmlspecialchars((string) $cancelMeta['reason']); ?></div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="x_panel">
    <div class="x_content">
        <div class="act-hero">
            <div>
                <div class="act-hero-overline">Evento</div>
                <div class="act-hero-code">
                    <?= htmlspecialchars((string) $activity['code']); ?>
                    <?php if ($isCancelled): ?>
                    <span class="act-pill act-pill-cancelled"><i class="fa fa-ban"></i> Anulado</span>
                    <?php else: ?>
                    <span class="act-pill act-pill-active"><i class="fa fa-check"></i> Ativo</span>
                    <?php endif; ?>
                </div>
                <div class="act-hero-meta">
                    <span><i class="fa fa-calendar"></i><?= htmlspecialchars(formatActivityDateTime((string) $activity['created_at'])); ?></span>
                    <?php if ($createdByName !== ''): ?><span><i class="fa fa-user"></i>Registado por <?= htmlspecialchars($createdByName); ?></span><?php endif; ?>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 d-print-none">
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i> Imprimir</button>
                <a class="btn btn-primary" href="<?= BASE_URL ?>contabilidade/atividades/novo"><i class="fa fa-plus"></i> Novo evento</a>
                <?php if ($isAdmin): ?>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Mais ações">
                        <i class="fa fa-ellipsis-h"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <?php if ($isCancelled): ?>
                        <li><a class="dropdown-item" href="#" id="act-reactivate"><i class="fa fa-undo text-primary"></i> Reativar evento</a></li>
                        <?php else: ?>
                        <li><a class="dropdown-item text-danger" href="#" id="act-cancel"><i class="fa fa-ban"></i> Anular evento</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8 act-print-col">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-building-o"></i> Cliente</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="act-client">
                    <span class="act-avatar"><?= htmlspecialchars(activityInitials((string) $activity['client_name'])); ?></span>
                    <div class="act-client-body">
                        <div class="act-client-name"><?= htmlspecialchars((string) $activity['client_name']); ?></div>
                        <div class="mt-1">
                            <?php if ((string) $activity['client_code'] !== ''): ?><span class="badge bg-secondary">Cód. <?= htmlspecialchars((string) $activity['client_code']); ?></span><?php endif; ?>
                            <?php if ((string) $activity['client_nif'] !== ''): ?><span class="badge bg-light text-dark border">NIF <?= htmlspecialchars((string) $activity['client_nif']); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($activityClientUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($activityClientUrl); ?>" class="btn btn-sm btn-outline-secondary text-nowrap flex-shrink-0 d-print-none"><i class="fa fa-external-link"></i> Ver ficha</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-tags"></i> Tipo de evento</h2>
                <span class="float-end badge bg-light text-dark border mt-2"><?= count($activityLines); ?> <?= count($activityLines) === 1 ? 'categoria' : 'categorias'; ?></span>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <ul class="act-lines">
                    <?php foreach ($activityLines as $line):
                        $lineValue = formatActivityExtraValue((string) $line['extra_field_type'], (string) $line['value']);
                    ?>
                    <li>
                        <span class="act-line-icon"><i class="fa fa-tag"></i></span>
                        <div>
                            <div class="act-line-title"><?= htmlspecialchars((string) $line['title']); ?></div>
                            <?php if ((string) $line['extra_field_label'] !== '' && $lineValue !== ''): ?>
                            <div class="act-line-sub"><?= htmlspecialchars((string) $line['extra_field_label']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="act-line-value">
                            <?php if ($lineValue !== ''): ?><strong><?= htmlspecialchars($lineValue); ?></strong><?php endif; ?>
                            <?php if ($line['fixed_assets_sold'] !== null): ?>
                                <?php if ((int) $line['fixed_assets_sold'] === 1): ?>
                                <span class="badge bg-warning text-dark">Com venda de imobilizado</span>
                                <?php else: ?>
                                <span class="badge bg-light text-muted border">Sem venda de imobilizado</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-align-left"></i> Observações</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?php if ($notesHtml !== ''): ?>
                <div class="act-notes"><?= $notesHtml; ?></div>
                <?php else: ?>
                <div class="act-empty-note">Sem observações.</div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <div class="col-lg-4 act-print-col">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-info-circle"></i> Detalhes</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <dl class="act-details mb-0">
                    <dt>Colaborador</dt>
                    <dd>
                        <?php if ($responsibleName !== ''): ?>
                        <div class="act-person">
                            <span class="act-avatar act-avatar-sm"><?= htmlspecialchars(activityInitials($responsibleName)); ?></span>
                            <div>
                                <?= htmlspecialchars($responsibleName); ?>
                                <?php if ((string) ($activity['responsible_email'] ?? '') !== ''): ?>
                                <div class="small text-muted"><?= htmlspecialchars((string) $activity['responsible_email']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <span class="act-empty-note">—</span>
                        <?php endif; ?>
                    </dd>
                    <dt>Registado por</dt>
                    <dd><?= $createdByName !== '' ? htmlspecialchars($createdByName) : '<span class="act-empty-note">—</span>'; ?></dd>
                    <dt>Data de registo</dt>
                    <dd><?= htmlspecialchars(formatActivityDateTime((string) $activity['created_at'])); ?></dd>
                    <dt>Referência</dt>
                    <dd class="d-flex align-items-start gap-2">
                        <span class="act-uuid" id="act-uuid"><?= htmlspecialchars((string) $activity['uuid']); ?></span>
                        <button type="button" class="btn btn-sm btn-link p-0 d-print-none" id="act-copy-link" title="Copiar ligação"><i class="fa fa-link"></i></button>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="x_panel d-print-none">
            <div class="x_title">
                <h2><i class="fa fa-history"></i> Histórico</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <ul class="act-timeline">
                    <?php foreach (array_reverse($activityHistory) as $entry):
                        [$entryLabel, $entryIcon, $entryClass] = $historyLabels[$entry['action']] ?? [ucfirst((string) $entry['action']), 'fa-circle', 'act-tl-blue'];
                        $entryMeta = json_decode((string) ($entry['meta'] ?? ''), true);
                        $entryBy = activityUserLabel($entry);
                    ?>
                    <li>
                        <span class="act-tl-dot <?= $entryClass; ?>"><i class="fa <?= $entryIcon; ?>"></i></span>
                        <div class="act-tl-title"><?= htmlspecialchars($entryLabel); ?></div>
                        <div class="act-tl-meta">
                            <?= htmlspecialchars(formatActivityDateTime((string) $entry['created_at'])); ?>
                            <?php if ($entryBy !== ''): ?> · <?= htmlspecialchars($entryBy); ?><?php endif; ?>
                        </div>
                        <?php if (!empty($entryMeta['reason'])): ?>
                        <div class="act-tl-reason"><?= htmlspecialchars((string) $entryMeta['reason']); ?></div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="act-signatures">
    <div>Responsável <?= htmlspecialchars((string) $appName); ?></div>
    <div>O Cliente</div>
</div>

<?php if ($isAdmin): ?>
<form method="post" action="<?= BASE_URL ?>contabilidade/atividades" id="act-status-form" class="d-none">
    <input type="hidden" name="action" value="<?= $isCancelled ? 'reactivate-activity' : 'cancel-activity'; ?>">
    <input type="hidden" name="activity_uuid" value="<?= htmlspecialchars((string) $activity['uuid']); ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="reason" id="act-status-reason" value="">
</form>
<?php endif; ?>

<script src="<?= BASE_URL; ?>vendors/sweetalert2/dist/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var statusForm = document.getElementById('act-status-form');
    var cancelLink = document.getElementById('act-cancel');
    var reactivateLink = document.getElementById('act-reactivate');

    if (cancelLink && statusForm) {
        cancelLink.addEventListener('click', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Anular este evento?',
                text: 'O evento fica marcado como anulado e pode ser reativado mais tarde.',
                input: 'textarea',
                inputPlaceholder: 'Motivo (opcional)',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Anular evento',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#d9534f'
            }).then(function (result) {
                if (result.isConfirmed) {
                    document.getElementById('act-status-reason').value = result.value || '';
                    statusForm.submit();
                }
            });
        });
    }
    if (reactivateLink && statusForm) {
        reactivateLink.addEventListener('click', function (e) {
            e.preventDefault();
            Swal.fire({
                title: 'Reativar este evento?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Reativar',
                cancelButtonText: 'Cancelar'
            }).then(function (result) {
                if (result.isConfirmed) {
                    statusForm.submit();
                }
            });
        });
    }

    var copyBtn = document.getElementById('act-copy-link');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var url = window.location.href.split('#')[0].split('?')[0];
            var done = function () {
                copyBtn.innerHTML = '<i class="fa fa-check text-success"></i>';
                setTimeout(function () { copyBtn.innerHTML = '<i class="fa fa-link"></i>'; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(done);
            } else {
                var tmp = document.createElement('textarea');
                tmp.value = url;
                document.body.appendChild(tmp);
                tmp.select();
                document.execCommand('copy');
                tmp.remove();
                done();
            }
        });
    }
});
</script>
<?php endif; ?>

<?php else: /* categorias */ ?>
<div class="row">
    <div class="col-md-4">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-<?= $editCategory ? 'pencil' : 'plus'; ?>"></i> <?= $editCategory ? 'Editar categoria' : 'Nova categoria'; ?></h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <form method="post" action="<?= BASE_URL ?>contabilidade/atividades">
                    <input type="hidden" name="action" value="save-category">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="category_id" value="<?= (int) ($editCategory['id'] ?? 0); ?>">
                    <div class="mb-3">
                        <label class="form-label" for="category-title">Título</label>
                        <input type="text" class="form-control" id="category-title" name="title" maxlength="150" required
                               value="<?= htmlspecialchars((string) ($editCategory['title'] ?? '')); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="category-extra-type">Campo adicional</label>
                        <select class="form-select" id="category-extra-type" name="extra_field_type">
                            <?php foreach (ACTIVITY_EXTRA_FIELD_TYPES as $typeKey => $typeLabel): ?>
                            <option value="<?= $typeKey; ?>"<?= ($editCategory['extra_field_type'] ?? 'none') === $typeKey ? ' selected' : ''; ?>><?= htmlspecialchars($typeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="category-extra-label">Legenda do campo</label>
                        <input type="text" class="form-control" id="category-extra-label" name="extra_field_label" maxlength="100"
                               placeholder="ex: Mês" value="<?= htmlspecialchars((string) ($editCategory['extra_field_label'] ?? '')); ?>">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="category-fixed-assets" name="asks_fixed_assets" value="1"
                               <?= !empty($editCategory['asks_fixed_assets']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="category-fixed-assets">Perguntar se houve venda/alienação de imobilizado</label>
                    </div>
                    <button type="submit" class="btn btn-primary"<?= $tablesReady ? '' : ' disabled'; ?>>Gravar</button>
                    <?php if ($editCategory): ?>
                    <a href="<?= BASE_URL ?>contabilidade/atividades/categorias" class="btn btn-outline-secondary">Cancelar</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-tags"></i> Categorias de evento</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr><th>Título</th><th>Campo adicional</th><th>Imobilizado</th><th>Estado</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php if (!$categoriesAll): ?>
                        <tr><td colspan="5" class="text-muted">Sem categorias.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($categoriesAll as $category): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $category['title']); ?></td>
                            <td>
                                <?= htmlspecialchars(ACTIVITY_EXTRA_FIELD_TYPES[$category['extra_field_type']] ?? $category['extra_field_type']); ?>
                                <?php if ((string) $category['extra_field_label'] !== ''): ?><small class="text-muted">(<?= htmlspecialchars((string) $category['extra_field_label']); ?>)</small><?php endif; ?>
                            </td>
                            <td><?= (int) $category['asks_fixed_assets'] === 1 ? '<span class="badge bg-info">Sim</span>' : ''; ?></td>
                            <td><?= (int) $category['is_active'] === 1 ? '<span class="badge bg-success">Ativa</span>' : '<span class="badge bg-secondary">Inativa</span>'; ?></td>
                            <td class="text-end text-nowrap">
                                <a href="<?= BASE_URL ?>contabilidade/atividades/categorias?edit=<?= (int) $category['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="fa fa-pencil"></i></a>
                                <form method="post" action="<?= BASE_URL ?>contabilidade/atividades" class="d-inline">
                                    <input type="hidden" name="action" value="toggle-category">
                                    <input type="hidden" name="category_id" value="<?= (int) $category['id']; ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-<?= (int) $category['is_active'] === 1 ? 'warning' : 'success'; ?>"
                                            title="<?= (int) $category['is_active'] === 1 ? 'Desativar' : 'Ativar'; ?>">
                                        <i class="fa fa-<?= (int) $category['is_active'] === 1 ? 'ban' : 'check'; ?>"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../footer.php'; ?>
