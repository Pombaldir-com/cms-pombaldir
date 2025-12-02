<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

if (!function_exists('normalizeSupplierPartyValue')) {
    function normalizeSupplierPartyValue($value): string {
        $string = trim((string) ($value ?? ''));
        if ($string === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($string, 0, 255, 'UTF-8');
        }

        return substr($string, 0, 255);
    }
}

if (!function_exists('normalizeDocTypeValue')) {
    function normalizeDocTypeValue($value): string {
        $string = trim((string) ($value ?? ''));
        if ($string === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($string, 0, 50, 'UTF-8');
        }

        return substr($string, 0, 50);
    }
}

$useDataTables = true;
$useDropzone = false;

$pdo = getPDO();
$ocrSkipMap = fetchOcrSkipMap($pdo);
$action = $_GET['action'] ?? '';
$importType = (int)($_GET['import_type'] ?? 1);
$currentErpWebserviceUrl = trim((string) getSetting('erp_webservice_url', ''));
$currentErpToken = trim((string) getSetting('erp_token', ''));

function buildDocumentFileAttachment(string $relativePath): ?array {
    $trimmedPath = trim($relativePath);
    if ($trimmedPath === '') {
        return null;
    }

    $relativePath = ltrim($trimmedPath, '/');
    $projectRoot = dirname(__DIR__);
    $absolutePath = realpath($projectRoot . '/' . $relativePath);

    if ($absolutePath === false || !is_file($absolutePath) || !is_readable($absolutePath)) {
        logErpMessage('Ficheiro associado ao documento CTB não encontrado ou inacessível: ' . $trimmedPath);
        return null;
    }

    $uploadsDir = realpath($projectRoot . '/uploads');
    if ($uploadsDir !== false && strpos($absolutePath, $uploadsDir) !== 0) {
        logErpMessage('Ficheiro CTB fora do diretório permitido: ' . $trimmedPath);
        return null;
    }

    $content = file_get_contents($absolutePath);
    if ($content === false) {
        logErpMessage('Falha ao ler ficheiro CTB: ' . $trimmedPath);
        return null;
    }

    $size = filesize($absolutePath);
    if ($size === false) {
        $size = strlen($content);
    }

    $mimeType = 'application/pdf';
    if (class_exists(finfo::class)) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $detected = $finfo->file($absolutePath);
            if (is_string($detected) && $detected !== '') {
                $mimeType = $detected;
            }
        }
    }

    return [
        'path' => $relativePath,
        'filename' => basename($absolutePath),
        'size' => $size,
        'mime_type' => $mimeType,
        'content_base64' => base64_encode($content),
    ];
}

/**
 * Remove payload fields that may contain large base64 blobs before returning a
 * response to the browser. The ERP service often echoes back the attachment
 * contents which greatly increases the payload and can freeze the UI when it
 * is logged in the console. We strip those fields and also normalise any JSON
 * strings we find so that nested structures are sanitised as well.
 */
function sanitizeServiceDebugPayload(mixed $value, int $depth = 0): mixed {
    if ($depth > 8) {
        return $value;
    }

    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && stripos($key, 'content_base64') !== false) {
                $clean[$key] = '[omitted: base64 content]';
                continue;
            }

            $clean[$key] = sanitizeServiceDebugPayload($item, $depth + 1);
        }

        return $clean;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $sanitized = sanitizeServiceDebugPayload($decoded, $depth + 1);
                $reEncoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($reEncoded)) {
                    return $reEncoded;
                }
            }
        }
    }

    return $value;
}

function normalizeCabIdValue(mixed $value): ?string {
    if (is_array($value) && array_key_exists('id', $value)) {
        $value = $value['id'];
    } elseif (is_object($value) && property_exists($value, 'id')) {
        $value = $value->id;
    }

    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $string = trim((string) $value);
    if ($string === '') {
        return null;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($string, 0, 100, 'UTF-8');
    }

    return substr($string, 0, 100);
}

