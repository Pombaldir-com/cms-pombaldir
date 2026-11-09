<?php
// Calendario do dashboard (porta da "Agenda" da intranet legacy:
// index.inc.php + window.php?act=agenda + data/utils.php eventos_*).
// Implementacao de referencia: intranet PombalData (app/eventos/, app/caldav/).
//
// - Eventos com titulo, inicio/fim (ou dia inteiro), local, cliente opcional
//   (accounting_entities 'acquirer'), utilizadores convidados, lembrete e
//   observacoes.
// - Quem ve: o criador e os convidados; os administradores (role <= 2) podem
//   ver todos ("Mostrar: Todos") e alterar qualquer um.
// - Avisos (calendar_event_notices): convite, alteracao de hora e lembrete,
//   mostrados no browser por assets/js/calendario-avisos.js (sem cron: o
//   lembrete e gerado no proprio pedido de avisos).
// - CalDAV (calendario/caldav.php): os mesmos eventos no telemovel.
//
// Ver docs/calendario.md.

require_once dirname(__DIR__) . '/functions.php';

const CALENDAR_REMINDER_DEFAULT = 60;
// Antecedencias do lembrete (minutos => texto); 0 = sem lembrete.
const CALENDAR_REMINDER_OPTIONS = [
    0 => 'Sem lembrete',
    15 => '15 minutos antes',
    30 => '30 minutos antes',
    60 => '1 hora antes',
    120 => '2 horas antes',
    240 => '4 horas antes',
    1440 => '1 dia antes',
];
// Um evento de dia inteiro nao tem hora: o lembrete conta a partir desta hora.
const CALENDAR_ALL_DAY_HOUR = '09:00';
const CALENDAR_COLOR_OWN = '#3498DB';
const CALENDAR_COLOR_INVITE = '#8E44AD';
const CALENDAR_COLOR_OTHER = '#73879C';

function calendarHasTables(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = hasTable('calendar_events') && hasTable('calendar_event_members') && hasTable('calendar_event_notices');
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

function calendarHasCaldavTable(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = hasTable('caldav_access_tokens');
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** O cliente so existe com o modulo Contabilidade (accounting_entities). */
function calendarClientsEnabled(): bool {
    static $enabled = null;
    if ($enabled === null) {
        try {
            $enabled = isModuleActive('contabilidade') && hasTable('accounting_entities');
        } catch (Throwable $e) {
            $enabled = false;
        }
    }
    return $enabled;
}

/** Nome da app nas Definicoes (ex.: "Z Contas"): nomes dos calendarios no telemovel, conta CalDAV, app instalada. */
function calendarAppName(): string {
    static $name = null;
    if ($name === null) {
        try {
            $name = trim((string) getSetting('app_name', ''));
        } catch (Throwable $e) {
            $name = '';
        }
        if ($name === '') {
            $name = 'CRM';
        }
    }
    return $name;
}

function calendarIsAdmin(int $role): bool {
    return $role > 0 && $role <= 2;
}

function calendarUuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function calendarIsUuid(string $value): bool {
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower(trim($value))) === 1;
}

/** 'Y-m-d', 'Y-m-dTH:i' ou 'Y-m-d H:i[:s]' => 'Y-m-d H:i:00' (so data: 00:00), ou null. */
function calendarParseDateTime(string $value): ?string {
    $value = trim(str_replace('T', ' ', $value));
    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?: (\d{2}:\d{2})(?::\d{2})?)?$/', $value, $m) !== 1) {
        return null;
    }
    $text = $m[1] . ' ' . ($m[2] ?? '00:00') . ':00';
    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text);
    return $date !== false && $date->format('Y-m-d H:i:s') === $text ? $text : null;
}

/** Utilizadores que podem ser convidados. @return list<array{id:int,name:string,username:string}> */
function calendarUsers(PDO $pdo): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach ($pdo->query('SELECT id, username, name FROM users ORDER BY COALESCE(NULLIF(TRIM(name), \'\'), username)')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            $cache[] = [
                'id' => (int) $row['id'],
                'name' => $name !== '' ? $name : (string) $row['username'],
                'username' => (string) $row['username'],
            ];
        }
    }
    return $cache;
}

