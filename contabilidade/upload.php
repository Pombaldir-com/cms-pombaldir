<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
$hasComprasUploadPermission = userHasDepartmentPermission('compras_upload');
if (!$hasComprasUploadPermission) {
    http_response_code(403);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sem permissao para upload de compras.']);
    } else {
        echo 'Acesso negado.';
    }
    exit;
}

$comprasActive = isModuleActive('compras');

function fetchUploadDatabaseCompanyId(string $database): string {
    $database = trim($database);
    if ($database === '') {
        return '';
    }
    if (!function_exists('curl_init')) {
        return '';
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    $token = trim((string) getSetting('erp_token', ''));
    if ($baseUrl === '' || $token === '') {
        return '';
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, 'contabilidade');
    if ($endpoint === '') {
        return '';
    }

    $payload = ['act' => 'listDBemp'];
    $companyParams = buildErpCompanyQueryParams();
    if (!empty($companyParams['EMP'])) {
        $payload['EMP'] = $companyParams['EMP'];
    }
    if (!empty($companyParams['db'])) {
        $payload['db'] = $companyParams['db'];
        $payload['database'] = $companyParams['db'];
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        return '';
    }

    $handle = curl_init($endpoint);
    if ($handle === false) {
        return '';
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
            'X-API-KEY: ' . $token,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encoded,
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response) || $response === '' || $status >= 400) {
        return '';
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return '';
    }

    $candidates = [];
    if (isset($decoded['options']) && is_array($decoded['options'])) {
        $candidates = $decoded['options'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $candidates = $decoded['data'];
    } elseif (isset($decoded['result']) && is_array($decoded['result'])) {
        $candidates = $decoded['result'];
    } elseif (isset($decoded['list']) && is_array($decoded['list'])) {
        $candidates = $decoded['list'];
    } elseif (isset($decoded['aaData']) && is_array($decoded['aaData'])) {
        $candidates = $decoded['aaData'];
    } elseif (array_keys($decoded) === range(0, count($decoded) - 1)) {
        $candidates = $decoded;
    }

    $sampleRows = [];
    $sampleCount = 0;
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        if ($sampleCount < 3) {
            $sampleRows[] = $candidate;
            $sampleCount += 1;
        }
        $normalized = [];
        foreach ($candidate as $key => $value) {
            if (is_string($key)) {
                $normalized[strtolower($key)] = $value;
            }
        }

        $candidateDb = '';
        $dbKeys = [
            'db',
            'database',
            'strbasedados',
            'basedados',
            'nomebase',
            'strdb',
            'strdatabasename',
            'strbd',
        ];
        foreach ($dbKeys as $dbKey) {
            if (array_key_exists($dbKey, $normalized) && trim((string) $normalized[$dbKey]) !== '') {
                $candidateDb = trim((string) $normalized[$dbKey]);
                break;
            }
        }
        $candidateValue = trim((string) ($normalized['value'] ?? ''));

        $matchesDatabase = false;
        if ($candidateDb !== '' && strcasecmp($candidateDb, $database) === 0) {
            $matchesDatabase = true;
        } elseif ($candidateValue !== '' && strcasecmp($candidateValue, $database) === 0) {
            $matchesDatabase = true;
        } elseif ($candidateDb !== '' && stripos($candidateDb, $database) !== false) {
            $matchesDatabase = true;
        }

        if (!$matchesDatabase) {
            continue;
        }
        $id = '';
        $idKeys = [
            'id',
            'intcodigo',
            'codigo',
            'empresaid',
            'idempresa',
            'companyid',
            'empid',
        ];
        foreach ($idKeys as $idKey) {
            if (array_key_exists($idKey, $normalized) && trim((string) $normalized[$idKey]) !== '') {
                $id = trim((string) $normalized[$idKey]);
                break;
            }
        }
        if ($id === '' && preg_match('/^\d+$/', $candidateValue)) {
            $id = $candidateValue;
        }
        if ($id !== '') {
            return $id;
        }
    }

    if (!empty($sampleRows)) {
        $sampleJson = json_encode($sampleRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($sampleJson)) {
            logErpMessage('listDBemp sem mapeamento de ID para db=' . $database . ' | amostra=' . $sampleJson);
        }
    } else {
        logErpMessage('listDBemp sem candidatos para resolver ID da db=' . $database);
    }

    return '';
}

