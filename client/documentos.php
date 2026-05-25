<?php
$currentClientPage = 'documentos';
$useDataTables = true;
require_once __DIR__ . '/header.php';
$clientUser = currentClientUser();
$docs = getClientAccountingDocuments((int) ($clientUser['accounting_entity_id'] ?? 0), 500);
?>
<div class="x_panel">
    <div class="x_title">
        <h2><i class="fa fa-files-o"></i> Documentos Contabilisticos</h2>
        <div class="clearfix"></div>
    </div>
    <div class="x_content">
        <table class="table table-striped datatable" data-order-column="0" data-order-dir="desc">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Data</th>
                    <th>Documento</th>
                    <th>Emitente</th>
                    <th>Adquirente</th>
                    <th>Total</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($docs as $doc): ?>
                <tr>
                    <td><?= (int) ($doc['id'] ?? 0); ?></td>
                    <td><?= htmlspecialchars((string) ($doc['date'] ?? '')); ?></td>
                    <td><?= htmlspecialchars((string) ($doc['file_name'] ?? '')); ?></td>
                    <td><?= htmlspecialchars((string) ($doc['field_A'] ?? '')); ?></td>
                    <td><?= htmlspecialchars((string) ($doc['field_B'] ?? '')); ?></td>
                    <td><?= htmlspecialchars((string) ($doc['total'] ?? '')); ?></td>
                    <td><?= htmlspecialchars((string) ($doc['status'] ?? '')); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
