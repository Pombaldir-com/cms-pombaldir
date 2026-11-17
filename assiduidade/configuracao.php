<?php
// Configuracao da assiduidade (administradores, role <= 2).
// Rota "assiduidade/configuracao".
//   ?tab=horarios|colaboradores|tipos|feriados|geral
//   ?tab=horarios&horario=ID|novo   formulario de um horario
// POST (PRG com flash): schedule_save, schedule_delete, users_save,
// types_save, type_delete, holiday_add, holiday_delete, general_save.
// Ver docs/assiduidade.md.

require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
attendanceRequirePage();

if (!attendanceIsAdmin()) {
    http_response_code(403);
    require_once dirname(__DIR__) . '/header.php';
    echo '<div class="x_panel"><div class="x_content"><div class="alert alert-danger mb-0">Só os administradores podem configurar a assiduidade.</div></div></div>';
    require_once dirname(__DIR__) . '/footer.php';
    exit;
}

$pdo = getPDO();
$selfUrl = BASE_URL . 'assiduidade/configuracao';
$tabs = [
    'horarios' => ['Horários', 'fa-clock-o'],
    'colaboradores' => ['Colaboradores', 'fa-users'],
    'tipos' => ['Tipos de ausência', 'fa-tags'],
    'feriados' => ['Feriados', 'fa-flag'],
    'geral' => ['Geral', 'fa-cog'],
];
$tab = (string) ($_GET['tab'] ?? 'horarios');
if (!isset($tabs[$tab])) {
    $tab = 'horarios';
}