function fetchUploadCompanyConfigCandidate(string $database): array {
    $database = trim($database);
    if ($database === '') {
        return ['id' => '', 'name' => ''];
    }
    if (!function_exists('curl_init')) {
        return ['id' => '', 'name' => ''];
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    $token = trim((string) getSetting('erp_token', ''));
    if ($baseUrl === '' || $token === '') {
        return ['id' => '', 'name' => ''];
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, '/tabelas/configEmpresa');
    if ($endpoint === '') {
        return ['id' => '', 'name' => ''];
    }
    $endpoint = appendQueryParamsToUrl($endpoint, [
        'db' => $database,
        'limit' => 5000,
        'offset' => 0,
    ]);

    $handle = curl_init($endpoint);
    if ($handle === false) {
        return ['id' => '', 'name' => ''];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-KEY: ' . $token,
        ],
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response) || $response === '' || $status >= 400) {
        return ['id' => '', 'name' => ''];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['id' => '', 'name' => ''];
    }
    $rows = [];
    if (isset($decoded['aaData']) && is_array($decoded['aaData'])) {
        $rows = $decoded['aaData'];
    }
    if (empty($rows)) {
        return ['id' => '', 'name' => ''];
    }

    $best = ['id' => '', 'name' => '', 'score' => -1];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['Id'] ?? ''));
        $name = trim((string) ($row['strValor'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        if (!preg_match('/[A-Za-zÀ-ÿ]/u', $name)) {
            continue;
        }
        if (preg_match('/^[0-9\\s.,-]+$/', $name)) {
            continue;
        }
        if (preg_match('/[\\\\\\/\\|@]/', $name)) {
            continue;
        }
        $score = 10;
        if (preg_match('/\\b(LDA|UNIPESSOAL|S\\.?A\\.?|SOCIEDADE)\\b/i', $name)) {
            $score += 30;
        }
        if (mb_strlen($name) >= 15) {
            $score += 10;
        }
        if (mb_strlen($name) >= 25) {
            $score += 10;
        }
        if ($score > $best['score']) {
            $best = ['id' => $id, 'name' => $name, 'score' => $score];
        }
    }

    return ['id' => (string) ($best['id'] ?? ''), 'name' => (string) ($best['name'] ?? '')];
}

function fetchUploadCompanyNameByConfigEmpresaId(string $database, string $companyId, string $baseUrl, string $token): array {
    $database = trim($database);
    $companyId = trim($companyId);
    if ($database === '' || $companyId === '') {
        return ['ok' => false, 'name' => '', 'error' => ''];
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, '/tabelas/configEmpresa');
    if ($endpoint === '') {
        return ['ok' => false, 'name' => '', 'error' => 'URL ERP inválida.'];
    }
    $endpoint = appendQueryParamsToUrl($endpoint, [
        'db' => $database,
        'q' => $companyId,
        'searchField' => 'Id',
    ]);

    $handle = curl_init($endpoint);
    if ($handle === false) {
        return ['ok' => false, 'name' => '', 'error' => 'Não foi possível iniciar a validação da base de dados ERP.'];
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-KEY: ' . $token,
        ],
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = $response === false ? curl_error($handle) : '';
    curl_close($handle);

    if ($response === false) {
        return ['ok' => false, 'name' => '', 'error' => 'Erro ao comunicar com ERP: ' . $curlError];
    }
    if ($status >= 400) {
        return ['ok' => false, 'name' => '', 'error' => 'ERP devolveu erro HTTP ' . $status . ' ao validar a base de dados.'];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'name' => '', 'error' => 'Resposta inválida do ERP ao validar a base de dados.'];
    }

    $successFlag = isset($decoded['success']) ? trim((string) $decoded['success']) : '';
    if ($successFlag === '0' || $successFlag === 'false') {
        $message = trim((string) ($decoded['message'] ?? 'Falha ao validar base de dados no ERP.'));
        return ['ok' => false, 'name' => '', 'error' => $message !== '' ? $message : 'Falha ao validar base de dados no ERP.'];
    }

    $rows = [];
    if (isset($decoded['aaData']) && is_array($decoded['aaData'])) {
        $rows = $decoded['aaData'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $rows = $decoded['data'];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = trim((string) ($row['strValor'] ?? $row['name'] ?? $row['nome'] ?? ''));
        if ($candidate !== '') {
            return ['ok' => true, 'name' => $candidate, 'error' => ''];
        }
    }

    return ['ok' => false, 'name' => '', 'error' => ''];
}

function fetchUploadAcquirerCompanyName(string $database, string $companyId = ''): array {
    $database = trim($database);
    if ($database === '') {
        return ['ok' => false, 'name' => '', 'error' => 'Base de dados ERP inválida.'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'name' => '', 'error' => 'Extensão cURL não disponível.'];
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    $token = trim((string) getSetting('erp_token', ''));
    if ($baseUrl === '' || $token === '') {
        return ['ok' => false, 'name' => '', 'error' => 'Serviço ERP indisponível para validar a base de dados.'];
    }

    $companyId = trim($companyId);
    $primaryId = $companyId !== '' ? $companyId : '384';
    $primaryLookup = fetchUploadCompanyNameByConfigEmpresaId($database, $primaryId, $baseUrl, $token);
    if (!empty($primaryLookup['ok']) && trim((string) ($primaryLookup['name'] ?? '')) !== '') {
        return ['ok' => true, 'name' => trim((string) $primaryLookup['name']), 'error' => ''];
    }
    if (!empty($primaryLookup['error'])) {
        return ['ok' => false, 'name' => '', 'error' => trim((string) $primaryLookup['error'])];
    }

    $candidateName = '';
    if ($companyId === '') {
        $companyId = fetchUploadDatabaseCompanyId($database);
        if ($companyId === '') {
            $fallback = fetchUploadCompanyConfigCandidate($database);
            $companyId = trim((string) ($fallback['id'] ?? ''));
            $candidateName = trim((string) ($fallback['name'] ?? ''));
        }
    }
    if ($companyId === '') {
        if ($candidateName !== '') {
            return ['ok' => true, 'name' => $candidateName, 'error' => ''];
        }
        return ['ok' => false, 'name' => '', 'error' => 'Não foi possível obter o nome da empresa para a base selecionada.'];
    }
    $fallbackLookup = fetchUploadCompanyNameByConfigEmpresaId($database, $companyId, $baseUrl, $token);
    if (!empty($fallbackLookup['ok']) && trim((string) ($fallbackLookup['name'] ?? '')) !== '') {
        return ['ok' => true, 'name' => trim((string) $fallbackLookup['name']), 'error' => ''];
    }
    if ($candidateName !== '') {
        return ['ok' => true, 'name' => $candidateName, 'error' => ''];
    }
    if (!empty($fallbackLookup['error'])) {
        return ['ok' => false, 'name' => '', 'error' => trim((string) $fallbackLookup['error'])];
    }

    return ['ok' => false, 'name' => '', 'error' => 'Não foi possível obter o nome da empresa para a base selecionada.'];
}

function resolveAccountingUploadPath(string $relativeFile): array {
    $relativeFile = trim(str_replace('\\', '/', $relativeFile));
    if ($relativeFile === '') {
        return ['ok' => false, 'error' => 'Ficheiro inválido.'];
    }

    $slug = getCompanySlug();
    if (!$slug) {
        return ['ok' => false, 'error' => 'Empresa não selecionada.'];
    }

    $baseDir = realpath(dirname(__DIR__) . '/uploads/' . $slug . '/accounting/');
    if ($baseDir === false) {
        return ['ok' => false, 'error' => 'Diretório de uploads indisponível.'];
    }

    $fullPath = realpath(dirname(__DIR__) . '/' . ltrim($relativeFile, '/'));
    if ($fullPath === false || strpos($fullPath, $baseDir) !== 0 || !is_file($fullPath)) {
        return ['ok' => false, 'error' => 'Ficheiro inválido.'];
    }

    return [
        'ok' => true,
        'relative' => ltrim($relativeFile, '/'),
        'absolute' => $fullPath,
        'base_dir' => $baseDir,
    ];
}

function normalizeUploadImportDocType(string $value): string {
    $normalized = strtoupper(trim($value));
    if ($normalized === '') {
        return '';
    }
    if (in_array($normalized, ['FTR', 'FATURA-RECIBO', 'FATURA RECIBO', 'FACTURA-RECIBO'], true)) {
        return 'FR';
    }
    if (in_array($normalized, ['FATURA', 'FACTURA'], true)) {
        return 'FT';
    }
    if ($normalized === 'RECIBO') {
        return 'RC';
    }
    return $normalized;
}

function uploadImportDocTypeIsInvoice(string $value): bool {
    $normalized = normalizeUploadImportDocType($value);
    return $normalized === 'FT' || $normalized === 'FR';
}

function uploadImportDocTypeIsReceipt(string $value): bool {
    return normalizeUploadImportDocType($value) === 'RC';
}

function normalizeUploadBooleanFlag($value): string {
    $flag = trim((string) $value);
    return ($flag === '1' || strcasecmp($flag, 'true') === 0) ? '1' : '0';
}

function annotateUploadRowsWithReceiptCompanion(array $rows): array {
    if (empty($rows)) {
        return [];
    }

    $fileFlags = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $file = trim((string) ($row['filename'] ?? ''));
        if ($file === '') {
            continue;
        }
        if (!isset($fileFlags[$file])) {
            $fileFlags[$file] = ['has_invoice' => false, 'has_receipt' => false];
        }
        $docType = (string) ($row['D'] ?? '');
        if (uploadImportDocTypeIsInvoice($docType)) {
            $fileFlags[$file]['has_invoice'] = true;
        } elseif (uploadImportDocTypeIsReceipt($docType)) {
            $fileFlags[$file]['has_receipt'] = true;
        }
    }

    $annotated = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $file = trim((string) ($row['filename'] ?? ''));
        $hasReceiptCompanion = normalizeUploadBooleanFlag($row['has_receipt_companion'] ?? '0') === '1';
        if (
            !$hasReceiptCompanion
            && $file !== ''
            && !empty($fileFlags[$file]['has_invoice'])
            && !empty($fileFlags[$file]['has_receipt'])
            && uploadImportDocTypeIsInvoice((string) ($row['D'] ?? ''))
        ) {
            $hasReceiptCompanion = true;
        }
        $row['has_receipt_companion'] = $hasReceiptCompanion ? '1' : '0';
        $annotated[] = $row;
    }

    return $annotated;
}

