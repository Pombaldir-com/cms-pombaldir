<?php
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

if (!isModuleActive('efatura')) {
    http_response_code(403);
    exit('Modulo indisponivel.');
}

if (!userHasDepartmentPermission('ctb_efatura_aceder')) {
    http_response_code(403);
    exit('Sem permissoes.');
}

$useDataTables = true;
$hideOcrModal = true;
$pdo = getPDO();
$user = currentUser();
$csrfToken = generateCsrfToken();
$view = trim((string) ($_GET['view'] ?? 'empresas'));
$action = trim((string) ($_GET['action'] ?? ''));
$allowedViews = ['empresas', 'documentos', 'sincronizacoes'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'empresas';
}

$efaturaSelectionSessionKey = 'efatura_selected_entity_id';
$selectedEntityId = 0;
if (array_key_exists('empresa', $_GET)) {
    $selectedEntityId = (int) $_GET['empresa'];
    $_SESSION[$efaturaSelectionSessionKey] = $selectedEntityId;
} elseif (isset($_SESSION[$efaturaSelectionSessionKey])) {
    $selectedEntityId = (int) $_SESSION[$efaturaSelectionSessionKey];
}

if ($action === 'sync_status') {
    handleEfaturaSyncStatus($pdo);
}

if ($action === 'documents_data') {
    handleEfaturaDocumentsData($pdo, $selectedEntityId);
}

$tablesReady = efaturaTablesReady();
$canManageCredentials = userHasDepartmentPermission('ctb_efatura_credenciais');
$canSync = userHasDepartmentPermission('ctb_efatura_sincronizar');
$flash = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['csrf_token'] ?? ''));
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        exit('Token invalido.');
    }

    $postAction = trim((string) ($_POST['action'] ?? ''));
    if (!$tablesReady) {
        $flash = ['type' => 'error', 'message' => 'As tabelas do modulo E-fatura ainda nao existem. Executa as migracoes primeiro.'];
    } elseif ($postAction === 'save_credentials') {
        if (!$canManageCredentials) {
            http_response_code(403);
            exit('Sem permissoes para gerir credenciais.');
        }
        try {
            saveEfaturaCredential($pdo, $user);
            $selectedView = 'empresas';
            $selectedEntity = (int) ($_POST['entity_id'] ?? 0);
            header('Location: ' . BASE_URL . 'contabilidade/efatura/empresas?empresa=' . $selectedEntity . '&status=success&msg=' . rawurlencode('Credenciais guardadas.'));
            exit;
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'message' => $e->getMessage()];
        }
    } elseif ($postAction === 'create_sync_job') {
        if (!$canSync) {
            http_response_code(403);
            exit('Sem permissoes para sincronizar.');
        }
        try {
            $jobId = createEfaturaSyncJob($pdo, $user);
            header('Location: ' . BASE_URL . 'contabilidade/efatura/sincronizacoes?status=success&msg=' . rawurlencode('Sincronizacao criada. Job #' . $jobId . '.'));
            exit;
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'message' => $e->getMessage()];
        }
    } elseif ($postAction === 'delete_credentials') {
        if (!$canManageCredentials) {
            http_response_code(403);
            exit('Sem permissoes para gerir credenciais.');
        }
        try {
            $selectedEntity = (int) ($_POST['entity_id'] ?? 0);
            deleteEfaturaCredential($pdo, $user, $selectedEntity);
            header('Location: ' . BASE_URL . 'contabilidade/efatura/empresas?empresa=' . $selectedEntity . '&status=success&msg=' . rawurlencode('Credenciais removidas.'));
            exit;
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'message' => $e->getMessage()];
        }
    }
}

if (isset($_GET['status'], $_GET['msg'])) {
    $flash = [
        'type' => trim((string) $_GET['status']) === 'success' ? 'success' : 'error',
        'message' => trim((string) $_GET['msg']),
    ];
}

$entities = [];
$selectedEntity = null;
$selectedCredential = null;
$latestJob = null;
$documents = [];
$jobs = [];
$stats = [
    'companies' => 0,
    'credentials' => 0,
    'documents' => 0,
    'jobs_running' => 0,
];

if ($tablesReady) {
    $entities = efaturaFetchCompanies($pdo);
    if ($view === 'empresas' && $selectedEntityId <= 0 && $entities) {
        $selectedEntityId = (int) ($entities[0]['id'] ?? 0);
        $_SESSION[$efaturaSelectionSessionKey] = $selectedEntityId;
    }
    foreach ($entities as $entityRow) {
        if ((int) $entityRow['id'] === $selectedEntityId) {
            $selectedEntity = $entityRow;
            break;
        }
    }
    $selectedCredential = $selectedEntityId > 0 ? efaturaFetchCredential($pdo, $selectedEntityId) : null;
    $documents = efaturaFetchDocuments($pdo, $selectedEntityId);
    $jobs = efaturaFetchJobs($pdo, $selectedEntityId, $view === 'sincronizacoes' ? 100 : 10);
    $latestJob = $jobs[0] ?? null;
    $stats = efaturaFetchStats($pdo);
}

$encryptionReady = efaturaEncryptionReady();
$efaturaTopbarSelector = [
    'enabled' => true,
    'action' => BASE_URL . 'contabilidade/efatura/' . $view,
    'selected_entity_id' => $selectedEntityId,
    'entities' => array_map(static function (array $entity): array {
        $erpDatabase = (string) ($entity['erp_database'] ?? '');
        $erpSuffix = '';
        if (preg_match('/emp_(\d+)/i', $erpDatabase, $matches)) {
            $erpSuffix = $matches[1];
        }
        return [
            'id' => (int) ($entity['id'] ?? 0),
            'name' => (string) ($entity['name'] ?? ''),
            'label' => ($erpSuffix !== '' ? $erpSuffix : (string) ($entity['id'] ?? 0)) . ' - ' . (string) ($entity['name'] ?? ''),
        ];
    }, $entities),
];

