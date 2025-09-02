<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

header('Content-Type: application/json');

$typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
$filterField = $_GET['filter_field'] ?? '';
$filterValue = $_GET['filter_value'] ?? '';

if (!$typeId) {
    echo json_encode(['entries' => []]);
    exit;
}

$filters = [];
if ($filterField !== '' && $filterValue !== '') {
    $filters[$filterField] = $filterValue;
}

$entries = getContentList($typeId, $filters);
$result = array_map(function ($entry) {
    return [
        'id' => $entry['id'],
        'title' => $entry['title']
    ];
}, $entries);

echo json_encode(['entries' => $result]);
