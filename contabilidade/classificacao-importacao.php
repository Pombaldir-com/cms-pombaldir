<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$useDataTables = true;
$useDropzone = false;

$pdo = getPDO();
$action = $_GET['action'] ?? '';
$importType = (int)($_GET['import_type'] ?? 1);

if ($action === 'data') {
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
        'filename',
        'line_items'
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
        $row['line_btn_class'] = 'btn-info';
        $lines = json_decode($row['line_items'] ?? '', true);
        if (is_array($lines) && count($lines) > 0) {
            $allFilled = true;
            foreach ($lines as $line) {
                if (trim($line['ERP'] ?? '') === '') {
                    $allFilled = false;
                    break;
                }
            }
            if ($allFilled) {
                $row['line_btn_class'] = 'btn-success';
            }
        }
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
            $actionsParts[] = '<button type="button" class="btn btn-xs ' . $row['line_btn_class'] . ' analyze-lines" data-id="' . (int)$row['id'] . '">Analisar</button>';
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
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM accounting_imports WHERE import_type = :type');
$stmt->execute([':type' => $importType]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $accounts = json_decode($row['account'] ?? '', true) ?: [];
    $row['account_iva6'] = $accounts['iva6'] ?? '';
    $row['account_iva13'] = $accounts['iva13'] ?? '';
    $row['account_iva23'] = $accounts['iva23'] ?? '';
    $row['account_novat'] = $accounts['novat'] ?? '';
}
unset($row);

$csrfToken = generateCsrfToken();

require_once __DIR__ . '/../header.php';
?>
<input type="hidden" id="import_type" value="<?= htmlspecialchars($importType); ?>">
<div class="row mb-3">
    <div class="col-12">
        <table id="classify-table" class="table table-striped">
            <thead>
                <tr>
                    <th class="text-start">Emitente</th>
                    <th class="text-start">Adquirente</th>
                    <th></th>
                    <th width="5%" class="text-middle">TP</th>
                    <th></th>
                    <th width="8%" class="text-middle">Data</th>
                    <th width="12%">Doc</th>
                    <th></th>
                    <th>País</th>
                    <th width="6%">Base 6%</th>
                    <th width="6%">IVA 6%</th>
                    <th width="6%">Base 13%</th>
                    <th width="6%">IVA 13%</th>
                    <th width="6%">Base 23%</th>
                    <th width="6%">IVA 23%</th>
                    <th width="5%">Total IVA</th>
                    <th width="5%">Total</th>
                    <th></th>
                    <th></th>
                    <th data-orderable="false" class="text-center">PDF</th>
                    <th data-orderable="false" width="14%" class="text-center">Ação</th>
                </tr>
            </thead>
            <tbody>

            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="text-start"><?= htmlspecialchars($row['field_A'] ?? ''); ?></td>
                    <td class="text-start"><?= htmlspecialchars($row['field_B'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_C'] ?? ''); ?></td>
                    <td class="text-middle"><?= htmlspecialchars($row['field_D'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_E'] ?? ''); ?></td>
                    <td class="text-middle"><?= htmlspecialchars($row['field_F'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_G'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_H'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_I1'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_I3'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_I4'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_I5'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_I6'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_I7'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_I8'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_N'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_O'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_Q'] ?? ''); ?></td>
                    <td><?= htmlspecialchars($row['field_R'] ?? ''); ?></td>
                    <td class="text-center"><a href="<?= htmlspecialchars($row['filename'] ?? ''); ?>" target="_blank" class="btn btn-xs btn-secondary"><i class="fa fa-file-pdf-o"></i></a></td>
                    <?php

                        // Only consider VAT columns to determine whether accounts are required.
                        $amtIva6 = abs((float) str_replace(',', '.', $row['field_I3'] ?? 0));
                        $amtIva13 = abs((float) str_replace(',', '.', $row['field_I5'] ?? 0));
                        $amtIva23 = abs((float) str_replace(',', '.', $row['field_I7'] ?? 0));
                        $hasIva6 = $amtIva6 > 0;
                        $hasIva13 = $amtIva13 > 0;
                        $hasIva23 = $amtIva23 > 0;

                        $total = abs((float)($row['field_O'] ?? 0));
                        $needsNovat = !$hasIva6 && !$hasIva13 && !$hasIva23 && $total > 0;

                        $allAccounts = (
                            ($amtIva6 == 0 || (int)($row['account_iva6'] ?? 0) > 0) &&
                            ($amtIva13 == 0 || (int)($row['account_iva13'] ?? 0) > 0) &&
                            ($amtIva23 == 0 || (int)($row['account_iva23'] ?? 0) > 0) &&
                            (!$needsNovat || (int)($row['account_novat'] ?? 0) > 0)
                        );
                        $requires = $hasIva6 || $hasIva13 || $hasIva23 || $needsNovat;
                        $hasAnyAccount = (
                            (int)($row['account_iva6'] ?? 0) > 0 ||
                            (int)($row['account_iva13'] ?? 0) > 0 ||
                            (int)($row['account_iva23'] ?? 0) > 0 ||
                            (int)($row['account_novat'] ?? 0) > 0
                        );
                        if (!$requires) {
                            $btnClass = 'btn-success';
                        } elseif ($allAccounts) {
                            $btnClass = 'btn-success';
                        } elseif ($hasAnyAccount) {
                            $btnClass = 'btn-warning';
                        } else {
                            $btnClass = 'btn-secondary';
                        }
                    ?>
                    <td class="text-center">

                        <?php if ($importType === 1): ?>
                        <button type="button" class="btn btn-xs <?= $btnClass; ?> classify-row" data-id="<?= (int)$row['id']; ?>" data-iva6="<?= htmlspecialchars($row['account_iva6'] ?? ''); ?>" data-iva13="<?= htmlspecialchars($row['account_iva13'] ?? ''); ?>" data-iva23="<?= htmlspecialchars($row['account_iva23'] ?? ''); ?>" data-novat="<?= htmlspecialchars($row['account_novat'] ?? ''); ?>" data-amt-iva6="<?= $amtIva6; ?>" data-amt-iva13="<?= $amtIva13; ?>" data-amt-iva23="<?= $amtIva23; ?>" data-req-novat="<?= $needsNovat ? 1 : 0; ?>" data-emitter="<?= htmlspecialchars($row['field_A'] ?? ''); ?>" data-acquirer="<?= htmlspecialchars($row['field_B'] ?? ''); ?>" data-doctype="<?= htmlspecialchars($row['field_D'] ?? ''); ?>">Classificar</button>
                        <?php endif; ?>

                        <?php if ($importType === 2): ?>
                        <button type="button" class="btn btn-xs btn-info analyze-lines" data-id="<?= (int)$row['id']; ?>">Analisar</button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-xs btn-danger remove-row" data-id="<?= (int)$row['id']; ?>"><i class="fa fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>

            </tbody>
        </table>
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
</div>
</div>
<div class="modal fade" id="classifyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Classificar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="classify-form">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">IVA 6%</label>
                        <input type="number" class="form-control" name="iva6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">IVA 13%</label>
                        <input type="number" class="form-control" name="iva13">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">IVA 23%</label>
                        <input type="number" class="form-control" name="iva23">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Valor S IVA</label>
                        <input type="number" class="form-control" name="novat">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
</div>
</div>
</div>
<div class="modal fade" id="linesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Linhas do Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div id="linesContainer"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" id="confirmLinesBtn">Confirmar</button>
            </div>
        </div>
    </div>
</div>
<script src="assets/js/classificacao_importacao.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>

