<?php
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
requireRole(2);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metodo nao permitido.');
}

$token = trim((string) ($_POST['csrf_token'] ?? ''));
if (!validateCsrfToken($token)) {
    http_response_code(400);
    exit('Token invalido.');
}

$result = runProjectMigrationsFromUi();
$output = is_array($result['output'] ?? null) ? $result['output'] : [];
$message = $result['ok']
    ? 'Migrações executadas com sucesso.'
    : 'A execução das migrações terminou com erros.';

setSessionFlash('migration_runner', [
    'type' => $result['ok'] ? 'success' : 'error',
    'message' => $message,
    'output' => array_slice($output, -12),
]);

$redirect = normalizeRedirectTarget($_SERVER['HTTP_REFERER'] ?? null);
if ($redirect === null) {
    $redirect = BASE_URL . 'dashboard';
}

header('Location: ' . $redirect);
exit;
