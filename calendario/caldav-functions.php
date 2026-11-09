<?php
// CalDAV: o calendario do dashboard no telemovel (app Calendario do iPhone/Mac,
// DAVx5 no Android, Thunderbird...), servido por calendario/caldav.php em
// BASE_URL . 'caldav/' com sabre/dav (composer). Cada utilizador tem:
//   eventos   os eventos que criou: criar, alterar e apagar no telemovel
//   convites  os eventos para que foi convidado: so leitura (apagar = sair)
// Login "{utilizador}@{NIF da empresa}" com uma password por dispositivo
// (caldav_access_tokens, so o hash), criada no perfil (users/profile).
// Os lembretes vao como VALARM (o telemovel avisa sozinho); os convidados e o
// cliente geram-se no dashboard. Ver docs/calendario.md.

require_once __DIR__ . '/functions.php';

use Sabre\VObject;

const CALDAV_CALENDARS = ['eventos' => 'Eventos', 'convites' => 'Convites'];
const CALDAV_COLORS = ['eventos' => CALENDAR_COLOR_OWN, 'convites' => CALENDAR_COLOR_INVITE];
// Eventos antigos ficam fora do telemovel (o CRM guarda-os).
const CALDAV_PAST_DAYS = 180;
const CALDAV_PRODID = '-//Teknisoft//CRM Calendario//PT';
// Linha acrescentada a descricao com o cliente; retirada ao ler do telemovel.
const CALDAV_CLIENT_PREFIX = '— Cliente: ';
// Limites de tentativas falhadas (janela de 15 minutos).
const CALDAV_THROTTLE_ACCOUNT = 8;
const CALDAV_THROTTLE_IP = 30;
const CALDAV_THROTTLE_WINDOW = 900;

function caldavAutoload(): void {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

function caldavAvailable(): bool {
    return is_file(dirname(__DIR__) . '/vendor/sabre/dav/lib/DAV/Server.php');
}

function caldavBasePath(): string {
    return BASE_URL . 'caldav/';
}

function caldavPrincipalPath(int $userId): string {
    return caldavBasePath() . 'principals/u' . $userId . '/';
}

function caldavLogin(string $username, string $nif): string {
    return $username . '@' . $nif;
}

/** "{utilizador}@{NIF}" => [nif, utilizador] (ultimo @), ou null. */
function caldavParseLogin(string $login): ?array {
    $pos = strrpos($login, '@');
    if ($pos === false) {
        return null;
    }
    $username = trim(substr($login, 0, $pos));
    $nif = trim(substr($login, $pos + 1));
    return $username !== '' && preg_match('/^\d{9}$/', $nif) === 1 ? [$nif, $username] : null;
}

/** NIF da empresa da sessao (data/companies.php esta indexado por NIF). */
function caldavCurrentCompanyNif(): string {
    $current = $_SESSION['company'] ?? [];
    $companies = require dirname(__DIR__) . '/data/companies.php';
    foreach ($companies as $nif => $cfg) {
        if (!is_array($cfg)) {
            continue;
        }
        if ((string) ($cfg['slug'] ?? '') === (string) ($current['slug'] ?? '')
            && (string) ($cfg['db_name'] ?? '') === (string) ($current['db_name'] ?? '')) {
            return (string) $nif;
        }
    }
    return '';
}

/** Password de um dispositivo: 4 grupos de 4 sem letras que se confundem. */
function caldavGeneratePassword(): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $groups = [];
    for ($g = 0; $g < 4; $g++) {
        $group = '';
        for ($i = 0; $i < 4; $i++) {
            $group .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $groups[] = $group;
    }
    return implode('-', $groups);
}

/** @return array{id:int,password:string} (a password nao fica guardada) */
function caldavCreateAccess(PDO $pdo, int $userId, string $name): array {
    $password = caldavGeneratePassword();
    $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 120);
    $pdo->prepare('INSERT INTO caldav_access_tokens (user_id, name, password_hash) VALUES (?, ?, ?)')
        ->execute([$userId, $name !== '' ? $name : 'Telemóvel', password_hash($password, PASSWORD_DEFAULT)]);
    return ['id' => (int) $pdo->lastInsertId(), 'password' => $password];
}

