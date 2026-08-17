<?php
// Temporary diagnostic probe: inspect the raw extracted QR/OCR fields stored
// on an accounting_imports row (and its linked efatura_documents row, if
// any), to trace where an incorrect IVA/base value came from.
// DELETE THIS FILE after the investigation is done.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
if (((int) ($user['role'] ?? 3)) !== 1) {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas administradores.']);
    exit;
}

$pdo = getPDO();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$docRef = trim((string) ($_GET['doc'] ?? ''));
$nif = trim((string) ($_GET['nif'] ?? ''));

if ($id <= 0 && $docRef === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Indica id ou doc (field_G).']);
    exit;
}

$where = [];
$params = [];
if ($id > 0) {
    $where[] = 'id = ?';
    $params[] = $id;
}
if ($docRef !== '') {
    $where[] = 'field_G LIKE ?';
    $params[] = '%' . $docRef . '%';
}
if ($nif !== '') {
    $where[] = 'field_A = ?';
    $params[] = $nif;
}

$sql = 'SELECT id, field_A, field_B, field_C, field_D, field_F, field_G, field_H,
               field_I1, field_I2, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8,
               field_M, field_N, field_O, field_Q, field_R,
               account, cost_center, cab_id, filename, import_type, dte_add
        FROM accounting_imports
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY id DESC LIMIT 10';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$efaturaLinkReady = accountingImportsEfaturaLinkReady();
$efaturaStmt = $efaturaLinkReady
    ? $pdo->prepare('SELECT id, invoice_no, issuer_vat, net_total, tax_payable, gross_total, raw_payload_json FROM efatura_documents WHERE id = ? LIMIT 1')
    : null;
$linkLookupStmt = $efaturaLinkReady
    ? $pdo->prepare('SELECT efatura_document_id FROM accounting_imports WHERE id = ? LIMIT 1')
    : null;

$result = [];
foreach ($rows as $row) {
    $entry = $row;
    $entry['efatura_document'] = null;
    if ($linkLookupStmt !== null) {
        $linkLookupStmt->execute([(int) $row['id']]);
        $efaturaDocId = (int) ($linkLookupStmt->fetchColumn() ?: 0);
        if ($efaturaDocId > 0 && $efaturaStmt !== null) {
            $efaturaStmt->execute([$efaturaDocId]);
            $efaturaRow = $efaturaStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($efaturaRow !== null && is_string($efaturaRow['raw_payload_json'] ?? null)) {
                $decoded = json_decode($efaturaRow['raw_payload_json'], true);
                $efaturaRow['raw_payload_json'] = json_last_error() === JSON_ERROR_NONE ? $decoded : $efaturaRow['raw_payload_json'];
            }
            $entry['efatura_document'] = $efaturaRow;
        }
    }
    $result[] = $entry;
}

echo json_encode([
    'ok' => true,
    'count' => count($result),
    'rows' => $result,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
