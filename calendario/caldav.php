<?php
// Servidor CalDAV em BASE_URL . 'caldav/' (router.php; /.well-known/caldav
// redireciona para aqui): o calendario do dashboard no telemovel.
// Autenticacao Basic com "{utilizador}@{NIF}" e a password de um dispositivo
// (calendario/caldav-functions.php), verificada antes do sabre/dav correr; sem
// sessao nem CSRF (o telemovel nao e um browser). Ver docs/calendario.md.

// Sessao so em memoria: um cliente CalDAV nao guarda cookies.
require_once dirname(__DIR__) . '/memory-session.php';
startMemorySession();

require_once __DIR__ . '/caldav-functions.php';

// As respostas sao XML: um aviso no ecra partia-as.
ini_set('display_errors', '0');

if (!caldavAvailable()) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CalDAV indisponível: falta instalar as dependências (composer install).';
    exit;
}

$caldavCredentials = caldavRequestCredentials($_SERVER);
$caldavAuth = $caldavCredentials !== null
    ? caldavAuthenticate($caldavCredentials[0], $caldavCredentials[1], (string) ($_SERVER['REMOTE_ADDR'] ?? ''))
    : ['ok' => false, 'blocked' => false];
if (!$caldavAuth['ok']) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!empty($caldavAuth['blocked'])) {
        http_response_code(429);
        header('Retry-After: ' . CALDAV_THROTTLE_WINDOW);
        echo 'Demasiadas tentativas falhadas.';
    } else {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Calendario", charset="UTF-8"');
        echo 'Autenticação necessária.';
    }
    exit;
}

caldavAutoload();
require_once __DIR__ . '/caldav-backends.php';

$caldavAccount = new CaldavAccount(getPDO(), $caldavAuth['user'], appAbsoluteBaseUrl());
// Para o logAuditAction() registar o utilizador do telemovel.
$_SESSION['user_id'] = $caldavAccount->userId();
$_SESSION['user_role'] = (int) $caldavAuth['user']['role'];

$caldavPrincipals = new CaldavPrincipalBackend($caldavAccount);
$caldavServer = new Sabre\DAV\Server([
    new Sabre\CalDAV\Principal\Collection($caldavPrincipals, 'principals'),
    new Sabre\CalDAV\CalendarRoot($caldavPrincipals, new CaldavCalendarBackend($caldavAccount)),
]);
$caldavServer->setBaseUri(caldavBasePath());
$caldavServer->debugExceptions = false;
// Erros 5xx (o telemovel so mostra "nao foi possivel ligar"): data/caldav-error.log.
$caldavServer->on('exception', static function (Throwable $e) use ($caldavAccount): void {
    if ($e instanceof Sabre\DAV\Exception && $e->getHTTPCode() < 500) {
        return;
    }
    @file_put_contents(
        dirname(__DIR__) . '/data/caldav-error.log',
        date('Y-m-d H:i:s') . ' u' . $caldavAccount->userId() . ' ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')
            . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n",
        FILE_APPEND | LOCK_EX
    );
});
$caldavServer->addPlugin(new Sabre\DAV\Auth\Plugin(new CaldavAuthBackend($caldavAccount)));
$caldavAcl = new Sabre\DAVACL\Plugin();
$caldavAcl->hideNodesFromListings = true;
$caldavServer->addPlugin($caldavAcl);
$caldavServer->addPlugin(new Sabre\CalDAV\Plugin());
$caldavServer->exec();
exit;
