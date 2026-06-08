<?php
// Entrega autenticada de ficheiros gerados pelo assistente AI.
// Rota: assistant/download/{token} (ver router.php).

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/assistant-downloads.php';

startSession();
requireLogin();

$token = trim((string) ($_GET['download_token'] ?? ''));
if ($token === '' || !preg_match('/^[a-f0-9]{48}$/', $token)) {
    http_response_code(400);
    exit('Pedido invalido.');
}

$pdo = getPDO();
if (!hasAssistantDownloadsTable()) {
    http_response_code(404);
    exit('Download indisponivel.');
}

$record = fetchAssistantDownload($pdo, $token);
if (!$record) {
    http_response_code(404);
    exit('Ficheiro nao encontrado.');
}

$user = currentUser();
$currentUserId = (int) ($user['id'] ?? 0);
if ($currentUserId === 0 || (int) ($record['user_id'] ?? -1) !== $currentUserId) {
    http_response_code(403);
    exit('Sem permissao para aceder a este ficheiro.');
}

$expiresAt = $record['expires_at'];
if ($expiresAt !== null && (int) $expiresAt > 0 && time() > (int) $expiresAt) {
    http_response_code(410);
    exit('O link expirou.');
}

$content = (string) ($record['content'] ?? '');
$filename = (string) ($record['filename'] ?? 'export.bin');
$mime = (string) ($record['mime'] ?? 'application/octet-stream');

logAuditAction('ai_assistant_download', 'assistant', null, ['token' => $token, 'filename' => $filename]);

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . strlen($content));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $content;
exit;
