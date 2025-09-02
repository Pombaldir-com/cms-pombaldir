<?php
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if ((int)getSetting('api_enabled', '0') !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'API desativada']);
    exit;
}

$providedToken = $_GET['token'] ?? '';
$apiToken = getApiToken();
if ($apiToken === '' || !hash_equals($apiToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token inválido']);
    exit;
}

$companySlug = getConfiguredCompanySlug();
$slug = trim($_GET['content_type'] ?? '');
$contentType = $slug !== '' ? getContentTypeBySlug($slug) : null;
if (!$contentType || (int)($contentType['api_enabled'] ?? 0) !== 1) {
    http_response_code(404);
    echo json_encode(['error' => 'Tipo de conteúdo não encontrado']);
    exit;
}

$contents = getContentList((int)$contentType['id']);

$fieldDefs = getCustomFields((int)$contentType['id']);
$fieldMap = [];
foreach ($fieldDefs as $f) {
    $fieldMap[$f['id']] = $f['name'];
}

$taxDefs = getTaxonomiesForContentType((int)$contentType['id']);
$taxMap = [];
foreach ($taxDefs as $t) {
    $taxMap[$t['id']] = $t['name'];
}

foreach ($contents as &$c) {
    $c['fields'] = array_map(function ($f) use ($fieldMap) {
        return [
            $fieldMap[$f['field_id']] ?? $f['field_id'] => $f['value'],
        ];
    }, $c['fields']);
    $c['taxonomies'] = array_map(function ($t) use ($taxMap) {
        return [
            'taxonomy' => $taxMap[$t['taxonomy_id']] ?? $t['taxonomy_id'],
            'term' => $t['term_name'],
        ];
    }, $c['taxonomies']);
}
unset($c);

$response = [
    'company_slug' => $companySlug,
    'content_type' => [
        'id' => (int)$contentType['id'],
        'slug' => $contentType['name'],
        'label' => $contentType['label'],
    ],
    'content' => $contents,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
