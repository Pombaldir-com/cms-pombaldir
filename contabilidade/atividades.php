<?php
// Registo de atividade (eventos) por cliente. Porta o "Criar novo evento" da
// intranet legacy (intranet/workflow.php?act=novo + data/workflow.php
// accaop=adiciona). Vistas:
//   contabilidade/atividades/novo        formulario (Cliente / Tipo de evento / Finalizar)
//   contabilidade/atividades/{id}        visualizar evento (imprimir, anular/reativar)
//   contabilidade/atividades/categorias  gestao das categorias (administradores)

require_once __DIR__ . '/../functions.php';

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
$tablesReady = hasTable('accounting_activities')
    && hasTable('accounting_activity_lines')
    && hasTable('accounting_activity_categories');

$view = (string) ($_GET['view'] ?? 'novo');
if (!in_array($view, ['novo', 'ver', 'categorias'], true)) {
    $view = 'novo';
}
$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');

const ACTIVITY_EXTRA_FIELD_TYPES = [
    'none' => 'Sem campo adicional',
    'month' => 'Mês',
    'date' => 'Data',
    'text' => 'Texto',
    'number' => 'Número',
];

function activityJson(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function activityUserLabel(?array $row): string {
    if (!$row) {
        return '';
    }
    $name = trim((string) ($row['name'] ?? ''));
    return $name !== '' ? $name : (string) ($row['username'] ?? '');
}

function getActivityCategories(PDO $pdo, bool $onlyActive): array {
    $sql = 'SELECT id, title, extra_field_type, extra_field_label, asks_fixed_assets, is_active
            FROM accounting_activity_categories';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY title ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Observacoes vem de um editor contenteditable: manter apenas formatacao
 * basica e remover todos os atributos (evita XSS na vista do evento).
 */
function sanitizeActivityNotes(string $html): string {
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
    $html = strip_tags($html, '<p><br><div><b><strong><i><em><u><ul><ol><li><h3><h4><blockquote>');
    $html = preg_replace('#<(/?)(\w+)\b[^>]*>#', '<$1$2>', $html) ?? '';
    $plain = trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $plain === '' ? '' : trim($html);
}

/**
 * Valida o valor do campo adicional de uma linha consoante o tipo da categoria.
 * Devolve [valor normalizado, erro].
 */
function normalizeActivityExtraValue(string $type, string $raw, string $label): array {
    $raw = trim($raw);
    $label = $label !== '' ? $label : 'campo adicional';
    if ($type === 'none') {
        return ['', null];
    }
    if ($raw === '') {
        return ['', 'Preencha o ' . $label . '.'];
    }
    switch ($type) {
        case 'month':
            if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $raw)) {
                return ['', 'Mês inválido em ' . $label . '.'];
            }
            return [$raw, null];
        case 'date':
            $date = DateTime::createFromFormat('!Y-m-d', $raw);
            if (!$date || $date->format('Y-m-d') !== $raw) {
                return ['', 'Data inválida em ' . $label . '.'];
            }
            return [$raw, null];
        case 'number':
            $normalized = str_replace([' ', ','], ['', '.'], $raw);
            if (!is_numeric($normalized)) {
                return ['', 'Valor numérico inválido em ' . $label . '.'];
            }
            return [$normalized, null];
        default:
            return [mb_substr($raw, 0, 255), null];
    }
}

function formatActivityExtraValue(string $type, string $value): string {
    if ($value === '') {
        return '';
    }
    if ($type === 'month' && preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
        $months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        return ($months[(int) $m[2] - 1] ?? $m[2]) . ' ' . $m[1];
    }
    if ($type === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return $value;
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
                (accounting_entity_id, client_name, client_code, client_nif, responsible_user_id, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)'
        );
        $stmt->execute([
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
        'accounting_entity_id' => (int) $entity['id'],
        'responsible_user_id' => $responsibleId,
        'lines' => count($lines),
    ]);

    activityJson([
        'ok' => true,
        'message' => 'O evento ' . $code . ' foi criado com sucesso.',
        'code' => $code,
        'view_url' => BASE_URL . 'contabilidade/atividades/' . $activityId,
        'csrf_token' => generateCsrfToken(true),
    ]);
}

