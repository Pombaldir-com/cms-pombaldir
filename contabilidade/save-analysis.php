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

if ($action === 'get') {
    $token = $_GET['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $a = $_GET['A'] ?? '';
    $b = $_GET['B'] ?? '';
    $d = $_GET['D'] ?? '';
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1');
    $stmt->execute([$a, $b, $d]);
    $account = $stmt->fetchColumn() ?: '';
    echo json_encode(['account' => $account, 'csrf_token' => generateCsrfToken()]);
    exit;
} elseif ($action === 'save') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $id = $_POST['id'] ?? '';
    $a = $_POST['A'] ?? '';
    $b = $_POST['B'] ?? '';
    $d = $_POST['D'] ?? '';
    $account = $_POST['account'] ?? '';
    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE accounting_imports SET account = ? WHERE id = ?');
        $stmt->execute([$account, $id]);
        $stmt2 = $pdo->prepare('INSERT INTO accounting_classifications (emitter, acquirer, doc_type, account) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE account = VALUES(account)');
        $stmt2->execute([$a, $b, $d, $account]);
        $pdo->commit();
        echo json_encode(['success' => true, 'csrf_token' => generateCsrfToken()]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao guardar', 'csrf_token' => generateCsrfToken()]);
    }
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida', 'csrf_token' => generateCsrfToken()]);
    exit;
}
