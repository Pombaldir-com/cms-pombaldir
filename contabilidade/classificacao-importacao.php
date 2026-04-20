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

if (!function_exists('buildClassificationPartyKey')) {
    function buildClassificationPartyKey($value, $fallbackVat = ''): string {
        $fallbackVat = trim((string) ($fallbackVat ?? ''));
        $vat = extractVatNumber($fallbackVat !== '' ? $fallbackVat : (string) $value);
        if ($vat !== '') {
            return $vat;
        }
        return normalizeSupplierPartyValue($value);
    }
}

if (!function_exists('resolveClassificationStorageIdentifiers')) {
    function resolveClassificationStorageIdentifiers($emitter, $acquirer, $docType, array $importRow = []): array {
        $resolvedEmitterSource = array_key_exists('field_A', $importRow) ? $importRow['field_A'] : $emitter;
        $resolvedEmitterVat = array_key_exists('field_C', $importRow) ? $importRow['field_C'] : '';
        $resolvedAcquirerSource = array_key_exists('field_B', $importRow) ? $importRow['field_B'] : $acquirer;
        $resolvedDocType = array_key_exists('field_D', $importRow) ? $importRow['field_D'] : $docType;

        return [
            buildClassificationPartyKey($resolvedEmitterSource, $resolvedEmitterVat),
            buildClassificationPartyKey($resolvedAcquirerSource),
            normalizeDocTypeValue($resolvedDocType),
        ];
    }
}