function filterUploadRowsPreferInvoicesByFile(array $rows): array {
    if (empty($rows)) {
        return [];
    }

    $fileFlags = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $file = trim((string) ($row['filename'] ?? ''));
        if ($file === '') {
            continue;
        }
        if (!isset($fileFlags[$file])) {
            $fileFlags[$file] = ['has_invoice' => false, 'has_receipt' => false];
        }
        $docType = (string) ($row['D'] ?? '');
        if (uploadImportDocTypeIsInvoice($docType)) {
            $fileFlags[$file]['has_invoice'] = true;
        } elseif (uploadImportDocTypeIsReceipt($docType)) {
            $fileFlags[$file]['has_receipt'] = true;
        }
    }

    $filtered = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $file = trim((string) ($row['filename'] ?? ''));
        if ($file !== '' && !empty($fileFlags[$file]['has_invoice']) && !empty($fileFlags[$file]['has_receipt'])) {
            if (uploadImportDocTypeIsReceipt((string) ($row['D'] ?? ''))) {
                continue;
            }
        }
        $filtered[] = $row;
    }

    return $filtered;
}

function normalizeUploadMoneyValue($value): string {
    if ($value === null || $value === '') {
        return '';
    }
    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }
    if (!is_numeric($value)) {
        return '';
    }
    return number_format((float) $value, 2, '.', '');
}

function summarizeEfaturaRatesForUpload(array $payload): array {
    $buckets = [
        '6' => ['base' => 0.0, 'tax' => 0.0],
        '13' => ['base' => 0.0, 'tax' => 0.0],
        '23' => ['base' => 0.0, 'tax' => 0.0],
    ];
    $lines = $payload['lines'] ?? [];
    if (!is_array($lines)) {
        $lines = [];
    }

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $rate = isset($line['tax_percentage']) ? (float) str_replace(',', '.', (string) $line['tax_percentage']) : null;
        $bucket = null;
        if ($rate !== null) {
            if (abs($rate - 6.0) < 0.2) {
                $bucket = '6';
            } elseif (abs($rate - 13.0) < 0.2) {
                $bucket = '13';
            } elseif (abs($rate - 23.0) < 0.2) {
                $bucket = '23';
            }
        }
        if ($bucket === null) {
            continue;
        }

        $base = isset($line['net_amount']) ? (float) str_replace(',', '.', (string) $line['net_amount']) : 0.0;
        $tax = 0.0;
        if (isset($line['tax_amount'])) {
            $tax = (float) str_replace(',', '.', (string) $line['tax_amount']);
        } elseif (isset($line['total_tax_amount'])) {
            $tax = (float) str_replace(',', '.', (string) $line['total_tax_amount']);
        }
        $buckets[$bucket]['base'] += $base;
        $buckets[$bucket]['tax'] += $tax;
    }

    $hasDetailedLines = false;
    foreach ($buckets as $bucketData) {
        if (abs((float) $bucketData['base']) > 0.00001 || abs((float) $bucketData['tax']) > 0.00001) {
            $hasDetailedLines = true;
            break;
        }
    }

    if (!$hasDetailedLines) {
        $netTotal = isset($payload['net_total']) ? (float) str_replace(',', '.', (string) $payload['net_total']) : 0.0;
        $taxPayable = isset($payload['tax_payable']) ? (float) str_replace(',', '.', (string) $payload['tax_payable']) : 0.0;
        if (abs($netTotal) > 0.00001) {
            $inferredBucket = null;
            if (abs($taxPayable) < 0.00001) {
                $inferredBucket = null;
            } else {
                $ratio = ($taxPayable / $netTotal) * 100;
                if (abs($ratio - 6.0) < 0.3) {
                    $inferredBucket = '6';
                } elseif (abs($ratio - 13.0) < 0.3) {
                    $inferredBucket = '13';
                } elseif (abs($ratio - 23.0) < 0.3) {
                    $inferredBucket = '23';
                }
            }

            if ($inferredBucket !== null) {
                $buckets[$inferredBucket]['base'] = $netTotal;
                $buckets[$inferredBucket]['tax'] = $taxPayable;
            }
        }
    }

    return [
        'I3' => normalizeUploadMoneyValue($buckets['6']['base']),
        'I4' => normalizeUploadMoneyValue($buckets['6']['tax']),
        'I5' => normalizeUploadMoneyValue($buckets['13']['base']),
        'I6' => normalizeUploadMoneyValue($buckets['13']['tax']),
        'I7' => normalizeUploadMoneyValue($buckets['23']['base']),
        'I8' => normalizeUploadMoneyValue($buckets['23']['tax']),
    ];
}