require_once __DIR__ . '/../header.php';
?>
<div class="container-fluid efatura-page">
        <?php if ($flash['message'] !== ''): ?>
            <div class="x_panel">
                <div class="x_content">
                    <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-danger'; ?>" role="alert" style="margin-bottom:0;">
                        <?= htmlspecialchars($flash['message']); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$tablesReady): ?>
            <div class="x_panel">
                <div class="x_content">
                    <div class="alert alert-warning" role="alert" style="margin-bottom:0;">
                        As tabelas do modulo E-fatura ainda nao existem nesta base de dados. Executa <code>php scripts/migrate.php</code> para ativar o modulo.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($tablesReady && !$encryptionReady): ?>
            <div class="x_panel">
                <div class="x_content">
                    <div class="alert alert-warning" role="alert" style="margin-bottom:0;">
                        Define <code>EFATURA_SECRET_KEY</code> no ambiente ou no ficheiro <code>.env</code> para guardar credenciais cifradas.
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view !== 'documentos'): ?>
        <div class="row tile_count">
            <div class="col-md-3 col-sm-6 tile_stats_count">
                <span class="count_top"><i class="fa fa-building"></i> Empresas</span>
                <div class="count"><?= (int) $stats['companies']; ?></div>
            </div>
            <div class="col-md-3 col-sm-6 tile_stats_count">
                <span class="count_top"><i class="fa fa-key"></i> Credenciais ativas</span>
                <div class="count"><?= (int) $stats['credentials']; ?></div>
            </div>
            <div class="col-md-3 col-sm-6 tile_stats_count">
                <span class="count_top"><i class="fa fa-files-o"></i> Documentos locais</span>
                <div class="count"><?= (int) $stats['documents']; ?></div>
            </div>
            <div class="col-md-3 col-sm-6 tile_stats_count">
                <span class="count_top"><i class="fa fa-refresh"></i> Jobs a correr</span>
                <div class="count"><?= (int) $stats['jobs_running']; ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-file-text-o"></i> E-fatura</h2>
                <ul class="nav navbar-right panel_toolbox">
                    <li><a href="<?= BASE_URL ?>contabilidade/efatura/empresas" class="<?= $view === 'empresas' ? 'text-primary' : ''; ?>">Empresas</a></li>
                    <li><a href="<?= BASE_URL ?>contabilidade/efatura/documentos" class="<?= $view === 'documentos' ? 'text-primary' : ''; ?>">Documentos</a></li>
                    <li><a href="<?= BASE_URL ?>contabilidade/efatura/sincronizacoes" class="<?= $view === 'sincronizacoes' ? 'text-primary' : ''; ?>">Sincronizações</a></li>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?php if ($view === 'empresas'): ?>
                    <div class="row">
                        <div class="col-lg-8 col-md-12">
                            <?php if ($selectedEntity): ?>
                                <div class="efatura-selection-banner">
                                    <div>
                                        <span class="efatura-selection-label">Empresa ativa</span>
                                        <strong><?= htmlspecialchars((string) $selectedEntity['name']); ?></strong>
                                        <span class="efatura-selection-meta">NIF <?= htmlspecialchars((string) $selectedEntity['nif']); ?> · <?= htmlspecialchars(efaturaFormatErpDatabase((string) ($selectedEntity['erp_database'] ?? ''))); ?></span>
                                    </div>
                                    <?php if (!empty($selectedEntity['last_sync_at'])): ?>
                                        <span class="badge badge-info">Última sync <?= htmlspecialchars((string) $selectedEntity['last_sync_at']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="efatura-companies-table">
                                    <thead>
                                    <tr>
                                        <th>Empresa</th>
                                        <th>NIF</th>
                                        <th>ERP/BD</th>
                                        <th>Credencial</th>
                                        <th>Última sync</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($entities as $entity): ?>
                                        <tr class="<?= $selectedEntity && (int) $selectedEntity['id'] === (int) $entity['id'] ? 'efatura-company-row-active' : ''; ?>">
                                            <td>
                                                <div class="efatura-company-name"><?= htmlspecialchars((string) ($entity['name'] ?? '')); ?></div>
                                            </td>
                                            <td><?= htmlspecialchars((string) ($entity['nif'] ?? '')); ?></td>
                                            <td><span class="badge badge-default"><?= htmlspecialchars(efaturaFormatErpDatabase((string) ($entity['erp_database'] ?? ''))); ?></span></td>
                                            <td>
                                                <?php if (!empty($entity['portal_username'])): ?>
                                                    <span class="badge badge-success"><?= htmlspecialchars(maskEfaturaUsername((string) $entity['portal_username'])); ?></span>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Por configurar</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars((string) ($entity['last_sync_at'] ?? '-')); ?></td>
                                            <td class="text-end">
                                                <div class="efatura-action-stack">
                                                <a class="btn btn-xs btn-primary" href="<?= BASE_URL ?>contabilidade/efatura/empresas?empresa=<?= (int) $entity['id']; ?>">Gerir</a>
                                                <?php if ($canSync): ?>
                                                    <form method="post" class="efatura-inline-form">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="create_sync_job">
                                                        <input type="hidden" name="entity_id" value="<?= (int) $entity['id']; ?>">
                                                        <input type="hidden" name="period_start" value="<?= htmlspecialchars(date('Y-m-01')); ?>">
                                                        <input type="hidden" name="period_end" value="<?= htmlspecialchars(date('Y-m-t')); ?>">
                                                        <button type="submit" class="btn btn-xs btn-success">Sync mês</button>
                                                    </form>
                                                <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-lg-4 col-md-12">
                            <?php if ($selectedEntity): ?>
                                <div class="x_panel efatura-side-panel">
                                    <div class="x_title">
                                        <h2><i class="fa fa-cog"></i> Empresa selecionada</h2>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="x_content">
                                        <div class="efatura-company-card">
                                            <div class="efatura-company-card-head">
                                                <h4><?= htmlspecialchars((string) $selectedEntity['name']); ?></h4>
                                                <span class="badge badge-primary"><?= htmlspecialchars(efaturaFormatErpDatabase((string) ($selectedEntity['erp_database'] ?? ''))); ?></span>
                                            </div>
                                            <div class="row efatura-company-meta">
                                                <div class="col-sm-6 col-12">
                                                    <span class="efatura-meta-label">NIF</span>
                                                    <strong><?= htmlspecialchars((string) $selectedEntity['nif']); ?></strong>
                                                </div>
                                                <div class="col-sm-6 col-12">
                                                    <span class="efatura-meta-label">Base ERP</span>
                                                    <strong><?= htmlspecialchars(efaturaFormatErpDatabase((string) ($selectedEntity['erp_database'] ?? ''))); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="x_panel efatura-side-panel">
                                    <div class="x_title">
                                        <h2><i class="fa fa-key"></i> Credenciais</h2>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="x_content">
                                        <?php if ($canManageCredentials): ?>
                                            <?php if ($selectedCredential): ?>
                                                <div class="efatura-toolbar">
                                                    <span class="badge badge-success">Credencial configurada</span>
                                                    <form method="post" class="efatura-inline-form" onsubmit="return confirm('Remover as credenciais guardadas desta empresa?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="action" value="delete_credentials">
                                                        <input type="hidden" name="entity_id" value="<?= (int) $selectedEntity['id']; ?>">
                                                        <button type="submit" class="btn btn-xs btn-danger">
                                                            <i class="fa fa-trash"></i> Remover
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                            <form method="post" class="row efatura-form-grid">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="save_credentials">
                                                <input type="hidden" name="entity_id" value="<?= (int) $selectedEntity['id']; ?>">
                                                <div class="col-md-6 col-12">
                                                    <label class="form-label">Etiqueta</label>
                                                    <input type="text" name="credential_label" class="form-control" value="<?= htmlspecialchars((string) ($selectedCredential['credential_label'] ?? 'Portal E-fatura')); ?>" <?= !$tablesReady || !$encryptionReady ? 'disabled' : ''; ?>>
                                                </div>
                                                <div class="col-md-6 col-12">
                                                    <label class="form-label">Utilizador do portal</label>
                                                    <input type="text" name="portal_username" class="form-control" value="<?= htmlspecialchars((string) ($selectedCredential['portal_username'] ?? '')); ?>" <?= !$tablesReady || !$encryptionReady ? 'disabled' : ''; ?>>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Password</label>
                                                    <input type="password" name="portal_password" class="form-control" placeholder="<?= $selectedCredential ? 'Preencher apenas para substituir' : ''; ?>" <?= !$tablesReady || !$encryptionReady ? 'disabled' : ''; ?>>
                                                </div>
                                                <div class="col-12">
                                                    <div class="checkbox efatura-checkbox">
                                                        <label>
                                                            <input type="checkbox" name="is_active" value="1" <?= !isset($selectedCredential['is_active']) || (int) $selectedCredential['is_active'] === 1 ? 'checked' : ''; ?> <?= !$tablesReady || !$encryptionReady ? 'disabled' : ''; ?>>
                                                            Credencial ativa
                                                        </label>
                                                    </div>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-primary btn-block" <?= !$tablesReady || !$encryptionReady ? 'disabled' : ''; ?>>
                                                        <i class="fa fa-save"></i> Guardar credenciais
                                                    </button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="alert alert-info" role="alert" style="margin-bottom:0;">
                                                Sem permissões para gerir credenciais.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($canSync): ?>
                                    <div class="x_panel efatura-side-panel">
                                        <div class="x_title">
                                            <h2><i class="fa fa-refresh"></i> Sincronização</h2>
                                            <div class="clearfix"></div>
                                        </div>
                                        <div class="x_content">
                                            <?php if ($latestJob): ?>
                                                <div class="alert alert-<?= in_array((string) ($latestJob['status'] ?? ''), ['failed', 'partial'], true) ? 'danger' : 'info'; ?>" role="alert">
                                                    <strong>Último job:</strong>
                                                    <?= htmlspecialchars((string) ($latestJob['status'] ?? '')); ?>
                                                    <?php if (!empty($latestJob['updated_at'])): ?>
                                                        em <?= htmlspecialchars((string) $latestJob['updated_at']); ?>
                                                    <?php endif; ?>
                                                    <?php if (!empty($latestJob['error_message'])): ?>
                                                        <br>
                                                        <?= nl2br(htmlspecialchars((string) $latestJob['error_message'])); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <form method="post" class="row efatura-form-grid">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="create_sync_job">
                                                <input type="hidden" name="entity_id" value="<?= (int) $selectedEntity['id']; ?>">
                                                <div class="col-sm-6 col-12">
                                                    <label class="form-label">Data início</label>
                                                    <input type="date" name="period_start" class="form-control" value="<?= htmlspecialchars(date('Y-m-01')); ?>" <?= !$tablesReady ? 'disabled' : ''; ?>>
                                                </div>
                                                <div class="col-sm-6 col-12">
                                                    <label class="form-label">Data fim</label>
                                                    <input type="date" name="period_end" class="form-control" value="<?= htmlspecialchars(date('Y-m-t')); ?>" <?= !$tablesReady ? 'disabled' : ''; ?>>
                                                </div>
                                                <div class="col-12 efatura-sync-note">
                                                    <span>O worker Python será lançado em background para este período.</span>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-success btn-block" <?= !$tablesReady ? 'disabled' : ''; ?>>
                                                        <i class="fa fa-refresh"></i> Criar job de sincronizacao
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="x_panel efatura-side-panel">
                                    <div class="x_content">
                                        <div class="alert alert-info" role="alert" style="margin-bottom:0;">
                                            Seleciona uma empresa para configurar o modulo.
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($view === 'documentos'): ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="efatura-documents-table">
                            <thead>
                            <tr>
                                <th>Data</th>
                                <th>Emitente</th>
                                <th>NIF emitente</th>
                                <th>Documento</th>
                                <th>Tipo</th>
                                <th>Líquido</th>
                                <th>IVA</th>
                                <th>Total</th>
                            </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="efatura-jobs-table">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Período</th>
                                <th>Estado</th>
                                <th>Docs</th>
                                <th>Erro</th>
                                <th>Atualizado</th>
                                <th>Ações</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr data-job-id="<?= (int) $job['id']; ?>">
                                    <td>#<?= (int) $job['id']; ?></td>
                                    <td><?= htmlspecialchars((string) $job['period_start']); ?> a <?= htmlspecialchars((string) $job['period_end']); ?></td>
                                    <td><span class="badge efatura-job-status badge-<?= htmlspecialchars(efaturaStatusBadge((string) $job['status'])); ?>"><?= htmlspecialchars((string) $job['status']); ?></span></td>
                                    <td><?= (int) $job['documents_saved']; ?>/<?= (int) $job['documents_found']; ?></td>
                                    <td><?= htmlspecialchars((string) ($job['error_message'] ?? '')); ?></td>
                                    <td><?= htmlspecialchars((string) ($job['updated_at'] ?? '')); ?></td>
                                    <td><button type="button" class="btn btn-xs btn-default efatura-job-log-btn" data-job-id="<?= (int) $job['id']; ?>"><i class="fa fa-file-text-o"></i> Log</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($tablesReady && $jobs && $view === 'sincronizacoes'): ?>
            <div class="x_panel">
                <div class="x_title">
                    <h2><i class="fa fa-clock-o"></i> Jobs recentes</h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="efatura-jobs-mini-table">
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>Empresa</th>
                                <th>Estado</th>
                                <th>Período</th>
                                <th>Docs</th>
                                <th>Ações</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach (array_slice($jobs, 0, 10) as $job): ?>
                                <tr data-job-id="<?= (int) $job['id']; ?>">
                                    <td>#<?= (int) $job['id']; ?></td>
                                    <td><?= htmlspecialchars((string) $job['entity_name']); ?></td>
                                    <td><span class="badge efatura-job-status badge-<?= htmlspecialchars(efaturaStatusBadge((string) $job['status'])); ?>"><?= htmlspecialchars((string) $job['status']); ?></span></td>
                                    <td><?= htmlspecialchars((string) $job['period_start']); ?> a <?= htmlspecialchars((string) $job['period_end']); ?></td>
                                    <td><?= (int) $job['documents_saved']; ?>/<?= (int) $job['documents_found']; ?></td>
                                    <td><button type="button" class="btn btn-xs btn-default efatura-job-log-btn" data-job-id="<?= (int) $job['id']; ?>"><i class="fa fa-file-text-o"></i> Log</button></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
</div>
<div class="modal fade" id="efaturaJobLogModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Log do job E-fatura</h4>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="efaturaJobLogMeta" class="text-muted" style="margin-bottom:12px;"></div>
                <pre id="efaturaJobLogContent" style="white-space:pre-wrap;word-break:break-word;max-height:60vh;">A carregar...</pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
<?php
$pageScripts = 'var efaturaStyle = document.createElement("style");
efaturaStyle.textContent = ".efatura-side-panel .x_content{padding:20px;}.efatura-form-grid>div{margin-bottom:15px;}.efatura-form-grid>div:last-child{margin-bottom:0;}.efatura-checkbox{margin:0;}.efatura-checkbox label{margin-bottom:0;font-weight:600;color:#4f6278;}.efatura-company-card{background:#f8fafc;border:1px solid #d8e2ee;border-radius:10px;padding:18px 18px;}.efatura-company-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;}.efatura-company-card h4{margin:0;line-height:1.35;color:#506784;}.efatura-company-card .badge{background:#e8f1fb !important;color:#35506d !important;border:1px solid #c7d8eb;}.efatura-company-meta{row-gap:12px;}.efatura-company-meta strong{color:#5b738e;}.efatura-meta-label{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#7d8fa4;margin-bottom:4px;}.efatura-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:16px;}.efatura-inline-form{margin:0;}.efatura-sync-note{color:#73879c;font-size:12px;}.efatura-page .x_title .panel_toolbox{min-width:auto;}#efatura-documents-table th:first-child,#efatura-documents-table td:first-child{white-space:nowrap;width:1%;}#efatura-documents-table_wrapper .row:first-child{display:flex;align-items:center;justify-content:space-between;}#efatura-documents-table_wrapper .dt-search,#efatura-documents-table_wrapper .dataTables_filter{margin-left:auto;}#efatura-documents-table_wrapper .efatura-documents-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}#efatura-documents-table_wrapper .dt-length,#efatura-documents-table_wrapper .dataTables_length{margin:0;}#efatura-documents-table_wrapper .dt-length label,#efatura-documents-table_wrapper .dataTables_length label{margin:0;display:flex;align-items:center;gap:8px;}#efatura-documents-table_wrapper .dt-layout-end,#efatura-documents-table_wrapper .dt-paging,#efatura-documents-table_wrapper .dataTables_paginate{margin-top:10px;}#efatura-documents-table_wrapper .dt-paging .pagination,#efatura-documents-table_wrapper .dataTables_paginate .pagination{gap:0;margin:0;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button.page-item,#efatura-documents-table_wrapper .dataTables_paginate .page-item{margin:0 3px;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button .page-link,#efatura-documents-table_wrapper .dataTables_paginate .page-link{padding:6px 9px !important;background:#ddd !important;border:1px solid #ddd !important;color:#73879c !important;border-radius:5px !important;box-shadow:none !important;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button.active .page-link,#efatura-documents-table_wrapper .dt-paging .dt-paging-button.active .page-link:hover,#efatura-documents-table_wrapper .dataTables_paginate .page-item.active .page-link,#efatura-documents-table_wrapper .dataTables_paginate .page-item.active .page-link:hover{background:#169f85 !important;border-color:#169f85 !important;color:#fff !important;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button .page-link:hover,#efatura-documents-table_wrapper .dataTables_paginate .page-link:hover{background:#ccc !important;border-color:#ccc !important;color:#2a3f54 !important;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button.disabled .page-link,#efatura-documents-table_wrapper .dt-paging .dt-paging-button.disabled .page-link:hover,#efatura-documents-table_wrapper .dataTables_paginate .page-item.disabled .page-link,#efatura-documents-table_wrapper .dataTables_paginate .page-item.disabled .page-link:hover{background:#ddd !important;border-color:#ddd !important;color:#9aa7b4 !important;opacity:1;}#efatura-documents-table_wrapper .dt-paging .ellipsis,#efatura-documents-table_wrapper .dataTables_paginate .ellipsis{padding:6px 4px;color:#73879c;}#efatura-documents-table_wrapper .paging_full_numbers{width:auto;height:auto;line-height:normal;}.efatura-documents-status-filter{display:flex;align-items:center;gap:8px;margin:0;}.efatura-documents-status-filter label{margin:0;font-weight:600;color:#5b738e;}.efatura-documents-status-filter .form-control{width:170px;min-width:170px;}.efatura-document-row-cancelled td{background:#fbe9e7 !important;color:#7f2d2d !important;}.efatura-document-row-cancelled a,.efatura-document-row-cancelled span,.efatura-document-row-cancelled strong{color:inherit !important;}.efatura-selection-banner{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px;margin-bottom:16px;border:1px solid #d6e1ee;border-radius:10px;background:linear-gradient(135deg,#f8fbff 0%,#eef4fb 100%);}.efatura-selection-label{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#6f86a1;margin-bottom:4px;}.efatura-selection-banner strong{display:block;font-size:18px;line-height:1.3;color:#33475b;}.efatura-selection-meta{display:block;margin-top:4px;color:#607790;font-size:12px;}.efatura-selection-banner .badge{background:#dfeafb !important;color:#45627f !important;border:1px solid #c6d8ef;}.efatura-company-name{font-weight:700;color:#33475b;}.efatura-company-subtext{margin-top:3px;font-size:12px;color:#6d84a0;}.efatura-company-row-active td{background:#edf4fd !important;color:#33475b !important;}.efatura-company-row-active .badge-default{background:#dde8f6 !important;color:#4c6684 !important;}.efatura-company-row-active .badge-success{background:#d9f2e7 !important;color:#2f6b4f !important;}.efatura-company-row-active .efatura-company-subtext{color:#5e7895;}.efatura-action-stack{display:flex;flex-direction:row;justify-content:flex-end;align-items:center;gap:8px;white-space:nowrap;min-width:150px;}.efatura-action-stack .btn{margin:0;}.efatura-side-panel{margin-bottom:18px;}.efatura-side-panel .alert{margin-bottom:15px;}.efatura-page .badge-secondary{background:#e5ebf2 !important;color:#576c84 !important;}.efatura-page .badge-success{background:#dff4ea !important;color:#2d6c50 !important;}.efatura-page .badge-default{background:#edf2f7 !important;color:#607790 !important;}.efatura-page .efatura-job-status.badge-danger{background:#d9534f !important;color:#fff !important;}.efatura-page .efatura-job-status.badge-info{background:#2f7edb !important;color:#fff !important;}.efatura-page .efatura-job-status.badge-warning{background:#f0ad4e !important;color:#fff !important;}.efatura-page .efatura-job-status.badge-success{background:#26b99a !important;color:#fff !important;}.efatura-page .efatura-job-status.badge-secondary{background:#73879c !important;color:#fff !important;}#efatura-companies-table td:last-child,#efatura-companies-table th:last-child{white-space:nowrap;width:1%;}#efatura-companies-table td:nth-child(2),#efatura-companies-table th:nth-child(2),#efatura-companies-table td:nth-child(3),#efatura-companies-table th:nth-child(3),#efatura-companies-table td:nth-child(4),#efatura-companies-table th:nth-child(4),#efatura-companies-table td:nth-child(5),#efatura-companies-table th:nth-child(5),#efatura-jobs-table td:nth-child(2),#efatura-jobs-table th:nth-child(2),#efatura-jobs-table td:nth-child(5),#efatura-jobs-table th:nth-child(5),#efatura-jobs-mini-table td:nth-child(4),#efatura-jobs-mini-table th:nth-child(4){white-space:nowrap;}#efatura-jobs-table td:nth-child(2),#efatura-jobs-table th:nth-child(2){min-width:200px;}#efatura-jobs-table td:nth-child(5),#efatura-jobs-table th:nth-child(5){min-width:170px;}#efatura-jobs-mini-table td:nth-child(4),#efatura-jobs-mini-table th:nth-child(4){min-width:80px;}@media (max-width: 991px){.efatura-selection-banner{flex-direction:column;align-items:flex-start;}.efatura-action-stack{flex-direction:column;align-items:stretch;min-width:0;white-space:normal;}.efatura-toolbar{flex-direction:column;align-items:stretch;}#efatura-documents-table_wrapper .row:first-child{display:block;}#efatura-documents-table_wrapper .dt-search,#efatura-documents-table_wrapper .dataTables_filter{margin-left:0;}#efatura-documents-table_wrapper .efatura-documents-controls{align-items:stretch;}.efatura-documents-status-filter{width:100%;}.efatura-documents-status-filter .form-control{width:100%;min-width:0;}}";
document.head.appendChild(efaturaStyle);
window.efaturaSyncStatusUrl = ' . json_encode(BASE_URL . 'contabilidade/efatura/sync-status', JSON_UNESCAPED_UNICODE) . ';
window.efaturaDocumentsDataUrl = ' . json_encode(BASE_URL . 'contabilidade/efatura/documentos?action=documents_data', JSON_UNESCAPED_UNICODE) . ';
function initEfaturaTable(selector, options) {
    if (!window.jQuery || !jQuery.fn.DataTable || !document.querySelector(selector)) {
        return null;
    }
    return jQuery(selector).DataTable(Object.assign({
        order: [],
        language: {
            emptyTable: "Sem registos.",
            lengthMenu: "_MENU_",
            search: "Pesquisa:",
            info: "A mostrar _START_ a _END_ de _TOTAL_ registos",
            infoEmpty: "A mostrar 0 registos",
            infoFiltered: "(filtrado de _MAX_ registos)",
            paginate: {
                first: "Primeiro",
                last: "Ultimo",
                next: "Seguinte",
                previous: "Anterior"
            }
        }
    }, options || {}));
}
initEfaturaTable("#efatura-companies-table", { columnDefs: [{ orderable: false, targets: [1, 3, 5] }] });

var efaturaDocumentsTable = initEfaturaTable("#efatura-documents-table", {
    serverSide: true,
    processing: true,
    order: [[0, "desc"]],
    pagingType: "full_numbers",
    dom: "<\"row\"<\"col-sm-6 col-12 d-flex align-items-center gap-2 efatura-documents-controls\"l<\"efatura-documents-status-slot\">><\"col-sm-6 col-12\"f>>rt<\"row\"<\"col-sm-6 col-12\"i><\"col-sm-6 col-12\"p>>",
    initComplete: function() {
        var slot = document.querySelector("#efatura-documents-table_wrapper .efatura-documents-status-slot");
        if (!slot || slot.querySelector("#efatura-document-status-filter")) {
            return;
        }
        slot.innerHTML = "<div class=\"efatura-documents-status-filter\"><label for=\"efatura-document-status-filter\">Estado</label><select id=\"efatura-document-status-filter\" class=\"form-control\"><option value=\"\">Todos</option><option value=\"A\">Anulado</option></select></div>";
        slot.querySelector("#efatura-document-status-filter").addEventListener("change", function() {
            if (efaturaDocumentsTable && typeof efaturaDocumentsTable.draw === "function") {
                efaturaDocumentsTable.draw();
            }
        });
    },
    ajax: {
        url: window.efaturaDocumentsDataUrl,
        data: function(d) {
            var statusFilter = document.querySelector("#efatura-document-status-filter");
            d.status_filter = statusFilter ? String(statusFilter.value || "").trim() : "";
        }
    },
    columns: [
        { data: "invoice_date" },
        { data: "issuer_name" },
        { data: "issuer_vat" },
        { data: "invoice_no" },
        { data: "invoice_type" },
        { data: "net_total" },
        { data: "tax_payable" },
        { data: "gross_total" }
    ]
});
initEfaturaTable("#efatura-jobs-table", { order: [[0, "desc"]], columnDefs: [{ orderable: false, targets: [3, 4, 6] }] });
initEfaturaTable("#efatura-jobs-mini-table", { order: [[0, "desc"]], columnDefs: [{ orderable: false, targets: [4, 5] }] });

function refreshEfaturaJobs() {
    var rows = document.querySelectorAll("[data-job-id]");
    if (!rows.length || !window.fetch) {
        return;
    }
    rows.forEach(function(row) {
        var jobId = row.getAttribute("data-job-id");
        fetch(window.efaturaSyncStatusUrl + "?job_id=" + encodeURIComponent(jobId), { credentials: "same-origin" })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (!data || !data.ok) {
                    return;
                }
                row.querySelectorAll(".efatura-job-status").forEach(function(badge) {
                    badge.textContent = data.job.status || "";
                    badge.className = "badge efatura-job-status badge-" + (data.job.badge || "secondary");
                });
            })
            .catch(function() {});
    });
}
setInterval(refreshEfaturaJobs, 10000);
refreshEfaturaJobs();

