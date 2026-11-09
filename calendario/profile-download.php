<?php
// Rota "caldav-perfil/{token}": perfil iOS (.mobileconfig) aberto no Safari a
// partir da app instalada no ecra principal, onde o iOS nao instala perfis e a
// sessao da app nao passa para o Safari. O token (uso unico, 30 min) e criado
// pelo painel do perfil (calendario/partials/caldav-panel.php); o acesso CalDAV
// so e criado aqui. Ver calendario/caldav-functions.php e docs/calendario.md.

require_once dirname(__DIR__) . '/memory-session.php';
startMemorySession();
require_once __DIR__ . '/caldav-functions.php';
require_once dirname(__DIR__) . '/companies.php';

$profileToken = (string) ($_GET['caldav_token'] ?? '');
$profileName = mb_substr(trim((string) ($_GET['n'] ?? '')), 0, 120) ?: 'iPhone';

$profileFail = static function (string $message): void {
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="pt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Calendário</title></head><body style="font-family:-apple-system,system-ui,sans-serif;padding:24px;color:#2a3f54">'
        . '<h2 style="font-weight:600">Calendário no telemóvel</h2><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
};

$profileData = caldavProfileTokenConsume($profileToken);
if ($profileData === null) {
    $profileFail('Este link já foi usado ou expirou. Volte à app, abra o seu perfil e toque outra vez em "Instalar no iPhone / iPad".');
}
$profileCompany = getCompanyByNif($profileData['nif']);
if (!is_array($profileCompany)) {
    $profileFail('Empresa não encontrada.');
}
$_SESSION['company'] = $profileCompany;
$_SESSION['user_id'] = $profileData['user_id'];

$profilePdo = getPDO();
if (!calendarHasTables() || !calendarHasCaldavTable()) {
    $profileFail('O calendário ainda não está ativo nesta empresa.');
}
$profileStmt = $profilePdo->prepare('SELECT id, username FROM users WHERE id = ?');
$profileStmt->execute([$profileData['user_id']]);
$profileUser = $profileStmt->fetch(PDO::FETCH_ASSOC);
if (!$profileUser) {
    $profileFail('Utilizador não encontrado.');
}

$profileAccess = caldavCreateAccess($profilePdo, (int) $profileUser['id'], $profileName);
logAuditAction('caldav_access_created', 'user', (int) $profileUser['id'], ['name' => $profileName, 'origin' => 'pwa-link']);

caldavSendMobileConfig(caldavMobileConfig(
    appAbsoluteBaseUrl(),
    caldavLogin((string) $profileUser['username'], $profileData['nif']),
    $profileAccess['password'],
    caldavPrincipalPath((int) $profileUser['id']),
    calendarAppName()
));
