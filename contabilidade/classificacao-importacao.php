<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$useDataTables = true;
$useDropzone = false;

$pdo = getPDO();
dropLegacyAccountColumns($pdo);
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