if (!function_exists('fetchClassificationAccountPayload')) {
    function fetchClassificationAccountPayload(PDO $pdo, $emitter, $acquirer, $docType, array $importRow = []): string {
        $candidates = [];

        $resolved = resolveClassificationStorageIdentifiers($emitter, $acquirer, $docType, $importRow);
        $candidates[] = $resolved;

        $normalizedLegacy = [
            normalizeSupplierPartyValue($emitter),
            normalizeSupplierPartyValue($acquirer),
            normalizeDocTypeValue($docType),
        ];
        $candidates[] = $normalizedLegacy;

        $rawLegacy = [
            trim((string) ($emitter ?? '')),
            trim((string) ($acquirer ?? '')),
            trim((string) ($docType ?? '')),
        ];
        $candidates[] = $rawLegacy;

        $seen = [];
        $stmt = $pdo->prepare(
            'SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
        );

        foreach ($candidates as $candidate) {
            [$candidateEmitter, $candidateAcquirer, $candidateDocType] = $candidate;
            $signature = $candidateEmitter . '|' . $candidateAcquirer . '|' . $candidateDocType;
            if ($candidateEmitter === '' || $candidateAcquirer === '' || $candidateDocType === '' || isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $stmt->execute([$candidateEmitter, $candidateAcquirer, $candidateDocType]);
            $payload = $stmt->fetchColumn();
            if (is_string($payload) && trim($payload) !== '') {
                return $payload;
            }
        }

        return '';
    }
}

if (!function_exists('resolveClassificationTotalAccountForContext')) {
    function resolveClassificationTotalAccountForContext(array $metadata, string $receiptCompanionFlag = '0'): string {
        $normalizedFlag = trim($receiptCompanionFlag) === '1' ? '1' : '0';
        if ($normalizedFlag === '1') {
            $receiptTotalAccount = trim((string) ($metadata['receipt_total_account'] ?? ''));
            if ($receiptTotalAccount !== '') {
                return $receiptTotalAccount;
            }
        }
        return trim((string) ($metadata['total_account'] ?? ''));
    }
}

if (!function_exists('resolveDisplayClassificationAccounts')) {
    function resolveDisplayClassificationAccounts(array $defaultAccounts, array $rowAccounts): array {
        $baseSanitized = sanitizeAccountInput($defaultAccounts);
        $rowSanitized = sanitizeAccountInput($rowAccounts);
        $allRates = array_values(array_unique(array_merge(array_keys($baseSanitized), array_keys($rowSanitized))));
        $result = [];

        foreach ($allRates as $rate) {
            $defaultEntry = $baseSanitized[$rate] ?? [];
            $rowEntry = $rowSanitized[$rate] ?? [];
            $entry = $defaultEntry;

            foreach (['iva_account', 'general_account', 'base', 'iva', 'label', 'base_source_field', 'erp_rubric_code', 'vat_amounts_adjusted'] as $field) {
                $rowValue = isset($rowEntry[$field]) ? trim((string) $rowEntry[$field]) : '';
                if ($rowValue !== '') {
                    $entry[$field] = $rowValue;
                }
            }

            $rowCostCenterRequired = trim((string) ($rowEntry['cost_center_required'] ?? ''));
            if ($rowCostCenterRequired === '1') {
                $entry['cost_center_required'] = '1';
            } elseif (!empty($defaultEntry['cost_center_required'])) {
                $entry['cost_center_required'] = '1';
            }

            $result[$rate] = $entry;
        }

        return $result;
    }
}

if (!function_exists('resolveDocumentLigacaoRubricCodes')) {
    function resolveDocumentLigacaoRubricCodes(PDO $pdo, array $document, array $accounts): array {
        if (empty(getAccountingFuelRubricCodes())) {
            return [];
        }

        $missingRates = [];
        foreach ($accounts as $rate => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (normalizeAccountingRubricCodeValue($entry['erp_rubric_code'] ?? '') !== '') {
                continue;
            }
            $missingRates[] = (string) $rate;
        }
        if (empty($missingRates)) {
            return [];
        }

        $docType = trim((string) ($document['field_D'] ?? ''));
        $docDate = normalizeSuggestionDocDate((string) ($document['field_F'] ?? ''));
        $ligacaoDocType = normalizeSuggestionLigacaoDocType($docType);
        if ($docDate === '' || $ligacaoDocType === '') {
            return [];
        }

        $emitter = trim((string) ($document['field_A'] ?? ''));
        $emitterNif = extractVatNumber((string) ($document['field_C'] ?? ''));
        if ($emitterNif === '' && $emitter !== '') {
            $emitterNif = extractVatNumber($emitter);
        }
        $acquirerRaw = trim((string) ($document['field_B'] ?? ''));
        $acquirerNif = extractVatNumber($acquirerRaw);
        if ($acquirerNif === '' && !empty($document['field_C'])) {
            $acquirerNif = extractVatNumber((string) $document['field_C']);
        }

        $databaseCandidates = [];
        if ($acquirerNif !== '') {
            $entity = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
            if (is_array($entity)) {
                $databaseCandidates[] = resolveAccountingEntityDatabase($entity);
            }
        }
        if ($emitterNif !== '') {
            $entity = findAccountingEntityByType($pdo, $emitterNif, 'emitter');
            if (is_array($entity)) {
                $databaseCandidates[] = resolveAccountingEntityDatabase($entity);
            }
        }
        $databaseCandidates[] = resolveErpDatabaseIdentifier('');
        $databaseCandidates = array_values(array_unique(array_filter($databaseCandidates, static function ($value): bool {
            return is_string($value) && trim($value) !== '';
        })));
        if (empty($databaseCandidates)) {
            return [];
        }

        $nifCandidates = array_values(array_unique(array_filter([
            $emitterNif,
            extractVatNumber($emitter),
            $acquirerNif,
            extractVatNumber($acquirerRaw),
        ], static function ($value): bool {
            return is_string($value) && trim($value) !== '';
        })));
        if (empty($nifCandidates)) {
            return [];
        }

        $ligacaoRows = [];
        $ligacaoQueryBase = [
            'datadoc' => $docDate,
            'strTpDoc' => $ligacaoDocType,
        ];
        $docYear = substr($docDate, 0, 4);
        if (preg_match('/^\d{4}$/', $docYear)) {
            $ligacaoQueryBase['strCodExercicio'] = $docYear;
        }

        foreach ($databaseCandidates as $databaseCandidate) {
            foreach ($nifCandidates as $nifCandidate) {
                $ligacaoPayload = fetchErpJsonForSuggestion('/contabilidade/LigacaoCteTipoDoc', $ligacaoQueryBase + [
                    'strNIF' => $nifCandidate,
                ], $databaseCandidate);
                if (empty($ligacaoPayload)) {
                    continue;
                }
                $candidateRows = extractErpRowsFromPayload($ligacaoPayload);
                if (empty($candidateRows)) {
                    continue;
                }
                $ligacaoRows = $candidateRows;
                break 2;
            }
        }

        if (empty($ligacaoRows)) {
            return [];
        }

        $rateLineType = resolveSuggestionLigacaoLineTypes($docType)['rate'];
        $result = [];
        foreach ($ligacaoRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tipo = strtoupper(trim((string) ($row['strTipo'] ?? '')));
            if ($tipo !== '' && $tipo !== $rateLineType) {
                continue;
            }
            $rateKey = resolveSuggestionLigacaoRateKeyFromRow($row);
            $rubricCode = normalizeAccountingRubricCodeValue($row['Rub_Codigo'] ?? '');
            if ($rateKey === '' || $rubricCode === '' || isset($result[$rateKey])) {
                continue;
            }
            $result[$rateKey] = $rubricCode;
        }

        return $result;
    }
}

if (!function_exists('resolveEffectiveDocumentAccountingConfiguration')) {
    function resolveEffectiveDocumentAccountingConfiguration(PDO $pdo, array $document): string {
        $rowPayload = (string) ($document['account'] ?? '');
        $rowAccounts = normalizeAccountingAccounts($rowPayload);
        $rowMetadata = normalizeAccountingMetadata($rowPayload);
        $classificationPayload = fetchClassificationAccountPayload(
            $pdo,
            (string) ($document['field_A'] ?? ''),
            (string) ($document['field_B'] ?? ''),
            (string) ($document['field_D'] ?? ''),
            $document
        );
        $classificationAccounts = [];
        $classificationMetadata = defaultAccountingMetadata();
        $effectiveAccounts = $rowAccounts;
        $effectiveMetadata = $rowMetadata;

        if (trim($classificationPayload) !== '') {
            $classificationAccounts = normalizeAccountingAccounts($classificationPayload);
            $classificationMetadata = normalizeAccountingMetadata($classificationPayload);
            $effectiveAccounts = resolveDisplayClassificationAccounts($classificationAccounts, $rowAccounts);

            if (trim((string) ($effectiveMetadata['total_account'] ?? '')) === '') {
                $effectiveMetadata['total_account'] = resolveClassificationTotalAccountForContext(
                    $classificationMetadata,
                    $rowMetadata['has_receipt_companion'] ?? '0'
                );
            }

            if (trim((string) ($effectiveMetadata['receipt_total_account'] ?? '')) === '') {
                $effectiveMetadata['receipt_total_account'] = trim((string) ($classificationMetadata['receipt_total_account'] ?? ''));
            }
        }

        $resolvedRubricCodes = resolveDocumentLigacaoRubricCodes($pdo, $document, $effectiveAccounts);
        if (!empty($resolvedRubricCodes)) {
            foreach ($effectiveAccounts as $rateKey => $entry) {
                if (!is_array($entry) || normalizeAccountingRubricCodeValue($entry['erp_rubric_code'] ?? '') !== '') {
                    continue;
                }
                $normalizedRateKey = normalizeSuggestionRateKey((string) $rateKey);
                if ($normalizedRateKey === '') {
                    $normalizedRateKey = (string) $rateKey;
                }
                $rubricCode = $resolvedRubricCodes[$normalizedRateKey] ?? $resolvedRubricCodes[(string) $rateKey] ?? '';
                if ($rubricCode !== '') {
                    $effectiveAccounts[$rateKey]['erp_rubric_code'] = $rubricCode;
                }
            }
        }

        if (trim($classificationPayload) === '' && $effectiveAccounts === $rowAccounts) {
            return $rowPayload;
        }

        return serializeAccountingAccounts($effectiveAccounts, $effectiveMetadata, $rowMetadata);
    }
}

$useDataTables = true;
$useDropzone = false;

$pdo = getPDO();
$ocrSkipMap = fetchOcrSkipMap($pdo);
$action = $_GET['action'] ?? '';
$importType = (int)($_GET['import_type'] ?? 1);
$viewMode = strtolower(trim((string) ($_GET['type'] ?? '')));
$isImportOnlyView = $importType === 1 && $viewMode === 'import';
$currentErpWebserviceUrl = trim((string) getSetting('erp_webservice_url', ''));
$currentErpToken = trim((string) getSetting('erp_token', ''));
$canClassifyCtb = $importType !== 1 || userHasDepartmentPermission('ctb_classificar_docs');
$canImportCtb = $importType !== 1 || userHasDepartmentPermission('ctb_importar_docs');
$classificationAcquirerOptions = [];

if (hasTable('accounting_entities')) {
    try {
        $stmt = $pdo->query("SELECT nif, name, erp_database, entity_type, erp_client_code FROM accounting_entities WHERE entity_type = 'acquirer' ORDER BY name ASC, nif ASC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entityRow) {
            $nif = extractVatNumber((string) ($entityRow['nif'] ?? ''));
            if ($nif === '') {
                continue;
            }

            $name = trim((string) ($entityRow['name'] ?? ''));
            $databaseRef = resolveAccountingEntityDatabase($entityRow);
            $companyCode = '';
            $companySort = PHP_INT_MAX;
            if (preg_match('/^emp[_-]?(\d+)$/i', $databaseRef, $matches)) {
                $companyCode = ltrim($matches[1], '0');
                if ($companyCode === '') {
                    $companyCode = '0';
                }
                $companySort = (int) $matches[1];
            }

            if ($companyCode !== '') {
                $companyName = $name !== '' ? $name : $nif;
                $label = $databaseRef . ' (' . $companyCode . ' - ' . $companyName . ')';
            } else {
                $label = $name !== '' && $name !== $nif ? ($name . ' - ' . $nif) : $nif;
            }

            $classificationAcquirerOptions[] = [
                'value' => $nif,
                'nif' => $nif,
                'name' => $name,
                'label' => $label,
                'erp_database' => $databaseRef,
                'company_code' => $companyCode,
                'company_sort' => $companySort,
            ];
        }

        usort($classificationAcquirerOptions, static function (array $left, array $right): int {
            $leftSort = (int) ($left['company_sort'] ?? PHP_INT_MAX);
            $rightSort = (int) ($right['company_sort'] ?? PHP_INT_MAX);
            if ($leftSort !== $rightSort) {
                return $leftSort <=> $rightSort;
            }

            $leftLabel = (string) ($left['label'] ?? '');
            $rightLabel = (string) ($right['label'] ?? '');
            return strnatcasecmp($leftLabel, $rightLabel);
        });
    } catch (Throwable $throwable) {
        logErpMessage('Erro ao carregar lista de adquirentes para classificação: ' . $throwable->getMessage());
    }
}

if ($isImportOnlyView && !$canImportCtb) {
    http_response_code(403);
    exit('Sem permissao para importar documentos CTB.');
}

function buildReceiptRowsHiddenSqlCondition(string $tableReference = ''): string {
    $outerPrefix = trim($tableReference) !== '' ? trim($tableReference) . '.' : '';
    $filenameExpr = "TRIM(COALESCE({$outerPrefix}filename, ''))";
    $docTypeExpr = "UPPER(TRIM(COALESCE({$outerPrefix}field_D, '')))";

    return 'NOT ('
        . $filenameExpr . " <> '' AND "
        . $docTypeExpr . " IN ('RC', 'RECIBO', 'RG') AND EXISTS ("
        . 'SELECT 1 FROM accounting_imports ai_invoice '
        . 'WHERE ai_invoice.import_type = ' . $outerPrefix . 'import_type '
        . "AND (ai_invoice.cab_id IS NULL OR ai_invoice.cab_id = '') "
        . 'AND ai_invoice.filename = ' . $outerPrefix . 'filename '
        . "AND UPPER(TRIM(COALESCE(ai_invoice.field_D, ''))) IN ('FT', 'FR', 'FTR', 'FATURA', 'FACTURA', 'FATURA-RECIBO', 'FATURA RECIBO', 'FACTURA-RECIBO')"
        . '))';
}

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

    $docInfoMap = [];
    try {
        $docIds = array_keys($assignments);
        $placeholders = implode(',', array_fill(0, count($docIds), '?'));
        $infoStmt = $pdo->prepare(
            'SELECT id, field_A, field_B, field_D, field_H FROM accounting_imports WHERE id IN (' . $placeholders . ')'
        );
        $infoStmt->execute($docIds);
        foreach ($infoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['id'])) {
                $docInfoMap[(int) $row['id']] = $row;
            }
        }
    } catch (Throwable $throwable) {
        logErpMessage('Falha ao obter dados dos documentos importados: ' . $throwable->getMessage());
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
            $info = $docInfoMap[(int) $docId] ?? [];
            $docRef = trim((string) ($info['field_H'] ?? ''));
            $emitter = trim((string) ($info['field_A'] ?? ''));
            $acquirer = trim((string) ($info['field_B'] ?? ''));
            $docType = trim((string) ($info['field_D'] ?? ''));
            $details = [];
            if ($docRef !== '') {
                $details[] = 'doc=' . $docRef;
            }
            if ($docType !== '') {
                $details[] = 'tipo=' . $docType;
            }
            if ($emitter !== '') {
                $details[] = 'emitente=' . $emitter;
            }
            if ($acquirer !== '') {
                $details[] = 'adquirente=' . $acquirer;
            }
            $detailText = $details ? ' (' . implode(', ', $details) . ')' : '';
            logErpMessage('Importação CTB concluída. Documento ' . $docId . $detailText . ' -> cab_id ' . $cabId . '.');
            logAuditAction('import_ctb', 'accounting_imports', (int) $docId, [
                'cab_id' => $cabId,
                'doc_ref' => $docRef,
                'doc_type' => $docType,
                'emitter' => $emitter,
                'acquirer' => $acquirer,
            ]);
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

function resolveMonthEndDate(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $patterns = [
        '/^\\d{4}-\\d{2}-\\d{2}$/' => ['format' => 'Y-m-d', 'output' => 'Y-m-t'],
        '/^\\d{2}\\/\\d{2}\\/\\d{4}$/' => ['format' => 'd/m/Y', 'output' => 'd/m/Y'],
        '/^\\d{2}-\\d{2}-\\d{4}$/' => ['format' => 'd-m-Y', 'output' => 'd-m-Y'],
        '/^\\d{4}\\/\\d{2}\\/\\d{2}$/' => ['format' => 'Y/m/d', 'output' => 'Y/m/t'],
    ];

    foreach ($patterns as $pattern => $config) {
        if (!preg_match($pattern, $value)) {
            continue;
        }

        $date = DateTime::createFromFormat($config['format'], $value);
        if ($date instanceof DateTime) {
            $date->modify('last day of this month');
            return $date->format($config['output']);
        }
    }

    return null;
}

function applyPostingDateMode(array $document, string $mode): array {
    if ($mode !== 'month_end') {
        return $document;
    }

    $docDate = trim((string) ($document['field_F'] ?? ''));
    if ($docDate === '') {
        return $document;
    }

    $monthEnd = resolveMonthEndDate($docDate);
    if ($monthEnd !== null) {
        $document['field_F'] = $monthEnd;
    }

    return $document;
}

function import_CTB(PDO $pdo, array $ids, int $importType, string $database = ''): array {
    $result = [
        'success' => false,
        'error' => '',
        'status' => 0,
        'response' => null,
    ];

    $database = trim($database);
    $erpEmp = trim((string) getSetting('accounting_base_company', ''));

    $targetCompany = $database !== '' ? $database : $erpEmp;

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

    if ($targetCompany === '' && !empty($documents[0]['field_B'])) {
        $fallbackNif = preg_replace('/\D+/', '', (string) $documents[0]['field_B']);
        if ($fallbackNif !== '') {
            $entity = findAccountingEntity($pdo, $fallbackNif);
            if (is_array($entity)) {
                $candidateDb = resolveAccountingEntityDatabase($entity);
                if ($candidateDb !== '') {
                    $targetCompany = $candidateDb;
                }
            }
        }
    }

    $postingDateMode = trim((string) getSetting('accounting_posting_date_mode', 'document'));
    $useMonthEnd = $importType === 1 && $postingDateMode === 'month_end';
    if ($importType === 1 && $targetCompany !== '') {
        preloadErpEInvoiceDocTypes($targetCompany);
    }

    $documentsPayload = array_map(static function (array $document) use ($useMonthEnd, $postingDateMode, $targetCompany, $importType): array {
        if ($useMonthEnd) {
            $document = applyPostingDateMode($document, $postingDateMode);
        }

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

        if ($importType === 1) {
            $rawDocumentType = trim((string) ($document['field_D'] ?? $document['docType'] ?? ''));
            $resolvedDocumentType = resolveErpAccountingDocumentTypeAbbreviation($rawDocumentType, $targetCompany);
            if ($resolvedDocumentType !== '') {
                $document['docType'] = $resolvedDocumentType;
            } elseif ($rawDocumentType !== '') {
                $document['docType'] = $rawDocumentType;
            }
        }



        return $document;
    }, $documents);

    $documentsWithoutLines = [];
    foreach ($documentsPayload as &$documentPayload) {
        $originalAccountConfig = (string) ($documentPayload['account'] ?? '');
        $effectiveAccountConfig = resolveEffectiveDocumentAccountingConfiguration($pdo, $documentPayload);
        if (trim($effectiveAccountConfig) !== '') {
            $documentPayload['account'] = $effectiveAccountConfig;
        }
        $documentPayload['account_configuration'] = (string) ($documentPayload['account'] ?? '');
        $documentPayload['account_configuration_source'] = $originalAccountConfig;
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

    $accountValidation = validateDocumentAccountsAgainstErpPlan($documentsPayload, $targetCompany);
    if (!($accountValidation['ok'] ?? false)) {
        $invalidDetails = [];
        foreach (($accountValidation['invalid_documents'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $docLabel = trim((string) ($item['document'] ?? ''));
            $accounts = isset($item['accounts']) && is_array($item['accounts']) ? $item['accounts'] : [];
            if ($docLabel === '' || empty($accounts)) {
                continue;
            }
            $invalidDetails[] = $docLabel . ' [' . implode(', ', $accounts) . ']';
        }

        $result['error'] = 'Existem contas que não existem no plano de contas ERP da base ' . $targetCompany . ': ' . implode('; ', $invalidDetails);
        $result['error_detail'] = $result['error'];
        logErpMessage('Importação CTB abortada por contas inexistentes no plano ERP da base ' . $targetCompany . '. Detalhe: ' . $result['error']);
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
        'database' => $targetCompany,
    ];

    if ($targetCompany !== '') {
        $postPayload['db'] = $targetCompany;
    }
    if ($erpEmp !== '') {
        $postPayload['EMP'] = $erpEmp;
    } elseif ($targetCompany !== '') {
        $postPayload['EMP'] = $targetCompany;
    }

    $accountingDiaryCode = trim((string) getSetting('accounting_diary', ''));
    if ($importType === 1 && $accountingDiaryCode !== '') {
        $postPayload['codDiario'] = $accountingDiaryCode;
    }

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
    if ($normalizedEmitterNif === '') {
        $normalizedEmitterNif = extractVatNumber($emitterRawValue);
    }
    $emitterName = $emitterRawValue;

    if ($normalizedEmitterNif !== '') {
        static $entityNameCache = [];
        if (!array_key_exists($normalizedEmitterNif, $entityNameCache)) {
            $cachedName = null;
            if (isset($pdo) && $pdo instanceof PDO && function_exists('findAccountingEntity')) {
                $entity = findAccountingEntity($pdo, $normalizedEmitterNif);
                if (is_array($entity)) {
                    $candidate = trim((string)($entity['name'] ?? ''));
                    if ($candidate !== '' && (!function_exists('isPlaceholderAccountingEntityName') || !isPlaceholderAccountingEntityName($candidate, $normalizedEmitterNif))) {
                        $cachedName = $candidate;
                    }
                }
                if (($cachedName === null || $cachedName === '') && function_exists('findAccountingEntityNameFromEfatura')) {
                    $candidate = trim((string) findAccountingEntityNameFromEfatura($pdo, $normalizedEmitterNif));
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

    $effectiveAccountConfig = resolveEffectiveDocumentAccountingConfiguration($pdo, $row);
    $accounts = normalizeAccountingAccounts($effectiveAccountConfig);
    $accountMetadata = normalizeAccountingMetadata($effectiveAccountConfig);
    $rowMetadata = normalizeAccountingMetadata($row['account'] ?? '');
    $summaries = computeImportRateSummaries($row);
    [$payload, $requirements] = buildClassificationRequirements($summaries, $accounts, $accountMetadata);
    if (($accountMetadata['ignore_detected_rates'] ?? '0') === '1') {
        $payload = filterVisibleAccountingRates($accounts);
    }
    $payload = adjustAccountingRatesForDisplay($payload);
    $row['rate_payload'] = $payload;
    $row['rate_requirements'] = $requirements;
    $row['cost_centers'] = normalizeCostCenters($row['cost_center'] ?? '');
    $row['cost_center_breakdowns'] = normalizeCostCenterBreakdowns($row['cost_center'] ?? '');
    $row['btn_class'] = determineClassificationButtonClass($requirements, $payload, $accountMetadata, $row['cost_centers']);
    $row['manual_review_required'] = (($rowMetadata['manual_review_required'] ?? '0') === '1') ? '1' : '0';
    $row['has_receipt_companion'] = (($rowMetadata['has_receipt_companion'] ?? '0') === '1') ? '1' : '0';
    $hasDocumentIdentity = false;
    foreach (['field_A', 'field_B', 'field_D', 'field_F', 'field_G', 'field_H', 'field_R'] as $identityField) {
        if (trim((string) ($row[$identityField] ?? '')) !== '') {
            $hasDocumentIdentity = true;
            break;
        }
    }
    $hasEmitterAndAcquirerOnly = trim((string) ($row['field_A'] ?? '')) !== ''
        && trim((string) ($row['field_B'] ?? '')) !== ''
        && trim((string) ($row['field_D'] ?? '')) === ''
        && trim((string) ($row['field_F'] ?? '')) === ''
        && trim((string) ($row['field_G'] ?? '')) === ''
        && trim((string) ($row['field_H'] ?? '')) === ''
        && trim((string) ($row['field_R'] ?? '')) === ''
        && trim((string) ($row['field_N'] ?? '')) === ''
        && trim((string) ($row['field_O'] ?? '')) === '';
    $row['show_document_fields'] = (($rowMetadata['manual_document_fields'] ?? '0') === '1' || !$hasDocumentIdentity || $hasEmitterAndAcquirerOnly) ? '1' : '0';
    $row['auto_import_ready'] = (trim((string) $row['btn_class']) === 'btn-success' && $row['manual_review_required'] !== '1');
    $row['total_account'] = $accountMetadata['total_account'] ?? '';
    $row['line_btn_class'] = 'btn-info';
    $row['acquirer_erp_database'] = '';

    $acquirerCandidates = [
        (string) ($row['field_B'] ?? ''),
        (string) ($row['field_C'] ?? ''),
    ];
    $acquirerNif = '';
    foreach ($acquirerCandidates as $candidateValue) {
        $candidateNif = extractVatNumber((string) $candidateValue);
        if ($candidateNif !== '') {
            $acquirerNif = $candidateNif;
            break;
        }
    }
    if ($acquirerNif !== '') {
        static $acquirerDatabaseCache = [];
        if (!array_key_exists($acquirerNif, $acquirerDatabaseCache)) {
            $dbValue = '';
            if (isset($pdo) && $pdo instanceof PDO && function_exists('findAccountingEntity')) {
                try {
                    $entity = findAccountingEntity($pdo, $acquirerNif);
                    if (is_array($entity)) {
                        $dbValue = resolveAccountingEntityDatabase($entity);
                    }
                } catch (Throwable $throwable) {
                    $dbValue = '';
                }
            }
            $acquirerDatabaseCache[$acquirerNif] = $dbValue;
        }
        $row['acquirer_erp_database'] = (string) ($acquirerDatabaseCache[$acquirerNif] ?? '');
    }
    if ($row['acquirer_erp_database'] === '') {
        $row['acquirer_erp_database'] = trim((string) getSetting('erp_database', ''));
    }
    if ($row['acquirer_erp_database'] !== '') {
        preloadErpEInvoiceDocTypes((string) $row['acquirer_erp_database']);
    }

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

function isImportReadyRow(array $row): bool {
    return trim((string) ($row['btn_class'] ?? '')) === 'btn-success';
}

function isAutoImportReadyRow(array $row): bool {
    return isImportReadyRow($row) && trim((string) ($row['manual_review_required'] ?? '0')) !== '1';
}

function classificationButtonLabel(array $row): string {
    return isAutoImportReadyRow($row) ? 'Classificado' : 'Classificar';
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
            'erp_database' => resolveAccountingEntityDatabase($entity),
            'entity_type' => $entityType,
            'erp_client_code' => trim((string)($entity['erp_client_code'] ?? '')),
            'display_name' => $displayName,
            'source_value' => $preferredValue,
        ];

        $entities[$acquirerNif] = $entityInfo;
    }

    return array_values($entities);
}

/**
 * Group selected import rows by resolved ERP database of the acquirer.
 *
 * @param PDO    $pdo
 * @param array  $ids
 * @param int    $importType
 * @param string $requestedDatabase Optional explicit database override.
 * @return array{groups: array<string, array<int>>, missing: array<int, array<string, mixed>>, entities: array<int, array<string, mixed>>}
 */
function collectImportGroupsByDatabase(PDO $pdo, array $ids, int $importType, string $requestedDatabase = ''): array {
    if (empty($ids)) {
        return ['groups' => [], 'missing' => [], 'entities' => []];
    }

    $requestedDatabase = trim($requestedDatabase);
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $sql = 'SELECT id, field_B, field_C FROM accounting_imports WHERE import_type = ? AND id IN (' . $placeholders . ') ORDER BY id';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $importType, PDO::PARAM_INT);

    foreach ($ids as $index => $id) {
        $stmt->bindValue($index + 2, $id, PDO::PARAM_INT);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return ['groups' => [], 'missing' => [], 'entities' => []];
    }

    $groups = [];
    $missing = [];
    $entities = [];
    $entityCache = [];

    foreach ($rows as $row) {
        $fieldB = trim((string) ($row['field_B'] ?? ''));
        $fieldC = trim((string) ($row['field_C'] ?? ''));
        $rowId = (int) ($row['id'] ?? 0);

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
            $missing[] = [
                'id' => $rowId,
                'nif' => '',
                'display_name' => $fieldB !== '' ? $fieldB : ('ID ' . $rowId),
            ];
            continue;
        }

        if (!array_key_exists($acquirerNif, $entityCache)) {
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
                ];
            }

            $entityDatabase = resolveAccountingEntityDatabase($entity);
            if ($entityDatabase === '' && $requestedDatabase !== '') {
                $entityDatabase = $requestedDatabase;
            }

            $displayNameResolved = trim((string) ($entity['name'] ?? ''));
            if ($displayNameResolved === '') {
                $displayNameResolved = $displayName !== '' ? $displayName : $acquirerNif;
            }

            $entityCache[$acquirerNif] = [
                'id' => $entity['id'] ?? null,
                'nif' => $acquirerNif,
                'name' => $displayNameResolved,
                'display_name' => $displayNameResolved,
                'erp_database' => $entityDatabase,
            ];
        }

        $entity = $entityCache[$acquirerNif];
        $entities[$acquirerNif] = $entity;
        $entityDatabase = trim((string) ($entity['erp_database'] ?? ''));

        if ($entityDatabase === '') {
            $missing[] = [
                'id' => $rowId,
                'nif' => $acquirerNif,
                'display_name' => $entity['display_name'] ?? $entity['name'] ?? $acquirerNif,
            ];
            continue;
        }

        if (!isset($groups[$entityDatabase])) {
            $groups[$entityDatabase] = [];
        }
        $groups[$entityDatabase][] = $rowId;
    }

    return [
        'groups' => $groups,
        'missing' => $missing,
        'entities' => array_values($entities),
    ];
}

function summarizeImportGroupMissingEntities(array $missing): string {
    $labels = [];

    foreach ($missing as $item) {
        if (!is_array($item)) {
            continue;
        }

        $rowId = (int) ($item['id'] ?? 0);
        $nif = trim((string) ($item['nif'] ?? ''));
        $displayName = trim((string) ($item['display_name'] ?? ''));

        if ($displayName === '') {
            if ($nif !== '') {
                $displayName = 'Cliente ' . $nif;
            } elseif ($rowId > 0) {
                $displayName = 'ID ' . $rowId;
            } else {
                $displayName = 'Registo sem adquirente';
            }
        }

        if ($nif !== '' && stripos($displayName, $nif) === false) {
            $displayName .= ' (NIF ' . $nif . ')';
        }

        $key = $nif !== '' ? $nif : ($displayName !== '' ? $displayName : ('id_' . $rowId));
        $labels[$key] = $displayName;
    }

    return implode('; ', array_values($labels));
}

function buildQrDocTypeMappingContextForIds(PDO $pdo, array $ids, int $importType, string $targetDatabase): array {
    $context = [
        'database' => trim($targetDatabase),
        'options' => [],
        'items' => [],
    ];

    if (empty($ids)) {
        return $context;
    }

    $context['options'] = buildErpEInvoiceDocTypeOptions($context['database']);
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        'SELECT id, field_D FROM accounting_imports WHERE import_type = ? AND id IN (' . $placeholders . ') ORDER BY id'
    );
    $stmt->bindValue(1, $importType, PDO::PARAM_INT);
    foreach ($ids as $index => $id) {
        $stmt->bindValue($index + 2, $id, PDO::PARAM_INT);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    $seenTypes = [];
    foreach ($rows as $row) {
        $rawDocType = trim((string) ($row['field_D'] ?? ''));
        $mappingKey = getAccountingQrDocTypeMappingKey($rawDocType);
        if ($mappingKey === '' || isset($seenTypes[$mappingKey])) {
            continue;
        }
        $seenTypes[$mappingKey] = true;

        $configuredMapping = getAccountingQrDocTypeMappingEntry($mappingKey, $context['database']);
        $configuredDocType = trim((string) ($configuredMapping['erp_doc_type'] ?? ''));
        if ($configuredDocType !== '') {
            continue;
        }

        $items[] = [
            'qr_doc_type' => $mappingKey,
            'raw_doc_type' => $rawDocType,
            'suggested_value' => resolveErpAccountingDocumentTypeAbbreviation($rawDocType, $context['database']),
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return strnatcasecmp((string) ($left['qr_doc_type'] ?? ''), (string) ($right['qr_doc_type'] ?? ''));
    });

    $context['items'] = $items;

    return $context;
}

function normalizeSuggestionRateKey(string $value): string {
    $clean = trim(str_replace('%', '', $value));
    if ($clean === '') {
        return '';
    }

    $clean = str_replace(',', '.', $clean);
    if (!is_numeric($clean)) {
        return $clean;
    }

    $number = (float) $clean;
    if ($number > 0 && $number <= 1) {
        $number *= 100;
    }
    $number = round($number, 2);

    if (abs($number - round($number)) < 0.001) {
        return (string) (int) round($number);
    }

    return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
}

function normalizeSuggestionDocDate(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp !== false) {
        return date('Y-m-d', $timestamp);
    }

    return '';
}

function normalizeSuggestionLigacaoDocType(string $docType): string {
    $value = strtoupper(trim($docType));
    if ($value === '') {
        return '';
    }
    if (in_array($value, ['FATURA', 'FACTURA', 'INVOICE'], true)) {
        return 'FT';
    }
    if (in_array($value, ['FATURA-RECIBO', 'FATURA RECIBO', 'FACTURA-RECIBO', 'FR', 'FTR'], true)) {
        return 'FR';
    }
    if (in_array($value, ['NOTA CREDITO', 'NOTA DE CREDITO', 'NC'], true)) {
        return 'NC';
    }
    if (in_array($value, ['NOTA DEBITO', 'NOTA DE DÉBITO', 'ND'], true)) {
        return 'ND';
    }
    if (in_array($value, ['RECIBO', 'RC', 'RG'], true)) {
        return 'RC';
    }
    return $value;
}

function resolveSuggestionLigacaoLineTypes(string $docType): array {
    $normalized = normalizeSuggestionLigacaoDocType($docType);
    if ($normalized === 'NC') {
        return ['rate' => 'C', 'total' => 'D'];
    }

    return ['rate' => 'D', 'total' => 'C'];
}

function resolveSuggestionLigacaoRateKeyFromRow(array $row): string {
    $rateValue = trim((string) ($row['fltTaxaValor'] ?? ''));
    if ($rateValue !== '' && $rateValue !== '.000000' && $rateValue !== '0.000000' && $rateValue !== '0') {
        return normalizeSuggestionRateKey($rateValue);
    }

    $descriptionCandidates = [
        trim((string) ($row['PC_Descricao'] ?? '')),
        trim((string) ($row['Rub_Descricao'] ?? '')),
        trim((string) ($row['Rub_Codigo'] ?? '')),
    ];
    foreach ($descriptionCandidates as $description) {
        $normalized = strtolower($description);
        if ($normalized === '') {
            continue;
        }
        if (strpos($normalized, 'taxa normal') !== false || strpos($normalized, 'normal') !== false) {
            return '23';
        }
        if (strpos($normalized, 'interm') !== false) {
            return '13';
        }
        if (strpos($normalized, 'reduz') !== false) {
            return '6';
        }
    }

    return '';
}

function isSuggestionLigacaoDirectIvaLine(array $row): bool {
    $account = trim((string) ($row['strConta'] ?? ''));
    if ($account === '') {
        return false;
    }

    $linkedIvaAccount = trim((string) ($row['strConta_Iva'] ?? ''));
    if ($linkedIvaAccount !== '') {
        return false;
    }

    $taxCode = trim((string) ($row['intCodTaxaIva'] ?? ''));
    if ($taxCode !== '' && strcasecmp($taxCode, 'null') !== 0) {
        return true;
    }

    $description = strtolower(trim((string) ($row['PC_Descricao'] ?? '')));
    if ($description !== '') {
        if (strpos($description, 'taxa normal') !== false
            || strpos($description, 'taxa interm') !== false
            || strpos($description, 'taxa intermedia') !== false
            || strpos($description, 'taxa reduz') !== false) {
            return true;
        }
    }

    return preg_match('/^243/', $account) === 1;
}

function resolveSuggestionLigacaoAccountCandidates(array $row): array {
    $general = trim((string) ($row['strConta'] ?? ''));
    $iva = trim((string) ($row['strConta_Iva'] ?? ''));

    if (isSuggestionLigacaoDirectIvaLine($row)) {
        if ($iva === '') {
            $iva = $general;
        }
        $general = '';
    }

    return [
        'general' => $general,
        'iva' => $iva,
    ];
}

function normalizePartyHintToken(string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }

    $normalized = preg_replace('/\s+/u', ' ', $value);
    if (!is_string($normalized)) {
        return $value;
    }

    return $normalized;
}

function extractAccountTokensFromMixed($value, array &$bucket): void {
    if (is_array($value)) {
        foreach ($value as $nested) {
            extractAccountTokensFromMixed($nested, $bucket);
        }
        return;
    }

    if (!is_string($value) && !is_numeric($value)) {
        return;
    }

    $token = trim((string) $value);
    if ($token === '' || !preg_match('/^\d{3,}$/', $token)) {
        return;
    }

    $bucket[$token] = ($bucket[$token] ?? 0) + 1;
}

function extractErpRowsFromPayload(array $payload): array {
    foreach (['aaData', 'data', 'result', 'results'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            return $payload[$key];
        }
    }

    if (array_keys($payload) === range(0, count($payload) - 1)) {
        return $payload;
    }

    return [];
}

function extractTotalAccountFromPayloadString(?string $json): string {
    $json = trim((string) $json);
    if ($json === '') {
        return '';
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return '';
    }

    $candidate = '';
    if (isset($decoded['meta']) && is_array($decoded['meta'])) {
        $candidate = trim((string) ($decoded['meta']['total_account'] ?? ''));
    }
    if ($candidate === '') {
        $candidate = trim((string) ($decoded['total_account'] ?? ''));
    }

    if ($candidate !== '' && preg_match('/^\d{3,}$/', $candidate)) {
        return $candidate;
    }

    return '';
}

function fetchErpJsonForSuggestion(string $path, array $query, string $database = ''): array {
    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    $token = trim((string) getSetting('erp_token', ''));

    if ($baseUrl === '' || $token === '' || !function_exists('curl_init')) {
        return [];
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, $path);
    if ($endpoint === '') {
        return [];
    }

    $query = array_merge($query, buildErpCompanyQueryParams($database));
    if (!empty($query)) {
        $endpoint = appendQueryParamsToUrl($endpoint, $query);
    }

    $handle = curl_init($endpoint);
    if ($handle === false) {
        return [];
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
    curl_close($handle);

    if (!is_string($response) || $response === '' || $status < 200 || $status >= 300) {
        return [];
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : [];
}

function fetchErpPlanAccountCode(string $accountCode, string $database = ''): bool {
    $accountCode = trim($accountCode);
    $database = trim($database);
    if ($database === '' || $accountCode === '' || !preg_match('/^\d{3,}$/', $accountCode)) {
        return false;
    }

    $payload = fetchErpJsonForSuggestion('/contabilidade/planocontas', [
        'strCodExercicio' => date('Y'),
        'strConta' => $accountCode,
        'limit' => 10,
        'offset' => 0,
    ], $database);
    if (empty($payload)) {
        return false;
    }

    $rows = extractErpRowsFromPayload($payload);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string) ($row['strConta'] ?? ''));
        if ($code !== '' && strcasecmp($code, $accountCode) === 0) {
            return true;
        }
    }

    return false;
}

