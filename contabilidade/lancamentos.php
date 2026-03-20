<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
$currentUser = currentUser();
$isSuperAdmin = ((int) ($currentUser['role'] ?? 3)) === 1;
$canAccessLancamentos = $isSuperAdmin || userHasDepartmentPermission('ctb_lancamentos_aceder');
$canDeleteLocalImports = $isSuperAdmin || userHasDepartmentPermission('ctb_lancamentos_remover_local');

if (!$canAccessLancamentos) {
    http_response_code(403);
    exit('Sem permissao para aceder a Lancamentos.');
}

if (!isModuleActive('contabilidade')) {
    http_response_code(404);
    exit('Modulo nao ativo.');
}

$useDataTables = true;

$pdo = getPDO();
$companyDatabases = [];
try {
    $stmt = $pdo->query(
        "SELECT erp_database, MAX(CASE WHEN entity_type = 'acquirer' THEN name ELSE '' END) AS company_name
         FROM accounting_entities
         WHERE erp_database <> ''
         GROUP BY erp_database
         ORDER BY erp_database ASC"
    );
    $companyDatabases = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    $companyDatabases = [];
}

$selectedDatabase = '';
$lancamentosSelectionSessionKey = 'lancamentos_selected_database';
if (array_key_exists('empresa', $_GET)) {
    $selectedDatabase = trim((string) $_GET['empresa']);
    $_SESSION[$lancamentosSelectionSessionKey] = $selectedDatabase;
} elseif (isset($_SESSION[$lancamentosSelectionSessionKey])) {
    $selectedDatabase = trim((string) $_SESSION[$lancamentosSelectionSessionKey]);
} else {
    $selectedDatabase = trim((string) getSetting('erp_database', ''));
}

$efaturaTopbarSelector = [
    'enabled' => !empty($companyDatabases),
    'action' => BASE_URL . 'contabilidade/lancamentos',
    'selected_entity_id' => $selectedDatabase,
    'entities' => array_map(static function (array $dbRow): array {
        $dbValue = trim((string) ($dbRow['erp_database'] ?? ''));
        $companyName = trim((string) ($dbRow['company_name'] ?? ''));
        $companyCode = preg_replace('/^emp_/i', '', $dbValue);
        if ($companyCode === null || $companyCode === '') {
            $companyCode = $dbValue;
        }
        $label = $companyCode;
        if ($companyName !== '') {
            $label .= ' - ' . $companyName;
        }
        return [
            'value' => $dbValue,
            'label' => $label,
            'name' => $companyName,
        ];
    }, $companyDatabases),
];

