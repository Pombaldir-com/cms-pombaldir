<?php
// Registo de Ponto: mapa mensal de assiduidade de um colaborador (porta de
// intranet/assiduidade.php). Rota "assiduidade/registo-ponto".
//   ?mes=YYYY-MM          mes a mostrar (por omissao o atual)
//   ?colaborador=ID       outro colaborador (quem tem attendanceCanViewTeam)
// Correcao de picagens por dia (modal) para attendanceCanManage().
// Ver docs/assiduidade.md.

require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
attendanceRequirePage();

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$canViewTeam = attendanceCanViewTeam();
$canManage = attendanceCanManage();

[$year, $month] = attendanceParseMonth($_GET['mes'] ?? '');
$monthKey = sprintf('%04d-%02d', $year, $month);

$team = $canViewTeam ? attendanceUsers(true) : [];
$targetId = $userId;
if ($canViewTeam && isset($_GET['colaborador'])) {
    $requested = (int) $_GET['colaborador'];
    if ($requested > 0 && (isset($team[$requested]) || $requested === $userId)) {
        $targetId = $requested;
    }
}
$targetUser = $targetId === $userId ? $user : (getPDO()->query('SELECT id, username, name FROM users WHERE id = ' . (int) $targetId)->fetch(PDO::FETCH_ASSOC) ?: $user);
$targetLabel = attendanceUserLabel($targetUser);
$isOwn = $targetId === $userId;
$isTracked = attendanceUserIsTracked($targetId);

$map = attendanceBuildMonth($targetId, $year, $month);
$totals = $map['totals'];
$vacation = attendanceVacationSummary($targetId, $year);
$schedule = $map['schedule'];

$prev = (new DateTime($monthKey . '-01'))->modify('-1 month')->format('Y-m');
$next = (new DateTime($monthKey . '-01'))->modify('+1 month')->format('Y-m');
$baseQuery = $targetId !== $userId ? '&colaborador=' . $targetId : '';