function buildCabIdAssignments(mixed $cabIdsPayload, array $documentIds): array {
    $docIds = array_values(array_unique(array_map('intval', $documentIds)));
    $docIds = array_values(array_filter($docIds, static fn(int $id): bool => $id > 0));
    if (empty($docIds)) {
        return [];
    }

    if (is_string($cabIdsPayload)) {
        $decoded = json_decode($cabIdsPayload, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $cabIdsPayload = $decoded;
        }
    }

    if (!is_array($cabIdsPayload)) {
        $single = normalizeCabIdValue($cabIdsPayload);
        if ($single === null) {
            return [];
        }
        return [$docIds[0] => $single];
    }

    $assignments = [];
    $unmappedCabIds = [];
    $docIdHints = ['document_id', 'doc_id', 'documentId', 'docId', 'document', 'documento', 'doc'];

    foreach ($cabIdsPayload as $key => $value) {
        $cabIdValue = normalizeCabIdValue($value);
        if ($cabIdValue === null) {
            continue;
        }

        $docIdFromValue = null;
        if (is_array($value)) {
            foreach ($docIdHints as $hintKey) {
                if (isset($value[$hintKey]) && $value[$hintKey] !== '') {
                    $docIdFromValue = (int) $value[$hintKey];
                    break;
                }
            }
        }

        $docIdFromKey = null;
        if (is_numeric($key)) {
            $docIdFromKey = (int) $key;
        } elseif (is_string($key) && ctype_digit($key)) {
            $docIdFromKey = (int) $key;
        }

        $targetDocId = null;
        if ($docIdFromValue !== null && in_array($docIdFromValue, $docIds, true)) {
            $targetDocId = $docIdFromValue;
        } elseif ($docIdFromKey !== null && in_array($docIdFromKey, $docIds, true)) {
            $targetDocId = $docIdFromKey;
        }

        if ($targetDocId !== null) {
            $assignments[$targetDocId] = $cabIdValue;
            $docIds = array_values(array_filter($docIds, static fn(int $id): bool => $id !== $targetDocId));
            continue;
        }

        $unmappedCabIds[] = $cabIdValue;
    }

    $remainingDocIds = $docIds;
    foreach ($unmappedCabIds as $index => $cabIdValue) {
        if (!isset($remainingDocIds[$index])) {
            break;
        }
        $assignments[$remainingDocIds[$index]] = $cabIdValue;
    }

    return $assignments;
}

function persistCabIds(PDO $pdo, array $documentIds, mixed $cabIdsPayload): array {
    $assignments = buildCabIdAssignments($cabIdsPayload, $documentIds);
    if (empty($assignments)) {
        return [];
    }

    $stmt = $pdo->prepare('UPDATE accounting_imports SET cab_id = :cab_id WHERE id = :id');
    $saved = [];

    foreach ($assignments as $docId => $cabId) {
        try {
            $stmt->execute([
                ':cab_id' => $cabId,
                ':id' => $docId,
            ]);
            $saved[$docId] = $cabId;
        } catch (Throwable $throwable) {
            logErpMessage('Falha ao guardar cab_id para o documento ' . $docId . ': ' . $throwable->getMessage());
        }
    }

    return $saved;
}

function buildOcrPreferenceKey($emitter, $acquirer, $docType): string {
    $emitterKey = normalizeSupplierPartyValue($emitter);
    $acquirerKey = normalizeSupplierPartyValue($acquirer);
    $docTypeKey = normalizeDocTypeValue($docType);
    if ($emitterKey === '' || $acquirerKey === '' || $docTypeKey === '') {
        return '';
    }
    return implode('|', [$emitterKey, $acquirerKey, $docTypeKey]);
}

function fetchOcrSkipMap(PDO $pdo): array {
    $stmt = $pdo->prepare(
        'SELECT emitter, acquirer, doc_type FROM accounting_classifications WHERE skip_ocr_lines = 1'
    );
    $stmt->execute();
    $map = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = buildOcrPreferenceKey($row['emitter'] ?? '', $row['acquirer'] ?? '', $row['doc_type'] ?? '');
        if ($key !== '') {
            $map[$key] = true;
        }
    }
    return $map;
}