function caldavListAccesses(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare('SELECT id, name, created_at, last_used_at FROM caldav_access_tokens WHERE user_id = ? ORDER BY id');
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function caldavRevokeAccess(PDO $pdo, int $userId, int $accessId): bool {
    $stmt = $pdo->prepare('DELETE FROM caldav_access_tokens WHERE id = ? AND user_id = ?');
    $stmt->execute([$accessId, $userId]);
    return $stmt->rowCount() > 0;
}

/** Credenciais Basic do pedido: [login, password] ou null. */
function caldavRequestCredentials(array $server): ?array {
    if (isset($server['PHP_AUTH_USER'])) {
        return [(string) $server['PHP_AUTH_USER'], (string) ($server['PHP_AUTH_PW'] ?? '')];
    }
    $header = (string) ($server['HTTP_AUTHORIZATION'] ?? ($server['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if (stripos($header, 'basic ') !== 0) {
        return null;
    }
    $decoded = base64_decode(trim(substr($header, 6)), true);
    if ($decoded === false || !str_contains($decoded, ':')) {
        return null;
    }
    return explode(':', $decoded, 2);
}

// ---------------------------------------------------------------- throttle
// Contadores em ficheiro (a app nao tem cache partilhada): um por chave.

function caldavThrottleFile(string $key): string {
    $dir = rtrim(sys_get_temp_dir(), '/') . '/crm-caldav-throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . sha1($key) . '.json';
}

/** Janela atual da chave: {since, count} (vazia quando expirou). */
function caldavThrottleRead(string $key): array {
    $file = caldavThrottleFile($key);
    $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (!is_array($data) || (int) ($data['since'] ?? 0) < time() - CALDAV_THROTTLE_WINDOW) {
        return ['since' => time(), 'count' => 0];
    }
    return ['since' => (int) $data['since'], 'count' => (int) ($data['count'] ?? 0)];
}

function caldavThrottleCount(string $key): int {
    return caldavThrottleRead($key)['count'];
}

function caldavThrottleFail(array $keys): void {
    foreach (array_keys($keys) as $key) {
        $data = caldavThrottleRead($key);
        $data['count']++;
        @file_put_contents(caldavThrottleFile($key), json_encode($data), LOCK_EX);
    }
}

function caldavThrottleClear(string $key): void {
    @unlink(caldavThrottleFile($key));
}

/**
 * Valida o login contra a empresa do NIF e os acessos do utilizador. Em caso de
 * sucesso deixa a empresa no contexto ($_SESSION['company']) para getPDO().
 *
 * @return array{ok:true,user:array,access_id:int}|array{ok:false,blocked:bool}
 */
function caldavAuthenticate(string $login, string $password, string $ip): array {
    $parts = caldavParseLogin($login);
    $accountKey = 'account:' . mb_strtolower(trim($login));
    $keys = [$accountKey => CALDAV_THROTTLE_ACCOUNT];
    if ($ip !== '') {
        $keys['ip:' . $ip] = CALDAV_THROTTLE_IP;
    }
    foreach ($keys as $key => $max) {
        if (caldavThrottleCount($key) >= $max) {
            return ['ok' => false, 'blocked' => true];
        }
    }
    $fail = static function () use ($keys): array {
        caldavThrottleFail($keys);
        return ['ok' => false, 'blocked' => false];
    };
    if ($parts === null || $password === '') {
        return $fail();
    }
    [$nif, $username] = $parts;
    require_once dirname(__DIR__) . '/companies.php';
    $company = getCompanyByNif($nif);
    if (!is_array($company)) {
        return $fail();
    }
    $_SESSION['company'] = $company;
    $pdo = getPDO();
    if (!calendarHasTables() || !calendarHasCaldavTable()) {
        return $fail();
    }
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.name, u.email, u.role, a.id AS access_id, a.password_hash, a.last_used_at
         FROM users u INNER JOIN caldav_access_tokens a ON a.user_id = u.id
         WHERE u.username = ?'
    );
    $stmt->execute([$username]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!password_verify($password, (string) $row['password_hash'])) {
            continue;
        }
        caldavThrottleClear($accountKey);
        // Ultimo uso no maximo a cada 5 minutos (um sync faz varios pedidos).
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 300) {
            $pdo->prepare('UPDATE caldav_access_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?')
                ->execute([substr($ip, 0, 45), (int) $row['access_id']]);
        }
        unset($row['password_hash']);
        return ['ok' => true, 'user' => $row, 'access_id' => (int) $row['access_id']];
    }
    return $fail();
}

