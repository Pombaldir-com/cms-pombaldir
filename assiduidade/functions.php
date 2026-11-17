<?php
// Assiduidade / Registo de Ponto. Porta de intranet/assiduidade.php (legacy:
// tabela assiduidade, ponto_next_assina(), ponto_getPeriodo(), settings
// hentrada1/hsaida1/hentrada2/hsaida2/tolerancia), alargada com:
//   - varios horarios do escritorio (attendance_schedules + _days), um por
//     colaborador (attendance_user_settings, NULL = horario por omissao);
//   - ausencias e ferias com pedido/aprovacao (attendance_absences);
//   - feriados nacionais calculados + municipais/tolerancias (attendance_holidays);
//   - mapa mensal com horas trabalhadas, previstas, atrasos e faltas.
//
// Periodos (como no legacy): 1 entrada manha, 2 saida manha (almoco),
// 3 entrada tarde, 4 saida tarde.
//
// Ver docs/assiduidade.md.

require_once dirname(__DIR__) . '/functions.php';

const ATTENDANCE_PERIODS = [
    1 => 'Entrada (manhã)',
    2 => 'Saída (almoço)',
    3 => 'Entrada (tarde)',
    4 => 'Saída (tarde)',
];
const ATTENDANCE_WEEKDAYS = [
    1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta',
    5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo',
];
const ATTENDANCE_WEEKDAYS_SHORT = [1 => 'S', 2 => 'T', 3 => 'Q', 4 => 'Q', 5 => 'S', 6 => 'S', 7 => 'D'];
const ATTENDANCE_MONTHS = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
    7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
];
const ATTENDANCE_DAY_PARTS = [
    'full' => 'Dia inteiro',
    'morning' => 'Manhã',
    'afternoon' => 'Tarde',
];
const ATTENDANCE_STATUSES = [
    'pending' => ['Pendente', 'warning'],
    'approved' => ['Aprovado', 'success'],
    'rejected' => ['Recusado', 'danger'],
    'cancelled' => ['Cancelado', 'secondary'],
];

// ---------------------------------------------------------------- acesso

