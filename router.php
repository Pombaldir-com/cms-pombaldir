<?php
// Simple router to provide friendly URLs throughout the CMS.
// Requests are rewritten here by .htaccess. We inspect the
// request path and include the appropriate script.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$fullPath = __DIR__ . $path;
if ($path !== '/' && is_file($fullPath)) {
    return false;
}
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$base = '';
if ($scriptName !== '' && str_ends_with($scriptName, '/router.php')) {
    $base = rtrim(dirname($scriptName), '/'); // → "/cms"
} else {
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docRoot !== '' && strpos(__DIR__, $docRoot) === 0) {
        $base = rtrim(substr(__DIR__, strlen($docRoot)), '/');
    }
}

if ($base && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base)); // remove "/cms" do início
}
$path = trim($path, '/'); // agora $path vira "dashboard", "login", etc.

if (preg_match('#^t/([A-Za-z0-9_-]+)/cliente(?:/(.*))?$#', $path, $tenantMatch)) {
    require_once __DIR__ . '/functions.php';
    startSession();
    $tenantSlug = trim((string) ($tenantMatch[1] ?? ''));
    $clientPath = trim((string) ($tenantMatch[2] ?? ''));

    if (!ensureTenantCompanyBySlug($tenantSlug)) {
        http_response_code(404);
        echo 'Tenant nao encontrada.';
        exit;
    }

    $_GET['tenant_slug'] = $tenantSlug;
    $_GET['client_path'] = $clientPath;
    if ($clientPath === '' || $clientPath === 'dashboard') {
        require __DIR__ . '/client/dashboard.php';
        exit;
    }
    if ($clientPath === 'login') {
        require __DIR__ . '/client/login.php';
        exit;
    }
    if ($clientPath === 'logout') {
        require __DIR__ . '/client/logout.php';
        exit;
    }
    if ($clientPath === 'documentos') {
        require __DIR__ . '/client/documentos.php';
        exit;
    }

    http_response_code(404);
    echo 'Page not found';
    exit;
}

