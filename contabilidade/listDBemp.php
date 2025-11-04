<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('curl_init')) {
    echo json_encode([
        'success' => false,
        'error' => 'Extensão cURL não disponível.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$baseUrl = trim((string) getSetting('erp_webservice_url', ''));
if ($baseUrl === '') {
    echo json_encode([
        'success' => false,
        'error' => 'URL do webservice ERP não configurada.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$endpoint = buildErpEndpointFromBase($baseUrl, 'contabilidade');
$sanitizedEndpoint = sanitizeUrlForLog($endpoint);

$handle = curl_init($endpoint);
if ($handle === false) {
    logErpMessage('Não foi possível iniciar pedido ao endpoint listDBemp.' . ($sanitizedEndpoint ? ' URL: ' . $sanitizedEndpoint : ''));
    echo json_encode([
        'success' => false,
        'error' => 'Não foi possível contactar o serviço de contabilidade.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payloadArray = [
    'act' => 'listDBemp',
];

$payload = json_encode($payloadArray, JSON_UNESCAPED_UNICODE);
if (!is_string($payload)) {
    logErpMessage('Falha ao codificar pedido JSON para listDBemp.');
    echo json_encode([
        'success' => false,
        'error' => 'Não foi possível preparar o pedido para o serviço de contabilidade.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$headers = [
    'Accept: application/json',
    'Content-Type: application/json; charset=utf-8'
];

$token = trim((string) getSetting('erp_token', ''));
if ($token !== '') {
    $headers[] = 'X-API-KEY: ' . $token;
}

curl_setopt_array($handle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POST => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_POSTFIELDS => $payload,
]);

$response = curl_exec($handle);


if ($response === false) {
    $error = curl_error($handle);
    curl_close($handle);
    logErpMessage('Erro cURL ao obter listDBemp: ' . $error . ($sanitizedEndpoint ? ' URL: ' . $sanitizedEndpoint : ''));
    echo json_encode([
        'success' => false,
        'error' => 'Não foi possível obter a lista de bases de dados.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
curl_close($handle);

if ($status >= 400) {
    logErpMessage('ERP-SINC devolveu HTTP ' . $status . ' ao solicitar listDBemp.' . ($sanitizedEndpoint ? ' URL: ' . $sanitizedEndpoint : ''));
    echo json_encode([
        'success' => false,
        'error' => 'O serviço de contabilidade devolveu um erro.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$trimmedResponse = trim((string) $response);
if ($trimmedResponse === '') {
    logErpMessage('Endpoint listDBemp devolveu uma resposta vazia.' . ($sanitizedEndpoint ? ' URL: ' . $sanitizedEndpoint : ''));
    echo json_encode([
        'success' => false,
        'error' => 'Resposta vazia do serviço de contabilidade.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode($trimmedResponse, true);
if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
    logErpMessage('Resposta inválida do endpoint listDBemp: ' . substr((string) $response, 0, 300) . ($sanitizedEndpoint ? ' URL: ' . $sanitizedEndpoint : ''));
    echo json_encode([
        'success' => false,
        'error' => 'Resposta inválida do serviço de contabilidade.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($decoded)) {
    $decoded = [];
}

$optionsPayload = $decoded;
if (array_key_exists('options', $decoded) && is_array($decoded['options'])) {
    $optionsPayload = $decoded['options'];
} elseif (array_key_exists('data', $decoded) && is_array($decoded['data'])) {
    $optionsPayload = $decoded['data'];
} elseif (array_key_exists('result', $decoded) && is_array($decoded['result'])) {
    $optionsPayload = $decoded['result'];
} elseif (array_key_exists('list', $decoded) && is_array($decoded['list'])) {
    $optionsPayload = $decoded['list'];
}

if (!is_array($optionsPayload)) {
    $optionsPayload = [];
}

$seenValues = [];
$options = [];

foreach ($optionsPayload as $entry) {
    if (!is_array($entry)) {
        continue;
    }

    $normalized = [];
    foreach ($entry as $key => $value) {
        if (!is_string($key)) {
            continue;
        }
        $normalizedKey = strtolower($key);
        $normalized[$normalizedKey] = $value;
    }

    $value = '';
    if (array_key_exists('value', $entry) && trim((string) $entry['value']) !== '') {
        $value = trim((string) $entry['value']);
    } elseif (array_key_exists('value', $normalized) && trim((string) $normalized['value']) !== '') {
        $value = trim((string) $normalized['value']);
    }

    if ($value === '') {
        continue;
    }

    if (array_key_exists($value, $seenValues)) {
        continue;
    }

    $label = '';
    $labelKeys = ['db', 'label', 'descricao', 'description', 'nome', 'name'];
    foreach ($labelKeys as $labelKey) {
        if (array_key_exists($labelKey, $entry) && trim((string) $entry[$labelKey]) !== '') {
            $label = trim((string) $entry[$labelKey]);
            break;
        }
        if (array_key_exists($labelKey, $normalized) && trim((string) $normalized[$labelKey]) !== '') {
            $label = trim((string) $normalized[$labelKey]);
            break;
        }
    }

    if ($label === '') {
        $label = $value;
    }

    $seenValues[$value] = true;
    $options[] = [
        'value' => $value,
        'db' => $label,
    ];
}

if (empty($options)) {
    echo json_encode([
        'success' => false,
        'error' => 'Nenhuma base de dados disponível.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'success' => true,
    'options' => $options,
], JSON_UNESCAPED_UNICODE);

