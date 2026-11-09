<?php
// Web Push para os browsers/dispositivos dos utilizadores (app instalada no
// telemovel ou browser), usado pelos avisos do calendario. A subscricao
// pertence ao utilizador, nao a sessao. Tabela push_subscriptions; cifra e
// VAPID em push/webpush.php; no browser assets/js/push.js; service worker
// internal-chat-sw.js. PUSH_OFF=1 no ambiente desliga o envio.
// Ver docs/calendario.md, "Notificacoes push".

require_once dirname(__DIR__) . '/functions.php';
require_once __DIR__ . '/webpush.php';

function pushHasTable(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = hasTable('push_subscriptions');
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

function pushDisabled(): bool {
    return getenv('PUSH_OFF') === '1';
}

/** O servidor consegue enviar push (openssl com EC e curl). */
function pushSupported(): bool {
    return function_exists('openssl_pkey_derive') && function_exists('curl_init') && function_exists('hash_hkdf');
}

/** Grava a subscricao de um browser (mesmo endpoint = mesma linha, passa para este utilizador). */
function pushSubscribe(PDO $pdo, int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): void {
    if ($userId <= 0 || preg_match('#^https://[^\s]+$#i', $endpoint) !== 1 || strlen($endpoint) > 1000
        || strlen(webpushB64urlDecode($p256dh)) !== 65 || strlen(webpushB64urlDecode($auth)) !== 16) {
        throw new InvalidArgumentException('Subscrição inválida.');
    }
    $pdo->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent) VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent), failures = 0'
    )->execute([$userId, hash('sha256', $endpoint), $endpoint, $p256dh, $auth, mb_substr($userAgent, 0, 255)]);
}

function pushUnsubscribe(PDO $pdo, int $userId, string $endpoint): void {
    $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND user_id = ?')->execute([hash('sha256', $endpoint), $userId]);
}

/** Dispositivos com push ligado do utilizador. */
function pushDeviceCount(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Envia $payload ({title, body, tag, url}) a cada subscricao. As que o servico
 * diz que ja nao existem (404/410) sao apagadas; outras falhas contam em
 * failures e vao para o log do PHP.
 *
 * @return array{sent:int,removed:int,failed:int}
 */
function pushSend(PDO $pdo, array $subscriptions, array $payload): array {
    $result = ['sent' => 0, 'removed' => 0, 'failed' => 0];
    if ($subscriptions === [] || pushDisabled()) {
        return $result;
    }
    $remove = $pdo->prepare('DELETE FROM push_subscriptions WHERE id = ?');
    $ok = $pdo->prepare('UPDATE push_subscriptions SET last_ok_at = NOW(), failures = 0 WHERE id = ?');
    $fail = $pdo->prepare('UPDATE push_subscriptions SET failures = failures + 1 WHERE id = ?');
    foreach ($subscriptions as $subscription) {
        try {
            $status = webpushSend($subscription, $payload);
        } catch (Throwable $e) {
            error_log('[push] ' . $e->getMessage());
            $status = 0;
        }
        if ($status === 404 || $status === 410) {
            $remove->execute([(int) $subscription['id']]);
            $result['removed']++;
        } elseif ($status >= 200 && $status < 300) {
            $ok->execute([(int) $subscription['id']]);
            $result['sent']++;
        } else {
            $fail->execute([(int) $subscription['id']]);
            $result['failed']++;
            error_log('[push] servico respondeu ' . $status . ' (' . parse_url((string) $subscription['endpoint'], PHP_URL_HOST) . ')');
        }
    }
    return $result;
}

/**
 * Completa o payload: URL absoluto (a notificacao abre fora da pagina) e o
 * icone do logotipo da empresa (app-icon.php).
 */
function pushPayload(array $payload): array {
    require_once dirname(__DIR__) . '/app-icon.php';
    $url = (string) ($payload['url'] ?? BASE_URL . 'dashboard');
    if (preg_match('#^https?://#i', $url) !== 1) {
        $url = rtrim(appAbsoluteBaseUrl(), '/') . '/' . ltrim(substr($url, strlen(BASE_URL) - 1), '/');
    }
    $payload['url'] = $url;
    $payload['icon'] ??= appIconUrl('192', true);
    return $payload;
}

/** pushSend() para todos os dispositivos de um utilizador. */
function pushSendToUser(PDO $pdo, int $userId, array $payload): array {
    $stmt = $pdo->prepare('SELECT * FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return pushSend($pdo, $subscriptions, $payload) + ['devices' => count($subscriptions)];
}

/**
 * Corre $task depois de a resposta ter saido (o pedido nao espera pelos
 * servicos de push). Erros vao para o log do PHP.
 */
function pushAfterResponse(callable $task): void {
    static $queue = null;
    if (pushDisabled()) {
        return;
    }
    if ($queue === null) {
        $queue = new ArrayObject();
        register_shutdown_function(static function () use ($queue): void {
            if (count($queue) === 0) {
                return;
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            ignore_user_abort(true);
            foreach ($queue as $queued) {
                try {
                    $queued();
                } catch (Throwable $e) {
                    error_log('[push] ' . $e->getMessage());
                }
            }
        });
    }
    $queue[] = $task;
}