if (($_GET['action'] ?? '') === 'data') {
    header('Content-Type: application/json; charset=utf-8');

    $draw = (int) ($_GET['draw'] ?? 1);
    $start = (int) ($_GET['start'] ?? 0);
    $length = (int) ($_GET['length'] ?? 20);
    if ($length <= 0) {
        $length = 20;
    }

    $database = trim((string) ($_GET['db'] ?? ''));
    if ($database === '') {
        $database = $selectedDatabase !== '' ? $selectedDatabase : trim((string) getSetting('erp_database', ''));
    }
    $year = trim((string) ($_GET['strCodExercicio'] ?? ''));
    if ($year === '') {
        $year = date('Y');
    }
    $diary = trim((string) ($_GET['intCodDiario'] ?? ''));
    $month = trim((string) ($_GET['intMes'] ?? ''));
    if ($month === '') {
        $month = date('n');
    }
    $docTypeFilter = strtoupper(trim((string) ($_GET['strAbrevTpDoc'] ?? '')));

    if ($database === '' || $diary === '') {
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    if ($baseUrl === '') {
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'URL do webservice ERP não configurada.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $endpoint = buildErpEndpointFromBase($baseUrl, 'contabilidade/movimentos');

    $query = [
        'db' => $database,
        'strCodExercicio' => $year,
        'intCodDiario' => $diary,
        'intMes' => $month,
        'limit' => $length,
        'offset' => $start,
    ];
    $query = array_merge($query, buildErpCompanyQueryParams($database));
    if ($docTypeFilter !== '') {
        $query['strAbrevTpDoc'] = $docTypeFilter;
    }

    $endpoint .= (strpos($endpoint, '?') === false ? '?' : '&') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $token = trim((string) getSetting('erp_token', ''));
    $headers = ['Accept: application/json'];
    if ($token !== '') {
        $headers[] = 'X-API-KEY: ' . $token;
    }

    $handle = curl_init($endpoint);
    if ($handle === false) {
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'Não foi possível contactar o webservice.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $error = curl_error($handle);
        curl_close($handle);
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'Erro ao contactar o webservice: ' . $error,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    curl_close($handle);

    if ($status >= 400) {
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'Webservice devolveu HTTP ' . $status . '.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'Resposta inválida do webservice.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $rows = [];
    if (isset($decoded['aaData']) && is_array($decoded['aaData'])) {
        $rows = $decoded['aaData'];
    }

    $allRows = $rows;
    $rows = $allRows;

    $data = [];
    foreach ($rows as $row) {
        $dateValue = (string) ($row['strData'] ?? '');
        $formattedDate = '';
        if ($dateValue !== '') {
            try {
                $dt = new DateTime($dateValue);
                $formattedDate = $dt->format('d-m');
            } catch (Throwable $e) {
                $formattedDate = $dateValue;
            }
        }

        $totalRaw = (string) ($row['fltFArchTotal'] ?? '');
        $totalFormatted = '';
        if ($totalRaw !== '') {
            $normalized = str_replace(',', '.', trim($totalRaw));
            if (is_numeric($normalized)) {
                $totalFormatted = number_format((float) $normalized, 2, '.', '');
            } else {
                $totalFormatted = $totalRaw;
            }
        }

        $data[] = [
            htmlspecialchars((string) ($row['intCodDiario'] ?? '')),
            htmlspecialchars($formattedDate),
            htmlspecialchars((string) ($row['intNum_Diario'] ?? '')),
            htmlspecialchars((string) ($row['strAbrevTpDoc'] ?? '')),
            htmlspecialchars((string) ($row['strNum_Doc'] ?? '')),
            htmlspecialchars((string) ($row['strFArchTaxPayer'] ?? '')),
            htmlspecialchars($totalFormatted),
        ];
    }

    $reportedTotal = (int) ($decoded['iTotalRecords'] ?? 0);
    $reportedFiltered = (int) ($decoded['iTotalDisplayRecords'] ?? 0);
    $inferredTotal = $start + count($allRows);
    $total = max($reportedTotal, $reportedFiltered, $inferredTotal);
    $filteredTotal = max($reportedFiltered, $reportedTotal, $inferredTotal);

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $total,
        'recordsFiltered' => $filteredTotal,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['action'] ?? '') === 'delete_local_import' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!$canDeleteLocalImports) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Sem permissao para remover registos locais.',
            'csrf_token' => generateCsrfToken(true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
    if ($csrfToken === '' || !validateCsrfToken($csrfToken)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Token CSRF invalido.',
            'csrf_token' => generateCsrfToken(true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cabId = trim((string) ($_POST['cab_id'] ?? ''));
    if ($cabId === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'cab_id em falta.',
            'csrf_token' => generateCsrfToken(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $stmt = $pdo->prepare('DELETE FROM accounting_imports WHERE cab_id = ?');
        $stmt->execute([$cabId]);
        $deletedRows = (int) $stmt->rowCount();
        if ($deletedRows > 0) {
            logAuditAction('delete_local_import_by_cab', 'accounting_imports', null, [
                'cab_id' => $cabId,
                'deleted_rows' => $deletedRows,
            ]);
        }

        echo json_encode([
            'success' => true,
            'deleted_rows' => $deletedRows,
            'csrf_token' => generateCsrfToken(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Erro ao remover registo local.',
            'csrf_token' => generateCsrfToken(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

require_once __DIR__ . '/../header.php';
$currentYear = (int) date('Y');
$yearOptions = [$currentYear, $currentYear - 1];
$csrfToken = generateCsrfToken();
?>
<style>
#lancamentos-table thead tr:nth-child(2) th.no-sort::before,
#lancamentos-table thead tr:nth-child(2) th.no-sort::after {
    display: none !important;
}

#lancamentos-table thead tr:nth-child(2) th.no-sort {
    cursor: default !important;
}
</style>
<div class="container-fluid">
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-book"></i> Lançamentos</h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div id="lancamentos-top-filters" class="d-none d-flex align-items-center">
                <input type="hidden" class="dt-filter" data-field="db" data-default="<?= htmlspecialchars($selectedDatabase); ?>" value="<?= htmlspecialchars($selectedDatabase); ?>">
                <select class="form-select dt-filter" style="min-width: 95px; height: 38px;" data-field="strCodExercicio" data-default="<?= htmlspecialchars((string) $currentYear); ?>">
                    <?php foreach ($yearOptions as $year): ?>
                        <option value="<?= htmlspecialchars((string) $year); ?>"><?= htmlspecialchars((string) $year); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <table id="lancamentos-table" class="table table-striped">
                <thead>
                    <tr>
                        <th width="10%" class="text-center"><input type="text" class="form-control dt-filter" style="height: 38px;" data-field="intCodDiario" data-default="" placeholder="Diário"></th>
                        <th width="10%" class="text-center"><input type="text" class="form-control dt-filter" style="height: 38px;" data-field="intMes" data-default="<?= htmlspecialchars(date('n')); ?>" placeholder="Mês"></th>
                        <th width="10%"></th>
                        <th width="15%"><input type="text" class="form-control dt-filter" style="height: 38px;" data-field="strAbrevTpDoc" data-default="" placeholder="Tipo Doc"></th>
                        <th></th>
                        <th></th>
                        <th></th>
                        <th width="6%"></th>
                    </tr>
                    <tr>
                        <th class="text-center">Diário</th>
                        <th class="text-center">Data</th>
                        <th class="text-center">Nº Diário</th>
                        <th>Tipo Doc</th>
                        <th class="no-sort">Nº Doc</th>
                        <th class="no-sort">NIF</th>
                        <th class="no-sort">Total</th>
                        <th class="text-center no-sort">PDF</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<?php
$classifyModalImportType = 1;
$classifyModalShowAiButtons = false;
$classifyModalTitle = 'Editar lançamento';
$classifyModalFooterRightHtml = $isSuperAdmin
    ? '<button type="button" class="btn btn-danger" id="lancamentoDeleteEditorBtn"><i class="fa fa-trash"></i> Eliminar lançamento</button>'
    : '';
require __DIR__ . '/partials/classify-modal.php';
?>

<div class="modal fade" id="lancamentoDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lancamentoDetailTitle">Detalhe do lançamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <h6 class="mb-2">Linhas do movimento</h6>
                    <div class="table-responsive">
                        <table class="table table-striped table-bordered mb-0">
                            <thead>
                                <tr>
                                    <th>Conta</th>
                                    <th>Descrição</th>
                                    <th class="text-end">Débito</th>
                                    <th class="text-end">Crédito</th>
                                    <th>NIF</th>
                                </tr>
                            </thead>
                            <tbody id="lancamentoDetailBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="table-responsive">
                    <h6 class="mb-2">Resumo centros de custo</h6>
                    <table class="table table-striped table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Conta/C.Custo</th>
                                <th class="text-end">%</th>
                                <th>N</th>
                            </tr>
                        </thead>
                        <tbody id="lancamentoCostCenterBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <?php if ($isSuperAdmin): ?>
                <button type="button" class="btn btn-danger" id="lancamentoDeleteBtn"><i class="fa fa-trash"></i> Eliminar lançamento</button>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<?php
$pageScripts = <<<'JS'
(function() {
    var tableEl = document.getElementById('lancamentos-table');
    if (!tableEl || typeof window.jQuery === 'undefined' || !jQuery.fn.DataTable) {
        return;
    }

    var storageKey = 'lancamentos_filters';
    var $filters = jQuery('.dt-filter');
    var detailModalEl = document.getElementById('lancamentoDetailModal');
    var detailBodyEl = document.getElementById('lancamentoDetailBody');
    var detailCostCenterBodyEl = document.getElementById('lancamentoCostCenterBody');
    var detailTitleEl = document.getElementById('lancamentoDetailTitle');
    var deleteBtnEl = document.getElementById('lancamentoDeleteBtn');
    var deleteEditorBtnEl = document.getElementById('lancamentoDeleteEditorBtn');
    var detailModal = (detailModalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function')
        ? new window.bootstrap.Modal(detailModalEl)
        : null;
    var canDeleteLancamento = window.lancamentosCanDelete === true;
    var currentDetailRow = null;
    var classifyModalEl = document.getElementById('classifyModal');
    var classifyModal = (classifyModalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function')
        ? new window.bootstrap.Modal(classifyModalEl)
        : null;
    var classifyModalTitleEl = document.getElementById('classifyModalLabel');
    var classifyFormEl = document.getElementById('classify-form');
    var classifyTableBodyEl = classifyFormEl ? classifyFormEl.querySelector('tbody') : null;
    var classifyDocumentPreviewFrame = document.getElementById('classifyDocumentPreviewFrame');
    var classifyDocumentPreviewEmpty = document.getElementById('classifyDocumentPreviewEmpty');
    var classifyDocumentOpenBtn = document.getElementById('classifyDocumentOpenBtn');
    var addVatLineBtn = document.getElementById('addVatLineBtn');
    var totalAccountInput = document.getElementById('totalAccountInput');
    var vatRateRowTemplate = document.getElementById('vatRateRowTemplate');
    var customRateRowTemplate = document.getElementById('customRateRowTemplate');
    var costCenterDistributionModalEl = document.getElementById('costCenterDistributionModal');
    var costCenterDistributionModal = (costCenterDistributionModalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function')
        ? new window.bootstrap.Modal(costCenterDistributionModalEl)
        : null;
    var costCenterDistributionDialogEl = costCenterDistributionModalEl ? costCenterDistributionModalEl.querySelector('.modal-dialog') : null;
    var costCenterDistributionHeaderEl = costCenterDistributionModalEl ? costCenterDistributionModalEl.querySelector('.modal-header') : null;
    var costCenterDistributionDocumentInfoEl = document.getElementById('ccDistributionDocumentInfo');
    var costCenterDistributionDateInfoEl = document.getElementById('ccDistributionDateInfo');
    var costCenterDistributionTypeInfoEl = document.getElementById('ccDistributionTypeInfo');
    var costCenterDistributionEmitterInfoEl = document.getElementById('ccDistributionEmitterInfo');
    var costCenterDistributionAccountInfoEl = document.getElementById('ccDistributionAccountInfo');
    var costCenterDistributionAccountLabelInfoEl = document.getElementById('ccDistributionAccountLabelInfo');
    var costCenterDistributionAmountInfoEl = document.getElementById('ccDistributionAmountInfo');
    var costCenterDistributionRateInfoEl = document.getElementById('ccDistributionRateInfo');
    var costCenterDistributionTableBody = document.getElementById('ccDistributionTableBody');
    var costCenterDistributionPercentAssignedEl = document.getElementById('ccDistributionPercentAssigned');
    var costCenterDistributionPercentRemainingEl = document.getElementById('ccDistributionPercentRemaining');
    var costCenterDistributionAmountRemainingEl = document.getElementById('ccDistributionAmountRemaining');
    var costCenterDistributionAddRowBtn = document.getElementById('ccDistributionAddRowBtn');
    var costCenterDistributionSaveBtn = document.getElementById('ccDistributionSaveBtn');
    var costCenterDistributionRowTemplate = document.getElementById('costCenterDistributionRowTemplate');
    var editorCurrentRow = null;
    var editorRateInputs = {};
    var editorRateData = {};
    var editorCostCenters = {};
    var editorCostCenterBreakdowns = {};
    var editorCostCenterCatalog = [];
    var editorCurrentDistributionRate = '';
    var editorDynamicRateCounter = 0;
    var editorPreviewObjectUrl = '';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseDecimalValue(value) {
        var raw = String(value || '').trim().replace(/\s+/g, '').replace(',', '.');
        if (raw === '') {
            return null;
        }
        var num = parseFloat(raw);
        return isNaN(num) ? null : num;
    }

    function formatDecimalValue(value) {
        var num = typeof value === 'number' ? value : parseDecimalValue(value);
        if (num === null || !isFinite(num)) {
            return '';
        }
        return num.toFixed(2);
    }

    function formatPercentValue(value) {
        var num = typeof value === 'number' ? value : parseDecimalValue(value);
        if (num === null || !isFinite(num)) {
            return '0.00';
        }
        return num.toFixed(2);
    }

    function normalizeRateKey(value) {
        var raw = String(value || '').trim();
        if (raw === '') {
            return '';
        }
        raw = raw.replace(',', '.');
        if (/%$/.test(raw)) {
            raw = raw.replace(/%$/, '').trim();
        }
        var num = parseFloat(raw);
        if (!isNaN(num) && isFinite(num)) {
            if (Math.abs(num - Math.round(num)) < 0.00001) {
                return String(Math.round(num));
            }
            return String(num);
        }
        return raw;
    }

    function getRateLabel(rateKey, fallbackLabel) {
        var fallback = String(fallbackLabel || '').trim();
        if (fallback !== '') {
            return fallback;
        }
        var normalized = normalizeRateKey(rateKey);
        if (normalized !== '' && /^-?\d+(\.\d+)?$/.test(normalized)) {
            return normalized + '%';
        }
        return normalized;
    }

    function getRateNumericValue(rateKey, label) {
        var candidates = [rateKey, label];
        for (var i = 0; i < candidates.length; i += 1) {
            var raw = String(candidates[i] || '').trim();
            if (raw === '') {
                continue;
            }
            var match = raw.match(/(\d+(?:[.,]\d+)?)\s*%?$/);
            if (!match) {
                continue;
            }
            var num = parseFloat(match[1].replace(',', '.'));
            if (!isNaN(num)) {
                return num;
            }
        }
        return null;
    }

    function isDefaultRate(rateKey) {
        return ['0', '6', '13', '23'].indexOf(String(rateKey || '')) !== -1;
    }

    function sanitizeText(value) {
        return String(value || '').trim();
    }

    function parseAmount(value) {
        var raw = String(value || '').trim().replace(',', '.');
        var num = parseFloat(raw);
        return isNaN(num) ? 0 : num;
    }

    function normalizeBool(value) {
        if (typeof value === 'boolean') {
            return value;
        }
        if (typeof value === 'number') {
            return value > 0;
        }
        var raw = String(value || '').trim().toLowerCase();
        if (raw === '') {
            return null;
        }
        if (raw === '1' || raw === 'true' || raw === 'sim' || raw === 'yes') {
            return true;
        }
        if (raw === '0' || raw === 'false' || raw === 'nao' || raw === 'não' || raw === 'no') {
            return false;
        }
        return null;
    }

    function extractHasDigitalAttachment(row) {
        if (!row || typeof row !== 'object') {
            return null;
        }
        var candidates = [
            'hasDigitalAttachment',
            'has_digital_attachment',
            'temAnexoDigital',
            'bitTemAnexoDigital',
            'hasAnexoDigital',
            'bitHasAnexoDigital',
            'hasAttachment',
            'bitHasAttachment',
            'anexoDigital',
            'hasAnexo',
            'temAnexo',
            'bitTemAnexo'
        ];
        for (var i = 0; i < candidates.length; i += 1) {
            if (Object.prototype.hasOwnProperty.call(row, candidates[i])) {
                return normalizeBool(row[candidates[i]]);
            }
        }
        return null;
    }

    function formatAmount(value) {
        var num = parseAmount(value);
        return num.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function lineDescription(line) {
        if (!line || typeof line !== 'object') {
            return '-';
        }
        var keys = ['strDescricao', 'descricao', 'strDescricaoConta', 'strContaDescricao', 'strDescConta', 'PC_Descricao', 'Rub_Descricao'];
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            if (line[key] !== undefined && line[key] !== null && String(line[key]).trim() !== '') {
                return String(line[key]).trim();
            }
        }
        return '-';
    }

    function getEditorRateKeys() {
        return Object.keys(editorRateInputs);
    }

    function resetEditorRows() {
        editorRateInputs = {};
        editorRateData = {};
        editorCostCenters = {};
        editorCostCenterBreakdowns = {};
        editorCurrentDistributionRate = '';
        if (classifyTableBodyEl) {
            classifyTableBodyEl.innerHTML = '';
        }
    }

    function revokeEditorPreviewObjectUrl() {
        if (editorPreviewObjectUrl) {
            window.URL.revokeObjectURL(editorPreviewObjectUrl);
            editorPreviewObjectUrl = '';
        }
    }

    function resetEditorPreview() {
        revokeEditorPreviewObjectUrl();
        if (classifyDocumentPreviewFrame) {
            classifyDocumentPreviewFrame.src = 'about:blank';
            classifyDocumentPreviewFrame.classList.add('d-none');
        }
        if (classifyDocumentPreviewEmpty) {
            classifyDocumentPreviewEmpty.style.display = 'flex';
        }
        if (classifyDocumentOpenBtn) {
            classifyDocumentOpenBtn.classList.add('d-none');
            classifyDocumentOpenBtn.setAttribute('href', '#');
        }
    }

    function setEditorPreviewBlob(blob, filename) {
        if (!blob) {
            resetEditorPreview();
            return;
        }
        revokeEditorPreviewObjectUrl();
        editorPreviewObjectUrl = window.URL.createObjectURL(blob);
        if (classifyDocumentPreviewFrame) {
            classifyDocumentPreviewFrame.src = editorPreviewObjectUrl;
            classifyDocumentPreviewFrame.classList.remove('d-none');
        }
        if (classifyDocumentPreviewEmpty) {
            classifyDocumentPreviewEmpty.style.display = 'none';
        }
        if (classifyDocumentOpenBtn) {
            classifyDocumentOpenBtn.classList.remove('d-none');
            classifyDocumentOpenBtn.setAttribute('href', editorPreviewObjectUrl);
            if (filename) {
                classifyDocumentOpenBtn.setAttribute('download', filename);
            }
        }
    }

    function loadEditorPreview(rowData) {
        resetEditorPreview();
        if (!rowData || rowData.hasDigitalAttachment !== true || !erpBaseUrl) {
            return;
        }
        var dbValue = getSelectedDatabase();
        if (!dbValue) {
            return;
        }
        jQuery.ajax({
            url: erpBaseUrl + '/anexosdigitais',
            method: 'GET',
            dataType: 'json',
            headers: getErpHeaders(),
            data: {
                db: dbValue,
                intTipoEntidade: 23,
                strChave1: String(rowData.strCodExercicio || ''),
                strChave2: String(rowData.intCodDiario || ''),
                strChave3: String(rowData.intMes || ''),
                intNumero: String(rowData.intNumDiario || '')
            }
        }).done(function(resp) {
            var anexos = Array.isArray(resp && resp.anexos) ? resp.anexos : [];
            if (!anexos.length || !anexos[0] || !anexos[0].Ficheiro) {
                return;
            }
            try {
                var blob = decodeBase64ToBlob(anexos[0].Ficheiro, 'application/pdf');
                var filename = anexos[0].strIdFicheiro || ('lancamento_' + String(rowData.intNumDiario || '') + '.pdf');
                setEditorPreviewBlob(blob, filename);
            } catch (e) {
                resetEditorPreview();
            }
        });
    }

    function setCostCenterFieldOptions(selectEl, selectedValue) {
        if (!selectEl) {
            return;
        }
        var selected = String(selectedValue || '').trim();
        var html = '<option value="">Selecione o centro de custo</option>';
        editorCostCenterCatalog.forEach(function(item) {
            var code = sanitizeText(item && (item.value || item.code || item.id || ''));
            if (code === '') {
                return;
            }
            var label = sanitizeText(item && (item.label || item.name || item.text || ''));
            if (label === '') {
                label = code;
            } else if (label.indexOf(code) !== 0) {
                label = code + ' - ' + label;
            }
            html += '<option value="' + escapeHtml(code) + '">' + escapeHtml(label) + '</option>';
        });
        if (selected !== '' && html.indexOf('value="' + escapeHtml(selected) + '"') === -1) {
            html += '<option value="' + escapeHtml(selected) + '">' + escapeHtml(selected) + '</option>';
        }
        selectEl.innerHTML = html;
        selectEl.value = selected;
    }

    function refreshCostCenterSummary(rateKey) {
        var info = editorRateInputs[rateKey];
        if (!info || !info.summaryEl) {
            return;
        }
        var rows = Array.isArray(editorCostCenterBreakdowns[rateKey]) ? editorCostCenterBreakdowns[rateKey] : [];
        if (!rows.length) {
            info.summaryEl.textContent = '';
            return;
        }
        info.summaryEl.textContent = rows.map(function(row) {
            return String(row.cost_center || '') + ' (' + formatPercentValue(row.percentage) + '%)';
        }).join(', ');
    }

    function recalculateIvaForRate(rateKey) {
        var info = editorRateInputs[rateKey];
        var data = editorRateData[rateKey];
        if (!info || !data || !info.ivaEl) {
            return;
        }
        var rateValue = getRateNumericValue(rateKey, data.label);
        if (rateValue === null) {
            return;
        }
        var baseValue = parseDecimalValue(info.baseEl ? info.baseEl.value : '');
        if (baseValue === null) {
            info.ivaEl.value = '';
            return;
        }
        info.ivaEl.value = formatDecimalValue(baseValue * (rateValue / 100));
    }

    function registerEditorRow(rowEl, rateKey) {
        var info = {
            rowEl: rowEl,
            labelEl: rowEl.querySelector('.rate-label-field'),
            rateStaticEl: rowEl.querySelector('.rate-label-static'),
            baseEl: rowEl.querySelector('.base-field'),
            ivaEl: rowEl.querySelector('.iva-field'),
            ivaAccountEl: rowEl.querySelector('.iva-account-field'),
            generalAccountEl: rowEl.querySelector('.general-account-field'),
            costCenterEl: rowEl.querySelector('.cost-center-field'),
            costCenterBtnEl: rowEl.querySelector('.cost-center-distribution-btn'),
            summaryEl: rowEl.querySelector('.cost-center-distribution-summary')
        };
        editorRateInputs[rateKey] = info;
        if (info.baseEl) {
            info.baseEl.addEventListener('input', function() {
                editorRateData[rateKey].base = info.baseEl.value;
                recalculateIvaForRate(rateKey);
            });
        }
        if (info.ivaEl) {
            info.ivaEl.addEventListener('input', function() {
                editorRateData[rateKey].iva = info.ivaEl.value;
            });
        }
        if (info.ivaAccountEl) {
            info.ivaAccountEl.addEventListener('input', function() {
                editorRateData[rateKey].iva_account = info.ivaAccountEl.value;
            });
        }
        if (info.generalAccountEl) {
            info.generalAccountEl.addEventListener('input', function() {
                editorRateData[rateKey].general_account = info.generalAccountEl.value;
            });
        }
        if (info.labelEl) {
            info.labelEl.addEventListener('input', function() {
                editorRateData[rateKey].label = info.labelEl.value;
                recalculateIvaForRate(rateKey);
            });
        }
        if (info.costCenterEl) {
            setCostCenterFieldOptions(info.costCenterEl, editorCostCenters[rateKey] || '');
            info.costCenterEl.classList.add('d-none');
        }
        if (info.costCenterBtnEl) {
            info.costCenterBtnEl.addEventListener('click', function() {
                openCostCenterDistributionModal(rateKey);
            });
        }
        var removeBtn = rowEl.querySelector('.remove-rate-row');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                delete editorRateInputs[rateKey];
                delete editorRateData[rateKey];
                delete editorCostCenters[rateKey];
                delete editorCostCenterBreakdowns[rateKey];
                rowEl.remove();
            });
        }
        refreshCostCenterSummary(rateKey);
        return info;
    }

    function createEditorRateRow(rateKey, isCustom) {
        var template = isCustom ? customRateRowTemplate : vatRateRowTemplate;
        if (!template || !classifyTableBodyEl) {
            return null;
        }
        var fragment = template.content ? template.content.cloneNode(true) : null;
        if (!fragment) {
            return null;
        }
        var rowEl = fragment.querySelector('tr');
        if (!rowEl) {
            return null;
        }
        rowEl.setAttribute('data-rate', rateKey);
        rowEl.setAttribute('data-custom-rate', isCustom ? '1' : '0');
        classifyTableBodyEl.appendChild(rowEl);
        return registerEditorRow(rowEl, rateKey);
    }

    function findLineAccount(lines, lineNo) {
        if (!Array.isArray(lines) || !lines.length) {
            return '';
        }
        var wanted = String(lineNo || '').trim();
        for (var i = 0; i < lines.length; i += 1) {
            var line = lines[i];
            var candidate = String(line && (line.intNumlinha || line.intNumLinha || '')).trim();
            if (candidate !== '' && candidate === wanted) {
                return String(line && line.strConta ? line.strConta : '').trim();
            }
        }
        return '';
    }

    function getCostCenterRowsByLine(rowData) {
        var map = {};
        var items = Array.isArray(rowData && rowData.mov_cc) ? rowData.mov_cc : [];
        items.forEach(function(item) {
            var key = String(item && (item.intNumLinha || item.intNumlinha || '')).trim();
            if (!key) {
                return;
            }
            if (!map[key]) {
                map[key] = [];
            }
            map[key].push({
                cost_center: sanitizeText(item && (item.strConta_CCusto || item.cost_center || '')),
                percentage: formatPercentValue(item && (item.fltPercentagem || item.percentage || '0')),
                value: formatDecimalValue(item && (item.fltValor || item.value || '0'))
            });
        });
        return map;
    }

    function inferLineComponent(line) {
        var explicit = sanitizeText(line && line.line_component);
        if (explicit !== '') {
            return explicit.toLowerCase();
        }
        var description = lineDescription(line).toUpperCase();
        if (description.indexOf('IVA') !== -1) {
            return 'iva';
        }
        if (description.indexOf('TOTAL') !== -1) {
            return 'total';
        }
        var debCre = sanitizeText(line && line.strDeb_Cre).toUpperCase();
        var nif = sanitizeText(line && line.strNumContrib);
        if (debCre === 'C' && nif !== '') {
            return 'total';
        }
        return 'base';
    }

    function inferRateInfo(line) {
        var description = lineDescription(line);
        var rawRate = sanitizeText(line && line.tax_rate);
        if (rawRate === '') {
            var match = description.match(/(\d+(?:[.,]\d+)?)\s*%/);
            if (match) {
                rawRate = match[1];
            }
        }
        var rateKey = normalizeRateKey(rawRate);
        if (rateKey === '') {
            rateKey = '0';
        }
        return {
            key: rateKey,
            label: getRateLabel(rateKey, rawRate !== '' ? rawRate + '%' : '')
        };
    }

    function buildFallbackRateLabel(line, component) {
        var description = sanitizeText(lineDescription(line));
        if (description !== '' && description !== '-') {
            return description;
        }
        return component === 'iva' ? 'IVA' : 'Linha';
    }

    function resolveMovementEditorRateKey(line, component, rateInfo, usedKeys, state) {
        var lineNo = String(line && (line.intNumlinha || line.intNumLinha || '')).trim();
        var baseKey = sanitizeText(rateInfo && rateInfo.key);
        if (baseKey === '') {
            baseKey = 'line_' + (lineNo || String(Object.keys(usedKeys).length + 1));
        }
        var normalizedBaseKey = baseKey;
        if (!usedKeys[normalizedBaseKey]) {
            usedKeys[normalizedBaseKey] = {
                component: component,
                lineNo: lineNo
            };
            return normalizedBaseKey;
        }
        if (state && state.rates && state.rates[normalizedBaseKey]) {
            var existingRate = state.rates[normalizedBaseKey];
            if (component === 'base' && sanitizeText(existingRate.general_account) === '') {
                return normalizedBaseKey;
            }
            if (component === 'iva' && sanitizeText(existingRate.iva_account) === '') {
                return normalizedBaseKey;
            }
        }
        var existing = usedKeys[normalizedBaseKey];
        if (existing.component === component && existing.lineNo === lineNo) {
            return normalizedBaseKey;
        }
        var candidate = normalizedBaseKey + '_' + (lineNo || component || 'row');
        var suffix = 2;
        while (usedKeys[candidate]) {
            candidate = normalizedBaseKey + '_' + (lineNo || component || 'row') + '_' + suffix;
            suffix += 1;
        }
        usedKeys[candidate] = {
            component: component,
            lineNo: lineNo
        };
        return candidate;
    }

    function buildEditorStateFromMovement(rowData) {
        var state = {
            rates: {},
            costCenters: {},
            costCenterBreakdowns: {},
            totalAccount: ''
        };
        var usedKeys = {};
        var ccRowsByLine = getCostCenterRowsByLine(rowData);
        var lines = Array.isArray(rowData && rowData.linhas) ? rowData.linhas : [];
        lines.forEach(function(line) {
            var component = inferLineComponent(line);
            if (component === 'total') {
                state.totalAccount = sanitizeText(line && line.strConta);
                return;
            }
            var rateInfo = inferRateInfo(line);
            var resolvedKey = resolveMovementEditorRateKey(line, component, rateInfo, usedKeys, state);
            if (!state.rates[resolvedKey]) {
                state.rates[resolvedKey] = {
                    label: rateInfo.label || buildFallbackRateLabel(line, component),
                    base: '',
                    iva: '',
                    iva_account: '',
                    general_account: ''
                };
            }
            var entry = state.rates[resolvedKey];
            var amount = formatDecimalValue(line && line.fltValor ? line.fltValor : '');
            if (component === 'iva') {
                entry.iva = amount;
                entry.iva_account = sanitizeText(line && line.strConta);
            } else {
                entry.base = amount;
                entry.general_account = sanitizeText(line && line.strConta);
                var lineNo = String(line && (line.intNumlinha || line.intNumLinha || '')).trim();
                var ccRows = lineNo && ccRowsByLine[lineNo] ? ccRowsByLine[lineNo].slice() : [];
                if (ccRows.length) {
                    state.costCenterBreakdowns[resolvedKey] = ccRows;
                    state.costCenters[resolvedKey] = sanitizeText(ccRows[0].cost_center);
                }
            }
        });
        return state;
    }

    function populateEditorModal(rowData) {
        editorCurrentRow = rowData;
        currentDetailRow = rowData;
        resetEditorRows();
        var state = buildEditorStateFromMovement(rowData);
        editorRateData = state.rates;
        editorCostCenters = state.costCenters;
        editorCostCenterBreakdowns = state.costCenterBreakdowns;
        if (totalAccountInput) {
            totalAccountInput.value = state.totalAccount;
        }
        Object.keys(editorRateData).forEach(function(rateKey) {
            var isCustom = !isDefaultRate(rateKey);
            var info = createEditorRateRow(rateKey, isCustom);
            var data = editorRateData[rateKey];
            if (!info || !data) {
                return;
            }
            if (info.rateStaticEl) {
                info.rateStaticEl.textContent = getRateLabel(rateKey, data.label);
            }
            if (info.labelEl) {
                info.labelEl.value = data.label || '';
            }
            if (info.baseEl) {
                info.baseEl.value = data.base || '';
            }
            if (info.ivaEl) {
                info.ivaEl.value = data.iva || '';
            }
            if (info.ivaAccountEl) {
                info.ivaAccountEl.value = data.iva_account || '';
            }
            if (info.generalAccountEl) {
                info.generalAccountEl.value = data.general_account || '';
            }
            if (info.costCenterEl) {
                setCostCenterFieldOptions(info.costCenterEl, editorCostCenters[rateKey] || '');
            }
            refreshCostCenterSummary(rateKey);
        });
        if (classifyModalTitleEl) {
            classifyModalTitleEl.textContent = 'Editar lançamento - Doc. ' + sanitizeText(rowData && rowData.strNumDoc);
        }
        loadEditorPreview(rowData);
    }

    function decodeBase64ToBlob(base64Content, mimeType) {
        var cleanBase64 = String(base64Content || '').trim();
        var commaPos = cleanBase64.indexOf(',');
        if (commaPos !== -1) {
            cleanBase64 = cleanBase64.slice(commaPos + 1);
        }
        cleanBase64 = cleanBase64.replace(/\s+/g, '');
        var binary = window.atob(cleanBase64);
        var len = binary.length;
        var bytes = new Uint8Array(len);
        for (var i = 0; i < len; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }
        return new Blob([bytes], { type: mimeType || 'application/pdf' });
    }

    function downloadBlob(blob, filename) {
        var safeName = String(filename || 'anexo.pdf').trim();
        if (safeName === '') {
            safeName = 'anexo.pdf';
        }
        if (!/\.pdf$/i.test(safeName)) {
            safeName += '.pdf';
        }
        var link = document.createElement('a');
        var objectUrl = window.URL.createObjectURL(blob);
        link.href = objectUrl;
        link.download = safeName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(objectUrl);
    }

    function openDetailModal(rowData) {
        if (!detailBodyEl || !rowData) {
            return;
        }
        currentDetailRow = rowData;
        var docNo = String(rowData.strNumDoc || '').trim();
        var diaryNo = String(rowData.intNumDiario || '').trim();
        var title = 'Detalhe do lançamento';
        if (docNo !== '') {
            title += ' - Doc ' + docNo;
        }
        if (diaryNo !== '') {
            title += ' | Nº Diário ' + diaryNo;
        }
        if (detailTitleEl) {
            detailTitleEl.textContent = title;
        }

        var lines = Array.isArray(rowData.linhas) ? rowData.linhas.slice() : [];
        lines.sort(function(a, b) {
            var aNum = parseInt(a && a.intNumlinha ? a.intNumlinha : 0, 10);
            var bNum = parseInt(b && b.intNumlinha ? b.intNumlinha : 0, 10);
            if (isNaN(aNum)) {
                aNum = 0;
            }
            if (isNaN(bNum)) {
                bNum = 0;
            }
            return aNum - bNum;
        });
        if (!lines.length) {
            detailBodyEl.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Sem linhas do movimento.</td></tr>';
        } else {
            var html = '';
            lines.forEach(function(line) {
                var debCre = String(line && line.strDeb_Cre ? line.strDeb_Cre : '').toUpperCase();
                var value = line && line.fltValor !== undefined ? line.fltValor : '';
                var debit = debCre === 'D' ? formatAmount(value) : '';
                var credit = debCre === 'C' ? formatAmount(value) : '';
                html += '<tr>'
                    + '<td>' + escapeHtml(line && line.strConta ? line.strConta : '') + '</td>'
                    + '<td>' + escapeHtml(lineDescription(line)) + '</td>'
                    + '<td class="text-end">' + escapeHtml(debit) + '</td>'
                    + '<td class="text-end">' + escapeHtml(credit) + '</td>'
                    + '<td>' + escapeHtml(line && line.strNumContrib ? line.strNumContrib : '') + '</td>'
                    + '</tr>';
            });
            detailBodyEl.innerHTML = html;
        }

        if (detailCostCenterBodyEl) {
            var movCc = Array.isArray(rowData.mov_cc) ? rowData.mov_cc.slice() : [];
            movCc.sort(function(a, b) {
                var aLine = parseInt(a && a.intNumLinha ? a.intNumLinha : 0, 10);
                var bLine = parseInt(b && b.intNumLinha ? b.intNumLinha : 0, 10);
                var aCc = parseInt(a && a.intNumLinha_CC ? a.intNumLinha_CC : 0, 10);
                var bCc = parseInt(b && b.intNumLinha_CC ? b.intNumLinha_CC : 0, 10);
                if (isNaN(aLine)) {
                    aLine = 0;
                }
                if (isNaN(bLine)) {
                    bLine = 0;
                }
                if (aLine !== bLine) {
                    return aLine - bLine;
                }
                if (isNaN(aCc)) {
                    aCc = 0;
                }
                if (isNaN(bCc)) {
                    bCc = 0;
                }
                return aCc - bCc;
            });

            if (!movCc.length) {
                detailCostCenterBodyEl.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Sem centros de custo.</td></tr>';
            } else {
                var costCenterHtml = '';
                movCc.forEach(function(item) {
                    var conta = findLineAccount(lines, item && item.intNumLinha ? item.intNumLinha : '');
                    var ccusto = String(item && item.strConta_CCusto ? item.strConta_CCusto : '').trim();
                    var contaCcusto = conta !== '' ? conta + ' / ' + ccusto : ccusto;
                    var percentagem = item && item.fltPercentagem !== undefined ? formatAmount(item.fltPercentagem) : '';
                    var natureza = String(item && item.strDeb_Cre ? item.strDeb_Cre : '').trim().toUpperCase();
                    costCenterHtml += '<tr>'
                        + '<td>' + escapeHtml(contaCcusto) + '</td>'
                        + '<td class="text-end">' + escapeHtml(percentagem) + '</td>'
                        + '<td>' + escapeHtml(natureza) + '</td>'
                        + '</tr>';
                });
                detailCostCenterBodyEl.innerHTML = costCenterHtml;
            }
        }

        if (detailModal) {
            detailModal.show();
        }
    }

    function loadCostCenterCatalogForDocument() {
        var dbValue = getSelectedDatabase();
        if (!dbValue) {
            editorCostCenterCatalog = [];
            return jQuery.Deferred().resolve([]).promise();
        }
        return jQuery.ajax({
            url: 'contabilidade/classificacao-importacao/cost-centers',
            method: 'GET',
            dataType: 'json',
            data: {
                db: dbValue,
                doc_date: editorCurrentRow && editorCurrentRow.strData ? editorCurrentRow.strData : ''
            }
        }).then(function(resp) {
            editorCostCenterCatalog = Array.isArray(resp && resp.rows) ? resp.rows : [];
            getEditorRateKeys().forEach(function(rateKey) {
                var info = editorRateInputs[rateKey];
                if (info && info.costCenterEl) {
                    setCostCenterFieldOptions(info.costCenterEl, editorCostCenters[rateKey] || '');
                }
            });
            return editorCostCenterCatalog;
        }, function() {
            editorCostCenterCatalog = [];
            return [];
        });
    }

    function clampCostCenterDistributionDialogPosition(left, top) {
        if (!costCenterDistributionModalEl || !costCenterDistributionDialogEl) {
            return { left: 0, top: 0 };
        }
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var dialogRect = costCenterDistributionDialogEl.getBoundingClientRect();
        var dialogWidth = dialogRect.width || costCenterDistributionDialogEl.offsetWidth || 0;
        var dialogHeight = dialogRect.height || costCenterDistributionDialogEl.offsetHeight || 0;
        var minVisibleWidth = Math.min(Math.max(120, dialogWidth * 0.2), dialogWidth);
        var minVisibleHeight = Math.min(72, dialogHeight);
        var minLeft = Math.min(0, viewportWidth - dialogWidth);
        var maxLeft = Math.max(minLeft, viewportWidth - minVisibleWidth);
        var minTop = Math.min(0, viewportHeight - dialogHeight);
        var maxTop = Math.max(minTop, viewportHeight - minVisibleHeight);
        return {
            left: Math.min(Math.max(left, minLeft), maxLeft),
            top: Math.min(Math.max(top, minTop), maxTop)
        };
    }

    function setCostCenterDistributionDialogPosition(left, top) {
        if (!costCenterDistributionDialogEl) {
            return;
        }
        var position = clampCostCenterDistributionDialogPosition(left, top);
        costCenterDistributionDialogEl.style.left = position.left + 'px';
        costCenterDistributionDialogEl.style.top = position.top + 'px';
    }

    function fixCostCenterDistributionDialogSize() {
        if (!costCenterDistributionDialogEl) {
            return;
        }
        var rect = costCenterDistributionDialogEl.getBoundingClientRect();
        var width = rect.width || costCenterDistributionDialogEl.offsetWidth || 0;
        var height = rect.height || costCenterDistributionDialogEl.offsetHeight || 0;
        if (width > 0 && !costCenterDistributionDialogEl.style.width) {
            costCenterDistributionDialogEl.style.width = width + 'px';
        }
        if (height > 0 && !costCenterDistributionDialogEl.style.height) {
            costCenterDistributionDialogEl.style.height = height + 'px';
        }
    }

    function resetCostCenterDistributionDialogPosition() {
        if (!costCenterDistributionDialogEl) {
            return;
        }
        fixCostCenterDistributionDialogSize();
        costCenterDistributionDialogEl.style.position = 'fixed';
        costCenterDistributionDialogEl.style.margin = '0';
        costCenterDistributionDialogEl.style.transform = 'none';
        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var dialogWidth = costCenterDistributionDialogEl.offsetWidth || 0;
        var dialogHeight = costCenterDistributionDialogEl.offsetHeight || 0;
        var left = Math.max((viewportWidth - dialogWidth) / 2, 0);
        var top = Math.max(Math.min((viewportHeight - dialogHeight) / 2, 24), 16);
        setCostCenterDistributionDialogPosition(left, top);
    }

    function initializeCostCenterDistributionDrag() {
        if (!costCenterDistributionModalEl || !costCenterDistributionDialogEl || !costCenterDistributionHeaderEl) {
            return;
        }
        if (costCenterDistributionModalEl.__dragInitialized) {
            return;
        }

        var dragState = {
            active: false,
            offsetX: 0,
            offsetY: 0
        };

        function getPointerPoint(event) {
            if (event.touches && event.touches.length) {
                return event.touches[0];
            }
            if (event.changedTouches && event.changedTouches.length) {
                return event.changedTouches[0];
            }
            return event;
        }

        function shouldIgnoreDragStart(target) {
            if (!target || !(target instanceof Element)) {
                return false;
            }
            return Boolean(target.closest('button, .btn, a, input, select, textarea, label'));
        }

        function startDrag(event) {
            if (event.type === 'mousedown' && event.button !== 0) {
                return;
            }
            if (shouldIgnoreDragStart(event.target)) {
                return;
            }
            var point = getPointerPoint(event);
            var rect = costCenterDistributionDialogEl.getBoundingClientRect();
            dragState.active = true;
            dragState.offsetX = point.clientX - rect.left;
            dragState.offsetY = point.clientY - rect.top;
            costCenterDistributionModalEl.classList.add('is-dragging');
            if (event.cancelable) {
                event.preventDefault();
            }
        }

        function moveDrag(event) {
            if (!dragState.active) {
                return;
            }
            var point = getPointerPoint(event);
            setCostCenterDistributionDialogPosition(
                point.clientX - dragState.offsetX,
                point.clientY - dragState.offsetY
            );
            if (event.cancelable) {
                event.preventDefault();
            }
        }

        function stopDrag() {
            if (!dragState.active) {
                return;
            }
            dragState.active = false;
            costCenterDistributionModalEl.classList.remove('is-dragging');
        }

        costCenterDistributionHeaderEl.addEventListener('mousedown', startDrag);
        costCenterDistributionHeaderEl.addEventListener('touchstart', startDrag, { passive: false });
        document.addEventListener('mousemove', moveDrag);
        document.addEventListener('touchmove', moveDrag, { passive: false });
        document.addEventListener('mouseup', stopDrag);
        document.addEventListener('touchend', stopDrag);
        document.addEventListener('touchcancel', stopDrag);
        costCenterDistributionModalEl.addEventListener('shown.bs.modal', function() {
            resetCostCenterDistributionDialogPosition();
        });
        costCenterDistributionModalEl.addEventListener('hidden.bs.modal', function() {
            stopDrag();
        });
        window.addEventListener('resize', function() {
            if (!costCenterDistributionModalEl.classList.contains('show')) {
                return;
            }
            var rect = costCenterDistributionDialogEl.getBoundingClientRect();
            setCostCenterDistributionDialogPosition(rect.left, rect.top);
        });

        costCenterDistributionModalEl.__dragInitialized = true;
    }

    function updateCostCenterDistributionTotals() {
        var totalPercentage = 0;
        var totalAmount = 0;
        var rateInfo = editorRateInputs[editorCurrentDistributionRate];
        if (rateInfo && rateInfo.baseEl) {
            totalAmount = parseDecimalValue(rateInfo.baseEl.value) || 0;
        }
        var assignedAmount = 0;
        if (costCenterDistributionTableBody) {
            jQuery(costCenterDistributionTableBody).find('tr').each(function() {
                var percentage = parseDecimalValue(jQuery(this).find('.cc-distribution-percentage').val()) || 0;
                totalPercentage += percentage;
                var value = totalAmount * (percentage / 100);
                assignedAmount += value;
                jQuery(this).find('.cc-distribution-value').text(formatAmount(value));
            });
        }
        if (costCenterDistributionPercentAssignedEl) {
            costCenterDistributionPercentAssignedEl.textContent = totalPercentage.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (costCenterDistributionPercentRemainingEl) {
            costCenterDistributionPercentRemainingEl.textContent = (100 - totalPercentage).toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }
        if (costCenterDistributionAmountRemainingEl) {
            costCenterDistributionAmountRemainingEl.textContent = formatAmount(totalAmount - assignedAmount);
        }
    }

    function addCostCenterDistributionRow(rowData) {
        if (!costCenterDistributionRowTemplate || !costCenterDistributionTableBody) {
            return;
        }
        var fragment = costCenterDistributionRowTemplate.content ? costCenterDistributionRowTemplate.content.cloneNode(true) : null;
        if (!fragment) {
            return;
        }
        var rowEl = fragment.querySelector('tr');
        if (!rowEl) {
            return;
        }
        var selectEl = rowEl.querySelector('.cc-distribution-code');
        var inputEl = rowEl.querySelector('.cc-distribution-percentage');
        setCostCenterFieldOptions(selectEl, rowData && rowData.cost_center ? rowData.cost_center : '');
        if (inputEl) {
            inputEl.value = rowData && rowData.percentage ? formatPercentValue(rowData.percentage) : '';
            inputEl.addEventListener('input', updateCostCenterDistributionTotals);
        }
        var removeBtn = rowEl.querySelector('.cc-distribution-remove-row');
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                rowEl.remove();
                updateCostCenterDistributionTotals();
            });
        }
        costCenterDistributionTableBody.appendChild(rowEl);
        updateCostCenterDistributionTotals();
    }

    function openCostCenterDistributionModal(rateKey) {
        if (!costCenterDistributionModal) {
            return;
        }
        editorCurrentDistributionRate = rateKey;
        var info = editorRateInputs[rateKey];
        var label = editorRateData[rateKey] && editorRateData[rateKey].label ? editorRateData[rateKey].label : getRateLabel(rateKey, '');
        if (costCenterDistributionDocumentInfoEl) {
            costCenterDistributionDocumentInfoEl.value = sanitizeText(editorCurrentRow && editorCurrentRow.strNumDoc);
        }
        if (costCenterDistributionDateInfoEl) {
            costCenterDistributionDateInfoEl.value = sanitizeText(editorCurrentRow && editorCurrentRow.strData);
        }
        if (costCenterDistributionTypeInfoEl) {
            costCenterDistributionTypeInfoEl.value = sanitizeText(editorCurrentRow && editorCurrentRow.strAbrevTpDoc);
        }
        if (costCenterDistributionEmitterInfoEl) {
            costCenterDistributionEmitterInfoEl.value = sanitizeText(editorCurrentRow && editorCurrentRow.strFArchTaxPayer);
        }
        if (costCenterDistributionAccountInfoEl) {
            costCenterDistributionAccountInfoEl.value = sanitizeText(info && info.generalAccountEl ? info.generalAccountEl.value : '');
        }
        if (costCenterDistributionAccountLabelInfoEl) {
            costCenterDistributionAccountLabelInfoEl.value = 'Base ' + label;
        }
        if (costCenterDistributionAmountInfoEl) {
            costCenterDistributionAmountInfoEl.value = formatAmount(info && info.baseEl ? info.baseEl.value : 0);
        }
        if (costCenterDistributionRateInfoEl) {
            costCenterDistributionRateInfoEl.value = label;
        }
        if (costCenterDistributionTableBody) {
            costCenterDistributionTableBody.innerHTML = '';
        }
        loadCostCenterCatalogForDocument().always(function() {
            var rows = Array.isArray(editorCostCenterBreakdowns[rateKey]) ? editorCostCenterBreakdowns[rateKey] : [];
            if (!rows.length && editorCostCenters[rateKey]) {
                rows = [{ cost_center: editorCostCenters[rateKey], percentage: '100.00', value: '' }];
            }
            if (!rows.length) {
                addCostCenterDistributionRow(null);
            } else {
                rows.forEach(function(row) {
                    addCostCenterDistributionRow(row);
                });
            }
            costCenterDistributionModal.show();
        });
    }

    initializeCostCenterDistributionDrag();

    function loadFilters() {
        if (!window.localStorage) {
            return {};
        }
        try {
            var raw = localStorage.getItem(storageKey);
            if (!raw) {
                return {};
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (e) {
            return {};
        }
    }

    function buildMovementDocumentFromEditor(rowData) {
        var lines = [];
        getEditorRateKeys().forEach(function(rateKey) {
            var data = editorRateData[rateKey] || {};
            var label = sanitizeText(data.label || getRateLabel(rateKey, ''));
            var baseValue = parseDecimalValue(editorRateInputs[rateKey] && editorRateInputs[rateKey].baseEl ? editorRateInputs[rateKey].baseEl.value : data.base);
            var ivaValue = parseDecimalValue(editorRateInputs[rateKey] && editorRateInputs[rateKey].ivaEl ? editorRateInputs[rateKey].ivaEl.value : data.iva);
            var generalAccount = sanitizeText(editorRateInputs[rateKey] && editorRateInputs[rateKey].generalAccountEl ? editorRateInputs[rateKey].generalAccountEl.value : data.general_account);
            var ivaAccount = sanitizeText(editorRateInputs[rateKey] && editorRateInputs[rateKey].ivaAccountEl ? editorRateInputs[rateKey].ivaAccountEl.value : data.iva_account);
            var taxRate = getRateNumericValue(rateKey, label);
            var movCcRows = Array.isArray(editorCostCenterBreakdowns[rateKey]) ? editorCostCenterBreakdowns[rateKey] : [];
            if (generalAccount && baseValue !== null && Math.abs(baseValue) > 0.00001) {
                lines.push({
                    strConta: generalAccount,
                    fltValor: Math.abs(baseValue),
                    strDeb_Cre: 'D',
                    strDescricao: 'Doc ' + sanitizeText(rowData.strNumDoc) + ' - BASE ' + label,
                    tax_rate: taxRate !== null ? String(taxRate) : '',
                    mov_cc: movCcRows.map(function(item, index) {
                        return {
                            intNumLinha_CC: index + 1,
                            strConta_CCusto: sanitizeText(item.cost_center),
                            fltPercentagem: parseDecimalValue(item.percentage) || 0,
                            fltValor: parseDecimalValue(item.value) || 0,
                            strDeb_Cre: 'D'
                        };
                    })
                });
            }
            if (ivaAccount && ivaValue !== null && Math.abs(ivaValue) > 0.00001) {
                lines.push({
                    strConta: ivaAccount,
                    fltValor: Math.abs(ivaValue),
                    strDeb_Cre: 'D',
                    strDescricao: 'Doc ' + sanitizeText(rowData.strNumDoc) + ' - IVA ' + label,
                    tax_rate: taxRate !== null ? String(taxRate) : ''
                });
            }
        });
        var totalAccount = sanitizeText(totalAccountInput ? totalAccountInput.value : '');
        var totalValue = parseDecimalValue(rowData && rowData.fltFArchTotal ? rowData.fltFArchTotal : rowData.total);
        if (totalAccount && totalValue !== null) {
            lines.push({
                strConta: totalAccount,
                fltValor: Math.abs(totalValue),
                strDeb_Cre: 'C',
                strDescricao: 'Total - Doc ' + sanitizeText(rowData.strNumDoc),
                strNumContrib: sanitizeText(rowData.strFArchTaxPayer),
                intGrp_Terc: 1
            });
        }
        return {
            field_A: sanitizeText(rowData.strFArchTaxPayer),
            field_B: '',
            field_C: 'Lançamento editado ' + sanitizeText(rowData.strNumDoc),
            field_D: '',
            field_E: '',
            field_F: sanitizeText(rowData.strData),
            field_G: sanitizeText(rowData.strNumDoc),
            field_H: '',
            field_I1: '',
            field_I3: '',
            field_I4: '',
            field_I5: '',
            field_I6: '',
            field_I7: '',
            field_I8: '',
            field_N: '',
            field_O: totalValue !== null ? Math.abs(totalValue) : 0,
            field_R: '',
            docType: sanitizeText(rowData.strAbrevTpDoc),
            account: lines
        };
    }

    function buildMovementDocumentFromOriginalRow(rowData) {
        var ccRowsByLine = getCostCenterRowsByLine(rowData);
        var lines = (Array.isArray(rowData && rowData.linhas) ? rowData.linhas : []).map(function(line) {
            var lineNo = String(line && (line.intNumlinha || line.intNumLinha || '')).trim();
            var item = {
                strConta: sanitizeText(line && line.strConta),
                fltValor: Math.abs(parseDecimalValue(line && line.fltValor) || 0),
                strDeb_Cre: sanitizeText(line && line.strDeb_Cre).toUpperCase() || 'D',
                strDescricao: sanitizeText(lineDescription(line)),
                strNumContrib: sanitizeText(line && line.strNumContrib),
                intGrp_Terc: line && line.intGrp_Terc ? line.intGrp_Terc : undefined
            };
            var rateInfo = inferRateInfo(line);
            var taxRate = getRateNumericValue(rateInfo.key, rateInfo.label);
            if (taxRate !== null) {
                item.tax_rate = String(taxRate);
            }
            if (lineNo && ccRowsByLine[lineNo] && ccRowsByLine[lineNo].length) {
                item.mov_cc = ccRowsByLine[lineNo].map(function(ccRow, index) {
                    return {
                        intNumLinha_CC: index + 1,
                        strConta_CCusto: sanitizeText(ccRow.cost_center),
                        fltPercentagem: parseDecimalValue(ccRow.percentage) || 0,
                        fltValor: parseDecimalValue(ccRow.value) || 0,
                        strDeb_Cre: item.strDeb_Cre
                    };
                });
            }
            return item;
        });
        return {
            field_A: sanitizeText(rowData.strFArchTaxPayer),
            field_B: '',
            field_C: 'Reposição lançamento ' + sanitizeText(rowData.strNumDoc),
            field_D: '',
            field_E: '',
            field_F: sanitizeText(rowData.strData),
            field_G: sanitizeText(rowData.strNumDoc),
            field_H: '',
            field_I1: '',
            field_I3: '',
            field_I4: '',
            field_I5: '',
            field_I6: '',
            field_I7: '',
            field_I8: '',
            field_N: '',
            field_O: Math.abs(parseDecimalValue(rowData && rowData.fltFArchTotal ? rowData.fltFArchTotal : rowData.total) || 0),
            field_R: '',
            docType: sanitizeText(rowData.strAbrevTpDoc),
            account: lines
        };
    }

    function saveFilters(values) {
        if (!window.localStorage) {
            return;
        }
        try {
            localStorage.setItem(storageKey, JSON.stringify(values));
        } catch (e) {
            return;
        }
    }

    var stored = loadFilters();
    $filters.each(function() {
        var field = this.getAttribute('data-field');
        if (!field) {
            return;
        }
        if (stored[field] !== undefined) {
            this.value = stored[field];
            return;
        }
        var defaultValue = this.getAttribute('data-default');
        if (defaultValue !== null && defaultValue !== '') {
            this.value = defaultValue;
        }
    });

    var erpBaseUrl = (window.erpLancamentosBaseUrl || '').replace(/\/+$/, '');
    var erpToken = window.erpLancamentosToken || '';
    var localDeleteUrl = window.lancamentosDeleteLocalUrl || '';
    var localCsrfToken = window.lancamentosCsrfToken || '';
    var unavailablePdfRows = {};
    if (window.lancamentosSelectedDatabase) {
        $filters.each(function() {
            if (this.getAttribute('data-field') === 'db') {
                this.value = window.lancamentosSelectedDatabase;
            }
        });
    }

    function getErpHeaders() {
        return erpToken
            ? { 'X-API-KEY': erpToken, 'Accept': 'application/json' }
            : { 'Accept': 'application/json' };
    }

    function getSelectedDatabase() {
        var dbValue = '';
        $filters.each(function() {
            if (this.getAttribute('data-field') === 'db') {
                dbValue = String(this.value || '').trim();
            }
        });
        return dbValue;
    }

    function getLancamentoKey(row) {
        if (!row) {
            return '';
        }
        return [
            String(row.strCodExercicio || ''),
            String(row.intCodDiario || ''),
            String(row.intMes || ''),
            String(row.intNumDiario || '')
        ].join('|');
    }

    var table = jQuery(tableEl).DataTable({
        serverSide: true,
        processing: true,
        order: [[2, 'desc']],
        lengthMenu: [[20, 50, 100], [20, 50, 100]],
        pageLength: 20,
        columns: [
            { data: 'intCodDiario' },
            { data: 'formattedDate' },
            { data: 'intNumDiario' },
            { data: 'strAbrevTpDoc' },
            {
                data: 'strNumDoc',
                render: function(data, type, row) {
                    var value = data || '';
                    if (type !== 'display') {
                        return value;
                    }
                    if (Array.isArray(row && row.linhas) && row.linhas.length) {
                        return '<a href="#" class="js-open-lancamento-lines">' + escapeHtml(value) + '</a>';
                    }
                    return escapeHtml(value);
                }
            },
            { data: 'strFArchTaxPayer' },
            { data: 'total' },
            {
                data: null,
                render: function(data, type, row) {
                    if (type !== 'display') {
                        return '';
                    }
                    if (!row || !row.strCodExercicio || !row.intCodDiario || !row.intMes || !row.intNumDiario) {
                        return '';
                    }
                    if (row.hasDigitalAttachment === false) {
                        return '<span class="btn btn-xs btn-default disabled" title="Sem anexo digital">'
                            + '<i class="fa fa-file-pdf-o text-muted"></i>'
                            + '</span>';
                    }
                    if (unavailablePdfRows[getLancamentoKey(row)] === true) {
                        return '<span class="btn btn-xs btn-default disabled" title="Sem anexo digital">'
                            + '<i class="fa fa-file-pdf-o text-muted"></i>'
                            + '</span>';
                    }
                    return '<a href="#" class="btn btn-xs btn-default js-download-lancamento-pdf" title="Download PDF">'
                        + '<i class="fa fa-file-pdf-o text-danger"></i>'
                        + '</a>';
                }
            }
        ],
        columnDefs: [
            { targets: [0, 1, 2, 7], className: 'text-center' },
            { targets: [4, 5, 6, 7], orderable: false },
            { targets: [7], searchable: false }
        ],
        dom: '<"row mb-2"<"col-md-6 d-flex align-items-center"l<' + "'lancamentos-top-filters-container'" + '>> <"col-md-6"f>>rt<"row mt-2"<"col-md-5"i><"col-md-7 d-flex justify-content-end"p>>',
        ajax: function(data, callback) {
            if (!erpBaseUrl) {
                callback({ data: [], recordsTotal: 0, recordsFiltered: 0, draw: data.draw });
                return;
            }
            var params = {
                db: '',
                strCodExercicio: '',
                intCodDiario: '',
                intMes: '',
                strAbrevTpDoc: '',
                limit: parseInt(data.length, 10) || 20,
                offset: Math.floor((parseInt(data.start, 10) || 0) / (parseInt(data.length, 10) || 20))
            };
            $filters.each(function() {
                var field = this.getAttribute('data-field');
                if (field) {
                    params[field] = this.value;
                }
            });
            var url = erpBaseUrl + '/contabilidade/movimentos';
            jQuery.ajax({
                url: url,
                data: params,
                headers: erpToken ? { 'X-API-KEY': erpToken, 'Accept': 'application/json' } : { 'Accept': 'application/json' },
                dataType: 'json'
            }).done(function(resp) {
                var rows = Array.isArray(resp && resp.aaData) ? resp.aaData : [];
                var formatted = rows.map(function(row) {
                    var dateValue = row && row.strData ? row.strData : '';
                    var formattedDate = dateValue;
                    if (dateValue) {
                        try {
                            var parts = dateValue.split('-');
                            if (parts.length === 3) {
                                formattedDate = parts[2] + '-' + parts[1];
                            }
                        } catch (e) {
                            formattedDate = dateValue;
                        }
                    }
                    var total = row && row.fltFArchTotal ? row.fltFArchTotal : '';
                    if (total !== '' && !isNaN(total)) {
                        total = parseFloat(total).toFixed(2);
                    }
                    return {
                        Id: row && row.Id ? row.Id : '',
                        strCodExercicio: row && row.strCodExercicio ? String(row.strCodExercicio) : '',
                        strData: row && row.strData ? String(row.strData) : '',
                        intCodDiario: row && row.intCodDiario ? row.intCodDiario : '',
                        intMes: row && row.intMes ? row.intMes : '',
                        formattedDate: formattedDate,
                        intNumDiario: row && row.intNum_Diario ? row.intNum_Diario : '',
                        strAbrevTpDoc: row && row.strAbrevTpDoc ? row.strAbrevTpDoc : '',
                        strNumDoc: row && row.strNum_Doc ? row.strNum_Doc : '',
                        strFArchTaxPayer: row && row.strFArchTaxPayer ? row.strFArchTaxPayer : '',
                        fltFArchTotal: row && row.fltFArchTotal ? row.fltFArchTotal : '',
                        total: total,
                        hasDigitalAttachment: extractHasDigitalAttachment(row),
                        linhas: Array.isArray(row && row.linhas) ? row.linhas : [],
                        mov_cc: Array.isArray(row && row.mov_cc) ? row.mov_cc : []
                    };
                });
                var total = resp && typeof resp.iTotalRecords !== 'undefined' ? parseInt(resp.iTotalRecords, 10) : formatted.length;
                var filtered = resp && typeof resp.iTotalDisplayRecords !== 'undefined' ? parseInt(resp.iTotalDisplayRecords, 10) : total;
                callback({
                    draw: data.draw,
                    recordsTotal: isNaN(total) ? 0 : total,
                    recordsFiltered: isNaN(filtered) ? 0 : filtered,
                    data: formatted
                });
            }).fail(function() {
                callback({ data: [], recordsTotal: 0, recordsFiltered: 0, draw: data.draw });
            });
        },
        language: {
            emptyTable: 'Sem registos.',
            lengthMenu: '_MENU_',
            search: 'Pesquisa:',
            info: 'A mostrar _START_ a _END_ de _TOTAL_ registos',
            infoEmpty: 'A mostrar 0 registos',
            infoFiltered: '(filtrado de _MAX_ registos)',
            paginate: {
                first: 'Primeiro',
                last: 'Último',
                next: 'Seguinte',
                previous: 'Anterior'
            }
        }
    });

    var topFiltersContainer = document.querySelector('.lancamentos-top-filters-container');
    var topFilters = document.getElementById('lancamentos-top-filters');
    if (topFiltersContainer && topFilters) {
        topFilters.classList.remove('d-none');
        topFilters.classList.add('ms-3');
        topFiltersContainer.appendChild(topFilters);
    }

    var lengthSelect = jQuery(tableEl).closest('.dataTables_wrapper').find('select[name="lancamentos-table_length"]');
    if (lengthSelect.length) {
        lengthSelect.addClass('form-select');
    }
    if (detailModalEl) {
        jQuery(detailModalEl).on('hidden.bs.modal', function() {
            currentDetailRow = null;
        });
    }

    if (classifyModalEl) {
        jQuery(classifyModalEl).on('hidden.bs.modal', function() {
            editorCurrentRow = null;
            resetEditorRows();
            resetEditorPreview();
            if (totalAccountInput) {
                totalAccountInput.value = '';
            }
        });
    }

    if (addVatLineBtn) {
        jQuery(addVatLineBtn).on('click', function() {
            var rateKey = 'custom_' + (++editorDynamicRateCounter);
            editorRateData[rateKey] = {
                label: '',
                base: '',
                iva: '',
                iva_account: '',
                general_account: ''
            };
            createEditorRateRow(rateKey, true);
        });
    }

    if (costCenterDistributionAddRowBtn) {
        jQuery(costCenterDistributionAddRowBtn).on('click', function() {
            addCostCenterDistributionRow(null);
        });
    }

    if (costCenterDistributionSaveBtn) {
        jQuery(costCenterDistributionSaveBtn).on('click', function() {
            if (!editorCurrentDistributionRate || !costCenterDistributionTableBody) {
                return;
            }
            var rows = [];
            var totalPercentage = 0;
            jQuery(costCenterDistributionTableBody).find('tr').each(function() {
                var code = sanitizeText(jQuery(this).find('.cc-distribution-code').val());
                var percentage = parseDecimalValue(jQuery(this).find('.cc-distribution-percentage').val());
                if (!code && (percentage === null || percentage === 0)) {
                    return;
                }
                if (!code || percentage === null || percentage <= 0) {
                    rows = null;
                    return false;
                }
                totalPercentage += percentage;
                rows.push({
                    cost_center: code,
                    percentage: formatPercentValue(percentage),
                    value: formatDecimalValue((parseDecimalValue(editorRateInputs[editorCurrentDistributionRate].baseEl.value) || 0) * (percentage / 100))
                });
            });
            if (!rows || !rows.length || Math.abs(totalPercentage - 100) > 0.01) {
                alert('A distribuição por centros de custo tem de estar completa e totalizar 100%.');
                return;
            }
            editorCostCenterBreakdowns[editorCurrentDistributionRate] = rows;
            editorCostCenters[editorCurrentDistributionRate] = rows[0].cost_center;
            refreshCostCenterSummary(editorCurrentDistributionRate);
            if (costCenterDistributionModal) {
                costCenterDistributionModal.hide();
            }
        });
    }

        function maybeToggleTable() {
            var hasDb = false;
            var hasDiary = false;
            $filters.each(function() {
                var field = this.getAttribute('data-field');
                if (field === 'db' && this.value.trim() !== '') {
                    hasDb = true;
                }
                if (field === 'intCodDiario' && this.value.trim() !== '') {
                    hasDiary = true;
                }
            });
            if (!hasDb || !hasDiary) {
                jQuery(tableEl).closest('.dataTables_wrapper').hide();
            } else {
                jQuery(tableEl).closest('.dataTables_wrapper').show();
            }
        }
        maybeToggleTable();

    var reloadTimer = null;
    $filters.on('input change', function() {
        if (reloadTimer) {
            clearTimeout(reloadTimer);
        }
            reloadTimer = setTimeout(function() {
                var values = {};
                $filters.each(function() {
                    var field = this.getAttribute('data-field');
                    if (field) {
                        values[field] = this.value;
                    }
                });
                saveFilters(values);
                maybeToggleTable();
                table.ajax.reload(null, true);
            }, 250);
        });

    jQuery(tableEl).on('click', '.js-open-lancamento-lines', function(ev) {
        ev.preventDefault();
        var rowData = table.row(jQuery(this).closest('tr')).data();
        if (!rowData || !classifyModal) {
            return;
        }
        populateEditorModal(rowData);
        loadCostCenterCatalogForDocument();
        classifyModal.show();
    });

    if (classifyFormEl) {
        jQuery(classifyFormEl).on('submit', function(ev) {
            ev.preventDefault();
            if (!editorCurrentRow) {
                return;
            }
            var dbValue = getSelectedDatabase();
            if (!dbValue) {
                alert('Selecione a empresa antes de guardar o lançamento.');
                return;
            }
            var documentPayload = buildMovementDocumentFromEditor(editorCurrentRow);
            if (!Array.isArray(documentPayload.account) || !documentPayload.account.length) {
                alert('Preencha pelo menos uma linha contabilística.');
                return;
            }
            var deletePayload = {
                act: 'deleteMovim',
                db: dbValue,
                database: dbValue,
                strCodExercicio: String(editorCurrentRow.strCodExercicio || ''),
                intCodDiario: parseInt(editorCurrentRow.intCodDiario, 10) || 0,
                intMes: parseInt(editorCurrentRow.intMes, 10) || 0,
                intNum_Diario: parseInt(editorCurrentRow.intNumDiario, 10) || 0
            };
            if (editorCurrentRow.Id !== undefined && editorCurrentRow.Id !== null && String(editorCurrentRow.Id).trim() !== '') {
                deletePayload.Id = editorCurrentRow.Id;
            }
            var importPayload = {
                act: 'importMovim',
                db: dbValue,
                database: dbValue,
                codDiario: parseInt(editorCurrentRow.intCodDiario, 10) || 0,
                documents: [documentPayload]
            };
            var restorePayload = {
                act: 'importMovim',
                db: dbValue,
                database: dbValue,
                codDiario: parseInt(editorCurrentRow.intCodDiario, 10) || 0,
                documents: [buildMovementDocumentFromOriginalRow(editorCurrentRow)]
            };
            var $submitBtn = jQuery(classifyFormEl).find('button[type="submit"]');
            $submitBtn.prop('disabled', true);
            jQuery.ajax({
                url: erpBaseUrl + '/contabilidade/movimentos',
                method: 'POST',
                headers: getErpHeaders(),
                contentType: 'application/json; charset=utf-8',
                dataType: 'json',
                data: JSON.stringify(deletePayload)
            }).then(function(deleteResp) {
                if (!deleteResp || !(deleteResp.success === 1 || deleteResp.success === true)) {
                    return jQuery.Deferred().reject({ message: (deleteResp && (deleteResp.errormsg || deleteResp.message)) || 'Falha ao remover o lançamento original.' });
                }
                return jQuery.ajax({
                    url: erpBaseUrl + '/contabilidade/movimentos',
                    method: 'POST',
                    headers: getErpHeaders(),
                    contentType: 'application/json; charset=utf-8',
                    dataType: 'json',
                    data: JSON.stringify(importPayload)
                });
            }).done(function(importResp) {
                if (!importResp || !(importResp.success === 1 || importResp.success === true)) {
                    jQuery.ajax({
                        url: erpBaseUrl + '/contabilidade/movimentos',
                        method: 'POST',
                        headers: getErpHeaders(),
                        contentType: 'application/json; charset=utf-8',
                        dataType: 'json',
                        data: JSON.stringify(restorePayload)
                    }).always(function() {
                        alert((importResp && (importResp.errormsg || importResp.message)) || 'Falha ao gravar o lançamento editado. O original foi reposto quando possível.');
                    });
                    return;
                }
                if (classifyModal) {
                    classifyModal.hide();
                }
                table.ajax.reload(null, false);
            }).fail(function(xhr) {
                var message = 'Falha ao atualizar o lançamento.';
                if (xhr && xhr.message) {
                    message = xhr.message;
                } else if (xhr && xhr.responseJSON && (xhr.responseJSON.errormsg || xhr.responseJSON.message)) {
                    message = xhr.responseJSON.errormsg || xhr.responseJSON.message;
                }
                alert(message);
            }).always(function() {
                $submitBtn.prop('disabled', false);
            });
        });
    }

    function handleDeleteLancamento(ev) {
            if (ev && typeof ev.preventDefault === 'function') {
                ev.preventDefault();
            }
            if (ev && typeof ev.stopPropagation === 'function') {
                ev.stopPropagation();
            }
            if (!currentDetailRow) {
                return;
            }
            if (!erpBaseUrl) {
                alert('URL do webservice ERP não configurada.');
                return;
            }

            var dbValue = getSelectedDatabase();
            if (!dbValue) {
                alert('Selecione a empresa para eliminar o lançamento.');
                return;
            }

            var docLabel = String(currentDetailRow.strNumDoc || '').trim();
            var confirmMessage = 'Confirma a eliminação deste lançamento?';
            if (docLabel !== '') {
                confirmMessage += '\nDocumento: ' + docLabel;
            }
            if (!window.confirm(confirmMessage)) {
                return;
            }

            var rowToDelete = currentDetailRow;

            var deletePayload = {
                act: 'deleteMovim',
                db: dbValue,
                database: dbValue,
                strCodExercicio: String(rowToDelete.strCodExercicio || ''),
                intCodDiario: parseInt(rowToDelete.intCodDiario, 10) || 0,
                intMes: parseInt(rowToDelete.intMes, 10) || 0,
                intNum_Diario: parseInt(rowToDelete.intNumDiario, 10) || 0
            };
            if (rowToDelete.Id !== undefined && rowToDelete.Id !== null && String(rowToDelete.Id).trim() !== '') {
                deletePayload.Id = rowToDelete.Id;
            }
            if (!deletePayload.strCodExercicio || !deletePayload.intCodDiario || !deletePayload.intMes || !deletePayload.intNum_Diario) {
                alert('Chave do lançamento inválida para eliminação.');
                return;
            }

            var $btn = jQuery(this);
            $btn.prop('disabled', true);

            function callDeleteMovimento() {
                return jQuery.ajax({
                    url: erpBaseUrl + '/contabilidade/movimentos',
                    method: 'POST',
                    headers: getErpHeaders(),
                    contentType: 'application/json; charset=utf-8',
                    dataType: 'json',
                    data: JSON.stringify(deletePayload)
                });
            }

            function deleteLocalImportIfAny() {
                var cabId = rowToDelete && rowToDelete.Id !== undefined && rowToDelete.Id !== null
                    ? String(rowToDelete.Id).trim()
                    : '';
                if (!cabId) {
                    return jQuery.Deferred().resolve({ success: true, deleted_rows: 0 }).promise();
                }
                return jQuery.ajax({
                    url: localDeleteUrl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        cab_id: cabId,
                        csrf_token: localCsrfToken
                    }
                }).done(function(resp) {
                    if (resp && resp.csrf_token) {
                        localCsrfToken = resp.csrf_token;
                    }
                }).fail(function(xhr) {
                    if (xhr && xhr.responseJSON && xhr.responseJSON.csrf_token) {
                        localCsrfToken = xhr.responseJSON.csrf_token;
                    }
                });
            }

            function deleteAnexosIfAny() {
                if (rowToDelete.hasDigitalAttachment !== true) {
                    return jQuery.Deferred().resolve().promise();
                }
                return jQuery.ajax({
                    url: erpBaseUrl + '/anexosdigitais',
                    method: 'GET',
                    headers: getErpHeaders(),
                    dataType: 'json',
                    data: {
                        db: dbValue,
                        intTipoEntidade: 23,
                        strChave1: String(rowToDelete.strCodExercicio || ''),
                        strChave2: String(rowToDelete.intCodDiario || ''),
                        strChave3: String(rowToDelete.intMes || ''),
                        intNumero: String(rowToDelete.intNumDiario || '')
                    }
                }).then(function(resp) {
                    var anexos = Array.isArray(resp && resp.anexos) ? resp.anexos : [];
                    if (!anexos.length) {
                        return;
                    }
                    var sequence = jQuery.Deferred().resolve().promise();
                    anexos.forEach(function(anexo) {
                        sequence = sequence.then(function() {
                            var anexoId = anexo && (anexo.id || anexo.idCab);
                            if (!anexoId) {
                                return;
                            }
                            return jQuery.ajax({
                                url: erpBaseUrl + '/anexosdigitais',
                                method: 'POST',
                                headers: getErpHeaders(),
                                contentType: 'application/json; charset=utf-8',
                                dataType: 'json',
                                data: JSON.stringify({
                                    act: 'delete',
                                    db: dbValue,
                                    database: dbValue,
                                    id: String(anexoId)
                                })
                            });
                        });
                    });
                    return sequence;
                });
            }

            deleteAnexosIfAny().always(function() {
                callDeleteMovimento()
                    .done(function(resp) {
                        if (!resp || !(resp.success === 1 || resp.success === true)) {
                            alert((resp && (resp.errormsg || resp.message)) ? (resp.errormsg || resp.message) : 'Falha ao eliminar lançamento.');
                            return;
                        }
                        currentDetailRow = null;
                        editorCurrentRow = null;
                        if (detailModal) {
                            detailModal.hide();
                        }
                        if (classifyModal) {
                            classifyModal.hide();
                        }
                        table.ajax.reload(null, false);

                        deleteLocalImportIfAny()
                            .done(function(localResp) {
                                if (localResp && localResp.success === false && window.console && typeof window.console.warn === 'function') {
                                    window.console.warn(localResp.error || 'Lançamento eliminado no ERP, mas falhou a remoção do registo local.');
                                }
                            })
                            .fail(function(xhr) {
                                if (window.console && typeof window.console.warn === 'function') {
                                    var localMsg = 'Lançamento eliminado no ERP, mas falhou a remoção do registo local.';
                                    if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                                        localMsg = xhr.responseJSON.error;
                                    }
                                    window.console.warn(localMsg);
                                }
                            });
                    })
                    .fail(function(xhr) {
                        var msg = 'Falha ao eliminar lançamento.';
                        if (xhr && xhr.responseJSON && (xhr.responseJSON.errormsg || xhr.responseJSON.message)) {
                            msg = xhr.responseJSON.errormsg || xhr.responseJSON.message;
                        }
                        alert(msg);
                    })
                    .always(function() {
                        $btn.prop('disabled', false);
                    });
            });
    }

    if (canDeleteLancamento) {
        if (deleteBtnEl) {
            jQuery(deleteBtnEl).on('click', handleDeleteLancamento);
        }
        if (deleteEditorBtnEl) {
            jQuery(deleteEditorBtnEl).on('click', handleDeleteLancamento);
        }
    }

    jQuery(tableEl).on('click', '.js-download-lancamento-pdf', function(ev) {
        ev.preventDefault();
        if (!erpBaseUrl) {
            alert('URL do webservice ERP não configurada.');
            return;
        }
        var $btn = jQuery(this);
        var rowData = table.row($btn.closest('tr')).data();
        if (!rowData) {
            return;
        }
        var dbValue = getSelectedDatabase();
        if (!dbValue) {
            dbValue = '';
        }
        if (!dbValue) {
            alert('Selecione a empresa para descarregar o PDF.');
            return;
        }

        var requestData = {
            db: dbValue,
            intTipoEntidade: 23,
            strChave1: String(rowData.strCodExercicio || ''),
            strChave2: String(rowData.intCodDiario || ''),
            strChave3: String(rowData.intMes || ''),
            intNumero: String(rowData.intNumDiario || '')
        };
        if (!requestData.strChave1 || !requestData.strChave2 || !requestData.strChave3 || !requestData.intNumero) {
            alert('Não foi possível montar as chaves do anexo digital.');
            return;
        }

        $btn.addClass('disabled');
        jQuery.ajax({
            url: erpBaseUrl + '/anexosdigitais',
            method: 'GET',
            data: requestData,
            headers: erpToken ? { 'X-API-KEY': erpToken, 'Accept': 'application/json' } : { 'Accept': 'application/json' },
            dataType: 'json'
        }).done(function(resp) {
            var anexos = Array.isArray(resp && resp.anexos) ? resp.anexos : [];
            if (!anexos.length) {
                unavailablePdfRows[getLancamentoKey(rowData)] = true;
                $btn.replaceWith('<span class="btn btn-xs btn-default disabled" title="Sem anexo digital"><i class="fa fa-file-pdf-o text-muted"></i></span>');
                return;
            }
            var anexo = anexos[0] || {};
            var fileBase64 = anexo.Ficheiro || '';
            if (!fileBase64) {
                unavailablePdfRows[getLancamentoKey(rowData)] = true;
                $btn.replaceWith('<span class="btn btn-xs btn-default disabled" title="Sem anexo digital"><i class="fa fa-file-pdf-o text-muted"></i></span>');
                return;
            }
            try {
                var blob = decodeBase64ToBlob(fileBase64, 'application/pdf');
                var filename = anexo.strIdFicheiro || ('lancamento_' + requestData.intNumero + '.pdf');
                downloadBlob(blob, filename);
            } catch (e) {
                alert('Falha ao processar o ficheiro PDF.');
            }
        }).fail(function() {
            alert('Erro ao obter anexo digital no ERP.');
        }).always(function() {
            $btn.removeClass('disabled');
        });
    });
})();
JS;
$pageScripts = "window.erpLancamentosBaseUrl = " . json_encode((string) getSetting('erp_webservice_url', ''), JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.erpLancamentosToken = " . json_encode((string) getSetting('erp_token', ''), JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.lancamentosSelectedDatabase = " . json_encode((string) $selectedDatabase, JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.lancamentosCanDelete = " . json_encode($canDeleteLocalImports, JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.lancamentosDeleteLocalUrl = " . json_encode((string) (BASE_URL . 'contabilidade/lancamentos?action=delete_local_import'), JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.lancamentosCsrfToken = " . json_encode($csrfToken, JSON_UNESCAPED_UNICODE) . ";\n"
    . $pageScripts;
require_once __DIR__ . '/../footer.php';