// ---------------------------------------------------------------- POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $backTab = (string) ($_POST['tab'] ?? $tab);
    $flash = static function (string $type, string $message, string $extra = '') use ($selfUrl, $backTab): void {
        setSessionFlash('attendance_config', ['type' => $type, 'message' => $message]);
        header('Location: ' . $selfUrl . '?tab=' . rawurlencode($backTab) . $extra);
        exit;
    };
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''), false)) {
        $flash('danger', 'Sessão expirada. Tente novamente.');
    }

    if ($action === 'schedule_save') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $flash('danger', 'Indique o nome do horário.', '&horario=' . ($id ?: 'novo'));
        }
        $tolerance = max(0, min(240, (int) ($_POST['tolerance_minutes'] ?? 0)));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($_POST['color'] ?? '')) ? (string) $_POST['color'] : '#3498DB';
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;
        $isActive = !empty($_POST['is_active']) || $isDefault ? 1 : 0;
        $days = [];
        foreach (ATTENDANCE_WEEKDAYS as $wd => $label) {
            $row = $_POST['days'][$wd] ?? [];
            $vals = [];
            foreach (['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as $field) {
                $raw = trim((string) ($row[$field] ?? ''));
                $vals[$field] = $raw === '' ? null : attendanceNormalizeTimeInput($raw);
                if ($raw !== '' && $vals[$field] === null) {
                    $flash('danger', $label . ': hora inválida.', '&horario=' . ($id ?: 'novo'));
                }
            }
            foreach (['morning', 'afternoon'] as $part) {
                $s = $vals[$part . '_start'];
                $e = $vals[$part . '_end'];
                if (($s === null) !== ($e === null) || ($s !== null && $e <= $s)) {
                    $flash('danger', $label . ': o período da ' . ($part === 'morning' ? 'manhã' : 'tarde') . ' precisa de início e fim, com o fim depois do início.', '&horario=' . ($id ?: 'novo'));
                }
            }
            if ($vals['morning_end'] !== null && $vals['afternoon_start'] !== null && $vals['afternoon_start'] < $vals['morning_end']) {
                $flash('danger', $label . ': a tarde não pode começar antes do fim da manhã.', '&horario=' . ($id ?: 'novo'));
            }
            $days[$wd] = $vals;
        }
        $pdo->beginTransaction();
        try {
            if ($isDefault) {
                $pdo->exec('UPDATE attendance_schedules SET is_default = 0');
            }
            $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
            if ($id > 0) {
                $pdo->prepare('UPDATE attendance_schedules SET name = ?, description = ?, tolerance_minutes = ?, color = ?, is_default = IF(? = 1, 1, is_default), is_active = ? WHERE id = ?')
                    ->execute([mb_substr($name, 0, 120), $desc, $tolerance, $color, $isDefault, $isActive, $id]);
            } else {
                $pdo->prepare('INSERT INTO attendance_schedules (name, description, tolerance_minutes, color, is_default, is_active) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([mb_substr($name, 0, 120), $desc, $tolerance, $color, $isDefault, $isActive]);
                $id = (int) $pdo->lastInsertId();
            }
            // Garantir sempre um horario por omissao.
            if (!(int) $pdo->query('SELECT COUNT(*) FROM attendance_schedules WHERE is_default = 1')->fetchColumn()) {
                $pdo->prepare('UPDATE attendance_schedules SET is_default = 1, is_active = 1 WHERE id = ?')->execute([$id]);
            }
            $pdo->prepare('DELETE FROM attendance_schedule_days WHERE schedule_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO attendance_schedule_days (schedule_id, weekday, morning_start, morning_end, afternoon_start, afternoon_end) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($days as $wd => $vals) {
                if ($vals['morning_start'] === null && $vals['afternoon_start'] === null) {
                    continue;
                }
                $ins->execute([$id, $wd, $vals['morning_start'], $vals['morning_end'], $vals['afternoon_start'], $vals['afternoon_end']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $flash('danger', 'Não foi possível guardar o horário: ' . $e->getMessage());
        }
        logAuditAction('attendance_schedule_save', 'attendance_schedule', $id, ['name' => $name]);
        $flash('success', 'Horário "' . $name . '" guardado.');
    }

    if ($action === 'schedule_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT * FROM attendance_schedules WHERE id = ?');
        $row->execute([$id]);
        $schedule = $row->fetch(PDO::FETCH_ASSOC);
        if (!$schedule) {
            $flash('danger', 'Horário não encontrado.');
        }
        if ((int) $schedule['is_default'] === 1) {
            $flash('danger', 'Não pode eliminar o horário por omissão. Defina outro como omissão primeiro.');
        }
        // Os colaboradores com este horario passam para o horario por omissao (FK ON DELETE SET NULL).
        $pdo->prepare('DELETE FROM attendance_schedules WHERE id = ?')->execute([$id]);
        logAuditAction('attendance_schedule_delete', 'attendance_schedule', $id, ['name' => $schedule['name']]);
        $flash('success', 'Horário eliminado. Os colaboradores que o usavam passam para o horário por omissão.');
    }

    if ($action === 'users_save') {
        $carryYear = (int) date('Y');
        $schedules = attendanceSchedules();
        $upsert = $pdo->prepare('INSERT INTO attendance_user_settings (user_id, schedule_id, tracked, vacation_days) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE schedule_id = VALUES(schedule_id), tracked = VALUES(tracked), vacation_days = VALUES(vacation_days)');
        $carry = $pdo->prepare('INSERT INTO attendance_vacation_carryover (user_id, year, days) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE days = VALUES(days)');
        $carryDel = $pdo->prepare('DELETE FROM attendance_vacation_carryover WHERE user_id = ? AND year = ?');
        $validUsers = array_map('intval', $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN));
        foreach ((array) ($_POST['users'] ?? []) as $uid => $row) {
            $uid = (int) $uid;
            if (!in_array($uid, $validUsers, true) || !is_array($row)) {
                continue;
            }
            $sid = (int) ($row['schedule_id'] ?? 0);
            $sid = isset($schedules[$sid]) ? $sid : null;
            $vacation = max(0, min(99, (float) str_replace(',', '.', (string) ($row['vacation_days'] ?? '22'))));
            $upsert->execute([$uid, $sid, !empty($row['tracked']) ? 1 : 0, round($vacation * 2) / 2]);
            $carryDays = round(max(0, min(99, (float) str_replace(',', '.', (string) ($row['carryover'] ?? '0')))) * 2) / 2;
            if ($carryDays > 0) {
                $carry->execute([$uid, $carryYear, $carryDays]);
            } else {
                $carryDel->execute([$uid, $carryYear]);
            }
        }
        logAuditAction('attendance_users_save', 'attendance', null);
        $flash('success', 'Configuração dos colaboradores guardada.');
    }

    if ($action === 'types_save') {
        $rows = (array) ($_POST['types'] ?? []);
        $upd = $pdo->prepare('UPDATE attendance_absence_types SET code = ?, name = ?, color = ?, is_vacation = ?, is_paid = ?, requires_approval = ?, is_active = ?, sort_order = ? WHERE id = ?');
        $ins = $pdo->prepare('INSERT INTO attendance_absence_types (code, name, color, is_vacation, is_paid, requires_approval, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        try {
            foreach ($rows as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = strtoupper(mb_substr(trim((string) ($row['code'] ?? '')), 0, 10));
                $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 120);
                if ($key === 'new' && $code === '' && $name === '') {
                    continue;
                }
                if ($code === '' || $name === '') {
                    $flash('danger', 'Cada tipo de ausência precisa de código e nome.');
                }
                $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($row['color'] ?? '')) ? (string) $row['color'] : '#26B99A';
                $vals = [$code, $name, $color, !empty($row['is_vacation']) ? 1 : 0, !empty($row['is_paid']) ? 1 : 0,
                    !empty($row['requires_approval']) ? 1 : 0, ($key === 'new' || !empty($row['is_active'])) ? 1 : 0, (int) ($row['sort_order'] ?? 0)];
                if ($key === 'new') {
                    $ins->execute($vals);
                } else {
                    $vals[] = (int) $key;
                    $upd->execute($vals);
                }
            }
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $flash('danger', 'Já existe um tipo de ausência com esse código.');
            }
            throw $e;
        }
        logAuditAction('attendance_types_save', 'attendance', null);
        $flash('success', 'Tipos de ausência guardados.');
    }

    if ($action === 'type_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $used = $pdo->prepare('SELECT COUNT(*) FROM attendance_absences WHERE type_id = ?');
        $used->execute([$id]);
        if ((int) $used->fetchColumn() > 0) {
            $pdo->prepare('UPDATE attendance_absence_types SET is_active = 0 WHERE id = ?')->execute([$id]);
            $flash('warning', 'O tipo já foi usado em ausências: foi desativado em vez de eliminado.');
        }
        $pdo->prepare('DELETE FROM attendance_absence_types WHERE id = ?')->execute([$id]);
        logAuditAction('attendance_type_delete', 'attendance', $id);
        $flash('success', 'Tipo de ausência eliminado.');
    }

    if ($action === 'holiday_add') {
        $date = attendanceValidDate($_POST['holiday_date'] ?? '');
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120);
        $part = (string) ($_POST['day_part'] ?? 'full');
        if (!$date || $name === '') {
            $flash('danger', 'Indique a data e o nome do feriado.', '&ano=' . (int) ($_POST['year'] ?? date('Y')));
        }
        if (!isset(ATTENDANCE_DAY_PARTS[$part])) {
            $part = 'full';
        }
        $pdo->prepare('INSERT INTO attendance_holidays (holiday_date, name, day_part, recurring) VALUES (?, ?, ?, ?)')
            ->execute([$date, $name, $part, !empty($_POST['recurring']) ? 1 : 0]);
        logAuditAction('attendance_holiday_add', 'attendance', (int) $pdo->lastInsertId(), ['date' => $date, 'name' => $name]);
        $flash('success', 'Feriado "' . $name . '" adicionado.', '&ano=' . substr($date, 0, 4));
    }

    if ($action === 'holiday_delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM attendance_holidays WHERE id = ?')->execute([$id]);
        logAuditAction('attendance_holiday_delete', 'attendance', $id);
        $flash('success', 'Feriado removido.', '&ano=' . (int) ($_POST['year'] ?? date('Y')));
    }

    if ($action === 'general_save') {
        $vacation = max(0, min(99, (float) str_replace(',', '.', (string) ($_POST['vacation_days_default'] ?? '22'))));
        setSetting('attendance_vacation_days_default', (string) (round($vacation * 2) / 2));
        setSetting('attendance_holiday_carnaval', !empty($_POST['holiday_carnaval']) ? '1' : '0');
        setSetting('attendance_require_justification', !empty($_POST['require_justification']) ? '1' : '0');
        setSetting('attendance_map_public', !empty($_POST['map_public']) ? '1' : '0');
        setSetting('attendance_topbar_button', !empty($_POST['topbar_button']) ? '1' : '0');
        logAuditAction('attendance_general_save', 'attendance', null);
        $flash('success', 'Definições gerais guardadas.');
    }

    $flash('danger', 'Ação desconhecida.');
}

