<?php
// Endpoint do Web Push (rota "push/handler"), usado por assets/js/push.js.
// POST action=subscribe   endpoint p256dh auth   grava a subscricao deste browser
// POST action=unsubscribe endpoint               remove-a (desligar no perfil)
// POST action=test                               envia um push de teste aos dispositivos do utilizador
// Os POST exigem csrf_token (nao consumido). Ver push/functions.php.

require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(['ok' => false, 'error' => 'Método não suportado.'], 405);
}
if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''), false)) {
    $respond(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 400);
}
if (!pushHasTable()) {
    $respond(['ok' => false, 'error' => 'Execute as migrações pendentes para ativar as notificações push.'], 503);
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
session_write_close();
$pdo = getPDO();
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'subscribe') {
        pushSubscribe($pdo, $userId, (string) ($_POST['endpoint'] ?? ''), (string) ($_POST['p256dh'] ?? ''), (string) ($_POST['auth'] ?? ''), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $respond(['ok' => true, 'devices' => pushDeviceCount($pdo, $userId)]);
    }
    if ($action === 'unsubscribe') {
        pushUnsubscribe($pdo, $userId, (string) ($_POST['endpoint'] ?? ''));
        $respond(['ok' => true, 'devices' => pushDeviceCount($pdo, $userId)]);
    }
    if ($action === 'test') {
        $appName = trim((string) getSetting('app_name', '')) ?: 'a app';
        $result = pushSendToUser($pdo, $userId, pushPayload([
            'title' => 'Notificações ativas',
            'body' => 'Vai receber aqui os convites e lembretes do calendário, mesmo com ' . $appName . ' fechado.',
            'tag' => 'push-test',
            'url' => BASE_URL . 'dashboard',
        ]));
        $respond(['ok' => true] + $result);
    }
    $respond(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (InvalidArgumentException $e) {
    $respond(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    error_log('[push] ' . $e->getMessage());
    $respond(['ok' => false, 'error' => 'Erro interno ao gravar a subscrição.'], 500);
}
