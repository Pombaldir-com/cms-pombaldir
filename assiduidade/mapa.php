<?php
// Mapa de ausencias / ferias da equipa. Rota "assiduidade/mapa".
//   ?vista=mes&mes=YYYY-MM   grelha colaboradores x dias + resumo de horas do mes
//   ?vista=ano&ano=YYYY      dias de ferias/ausencias por mes
// Quem tem attendanceCanViewTeam() ve todos; os restantes so a si proprios,
// salvo se a setting attendance_map_public = 1 (grelha visivel a todos, sem o
// resumo de horas).
// Ver docs/assiduidade.md.

require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
attendanceRequirePage();

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$canViewTeam = attendanceCanViewTeam();
$mapPublic = attendanceSetting('attendance_map_public', '0') === '1';
$seeAll = $canViewTeam || $mapPublic;
$selfUrl = BASE_URL . 'assiduidade/mapa';

$view = ($_GET['vista'] ?? 'mes') === 'ano' ? 'ano' : 'mes';
[$year, $month] = attendanceParseMonth($_GET['mes'] ?? '');
if ($view === 'ano') {
    $year = (int) ($_GET['ano'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }
}
$monthKey = sprintf('%04d-%02d', $year, $month);

$users = attendanceUsers(true);
if (!$seeAll) {
    $users = array_intersect_key($users, [$userId => true]);
    if (!$users) {
        $users = [$userId => ['id' => $userId, 'label' => attendanceUserLabel($user)]];
    }
}
$types = attendanceAbsenceTypes();

$useAttendance = true;
require_once dirname(__DIR__) . '/header.php';
?>
<div class="container-fluid attendance-page attendance-map-page">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3 no-print">
        <div>
            <h2 class="mb-1"><i class="fa fa-th"></i> Mapa de Ausências</h2>
            <p class="mb-0 text-muted">Férias, faltas e outras ausências da equipa, com feriados e folgas.</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <ul class="nav nav-pills attendance-view-pills">
                <li class="nav-item"><a class="nav-link <?= $view === 'mes' ? 'active' : ''; ?>" href="<?= $selfUrl; ?>?vista=mes&mes=<?= $view === 'mes' ? $monthKey : $year . '-' . date('m'); ?>">Mensal</a></li>
                <li class="nav-item"><a class="nav-link <?= $view === 'ano' ? 'active' : ''; ?>" href="<?= $selfUrl; ?>?vista=ano&ano=<?= $year; ?>">Anual</a></li>
            </ul>
            <?php if ($view === 'mes'):
                $prev = (new DateTime($monthKey . '-01'))->modify('-1 month')->format('Y-m');
                $next = (new DateTime($monthKey . '-01'))->modify('+1 month')->format('Y-m'); ?>
                <div class="btn-group" role="group" aria-label="Mês">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $selfUrl; ?>?vista=mes&mes=<?= $prev; ?>" title="Mês anterior"><i class="fa fa-chevron-left"></i></a>
                    <form method="get" action="<?= $selfUrl; ?>" class="d-inline">
                        <input type="hidden" name="vista" value="mes">
                        <input type="month" name="mes" value="<?= $monthKey; ?>" class="form-control form-control-sm attendance-month-input" onchange="this.form.submit()" aria-label="Mês">
                    </form>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $selfUrl; ?>?vista=mes&mes=<?= $next; ?>" title="Mês seguinte"><i class="fa fa-chevron-right"></i></a>
                </div>
            <?php else: ?>
                <div class="btn-group" role="group" aria-label="Ano">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $selfUrl; ?>?vista=ano&ano=<?= $year - 1; ?>"><i class="fa fa-chevron-left"></i></a>
                    <span class="btn btn-sm btn-outline-secondary disabled"><?= $year; ?></span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= $selfUrl; ?>?vista=ano&ano=<?= $year + 1; ?>"><i class="fa fa-chevron-right"></i></a>
                </div>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-secondary" onclick="window.print()"><i class="fa fa-print"></i> Imprimir</button>
        </div>
    </div>

<?php if ($view === 'mes'):
    $from = $monthKey . '-01';
    $to = date('Y-m-t', strtotime($from));
    $holidays = attendanceHolidaysBetween($from, $to);
    $numDays = (int) date('t', strtotime($from));
    $today = date('Y-m-d');
    $months = [];
    foreach ($users as $uid => $member) {
        $months[$uid] = attendanceBuildMonth((int) $uid, $year, $month);
    }
?>
    <div class="print-only mb-2"><h3 class="mb-0">Mapa de ausências — <?= attendanceEsc(attendanceMonthLabel($year, $month)); ?></h3></div>
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-calendar"></i> <?= attendanceEsc(attendanceMonthLabel($year, $month)); ?></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div class="table-responsive">
                <table class="table table-bordered attendance-grid mb-2">
                    <thead>
                        <tr>
                            <th class="attendance-grid-name">Colaborador</th>
                            <?php for ($d = 1; $d <= $numDays; $d++):
                                $date = sprintf('%s-%02d', $monthKey, $d);
                                $wd = (int) date('N', strtotime($date));
                                $hol = $holidays[$date] ?? null; ?>
                                <th class="text-center <?= $wd >= 6 ? 'is-rest' : ''; ?> <?= $hol ? 'is-holiday' : ''; ?> <?= $date === $today ? 'is-today' : ''; ?>"<?= $hol ? ' title="' . attendanceEsc($hol['name']) . '"' : ''; ?>>
                                    <div class="small text-muted"><?= ATTENDANCE_WEEKDAYS_SHORT[$wd]; ?></div><?= $d; ?>
                                </th>
                            <?php endfor; ?>
                            <th class="text-center" title="Dias de férias no mês">Fér.</th>
                            <th class="text-center" title="Outras ausências no mês">Aus.</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $uid => $member):
                        $monthData = $months[$uid];
                        $vacDays = 0.0;
                        $otherDays = 0.0;
                        foreach ($monthData['totals']['absence_types'] as $item) {
                            if ((int) ($item['type']['is_vacation'] ?? 0) === 1) {
                                $vacDays += $item['days'];
                            } else {
                                $otherDays += $item['days'];
                            }
                        }
                    ?>
                        <tr>
                            <td class="attendance-grid-name text-nowrap">
                                <?php if ($canViewTeam): ?>
                                    <a href="<?= BASE_URL ?>assiduidade/registo-ponto?mes=<?= $monthKey; ?>&colaborador=<?= (int) $uid; ?>"><?= attendanceEsc($member['label']); ?></a>
                                <?php else: ?>
                                    <?= attendanceEsc($member['label']); ?>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($monthData['days'] as $date => $day):
                                $classes = [];
                                $style = '';
                                $text = '';
                                $title = [];
                                if ($day['is_rest']) { $classes[] = 'is-rest'; $title[] = 'Folga'; }
                                if ($day['holiday']) { $classes[] = 'is-holiday'; $title[] = $day['holiday']['name']; }
                                if ($day['is_today']) { $classes[] = 'is-today'; }
                                $cellAbsences = ($day['is_rest'] || ($day['holiday'] && $day['holiday']['day_part'] === 'full')) ? [] : $day['absences'];
                                if ($cellAbsences) {
                                    // Duas meias ausencias no mesmo dia: celula cheia com os dois codigos.
                                    $first = $cellAbsences[0];
                                    $classes[] = 'has-absence';
                                    if (count($cellAbsences) === 1 && $first['day_part'] !== 'full') { $classes[] = 'is-half-' . $first['day_part']; }
                                    if (!array_filter($cellAbsences, static fn($a) => $a['status'] === 'approved')) { $classes[] = 'is-pending'; }
                                    $style = '--absence-color: ' . attendanceEsc($first['type_color']) . ';';
                                    $text = attendanceEsc(implode('/', array_unique(array_column($cellAbsences, 'type_code'))));
                                    foreach ($cellAbsences as $absence) {
                                        $title[] = $absence['type_name'] . ($absence['day_part'] !== 'full' ? ' (' . mb_strtolower(ATTENDANCE_DAY_PARTS[$absence['day_part']]) . ')' : '') . ($absence['status'] === 'pending' ? ' — pendente' : '');
                                    }
                                }
                                if ($canViewTeam && $day['missing'] && $text === '') {
                                    $classes[] = 'is-missing';
                                    $text = '!';
                                    $title[] = 'Sem registo de ponto';
                                }
                            ?>
                                <td class="text-center <?= implode(' ', $classes); ?>" style="<?= $style; ?>"<?= $title ? ' title="' . attendanceEsc(implode(' · ', $title)) . '"' : ''; ?>><?= $text; ?></td>
                            <?php endforeach; ?>
                            <td class="text-center fw-bold"><?= $vacDays > 0 ? attendanceEsc(attendanceFormatDays($vacDays)) : ''; ?></td>
                            <td class="text-center"><?= $otherDays > 0 ? attendanceEsc(attendanceFormatDays($otherDays)) : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="attendance-legend small">
                <?php foreach (attendanceAbsenceTypes(true) as $type): ?>
                    <span class="attendance-legend-item"><span class="attendance-legend-swatch" style="--absence-color: <?= attendanceEsc($type['color']); ?>"><?= attendanceEsc($type['code']); ?></span> <?= attendanceEsc($type['name']); ?></span>
                <?php endforeach; ?>
                <span class="attendance-legend-item"><span class="attendance-legend-swatch is-pending" style="--absence-color: #73879C">&nbsp;</span> Pendente</span>
                <span class="attendance-legend-item"><span class="attendance-legend-swatch is-holiday-swatch">&nbsp;</span> Feriado</span>
                <span class="attendance-legend-item"><span class="attendance-legend-swatch is-rest-swatch">&nbsp;</span> Folga</span>
                <?php if ($canViewTeam): ?><span class="attendance-legend-item"><span class="attendance-legend-swatch is-missing-swatch">!</span> Sem registo</span><?php endif; ?>
            </div>
            <?php if ($holidays): ?>
                <div class="small text-muted mt-2"><i class="fa fa-flag"></i> Feriados:
                    <?= attendanceEsc(implode('; ', array_map(static fn($date, $h) => date('d', strtotime($date)) . ' ' . $h['name'] . ($h['day_part'] !== 'full' ? ' (' . mb_strtolower(ATTENDANCE_DAY_PARTS[$h['day_part']]) . ')' : ''), array_keys($holidays), $holidays))); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canViewTeam): ?>
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-clock-o"></i> Resumo de horas <small><?= attendanceEsc(attendanceMonthLabel($year, $month)); ?></small></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th>Colaborador</th>
                            <th>Horário</th>
                            <th class="text-center">Previstas</th>
                            <th class="text-center">Trabalhadas</th>
                            <th class="text-center">Saldo</th>
                            <th class="text-center">Atrasos</th>
                            <th class="text-center">Saídas antec.</th>
                            <th class="text-center">Sem registo</th>
                            <th class="text-center">Incompletos</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $uid => $member): $t = $months[$uid]['totals']; ?>
                        <tr>
                            <td><a href="<?= BASE_URL ?>assiduidade/registo-ponto?mes=<?= $monthKey; ?>&colaborador=<?= (int) $uid; ?>"><?= attendanceEsc($member['label']); ?></a></td>
                            <td class="small text-muted"><?= attendanceEsc($months[$uid]['schedule']['name'] ?? '—'); ?></td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDuration($t['expected'])); ?></td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDuration($t['worked'])); ?></td>
                            <td class="text-center fw-bold <?= $t['balance'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= attendanceEsc(attendanceFormatDuration($t['balance'], true)); ?></td>
                            <td class="text-center <?= $t['late_count'] ? 'text-danger' : ''; ?>"><?= (int) $t['late_count']; ?><?= $t['late_count'] ? ' <span class="small text-muted">(' . attendanceEsc(attendanceFormatDuration($t['late_minutes'])) . ')</span>' : ''; ?></td>
                            <td class="text-center"><?= $t['early_minutes'] ? attendanceEsc(attendanceFormatDuration($t['early_minutes'])) : '0'; ?></td>
                            <td class="text-center <?= $t['missing_days'] ? 'text-danger fw-bold' : ''; ?>"><?= (int) $t['missing_days']; ?></td>
                            <td class="text-center <?= $t['incomplete_days'] ? 'text-warning' : ''; ?>"><?= (int) $t['incomplete_days']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mb-0">Horas previstas contadas até hoje, já descontando feriados e ausências aprovadas.</p>
        </div>
    </div>
    <?php endif; ?>

