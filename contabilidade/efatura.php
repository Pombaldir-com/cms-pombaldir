<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

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
$useDateRangePicker = true;
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

if ($action === 'missing_docs_preview') {
    handleEfaturaMissingDocsPreview($pdo, $selectedEntityId, $user);
}

$tablesReady = efaturaTablesReady();
$canManageCredentials = userHasDepartmentPermission('ctb_efatura_credenciais');
$canSync = userHasDepartmentPermission('ctb_efatura_sincronizar');
$canRunMigrations = ((int) ($user['role'] ?? 3) === 1);
$flash = ['type' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['csrf_token'] ?? ''));
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        exit('Token invalido.');
    }

    $postAction = trim((string) ($_POST['action'] ?? ''));
    if (!$tablesReady) {
        $flash = ['type' => 'error', 'message' => 'As tabelas do modulo E-fatura ainda nao existem nesta base de dados.'];
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
            $selectedEntity = (int) ($_POST['entity_id'] ?? 0);
            $_SESSION[$efaturaSelectionSessionKey] = $selectedEntity;
            $jobId = createEfaturaSyncJob($pdo, $user);
            header('Location: ' . BASE_URL . 'contabilidade/efatura/sincronizacoes?empresa=' . $selectedEntity . '&status=success&msg=' . rawurlencode('Sincronizacao criada. Job #' . $jobId . '.'));
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
    } elseif ($postAction === 'send_missing_docs_email') {
        handleEfaturaSendMissingDocsEmail($pdo, $selectedEntityId, $user);
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
        $erpDatabase = efaturaResolveEntityErpDatabase($entity);
        $companyCode = '';
        if (preg_match('/^emp[_-]?(\d+)$/i', $erpDatabase, $matches)) {
            $companyCode = ltrim($matches[1], '0');
            if ($companyCode === '') {
                $companyCode = '0';
            }
        }
        $entityName = (string) ($entity['name'] ?? '');
        $label = $entityName;
        if ($companyCode !== '') {
            $label = $companyCode . ($entityName !== '' ? ' - ' . $entityName : '');
        } elseif ($erpDatabase !== '') {
            $label = $erpDatabase . ($entityName !== '' ? ' - ' . $entityName : '');
        } elseif ($entityName === '') {
            $label = (string) ($entity['id'] ?? '');
        }
        return [
            'id' => (int) ($entity['id'] ?? 0),
            'name' => $entityName,
            'label' => $label,
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
                        <div class="d-flex justify-content-between align-items-start gap-3">
                            <div>
                                <strong>Modulo E-fatura por ativar</strong><br>
                                <span>As tabelas do modulo E-fatura ainda nao existem nesta base de dados.</span>
                                <?php if (!$canRunMigrations): ?>
                                    <br>
                                    <span>Um superadmin deve executar as migracoes pela interface para ativar o modulo.</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canRunMigrations): ?>
                                <form method="post" action="<?= BASE_URL ?>system/run-migrations" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i class="fa fa-refresh"></i> Executar migracoes
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
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
                                        <span class="efatura-selection-meta">NIF <?= htmlspecialchars((string) $selectedEntity['nif']); ?> · <?= htmlspecialchars(efaturaFormatErpDatabase(efaturaResolveEntityErpDatabase($selectedEntity))); ?></span>
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
                                            <td><span class="badge badge-default"><?= htmlspecialchars(efaturaFormatErpDatabase(efaturaResolveEntityErpDatabase($entity))); ?></span></td>
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
                                                <span class="badge badge-primary"><?= htmlspecialchars(efaturaFormatErpDatabase(efaturaResolveEntityErpDatabase($selectedEntity))); ?></span>
                                            </div>
                                            <div class="row efatura-company-meta">
                                                <div class="col-sm-6 col-12">
                                                    <span class="efatura-meta-label">NIF</span>
                                                    <strong><?= htmlspecialchars((string) $selectedEntity['nif']); ?></strong>
                                                </div>
                                                <div class="col-sm-6 col-12">
                                                    <span class="efatura-meta-label">Base ERP</span>
                                                    <strong><?= htmlspecialchars(efaturaFormatErpDatabase(efaturaResolveEntityErpDatabase($selectedEntity))); ?></strong>
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
                                <th>Upload</th>
                                <th>Classificação</th>
                                <th>Contabilidade</th>
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
                                    <td data-order="<?= (int) $job['id']; ?>">#<?= (int) $job['id']; ?></td>
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
                                    <td data-order="<?= (int) $job['id']; ?>">#<?= (int) $job['id']; ?></td>
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
<div class="modal fade" id="efaturaMissingDocsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Enviar faltas de documentos</h4>
                <button type="button" class="close" data-dismiss="modal" data-bs-dismiss="modal" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="efaturaMissingDocsAlert" class="alert" role="alert" style="display:none;"></div>
                <div class="alert alert-info" role="alert">
                    A mensagem é gerada com os dados da empresa selecionada e com a lista de documentos do E-fatura que continuam sem upload.
                </div>
                <div id="efaturaMissingDocsSummary" class="text-muted" style="margin-bottom:14px;"></div>
                <form id="efaturaMissingDocsForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="send_missing_docs_email">
                    <div class="row">
                        <div class="col-sm-6 col-12">
                            <div class="form-group">
                                <label for="efaturaMissingDocsTo">Para</label>
                                <input type="text" class="form-control" id="efaturaMissingDocsTo" name="to" autocomplete="off">
                            </div>
                        </div>
                        <div class="col-sm-6 col-12">
                            <div class="form-group">
                                <label for="efaturaMissingDocsFrom">Remetente</label>
                                <select class="form-control" id="efaturaMissingDocsFrom" name="from_email"></select>
                            </div>
                        </div>
                        <div class="col-sm-12 col-12">
                            <div class="form-group">
                                <label for="efaturaMissingDocsReplyTo">Responder para</label>
                                <input type="text" class="form-control" id="efaturaMissingDocsReplyTo" name="reply_to" autocomplete="off">
                            </div>
                        </div>
                        <div class="col-sm-12 col-12">
                            <div class="form-group">
                                <label for="efaturaMissingDocsSubject">Assunto</label>
                                <input type="text" class="form-control" id="efaturaMissingDocsSubject" name="subject" autocomplete="off">
                            </div>
                        </div>
                        <div class="col-sm-12 col-12">
                            <div class="form-group" style="margin-bottom:0;">
                                <label for="efaturaMissingDocsBody">Mensagem</label>
                                <textarea class="form-control" id="efaturaMissingDocsBody" name="body" rows="16"></textarea>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning" id="efaturaMissingDocsSendBtn">
                    <i class="fa fa-paper-plane"></i> Enviar
                </button>
            </div>
        </div>
    </div>
</div>
<?php
$pageScripts = 'var efaturaStyle = document.createElement("style");
efaturaStyle.textContent = ".efatura-side-panel .x_content{padding:20px;}.efatura-form-grid>div{margin-bottom:15px;}.efatura-form-grid>div:last-child{margin-bottom:0;}.efatura-checkbox{margin:0;}.efatura-checkbox label{margin-bottom:0;font-weight:600;color:#4f6278;}.efatura-company-card{background:#f8fafc;border:1px solid #d8e2ee;border-radius:10px;padding:18px 18px;}.efatura-company-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:14px;}.efatura-company-card h4{margin:0;line-height:1.35;color:#506784;}.efatura-company-card .badge{background:#e8f1fb !important;color:#35506d !important;border:1px solid #c7d8eb;}.efatura-company-meta{row-gap:12px;}.efatura-company-meta strong{color:#5b738e;}.efatura-meta-label{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#7d8fa4;margin-bottom:4px;}.efatura-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:16px;}.efatura-inline-form{margin:0;}.efatura-sync-note{color:#73879c;font-size:12px;}.efatura-page .x_title .panel_toolbox{min-width:auto;}#efatura-documents-table th:first-child,#efatura-documents-table td:first-child{white-space:nowrap;width:1%;}#efatura-documents-table_wrapper .row:first-child{display:flex;align-items:center;justify-content:space-between;}#efatura-documents-table_wrapper .dt-search,#efatura-documents-table_wrapper .dataTables_filter{margin-left:auto;}#efatura-documents-table_wrapper .efatura-documents-controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}#efatura-documents-table_wrapper .dt-length,#efatura-documents-table_wrapper .dataTables_length{margin:0;}#efatura-documents-table_wrapper .dt-length label,#efatura-documents-table_wrapper .dataTables_length label{margin:0;display:flex;align-items:center;gap:8px;}#efatura-documents-table_wrapper .dt-layout-end,#efatura-documents-table_wrapper .dt-paging,#efatura-documents-table_wrapper .dataTables_paginate{margin-top:10px;}#efatura-documents-table_wrapper .row:last-child{display:flex;align-items:center;justify-content:space-between;}#efatura-documents-table_wrapper .row:last-child .col-sm-6:last-child,#efatura-documents-table_wrapper .row:last-child .col-12:last-child{text-align:right;}#efatura-documents-table_wrapper .dt-paging,#efatura-documents-table_wrapper .dataTables_paginate{display:flex;justify-content:flex-end;}#efatura-documents-table_wrapper .dt-paging .pagination,#efatura-documents-table_wrapper .dataTables_paginate .pagination{gap:0;margin:0;justify-content:flex-end;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button.page-item,#efatura-documents-table_wrapper .dataTables_paginate .page-item{margin:0 3px;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button .page-link,#efatura-documents-table_wrapper .dataTables_paginate .page-link{padding:6px 9px !important;background:#ddd !important;border:1px solid #ddd !important;color:#73879c !important;border-radius:5px !important;box-shadow:none !important;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button.active .page-link,#efatura-documents-table_wrapper .dt-paging .dt-paging-button.active .page-link:hover,#efatura-documents-table_wrapper .dataTables_paginate .page-item.active .page-link,#efatura-documents-table_wrapper .dataTables_paginate .page-item.active .page-link:hover{background:#169f85 !important;border-color:#169f85 !important;color:#fff !important;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button .page-link:hover,#efatura-documents-table_wrapper .dataTables_paginate .page-link:hover{background:#ccc !important;border-color:#ccc !important;color:#2a3f54 !important;}#efatura-documents-table_wrapper .dt-paging .dt-paging-button.disabled .page-link,#efatura-documents-table_wrapper .dt-paging .dt-paging-button.disabled .page-link:hover,#efatura-documents-table_wrapper .dataTables_paginate .page-item.disabled .page-link,#efatura-documents-table_wrapper .dataTables_paginate .page-item.disabled .page-link:hover{background:#ddd !important;border-color:#ddd !important;color:#9aa7b4 !important;opacity:1;}#efatura-documents-table_wrapper .dt-paging .ellipsis,#efatura-documents-table_wrapper .dataTables_paginate .ellipsis{padding:6px 4px;color:#73879c;}#efatura-documents-table_wrapper .paging_full_numbers{width:auto;height:auto;line-height:normal;}.efatura-documents-status-filter,.efatura-documents-date-filter{display:flex;align-items:center;gap:8px;margin:0;}.efatura-documents-status-filter label,.efatura-documents-date-filter label{margin:0;font-weight:600;color:#5b738e;}.efatura-documents-status-filter .form-control{width:170px;min-width:170px;}.efatura-documents-date-filter .form-control{width:250px;min-width:250px;background:#fff;cursor:pointer;}.efatura-documents-date-filter .btn{white-space:nowrap;}.efatura-documents-missing-slot{display:flex;align-items:center;}.efatura-documents-missing-slot .btn{white-space:nowrap;}.efatura-document-row-cancelled td{background:#fbe9e7 !important;color:#7f2d2d !important;}.efatura-document-row-cancelled a,.efatura-document-row-cancelled span,.efatura-document-row-cancelled strong{color:inherit !important;}.efatura-document-row-missing td{background:#fff8e1 !important;}.efatura-selection-banner{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 16px;margin-bottom:16px;border:1px solid #d6e1ee;border-radius:10px;background:linear-gradient(135deg,#f8fbff 0%,#eef4fb 100%);}.efatura-selection-label{display:block;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#6f86a1;margin-bottom:4px;}.efatura-selection-banner strong{display:block;font-size:18px;line-height:1.3;color:#33475b;}.efatura-selection-meta{display:block;margin-top:4px;color:#607790;font-size:12px;}.efatura-selection-banner .badge{background:#dfeafb !important;color:#45627f !important;border:1px solid #c6d8ef;}.efatura-company-name{font-weight:700;color:#33475b;}.efatura-company-subtext{margin-top:3px;font-size:12px;color:#6d84a0;}.efatura-company-row-active td{background:#edf4fd !important;color:#33475b !important;}.efatura-company-row-active .badge-default{background:#dde8f6 !important;color:#4c6684 !important;}.efatura-company-row-active .badge-success{background:#d9f2e7 !important;color:#2f6b4f !important;}.efatura-company-row-active .efatura-company-subtext{color:#5e7895;}.efatura-action-stack{display:flex;flex-direction:row;justify-content:flex-end;align-items:center;gap:8px;white-space:nowrap;min-width:150px;}.efatura-action-stack .btn{margin:0;}.efatura-side-panel{margin-bottom:18px;}.efatura-side-panel .alert{margin-bottom:15px;}.efatura-page .badge-secondary{background:#e5ebf2 !important;color:#576c84 !important;}.efatura-page .badge-success{background:#dff4ea !important;color:#2d6c50 !important;}.efatura-page .badge-default{background:#edf2f7 !important;color:#607790 !important;}.efatura-page .efatura-job-status.badge-danger{background:#d9534f !important;color:#fff !important;}.efatura-page .efatura-job-status.badge-info,.efatura-page .badge-info{background:#2f7edb !important;color:#fff !important;}.efatura-page .efatura-job-status.badge-warning,.efatura-page .badge-warning{background:#f0ad4e !important;color:#fff !important;}.efatura-page .efatura-job-status.badge-success{background:#26b99a !important;color:#fff !important;}.efatura-page .efatura-job-status.badge-secondary{background:#73879c !important;color:#fff !important;}#efatura-companies-table td:last-child,#efatura-companies-table th:last-child{white-space:nowrap;width:1%;}#efatura-companies-table td:nth-child(2),#efatura-companies-table th:nth-child(2),#efatura-companies-table td:nth-child(3),#efatura-companies-table th:nth-child(3),#efatura-companies-table td:nth-child(4),#efatura-companies-table th:nth-child(4),#efatura-companies-table td:nth-child(5),#efatura-companies-table th:nth-child(5),#efatura-jobs-table td:nth-child(2),#efatura-jobs-table th:nth-child(2),#efatura-jobs-table td:nth-child(5),#efatura-jobs-table th:nth-child(5),#efatura-jobs-mini-table td:nth-child(4),#efatura-jobs-mini-table th:nth-child(4){white-space:nowrap;}#efatura-jobs-table td:nth-child(2),#efatura-jobs-table th:nth-child(2){min-width:200px;}#efatura-jobs-table td:nth-child(5),#efatura-jobs-table th:nth-child(5){min-width:170px;}#efatura-jobs-mini-table td:nth-child(4),#efatura-jobs-mini-table th:nth-child(4){min-width:80px;}@media (max-width: 991px){.efatura-selection-banner{flex-direction:column;align-items:flex-start;}.efatura-action-stack{flex-direction:column;align-items:stretch;min-width:0;white-space:normal;}.efatura-toolbar{flex-direction:column;align-items:stretch;}#efatura-documents-table_wrapper .row:first-child,#efatura-documents-table_wrapper .row:last-child{display:block;}#efatura-documents-table_wrapper .dt-search,#efatura-documents-table_wrapper .dataTables_filter{margin-left:0;}#efatura-documents-table_wrapper .efatura-documents-controls{align-items:stretch;}.efatura-documents-status-filter,.efatura-documents-date-filter,.efatura-documents-missing-slot{width:100%;}.efatura-documents-status-filter .form-control,.efatura-documents-date-filter .form-control{width:100%;min-width:0;}.efatura-documents-missing-slot .btn{width:100%;}#efatura-documents-table_wrapper .row:last-child .col-sm-6:last-child,#efatura-documents-table_wrapper .row:last-child .col-12:last-child{text-align:left;}}";
document.head.appendChild(efaturaStyle);
window.efaturaSyncStatusUrl = ' . json_encode(BASE_URL . 'contabilidade/efatura/sync-status', JSON_UNESCAPED_UNICODE) . ';
window.efaturaDocumentsDataUrl = ' . json_encode(BASE_URL . 'contabilidade/efatura/documentos?action=documents_data', JSON_UNESCAPED_UNICODE) . ';
window.efaturaMissingDocsPreviewUrl = ' . json_encode(BASE_URL . 'contabilidade/efatura/documentos?action=missing_docs_preview', JSON_UNESCAPED_UNICODE) . ';
window.efaturaMissingDocsSendUrl = ' . json_encode(BASE_URL . 'contabilidade/efatura/documentos', JSON_UNESCAPED_UNICODE) . ';
window.efaturaSelectedEntityId = ' . (int) $selectedEntityId . ';
window.efaturaDocumentsDateStorageKey = ' . json_encode('efatura_documents_date_range:' . (int) $selectedEntityId, JSON_UNESCAPED_UNICODE) . ';
window.efaturaDocumentsDateFilter = (function() {
    var fallbackStart = null;
    var fallbackEnd = null;

    if (window.moment) {
        fallbackStart = window.moment().startOf("month");
        fallbackEnd = window.moment().endOf("month");
    }

    function cloneMomentValue(value) {
        return value && typeof value.clone === "function" ? value.clone() : null;
    }

    function saveRange(startValue, endValue) {
        if (!window.localStorage) {
            return;
        }
        try {
            if (!startValue || !endValue) {
                window.localStorage.removeItem(window.efaturaDocumentsDateStorageKey);
                return;
            }
            window.localStorage.setItem(window.efaturaDocumentsDateStorageKey, JSON.stringify({
                start: startValue.format("YYYY-MM-DD"),
                end: endValue.format("YYYY-MM-DD")
            }));
        } catch (error) {}
    }

    function loadRange() {
        if (!window.localStorage || !window.moment) {
            return null;
        }
        try {
            var raw = window.localStorage.getItem(window.efaturaDocumentsDateStorageKey);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (!parsed || !parsed.start || !parsed.end) {
                return null;
            }
            var startValue = window.moment(parsed.start, "YYYY-MM-DD", true);
            var endValue = window.moment(parsed.end, "YYYY-MM-DD", true);
            if (!startValue.isValid() || !endValue.isValid()) {
                return null;
            }
            return { start: startValue, end: endValue };
        } catch (error) {
            return null;
        }
    }

    var storedRange = loadRange();
    var currentDateStart = storedRange ? storedRange.start : cloneMomentValue(fallbackStart);
    var currentDateEnd = storedRange ? storedRange.end : cloneMomentValue(fallbackEnd);
    saveRange(currentDateStart, currentDateEnd);

    return {
        getStart: function() {
            return currentDateStart ? currentDateStart.format("YYYY-MM-DD") : "";
        },
        getEnd: function() {
            return currentDateEnd ? currentDateEnd.format("YYYY-MM-DD") : "";
        },
        getStartMoment: function() {
            return cloneMomentValue(currentDateStart);
        },
        getEndMoment: function() {
            return cloneMomentValue(currentDateEnd);
        },
        setRange: function(startValue, endValue) {
            currentDateStart = cloneMomentValue(startValue);
            currentDateEnd = cloneMomentValue(endValue);
            saveRange(currentDateStart, currentDateEnd);
        },
        clearRange: function() {
            currentDateStart = null;
            currentDateEnd = null;
            saveRange(null, null);
        }
    };
})();
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
    dom: "<\"row\"<\"col-sm-8 col-12 d-flex align-items-center gap-2 efatura-documents-controls\"l<\"efatura-documents-date-slot\"><\"efatura-documents-status-slot\">><\"col-sm-4 col-12 d-flex align-items-center justify-content-end gap-2\"<\"efatura-documents-missing-slot\">f>>rt<\"row\"<\"col-sm-6 col-12\"i><\"col-sm-6 col-12\"p>>",
    initComplete: function() {
        var dateSlot = document.querySelector("#efatura-documents-table_wrapper .efatura-documents-date-slot");
        var slot = document.querySelector("#efatura-documents-table_wrapper .efatura-documents-status-slot");
        var missingSlot = document.querySelector("#efatura-documents-table_wrapper .efatura-documents-missing-slot");
        if (dateSlot && !dateSlot.querySelector("#efatura-document-date-range")) {
            dateSlot.innerHTML = "<div class=\"efatura-documents-date-filter\"><label for=\"efatura-document-date-range\">Datas</label><input type=\"text\" id=\"efatura-document-date-range\" class=\"form-control\" placeholder=\"Todas as datas\" autocomplete=\"off\"><button type=\"button\" class=\"btn btn-default btn-sm\" id=\"efatura-document-date-clear\">Limpar</button></div>";
            var dateInput = dateSlot.querySelector("#efatura-document-date-range");
            var clearButton = dateSlot.querySelector("#efatura-document-date-clear");

            if (window.jQuery && jQuery.fn.daterangepicker && window.moment) {
                var initialStart = window.efaturaDocumentsDateFilter && typeof window.efaturaDocumentsDateFilter.getStartMoment === "function"
                    ? window.efaturaDocumentsDateFilter.getStartMoment()
                    : null;
                var initialEnd = window.efaturaDocumentsDateFilter && typeof window.efaturaDocumentsDateFilter.getEndMoment === "function"
                    ? window.efaturaDocumentsDateFilter.getEndMoment()
                    : null;

                jQuery(dateInput).daterangepicker({
                    autoUpdateInput: false,
                    autoApply: true,
                    opens: "left",
                    startDate: initialStart || window.moment().startOf("month"),
                    endDate: initialEnd || window.moment().endOf("month"),
                    locale: {
                        format: "DD/MM/YYYY",
                        separator: " - ",
                        applyLabel: "Aplicar",
                        cancelLabel: "Limpar",
                        fromLabel: "De",
                        toLabel: "Até",
                        customRangeLabel: "Personalizado",
                        weekLabel: "S",
                        daysOfWeek: ["Do", "2ª", "3ª", "4ª", "5ª", "6ª", "Sá"],
                        monthNames: ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"],
                        firstDay: 1
                    }
                });

                if (initialStart && initialEnd) {
                    dateInput.value = initialStart.format("DD/MM/YYYY") + " - " + initialEnd.format("DD/MM/YYYY");
                }

                jQuery(dateInput).on("apply.daterangepicker", function(ev, picker) {
                    window.efaturaDocumentsDateFilter.setRange(picker.startDate, picker.endDate);
                    dateInput.value = picker.startDate.format("DD/MM/YYYY") + " - " + picker.endDate.format("DD/MM/YYYY");
                    if (efaturaDocumentsTable && typeof efaturaDocumentsTable.draw === "function") {
                        efaturaDocumentsTable.draw();
                    }
                });

                jQuery(dateInput).on("cancel.daterangepicker", function() {
                    window.efaturaDocumentsDateFilter.clearRange();
                    dateInput.value = "";
                    if (efaturaDocumentsTable && typeof efaturaDocumentsTable.draw === "function") {
                        efaturaDocumentsTable.draw();
                    }
                });
            }

            if (clearButton) {
                clearButton.addEventListener("click", function() {
                    window.efaturaDocumentsDateFilter.clearRange();
                    dateInput.value = "";
                    if (efaturaDocumentsTable && typeof efaturaDocumentsTable.draw === "function") {
                        efaturaDocumentsTable.draw();
                    }
                });
            }
        }
        if (missingSlot && !missingSlot.querySelector("#efatura-documents-missing-btn")) {
            missingSlot.innerHTML = "<button type=\"button\" class=\"btn btn-warning btn-sm\" id=\"efatura-documents-missing-btn\"" + (window.efaturaSelectedEntityId > 0 ? "" : " disabled") + "><i class=\"fa fa-envelope\"></i> Faltas</button>";
        }
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
            d.date_start = window.efaturaDocumentsDateFilter && typeof window.efaturaDocumentsDateFilter.getStart === "function" ? window.efaturaDocumentsDateFilter.getStart() : "";
            d.date_end = window.efaturaDocumentsDateFilter && typeof window.efaturaDocumentsDateFilter.getEnd === "function" ? window.efaturaDocumentsDateFilter.getEnd() : "";
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
        { data: "gross_total" },
        { data: "upload_status", orderable: false, searchable: false },
        { data: "classification_status", orderable: false, searchable: false },
        { data: "ctb_status", orderable: false, searchable: false }
    ]
});
initEfaturaTable("#efatura-jobs-table", {
    order: [[0, "desc"]],
    stateSave: false,
    columnDefs: [
        { type: "num", targets: 0 },
        { orderable: false, targets: [3, 4, 6] }
    ]
});
initEfaturaTable("#efatura-jobs-mini-table", {
    order: [[0, "desc"]],
    stateSave: false,
    columnDefs: [
        { type: "num", targets: 0 },
        { orderable: false, targets: [4, 5] }
    ]
});

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

