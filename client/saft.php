<?php
// Extranet do cliente: envio de SAF-T. Os utilizadores da empresa de
// contabilidade podem enviar o ficheiro SAF-T pela sua area reservada, mas
// apenas para a propria empresa associada a conta (accounting_entity_id da
// sessao de cliente) - o NIF do ficheiro tem de corresponder a essa empresa.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../contabilidade/functions.php';
require_once __DIR__ . '/../contabilidade/saft-envio-functions.php';

// A rota AJAX (?action=invoices) precisa de autenticacao/contexto de tenant
// mas nao do HTML da pagina, por isso e tratada antes de incluir header.php
// (que ja envia o documento HTML assim que e incluido).
startSession();
$tenantSlug = trim((string) ($_GET['tenant_slug'] ?? ($_SESSION['client_user_tenant_slug'] ?? '')));
if ($tenantSlug === '' || !ensureTenantCompanyBySlug($tenantSlug)) {
    http_response_code(404);
    exit('Tenant invalida.');
}
requireClientLogin($tenantSlug);
$clientUser = currentClientUser();
$entityId = (int) ($clientUser['accounting_entity_id'] ?? 0);
$entityNif = (string) ($clientUser['entity_nif'] ?? '');
$entityName = (string) ($clientUser['entity_name'] ?? '');

$pdo = getPDO();

if (!hasTable('accounting_saft_submissions')) {
    http_response_code(500);
    exit('A tabela accounting_saft_submissions ainda nao existe. Execute as migracoes.');
}

