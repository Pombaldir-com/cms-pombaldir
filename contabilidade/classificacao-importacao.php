<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$useDataTables = true;
$useDropzone = false;

$pdo = getPDO();
$importType = (int)($_GET['import_type'] ?? 1);

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

                        <button type="button" class="btn btn-xs <?= $btnClass; ?> classify-row" data-id="<?= (int)$row['id']; ?>" data-iva6="<?= htmlspecialchars($row['account_iva6'] ?? ''); ?>" data-iva13="<?= htmlspecialchars($row['account_iva13'] ?? ''); ?>" data-iva23="<?= htmlspecialchars($row['account_iva23'] ?? ''); ?>" data-novat="<?= htmlspecialchars($row['account_novat'] ?? ''); ?>" data-amt-iva6="<?= $amtIva6; ?>" data-amt-iva13="<?= $amtIva13; ?>" data-amt-iva23="<?= $amtIva23; ?>" data-req-novat="<?= $needsNovat ? 1 : 0; ?>" data-emitter="<?= htmlspecialchars($row['field_A'] ?? ''); ?>" data-acquirer="<?= htmlspecialchars($row['field_B'] ?? ''); ?>" data-doctype="<?= htmlspecialchars($row['field_D'] ?? ''); ?>">Classificar</button>

                        <a href="contabilidade/save-analysis.php?action=lines&id=<?= (int)$row['id']; ?>&csrf_token=<?= urlencode($csrfToken); ?>" class="btn btn-xs btn-info" target="_blank">Analisar</a>
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
<script src="assets/js/classificacao_importacao.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>