$pageScripts .= '
(function() {
    var modalEl = document.getElementById("efaturaMissingDocsModal");
    var formEl = document.getElementById("efaturaMissingDocsForm");
    var alertEl = document.getElementById("efaturaMissingDocsAlert");
    var summaryEl = document.getElementById("efaturaMissingDocsSummary");
    var toEl = document.getElementById("efaturaMissingDocsTo");
    var fromEl = document.getElementById("efaturaMissingDocsFrom");
    var replyToEl = document.getElementById("efaturaMissingDocsReplyTo");
    var subjectEl = document.getElementById("efaturaMissingDocsSubject");
    var bodyEl = document.getElementById("efaturaMissingDocsBody");
    var sendBtn = document.getElementById("efaturaMissingDocsSendBtn");
    var previewState = null;

    function getDocumentsSearchValue() {
        if (efaturaDocumentsTable && typeof efaturaDocumentsTable.search === "function") {
            return String(efaturaDocumentsTable.search() || "").trim();
        }
        var input = document.querySelector("#efatura-documents-table_filter input, #efatura-documents-table_wrapper .dataTables_filter input");
        return input ? String(input.value || "").trim() : "";
    }

    function getCurrentFilters() {
        var statusFilter = document.querySelector("#efatura-document-status-filter");
        return {
            search: getDocumentsSearchValue(),
            status_filter: statusFilter ? String(statusFilter.value || "").trim() : "",
            date_start: window.efaturaDocumentsDateFilter && typeof window.efaturaDocumentsDateFilter.getStart === "function" ? window.efaturaDocumentsDateFilter.getStart() : "",
            date_end: window.efaturaDocumentsDateFilter && typeof window.efaturaDocumentsDateFilter.getEnd === "function" ? window.efaturaDocumentsDateFilter.getEnd() : ""
        };
    }

    function openModal() {
        if (!modalEl) {
            return;
        }
        if (window.jQuery && jQuery.fn.modal) {
            jQuery(modalEl).modal("show");
        } else if (window.bootstrap && typeof window.bootstrap.Modal === "function") {
            new window.bootstrap.Modal(modalEl).show();
        }
    }

    function closeModal() {
        if (!modalEl) {
            return;
        }
        if (window.jQuery && jQuery.fn.modal) {
            jQuery(modalEl).modal("hide");
        }
    }

    function showAlert(type, message) {
        if (!alertEl) {
            return;
        }
        alertEl.className = "alert alert-" + (type || "info");
        alertEl.textContent = message || "";
        alertEl.style.display = message ? "" : "none";
    }

    function resetGeneratedFields() {
        previewState = null;
        if (toEl) {
            toEl.value = "";
        }
        if (fromEl) {
            fromEl.innerHTML = "";
        }
        if (replyToEl) {
            replyToEl.value = "";
        }
        if (subjectEl) {
            subjectEl.value = "";
        }
        if (bodyEl) {
            bodyEl.value = "";
        }
        updateSummary(null);
    }

    function renderTemplate(template, values) {
        return String(template || "").replace(/{{\\s*([a-z0-9_]+)\\s*}}/gi, function(match, key) {
            return Object.prototype.hasOwnProperty.call(values, key) ? String(values[key] || "") : "";
        });
    }

    function getSelectedSender() {
        if (!fromEl) {
            return null;
        }
        var selectedOption = fromEl.options[fromEl.selectedIndex];
        if (!selectedOption) {
            return null;
        }
        return {
            email: String(selectedOption.value || "").trim(),
            name: String(selectedOption.getAttribute("data-sender-name") || "").trim()
        };
    }

    function applyRenderedTemplate() {
        if (!previewState) {
            return;
        }
        var sender = getSelectedSender();
        var renderValues = Object.assign({}, previewState.placeholders || {}, {
            sender_name: sender && sender.name ? sender.name : "",
            sender_email: sender && sender.email ? sender.email : ""
        });
        if (subjectEl) {
            subjectEl.value = renderTemplate(previewState.subject_template || "", renderValues);
        }
        if (bodyEl) {
            bodyEl.value = renderTemplate(previewState.body_template || "", renderValues);
        }
    }

    function populateSenderOptions(options, defaultSender) {
        if (!fromEl) {
            return;
        }
        fromEl.innerHTML = "";
        (options || []).forEach(function(option) {
            var optionEl = document.createElement("option");
            optionEl.value = String(option.email || "").trim();
            optionEl.textContent = String(option.label || option.email || "").trim();
            optionEl.setAttribute("data-sender-name", String(option.name || "").trim());
            if (optionEl.value === String(defaultSender || "").trim()) {
                optionEl.selected = true;
            }
            fromEl.appendChild(optionEl);
        });
    }

    function updateSummary(payload) {
        if (!summaryEl) {
            return;
        }
        var parts = [];
        if (payload && payload.company_label) {
            parts.push(payload.company_label);
        }
        if (payload && payload.missing_count) {
            parts.push(String(payload.missing_count) + " documento(s) sem upload");
        }
        if (payload && payload.filters_summary) {
            parts.push(payload.filters_summary);
        }
        summaryEl.textContent = parts.join(" | ");
    }

    function requestPreview() {
        if (!window.fetch) {
            showAlert("danger", "O browser nao suporta pedidos assincronos.");
            return;
        }
        if (!window.efaturaSelectedEntityId) {
            showAlert("warning", "Seleciona uma empresa antes de enviar faltas.");
            openModal();
            return;
        }

        var filters = getCurrentFilters();
        var url = new URL(window.efaturaMissingDocsPreviewUrl, window.location.origin);
        Object.keys(filters).forEach(function(key) {
            if (filters[key]) {
                url.searchParams.set(key, filters[key]);
            }
        });

        showAlert("info", "A preparar a listagem...");
        if (sendBtn) {
            sendBtn.disabled = true;
        }
        openModal();

        fetch(url.toString(), { credentials: "same-origin" })
            .then(function(response) {
                return response.json().then(function(data) {
                    if (!response.ok || !data || !data.ok) {
                        var errorMessage = data && data.error ? data.error : "Nao foi possivel preparar a mensagem.";
                        throw new Error(errorMessage);
                    }
                    return data;
                });
            })
            .then(function(payload) {
                previewState = payload || null;
                populateSenderOptions(payload.sender_options || [], payload.default_sender || "");
                if (toEl) {
                    toEl.value = String(payload.recipient_email || "").trim();
                }
                if (replyToEl) {
                    replyToEl.value = String(payload.default_reply_to || "").trim();
                }
                updateSummary(payload);
                applyRenderedTemplate();
                showAlert(payload.recipient_email ? "success" : "warning", payload.recipient_email ? "Mensagem pronta a enviar." : "Nao foi encontrado email na ficha da entidade. Indica manualmente o destinatario.");
                if (sendBtn) {
                    sendBtn.disabled = false;
                }
            })
            .catch(function(error) {
                resetGeneratedFields();
                if (sendBtn) {
                    sendBtn.disabled = true;
                }
                showAlert("danger", error && error.message ? error.message : "Erro ao preparar a mensagem.");
            });
    }

    document.addEventListener("click", function(event) {
        var button = event.target.closest ? event.target.closest("#efatura-documents-missing-btn") : null;
        if (!button) {
            return;
        }
        event.preventDefault();
        requestPreview();
    });

    if (fromEl) {
        fromEl.addEventListener("change", function() {
            applyRenderedTemplate();
        });
    }

    if (sendBtn && formEl) {
        sendBtn.addEventListener("click", function() {
            if (!previewState) {
                showAlert("warning", "Prepara primeiro a listagem de faltas.");
                return;
            }
            if (!window.fetch) {
                showAlert("danger", "O browser nao suporta pedidos assincronos.");
                return;
            }

            var formData = new FormData(formEl);
            sendBtn.disabled = true;
            showAlert("info", "A enviar email...");

            fetch(window.efaturaMissingDocsSendUrl, {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            })
                .then(function(response) {
                    return response.json().then(function(data) {
                        if (!response.ok || !data || !data.ok) {
                            var errorMessage = data && data.error ? data.error : "Nao foi possivel enviar o email.";
                            throw new Error(errorMessage);
                        }
                        return data;
                    });
                })
                .then(function(payload) {
                    showAlert("success", payload && payload.message ? payload.message : "Email enviado com sucesso.");
                    setTimeout(function() {
                        closeModal();
                    }, 700);
                })
                .catch(function(error) {
                    showAlert("danger", error && error.message ? error.message : "Erro ao enviar o email.");
                })
                .finally(function() {
                    sendBtn.disabled = false;
                });
        });
    }

    if (window.jQuery && modalEl) {
        jQuery(modalEl).on("hidden.bs.modal", function() {
            resetGeneratedFields();
            showAlert("", "");
        });
    }
})();
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
            foreach ([
                'http_json_sample',
                'json_raw_sample',
                'http_login_sample',
                'browser_relay_form',
                'apos_login_url',
                'apos_login_title',
                'apos_login_hidden_inputs',
                'apos_login_forms',
                'apos_login_text_sample',
                'json_expired_attempt_1_cookie_domains',
                'json_expired_attempt_1_cookies_by_domain',
                'json_expired_attempt_2_cookie_domains',
                'json_expired_attempt_2_cookies_by_domain',
                'html_snapshot',
                'screenshot',
            ] as $key) {
                if (!empty($decoded['debug'][$key])) {
                    $parts[] = '';
                    $parts[] = strtoupper($key) . ':';
                    $value = $decoded['debug'][$key];
                    $parts[] = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }
            }
            if (!empty($decoded['runner_log']) && is_file((string) $decoded['runner_log'])) {
                $runnerLog = @file_get_contents((string) $decoded['runner_log']);
                if (is_string($runnerLog) && trim($runnerLog) !== '') {
                    $parts[] = '';
                    $parts[] = 'RUNNER LOG:';
                    $parts[] = trim($runnerLog);
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
    $pythonCandidates = [
        __DIR__ . '/../.venv/bin/python',
        __DIR__ . '/../.venv/bin/python3',
        trim((string) @shell_exec('command -v python3 2>/dev/null')),
        trim((string) @shell_exec('command -v python 2>/dev/null')),
    ];
    $python = '';
    foreach ($pythonCandidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }
        if (strpos($candidate, DIRECTORY_SEPARATOR) !== false) {
            if (is_file($candidate) && is_executable($candidate)) {
                $python = $candidate;
                break;
            }
            continue;
        }
        $python = $candidate;
        break;
    }
    if ($python === '') {
        throw new RuntimeException('python3 nao encontrado no servidor.');
    }
    $script = __DIR__ . '/efatura_worker.py';
    if (!is_file($script)) {
        throw new RuntimeException('Worker E-fatura nao encontrado.');
    }
    $portalPassword = decryptEfaturaSecret((string) ($credential['portal_password_encrypted'] ?? ''));
    $runnerLogPath = preg_replace('/\.[^.]+$/', '', $artifactPath) . '.runner.log';
    @file_put_contents($artifactPath, json_encode([
        'job_id' => $jobId,
        'status' => 'running',
        'started_at' => date('c'),
        'last_step' => 'A lancar worker Python.',
        'runner_log' => $runnerLogPath,
        'debug' => [
            'steps' => [[
                'at' => date('c'),
                'message' => 'Preparado comando de arranque do worker.',
            ]],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        . ' >> ' . escapeshellarg($runnerLogPath) . ' 2>&1 &';
    @exec($envPrefix . $cmd);
}

function efaturaFetchCompanies(PDO $pdo): array {
    $sql = "SELECT ae.id, ae.name, ae.nif, ae.erp_database, ae.erp_client_code,
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
    $stmt = $pdo->prepare("SELECT id, name, nif, erp_database, erp_client_code FROM accounting_entities WHERE id = ? AND entity_type = 'acquirer' LIMIT 1");
    $stmt->execute([$entityId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function efaturaResolveEntityErpDatabase(array $entity): string {
    return resolveAccountingEntityDatabase($entity);
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
    $linkSelect = '';
    $linkJoin = '';
    if (accountingImportsEfaturaLinkReady()) {
        $linkSelect = ',
            COALESCE(ai.has_upload, 0) AS has_upload,
            COALESCE(ai.has_classification, 0) AS has_classification,
            COALESCE(ai.has_ctb_import, 0) AS has_ctb_import';
        $linkJoin = "
            LEFT JOIN (
                SELECT
                    efatura_document_id,
                    1 AS has_upload,
                    MAX(CASE WHEN TRIM(COALESCE(account, '')) <> '' THEN 1 ELSE 0 END) AS has_classification,
                    MAX(CASE WHEN TRIM(COALESCE(cab_id, '')) <> '' THEN 1 ELSE 0 END) AS has_ctb_import
                FROM accounting_imports
                WHERE efatura_document_id IS NOT NULL
                GROUP BY efatura_document_id
            ) ai ON ai.efatura_document_id = d.id";
    }
    $sql = "SELECT d.*, ae.name AS entity_name{$linkSelect}
            FROM efatura_documents d
            JOIN accounting_entities ae ON ae.id = d.entity_id{$linkJoin}";
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

function efaturaParseDocumentFilters(array $source): array {
    $searchRaw = '';
    if (isset($source['search']) && is_array($source['search'])) {
        $searchRaw = (string) ($source['search']['value'] ?? '');
    } else {
        $searchRaw = (string) ($source['search'] ?? '');
    }

    $filters = [
        'search' => trim($searchRaw),
        'status_filter' => strtoupper(trim((string) ($source['status_filter'] ?? ''))),
        'date_start' => '',
        'date_end' => '',
    ];

    $dateStart = trim((string) ($source['date_start'] ?? ''));
    if ($dateStart !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $dateStart);
        if ($date instanceof DateTime) {
            $filters['date_start'] = $date->format('Y-m-d');
        }
    }

    $dateEnd = trim((string) ($source['date_end'] ?? ''));
    if ($dateEnd !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $dateEnd);
        if ($date instanceof DateTime) {
            $filters['date_end'] = $date->format('Y-m-d');
        }
    }

    return $filters;
}

function efaturaBuildDocumentWhereSql(int $selectedEntityId, array $filters, array &$params): string {
    $where = [];
    $params = [];

    if ($selectedEntityId > 0) {
        $where[] = 'd.entity_id = ?';
        $params[] = $selectedEntityId;
    }

    if (($filters['status_filter'] ?? '') === 'A') {
        $where[] = 'UPPER(COALESCE(d.document_status, "")) = ?';
        $params[] = 'A';
    }

    if (($filters['date_start'] ?? '') !== '') {
        $where[] = 'DATE(d.invoice_date) >= ?';
        $params[] = $filters['date_start'];
    }

    if (($filters['date_end'] ?? '') !== '') {
        $where[] = 'DATE(d.invoice_date) <= ?';
        $params[] = $filters['date_end'];
    }

    if (($filters['search'] ?? '') !== '') {
        $where[] = '(d.invoice_date LIKE ? OR ae.name LIKE ? OR d.issuer_name LIKE ? OR d.issuer_vat LIKE ? OR d.invoice_no LIKE ? OR d.invoice_type LIKE ?)';
        $searchTerm = '%' . $filters['search'] . '%';
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    return $where ? ' WHERE ' . implode(' AND ', $where) : '';
}

function efaturaBuildDocumentLinkSql(): array {
    $linkSelect = '';
    $linkJoin = '';

    if (accountingImportsEfaturaLinkReady()) {
        $linkSelect = ',
            COALESCE(ai.has_upload, 0) AS has_upload,
            COALESCE(ai.has_classification, 0) AS has_classification,
            COALESCE(ai.has_ctb_import, 0) AS has_ctb_import';
        $linkJoin = ' LEFT JOIN (
                SELECT
                    efatura_document_id,
                    1 AS has_upload,
                    MAX(CASE WHEN TRIM(COALESCE(account, \'\')) <> \'\' THEN 1 ELSE 0 END) AS has_classification,
                    MAX(CASE WHEN TRIM(COALESCE(cab_id, \'\')) <> \'\' THEN 1 ELSE 0 END) AS has_ctb_import
                FROM accounting_imports
                WHERE efatura_document_id IS NOT NULL
                GROUP BY efatura_document_id
            ) ai ON ai.efatura_document_id = d.id';
    }

    return [$linkSelect, $linkJoin];
}

function efaturaResolveDocumentImportState(PDO $pdo, array $row, ?PDOStatement $importStatusStmt = null): array {
    $hasUpload = (int) ($row['has_upload'] ?? 0) === 1;
    $hasClassification = (int) ($row['has_classification'] ?? 0) === 1;
    $hasCtbImport = (int) ($row['has_ctb_import'] ?? 0) === 1;

    if (!$hasUpload && $importStatusStmt instanceof PDOStatement) {
        $match = reconcileEfaturaDocumentWithAccountingImport($pdo, (int) ($row['id'] ?? 0), $row);
        if ($match && !empty($match['id'])) {
            $hasUpload = true;
            $importStatusStmt->execute([(int) $match['id']]);
            $importRow = $importStatusStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $hasClassification = trim((string) ($importRow['account'] ?? '')) !== '';
            $hasCtbImport = trim((string) ($importRow['cab_id'] ?? '')) !== '';
        }
    }

    return [
        'has_upload' => $hasUpload,
        'has_classification' => $hasClassification,
        'has_ctb_import' => $hasCtbImport,
    ];
}

function efaturaFetchFilteredDocumentRows(PDO $pdo, int $selectedEntityId, array $filters, string $orderBy = 'd.invoice_date DESC, d.id DESC', ?int $limit = null, int $offset = 0): array {
    [$linkSelect, $linkJoin] = efaturaBuildDocumentLinkSql();
    $baseFrom = ' FROM efatura_documents d JOIN accounting_entities ae ON ae.id = d.entity_id' . $linkJoin;
    $params = [];
    $whereSql = efaturaBuildDocumentWhereSql($selectedEntityId, $filters, $params);
    $sql = 'SELECT d.*, ae.name AS entity_name' . $linkSelect
        . $baseFrom
        . $whereSql
        . ' ORDER BY ' . $orderBy;

    if ($limit !== null && $limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . max(0, $offset);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function efaturaBuildImportStatusLookup(PDO $pdo): ?PDOStatement {
    if (!accountingImportsEfaturaLinkReady()) {
        return null;
    }
    return $pdo->prepare('SELECT account, cab_id FROM accounting_imports WHERE id = ? LIMIT 1');
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

    $filters = efaturaParseDocumentFilters($_GET);
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
        8 => 'has_upload',
        9 => 'has_classification',
        10 => 'has_ctb_import',
    ];
    $orderBy = $orderableColumns[$orderColumn] ?? 'd.invoice_date';

    [$linkSelect, $linkJoin] = efaturaBuildDocumentLinkSql();
    $baseFrom = ' FROM efatura_documents d JOIN accounting_entities ae ON ae.id = d.entity_id ' . $linkJoin;
    $params = [];
    $whereSql = efaturaBuildDocumentWhereSql($selectedEntityId, $filters, $params);

    $totalSql = 'SELECT COUNT(*)' . $baseFrom . ($selectedEntityId > 0 ? ' WHERE d.entity_id = ?' : '');
    $totalStmt = $pdo->prepare($totalSql);
    $totalStmt->execute($selectedEntityId > 0 ? [$selectedEntityId] : []);
    $recordsTotal = (int) $totalStmt->fetchColumn();

    $filteredSql = 'SELECT COUNT(*)' . $baseFrom . $whereSql;
    $filteredStmt = $pdo->prepare($filteredSql);
    $filteredStmt->execute($params);
    $recordsFiltered = (int) $filteredStmt->fetchColumn();

    $dataSql = 'SELECT d.*, ae.name AS entity_name' . $linkSelect
        . $baseFrom
        . $whereSql
        . ' ORDER BY ' . $orderBy . ' ' . $orderDir . ', d.id ' . $orderDir
        . ' LIMIT ' . (int) $length . ' OFFSET ' . (int) $start;
    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $importStatusStmt = efaturaBuildImportStatusLookup($pdo);

    $data = [];
    foreach ($rows as $row) {
        $status = strtoupper(trim((string) ($row['document_status'] ?? '')));
        $state = efaturaResolveDocumentImportState($pdo, $row, $importStatusStmt);
        $hasUpload = $state['has_upload'];
        $hasClassification = $state['has_classification'];
        $hasCtbImport = $state['has_ctb_import'];
        $statusBadgeClass = $hasCtbImport ? 'badge-success' : ($hasClassification ? 'badge-info' : ($hasUpload ? 'badge-warning' : 'badge-secondary'));
        $statusBadgeLabel = $hasCtbImport ? 'Importado CTB' : ($hasClassification ? 'Classificado' : ($hasUpload ? 'Por classificar' : 'Em falta'));
        $data[] = [
            'invoice_date' => (string) ($row['invoice_date'] ?? ''),
            'issuer_name' => (string) ($row['issuer_name'] ?? ''),
            'issuer_vat' => (string) ($row['issuer_vat'] ?? ''),
            'invoice_no' => (string) ($row['invoice_no'] ?? ''),
            'invoice_type' => (string) ($row['invoice_type'] ?? ''),
            'net_total' => number_format((float) ($row['net_total'] ?? 0), 2, ',', ' '),
            'tax_payable' => number_format((float) ($row['tax_payable'] ?? 0), 2, ',', ' '),
            'gross_total' => number_format((float) ($row['gross_total'] ?? 0), 2, ',', ' '),
            'upload_status' => '<span class="badge ' . ($hasUpload ? 'badge-success' : 'badge-secondary') . '">' . ($hasUpload ? 'Upload' : 'Sem upload') . '</span>',
            'classification_status' => '<span class="badge ' . ($hasClassification ? 'badge-info' : 'badge-secondary') . '">' . ($hasClassification ? 'Classificado' : 'Por classificar') . '</span>',
            'ctb_status' => '<span class="badge ' . $statusBadgeClass . '">' . $statusBadgeLabel . '</span>',
            'DT_RowClass' => trim(($status === 'A' ? 'efatura-document-row-cancelled ' : '') . (!$hasUpload ? 'efatura-document-row-missing' : '')),
            'DT_RowAttr' => ['data-status' => $status, 'data-uploaded' => $hasUpload ? '1' : '0'],
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

function handleEfaturaMissingDocsPreview(PDO $pdo, int $selectedEntityId, array $user): void {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($selectedEntityId <= 0) {
            throw new RuntimeException('Seleciona uma empresa antes de enviar faltas.');
        }

        $filters = efaturaParseDocumentFilters($_GET);
        $missingDocuments = efaturaFetchMissingDocumentsForCommunication($pdo, $selectedEntityId, $filters);
        if (!$missingDocuments) {
            throw new RuntimeException('Nao existem documentos sem upload com os filtros atuais.');
        }

        $entityContext = efaturaResolveEntityCommunicationContext($pdo, $selectedEntityId);
        $senderConfig = efaturaResolveMissingDocsSenderOptions($user);
        $templates = efaturaResolveMissingDocsTemplates();
        $placeholders = efaturaBuildMissingDocsPlaceholders($entityContext, $missingDocuments, $filters);

        echo json_encode([
            'ok' => true,
            'company_label' => (string) ($placeholders['entity_display'] ?? ''),
            'missing_count' => count($missingDocuments),
            'filters_summary' => (string) ($placeholders['filters_summary'] ?? ''),
            'recipient_email' => (string) ($entityContext['entity_email'] ?? ''),
            'sender_options' => $senderConfig['options'],
            'default_sender' => (string) ($senderConfig['default_sender'] ?? ''),
            'default_reply_to' => (string) ($senderConfig['default_reply_to'] ?? ''),
            'subject_template' => $templates['subject'],
            'body_template' => $templates['body'],
            'placeholders' => $placeholders,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function handleEfaturaSendMissingDocsEmail(PDO $pdo, int $selectedEntityId, array $user): void {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if ($selectedEntityId <= 0) {
            throw new RuntimeException('Seleciona uma empresa antes de enviar faltas.');
        }

        $recipients = efaturaExtractEmailAddresses((string) ($_POST['to'] ?? ''));
        if (!$recipients) {
            throw new RuntimeException('Indica pelo menos um destinatario valido.');
        }

        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($subject === '') {
            throw new RuntimeException('O assunto e obrigatorio.');
        }
        if ($body === '') {
            throw new RuntimeException('A mensagem e obrigatoria.');
        }

        $senderConfig = efaturaResolveMissingDocsSenderOptions($user);
        $allowedSenders = [];
        foreach ($senderConfig['options'] as $option) {
            $email = strtolower(trim((string) ($option['email'] ?? '')));
            if ($email !== '') {
                $allowedSenders[$email] = $option;
            }
        }

        $fromEmail = strtolower(trim((string) ($_POST['from_email'] ?? '')));
        if ($fromEmail === '' || !isset($allowedSenders[$fromEmail])) {
            throw new RuntimeException('O remetente selecionado nao e valido.');
        }

        $replyToList = efaturaExtractEmailAddresses((string) ($_POST['reply_to'] ?? ''));
        $replyTo = $replyToList[0] ?? '';

        efaturaSendEmail([
            'to' => $recipients,
            'from_email' => (string) $allowedSenders[$fromEmail]['email'],
            'from_name' => (string) ($allowedSenders[$fromEmail]['name'] ?? ''),
            'reply_to' => $replyTo,
            'subject' => $subject,
            'body' => $body,
        ]);

        logAuditAction('efatura_missing_docs_email_send', 'accounting_entities', $selectedEntityId, [
            'entity_id' => $selectedEntityId,
            'to' => implode('; ', $recipients),
            'from' => (string) $allowedSenders[$fromEmail]['email'],
            'reply_to' => $replyTo,
            'subject' => $subject,
            'sent_by' => (int) ($user['id'] ?? 0),
        ]);

        echo json_encode([
            'ok' => true,
            'message' => 'Email enviado com sucesso.',
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function efaturaFetchMissingDocumentsForCommunication(PDO $pdo, int $selectedEntityId, array $filters): array {
    $rows = efaturaFetchFilteredDocumentRows($pdo, $selectedEntityId, $filters, 'd.invoice_date ASC, d.id ASC');
    $importStatusStmt = efaturaBuildImportStatusLookup($pdo);
    $missing = [];

    foreach ($rows as $row) {
        $state = efaturaResolveDocumentImportState($pdo, $row, $importStatusStmt);
        if ($state['has_upload']) {
            continue;
        }
        $row['has_upload'] = 0;
        $missing[] = $row;
    }

    return $missing;
}

function efaturaResolveEntityCommunicationContext(PDO $pdo, int $entityId): array {
    $entity = efaturaFetchEntity($pdo, $entityId);
    if (!$entity) {
        throw new RuntimeException('Empresa nao encontrada.');
    }

    $resolvedErpDatabase = efaturaResolveEntityErpDatabase($entity);
    $code = efaturaFormatErpDatabase($resolvedErpDatabase);
    $display = ($code !== '' && $code !== '-' ? $code . ' - ' : '') . trim((string) ($entity['name'] ?? ''));
    $context = [
        'entity_name' => trim((string) ($entity['name'] ?? '')),
        'entity_nif' => trim((string) ($entity['nif'] ?? '')),
        'entity_code' => $code !== '-' ? $code : '',
        'entity_erp_database' => $resolvedErpDatabase,
        'entity_display' => trim($display),
        'entity_email' => '',
        'entity_address' => '',
        'entity_address2' => '',
        'entity_postal_code' => '',
        'entity_city' => '',
        'entity_phone' => '',
        'entity_mobile' => '',
        'entity_number' => '',
    ];

    $erpDatabase = $resolvedErpDatabase;
    $entityNif = trim((string) ($entity['nif'] ?? ''));
    if ($entityNif !== '') {
        $remote = fetchAccountingEntityFromErp($entityNif, 'acquirer', true, $erpDatabase);
        if (is_array($remote) && empty($remote['error'])) {
            $row = efaturaExtractErpEntityRow($remote['payload'] ?? []);
            if ($row) {
                $emails = efaturaExtractEmailAddresses((string) ($row['strEmail'] ?? ''));
                $context['entity_name'] = trim((string) ($row['strNome'] ?? '')) !== '' ? trim((string) ($row['strNome'] ?? '')) : $context['entity_name'];
                $context['entity_nif'] = extractVatNumber((string) ($row['strNumContrib'] ?? '')) ?: $context['entity_nif'];
                $context['entity_email'] = $emails ? implode('; ', $emails) : '';
                $context['entity_address'] = trim((string) ($row['strMorada_lin1'] ?? ''));
                $context['entity_address2'] = trim((string) ($row['strMorada_lin2'] ?? ''));
                $context['entity_postal_code'] = trim((string) ($row['strPostal'] ?? ''));
                $context['entity_city'] = trim((string) ($row['strLocalidade'] ?? ''));
                $context['entity_phone'] = trim((string) ($row['strTelefone'] ?? ''));
                $context['entity_mobile'] = trim((string) ($row['strTelemovel'] ?? ''));
                $context['entity_number'] = trim((string) ($row['intCodigo'] ?? $row['Id'] ?? ''));
            }
        }
    }

    $context['entity_display'] = trim((($context['entity_code'] ?? '') !== '' ? ($context['entity_code'] . ' - ') : '') . ($context['entity_name'] ?? ''));
    $context['entity_address_full'] = efaturaJoinNonEmpty([
        $context['entity_address'],
        $context['entity_address2'],
        trim(efaturaJoinNonEmpty([$context['entity_postal_code'], $context['entity_city']], ' ')),
    ], ', ');
    $context['entity_contact_summary'] = efaturaJoinNonEmpty([
        $context['entity_email'],
        $context['entity_phone'],
        $context['entity_mobile'],
    ], ' | ');

    return $context;
}

function efaturaExtractErpEntityRow($payload): array {
    if (!is_array($payload)) {
        return [];
    }
    if (isset($payload['aaData']) && is_array($payload['aaData']) && !empty($payload['aaData'][0]) && is_array($payload['aaData'][0])) {
        return $payload['aaData'][0];
    }
    if (isset($payload['data']) && is_array($payload['data']) && !empty($payload['data'][0]) && is_array($payload['data'][0])) {
        return $payload['data'][0];
    }
    return [];
}

function efaturaResolveMissingDocsTemplates(): array {
    $defaultSubject = 'E-fatura - documentos em falta para {{entity_display}}';
    $defaultBody = "Exmos. Senhores,\n\n"
        . "Na conferência dos documentos de {{entity_name}} (NIF {{entity_nif}}), identificámos {{missing_count}} documento(s) no E-fatura sem o respetivo upload para classificação contabilística.\n\n"
        . "Filtros considerados: {{filters_summary}}\n\n"
        . "Agradecemos o envio do papel ou PDF dos seguintes documentos:\n"
        . "{{documents_list}}\n\n"
        . "Se algum destes documentos já tiver sido entregue, podem ignorar a respetiva linha.\n\n"
        . "Com os melhores cumprimentos,\n"
        . "{{sender_name}}\n"
        . "{{sender_email}}";

    return [
        'subject' => trim((string) getSetting('efatura_missing_docs_email_subject_template', $defaultSubject)),
        'body' => trim((string) getSetting('efatura_missing_docs_email_body_template', $defaultBody)),
    ];
}

function efaturaBuildMissingDocsPlaceholders(array $entityContext, array $missingDocuments, array $filters): array {
    $dateGenerated = date('Y-m-d H:i');
    $documentsList = efaturaBuildMissingDocumentLinesText($missingDocuments);

    return [
        'entity_name' => (string) ($entityContext['entity_name'] ?? ''),
        'entity_nif' => (string) ($entityContext['entity_nif'] ?? ''),
        'entity_code' => (string) ($entityContext['entity_code'] ?? ''),
        'entity_display' => (string) ($entityContext['entity_display'] ?? ''),
        'entity_erp_database' => (string) ($entityContext['entity_erp_database'] ?? ''),
        'entity_email' => (string) ($entityContext['entity_email'] ?? ''),
        'entity_address' => (string) ($entityContext['entity_address'] ?? ''),
        'entity_address2' => (string) ($entityContext['entity_address2'] ?? ''),
        'entity_postal_code' => (string) ($entityContext['entity_postal_code'] ?? ''),
        'entity_city' => (string) ($entityContext['entity_city'] ?? ''),
        'entity_address_full' => (string) ($entityContext['entity_address_full'] ?? ''),
        'entity_phone' => (string) ($entityContext['entity_phone'] ?? ''),
        'entity_mobile' => (string) ($entityContext['entity_mobile'] ?? ''),
        'entity_number' => (string) ($entityContext['entity_number'] ?? ''),
        'entity_contact_summary' => (string) ($entityContext['entity_contact_summary'] ?? ''),
        'missing_count' => (string) count($missingDocuments),
        'documents_list' => $documentsList,
        'filters_summary' => efaturaBuildMissingDocsFilterSummary($filters),
        'generated_at' => $dateGenerated,
        'sender_name' => '',
        'sender_email' => '',
    ];
}

function efaturaBuildMissingDocumentLinesText(array $documents): string {
    $lines = [];
    foreach ($documents as $row) {
        $invoiceDate = trim((string) ($row['invoice_date'] ?? ''));
        $invoiceType = trim((string) ($row['invoice_type'] ?? ''));
        $invoiceNo = trim((string) ($row['invoice_no'] ?? ''));
        $issuerName = trim((string) ($row['issuer_name'] ?? ''));
        $issuerVat = trim((string) ($row['issuer_vat'] ?? ''));
        $grossTotal = number_format((float) ($row['gross_total'] ?? 0), 2, ',', ' ');
        $parts = [
            $invoiceDate,
            trim($invoiceType . ' ' . $invoiceNo),
            $issuerName,
        ];
        if ($issuerVat !== '') {
            $parts[] = 'NIF ' . $issuerVat;
        }
        $parts[] = 'Total ' . $grossTotal . ' EUR';
        $lines[] = '- ' . efaturaJoinNonEmpty($parts, ' | ');
    }
    return implode("\n", $lines);
}

function efaturaBuildMissingDocsFilterSummary(array $filters): string {
    $parts = [];
    if (($filters['date_start'] ?? '') !== '' && ($filters['date_end'] ?? '') !== '') {
        $parts[] = $filters['date_start'] . ' a ' . $filters['date_end'];
    } elseif (($filters['date_start'] ?? '') !== '') {
        $parts[] = 'desde ' . $filters['date_start'];
    } elseif (($filters['date_end'] ?? '') !== '') {
        $parts[] = 'ate ' . $filters['date_end'];
    }

    if (($filters['status_filter'] ?? '') === 'A') {
        $parts[] = 'apenas anulados';
    }

    if (($filters['search'] ?? '') !== '') {
        $parts[] = 'pesquisa "' . $filters['search'] . '"';
    }

    return $parts ? implode(' | ', $parts) : 'sem filtros adicionais';
}

function efaturaResolveMissingDocsSenderOptions(array $user): array {
    $options = [];
    $currentUserEmail = efaturaExtractEmailAddresses((string) ($user['email'] ?? ''));
    $currentUserEmail = $currentUserEmail[0] ?? '';
    $currentUserName = trim((string) ($user['name'] ?? $user['username'] ?? ''));
    $appName = trim((string) getSetting('app_name', 'AICRM'));
    $noReplyEmail = efaturaResolveNoReplyEmail($user);

    if ($currentUserEmail !== '') {
        $options[] = [
            'email' => $currentUserEmail,
            'name' => $currentUserName !== '' ? $currentUserName : $currentUserEmail,
            'label' => 'Utilizador atual - ' . ($currentUserName !== '' ? $currentUserName . ' <' . $currentUserEmail . '>' : $currentUserEmail),
        ];
    }

    $options[] = [
        'email' => $noReplyEmail,
        'name' => $appName !== '' ? $appName : 'No-reply',
        'label' => 'No-reply - ' . $noReplyEmail,
    ];

    $unique = [];
    foreach ($options as $option) {
        $key = strtolower(trim((string) ($option['email'] ?? '')));
        if ($key === '' || isset($unique[$key])) {
            continue;
        }
        $unique[$key] = $option;
    }

    return [
        'options' => array_values($unique),
        'default_sender' => $currentUserEmail !== '' ? $currentUserEmail : $noReplyEmail,
        'default_reply_to' => $currentUserEmail !== '' ? $currentUserEmail : $noReplyEmail,
    ];
}

function efaturaResolveNoReplyEmail(array $user): string {
    $domain = '';
    $smtpUserEmails = efaturaExtractEmailAddresses((string) getSetting('smtp_user', ''));
    if (!empty($smtpUserEmails[0]) && strpos($smtpUserEmails[0], '@') !== false) {
        $domain = substr($smtpUserEmails[0], strpos($smtpUserEmails[0], '@') + 1);
    }

    if ($domain === '') {
        $userEmails = efaturaExtractEmailAddresses((string) ($user['email'] ?? ''));
        if (!empty($userEmails[0]) && strpos($userEmails[0], '@') !== false) {
            $domain = substr($userEmails[0], strpos($userEmails[0], '@') + 1);
        }
    }

    if ($domain === '') {
        $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = preg_replace('/^www\./', '', $host) ?? '';
        if ($host !== '') {
            $domain = $host;
        }
    }

    if ($domain === '') {
        $domain = 'example.local';
    }

    return 'noreply@' . $domain;
}

function efaturaExtractEmailAddresses(string $value): array {
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $parts = preg_split('/[;,]+/', $value) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $candidate = trim((string) $part);
        if ($candidate === '') {
            continue;
        }
        if (preg_match('/<([^>]+)>/', $candidate, $matches)) {
            $candidate = trim((string) $matches[1]);
        }
        $candidate = trim($candidate, "\"' ");
        if (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $key = strtolower($candidate);
        $emails[$key] = $candidate;
    }

    return array_values($emails);
}

function efaturaJoinNonEmpty(array $parts, string $separator): string {
    $filtered = [];
    foreach ($parts as $part) {
        $value = trim((string) $part);
        if ($value !== '') {
            $filtered[] = $value;
        }
    }
    return implode($separator, $filtered);
}

function efaturaSendEmail(array $message): void {
    $smtpHost = trim((string) getSetting('smtp_host', ''));
    if ($smtpHost !== '') {
        efaturaSendEmailViaSmtp($message);
        return;
    }
    efaturaSendEmailViaMail($message);
}

function efaturaSendEmailViaMail(array $message): void {
    if (!function_exists('mail')) {
        throw new RuntimeException('Nao existe transporte de email configurado.');
    }

    $headers = efaturaBuildEmailHeaders($message, false, false);
    $toHeader = implode(', ', $message['to']);
    $subject = efaturaEncodeMimeHeader((string) ($message['subject'] ?? ''));
    $body = chunk_split(base64_encode((string) ($message['body'] ?? '')));
    $envelopeFrom = trim((string) ($message['from_email'] ?? ''));

    $result = @mail($toHeader, $subject, $body, $headers, $envelopeFrom !== '' ? '-f ' . $envelopeFrom : '');
    if (!$result) {
        throw new RuntimeException('Falha ao enviar email pelo transporte local.');
    }
}

function efaturaSendEmailViaSmtp(array $message): void {
    $host = trim((string) getSetting('smtp_host', ''));
    $port = (int) getSetting('smtp_port', '0');
    $encryption = strtolower(trim((string) getSetting('smtp_encryption', '')));
    $username = trim((string) getSetting('smtp_user', ''));
    $password = (string) getSetting('smtp_pass', '');

    if ($host === '') {
        throw new RuntimeException('Servidor SMTP nao configurado.');
    }

    if ($port <= 0) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    $remoteHost = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($remoteHost . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        throw new RuntimeException('Falha ao ligar ao SMTP: ' . $errstr);
    }

    stream_set_timeout($socket, 20);
    efaturaSmtpExpect($socket, [220], 'ligacao inicial');
    efaturaSmtpCommand($socket, 'EHLO ' . efaturaSmtpClientHost(), [250], 'EHLO');

    if ($encryption === 'tls') {
        efaturaSmtpCommand($socket, 'STARTTLS', [220], 'STARTTLS');
        $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoEnabled !== true) {
            fclose($socket);
            throw new RuntimeException('Nao foi possivel ativar TLS no SMTP.');
        }
        efaturaSmtpCommand($socket, 'EHLO ' . efaturaSmtpClientHost(), [250], 'EHLO pos-TLS');
    }

    if ($username !== '') {
        efaturaSmtpCommand($socket, 'AUTH LOGIN', [334], 'AUTH LOGIN');
        efaturaSmtpCommand($socket, base64_encode($username), [334], 'SMTP utilizador');
        efaturaSmtpCommand($socket, base64_encode($password), [235], 'SMTP password');
    }

    $fromEmail = trim((string) ($message['from_email'] ?? ''));
    $smtpEnvelopeCandidates = efaturaExtractEmailAddresses($username);
    $envelopeFrom = $smtpEnvelopeCandidates[0] ?? $fromEmail;
    efaturaSmtpCommand($socket, 'MAIL FROM:<' . $envelopeFrom . '>', [250], 'MAIL FROM');
    foreach ($message['to'] as $recipient) {
        efaturaSmtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], 'RCPT TO');
    }

    efaturaSmtpCommand($socket, 'DATA', [354], 'DATA');
    $rawMessage = efaturaBuildRawEmailMessage($message);
    $rawMessage = preg_replace("/(?m)^\\./", '..', $rawMessage) ?? $rawMessage;
    fwrite($socket, $rawMessage . "\r\n.\r\n");
    efaturaSmtpExpect($socket, [250], 'corpo da mensagem');
    @fwrite($socket, "QUIT\r\n");
    fclose($socket);
}

function efaturaBuildRawEmailMessage(array $message): string {
    $headers = efaturaBuildEmailHeaders($message, true, true);
    $body = chunk_split(base64_encode((string) ($message['body'] ?? '')));
    return $headers . "\r\n\r\n" . $body;
}

function efaturaBuildEmailHeaders(array $message, bool $includeToHeader = false, bool $includeSubjectHeader = true): string {
    $headers = [];
    if ($includeToHeader) {
        $headers[] = 'To: ' . implode(', ', $message['to']);
    }
    $fromName = trim((string) ($message['from_name'] ?? ''));
    $fromEmail = trim((string) ($message['from_email'] ?? ''));
    $headers[] = 'From: ' . ($fromName !== '' ? efaturaEncodeMimeHeader($fromName) . ' <' . $fromEmail . '>' : $fromEmail);
    if (trim((string) ($message['reply_to'] ?? '')) !== '') {
        $headers[] = 'Reply-To: ' . trim((string) $message['reply_to']);
    }
    if ($includeSubjectHeader) {
        $headers[] = 'Subject: ' . efaturaEncodeMimeHeader((string) ($message['subject'] ?? ''));
    }
    $headers[] = 'Date: ' . date(DATE_RFC2822);
    $headers[] = 'Message-ID: <' . uniqid('efatura_', true) . '@' . efaturaSmtpClientHost() . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: base64';
    $headers[] = 'X-Mailer: AICRM E-fatura';
    return implode("\r\n", $headers);
}

function efaturaEncodeMimeHeader(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function efaturaSmtpClientHost(): string {
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost.localdomain')));
    $host = preg_replace('/:\d+$/', '', $host) ?? 'localhost.localdomain';
    return $host !== '' ? $host : 'localhost.localdomain';
}

function efaturaSmtpCommand($socket, string $command, array $expectedCodes, string $context): string {
    fwrite($socket, $command . "\r\n");
    return efaturaSmtpExpect($socket, $expectedCodes, $context);
}

function efaturaSmtpExpect($socket, array $expectedCodes, string $context): string {
    $response = efaturaSmtpReadResponse($socket);
    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP falhou em ' . $context . ': ' . trim($response));
    }
    return $response;
}

function efaturaSmtpReadResponse($socket): string {
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }
    if ($response === '') {
        throw new RuntimeException('Resposta vazia do servidor SMTP.');
    }
    return $response;
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

function efaturaNormalizeDbText(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $normalized = strtr($value, [
        "\r\n" => "\n",
        "\r" => "\n",
        '╔' => '+',
        '╗' => '+',
        '╚' => '+',
        '╝' => '+',
        '╠' => '+',
        '╣' => '+',
        '╦' => '+',
        '╩' => '+',
        '╬' => '+',
        '═' => '=',
        '║' => '|',
        '─' => '-',
        '│' => '|',
        '┌' => '+',
        '┐' => '+',
        '└' => '+',
        '┘' => '+',
        '├' => '+',
        '┤' => '+',
        '┬' => '+',
        '┴' => '+',
        '┼' => '+',
    ]);

    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (is_string($ascii) && $ascii !== '') {
            $normalized = $ascii;
        }
    }

    $normalized = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $normalized);
    return trim((string) $normalized);
}

function efaturaShouldRetryWithNormalizedDbText(Throwable $e): bool {
    if (!$e instanceof PDOException) {
        return false;
    }
    return (int) ($e->errorInfo[1] ?? 0) === 1366;
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
    $errorMessageForDb = $errorMessage !== '' ? $errorMessage : null;
    try {
        $stmt->execute([$status, $documentsFound, $documentsSaved, $errorMessageForDb, $status, (int) $job['id']]);
    } catch (Throwable $e) {
        if (!$errorMessageForDb || !efaturaShouldRetryWithNormalizedDbText($e)) {
            throw $e;
        }
        $errorMessageForDb = efaturaNormalizeDbText($errorMessage);
        $stmt->execute([$status, $documentsFound, $documentsSaved, $errorMessageForDb !== '' ? $errorMessageForDb : null, $status, (int) $job['id']]);
        $errorMessage = $errorMessageForDb;
    }
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

        reconcileEfaturaDocumentWithAccountingImport($pdo, $documentId, [
            'id' => $documentId,
            'source_hash' => $sourceHash,
            'issuer_vat' => trim((string) ($document['issuer_vat'] ?? '')),
            'customer_vat' => trim((string) ($document['customer_vat'] ?? '')),
            'invoice_no' => $invoiceNo,
            'invoice_date' => $invoiceDate,
            'atcud' => trim((string) ($document['atcud'] ?? '')),
        ]);

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
