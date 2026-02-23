<?php
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

if (!isModuleActive('contabilidade')) {
    http_response_code(403);
    exit('Módulo indisponível.');
}

$user = currentUser();
if (!$user || ($user['role'] ?? 3) > 2) {
    http_response_code(403);
    exit('Sem permissões.');
}

$csrfToken = generateCsrfToken();
$useDataTables = true;
$hideOcrModal = true;

$pdo = getPDO();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        exit('Token inválido.');
    }

    $action = $_POST['action'] ?? '';
    $taskId = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $assignedTo = isset($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null;
        $dueDate = trim((string) ($_POST['due_date'] ?? ''));
        if ($title !== '') {
            $stmt = $pdo->prepare('INSERT INTO ai_tasks (title, description, assigned_to, created_by, due_date) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$title, $description !== '' ? $description : null, $assignedTo ?: null, $user['id'], $dueDate !== '' ? $dueDate : null]);
            logAuditAction('ai_task_create', 'ai_tasks', (int) $pdo->lastInsertId(), ['title' => $title]);
        }
    } elseif ($action === 'close' && $taskId > 0) {
        $stmt = $pdo->prepare('UPDATE ai_tasks SET status = ? WHERE id = ?');
        $stmt->execute(['closed', $taskId]);
        logAuditAction('ai_task_close', 'ai_tasks', $taskId);
    } elseif ($action === 'assign' && $taskId > 0) {
        $assignedTo = isset($_POST['assigned_to']) ? (int) $_POST['assigned_to'] : null;
        $stmt = $pdo->prepare('UPDATE ai_tasks SET assigned_to = ? WHERE id = ?');
        $stmt->execute([$assignedTo ?: null, $taskId]);
        logAuditAction('ai_task_assign', 'ai_tasks', $taskId, ['assigned_to' => $assignedTo ?: null]);
    }

    header('Location: ' . BASE_URL . 'contabilidade/ai-tarefas');
    exit;
}

$users = $pdo->query('SELECT id, username FROM users ORDER BY username ASC')->fetchAll(PDO::FETCH_ASSOC);
$tasks = $pdo->query('SELECT t.*, u.username AS assigned_name, c.username AS creator_name FROM ai_tasks t LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN users c ON c.id = t.created_by ORDER BY t.status ASC, t.created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../header.php';
?>
<div class="right_col" role="main">
    <div class="container-fluid">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-plus-circle"></i> Nova tarefa</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">Título</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Atribuir a</label>
                            <select name="assigned_to" class="form-select">
                                <option value="">(Sem atribuição)</option>
                                <?php foreach ($users as $row): ?>
                                    <option value="<?= (int) $row['id']; ?>"><?= htmlspecialchars($row['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prazo</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                        <div class="col-12 mt-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-12 mt-3">
                            <button type="submit" class="btn btn-success">
                                <i class="fa fa-plus"></i> Criar tarefa
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-check-square-o"></i> Tarefas AI</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="table-responsive">
                    <table class="table table-striped" id="ai-tasks-table">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Título</th>
                            <th>Estado</th>
                            <th>Atribuído</th>
                            <th>Criado por</th>
                            <th>Prazo</th>
                            <th class="text-end">Ações</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $status = $task['status'] ?? 'open';
                            $statusBadge = $status === 'closed' ? 'badge-success' : 'badge-info';
                            $dueDate = $task['due_date'] ?? '';
                            ?>
                            <tr>
                                <td><?= (int) $task['id']; ?></td>
                                <td><?= htmlspecialchars($task['title']); ?></td>
                                <td><span class="badge <?= $statusBadge; ?>"><?= htmlspecialchars($status); ?></span></td>
                                <td><?= htmlspecialchars($task['assigned_name'] ?? ''); ?></td>
                                <td><?= htmlspecialchars($task['creator_name'] ?? ''); ?></td>
                                <td><?= $dueDate ? htmlspecialchars($dueDate) : '-'; ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="assign">
                                        <input type="hidden" name="id" value="<?= (int) $task['id']; ?>">
                                        <select name="assigned_to" class="form-select form-select-sm d-inline w-auto">
                                            <option value="">(Sem atribuição)</option>
                                            <?php foreach ($users as $row): ?>
                                                <option value="<?= (int) $row['id']; ?>" <?= (int) $task['assigned_to'] === (int) $row['id'] ? 'selected' : ''; ?>>
                                                    <?= htmlspecialchars($row['username']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-xs btn-primary">Atribuir</button>
                                    </form>
                                    <?php if ($task['status'] !== 'closed'): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="close">
                                            <input type="hidden" name="id" value="<?= (int) $task['id']; ?>">
                                            <button type="submit" class="btn btn-xs btn-success">Concluir</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<'JS'
var aiTasksTable = document.getElementById('ai-tasks-table');
if (aiTasksTable) {
    aiTasksTable.classList.add('table-hover');
}
if (window.jQuery && jQuery.fn.DataTable) {
    jQuery('#ai-tasks-table').DataTable({
        language: {
            emptyTable: 'Sem registos.',
            lengthMenu: '_MENU_',
            search: 'Pesquisa:',
            info: 'A mostrar _START_ a _END_ de _TOTAL_ registos',
            infoEmpty: 'A mostrar 0 registos',
            infoFiltered: '(filtrado de _MAX_ registos)',
            paginate: {
                first: 'Primeiro',
                last: 'Último',
                next: 'Seguinte',
                previous: 'Anterior'
            }
        }
    });
}
JS;

require_once __DIR__ . '/../footer.php';
?>