function validateDocumentAccountsAgainstErpPlan(array $documentsPayload, string $database): array {
    $checkedAccounts = [];
    $planLoaded = false;
    if (trim($database) === '') {
        return [
            'ok' => true,
            'invalid_documents' => [],
            'invalid_accounts' => [],
            'plan_loaded' => $planLoaded,
        ];
    }

    $invalidDocuments = [];
    $invalidAccounts = [];

    foreach ($documentsPayload as $document) {
        if (!is_array($document)) {
            continue;
        }
        $documentAccounts = [];
        $accountLines = isset($document['account_lines']) && is_array($document['account_lines']) ? $document['account_lines'] : [];
        foreach ($accountLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $account = trim((string) ($line['strConta'] ?? ''));
            if ($account === '') {
                continue;
            }
            if (!array_key_exists($account, $checkedAccounts)) {
                $checkedAccounts[$account] = fetchErpPlanAccountCode($account, $database);
                $planLoaded = true;
            }
            if ($checkedAccounts[$account]) {
                continue;
            }
            $documentAccounts[$account] = true;
            $invalidAccounts[$account] = true;
        }

        if (!empty($documentAccounts)) {
            $docLabel = trim((string) ($document['field_G'] ?? ''));
            if ($docLabel === '') {
                $docLabel = 'ID ' . (string) ($document['id'] ?? '?');
            }
            $invalidDocuments[] = [
                'document' => $docLabel,
                'accounts' => array_values(array_keys($documentAccounts)),
            ];
        }
    }

    return [
        'ok' => empty($invalidDocuments),
        'invalid_documents' => $invalidDocuments,
        'invalid_accounts' => array_values(array_keys($invalidAccounts)),
        'plan_loaded' => $planLoaded,
    ];
}

