<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if ((int)getSetting('api_enabled', '0') !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'API desativada']);
    exit;
}

$providedToken = $_GET['token'] ?? '';
$apiToken = getSetting('api_token', '');
if ($apiToken === '' || !hash_equals($apiToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$slug = $_GET['taxonomy_slug'] ?? '';
$taxonomy = $slug !== '' ? getTaxonomyBySlug($slug) : null;
if (!$taxonomy) {
    http_response_code(404);
    echo json_encode(['error' => 'Taxonomia não encontrada']);
    exit;
}

$terms = getTerms((int)$taxonomy['id']);
$terms = array_map(function ($t) {
    return ['id' => (int)$t['id'], 'name' => $t['name']];
}, $terms);

$response = [
    'taxonomy' => [
        'id' => (int)$taxonomy['id'],
        'slug' => $taxonomy['name'],
        'label' => $taxonomy['label'],
        'terms' => $terms,
    ],
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