// ---------------------------------------------------------------- iCalendar

function caldavUtc(string $local): DateTimeImmutable {
    return (new DateTimeImmutable($local, new DateTimeZone(APP_TIMEZONE)))->setTimezone(new DateTimeZone('UTC'));
}

/** O evento como VCALENDAR (horas em UTC; dia inteiro com datas, fim exclusivo). */
function caldavEventIcs(array $event, string $baseUrl): string {
    caldavAutoload();
    $vcal = new VObject\Component\VCalendar(['PRODID' => CALDAV_PRODID]);
    $description = trim((string) ($event['description'] ?? ''));
    $client = trim((string) ($event['client_name'] ?? ''));
    if ($client !== '') {
        $nif = trim((string) ($event['client_nif'] ?? ''));
        $description = ltrim($description . "\n\n" . CALDAV_CLIENT_PREFIX . $client . ($nif !== '' ? ' (NIF ' . $nif . ')' : ''));
    }
    $props = [
        'UID' => (string) ($event['caldav_uid'] ?? '') !== '' ? (string) $event['caldav_uid'] : (string) $event['uuid'],
        'DTSTAMP' => caldavUtc((string) $event['updated_at']),
        'LAST-MODIFIED' => caldavUtc((string) $event['updated_at']),
        'SUMMARY' => (string) $event['title'],
        'URL' => $baseUrl . 'dashboard?evento=' . rawurlencode((string) $event['uuid']),
    ];
    if (trim((string) $event['location']) !== '') {
        $props['LOCATION'] = (string) $event['location'];
    }
    if ($description !== '') {
        $props['DESCRIPTION'] = $description;
    }
    $ev = $vcal->add('VEVENT', $props);
    $allDay = (int) $event['all_day'] === 1;
    if ($allDay) {
        $ev->add('DTSTART', str_replace('-', '', substr((string) $event['start_at'], 0, 10)), ['VALUE' => 'DATE']);
        $last = new DateTimeImmutable(substr((string) ($event['end_at'] ?? $event['start_at']), 0, 10));
        $ev->add('DTEND', $last->modify('+1 day')->format('Ymd'), ['VALUE' => 'DATE']);
    } else {
        $ev->add('DTSTART', caldavUtc((string) $event['start_at']));
        if (($event['end_at'] ?? null) !== null && (string) $event['end_at'] !== '') {
            $ev->add('DTEND', caldavUtc((string) $event['end_at']));
        }
    }
    $minutes = (int) $event['reminder_minutes'];
    if ($minutes > 0) {
        // Dia inteiro: o lembrete conta das 09:00 (o TRIGGER e relativo as 00:00).
        $offset = $allDay ? caldavAllDayOffsetMinutes() - $minutes : -$minutes;
        $ev->add('VALARM', [
            'ACTION' => 'DISPLAY',
            'DESCRIPTION' => (string) $event['title'],
            'TRIGGER' => ($offset < 0 ? '-' : '') . 'PT' . abs($offset) . 'M',
        ]);
    }
    return $vcal->serialize();
}

function caldavAllDayOffsetMinutes(): int {
    [$h, $m] = array_map('intval', explode(':', CALENDAR_ALL_DAY_HOUR));
    return $h * 60 + $m;
}

function caldavEventUri(array $event): string {
    return (string) ($event['caldav_uri'] ?? '') !== '' ? (string) $event['caldav_uri'] : $event['uuid'] . '.ics';
}

/**
 * iCalendar vindo do telemovel => input de calendarValidate() (+ uid).
 * Eventos repetidos sao recusados. O primeiro alarme passa a ser o lembrete
 * (a opcao mais proxima); sem alarme, sem lembrete. Os convidados e o cliente
 * mantem-se (geram-se no dashboard).
 */