// ---------------------------------------------------------------------------
// POST: anular / reativar evento (administradores)
// ---------------------------------------------------------------------------
if (in_array($action, ['cancel-activity', 'reactivate-activity'], true) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $activityId = (int) ($_POST['activity_id'] ?? 0);
    $returnUrl = BASE_URL . 'contabilidade/atividades/' . $activityId;
    if (!$isAdmin) {
        http_response_code(403);
        echo 'Acesso negado.';
        exit;
    }
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        setSessionFlash('accounting_activities', ['type' => 'danger', 'message' => 'Sessão expirada. Tente novamente.']);
    } elseif ($tablesReady) {
        $status = $action === 'cancel-activity' ? 0 : 1;
        $pdo->prepare('UPDATE accounting_activities SET status = ? WHERE id = ?')->execute([$status, $activityId]);
        logAuditAction($status ? 'reactivate' : 'cancel', 'accounting_activity', $activityId);
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
if ($view === 'ver') {
    $activityId = (int) ($_GET['id'] ?? 0);
    if ($tablesReady) {
        $stmt = $pdo->prepare('SELECT * FROM accounting_activities WHERE id = ? LIMIT 1');
        $stmt->execute([$activityId]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$activity) {
        http_response_code(404);
    } else {
        $stmt = $pdo->prepare(
            'SELECT l.line_no, l.value, l.fixed_assets_sold, c.title, c.extra_field_type, c.extra_field_label
             FROM accounting_activity_lines l
             INNER JOIN accounting_activity_categories c ON c.id = l.category_id
             WHERE l.activity_id = ?
             ORDER BY l.line_no ASC'
        );
        $stmt->execute([$activityId]);
        $activityLines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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

$users = [];
if ($view === 'novo') {
    foreach (getUsers() as $row) {
        $users[] = ['id' => (int) $row['id'], 'label' => activityUserLabel($row)];
    }
    usort($users, static fn($a, $b) => strcasecmp($a['label'], $b['label']));
}

$useSelect2 = $view === 'novo';
require_once __DIR__ . '/../header.php';
?>
<div class="page-title">
    <div class="title_left">
        <h3>Atividades <small><?= $view === 'categorias' ? 'Categorias' : ($view === 'ver' ? 'Evento' : 'Registar atividade'); ?></small></h3>
    </div>
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

<?php if ($view === 'novo'): ?>
<style>
    .activity-form .accordion-button { font-weight: 600; }
    .activity-form .accordion-button:not(.collapsed) { color: #2a3f54; background: #f5f7fa; box-shadow: none; }
    .activity-form .accordion-body { padding: 20px 15px; }
    .activity-form .control-label { text-align: right; font-weight: 600; padding-top: 7px; }
    .activity-client-body { min-height: 260px; }
    .activity-line + .activity-line { margin-top: 10px; }
    .activity-notes-toolbar .btn { background: #f7f7f7; }
    .activity-notes-editor {
        min-height: 180px;
        max-height: 420px;
        overflow-y: auto;
        border: 1px solid #ccc;
        padding: 10px 12px;
        background: #fff;
        margin-top: 8px;
    }
    .activity-notes-editor:focus { outline: none; border-color: #66afe9; box-shadow: 0 0 0 .15rem rgba(102, 175, 233, .25); }
    .activity-form .select2-container { width: 100% !important; }
    .activity-form .select2-container .select2-selection--single { height: 38px; padding-top: 4px; }
    @media (max-width: 767px) { .activity-form .control-label { text-align: left; } }
</style>

<?php if ($tablesReady && !$categoriesAll): ?>
<div class="alert alert-info">
    Ainda não existem categorias de evento.
    <?php if ($isAdmin): ?><a href="<?= BASE_URL ?>contabilidade/atividades/categorias">Criar categorias</a>.<?php else: ?>Contacte o administrador.<?php endif; ?>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-bars"></i> Criar novo evento</h2>
                <ul class="nav navbar-right panel_toolbox">
                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <form id="activity-form" class="activity-form" autocomplete="off" novalidate>
                    <input type="hidden" name="action" value="save-activity">
                    <input type="hidden" name="csrf_token" id="activity-csrf" value="<?= htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="notes" id="activity-notes-input" value="">

                    <div class="accordion" id="activity-accordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#activity-step-client" aria-expanded="true">Cliente</button>
                            </h2>
                            <div id="activity-step-client" class="accordion-collapse collapse show" data-bs-parent="#activity-accordion">
                                <div class="accordion-body activity-client-body">
                                    <div class="row mb-3">
                                        <label class="col-md-2 control-label" for="activity-client">Cliente</label>
                                        <div class="col-md-6">
                                            <select id="activity-client" name="entity_id" class="form-control"></select>
                                            <small class="text-muted">Pesquise o Nome de Cliente</small>
                                        </div>
                                        <div class="col-md-1">
                                            <input type="text" id="activity-client-code" class="form-control" placeholder="Código" title="Enter para procurar por código">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" id="activity-client-nif" class="form-control" placeholder="NIF" title="Enter para procurar por NIF">
                                        </div>
                                    </div>
                                    <hr>
                                    <div class="row">
                                        <label class="col-md-2 control-label" for="activity-responsible">Colaborador</label>
                                        <div class="col-md-3">
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

                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#activity-step-type" aria-expanded="false">Tipo de evento</button>
                            </h2>
                            <div id="activity-step-type" class="accordion-collapse collapse" data-bs-parent="#activity-accordion">
                                <div class="accordion-body">
                                    <div id="activity-lines"></div>

                                    <div class="row mt-3">
                                        <label class="col-md-2 control-label">Observações:</label>
                                        <div class="col-md-9">
                                            <div class="btn-toolbar activity-notes-toolbar" role="toolbar" data-target="#activity-notes-editor">
                                                <div class="btn-group me-2 mb-1">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="fa fa-font"></i> Formato</button>
                                                    <ul class="dropdown-menu">
                                                        <li><a class="dropdown-item" href="#" data-cmd="formatBlock" data-value="p">Texto normal</a></li>
                                                        <li><a class="dropdown-item" href="#" data-cmd="formatBlock" data-value="h3">Título</a></li>
                                                        <li><a class="dropdown-item" href="#" data-cmd="formatBlock" data-value="h4">Subtítulo</a></li>
                                                        <li><a class="dropdown-item" href="#" data-cmd="formatBlock" data-value="blockquote">Citação</a></li>
                                                    </ul>
                                                </div>
                                                <div class="btn-group me-2 mb-1">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="bold" title="Negrito"><strong>Negrito</strong></button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="italic" title="Itálico"><em>Itálico</em></button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="underline" title="Sublinhado"><u>Sublinhado</u></button>
                                                </div>
                                                <div class="btn-group me-2 mb-1">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="insertUnorderedList" title="Lista"><i class="fa fa-list-ul"></i></button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="insertOrderedList" title="Lista numerada"><i class="fa fa-list-ol"></i></button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="outdent" title="Diminuir avanço"><i class="fa fa-outdent"></i></button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="indent" title="Aumentar avanço"><i class="fa fa-indent"></i></button>
                                                </div>
                                                <div class="btn-group mb-1">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="undo" title="Anular"><i class="fa fa-undo"></i></button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-cmd="redo" title="Refazer"><i class="fa fa-repeat"></i></button>
                                                </div>
                                            </div>
                                            <div id="activity-notes-editor" class="activity-notes-editor" contenteditable="true"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#activity-step-finish" aria-expanded="false">Finalizar</button>
                            </h2>
                            <div id="activity-step-finish" class="accordion-collapse collapse" data-bs-parent="#activity-accordion">
                                <div class="accordion-body">
                                    <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                                        <span id="activity-result" class="me-auto"></span>
                                        <button type="submit" id="activity-submit" class="btn btn-primary"<?= $tablesReady && $categoriesAll ? '' : ' disabled'; ?>>Submeter</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?= BASE_URL; ?>vendors/sweetalert2/dist/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var endpoint = <?= json_encode(BASE_URL . 'contabilidade/atividades', JSON_UNESCAPED_SLASHES); ?>;
    var categories = <?= json_encode(array_map(static fn($c) => [
        'id' => (int) $c['id'],
        'title' => (string) $c['title'],
        'type' => (string) $c['extra_field_type'],
        'label' => (string) $c['extra_field_label'],
        'fixed_assets' => (int) $c['asks_fixed_assets'] === 1,
    ], $categoriesAll), JSON_UNESCAPED_UNICODE); ?>;
    var categoriesById = {};
    categories.forEach(function (c) { categoriesById[c.id] = c; });

    var form = document.getElementById('activity-form');
    var $client = $('#activity-client');
    var codeInput = document.getElementById('activity-client-code');
    var nifInput = document.getElementById('activity-client-nif');
    var linesBox = document.getElementById('activity-lines');
    var editor = document.getElementById('activity-notes-editor');
    var submitBtn = document.getElementById('activity-submit');
    var resultBox = document.getElementById('activity-result');
    var csrfInput = document.getElementById('activity-csrf');
    var lineSeq = 0;

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function notify(type, message) {
        if (window.Swal) {
            Swal.fire({ icon: type, text: message, timer: type === 'success' ? 2500 : undefined, showConfirmButton: type !== 'success' });
        } else {
            alert(message);
        }
    }

    // ---- Cliente -------------------------------------------------------
    $client.select2({
        placeholder: '',
        allowClear: true,
        minimumInputLength: 2,
        language: {
            inputTooShort: function () { return 'Introduza pelo menos 2 caracteres'; },
            noResults: function () { return 'Nenhum cliente encontrado'; },
            searching: function () { return 'A pesquisar...'; }
        },
        ajax: {
            url: endpoint,
            dataType: 'json',
            delay: 250,
            data: function (params) { return { action: 'search-clients', q: params.term || '' }; },
            processResults: function (data) { return { results: (data && data.results) || [] }; }
        }
    });

    function applyClient(item) {
        codeInput.value = item ? (item.code || '') : '';
        nifInput.value = item ? (item.nif || '') : '';
    }

    $client.on('select2:select', function (e) { applyClient(e.params.data); });
    $client.on('select2:clear', function () { applyClient(null); });

    // Procura exata por Codigo/NIF (Enter ou ao sair do campo).
    function lookupExact(input) {
        var value = input.value.trim();
        if (value === '' || input.dataset.lastLookup === value) {
            return;
        }
        input.dataset.lastLookup = value;
        $.getJSON(endpoint, { action: 'search-clients', exact: value }).done(function (data) {
            var results = (data && data.results) || [];
            if (results.length === 1) {
                var item = results[0];
                var option = new Option(item.text, item.id, true, true);
                $client.empty().append(option).trigger('change');
                applyClient(item);
            } else if (results.length === 0) {
                notify('warning', 'Nenhum cliente com o código/NIF ' + value + '.');
            } else {
                notify('info', 'Existe mais do que um cliente com ' + value + '. Pesquise pelo nome.');
            }
        });
    }
    [codeInput, nifInput].forEach(function (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                lookupExact(input);
            }
        });
        input.addEventListener('blur', function () { lookupExact(input); });
        input.addEventListener('input', function () { delete input.dataset.lastLookup; });
    });

    // ---- Tipo de evento (linhas de categoria) --------------------------
    function currentMonth() {
        var d = new Date();
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    }

    function categoryOptions() {
        return '<option value=""></option>' + categories.map(function (c) {
            return '<option value="' + c.id + '">' + escapeHtml(c.title) + '</option>';
        }).join('');
    }

    function renderExtras(row) {
        var category = categoriesById[row.querySelector('.activity-line-category').value];
        var idx = row.dataset.idx;
        var fixedBox = row.querySelector('.activity-line-fixed');
        var extraBox = row.querySelector('.activity-line-extra');
        var extraInput = row.querySelector('.activity-line-value');
        fixedBox.innerHTML = '';
        extraBox.querySelector('.activity-line-legend').textContent = '';

        if (category && category.fixed_assets) {
            fixedBox.innerHTML = '<select class="form-select" name="lines[' + idx + '][fixed_assets_sold]" title="Venda/alienação de imobilizado">'
                + '<option value="">Imobilizado?</option><option value="0">Imobilizado: Não</option><option value="1">Imobilizado: Sim</option></select>';
        }

        var type = category ? category.type : 'none';
        if (type === 'none') {
            extraInput.type = 'text';
            extraInput.value = '';
            extraInput.disabled = true;
            extraInput.placeholder = '';
            return;
        }
        extraBox.querySelector('.activity-line-legend').textContent = (category.label || '') + (category.label ? ':' : '');
        extraInput.disabled = false;
        extraInput.type = { month: 'month', date: 'date', number: 'number' }[type] || 'text';
        extraInput.step = type === 'number' ? 'any' : '';
        extraInput.placeholder = category.label || '';
        extraInput.value = type === 'month' ? currentMonth() : '';
    }

    function addLine(isFirst) {
        var idx = lineSeq++;
        var row = document.createElement('div');
        row.className = 'row align-items-start activity-line';
        row.dataset.idx = idx;
        row.innerHTML =
            '<label class="col-md-2 control-label">Categoria</label>'
            + '<div class="col-md-3"><select class="form-select activity-line-category" name="lines[' + idx + '][category_id]">' + categoryOptions() + '</select></div>'
            + '<div class="col-md-2 activity-line-fixed"></div>'
            + '<div class="col-md-3 activity-line-extra"><div class="input-group">'
            + '<span class="input-group-text activity-line-legend" style="display:none"></span>'
            + '<input type="text" class="form-control activity-line-value" name="lines[' + idx + '][value]" disabled>'
            + '</div></div>'
            + '<div class="col-md-1">' + (isFirst
                ? '<button type="button" class="btn btn-primary btn-sm activity-line-add" title="Adicionar categoria"><i class="fa fa-plus-circle"></i></button>'
                : '<button type="button" class="btn btn-danger btn-sm activity-line-remove" title="Remover"><i class="fa fa-times"></i></button>')
            + '</div>';
        linesBox.appendChild(row);
        return row;
    }

    linesBox.addEventListener('change', function (e) {
        if (e.target.classList.contains('activity-line-category')) {
            var row = e.target.closest('.activity-line');
            renderExtras(row);
            var legend = row.querySelector('.activity-line-legend');
            legend.style.display = legend.textContent ? '' : 'none';
        }
    });
    linesBox.addEventListener('click', function (e) {
        if (e.target.closest('.activity-line-add')) {
            addLine(false);
        } else if (e.target.closest('.activity-line-remove')) {
            e.target.closest('.activity-line').remove();
        }
    });
    addLine(true);

    // ---- Observacoes (editor simples) ----------------------------------
    document.querySelectorAll('.activity-notes-toolbar [data-cmd]').forEach(function (btn) {
        btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            editor.focus();
            var cmd = btn.dataset.cmd;
            var value = btn.dataset.value || null;
            document.execCommand(cmd, false, cmd === 'formatBlock' ? '<' + value + '>' : value);
        });
    });

    // ---- Submeter ------------------------------------------------------
    function openStep(id) {
        var el = document.getElementById(id);
        if (el && !el.classList.contains('show')) {
            bootstrap.Collapse.getOrCreateInstance(el, { toggle: false }).show();
        }
    }

    function validate() {
        if (!$client.val()) {
            openStep('activity-step-client');
            return 'Selecione o cliente.';
        }
        var rows = linesBox.querySelectorAll('.activity-line');
        var hasCategory = false;
        for (var i = 0; i < rows.length; i++) {
            var category = categoriesById[rows[i].querySelector('.activity-line-category').value];
            if (!category) {
                continue;
            }
            hasCategory = true;
            var fixed = rows[i].querySelector('.activity-line-fixed select');
            if (fixed && fixed.value === '') {
                openStep('activity-step-type');
                fixed.focus();
                return 'Por favor confirme se houve ou não vendas de imobilizado.';
            }
            var extra = rows[i].querySelector('.activity-line-value');
            if (category.type !== 'none' && extra.value.trim() === '') {
                openStep('activity-step-type');
                extra.focus();
                return 'Preencha o ' + (category.label || 'campo adicional') + ' (' + category.title + ').';
            }
        }
        if (!hasCategory) {
            openStep('activity-step-type');
            return 'Introduza a categoria no Tipo de evento.';
        }
        return '';
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var error = validate();
        if (error) {
            notify('error', error);
            return;
        }
        document.getElementById('activity-notes-input').value = editor.innerHTML;
        submitBtn.disabled = true;
        fetch(endpoint, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data && data.csrf_token) {
                    csrfInput.value = data.csrf_token;
                }
                if (data && data.ok) {
                    notify('success', data.message);
                    resultBox.innerHTML = '<a href="' + escapeHtml(data.view_url) + '" class="btn btn-success">Visualizar ' + escapeHtml(data.code) + '</a> '
                        + '<a href="' + escapeHtml(endpoint + '/novo') + '" class="btn btn-default btn-outline-secondary">Registar outro</a>';
                    return;
                }
                submitBtn.disabled = false;
                notify('error', (data && data.message) || 'Não foi possível gravar o evento.');
            })
            .catch(function () {
                submitBtn.disabled = false;
                notify('error', 'Erro de comunicação ao gravar o evento.');
            });
    });
});
</script>