// ---------------------------------------------------------------- GET
$flashMsg = pullSessionFlash('attendance_config');
$csrfToken = generateCsrfToken();
$schedules = attendanceSchedules();
$scheduleParam = (string) ($_GET['horario'] ?? '');
$editSchedule = null;
if ($tab === 'horarios' && $scheduleParam !== '') {
    if ($scheduleParam === 'novo') {
        $editSchedule = ['id' => 0, 'name' => '', 'description' => '', 'tolerance_minutes' => 15, 'color' => '#3498DB', 'is_default' => 0, 'is_active' => 1, 'days' => []];
        // Pre-preencher com o horario por omissao.
        $default = $schedules[attendanceDefaultScheduleId()] ?? null;
        if ($default) {
            $editSchedule['days'] = $default['days'];
        }
    } elseif (isset($schedules[(int) $scheduleParam])) {
        $editSchedule = $schedules[(int) $scheduleParam];
    }
}
$scheduleUsage = [];
foreach ($pdo->query('SELECT schedule_id, COUNT(*) AS n FROM attendance_user_settings WHERE schedule_id IS NOT NULL GROUP BY schedule_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $scheduleUsage[(int) $row['schedule_id']] = (int) $row['n'];
}
$defaultId = attendanceDefaultScheduleId();
$explicitUsers = 0;
foreach ($scheduleUsage as $n) {
    $explicitUsers += $n;
}

$useAttendance = true;
require_once dirname(__DIR__) . '/header.php';
?>
<div class="container-fluid attendance-page">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h2 class="mb-1"><i class="fa fa-cogs"></i> Configuração da Assiduidade</h2>
            <p class="mb-0 text-muted">Horários do escritório, colaboradores, tipos de ausência e feriados.</p>
        </div>
    </div>

    <?php if ($flashMsg): ?>
        <div class="alert alert-<?= attendanceEsc($flashMsg['type']); ?> alert-dismissible fade show" role="alert">
            <?= attendanceEsc($flashMsg['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs bar_tabs" role="tablist">
        <?php foreach ($tabs as $key => [$label, $icon]): ?>
            <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : ''; ?>" href="<?= $selfUrl; ?>?tab=<?= $key; ?>"><i class="fa <?= $icon; ?>"></i> <?= attendanceEsc($label); ?></a></li>
        <?php endforeach; ?>
    </ul>

<?php if ($tab === 'horarios' && $editSchedule !== null): ?>
    <form method="post" action="<?= $selfUrl; ?>">
        <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
        <input type="hidden" name="action" value="schedule_save">
        <input type="hidden" name="tab" value="horarios">
        <input type="hidden" name="id" value="<?= (int) $editSchedule['id']; ?>">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-clock-o"></i> <?= $editSchedule['id'] ? 'Editar horário' : 'Novo horário'; ?></h2>
                <div class="pull-right"><a href="<?= $selfUrl; ?>?tab=horarios" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i> Voltar</a></div>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="scheduleName">Nome</label>
                        <input type="text" class="form-control" id="scheduleName" name="name" maxlength="120" required value="<?= attendanceEsc($editSchedule['name']); ?>" placeholder="Ex.: Horário de verão">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="scheduleDesc">Descrição</label>
                        <input type="text" class="form-control" id="scheduleDesc" name="description" maxlength="255" value="<?= attendanceEsc($editSchedule['description']); ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="scheduleTolerance">Tolerância (min)</label>
                        <input type="number" min="0" max="240" class="form-control" id="scheduleTolerance" name="tolerance_minutes" value="<?= (int) $editSchedule['tolerance_minutes']; ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="scheduleColor">Cor</label>
                        <input type="color" class="form-control form-control-color" id="scheduleColor" name="color" value="<?= attendanceEsc($editSchedule['color']); ?>">
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered attendance-schedule-form">
                        <thead>
                            <tr>
                                <th rowspan="2" class="align-middle">Dia</th>
                                <th colspan="2" class="text-center">Manhã</th>
                                <th colspan="2" class="text-center">Tarde</th>
                                <th rowspan="2" class="text-center align-middle">Horas</th>
                                <th rowspan="2" class="no-print"></th>
                            </tr>
                            <tr>
                                <th class="text-center">Entrada</th><th class="text-center">Saída</th>
                                <th class="text-center">Entrada</th><th class="text-center">Saída</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (ATTENDANCE_WEEKDAYS as $wd => $label): $day = $editSchedule['days'][$wd] ?? []; ?>
                            <tr data-weekday="<?= $wd; ?>">
                                <td class="align-middle fw-bold"><?= attendanceEsc($label); ?></td>
                                <?php foreach (['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as $field): ?>
                                    <td><input type="time" class="form-control form-control-sm js-schedule-time" name="days[<?= $wd; ?>][<?= $field; ?>]" value="<?= attendanceEsc(substr((string) ($day[$field] ?? ''), 0, 5)); ?>"></td>
                                <?php endforeach; ?>
                                <td class="text-center align-middle js-schedule-hours"></td>
                                <td class="text-nowrap align-middle">
                                    <button type="button" class="btn btn-xs btn-link js-schedule-copy" title="Copiar para os dias seguintes"><i class="fa fa-level-down"></i></button>
                                    <button type="button" class="btn btn-xs btn-link text-danger js-schedule-clear" title="Dia de folga"><i class="fa fa-eraser"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><th colspan="5" class="text-end">Total semanal</th><th class="text-center" id="scheduleWeekTotal"></th><th></th></tr></tfoot>
                    </table>
                </div>
                <p class="text-muted small">Deixe a tarde vazia num horário contínuo ou de meio dia. Um dia sem horas é folga. A tolerância aplica-se às entradas da manhã e da tarde.</p>
                <div class="d-flex flex-wrap gap-4 mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="scheduleDefault" name="is_default" value="1" <?= (int) $editSchedule['is_default'] === 1 ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="scheduleDefault">Horário por omissão</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="scheduleActive" name="is_active" value="1" <?= (int) $editSchedule['is_active'] === 1 ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="scheduleActive">Ativo</label>
                    </div>
                </div>
                <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar horário</button>
            </div>
        </div>
    </form>
<?php elseif ($tab === 'horarios'): ?>
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-clock-o"></i> Horários do escritório</h2>
            <div class="pull-right"><a href="<?= $selfUrl; ?>?tab=horarios&horario=novo" class="btn btn-sm btn-primary"><i class="fa fa-plus"></i> Novo horário</a></div>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div class="table-responsive">
                <table class="table table-striped jambo_table">
                    <thead>
                        <tr><th>Horário</th><th>Dias e horas</th><th class="text-center">Semana</th><th class="text-center">Tolerância</th><th class="text-center">Colaboradores</th><th class="text-end">Ações</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$schedules): ?>
                        <tr><td colspan="6" class="text-center text-muted">Sem horários. <a href="<?= $selfUrl; ?>?tab=horarios&horario=novo">Criar o primeiro</a>.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($schedules as $sid => $schedule):
                        $week = 0;
                        foreach (ATTENDANCE_WEEKDAYS as $wd => $_) { $week += attendanceScheduleDayMinutes($schedule, $wd); }
                        $count = ($scheduleUsage[$sid] ?? 0);
                    ?>
                        <tr>
                            <td>
                                <span class="attendance-color-dot" style="background: <?= attendanceEsc($schedule['color']); ?>"></span>
                                <strong><?= attendanceEsc($schedule['name']); ?></strong>
                                <?php if ((int) $schedule['is_default'] === 1): ?><span class="badge bg-primary">Omissão</span><?php endif; ?>
                                <?php if ((int) $schedule['is_active'] !== 1): ?><span class="badge bg-secondary">Inativo</span><?php endif; ?>
                                <?php if ($schedule['description'] !== ''): ?><div class="small text-muted"><?= attendanceEsc($schedule['description']); ?></div><?php endif; ?>
                            </td>
                            <td class="small"><?= attendanceEsc(attendanceScheduleSummary($schedule)); ?></td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDuration($week)); ?></td>
                            <td class="text-center"><?= (int) $schedule['tolerance_minutes']; ?> min</td>
                            <td class="text-center"><?= $count; ?><?= (int) $schedule['is_default'] === 1 ? ' <span class="small text-muted">+ sem horário atribuído</span>' : ''; ?></td>
                            <td class="text-end text-nowrap">
                                <a href="<?= $selfUrl; ?>?tab=horarios&horario=<?= (int) $sid; ?>" class="btn btn-sm btn-secondary"><i class="fa fa-pencil"></i> Editar</a>
                                <?php if ((int) $schedule['is_default'] !== 1): ?>
                                <form method="post" action="<?= $selfUrl; ?>" class="d-inline" onsubmit="return confirm('Eliminar este horário? Os colaboradores que o usam passam para o horário por omissão.');">
                                    <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
                                    <input type="hidden" name="action" value="schedule_delete">
                                    <input type="hidden" name="tab" value="horarios">
                                    <input type="hidden" name="id" value="<?= (int) $sid; ?>">
                                    <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mb-0">Atribua os horários a cada colaborador no separador <a href="<?= $selfUrl; ?>?tab=colaboradores">Colaboradores</a>. Quem não tiver horário atribuído usa o horário por omissão.</p>
        </div>
    </div>

<?php elseif ($tab === 'colaboradores'):
    $allUsers = attendanceUsers(false);
    $carryYear = (int) date('Y');
    $carryRows = [];
    $stmt = $pdo->prepare('SELECT user_id, days FROM attendance_vacation_carryover WHERE year = ?');
    $stmt->execute([$carryYear]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $carryRows[(int) $row['user_id']] = (float) $row['days'];
    }
?>
    <form method="post" action="<?= $selfUrl; ?>">
        <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
        <input type="hidden" name="action" value="users_save">
        <input type="hidden" name="tab" value="colaboradores">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-users"></i> Colaboradores</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr><th>Colaborador</th><th class="text-center">Regista ponto</th><th>Horário</th><th class="text-center">Férias / ano</th><th class="text-center">Transitados <?= $carryYear; ?></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($allUsers as $uid => $member): ?>
                            <tr>
                                <td><?= attendanceEsc($member['label']); ?> <span class="text-muted small">(<?= attendanceEsc($member['username']); ?>)</span></td>
                                <td class="text-center">
                                    <input type="hidden" name="users[<?= (int) $uid; ?>][tracked]" value="0">
                                    <div class="form-check form-switch d-inline-block">
                                        <input class="form-check-input" type="checkbox" name="users[<?= (int) $uid; ?>][tracked]" value="1" <?= $member['tracked'] === 1 ? 'checked' : ''; ?> aria-label="Regista ponto">
                                    </div>
                                </td>
                                <td>
                                    <select name="users[<?= (int) $uid; ?>][schedule_id]" class="form-select form-select-sm">
                                        <option value="0">Por omissão (<?= attendanceEsc($schedules[$defaultId]['name'] ?? '—'); ?>)</option>
                                        <?php foreach ($schedules as $sid => $schedule): ?>
                                            <?php if ((int) $schedule['is_active'] !== 1 && $member['schedule_id'] !== $sid) { continue; } ?>
                                            <option value="<?= (int) $sid; ?>" <?= $member['schedule_id'] === $sid ? 'selected' : ''; ?>><?= attendanceEsc($schedule['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td class="text-center"><input type="number" step="0.5" min="0" max="99" class="form-control form-control-sm attendance-input-days" name="users[<?= (int) $uid; ?>][vacation_days]" value="<?= attendanceEsc(rtrim(rtrim((string) $member['vacation_days'], '0'), '.')); ?>"></td>
                                <td class="text-center"><input type="number" step="0.5" min="0" max="99" class="form-control form-control-sm attendance-input-days" name="users[<?= (int) $uid; ?>][carryover]" value="<?= attendanceEsc(isset($carryRows[$uid]) ? rtrim(rtrim((string) $carryRows[$uid], '0'), '.') : '0'); ?>"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small">Quem não regista ponto não vê o botão de registo nem aparece nos mapas. Os dias de férias contam-se em dias úteis do horário do colaborador (sem feriados).</p>
                <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar</button>
            </div>
        </div>
    </form>

<?php elseif ($tab === 'tipos'):
    $types = attendanceAbsenceTypes();
    $usage = [];
    foreach ($pdo->query('SELECT type_id, COUNT(*) AS n FROM attendance_absences GROUP BY type_id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $usage[(int) $row['type_id']] = (int) $row['n'];
    }
    $typeRows = $types + ['new' => ['id' => 'new', 'code' => '', 'name' => '', 'color' => '#26B99A', 'is_vacation' => 0, 'is_paid' => 1, 'requires_approval' => 1, 'is_active' => 1, 'sort_order' => (count($types) + 1) * 10]];
?>
    <form method="post" action="<?= $selfUrl; ?>" id="typesForm">
        <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
        <input type="hidden" name="action" value="types_save">
        <input type="hidden" name="tab" value="tipos">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-tags"></i> Tipos de ausência</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Código</th><th>Nome</th><th>Cor</th>
                                <th class="text-center" title="Desconta nos dias de férias">Férias</th>
                                <th class="text-center">Remunerada</th>
                                <th class="text-center" title="O pedido do colaborador fica pendente até ser aprovado">Requer aprovação</th>
                                <th class="text-center">Ativo</th>
                                <th>Ordem</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($typeRows as $key => $type): $isNew = $key === 'new'; ?>
                            <tr class="<?= $isNew ? 'table-info' : ''; ?>">
                                <td><input type="text" class="form-control form-control-sm attendance-input-code" name="types[<?= $key; ?>][code]" maxlength="10" value="<?= attendanceEsc($type['code']); ?>" <?= $isNew ? 'placeholder="Novo"' : 'required'; ?>></td>
                                <td><input type="text" class="form-control form-control-sm" name="types[<?= $key; ?>][name]" maxlength="120" value="<?= attendanceEsc($type['name']); ?>" <?= $isNew ? 'placeholder="Nome do novo tipo"' : 'required'; ?>></td>
                                <td><input type="color" class="form-control form-control-sm form-control-color" name="types[<?= $key; ?>][color]" value="<?= attendanceEsc($type['color']); ?>"></td>
                                <?php foreach (['is_vacation', 'is_paid', 'requires_approval', 'is_active'] as $flag): ?>
                                    <td class="text-center">
                                        <?php if ($isNew && $flag === 'is_active'): ?>
                                            <span class="text-muted small">sim</span>
                                        <?php else: ?>
                                            <div class="form-check form-switch d-inline-block"><input class="form-check-input" type="checkbox" name="types[<?= $key; ?>][<?= $flag; ?>]" value="1" <?= (int) $type[$flag] === 1 ? 'checked' : ''; ?>></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                                <td><input type="number" class="form-control form-control-sm attendance-input-days" name="types[<?= $key; ?>][sort_order]" value="<?= (int) $type['sort_order']; ?>"></td>
                                <td class="text-end">
                                    <?php if (!$isNew): ?>
                                        <button type="submit" form="typeDelete<?= (int) $type['id']; ?>" class="btn btn-xs btn-outline-danger" title="<?= !empty($usage[(int) $type['id']]) ? 'Já usado: será desativado' : 'Eliminar'; ?>" onclick="return confirm('<?= !empty($usage[(int) $type['id']]) ? 'Este tipo já foi usado e será apenas desativado. Continuar?' : 'Eliminar este tipo de ausência?'; ?>');"><i class="fa fa-trash"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted small">Preencha a última linha para criar um novo tipo. O código aparece nas células do mapa de ausências.</p>
                <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar tipos</button>
            </div>
        </div>
    </form>
    <?php foreach ($types as $type): ?>
        <form method="post" action="<?= $selfUrl; ?>" id="typeDelete<?= (int) $type['id']; ?>" class="d-none">
            <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
            <input type="hidden" name="action" value="type_delete">
            <input type="hidden" name="tab" value="tipos">
            <input type="hidden" name="id" value="<?= (int) $type['id']; ?>">
        </form>
    <?php endforeach; ?>

<?php elseif ($tab === 'feriados'):
    $hYear = (int) ($_GET['ano'] ?? date('Y'));
    if ($hYear < 2000 || $hYear > 2100) {
        $hYear = (int) date('Y');
    }
    $national = attendanceNationalHolidays($hYear);
    $local = $pdo->query('SELECT * FROM attendance_holidays ORDER BY recurring DESC, holiday_date ASC')->fetchAll(PDO::FETCH_ASSOC);
?>
    <div class="row">
        <div class="col-12 col-lg-5">
            <div class="x_panel">
                <div class="x_title">
                    <h2><i class="fa fa-flag"></i> Feriados nacionais</h2>
                    <div class="pull-right btn-group">
                        <a class="btn btn-sm btn-outline-secondary" href="<?= $selfUrl; ?>?tab=feriados&ano=<?= $hYear - 1; ?>"><i class="fa fa-chevron-left"></i></a>
                        <span class="btn btn-sm btn-outline-secondary disabled"><?= $hYear; ?></span>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= $selfUrl; ?>?tab=feriados&ano=<?= $hYear + 1; ?>"><i class="fa fa-chevron-right"></i></a>
                    </div>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <table class="table table-sm table-striped mb-2">
                        <tbody>
                        <?php foreach ($national as $date => $name): ?>
                            <tr>
                                <td class="text-nowrap"><?= date('d/m', strtotime($date)); ?></td>
                                <td class="text-muted small"><?= attendanceEsc(ATTENDANCE_WEEKDAYS[(int) date('N', strtotime($date))]); ?></td>
                                <td><?= attendanceEsc($name); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="text-muted small mb-0">Calculados automaticamente (incluindo Páscoa e Corpo de Deus). O Carnaval controla-se no separador Geral.</p>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-7">
            <div class="x_panel">
                <div class="x_title">
                    <h2><i class="fa fa-map-marker"></i> Feriado municipal, tolerâncias e encerramentos</h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <form method="post" action="<?= $selfUrl; ?>" class="row g-2 align-items-end mb-3">
                        <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
                        <input type="hidden" name="action" value="holiday_add">
                        <input type="hidden" name="tab" value="feriados">
                        <input type="hidden" name="year" value="<?= $hYear; ?>">
                        <div class="col-6 col-md-3">
                            <label class="form-label small mb-1" for="holidayDate">Data</label>
                            <input type="date" class="form-control form-control-sm" id="holidayDate" name="holiday_date" required>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label small mb-1" for="holidayName">Nome</label>
                            <input type="text" class="form-control form-control-sm" id="holidayName" name="name" maxlength="120" required placeholder="Ex.: Feriado municipal">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small mb-1" for="holidayPart">Parte</label>
                            <select class="form-select form-select-sm" id="holidayPart" name="day_part">
                                <?php foreach (ATTENDANCE_DAY_PARTS as $key => $label): ?><option value="<?= $key; ?>"><?= attendanceEsc($label); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="form-check form-switch mb-1">
                                <input class="form-check-input" type="checkbox" id="holidayRecurring" name="recurring" value="1">
                                <label class="form-check-label small" for="holidayRecurring">Todos os anos</label>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fa fa-plus"></i> Adicionar</button>
                        </div>
                    </form>
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Data</th><th>Nome</th><th>Parte</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$local): ?><tr><td colspan="4" class="text-muted text-center">Sem feriados adicionais.</td></tr><?php endif; ?>
                        <?php foreach ($local as $row): ?>
                            <tr>
                                <td class="text-nowrap"><?= (int) $row['recurring'] === 1 ? date('d/m', strtotime($row['holiday_date'])) . ' <span class="badge bg-info">anual</span>' : date('d/m/Y', strtotime($row['holiday_date'])); ?></td>
                                <td><?= attendanceEsc($row['name']); ?></td>
                                <td class="small"><?= attendanceEsc(ATTENDANCE_DAY_PARTS[$row['day_part']] ?? $row['day_part']); ?></td>
                                <td class="text-end">
                                    <form method="post" action="<?= $selfUrl; ?>" class="d-inline" onsubmit="return confirm('Remover este feriado?');">
                                        <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
                                        <input type="hidden" name="action" value="holiday_delete">
                                        <input type="hidden" name="tab" value="feriados">
                                        <input type="hidden" name="year" value="<?= $hYear; ?>">
                                        <input type="hidden" name="id" value="<?= (int) $row['id']; ?>">
                                        <button type="submit" class="btn btn-xs btn-outline-danger"><i class="fa fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <form method="post" action="<?= $selfUrl; ?>">
        <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
        <input type="hidden" name="action" value="general_save">
        <input type="hidden" name="tab" value="geral">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-cog"></i> Definições gerais</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="row g-3 mb-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="vacationDefault">Dias de férias por omissão</label>
                        <input type="number" step="0.5" min="0" max="99" class="form-control" id="vacationDefault" name="vacation_days_default" value="<?= attendanceEsc(attendanceSetting('attendance_vacation_days_default', '22')); ?>">
                        <div class="form-text">Para colaboradores sem valor próprio (Código do Trabalho: 22 dias úteis).</div>
                    </div>
                </div>
                <?php
                $switches = [
                    ['topbar_button', 'attendance_topbar_button', '1', 'Botão "Registar" na barra de topo', 'Mostra em todas as páginas o botão com o próximo registo (entrada/saída), como na intranet legacy.'],
                    ['require_justification', 'attendance_require_justification', '1', 'Pedir justificação de atrasos', 'Ao registar uma entrada depois da tolerância, o colaborador tem de indicar o motivo.'],
                    ['holiday_carnaval', 'attendance_holiday_carnaval', '1', 'Considerar o Carnaval como feriado', 'Terça-feira de Carnaval (tolerância de ponto habitual).'],
                    ['map_public', 'attendance_map_public', '0', 'Mapa de ausências visível para todos', 'Todos os colaboradores veem o mapa de ausências da equipa (sem o resumo de horas).'],
                ];
                foreach ($switches as [$field, $setting, $default, $label, $help]): ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="general_<?= $field; ?>" name="<?= $field; ?>" value="1" <?= attendanceSetting($setting, $default) === '1' ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="general_<?= $field; ?>"><?= attendanceEsc($label); ?></label>
                        <div class="form-text"><?= attendanceEsc($help); ?></div>
                    </div>
                <?php endforeach; ?>
                <p class="text-muted small">Permissões por departamento (Definições &gt; Permissões): <em>Assiduidade - Ver mapas da equipa</em> e <em>Assiduidade - Corrigir picagens e aprovar ausências</em>. Os administradores têm sempre acesso total.</p>
                <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar</button>
            </div>
        </div>
    </form>
<?php endif; ?>
</div>
<?php
if ($tab === 'horarios' && $editSchedule !== null) {
    $pageScripts = <<<'JS'
(function () {
    function toMin(v) { if (!v) { return null; } var p = v.split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1], 10); }
    function fmt(m) { return Math.floor(m / 60) + 'h' + ('0' + (m % 60)).slice(-2); }
    var rows = Array.prototype.slice.call(document.querySelectorAll('.attendance-schedule-form tbody tr'));
    function recalc() {
        var week = 0;
        rows.forEach(function (row) {
            var t = row.querySelectorAll('.js-schedule-time');
            var total = 0;
            [[0, 1], [2, 3]].forEach(function (pair) {
                var s = toMin(t[pair[0]].value), e = toMin(t[pair[1]].value);
                if (s !== null && e !== null && e > s) { total += e - s; }
            });
            week += total;
            row.querySelector('.js-schedule-hours').textContent = total ? fmt(total) : 'Folga';
            row.classList.toggle('is-rest', !total);
        });
        document.getElementById('scheduleWeekTotal').textContent = fmt(week);
    }
    rows.forEach(function (row, idx) {
        row.querySelectorAll('.js-schedule-time').forEach(function (input) { input.addEventListener('change', recalc); });
        row.querySelector('.js-schedule-clear').addEventListener('click', function () {
            row.querySelectorAll('.js-schedule-time').forEach(function (input) { input.value = ''; });
            recalc();
        });
        row.querySelector('.js-schedule-copy').addEventListener('click', function () {
            var src = row.querySelectorAll('.js-schedule-time');
            // Copia para os dias seguintes ate sexta (ou ate domingo se for fim de semana).
            var last = idx < 5 ? 4 : 6;
            for (var i = idx + 1; i <= last; i++) {
                var dst = rows[i].querySelectorAll('.js-schedule-time');
                for (var k = 0; k < 4; k++) { dst[k].value = src[k].value; }
            }
            recalc();
        });
    });
    recalc();
})();
JS;
}
require_once dirname(__DIR__) . '/footer.php';