function bindEfaturaJobLogButtons() {
    var modalEl = document.getElementById("efaturaJobLogModal");
    var metaEl = document.getElementById("efaturaJobLogMeta");
    var contentEl = document.getElementById("efaturaJobLogContent");
    if (!modalEl || !metaEl || !contentEl || !window.fetch) {
        return;
    }
    var activeJobId = null;
    var modalRefreshTimer = null;
    var openModal = function () {
        if (window.jQuery && jQuery.fn.modal) {
            jQuery(modalEl).modal("show");
        } else if (window.bootstrap && typeof window.bootstrap.Modal === "function") {
            new window.bootstrap.Modal(modalEl).show();
        }
    };
    var closeRefresh = function () {
        if (modalRefreshTimer) {
            clearTimeout(modalRefreshTimer);
            modalRefreshTimer = null;
        }
    };
    var isModalVisible = function () {
        return modalEl.classList.contains("in") || modalEl.classList.contains("show");
    };
    var loadJobLog = function (jobId) {
        if (!jobId) {
            return;
        }
        activeJobId = jobId;
        fetch(window.efaturaSyncStatusUrl + "?job_id=" + encodeURIComponent(jobId), { credentials: "same-origin" })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.ok || !data.job) {
                    contentEl.textContent = "Nao foi possivel carregar o log.";
                    closeRefresh();
                    return;
                }
                metaEl.textContent = "Job #" + (data.job.id || jobId) + " | Estado: " + (data.job.status || "-") + " | Atualizado: " + (data.job.updated_at || "-");
                contentEl.textContent = data.job.log_text || data.job.error_message || "Sem log disponivel.";
                closeRefresh();
                if (isModalVisible() && ["queued", "running"].indexOf(String(data.job.status || "")) !== -1) {
                    modalRefreshTimer = setTimeout(function () {
                        loadJobLog(jobId);
                    }, 3000);
                }
            })
            .catch(function () {
                contentEl.textContent = "Erro ao carregar o log.";
                closeRefresh();
            });
    };
    document.querySelectorAll(".efatura-job-log-btn").forEach(function (button) {
        button.addEventListener("click", function () {
            var jobId = button.getAttribute("data-job-id");
            metaEl.textContent = "Job #" + jobId;
            contentEl.textContent = "A carregar...";
            openModal();
            loadJobLog(jobId);
        });
    });
    if (window.jQuery) {
        jQuery(modalEl).on("hidden.bs.modal", function () {
            activeJobId = null;
            closeRefresh();
        });
    }
}
bindEfaturaJobLogButtons();
';