function caldavEventFromIcs(string $data, ?array $existing): array {
    caldavAutoload();
    try {
        $vcal = VObject\Reader::read($data, VObject\Reader::OPTION_FORGIVING);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('Calendário inválido.');
    }
    $events = $vcal->select('VEVENT');
    if (count($events) !== 1) {
        throw new InvalidArgumentException(count($events) === 0 ? 'Só são aceites eventos.' : 'Os eventos repetidos não são suportados.');
    }
    $ev = $events[0];
    if (isset($ev->RRULE) || isset($ev->RDATE) || isset($ev->{'RECURRENCE-ID'})) {
        throw new InvalidArgumentException('Os eventos repetidos não são suportados.');
    }
    if (!isset($ev->DTSTART)) {
        throw new InvalidArgumentException('Evento sem data de início.');
    }
    $tz = new DateTimeZone(APP_TIMEZONE);
    $allDay = !$ev->DTSTART->hasTime();
    $start = $ev->DTSTART->getDateTime($tz)->setTimezone($tz);
    if (isset($ev->DTEND)) {
        $end = $ev->DTEND->getDateTime($tz)->setTimezone($tz);
    } elseif (isset($ev->DURATION)) {
        $end = $start->add(VObject\DateTimeParser::parseDuration((string) $ev->DURATION));
    } else {
        $end = null;
    }
    if ($allDay) {
        $last = $end !== null ? $end->modify('-1 day') : $start;
        $startText = $start->format('Y-m-d');
        $endText = $last->format('Y-m-d') > $startText ? $last->format('Y-m-d') : '';
    } else {
        $startText = $start->format('Y-m-d\TH:i');
        $endText = $end !== null && $end > $start ? $end->format('Y-m-d\TH:i') : '';
    }

    $reminder = 0;
    foreach ($ev->select('VALARM') as $alarm) {
        if (!isset($alarm->TRIGGER) || (string) ($alarm->TRIGGER['VALUE'] ?? '') === 'DATE-TIME') {
            continue;
        }
        try {
            $interval = VObject\DateTimeParser::parseDuration((string) $alarm->TRIGGER);
        } catch (Throwable $e) {
            continue;
        }
        $before = (int) round((new DateTimeImmutable('@0'))->sub($interval)->getTimestamp() / 60); // -PT1H => 60
        if ($allDay) {
            $before += caldavAllDayOffsetMinutes();
        }
        $reminder = caldavNearestReminder($before);
        break;
    }

    // A linha do cliente acrescentada por caldavEventIcs() nao e descricao.
    $description = (string) ($ev->DESCRIPTION ?? '');
    $prefixPos = mb_strrpos($description, CALDAV_CLIENT_PREFIX);
    if ($prefixPos !== false) {
        $description = rtrim(mb_substr($description, 0, $prefixPos));
    }
    $title = trim((string) ($ev->SUMMARY ?? ''));

    return [
        'input' => [
            'title' => $title !== '' ? mb_substr($title, 0, 190) : '(sem título)',
            'description' => $description,
            'location' => (string) ($ev->LOCATION ?? ''),
            'start' => $startText,
            'end' => $endText,
            'all_day' => $allDay ? '1' : '0',
            'reminder_minutes' => $reminder,
            'members' => $existing['members'] ?? [],
        ],
        'uid' => mb_substr(trim((string) ($ev->UID ?? '')), 0, 255),
    ];
}

function caldavNearestReminder(int $minutes): int {
    $best = CALENDAR_REMINDER_DEFAULT;
    $distance = PHP_INT_MAX;
    foreach (array_keys(CALENDAR_REMINDER_OPTIONS) as $option) {
        if ($option > 0 && abs($option - $minutes) < $distance) {
            $best = $option;
            $distance = abs($option - $minutes);
        }
    }
    return $best;
}