function import_CTB(PDO $pdo, array $ids, int $importType, string $database = ''): array {
    $result = [
        'success' => false,
        'error' => '',
        'status' => 0,
        'response' => null,
    ];

    $database = trim($database);

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

    $endpointPath = 'contabilidade/movimentos';
    $payloadAction = 'movimentos';
    if ($importType === 2) {
        $endpointPath = 'compras';
        $payloadAction = 'compras';
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, $endpointPath);
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
        'Content-Type: application/json; charset=UTF-8',
    ];

    $token = trim((string) getSetting('erp_token', ''));
    if ($token !== '') {
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

        if (isset($document['filename'])) {
            $attachment = buildDocumentFileAttachment((string) $document['filename']);
            if ($attachment !== null) {
                $document['file_attachment'] = $attachment;
            }
        }



        return $document;
    }, $documents);

    $documentsWithoutLines = [];
    foreach ($documentsPayload as &$documentPayload) {
        $originalAccountConfig = $documentPayload['account'] ?? '';
        $documentPayload['account_configuration'] = $originalAccountConfig;
        $documentPayload['cost_center_configuration'] = $documentPayload['cost_center'] ?? '';
        $accountLines = buildDocumentAccountingLines($documentPayload);
        $documentPayload['account_lines'] = $accountLines;
        $documentPayload['account'] = $accountLines;

        if (empty($accountLines)) {
            $docLabel = trim((string) ($documentPayload['field_G'] ?? ''));
            if ($docLabel === '') {
                $docLabel = 'ID ' . ($documentPayload['id'] ?? '?');
            }
            $documentsWithoutLines[] = $docLabel;
        }
    }
    unset($documentPayload);

    if (!empty($documentsWithoutLines)) {
        $result['error'] = 'Existem documentos sem linhas contabilísticas configuradas: ' . implode(', ', $documentsWithoutLines);
        $result['error_detail'] = $result['error'];
        return $result;
    }

    $tpValue = 'importMovim';
    $actValue = 'importMovim';
    if ($importType === 2) {
        $tpValue = '';
        $actValue = '';
    }

    $postPayload = [
        'tp' => $tpValue,
        'act' => $actValue,
        'accao' => $payloadAction,
        'import_type' => $importType,
        'document_ids' => array_values($ids),
        'documents' => $documentsPayload,
        'database' => $database,
    ];

    if ($importType === 2) {
        $sectionCode = trim((string) getSetting('compras_section', ''));
        $documentType = trim((string) getSetting('compras_document_type', ''));

        if ($sectionCode !== '') {
            $postPayload['strCodSeccao'] = $sectionCode;
        }

        if ($documentType !== '') {
            $postPayload['strAbrevTpDoc'] = $documentType;
        }
    }

    $postFields = json_encode($postPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($postFields === false) {
        $result['error'] = 'Falha ao preparar os documentos para envio.';
        logErpMessage('Erro ao codificar JSON dos documentos CTB: ' . json_last_error_msg());
        return $result;
    }

    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $postFields,
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

    if ($response === false) {
        $errorMessage = curl_error($handle);
        curl_close($handle);
        $result['error'] = 'Erro ao comunicar com o webservice de contabilidade.';
        logErpMessage('Erro cURL ao importar movimentos CTB: ' . $errorMessage . $endpointInfo);
        return $result;
    }

    $trimmedResponse = is_string($response) ? trim($response) : '';
    if ($trimmedResponse === '') {
        curl_close($handle);
        $result['status'] = $status;
        $result['response'] = $response;

        if ($status === 204) {
            $result['success'] = true;
            $result['message'] = 'O webservice de contabilidade devolveu HTTP 204 sem conteúdo.';
            logErpMessage('Webservice CTB devolveu HTTP 204 sem conteúdo ao importar movimentos. A assumir sucesso.' . $endpointInfo);
            return $result;
        }

        $result['success'] = false;
        $result['error'] = 'O webservice de contabilidade devolveu uma resposta vazia (HTTP ' . $status . ').';
        logErpMessage('Webservice CTB devolveu resposta vazia ao importar movimentos. HTTP ' . $status . $endpointInfo);
        return $result;
    }

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

            foreach (['logmsg', 'log'] as $logField) {
                if (isset($decodedResponse[$logField])) {
                    $result['log'] = $decodedResponse[$logField];
                    break;
                }
            }

            $logDetail = $errorDetail !== '' ? (' Detalhe: ' . $errorDetail) : '';
            logErpMessage('Webservice CTB devolveu sucesso=0 ao importar movimentos.' . $endpointInfo . $logDetail);

            $result['success'] = false;
            return $result;
        }

        if ($webserviceSuccess === true) {
            $result['success'] = true;
        }

        if (array_key_exists('cab_ids', $decodedResponse)) {
            $savedCabIds = persistCabIds($pdo, $ids, $decodedResponse['cab_ids']);
            if (!empty($savedCabIds)) {
                $result['cab_id_map'] = $savedCabIds;
                $logPayload = json_encode($savedCabIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $logMessage = is_string($logPayload) ? $logPayload : '';
                if ($logMessage !== '') {
                    logErpMessage('IDs de cabeçalho associados aos documentos: ' . $logMessage);
                }
            }
        }

        $messageFields = ['mensagem', 'message', 'msg'];
        foreach ($messageFields as $messageField) {
            if (isset($decodedResponse[$messageField])) {
                $candidateMessage = trim((string) $decodedResponse[$messageField]);
                if ($candidateMessage !== '') {
                    $result['message'] = $candidateMessage;
                    break;
                }
            }
        }

        foreach (['logmsg', 'log'] as $logField) {
            if (isset($decodedResponse[$logField])) {
                $result['log'] = $decodedResponse[$logField];
                break;
            }
        }
    }

    if (empty($result['success'])) {
        $result['success'] = true;
    }

    logErpMessage('Pedido de importação CTB enviado com sucesso. HTTP ' . $status . '. IDs: ' . implode(', ', $ids));

    return $result;
}