<?php elseif ($view === 'ver'): ?>
<?php if (!$activity): ?>
<div class="alert alert-danger">Evento não encontrado.</div>
<?php else:
    $createdBy = activityUserLabel(getUserById((int) ($activity['created_by'] ?? 0)));
    $responsible = activityUserLabel(getUserById((int) ($activity['responsible_user_id'] ?? 0)));
    $isCancelled = (int) $activity['status'] === 0;
?>
<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-file-text-o"></i> Evento #<?= htmlspecialchars((string) $activity['code']); ?>
                    <?php if ($isCancelled): ?><span class="badge bg-danger ms-2">Anulado</span><?php endif; ?>
                </h2>
                <div class="float-end text-muted pt-2"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $activity['created_at']))); ?></div>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Operador:</strong> <?= htmlspecialchars($createdBy); ?><br>
                        <strong>Colaborador:</strong> <?= htmlspecialchars($responsible); ?>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <strong><?= htmlspecialchars((string) $activity['client_name']); ?></strong><br>
                        <strong>N.º cliente:</strong> <?= htmlspecialchars((string) $activity['client_code']); ?>
                        &nbsp;&nbsp;&nbsp;
                        <strong>NIF:</strong> <?= htmlspecialchars((string) $activity['client_nif']); ?>
                    </div>
                </div>
                <table class="table table-striped table-hover">
                    <thead>
                        <tr><th>Descrição</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activityLines as $line): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string) $line['title']); ?></strong> <?= htmlspecialchars((string) $line['extra_field_label']); ?></td>
                            <td>
                                <?= htmlspecialchars(formatActivityExtraValue((string) $line['extra_field_type'], (string) $line['value'])); ?>
                                <?php if ($line['fixed_assets_sold'] !== null): ?>
                                    <?= (int) $line['fixed_assets_sold'] === 1 ? '(Houve venda imobiliz.)' : '(Sem venda de imobilizado)'; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="row">
                    <div class="col-md-6">
                        <div class="well p-2 border bg-light">
                            <strong>Notas:</strong>
                            <?= (string) ($activity['notes'] ?? '') !== '' ? sanitizeActivityNotes((string) $activity['notes']) : ''; ?>
                        </div>
                    </div>
                    <div class="col-md-6 text-md-end d-print-none">
                        <a class="btn btn-outline-secondary" href="javascript:history.back()"><i class="fa fa-arrow-left"></i> Retroceder</a>
                        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>contabilidade/atividades/novo"><i class="fa fa-plus"></i> Novo evento</a>
                        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i> Imprimir</button>
                        <?php if ($isAdmin): ?>
                        <form method="post" action="<?= BASE_URL ?>contabilidade/atividades" class="d-inline"
                              onsubmit="return confirm('<?= $isCancelled ? 'Pretende ativar este evento?' : 'Pretende anular este evento?'; ?>');">
                            <input type="hidden" name="action" value="<?= $isCancelled ? 'reactivate-activity' : 'cancel-activity'; ?>">
                            <input type="hidden" name="activity_id" value="<?= (int) $activity['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                            <?php if ($isCancelled): ?>
                            <button type="submit" class="btn btn-success"><i class="fa fa-play"></i> Reativar</button>
                            <?php else: ?>
                            <button type="submit" class="btn btn-warning"><i class="fa fa-trash"></i> Anular</button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <table class="w-100 mt-5 d-none d-print-table">
                    <tr>
                        <td style="width:50%">Responsável <?= htmlspecialchars((string) $appName); ?>:</td>
                        <td style="width:50%">O Cliente:</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
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