<?php else:
    $from = "$year-01-01";
    $to = "$year-12-31";
    $absences = attendanceAbsencesBetween($from, $to, array_keys($users), ['approved', 'pending']);
    $grid = [];
    foreach ($absences as $absence) {
        $uid = (int) $absence['user_id'];
        for ($m = 1; $m <= 12; $m++) {
            $mFrom = sprintf('%04d-%02d-01', $year, $m);
            $mTo = date('Y-m-t', strtotime($mFrom));
            if ($absence['start_date'] > $mTo || $absence['end_date'] < $mFrom) {
                continue;
            }
            $days = attendanceAbsenceDaysWithin($absence, $mFrom, $mTo);
            if ($days <= 0) {
                continue;
            }
            $key = (int) $absence['is_vacation'] === 1 ? 'vac' : 'other';
            $grid[$uid][$m][$key] = ($grid[$uid][$m][$key] ?? 0) + $days;
            if ($absence['status'] === 'pending') {
                $grid[$uid][$m]['pending'] = true;
            }
        }
    }
?>
    <div class="print-only mb-2"><h3 class="mb-0">Mapa anual de ausências — <?= $year; ?></h3></div>
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-calendar"></i> <?= $year; ?> <small>dias úteis de férias / outras ausências por mês</small></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div class="table-responsive">
                <table class="table table-bordered table-sm attendance-year-table">
                    <thead>
                        <tr>
                            <th>Colaborador</th>
                            <?php foreach (ATTENDANCE_MONTHS as $m => $label): ?>
                                <th class="text-center"><a href="<?= $selfUrl; ?>?vista=mes&mes=<?= sprintf('%04d-%02d', $year, $m); ?>"><?= attendanceEsc(mb_substr($label, 0, 3)); ?></a></th>
                            <?php endforeach; ?>
                            <th class="text-center">Férias</th>
                            <th class="text-center">Por gozar</th>
                            <th class="text-center">Outras</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $uid => $member):
                        $vacTotal = 0.0;
                        $otherTotal = 0.0;
                        $summary = attendanceVacationSummary((int) $uid, $year); ?>
                        <tr>
                            <td class="text-nowrap"><?= attendanceEsc($member['label']); ?></td>
                            <?php for ($m = 1; $m <= 12; $m++):
                                $cell = $grid[$uid][$m] ?? [];
                                $vac = $cell['vac'] ?? 0;
                                $other = $cell['other'] ?? 0;
                                $vacTotal += $vac;
                                $otherTotal += $other; ?>
                                <td class="text-center <?= $vac > 0 ? 'attendance-year-vac' : ''; ?> <?= !empty($cell['pending']) ? 'is-pending' : ''; ?>">
                                    <?= $vac > 0 ? '<strong>' . attendanceEsc(attendanceFormatDays($vac)) . '</strong>' : ''; ?>
                                    <?= $other > 0 ? '<span class="text-muted small">' . ($vac > 0 ? ' / ' : '') . attendanceEsc(attendanceFormatDays($other)) . '</span>' : ''; ?>
                                </td>
                            <?php endfor; ?>
                            <td class="text-center fw-bold"><?= attendanceEsc(attendanceFormatDays($vacTotal)); ?></td>
                            <td class="text-center <?= $summary['remaining'] < 0 ? 'text-danger' : ''; ?>"><?= attendanceEsc(attendanceFormatDays($summary['remaining'])); ?></td>
                            <td class="text-center"><?= attendanceEsc(attendanceFormatDays($otherTotal)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mb-0">Inclui pedidos pendentes (fundo tracejado). Em negrito os dias de férias; a cinzento as outras ausências.</p>
        </div>
    </div>
<?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/footer.php'; ?>