function prepareImportRow(array $row): array {
    global $pdo, $ocrSkipMap;

    $rawEmitter = (string)($row['field_A'] ?? '');
    $rawEmitterNif = (string)($row['field_C'] ?? '');
    $normalizedEmitterNif = preg_replace('/\D+/', '', $rawEmitterNif);
    $emitterRawValue = trim($rawEmitter);
    $emitterName = $emitterRawValue;

    if ($normalizedEmitterNif !== '') {
        static $entityNameCache = [];
        if (!array_key_exists($normalizedEmitterNif, $entityNameCache)) {
            $cachedName = null;
            if (isset($pdo) && $pdo instanceof PDO && function_exists('findAccountingEntity')) {
                $entity = findAccountingEntity($pdo, $normalizedEmitterNif);
                if (is_array($entity)) {
                    $candidate = trim((string)($entity['name'] ?? ''));
                    if ($candidate !== '') {
                        $cachedName = $candidate;
                    }
                }
            }
            $entityNameCache[$normalizedEmitterNif] = $cachedName;
        }

        $cachedName = $entityNameCache[$normalizedEmitterNif];
        if (is_string($cachedName) && $cachedName !== '') {
            $emitterName = $cachedName;
        }
    }

    if ($emitterName === '') {
        $emitterName = $normalizedEmitterNif !== '' ? $normalizedEmitterNif : trim($rawEmitterNif);
    }

    $row['emitter_raw_value'] = $emitterRawValue;
    $row['emitter_display_name'] = $emitterName;
    if ($normalizedEmitterNif !== '') {
        $row['emitter_nif_normalized'] = $normalizedEmitterNif;
    }

    $accounts = normalizeAccountingAccounts($row['account'] ?? '');
    $accountMetadata = normalizeAccountingMetadata($row['account'] ?? '');
    $summaries = computeImportRateSummaries($row);
    [$payload, $requirements] = buildRatePayload($summaries, $accounts);
    $row['rate_payload'] = $payload;
    $row['rate_requirements'] = $requirements;
    $row['cost_centers'] = normalizeCostCenters($row['cost_center'] ?? '');
    $row['btn_class'] = determineClassificationButtonClass($requirements, $payload, $accountMetadata);
    $row['total_account'] = $accountMetadata['total_account'] ?? '';
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
    if ($row['line_btn_class'] !== 'btn-success') {
        $skipKey = buildOcrPreferenceKey($row['field_A'] ?? '', $row['field_B'] ?? '', $row['field_D'] ?? '');
        if ($skipKey !== '' && isset($ocrSkipMap[$skipKey])) {
            $row['line_btn_class'] = 'btn-success';
        }
    }

    return $row;
}

/**
 * Retrieve unique acquirer entities for the provided import rows.
 *
 * @param PDO  $pdo        Active database connection.
 * @param array $ids       Selected import identifiers.
 * @param int   $importType Current import type.
 * @return array<int, array<string, mixed>>
 */
