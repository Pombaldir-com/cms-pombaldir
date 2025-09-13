<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$pdo = getPDO();
$importType = (int)($_GET['import_type'] ?? 1);
// Reuse the existing session CSRF token so links can be authenticated
$csrfToken = generateCsrfToken();
dropLegacyAccountColumns($pdo);

$columns = [
    'id',
    'account',
    'field_A',
    'field_B',
    'field_C',
    'field_D',
    'field_E',
    'field_F',
    'field_G',
    'field_H',
    'field_I1',
    'field_I3',
    'field_I4',
    'field_I5',
    'field_I6',
    'field_I7',
    'field_I8',
    'field_N',
    'field_O',
    'field_Q',
    'field_R',
    'filename'
];

$draw = (int)($_GET['draw'] ?? 0);
$start = (int)($_GET['start'] ?? 0);
$length = (int)($_GET['length'] ?? 10);
if ($length <= 0) {
    $length = 10;
}

$totalStmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_imports WHERE import_type = :importType');
$totalStmt->execute([':importType' => $importType]);
$total = (int)$totalStmt->fetchColumn();

$colList = implode(', ', array_map(fn($c) => "`$c`", $columns));
$sql = "SELECT $colList FROM accounting_imports WHERE import_type = :importType ORDER BY id LIMIT :start, :length";
$stmt = $pdo->prepare($sql);
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':length', $length, PDO::PARAM_INT);
$stmt->bindValue(':importType', $importType, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as &$row) {
    $accounts = json_decode($row['account'] ?? '', true) ?: [];
    $row['account_iva6'] = $accounts['iva6'] ?? '';
    $row['account_iva13'] = $accounts['iva13'] ?? '';
    $row['account_iva23'] = $accounts['iva23'] ?? '';
    $row['account_novat'] = $accounts['novat'] ?? '';
    $amtIva6 = abs((float) str_replace(',', '.', $row['field_I3'] ?? 0));
    $amtIva13 = abs((float) str_replace(',', '.', $row['field_I5'] ?? 0));
    $amtIva23 = abs((float) str_replace(',', '.', $row['field_I7'] ?? 0));
    $needsNovat = !$amtIva6 && !$amtIva13 && !$amtIva23 && abs((float)($row['field_O'] ?? 0)) > 0;
    $row['btn_class'] = 'btn-secondary';
    $hasAnyAccount = (
        (int)($row['account_iva6'] ?? 0) > 0 ||
        (int)($row['account_iva13'] ?? 0) > 0 ||
        (int)($row['account_iva23'] ?? 0) > 0 ||
        (int)($row['account_novat'] ?? 0) > 0
    );
    $allAccounts = (
        ($amtIva6 == 0 || (int)($row['account_iva6'] ?? 0) > 0) &&
        ($amtIva13 == 0 || (int)($row['account_iva13'] ?? 0) > 0) &&
        ($amtIva23 == 0 || (int)($row['account_iva23'] ?? 0) > 0) &&
        (!$needsNovat || (int)($row['account_novat'] ?? 0) > 0)
    );
    $requires = $amtIva6 > 0 || $amtIva13 > 0 || $amtIva23 > 0 || $needsNovat;
    if (!$requires || $allAccounts) {
        $row['btn_class'] = 'btn-success';
    } elseif ($hasAnyAccount) {
        $row['btn_class'] = 'btn-warning';
    }
    $row['needs_novat'] = $needsNovat ? 1 : 0;
    $row['amt_iva6'] = $amtIva6;
    $row['amt_iva13'] = $amtIva13;
    $row['amt_iva23'] = $amtIva23;
}
unset($row);

$data = [];
foreach ($rows as $row) {
    $actionsParts = [];
    if ($importType === 1) {
        $actionsParts[] = '<button type="button" class="btn btn-xs ' . $row['btn_class'] . ' classify-row" '
            . 'data-id="' . (int)$row['id'] . '" '
            . 'data-iva6="' . htmlspecialchars($row['account_iva6']) . '" '
            . 'data-iva13="' . htmlspecialchars($row['account_iva13']) . '" '
            . 'data-iva23="' . htmlspecialchars($row['account_iva23']) . '" '
            . 'data-novat="' . htmlspecialchars($row['account_novat']) . '" '
            . 'data-amt-iva6="' . $row['amt_iva6'] . '" '
            . 'data-amt-iva13="' . $row['amt_iva13'] . '" '
            . 'data-amt-iva23="' . $row['amt_iva23'] . '" '
            . 'data-req-novat="' . $row['needs_novat'] . '" '
            . 'data-emitter="' . htmlspecialchars($row['field_A'] ?? '') . '" '
            . 'data-acquirer="' . htmlspecialchars($row['field_B'] ?? '') . '" '
            . 'data-doctype="' . htmlspecialchars($row['field_D'] ?? '') . '">Classificar</button>';
    }
    if ($importType === 2) {
        $actionsParts[] = '<a href="contabilidade/save-analysis.php?action=lines&id=' . (int)$row['id']
            . '&csrf_token=' . urlencode($csrfToken) . '" '
            . 'class="btn btn-xs btn-info" target="_blank">Analisar</a>';
    }
    $actionsParts[] = '<button type="button" class="btn btn-xs btn-danger remove-row" data-id="' . (int)$row['id'] . '"><i class="fa fa-trash"></i></button>';
    $actions = implode(' ', $actionsParts);
    $pdfLink = '<a href="' . htmlspecialchars($row['filename'] ?? '') . '" target="_blank" class="btn btn-xs btn-secondary"><i class="fa fa-file-pdf-o"></i></a>';
    $data[] = [
        htmlspecialchars($row['field_A'] ?? ''),
        htmlspecialchars($row['field_B'] ?? ''),
        htmlspecialchars($row['field_C'] ?? ''),
        htmlspecialchars($row['field_D'] ?? ''),
        htmlspecialchars($row['field_E'] ?? ''),
        htmlspecialchars($row['field_F'] ?? ''),
        htmlspecialchars($row['field_G'] ?? ''),
        htmlspecialchars($row['field_H'] ?? ''),
        htmlspecialchars($row['field_I1'] ?? ''),
        htmlspecialchars($row['field_I3'] ?? ''),
        htmlspecialchars($row['field_I4'] ?? ''),
        htmlspecialchars($row['field_I5'] ?? ''),
        htmlspecialchars($row['field_I6'] ?? ''),
        htmlspecialchars($row['field_I7'] ?? ''),
        htmlspecialchars($row['field_I8'] ?? ''),
        htmlspecialchars($row['field_N'] ?? ''),
        htmlspecialchars($row['field_O'] ?? ''),
        htmlspecialchars($row['field_Q'] ?? ''),
        htmlspecialchars($row['field_R'] ?? ''),
        $pdfLink,
        $actions
    ];
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $total,
    'recordsFiltered' => $total,
    'data' => $data,
], JSON_UNESCAPED_UNICODE);