function calendarUserName(PDO $pdo, int $userId): string {
    foreach (calendarUsers($pdo) as $u) {
        if ($u['id'] === $userId) {
            return $u['name'];
        }
    }
    return '';
}

/**
 * Valida o formulario (title, start, end, all_day, location, description,
 * reminder_minutes, members[], entity_id).
 * Dia inteiro: start e end sao dias ('Y-m-d 00:00:00', end = ultimo dia, inclusive).
 */
function calendarValidate(array $input): array {
    $line = static fn ($v): string => trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    $title = $line($input['title'] ?? '');
    if ($title === '') {
        throw new InvalidArgumentException('Indique o título do evento.');
    }
    if (mb_strlen($title) > 190) {
        throw new InvalidArgumentException('O título tem no máximo 190 caracteres.');
    }
    $location = mb_substr($line($input['location'] ?? ''), 0, 190);
    $description = mb_substr(trim(str_replace("\r\n", "\n", (string) ($input['description'] ?? ''))), 0, 5000);
    $allDay = !empty($input['all_day']) && (string) $input['all_day'] !== '0';

    $start = calendarParseDateTime((string) ($input['start'] ?? ''));
    if ($start === null) {
        throw new InvalidArgumentException('Indique a data de início.');
    }
    $endText = trim((string) ($input['end'] ?? ''));
    $end = $endText !== '' ? calendarParseDateTime($endText) : null;
    if ($endText !== '' && $end === null) {
        throw new InvalidArgumentException('Data de fim inválida.');
    }
    if ($allDay) {
        $start = substr($start, 0, 10) . ' 00:00:00';
        $end = $end !== null ? substr($end, 0, 10) . ' 00:00:00' : null;
        if ($end !== null && $end < $start) {
            throw new InvalidArgumentException('O fim não pode ser antes do início.');
        }
        if ($end === $start) {
            $end = null;
        }
    } elseif ($end !== null && $end <= $start) {
        throw new InvalidArgumentException('O fim tem de ser depois do início.');
    }

    $reminder = (int) ($input['reminder_minutes'] ?? CALENDAR_REMINDER_DEFAULT);
    if (!isset(CALENDAR_REMINDER_OPTIONS[$reminder])) {
        throw new InvalidArgumentException('Lembrete inválido.');
    }
    $members = [];
    foreach ((array) ($input['members'] ?? []) as $id) {
        if ((int) $id > 0) {
            $members[(int) $id] = (int) $id;
        }
    }

    return [
        'title' => $title,
        'description' => $description,
        'location' => $location,
        'start' => $start,
        'end' => $end,
        'all_day' => $allDay,
        'reminder_minutes' => $reminder,
        'members' => array_values($members),
        'entity_id' => max(0, (int) ($input['entity_id'] ?? 0)),
    ];
}