function collectAcquirerEntities(PDO $pdo, array $ids, int $importType): array {
    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $sql = 'SELECT field_B, field_C FROM accounting_imports WHERE import_type = ? AND id IN (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $importType, PDO::PARAM_INT);

    foreach ($ids as $index => $id) {
        $stmt->bindValue($index + 2, $id, PDO::PARAM_INT);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }

    $entities = [];

    foreach ($rows as $row) {
        $fieldB = trim((string)($row['field_B'] ?? ''));
        $fieldC = trim((string)($row['field_C'] ?? ''));

        $candidateValues = [];
        if ($fieldC !== '') {
            $candidateValues[] = $fieldC;
        }
        if ($fieldB !== '') {
            $candidateValues[] = $fieldB;
        }

        $acquirerNif = '';
        foreach ($candidateValues as $candidateValue) {
            $candidateNif = extractVatNumber($candidateValue);
            if ($candidateNif !== '') {
                $acquirerNif = $candidateNif;
                break;
            }
        }

        if ($acquirerNif === '') {
            continue;
        }

        if (array_key_exists($acquirerNif, $entities)) {
            continue;
        }

        $preferredValue = $fieldB !== '' ? $fieldB : ($fieldC !== '' ? $fieldC : $acquirerNif);
        $displayName = trim($preferredValue);
        if ($displayName === '') {
            $displayName = $acquirerNif;
        }

        $entity = null;
        try {
            $entity = ensureAccountingEntity($pdo, $preferredValue, ['entity_type' => 'acquirer']);
        } catch (Throwable $throwable) {
            logErpMessage('Erro ao garantir adquirente ' . $acquirerNif . ': ' . $throwable->getMessage());
        }

        if ($entity === null) {
            try {
                $entity = findAccountingEntity($pdo, $acquirerNif);
            } catch (Throwable $throwable) {
                logErpMessage('Erro ao pesquisar adquirente ' . $acquirerNif . ': ' . $throwable->getMessage());
            }
        }

        if ($entity === null) {
            $entity = [
                'id' => null,
                'nif' => $acquirerNif,
                'name' => $displayName !== '' ? $displayName : 'Cliente ' . $acquirerNif,
                'erp_database' => '',
                'entity_type' => 'acquirer',
                'erp_client_code' => '',
            ];
        }

        $entityType = trim((string)($entity['entity_type'] ?? ''));
        if ($entityType === '') {
            $entityType = 'acquirer';
        }

        $entityInfo = [
            'id' => $entity['id'] ?? null,
            'nif' => $acquirerNif,
            'name' => trim((string)($entity['name'] ?? $displayName)) ?: ($displayName !== '' ? $displayName : 'Cliente ' . $acquirerNif),
            'erp_database' => trim((string)($entity['erp_database'] ?? '')),
            'entity_type' => $entityType,
            'erp_client_code' => trim((string)($entity['erp_client_code'] ?? '')),
            'display_name' => $displayName,
            'source_value' => $preferredValue,
        ];

        $entities[$acquirerNif] = $entityInfo;
    }

    return array_values($entities);
}

