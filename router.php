<?php
// Simple router to provide friendly URLs throughout the CMS.
// Requests are rewritten here by .htaccess. We inspect the
// request path and include the appropriate script.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // → "/cms"

if ($base && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base)); // remove "/cms" do início
}
$path = trim($path, '/'); // agora $path vira "dashboard", "login", etc.


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
    case $path === 'contabilidade/upload':
        require __DIR__ . '/contabilidade/upload.php';
        break;
    case $path === 'contabilidade/upload-handler.php':
        require __DIR__ . '/contabilidade/upload-handler.php';
        break;
    case $path === 'contabilidade/saft':
        require __DIR__ . '/contabilidade/saft.php';
        break;
    case $path === 'contabilidade/saft-handler.php':
        require __DIR__ . '/contabilidade/saft-handler.php';
        break;
    case $path === 'contabilidade/save-analysis.php':
        require __DIR__ . '/contabilidade/save-analysis.php';
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
        'contabilidade/classificacao-importacao/import-ctb',
        'contabilidade/classificacao-importacao/import_ctb',
        'contabilidade/classificacao-importacao-import-ctb.php'
    ], true):
        $_GET['action'] = 'import_ctb';
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
