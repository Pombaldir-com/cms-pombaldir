<?php
require_once __DIR__ . '/../data/db.php';

$filePath = $_POST['file_path'] ?? null;
$field1   = $_POST['field1'] ?? null;
$field2   = $_POST['field2'] ?? null;

if ($filePath === null || $field1 === null || $field2 === null) {
    http_response_code(400);
    echo 'Missing data';
    exit;
}

$pdo = getPDO();
$stmt = $pdo->prepare('INSERT INTO accounting_documents (file_path, field1, field2, created_at) VALUES (?, ?, ?, NOW())');
$stmt->execute([$filePath, $field1, $field2]);

echo 'ok';

