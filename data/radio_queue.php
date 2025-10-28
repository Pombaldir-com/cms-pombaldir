<?php
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

header('Content-Type: application/json');

$stationId = isset($_GET['station']) ? (int)$_GET['station'] : 1;
if ($stationId < 1) {
    $stationId = 1;
}

$apiUrl = trim(getSetting('radio_nowplaying_url', ''));
if ($apiUrl === '') {
    $baseUrl = trim(getSetting('radio_api_base_url', ''));
    if ($baseUrl !== '') {
        $apiUrl = rtrim($baseUrl, '/') . '/nowplaying/' . $stationId;
    }
}
if ($apiUrl === '') {
    $envUrl = getenv('RADIO_NOWPLAYING_URL');
    if ($envUrl) {
        $apiUrl = trim($envUrl);
    }
}
if ($apiUrl === '') {
    $apiUrl = 'https://radio.pombaldir.com/api/nowplaying/' . $stationId;
}

function fetchRadioQueue(string $url): array {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'Accept: application/json',
                'User-Agent: CMS-Pombaldir/1.0'
            ],
            'timeout' => 5,
        ],
        'https' => [
            'method' => 'GET',
            'header' => [
                'Accept: application/json',
                'User-Agent: CMS-Pombaldir/1.0'
            ],
            'timeout' => 5,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return [];
    }

    $json = json_decode($response, true);
    if (!is_array($json)) {
        return [];
    }

    if (isset($json['queue']) && is_array($json['queue'])) {
        return $json['queue'];
    }

    if (isset($json[0]['queue']) && is_array($json[0]['queue'])) {
        return $json[0]['queue'];
    }

    return [];
}

$queueItems = fetchRadioQueue($apiUrl);
if (!$queueItems) {
    echo json_encode([
        'data' => [],
        'error' => 'Não foi possível carregar a fila da rádio.'
    ]);
    exit;
}

$data = [];
$position = 1;
foreach ($queueItems as $item) {
    $song = $item['song'] ?? [];
    $title = $song['title'] ?? ($item['title'] ?? 'Desconhecido');
    $artist = $song['artist'] ?? ($item['artist'] ?? '');
    $duration = isset($item['duration']) ? (int)$item['duration'] : (int)($song['duration'] ?? 0);
    $queueId = $item['queue_id'] ?? $item['id'] ?? '';
    $requester = '';
    if (!empty($item['request_id']) || !empty($item['requester'])) {
        $requester = $item['requester'] ?? 'Pedido';
    }

    $scheduledFor = '';
    if (!empty($item['cued_at'])) {
        $scheduledFor = date('d/m/Y H:i', (int)$item['cued_at']);
    } elseif (!empty($item['played_at'])) {
        $scheduledFor = date('d/m/Y H:i', (int)$item['played_at']);
    }

    $actions = '';
    if ($queueId !== '') {
        $actions = '<div class="btn-group" role="group">';
        $actions .= '<button type="button" class="btn btn-sm btn-outline-success js-radio-action" data-action="upvote" data-queue-id="' . htmlspecialchars($queueId, ENT_QUOTES, 'UTF-8') . '"><i class="fa fa-thumbs-up"></i></button>';
        $actions .= '<button type="button" class="btn btn-sm btn-outline-danger js-radio-action" data-action="delete" data-queue-id="' . htmlspecialchars($queueId, ENT_QUOTES, 'UTF-8') . '"><i class="fa fa-trash"></i></button>';
        $actions .= '</div>';
    }

    $data[] = [
        'position' => $position++,
        'title' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
        'artist' => htmlspecialchars($artist, ENT_QUOTES, 'UTF-8'),
        'duration' => $duration,
        'requester' => htmlspecialchars($requester, ENT_QUOTES, 'UTF-8'),
        'scheduled_for' => $scheduledFor,
        'actions' => $actions,
    ];
}

echo json_encode(['data' => $data]);