require_once __DIR__ . '/../footer.php';

function handleEfaturaSyncStatus(PDO $pdo): void {
    header('Content-Type: application/json; charset=utf-8');
    if (!efaturaTablesReady()) {
        echo json_encode(['ok' => false, 'error' => 'schema_missing']);
        exit;
    }
    $jobId = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;
    if ($jobId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'job_id_missing']);
        exit;
    }
    $job = efaturaFetchJob($pdo, $jobId);
    if (!$job) {
        echo json_encode(['ok' => false, 'error' => 'job_not_found']);
        exit;
    }
    $job = efaturaRefreshJobFromArtifact($pdo, $job);
    echo json_encode([
        'ok' => true,
        'job' => [
            'id' => (int) $job['id'],
            'status' => (string) $job['status'],
            'badge' => efaturaStatusBadge((string) $job['status']),
            'documents_found' => (int) $job['documents_found'],
            'documents_saved' => (int) $job['documents_saved'],
            'updated_at' => (string) ($job['updated_at'] ?? ''),
            'error_message' => (string) ($job['error_message'] ?? ''),
            'log_text' => efaturaBuildJobLogText($job),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function efaturaBuildJobLogText(array $job): string {
    $parts = [];
    $artifact = trim((string) ($job['debug_artifact'] ?? ''));
    if ($artifact !== '' && is_file($artifact)) {
        $decoded = json_decode((string) @file_get_contents($artifact), true);
        if (is_array($decoded)) {
            if (!empty($decoded['last_step'])) {
                $parts[] = 'Ultimo passo: ' . (string) $decoded['last_step'];
            }
            if (!empty($decoded['error_message'])) {
                $parts[] = 'Erro: ' . (string) $decoded['error_message'];
            }
            $steps = $decoded['debug']['steps'] ?? [];
            if (is_array($steps) && $steps) {
                $parts[] = '';
                $parts[] = 'Passos:';
                foreach ($steps as $step) {
                    if (!is_array($step)) {
                        continue;
                    }
                    $parts[] = '- ' . trim((string) ($step['at'] ?? '')) . ' ' . trim((string) ($step['message'] ?? ''));
                }
            }
            foreach (['http_json_sample', 'json_raw_sample', 'http_login_sample', 'html_snapshot', 'screenshot'] as $key) {
                if (!empty($decoded['debug'][$key])) {
                    $parts[] = '';
                    $parts[] = strtoupper($key) . ':';
                    $parts[] = (string) $decoded['debug'][$key];
                }
            }
        }
    }
    if (!$parts && !empty($job['error_message'])) {
        $parts[] = (string) $job['error_message'];
    }
    return trim(implode("\n", $parts));
}

function efaturaTablesReady(): bool {
    foreach ([
        'efatura_company_credentials',
        'efatura_sync_jobs',
        'efatura_documents',
        'efatura_document_lines',
        'efatura_sync_logs',
    ] as $table) {
        if (!hasTable($table)) {
            return false;
        }
    }
    return true;
}

function efaturaEncryptionReady(): bool {
    return function_exists('openssl_encrypt') && trim((string) getenv('EFATURA_SECRET_KEY')) !== '';
}

function getEfaturaEncryptionKey(): string {
    return trim((string) getenv('EFATURA_SECRET_KEY'));
}

function encryptEfaturaSecret(string $plaintext): string {
    $key = getEfaturaEncryptionKey();
    if ($key === '') {
        throw new RuntimeException('EFATURA_SECRET_KEY nao configurada.');
    }
    $iv = random_bytes(16);
    $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($ciphertext === false) {
        throw new RuntimeException('Falha ao cifrar a credencial.');
    }
    return base64_encode($iv . $ciphertext);
}

function decryptEfaturaSecret(string $ciphertext): string {
    $key = getEfaturaEncryptionKey();
    if ($key === '') {
        throw new RuntimeException('EFATURA_SECRET_KEY nao configurada.');
    }
    $binary = base64_decode($ciphertext, true);
    if ($binary === false || strlen($binary) <= 16) {
        throw new RuntimeException('Credencial cifrada invalida.');
    }
    $iv = substr($binary, 0, 16);
    $payload = substr($binary, 16);
    $plaintext = openssl_decrypt($payload, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
    if ($plaintext === false) {
        throw new RuntimeException('Nao foi possivel descifrar a credencial.');
    }
    return $plaintext;
}

function saveEfaturaCredential(PDO $pdo, array $user): void {
    $entityId = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
    if ($entityId <= 0) {
        throw new RuntimeException('Empresa invalida.');
    }
    $label = trim((string) ($_POST['credential_label'] ?? 'Portal E-fatura'));
    $username = trim((string) ($_POST['portal_username'] ?? ''));
    $password = (string) ($_POST['portal_password'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    if ($username === '') {
        throw new RuntimeException('Indica o utilizador do portal.');
    }
    $existing = efaturaFetchCredential($pdo, $entityId);
    $encrypted = $existing['portal_password_encrypted'] ?? '';
    if ($password !== '') {
        $encrypted = encryptEfaturaSecret($password);
    }
    if ($encrypted === '') {
        throw new RuntimeException('Indica a password inicial da credencial.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO efatura_company_credentials (entity_id, credential_label, portal_username, portal_password_encrypted, is_active)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE credential_label = VALUES(credential_label), portal_username = VALUES(portal_username), portal_password_encrypted = VALUES(portal_password_encrypted), is_active = VALUES(is_active), updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$entityId, $label, $username, $encrypted, $isActive]);
    logAuditAction('efatura_credentials_save', 'efatura_company_credentials', $existing ? (int) $existing['id'] : (int) $pdo->lastInsertId(), [
        'entity_id' => $entityId,
        'saved_by' => (int) ($user['id'] ?? 0),
    ]);
}

function createEfaturaSyncJob(PDO $pdo, array $user): int {
    $entityId = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
    $periodStart = trim((string) ($_POST['period_start'] ?? ''));
    $periodEnd = trim((string) ($_POST['period_end'] ?? ''));
    if ($entityId <= 0) {
        throw new RuntimeException('Empresa invalida.');
    }
    if ($periodStart === '' || $periodEnd === '') {
        throw new RuntimeException('Indica o periodo da sincronizacao.');
    }
    if ($periodStart > $periodEnd) {
        throw new RuntimeException('A data inicio nao pode ser superior a data fim.');
    }
    $credential = efaturaFetchCredential($pdo, $entityId);
    if (!$credential || (int) ($credential['is_active'] ?? 0) !== 1) {
        throw new RuntimeException('Nao existe credencial ativa para esta empresa.');
    }

    $artifactDir = __DIR__ . '/../data/efatura_jobs';
    if (!is_dir($artifactDir) && !mkdir($artifactDir, 0775, true) && !is_dir($artifactDir)) {
        throw new RuntimeException('Nao foi possivel criar a pasta de jobs E-fatura.');
    }

    $stmt = $pdo->prepare(
        'INSERT INTO efatura_sync_jobs (entity_id, credential_id, requested_by, sync_mode, period_start, period_end, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$entityId, (int) $credential['id'], (int) ($user['id'] ?? 0), 'manual', $periodStart, $periodEnd, 'queued']);
    $jobId = (int) $pdo->lastInsertId();
    $artifactPath = $artifactDir . '/job_' . $jobId . '.json';
    $update = $pdo->prepare('UPDATE efatura_sync_jobs SET debug_artifact = ?, status = ?, started_at = NOW() WHERE id = ?');
    $update->execute([$artifactPath, 'running', $jobId]);

    $company = efaturaFetchEntity($pdo, $entityId);
    launchEfaturaWorker($jobId, $artifactPath, $company, $credential, $periodStart, $periodEnd);
    logAuditAction('efatura_sync_create', 'efatura_sync_jobs', $jobId, [
        'entity_id' => $entityId,
        'period_start' => $periodStart,
        'period_end' => $periodEnd,
    ]);

    return $jobId;
}

function launchEfaturaWorker(int $jobId, string $artifactPath, ?array $company, array $credential, string $periodStart, string $periodEnd): void {
    $python = trim((string) @shell_exec('command -v python3 2>/dev/null'));
    if ($python === '') {
        throw new RuntimeException('python3 nao encontrado no servidor.');
    }
    $script = __DIR__ . '/efatura_worker.py';
    if (!is_file($script)) {
        throw new RuntimeException('Worker E-fatura nao encontrado.');
    }
    $portalPassword = decryptEfaturaSecret((string) ($credential['portal_password_encrypted'] ?? ''));
    $envPrefix = 'EFATURA_PORTAL_PASSWORD=' . escapeshellarg($portalPassword) . ' ';
    $cmd = escapeshellarg($python)
        . ' ' . escapeshellarg($script)
        . ' --job-id ' . escapeshellarg((string) $jobId)
        . ' --artifact ' . escapeshellarg($artifactPath)
        . ' --company-name ' . escapeshellarg((string) ($company['name'] ?? ''))
        . ' --company-vat ' . escapeshellarg((string) ($company['nif'] ?? ''))
        . ' --portal-username ' . escapeshellarg((string) ($credential['portal_username'] ?? ''))
        . ' --period-start ' . escapeshellarg($periodStart)
        . ' --period-end ' . escapeshellarg($periodEnd)
        . ' > /dev/null 2>&1 &';
    @exec($envPrefix . $cmd);
}

function efaturaFetchCompanies(PDO $pdo): array {
    $sql = "SELECT ae.id, ae.name, ae.nif, ae.erp_database,
                   ecc.portal_username, ecc.is_active,
                   last_job.finished_at AS last_sync_at
            FROM accounting_entities ae
            LEFT JOIN efatura_company_credentials ecc ON ecc.entity_id = ae.id
            LEFT JOIN (
                SELECT j1.entity_id, MAX(COALESCE(j1.finished_at, j1.updated_at, j1.created_at)) AS finished_at
                FROM efatura_sync_jobs j1
                GROUP BY j1.entity_id
            ) last_job ON last_job.entity_id = ae.id
            WHERE ae.entity_type = 'acquirer'
            ORDER BY ae.name ASC, ae.nif ASC";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function efaturaFetchEntity(PDO $pdo, int $entityId): ?array {
    $stmt = $pdo->prepare("SELECT id, name, nif, erp_database FROM accounting_entities WHERE id = ? AND entity_type = 'acquirer' LIMIT 1");
    $stmt->execute([$entityId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function efaturaFormatErpDatabase(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '-';
    }
    if (preg_match('/^emp_(\d+)$/i', $value, $matches)) {
        return $matches[1];
    }
    return $value;
}

function efaturaFetchCredential(PDO $pdo, int $entityId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM efatura_company_credentials WHERE entity_id = ? LIMIT 1');
    $stmt->execute([$entityId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function efaturaFetchDocuments(PDO $pdo, int $entityId = 0): array {
    $sql = "SELECT d.*, ae.name AS entity_name
            FROM efatura_documents d
            JOIN accounting_entities ae ON ae.id = d.entity_id";
    $params = [];
    if ($entityId > 0) {
        $sql .= " WHERE d.entity_id = ?";
        $params[] = $entityId;
    }
    $sql .= " ORDER BY d.invoice_date DESC, d.id DESC LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function handleEfaturaDocumentsData(PDO $pdo, int $selectedEntityId): void {
    header('Content-Type: application/json; charset=utf-8');

    $draw = (int) ($_GET['draw'] ?? 1);
    $start = max(0, (int) ($_GET['start'] ?? 0));
    $length = (int) ($_GET['length'] ?? 10);
    if ($length <= 0) {
        $length = 10;
    }
    if ($length > 500) {
        $length = 500;
    }

    $searchValue = trim((string) ($_GET['search']['value'] ?? ''));
    $statusFilter = strtoupper(trim((string) ($_GET['status_filter'] ?? '')));
    $orderColumn = (int) ($_GET['order'][0]['column'] ?? 0);
    $orderDir = strtolower(trim((string) ($_GET['order'][0]['dir'] ?? 'desc'))) === 'asc' ? 'ASC' : 'DESC';

    $orderableColumns = [
        0 => 'd.invoice_date',
        1 => 'd.issuer_name',
        2 => 'd.issuer_vat',
        3 => 'd.invoice_no',
        4 => 'd.invoice_type',
        5 => 'd.net_total',
        6 => 'd.tax_payable',
        7 => 'd.gross_total',
    ];
    $orderBy = $orderableColumns[$orderColumn] ?? 'd.invoice_date';

    $baseFrom = ' FROM efatura_documents d JOIN accounting_entities ae ON ae.id = d.entity_id ';
    $where = [];
    $params = [];

    if ($selectedEntityId > 0) {
        $where[] = 'd.entity_id = ?';
        $params[] = $selectedEntityId;
    }

    if ($statusFilter === 'A') {
        $where[] = 'UPPER(COALESCE(d.document_status, "")) = ?';
        $params[] = $statusFilter;
    }

    if ($searchValue !== '') {
        $where[] = '(d.invoice_date LIKE ? OR ae.name LIKE ? OR d.issuer_name LIKE ? OR d.issuer_vat LIKE ? OR d.invoice_no LIKE ? OR d.invoice_type LIKE ?)';
        $searchTerm = '%' . $searchValue . '%';
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    $totalSql = 'SELECT COUNT(*)' . $baseFrom . ($selectedEntityId > 0 ? ' WHERE d.entity_id = ?' : '');
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($selectedEntityId > 0 ? [$selectedEntityId] : []);
    $recordsTotal = (int) $totalStmt->fetchColumn();

    $filteredSql = 'SELECT COUNT(*)' . $baseFrom . $whereSql;
    $filteredStmt = $pdo->prepare($filteredSql);
    $filteredStmt->execute($params);
    $recordsFiltered = (int) $filteredStmt->fetchColumn();

    $dataSql = 'SELECT d.*, ae.name AS entity_name'
        . $baseFrom
        . $whereSql
        . ' ORDER BY ' . $orderBy . ' ' . $orderDir . ', d.id ' . $orderDir
        . ' LIMIT ' . (int) $length . ' OFFSET ' . (int) $start;
    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $data = [];
    foreach ($rows as $row) {
        $status = strtoupper(trim((string) ($row['document_status'] ?? '')));
        $data[] = [
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'issuer_name' => (string) ($row['issuer_name'] ?? ''),
            'issuer_vat' => (string) ($row['issuer_vat'] ?? ''),
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_type' => (string) ($row['invoice_type'] ?? ''),
            'net_total' => number_format((float) ($row['net_total'] ?? 0), 2, ',', ' '),
            'tax_payable' => number_format((float) ($row['tax_payable'] ?? 0), 2, ',', ' '),
            'gross_total' => number_format((float) ($row['gross_total'] ?? 0), 2, ',', ' '),
            'DT_RowClass' => $status === 'A' ? 'efatura-document-row-cancelled' : '',
            'DT_RowAttr' => ['data-status' => $status],
        ];
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function efaturaFetchJobs(PDO $pdo, int $entityId = 0, int $limit = 20): array {
    $sql = "SELECT j.*, ae.name AS entity_name
            FROM efatura_sync_jobs j
            JOIN accounting_entities ae ON ae.id = j.entity_id";
    $params = [];
    if ($entityId > 0) {
        $sql .= " WHERE j.entity_id = ?";
        $params[] = $entityId;
    }
    $sql .= " ORDER BY j.id DESC LIMIT " . max(1, (int) $limit);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row = efaturaRefreshJobFromArtifact($pdo, $row);
    }
    unset($row);
    return $rows;
}

function efaturaFetchJob(PDO $pdo, int $jobId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM efatura_sync_jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function efaturaRefreshJobFromArtifact(PDO $pdo, array $job): array {
    efaturaPruneExpiredArtifacts($pdo);
    $artifact = trim((string) ($job['debug_artifact'] ?? ''));
    if ($artifact === '' || !is_file($artifact)) {
        return $job;
    }
    $raw = @file_get_contents($artifact);
    if ($raw === false || trim($raw) === '') {
        return $job;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $job;
    }
    if (
        ($decoded['status'] ?? '') === 'done'
        && !empty($decoded['documents'])
        && empty($decoded['ingested_at'])
    ) {
        $ingestResult = efaturaPersistDocumentsFromArtifact($pdo, (int) $job['entity_id'], (int) $job['id'], $decoded['documents']);
        $decoded['documents_found'] = (int) ($decoded['documents_found'] ?? count($decoded['documents']));
        $decoded['documents_saved'] = (int) $ingestResult['saved'];
        $decoded['ingested_at'] = date('c');
        @file_put_contents($artifact, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    $status = trim((string) ($decoded['status'] ?? ''));
    if ($status === '' || $status === (string) ($job['status'] ?? '')) {
        return $job;
    }
    $documentsFound = isset($decoded['documents_found']) ? (int) $decoded['documents_found'] : (int) ($job['documents_found'] ?? 0);
    $documentsSaved = isset($decoded['documents_saved']) ? (int) $decoded['documents_saved'] : (int) ($job['documents_saved'] ?? 0);
    $errorMessage = trim((string) ($decoded['error_message'] ?? ''));
    $stmt = $pdo->prepare(
        'UPDATE efatura_sync_jobs
         SET status = ?, documents_found = ?, documents_saved = ?, error_message = ?, finished_at = CASE WHEN ? IN ("done", "failed", "partial") THEN NOW() ELSE finished_at END, updated_at = CURRENT_TIMESTAMP
         WHERE id = ?'
    );
    $stmt->execute([$status, $documentsFound, $documentsSaved, $errorMessage !== '' ? $errorMessage : null, $status, (int) $job['id']]);
    $job['status'] = $status;
    $job['documents_found'] = $documentsFound;
    $job['documents_saved'] = $documentsSaved;
    $job['error_message'] = $errorMessage;
    $job['updated_at'] = date('Y-m-d H:i:s');
    if (in_array($status, ['done', 'failed', 'partial'], true)) {
        $job['finished_at'] = date('Y-m-d H:i:s');
    }
    return $job;
}

function efaturaPruneExpiredArtifacts(PDO $pdo): void {
    static $alreadyRan = false;
    if ($alreadyRan) {
        return;
    }
    $alreadyRan = true;

    $retentionDays = (int) getSetting('efatura_artifact_retention_days', '15');
    if ($retentionDays <= 0) {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT id, debug_artifact
         FROM efatura_sync_jobs
         WHERE status IN ("done", "failed", "partial")
           AND debug_artifact IS NOT NULL
           AND debug_artifact <> ""
           AND COALESCE(finished_at, updated_at, created_at) < (NOW() - INTERVAL ? DAY)
         LIMIT 100'
    );
    $stmt->execute([$retentionDays]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$jobs) {
        return;
    }

    $clearStmt = $pdo->prepare('UPDATE efatura_sync_jobs SET debug_artifact = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    foreach ($jobs as $jobRow) {
        $artifactPath = trim((string) ($jobRow['debug_artifact'] ?? ''));
        if ($artifactPath === '') {
            continue;
        }
        efaturaDeleteArtifactFiles($artifactPath);
        $clearStmt->execute([(int) $jobRow['id']]);
    }
}

function efaturaDeleteArtifactFiles(string $artifactPath): void {
    $base = preg_replace('/\.[^.]+$/', '', $artifactPath);
    $candidates = array_unique([
        $artifactPath,
        $base . '.json',
        $base . '.html',
        $base . '.png',
    ]);
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
            @unlink($candidate);
        }
    }
}

function efaturaPersistDocumentsFromArtifact(PDO $pdo, int $entityId, int $jobId, array $documents): array {
    if ($entityId <= 0 || !$documents) {
        return ['saved' => 0];
    }

    $saved = 0;
    $insertDocument = $pdo->prepare(
        'INSERT INTO efatura_documents
        (entity_id, sync_job_id, issuer_vat, issuer_name, customer_vat, invoice_no, atcud, invoice_date, invoice_type, document_status, sector, tax_payable, net_total, gross_total, source_hash, raw_payload_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            sync_job_id = VALUES(sync_job_id),
            issuer_name = VALUES(issuer_name),
            customer_vat = VALUES(customer_vat),
            atcud = VALUES(atcud),
            invoice_type = VALUES(invoice_type),
            document_status = VALUES(document_status),
            sector = VALUES(sector),
            tax_payable = VALUES(tax_payable),
            net_total = VALUES(net_total),
            gross_total = VALUES(gross_total),
            raw_payload_json = VALUES(raw_payload_json),
            updated_at = CURRENT_TIMESTAMP'
    );
    $selectDocument = $pdo->prepare('SELECT id FROM efatura_documents WHERE entity_id = ? AND source_hash = ? LIMIT 1');
    $deleteLines = $pdo->prepare('DELETE FROM efatura_document_lines WHERE document_id = ?');
    $insertLine = $pdo->prepare(
        'INSERT INTO efatura_document_lines
        (document_id, tax_point_date, debit_credit_indicator, tax_amount, net_amount, gross_amount, total_tax_base, tax_type, tax_country_region, tax_code, tax_percentage, total_tax_amount, tax_exemption_code)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($documents as $document) {
        if (!is_array($document)) {
            continue;
        }
        $invoiceNo = trim((string) ($document['invoice_no'] ?? ''));
        $invoiceDate = normalizeEfaturaDate((string) ($document['invoice_date'] ?? ''));
        $sourceHash = trim((string) ($document['source_hash'] ?? ''));
        if ($invoiceNo === '' || $invoiceDate === '' || $sourceHash === '') {
            continue;
        }
        $invoiceNo = limitEfaturaText($invoiceNo, 60);

        $insertDocument->execute([
            $entityId,
            $jobId,
            limitEfaturaText(trim((string) ($document['issuer_vat'] ?? '')), 30),
            limitEfaturaText(trim((string) ($document['issuer_name'] ?? '')), 255),
            limitEfaturaText(trim((string) ($document['customer_vat'] ?? '')), 30),
            $invoiceNo,
            limitEfaturaText(trim((string) ($document['atcud'] ?? '')), 100),
            $invoiceDate,
            limitEfaturaText(trim((string) ($document['invoice_type'] ?? '')), 10),
            limitEfaturaText(trim((string) ($document['document_status'] ?? '')), 10),
            limitEfaturaText(trim((string) ($document['sector'] ?? '')), 10),
            normalizeEfaturaDecimal($document['tax_payable'] ?? 0),
            normalizeEfaturaDecimal($document['net_total'] ?? 0),
            normalizeEfaturaDecimal($document['gross_total'] ?? 0),
            limitEfaturaText($sourceHash, 64),
            json_encode($document, JSON_UNESCAPED_UNICODE),
        ]);

        $selectDocument->execute([$entityId, $sourceHash]);
        $documentId = (int) $selectDocument->fetchColumn();
        if ($documentId <= 0) {
            continue;
        }

        $deleteLines->execute([$documentId]);
        foreach (($document['lines'] ?? []) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $insertLine->execute([
                $documentId,
                normalizeEfaturaNullableDate((string) ($line['tax_point_date'] ?? '')),
                limitEfaturaText(trim((string) ($line['debit_credit_indicator'] ?? '')), 2),
                normalizeEfaturaDecimal($line['tax_amount'] ?? 0),
                normalizeEfaturaDecimal($line['net_amount'] ?? 0),
                normalizeEfaturaDecimal($line['gross_amount'] ?? 0),
                normalizeEfaturaDecimal($line['total_tax_base'] ?? 0),
                limitEfaturaText(trim((string) ($line['tax_type'] ?? '')), 10),
                limitEfaturaText(trim((string) ($line['tax_country_region'] ?? '')), 10),
                limitEfaturaText(trim((string) ($line['tax_code'] ?? '')), 20),
                normalizeEfaturaDecimal($line['tax_percentage'] ?? 0),
                normalizeEfaturaDecimal($line['total_tax_amount'] ?? 0),
                limitEfaturaText(trim((string) ($line['tax_exemption_code'] ?? '')), 20),
            ]);
        }
        $saved++;
    }

    $updateJob = $pdo->prepare('UPDATE efatura_sync_jobs SET documents_saved = ?, documents_found = GREATEST(documents_found, ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $updateJob->execute([$saved, count($documents), $jobId]);

    return ['saved' => $saved];
}

function normalizeEfaturaDate(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }
    return date('Y-m-d', $timestamp);
}

function normalizeEfaturaNullableDate(string $value): ?string {
    $normalized = normalizeEfaturaDate($value);
    return $normalized !== '' ? $normalized : null;
}

function normalizeEfaturaDecimal($value): string {
    if (is_string($value)) {
        $value = str_replace([' ', "\xc2\xa0"], '', $value);
        $hasComma = strpos($value, ',') !== false;
        $hasDot = strpos($value, '.') !== false;
        if ($hasComma && $hasDot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        }
        $value = preg_replace('/[^0-9.\-]/', '', (string) $value) ?? '0';
    }
    if (!is_numeric($value)) {
        $value = 0;
    }
    return number_format((float) $value, 2, '.', '');
}

function limitEfaturaText(string $value, int $maxLength): string {
    $value = trim($value);
    if ($maxLength <= 0 || $value === '') {
        return $maxLength <= 0 ? '' : $value;
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($value, 'UTF-8') <= $maxLength) {
            return $value;
        }
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return strlen($value) <= $maxLength ? $value : substr($value, 0, $maxLength);
}

function efaturaFetchStats(PDO $pdo): array {
    return [
        'companies' => (int) $pdo->query("SELECT COUNT(*) FROM accounting_entities WHERE entity_type = 'acquirer'")->fetchColumn(),
        'credentials' => (int) $pdo->query('SELECT COUNT(*) FROM efatura_company_credentials WHERE is_active = 1')->fetchColumn(),
        'documents' => (int) $pdo->query('SELECT COUNT(*) FROM efatura_documents')->fetchColumn(),
        'jobs_running' => (int) $pdo->query("SELECT COUNT(*) FROM efatura_sync_jobs WHERE status IN ('queued', 'running')")->fetchColumn(),
    ];
}

function maskEfaturaUsername(string $username): string {
    $username = trim($username);
    if ($username === '') {
        return '';
    }
    $length = strlen($username);
    if ($length <= 4) {
        return str_repeat('*', $length);
    }
    return substr($username, 0, 2) . str_repeat('*', max(0, $length - 4)) . substr($username, -2);
}

function deleteEfaturaCredential(PDO $pdo, array $user, int $entityId): void {
    if ($entityId <= 0) {
        throw new RuntimeException('Empresa invalida.');
    }
    $credential = efaturaFetchCredential($pdo, $entityId);
    if (!$credential) {
        throw new RuntimeException('Nao existem credenciais guardadas para esta empresa.');
    }
    $stmt = $pdo->prepare('DELETE FROM efatura_company_credentials WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $credential['id']]);
    logAuditAction('efatura_credentials_delete', 'efatura_company_credentials', (int) $credential['id'], [
        'entity_id' => $entityId,
        'deleted_by' => (int) ($user['id'] ?? 0),
    ]);
}

function efaturaStatusBadge(string $status): string {
    switch ($status) {
        case 'done':
            return 'success';
        case 'failed':
            return 'danger';
        case 'partial':
            return 'warning';
        case 'running':
            return 'info';
        default:
            return 'secondary';
    }
}
