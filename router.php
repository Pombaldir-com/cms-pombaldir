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
        require __DIR__ . '/logout.php';
        break;
    case $path === 'editar-perfil':
        require __DIR__ . '/editar_perfil.php';
        break;
    case $path === 'definicoes':
        require __DIR__ . '/definicoes.php';
        break;
    case $path === 'dashboard':
        require __DIR__ . '/dashboard.php';
        break;
    case $path === 'content-types':
        require __DIR__ . '/content_types.php';
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
    case preg_match('#^([0-9]+)/edit-field/([0-9]+)$#', $path, $m):
        // Edit a specific custom field
        $_GET['type_id'] = $m[1];
        $_GET['edit_id'] = $m[2];
        require __DIR__ . '/custom_fields.php';
        break;
    case preg_match('#^([0-9]+)$#', $path, $m):
        // Custom fields of a content type by numeric ID, e.g. "/cms/3"
        $_GET['type_id'] = $m[1];
        require __DIR__ . '/custom_fields.php';
        break;
    case preg_match('#^tipode-conteudo/([^/]+)/add$#', $path, $m):
        $_GET['type_slug'] = $m[1];
        require __DIR__ . '/add_content.php';
        break;
    case preg_match('#^tipode-conteudo/([^/]+)/([0-9]+)$#', $path, $m):
        $_GET['id'] = $m[2];
        // Optional slug is $m[1] if needed in the script
        $_GET['type_slug'] = $m[1];
        require __DIR__ . '/edit_content.php';
        break;
    case preg_match('#^tipode-conteudo/([^/]+)$#', $path, $m):
        $_GET['type_slug'] = $m[1];
        require __DIR__ . '/list_content.php';
        break;
    default:
    print_r($path);
        http_response_code(404);
        echo 'Page not found';
}
