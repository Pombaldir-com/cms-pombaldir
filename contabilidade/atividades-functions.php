<?php
// Helpers do registo de atividade (contabilidade/atividades.php), partilhados
// com o dashboard (widget "Ultimas atividades").

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

const ACTIVITY_EXTRA_FIELD_TYPES = [
    'none' => 'Sem campo adicional',
    'month' => 'Mês',
    'date' => 'Data',
    'text' => 'Texto',
    'number' => 'Número',
];


function activityUserLabel(?array $row): string {
    if (!$row) {
        return '';
    }
    $name = trim((string) ($row['name'] ?? ''));
    return $name !== '' ? $name : (string) ($row['username'] ?? '');
}

function activityInitials(string $name): string {
    $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $first = mb_substr($words[0] ?? '?', 0, 1);
    $second = isset($words[1]) ? mb_substr($words[1], 0, 1) : '';
    return mb_strtoupper($first . $second);
}

function formatActivityDateTime(string $value): string {
    $ts = strtotime($value);
    if ($ts === false) {
        return $value;
    }
    $months = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts) . ', ' . date('H:i', $ts);
}

function getActivityCategories(PDO $pdo, bool $onlyActive): array {
    $sql = 'SELECT id, title, extra_field_type, extra_field_label, asks_fixed_assets, is_active
            FROM accounting_activity_categories';
    if ($onlyActive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY title ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Observacoes vem de um editor contenteditable: manter apenas formatacao
 * basica e remover todos os atributos (evita XSS na vista do evento).
 */
function sanitizeActivityNotes(string $html): string {
    $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
    $html = strip_tags($html, '<p><br><div><b><strong><i><em><u><ul><ol><li><h3><h4><blockquote>');
    $html = preg_replace('#<(/?)(\w+)\b[^>]*>#', '<$1$2>', $html) ?? '';
    $plain = trim(str_replace("\xC2\xA0", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $plain === '' ? '' : trim($html);
}

/**
 * Valida o valor do campo adicional de uma linha consoante o tipo da categoria.
 * Devolve [valor normalizado, erro].
 */
function normalizeActivityExtraValue(string $type, string $raw, string $label): array {
    $raw = trim($raw);
    $label = $label !== '' ? $label : 'campo adicional';
    if ($type === 'none') {
        return ['', null];
    }
    if ($raw === '') {
        return ['', 'Preencha o ' . $label . '.'];
    }
    switch ($type) {
        case 'month':
            if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $raw)) {
                return ['', 'Mês inválido em ' . $label . '.'];
            }
            return [$raw, null];
        case 'date':
            $date = DateTime::createFromFormat('!Y-m-d', $raw);
            if (!$date || $date->format('Y-m-d') !== $raw) {
                return ['', 'Data inválida em ' . $label . '.'];
            }
            return [$raw, null];
        case 'number':
            $normalized = str_replace([' ', ','], ['', '.'], $raw);
            if (!is_numeric($normalized)) {
                return ['', 'Valor numérico inválido em ' . $label . '.'];
            }
            return [$normalized, null];
        default:
            return [mb_substr($raw, 0, 255), null];
    }
}

function formatActivityExtraValue(string $type, string $value): string {
    if ($value === '') {
        return '';
    }
    if ($type === 'month' && preg_match('/^(\d{4})-(\d{2})$/', $value, $m)) {
        $months = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
        return ($months[(int) $m[2] - 1] ?? $m[2]) . ' ' . $m[1];
    }
    if ($type === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return $value;
}


function hasAccountingActivityTables(): bool {
    return hasTable('accounting_activities')
        && hasTable('accounting_activity_lines')
        && hasTable('accounting_activity_categories');
}

/**
 * NOW() da base de dados. created_at e gravado pelo MySQL, que pode estar
 * noutro fuso que o PHP; comparar sempre com o relogio da BD.
 */
function getActivityDbNow(): string {
    static $now = null;
    if ($now === null) {
        $now = (string) getPDO()->query('SELECT NOW()')->fetchColumn();
    }
    return $now;
}

/**
 * Data relativa curta ("agora", "ha 5 min", "ha 3 h", "ontem, 14:20", "12 set 2026").
 */
function formatActivityRelative(string $value): string {
    $ts = strtotime($value);
    $nowTs = strtotime(getActivityDbNow());
    if ($ts === false || $nowTs === false) {
        return $value;
    }
    $diff = $nowTs - $ts;
    $day = date('Y-m-d', $ts);
    if ($diff < 60) {
        return 'agora';
    }
    if ($diff < 3600) {
        return 'há ' . (int) floor($diff / 60) . ' min';
    }
    if ($day === date('Y-m-d', $nowTs)) {
        return 'há ' . (int) floor($diff / 3600) . ' h';
    }
    if ($day === date('Y-m-d', strtotime('-1 day', $nowTs))) {
        return 'ontem, ' . date('H:i', $ts);
    }
    $full = formatActivityDateTime($value);
    return substr($full, 0, (int) strrpos($full, ','));
}

/**
 * Categorias (titulo + valor formatado) por evento, indexadas pelo id do evento.
 */
function getActivityLinesSummary(PDO $pdo, array $activityIds): array {
    $activityIds = array_values(array_unique(array_filter(array_map('intval', $activityIds))));
    if (!$activityIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($activityIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT l.activity_id, l.value, l.fixed_assets_sold, c.title, c.extra_field_type
         FROM accounting_activity_lines l
         INNER JOIN accounting_activity_categories c ON c.id = l.category_id
         WHERE l.activity_id IN ($placeholders)
         ORDER BY l.activity_id, l.line_no"
    );
    $stmt->execute($activityIds);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $result[(int) $row['activity_id']][] = [
            'title' => (string) $row['title'],
            'value' => formatActivityExtraValue((string) $row['extra_field_type'], (string) $row['value']),
            'fixed_assets_sold' => $row['fixed_assets_sold'] === null ? null : (int) $row['fixed_assets_sold'],
        ];
    }
    return $result;
}

/**
 * Ultimas atividades ativas (dashboard).
 */
function getRecentActivities(PDO $pdo, int $limit = 8): array {
    $limit = max(1, min(50, $limit));
    $rows = $pdo->query(
        "SELECT a.id, a.uuid, a.code, a.client_name, a.client_code, a.client_nif, a.created_at,
                ru.name AS responsible_name, ru.username AS responsible_username
         FROM accounting_activities a
         LEFT JOIN users ru ON ru.id = a.responsible_user_id
         WHERE a.status = 1
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT $limit"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $lines = getActivityLinesSummary($pdo, array_column($rows, 'id'));
    foreach ($rows as &$row) {
        $row['lines'] = $lines[(int) $row['id']] ?? [];
        $row['responsible_label'] = activityUserLabel(['name' => $row['responsible_name'], 'username' => $row['responsible_username']]);
    }
    unset($row);
    return $rows;
}
