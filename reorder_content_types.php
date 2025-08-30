<?php
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order']) && is_array($_POST['order'])) {
    reorderContentTypes(array_map('intval', $_POST['order']));
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok']);
    exit;
}
http_response_code(400);
?>
