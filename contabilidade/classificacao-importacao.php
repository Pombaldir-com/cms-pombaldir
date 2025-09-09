<?php
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

$useDataTables = true;
$useDropzone = false;

$pdo = getPDO();
$stmt = $pdo->query('SELECT * FROM accounting_imports');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$csrfToken = generateCsrfToken();

require_once __DIR__ . '/../header.php';
?>
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
                    <?php $btnClass = $row['account'] ? 'btn-success' : 'btn-warning'; ?>
                    <td class="text-center">
                        <button type="button" class="btn btn-xs <?= $btnClass; ?> classify-row" data-id="<?= (int)$row['id']; ?>" data-account="<?= htmlspecialchars($row['account'] ?? ''); ?>" data-emitter="<?= htmlspecialchars($row['field_A'] ?? ''); ?>" data-acquirer="<?= htmlspecialchars($row['field_B'] ?? ''); ?>" data-doctype="<?= htmlspecialchars($row['field_D'] ?? ''); ?>">Classificar</button>
                        <a href="contabilidade/save-analysis.php?action=lines&id=<?= (int)$row['id']; ?>" class="btn btn-xs btn-info" target="_blank">Analisar</a>
                        <button type="button" class="btn btn-xs btn-danger remove-row" data-id="<?= (int)$row['id']; ?>"><i class="fa fa-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
    </div>
</div>
<script src="assets/js/classificacao_importacao.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>

