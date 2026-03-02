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
    $stmt = $pdo->query("SELECT DISTINCT erp_database FROM accounting_entities WHERE erp_database <> '' ORDER BY erp_database ASC");
    $companyDatabases = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
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
                <select class="form-select dt-filter me-2" style="min-width: 220px; height: 38px;" data-field="db" data-default="<?= htmlspecialchars((string) getSetting('erp_database', '')); ?>">
                    <option value="">Empresa</option>
                    <?php foreach ($companyDatabases as $dbValue): ?>
                        <option value="<?= htmlspecialchars((string) $dbValue); ?>"><?= htmlspecialchars((string) $dbValue); ?></option>
                    <?php endforeach; ?>
                </select>
                <select class="form-select dt-filter" style="min-width: 140px; height: 38px;" data-field="strCodExercicio" data-default="<?= htmlspecialchars((string) $currentYear); ?>">
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
                    </tr>
                    <tr>
                        <th class="text-center">Diário</th>
                        <th class="text-center">Data</th>
                        <th class="text-center">Nº Diário</th>
                        <th>Tipo Doc</th>
                        <th>Nº Doc</th>
                        <th>Tax Payer</th>
                        <th>Total</th>
                    </tr>
                </thead>
            </table>
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
        columnDefs: [
            { targets: [0, 1, 2], className: 'text-center' }
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
                    return [
                        row && row.intCodDiario ? row.intCodDiario : '',
                        formattedDate,
                        row && row.intNum_Diario ? row.intNum_Diario : '',
                        row && row.strAbrevTpDoc ? row.strAbrevTpDoc : '',
                        row && row.strNum_Doc ? row.strNum_Doc : '',
                        row && row.strFArchTaxPayer ? row.strFArchTaxPayer : '',
                        total
                    ];
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
})();
JS;
$pageScripts = "window.erpLancamentosBaseUrl = " . json_encode((string) getSetting('erp_webservice_url', ''), JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.erpLancamentosToken = " . json_encode((string) getSetting('erp_token', ''), JSON_UNESCAPED_UNICODE) . ";\n"
    . $pageScripts;
require_once __DIR__ . '/../footer.php';