/** Cliente (entidade adquirente) por id: {id, name, nif, code, route_key} ou null. */
function calendarFindClient(PDO $pdo, int $entityId): ?array {
    if ($entityId <= 0 || !calendarClientsEnabled()) {
        return null;
    }
    $uuidCol = hasColumn('accounting_entities', 'uuid') ? ', uuid' : '';
    $stmt = $pdo->prepare("SELECT id, name, nif, erp_client_code{$uuidCol} FROM accounting_entities WHERE id = ? AND entity_type = 'acquirer' LIMIT 1");
    $stmt->execute([$entityId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? calendarClientRow($row) : null;
}

function calendarClientRow(array $row): array {
    $uuid = strtolower(trim((string) ($row['uuid'] ?? '')));
    return [
        'id' => (int) $row['id'],
        'name' => (string) ($row['name'] ?? ''),
        'nif' => (string) ($row['nif'] ?? ''),
        'code' => trim((string) ($row['erp_client_code'] ?? '')),
        'route_key' => calendarIsUuid($uuid) ? $uuid : (string) (int) $row['id'],
    ];
}

function calendarClientUrl(string $routeKey): string {
    return $routeKey !== '' ? BASE_URL . 'contabilidade/entidades/empresas/' . rawurlencode($routeKey) : '';
}

/** Pesquisa de clientes para o Select2 (mesmo criterio das Atividades). */
function calendarSearchClients(PDO $pdo, string $term): array {
    if (!calendarClientsEnabled()) {
        return [];
    }
    $uuidCol = hasColumn('accounting_entities', 'uuid') ? ', uuid' : '';
    $term = trim($term);
    if ($term === '') {
        $stmt = $pdo->query("SELECT id, name, nif, erp_client_code{$uuidCol} FROM accounting_entities WHERE entity_type = 'acquirer' ORDER BY name LIMIT 30");
    } else {
        $like = '%' . $term . '%';
        $stmt = $pdo->prepare(
            "SELECT id, name, nif, erp_client_code{$uuidCol} FROM accounting_entities
             WHERE entity_type = 'acquirer' AND (name LIKE ? OR nif LIKE ? OR erp_client_code LIKE ?)
             ORDER BY (erp_client_code = ? OR nif = ?) DESC, name LIMIT 30"
        );
        $stmt->execute([$like, $like, $like, $term, $term]);
    }
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $client = calendarClientRow($row);
        $label = $client['name'];
        $extra = array_filter([$client['code'] !== '' ? 'Cód. ' . $client['code'] : '', $client['nif'] !== '' ? 'NIF ' . $client['nif'] : '']);
        $results[] = ['id' => $client['id'], 'text' => $label . ($extra ? ' (' . implode(' · ', $extra) . ')' : ''), 'name' => $client['name'], 'nif' => $client['nif'], 'code' => $client['code']];
    }
    return $results;
}

/** Evento por uuid, com members (ids) e creator_name; null quando nao existe. */
function calendarLoad(PDO $pdo, string $uuid): ?array {
    if (!calendarIsUuid($uuid)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM calendar_events WHERE uuid = ?');
    $stmt->execute([strtolower(trim($uuid))]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($event)) {
        return null;
    }
    return calendarAttachMembers($pdo, $event);
}

function calendarLoadById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM calendar_events WHERE id = ?');
    $stmt->execute([$id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($event) ? calendarAttachMembers($pdo, $event) : null;
}

function calendarAttachMembers(PDO $pdo, array $event): array {
    $stmt = $pdo->prepare('SELECT user_id FROM calendar_event_members WHERE event_id = ? ORDER BY user_id');
    $stmt->execute([(int) $event['id']]);
    $event['members'] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $event['creator_name'] = calendarUserName($pdo, (int) $event['created_by']);
    return $event;
}

function calendarCanEdit(array $event, int $userId, int $role): bool {
    return (int) ($event['created_by'] ?? 0) === $userId || calendarIsAdmin($role);
}

function calendarCanView(array $event, int $userId, int $role): bool {
    return calendarCanEdit($event, $userId, $role) || in_array($userId, $event['members'] ?? [], true);
}

/**
 * Cria ($existing null) ou altera o evento. Os convidados sao os utilizadores
 * validos de $data['members'] exceto o criador. Gera os avisos de convite
 * (novos convidados) e de alteracao de hora (convidados que ja la estavam).
 *
 * @param array{uri?:string,uid?:string} $caldav nome/UID dados pelo telemovel
 * @return array{id:int,uuid:string,new_members:list<int>}
 */
function calendarSave(PDO $pdo, int $userId, array $data, ?array $existing = null, array $caldav = []): array {
    $creator = $existing !== null ? (int) $existing['created_by'] : $userId;
    $validIds = array_column(calendarUsers($pdo), 'id');
    $members = array_values(array_filter($data['members'], static fn (int $id): bool => $id !== $creator && in_array($id, $validIds, true)));

    // Cliente: o id da entidade escolhida, com copia dos dados. Sem entity_id
    // (ex.: alteracao vinda do telemovel) mantem-se o cliente atual.
    $client = ['accounting_entity_id' => null, 'client_name' => '', 'client_nif' => '', 'client_code' => ''];
    if (array_key_exists('keep_client', $data) && $existing !== null) {
        $client = [
            'accounting_entity_id' => $existing['accounting_entity_id'] !== null ? (int) $existing['accounting_entity_id'] : null,
            'client_name' => (string) $existing['client_name'],
            'client_nif' => (string) $existing['client_nif'],
            'client_code' => (string) $existing['client_code'],
        ];
    } elseif ((int) ($data['entity_id'] ?? 0) > 0) {
        $found = calendarFindClient($pdo, (int) $data['entity_id']);
        if ($found === null) {
            throw new InvalidArgumentException('Cliente não encontrado.');
        }
        $client = ['accounting_entity_id' => $found['id'], 'client_name' => $found['name'], 'client_nif' => $found['nif'], 'client_code' => $found['code']];
    }

    $fields = [
        'title' => $data['title'],
        'description' => $data['description'],
        'location' => $data['location'],
        'start_at' => $data['start'],
        'end_at' => $data['end'],
        'all_day' => $data['all_day'] ? 1 : 0,
        'reminder_minutes' => $data['reminder_minutes'],
    ] + $client;

    $pdo->beginTransaction();
    try {
        if ($existing === null) {
            $uuid = calendarUuid();
            $pdo->prepare(
                'INSERT INTO calendar_events (uuid, title, description, location, start_at, end_at, all_day, reminder_minutes,
                    accounting_entity_id, client_name, client_nif, client_code, caldav_uri, caldav_uid, created_by)
                 VALUES (:uuid, :title, :description, :location, :start_at, :end_at, :all_day, :reminder_minutes,
                    :accounting_entity_id, :client_name, :client_nif, :client_code, :caldav_uri, :caldav_uid, :created_by)'
            )->execute($fields + [
                'uuid' => $uuid,
                'caldav_uri' => $caldav['uri'] ?? null,
                'caldav_uid' => $caldav['uid'] ?? null,
                'created_by' => $userId,
            ]);
            $id = (int) $pdo->lastInsertId();
            $previous = [];
        } else {
            $id = (int) $existing['id'];
            $uuid = (string) $existing['uuid'];
            $pdo->prepare(
                'UPDATE calendar_events SET title = :title, description = :description, location = :location,
                    start_at = :start_at, end_at = :end_at, all_day = :all_day, reminder_minutes = :reminder_minutes,
                    accounting_entity_id = :accounting_entity_id, client_name = :client_name, client_nif = :client_nif,
                    client_code = :client_code, updated_by = :updated_by
                 WHERE id = :id'
            )->execute($fields + ['updated_by' => $userId, 'id' => $id]);
            $previous = $existing['members'] ?? [];
        }
        $removed = array_values(array_diff($previous, $members));
        if ($removed !== []) {
            $pdo->prepare('DELETE FROM calendar_event_members WHERE event_id = ? AND user_id IN (' . implode(',', array_fill(0, count($removed), '?')) . ')')
                ->execute(array_merge([$id], $removed));
        }
        $new = array_values(array_diff($members, $previous));
        $insert = $pdo->prepare('INSERT IGNORE INTO calendar_event_members (event_id, user_id) VALUES (?, ?)');
        foreach ($new as $member) {
            $insert->execute([$id, $member]);
        }

        // Avisos: convite aos novos; alteracao de hora aos que ja estavam
        // (e ao criador, se foi outro a mudar). Quem fez a alteracao nao e avisado.
        $notice = $pdo->prepare('INSERT IGNORE INTO calendar_event_notices (event_id, user_id, kind, event_start) VALUES (?, ?, ?, ?)');
        foreach ($new as $member) {
            if ($member !== $userId) {
                $notice->execute([$id, $member, 'invite', $data['start']]);
            }
        }
        if ($existing !== null && ((string) $existing['start_at'] !== $data['start'] || (string) ($existing['end_at'] ?? '') !== (string) ($data['end'] ?? ''))) {
            $kept = array_values(array_intersect($previous, $members));
            $kept[] = $creator;
            foreach (array_unique($kept) as $member) {
                if ($member !== $userId) {
                    $notice->execute([$id, $member, 'changed', $data['start']]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['id' => $id, 'uuid' => $uuid, 'new_members' => $new];
}

function calendarDelete(PDO $pdo, int $eventId): void {
    // Membros e avisos saem por ON DELETE CASCADE.
    $pdo->prepare('DELETE FROM calendar_events WHERE id = ?')->execute([$eventId]);
}

/** Evento em JSON para o modal do dashboard. */
function calendarEventJson(array $event, int $userId, int $role): array {
    $allDay = (int) $event['all_day'] === 1;
    $fmt = static fn (?string $v): string => $v === null || $v === '' ? '' : ($allDay ? substr($v, 0, 10) : substr(str_replace(' ', 'T', $v), 0, 16));
    $client = null;
    if ((string) $event['client_name'] !== '' || $event['accounting_entity_id'] !== null) {
        $routeKey = '';
        if ($event['accounting_entity_id'] !== null) {
            $found = calendarFindClient(getPDO(), (int) $event['accounting_entity_id']);
            $routeKey = $found['route_key'] ?? '';
        }
        $client = [
            'id' => $event['accounting_entity_id'] !== null ? (int) $event['accounting_entity_id'] : 0,
            'name' => (string) $event['client_name'],
            'nif' => (string) $event['client_nif'],
            'code' => (string) $event['client_code'],
            'url' => calendarClientUrl($routeKey),
        ];
    }

    return [
        'uuid' => (string) $event['uuid'],
        'title' => (string) $event['title'],
        'description' => (string) ($event['description'] ?? ''),
        'location' => (string) $event['location'],
        'start' => $fmt((string) $event['start_at']),
        'end' => $fmt($event['end_at'] !== null ? (string) $event['end_at'] : null),
        'all_day' => $allDay,
        'reminder_minutes' => (int) $event['reminder_minutes'],
        'members' => $event['members'] ?? [],
        'client' => $client,
        'creator' => (int) $event['created_by'],
        'creator_name' => (string) ($event['creator_name'] ?? ''),
        'can_edit' => calendarCanEdit($event, $userId, $role),
    ];
}

/**
 * Eventos entre dois dias como eventos FullCalendar (fim exclusivo).
 * $scope 'mine': criados pelo utilizador ou para que foi convidado;
 * 'all' (so administradores): todos.
 */
function calendarFeed(PDO $pdo, int $userId, int $role, string $from, string $to, string $scope = 'mine'): array {
    $all = $scope === 'all' && calendarIsAdmin($role);
    $sql = 'SELECT e.*,
                (SELECT COUNT(*) FROM calendar_event_members m2 WHERE m2.event_id = e.id) AS total_members,
                EXISTS (SELECT 1 FROM calendar_event_members m3 WHERE m3.event_id = e.id AND m3.user_id = :u0) AS is_member
            FROM calendar_events e
            WHERE e.start_at < :to AND COALESCE(e.end_at, e.start_at) >= :from';
    $params = ['u0' => $userId, 'from' => $from . ' 00:00:00', 'to' => $to . ' 00:00:00'];
    if (!$all) {
        $sql .= ' AND (e.created_by = :u1 OR EXISTS (SELECT 1 FROM calendar_event_members m WHERE m.event_id = e.id AND m.user_id = :u2))';
        $params['u1'] = $userId;
        $params['u2'] = $userId;
    }
    $sql .= ' ORDER BY e.start_at, e.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $events = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $allDay = (int) $row['all_day'] === 1;
        $end = $row['end_at'] !== null ? (string) $row['end_at'] : '';
        if ($allDay) {
            $startCal = substr((string) $row['start_at'], 0, 10);
            $endCal = $end !== '' ? (new DateTimeImmutable(substr($end, 0, 10)))->modify('+1 day')->format('Y-m-d') : null;
        } else {
            $startCal = str_replace(' ', 'T', (string) $row['start_at']);
            $endCal = $end !== '' ? str_replace(' ', 'T', $end) : null;
        }
        $own = (int) $row['created_by'] === $userId;
        $kind = $own ? 'own' : ((int) $row['is_member'] === 1 ? 'invite' : 'other');
        $color = $kind === 'own' ? CALENDAR_COLOR_OWN : ($kind === 'invite' ? CALENDAR_COLOR_INVITE : CALENDAR_COLOR_OTHER);
        $events[] = [
            'id' => $row['uuid'],
            'title' => (string) $row['title'] . ((string) $row['client_name'] !== '' ? ' · ' . $row['client_name'] : ''),
            'start' => $startCal,
            'end' => $endCal,
            'allDay' => $allDay,
            'backgroundColor' => $color,
            'borderColor' => $color,
            'editable' => $own || calendarIsAdmin($role),
            'extendedProps' => [
                'uuid' => (string) $row['uuid'],
                'kind' => $kind,
                'location' => (string) $row['location'],
                'client' => (string) $row['client_name'],
                'creator' => calendarUserName($pdo, (int) $row['created_by']),
                'members' => (int) $row['total_members'],
                'detail' => mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', (string) ($row['description'] ?? ''))), 0, 200, '…'),
            ],
        ];
    }
    return $events;
}

/** Proximos eventos do utilizador (painel lateral do dashboard). */
function calendarUpcoming(PDO $pdo, int $userId, int $limit = 8): array {
    $now = new DateTimeImmutable('now');
    $stmt = $pdo->prepare(
        'SELECT e.* FROM calendar_events e
         WHERE (e.created_by = :u1 OR EXISTS (SELECT 1 FROM calendar_event_members m WHERE m.event_id = e.id AND m.user_id = :u2))
           AND COALESCE(e.end_at, e.start_at) >= :today
         ORDER BY e.start_at, e.id LIMIT ' . max(1, $limit * 3)
    );
    $stmt->execute(['u1' => $userId, 'u2' => $userId, 'today' => $now->format('Y-m-d') . ' 00:00:00']);
    $list = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        // Eventos com hora ja terminados hoje ficam de fora.
        $endRef = (int) $row['all_day'] === 1
            ? substr((string) ($row['end_at'] ?? $row['start_at']), 0, 10) . ' 23:59:59'
            : (string) ($row['end_at'] ?? $row['start_at']);
        if ($endRef < $now->format('Y-m-d H:i:s')) {
            continue;
        }
        $row['when'] = calendarWhen($row);
        $row['own'] = (int) $row['created_by'] === $userId;
        $list[] = $row;
        if (count($list) >= $limit) {
            break;
        }
    }
    return $list;
}

/** "03/11 10:00–11:00", "03/11 (dia inteiro)", "03/11 a 05/11". */
function calendarWhen(array $event): string {
    $start = new DateTimeImmutable((string) $event['start_at']);
    $end = ($event['end_at'] ?? null) !== null ? new DateTimeImmutable((string) $event['end_at']) : null;
    if ((int) $event['all_day'] === 1) {
        return $start->format('d/m') . ($end !== null ? ' a ' . $end->format('d/m') : ' (dia inteiro)');
    }
    if ($end === null) {
        return $start->format('d/m H:i');
    }
    return $start->format('d/m H:i') . ($end->format('Y-m-d') === $start->format('Y-m-d') ? '–' . $end->format('H:i') : ' a ' . $end->format('d/m H:i'));
}

/** Momento a partir do qual conta o lembrete. */
function calendarReminderReference(array $event): string {
    return (int) $event['all_day'] === 1
        ? substr((string) $event['start_at'], 0, 10) . ' ' . CALENDAR_ALL_DAY_HOUR . ':00'
        : (string) $event['start_at'];
}

/**
 * Cria os lembretes devidos agora: inicio ainda no futuro e dentro da
 * antecedencia escolhida. Com $userId so para esse utilizador (pedido de
 * avisos do browser); sem ele para o criador e todos os convidados (envio
 * push). $eventId limita a um evento. Idempotente.
 */
function calendarQueueDueReminders(PDO $pdo, ?int $userId, ?DateTimeImmutable $now = null, ?int $eventId = null): void {
    $now ??= new DateTimeImmutable('now');
    $nowText = $now->format('Y-m-d H:i:s');
    $maxMinutes = max(array_keys(CALENDAR_REMINDER_OPTIONS));
    $sql = 'SELECT e.id, e.start_at, e.all_day, e.reminder_minutes, e.created_by FROM calendar_events e
            WHERE e.reminder_minutes > 0 AND e.start_at BETWEEN ? AND ?';
    $params = [
        $now->modify('-1 day')->format('Y-m-d H:i:s'),
        $now->modify('+' . ($maxMinutes + 1440) . ' minutes')->format('Y-m-d H:i:s'),
    ];
    if ($userId !== null) {
        $sql .= ' AND (e.created_by = ? OR EXISTS (SELECT 1 FROM calendar_event_members m WHERE m.event_id = e.id AND m.user_id = ?))';
        $params[] = $userId;
        $params[] = $userId;
    }
    if ($eventId !== null) {
        $sql .= ' AND e.id = ?';
        $params[] = $eventId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $insert = $pdo->prepare('INSERT IGNORE INTO calendar_event_notices (event_id, user_id, kind, event_start) VALUES (?, ?, \'reminder\', ?)');
    $members = $pdo->prepare('SELECT user_id FROM calendar_event_members WHERE event_id = ?');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $event) {
        $reference = calendarReminderReference($event);
        $due = (new DateTimeImmutable($reference))->modify('-' . (int) $event['reminder_minutes'] . ' minutes')->format('Y-m-d H:i:s');
        if ($reference <= $nowText || $due > $nowText) {
            continue;
        }
        if ($userId !== null) {
            $recipients = [$userId];
        } else {
            $members->execute([(int) $event['id']]);
            $recipients = array_unique(array_merge([(int) $event['created_by']], array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        }
        foreach ($recipients as $recipient) {
            $insert->execute([(int) $event['id'], $recipient, (string) $event['start_at']]);
        }
    }
}

/**
 * Envia por Web Push os avisos ainda nao enviados (convites, alteracoes e
 * lembretes devidos) aos utilizadores com dispositivos subscritos. Corre no
 * cron scripts/calendar-push.php (lembretes) e logo depois de gravar um
 * evento ($eventId: so esse). Cada aviso e enviado uma vez (pushed_at).
 *
 * @return array{notices:int,sent:int,removed:int,failed:int}
 */
function calendarPushPending(PDO $pdo, ?int $eventId = null, ?DateTimeImmutable $now = null): array {
    require_once dirname(__DIR__) . '/push/functions.php';
    $result = ['notices' => 0, 'sent' => 0, 'removed' => 0, 'failed' => 0];
    if (!pushHasTable() || !hasColumn('calendar_event_notices', 'pushed_at') || pushDisabled()) {
        return $result;
    }
    $now ??= new DateTimeImmutable('now');
    calendarQueueDueReminders($pdo, null, $now, $eventId);

    $sql = 'SELECT e.*, n.id AS notice_id, n.kind AS notice_kind, n.user_id AS notice_user
            FROM calendar_event_notices n
            INNER JOIN calendar_events e ON e.id = n.event_id
            WHERE n.pushed_at IS NULL AND n.created_at >= ?
              AND EXISTS (SELECT 1 FROM push_subscriptions s WHERE s.user_id = n.user_id)';
    $params = [$now->modify('-1 day')->format('Y-m-d H:i:s')];
    if ($eventId !== null) {
        $sql .= ' AND n.event_id = ?';
        $params[] = $eventId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY n.id LIMIT 500');
    $stmt->execute($params);
    $claim = $pdo->prepare('UPDATE calendar_event_notices SET pushed_at = NOW() WHERE id = ? AND pushed_at IS NULL');
    $nowText = $now->format('Y-m-d H:i:s');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $claim->execute([(int) $row['notice_id']]);
        if ($claim->rowCount() === 0) {
            continue; // outro processo ja o enviou
        }
        $kind = (string) $row['notice_kind'];
        if ($kind === 'reminder' && calendarReminderReference($row) <= $nowText) {
            continue;
        }
        $payload = pushPayload(calendarNoticePayload($pdo, $row, $kind, $now));
        $sent = pushSendToUser($pdo, (int) $row['notice_user'], $payload);
        $result['notices']++;
        $result['sent'] += $sent['sent'];
        $result['removed'] += $sent['removed'];
        $result['failed'] += $sent['failed'];
    }
    return $result;
}

/** Depois da resposta: envia por push os avisos do evento acabado de gravar. */
function calendarPushAfterSave(PDO $pdo, int $eventId): void {
    require_once dirname(__DIR__) . '/push/functions.php';
    if (!pushHasTable()) {
        return;
    }
    pushAfterResponse(static function () use ($pdo, $eventId): void {
        calendarPushPending($pdo, $eventId);
    });
}

/**
 * Avisos por mostrar ao utilizador (marcados logo como entregues, para que
 * so um separador os mostre). Um lembrete cujo evento ja comecou deixa de
 * ser mostrado; convites/alteracoes com mais de 7 dias tambem.
 *
 * @return list<array{id:int,kind:string,title:string,body:string,url:string,tag:string}>
 */
function calendarPullNotices(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): array {
    $now ??= new DateTimeImmutable('now');
    calendarQueueDueReminders($pdo, $userId, $now);
    $stmt = $pdo->prepare(
        'SELECT e.*, n.id AS notice_id, n.kind AS notice_kind, n.created_at AS notice_created_at
         FROM calendar_event_notices n
         INNER JOIN calendar_events e ON e.id = n.event_id
         WHERE n.user_id = ? AND n.delivered_at IS NULL
         ORDER BY n.id LIMIT 20'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return [];
    }
    $mark = $pdo->prepare('UPDATE calendar_event_notices SET delivered_at = NOW() WHERE id = ? AND delivered_at IS NULL');
    $nowText = $now->format('Y-m-d H:i:s');
    $staleLimit = $now->modify('-7 days')->format('Y-m-d H:i:s');
    $notices = [];
    foreach ($rows as $row) {
        $mark->execute([(int) $row['notice_id']]);
        if ($mark->rowCount() === 0) {
            continue; // outro separador ja o mostrou
        }
        $kind = (string) $row['notice_kind'];
        if ($kind === 'reminder' && calendarReminderReference($row) <= $nowText) {
            continue;
        }
        if ($kind !== 'reminder' && (string) $row['notice_created_at'] < $staleLimit) {
            continue;
        }
        $notices[] = calendarNoticePayload($pdo, $row, $kind, $now) + ['id' => (int) $row['notice_id'], 'kind' => $kind];
    }
    return $notices;
}

function calendarNoticePayload(PDO $pdo, array $event, string $kind, DateTimeImmutable $now): array {
    $line = static fn (string $t): string => trim((string) preg_replace('/\s+/u', ' ', $t));
    $parts = array_filter([
        calendarWhen($event),
        $line((string) $event['client_name']),
        $line((string) $event['location']),
    ], static fn (string $p): bool => $p !== '');
    $body = implode(' · ', $parts);
    $author = calendarUserName($pdo, (int) ($event['updated_by'] ?? 0) ?: (int) $event['created_by']);

    if ($kind === 'invite') {
        $title = 'Convite' . ($author !== '' ? ' de ' . $author : '') . ': ' . $event['title'];
    } elseif ($kind === 'changed') {
        $title = 'Evento alterado' . ($author !== '' ? ' por ' . $author : '') . ': ' . $event['title'];
    } else {
        $reference = new DateTimeImmutable(calendarReminderReference($event));
        $minutes = max(0, (int) round(($reference->getTimestamp() - $now->getTimestamp()) / 60));
        if ((int) $event['all_day'] === 1) {
            $when = $reference->format('Y-m-d') === $now->format('Y-m-d') ? 'Hoje' : ($minutes <= 2880 ? 'Amanhã' : 'A ' . $reference->format('d/m'));
        } else {
            $when = $minutes >= 90 ? 'Daqui a ' . (int) round($minutes / 60) . ' h' : 'Daqui a ' . $minutes . ' min';
        }
        $title = $when . ': ' . $event['title'];
        $description = mb_strimwidth($line((string) ($event['description'] ?? '')), 0, 140, '…');
        if ($description !== '') {
            $body .= "\n" . $description;
        }
    }

    return [
        'title' => (string) $title,
        'body' => $body,
        'url' => BASE_URL . 'dashboard?evento=' . rawurlencode((string) $event['uuid']),
        'tag' => 'calendar-' . $kind . '-' . $event['uuid'],
    ];
}

function calendarLog(string $action, array $event, array $meta = []): void {
    logAuditAction($action, 'calendar_event', isset($event['id']) ? (int) $event['id'] : null, $meta + [
        'uuid' => (string) ($event['uuid'] ?? ''),
        'title' => (string) ($event['title'] ?? ''),
    ]);
}
