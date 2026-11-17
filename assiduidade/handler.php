<?php
// Endpoint JSON da assiduidade (rota "assiduidade/handler").
// Ver assiduidade/functions.php e docs/assiduidade.md.
//
// GET  ?action=status                          estado do ponto de hoje (botao/modal)
// GET  ?action=day&user_id=&date=              picagens de um dia (modal de correcao)
// POST action=punch    period [justification]  regista a picagem agora
// POST action=save_day user_id date p1..p4 j1..j4   corrige um dia (gestores)
//
// Os POST exigem csrf_token (nao consumido, como no calendario).

require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (!attendanceIsEnabled()) {
    $respond(['ok' => false, 'error' => 'O módulo Assiduidade não está ativo ou faltam migrações.'], 503);
}
if ($isPost && !validateCsrfToken((string) ($_POST['csrf_token'] ?? ''), false)) {
    $respond(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 400);
}

$pdo = getPDO();

if ($action === 'status' && !$isPost) {
    if (!attendanceUserIsTracked($userId)) {
        $respond(['ok' => true, 'tracked' => false]);
    }
    $status = attendanceTodayStatus($userId);
    $status['available_labels'] = [];
    foreach ($status['available'] as $p) {
        $status['available_labels'][$p] = $status['labels'][$p];
    }
    $respond(['ok' => true, 'tracked' => true] + $status);
}

if ($action === 'punch' && $isPost) {
    if (!attendanceUserIsTracked($userId)) {
        $respond(['ok' => false, 'error' => 'O seu utilizador não regista ponto.'], 403);
    }
    $period = (int) ($_POST['period'] ?? 0);
    $result = attendanceRegisterPunch($userId, $period, (string) ($_POST['justification'] ?? ''));
    if (!empty($result['ok'])) {
        logAuditAction('attendance_punch', 'user', $userId, ['period' => $period, 'time' => $result['time']]);
        $next = attendanceTodayStatus($userId);
        $result['next'] = $next['next'];
        $result['next_label'] = $next['next_label'];
        $result['is_entry'] = $next['is_entry'];
        $result['message'] = $result['label'] . ' registada às ' . $result['time'] . '.';
    }
    $respond($result, !empty($result['ok']) ? 200 : 422);
}

if ($action === 'day' && !$isPost) {
    $targetId = (int) ($_GET['user_id'] ?? 0);
    $date = attendanceValidDate($_GET['date'] ?? '');
    if (!$date || $targetId <= 0) {
        $respond(['ok' => false, 'error' => 'Pedido inválido.'], 400);
    }
    if ($targetId !== $userId && !attendanceCanViewTeam()) {
        $respond(['ok' => false, 'error' => 'Sem permissão.'], 403);
    }
    $rows = attendancePunchesBetween($targetId, $date, $date)[$date] ?? [];
    $punches = [];
    foreach ([1, 2, 3, 4] as $p) {
        $row = $rows[$p] ?? null;
        $punches[$p] = [
            'time' => $row ? substr((string) $row['punched_at'], 11, 5) : '',
            'justification' => (string) ($row['justification'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
        ];
    }
    $respond(['ok' => true, 'date' => $date, 'user_id' => $targetId, 'punches' => $punches]);
}

if ($action === 'save_day' && $isPost) {
    if (!attendanceCanManage()) {
        $respond(['ok' => false, 'error' => 'Sem permissão para corrigir picagens.'], 403);
    }
    $targetId = (int) ($_POST['user_id'] ?? 0);
    $date = attendanceValidDate($_POST['date'] ?? '');
    if (!$date || $targetId <= 0) {
        $respond(['ok' => false, 'error' => 'Pedido inválido.'], 400);
    }
    $values = [];
    $prev = -1;
    foreach ([1, 2, 3, 4] as $p) {
        $raw = trim((string) ($_POST['p' . $p] ?? ''));
        if ($raw === '') {
            $values[$p] = null;
            continue;
        }
        $min = attendanceTimeToMinutes($raw);
        if ($min === null) {
            $respond(['ok' => false, 'error' => 'Hora inválida em "' . ATTENDANCE_PERIODS[$p] . '".'], 422);
        }
        if ($min <= $prev) {
            $respond(['ok' => false, 'error' => 'As horas têm de ser crescentes (' . ATTENDANCE_PERIODS[$p] . ').'], 422);
        }
        $prev = $min;
        $values[$p] = $min;
    }

    $existing = attendancePunchesBetween($targetId, $date, $date)[$date] ?? [];
    $changes = [];
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM attendance_punches WHERE user_id = ? AND work_date = ? AND period = ?');
        $ins = $pdo->prepare('INSERT INTO attendance_punches (user_id, work_date, period, punched_at, source, justification, created_by, updated_by)
            VALUES (?, ?, ?, ?, \'manual\', ?, ?, ?)');
        // source antes de punched_at: o MySQL avalia o SET da esquerda para a direita.
        $upd = $pdo->prepare('UPDATE attendance_punches SET source = IF(punched_at = ?, source, \'manual\'), punched_at = ?, justification = ?, updated_by = ? WHERE id = ?');
        foreach ($values as $p => $min) {
            $justification = trim((string) ($_POST['j' . $p] ?? ''));
            $justification = $justification !== '' ? mb_substr($justification, 0, 1000) : null;
            $row = $existing[$p] ?? null;
            if ($min === null) {
                if ($row) {
                    $del->execute([$targetId, $date, $p]);
                    $changes[$p] = 'removed';
                }
                continue;
            }
            $stamp = $date . ' ' . attendanceMinutesToTime($min) . ':00';
            if ($row) {
                // Mesma hora ao minuto: mantem o registo original (segundos e origem web).
                if (substr((string) $row['punched_at'], 0, 16) === substr($stamp, 0, 16)) {
                    $stamp = (string) $row['punched_at'];
                }
                if ((string) $row['punched_at'] !== $stamp || (string) ($row['justification'] ?? '') !== (string) $justification) {
                    $upd->execute([$stamp, $stamp, $justification, $userId, (int) $row['id']]);
                    $changes[$p] = attendanceMinutesToTime($min);
                }
            } else {
                $ins->execute([$targetId, $date, $p, $stamp, $justification, $userId, $userId]);
                $changes[$p] = attendanceMinutesToTime($min);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $respond(['ok' => false, 'error' => 'Não foi possível guardar: ' . $e->getMessage()], 500);
    }
    if ($changes) {
        logAuditAction('attendance_day_edit', 'user', $targetId, ['date' => $date, 'changes' => $changes]);
    }
    $respond(['ok' => true, 'message' => $changes ? 'Registos do dia atualizados.' : 'Sem alterações.']);
}

$respond(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