switch (true) {
    case $path === '':
        require __DIR__ . '/index.php';
        break;
    case $path === 'login':
        require __DIR__ . '/login.php';
        break;
    case $path === 'terminar-sessao':
    case $path === 'logout':
        $_GET['action'] = 'logout';
        require __DIR__ . '/login.php';
        break;
    case $path === 'definicoes':
        require __DIR__ . '/definicoes.php';
        break;
    case $path === 'system/run-migrations':
        require __DIR__ . '/system-run-migrations.php';
        break;
    case preg_match('#^api/([A-Za-z0-9_-]+)$#', $path, $m):
        $_GET['taxonomy_slug'] = $m[1];
        require __DIR__ . '/api.php';
        break;
    case $path === 'users':
        require __DIR__ . '/users.php';
        break;
    case $path === 'users/add':
        $_GET['action'] = 'add';
        require __DIR__ . '/users.php';
        break;
    case preg_match('#^users/edit/([0-9]+)$#', $path, $m):
        $_GET['action'] = 'edit';
        $_GET['id'] = $m[1];
        require __DIR__ . '/users.php';
        break;
    case preg_match('#^users/delete/([0-9]+)$#', $path, $m):
        $_GET['delete_id'] = $m[1];
        require __DIR__ . '/users.php';
        break;
    case $path === 'users/profile':
        $_GET['profile'] = 1;
        require __DIR__ . '/users.php';
        break;
    case $path === 'dashboard':
        require __DIR__ . '/dashboard.php';
        break;
    case $path === 'assistant':
        require __DIR__ . '/assistant.php';
        break;
    case $path === 'chat-interno':
        require __DIR__ . '/internal-chat.php';
        break;
    case $path === 'chat-interno-handler':
        require __DIR__ . '/internal-chat-handler.php';
        break;
    case $path === 'content-types':
        $_GET['manage_types'] = 1;
        require __DIR__ . '/content.php';
        break;
    case $path === 'content-types/add':
        $_GET['act'] = 'ad';
        $_GET['manage_types'] = 1;
        require __DIR__ . '/content.php';
        break;
    case preg_match('#^content-types/edit/([0-9]+)$#', $path, $m):
        $_GET['id'] = $m[1];
        $_GET['manage_types'] = 1;
        require __DIR__ . '/content.php';
        break;
    case $path === 'taxonomies/add':
        // Create a new taxonomy
        $_GET['act'] = 'ad';
        require __DIR__ . '/taxonomies.php';
        break;
    case preg_match('#^taxonomies/edit-terms/([0-9]+)/add$#', $path, $m):
        // Add a term to a taxonomy
        $_GET['taxonomy_id'] = $m[1];
        $_GET['act'] = 'ad';
        require __DIR__ . '/taxonomies.php';
        break;
    case preg_match('#^taxonomies/edit-terms/([0-9]+)/edit/([0-9]+)$#', $path, $m):
        // Edit a specific term
        $_GET['taxonomy_id'] = $m[1];
        $_GET['term_edit_id'] = $m[2];
        $_GET['act'] = 'ad';
        require __DIR__ . '/taxonomies.php';
        break;
    case preg_match('#^taxonomies/edit-terms/([0-9]+)/delete/([0-9]+)$#', $path, $m):
        // Delete a specific term
        $_GET['taxonomy_id'] = $m[1];
        $_GET['term_delete_id'] = $m[2];
        require __DIR__ . '/taxonomies.php';
        break;
    case preg_match('#^taxonomies/edit-terms/([0-9]+)$#', $path, $m):
        // List terms for a taxonomy
        $_GET['taxonomy_id'] = $m[1];
        require __DIR__ . '/taxonomies.php';
        break;
    case preg_match('#^taxonomies/edit/([0-9]+)$#', $path, $m):
        // Edit an existing taxonomy
        $_GET['edit_id'] = $m[1];
        require __DIR__ . '/taxonomies.php';
        break;
    case preg_match('#^taxonomies/delete/([0-9]+)$#', $path, $m):
        // Delete a taxonomy
        $_GET['delete_id'] = $m[1];
        require __DIR__ . '/taxonomies.php';
        break;
    case $path === 'taxonomies':
        // List all taxonomies
        require __DIR__ . '/taxonomies.php';
        break;
    case $path === 'tabelas/departamentos':
        require __DIR__ . '/departments.php';
        break;
    case $path === 'tabelas/departamentos/add':
        $_GET['action'] = 'add';
        require __DIR__ . '/departments.php';
        break;
    case preg_match('#^tabelas/departamentos/edit/([0-9]+)$#', $path, $m):
        $_GET['action'] = 'edit';
        $_GET['id'] = $m[1];
        require __DIR__ . '/departments.php';
        break;
    case $path === 'tabelas/campos-adicionais':
        require __DIR__ . '/additional_fields.php';
        break;
    case preg_match('#^fields/([0-9]+)/ad$#', $path, $m):
        // Add a custom field to a content type, e.g. "/cms/fields/3/ad"
        $_GET['type_id'] = $m[1];
        $_GET['act'] = 'ad';

        require __DIR__ . '/custom_fields.php';
        break;
    case preg_match('#^fields/layout/([0-9]+)$#', $path, $m):
        $_GET['type_id'] = $m[1];
        $_GET['layout'] = 1;
        require __DIR__ . '/custom_fields.php';
        break;
    case $path === 'fields/save-layout':
        require_once __DIR__ . '/functions.php';
        startSession();
        requireLogin();
        requireRole(2);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            updateFieldLayout(
                $_POST['field_id'] ?? 0,
                (int) ($_POST['type_id'] ?? 0),
                (int) ($_POST['row'] ?? 0),
                (int) ($_POST['col'] ?? 0),
                (int) ($_POST['width'] ?? 1)
            );
            echo 'ok';
        }
        break;
    case preg_match('#^fields/edit-field/([0-9]+)$#', $path, $m):
        // Edit a custom field, e.g. "/cms/fields/edit-field/10"
        $_GET['edit_id'] = $m[1];
        require __DIR__ . '/custom_fields.php';
        break;
    case preg_match('#^fields/([0-9]+)$#', $path, $m):
        // Custom fields of a content type by numeric ID, e.g. "/cms/fields/3"
        $_GET['type_id'] = $m[1];
        require __DIR__ . '/custom_fields.php';
        break;
    case preg_match('#^content-types/taxonomies/([0-9]+)$#', $path, $m):
        $_GET['type_id'] = $m[1];
        $_GET['manage_types'] = 1;
        require __DIR__ . '/content.php';
        break;
    case $path === 'upload':
    case $path === 'upload.php':
    case $path === 'contabilidade/upload':
    case $path === 'contabilidade/upload.php':
        require __DIR__ . '/contabilidade/upload.php';
        break;
    case $path === 'upload-handler.php':
    case $path === 'contabilidade/upload-handler.php':
        require __DIR__ . '/contabilidade/upload-handler.php';
        break;
    case $path === 'contabilidade/saft':
        require __DIR__ . '/contabilidade/saft.php';
        break;
    case $path === 'contabilidade/entidades':
        $_GET['tipo'] = 'empresas';
        require __DIR__ . '/contabilidade/entidades.php';
        break;
    case preg_match('#^contabilidade/entidades/([A-Za-z0-9_-]+)$#', $path, $m):
        $_GET['tipo'] = $m[1];
        require __DIR__ . '/contabilidade/entidades.php';
        break;
    case preg_match('#^contabilidade/entidades/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/fornecedores$#', $path, $m):
        $_GET['tipo'] = $m[1];
        $_GET['fornecedores'] = $m[2];
        require __DIR__ . '/contabilidade/entidades.php';
        break;
    case preg_match('#^contabilidade/entidades/([A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)$#', $path, $m):
        $_GET['tipo'] = $m[1];
        $_GET['consulta'] = $m[2];
        require __DIR__ . '/contabilidade/entidades.php';
        break;
    case $path === 'contabilidade/saft-handler.php':
        require __DIR__ . '/contabilidade/saft-handler.php';
        break;
    case $path === 'contabilidade/lancamentos':
        require __DIR__ . '/contabilidade/lancamentos.php';
        break;
    case $path === 'contabilidade/efatura':
        $_GET['view'] = 'empresas';
        require __DIR__ . '/contabilidade/efatura.php';
        break;
    case preg_match('#^contabilidade/efatura/(empresas|documentos|sincronizacoes)$#', $path, $m):
        $_GET['view'] = $m[1];
        require __DIR__ . '/contabilidade/efatura.php';
        break;
    case in_array($path, [
        'contabilidade/efatura/sync',
        'contabilidade/efatura-sync.php'
    ], true):
        $_GET['action'] = 'sync';
        require __DIR__ . '/contabilidade/efatura.php';
        break;
    case in_array($path, [
        'contabilidade/efatura/sync-status',
        'contabilidade/efatura-sync-status.php'
    ], true):
        $_GET['action'] = 'sync_status';
        require __DIR__ . '/contabilidade/efatura.php';
        break;
    case $path === 'contabilidade/ai-tarefas':
        require __DIR__ . '/contabilidade/ai-tarefas.php';
        break;
    case $path === 'contabilidade/save-analysis.php':
        require __DIR__ . '/contabilidade/save-analysis.php';
        break;
    case in_array($path, [
        'contabilidade/listDBemp',
        'contabilidade/listDBemp.php'
    ], true):
        require __DIR__ . '/contabilidade/listDBemp.php';
        break;
    case in_array($path, [
        'contabilidade/classificacao-importacao/data',
        'contabilidade/classificacao-importacao-data',
        'contabilidade/classificacao-importacao-data.php'
    ], true):
        $_GET['action'] = 'data';
        require __DIR__ . '/contabilidade/classificacao-importacao.php';
        break;
    case in_array($path, [
        'contabilidade/classificacao-importacao/ready-ids',
        'contabilidade/classificacao-importacao/ready_ids',
        'contabilidade/classificacao-importacao-ready-ids.php'
    ], true):
        $_GET['action'] = 'ready_ids';
        require __DIR__ . '/contabilidade/classificacao-importacao.php';
        break;
    case in_array($path, [
        'contabilidade/classificacao-importacao/import-ctb',
        'contabilidade/classificacao-importacao/import_ctb',
        'contabilidade/classificacao-importacao-import-ctb.php'
    ], true):
        $_GET['action'] = 'import_ctb';
        require __DIR__ . '/contabilidade/classificacao-importacao.php';
        break;
    case in_array($path, [
        'contabilidade/classificacao-importacao/acquirer-database',
        'contabilidade/classificacao-importacao/acquirer_database',
        'contabilidade/classificacao-importacao-acquirer-database.php'
    ], true):
        $_GET['action'] = 'acquirer_database';
        require __DIR__ . '/contabilidade/classificacao-importacao.php';
        break;
    case in_array($path, [
        'contabilidade/classificacao-importacao/suggestion-explanation',
        'contabilidade/classificacao-importacao/suggestion_explanation',
        'contabilidade/classificacao-importacao-suggestion-explanation.php'
    ], true):
        $_GET['action'] = 'suggestion_explanation';
        require __DIR__ . '/contabilidade/classificacao-importacao.php';
        break;
    case in_array($path, [
        'contabilidade/classificacao-importacao/cost-centers',
        'contabilidade/classificacao-importacao/cost_centers',
        'contabilidade/classificacao-importacao-cost-centers.php'
    ], true):
        $_GET['action'] = 'cost_centers';
        require __DIR__ . '/contabilidade/classificacao-importacao.php';
        break;
    case in_array($path, [
        'contabilidade/classificacao-importacao/qr-doc-type-mapping',
        'contabilidade/classificacao-importacao/qr_doc_type_mapping',
        'contabilidade/classificacao-importacao-qr-doc-type-mapping.php'
    ], true):
        $_GET['action'] = 'qr_doc_type_mapping';
        require __DIR__ . '/contabilidade/classificacao-importacao.php';
        break;
    case $path === 'contabilidade/classificacao-importacao':
        require __DIR__ . '/contabilidade/classificacao-importacao.php';
        break;
    case preg_match('#^([^/]+)/add$#', $path, $m):
        $_GET['type_slug'] = $m[1];
        $_GET['action'] = 'add';
        require __DIR__ . '/content.php';
        break;
    case preg_match('#^([^/]+)/edit/([0-9]+)$#', $path, $m):
        $_GET['type_slug'] = $m[1];
        $_GET['id'] = $m[2];
        $_GET['action'] = 'edit';
        require __DIR__ . '/content.php';
        break;
    case preg_match('#^([^/]+)/([0-9]+)$#', $path, $m):
        // Support legacy URLs like "/tipo/3" by redirecting to "/tipo/edit/3"
        header('Location: ' . $base . '/' . rawurlencode($m[1]) . '/edit/' . $m[2]);
        exit;
    case preg_match('#^([^/]+)$#', $path, $m):
        $_GET['type_slug'] = $m[1];
        $_GET['action'] = 'list';
        require __DIR__ . '/content.php';
        break;
    default:
        http_response_code(404);
        echo 'Page not found';
}