function attendanceHasTables(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = hasTable('attendance_schedules') && hasTable('attendance_punches') && hasTable('attendance_absences');
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

function attendanceIsEnabled(): bool {
    return isModuleActive('assiduidade') && attendanceHasTables();
}

function attendanceCurrentRole(): int {
    $user = currentUser();
    return (int) ($user['role'] ?? 3);
}

/** Configuracao (horarios, tipos, feriados, colaboradores): so administradores. */
function attendanceIsAdmin(): bool {
    return attendanceCurrentRole() <= 2;
}

/** Corrigir picagens e aprovar/recusar ausencias de qualquer colaborador. */
function attendanceCanManage(): bool {
    return attendanceIsAdmin() || userHasDepartmentPermission('assiduidade_gerir');
}

/** Ver mapas e registos de todos os colaboradores (so leitura). */
function attendanceCanViewTeam(): bool {
    return attendanceCanManage() || userHasDepartmentPermission('assiduidade_ver_equipa');
}

// ---------------------------------------------------------------- utilitarios

function attendanceEsc($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function attendanceTimeToMinutes(?string $time): ?int {
    $time = trim((string) $time);
    if ($time === '' || !preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
        return null;
    }
    $h = (int) $m[1];
    $i = (int) $m[2];
    if ($h > 23 || $i > 59) {
        return null;
    }
    return $h * 60 + $i;
}

function attendanceMinutesToTime(?int $minutes): string {
    if ($minutes === null) {
        return '';
    }
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/** Duracao em "7h30" (com sinal quando $signed). */
function attendanceFormatDuration(int $minutes, bool $signed = false): string {
    $sign = '';
    if ($minutes < 0) {
        $sign = '-';
    } elseif ($signed && $minutes > 0) {
        $sign = '+';
    }
    $minutes = abs($minutes);
    return $sign . intdiv($minutes, 60) . 'h' . sprintf('%02d', $minutes % 60);
}

function attendanceFormatDays(float $days): string {
    return rtrim(rtrim(number_format($days, 1, ',', ''), '0'), ',');
}

function attendanceNormalizeTimeInput(?string $value): ?string {
    $minutes = attendanceTimeToMinutes($value);
    return $minutes === null ? null : attendanceMinutesToTime($minutes) . ':00';
}

function attendanceValidDate(?string $value): ?string {
    $value = trim((string) $value);
    $date = $value !== '' ? DateTime::createFromFormat('!Y-m-d', $value) : false;
    return ($date && $date->format('Y-m-d') === $value) ? $value : null;
}

/** "YYYY-MM" valido ou o mes atual. */
function attendanceParseMonth(?string $value): array {
    $value = trim((string) $value);
    if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
        return [(int) $m[1], (int) $m[2]];
    }
    return [(int) date('Y'), (int) date('n')];
}

function attendanceMonthLabel(int $year, int $month): string {
    return ATTENDANCE_MONTHS[$month] . ' ' . $year;
}

function attendanceUserLabel(array $row): string {
    $name = trim((string) ($row['name'] ?? ''));
    return $name !== '' ? $name : (string) ($row['username'] ?? '');
}

function attendanceSetting(string $key, string $default): string {
    return (string) (getSetting($key, $default) ?? $default);
}

// ---------------------------------------------------------------- feriados

/** Domingo de Pascoa (algoritmo de Meeus/Jones/Butcher; sem ext-calendar). */
function attendanceEasterDate(int $year): DateTime {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
}

/** Feriados nacionais de Portugal (Codigo do Trabalho, art. 234.o). */
function attendanceNationalHolidays(int $year): array {
    $easter = attendanceEasterDate($year);
    $rel = static function (int $days) use ($easter): string {
        $d = clone $easter;
        $d->modify(($days >= 0 ? '+' : '') . $days . ' days');
        return $d->format('Y-m-d');
    };
    $list = [
        "$year-01-01" => 'Ano Novo',
        $rel(-2) => 'Sexta-feira Santa',
        $rel(0) => 'Páscoa',
        "$year-04-25" => 'Dia da Liberdade',
        "$year-05-01" => 'Dia do Trabalhador',
        $rel(60) => 'Corpo de Deus',
        "$year-06-10" => 'Dia de Portugal',
        "$year-08-15" => 'Assunção de Nossa Senhora',
        "$year-10-05" => 'Implantação da República',
        "$year-11-01" => 'Todos os Santos',
        "$year-12-01" => 'Restauração da Independência',
        "$year-12-08" => 'Imaculada Conceição',
        "$year-12-25" => 'Natal',
    ];
    if (attendanceSetting('attendance_holiday_carnaval', '1') === '1') {
        $list[$rel(-47)] = 'Carnaval';
    }
    ksort($list);
    return $list;
}

/**
 * Feriados entre duas datas: date => ['name', 'day_part', 'national'].
 * Os locais (attendance_holidays) prevalecem sobre os nacionais do mesmo dia.
 */
function attendanceHolidaysBetween(string $from, string $to): array {
    static $cache = [];
    $key = $from . '|' . $to;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $result = [];
    $y1 = (int) substr($from, 0, 4);
    $y2 = (int) substr($to, 0, 4);
    for ($y = $y1; $y <= $y2; $y++) {
        foreach (attendanceNationalHolidays($y) as $date => $name) {
            if ($date >= $from && $date <= $to) {
                $result[$date] = ['name' => $name, 'day_part' => 'full', 'national' => true];
            }
        }
    }
    try {
        $pdo = getPDO();
        $stmt = $pdo->query('SELECT holiday_date, name, day_part, recurring FROM attendance_holidays');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dates = [];
            if ((int) $row['recurring'] === 1) {
                $md = substr((string) $row['holiday_date'], 5);
                for ($y = $y1; $y <= $y2; $y++) {
                    $dates[] = $y . '-' . $md;
                }
            } else {
                $dates[] = (string) $row['holiday_date'];
            }
            foreach ($dates as $date) {
                if ($date >= $from && $date <= $to) {
                    $result[$date] = ['name' => (string) $row['name'], 'day_part' => (string) $row['day_part'], 'national' => false];
                }
            }
        }
    } catch (Throwable $e) {
        // Sem tabela: so os nacionais.
    }
    ksort($result);
    return $cache[$key] = $result;
}

// ---------------------------------------------------------------- horarios

/** Todos os horarios com os dias (weekday => linha). */
function attendanceSchedules(bool $activeOnly = false): array {
    static $cache = null;
    if ($cache === null) {
        $pdo = getPDO();
        $cache = [];
        foreach ($pdo->query('SELECT * FROM attendance_schedules ORDER BY is_default DESC, name ASC')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['days'] = [];
            $cache[(int) $row['id']] = $row;
        }
        foreach ($pdo->query('SELECT * FROM attendance_schedule_days')->fetchAll(PDO::FETCH_ASSOC) as $day) {
            $sid = (int) $day['schedule_id'];
            if (isset($cache[$sid])) {
                $cache[$sid]['days'][(int) $day['weekday']] = $day;
            }
        }
    }
    if (!$activeOnly) {
        return $cache;
    }
    return array_filter($cache, static fn($s) => (int) $s['is_active'] === 1);
}

function attendanceDefaultScheduleId(): int {
    foreach (attendanceSchedules() as $id => $schedule) {
        if ((int) $schedule['is_default'] === 1) {
            return $id;
        }
    }
    $all = attendanceSchedules();
    return $all ? (int) array_key_first($all) : 0;
}

/** Configuracao do colaborador (com valores por omissao se nao existir linha). */
function attendanceUserSettings(int $userId): array {
    static $cache = [];
    if (!isset($cache[$userId])) {
        $stmt = getPDO()->prepare('SELECT * FROM attendance_user_settings WHERE user_id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $cache[$userId] = [
            'user_id' => $userId,
            'schedule_id' => isset($row['schedule_id']) ? (int) $row['schedule_id'] : 0,
            'tracked' => isset($row['tracked']) ? (int) $row['tracked'] : 1,
            'vacation_days' => isset($row['vacation_days'])
                ? (float) $row['vacation_days']
                : (float) attendanceSetting('attendance_vacation_days_default', '22'),
            'has_row' => (bool) $row,
        ];
    }
    return $cache[$userId];
}

function attendanceUserSchedule(int $userId): ?array {
    $schedules = attendanceSchedules();
    $sid = attendanceUserSettings($userId)['schedule_id'];
    if ($sid <= 0 || !isset($schedules[$sid])) {
        $sid = attendanceDefaultScheduleId();
    }
    return $schedules[$sid] ?? null;
}

/**
 * Periodos previstos de um dia: ['morning' => [ini, fim] | null,
 * 'afternoon' => [ini, fim] | null], em minutos desde a meia-noite.
 */
function attendanceScheduleSegments(?array $schedule, string $date): array {
    $segments = ['morning' => null, 'afternoon' => null];
    if (!$schedule) {
        return $segments;
    }
    $weekday = (int) date('N', strtotime($date));
    $day = $schedule['days'][$weekday] ?? null;
    if (!$day) {
        return $segments;
    }
    foreach (['morning', 'afternoon'] as $part) {
        $s = attendanceTimeToMinutes($day[$part . '_start'] ?? null);
        $e = attendanceTimeToMinutes($day[$part . '_end'] ?? null);
        if ($s !== null && $e !== null && $e > $s) {
            $segments[$part] = [$s, $e];
        }
    }
    return $segments;
}

function attendanceScheduleDayMinutes(?array $schedule, int $weekday): int {
    $day = $schedule['days'][$weekday] ?? null;
    if (!$day) {
        return 0;
    }
    $total = 0;
    foreach (['morning', 'afternoon'] as $part) {
        $s = attendanceTimeToMinutes($day[$part . '_start'] ?? null);
        $e = attendanceTimeToMinutes($day[$part . '_end'] ?? null);
        if ($s !== null && $e !== null && $e > $s) {
            $total += $e - $s;
        }
    }
    return $total;
}

function attendanceScheduleSummary(array $schedule): string {
    $groups = [];
    foreach (ATTENDANCE_WEEKDAYS as $wd => $label) {
        $day = $schedule['days'][$wd] ?? null;
        $parts = [];
        foreach (['morning', 'afternoon'] as $part) {
            $s = attendanceTimeToMinutes($day[$part . '_start'] ?? null);
            $e = attendanceTimeToMinutes($day[$part . '_end'] ?? null);
            if ($s !== null && $e !== null && $e > $s) {
                $parts[] = attendanceMinutesToTime($s) . '-' . attendanceMinutesToTime($e);
            }
        }
        if ($parts) {
            $groups[implode(' / ', $parts)][] = mb_substr($label, 0, 3);
        }
    }
    if (!$groups) {
        return 'Sem dias de trabalho';
    }
    $out = [];
    foreach ($groups as $hours => $days) {
        $out[] = (count($days) > 2 ? $days[0] . '-' . end($days) : implode(', ', $days)) . ' ' . $hours;
    }
    return implode('; ', $out);
}

// ---------------------------------------------------------------- colaboradores

/** Utilizadores com a configuracao de assiduidade. $onlyTracked: so os que registam ponto. */
function attendanceUsers(bool $onlyTracked = true): array {
    $pdo = getPDO();
    $sql = 'SELECT u.id, u.username, u.name, u.role, s.schedule_id, s.tracked, s.vacation_days
            FROM users u LEFT JOIN attendance_user_settings s ON s.user_id = u.id
            ORDER BY COALESCE(NULLIF(u.name, \'\'), u.username) ASC';
    $rows = [];
    $defaultVacation = (float) attendanceSetting('attendance_vacation_days_default', '22');
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['tracked'] = $row['tracked'] === null ? 1 : (int) $row['tracked'];
        $row['schedule_id'] = (int) ($row['schedule_id'] ?? 0);
        $row['vacation_days'] = $row['vacation_days'] === null ? $defaultVacation : (float) $row['vacation_days'];
        $row['label'] = attendanceUserLabel($row);
        if ($onlyTracked && $row['tracked'] !== 1) {
            continue;
        }
        $rows[(int) $row['id']] = $row;
    }
    return $rows;
}

function attendanceUserIsTracked(int $userId): bool {
    return attendanceUserSettings($userId)['tracked'] === 1;
}

// ---------------------------------------------------------------- ausencias

function attendanceAbsenceTypes(bool $activeOnly = false): array {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (getPDO()->query('SELECT * FROM attendance_absence_types ORDER BY sort_order ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cache[(int) $row['id']] = $row;
        }
    }
    return $activeOnly ? array_filter($cache, static fn($t) => (int) $t['is_active'] === 1) : $cache;
}

/**
 * Ausencias que intersetam [from, to]. $userIds null = todos.
 * $statuses: estados a incluir (por omissao pendentes e aprovadas).
 */
function attendanceAbsencesBetween(string $from, string $to, ?array $userIds = null, array $statuses = ['pending', 'approved']): array {
    $where = ['a.start_date <= ?', 'a.end_date >= ?'];
    $params = [$to, $from];
    if ($statuses) {
        $where[] = 'a.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        $params = array_merge($params, $statuses);
    }
    if ($userIds !== null) {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) {
            return [];
        }
        $where[] = 'a.user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')';
        $params = array_merge($params, $userIds);
    }
    $sql = 'SELECT a.*, t.code AS type_code, t.name AS type_name, t.color AS type_color, t.is_vacation
            FROM attendance_absences a JOIN attendance_absence_types t ON t.id = a.type_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY a.start_date ASC, a.id ASC';
    $stmt = getPDO()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Peso de um dia de ausencia (0, 0.5 ou 1): conta apenas dias de trabalho do
 * horario do colaborador que nao sejam feriado.
 */
function attendanceAbsenceDayWeight(?array $schedule, string $date, string $dayPart, array $holidays): float {
    $segments = attendanceScheduleSegments($schedule, $date);
    $available = array_keys(array_filter($segments));
    $holiday = $holidays[$date] ?? null;
    if ($holiday) {
        if ($holiday['day_part'] === 'full') {
            return 0.0;
        }
        $available = array_values(array_diff($available, [$holiday['day_part']]));
    }
    if (!$available) {
        return 0.0;
    }
    if ($dayPart === 'full') {
        $hasBoth = $segments['morning'] && $segments['afternoon'];
        return ($hasBoth && count($available) === 1) ? 0.5 : 1.0;
    }
    if (!in_array($dayPart, $available, true)) {
        return 0.0;
    }
    return ($segments['morning'] && $segments['afternoon']) ? 0.5 : 1.0;
}

/** Dias uteis de uma ausencia (respeita horario e feriados). */
function attendanceCountAbsenceDays(int $userId, string $start, string $end, string $dayPart = 'full'): float {
    if ($end < $start) {
        return 0.0;
    }
    $schedule = attendanceUserSchedule($userId);
    $holidays = attendanceHolidaysBetween($start, $end);
    $total = 0.0;
    $d = new DateTime($start);
    $last = new DateTime($end);
    while ($d <= $last) {
        $total += attendanceAbsenceDayWeight($schedule, $d->format('Y-m-d'), $dayPart, $holidays);
        $d->modify('+1 day');
    }
    return $total;
}

/** Dias de uma ausencia dentro de [from, to]. */
function attendanceAbsenceDaysWithin(array $absence, string $from, string $to): float {
    $start = max((string) $absence['start_date'], $from);
    $end = min((string) $absence['end_date'], $to);
    return attendanceCountAbsenceDays((int) $absence['user_id'], $start, $end, (string) $absence['day_part']);
}

function attendanceVacationCarryover(int $userId, int $year): float {
    try {
        $stmt = getPDO()->prepare('SELECT days FROM attendance_vacation_carryover WHERE user_id = ? AND year = ?');
        $stmt->execute([$userId, $year]);
        return (float) ($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0.0;
    }
}

/** Saldo de ferias do ano: direito, transitados, gozados (aprovados), pendentes, restantes. */
function attendanceVacationSummary(int $userId, int $year, ?int $excludeAbsenceId = null): array {
    $from = "$year-01-01";
    $to = "$year-12-31";
    $allowed = attendanceUserSettings($userId)['vacation_days'];
    $carry = attendanceVacationCarryover($userId, $year);
    $used = 0.0;
    $pending = 0.0;
    foreach (attendanceAbsencesBetween($from, $to, [$userId]) as $absence) {
        if ((int) $absence['is_vacation'] !== 1 || ($excludeAbsenceId && (int) $absence['id'] === $excludeAbsenceId)) {
            continue;
        }
        $days = attendanceAbsenceDaysWithin($absence, $from, $to);
        if ($absence['status'] === 'approved') {
            $used += $days;
        } else {
            $pending += $days;
        }
    }
    return [
        'allowed' => $allowed,
        'carryover' => $carry,
        'total' => $allowed + $carry,
        'used' => $used,
        'pending' => $pending,
        'remaining' => $allowed + $carry - $used - $pending,
    ];
}

/** Ausencias que se sobrepoem (pendentes/aprovadas) a um intervalo/parte do dia. */
function attendanceFindOverlap(int $userId, string $start, string $end, string $dayPart, int $ignoreId = 0): ?array {
    foreach (attendanceAbsencesBetween($start, $end, [$userId]) as $absence) {
        if ((int) $absence['id'] === $ignoreId) {
            continue;
        }
        // Meio dia de manha e meio dia de tarde no mesmo dia nao colidem.
        if ($start === $end && $absence['start_date'] === $absence['end_date']
            && $dayPart !== 'full' && $absence['day_part'] !== 'full' && $dayPart !== $absence['day_part']) {
            continue;
        }
        return $absence;
    }
    return null;
}

function attendancePendingAbsenceCount(): int {
    try {
        return (int) getPDO()->query("SELECT COUNT(*) FROM attendance_absences WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// ---------------------------------------------------------------- picagens

/** Picagens de um colaborador: date => [period => linha]. */
function attendancePunchesBetween(int $userId, string $from, string $to): array {
    $stmt = getPDO()->prepare('SELECT * FROM attendance_punches WHERE user_id = ? AND work_date BETWEEN ? AND ? ORDER BY work_date, period');
    $stmt->execute([$userId, $from, $to]);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(string) $row['work_date']][(int) $row['period']] = $row;
    }
    return $result;
}

/** Periodos que o colaborador deve picar num dia (todos quando nao e dia de trabalho). */
function attendanceExpectedPeriods(array $segments): array {
    $periods = [];
    if ($segments['morning']) {
        $periods[] = 1;
        $periods[] = 2;
    }
    if ($segments['afternoon']) {
        $periods[] = 3;
        $periods[] = 4;
    }
    return $periods ?: [1, 2, 3, 4];
}

/** Nomes dos periodos para um dia: num horario so de manha ou so de tarde, "Entrada"/"Saida". */
function attendancePeriodLabels(array $segments): array {
    $labels = ATTENDANCE_PERIODS;
    if ($segments['morning'] && !$segments['afternoon']) {
        $labels[1] = 'Entrada';
        $labels[2] = 'Saída';
    } elseif ($segments['afternoon'] && !$segments['morning']) {
        $labels[3] = 'Entrada';
        $labels[4] = 'Saída';
    }
    return $labels;
}

/**
 * Estado do ponto de hoje para o botao da barra de topo e o modal:
 * proximo periodo (ou null quando o dia esta completo), periodos ainda
 * disponiveis, hora prevista e atraso previsto.
 */
function attendanceTodayStatus(int $userId, ?DateTime $now = null): array {
    $now = $now ?: new DateTime();
    $today = $now->format('Y-m-d');
    $nowMin = (int) $now->format('G') * 60 + (int) $now->format('i');
    $schedule = attendanceUserSchedule($userId);
    $segments = attendanceScheduleSegments($schedule, $today);
    $punches = attendancePunchesBetween($userId, $today, $today)[$today] ?? [];
    $expected = attendanceExpectedPeriods($segments);

    // Ausencia aprovada de meio dia: esse periodo nao se pica.
    foreach (attendanceAbsencesBetween($today, $today, [$userId], ['approved']) as $absence) {
        if ($absence['day_part'] === 'morning') {
            $expected = array_values(array_diff($expected, [1, 2])) ?: $expected;
        } elseif ($absence['day_part'] === 'afternoon') {
            $expected = array_values(array_diff($expected, [3, 4])) ?: $expected;
        }
    }

    $last = $punches ? max(array_keys($punches)) : 0;
    $available = array_values(array_filter($expected, static fn($p) => $p > $last));
    $next = $available[0] ?? null;

    // Sem picagens e ja passou o fim da manha (legacy: ponto_next_assina):
    // a primeira picagem e a entrada da tarde.
    if (!$punches && $next === 1 && $segments['morning'] && $segments['afternoon'] && $nowMin >= $segments['morning'][1]) {
        $next = 3;
    }

    $reference = null;
    if ($next === 1 && $segments['morning']) {
        $reference = $segments['morning'][0];
    } elseif ($next === 2 && $segments['morning']) {
        $reference = $segments['morning'][1];
    } elseif ($next === 3 && $segments['afternoon']) {
        $reference = $segments['afternoon'][0];
    } elseif ($next === 4 && $segments['afternoon']) {
        $reference = $segments['afternoon'][1];
    }
    $tolerance = (int) ($schedule['tolerance_minutes'] ?? 0);
    $late = 0;
    if ($reference !== null && in_array($next, [1, 3], true) && $nowMin - $reference > $tolerance) {
        $late = $nowMin - $reference;
    }

    return [
        'date' => $today,
        'now' => $now->format('H:i'),
        'next' => $next,
        'next_label' => $next ? attendancePeriodLabels($segments)[$next] : '',
        'labels' => attendancePeriodLabels($segments),
        'is_entry' => in_array($next, [1, 3], true),
        'available' => $available,
        'reference' => $reference !== null ? attendanceMinutesToTime($reference) : '',
        'late_minutes' => $late,
        'require_justification' => $late > 0 && attendanceSetting('attendance_require_justification', '1') === '1',
        'punches' => array_map(static fn($p) => substr((string) $p['punched_at'], 11, 5), $punches),
        'schedule_name' => (string) ($schedule['name'] ?? ''),
    ];
}

/**
 * Regista a picagem do colaborador agora (botao Registar). So aceita um
 * periodo posterior ao ultimo registado hoje.
 */
function attendanceRegisterPunch(int $userId, int $period, string $justification = ''): array {
    $status = attendanceTodayStatus($userId);
    if (!$status['next']) {
        return ['ok' => false, 'error' => 'Os registos de hoje já estão completos.'];
    }
    $allowed = array_unique(array_merge([$status['next']], $status['available']));
    if (!in_array($period, $allowed, true) || ($period < $status['next'])) {
        return ['ok' => false, 'error' => 'Período inválido para registo.'];
    }
    $late = $period === $status['next'] ? (int) $status['late_minutes'] : 0;
    $justification = trim($justification);
    if ($late > 0 && $status['require_justification'] && $justification === '' && $period === $status['next']) {
        return ['ok' => false, 'error' => 'Indique o motivo do atraso.', 'need_justification' => true];
    }
    $now = date('Y-m-d H:i:s');
    try {
        $stmt = getPDO()->prepare('INSERT INTO attendance_punches (user_id, work_date, period, punched_at, source, late_minutes, justification, ip, created_by)
            VALUES (?, ?, ?, ?, \'web\', ?, ?, ?, ?)');
        $stmt->execute([$userId, $status['date'], $period, $now, $late, $justification !== '' ? $justification : null,
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $userId]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            return ['ok' => false, 'error' => 'Este período já foi registado hoje.'];
        }
        throw $e;
    }
    return ['ok' => true, 'period' => $period, 'time' => substr($now, 11, 5), 'label' => $status['labels'][$period] ?? ATTENDANCE_PERIODS[$period]];
}

// ---------------------------------------------------------------- mapa mensal

/**
 * Mapa mensal de um colaborador: um registo por dia com picagens, horas
 * previstas/trabalhadas, atrasos, saidas antecipadas, feriados e ausencias,
 * mais os totais do mes.
 */
function attendanceBuildMonth(int $userId, int $year, int $month): array {
    $from = sprintf('%04d-%02d-01', $year, $month);
    $to = date('Y-m-t', strtotime($from));
    $today = date('Y-m-d');
    $schedule = attendanceUserSchedule($userId);
    $tolerance = (int) ($schedule['tolerance_minutes'] ?? 0);
    $holidays = attendanceHolidaysBetween($from, $to);
    $punches = attendancePunchesBetween($userId, $from, $to);
    $absences = attendanceAbsencesBetween($from, $to, [$userId]);
    $types = attendanceAbsenceTypes();

    $days = [];
    $totals = [
        'expected' => 0, 'worked' => 0, 'late_count' => 0, 'late_minutes' => 0,
        'early_minutes' => 0, 'missing_days' => 0, 'incomplete_days' => 0,
        'work_days' => 0, 'absences' => [],
    ];

    $d = new DateTime($from);
    $last = new DateTime($to);
    while ($d <= $last) {
        $date = $d->format('Y-m-d');
        $segments = attendanceScheduleSegments($schedule, $date);
        $holiday = $holidays[$date] ?? null;
        $dayAbsences = [];
        foreach ($absences as $absence) {
            if ($absence['start_date'] <= $date && $absence['end_date'] >= $date) {
                $dayAbsences[] = $absence;
            }
        }

        // Partes do dia previstas, depois de feriados e ausencias aprovadas.
        $expectedParts = array_filter($segments);
        if ($holiday) {
            if ($holiday['day_part'] === 'full') {
                $expectedParts = [];
            } else {
                unset($expectedParts[$holiday['day_part']]);
            }
        }
        foreach ($dayAbsences as $absence) {
            if ($absence['status'] !== 'approved') {
                continue;
            }
            if ($absence['day_part'] === 'full') {
                $expectedParts = [];
            } else {
                unset($expectedParts[$absence['day_part']]);
            }
            $weight = attendanceAbsenceDayWeight($schedule, $date, (string) $absence['day_part'], $holidays);
            if ($weight > 0) {
                $tid = (int) $absence['type_id'];
                $totals['absences'][$tid] = ($totals['absences'][$tid] ?? 0) + $weight;
            }
        }
        $expected = 0;
        foreach ($expectedParts as $seg) {
            $expected += $seg[1] - $seg[0];
        }

        $dayPunches = $punches[$date] ?? [];
        $times = [];
        foreach ([1, 2, 3, 4] as $p) {
            $times[$p] = isset($dayPunches[$p]) ? attendanceTimeToMinutes(substr((string) $dayPunches[$p]['punched_at'], 11, 5)) : null;
        }
        $worked = 0;
        $incomplete = false;
        foreach ([[1, 2], [3, 4]] as [$in, $out]) {
            if ($times[$in] !== null && $times[$out] !== null && $times[$out] > $times[$in]) {
                $worked += $times[$out] - $times[$in];
            } elseif ($times[$in] !== null || $times[$out] !== null) {
                $incomplete = true;
            }
        }
        // Dia continuo (so entrada manha + saida tarde).
        if ($times[1] !== null && $times[2] === null && $times[3] === null && $times[4] !== null && $times[4] > $times[1]) {
            $worked = $times[4] - $times[1];
            $incomplete = false;
        }

        $late = 0;
        $early = 0;
        $lateFlags = [];
        if (isset($expectedParts['morning'])) {
            [$ms, $me] = $expectedParts['morning'];
            if ($times[1] !== null && $times[1] - $ms > $tolerance) {
                $late += $times[1] - $ms;
                $lateFlags[1] = true;
            }
            if ($times[2] !== null && $times[2] < $me) {
                $early += $me - $times[2];
                $lateFlags[2] = true;
            }
        }
        if (isset($expectedParts['afternoon'])) {
            [$as, $ae] = $expectedParts['afternoon'];
            if ($times[3] !== null && $times[3] - $as > $tolerance) {
                $late += $times[3] - $as;
                $lateFlags[3] = true;
            }
            if ($times[4] !== null && $times[4] < $ae) {
                $early += $ae - $times[4];
                $lateFlags[4] = true;
            }
        }

        $hasPunches = (bool) array_filter($times, static fn($t) => $t !== null);
        $isPast = $date < $today;
        // Picou uma parte do dia mas falta outra parte prevista (ex.: so a manha).
        $continuous = $times[1] !== null && $times[4] !== null && $times[2] === null && $times[3] === null;
        if ($hasPunches && !$continuous) {
            if (isset($expectedParts['morning']) && $times[1] === null && $times[2] === null) {
                $incomplete = true;
            }
            if (isset($expectedParts['afternoon']) && $times[3] === null && $times[4] === null) {
                $incomplete = true;
            }
        }
        $missing = $expected > 0 && $isPast && !$hasPunches;
        if ($date === $today) {
            $incomplete = false;
        }

        $totals['expected'] += ($date <= $today) ? $expected : 0;
        $totals['worked'] += $worked;
        $totals['late_minutes'] += $late;
        $totals['late_count'] += count(array_intersect_key($lateFlags, [1 => 1, 3 => 1]));
        $totals['early_minutes'] += $early;
        $totals['missing_days'] += $missing ? 1 : 0;
        $totals['incomplete_days'] += ($incomplete && $isPast) ? 1 : 0;
        $totals['work_days'] += $expected > 0 ? 1 : 0;

        $justifications = [];
        foreach ($dayPunches as $p => $row) {
            if (trim((string) ($row['justification'] ?? '')) !== '') {
                $justifications[$p] = (string) $row['justification'];
            }
        }

        $days[$date] = [
            'date' => $date,
            'day' => (int) $d->format('j'),
            'weekday' => (int) $d->format('N'),
            'is_rest' => !$segments['morning'] && !$segments['afternoon'],
            'is_today' => $date === $today,
            'is_future' => $date > $today,
            'holiday' => $holiday,
            'absences' => $dayAbsences,
            'punches' => array_map(static fn($t) => $t === null ? '' : attendanceMinutesToTime($t), $times),
            'manual' => array_map(static fn($row) => ($row['source'] ?? '') === 'manual', $dayPunches),
            'late_flags' => $lateFlags,
            'justifications' => $justifications,
            'expected' => $expected,
            'worked' => $worked,
            'late' => $late,
            'early' => $early,
            'missing' => $missing,
            'incomplete' => $incomplete && $isPast,
        ];
        $d->modify('+1 day');
    }
    $totals['balance'] = $totals['worked'] - $totals['expected'];
    $totals['absence_types'] = [];
    foreach ($totals['absences'] as $tid => $count) {
        $totals['absence_types'][] = ['type' => $types[$tid] ?? ['name' => '?', 'color' => '#999', 'code' => '?'], 'days' => $count];
    }

    return ['from' => $from, 'to' => $to, 'schedule' => $schedule, 'days' => $days, 'totals' => $totals];
}

/** Notifica por push (se disponivel) sem bloquear o pedido. */
function attendanceNotifyUsers(array $userIds, string $title, string $body, string $url): void {
    $pushFile = dirname(__DIR__) . '/push/functions.php';
    if (!is_file($pushFile)) {
        return;
    }
    require_once $pushFile;
    if (!function_exists('pushSendToUser') || !pushHasTable()) {
        return;
    }
    $pdo = getPDO();
    $payload = pushPayload(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => 'assiduidade']);
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    pushAfterResponse(static function () use ($pdo, $userIds, $payload): void {
        foreach ($userIds as $uid) {
            pushSendToUser($pdo, $uid, $payload);
        }
    });
}

/** Utilizadores que decidem pedidos de ausencia (admins + permissao assiduidade_gerir). */
function attendanceManagerIds(): array {
    $ids = [];
    $pdo = getPDO();
    foreach ($pdo->query('SELECT id FROM users WHERE role <= 2')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[] = (int) $id;
    }
    $map = function_exists('getDepartmentPermissions') ? getDepartmentPermissions() : [];
    $departments = [];
    foreach ($map as $deptId => $perms) {
        if (is_array($perms) && in_array('assiduidade_gerir', $perms, true)) {
            $departments[] = (int) $deptId;
        }
    }
    if ($departments && hasTable('user_taxonomy_terms')) {
        $stmt = $pdo->prepare('SELECT DISTINCT user_id FROM user_taxonomy_terms WHERE term_id IN (' . implode(',', array_fill(0, count($departments), '?')) . ')');
        $stmt->execute($departments);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }
    }
    return array_values(array_unique($ids));
}

/** Pagina: termina com 403 quando o modulo esta inativo. */
function attendanceRequirePage(): void {
    if (!isModuleActive('assiduidade')) {
        http_response_code(403);
        require_once dirname(__DIR__) . '/header.php';
        echo '<div class="x_panel"><div class="x_content"><div class="alert alert-warning mb-0">O módulo Assiduidade não está ativo. Ative-o em Definições &gt; Módulos.</div></div></div>';
        require_once dirname(__DIR__) . '/footer.php';
        exit;
    }
    if (!attendanceHasTables()) {
        require_once dirname(__DIR__) . '/header.php';
        echo '<div class="x_panel"><div class="x_content"><div class="alert alert-warning mb-0">A assiduidade ainda não está disponível nesta base de dados. Execute as migrações pendentes.</div></div></div>';
        require_once dirname(__DIR__) . '/footer.php';
        exit;
    }
}
