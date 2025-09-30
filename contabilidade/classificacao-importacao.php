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
$currentErpWebserviceUrl = trim((string) getSetting('erp_webservice_url', ''));

function import_CTB(PDO $pdo, array $ids, int $importType): array {
    $result = [
        'success' => false,
        'error' => '',
        'status' => 0,
        'response' => null,
    ];

    if (!function_exists('curl_init')) {
        $result['error'] = 'Extensão cURL não disponível no servidor.';
        logErpMessage('Extensão cURL não disponível para importar movimentos CTB.');
        return $result;
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    if ($baseUrl === '') {
        $result['error'] = 'URL do webservice ERP não está configurada.';
        logErpMessage('URL do ERP-SINC não configurada para importar movimentos CTB.');
        return $result;
    }

    $endpoint = rtrim($baseUrl, '/') . '/contabilidade/movimentos';
    $sanitizedEndpoint = sanitizeUrlForLog($endpoint);
    $endpointInfo = $sanitizedEndpoint !== '' ? ' URL: ' . $sanitizedEndpoint : '';

    $handle = curl_init($endpoint);
    if ($handle === false) {
        $result['error'] = 'Não foi possível iniciar o pedido ao webservice de contabilidade.';
        logErpMessage('Falha ao inicializar pedido para importar movimentos CTB.' . $endpointInfo);
        return $result;
    }

    $headers = [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
    ];

    $token = trim((string) getSetting('erp_token', ''));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'X-API-KEY: ' . $token;
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $documentSql = 'SELECT * FROM accounting_imports WHERE import_type = ? AND id IN (' . $placeholders . ') ORDER BY id';
    $documentStmt = $pdo->prepare($documentSql);
    $documentStmt->bindValue(1, $importType, PDO::PARAM_INT);
    foreach ($ids as $index => $id) {
        $documentStmt->bindValue($index + 2, $id, PDO::PARAM_INT);
    }

    $documentStmt->execute();
    $documents = $documentStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($documents)) {
        $result['error'] = 'Nenhum documento encontrado para importar.';
        logErpMessage('Importação CTB abortada: nenhum documento encontrado para os IDs ' . implode(', ', $ids));
        return $result;
    }

    $documentsPayload = array_map(static function (array $document): array {
        if (array_key_exists('line_items', $document)) {
            $decodedLineItems = json_decode((string) $document['line_items'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $document['line_items'] = $decodedLineItems;
            }
        }

        return $document;
    }, $documents);

    $documentsJson = json_encode($documentsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($documentsJson === false) {
        $result['error'] = 'Falha ao preparar os documentos para envio.';
        logErpMessage('Erro ao codificar JSON dos documentos CTB: ' . json_last_error_msg());
        return $result;
    }

    $postPayload = [
        'tp' => 'importMovim',
        'act' => 'importMovim',
        'accao' => 'movimentos',
        'import_type' => $importType,
        'document_ids' => implode(',', $ids),
        'documents' => $documentsJson,
    ];


    $postFields = http_build_query($postPayload, '', '&', PHP_QUERY_RFC3986);

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $postFields,
    ]);

    $response = curl_exec($handle);
    if ($response === false) {
        $errorMessage = curl_error($handle);
        curl_close($handle);
        $result['error'] = 'Erro ao comunicar com o webservice de contabilidade.';
        logErpMessage('Erro cURL ao importar movimentos CTB: ' . $errorMessage . $endpointInfo);
        return $result;
    }

    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    $result['status'] = $status;
    $result['response'] = $response;

    if ($status >= 400) {
        $detail = '';
        $decoded = json_decode((string) $response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $detailKeys = ['message', 'error', 'detail', 'descricao', 'mensagem'];
            foreach ($detailKeys as $detailKey) {
                if (array_key_exists($detailKey, $decoded) && trim((string) $decoded[$detailKey]) !== '') {
                    $detail = trim((string) $decoded[$detailKey]);
                    break;
                }
            }
        }

        if ($detail === '' && is_string($response)) {
            $snippetSource = trim($response);
            if ($snippetSource !== '') {
                if (function_exists('mb_substr')) {
                    $detail = trim(mb_substr($snippetSource, 0, 200));
                } else {
                    $detail = trim(substr($snippetSource, 0, 200));
                }
            }
        }

        $baseErrorMessage = 'O webservice de contabilidade devolveu um erro (HTTP ' . $status . ').';
        if ($detail !== '') {
            $result['error_detail'] = $detail;
            $result['error'] = $baseErrorMessage . ' Detalhe: ' . $detail;
        } else {
            $result['error'] = $baseErrorMessage;
        }

        $logResponse = is_string($response) ? $response : json_encode($response);
        logErpMessage('Webservice CTB devolveu HTTP ' . $status . ' ao importar movimentos.' . $endpointInfo . ' Resposta: ' . substr((string) $logResponse, 0, 500));
        return $result;
    }

    $decodedResponse = json_decode((string) $response, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedResponse)) {
        $result['decoded'] = $decodedResponse;

        $webserviceSuccess = null;
        if (array_key_exists('success', $decodedResponse)) {
            $webserviceSuccess = filter_var($decodedResponse['success'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($webserviceSuccess === null && is_numeric($decodedResponse['success'])) {
                $webserviceSuccess = (int) $decodedResponse['success'] === 1;
            }
        }

        if ($webserviceSuccess === false) {
            $errorDetail = '';
            $errorFields = ['errormsg', 'error', 'mensagem', 'message'];
            foreach ($errorFields as $errorField) {
                if (isset($decodedResponse[$errorField]) && trim((string) $decodedResponse[$errorField]) !== '') {
                    $errorDetail = trim((string) $decodedResponse[$errorField]);
                    break;
                }
            }

            if ($errorDetail !== '') {
                $result['error'] = $errorDetail;
            } else {
                $result['error'] = 'O webservice de contabilidade devolveu um erro ao importar os movimentos.';
            }

            if (isset($decodedResponse['logmsg'])) {
                $result['log'] = $decodedResponse['logmsg'];
            }

            $logDetail = $errorDetail !== '' ? (' Detalhe: ' . $errorDetail) : '';
            logErpMessage('Webservice CTB devolveu sucesso=0 ao importar movimentos.' . $endpointInfo . $logDetail);

            $result['success'] = false;
            return $result;
        }

        if ($webserviceSuccess === true) {
            $result['success'] = true;
        }

        if (isset($decodedResponse['logmsg'])) {
            $result['log'] = $decodedResponse['logmsg'];
        }
    }

    if (empty($result['success'])) {
        $result['success'] = true;
    }

    logErpMessage('Pedido de importação CTB enviado com sucesso. HTTP ' . $status . '. IDs: ' . implode(', ', $ids));

    return $result;
}

function prepareImportRow(array $row): array {
    $accounts = normalizeAccountingAccounts($row['account'] ?? '');
    $summaries = computeImportRateSummaries($row);
    [$payload, $requirements] = buildRatePayload($summaries, $accounts);
    $row['rate_payload'] = $payload;
    $row['rate_requirements'] = $requirements;
    $row['cost_centers'] = normalizeCostCenters($row['cost_center'] ?? '');
    $row['btn_class'] = determineClassificationButtonClass($requirements, $payload);
    $row['line_btn_class'] = 'btn-info';
    $lines = json_decode($row['line_items'] ?? '', true);
    if (is_array($lines) && count($lines) > 0) {
        $allFilled = true;
        foreach ($lines as $line) {
            $erpValue = trim((string) ($line['ERP'] ?? ''));
            if ($erpValue === '') {
                $allFilled = false;
                break;
            }
        }
        if ($allFilled) {
            $row['line_btn_class'] = 'btn-success';
        }
    }

    return $row;
}

if ($action === 'import_ctb' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $_POST['act'] = 'import-ctb';

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?? '', true);

    if (!is_array($payload)) {
        echo json_encode([
            'success' => false,
            'error' => 'Pedido inválido',
            'csrf_token' => generateCsrfToken(true)
        ]);
        exit;
    }

    $csrfToken = (string)($payload['csrf_token'] ?? '');
    if ($csrfToken === '' || !validateCsrfToken($csrfToken)) {
        echo json_encode([
            'success' => false,
            'error' => 'Token CSRF inválido',
            'csrf_token' => generateCsrfToken(true)
        ]);
        exit;
    }

    $ids = [];
    foreach ($payload['ids'] ?? [] as $value) {
        if (is_numeric($value)) {
            $id = (int)$value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
    }
    $ids = array_values($ids);

    if (empty($ids)) {
        echo json_encode([
            'success' => false,
            'error' => 'Nenhuma linha seleccionada para importar',
            'csrf_token' => generateCsrfToken(true)
        ]);
        exit;
    }

    $requestedImportType = (int)($payload['import_type'] ?? $importType);
    if ($requestedImportType <= 0) {
        $requestedImportType = 1;
    }

    $serviceResult = import_CTB($pdo, $ids, $requestedImportType);

    $responsePayload = [
        'success' => (bool)($serviceResult['success'] ?? false),
        'ids' => $ids,
        'import_type' => $requestedImportType,
        'csrf_token' => generateCsrfToken(),
        'http_status' => $serviceResult['status'] ?? 0,
    ];

    if (!empty($serviceResult['error'])) {
        $responsePayload['error'] = $serviceResult['error'];
    }

    if (!empty($serviceResult['error_detail'])) {
        $responsePayload['error_detail'] = $serviceResult['error_detail'];
    }

    if (array_key_exists('response', $serviceResult)) {
        $responsePayload['service_response'] = $serviceResult['response'];
    }

    if (array_key_exists('decoded', $serviceResult)) {
        $responsePayload['service_payload'] = $serviceResult['decoded'];
    }

    if (array_key_exists('log', $serviceResult)) {
        $responsePayload['log'] = $serviceResult['log'];
    }

    if (!empty($serviceResult['success'])) {
        $responsePayload['message'] = 'OK';
    }

    echo json_encode($responsePayload);
    exit;
}

if ($action === 'data') {
    $columns = [
        'id',
        'account',
        'cost_center',
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

    $countSql = 'SELECT COUNT(*) FROM accounting_imports WHERE import_type = :importType';
    $countStmt = $pdo->prepare($countSql);
    $countStmt->bindValue(':importType', $importType, PDO::PARAM_INT);
    $countStmt->execute();
    $totalCount = (int)$countStmt->fetchColumn();
    $filteredCount = $totalCount;

    $colList = implode(', ', array_map(fn($c) => "`$c`", $columns));
    $sql = "SELECT $colList FROM accounting_imports WHERE import_type = :importType ORDER BY id LIMIT :start, :length";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start', $start, PDO::PARAM_INT);
    $stmt->bindValue(':length', $length, PDO::PARAM_INT);
    $stmt->bindValue(':importType', $importType, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row = prepareImportRow($row);
    }
    unset($row);

    $data = [];
    foreach ($rows as $row) {
        $actionsParts = [];
        $ratesAttr = htmlspecialchars(json_encode($row['rate_payload'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        $requirementsAttr = htmlspecialchars(json_encode($row['rate_requirements'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
        $costCentersAttr = htmlspecialchars(json_encode($row['cost_centers'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

        if ($importType === 1) {
            $actionsParts[] = '<button type="button" class="btn btn-xs ' . $row['btn_class'] . ' classify-row" '
                . 'data-id="' . (int)$row['id'] . '" '
                . 'data-rates="' . $ratesAttr . '" '
                . 'data-requirements="' . $requirementsAttr . '" '
                . 'data-cost-centers="' . $costCentersAttr . '" '
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
        'recordsTotal' => $totalCount,
        'recordsFiltered' => $filteredCount,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM accounting_imports WHERE import_type = :type');
$stmt->execute([':type' => $importType]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $row = prepareImportRow($row);
}
unset($row);

$csrfToken = generateCsrfToken();

require_once __DIR__ . '/../header.php';
?>
<input type="hidden" id="import_type" value="<?= htmlspecialchars($importType); ?>">
<div class="row mb-3">
    <div class="col-12">
        <?php if ($importType === 1): ?>


        <div id="importCtbButtonWrapper" class="d-none">
            <button type="button" class="btn btn-sm btn-primary" id="importCtbButton" disabled>Importar Ctb</button>
        </div>
        <?php endif; ?>
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
                        $ratesAttr = htmlspecialchars(json_encode($row['rate_payload'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $requirementsAttr = htmlspecialchars(json_encode($row['rate_requirements'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $costCentersAttr = htmlspecialchars(json_encode($row['cost_centers'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $btnClass = htmlspecialchars($row['btn_class'] ?? 'btn-secondary');
                    ?>
                    <td class="text-center">

                        <?php if ($importType === 1): ?>
                        <button
                            type="button"
                            class="btn btn-xs <?= $btnClass; ?> classify-row"
                            data-id="<?= (int)$row['id']; ?>"
                            data-rates="<?= $ratesAttr; ?>"
                            data-requirements="<?= $requirementsAttr; ?>"
                            data-cost-centers="<?= $costCentersAttr; ?>"


                            data-emitter="<?= htmlspecialchars($row['field_A'] ?? ''); ?>"
                            data-acquirer="<?= htmlspecialchars($row['field_B'] ?? ''); ?>"
                            data-doctype="<?= htmlspecialchars($row['field_D'] ?? ''); ?>">Classificar</button>
                        <?php endif; ?>

                        <?php if ($importType === 2): ?>
                        <button type="button" class="btn btn-xs <?= htmlspecialchars($row['line_btn_class'] ?? 'btn-info'); ?> analyze-lines" data-id="<?= (int)$row['id']; ?>">Analisar</button>
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
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="classifyModalLabel">Classificar</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <form id="classify-form">
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Taxa</th>
                                    <th>Base</th>
                                    <th>IVA</th>
                                    <th>Conta IVA</th>
                                    <th>Conta Geral</th>
                                    <?php if ($importType === 1): ?>
                                    <th>Centro de Custo</th>
                                    <?php endif; ?>
                                    <th class="text-center" width="1%">Ações</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-primary me-auto" id="addVatLineBtn">
                        <i class="fa fa-plus"></i> Adicionar linha de IVA
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<template id="vatRateRowTemplate">
    <tr data-custom-rate="0">
        <td class="align-middle">
            <span class="rate-label-static"></span>
        </td>
        <td><input type="text" class="form-control form-control-sm base-field" inputmode="decimal"></td>
        <td><input type="text" class="form-control form-control-sm iva-field" readonly></td>
        <td><input type="text" class="form-control form-control-sm iva-account-field"></td>
        <td><input type="text" class="form-control form-control-sm general-account-field"></td>
        <?php if ($importType === 1): ?>
        <td class="align-middle">
            <input
                type="text"
                class="form-control form-control-sm cost-center-field"
                placeholder="Introduza o centro de custo"
            >
        </td>
        <?php endif; ?>
        <td class="text-center align-middle actions-cell">
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary me-1 restore-base-btn d-none"
                title="Repor base original"
            >
                <i class="fa fa-undo"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger remove-rate-row" title="Remover linha">
                <i class="fa fa-trash"></i>
            </button>
        </td>
    </tr>
</template>
<template id="customRateRowTemplate">
    <tr data-custom-rate="1">
        <td>
            <input type="text" class="form-control form-control-sm rate-label-field" placeholder="Identificador da taxa">
        </td>
        <td><input type="text" class="form-control form-control-sm base-field" inputmode="decimal"></td>
        <td><input type="text" class="form-control form-control-sm iva-field" readonly></td>
        <td><input type="text" class="form-control form-control-sm iva-account-field"></td>
        <td><input type="text" class="form-control form-control-sm general-account-field"></td>
        <?php if ($importType === 1): ?>
        <td>
            <input
                type="text"
                class="form-control form-control-sm cost-center-field"
                placeholder="Introduza o centro de custo"
            >
        </td>
        <?php endif; ?>
        <td class="text-center align-middle">
            <button
                type="button"
                class="btn btn-sm btn-outline-secondary me-1 restore-base-btn d-none"
                title="Repor base original"
            >
                <i class="fa fa-undo"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger remove-rate-row" title="Remover linha">
                <i class="fa fa-trash"></i>
            </button>
        </td>
    </tr>
</template>
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
<script>
    window.erpWebserviceUrl = <?= json_encode(
        $currentErpWebserviceUrl,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>;
</script>
<script src="assets/js/classificacao_importacao.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>

