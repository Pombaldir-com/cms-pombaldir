<?php
require_once __DIR__ . '/../functions.php';

startSession();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$newToken = generateCsrfToken();

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos', 'csrf_token' => $newToken]);
    exit;
}

$token = $data['csrf_token'] ?? '';
if (!validateCsrfToken($token)) {
    http_response_code(400);
    echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => $newToken]);
    exit;
}

$rows = $data['rows'] ?? [];
if (!is_array($rows)) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos', 'csrf_token' => $newToken]);
    exit;
}

$slug = getCompanySlug();
if (!$slug) {
    http_response_code(500);
    echo json_encode(['error' => 'Empresa não selecionada', 'csrf_token' => $newToken]);
    exit;
}

$year = date('Y');
$month = date('m');
$dir = dirname(__DIR__) . '/uploads/' . $slug . '/accounting/' . $year . '/' . $month . '/';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao criar diretório de importação', 'csrf_token' => $newToken]);
    exit;
}

$file = $dir . 'import.json';
$success = file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;

if ($success) {
    echo json_encode(['success' => true, 'csrf_token' => $newToken]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Falha ao gravar o ficheiro', 'csrf_token' => $newToken]);
}