/** Eventos de um calendario do utilizador, desde ha CALDAV_PAST_DAYS dias. */
function caldavEvents(PDO $pdo, int $userId, string $calendar): array {
    $since = (new DateTimeImmutable('today'))->modify('-' . CALDAV_PAST_DAYS . ' days')->format('Y-m-d H:i:s');
    $sql = $calendar === 'eventos'
        ? 'SELECT e.* FROM calendar_events e WHERE e.created_by = ? AND COALESCE(e.end_at, e.start_at) >= ? ORDER BY e.start_at, e.id'
        : 'SELECT e.* FROM calendar_events e INNER JOIN calendar_event_members m ON m.event_id = e.id AND m.user_id = ? WHERE COALESCE(e.end_at, e.start_at) >= ? ORDER BY e.start_at, e.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $since]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Envia o perfil (.mobileconfig) e termina. Descarta qualquer output pendente
 * (um aviso ou espaco em branco antes dos cabecalhos faz o iPhone recebe-lo
 * como texto em vez de o instalar) e regista no log se os cabecalhos ja
 * tiverem saido.
 */
function caldavSendMobileConfig(string $body): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (headers_sent($file, $line)) {
        error_log('[caldav] perfil iOS: cabecalhos ja enviados em ' . $file . ':' . $line);
    }
    header('Content-Type: application/x-apple-aspen-config');
    // Sem nosniff o WebKit do iOS (Safari, Chrome e a app instalada) ve "<?xml"
    // e mostra o perfil como texto em vez de o instalar (a intranet PombalData
    // envia nosniff em todas as respostas, pelo .htaccess).
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="calendario.mobileconfig"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

/**
 * Perfil de configuracao para iPhone/iPad/Mac: cria a conta CalDAV com o
 * servidor, o login e a password do novo acesso.
 */
function caldavMobileConfig(string $baseUrl, string $login, string $password, string $principalPath, string $appName): string {
    $parts = parse_url($baseUrl) ?: [];
    $https = ($parts['scheme'] ?? 'https') === 'https';
    $port = (int) ($parts['port'] ?? ($https ? 443 : 80));
    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $id = 'pt.teknisoft.crm.caldav.' . preg_replace('/[^a-z0-9.]/i', '-', $login);
    // A conta aparece no telemovel com o nome da app (ex.: "Z Contas").
    $name = $appName !== '' ? $appName : 'CRM';

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">' . "\n"
        . '<plist version="1.0"><dict>'
        . '<key>PayloadContent</key><array><dict>'
        . '<key>CalDAVAccountDescription</key><string>' . $esc($name) . '</string>'
        . '<key>CalDAVHostName</key><string>' . $esc((string) ($parts['host'] ?? 'localhost')) . '</string>'
        . '<key>CalDAVPort</key><integer>' . $port . '</integer>'
        . '<key>CalDAVPrincipalURL</key><string>' . $esc($principalPath) . '</string>'
        . '<key>CalDAVUseSSL</key>' . ($https ? '<true/>' : '<false/>')
        . '<key>CalDAVUsername</key><string>' . $esc($login) . '</string>'
        . '<key>CalDAVPassword</key><string>' . $esc($password) . '</string>'
        . '<key>PayloadDisplayName</key><string>' . $esc($name) . '</string>'
        . '<key>PayloadIdentifier</key><string>' . $esc($id) . '.conta</string>'
        . '<key>PayloadType</key><string>com.apple.caldav.account</string>'
        . '<key>PayloadUUID</key><string>' . strtoupper(calendarUuid()) . '</string>'
        . '<key>PayloadVersion</key><integer>1</integer>'
        . '</dict></array>'
        . '<key>PayloadDescription</key><string>' . $esc('Adiciona a conta CalDAV com os eventos do calendário de ' . $name . '.') . '</string>'
        . '<key>PayloadDisplayName</key><string>' . $esc($name) . '</string>'
        . '<key>PayloadIdentifier</key><string>' . $esc($id) . '</string>'
        . '<key>PayloadRemovalDisallowed</key><false/>'
        . '<key>PayloadType</key><string>Configuration</string>'
        . '<key>PayloadUUID</key><string>' . strtoupper(calendarUuid()) . '</string>'
        . '<key>PayloadVersion</key><integer>1</integer>'
        . '</dict></plist>' . "\n";
}
