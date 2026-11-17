<?php
// Ausencias e Ferias: pedidos dos colaboradores, aprovacao pelos gestores e
// saldo de ferias. Rota "assiduidade/ausencias".
//   ?ano=YYYY  ?estado=pending|approved|rejected|cancelled  ?colaborador=ID  ?tipo=ID
// POST (formularios desta pagina, PRG com flash):
//   save    [user_id] type_id start_date end_date day_part notes [approve_now]
//   decide  id decision=approved|rejected [decision_note]   (gestores)
//   cancel  id                                              (proprio ou gestor)
//   delete  id                                              (gestores)
// Ver docs/assiduidade.md.

require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
attendanceRequirePage();

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$canViewTeam = attendanceCanViewTeam();
$canManage = attendanceCanManage();
$pdo = getPDO();
$selfUrl = BASE_URL . 'assiduidade/ausencias';

$year = (int) ($_GET['ano'] ?? date('Y'));
if ($year < 2000 || $year > 2100) {
    $year = (int) date('Y');
}

// ---------------------------------------------------------------- POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $back = $selfUrl . '?' . http_build_query(array_filter([
        'ano' => $_POST['back_year'] ?? $year,
        'estado' => $_POST['back_status'] ?? '',
        'colaborador' => $_POST['back_user'] ?? '',
    ]));
    $flash = static function (string $type, string $message) use ($back): void {
        setSessionFlash('attendance_absences', ['type' => $type, 'message' => $message]);
        header('Location: ' . $back);
        exit;
    };
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''), false)) {
        $flash('danger', 'Sessão expirada. Tente novamente.');
    }
    $action = (string) ($_POST['action'] ?? '');

    $loadAbsence = static function (int $id) use ($pdo): ?array {
        $stmt = $pdo->prepare('SELECT a.*, t.name AS type_name FROM attendance_absences a JOIN attendance_absence_types t ON t.id = a.type_id WHERE a.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    if ($action === 'save') {
        $targetId = $canManage ? (int) ($_POST['user_id'] ?? $userId) : $userId;
        $types = attendanceAbsenceTypes(true);
        $typeId = (int) ($_POST['type_id'] ?? 0);
        $type = $types[$typeId] ?? null;
        $start = attendanceValidDate($_POST['start_date'] ?? '');
        $end = attendanceValidDate($_POST['end_date'] ?? '') ?: $start;
        $dayPart = (string) ($_POST['day_part'] ?? 'full');
        $notes = trim((string) ($_POST['notes'] ?? ''));
        if (!$type) {
            $flash('danger', 'Escolha o tipo de ausência.');
        }
        if (!$start || !$end || $end < $start) {
            $flash('danger', 'Datas inválidas: a data de fim tem de ser igual ou posterior à de início.');
        }
        if ((new DateTime($start))->diff(new DateTime($end))->days > 366) {
            $flash('danger', 'O período não pode ultrapassar um ano.');
        }
        if (!isset(ATTENDANCE_DAY_PARTS[$dayPart]) || $start !== $end) {
            $dayPart = 'full';
        }
        $days = attendanceCountAbsenceDays($targetId, $start, $end, $dayPart);
        if ($days <= 0) {
            $flash('danger', 'O período escolhido não inclui dias de trabalho (fins de semana, folgas ou feriados).');
        }
        if ($overlap = attendanceFindOverlap($targetId, $start, $end, $dayPart)) {
            $flash('danger', 'Já existe uma ausência (' . $overlap['type_name'] . ', ' . date('d/m/Y', strtotime($overlap['start_date'])) . ') que se sobrepõe a este período.');
        }
        if ((int) $type['is_vacation'] === 1 && !$canManage) {
            $years = range((int) substr($start, 0, 4), (int) substr($end, 0, 4));
            foreach ($years as $y) {
                $summary = attendanceVacationSummary($targetId, $y);
                $inYear = attendanceCountAbsenceDays($targetId, max($start, "$y-01-01"), min($end, "$y-12-31"), $dayPart);
                if ($inYear > $summary['remaining'] + 0.001) {
                    $flash('danger', 'Não tem dias de férias suficientes em ' . $y . ' (por gozar: ' . attendanceFormatDays(max(0, $summary['remaining'])) . ', pedido: ' . attendanceFormatDays($inYear) . ').');
                }
            }
        }
        $approveNow = $canManage && !empty($_POST['approve_now']);
        $status = ($approveNow || (int) $type['requires_approval'] === 0) ? 'approved' : 'pending';
        $stmt = $pdo->prepare('INSERT INTO attendance_absences (user_id, type_id, start_date, end_date, day_part, status, notes, created_by, decided_by, decided_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$targetId, $typeId, $start, $end, $dayPart, $status, $notes !== '' ? mb_substr($notes, 0, 2000) : null, $userId,
            $status === 'approved' ? $userId : null, $status === 'approved' ? date('Y-m-d H:i:s') : null]);
        $absenceId = (int) $pdo->lastInsertId();
        logAuditAction('attendance_absence_create', 'attendance_absence', $absenceId, ['user_id' => $targetId, 'type' => $type['code'], 'start' => $start, 'end' => $end, 'status' => $status]);

        $period = date('d/m/Y', strtotime($start)) . ($end !== $start ? ' a ' . date('d/m/Y', strtotime($end)) : '');
        if ($status === 'pending') {
            $who = attendanceUserLabel($targetId === $userId ? $user : ($pdo->query('SELECT name, username FROM users WHERE id = ' . $targetId)->fetch(PDO::FETCH_ASSOC) ?: []));
            attendanceNotifyUsers(array_diff(attendanceManagerIds(), [$userId]), 'Pedido de ' . mb_strtolower($type['name']),
                $who . ': ' . $period . ' (' . attendanceFormatDays($days) . ' dia(s))', $selfUrl . '?estado=pending&ano=' . substr($start, 0, 4));
        } elseif ($targetId !== $userId) {
            attendanceNotifyUsers([$targetId], $type['name'] . ' registada', $period . ' (' . attendanceFormatDays($days) . ' dia(s))', $selfUrl . '?ano=' . substr($start, 0, 4));
        }
        $flash('success', $status === 'pending'
            ? 'Pedido registado (' . attendanceFormatDays($days) . ' dia(s)). Fica pendente até ser aprovado.'
            : 'Ausência registada (' . attendanceFormatDays($days) . ' dia(s)).');
    }

    $id = (int) ($_POST['id'] ?? 0);
    $absence = $id > 0 ? $loadAbsence($id) : null;
    if (!$absence) {
        $flash('danger', 'Ausência não encontrada.');
    }
    $period = date('d/m/Y', strtotime($absence['start_date'])) . ($absence['end_date'] !== $absence['start_date'] ? ' a ' . date('d/m/Y', strtotime($absence['end_date'])) : '');

    if ($action === 'decide') {
        if (!$canManage) {
            $flash('danger', 'Sem permissão para aprovar ausências.');
        }
        $decision = (string) ($_POST['decision'] ?? '');
        if (!in_array($decision, ['approved', 'rejected'], true) || $absence['status'] !== 'pending') {
            $flash('danger', 'Este pedido já não está pendente.');
        }
        $note = mb_substr(trim((string) ($_POST['decision_note'] ?? '')), 0, 255);
        $pdo->prepare('UPDATE attendance_absences SET status = ?, decision_note = ?, decided_by = ?, decided_at = ? WHERE id = ?')
            ->execute([$decision, $note, $userId, date('Y-m-d H:i:s'), $id]);
        logAuditAction('attendance_absence_' . ($decision === 'approved' ? 'approve' : 'reject'), 'attendance_absence', $id, ['note' => $note]);
        attendanceNotifyUsers([(int) $absence['user_id']], $absence['type_name'] . ($decision === 'approved' ? ' aprovada' : ' recusada'),
            $period . ($note !== '' ? ' — ' . $note : ''), $selfUrl . '?ano=' . substr($absence['start_date'], 0, 4));
        $flash('success', $decision === 'approved' ? 'Pedido aprovado.' : 'Pedido recusado.');
    }

    if ($action === 'cancel') {
        $isOwner = (int) $absence['user_id'] === $userId;
        $ownerCanCancel = $isOwner && ($absence['status'] === 'pending' || ($absence['status'] === 'approved' && $absence['start_date'] > date('Y-m-d')));
        if (!$canManage && !$ownerCanCancel) {
            $flash('danger', 'Só pode cancelar pedidos pendentes ou ausências que ainda não começaram.');
        }
        if (!in_array($absence['status'], ['pending', 'approved'], true)) {
            $flash('danger', 'Esta ausência já não está ativa.');
        }
        $pdo->prepare("UPDATE attendance_absences SET status = 'cancelled', decided_by = ?, decided_at = ? WHERE id = ?")
            ->execute([$userId, date('Y-m-d H:i:s'), $id]);
        logAuditAction('attendance_absence_cancel', 'attendance_absence', $id);
        if (!$isOwner) {
            attendanceNotifyUsers([(int) $absence['user_id']], $absence['type_name'] . ' cancelada', $period, $selfUrl . '?ano=' . substr($absence['start_date'], 0, 4));
        }
        $flash('success', 'Ausência cancelada.');
    }

    if ($action === 'delete') {
        if (!$canManage) {
            $flash('danger', 'Sem permissão.');
        }
        $pdo->prepare('DELETE FROM attendance_absences WHERE id = ?')->execute([$id]);
        logAuditAction('attendance_absence_delete', 'attendance_absence', $id, ['user_id' => (int) $absence['user_id'], 'start' => $absence['start_date'], 'end' => $absence['end_date']]);
        $flash('success', 'Ausência eliminada.');
    }

    $flash('danger', 'Ação desconhecida.');
}