function buildUploadRowFromEfaturaDocument(array $document): array {
    $payload = [];
    if (!empty($document['raw_payload_json']) && is_string($document['raw_payload_json'])) {
        $decoded = json_decode($document['raw_payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    $rawRow = is_array($payload['raw_row'] ?? null) ? $payload['raw_row'] : [];
    $issuerVat = trim((string) ($payload['issuer_vat'] ?? $document['issuer_vat'] ?? ''));
    $issuerName = trim((string) ($payload['issuer_name'] ?? $document['issuer_name'] ?? ''));
    $customerVat = trim((string) ($payload['customer_vat'] ?? $document['customer_vat'] ?? ''));
    $country = trim((string) ($rawRow['paisAdquirente'] ?? $payload['customer_country'] ?? ''));
    $invoiceType = trim((string) ($payload['invoice_type'] ?? $document['invoice_type'] ?? ''));
    $documentStatus = trim((string) ($payload['document_status'] ?? $document['document_status'] ?? ''));
    $invoiceDate = trim((string) ($payload['invoice_date'] ?? $document['invoice_date'] ?? ''));
    $invoiceNo = trim((string) ($payload['invoice_no'] ?? $document['invoice_no'] ?? ''));
    $atcud = trim((string) ($payload['atcud'] ?? $document['atcud'] ?? ''));
    $sourceHash = trim((string) ($payload['source_hash'] ?? $document['source_hash'] ?? ''));
    $taxPayable = normalizeUploadMoneyValue($payload['tax_payable'] ?? $document['tax_payable'] ?? '');
    $grossTotal = normalizeUploadMoneyValue($payload['gross_total'] ?? $document['gross_total'] ?? '');

    $issuerField = $issuerVat;
    if ($issuerVat !== '' && $issuerName !== '') {
        $issuerField .= ' - ' . $issuerName;
    } elseif ($issuerField === '') {
        $issuerField = $issuerName;
    }

    $rateSummary = summarizeEfaturaRatesForUpload($payload);

    return [
        'A' => $issuerField,
        'B' => $customerVat,
        'C' => '',
        'D' => $invoiceType,
        'E' => $documentStatus,
        'F' => $invoiceDate,
        'G' => $invoiceNo,
        'H' => $atcud,
        'I1' => $country,
        'I3' => $rateSummary['I3'],
        'I4' => $rateSummary['I4'],
        'I5' => $rateSummary['I5'],
        'I6' => $rateSummary['I6'],
        'I7' => $rateSummary['I7'],
        'I8' => $rateSummary['I8'],
        'N' => $taxPayable,
        'O' => $grossTotal,
        'Q' => $sourceHash,
        'R' => $sourceHash !== '' ? $sourceHash : $atcud,
    ];
}

function searchEfaturaDocumentsForUpload(PDO $pdo, string $term, int $limit = 20): array {
    $limit = max(1, min(50, $limit));
    $normalizedDigits = preg_replace('/\D+/', '', $term) ?? '';
    $searchTerm = trim($term);

    if (!hasTable('efatura_documents')) {
        return [];
    }

    $sql = 'SELECT id, issuer_vat, issuer_name, customer_vat, invoice_no, atcud, invoice_date, invoice_type, document_status, tax_payable, net_total, gross_total, source_hash, raw_payload_json
            FROM efatura_documents';
    $where = [];
    $params = [];
    if ($normalizedDigits !== '') {
        $where[] = 'REPLACE(REPLACE(REPLACE(TRIM(issuer_vat), \' \', \'\'), \'-\', \'\'), \'.\', \'\') LIKE ?';
        $params[] = '%' . $normalizedDigits . '%';
        $where[] = 'REPLACE(REPLACE(REPLACE(TRIM(customer_vat), \' \', \'\'), \'-\', \'\'), \'.\', \'\') LIKE ?';
        $params[] = '%' . $normalizedDigits . '%';
    }
    if ($searchTerm !== '') {
        $where[] = 'issuer_name LIKE ?';
        $params[] = '%' . $searchTerm . '%';
        $where[] = 'invoice_no LIKE ?';
        $params[] = '%' . $searchTerm . '%';
        $where[] = 'atcud LIKE ?';
        $params[] = '%' . $searchTerm . '%';
        $where[] = 'source_hash LIKE ?';
        $params[] = '%' . $searchTerm . '%';
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' OR ', $where);
    }
    $sql .= ' ORDER BY invoice_date DESC, id DESC LIMIT ' . (int) $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function runQrToolCommand(array $arguments): array {
    $script = __DIR__ . '/detectar_qr.py';
    $popplerPath = getenv('POPPLER_PATH');
    $commandParts = [];
    if ($popplerPath) {
        $commandParts[] = 'POPPLER_PATH=' . escapeshellarg($popplerPath);
    }
    $commandParts[] = escapeshellcmd('python3');
    $commandParts[] = escapeshellarg($script);
    foreach ($arguments as $argument) {
        $commandParts[] = escapeshellarg((string) $argument);
    }
    $cmd = implode(' ', $commandParts) . ' 2>&1';
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    return [
        'command' => $cmd,
        'output' => $output,
        'status' => $ret,
    ];
}

function renderAccountingUploadPreview(string $absoluteFile, int $page = 1): array {
    $previewDpi = (int) getSetting('qr_preview_dpi', '150');
    if ($previewDpi <= 0) {
        $previewDpi = 150;
    }

    $previewPath = $absoluteFile . '.preview-p' . max(1, $page) . '.png';
    $run = runQrToolCommand([
        $absoluteFile,
        '--render-preview',
        '--page',
        (string) max(1, $page),
        '--dpi',
        (string) $previewDpi,
        '--output',
        $previewPath,
        '--json',
    ]);

    if ($run['status'] !== 0 || empty($run['output'])) {
        return ['ok' => false, 'error' => 'Falha ao gerar pré-visualização do documento.'];
    }

    $json = trim((string) end($run['output']));
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || empty($decoded['ok']) || !file_exists($previewPath)) {
        return ['ok' => false, 'error' => 'Pré-visualização inválida.'];
    }

    $slug = getCompanySlug() ?: '';
    $relativePreview = str_replace(dirname(__DIR__) . '/', '', $previewPath);

    return [
        'ok' => true,
        'preview_file' => $relativePreview,
        'preview_width' => (int) ($decoded['width'] ?? 0),
        'preview_height' => (int) ($decoded['height'] ?? 0),
        'page' => (int) ($decoded['page'] ?? $page),
        'page_count' => (int) ($decoded['page_count'] ?? 1),
        'document_file' => str_replace(dirname(__DIR__) . '/', '', $absoluteFile),
    ];
}

function decodeAccountingUploadQrSelection(string $absoluteFile, int $page, array $ratios): array {
    $qrDpi = (int) getSetting('qr_dpi', '150');
    if ($qrDpi <= 0) {
        $qrDpi = 150;
    }

    $crop = implode(',', [
        number_format((float) ($ratios['x'] ?? 0), 6, '.', ''),
        number_format((float) ($ratios['y'] ?? 0), 6, '.', ''),
        number_format((float) ($ratios['w'] ?? 0), 6, '.', ''),
        number_format((float) ($ratios['h'] ?? 0), 6, '.', ''),
    ]);

    $run = runQrToolCommand([
        $absoluteFile,
        '--page',
        (string) max(1, $page),
        '--dpi',
        (string) $qrDpi,
        '--crop-ratios',
        $crop,
    ]);

    $texts = [];
    if ($run['status'] === 0 && !empty($run['output'])) {
        foreach ($run['output'] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $texts[] = $line;
            }
        }
    }

    return [
        'ok' => true,
        'qr_texts' => array_values(array_unique($texts)),
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'preview-image') {
    if (!isLoggedIn()) {
        http_response_code(403);
        exit('Sessão inválida');
    }

    $file = trim((string) ($_GET['file'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $resolved = resolveAccountingUploadPath($file);
    if (empty($resolved['ok'])) {
        http_response_code(400);
        exit('Ficheiro inválido.');
    }

    $preview = renderAccountingUploadPreview((string) $resolved['absolute'], $page);
    if (empty($preview['ok'])) {
        http_response_code(500);
        exit('Falha ao gerar pré-visualização.');
    }

    $previewPath = dirname(__DIR__) . '/' . ltrim((string) $preview['preview_file'], '/');
    if (!is_file($previewPath)) {
        http_response_code(404);
        exit('Pré-visualização não encontrada.');
    }

    header('Content-Type: image/png');
    header('Content-Length: ' . (string) filesize($previewPath));
    header('Cache-Control: private, max-age=300');
    readfile($previewPath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'document-file') {
    if (!isLoggedIn()) {
        http_response_code(403);
        exit('Sessão inválida');
    }

    $file = trim((string) ($_GET['file'] ?? ''));
    $resolved = resolveAccountingUploadPath($file);
    if (empty($resolved['ok'])) {
        http_response_code(400);
        exit('Ficheiro inválido.');
    }

    $absolutePath = (string) $resolved['absolute'];
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($absolutePath));
    header('Content-Disposition: inline; filename="' . basename($absolutePath) . '"');
    header('Cache-Control: private, max-age=300');
    readfile($absolutePath);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'efatura-search') {
    header('Content-Type: application/json');

    if (!isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['results' => []]);
        exit;
    }

    $term = trim((string) ($_GET['q'] ?? ''));
    $pdo = getPDO();
    $documents = searchEfaturaDocumentsForUpload($pdo, $term, 30);
    $results = [];
    foreach ($documents as $document) {
        $issuerVat = trim((string) ($document['issuer_vat'] ?? ''));
        $issuerName = trim((string) ($document['issuer_name'] ?? ''));
        $invoiceNo = trim((string) ($document['invoice_no'] ?? ''));
        $invoiceDate = trim((string) ($document['invoice_date'] ?? ''));
        $grossTotal = normalizeUploadMoneyValue($document['gross_total'] ?? '');
        $labelParts = [trim($issuerVat . ' - ' . $issuerName)];
        if ($invoiceNo !== '') {
            $labelParts[] = $invoiceNo;
        }
        if ($invoiceDate !== '') {
            $labelParts[] = $invoiceDate;
        }
        if ($grossTotal !== '') {
            $labelParts[] = $grossTotal;
        }
        $results[] = [
            'id' => (int) ($document['id'] ?? 0),
            'text' => implode(' | ', array_values(array_filter($labelParts, static function ($value): bool {
                return trim((string) $value) !== '';
            }))),
            'issuer_vat' => $issuerVat,
            'issuer_name' => $issuerName,
            'customer_vat' => trim((string) ($document['customer_vat'] ?? '')),
            'invoice_no' => $invoiceNo,
            'invoice_date' => $invoiceDate,
            'invoice_type' => trim((string) ($document['invoice_type'] ?? '')),
            'gross_total' => $grossTotal,
            'mapped_row' => buildUploadRowFromEfaturaDocument($document),
        ];
    }

    echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'preview-page') {
    header('Content-Type: application/json');

    if (!isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sessão inválida']);
        exit;
    }

    $file = trim((string) ($_GET['file'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $resolved = resolveAccountingUploadPath($file);
    if (empty($resolved['ok'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $resolved['error'] ?? 'Ficheiro inválido.']);
        exit;
    }

    $preview = renderAccountingUploadPreview((string) $resolved['absolute'], $page);
    if (empty($preview['ok'])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $preview['error'] ?? 'Falha ao gerar pré-visualização.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'preview_url' => BASE_URL . 'upload?action=preview-image&file='
            . rawurlencode($file) . '&page=' . rawurlencode((string) $page),
        'document_url' => BASE_URL . 'upload?action=document-file&file=' . rawurlencode($file),
        'preview_width' => (int) $preview['preview_width'],
        'preview_height' => (int) $preview['preview_height'],
        'page' => (int) $preview['page'],
        'page_count' => (int) $preview['page_count'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sessão inválida']);
        exit;
    }

    $newToken = generateCsrfToken();

    if ($action === 'sync-entity') {
        $value = $_POST['value'] ?? '';
        $entityType = $_POST['entity_type'] ?? 'emitter';
        $database = isset($_POST['database']) ? trim((string) $_POST['database']) : trim((string) getSetting('erp_database', ''));
        $acquirerValue = $_POST['acquirer_value'] ?? '';
        if (trim($value) === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Entidade inválida', 'csrf_token' => $newToken]);
            exit;
        }

        $pdo = getPDO();
        $nif = extractVatNumber((string) $value);
        $acquirerNif = extractVatNumber((string) $acquirerValue);
        $acquirerEntity = null;
        $requiresAcquirerDatabase = false;
        if ($acquirerNif !== '') {
            $acquirerEntity = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
            if ($acquirerEntity === null) {
                $acquirerEntity = findAccountingEntity($pdo, $acquirerNif);
            }
            $acquirerDatabase = is_array($acquirerEntity) ? resolveAccountingEntityDatabase($acquirerEntity) : '';
            if ($acquirerDatabase !== '') {
                if ($database === '') {
                    $database = $acquirerDatabase;
                }
            } elseif ($acquirerEntity === null) {
                $requiresAcquirerDatabase = true;
            }
        }
        $debugRemote = null;
        $isDebugMode = getSetting('debug_mode', '0') === '1';
        if ($isDebugMode && $nif !== '' && $database !== '') {
            $debugRemote = fetchAccountingEntityFromErp($nif, 'emitter', true, $database);
        }

        $entity = null;
        if ($database !== '') {
            $entity = ensureAccountingEntity($pdo, (string) $value, ['entity_type' => $entityType, 'erp_database' => $database]);
        } elseif ($nif !== '') {
            $entity = findAccountingEntity($pdo, $nif);
            if ($entity === null) {
                $fallbackName = deriveEntityNameFromField((string) $value, $nif);
                $fallbackData = [
                    'nif' => $nif,
                    'name' => $fallbackName !== '' ? $fallbackName : ('Cliente ' . $nif),
                    'erp_database' => '',
                    'entity_type' => $entityType,
                ];
                saveAccountingEntity($pdo, $fallbackData);
                $entity = findAccountingEntity($pdo, $nif);
            }
        }

        echo json_encode([
            'success' => $entity !== null,
            'entity' => $entity,
            'remote' => $debugRemote,
            'requires_acquirer_database' => $requiresAcquirerDatabase,
            'acquirer' => $acquirerNif !== '' ? [
                'nif' => $acquirerNif,
                'name' => trim((string) (($acquirerEntity['name'] ?? '') ?: deriveEntityNameFromField((string) $acquirerValue, $acquirerNif))),
                'erp_database' => is_array($acquirerEntity) ? resolveAccountingEntityDatabase($acquirerEntity) : '',
            ] : null,
            'csrf_token' => $newToken,
        ]);
        exit;
    } elseif ($action === 'set-acquirer-database') {
        $acquirerNif = extractVatNumber((string) ($_POST['acquirer_nif'] ?? ''));
        $acquirerValue = trim((string) ($_POST['acquirer_value'] ?? ''));
        $selectedDatabase = trim((string) ($_POST['database'] ?? ''));
        $selectedDatabaseId = trim((string) ($_POST['database_id'] ?? ''));

        if ($acquirerNif === '' || $selectedDatabase === '') {
            http_response_code(400);
            echo json_encode(['error' => 'NIF e base de dados são obrigatórios.', 'csrf_token' => $newToken]);
            exit;
        }

        $pdo = getPDO();
        $companyValidation = fetchUploadAcquirerCompanyName($selectedDatabase, $selectedDatabaseId);
        if (empty($companyValidation['ok'])) {
            http_response_code(400);
            echo json_encode([
                'error' => trim((string) ($companyValidation['error'] ?? 'Não foi possível validar a base de dados ERP selecionada.')),
                'csrf_token' => $newToken
            ]);
            exit;
        }

        $existing = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
        if ($existing === null) {
            $existing = findAccountingEntity($pdo, $acquirerNif);
        }
        $name = trim((string) ($companyValidation['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($existing['name'] ?? ''));
        }
        if ($name === '') {
            $source = $acquirerValue !== '' ? $acquirerValue : $acquirerNif;
            $name = deriveEntityNameFromField($source, $acquirerNif);
        }
        if ($name === '') {
            $name = 'Cliente ' . $acquirerNif;
        }

        $saveData = [
            'nif' => $acquirerNif,
            'name' => $name,
            'erp_database' => $selectedDatabase,
            'entity_type' => 'acquirer',
            'erp_client_code' => trim((string) ($existing['erp_client_code'] ?? '')),
        ];
        saveAccountingEntity($pdo, $saveData);

        $stored = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
        echo json_encode([
            'success' => true,
            'entity' => $stored ?: $saveData,
            'csrf_token' => $newToken,
        ]);
        exit;
    } elseif ($action === 'import') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Dados inválidos', 'csrf_token' => $newToken]);
            exit;
        }

        $token = $data['csrf_token'] ?? '';
        if (!validateCsrfToken($token, false)) {
            http_response_code(400);
            echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => $newToken]);
            exit;
        }

        $rows = $data['rows'] ?? [];
        if (!is_array($rows)) {
            http_response_code(400);
            echo json_encode(['error' => 'Dados inválidos', 'csrf_token' => $newToken]);
            exit;
        }
        $rows = annotateUploadRowsWithReceiptCompanion($rows);
        $rows = filterUploadRowsPreferInvoicesByFile($rows);

        $importType = isset($data['import_type']) ? (int)$data['import_type'] : 1;

        $pdo = getPDO();

        // Preencher conta associada, se existir classificação e sincronizar entidade do emitente
        $stmt = $pdo->prepare('SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1');
        $entityCache = [];
        foreach ($rows as &$row) {
            $rawEmitterValue = trim((string) ($row['A'] ?? ''));
            $normalizedEmitterNif = extractVatNumber($rawEmitterValue);
            $a = $normalizedEmitterNif !== '' ? $normalizedEmitterNif : $rawEmitterValue;
            $b = $row['B'] ?? '';
            $d = $row['D'] ?? '';
            if ($rawEmitterValue !== '') {
                $nif = $normalizedEmitterNif;
                if ($nif !== '' && !array_key_exists($nif, $entityCache)) {
                    $entityCache[$nif] = ensureAccountingEntity($pdo, $rawEmitterValue);
                }
            }
            $row['A'] = $a;
            $stmt->execute([$a, $b, $d]);
            $existingAccount = (string) ($stmt->fetchColumn() ?: '');
            $hasReceiptCompanion = normalizeUploadBooleanFlag($row['has_receipt_companion'] ?? '0');
            if ($existingAccount !== '' || $hasReceiptCompanion === '1') {
                $row['account'] = serializeAccountingAccounts(
                    normalizeAccountingAccounts($existingAccount),
                    ['has_receipt_companion' => $hasReceiptCompanion],
                    normalizeAccountingMetadata($existingAccount)
                );
            } else {
                $row['account'] = '';
            }
        }
        unset($row);

        // Inserir linhas na tabela accounting_imports, evitando duplicados reais
        // com base em identificadores documentais, sem bloquear documentos
        // diferentes do mesmo fornecedor por partilharem um field_H pouco específico.
        $insert = $pdo->prepare('INSERT INTO accounting_imports (field_A, field_B, field_C, field_D, field_E, field_F, field_G, field_H, field_I1, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8, field_N, field_O, field_Q, field_R, account, filename, import_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $existsByComposite = $pdo->prepare(
            'SELECT 1 FROM accounting_imports '
            . 'WHERE import_type = ? '
            . 'AND field_A = ? '
            . 'AND field_B = ? '
            . 'AND field_D = ? '
            . 'AND field_F = ? '
            . 'AND field_G = ? '
            . 'AND field_R = ? '
            . 'LIMIT 1'
        );
        $existsByFieldH = $pdo->prepare(
            'SELECT 1 FROM accounting_imports '
            . 'WHERE import_type = ? '
            . 'AND field_H = ? '
            . 'AND field_A = ? '
            . 'AND field_B = ? '
            . 'AND field_D = ? '
            . 'AND field_F = ? '
            . 'AND field_G = ? '
            . 'LIMIT 1'
        );
        foreach ($rows as $row) {
            $fieldA = trim((string) ($row['A'] ?? ''));
            $fieldB = trim((string) ($row['B'] ?? ''));
            $fieldD = trim((string) ($row['D'] ?? ''));
            $fieldF = trim((string) ($row['F'] ?? ''));
            $fieldG = trim((string) ($row['G'] ?? ''));
            $fieldH = $row['H'] ?? '';
            $fieldR = trim((string) ($row['R'] ?? ''));

            $existsByComposite->execute([$importType, $fieldA, $fieldB, $fieldD, $fieldF, $fieldG, $fieldR]);
            if ($existsByComposite->fetchColumn()) {
                continue;
            }

            if ($fieldH !== '') {
                $existsByFieldH->execute([$importType, $fieldH, $fieldA, $fieldB, $fieldD, $fieldF, $fieldG]);
                if ($existsByFieldH->fetchColumn()) {
                    continue;
                }
            }

            $insert->execute([
                $fieldA,
                $fieldB,
                $row['C'] ?? '',
                $fieldD,
                $row['E'] ?? '',
                $fieldF,
                $fieldG,
                $fieldH,
                $row['I1'] ?? '',
                $row['I3'] ?? '',
                $row['I4'] ?? '',
                $row['I5'] ?? '',
                $row['I6'] ?? '',
                $row['I7'] ?? '',
                $row['I8'] ?? '',
                $row['N'] ?? '',
                $row['O'] ?? '',
                $row['Q'] ?? '',
                $fieldR,
                $row['account'] ?? '',
                $row['filename'] ?? '',
                $importType
            ]);

            $insertedId = (int) $pdo->lastInsertId();
            if ($insertedId > 0) {
                $storedRow = [
                    'field_A' => $fieldA,
                    'field_B' => $fieldB,
                    'field_C' => $row['C'] ?? '',
                    'field_F' => $fieldF,
                    'field_G' => $fieldG,
                    'field_H' => $fieldH,
                    'field_R' => $fieldR,
                ];
                reconcileAccountingImportWithEfaturaDocument($pdo, $insertedId, $storedRow);
            }
        }

        echo json_encode(['success' => true, 'csrf_token' => $newToken]);
        exit;
    } elseif ($action === 'manual-qr') {
        $token = trim((string) ($_POST['csrf_token'] ?? ''));
        if ($token === '' || !validateCsrfToken($token, false)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Token CSRF inválido', 'csrf_token' => $newToken]);
            exit;
        }

        $file = trim((string) ($_POST['file'] ?? ''));
        $page = max(1, (int) ($_POST['page'] ?? 1));
        $resolved = resolveAccountingUploadPath($file);
        if (empty($resolved['ok'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $resolved['error'] ?? 'Ficheiro inválido', 'csrf_token' => $newToken]);
            exit;
        }

        $ratios = [
            'x' => max(0, min(1, (float) ($_POST['x'] ?? 0))),
            'y' => max(0, min(1, (float) ($_POST['y'] ?? 0))),
            'w' => max(0, min(1, (float) ($_POST['w'] ?? 0))),
            'h' => max(0, min(1, (float) ($_POST['h'] ?? 0))),
        ];
        if ($ratios['w'] <= 0 || $ratios['h'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Selecione uma zona válida do documento.', 'csrf_token' => $newToken]);
            exit;
        }

        $decoded = decodeAccountingUploadQrSelection((string) $resolved['absolute'], $page, $ratios);
        echo json_encode([
            'success' => true,
            'qr_texts' => $decoded['qr_texts'] ?? [],
            'csrf_token' => $newToken,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } elseif ($action === 'delete') {
        $file = $_POST['file'] ?? '';
        $slug = getCompanySlug();
        $baseDir = realpath(dirname(__DIR__) . '/uploads/' . $slug . '/accounting/');

        if ($file === '' || !$baseDir) {
            http_response_code(400);
            echo json_encode(['error' => 'Ficheiro inválido', 'csrf_token' => $newToken]);
            exit;
        }

        $fullPath = realpath(dirname(__DIR__) . '/' . ltrim($file, '/'));
        if ($fullPath === false || strpos($fullPath, $baseDir) !== 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Ficheiro inválido', 'csrf_token' => $newToken]);
            exit;
        }

        $success = file_exists($fullPath) && unlink($fullPath);
        if ($success) {
            echo json_encode(['success' => true, 'csrf_token' => $newToken]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao eliminar o ficheiro ' . $file, 'csrf_token' => $newToken]);
        }

        exit;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Ação inválida', 'csrf_token' => $newToken]);
        exit;
    }
}

$useDropzone = true;
$useDataTables = true;
$useSelect2 = true;
require_once __DIR__ . '/../header.php';
$csrfToken = generateCsrfToken();
$erpDatabase = trim((string) getSetting('erp_database', ''));
$qrParallelUploads = (int) getSetting('qr_parallel_uploads', '2');
if ($qrParallelUploads <= 0) {
    $qrParallelUploads = 2;
}
if ($qrParallelUploads > 6) {
    $qrParallelUploads = 6;
}
?>
<div class="row mb-3">
    <div class="col-12">
  
    
<form id="multi-upload" class="dropzone d-none d-md-block">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
</form>
</div>



</div>

<div class="row mb-3">

    <div id="qr-results">
        <table id="qr-table" class="table table-striped">
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
                <th width="8%">Base 6%</th>
                <th width="8%">IVA 6%</th>
                <th width="8%">Base 13%</th>
                <th width="8%">IVA 13%</th>
                <th width="8%">Base 23%</th>
                <th width="6%">IVA 23%</th>
                <th width="8%">Total IVA</th>
                <th width="8%">Total</th>
                <th></th>
                <th></th>
                <th data-orderable="false" width="8%" class="text-middle">Ações</th>
            </tr>
        </thead>
        <tbody></tbody>
        </table>
        <button id="import-btn" class="btn btn-success mt-3" style="display: none;">Importar</button>
        <?php if ($comprasActive): ?>
        <button id="import-compras-btn" class="btn btn-primary mt-3" style="display: none;">Importar Compras</button>
        <?php endif; ?>
    </div>

</div>

<div class="row mb-3">
<div class="col-12">
<div id="mobile-upload-actions" class="d-flex gap-2 mb-3 d-md-none">
    <button id="camera-btn" class="btn btn-secondary">Usar câmara</button>
    <button id="mobile-file-btn" class="btn btn-primary">Selecionar PDF</button>
</div>

</div>
</div>


<div class="modal fade" id="uploadAcquirerDatabaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="uploadAcquirerDatabaseForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-database"></i> Base de dados do adquirente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p id="uploadAcquirerDatabaseMessage" class="mb-3">
                        Indique a base de dados ERP para o adquirente.
                    </p>
                    <div class="mb-3">
                        <label for="uploadAcquirerDatabaseInput" class="form-label">Base de dados ERP</label>
                        <input type="text" class="form-control" id="uploadAcquirerDatabaseInput" placeholder="Ex: emp_236" required>
                    </div>
                    <div id="uploadAcquirerDatabaseError" class="alert alert-danger d-none" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="uploadAcquirerDatabaseConfirmBtn">
                        <i class="fa fa-save"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="manualQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1"><i class="fa fa-qrcode"></i> Selecionar zona do QR Code</h5>
                    <div class="text-muted small">
                        <span id="manualQrQueueLabel">Ficheiro 1 de 1</span>
                        <span class="mx-1">|</span>
                        <span id="manualQrFileName">-</span>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    Abra a página correta, desenhe um retângulo sobre o QR Code e depois clique em <strong>Ler QR selecionado</strong>.
                </div>
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <button type="button" class="btn btn-secondary btn-sm" id="manualQrPrevPageBtn"><i class="fa fa-chevron-left"></i> Página anterior</button>
                        <span id="manualQrPageLabel" class="mx-2">Página 1 de 1</span>
                        <button type="button" class="btn btn-secondary btn-sm" id="manualQrNextPageBtn">Página seguinte <i class="fa fa-chevron-right"></i></button>
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="btn-group btn-group-sm" role="group" aria-label="Zoom da pré-visualização">
                            <button type="button" class="btn btn-default" id="manualQrZoom100Btn">100%</button>
                            <button type="button" class="btn btn-default" id="manualQrZoom150Btn">150%</button>
                            <button type="button" class="btn btn-default" id="manualQrZoom200Btn">200%</button>
                        </div>
                        <a href="#" target="_blank" rel="noopener" class="btn btn-default btn-sm" id="manualQrOpenFileBtn"><i class="fa fa-file-pdf-o"></i> Abrir anexo</a>
                    </div>
                </div>
                <div id="manualQrError" class="alert alert-danger d-none"></div>
                <div class="x_panel manual-efatura-panel">
                    <div class="x_title">
                        <h2><i class="fa fa-search"></i> Sugestao por E-fatura</h2>
                        <div class="clearfix"></div>
                    </div>
                    <div class="x_content">
                        <p class="text-muted">
                            Se o QR nao for detetado, pesquise pelo NIF ou nome do emitente e associe um documento importado do E-fatura a este ficheiro.
                        </p>
                        <div class="row">
                            <div class="col-md-8 col-sm-12">
                                <label for="manualQrEfaturaSelect" class="form-label">Documento E-fatura</label>
                                <select id="manualQrEfaturaSelect" class="form-control"></select>
                            </div>
                            <div class="col-md-4 col-sm-12">
                                <label class="form-label d-block">&nbsp;</label>
                                <button type="button" class="btn btn-primary" id="manualQrApplyEfaturaBtn">
                                    <i class="fa fa-link"></i> Associar documento
                                </button>
                            </div>
                        </div>
                        <div id="manualQrEfaturaInfo" class="alert alert-info d-none mt-3"></div>
                        <div id="manualQrEfaturaError" class="alert alert-danger d-none mt-3"></div>
                    </div>
                </div>
                <div id="manualQrLoading" class="dataTables_processing panel panel-default d-none" style="display: block;">
                    <span><i class="fa fa-spinner fa-spin"></i> A gerar pré-visualização...</span>
                </div>
                <div class="manual-qr-stage">
                    <div class="manual-qr-canvas-wrap" id="manualQrCanvasWrap">
                        <img id="manualQrPreviewImage" class="img-responsive" alt="Pré-visualização do documento">
                        <div id="manualQrSelectionBox"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="manualQrDiscardBtn"><i class="fa fa-trash"></i> Ignorar ficheiro</button>
                <button type="button" class="btn btn-default" id="manualQrClearBtn"><i class="fa fa-eraser"></i> Limpar seleção</button>
                <button type="button" class="btn btn-primary" id="manualQrDecodeBtn"><i class="fa fa-qrcode"></i> Ler QR selecionado</button>
            </div>
        </div>
    </div>
</div>

<style>
.manual-qr-stage {
    max-height: 70vh;
    overflow: auto;
    border: 1px solid #ddd;
    background: #f7f7f7;
    padding: 10px;
}

.manual-qr-canvas-wrap {
    position: relative;
    display: inline-block;
    cursor: crosshair;
}

#manualQrPreviewImage {
    display: block;
    height: auto;
}

#manualQrPreviewImage.is-hidden {
    visibility: hidden;
}

#manualQrSelectionBox {
    position: absolute;
    border: 2px dashed #26b99a;
    background: rgba(38, 185, 154, 0.18);
    display: none;
    pointer-events: none;
}

#multi-upload .dz-preview.qr-auto-detected .dz-image {
    border: 2px solid #26b99a;
    background: #dff5ee;
    box-shadow: 0 0 0 3px rgba(38, 185, 154, 0.18);
}

#multi-upload .dz-preview.qr-manual-required .dz-image {
    border: 2px solid #f0ad4e;
    background: #fff4df;
    box-shadow: 0 0 0 3px rgba(240, 173, 78, 0.18);
}
.manual-efatura-panel {
    margin-bottom: 15px;
}
</style>

<?php
$pageScripts = "window.erpDatabase = " . json_encode($erpDatabase, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ";\n"
    . "window.accountingUploadPreviewUrl = " . json_encode((string) (BASE_URL . 'upload?action=preview-page'), JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.accountingUploadManualQrUrl = " . json_encode((string) (BASE_URL . 'upload?action=manual-qr'), JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.accountingUploadEfaturaSearchUrl = " . json_encode((string) (BASE_URL . 'upload?action=efatura-search'), JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.accountingUploadDeleteUrl = " . json_encode((string) (BASE_URL . 'upload?action=delete'), JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.accountingUploadParallelUploads = " . json_encode($qrParallelUploads, JSON_UNESCAPED_UNICODE) . ";\n"
    . "window.accountingUploadDebug = " . json_encode(getSetting('debug_mode', '0') === '1', JSON_UNESCAPED_UNICODE) . ";\n";
?>
<?php require_once __DIR__ . '/../footer.php'; ?>
