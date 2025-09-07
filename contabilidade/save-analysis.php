<?php
require_once __DIR__ . '/../functions.php';

startSession();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$newToken = generateCsrfToken();

$slug = getCompanySlug();
if (!$slug) {
    http_response_code(400);
    echo json_encode(['error' => 'Empresa não selecionada', 'csrf_token' => $newToken]);
    exit;
}

if ($action === 'get') {
    $token = $_GET['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => $newToken]);
        exit;
    }
    $a = $_GET['A'] ?? '';
    $b = $_GET['B'] ?? '';
    $d = $_GET['D'] ?? '';
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT account FROM accounting_classifications WHERE company_slug = ? AND emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1');
    $stmt->execute([$slug, $a, $b, $d]);
    $account = $stmt->fetchColumn() ?: '';
    echo json_encode(['account' => $account, 'csrf_token' => $newToken]);
    exit;
} elseif ($action === 'save') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => $newToken]);
        exit;
    }
    $a = $_POST['A'] ?? '';
    $b = $_POST['B'] ?? '';
    $d = $_POST['D'] ?? '';
    $account = $_POST['account'] ?? '';
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO accounting_classifications (company_slug, emitter, acquirer, doc_type, account) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE account = VALUES(account)');
    $stmt->execute([$slug, $a, $b, $d, $account]);
    echo json_encode(['success' => true, 'csrf_token' => $newToken]);
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida', 'csrf_token' => $newToken]);
    exit;
}
