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
                    <th width="8%">Total s/IVA</th>
                    <th width="8%">IVA 23%</th>
                    <th width="8%">Total IVA</th>
                    <th width="8%">Total</th>
                    <th></th>
                    <th></th>
                    <th data-orderable="false">Ações</th>
                    <th data-orderable="false">Classificar</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="text-start"><?= htmlspecialchars($row['field_A']); ?></td>
                    <td class="text-start"><?= htmlspecialchars($row['field_B']); ?></td>
                    <td><?= htmlspecialchars($row['field_C']); ?></td>
                    <td class="text-middle"><?= htmlspecialchars($row['field_D']); ?></td>
                    <td><?= htmlspecialchars($row['field_E']); ?></td>
                    <td class="text-middle"><?= htmlspecialchars($row['field_F']); ?></td>
                    <td><?= htmlspecialchars($row['field_G']); ?></td>
                    <td><?= htmlspecialchars($row['field_H']); ?></td>
                    <td><?= htmlspecialchars($row['field_I1']); ?></td>
                    <td><?= htmlspecialchars($row['field_I7']); ?></td>
                    <td><?= htmlspecialchars($row['field_I8']); ?></td>
                    <td><?= htmlspecialchars($row['field_N']); ?></td>
                    <td><?= htmlspecialchars($row['field_O']); ?></td>
                    <td><?= htmlspecialchars($row['field_Q']); ?></td>
                    <td><?= htmlspecialchars($row['field_R']); ?></td>
                    <td><a href="<?= htmlspecialchars($row['filename']); ?>" target="_blank" class="btn btn-xs btn-secondary">Ver PDF</a></td>
                    <td><button type="button" class="btn btn-xs btn-warning classify-row" data-emitter="<?= htmlspecialchars($row['field_A']); ?>" data-acquirer="<?= htmlspecialchars($row['field_B']); ?>" data-doctype="<?= htmlspecialchars($row['field_D']); ?>">Classificar</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
    </div>
</div>
<script src="assets/js/classificacao_importacao.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>

