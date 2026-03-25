<?php
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');

function internalChatJsonResponse(int $statusCode, array $payload): void {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isInternalChatEnabled()) {
    internalChatJsonResponse(403, [
        'ok' => false,
        'message' => 'O chat interno esta desativado nas definicoes da aplicacao.',
    ]);
}

if (!hasInternalChatTables()) {
    internalChatJsonResponse(503, [
        'ok' => false,
        'message' => 'O chat interno ainda nao esta disponivel nesta base de dados. Execute as migracoes pendentes.',
    ]);
}

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
if ($userId <= 0) {
    internalChatJsonResponse(401, [
        'ok' => false,
        'message' => 'Sessao invalida.',
    ]);
}

ensureInternalChatPublicChannel();

$action = $_POST['action'] ?? $_GET['action'] ?? 'channels';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCsrfToken($_POST['csrf_token'] ?? '', false)) {
    internalChatJsonResponse(400, [
        'ok' => false,
        'message' => 'Token CSRF invalido.',
    ]);
}

try {
    switch ($action) {
        case 'channels':
            internalChatJsonResponse(200, [
                'ok' => true,
                'channels' => getInternalChatChannelsForUser($userId),
            ]);
            break;

        case 'presence':
            internalChatJsonResponse(200, [
                'ok' => true,
                'users' => getInternalChatPresenceUsers(),
                'counts' => getInternalChatPresenceCounts(),
            ]);
            break;

        case 'summary':
            $afterMessageId = (int) ($_GET['after_message_id'] ?? 0);
            internalChatJsonResponse(200, array_merge([
                'ok' => true,
            ], getInternalChatSummary($userId, $afterMessageId)));
            break;

        case 'messages':
            $channelId = (int) ($_GET['channel_id'] ?? 0);
            if (!userCanAccessInternalChatChannel($userId, $channelId)) {
                internalChatJsonResponse(403, [
                    'ok' => false,
                    'message' => 'Sem acesso ao canal selecionado.',
                ]);
            }

            internalChatJsonResponse(200, [
                'ok' => true,
                'messages' => getInternalChatMessages($userId, $channelId),
            ]);
            break;

        case 'send':
            $channelId = (int) ($_POST['channel_id'] ?? 0);
            $message = (string) ($_POST['message'] ?? '');
            createInternalChatMessage($channelId, $userId, $message);

            internalChatJsonResponse(200, [
                'ok' => true,
                'channels' => getInternalChatChannelsForUser($userId),
                'messages' => getInternalChatMessages($userId, $channelId),
            ]);
            break;

        case 'heartbeat':
            $state = (string) ($_POST['state'] ?? 'online');
            $page = (string) ($_POST['page'] ?? '');
            $touchActivity = isset($_POST['touch_activity'])
                ? ((string) $_POST['touch_activity'] === '1')
                : true;

            upsertInternalChatPresence($userId, $state, $page, $touchActivity);

            internalChatJsonResponse(200, [
                'ok' => true,
                'counts' => getInternalChatPresenceCounts(),
            ]);
            break;

        case 'create_group':
            if ((int) ($user['role'] ?? 3) > 2) {
                internalChatJsonResponse(403, [
                    'ok' => false,
                    'message' => 'Apenas administradores podem criar grupos.',
                ]);
            }

            $name = (string) ($_POST['name'] ?? '');
            $memberIds = $_POST['member_ids'] ?? [];
            if (!is_array($memberIds)) {
                $memberIds = [];
            }

            $channelId = createInternalChatGroup($name, $memberIds, $userId);

            internalChatJsonResponse(200, [
                'ok' => true,
                'channel_id' => $channelId,
                'channels' => getInternalChatChannelsForUser($userId),
            ]);
            break;

        default:
            internalChatJsonResponse(400, [
                'ok' => false,
                'message' => 'Acao invalida.',
            ]);
    }
} catch (InvalidArgumentException $e) {
    internalChatJsonResponse(422, [
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
} catch (RuntimeException $e) {
    internalChatJsonResponse(403, [
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
} catch (Throwable $e) {
    internalChatJsonResponse(500, [
        'ok' => false,
        'message' => 'Erro interno ao processar o chat.',
    ]);
}
