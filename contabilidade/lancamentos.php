<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
requireRole(2);

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
        $database = trim((string) getSetting('erp_database', ''));
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

require_once __DIR__ . '/../header.php';
$currentYear = (int) date('Y');
$yearOptions = [$currentYear, $currentYear - 1];
?>
<div class="container-fluid">
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-book"></i> Lançamentos</h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div id="lancamentos-top-filters" class="d-none d-flex align-items-center">
                <select class="form-select dt-filter me-2" style="min-width: 520px; height: 38px;" data-field="db" data-default="<?= htmlspecialchars((string) getSetting('erp_database', '')); ?>">
                    <option value="">Empresa</option>
                    <?php foreach ($companyDatabases as $dbRow): ?>
                        <?php
                            $dbValue = trim((string) ($dbRow['erp_database'] ?? ''));
                            $companyName = trim((string) ($dbRow['company_name'] ?? ''));
                            $companyCode = preg_replace('/^emp_/i', '', $dbValue);
                            if ($companyCode === null || $companyCode === '') {
                                $companyCode = $dbValue;
                            }
                            $optionLabel = $companyCode;
                            if ($companyName !== '') {
                                $optionLabel .= ' - ' . $companyName;
                            }
                        ?>
                        <option value="<?= htmlspecialchars($dbValue); ?>"><?= htmlspecialchars($optionLabel); ?></option>
                    <?php endforeach; ?>
                </select>
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
                        <th>Nº Doc</th>
                        <th>Tax Payer</th>
                        <th>Total</th>
                        <th class="text-center">PDF</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="lancamentoDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lancamentoDetailTitle">Detalhe do lançamento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
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
            <div class="modal-footer">
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
    var detailTitleEl = document.getElementById('lancamentoDetailTitle');
    var detailModal = (detailModalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function')
        ? new window.bootstrap.Modal(detailModalEl)
        : null;

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseAmount(value) {
        var raw = String(value || '').trim().replace(',', '.');
        var num = parseFloat(raw);
        return isNaN(num) ? 0 : num;
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

        if (detailModal) {
            detailModal.show();
        }
    }

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
                    return '<a href="#" class="btn btn-xs btn-default js-download-lancamento-pdf" title="Download PDF">'
                        + '<i class="fa fa-file-pdf-o text-danger"></i>'
                        + '</a>';
                }
            }
        ],
        columnDefs: [
            { targets: [0, 1, 2, 7], className: 'text-center' },
            { targets: [7], orderable: false, searchable: false }
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
                        strCodExercicio: row && row.strCodExercicio ? String(row.strCodExercicio) : '',
                        intCodDiario: row && row.intCodDiario ? row.intCodDiario : '',
                        intMes: row && row.intMes ? row.intMes : '',
                        formattedDate: formattedDate,
                        intNumDiario: row && row.intNum_Diario ? row.intNum_Diario : '',
                        strAbrevTpDoc: row && row.strAbrevTpDoc ? row.strAbrevTpDoc : '',
                        strNumDoc: row && row.strNum_Doc ? row.strNum_Doc : '',
                        strFArchTaxPayer: row && row.strFArchTaxPayer ? row.strFArchTaxPayer : '',
                        total: total,
                        linhas: Array.isArray(row && row.linhas) ? row.linhas : []
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
        if (!rowData) {
            return;
        }
        openDetailModal(rowData);
    });

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
        var dbValue = '';
        $filters.each(function() {
            if (this.getAttribute('data-field') === 'db') {
                dbValue = this.value.trim();
            }
        });
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
                alert('Sem anexos digitais para este lançamento.');
                return;
            }
            var anexo = anexos[0] || {};
            var fileBase64 = anexo.Ficheiro || '';
            if (!fileBase64) {
                alert('O anexo não contém ficheiro para download.');
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
    . $pageScripts;
require_once __DIR__ . '/../footer.php';