$csrfToken = generateCsrfToken();
$useAttendance = true;
require_once dirname(__DIR__) . '/header.php';
?>
<div class="container-fluid attendance-page">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3 no-print">
        <div>
            <h2 class="mb-1"><i class="fa fa-clock-o"></i> Registo de Ponto</h2>
            <p class="mb-0 text-muted">Entradas e saídas por período, horas trabalhadas e ausências do mês.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($canViewTeam && $team): ?>
            <form method="get" action="<?= BASE_URL ?>assiduidade/registo-ponto" class="d-flex align-items-center gap-2">
                <input type="hidden" name="mes" value="<?= attendanceEsc($monthKey); ?>">
                <select name="colaborador" class="form-select form-select-sm attendance-user-select" onchange="this.form.submit()" aria-label="Colaborador">
                    <?php if (!isset($team[$userId])): ?>
                        <option value="<?= $userId; ?>" <?= $isOwn ? 'selected' : ''; ?>><?= attendanceEsc(attendanceUserLabel($user)); ?> (eu)</option>
                    <?php endif; ?>
                    <?php foreach ($team as $member): ?>
                        <option value="<?= (int) $member['id']; ?>" <?= (int) $member['id'] === $targetId ? 'selected' : ''; ?>><?= attendanceEsc($member['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php endif; ?>
            <div class="btn-group" role="group" aria-label="Mês">
                <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>assiduidade/registo-ponto?mes=<?= $prev . $baseQuery; ?>" title="Mês anterior"><i class="fa fa-chevron-left"></i></a>
                <form method="get" action="<?= BASE_URL ?>assiduidade/registo-ponto" class="d-inline">
                    <?php if ($targetId !== $userId): ?><input type="hidden" name="colaborador" value="<?= $targetId; ?>"><?php endif; ?>
                    <input type="month" name="mes" value="<?= attendanceEsc($monthKey); ?>" class="form-control form-control-sm attendance-month-input" onchange="this.form.submit()" aria-label="Mês">
                </form>
                <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>assiduidade/registo-ponto?mes=<?= $next . $baseQuery; ?>" title="Mês seguinte"><i class="fa fa-chevron-right"></i></a>
            </div>
            <button type="button" class="btn btn-sm btn-secondary" onclick="window.print()"><i class="fa fa-print"></i> Imprimir</button>
        </div>
    </div>

    <div class="print-only mb-2">
        <h3 class="mb-0">Mapa de assiduidade — <?= attendanceEsc($targetLabel); ?></h3>
        <div><?= attendanceEsc(attendanceMonthLabel($year, $month)); ?> · Horário: <?= attendanceEsc($schedule['name'] ?? '—'); ?></div>
    </div>

    <?php if (!$isTracked): ?>
        <div class="alert alert-info no-print">Este colaborador está configurado como <strong>não regista ponto</strong>. O mapa mostra apenas o que existir registado.</div>
    <?php endif; ?>

    <?php if ($isOwn && $isTracked && $monthKey === date('Y-m')): ?>
    <div class="x_panel attendance-today no-print" id="attendanceTodayPanel">
        <div class="x_content">
            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="attendance-clock" id="attendanceClock"><?= date('H:i'); ?></div>
                    <div>
                        <div class="fw-bold">Hoje, <?= attendanceEsc(ATTENDANCE_WEEKDAYS[(int) date('N')] . ', ' . date('j') . ' de ' . mb_strtolower(ATTENDANCE_MONTHS[(int) date('n')])); ?></div>
                        <div class="text-muted small">Horário: <?= attendanceEsc($schedule['name'] ?? '—'); ?></div>
                        <div class="attendance-today-punches small mt-1" id="attendanceTodayPunches"></div>
                    </div>
                </div>
                <button type="button" class="btn btn-lg attendance-punch-btn js-attendance-punch" id="attendanceTodayBtn" style="display:none;">
                    <i class="fa fa-clock-o"></i> <span class="js-attendance-label">Registar</span>
                </button>
                <span class="badge bg-success attendance-done" id="attendanceTodayDone" style="display:none;"><i class="fa fa-check"></i> Registos de hoje completos</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row tile_count attendance-tiles">
        <div class="col-6 col-md-4 col-xl-2 tile_stats_count">
            <span class="count_top"><i class="fa fa-clock-o"></i> Horas trabalhadas</span>
            <div class="count"><?= attendanceEsc(attendanceFormatDuration($totals['worked'])); ?></div>
            <span class="count_bottom">previstas até hoje <?= attendanceEsc(attendanceFormatDuration($totals['expected'])); ?></span>
        </div>
        <div class="col-6 col-md-4 col-xl-2 tile_stats_count">
            <span class="count_top"><i class="fa fa-balance-scale"></i> Saldo</span>
            <div class="count <?= $totals['balance'] < 0 ? 'red' : 'green'; ?>"><?= attendanceEsc(attendanceFormatDuration($totals['balance'], true)); ?></div>
            <span class="count_bottom">trabalhadas − previstas</span>
        </div>
        <div class="col-6 col-md-4 col-xl-2 tile_stats_count">
            <span class="count_top"><i class="fa fa-hourglass-half"></i> Atrasos</span>
            <div class="count <?= $totals['late_count'] > 0 ? 'red' : ''; ?>"><?= (int) $totals['late_count']; ?></div>
            <span class="count_bottom"><?= attendanceEsc(attendanceFormatDuration($totals['late_minutes'])); ?> no total</span>
        </div>
        <div class="col-6 col-md-4 col-xl-2 tile_stats_count">
            <span class="count_top"><i class="fa fa-sign-out"></i> Saídas antecipadas</span>
            <div class="count"><?= attendanceEsc(attendanceFormatDuration($totals['early_minutes'])); ?></div>
            <span class="count_bottom">antes do fim do período</span>
        </div>
        <div class="col-6 col-md-4 col-xl-2 tile_stats_count">
            <span class="count_top"><i class="fa fa-exclamation-triangle"></i> Dias sem registo</span>
            <div class="count <?= $totals['missing_days'] > 0 ? 'red' : ''; ?>"><?= (int) $totals['missing_days']; ?></div>
            <span class="count_bottom"><?= (int) $totals['incomplete_days']; ?> dia(s) incompleto(s)</span>
        </div>
        <div class="col-6 col-md-4 col-xl-2 tile_stats_count">
            <span class="count_top"><i class="fa fa-plane"></i> Férias <?= $year; ?></span>
            <div class="count"><?= attendanceEsc(attendanceFormatDays($vacation['remaining'])); ?></div>
            <span class="count_bottom">por gozar de <?= attendanceEsc(attendanceFormatDays($vacation['total'])); ?> (<?= attendanceEsc(attendanceFormatDays($vacation['used'])); ?> gozados)</span>
        </div>
    </div>

    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-calendar"></i> <?= attendanceEsc(attendanceMonthLabel($year, $month)); ?> <small><?= attendanceEsc($targetLabel); ?> · <?= attendanceEsc($schedule['name'] ?? 'Sem horário'); ?></small></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div class="table-responsive">
                <table class="table table-sm table-bordered attendance-month-table mb-2">
                    <thead>
                        <tr>
                            <th rowspan="2" class="text-center align-middle">Dia</th>
                            <th colspan="2" class="text-center">Manhã</th>
                            <th colspan="2" class="text-center">Tarde</th>
                            <th rowspan="2" class="text-center align-middle">Trabalhado</th>
                            <th rowspan="2" class="text-center align-middle">Previsto</th>
                            <th rowspan="2" class="text-center align-middle">Saldo</th>
                            <th rowspan="2" class="align-middle">Observações</th>
                            <?php if ($canManage): ?><th rowspan="2" class="no-print"></th><?php endif; ?>
                        </tr>
                        <tr>
                            <th class="text-center">Entrada</th>
                            <th class="text-center">Saída</th>
                            <th class="text-center">Entrada</th>
                            <th class="text-center">Saída</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($map['days'] as $day):
                        $classes = [];
                        if ($day['is_rest']) { $classes[] = 'is-rest'; }
                        if ($day['holiday']) { $classes[] = 'is-holiday'; }
                        if ($day['is_today']) { $classes[] = 'is-today'; }
                        if ($day['missing']) { $classes[] = 'is-missing'; }
                        $showBalance = !$day['is_future'] && ($day['expected'] > 0 || $day['worked'] > 0) && !$day['is_today'];
                        $balance = $day['worked'] - $day['expected'];
                    ?>
                        <tr class="<?= implode(' ', $classes); ?>">
                            <td class="text-center text-nowrap"><strong><?= $day['day']; ?></strong> <span class="text-muted small"><?= ATTENDANCE_WEEKDAYS_SHORT[$day['weekday']]; ?></span></td>
                            <?php foreach ([1, 2, 3, 4] as $p):
                                $time = $day['punches'][$p];
                                $cellClass = !empty($day['late_flags'][$p]) ? 'text-danger fw-bold' : '';
                                $title = $day['justifications'][$p] ?? '';
                            ?>
                                <td class="text-center <?= $cellClass; ?>"<?= $title !== '' ? ' title="' . attendanceEsc($title) . '"' : ''; ?>>
                                    <?= attendanceEsc($time); ?><?php if ($time !== '' && !empty($day['manual'][$p])): ?><sup class="text-muted" title="Corrigido manualmente">*</sup><?php endif; ?><?php if ($title !== ''): ?> <i class="fa fa-comment-o text-muted"></i><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center"><?= $day['worked'] > 0 ? attendanceEsc(attendanceFormatDuration($day['worked'])) : ''; ?></td>
                            <td class="text-center text-muted"><?= $day['expected'] > 0 ? attendanceEsc(attendanceFormatDuration($day['expected'])) : ''; ?></td>
                            <td class="text-center <?= $showBalance ? ($balance < 0 ? 'text-danger' : ($balance > 0 ? 'text-success' : '')) : ''; ?>"><?= $showBalance ? attendanceEsc(attendanceFormatDuration($balance, true)) : ''; ?></td>
                            <td class="attendance-notes">
                                <?php if ($day['holiday']): ?>
                                    <span class="badge attendance-badge-holiday"><i class="fa fa-flag"></i> <?= attendanceEsc($day['holiday']['name']); ?><?= $day['holiday']['day_part'] !== 'full' ? ' (' . attendanceEsc(mb_strtolower(ATTENDANCE_DAY_PARTS[$day['holiday']['day_part']])) . ')' : ''; ?></span>
                                <?php endif; ?>
                                <?php foreach ($day['absences'] as $absence): ?>
                                    <span class="badge attendance-badge-absence <?= $absence['status'] === 'pending' ? 'is-pending' : ''; ?>" style="--absence-color: <?= attendanceEsc($absence['type_color']); ?>" title="<?= attendanceEsc(ATTENDANCE_STATUSES[$absence['status']][0] . ($absence['notes'] ? ' — ' . $absence['notes'] : '')); ?>">
                                        <?= attendanceEsc($absence['type_name']); ?><?= $absence['day_part'] !== 'full' ? ' (' . attendanceEsc(mb_strtolower(ATTENDANCE_DAY_PARTS[$absence['day_part']])) . ')' : ''; ?><?= $absence['status'] === 'pending' ? ' · pendente' : ''; ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if ($day['missing']): ?>
                                    <span class="badge bg-danger">Sem registo</span>
                                <?php elseif ($day['incomplete']): ?>
                                    <span class="badge bg-warning text-dark">Incompleto</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($canManage): ?>
                                <td class="text-center no-print">
                                    <button type="button" class="btn btn-xs btn-link p-0 js-attendance-edit-day" data-date="<?= $day['date']; ?>" title="Corrigir registos"><i class="fa fa-pencil"></i></button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="5" class="text-end">Total do mês</th>
                            <th class="text-center"><?= attendanceEsc(attendanceFormatDuration($totals['worked'])); ?></th>
                            <th class="text-center"><?= attendanceEsc(attendanceFormatDuration($totals['expected'])); ?></th>
                            <th class="text-center <?= $totals['balance'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= attendanceEsc(attendanceFormatDuration($totals['balance'], true)); ?></th>
                            <th colspan="<?= $canManage ? 2 : 1; ?>">
                                <?php foreach ($totals['absence_types'] as $item): ?>
                                    <span class="badge attendance-badge-absence" style="--absence-color: <?= attendanceEsc($item['type']['color']); ?>"><?= attendanceEsc($item['type']['name']); ?>: <?= attendanceEsc(attendanceFormatDays($item['days'])); ?> d</span>
                                <?php endforeach; ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="text-muted small">
                Horas a vermelho: atraso para além da tolerância (<?= (int) ($schedule['tolerance_minutes'] ?? 0); ?> min) ou saída antes do fim do período. <sup>*</sup> corrigido manualmente. <i class="fa fa-comment-o"></i> com justificação (passe o rato).
                Horário: <?= attendanceEsc($schedule ? attendanceScheduleSummary($schedule) : '—'); ?>.
            </div>

            <?php
            $justified = [];
            foreach ($map['days'] as $day) {
                foreach ($day['justifications'] as $p => $text) {
                    $justified[] = [$day['date'], ATTENDANCE_PERIODS[$p], $day['punches'][$p], $text];
                }
            }
            ?>
            <?php if ($justified): ?>
                <h5 class="mt-4">Justificações</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead><tr><th>Data</th><th>Período</th><th class="text-center">Hora</th><th>Motivo</th></tr></thead>
                        <tbody>
                        <?php foreach ($justified as [$date, $period, $time, $text]): ?>
                            <tr>
                                <td class="text-nowrap"><?= attendanceEsc(date('d/m/Y', strtotime($date))); ?></td>
                                <td class="text-nowrap"><?= attendanceEsc($period); ?></td>
                                <td class="text-center"><?= attendanceEsc($time); ?></td>
                                <td><?= nl2br(attendanceEsc($text)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <div class="print-only attendance-signature">
                <div>O colaborador: ______________________________</div>
                <div>A direção: ______________________________</div>
            </div>
        </div>
    </div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="attendanceDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="attendanceDayForm">
            <div class="modal-header">
                <h5 class="modal-title">Corrigir registos <small class="text-muted" id="attendanceDayTitle"></small></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save_day">
                <input type="hidden" name="csrf_token" value="<?= attendanceEsc($csrfToken); ?>">
                <input type="hidden" name="user_id" value="<?= $targetId; ?>">
                <input type="hidden" name="date" id="attendanceDayDate">
                <?php foreach (ATTENDANCE_PERIODS as $p => $label): ?>
                    <div class="row g-2 align-items-center mb-2">
                        <label class="col-5 col-form-label" for="attendanceDayP<?= $p; ?>"><?= attendanceEsc($label); ?></label>
                        <div class="col-7 col-sm-3">
                            <input type="time" class="form-control form-control-sm" name="p<?= $p; ?>" id="attendanceDayP<?= $p; ?>">
                        </div>
                        <div class="col-12 col-sm-4">
                            <input type="text" class="form-control form-control-sm" name="j<?= $p; ?>" id="attendanceDayJ<?= $p; ?>" placeholder="Justificação" maxlength="1000">
                        </div>
                    </div>
                <?php endforeach; ?>
                <p class="text-muted small mb-0">Deixe a hora vazia para remover o registo. As alterações ficam no registo de auditoria.</p>
                <div class="alert alert-danger mt-2 mb-0" id="attendanceDayError" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>
<?php
$handlerUrl = json_encode(BASE_URL . 'assiduidade/handler', JSON_UNESCAPED_SLASHES);
$targetJson = (int) $targetId;
$pageScripts = <<<JS
(function () {
    var modalEl = document.getElementById('attendanceDayModal');
    if (!modalEl) { return; }
    var modal = new bootstrap.Modal(modalEl);
    var form = document.getElementById('attendanceDayForm');
    var errorBox = document.getElementById('attendanceDayError');
    document.querySelectorAll('.js-attendance-edit-day').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var date = btn.getAttribute('data-date');
            errorBox.style.display = 'none';
            form.reset();
            document.getElementById('attendanceDayDate').value = date;
            document.getElementById('attendanceDayTitle').textContent = date.split('-').reverse().join('/');
            fetch({$handlerUrl} + '?action=day&user_id={$targetJson}&date=' + encodeURIComponent(date), {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) { throw new Error(data.error || 'Erro'); }
                    [1, 2, 3, 4].forEach(function (p) {
                        document.getElementById('attendanceDayP' + p).value = data.punches[p].time;
                        document.getElementById('attendanceDayJ' + p).value = data.punches[p].justification;
                    });
                    modal.show();
                })
                .catch(function (e) { alert(e.message); });
        });
    });
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        errorBox.style.display = 'none';
        fetch({$handlerUrl}, {method: 'POST', body: new FormData(form), credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { throw new Error(data.error || 'Erro'); }
                window.location.reload();
            })
            .catch(function (e) { errorBox.textContent = e.message; errorBox.style.display = ''; });
    });
})();
JS;
?>
<?php endif; ?>
<?php require_once dirname(__DIR__) . '/footer.php'; ?>
