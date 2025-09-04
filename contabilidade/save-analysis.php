<?php
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método inválido']);
    exit;
}

$text = $_POST['text'] ?? '';
$fields = $_POST['fields'] ?? [];
$filename = $_POST['filename'] ?? '';

// Placeholder: persist analysis data as needed.
// For now, simply return success.

echo json_encode(['success' => true]);
