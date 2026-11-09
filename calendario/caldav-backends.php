<?php
// Backends sabre/dav do servidor CalDAV (calendario/caldav.php): o utilizador
// ja autenticado por caldavAuthenticate() e o unico principal; os calendarios
// vem de calendario/caldav-functions.php.

use Sabre\CalDAV;
use Sabre\DAV;
use Sabre\DAVACL;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/** A conta do pedido: ligacao da empresa, linha do utilizador, URL base dos links. */
final class CaldavAccount
{
    public function __construct(
        public readonly PDO $pdo,
        public readonly array $user,
        public readonly string $baseUrl,
    ) {
    }

    public function principal(): string
    {
        return 'principals/u' . $this->userId();
    }

    public function userId(): int
    {
        return (int) $this->user['id'];
    }

    public function role(): int
    {
        return (int) ($this->user['role'] ?? 3);
    }
}

/** A autenticacao foi feita antes do servidor correr: aqui so se nomeia o principal. */
final class CaldavAuthBackend implements DAV\Auth\Backend\BackendInterface
{
    public function __construct(private readonly CaldavAccount $account)
    {
    }

    public function check(RequestInterface $request, ResponseInterface $response)
    {
        return [true, $this->account->principal()];
    }

    public function challenge(RequestInterface $request, ResponseInterface $response)
    {
        $response->addHeader('WWW-Authenticate', 'Basic realm="Calendario", charset="UTF-8"');
    }
}

/** So o utilizador autenticado e principal (os outros nao sao listados nem pesquisaveis). */
final class CaldavPrincipalBackend extends DAVACL\PrincipalBackend\AbstractBackend
{
    public function __construct(private readonly CaldavAccount $account)
    {
    }

    private function own(): array
    {
        $user = $this->account->user;
        $name = trim((string) ($user['name'] ?? ''));
        return [
            'uri' => $this->account->principal(),
            '{DAV:}displayname' => $name !== '' ? $name : (string) $user['username'],
            '{http://sabredav.org/ns}email-address' => (string) ($user['email'] ?? ''),
        ];
    }

    public function getPrincipalsByPrefix($prefixPath)
    {
        return $prefixPath === 'principals' ? [$this->own()] : [];
    }

    public function getPrincipalByPath($path)
    {
        return $path === $this->account->principal() ? $this->own() : null;
    }

    public function updatePrincipal($path, DAV\PropPatch $propPatch)
    {
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        return [];
    }

    public function getGroupMemberSet($principal)
    {
        return [];
    }

    public function getGroupMembership($principal)
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members)
    {
        throw new DAV\Exception\Forbidden('Não suportado.');
    }
}

/**
 * Calendarios 'eventos' (editavel) e 'convites' (so leitura) do utilizador. Os
 * objetos sao gerados por pedido (algumas centenas de eventos no maximo).
 */
final class CaldavCalendarBackend extends CalDAV\Backend\AbstractBackend
{
    /** @var array<string, array<string, array{data:string,event:array}>> */
    private array $cache = [];

    public function __construct(private readonly CaldavAccount $account)
    {
    }

    /** @return array<string, array{data:string,event:array}> */
    private function objects(string $calendar): array
    {
        if (!isset($this->cache[$calendar])) {
            $objects = [];
            if (isset(CALDAV_CALENDARS[$calendar])) {
                foreach (caldavEvents($this->account->pdo, $this->account->userId(), $calendar) as $event) {
                    $objects[caldavEventUri($event)] = ['data' => caldavEventIcs($event, $this->account->baseUrl), 'event' => $event];
                }
            }
            $this->cache[$calendar] = $objects;
        }
        return $this->cache[$calendar];
    }

    private function row(string $calendar, string $uri, array $object, bool $withData): array
    {
        $row = [
            'calendarid' => $calendar,
            'uri' => $uri,
            'etag' => '"' . md5($object['data']) . '"',
            'size' => strlen($object['data']),
            'component' => 'vevent',
            'lastmodified' => strtotime((string) $object['event']['updated_at']) ?: null,
        ];
        if ($withData) {
            $row['calendardata'] = $object['data'];
        }
        return $row;
    }

    public function getCalendarsForUser($principalUri)
    {
        if ($principalUri !== $this->account->principal()) {
            return [];
        }
        $calendars = [];
        foreach (CALDAV_CALENDARS as $id => $name) {
            $objects = $this->objects($id);
            $calendars[] = [
                'id' => $id,
                'uri' => $id,
                'principaluri' => $principalUri,
                '{DAV:}displayname' => calendarAppName() . ' · ' . $name,
                '{http://calendarserver.org/ns/}getctag' => md5((string) json_encode(array_map(static fn (array $o): string => md5($o['data']), $objects))),
                '{http://apple.com/ns/ical/}calendar-color' => CALDAV_COLORS[$id],
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT']),
                '{http://sabredav.org/ns}read-only' => $id !== 'eventos',
            ];
        }
        return $calendars;
    }

