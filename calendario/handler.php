<?php
// Endpoint do calendario do dashboard (rota "calendario/handler").
// Ver calendario/functions.php e docs/calendario.md.
//
// GET  ?action=feed&start=&end=&scope=mine|all   eventos FullCalendar
// GET  ?action=view&uuid=                        evento para o modal
// GET  ?action=clients&q=                        pesquisa de clientes (Select2)
// GET  ?action=notices                           avisos por mostrar (calendario-avisos.js)
// POST action=save   [uuid] title start end all_day location description reminder_minutes members[] entity_id
// POST action=move   uuid start end all_day      arrastar/redimensionar no calendario
// POST action=delete uuid
// POST action=caldav_create (formulario do perfil: device=ios|manual, name)
// POST action=caldav_revoke (formulario do perfil: access_id)
//
// Os POST exigem csrf_token (nao consumido, como no chat interno).

require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$isProfileForm = in_array($action, ['caldav_create', 'caldav_revoke'], true);

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (int) ($_SESSION['user_role'] ?? 3);

if ($isPost && !validateCsrfToken((string) ($_POST['csrf_token'] ?? ''), false)) {
    if ($isProfileForm) {
        setSessionFlash('caldav', ['type' => 'danger', 'message' => 'Sessão expirada. Tente novamente.']);
        header('Location: ' . BASE_URL . 'users/profile#calendario-telemovel');
        exit;
    }
    $respond(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 400);
}

if (!calendarHasTables()) {
    $respond(['ok' => false, 'error' => 'O calendário ainda não está disponível nesta base de dados. Execute as migrações pendentes.'], 503);
}

$pdo = getPDO();

// ---------------------------------------------------------------- perfil: CalDAV
if ($isProfileForm && $isPost) {
    require_once __DIR__ . '/caldav-functions.php';
    $back = BASE_URL . 'users/profile#calendario-telemovel';
    if (!calendarHasCaldavTable()) {
        setSessionFlash('caldav', ['type' => 'danger', 'message' => 'Execute as migrações pendentes para ativar o calendário no telemóvel.']);
        header('Location: ' . $back);
        exit;
    }
    if ($action === 'caldav_revoke') {
        if (caldavRevokeAccess($pdo, $userId, (int) ($_POST['access_id'] ?? 0))) {
            logAuditAction('caldav_access_revoked', 'user', $userId);
            setSessionFlash('caldav', ['type' => 'success', 'message' => 'Acesso revogado: esse dispositivo deixa de sincronizar o calendário.']);
        }
        header('Location: ' . $back);
        exit;
    }
    $device = (string) ($_POST['device'] ?? 'manual') === 'ios' ? 'ios' : 'manual';
    $name = trim((string) ($_POST['name'] ?? '')) ?: ($device === 'ios' ? 'iPhone' : 'Telemóvel');
    $created = caldavCreateAccess($pdo, $userId, $name);
    logAuditAction('caldav_access_created', 'user', $userId, ['name' => $name]);
    $user = currentUser();
    $login = caldavLogin((string) ($user['username'] ?? ''), caldavCurrentCompanyNif());
    if ($device === 'ios') {
        caldavSendMobileConfig(caldavMobileConfig(appAbsoluteBaseUrl(), $login, $created['password'], caldavPrincipalPath($userId), calendarAppName()));
    }
    // A password so e mostrada uma vez (flash da sessao).
    setSessionFlash('caldav', ['type' => 'new', 'name' => $name, 'login' => $login, 'password' => $created['password']]);
    header('Location: ' . $back);
    exit;
}

// Daqui para a frente so JSON; liberta a sessao para nao bloquear outros pedidos.
session_write_close();

try {
    if ($action === 'notices' && !$isPost) {
        $respond(['ok' => true, 'notices' => calendarPullNotices($pdo, $userId)]);
    }

    if ($action === 'feed' && !$isPost) {
        $start = substr((string) ($_GET['start'] ?? ''), 0, 10);
        $end = substr((string) ($_GET['end'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) !== 1) {
            $respond(['ok' => false, 'error' => 'Intervalo inválido.'], 400);
        }
        $scope = (string) ($_GET['scope'] ?? 'mine') === 'all' ? 'all' : 'mine';
        $respond(['ok' => true, 'events' => calendarFeed($pdo, $userId, $role, $start, $end, $scope)]);
    }

    if ($action === 'clients' && !$isPost) {
        $respond(['ok' => true, 'results' => calendarSearchClients($pdo, (string) ($_GET['q'] ?? ''))]);
    }

    $uuid = strtolower(trim((string) ($isPost ? ($_POST['uuid'] ?? '') : ($_GET['uuid'] ?? ''))));
    $event = $uuid !== '' ? calendarLoad($pdo, $uuid) : null;
    if ($uuid !== '' && ($event === null || !calendarCanView($event, $userId, $role))) {
        $respond(['ok' => false, 'error' => 'Evento não encontrado.'], 404);
    }
    if (in_array($action, ['move', 'delete'], true) && $event === null) {
        $respond(['ok' => false, 'error' => 'Evento não encontrado.'], 404);
    }
    if ($isPost && $event !== null && !calendarCanEdit($event, $userId, $role)) {
        $respond(['ok' => false, 'error' => 'Só quem criou o evento o pode alterar.'], 403);
    }

    if ($action === 'view' && !$isPost && $event !== null) {
        $respond(['ok' => true, 'event' => calendarEventJson($event, $userId, $role)]);
    }

    if ($action === 'save' && $isPost) {
        $data = calendarValidate($_POST);
        $result = calendarSave($pdo, $userId, $data, $event);
        calendarLog($event === null ? 'calendar_event_created' : 'calendar_event_updated', ['id' => $result['id'], 'uuid' => $result['uuid'], 'title' => $data['title']], [
            'start' => $data['start'], 'end' => $data['end'], 'members' => $data['members'], 'entity_id' => $data['entity_id'],
        ]);
        calendarPushAfterSave($pdo, $result['id']);
        $respond(['ok' => true, 'uuid' => $result['uuid'], 'message' => $event === null ? 'Evento criado.' : 'Evento gravado.']);
    }

    if ($action === 'move' && $isPost) {
        $data = calendarValidate([
            'title' => $event['title'],
            'description' => $event['description'],
            'location' => $event['location'],
            'reminder_minutes' => $event['reminder_minutes'],
            'members' => $event['members'],
            'start' => $_POST['start'] ?? '',
            'end' => $_POST['end'] ?? '',
            'all_day' => $_POST['all_day'] ?? '0',
        ]);
        $data['keep_client'] = true;
        $result = calendarSave($pdo, $userId, $data, $event);
        calendarLog('calendar_event_moved', $event, ['start' => $data['start'], 'end' => $data['end']]);
        calendarPushAfterSave($pdo, $result['id']);
        $respond(['ok' => true, 'uuid' => $result['uuid'], 'message' => 'Evento movido.']);
    }

    if ($action === 'delete' && $isPost) {
        calendarDelete($pdo, (int) $event['id']);
        calendarLog('calendar_event_deleted', $event);
        $respond(['ok' => true, 'message' => 'Evento eliminado.']);
    }

    $respond(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (InvalidArgumentException $e) {
    $respond(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if (getSetting('debug_mode', '0') === '1') {
        error_log('[calendario] ' . $e->getMessage());
    }
    $respond(['ok' => false, 'error' => 'Erro interno ao processar o calendário.'], 500);
}