// ---------------------------------------------------------------- GET
$statusFilter = (string) ($_GET['estado'] ?? '');
if (!isset(ATTENDANCE_STATUSES[$statusFilter])) {
    $statusFilter = '';
}
$team = $canViewTeam ? attendanceUsers(false) : [];
$userFilter = $canViewTeam ? (int) ($_GET['colaborador'] ?? 0) : $userId;
$typeFilter = (int) ($_GET['tipo'] ?? 0);
$types = attendanceAbsenceTypes();
$activeTypes = attendanceAbsenceTypes(true);

$where = ['a.start_date <= ?', 'a.end_date >= ?'];
$params = ["$year-12-31", "$year-01-01"];
if ($statusFilter !== '') {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}
if ($userFilter > 0) {
    $where[] = 'a.user_id = ?';
    $params[] = $userFilter;
}
if ($typeFilter > 0) {
    $where[] = 'a.type_id = ?';
    $params[] = $typeFilter;
}
$stmt = $pdo->prepare('SELECT a.*, t.name AS type_name, t.color AS type_color, t.code AS type_code,
        COALESCE(NULLIF(u.name, \'\'), u.username) AS user_label,
        COALESCE(NULLIF(d.name, \'\'), d.username) AS decided_label
    FROM attendance_absences a
    JOIN attendance_absence_types t ON t.id = a.type_id
    JOIN users u ON u.id = a.user_id
    LEFT JOIN users d ON d.id = a.decided_by
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY (a.status = \'pending\') DESC, a.start_date DESC, a.id DESC');
$stmt->execute($params);
$absences = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = $canManage ? attendancePendingAbsenceCount() : 0;
$ownVacation = attendanceVacationSummary($userId, $year);
$teamVacation = [];
if ($canViewTeam) {
    foreach (attendanceUsers(true) as $member) {
        $teamVacation[] = ['user' => $member, 'summary' => attendanceVacationSummary((int) $member['id'], $year)];
    }
}
$flashMsg = pullSessionFlash('attendance_absences');
$csrfToken = generateCsrfToken();
$today = date('Y-m-d');
$backFields = '<input type="hidden" name="back_year" value="' . $year . '"><input type="hidden" name="back_status" value="' . attendanceEsc($statusFilter) . '"><input type="hidden" name="back_user" value="' . ($canViewTeam ? $userFilter : '') . '">';

$useAttendance = true;
require_once dirname(__DIR__) . '/header.php';
?>
<div class="container-fluid attendance-page">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h2 class="mb-1"><i class="fa fa-plane"></i> Ausências e Férias</h2>
            <p class="mb-0 text-muted">Pedidos de férias, faltas e outras ausências, com aprovação e saldo anual.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($canManage && $pendingCount > 0): ?>
                <a href="<?= $selfUrl; ?>?estado=pending&ano=<?= $year; ?>" class="btn btn-warning btn-sm"><i class="fa fa-inbox"></i> <?= $pendingCount; ?> pendente(s)</a>
            <?php endif; ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#absenceModal"><i class="fa fa-plus"></i> <?= $canManage ? 'Registar ausência' : 'Pedir férias / ausência'; ?></button>
        </div>
    </div>

    <?php if ($flashMsg): ?>
        <div class="alert alert-<?= attendanceEsc($flashMsg['type']); ?> alert-dismissible fade show" role="alert">
            <?= attendanceEsc($flashMsg['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>
    <?php endif; ?>

    <div class="row tile_count attendance-tiles">
        <div class="col-6 col-md-3 tile_stats_count">
            <span class="count_top"><i class="fa fa-calendar"></i> Direito <?= $year; ?></span>
            <div class="count"><?= attendanceEsc(attendanceFormatDays($ownVacation['total'])); ?></div>
            <span class="count_bottom"><?= attendanceEsc(attendanceFormatDays($ownVacation['allowed'])); ?> do ano<?= $ownVacation['carryover'] > 0 ? ' + ' . attendanceEsc(attendanceFormatDays($ownVacation['carryover'])) . ' transitados' : ''; ?></span>
        </div>
        <div class="col-6 col-md-3 tile_stats_count">
            <span class="count_top"><i class="fa fa-check"></i> Gozados / marcados</span>
            <div class="count"><?= attendanceEsc(attendanceFormatDays($ownVacation['used'])); ?></div>
            <span class="count_bottom">aprovados</span>
        </div>
        <div class="col-6 col-md-3 tile_stats_count">
            <span class="count_top"><i class="fa fa-hourglass-half"></i> Pendentes</span>
            <div class="count"><?= attendanceEsc(attendanceFormatDays($ownVacation['pending'])); ?></div>
            <span class="count_bottom">a aguardar aprovação</span>
        </div>
        <div class="col-6 col-md-3 tile_stats_count">
            <span class="count_top"><i class="fa fa-plane"></i> Por gozar</span>
            <div class="count <?= $ownVacation['remaining'] < 0 ? 'red' : 'green'; ?>"><?= attendanceEsc(attendanceFormatDays($ownVacation['remaining'])); ?></div>
            <span class="count_bottom">dias úteis</span>
        </div>
    </div>

    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-list"></i> Registos de <?= $year; ?></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <form method="get" action="<?= $selfUrl; ?>" class="row g-2 align-items-end mb-3">
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="filterYear">Ano</label>
                    <select name="ano" id="filterYear" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 5; $y--): ?>
                            <option value="<?= $y; ?>" <?= $y === $year ? 'selected' : ''; ?>><?= $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small mb-1" for="filterStatus">Estado</label>
                    <select name="estado" id="filterStatus" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach (ATTENDANCE_STATUSES as $key => [$label]): ?>
                            <option value="<?= $key; ?>" <?= $statusFilter === $key ? 'selected' : ''; ?>><?= attendanceEsc($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="filterType">Tipo</label>
                    <select name="tipo" id="filterType" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($types as $type): ?>
                            <option value="<?= (int) $type['id']; ?>" <?= $typeFilter === (int) $type['id'] ? 'selected' : ''; ?>><?= attendanceEsc($type['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($canViewTeam): ?>
                <div class="col-6 col-md-3">
                    <label class="form-label small mb-1" for="filterUser">Colaborador</label>
                    <select name="colaborador" id="filterUser" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <?php foreach ($team as $member): ?>
                            <option value="<?= (int) $member['id']; ?>" <?= $userFilter === (int) $member['id'] ? 'selected' : ''; ?>><?= attendanceEsc($member['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="table table-striped table-sm jambo_table attendance-absences-table">
                    <thead>
                        <tr>
                            <?php if ($canViewTeam): ?><th>Colaborador</th><?php endif; ?>
                            <th>Tipo</th>
                            <th>Período</th>
                            <th class="text-center">Dias úteis</th>
                            <th>Estado</th>
                            <th>Observações</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$absences): ?>
                        <tr><td colspan="<?= $canViewTeam ? 7 : 6; ?>" class="text-center text-muted">Sem registos para os filtros escolhidos.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($absences as $absence):
                        [$statusLabel, $statusClass] = ATTENDANCE_STATUSES[$absence['status']] ?? [$absence['status'], 'secondary'];
                        $isOwner = (int) $absence['user_id'] === $userId;
                        $canCancel = in_array($absence['status'], ['pending', 'approved'], true)
                            && ($canManage || ($isOwner && ($absence['status'] === 'pending' || $absence['start_date'] > $today)));
                        $days = attendanceCountAbsenceDays((int) $absence['user_id'], (string) $absence['start_date'], (string) $absence['end_date'], (string) $absence['day_part']);
                    ?>
                        <tr>
                            <?php if ($canViewTeam): ?><td><?= attendanceEsc($absence['user_label']); ?></td><?php endif; ?>
                            <td><span class="badge attendance-badge-absence" style="--absence-color: <?= attendanceEsc($absence['type_color']); ?>"><?= attendanceEsc($absence['type_name']); ?></span></td>
                            <td class="text-nowrap">
                                <?= date('d/m/Y', strtotime($absence['start_date'])); ?><?= $absence['end_date'] !== $absence['start_date'] ? ' – ' . date('d/m/Y', strtotime($absence['end_date'])) : ''; ?>
                                <?php if ($absence['day_part'] !== 'full'): ?><span class="text-muted small">(<?= attendanceEsc(mb_strtolower(ATTENDANCE_DAY_PARTS[$absence['day_part']])); ?>)</span><?php endif; ?>
                            </td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDays($days)); ?></td>
                            <td>
                                <span class="badge bg-<?= $statusClass; ?><?= $statusClass === 'warning' ? ' text-dark' : ''; ?>"><?= attendanceEsc($statusLabel); ?></span>
                                <?php if ($absence['decided_label'] && $absence['status'] !== 'pending'): ?>
                                    <div class="text-muted small"><?= attendanceEsc($absence['decided_label']); ?>, <?= date('d/m/Y', strtotime((string) $absence['decided_at'])); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= nl2br(attendanceEsc((string) $absence['notes'])); ?>
                                <?php if ($absence['decision_note'] !== ''): ?><div class="text-muted"><i class="fa fa-reply"></i> <?= attendanceEsc($absence['decision_note']); ?></div><?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if ($canManage && $absence['status'] === 'pending'): ?>
                                    <button type="button" class="btn btn-xs btn-success js-absence-decide" data-id="<?= (int) $absence['id']; ?>" data-decision="approved" data-label="<?= attendanceEsc($absence['user_label'] . ' — ' . $absence['type_name']); ?>"><i class="fa fa-check"></i> Aprovar</button>
                                    <button type="button" class="btn btn-xs btn-danger js-absence-decide" data-id="<?= (int) $absence['id']; ?>" data-decision="rejected" data-label="<?= attendanceEsc($absence['user_label'] . ' — ' . $absence['type_name']); ?>"><i class="fa fa-times"></i> Recusar</button>
                                <?php endif; ?>
                                <?php if ($canCancel): ?>
                                    <form method="post" action="<?= $selfUrl; ?>" class="d-inline" onsubmit="return confirm('Cancelar esta ausência?');">
                                        <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="id" value="<?= (int) $absence['id']; ?>">
                                        <?= $backFields; ?>
                                        <button type="submit" class="btn btn-xs btn-secondary"><i class="fa fa-ban"></i> Cancelar</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($canManage): ?>
                                    <form method="post" action="<?= $selfUrl; ?>" class="d-inline" onsubmit="return confirm('Eliminar definitivamente este registo?');">
                                        <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $absence['id']; ?>">
                                        <?= $backFields; ?>
                                        <button type="submit" class="btn btn-xs btn-outline-danger" title="Eliminar"><i class="fa fa-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($canViewTeam && $teamVacation): ?>
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-users"></i> Saldo de férias da equipa <small><?= $year; ?></small></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr><th>Colaborador</th><th class="text-center">Direito</th><th class="text-center">Transitados</th><th class="text-center">Gozados</th><th class="text-center">Pendentes</th><th class="text-center">Por gozar</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($teamVacation as $row): $s = $row['summary']; ?>
                        <tr>
                            <td><a href="<?= $selfUrl; ?>?ano=<?= $year; ?>&colaborador=<?= (int) $row['user']['id']; ?>"><?= attendanceEsc($row['user']['label']); ?></a></td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDays($s['allowed'])); ?></td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDays($s['carryover'])); ?></td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDays($s['used'])); ?></td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDays($s['pending'])); ?></td>
                            <td class="text-center fw-bold <?= $s['remaining'] < 0 ? 'text-danger' : ''; ?>"><?= attendanceEsc(attendanceFormatDays($s['remaining'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mb-0">O direito anual e os dias transitados definem-se em Assiduidade &gt; Configuração &gt; Colaboradores.</p>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="absenceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= $selfUrl; ?>" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= $canManage ? 'Registar ausência' : 'Pedir férias / ausência'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
                <input type="hidden" name="action" value="save">
                <?= $backFields; ?>
                <?php if ($canManage): ?>
                <div class="mb-3">
                    <label class="form-label" for="absenceUser">Colaborador</label>
                    <select name="user_id" id="absenceUser" class="form-select" required>
                        <?php foreach (attendanceUsers(true) as $member): ?>
                            <option value="<?= (int) $member['id']; ?>" <?= (int) $member['id'] === ($userFilter ?: $userId) ? 'selected' : ''; ?>><?= attendanceEsc($member['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label" for="absenceType">Tipo</label>
                    <select name="type_id" id="absenceType" class="form-select" required>
                        <?php foreach ($activeTypes as $type): ?>
                            <option value="<?= (int) $type['id']; ?>"><?= attendanceEsc($type['name']); ?><?= (int) $type['requires_approval'] === 1 && !$canManage ? ' (requer aprovação)' : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="absenceStart">De</label>
                        <input type="date" name="start_date" id="absenceStart" class="form-control" required value="<?= $today; ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="absenceEnd">Até</label>
                        <input type="date" name="end_date" id="absenceEnd" class="form-control" required value="<?= $today; ?>">
                    </div>
                </div>
                <div class="mb-3" id="absenceDayPartGroup">
                    <label class="form-label" for="absenceDayPart">Parte do dia</label>
                    <select name="day_part" id="absenceDayPart" class="form-select">
                        <?php foreach (ATTENDANCE_DAY_PARTS as $key => $label): ?>
                            <option value="<?= $key; ?>"><?= attendanceEsc($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Só para ausências de um único dia.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="absenceNotes">Observações</label>
                    <textarea name="notes" id="absenceNotes" class="form-control" rows="2" maxlength="2000"></textarea>
                </div>
                <?php if ($canManage): ?>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="approve_now" id="absenceApproveNow" value="1" checked>
                    <label class="form-check-label" for="absenceApproveNow">Aprovar já</label>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?= $canManage ? 'Registar' : 'Enviar pedido'; ?></button>
            </div>
        </form>
    </div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="absenceDecideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="<?= $selfUrl; ?>" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="absenceDecideTitle">Decidir pedido</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
                <input type="hidden" name="action" value="decide">
                <input type="hidden" name="id" id="absenceDecideId">
                <input type="hidden" name="decision" id="absenceDecideValue">
                <?= $backFields; ?>
                <p id="absenceDecideLabel" class="fw-bold"></p>
                <label class="form-label" for="absenceDecideNote">Nota para o colaborador (opcional)</label>
                <input type="text" name="decision_note" id="absenceDecideNote" class="form-control" maxlength="255">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Voltar</button>
                <button type="submit" class="btn" id="absenceDecideSubmit">Confirmar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php
$pageScripts = <<<'JS'
(function () {
    var start = document.getElementById('absenceStart');
    var end = document.getElementById('absenceEnd');
    var group = document.getElementById('absenceDayPartGroup');
    var part = document.getElementById('absenceDayPart');
    function sync() {
        if (end.value && start.value && end.value < start.value) { end.value = start.value; }
        var single = start.value === end.value;
        group.style.display = single ? '' : 'none';
        if (!single) { part.value = 'full'; }
    }
    start.addEventListener('change', sync);
    end.addEventListener('change', sync);
    sync();

    var decideEl = document.getElementById('absenceDecideModal');
    if (decideEl) {
        var decideModal = new bootstrap.Modal(decideEl);
        document.querySelectorAll('.js-absence-decide').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var approve = btn.getAttribute('data-decision') === 'approved';
                document.getElementById('absenceDecideId').value = btn.getAttribute('data-id');
                document.getElementById('absenceDecideValue').value = btn.getAttribute('data-decision');
                document.getElementById('absenceDecideLabel').textContent = btn.getAttribute('data-label');
                document.getElementById('absenceDecideTitle').textContent = approve ? 'Aprovar pedido' : 'Recusar pedido';
                var submit = document.getElementById('absenceDecideSubmit');
                submit.className = 'btn ' + (approve ? 'btn-success' : 'btn-danger');
                submit.textContent = approve ? 'Aprovar' : 'Recusar';
                decideModal.show();
            });
        });
    }
})();
JS;
require_once dirname(__DIR__) . '/footer.php';