    public function createCalendar($principalUri, $calendarUri, array $properties)
    {
        throw new DAV\Exception\Forbidden('Não é possível criar calendários em ' . calendarAppName() . '.');
    }

    public function updateCalendar($calendarId, DAV\PropPatch $propPatch)
    {
        // Cor e ordem escolhidas no telemovel: aceites e esquecidas.
        $propPatch->handleRemaining(static fn (): bool => true);
    }

    public function deleteCalendar($calendarId)
    {
        throw new DAV\Exception\Forbidden('Não é possível apagar os calendários de ' . calendarAppName() . '.');
    }

    public function getCalendarObjects($calendarId)
    {
        $rows = [];
        foreach ($this->objects((string) $calendarId) as $uri => $object) {
            $rows[] = $this->row((string) $calendarId, $uri, $object, false);
        }
        return $rows;
    }

    public function getCalendarObject($calendarId, $objectUri)
    {
        $object = $this->objects((string) $calendarId)[$objectUri] ?? null;
        return $object !== null ? $this->row((string) $calendarId, $objectUri, $object, true) : null;
    }

    public function createCalendarObject($calendarId, $objectUri, $calendarData)
    {
        if ($calendarId !== 'eventos') {
            throw new DAV\Exception\Forbidden('Este calendário é só de leitura: crie o evento em "' . calendarAppName() . ' · Eventos".');
        }
        if (preg_match('/^[A-Za-z0-9._@~-]{1,250}$/', (string) $objectUri) !== 1) {
            throw new DAV\Exception\BadRequest('Nome de evento inválido.');
        }
        $read = $this->read((string) $calendarData, null);
        $result = calendarSave($this->account->pdo, $this->account->userId(), $read['data'], null, ['uri' => (string) $objectUri, 'uid' => $read['uid']]);
        unset($this->cache['eventos']);
        calendarLog('calendar_event_created', ['id' => $result['id'], 'uuid' => $result['uuid'], 'title' => $read['data']['title']], ['origin' => 'caldav']);
        calendarPushAfterSave($this->account->pdo, $result['id']);
        return null; // guardado diferente do enviado: o telemovel volta a pedi-lo
    }

    public function updateCalendarObject($calendarId, $objectUri, $calendarData)
    {
        $object = $calendarId === 'eventos' ? ($this->objects('eventos')[$objectUri] ?? null) : null;
        if ($object === null) {
            throw new DAV\Exception\Forbidden('Este calendário é só de leitura: só o criador altera o evento.');
        }
        $event = calendarLoad($this->account->pdo, (string) $object['event']['uuid']);
        if ($event === null) {
            throw new DAV\Exception\NotFound('Evento não encontrado.');
        }
        $read = $this->read((string) $calendarData, $event);
        $read['data']['keep_client'] = true;
        $result = calendarSave($this->account->pdo, $this->account->userId(), $read['data'], $event);
        calendarPushAfterSave($this->account->pdo, $result['id']);
        unset($this->cache['eventos']);
        calendarLog('calendar_event_updated', $event, ['origin' => 'caldav']);
        return null;
    }

    /** Evento proprio: eliminado para todos. Convite: o utilizador sai dele. */
    public function deleteCalendarObject($calendarId, $objectUri)
    {
        $object = $this->objects((string) $calendarId)[$objectUri] ?? null;
        if ($object === null) {
            return;
        }
        $event = $object['event'];
        if ($calendarId === 'eventos') {
            calendarDelete($this->account->pdo, (int) $event['id']);
            calendarLog('calendar_event_deleted', $event, ['origin' => 'caldav']);
        } else {
            $this->account->pdo->prepare('DELETE FROM calendar_event_members WHERE event_id = ? AND user_id = ?')
                ->execute([(int) $event['id'], $this->account->userId()]);
            calendarLog('calendar_event_left', $event, ['origin' => 'caldav']);
        }
        unset($this->cache[(string) $calendarId]);
    }

    /** @return array{data:array,uid:string} */
    private function read(string $calendarData, ?array $event): array
    {
        try {
            $parsed = caldavEventFromIcs($calendarData, $event);
            return ['data' => calendarValidate($parsed['input']), 'uid' => $parsed['uid']];
        } catch (InvalidArgumentException $e) {
            throw new DAV\Exception\Forbidden($e->getMessage());
        }
    }
}