if ($action === 'acquirer_database' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?? '', true);

    $response = [
        'success' => false,
        'requires_selection' => false,
        'entity' => null,
    ];

    if (!is_array($payload)) {
        $response['error'] = 'Pedido inválido';
        $response['csrf_token'] = generateCsrfToken(true);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $csrfToken = (string)($payload['csrf_token'] ?? '');
    if ($csrfToken === '' || !validateCsrfToken($csrfToken)) {
        $response['error'] = 'Token CSRF inválido';
        $response['csrf_token'] = generateCsrfToken(true);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $response['csrf_token'] = generateCsrfToken();

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
        $response['error'] = 'Nenhuma linha seleccionada.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $requestedImportType = (int)($payload['import_type'] ?? $importType);
    if ($requestedImportType <= 0) {
        $requestedImportType = 1;
    }

    $mode = strtolower((string)($payload['mode'] ?? 'check'));
    if ($mode !== 'update') {
        $mode = 'check';
    }

    try {
        $entities = collectAcquirerEntities($pdo, $ids, $requestedImportType);
    } catch (Throwable $throwable) {
        logErpMessage('Erro ao obter adquirente para importação CTB: ' . $throwable->getMessage());
        $response['error'] = 'Não foi possível determinar o adquirente.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($entities)) {
        $response['success'] = true;
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (count($entities) > 1) {
        $response['error'] = 'Existe mais do que um adquirente associado às linhas seleccionadas.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $entity = $entities[0];
    $entityResponse = [
        'nif' => $entity['nif'],
        'name' => $entity['name'],
        'display_name' => $entity['display_name'],
        'erp_database' => $entity['erp_database'],
    ];

    if ($mode === 'check') {
        $response['success'] = true;
        $response['entity'] = $entityResponse;
        $response['requires_selection'] = trim((string)$entity['erp_database']) === '';
        if ($response['requires_selection']) {
            $response['message'] = 'Selecione a base de dados do adquirente antes de importar.';
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $selectedDatabase = trim((string)($payload['selected_database'] ?? ''));
    if ($selectedDatabase === '') {
        $response['error'] = 'Selecione uma base de dados válida.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $entityType = trim((string)($entity['entity_type'] ?? ''));
    if ($entityType === '') {
        $entityType = 'acquirer';
    }

    $entityName = trim((string)($entity['name'] ?? ''));
    if ($entityName === '' && ($entity['source_value'] ?? '') !== '') {
        $entityName = deriveEntityNameFromField((string)$entity['source_value'], $entity['nif']);
    }
    if ($entityName === '') {
        $entityName = 'Cliente ' . $entity['nif'];
    }

    $saveData = [
        'nif' => $entity['nif'],
        'name' => $entityName,
        'erp_database' => $selectedDatabase,
        'entity_type' => $entityType,
        'erp_client_code' => trim((string)($entity['erp_client_code'] ?? '')),
    ];

    try {
        saveAccountingEntity($pdo, $saveData);
        $stored = findAccountingEntity($pdo, $entity['nif']);
        if (is_array($stored)) {
            $entityResponse['name'] = trim((string)($stored['name'] ?? $entityName)) ?: $entityName;
            $entityResponse['erp_database'] = trim((string)($stored['erp_database'] ?? $selectedDatabase)) ?: $selectedDatabase;
        } else {
            $entityResponse['name'] = $entityName;
            $entityResponse['erp_database'] = $selectedDatabase;
        }
        $response['success'] = true;
        $response['requires_selection'] = false;
        $response['entity'] = $entityResponse;
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $throwable) {
        logErpMessage('Erro ao atualizar base de dados do adquirente ' . $entity['nif'] . ': ' . $throwable->getMessage());
        $response['error'] = 'Não foi possível guardar a base de dados do adquirente.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'import_ctb' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $_POST['act'] = 'importMovim';

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

    $requestedDatabase = trim((string)($payload['database'] ?? ''));

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

    $targetDatabase = $requestedDatabase;
    if ($targetDatabase === '') {
        try {
            $entities = collectAcquirerEntities($pdo, $ids, $requestedImportType);
            if (!empty($entities)) {
                foreach ($entities as $entity) {
                    $candidateDatabase = trim((string)($entity['erp_database'] ?? ''));
                    if ($candidateDatabase !== '') {
                        $targetDatabase = $candidateDatabase;
                        break;
                    }
                }
            }
        } catch (Throwable $throwable) {
            logErpMessage('Erro ao determinar a base de dados do adquirente para importação CTB: ' . $throwable->getMessage());
        }
    }

    $serviceResult = import_CTB($pdo, $ids, $requestedImportType, $targetDatabase);

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

    $servicePayload = null;
    if (array_key_exists('decoded', $serviceResult)) {
        $servicePayload = sanitizeServiceDebugPayload($serviceResult['decoded']);
        $responsePayload['service_payload'] = $servicePayload;

        if (is_array($servicePayload) && array_key_exists('response', $servicePayload)) {
            $serviceResponse = sanitizeServiceDebugPayload($servicePayload['response']);
            if ($serviceResponse !== null && $serviceResponse !== '') {
                $responsePayload['service_response'] = $serviceResponse;
            }
        }
    }

    if (!array_key_exists('service_response', $responsePayload) && array_key_exists('response', $serviceResult)) {
        $responsePayload['service_response'] = sanitizeServiceDebugPayload($serviceResult['response']);
    }

    if (!empty($serviceResult['message'])) {
        $responsePayload['message'] = $serviceResult['message'];
    }

    if (array_key_exists('log', $serviceResult)) {
        $responsePayload['log'] = $serviceResult['log'];
    }

    if (!empty($serviceResult['cab_id_map'])) {
        $responsePayload['cab_id_map'] = $serviceResult['cab_id_map'];
    }

    $successMessage = '';
    if (is_array($servicePayload)) {
        $messageKeys = ['mensagem', 'message', 'msg', 'mensagem_erro'];
        foreach ($messageKeys as $messageKey) {
            if (isset($servicePayload[$messageKey])) {
                $candidate = trim((string) $servicePayload[$messageKey]);
                if ($candidate !== '') {
                    $successMessage = $candidate;
                    break;
                }
            }
        }
    } elseif (is_string($servicePayload)) {
        $trimmed = trim($servicePayload);
        if ($trimmed !== '') {
            $successMessage = $trimmed;
        }
    }

    if (!empty($serviceResult['success'])) {
        $responsePayload['message'] = $successMessage !== '' ? $successMessage : 'OK';
    } elseif ($successMessage !== '') {
        $responsePayload['message'] = $successMessage;
    }

    $jsonResponse = json_encode($responsePayload, JSON_UNESCAPED_UNICODE);

    if ($jsonResponse === false) {
        $fallbackPayload = [
            'success' => false,
            'error' => 'Não foi possível preparar a resposta da importação.',
            'csrf_token' => generateCsrfToken(),
        ];

        if (function_exists('json_last_error')) {
            $lastError = json_last_error();
            if ($lastError !== JSON_ERROR_NONE && function_exists('json_last_error_msg')) {
                $errorDetail = trim((string) json_last_error_msg());
                if ($errorDetail !== '') {
                    $fallbackPayload['error_detail'] = $errorDetail;
                }
            }
        }

        logErpMessage('Falha ao codificar resposta da importação CTB para JSON: ' . ($fallbackPayload['error_detail'] ?? 'erro desconhecido'));

        $jsonResponse = json_encode($fallbackPayload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($jsonResponse === false) {
            // Em último recurso devolvemos uma resposta mínima válida.
            $jsonResponse = '{"success":false,"error":"Não foi possível preparar a resposta da importação."}';
        }
    }

    echo $jsonResponse;
    exit;
}

if ($action === 'data') {
    $draw = (int)($_GET['draw'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');

    try {
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
            'line_items',
            'cab_id'
        ];

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
            $emitterDisplay = (string)($row['emitter_display_name'] ?? '');
            $emitterRawValue = (string)($row['emitter_raw_value'] ?? '');
            if ($emitterDisplay === '') {
                $emitterDisplay = (string)($row['field_A'] ?? '');
            }
            if ($emitterRawValue === '') {
                $emitterRawValue = (string)($row['field_A'] ?? '');
            }
            $emitterNifValue = (string)($row['emitter_nif_normalized'] ?? ($row['field_C'] ?? ''));
            $emitterDisplayEscaped = htmlspecialchars($emitterDisplay, ENT_QUOTES, 'UTF-8');
            $emitterRawEscaped = htmlspecialchars($emitterRawValue, ENT_QUOTES, 'UTF-8');
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
                    . 'data-total-account="' . htmlspecialchars($row['total_account'] ?? '', ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-emitter="' . $emitterRawEscaped . '" '
                    . 'data-emitter-display="' . $emitterDisplayEscaped . '" '
                    . 'data-emitter-nif="' . htmlspecialchars($emitterNifValue) . '" '
                    . 'data-doc-number="' . htmlspecialchars($row['field_G'] ?? '') . '" '
                    . 'data-acquirer="' . htmlspecialchars($row['field_B'] ?? '') . '" '
                    . 'data-doctype="' . htmlspecialchars($row['field_D'] ?? '') . '">Classificar</button>';
            }
            if ($importType === 2) {
                $actionsParts[] = '<button type="button" class="btn btn-xs ' . $row['line_btn_class'] . ' analyze-lines" '
                    . 'data-id="' . (int)$row['id'] . '" '
                    . 'data-emitter="' . $emitterRawEscaped . '" '
                    . 'data-emitter-display="' . $emitterDisplayEscaped . '" '
                    . 'data-emitter-nif="' . htmlspecialchars($emitterNifValue) . '" '
                    . 'data-acquirer="' . htmlspecialchars($row['field_B'] ?? '') . '" '
                    . 'data-doctype="' . htmlspecialchars($row['field_D'] ?? '') . '" '
                    . 'data-doc-number="' . htmlspecialchars($row['field_G'] ?? '') . '">Analisar</button>';
            }
            $actionsParts[] = '<button type="button" class="btn btn-xs btn-danger remove-row" data-id="' . (int)$row['id'] . '"><i class="fa fa-trash"></i></button>';
            $actions = implode(' ', $actionsParts);
            $pdfLink = '<a href="' . htmlspecialchars($row['filename'] ?? '') . '" target="_blank" class="btn btn-xs btn-secondary"><i class="fa fa-file-pdf-o"></i></a>';
            $data[] = [
                $emitterDisplayEscaped,
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

        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $totalCount,
            'recordsFiltered' => $filteredCount,
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $throwable) {
        http_response_code(500);
        $errorMessage = 'Não foi possível carregar os dados de classificação.';
        if (function_exists('logErpMessage')) {
            logErpMessage('Erro ao obter dados de classificação: ' . $throwable->getMessage());
        }

        $response = [
            'draw' => $draw,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => $errorMessage,
        ];

        $detail = trim($throwable->getMessage());
        if ($detail !== '') {
            $response['error_detail'] = $detail;
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
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
$showImportButton = in_array($importType, [1, 2], true);
$importButtonLabel = $importType === 2 ? 'Importar Compras' : 'Importar Ctb';

require_once __DIR__ . '/../header.php';
?>
<input type="hidden" id="import_type" value="<?= htmlspecialchars($importType); ?>">
<div class="row mb-3">
    <div class="col-12">
        <?php if ($showImportButton): ?>


        <div id="importCtbButtonWrapper" class="d-none">
            <button type="button" class="btn btn-sm btn-primary" id="importCtbButton" disabled>
                <i class="fa fa-cloud-upload"></i> <?= htmlspecialchars($importButtonLabel); ?>
            </button>
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
                <?php
                    $emitterDisplay = (string)($row['emitter_display_name'] ?? '');
                    $emitterRawValue = (string)($row['emitter_raw_value'] ?? '');
                    if ($emitterDisplay === '') {
                        $emitterDisplay = (string)($row['field_A'] ?? '');
                    }
                    if ($emitterRawValue === '') {
                        $emitterRawValue = (string)($row['field_A'] ?? '');
                    }
                    $emitterNifValue = (string)($row['emitter_nif_normalized'] ?? ($row['field_C'] ?? ''));
                ?>
                <tr>
                    <td class="text-start"><?= htmlspecialchars($emitterDisplay); ?></td>
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
                                data-emitter="<?= htmlspecialchars($emitterRawValue); ?>"
                                data-emitter-display="<?= htmlspecialchars($emitterDisplay); ?>"
                                data-emitter-nif="<?= htmlspecialchars($emitterNifValue); ?>"
                                data-doc-number="<?= htmlspecialchars($row['field_G'] ?? ''); ?>"
                                data-acquirer="<?= htmlspecialchars($row['field_B'] ?? ''); ?>"
                                data-doctype="<?= htmlspecialchars($row['field_D'] ?? ''); ?>">Classificar</button>
                        <?php endif; ?>

                        <?php if ($importType === 2): ?>
                        <button type="button" class="btn btn-xs <?= htmlspecialchars($row['line_btn_class'] ?? 'btn-info'); ?> analyze-lines"
                                data-id="<?= (int)$row['id']; ?>"
                                data-emitter="<?= htmlspecialchars($row['field_A'] ?? ''); ?>"
                                data-emitter-display="<?= htmlspecialchars($row['emitter_display_name'] ?? ($row['field_A'] ?? '')); ?>"
                                data-emitter-nif="<?= htmlspecialchars($row['emitter_nif_normalized'] ?? ''); ?>"
                                data-acquirer="<?= htmlspecialchars($row['field_B'] ?? ''); ?>"
                                data-doctype="<?= htmlspecialchars($row['field_D'] ?? ''); ?>"
                                data-doc-number="<?= htmlspecialchars($row['field_G'] ?? ''); ?>">Analisar</button>
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
                    <?php if ($importType === 1): ?>
                    <div class="mt-3">
                        <label for="totalAccountInput" class="form-label mb-1">Valor Total</label>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <input
                                type="text"
                                class="form-control form-control-sm w-auto"
                                id="totalAccountInput"
                                placeholder="Conta para o valor total"
                                style="min-width: 160px; max-width: 220px;"
                            >
                            <small class="text-muted">Será enviada como última linha, com o total do documento e NIF.</small>
                        </div>
                    </div>
                    <?php endif; ?>
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
<div class="modal fade" id="acquirerDatabaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="acquirerDatabaseForm">
                <div class="modal-header">
                    <h5 class="modal-title">Selecionar base de dados</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p id="acquirerDatabaseMessage" class="mb-3">Selecione a base de dados do adquirente.</p>
                    <div id="acquirerDatabaseLoading" class="d-none text-muted mb-3">
                        <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                        A carregar bases de dados...
                    </div>
                    <div class="mb-3">
                        <label for="acquirerDatabaseSelect" class="form-label">Base de dados</label>
                        <select class="form-select" id="acquirerDatabaseSelect" required>
                            <option value="" disabled selected>Selecione uma base de dados</option>
                        </select>
                    </div>
                    <div id="acquirerDatabaseError" class="alert alert-danger d-none" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="confirmAcquirerDatabaseBtn">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
    window.erpWebserviceUrl = <?= json_encode(
        $currentErpWebserviceUrl,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>;
    window.erpWebserviceToken = <?= json_encode(
        $currentErpToken ?? '',
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>;
</script>
<script src="assets/js/pnotify_theme_adapter.js"></script>
<script src="assets/js/classificacao_importacao.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>
