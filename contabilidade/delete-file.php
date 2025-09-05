<?php
require_once __DIR__ . '/../functions.php';

startSession();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $newToken = generateCsrfToken(true);
    http_response_code(400);
    echo json_encode([
        'error' => 'Token CSRF inválido',
        'csrf_token' => $newToken,
    ]);
    exit;
}

$file = $_POST['file'] ?? '';
$slug = getCompanySlug();
$baseDir = realpath(dirname(__DIR__) . '/uploads/' . $slug . '/accounting/');
$newToken = generateCsrfToken();

if ($file === '' || !$baseDir) {
    http_response_code(400);
    echo json_encode(['error' => 'Ficheiro inválido', 'csrf_token' => $newToken]);
    exit;
}

$fullPath = realpath(dirname(__DIR__) . '/' . ltrim($file, '/'));
if ($fullPath === false || strpos($fullPath, $baseDir) !== 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Ficheiro inválido', 'csrf_token' => $newToken]);
    exit;
}

$success = file_exists($fullPath) && unlink($fullPath);
if ($success) {
    echo json_encode(['success' => true, 'csrf_token' => $newToken]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao eliminar o ficheiro ' . $file, 'csrf_token' => $newToken]);
}