if (($_GET['action'] ?? '') === 'invoices') {
    header('Content-Type: application/json; charset=utf-8');
    $submissionId = (int) ($_GET['submission_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT accounting_entity_id FROM accounting_saft_submissions WHERE id = ? LIMIT 1');
    $stmt->execute([$submissionId]);
    $ownerEntityId = (int) ($stmt->fetchColumn() ?: 0);
    if ($ownerEntityId <= 0 || $ownerEntityId !== $entityId) {
        http_response_code(403);
        echo json_encode(['error' => 'Sem permissão.']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT invoice_no, invoice_type, invoice_status, invoice_date, customer_id, tax_payable, net_total, gross_total
         FROM accounting_saft_invoices WHERE submission_id = ? ORDER BY invoice_date ASC, id ASC'
    );
    $stmt->execute([$submissionId]);
    echo json_encode(['invoices' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
    exit;
}

$feedback = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }

    // Na extranet do cliente nao e permitido eliminar envios; um novo envio
    // para o mesmo periodo substitui automaticamente o anterior (ver
    // saftHandleUpload).
    $resolveEntity = function (string $fileNif) use ($entityId, $entityNif, $entityName): array {
        if (extractVatNumber($fileNif) !== extractVatNumber($entityNif)) {
            return [
                'entity' => null,
                'error' => 'O ficheiro pertence ao NIF ' . htmlspecialchars($fileNif) . ', diferente do NIF da sua empresa (' . htmlspecialchars($entityNif) . '). Só pode enviar o SAF-T da sua própria empresa.',
            ];
        }
        return ['entity' => ['id' => $entityId, 'nif' => $entityNif, 'name' => $entityName], 'error' => null];
    };

    $result = saftHandleUpload($pdo, $_FILES['saft_file'] ?? [], $resolveEntity, null);
    $feedback = $result['feedback'];
}

$csrfToken = generateCsrfToken();

$saftPeriodFilterSessionKey = 'saft_cliente_period_filter';
if (array_key_exists('filtro_ano', $_GET) || array_key_exists('filtro_mes', $_GET)) {
    $filterYear = (int) ($_GET['filtro_ano'] ?? 0);
    $filterMonth = (int) ($_GET['filtro_mes'] ?? 0);
    $_SESSION[$saftPeriodFilterSessionKey] = ['year' => $filterYear, 'month' => $filterMonth];
} else {
    $storedFilter = $_SESSION[$saftPeriodFilterSessionKey] ?? ['year' => 0, 'month' => 0];
    $filterYear = (int) ($storedFilter['year'] ?? 0);
    $filterMonth = (int) ($storedFilter['month'] ?? 0);
}

$monthNames = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
$currentYear = (int) date('Y');

$listConditions = ['s.accounting_entity_id = ?'];
$listParams = [$entityId];
if ($filterYear > 0) {
    $listConditions[] = 's.period_year = ?';
    $listParams[] = $filterYear;
}
if ($filterMonth > 0) {
    $listConditions[] = 's.period_month = ?';
    $listParams[] = $filterMonth;
}
$stmt = $pdo->prepare(
    'SELECT s.* FROM accounting_saft_submissions s
     WHERE ' . implode(' AND ', $listConditions) . '
     ORDER BY s.created_at DESC'
);
$stmt->execute($listParams);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$currentClientPage = 'saft';
$useDataTables = true;
require_once __DIR__ . '/header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Envio de SAF-T <small><?= htmlspecialchars($entityName); ?> (<?= htmlspecialchars($entityNif); ?>)</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($feedback['message']); ?>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-history"></i> Envios efetuados</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <style>
                    .dt-hidden-until-ready { display: none !important; }
                    .saft-filter-row {
                        padding: 6px 0 14px;
                        border-bottom: 1px solid #e6e9ed;
                        margin-bottom: 14px !important;
                    }
                    .saft-period-filter { gap: 22px; }
                    .saft-period-filter .saft-period-field { gap: 8px; }
                    .saft-period-filter .control-label { margin-bottom: 0; white-space: nowrap; }
                    .saft-period-filter select { min-width: 110px; }
                    .saft-upload-slot { display: flex; align-items: center; justify-content: center; }
                    .saft-paging-slot .pagination { justify-content: flex-end; }
                    .saft-actions {
                        display: flex !important;
                        align-items: center;
                        justify-content: flex-end;
                        gap: 6px;
                    }
                    .saft-icon-btn {
                        display: inline-flex !important;
                        align-items: center;
                        justify-content: center;
                        box-sizing: border-box;
                        width: 26px !important;
                        height: 26px !important;
                        padding: 0 !important;
                        line-height: 1;
                        margin: 0 !important;
                        vertical-align: middle;
                    }
                </style>
                <div id="saftPeriodFilterWrapper" class="dt-hidden-until-ready">
                    <form method="get" class="d-flex align-items-center flex-wrap saft-period-filter">
                        <div class="d-flex align-items-center saft-period-field">
                            <label class="control-label">Ano</label>
                            <select class="form-control input-sm" name="filtro_ano" onchange="this.form.submit()">
                                <option value="0" <?= $filterYear === 0 ? 'selected' : ''; ?>>Todos</option>
                                <?php for ($y = $currentYear; $y >= $currentYear - 5; $y--): ?>
                                    <option value="<?= $y; ?>" <?= $y === $filterYear ? 'selected' : ''; ?>><?= $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="d-flex align-items-center saft-period-field">
                            <label class="control-label">Mês</label>
                            <select class="form-control input-sm" name="filtro_mes" onchange="this.form.submit()">
                                <option value="0" <?= $filterMonth === 0 ? 'selected' : ''; ?>>Todos</option>
                                <?php foreach ($monthNames as $num => $label): ?>
                                    <option value="<?= $num; ?>" <?= $num === $filterMonth ? 'selected' : ''; ?>><?= htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <noscript><button type="submit" class="btn btn-default btn-sm">Filtrar</button></noscript>
                    </form>
                </div>
                <div id="saftUploadWrapper" class="dt-hidden-until-ready">
                    <form method="post" enctype="multipart/form-data" id="saft-upload-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="upload">
                        <input type="file" name="saft_file" accept=".xml,.zip,.gz" id="saft-file-input" style="display: none;">
                        <button type="button" class="btn btn-primary btn-sm" id="saft-upload-trigger">
                            <i class="fa fa-upload"></i> Enviar SAF-T
                        </button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table id="saft-submissions-table" class="table table-striped jambo_table">
                        <thead>
                            <tr>
                                <th>Data de envio</th>
                                <th>Período</th>
                                <th>Ficheiro</th>
                                <th>Tamanho</th>
                                <th>Estado</th>
                                <th>Resposta AT</th>
                                <th>Valores extraídos</th>
                                <th class="text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <tr>
                                <td data-order="<?= htmlspecialchars($submission['created_at']); ?>"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $submission['created_at']))); ?></td>
                                <td data-order="<?= (int) $submission['period_year'] * 100 + (int) $submission['period_month']; ?>">
                                    <?= htmlspecialchars(($monthNames[(int) $submission['period_month']] ?? $submission['period_month']) . ' ' . $submission['period_year']); ?>
                                </td>
                                <td><?= htmlspecialchars($submission['original_filename']); ?></td>
                                <td data-order="<?= (int) $submission['file_size']; ?>">
                                    <?php
                                        $size = (int) $submission['file_size'];
                                        echo $size >= 1048576
                                            ? htmlspecialchars(number_format($size / 1048576, 1, ',', '.')) . ' MB'
                                            : htmlspecialchars(number_format(max(1, round($size / 1024)), 0, ',', '.')) . ' KB';
                                    ?>
                                </td>
                                <td>
                                    <?php
                                        $status = (string) ($submission['status'] ?? 'registado');
                                        $statusClass = $status === 'enviado' ? 'label-success'
                                            : ($status === 'erro' ? 'label-danger'
                                            : ($status === 'teste' ? 'label-warning' : 'label-default'));
                                    ?>
                                    <span class="label <?= $statusClass; ?>"><?= htmlspecialchars(ucfirst($status)); ?></span>
                                </td>
                                <td>
                                    <?php
                                        $atCode = trim((string) ($submission['at_response_code'] ?? ''));
                                        $atDetails = [];
                                        if ($atCode !== '') {
                                            $atDetails[] = 'Código: ' . $atCode;
                                        }
                                        if (trim((string) ($submission['at_id_ficheiro'] ?? '')) !== '') {
                                            $atDetails[] = 'Ficheiro AT n.º ' . trim((string) $submission['at_id_ficheiro']);
                                        }
                                        if (trim((string) ($submission['at_total_faturas'] ?? '')) !== '') {
                                            $atDetails[] = 'Faturas: ' . trim((string) $submission['at_total_faturas'])
                                                . ' | Créditos: ' . trim((string) ($submission['at_total_creditos'] ?? '—'))
                                                . ' | Débitos: ' . trim((string) ($submission['at_total_debitos'] ?? '—'));
                                        }
                                        $atTooltipParts = [];
                                        if (trim((string) ($submission['at_warning'] ?? '')) !== '') {
                                            $atTooltipParts[] = 'Aviso: ' . trim((string) $submission['at_warning']);
                                        }
                                        if (trim((string) ($submission['at_errors'] ?? '')) !== '') {
                                            $atTooltipParts[] = 'Erros: ' . trim((string) $submission['at_errors']);
                                        }
                                    ?>
                                    <?php if ($atDetails): ?>
                                        <small<?= $atTooltipParts ? ' title="' . htmlspecialchars(implode("\n", $atTooltipParts), ENT_QUOTES) . '"' : ''; ?>>
                                            <?= htmlspecialchars(implode(' · ', $atDetails)); ?>
                                            <?php if ($atTooltipParts): ?><i class="fa fa-info-circle text-warning"></i><?php endif; ?>
                                        </small>
                                    <?php elseif ($atTooltipParts): ?>
                                        <small class="text-danger" title="<?= htmlspecialchars(implode("\n", $atTooltipParts), ENT_QUOTES); ?>">
                                            <?= htmlspecialchars(mb_strimwidth(implode(' ', $atTooltipParts), 0, 80, '…')); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $extractionError = trim((string) ($submission['saft_extraction_error'] ?? ''));
                                        $numberOfEntries = $submission['saft_number_of_entries'] ?? null;
                                    ?>
                                    <?php if ($extractionError !== ''): ?>
                                        <small class="text-danger" title="<?= htmlspecialchars($extractionError, ENT_QUOTES); ?>">
                                            <i class="fa fa-exclamation-triangle"></i> Falha na extração
                                        </small>
                                    <?php elseif ($numberOfEntries !== null): ?>
                                        <small>
                                            <?= (int) $numberOfEntries; ?> faturas
                                            · Débito: <?= htmlspecialchars(number_format((float) ($submission['saft_total_debit'] ?? 0), 2, ',', '.')); ?>
                                            · Crédito: <?= htmlspecialchars(number_format((float) ($submission['saft_total_credit'] ?? 0), 2, ',', '.')); ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right" style="white-space: nowrap;">
                                    <div class="saft-actions">
                                        <?php if (($submission['saft_number_of_entries'] ?? null) !== null): ?>
                                        <button type="button" class="btn btn-xs btn-default saft-icon-btn saft-view-invoices"
                                            title="Ver faturas"
                                            data-submission-id="<?= (int) $submission['id']; ?>"
                                            data-entity-name="<?= htmlspecialchars($entityName, ENT_QUOTES); ?>"
                                            data-period="<?= htmlspecialchars(($monthNames[(int) $submission['period_month']] ?? '') . ' ' . $submission['period_year'], ENT_QUOTES); ?>">
                                            <i class="fa fa-list"></i>
                                        </button>
                                        <?php endif; ?>
                                        <a class="btn btn-xs btn-default saft-icon-btn" href="<?= htmlspecialchars(BASE_URL . ltrim((string) $submission['file_path'], '/')); ?>" download="<?= htmlspecialchars($submission['original_filename'], ENT_QUOTES); ?>" title="Transferir">
                                            <i class="fa fa-download"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="saft-invoices-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-list"></i> Faturas extraídas do SAF-T</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" style="padding: 0;">
                <div id="saft-invoices-modal-summary" class="d-flex flex-wrap justify-content-between align-items-center" style="padding: 12px 20px; background: #f7f9fb; border-bottom: 1px solid #e6e9ed; font-size: 13px; color: #73879c;"></div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm" style="margin-bottom: 0;">
                        <thead style="background: #f7f9fb;">
                            <tr>
                                <th style="padding: 10px 20px;">N.º Fatura</th>
                                <th>Tipo</th>
                                <th>Data</th>
                                <th>Cliente</th>
                                <th class="text-right">Total s/IVA</th>
                                <th class="text-right">IVA</th>
                                <th class="text-right" style="padding-right: 20px;">Total c/IVA</th>
                            </tr>
                        </thead>
                        <tbody id="saft-invoices-modal-body">
                            <tr><td colspan="7" class="text-center text-muted" style="padding: 30px;"><i class="fa fa-spinner fa-spin"></i> A carregar…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && $.fn.DataTable) {
        $('#saft-submissions-table').DataTable({
            language: { url: 'vendors/datatables.net/i18n/pt-PT.json' },
            order: [[0, 'desc']],
            columnDefs: [{ targets: -1, orderable: false }],
            dom: "<'row mb-2 align-items-center saft-filter-row'" +
                    "<'col-sm-12 col-md-2'l>" +
                    "<'col-sm-12 col-md-4 saft-period-slot'>" +
                    "<'col-sm-12 col-md-2 saft-upload-slot'>" +
                    "<'col-sm-12 col-md-4'f>" +
                 ">" +
                 "rt" +
                 "<'row mt-2 align-items-center'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7 saft-paging-slot'p>>",
            initComplete: function () {
                var $dtWrapper = $('#saft-submissions-table').closest('.dt-container');
                $('#saftPeriodFilterWrapper').appendTo($dtWrapper.find('.saft-period-slot')).removeClass('dt-hidden-until-ready');
                $('#saftUploadWrapper').appendTo($dtWrapper.find('.saft-upload-slot')).removeClass('dt-hidden-until-ready');
            }
        });
    }

    var saftUploadTrigger = document.getElementById('saft-upload-trigger');
    var saftFileInput = document.getElementById('saft-file-input');
    if (saftUploadTrigger && saftFileInput) {
        saftUploadTrigger.addEventListener('click', function () {
            saftFileInput.click();
        });
        saftFileInput.addEventListener('change', function () {
            if (saftFileInput.files.length) {
                document.getElementById('saft-upload-form').submit();
            }
        });
    }

    document.querySelectorAll('.saft-view-invoices').forEach(function (button) {
        button.addEventListener('click', function () {
            var submissionId = button.getAttribute('data-submission-id');
            var body = document.getElementById('saft-invoices-modal-body');
            var summary = document.getElementById('saft-invoices-modal-summary');
            body.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding: 30px;"><i class="fa fa-spinner fa-spin"></i> A carregar…</td></tr>';
            summary.innerHTML = '<strong>' + escapeHtml(button.getAttribute('data-entity-name') || '') + '</strong>'
                + '<span>' + escapeHtml(button.getAttribute('data-period') || '') + '</span>';
            if (window.jQuery) {
                $('#saft-invoices-modal').modal('show');
            }
            fetch('<?= htmlspecialchars(BASE_URL . 't/' . rawurlencode($tenantSlug) . '/cliente/saft'); ?>?action=invoices&submission_id=' + encodeURIComponent(submissionId))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    var invoices = data.invoices || [];
                    if (!invoices.length) {
                        body.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding: 30px;">Sem faturas extraídas.</td></tr>';
                        return;
                    }
                    var totalNet = 0, totalTax = 0, totalGross = 0;
                    body.innerHTML = invoices.map(function (inv) {
                        totalNet += parseFloat(inv.net_total) || 0;
                        totalTax += parseFloat(inv.tax_payable) || 0;
                        totalGross += parseFloat(inv.gross_total) || 0;
                        return '<tr>'
                            + '<td style="padding-left: 20px;">' + escapeHtml(inv.invoice_no || '') + '</td>'
                            + '<td>' + escapeHtml(inv.invoice_type || '') + '</td>'
                            + '<td>' + formatDate(inv.invoice_date) + '</td>'
                            + '<td>' + escapeHtml(inv.customer_id || '') + '</td>'
                            + '<td class="text-right">' + formatMoney(inv.net_total) + '</td>'
                            + '<td class="text-right">' + formatMoney(inv.tax_payable) + '</td>'
                            + '<td class="text-right" style="padding-right: 20px;">' + formatMoney(inv.gross_total) + '</td>'
                            + '</tr>';
                    }).join('');
                    summary.innerHTML += '<span><strong>' + invoices.length + '</strong> faturas &nbsp;·&nbsp; Total c/IVA: <strong>' + formatMoney(totalGross) + '</strong></span>';
                })
                .catch(function () {
                    body.innerHTML = '<tr><td colspan="7" class="text-center text-danger" style="padding: 30px;">Erro ao carregar faturas.</td></tr>';
                });
        });
    });

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function formatMoney(value) {
        var num = parseFloat(value);
        if (isNaN(num)) { return '—'; }
        return num.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatDate(value) {
        if (!value) { return '—'; }
        var parts = String(value).split('-');
        if (parts.length === 3) {
            return parts[2] + '/' + parts[1] + '/' + parts[0];
        }
        return escapeHtml(value);
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
