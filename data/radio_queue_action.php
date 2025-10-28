<?php
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$queueId = $_POST['queue_id'] ?? '';
if ($queueId === '' || $action === '') {
    echo json_encode(['success' => false, 'error' => 'Pedido inválido.']);
    exit;
}

$stationId = isset($_POST['station']) ? (int)$_POST['station'] : 1;
if ($stationId < 1) {
    $stationId = 1;
}

$apiBase = trim(getSetting('radio_api_base_url', ''));
if ($apiBase === '') {
    $envBase = getenv('RADIO_API_BASE_URL');
    if ($envBase) {
        $apiBase = trim($envBase);
    }
}
if ($apiBase === '') {
    echo json_encode(['success' => false, 'error' => 'Endpoint da rádio não configurado.']);
    exit;
}

$apiKey = trim(getSetting('radio_api_key', ''));
if ($apiKey === '') {
    $envKey = getenv('RADIO_API_KEY');
    if ($envKey) {
        $apiKey = trim($envKey);
    }
}

$endpoint = '';
$method = 'POST';
switch ($action) {
    case 'upvote':
        $endpoint = sprintf('/admin/station/%d/queue/%s/promote', $stationId, rawurlencode($queueId));
        break;
    case 'delete':
        $endpoint = sprintf('/admin/station/%d/queue/%s', $stationId, rawurlencode($queueId));
        $method = 'DELETE';
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Ação desconhecida.']);
        exit;
}

$url = rtrim($apiBase, '/') . $endpoint;

$options = [
    'http' => [
        'method' => $method,
        'header' => [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: CMS-Pombaldir/1.0',
            'X-API-Key: ' . $apiKey,
        ],
        'timeout' => 5,
        'ignore_errors' => true,
    ],
];

if ($method === 'POST') {
    $options['http']['content'] = '{}';
}

$context = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
if ($response === false) {
    echo json_encode(['success' => false, 'error' => 'Não foi possível comunicar com a rádio.']);
    exit;
}

$statusLine = $http_response_header[0] ?? '';
preg_match('#\s(\d{3})\s#', $statusLine, $matches);
$statusCode = isset($matches[1]) ? (int)$matches[1] : 0;

if ($statusCode >= 200 && $statusCode < 300) {
    echo json_encode(['success' => true]);
    exit;
}

$body = json_decode($response, true);
$errorMessage = $body['message'] ?? $body['error'] ?? 'A rádio devolveu um erro.';

echo json_encode([
    'success' => false,
    'error' => $errorMessage,
]);