function fetchErpConfigEmpresaByDatabase(string $database, string $companyId = ''): array {
    $database = trim($database);
    if ($database === '') {
        return ['ok' => false, 'name' => '', 'error' => 'Base de dados ERP inválida.'];
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    $token = trim((string) getSetting('erp_token', ''));
    if ($baseUrl === '' || $token === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'name' => '', 'error' => 'Serviço ERP indisponível para validar a base de dados.'];
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, '/tabelas/configEmpresa');
    if ($endpoint === '') {
        return ['ok' => false, 'name' => '', 'error' => 'URL ERP inválida para validar a base de dados.'];
    }

    $companyId = trim($companyId);
    $companyIdForLookup = $companyId !== '' ? $companyId : '384';
    $endpointPrimary = appendQueryParamsToUrl($endpoint, [
        'db' => $database,
        'q' => $companyIdForLookup,
        'searchField' => 'Id',
    ]);

    $handle = curl_init($endpointPrimary);
    if ($handle === false) {
        return ['ok' => false, 'name' => '', 'error' => 'Não foi possível iniciar validação da base de dados ERP.'];
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

    $rows = extractErpRowsFromPayload($decoded);
    $companyName = '';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = trim((string) ($row['strValor'] ?? $row['nome'] ?? $row['name'] ?? ''));
        if ($candidate !== '') {
            $companyName = $candidate;
            break;
        }
    }

    if ($companyName !== '') {
        return ['ok' => true, 'name' => $companyName, 'error' => ''];
    }

    $endpointFallback = appendQueryParamsToUrl($endpoint, ['db' => $database]);
    $handleFallback = curl_init($endpointFallback);
    if ($handleFallback === false) {
        return ['ok' => false, 'name' => '', 'error' => 'Não foi possível obter o nome da empresa para a base selecionada.'];
    }
    curl_setopt_array($handleFallback, [
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
    $responseFallback = curl_exec($handleFallback);
    $statusFallback = (int) curl_getinfo($handleFallback, CURLINFO_HTTP_CODE);
    curl_close($handleFallback);
    if (!is_string($responseFallback) || $responseFallback === '' || $statusFallback >= 400) {
        $message = trim((string) ($decoded['message'] ?? 'Não foi possível obter o nome da empresa para a base selecionada.'));
        return ['ok' => false, 'name' => '', 'error' => $message !== '' ? $message : 'Não foi possível obter o nome da empresa para a base selecionada.'];
    }
    $decodedFallback = json_decode($responseFallback, true);
    if (!is_array($decodedFallback)) {
        $message = trim((string) ($decoded['message'] ?? 'Não foi possível obter o nome da empresa para a base selecionada.'));
        return ['ok' => false, 'name' => '', 'error' => $message !== '' ? $message : 'Não foi possível obter o nome da empresa para a base selecionada.'];
    }
    $rowsFallback = extractErpRowsFromPayload($decodedFallback);
    foreach ($rowsFallback as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = trim((string) ($row['strValor'] ?? $row['nome'] ?? $row['name'] ?? ''));
        if ($candidate !== '') {
            return ['ok' => true, 'name' => $candidate, 'error' => ''];
        }
    }

    $message = trim((string) ($decoded['message'] ?? 'Não foi possível obter o nome da empresa para a base selecionada.'));
    return ['ok' => false, 'name' => '', 'error' => $message !== '' ? $message : 'Não foi possível obter o nome da empresa para a base selecionada.'];
}

function buildSuggestionTallyFromHistory(PDO $pdo, string $acquirerNif, string $docType, string $emitter, int $limit = 140): array {
    $acquirerNif = extractVatNumber($acquirerNif);
    $docType = trim($docType);
    $emitterHint = normalizePartyHintToken($emitter);

    $sql = 'SELECT id, field_A, field_B, field_C, field_D, account FROM accounting_imports WHERE import_type = 1 AND account IS NOT NULL AND account <> ""';
    $params = [];
    if ($docType !== '') {
        $sql .= ' AND field_D = ?';
        $params[] = $docType;
    }
    if ($acquirerNif !== '') {
        $sql .= ' AND (field_B LIKE ? OR field_C LIKE ?)';
        $params[] = '%' . $acquirerNif . '%';
        $params[] = '%' . $acquirerNif . '%';
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . max(40, $limit);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rates = [];
    $samples = 0;
    $totals = [];
    foreach ($rows as $row) {
        $accountPayload = trim((string) ($row['account'] ?? ''));
        if ($accountPayload === '') {
            continue;
        }

        $normalizedRates = normalizeAccountingAccounts($accountPayload);
        if (empty($normalizedRates)) {
            continue;
        }

        $samples += 1;
        $score = 1;
        $rowAcquirer = extractVatNumber((string) ($row['field_B'] ?? ''));
        if ($rowAcquirer === '') {
            $rowAcquirer = extractVatNumber((string) ($row['field_C'] ?? ''));
        }
        if ($acquirerNif !== '' && $rowAcquirer !== '' && $acquirerNif === $rowAcquirer) {
            $score += 6;
        }
        if ($docType !== '' && trim((string) ($row['field_D'] ?? '')) === $docType) {
            $score += 4;
        }
        if ($emitterHint !== '') {
            $rowEmitter = normalizePartyHintToken((string) ($row['field_A'] ?? ''));
            if ($rowEmitter !== '' && strpos($rowEmitter, $emitterHint) !== false) {
                $score += 2;
            }
        }

        $totalAccount = extractTotalAccountFromPayloadString($accountPayload);
        if ($totalAccount !== '') {
            $totals[$totalAccount] = ($totals[$totalAccount] ?? 0) + $score;
        }

        foreach ($normalizedRates as $rateKey => $entry) {
            $effectiveRate = normalizeSuggestionRateKey((string) $rateKey);
            if ($effectiveRate === '') {
                $effectiveRate = (string) $rateKey;
            }
            if (!isset($rates[$effectiveRate])) {
                $rates[$effectiveRate] = ['general' => [], 'iva' => []];
            }

            $general = trim((string) ($entry['general_account'] ?? ''));
            $iva = trim((string) ($entry['iva_account'] ?? ''));
            if ($general !== '') {
                $rates[$effectiveRate]['general'][$general] = ($rates[$effectiveRate]['general'][$general] ?? 0) + $score;
            }
            if ($iva !== '') {
                $rates[$effectiveRate]['iva'][$iva] = ($rates[$effectiveRate]['iva'][$iva] ?? 0) + $score;
            }
        }
    }

    foreach ($rates as $rateKey => $accountMap) {
        arsort($accountMap['general']);
        arsort($accountMap['iva']);
        $rates[$rateKey] = $accountMap;
    }

    arsort($totals);

    return ['samples' => $samples, 'rates' => $rates, 'totals' => $totals];
}

function buildSuggestionTallyFromRules(PDO $pdo, string $docType, string $emitter, string $acquirer, int $limit = 80): array {
    if (!hasTable('accounting_classifications')) {
        return ['samples' => 0, 'rates' => []];
    }

    $docType = trim($docType);
    $emitterHint = normalizePartyHintToken($emitter);
    $acquirerHint = normalizePartyHintToken($acquirer);

    $sql = 'SELECT id, emitter, acquirer, doc_type, account FROM accounting_classifications WHERE account IS NOT NULL AND account <> ""';
    $params = [];
    if ($docType !== '') {
        $sql .= ' AND doc_type = ?';
        $params[] = $docType;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . max(20, $limit);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rates = [];
    $samples = 0;
    $totals = [];
    foreach ($rows as $row) {
        $accountPayload = trim((string) ($row['account'] ?? ''));
        if ($accountPayload === '') {
            continue;
        }

        $normalizedRates = normalizeAccountingAccounts($accountPayload);
        if (empty($normalizedRates)) {
            continue;
        }

        $samples += 1;
        $score = 1;
        if ($docType !== '' && trim((string) ($row['doc_type'] ?? '')) === $docType) {
            $score += 4;
        }

        $rowEmitter = normalizePartyHintToken((string) ($row['emitter'] ?? ''));
        if ($emitterHint !== '' && $rowEmitter !== '' && (strpos($rowEmitter, $emitterHint) !== false || strpos($emitterHint, $rowEmitter) !== false)) {
            $score += 3;
        }

        $rowAcquirer = normalizePartyHintToken((string) ($row['acquirer'] ?? ''));
        if ($acquirerHint !== '' && $rowAcquirer !== '' && (strpos($rowAcquirer, $acquirerHint) !== false || strpos($acquirerHint, $rowAcquirer) !== false)) {
            $score += 2;
        }

        $totalAccount = extractTotalAccountFromPayloadString($accountPayload);
        if ($totalAccount !== '') {
            $totals[$totalAccount] = ($totals[$totalAccount] ?? 0) + $score;
        }

        foreach ($normalizedRates as $rateKey => $entry) {
            $effectiveRate = normalizeSuggestionRateKey((string) $rateKey);
            if ($effectiveRate === '') {
                $effectiveRate = (string) $rateKey;
            }
            if (!isset($rates[$effectiveRate])) {
                $rates[$effectiveRate] = ['general' => [], 'iva' => []];
            }

            $general = trim((string) ($entry['general_account'] ?? ''));
            $iva = trim((string) ($entry['iva_account'] ?? ''));
            if ($general !== '') {
                $rates[$effectiveRate]['general'][$general] = ($rates[$effectiveRate]['general'][$general] ?? 0) + $score;
            }
            if ($iva !== '') {
                $rates[$effectiveRate]['iva'][$iva] = ($rates[$effectiveRate]['iva'][$iva] ?? 0) + $score;
            }
        }
    }

    foreach ($rates as $rateKey => $accountMap) {
        arsort($accountMap['general']);
        arsort($accountMap['iva']);
        $rates[$rateKey] = $accountMap;
    }

    arsort($totals);

    return ['samples' => $samples, 'rates' => $rates, 'totals' => $totals];
}

if ($action === 'suggestion_explanation' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?? '', true);
    if (!is_array($payload)) {
        echo json_encode(['success' => false, 'error' => 'Pedido inválido.', 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $csrfToken = (string) ($payload['csrf_token'] ?? '');
    if ($csrfToken === '' || !validateCsrfToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.', 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (getSetting('ai_enabled', '0') !== '1' || !userHasDepartmentPermission('ai_suggest_vat')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Sem permissao para consultar explicações de sugestão.', 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $args = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
    $docType = trim((string) ($args['doc_type'] ?? ''));
    $docDate = normalizeSuggestionDocDate((string) ($args['doc_date'] ?? ''));
    $emitter = trim((string) ($args['emitter'] ?? ''));
    $emitterNif = extractVatNumber((string) ($args['emitter_nif'] ?? ''));
    if ($emitterNif === '' && $emitter !== '') {
        $emitterNif = extractVatNumber($emitter);
    }
    $acquirerRaw = trim((string) ($args['acquirer_raw'] ?? ''));
    $acquirerNif = extractVatNumber((string) ($args['acquirer_nif'] ?? ''));
    if ($acquirerNif === '' && $acquirerRaw !== '') {
        $acquirerNif = extractVatNumber($acquirerRaw);
    }

    $rateItems = is_array($args['rates'] ?? null) ? $args['rates'] : [];
    if ($acquirerNif === '' || empty($rateItems)) {
        echo json_encode(['success' => false, 'error' => 'Parâmetros insuficientes para explicar a sugestão.', 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $historyTally = buildSuggestionTallyFromHistory($pdo, $acquirerNif, $docType, $emitter);
    $ruleTally = buildSuggestionTallyFromRules($pdo, $docType, $emitter, $acquirerRaw !== '' ? $acquirerRaw : $acquirerNif);
    $hasReceiptCompanion = trim((string) ($args['has_receipt_companion'] ?? '0')) === '1';

    $database = trim((string) ($args['db'] ?? $args['database'] ?? ''));
    $emitterDatabase = '';
    if ($emitterNif !== '') {
        $entity = findAccountingEntity($pdo, $emitterNif);
        if (is_array($entity)) {
            $emitterDatabase = resolveAccountingEntityDatabase($entity);
        }
    }
    $acquirerDatabase = '';
    if ($acquirerNif !== '') {
        $entity = findAccountingEntity($pdo, $acquirerNif);
        if (is_array($entity)) {
            $acquirerDatabase = resolveAccountingEntityDatabase($entity);
        }
    }

    if ($database === '') {
        $database = $emitterDatabase;
    }
    if ($database === '') {
        $database = $acquirerDatabase;
    }
    if ($database === '') {
        $database = resolveErpDatabaseIdentifier('');
    }

    $ligacaoRows = [];
    $ligacaoPerRate = [];
    $ligacaoDocType = normalizeSuggestionLigacaoDocType($docType);
    $ligacaoLineTypes = resolveSuggestionLigacaoLineTypes($docType);
    $ligacaoRateLineType = $ligacaoLineTypes['rate'];
    $ligacaoTotalLineType = $ligacaoLineTypes['total'];
    $ligacaoNifCandidates = array_values(array_unique(array_filter([
        $emitterNif,
        extractVatNumber($emitter),
        $acquirerNif,
        extractVatNumber($acquirerRaw),
    ], static function ($value): bool {
        return is_string($value) && trim($value) !== '';
    })));
    $databaseCandidates = array_values(array_unique(array_filter([
        $database,
        $emitterDatabase,
        $acquirerDatabase,
        resolveErpDatabaseIdentifier(''),
    ], static function ($value): bool {
        return is_string($value) && trim($value) !== '';
    })));
    if ($ligacaoDocType !== '' && $docDate !== '' && !empty($ligacaoNifCandidates) && !empty($databaseCandidates)) {
        $ligacaoQueryBase = [
            'datadoc' => $docDate,
            'strTpDoc' => $ligacaoDocType,
        ];
        $docYear = substr($docDate, 0, 4);
        if (preg_match('/^\d{4}$/', $docYear)) {
            $ligacaoQueryBase['strCodExercicio'] = $docYear;
        }
        foreach ($databaseCandidates as $databaseCandidate) {
            foreach ($ligacaoNifCandidates as $ligacaoNifCandidate) {
                $ligacaoPayload = fetchErpJsonForSuggestion('/contabilidade/LigacaoCteTipoDoc', $ligacaoQueryBase + [
                    'strNIF' => $ligacaoNifCandidate,
                ], $databaseCandidate);
                if (empty($ligacaoPayload)) {
                    continue;
                }
                $candidateRows = extractErpRowsFromPayload($ligacaoPayload);
                if (empty($candidateRows)) {
                    continue;
                }
                $ligacaoRows = $candidateRows;
                $database = $databaseCandidate;
                break 2;
            }
        }
    }

    $ligacaoGeneralAccounts = [];
    $ligacaoIvaAccounts = [];
    $ligacaoTotalCreditAccounts = [];
    $ligacaoTotalEntityAccounts = [];
    foreach ($ligacaoRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $tipo = strtoupper(trim((string) ($row['strTipo'] ?? '')));
        $accountCandidates = resolveSuggestionLigacaoAccountCandidates($row);
        $general = $accountCandidates['general'];
        $iva = $accountCandidates['iva'];
        $total = trim((string) ($row['strContaEntidade'] ?? ''));
        if ($tipo === $ligacaoTotalLineType && $general !== '') {
            $ligacaoTotalCreditAccounts[$general] = ($ligacaoTotalCreditAccounts[$general] ?? 0) + 1;
        }
        if ($total !== '') {
            $ligacaoTotalEntityAccounts[$total] = ($ligacaoTotalEntityAccounts[$total] ?? 0) + 1;
        }
        if ($tipo !== '' && $tipo !== $ligacaoRateLineType) {
            continue;
        }
        $rateKey = resolveSuggestionLigacaoRateKeyFromRow($row);
        if ($rateKey !== '' && !isset($ligacaoPerRate[$rateKey])) {
            $ligacaoPerRate[$rateKey] = ['general' => [], 'iva' => []];
        }
        if ($general !== '') {
            $ligacaoGeneralAccounts[$general] = ($ligacaoGeneralAccounts[$general] ?? 0) + 1;
            if ($rateKey !== '') {
                $ligacaoPerRate[$rateKey]['general'][$general] = ($ligacaoPerRate[$rateKey]['general'][$general] ?? 0) + 1;
            }
        }
        if ($iva !== '') {
            $ligacaoIvaAccounts[$iva] = ($ligacaoIvaAccounts[$iva] ?? 0) + 1;
            if ($rateKey !== '') {
                $ligacaoPerRate[$rateKey]['iva'][$iva] = ($ligacaoPerRate[$rateKey]['iva'][$iva] ?? 0) + 1;
            }
        }
    }
    arsort($ligacaoGeneralAccounts);
    arsort($ligacaoIvaAccounts);
    arsort($ligacaoTotalCreditAccounts);
    arsort($ligacaoTotalEntityAccounts);
    foreach ($ligacaoPerRate as $rateKey => $entry) {
        arsort($entry['general']);
        arsort($entry['iva']);
        $ligacaoPerRate[$rateKey] = $entry;
    }
    $ligacaoTotalAccounts = !empty($ligacaoTotalCreditAccounts)
        ? $ligacaoTotalCreditAccounts
        : $ligacaoTotalEntityAccounts;

    $movementRows = [];
    $movementPayload = fetchErpJsonForSuggestion('/contabilidade/movimentos', [
        'limit' => 120,
        'offset' => 0,
        'strAbrevTpDoc' => $docType,
        'strNumContrib' => $acquirerNif,
    ], $database);
    if (!empty($movementPayload)) {
        $movementRows = extractErpRowsFromPayload($movementPayload);
    }
    $movementAccounts = [];
    foreach ($movementRows as $row) {
        extractAccountTokensFromMixed($row, $movementAccounts);
    }

    $planRows = [];
    $planPayload = fetchErpJsonForSuggestion('/contabilidade/planocontas', [
        'strCodExercicio' => date('Y'),
        'strNumContrib' => $acquirerNif,
        'limit' => 200,
        'offset' => 0,
    ], $database);
    if (!empty($planPayload)) {
        $planRows = extractErpRowsFromPayload($planPayload);
    }
    $globalPlanRows = [];

    $planAccounts = [];
    $planIvaAccounts = [];
    foreach ($planRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $general = trim((string) ($row['strConta'] ?? ''));
        $iva = trim((string) ($row['strConta_Iva'] ?? ''));
        if ($general !== '') {
            $planAccounts[$general] = true;
        }
        if ($iva !== '') {
            $planIvaAccounts[$iva] = true;
        }
    }

    $missingSupplierInErp = empty($ligacaoRows) && $ligacaoDocType !== '' && $docDate !== '' && !empty($ligacaoNifCandidates) && !empty($databaseCandidates);
    $supplierLookupNif = (string) ($ligacaoNifCandidates[0] ?? '');
    $supplierNotFoundMessage = '';
    if ($missingSupplierInErp) {
        $supplierNotFoundMessage = 'Fornecedor'
            . ($supplierLookupNif !== '' ? ' ' . $supplierLookupNif : '')
            . ' não encontrado na Ligação Cte Tipo Doc ERP'
            . ' (db=' . ($database !== '' ? $database : 'n/d')
            . ', tipo=' . ($ligacaoDocType !== '' ? $ligacaoDocType : 'n/d') . ').';
    }

    $explanations = [];
    foreach ($rateItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $rawRateKey = trim((string) ($item['key'] ?? ''));
        if ($rawRateKey === '') {
            continue;
        }
        $rateKey = normalizeSuggestionRateKey($rawRateKey);
        if ($rateKey === '') {
            $rateKey = $rawRateKey;
        }

        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '') {
            $label = buildVatRateLabel($rateKey);
        }
        $suggestedGeneral = trim((string) ($item['general_account'] ?? ''));
        $suggestedIva = trim((string) ($item['iva_account'] ?? ''));

        $historyRate = $historyTally['rates'][$rateKey] ?? ['general' => [], 'iva' => []];
        $rulesRate = $ruleTally['rates'][$rateKey] ?? ['general' => [], 'iva' => []];
        $ligacaoRate = $ligacaoPerRate[$rateKey] ?? ['general' => [], 'iva' => []];
        $ligacaoRateGeneral = is_array($ligacaoRate['general'] ?? null) ? $ligacaoRate['general'] : [];
        $ligacaoRateIva = is_array($ligacaoRate['iva'] ?? null) ? $ligacaoRate['iva'] : [];

        $topHistoryGeneral = (string) (array_key_first($historyRate['general']) ?? '');
        $topHistoryIva = (string) (array_key_first($historyRate['iva']) ?? '');
        $topRulesGeneral = (string) (array_key_first($rulesRate['general']) ?? '');
        $topRulesIva = (string) (array_key_first($rulesRate['iva']) ?? '');

        $reasons = [];
        if ((int) ($historyTally['samples'] ?? 0) > 0) {
            $line = 'Histórico MySQL analisado (' . (int) $historyTally['samples'] . ' registos)';
            if ($topHistoryGeneral !== '' || $topHistoryIva !== '') {
                $line .= ': top geral ' . ($topHistoryGeneral !== '' ? $topHistoryGeneral : '-') . ', top IVA ' . ($topHistoryIva !== '' ? $topHistoryIva : '-') . '.';
            } else {
                $line .= '.';
            }
            $reasons[] = $line;
        }
        if ((int) ($ruleTally['samples'] ?? 0) > 0) {
            $line = 'Regras de classificação analisadas (' . (int) $ruleTally['samples'] . ' regras)';
            if ($topRulesGeneral !== '' || $topRulesIva !== '') {
                $line .= ': top geral ' . ($topRulesGeneral !== '' ? $topRulesGeneral : '-') . ', top IVA ' . ($topRulesIva !== '' ? $topRulesIva : '-') . '.';
            } else {
                $line .= '.';
            }
            $reasons[] = $line;
        }

        if (!empty($movementAccounts)) {
            $movementHits = [];
            if ($suggestedGeneral !== '') {
                $movementHits[] = 'geral ' . $suggestedGeneral . ': ' . (int) ($movementAccounts[$suggestedGeneral] ?? 0) . ' ocorrências';
            }
            if ($suggestedIva !== '') {
                $movementHits[] = 'IVA ' . $suggestedIva . ': ' . (int) ($movementAccounts[$suggestedIva] ?? 0) . ' ocorrências';
            }
            $reasons[] = 'Movimentos ERP analisados (' . count($movementRows) . ' linhas)' . (!empty($movementHits) ? ' - ' . implode(', ', $movementHits) . '.' : '.');
        }

        if (!empty($ligacaoRows)) {
            $ligacaoHits = [];
            $useRateScopedLigacao = !empty($ligacaoRateGeneral) || !empty($ligacaoRateIva);
            if ($suggestedGeneral !== '') {
                $ligacaoHits[] = 'geral ' . $suggestedGeneral . ': ' . (int) (
                    $useRateScopedLigacao
                        ? ($ligacaoRateGeneral[$suggestedGeneral] ?? 0)
                        : ($ligacaoGeneralAccounts[$suggestedGeneral] ?? 0)
                ) . ' ocorrências';
            }
            if ($suggestedIva !== '') {
                $ligacaoHits[] = 'IVA ' . $suggestedIva . ': ' . (int) (
                    $useRateScopedLigacao
                        ? ($ligacaoRateIva[$suggestedIva] ?? 0)
                        : ($ligacaoIvaAccounts[$suggestedIva] ?? 0)
                ) . ' ocorrências';
            }
            if (empty($ligacaoHits)) {
                $topGeneral = (string) (array_key_first($useRateScopedLigacao ? $ligacaoRateGeneral : $ligacaoGeneralAccounts) ?? '');
                $topIva = (string) (array_key_first($useRateScopedLigacao ? $ligacaoRateIva : $ligacaoIvaAccounts) ?? '');
                if ($topGeneral !== '') {
                    $ligacaoHits[] = 'top geral ' . $topGeneral;
                }
                if ($topIva !== '') {
                    $ligacaoHits[] = 'top IVA ' . $topIva;
                }
            }
            $reasons[] = 'Ligação Cte Tipo Doc ERP analisada (' . count($ligacaoRows) . ' linhas, db=' . ($database !== '' ? $database : 'n/d') . ')'
                . ($useRateScopedLigacao ? ' - taxa mapeada por PC_Descricao' : '')
                . (!empty($ligacaoHits) ? ' - ' . implode(', ', $ligacaoHits) . '.' : '.');
        } elseif ($supplierNotFoundMessage !== '') {
            $reasons[] = $supplierNotFoundMessage;
        }

        if (!empty($planRows)) {
            $generalInPlan = $suggestedGeneral !== '' && isset($planAccounts[$suggestedGeneral]);
            $ivaInPlan = $suggestedIva !== '' && isset($planIvaAccounts[$suggestedIva]);
            $reasons[] = 'Plano de contas ERP consultado como fallback (última opção) (' . count($planRows) . ' linhas, db=' . ($database !== '' ? $database : 'n/d') . '): '
                . 'geral ' . ($generalInPlan ? 'válida' : 'não encontrada')
                . ', IVA ' . ($ivaInPlan ? 'válida' : 'não encontrada') . '.';
        }

        if (empty($reasons)) {
            $reasons[] = 'Sem evidências suficientes nas fontes disponíveis para esta taxa.';
        }

        $explanations[$rateKey] = [
            'rate_key' => $rateKey,
            'label' => $label,
            'suggested' => [
                'general_account' => $suggestedGeneral,
                'iva_account' => $suggestedIva,
            ],
            'top_accounts' => [
                'history' => [
                    'general' => $topHistoryGeneral,
                    'iva' => $topHistoryIva,
                ],
                'rules' => [
                    'general' => $topRulesGeneral,
                    'iva' => $topRulesIva,
                ],
            ],
            'reasons' => $reasons,
        ];
    }

    $suggestedTotalAccount = trim((string) ($args['total_account'] ?? ''));
    if ($missingSupplierInErp) {
        $suggestedTotalAccount = '';
        if ($hasReceiptCompanion) {
            foreach ($planRows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $account = trim((string) ($row['strConta'] ?? ''));
                if ($account !== '' && strpos($account, '12') === 0) {
                    $suggestedTotalAccount = $account;
                    break;
                }
            }
            if ($suggestedTotalAccount === '' && $database !== '') {
                $globalPlanPayload = fetchErpJsonForSuggestion('/contabilidade/planocontas', [
                    'strCodExercicio' => date('Y'),
                    'limit' => 200,
                    'offset' => 0,
                ], $database);
                if (!empty($globalPlanPayload)) {
                    $globalPlanRows = extractErpRowsFromPayload($globalPlanPayload);
                }
                foreach ($globalPlanRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $account = trim((string) ($row['strConta'] ?? ''));
                    if ($account !== '' && strpos($account, '12') === 0) {
                        $suggestedTotalAccount = $account;
                        break;
                    }
                }
            }
        }
    } else {
        if ($suggestedTotalAccount === '' && !empty($historyTally['totals'])) {
            $suggestedTotalAccount = (string) array_key_first($historyTally['totals']);
        }
        if ($suggestedTotalAccount === '' && !empty($ruleTally['totals'])) {
            $suggestedTotalAccount = (string) array_key_first($ruleTally['totals']);
        }
        if ($suggestedTotalAccount === '' && !empty($ligacaoTotalAccounts)) {
            $suggestedTotalAccount = (string) array_key_first($ligacaoTotalAccounts);
        }
    }

    $topHistoryTotal = !empty($historyTally['totals']) ? (string) array_key_first($historyTally['totals']) : '';
    $topRulesTotal = !empty($ruleTally['totals']) ? (string) array_key_first($ruleTally['totals']) : '';

    $totalReasons = [];
    if ((int) ($historyTally['samples'] ?? 0) > 0) {
        $line = 'Histórico MySQL analisado (' . (int) $historyTally['samples'] . ' registos)';
        if ($topHistoryTotal !== '') {
            $line .= ': conta total mais usada ' . $topHistoryTotal . '.';
        } else {
            $line .= '.';
        }
        $totalReasons[] = $line;
    }
    if ((int) ($ruleTally['samples'] ?? 0) > 0) {
        $line = 'Regras de classificação analisadas (' . (int) $ruleTally['samples'] . ' regras)';
        if ($topRulesTotal !== '') {
            $line .= ': conta total mais usada ' . $topRulesTotal . '.';
        } else {
            $line .= '.';
        }
        $totalReasons[] = $line;
    }
    if (!empty($movementAccounts)) {
        $occurrences = $suggestedTotalAccount !== '' ? (int) ($movementAccounts[$suggestedTotalAccount] ?? 0) : 0;
        $totalReasons[] = 'Movimentos ERP analisados (' . count($movementRows) . ' linhas): conta total '
            . ($suggestedTotalAccount !== '' ? $suggestedTotalAccount : 'n/d')
            . ' com ' . $occurrences . ' ocorrências.';
    }
    if (!empty($ligacaoRows)) {
        $occurrences = $suggestedTotalAccount !== '' ? (int) ($ligacaoTotalAccounts[$suggestedTotalAccount] ?? 0) : 0;
        $topLigacaoTotal = (string) (array_key_first($ligacaoTotalAccounts) ?? '');
        $totalReasons[] = 'Ligação Cte Tipo Doc ERP analisada (' . count($ligacaoRows) . ' linhas): conta total '
            . ($suggestedTotalAccount !== '' ? $suggestedTotalAccount : 'n/d')
            . ' com ' . $occurrences . ' ocorrências'
            . ($topLigacaoTotal !== '' ? ' (top: ' . $topLigacaoTotal . ').' : '.');
    } elseif ($ligacaoDocType !== '' && $docDate !== '' && !empty($ligacaoNifCandidates) && !empty($databaseCandidates)) {
        $line = $supplierNotFoundMessage !== ''
            ? $supplierNotFoundMessage . ' Sem conta automática de valor total a partir da ligação ERP.'
            : 'Ligação Cte Tipo Doc ERP sem resultados para o fornecedor/tipo do documento; sem conta automática de valor total a partir da ligação ERP.';
        if ($hasReceiptCompanion) {
            $line .= ' Com recibo no mesmo PDF, o fallback fica limitado a conta de banco (12...).';
        }
        $totalReasons[] = $line;
    }
    if (!empty($planRows)) {
        $inPlan = $suggestedTotalAccount !== '' && isset($planAccounts[$suggestedTotalAccount]);
        $totalReasons[] = 'Plano de contas ERP consultado como fallback (última opção) (' . count($planRows) . ' linhas, db=' . ($database !== '' ? $database : 'n/d') . '): conta total '
            . ($inPlan ? 'válida' : 'não encontrada') . '.';
    }
    if (empty($totalReasons)) {
        $totalReasons[] = 'Sem evidências suficientes nas fontes disponíveis para a conta de valor total.';
    }

    echo json_encode([
        'success' => true,
        'csrf_token' => generateCsrfToken(),
        'summary' => [
            'history_samples' => (int) ($historyTally['samples'] ?? 0),
            'rule_samples' => (int) ($ruleTally['samples'] ?? 0),
            'erp_ligacao_rows' => count($ligacaoRows),
            'erp_movement_rows' => count($movementRows),
            'erp_plan_rows' => count($planRows),
            'database' => $database,
            'supplier_not_found' => $missingSupplierInErp ? 1 : 0,
            'supplier_lookup_nif' => $supplierLookupNif,
            'supplier_lookup_doc_type' => $ligacaoDocType,
            'supplier_lookup_message' => $supplierNotFoundMessage,
        ],
        'total_account' => [
            'suggested' => $suggestedTotalAccount,
            'top_accounts' => [
                'history' => $topHistoryTotal,
                'rules' => $topRulesTotal,
            ],
            'reasons' => $totalReasons,
        ],
        'rates' => $explanations,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($action === 'cost_centers' || $action === 'cost-centers') && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');

    $database = trim((string) ($_GET['db'] ?? ''));
    $docDate = normalizeSuggestionDocDate((string) ($_GET['doc_date'] ?? ''));
    $query = [];
    if ($docDate !== '') {
        $query['datadoc'] = $docDate;
    }

    $payload = fetchErpJsonForSuggestion('/contabilidade/centroscusto', $query, $database);
    $rows = !empty($payload) ? extractErpRowsFromPayload($payload) : [];
    if (empty($rows) && $docDate !== '') {
        $fallbackPayload = fetchErpJsonForSuggestion('/contabilidade/centroscusto', [], $database);
        if (!empty($fallbackPayload)) {
            $rows = extractErpRowsFromPayload($fallbackPayload);
        }
    }
    $items = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string) ($row['strConta'] ?? ''));
        if ($code === '') {
            continue;
        }
        $description = trim((string) ($row['strDescricao'] ?? ''));
        $movimenta = trim((string) ($row['bitmovimenta'] ?? $row['bitMovimenta'] ?? ''));
        if ($movimenta !== '' && $movimenta !== '1') {
            continue;
        }
        $items[] = [
            'code' => $code,
            'description' => $description,
            'label' => $description !== '' ? ($code . ' - ' . $description) : $code,
        ];
    }
    usort($items, static function (array $left, array $right): int {
        return strnatcasecmp((string) ($left['code'] ?? ''), (string) ($right['code'] ?? ''));
    });

    echo json_encode([
        'success' => true,
        'items' => $items,
        'db' => $database,
        'doc_date' => $docDate,
        'csrf_token' => generateCsrfToken(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (
    ($action === 'qr_doc_type_mapping' || $action === 'qr-doc-type-mapping' || $action === 'doc_type_mapping' || $action === 'doc-type-mapping')
    && $_SERVER['REQUEST_METHOD'] === 'POST'
) {
    header('Content-Type: application/json; charset=utf-8');

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody ?? '', true);
    $response = [
        'success' => false,
        'requires_mapping' => false,
        'items' => [],
        'options' => [],
    ];

    if (!is_array($payload)) {
        $response['error'] = 'Pedido inválido.';
        $response['csrf_token'] = generateCsrfToken(true);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $csrfToken = (string) ($payload['csrf_token'] ?? '');
    if ($csrfToken === '' || !validateCsrfToken($csrfToken)) {
        $response['error'] = 'Token CSRF inválido.';
        $response['csrf_token'] = generateCsrfToken(true);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $response['csrf_token'] = generateCsrfToken();

    $ids = [];
    foreach ($payload['ids'] ?? [] as $value) {
        if (is_numeric($value)) {
            $id = (int) $value;
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

    $requestedImportType = (int) ($payload['import_type'] ?? $importType);
    if ($requestedImportType <= 0) {
        $requestedImportType = 1;
    }

    $allowClassifiedFlow = (int) ($payload['allow_classified_flow'] ?? 0) === 1;
    if ($requestedImportType === 1 && !userHasDepartmentPermission('ctb_importar_docs') && !$allowClassifiedFlow) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Sem permissao para importar documentos.',
            'csrf_token' => generateCsrfToken(true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $requestedDatabase = trim((string) ($payload['database'] ?? ''));
    try {
        $groupResolution = collectImportGroupsByDatabase($pdo, $ids, $requestedImportType, $requestedDatabase);
    } catch (Throwable $throwable) {
        logErpMessage('Erro ao determinar a base de dados ERP para associação de tipos QR: ' . $throwable->getMessage());
        $response['error'] = 'Não foi possível determinar a base de dados ERP para associar o tipo documental.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $missingEntities = $groupResolution['missing'] ?? [];
    if (!empty($missingEntities)) {
        $missingLabel = summarizeImportGroupMissingEntities($missingEntities);
        $response['error'] = 'Não foi possível determinar a base de dados ERP para associar o tipo documental'
            . ($missingLabel !== '' ? ' em: ' . $missingLabel : '.');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $databaseGroups = $groupResolution['groups'] ?? [];
    if (empty($databaseGroups)) {
        $targetDatabase = $requestedDatabase;
        if ($targetDatabase === '') {
            $targetDatabase = trim((string) getSetting('erp_database', ''));
        }

        if ($targetDatabase === '') {
            $response['error'] = 'Não foi possível determinar a base de dados ERP para associar o tipo documental.';
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $databaseGroups = [
            $targetDatabase => $ids,
        ];
    }

    $mode = strtolower(trim((string) ($payload['mode'] ?? 'check')));
    if ($mode === 'save') {
        $requestedMappings = is_array($payload['mappings'] ?? null) ? $payload['mappings'] : [];
        $requestedGroupMappings = is_array($payload['group_mappings'] ?? null) ? $payload['group_mappings'] : [];
        $savedMappings = [];

        $mappingsByDatabase = [];
        foreach ($requestedGroupMappings as $groupDatabase => $groupMappings) {
            $normalizedDatabase = trim((string) $groupDatabase);
            if ($normalizedDatabase === '' || !isset($databaseGroups[$normalizedDatabase]) || !is_array($groupMappings)) {
                continue;
            }
            $mappingsByDatabase[$normalizedDatabase] = $groupMappings;
        }

        if (empty($mappingsByDatabase)) {
            $fallbackDatabase = $requestedDatabase;
            if ($fallbackDatabase === '' && !empty($databaseGroups)) {
                $fallbackDatabase = (string) array_key_first($databaseGroups);
            }
            if ($fallbackDatabase !== '' && isset($databaseGroups[$fallbackDatabase])) {
                $mappingsByDatabase[$fallbackDatabase] = $requestedMappings;
            }
        }

        foreach ($mappingsByDatabase as $mappingDatabase => $databaseMappings) {
            $context = buildQrDocTypeMappingContextForIds($pdo, $databaseGroups[$mappingDatabase], $requestedImportType, $mappingDatabase);
            $options = is_array($context['options'] ?? null) ? $context['options'] : [];
            if (empty($options)) {
                $response['error'] = 'O webservice ERP não devolveu tipos documentais disponíveis para a base ' . $mappingDatabase . '.';
                echo json_encode($response, JSON_UNESCAPED_UNICODE);
                exit;
            }

            $optionsByValue = [];
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optionValue = trim((string) ($option['value'] ?? ''));
                if ($optionValue === '') {
                    continue;
                }
                $optionsByValue[$optionValue] = $option;
            }

            foreach ($databaseMappings as $qrDocType => $erpDocTypeValue) {
                $mappingKey = getAccountingQrDocTypeMappingKey((string) $qrDocType);
                $erpDocType = trim((string) $erpDocTypeValue);
                if ($mappingKey === '' || $erpDocType === '' || !isset($optionsByValue[$erpDocType])) {
                    continue;
                }

                $option = $optionsByValue[$erpDocType];
                try {
                    if (!isset($savedMappings[$mappingDatabase])) {
                        $savedMappings[$mappingDatabase] = [];
                    }
                    $savedMappings[$mappingDatabase][$mappingKey] = setAccountingQrDocTypeMapping(
                        $mappingKey,
                        $erpDocType,
                        trim((string) ($option['title'] ?? $option['label'] ?? $erpDocType)),
                        $mappingDatabase
                    );
                } catch (Throwable $throwable) {
                    $response['error'] = 'Não foi possível guardar a associação do tipo documental para a base ' . $mappingDatabase . '.';
                    logErpMessage('Erro ao guardar associação do tipo documental QR para a base ' . $mappingDatabase . ': ' . $throwable->getMessage());
                    echo json_encode($response, JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }
        }

        if (empty($savedMappings)) {
            $response['error'] = 'Selecione uma associação válida para guardar.';
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }

        $response['success'] = true;
        $response['saved_mappings'] = $savedMappings;
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $groupContexts = [];
    foreach ($databaseGroups as $groupDatabase => $groupIds) {
        $context = buildQrDocTypeMappingContextForIds($pdo, $groupIds, $requestedImportType, (string) $groupDatabase);
        $options = is_array($context['options'] ?? null) ? $context['options'] : [];
        if (empty($options)) {
            $response['error'] = 'O webservice ERP não devolveu tipos documentais disponíveis para a base ' . $groupDatabase . '.';
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!empty($context['items'])) {
            $groupContexts[] = $context;
        }
    }

    if (count($groupContexts) === 1) {
        $singleContext = $groupContexts[0];
        $response['database'] = $singleContext['database'] ?? '';
        $response['options'] = $singleContext['options'] ?? [];
        $response['items'] = $singleContext['items'] ?? [];
    } else {
        $response['database'] = '';
        $response['options'] = [];
        $response['items'] = [];
    }

    $response['success'] = true;
    $response['requires_mapping'] = !empty($groupContexts);
    $response['groups'] = $groupContexts;

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
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
    $allowClassifiedFlow = (int)($payload['allow_classified_flow'] ?? 0) === 1;
    if ($requestedImportType === 1 && !userHasDepartmentPermission('ctb_importar_docs') && !$allowClassifiedFlow) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Sem permissao para importar documentos.',
            'csrf_token' => generateCsrfToken(true)
        ]);
        exit;
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

    try {
        $groupResolution = collectImportGroupsByDatabase($pdo, $ids, $requestedImportType);
    } catch (Throwable $throwable) {
        logErpMessage('Erro ao determinar grupos de importação CTB por adquirente: ' . $throwable->getMessage());
        $response['error'] = 'Não foi possível determinar a base de dados do adquirente.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($entities)) {
        $response['success'] = true;
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $entitiesByNif = [];
    foreach ($entities as $entity) {
        if (!is_array($entity)) {
            continue;
        }
        $nif = trim((string) ($entity['nif'] ?? ''));
        if ($nif === '') {
            continue;
        }
        $entitiesByNif[$nif] = $entity;
    }

    $missingSelectionCandidates = [];
    foreach (($groupResolution['missing'] ?? []) as $missingItem) {
        if (!is_array($missingItem)) {
            continue;
        }

        $nif = trim((string) ($missingItem['nif'] ?? ''));
        $key = $nif !== '' ? $nif : ('id_' . (int) ($missingItem['id'] ?? 0));
        if (isset($missingSelectionCandidates[$key])) {
            continue;
        }

        $entity = $nif !== '' && isset($entitiesByNif[$nif]) ? $entitiesByNif[$nif] : [
            'nif' => $nif,
            'name' => trim((string) ($missingItem['display_name'] ?? '')),
            'display_name' => trim((string) ($missingItem['display_name'] ?? '')),
            'erp_database' => '',
            'entity_type' => 'acquirer',
            'erp_client_code' => '',
        ];
        $missingSelectionCandidates[$key] = $entity;
    }

    $entity = $entities[0];
    if (count($missingSelectionCandidates) === 1) {
        $entity = reset($missingSelectionCandidates);
    }

    $entityDatabase = resolveAccountingEntityDatabase($entity);
    $entityResponse = [
        'nif' => $entity['nif'],
        'name' => $entity['name'],
        'display_name' => $entity['display_name'],
        'erp_database' => $entityDatabase,
    ];

    if ($mode === 'check') {
        $response['success'] = true;
        if (count($missingSelectionCandidates) === 1) {
            $response['entity'] = $entityResponse;
            $response['requires_selection'] = true;
            $response['message'] = 'Selecione a base de dados do adquirente antes de importar.';
        } elseif (!empty($missingSelectionCandidates)) {
            $response['success'] = false;
            $response['error'] = 'Existem adquirentes sem base de dados ERP associada: '
                . summarizeImportGroupMissingEntities($groupResolution['missing'] ?? []) . '.';
        } elseif (count($entities) === 1) {
            $response['entity'] = $entityResponse;
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (count($missingSelectionCandidates) !== 1) {
        $response['error'] = 'Não foi possível determinar um único adquirente para atualizar a base de dados ERP.';
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $selectedDatabase = trim((string)($payload['selected_database'] ?? ''));
    $selectedDatabaseId = trim((string)($payload['selected_database_id'] ?? ''));
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

    $companyValidation = fetchErpConfigEmpresaByDatabase($selectedDatabase, $selectedDatabaseId);
    if (empty($companyValidation['ok'])) {
        $response['error'] = trim((string) ($companyValidation['error'] ?? 'Não foi possível validar a base de dados ERP selecionada.'));
        if ($response['error'] === '') {
            $response['error'] = 'Não foi possível validar a base de dados ERP selecionada.';
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $validatedCompanyName = trim((string) ($companyValidation['name'] ?? ''));
    if ($validatedCompanyName !== '') {
        $entityName = $validatedCompanyName;
    }

    $saveData = [
        'nif' => $entity['nif'],
        'name' => $entityName,
        'erp_database' => $selectedDatabase,
        'entity_type' => $entityType,
        'erp_client_code' => $entityType === 'acquirer'
            ? fetchAccountingAcquirerClientCodeFromBaseErp(
                (string) $entity['nif'],
                trim((string)($entity['erp_client_code'] ?? ''))
            )
            : trim((string)($entity['erp_client_code'] ?? '')),
    ];

    try {
        saveAccountingEntity($pdo, $saveData);
        $stored = findAccountingEntityByType($pdo, $entity['nif'], $entityType);
        if (!is_array($stored)) {
            $stored = findAccountingEntity($pdo, $entity['nif']);
        }
        if (is_array($stored)) {
            $entityResponse['name'] = trim((string)($stored['name'] ?? $entityName)) ?: $entityName;
            $entityResponse['display_name'] = $entityResponse['name'];
            $entityResponse['erp_database'] = resolveAccountingEntityDatabase($stored) ?: $selectedDatabase;
        } else {
            $entityResponse['name'] = $entityName;
            $entityResponse['display_name'] = $entityName;
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

    try {
        $groupResolution = collectImportGroupsByDatabase($pdo, $ids, $requestedImportType, $requestedDatabase);
    } catch (Throwable $throwable) {
        logErpMessage('Erro ao determinar grupos da importação CTB: ' . $throwable->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Não foi possível determinar a base de dados do adquirente.',
            'csrf_token' => generateCsrfToken(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $missingEntities = $groupResolution['missing'] ?? [];
    if (!empty($missingEntities)) {
        echo json_encode([
            'success' => false,
            'error' => 'Existem adquirentes sem base de dados ERP associada: ' . summarizeImportGroupMissingEntities($missingEntities) . '.',
            'csrf_token' => generateCsrfToken(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $databaseGroups = $groupResolution['groups'] ?? [];
    if (count($databaseGroups) > 1) {
        $batchResults = [];
        $mergedCabIdMap = [];
        $successCount = 0;
        $failureCount = 0;
        $statusCodes = [];
        $successDatabases = [];
        $failedDatabases = [];

        foreach ($databaseGroups as $groupDatabase => $groupIds) {
            $serviceResult = import_CTB($pdo, $groupIds, $requestedImportType, (string) $groupDatabase);
            $batchSuccess = !empty($serviceResult['success']);
            $servicePayload = null;

            $batchPayload = [
                'database' => (string) $groupDatabase,
                'ids' => array_values($groupIds),
                'success' => $batchSuccess,
                'http_status' => $serviceResult['status'] ?? 0,
            ];

            if (!empty($serviceResult['error'])) {
                $batchPayload['error'] = $serviceResult['error'];
            }
            if (!empty($serviceResult['error_detail'])) {
                $batchPayload['error_detail'] = $serviceResult['error_detail'];
            }

            if (array_key_exists('decoded', $serviceResult)) {
                $servicePayload = sanitizeServiceDebugPayload($serviceResult['decoded']);
                $batchPayload['service_payload'] = $servicePayload;

                if (is_array($servicePayload) && array_key_exists('response', $servicePayload)) {
                    $serviceResponse = sanitizeServiceDebugPayload($servicePayload['response']);
                    if ($serviceResponse !== null && $serviceResponse !== '') {
                        $batchPayload['service_response'] = $serviceResponse;
                    }
                }
            }

            if (!array_key_exists('service_response', $batchPayload) && array_key_exists('response', $serviceResult)) {
                $batchPayload['service_response'] = sanitizeServiceDebugPayload($serviceResult['response']);
            }

            if (!empty($serviceResult['message'])) {
                $batchPayload['message'] = $serviceResult['message'];
            }
            if (array_key_exists('log', $serviceResult)) {
                $batchPayload['log'] = $serviceResult['log'];
            }
            if (!empty($serviceResult['cab_id_map'])) {
                $batchPayload['cab_id_map'] = $serviceResult['cab_id_map'];
                $mergedCabIdMap = array_replace($mergedCabIdMap, $serviceResult['cab_id_map']);
            }

            $batchMessage = '';
            if (is_array($servicePayload)) {
                foreach (['mensagem', 'message', 'msg', 'mensagem_erro'] as $messageKey) {
                    if (!isset($servicePayload[$messageKey])) {
                        continue;
                    }
                    $candidate = trim((string) $servicePayload[$messageKey]);
                    if ($candidate !== '') {
                        $batchMessage = $candidate;
                        break;
                    }
                }
            } elseif (is_string($servicePayload)) {
                $candidate = trim($servicePayload);
                if ($candidate !== '') {
                    $batchMessage = $candidate;
                }
            }
            if ($batchMessage === '' && !empty($serviceResult['message'])) {
                $batchMessage = trim((string) $serviceResult['message']);
            }
            if ($batchMessage === '' && !$batchSuccess && !empty($serviceResult['error'])) {
                $batchMessage = trim((string) $serviceResult['error']);
            }
            if ($batchMessage !== '') {
                $batchPayload['message'] = $batchMessage;
            }

            $statusCodes[] = (int) ($serviceResult['status'] ?? 0);
            if ($batchSuccess) {
                $successCount++;
                $successDatabases[] = (string) $groupDatabase;
            } else {
                $failureCount++;
                $failedDatabases[] = (string) $groupDatabase;
            }

            $batchResults[] = $batchPayload;
        }

        $responsePayload = [
            'success' => $successCount > 0,
            'ids' => $ids,
            'import_type' => $requestedImportType,
            'csrf_token' => generateCsrfToken(),
            'group_count' => count($databaseGroups),
            'batches' => $batchResults,
            'http_status' => !empty($statusCodes) ? max($statusCodes) : 0,
        ];

        if (!empty($mergedCabIdMap)) {
            $responsePayload['cab_id_map'] = $mergedCabIdMap;
        }

        if ($failureCount === 0) {
            $responsePayload['type'] = 'success';
            $responsePayload['message'] = 'Importação concluída para ' . count($successDatabases) . ' empresa(s): ' . implode(', ', $successDatabases) . '.';
        } elseif ($successCount > 0) {
            $responsePayload['type'] = 'warning';
            $responsePayload['message'] = 'Importação concluída parcialmente. Empresas importadas: ' . implode(', ', $successDatabases)
                . '. Empresas com erro: ' . implode(', ', $failedDatabases) . '.';
        } else {
            $responsePayload['error'] = 'Falha ao importar os documentos nas empresas selecionadas: ' . implode(', ', $failedDatabases) . '.';
        }

        $jsonResponse = json_encode($responsePayload, JSON_UNESCAPED_UNICODE);
        if ($jsonResponse === false) {
            $jsonResponse = '{"success":false,"error":"Não foi possível preparar a resposta da importação."}';
        }

        echo $jsonResponse;
        exit;
    }

    $targetDatabase = $requestedDatabase;
    if (count($databaseGroups) === 1) {
        $targetDatabase = (string) array_key_first($databaseGroups);
    } elseif ($targetDatabase === '') {
        try {
            $entities = collectAcquirerEntities($pdo, $ids, $requestedImportType);
            if (!empty($entities)) {
                foreach ($entities as $entity) {
                    $candidateDatabase = resolveAccountingEntityDatabase($entity);
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

if ($action === 'ready_ids') {
    header('Content-Type: application/json; charset=utf-8');
    $viewMode = strtolower(trim((string) ($_GET['view_mode'] ?? ($_GET['type'] ?? ''))));
    $isImportOnlyRequest = $importType === 1 && $viewMode === 'import';

    try {
        $stmt = $pdo->prepare(
            'SELECT * '
            . 'FROM accounting_imports ai '
            . 'WHERE import_type = :importType AND (cab_id IS NULL OR cab_id = \'\') '
            . ($importType === 1 ? ' AND ' . buildReceiptRowsHiddenSqlCondition('ai') : '')
            . 'ORDER BY id'
        );
        $stmt->bindValue(':importType', $importType, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $readyIds = [];
        foreach ($rows as $row) {
            $preparedRow = prepareImportRow($row);

            if ($importType === 2) {
                if (trim((string) ($preparedRow['line_btn_class'] ?? '')) === 'btn-success') {
                    $readyIds[] = (int) $preparedRow['id'];
                }
                continue;
            }

            if ($isImportOnlyRequest) {
                if (isImportReadyRow($preparedRow)) {
                    $readyIds[] = (int) $preparedRow['id'];
                }
                continue;
            }

            if (isImportReadyRow($preparedRow)) {
                $readyIds[] = (int) $preparedRow['id'];
            }
        }

        echo json_encode([
            'success' => true,
            'ids' => $readyIds,
            'count' => count($readyIds),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $throwable) {
        http_response_code(500);
        if (function_exists('logErpMessage')) {
            logErpMessage('Erro ao obter linhas prontas para importação: ' . $throwable->getMessage());
        }
        echo json_encode([
            'success' => false,
            'ids' => [],
            'count' => 0,
            'error' => 'Não foi possível obter as linhas prontas para importação.',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'data') {
    $draw = (int)($_GET['draw'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');
    $viewMode = strtolower(trim((string) ($_GET['view_mode'] ?? ($_GET['type'] ?? ''))));
    $isImportOnlyRequest = $importType === 1 && $viewMode === 'import';

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
        $searchValue = '';
        if (isset($_GET['search']) && is_array($_GET['search'])) {
            $searchValue = trim((string) ($_GET['search']['value'] ?? ''));
        } else {
            $searchValue = trim((string) ($_GET['search'] ?? ''));
        }
        if ($length <= 0) {
            $length = 10;
        }

        $normalizeSearchToken = static function (string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }

            if (function_exists('mb_strtolower')) {
                return mb_strtolower($value, 'UTF-8');
            }

            return strtolower($value);
        };

        $buildSearchTokens = static function (string $value) use ($normalizeSearchToken): array {
            $value = trim($value);
            if ($value === '') {
                return [];
            }

            $normalizedWhitespace = preg_replace('/\s+/u', ' ', $value);
            if (is_string($normalizedWhitespace)) {
                $value = trim($normalizedWhitespace);
            }

            $tokens = preg_split('/\s+/u', $value);
            if (!is_array($tokens)) {
                $tokens = [$value];
            }

            $result = [];
            foreach ($tokens as $token) {
                $normalizedToken = $normalizeSearchToken((string) $token);
                if ($normalizedToken !== '') {
                    $result[] = $normalizedToken;
                }
            }

            return array_values(array_unique($result));
        };

        $searchTokens = $buildSearchTokens($searchValue);
        $matchRowSearch = static function (array $row) use ($searchTokens, $normalizeSearchToken): bool {
            if (empty($searchTokens)) {
                return true;
            }

            $haystackParts = [
                (string) ($row['id'] ?? ''),
                (string) ($row['account'] ?? ''),
                (string) ($row['cost_center'] ?? ''),
                (string) ($row['field_A'] ?? ''),
                (string) ($row['field_B'] ?? ''),
                (string) ($row['field_C'] ?? ''),
                (string) ($row['field_D'] ?? ''),
                (string) ($row['field_E'] ?? ''),
                (string) ($row['field_F'] ?? ''),
                (string) ($row['field_G'] ?? ''),
                (string) ($row['field_H'] ?? ''),
                (string) ($row['field_I1'] ?? ''),
                (string) ($row['field_I3'] ?? ''),
                (string) ($row['field_I4'] ?? ''),
                (string) ($row['field_I5'] ?? ''),
                (string) ($row['field_I6'] ?? ''),
                (string) ($row['field_I7'] ?? ''),
                (string) ($row['field_I8'] ?? ''),
                (string) ($row['field_N'] ?? ''),
                (string) ($row['field_O'] ?? ''),
                (string) ($row['field_Q'] ?? ''),
                (string) ($row['field_R'] ?? ''),
                (string) ($row['filename'] ?? ''),
                (string) ($row['emitter_display_name'] ?? ''),
                (string) ($row['emitter_raw_value'] ?? ''),
                (string) ($row['emitter_nif_normalized'] ?? ''),
            ];

            $haystack = $normalizeSearchToken(implode(' ', $haystackParts));
            foreach ($searchTokens as $token) {
                if (strpos($haystack, $token) === false) {
                    return false;
                }
            }

            return true;
        };

        $searchSql = '';
        $searchBindings = [];
        if (!empty($searchTokens)) {
            $searchExpr = "LOWER(CONCAT_WS(' ', "
                . "CAST(ai.id AS CHAR), "
                . "COALESCE(ai.account, ''), "
                . "COALESCE(ai.cost_center, ''), "
                . "COALESCE(ai.field_A, ''), "
                . "COALESCE(ai.field_B, ''), "
                . "COALESCE(ai.field_C, ''), "
                . "COALESCE(ai.field_D, ''), "
                . "COALESCE(ai.field_E, ''), "
                . "COALESCE(ai.field_F, ''), "
                . "COALESCE(ai.field_G, ''), "
                . "COALESCE(ai.field_H, ''), "
                . "COALESCE(ai.field_I1, ''), "
                . "COALESCE(ai.field_I3, ''), "
                . "COALESCE(ai.field_I4, ''), "
                . "COALESCE(ai.field_I5, ''), "
                . "COALESCE(ai.field_I6, ''), "
                . "COALESCE(ai.field_I7, ''), "
                . "COALESCE(ai.field_I8, ''), "
                . "COALESCE(ai.field_N, ''), "
                . "COALESCE(ai.field_O, ''), "
                . "COALESCE(ai.field_Q, ''), "
                . "COALESCE(ai.field_R, ''), "
                . "COALESCE(ai.filename, '')"
                . "))";
            $searchClauses = [];
            foreach ($searchTokens as $index => $token) {
                $paramName = ':search_' . $index;
                $searchClauses[] = $searchExpr . ' LIKE ' . $paramName;
                $searchBindings[$paramName] = '%' . $token . '%';
            }
            $searchSql = ' AND ' . implode(' AND ', $searchClauses);
        }

        $colList = implode(', ', array_map(fn($c) => "`$c`", $columns));
        $visibilitySql = $importType === 1 ? ' AND ' . buildReceiptRowsHiddenSqlCondition('ai') : '';
        $baseSql = "SELECT $colList FROM accounting_imports ai WHERE import_type = :importType AND (cab_id IS NULL OR cab_id = '')$visibilitySql";
        if ($isImportOnlyRequest) {
            $stmt = $pdo->prepare($baseSql . ' ORDER BY id');
            $stmt->bindValue(':importType', $importType, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row = prepareImportRow($row);
            }
            unset($row);
            $rows = array_values(array_filter($rows, static fn(array $row): bool => isImportReadyRow($row)));
            $totalCount = count($rows);
            if (!empty($searchTokens)) {
                $rows = array_values(array_filter($rows, $matchRowSearch));
            }
            $filteredCount = count($rows);
            $rows = array_slice($rows, $start, $length);
        } else {
            $countSql = 'SELECT COUNT(*) FROM accounting_imports ai WHERE import_type = :importType AND (cab_id IS NULL OR cab_id = \'\')' . $visibilitySql;
            $countStmt = $pdo->prepare($countSql);
            $countStmt->bindValue(':importType', $importType, PDO::PARAM_INT);
            $countStmt->execute();
            $totalCount = (int)$countStmt->fetchColumn();

            if ($searchSql !== '') {
                $filteredCountSql = 'SELECT COUNT(*) FROM accounting_imports ai WHERE import_type = :importType AND (cab_id IS NULL OR cab_id = \'\')'
                    . $visibilitySql
                    . $searchSql;
                $filteredCountStmt = $pdo->prepare($filteredCountSql);
                $filteredCountStmt->bindValue(':importType', $importType, PDO::PARAM_INT);
                foreach ($searchBindings as $paramName => $paramValue) {
                    $filteredCountStmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
                }
                $filteredCountStmt->execute();
                $filteredCount = (int) $filteredCountStmt->fetchColumn();
            } else {
                $filteredCount = $totalCount;
            }

            $sql = $baseSql . $searchSql . ' ORDER BY id LIMIT :start, :length';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':importType', $importType, PDO::PARAM_INT);
            foreach ($searchBindings as $paramName => $paramValue) {
                $stmt->bindValue($paramName, $paramValue, PDO::PARAM_STR);
            }
            $stmt->bindValue(':start', $start, PDO::PARAM_INT);
            $stmt->bindValue(':length', $length, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row = prepareImportRow($row);
            }
            unset($row);
        }

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
            $costCenterBreakdownsAttr = htmlspecialchars(json_encode($row['cost_center_breakdowns'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            $qrFields = [];
            foreach ($row as $rowKey => $rowValue) {
                if (preg_match('/^field_[A-Z0-9]+$/', (string) $rowKey)) {
                    $qrFields[$rowKey] = (string) $rowValue;
                }
            }
            $qrFieldsAttr = htmlspecialchars(json_encode($qrFields, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

            if ($importType === 1) {
                $disabledAttr = $canClassifyCtb ? '' : ' disabled title="Sem permissao"';
                $classifyLabel = classificationButtonLabel($row);
                $actionsParts[] = '<button type="button" class="btn btn-xs ' . $row['btn_class'] . ' classify-row" '
                    . 'data-id="' . (int)$row['id'] . '" '
                    . 'data-rates="' . $ratesAttr . '" '
                    . 'data-requirements="' . $requirementsAttr . '" '
                    . 'data-cost-centers="' . $costCentersAttr . '" '
                    . 'data-cost-center-breakdowns="' . $costCenterBreakdownsAttr . '" '
                    . 'data-total-account="' . htmlspecialchars($row['total_account'] ?? '', ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-manual-review="' . htmlspecialchars((string) ($row['manual_review_required'] ?? '0'), ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-show-document-fields="' . htmlspecialchars((string) ($row['show_document_fields'] ?? '0'), ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-auto-import="' . (isAutoImportReadyRow($row) ? '1' : '0') . '" '
                    . 'data-emitter="' . $emitterRawEscaped . '" '
                    . 'data-emitter-display="' . $emitterDisplayEscaped . '" '
                    . 'data-emitter-nif="' . htmlspecialchars($emitterNifValue) . '" '
                    . 'data-doc-number="' . htmlspecialchars($row['field_G'] ?? '') . '" '
                    . 'data-docdate="' . htmlspecialchars($row['field_F'] ?? '') . '" '
                    . 'data-qr-fields="' . $qrFieldsAttr . '" '
                    . 'data-file-url="' . htmlspecialchars($row['filename'] ?? '', ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-has-receipt-companion="' . htmlspecialchars((string) ($row['has_receipt_companion'] ?? '0'), ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-acquirer="' . htmlspecialchars($row['field_B'] ?? '') . '" '
                    . 'data-acquirer-db="' . htmlspecialchars((string) ($row['acquirer_erp_database'] ?? ''), ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-doctype="' . htmlspecialchars($row['field_D'] ?? '') . '"' . $disabledAttr . '>' . $classifyLabel . '</button>';
            }
            if ($importType === 2) {
                $actionsParts[] = '<button type="button" class="btn btn-xs ' . $row['line_btn_class'] . ' analyze-lines" '
                    . 'data-id="' . (int)$row['id'] . '" '
                    . 'data-emitter="' . $emitterRawEscaped . '" '
                    . 'data-emitter-display="' . $emitterDisplayEscaped . '" '
                    . 'data-emitter-nif="' . htmlspecialchars($emitterNifValue) . '" '
                    . 'data-acquirer="' . htmlspecialchars($row['field_B'] ?? '') . '" '
                    . 'data-acquirer-db="' . htmlspecialchars((string) ($row['acquirer_erp_database'] ?? ''), ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-doctype="' . htmlspecialchars($row['field_D'] ?? '') . '" '
                    . 'data-docdate="' . htmlspecialchars($row['field_F'] ?? '') . '" '
                    . 'data-doc-number="' . htmlspecialchars($row['field_G'] ?? '') . '">Analisar</button>';
            }
            $actionsParts[] = '<button type="button" class="btn btn-xs btn-danger remove-row" data-id="' . (int)$row['id'] . '"><i class="fa fa-trash"></i></button>';
            $actions = implode(' ', $actionsParts);
            $pdfLink = '<a href="' . htmlspecialchars($row['filename'] ?? '') . '" target="_blank" class="btn btn-xs btn-secondary"><i class="fa fa-file-pdf-o"></i></a>';
            $data[] = [
                htmlspecialchars($emitterNifValue, ENT_QUOTES, 'UTF-8'),
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
$initialVisibilitySql = $importType === 1 ? ' AND ' . buildReceiptRowsHiddenSqlCondition('ai') : '';
$stmt = $pdo->prepare('SELECT * FROM accounting_imports ai WHERE import_type = :type AND (cab_id IS NULL OR cab_id = \'\')' . $initialVisibilitySql);
$stmt->execute([':type' => $importType]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$row) {
    $row = prepareImportRow($row);
}
unset($row);
if ($isImportOnlyView) {
    $rows = array_values(array_filter($rows, static fn(array $row): bool => isImportReadyRow($row)));
}

$initialReadyCount = 0;
foreach ($rows as $row) {
    if ($importType === 2) {
        if (trim((string) ($row['line_btn_class'] ?? '')) === 'btn-success') {
            $initialReadyCount++;
        }
        continue;
    }

    if (isImportReadyRow($row)) {
        $initialReadyCount++;
    }
}

$csrfToken = generateCsrfToken();
$showImportButton = ($importType === 1) || ($importType === 2);
$showImportCtbParamInfo = getSetting('debug_mode', '0') === '1';
if ($importType === 2) {
    $importButtonLabel = 'Importar Compras';
    $importButtonIcon = 'fa-shopping-cart';
} elseif ($isImportOnlyView) {
    $importButtonLabel = 'Importar Ctb';
    $importButtonIcon = 'fa-cloud-upload';
} else {
    $importButtonLabel = 'Classificado';
    $importButtonIcon = 'fa-check-circle';
}

require_once __DIR__ . '/../header.php';
?>
<input type="hidden" id="import_type" value="<?= htmlspecialchars($importType); ?>">
<input type="hidden" id="view_mode" value="<?= htmlspecialchars($viewMode); ?>">
<?php if (!$showImportCtbParamInfo): ?>
<style>
    #importCtbParamInfo {
        display: none !important;
    }
</style>
<?php endif; ?>
<div class="row mb-3">
    <div class="col-12">
        <?php if ($showImportButton): ?>


        <div id="importCtbButtonWrapper" class="mb-3 d-none" aria-hidden="true">
            <button
                type="button"
                class="btn btn-sm btn-primary"
                id="importCtbButton"
                data-base-label="<?= htmlspecialchars($importButtonLabel, ENT_QUOTES, 'UTF-8'); ?>"
                <?= $initialReadyCount > 0 ? '' : 'disabled'; ?>
            >
                <i class="fa <?= htmlspecialchars($importButtonIcon); ?>"></i> <span class="import-ctb-button-label"><?= htmlspecialchars($importButtonLabel); ?><?= $initialReadyCount > 0 ? ' (' . (int) $initialReadyCount . ')' : ''; ?></span>
            </button>
        </div>
        <?php endif; ?>
        <table id="classify-table" class="table table-striped">
            <thead>
                <tr>
                    <th class="text-start">NIF Emitente</th>
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
                    <td class="text-start"><?= htmlspecialchars($emitterNifValue); ?></td>
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
                        $costCenterBreakdownsAttr = htmlspecialchars(json_encode($row['cost_center_breakdowns'] ?? [], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $qrFields = [];
                        foreach ($row as $rowKey => $rowValue) {
                            if (preg_match('/^field_[A-Z0-9]+$/', (string) $rowKey)) {
                                $qrFields[$rowKey] = (string) $rowValue;
                            }
                        }
                        $qrFieldsAttr = htmlspecialchars(json_encode($qrFields, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                        $btnClass = htmlspecialchars($row['btn_class'] ?? 'btn-secondary');
                    ?>
                    <td class="text-center">

                    <?php if ($importType === 1): ?>
                            <?php $classifyLabel = classificationButtonLabel($row); ?>
                            <button
                                type="button"
                                class="btn btn-xs <?= $btnClass; ?> classify-row"
                                data-id="<?= (int)$row['id']; ?>"
                                data-rates="<?= $ratesAttr; ?>"
                                data-requirements="<?= $requirementsAttr; ?>"
                                data-cost-centers="<?= $costCentersAttr; ?>"
                                data-cost-center-breakdowns="<?= $costCenterBreakdownsAttr; ?>"
                                data-manual-review="<?= htmlspecialchars((string) ($row['manual_review_required'] ?? '0')); ?>"
                                data-show-document-fields="<?= htmlspecialchars((string) ($row['show_document_fields'] ?? '0')); ?>"
                                data-auto-import="<?= isAutoImportReadyRow($row) ? '1' : '0'; ?>"
                                data-emitter="<?= htmlspecialchars($emitterRawValue); ?>"
                                data-emitter-display="<?= htmlspecialchars($emitterDisplay); ?>"
                                data-emitter-nif="<?= htmlspecialchars($emitterNifValue); ?>"
                                data-doc-number="<?= htmlspecialchars($row['field_G'] ?? ''); ?>"
                                data-docdate="<?= htmlspecialchars($row['field_F'] ?? ''); ?>"
                                data-qr-fields="<?= $qrFieldsAttr; ?>"
                                data-file-url="<?= htmlspecialchars($row['filename'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                data-has-receipt-companion="<?= htmlspecialchars((string) ($row['has_receipt_companion'] ?? '0'), ENT_QUOTES, 'UTF-8'); ?>"
                                data-acquirer="<?= htmlspecialchars($row['field_B'] ?? ''); ?>"
                                data-acquirer-db="<?= htmlspecialchars((string) ($row['acquirer_erp_database'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-doctype="<?= htmlspecialchars($row['field_D'] ?? ''); ?>" <?= $canClassifyCtb ? '' : 'disabled title="Sem permissao"'; ?>><?= htmlspecialchars($classifyLabel); ?></button>
                    <?php endif; ?>

                        <?php if ($importType === 2): ?>
                        <button type="button" class="btn btn-xs <?= htmlspecialchars($row['line_btn_class'] ?? 'btn-info'); ?> analyze-lines"
                                data-id="<?= (int)$row['id']; ?>"
                                data-emitter="<?= htmlspecialchars($row['field_A'] ?? ''); ?>"
                                data-emitter-display="<?= htmlspecialchars($row['emitter_display_name'] ?? ($row['field_A'] ?? '')); ?>"
                                data-emitter-nif="<?= htmlspecialchars($row['emitter_nif_normalized'] ?? ''); ?>"
                                data-acquirer="<?= htmlspecialchars($row['field_B'] ?? ''); ?>"
                                data-acquirer-db="<?= htmlspecialchars((string) ($row['acquirer_erp_database'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                data-doctype="<?= htmlspecialchars($row['field_D'] ?? ''); ?>"
                                data-docdate="<?= htmlspecialchars($row['field_F'] ?? ''); ?>"
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
<?php
$classifyModalImportType = (int) $importType;
$classifyModalShowAiButtons = getSetting('ai_enabled', '0') === '1' && userHasDepartmentPermission('ai_suggest_vat');
$classifyModalTitle = 'Classificar';
require __DIR__ . '/partials/classify-modal.php';
?>
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
<div class="modal fade" id="qrDocTypeMappingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="qrDocTypeMappingForm">
                <div class="modal-header">
                    <h5 class="modal-title">Associar tipo documental QR</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p id="qrDocTypeMappingMessage" class="mb-3">Associe o tipo de documento E-fatura lido no QR ao tipo documental ERP.</p>
                    <div id="qrDocTypeMappingContainer"></div>
                    <div id="qrDocTypeMappingError" class="alert alert-danger d-none mt-3" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="confirmQrDocTypeMappingBtn">Guardar associação</button>
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
    window.erpBaseCompany = <?= json_encode(
        trim((string) getSetting('accounting_base_company', '')),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>;
    window.erpDefaultDatabase = <?= json_encode(
        trim((string) getSetting('erp_database', '')),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>;
    window.classificacaoImportDebugMode = <?= json_encode(
        $showImportCtbParamInfo,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>;
    window.classificationAcquirerOptions = <?= json_encode(
        $classificationAcquirerOptions,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>;
    window.accountingFuelRubricCodes = <?= json_encode(
        getAccountingFuelRubricCodes(),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ); ?>;
</script>
<script src="assets/js/pnotify_theme_adapter.js"></script>
<script src="assets/js/classificacao_importacao.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>
