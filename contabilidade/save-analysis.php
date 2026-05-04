<?php
require_once __DIR__ . '/../functions.php';
// Load Composer's autoloader if available. This prevents fatal errors in
// environments where the dependencies have not been installed yet.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/functions.php';

startSession();


/**
 * Normalize a supplier party identifier (emitter/acquirer).
 */
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

/**
 * Uppercase helper compatible with environments without mbstring.
 */
function supplierToUpper(string $value): string {
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($value, 'UTF-8');
    }

    return strtoupper($value);
}

/**
 * Normalize the document/article code extracted from invoice lines.
 */
function normalizeSupplierDocumentCode($value): string {
    $string = trim((string) ($value ?? ''));
    if ($string === '') {
        return '';
    }

    $string = supplierToUpper($string);

    if (function_exists('mb_substr')) {
        return mb_substr($string, 0, 255, 'UTF-8');
    }

    return substr($string, 0, 255);
}

/**
 * Normalize ERP codes stored by the user.
 */
function normalizeErpCodeValue($value): string {
    $string = trim((string) ($value ?? ''));
    if ($string === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($string, 0, 255, 'UTF-8');
    }

    return substr($string, 0, 255);
}

function getEditableAccountingImportFieldColumns(): array {
    return [
        'field_A',
        'field_B',
        'field_C',
        'field_D',
        'field_E',
        'field_F',
        'field_G',
        'field_H',
        'field_I1',
        'field_I2',
        'field_I3',
        'field_I4',
        'field_I5',
        'field_I6',
        'field_I7',
        'field_I8',
        'field_M',
        'field_N',
        'field_O',
        'field_Q',
        'field_R',
    ];
}

function normalizeEditableAccountingImportFieldKey($key): string {
    $string = trim((string) ($key ?? ''));
    if ($string === '') {
        return '';
    }

    $upper = strtoupper($string);
    if (strpos($upper, 'FIELD_') === 0) {
        $suffix = substr($upper, 6);
        return $suffix !== '' ? 'field_' . $suffix : '';
    }

    return 'field_' . $upper;
}

function normalizeEditableAccountingImportFieldValue(string $field, $value): string {
    $string = trim((string) ($value ?? ''));
    if ($string === '') {
        return '';
    }

    $maxLength = 255;
    if ($field === 'field_D') {
        $maxLength = 50;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($string, 0, $maxLength, 'UTF-8');
    }

    return substr($string, 0, $maxLength);
}

function extractEditableAccountingImportFields(array $row): array {
    $result = [];
    foreach (getEditableAccountingImportFieldColumns() as $field) {
        $result[$field] = isset($row[$field]) ? (string) $row[$field] : '';
    }
    return $result;
}

function normalizeSubmittedEditableAccountingImportFields($value): array {
    if (!is_array($value)) {
        return [];
    }

    $allowedFields = array_flip(getEditableAccountingImportFieldColumns());
    $result = [];

    foreach ($value as $fieldKey => $fieldValue) {
        $normalizedKey = normalizeEditableAccountingImportFieldKey($fieldKey);
        if ($normalizedKey === '' || !isset($allowedFields[$normalizedKey])) {
            continue;
        }
        $result[$normalizedKey] = normalizeEditableAccountingImportFieldValue($normalizedKey, $fieldValue);
    }

    return $result;
}

function buildDerivedEditableAccountingImportAmountFields(array $rates): array {
    $result = [
        'field_I3' => '',
        'field_I4' => '',
        'field_I5' => '',
        'field_I6' => '',
        'field_I7' => '',
        'field_I8' => '',
        'field_N' => '',
        'field_O' => '',
    ];

    $rateFieldMap = [
        '6' => ['base' => 'field_I3', 'iva' => 'field_I4'],
        '13' => ['base' => 'field_I5', 'iva' => 'field_I6'],
        '23' => ['base' => 'field_I7', 'iva' => 'field_I8'],
    ];

    $normalizedRates = sanitizeAccountInput($rates);
    $aggregated = [];
    $seenFields = [];
    $totalIva = 0.0;
    $totalDocument = 0.0;
    $hasAnyAmount = false;
    
    // When dealing with bank loan conversion or multiple accounts per rate,
    // aggregate ONLY by rate without considering general_account in aggregation.
    // The UI displays amounts aggregated by rate only.

    foreach ($normalizedRates as $rate => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $baseString = extractDecimalAmount($entry['base'] ?? ($entry['base_value'] ?? ''));
        $ivaString = extractDecimalAmount($entry['iva'] ?? ($entry['iva_value'] ?? ''));
        $hasBase = ($baseString !== null && $baseString !== '');
        $hasIva = ($ivaString !== null && $ivaString !== '');
        if (!$hasBase && !$hasIva) {
            continue;
        }

        $baseValue = $hasBase ? (float) $baseString : 0.0;
        $ivaValue = $hasIva ? (float) $ivaString : 0.0;
        $hasAnyAmount = true;
        $totalIva += $ivaValue;
        $totalDocument += $baseValue + $ivaValue;

        $normalizedRate = normalizeAccountingRateKey((string) $rate);
        if (!isset($rateFieldMap[$normalizedRate])) {
            continue;
        }

        $baseField = $rateFieldMap[$normalizedRate]['base'];
        $ivaField = $rateFieldMap[$normalizedRate]['iva'];
        $aggregated[$baseField] = ($aggregated[$baseField] ?? 0.0) + $baseValue;
        $aggregated[$ivaField] = ($aggregated[$ivaField] ?? 0.0) + $ivaValue;
        $seenFields[$baseField] = true;
        $seenFields[$ivaField] = true;
    }

    foreach ($aggregated as $fieldName => $value) {
        if (!isset($result[$fieldName])) {
            continue;
        }
        $result[$fieldName] = number_format((float) $value, 2, '.', '');
    }

    foreach ($seenFields as $fieldName => $_) {
        if (!isset($result[$fieldName]) || $result[$fieldName] !== '') {
            continue;
        }
        $result[$fieldName] = '0.00';
    }

    if ($hasAnyAmount) {
        $result['field_N'] = number_format($totalIva, 2, '.', '');
        $result['field_O'] = number_format($totalDocument, 2, '.', '');
    }

    return $result;
}

function applyDefaultEditableAccountingImportFields(array &$row): void {
    if (trim((string) ($row['field_C'] ?? '')) === '') {
        $row['field_C'] = 'PT';
    }
    if (trim((string) ($row['field_I1'] ?? '')) === '') {
        $row['field_I1'] = 'PT';
    }
    if (trim((string) ($row['field_E'] ?? '')) === '') {
        $row['field_E'] = 'N';
    }
    if (trim((string) ($row['field_F'] ?? '')) === '') {
        $row['field_F'] = date('Y-m-d');
    }
}

function requireCtbClassificationPermission(PDO $pdo, ?int $importId = null): void {
    if (userHasDepartmentPermission('ctb_classificar_docs')) {
        return;
    }

    if ($importId !== null && $importId > 0) {
        $stmt = $pdo->prepare('SELECT import_type FROM accounting_imports WHERE id = ? LIMIT 1');
        $stmt->execute([$importId]);
        $importType = (int) $stmt->fetchColumn();
        if ($importType !== 1) {
            return;
        }
    }

    http_response_code(403);
    echo json_encode(['error' => 'Sem permissao para classificar documentos.', 'csrf_token' => generateCsrfToken(true)]);
    exit;
}

function suggestHistoricalCostCenters(PDO $pdo, string $emitter, string $acquirer, string $docType, int $excludeId = 0): array {
    $emitter = trim($emitter);
    $acquirer = trim($acquirer);
    $docType = trim($docType);
    if ($emitter === '' || $acquirer === '' || $docType === '') {
        return buildEmptyCostCenterMap();
    }

    $sql = 'SELECT cost_center FROM accounting_imports '
        . 'WHERE field_A = ? AND field_B = ? AND field_D = ? AND cost_center IS NOT NULL AND cost_center <> "" ';
    $params = [$emitter, $acquirer, $docType];
    if ($excludeId > 0) {
        $sql .= 'AND id <> ? ';
        $params[] = $excludeId;
    }
    $sql .= 'ORDER BY id DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return buildEmptyCostCenterMap();
    }

    $tallies = [];
    foreach ($rows as $row) {
        $costCenters = normalizeCostCenters($row['cost_center'] ?? '');
        foreach ($costCenters as $rate => $value) {
            $rateKey = (string) $rate;
            $code = trim((string) $value);
            if ($code === '') {
                continue;
            }
            if (!isset($tallies[$rateKey])) {
                $tallies[$rateKey] = [];
            }
            $tallies[$rateKey][$code] = ($tallies[$rateKey][$code] ?? 0) + 1;
        }
    }

    $result = buildEmptyCostCenterMap(array_keys($tallies));
    foreach ($tallies as $rate => $map) {
        if (!is_array($map) || empty($map)) {
            continue;
        }
        arsort($map);
        $result[$rate] = (string) array_key_first($map);
    }

    return $result;
}

function suggestHistoricalTotalAccount(PDO $pdo, array $context, int $excludeId = 0): string {
    $emitter = trim((string) ($context['emitter'] ?? ''));
    $acquirer = trim((string) ($context['acquirer'] ?? ''));
    $docType = trim((string) ($context['doc_type'] ?? ''));
    $emitterNif = extractVatNumber((string) ($context['emitter_nif'] ?? $emitter));
    $acquirerNif = extractVatNumber((string) ($context['acquirer_nif'] ?? $acquirer));
    $receiptCompanionFlag = trim((string) ($context['has_receipt_companion'] ?? '0')) === '1' ? '1' : '0';

    if ($docType === '') {
        return '';
    }

    $sql = 'SELECT id, field_A, field_B, field_C, field_D, account
            FROM accounting_imports
            WHERE field_D = ? AND account IS NOT NULL AND account <> ""';
    $params = [$docType];

    if ($acquirerNif !== '') {
        $sql .= ' AND (field_B = ? OR field_B LIKE ? OR field_C = ? OR field_C LIKE ?)';
        $params[] = $acquirerNif;
        $params[] = '%' . $acquirerNif . '%';
        $params[] = $acquirerNif;
        $params[] = '%' . $acquirerNif . '%';
    } elseif ($acquirer !== '') {
        $sql .= ' AND (field_B = ? OR field_C = ?)';
        $params[] = $acquirer;
        $params[] = $acquirer;
    }

    if ($excludeId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeId;
    }

    $sql .= ' ORDER BY id DESC LIMIT 250';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return '';
    }

    $bestAccount = '';
    $bestScore = PHP_INT_MIN;
    $bestId = 0;

    foreach ($rows as $row) {
        $metadata = normalizeAccountingMetadata($row['account'] ?? '');
        $totalAccount = trim((string) ($metadata['total_account'] ?? ''));
        if ($totalAccount === '') {
            continue;
        }

        $score = 0;
        $rowEmitter = trim((string) ($row['field_A'] ?? ''));
        $rowAcquirer = trim((string) ($row['field_B'] ?? ''));
        $rowEmitterNif = extractVatNumber((string) ($row['field_C'] ?? ''));
        if ($rowEmitterNif === '') {
            $rowEmitterNif = extractVatNumber($rowEmitter);
        }
        $rowAcquirerNif = extractVatNumber($rowAcquirer);
        if ($rowAcquirerNif === '') {
            $rowAcquirerNif = extractVatNumber((string) ($row['field_C'] ?? ''));
        }

        if ($emitterNif !== '' && $rowEmitterNif !== '' && $emitterNif === $rowEmitterNif) {
            $score += 8;
        } elseif ($emitter !== '' && $rowEmitter === $emitter) {
            $score += 2;
        }

        if ($acquirerNif !== '' && $rowAcquirerNif !== '' && $acquirerNif === $rowAcquirerNif) {
            $score += 6;
        } elseif ($acquirer !== '' && $rowAcquirer === $acquirer) {
            $score += 2;
        }

        $rowReceiptCompanionFlag = (($metadata['has_receipt_companion'] ?? '0') === '1') ? '1' : '0';
        if ($rowReceiptCompanionFlag === $receiptCompanionFlag) {
            $score += 3;
        } elseif ($receiptCompanionFlag === '1') {
            $score -= 2;
        }

        $rowId = (int) ($row['id'] ?? 0);
        if ($score > $bestScore || ($score === $bestScore && $rowId > $bestId)) {
            $bestAccount = $totalAccount;
            $bestScore = $score;
            $bestId = $rowId;
        }
    }

    return $bestAccount;
}

function buildClassificationPartyKey($value, $fallbackVat = ''): string {
    $fallbackVat = trim((string) ($fallbackVat ?? ''));
    $vat = extractVatNumber($fallbackVat !== '' ? $fallbackVat : (string) $value);
    if ($vat !== '') {
        return $vat;
    }
    return normalizeSupplierPartyValue($value);
}

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

function classificationHasAnyMappedAccount(array $rates): bool {
    $normalizedRates = sanitizeAccountInput($rates);

    foreach ($normalizedRates as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $generalAccount = trim((string) ($entry['general_account'] ?? ''));
        $ivaAccount = trim((string) ($entry['iva_account'] ?? ''));
        if ($generalAccount !== '' || $ivaAccount !== '') {
            return true;
        }
    }

    return false;
}

function shouldPersistSharedClassification(array $requirements, array $payload, array $metadata = [], array $costCenters = []): bool {
    if (!classificationHasAnyMappedAccount($payload)) {
        return false;
    }

    return determineClassificationButtonClass($requirements, $payload, $metadata, $costCenters) === 'btn-success';
}

function markAccountingEntityAsBankEntity(PDO $pdo, string $entityFieldValue): void {
    if (getAccountingEmitterTypeColumn() === '') {
        return;
    }

    $nif = extractVatNumber($entityFieldValue);
    if ($nif === '') {
        return;
    }

    $existing = findAccountingEntity($pdo, $nif);
    $name = trim((string) ($existing['name'] ?? ''));
    if ($name === '' || isPlaceholderAccountingEntityName($name, $nif)) {
        $efaturaName = findAccountingEntityNameFromEfatura($pdo, $nif);
        $name = $efaturaName !== '' ? $efaturaName : deriveEntityNameFromField($entityFieldValue, $nif);
    }

    saveAccountingEntity($pdo, [
        'nif' => $nif,
        'name' => $name,
        'erp_database' => trim((string) ($existing['erp_database'] ?? '')),
        'erp_client_code' => trim((string) ($existing['erp_client_code'] ?? '')),
        'entity_type' => trim((string) ($existing['entity_type'] ?? '')) !== ''
            ? trim((string) ($existing['entity_type'] ?? ''))
            : 'emitter',
        'qr_doc_type_mappings' => array_key_exists('qr_doc_type_mappings', (array) $existing)
            ? (string) ($existing['qr_doc_type_mappings'] ?? '')
            : '',
        'emitter_type' => '1',
    ]);
}

function normalizeEmitterTypeValue($value): string {
    $type = strtolower(trim((string) ($value ?? '')));
    if (in_array($type, ['normal', 'bank', 'insurance'], true)) {
        return $type;
    }
    return '';
}

function isBankEmitterCandidateFromQrFields(array $document): bool {
    $exemptBase = parseAccountingBankLoanAmount($document['field_I2'] ?? null);
    $stampTax = parseAccountingBankLoanAmount($document['field_M'] ?? null);
    $total = parseAccountingBankLoanAmount($document['field_O'] ?? null);
    if ($stampTax === null || $total === null) {
        return false;
    }
    if ($exemptBase === null && $total > 0 && $stampTax > 0) {
        $exemptBase = $total - $stampTax;
    }
    if ($exemptBase <= 0 || $stampTax <= 0 || $total <= 0) {
        return false;
    }

    $vatBase6 = parseAccountingBankLoanAmount($document['field_I3'] ?? null) ?? 0.0;
    $vatAmount6 = parseAccountingBankLoanAmount($document['field_I4'] ?? null) ?? 0.0;
    $vatBase13 = parseAccountingBankLoanAmount($document['field_I5'] ?? null) ?? 0.0;
    $vatAmount13 = parseAccountingBankLoanAmount($document['field_I6'] ?? null) ?? 0.0;
    $vatBase23 = parseAccountingBankLoanAmount($document['field_I7'] ?? null) ?? 0.0;
    $vatAmount23 = parseAccountingBankLoanAmount($document['field_I8'] ?? null) ?? 0.0;
    $hasVatAmounts = abs($vatBase6) >= 0.005
        || abs($vatAmount6) >= 0.005
        || abs($vatBase13) >= 0.005
        || abs($vatAmount13) >= 0.005
        || abs($vatBase23) >= 0.005
        || abs($vatAmount23) >= 0.005;
    if ($hasVatAmounts) {
        return false;
    }

    return abs(($exemptBase + $stampTax) - $total) < 0.03;
}

function normalizeEmitterTypeDetectionText(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim($value);
}

function flattenAccountingValueForDetection($value, array &$parts): void {
    if ($value === null) {
        return;
    }
    if (is_array($value)) {
        foreach ($value as $nestedKey => $nestedValue) {
            if (is_string($nestedKey)) {
                $parts[] = $nestedKey;
            }
            flattenAccountingValueForDetection($nestedValue, $parts);
        }
        return;
    }
    if (is_scalar($value)) {
        $parts[] = (string) $value;
    }
}

function isInsuranceEmitterCandidateFromDocumentText(array $document): bool {
    $parts = [];
    foreach (['field_A', 'field_D', 'field_G', 'field_H', 'field_Q', 'field_R'] as $field) {
        if (isset($document[$field])) {
            $parts[] = (string) $document[$field];
        }
    }

    if (!empty($document['line_items'])) {
        $lineItems = is_array($document['line_items'])
            ? $document['line_items']
            : json_decode((string) $document['line_items'], true);
        if (is_array($lineItems)) {
            flattenAccountingValueForDetection($lineItems, $parts);
        }
    }

    $text = normalizeEmitterTypeDetectionText(implode(' ', $parts));
    if ($text === '') {
        return false;
    }

    foreach ([
        'apolice',
        'seguro',
        'seguros',
        'segurado',
        'tomador',
        'corretor',
        'mediador',
        'premio comercial',
        'premio total',
        'premio',
        'sinistro',
        'ramo',
        'companhia de seguros',
    ] as $needle) {
        if (strpos($text, $needle) !== false) {
            return true;
        }
    }

    return false;
}

function resolveEmitterTypeValue(PDO $pdo, string $entityFieldValue, array $document = []): string {
    $emitterTypeColumn = getAccountingEmitterTypeColumn();
    if ($emitterTypeColumn === '') {
        if (isInsuranceEmitterCandidateFromDocumentText($document)) {
            return 'insurance';
        }
        return isBankEmitterCandidateFromQrFields($document) ? 'bank' : '';
    }

    $nif = extractVatNumber($entityFieldValue);
    if ($nif === '') {
        if (isInsuranceEmitterCandidateFromDocumentText($document)) {
            return 'insurance';
        }
        return isBankEmitterCandidateFromQrFields($document) ? 'bank' : '';
    }

    $stmt = $pdo->prepare(
        "SELECT " . $emitterTypeColumn . " AS emitter_type
         FROM accounting_entities
         WHERE nif = ?
           AND (entity_type = 'emitter' OR entity_type = 'emitente' OR entity_type = '')
         ORDER BY FIELD(" . $emitterTypeColumn . ", 1, 2, 0) ASC, id ASC
         LIMIT 1"
    );
    $stmt->execute([$nif]);
    $emitterRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (is_array($emitterRow)) {
        $storedKind = trim((string) ($emitterRow['emitter_type'] ?? '0'));
        if ($storedKind === '1') {
            return 'bank';
        }
        if ($storedKind === '2') {
            return 'insurance';
        }
    }

    $existing = findAccountingEntity($pdo, $nif);
    if (is_array($existing)) {
        $storedKind = trim((string) ($existing['emitter_type'] ?? '0'));
        if ($storedKind === '1') {
            return 'bank';
        }
        if ($storedKind === '2') {
            return 'insurance';
        }
    }

    if (isInsuranceEmitterCandidateFromDocumentText($document)) {
        return 'insurance';
    }

    return isBankEmitterCandidateFromQrFields($document) ? 'bank' : '';
}

function persistEmitterTypeValue(PDO $pdo, string $entityFieldValue, string $type): void {
    if (getAccountingEmitterTypeColumn() === '') {
        return;
    }

    $normalizedType = normalizeEmitterTypeValue($type);
    if ($normalizedType === '') {
        return;
    }

    $nif = extractVatNumber($entityFieldValue);
    if ($nif === '') {
        return;
    }

    $existing = findAccountingEntity($pdo, $nif);
    $name = trim((string) ($existing['name'] ?? ''));
    if ($name === '' || isPlaceholderAccountingEntityName($name, $nif)) {
        $efaturaName = findAccountingEntityNameFromEfatura($pdo, $nif);
        $name = $efaturaName !== '' ? $efaturaName : deriveEntityNameFromField($entityFieldValue, $nif);
    }

    saveAccountingEntity($pdo, [
        'nif' => $nif,
        'name' => $name,
        'erp_database' => trim((string) ($existing['erp_database'] ?? '')),
        'erp_client_code' => trim((string) ($existing['erp_client_code'] ?? '')),
        'entity_type' => trim((string) ($existing['entity_type'] ?? '')) !== ''
            ? trim((string) ($existing['entity_type'] ?? ''))
            : 'emitter',
        'qr_doc_type_mappings' => array_key_exists('qr_doc_type_mappings', (array) $existing)
            ? (string) ($existing['qr_doc_type_mappings'] ?? '')
            : '',
        'emitter_type' => $normalizedType === 'bank' ? '1' : ($normalizedType === 'insurance' ? '2' : '0'),
    ]);
}

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

function getSharedClassificationModelPath(): string {
    return dirname(__DIR__) . '/data/shared/accounting_classification_models.json';
}

function sanitizeClassificationModelName($value): string {
    $name = trim((string) ($value ?? ''));
    if ($name === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($name, 0, 120, 'UTF-8');
    }
    return substr($name, 0, 120);
}

function normalizeClassificationModelTenantKey($value): string {
    $tenantKey = trim((string) ($value ?? ''));
    if ($tenantKey === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($tenantKey, 0, 120, 'UTF-8');
    }
    return substr($tenantKey, 0, 120);
}

function buildClassificationModelScopeKey($tenantKey, $emitter, $acquirer, $docType): string {
    $parts = [
        normalizeClassificationModelTenantKey($tenantKey),
        normalizeSupplierPartyValue($acquirer),
        normalizeDocTypeValue($docType),
    ];
    return implode('|', $parts);
}

function buildLegacyClassificationModelScopeKey($tenantKey, $emitter, $acquirer, $docType): string {
    $parts = [
        normalizeClassificationModelTenantKey($tenantKey),
        normalizeSupplierPartyValue($emitter),
        normalizeSupplierPartyValue($acquirer),
        normalizeDocTypeValue($docType),
    ];
    return implode('|', $parts);
}

function resolveClassificationModelTenantKey(PDO $pdo, array $context = []): string {
    $explicitTenantKey = normalizeClassificationModelTenantKey(
        $context['tenant_key']
        ?? $context['model_tenant_key']
        ?? $context['acquirer_database']
        ?? $context['database']
        ?? ''
    );
    if ($explicitTenantKey !== '') {
        return $explicitTenantKey;
    }

    $acquirerNif = extractVatNumber((string) ($context['acquirer'] ?? ''));
    if ($acquirerNif !== '') {
        $entity = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
        $entityTenantKey = normalizeClassificationModelTenantKey(is_array($entity) ? resolveAccountingEntityDatabase($entity) : '');
        if ($entityTenantKey !== '') {
            return $entityTenantKey;
        }
    }

    $emitterNif = extractVatNumber((string) ($context['emitter'] ?? ''));
    if ($emitterNif !== '') {
        $entity = findAccountingEntityByType($pdo, $emitterNif, 'emitter');
        $entityTenantKey = normalizeClassificationModelTenantKey(is_array($entity) ? resolveAccountingEntityDatabase($entity) : '');
        if ($entityTenantKey !== '') {
            return $entityTenantKey;
        }
    }

    return normalizeClassificationModelTenantKey((string) (getCompanySlug() ?? ''));
}

function loadAllSharedClassificationModels(): array {
    $path = getSharedClassificationModelPath();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $models = is_array($decoded['models'] ?? null) ? $decoded['models'] : $decoded;
    if (!is_array($models)) {
        return [];
    }

    $result = [];
    foreach ($models as $model) {
        if (!is_array($model)) {
            continue;
        }
        $companySlug = trim((string) ($model['company_slug'] ?? ''));
        $tenantKey = normalizeClassificationModelTenantKey($model['tenant_key'] ?? $companySlug);
        $name = sanitizeClassificationModelName($model['name'] ?? '');
        $emitter = normalizeSupplierPartyValue($model['emitter'] ?? '');
        $acquirer = normalizeSupplierPartyValue($model['acquirer'] ?? '');
        $docType = normalizeDocTypeValue($model['doc_type'] ?? '');
        if ($name === '') {
            continue;
        }

        $rates = stripAccountingAmounts(is_array($model['rates'] ?? null) ? $model['rates'] : []);
        $costCenters = sanitizeCostCenterValues($model['cost_centers'] ?? []);
        $costCenterBreakdowns = sanitizeCostCenterBreakdownValues($model['cost_center_breakdowns'] ?? []);
        $metadata = sanitizeAccountingMetadata([
            'total_account' => $model['total_account'] ?? '',
            'ignore_detected_rates' => '1',
            'classification_model_name' => $name,
        ]);

        $result[] = [
            'company_slug' => $companySlug,
            'tenant_key' => $tenantKey,
            'name' => $name,
            'emitter' => $emitter,
            'acquirer' => $acquirer,
            'doc_type' => $docType,
            'scope_key' => buildClassificationModelScopeKey($tenantKey, $emitter, $acquirer, $docType),
            'rates' => $rates,
            'cost_centers' => $costCenters,
            'cost_center_breakdowns' => $costCenterBreakdowns,
            'total_account' => $metadata['total_account'] ?? '',
            'ignore_detected_rates' => '1',
            'updated_at' => trim((string) ($model['updated_at'] ?? '')),
        ];
    }

    usort($result, static function (array $a, array $b): int {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    return $result;
}

function loadSharedClassificationModels(PDO $pdo, $emitter, $acquirer, $docType, string $tenantKey = ''): array {
    $resolvedTenantKey = $tenantKey !== ''
        ? normalizeClassificationModelTenantKey($tenantKey)
        : resolveClassificationModelTenantKey($pdo, [
            'emitter' => $emitter,
            'acquirer' => $acquirer,
        ]);
    $companySlug = normalizeClassificationModelTenantKey((string) (getCompanySlug() ?? ''));
    $candidateScopeKeys = array_values(array_unique(array_filter([
        buildClassificationModelScopeKey($resolvedTenantKey, $emitter, $acquirer, $docType),
        buildClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType),
        buildLegacyClassificationModelScopeKey($resolvedTenantKey, $emitter, $acquirer, $docType),
        buildLegacyClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType),
    ], static function ($value): bool {
        return $value !== '||';
    })));
    if (empty($candidateScopeKeys)) {
        return [];
    }

    $models = loadAllSharedClassificationModels();
    $preferredScopeKey = buildClassificationModelScopeKey($resolvedTenantKey, $emitter, $acquirer, $docType);
    $filtered = [];
    foreach ($models as $model) {
        $modelScopeKey = (string) ($model['scope_key'] ?? '');
        if (!in_array($modelScopeKey, $candidateScopeKeys, true)) {
            continue;
        }
        $nameKey = function_exists('mb_strtolower')
            ? mb_strtolower((string) ($model['name'] ?? ''), 'UTF-8')
            : strtolower((string) ($model['name'] ?? ''));
        $priority = $modelScopeKey === $preferredScopeKey ? 2 : 1;
        if (!isset($filtered[$nameKey]) || $priority > (int) ($filtered[$nameKey]['_priority'] ?? 0)) {
            $model['_priority'] = $priority;
            $filtered[$nameKey] = $model;
        }
    }

    $filtered = array_values($filtered);
    foreach ($filtered as $index => $model) {
        unset($filtered[$index]['_priority']);
    }

    usort($filtered, static function (array $a, array $b): int {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    return $filtered;
}

function saveSharedClassificationModels(array $models): void {
    $path = getSharedClassificationModelPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $payload = [
        'models' => array_values($models),
        'updated_at' => date('c'),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Não foi possível serializar os modelos.');
    }

    if (@file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível guardar os modelos partilhados.');
    }
}

function upsertSharedClassificationModel(PDO $pdo, array $model): array {
    $companySlug = (string) (getCompanySlug() ?? '');
    $tenantKey = resolveClassificationModelTenantKey($pdo, [
        'tenant_key' => $model['tenant_key'] ?? '',
        'acquirer_database' => $model['acquirer_database'] ?? '',
        'database' => $model['database'] ?? '',
        'emitter' => $model['emitter'] ?? '',
        'acquirer' => $model['acquirer'] ?? '',
    ]);
    $name = sanitizeClassificationModelName($model['name'] ?? '');
    $emitter = normalizeSupplierPartyValue($model['emitter'] ?? '');
    $acquirer = normalizeSupplierPartyValue($model['acquirer'] ?? '');
    $docType = normalizeDocTypeValue($model['doc_type'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Indique um nome para o modelo.');
    }
    if ($acquirer === '') {
        throw new RuntimeException('Modelo sem adquirente válido.');
    }

    $models = loadAllSharedClassificationModels();
    $normalized = [
        'company_slug' => $companySlug,
        'tenant_key' => $tenantKey,
        'name' => $name,
        'emitter' => $emitter,
        'acquirer' => $acquirer,
        'doc_type' => $docType,
        'scope_key' => buildClassificationModelScopeKey($tenantKey, $emitter, $acquirer, $docType),
        'rates' => stripAccountingAmounts(is_array($model['rates'] ?? null) ? $model['rates'] : []),
        'cost_centers' => sanitizeCostCenterValues($model['cost_centers'] ?? []),
        'cost_center_breakdowns' => sanitizeCostCenterBreakdownValues($model['cost_center_breakdowns'] ?? []),
        'total_account' => trim((string) ($model['total_account'] ?? '')),
        'ignore_detected_rates' => '1',
        'updated_at' => date('c'),
    ];

    $legacyScopeKey = buildClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType);
    $legacyEmitterScopeKey = buildLegacyClassificationModelScopeKey($tenantKey, $emitter, $acquirer, $docType);
    $legacyCompanyEmitterScopeKey = buildLegacyClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType);
    $updated = false;
    foreach ($models as $index => $existing) {
        if (
            strcasecmp((string) ($existing['name'] ?? ''), $name) === 0
            && normalizeSupplierPartyValue($existing['acquirer'] ?? '') === $acquirer
            && normalizeDocTypeValue($existing['doc_type'] ?? '') === $docType
            && (
                normalizeClassificationModelTenantKey($existing['tenant_key'] ?? '') === $tenantKey
                || (string) ($existing['scope_key'] ?? '') === $normalized['scope_key']
                || (string) ($existing['scope_key'] ?? '') === $legacyScopeKey
                || (string) ($existing['scope_key'] ?? '') === $legacyEmitterScopeKey
                || (string) ($existing['scope_key'] ?? '') === $legacyCompanyEmitterScopeKey
            )
        ) {
            $models[$index] = $normalized;
            $updated = true;
            break;
        }
    }
    if (!$updated) {
        $models[] = $normalized;
    }

    usort($models, static function (array $a, array $b): int {
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    saveSharedClassificationModels($models);

    return $normalized;
}

function deleteSharedClassificationModel(PDO $pdo, $emitter, $acquirer, $docType, $name, string $tenantKey = ''): bool {
    $companySlug = normalizeClassificationModelTenantKey((string) (getCompanySlug() ?? ''));
    $resolvedTenantKey = $tenantKey !== ''
        ? normalizeClassificationModelTenantKey($tenantKey)
        : resolveClassificationModelTenantKey($pdo, [
            'emitter' => $emitter,
            'acquirer' => $acquirer,
        ]);
    $scopeKey = buildClassificationModelScopeKey($resolvedTenantKey, $emitter, $acquirer, $docType);
    $legacyScopeKey = buildClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType);
    $legacyEmitterScopeKey = buildLegacyClassificationModelScopeKey($resolvedTenantKey, $emitter, $acquirer, $docType);
    $legacyCompanyEmitterScopeKey = buildLegacyClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType);
    $modelName = sanitizeClassificationModelName($name);
    if (($scopeKey === '||' && $legacyScopeKey === '||') || $modelName === '') {
        return false;
    }

    $models = loadAllSharedClassificationModels();
    $filtered = [];
    $deleted = false;

    foreach ($models as $model) {
        $sameScope = in_array((string) ($model['scope_key'] ?? ''), [$scopeKey, $legacyScopeKey, $legacyEmitterScopeKey, $legacyCompanyEmitterScopeKey], true);
        $sameName = strcasecmp((string) ($model['name'] ?? ''), $modelName) === 0;
        if ($sameScope && $sameName) {
            $deleted = true;
            continue;
        }
        $filtered[] = $model;
    }

    if ($deleted) {
        saveSharedClassificationModels($filtered);
    }

    return $deleted;
}

/**
 * Attempt to obtain the document/article code from a parsed invoice line.
 *
 * @param array $line Parsed line data.
 */
function extractDocumentCodeFromLine(array $line): string {
    $candidates = [];

    $candidates[] = $line['PRODUCT_CODE'] ?? null;
    $candidates[] = $line['ITEM_CODE'] ?? null;

    if (isset($line['ITEM_QUANTITY_UNIT_PRICE']) && is_array($line['ITEM_QUANTITY_UNIT_PRICE'])) {
        $iqp = $line['ITEM_QUANTITY_UNIT_PRICE'];
        $candidates[] = $iqp['PRODUCT_CODE'] ?? null;
        $candidates[] = $iqp['ITEM_CODE'] ?? null;
    }

    foreach ($candidates as $candidate) {
        $normalized = normalizeSupplierDocumentCode($candidate);
        if ($normalized !== '') {
            return $normalized;
        }
    }

    $itemName = $line['ITEM'] ?? null;
    $normalizedItem = normalizeSupplierDocumentCode($itemName);
    if ($normalizedItem !== '') {
        return $normalizedItem;
    }

    return '';
}

/**
 * Apply stored ERP mappings for supplier documents to parsed lines.
 *
 * @param PDO    $pdo      Active database connection.
 * @param string $emitter  Emitter identifier.
 * @param string $acquirer Acquirer identifier.
 * @param array  $items    Parsed line items.
 *
 * @return array Lines with pre-filled ERP codes when available.
 */
function applySupplierDocumentMappings(PDO $pdo, $emitter, $acquirer, array $items): array {
    if (empty($items)) {
        return $items;
    }

    $normalizedEmitter = normalizeSupplierPartyValue($emitter);
    $normalizedAcquirer = normalizeSupplierPartyValue($acquirer);
    if ($normalizedEmitter === '' || $normalizedAcquirer === '') {
        return $items;
    }

    $docCodes = [];
    foreach ($items as $line) {
        if (!is_array($line)) {
            continue;
        }
        $code = extractDocumentCodeFromLine($line);
        if ($code !== '') {
            $docCodes[$code] = true;
        }
    }

    if (empty($docCodes)) {
        return $items;
    }

    $placeholders = implode(', ', array_fill(0, count($docCodes), '?'));
    $sql = 'SELECT doc_codigo, erp_codigo FROM supplier_documents WHERE emitter = ? AND acquirer = ? AND doc_codigo IN (' . $placeholders . ')';
    $stmt = $pdo->prepare($sql);
    $params = [$normalizedEmitter, $normalizedAcquirer];
    foreach (array_keys($docCodes) as $code) {
        $params[] = $code;
    }
    $stmt->execute($params);

    $mappings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $docCode = normalizeSupplierDocumentCode($row['doc_codigo'] ?? '');
        $erpCode = normalizeErpCodeValue($row['erp_codigo'] ?? '');
        if ($docCode === '' || $erpCode === '') {
            continue;
        }
        $mappings[$docCode] = $erpCode;
    }

    if (empty($mappings)) {
        return $items;
    }

    foreach ($items as &$line) {
        if (!is_array($line)) {
            continue;
        }
        $existingErp = normalizeErpCodeValue($line['ERP'] ?? '');
        if ($existingErp !== '') {
            continue;
        }
        $code = extractDocumentCodeFromLine($line);
        if ($code === '' || !isset($mappings[$code])) {
            continue;
        }
        $line['ERP'] = $mappings[$code];
    }
    unset($line);

    return $items;
}

function normalizeOcrIdentifiers($emitter, $acquirer, $docType): array {
    $normalizedEmitter = normalizeSupplierPartyValue($emitter);
    $normalizedAcquirer = normalizeSupplierPartyValue($acquirer);
    $normalizedDocType = normalizeDocTypeValue($docType);
    return [$normalizedEmitter, $normalizedAcquirer, $normalizedDocType];
}

function isOcrLinesDisabled(PDO $pdo, $emitter, $acquirer, $docType): bool {
    [$normalizedEmitter, $normalizedAcquirer, $normalizedDocType] = normalizeOcrIdentifiers($emitter, $acquirer, $docType);
    if ($normalizedEmitter === '' || $normalizedAcquirer === '' || $normalizedDocType === '') {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT skip_ocr_lines FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
    );
    $stmt->execute([$normalizedEmitter, $normalizedAcquirer, $normalizedDocType]);
    return (bool) $stmt->fetchColumn();
}

function updateOcrLinesPreference(PDO $pdo, $emitter, $acquirer, $docType, bool $skip): void {
    [$normalizedEmitter, $normalizedAcquirer, $normalizedDocType] = normalizeOcrIdentifiers($emitter, $acquirer, $docType);
    if ($normalizedEmitter === '' || $normalizedAcquirer === '' || $normalizedDocType === '') {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO accounting_classifications (emitter, acquirer, doc_type, account, skip_ocr_lines) VALUES (?, ?, ?, ?, ?) 
        ON DUPLICATE KEY UPDATE skip_ocr_lines = VALUES(skip_ocr_lines)'
    );
    $stmt->execute([$normalizedEmitter, $normalizedAcquirer, $normalizedDocType, '', $skip ? 1 : 0]);
}


$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'lines') {
    if (!isLoggedIn() || getCompanySlug() === null) {
        http_response_code(403);
        exit;
    }
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $idValue = is_numeric($id) ? (int) $id : 0;
    requireCtbClassificationPermission($pdo, $idValue > 0 ? $idValue : null);
    $stmt = $pdo->prepare(
        'SELECT id, filename, line_items, field_A, field_B, field_D, field_I2, field_M, field_O, field_N, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8
         FROM accounting_imports
         WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        http_response_code(404);
        exit;
    }
    $emitterRaw = $row['field_A'] ?? '';
    $acquirerRaw = $row['field_B'] ?? '';
    $docTypeRaw = $row['field_D'] ?? '';
    $skipOcr = isOcrLinesDisabled($pdo, $emitterRaw, $acquirerRaw, $docTypeRaw);
    $debugOcr = getSetting('debug_mode', '0') === '1';
    $ocrDiagnostics = [
        'id' => (int) $id,
        'provider' => '',
        'skip_ocr' => $skipOcr ? 1 : 0,
        'filename' => (string) ($row['filename'] ?? ''),
        'qr' => [
            'field_I2' => (string) ($row['field_I2'] ?? ''),
            'field_M' => (string) ($row['field_M'] ?? ''),
            'field_N' => (string) ($row['field_N'] ?? ''),
            'field_O' => (string) ($row['field_O'] ?? ''),
        ],
    ];

    $buildBankQrFallbackLines = static function(array $document): array {
        if (!isBankEmitterCandidateFromQrFields($document)) {
            return [];
        }
        $base = parseAccountingBankLoanAmount($document['field_I2'] ?? null);
        $stamp = parseAccountingBankLoanAmount($document['field_M'] ?? null);
        $total = parseAccountingBankLoanAmount($document['field_O'] ?? null);
        if ($stamp === null || $total === null) {
            return [];
        }
        if ($base === null && $total > 0 && $stamp > 0) {
            $base = $total - $stamp;
        }
        if ($base <= 0 || $stamp <= 0 || $total <= 0) {
            return [];
        }
        return [
            [
                'ERP' => '1',
                'IVA_TAXA' => 'IS',
                'ITEM' => 'Juros',
                'QUANTITY' => '1',
                'UNIT_PRICE' => number_format($base, 2, ',', ''),
                'PRICE' => number_format($base, 2, ',', ''),
                'BANK_QR_FALLBACK' => '1',
            ],
            [
                'ERP' => '2',
                'IVA_TAXA' => 'IS',
                'ITEM' => 'Comissoes / Imposto do selo',
                'QUANTITY' => '1',
                'UNIT_PRICE' => number_format($stamp, 2, ',', ''),
                'PRICE' => number_format($stamp, 2, ',', ''),
                'BANK_QR_FALLBACK' => '1',
            ],
        ];
    };

    $respondWithLines = static function(array $items, string $source = 'unknown') use ($row, $skipOcr, $emitterRaw, $acquirerRaw, $docTypeRaw, $debugOcr, &$ocrDiagnostics) {
        header('Content-Type: application/json');
        $payload = [
            'lines' => $items,
            'source' => $source,
            'skip_ocr' => $skipOcr,
            'emitter' => $emitterRaw,
            'emitter_display' => $row['field_A'] ?? '',
            'acquirer' => $acquirerRaw,
            'doc_type' => $docTypeRaw,
            'csrf_token' => generateCsrfToken()
        ];
        if ($debugOcr) {
            $ocrDiagnostics['source'] = $source;
            $ocrDiagnostics['returned_lines'] = count($items);
            $payload['ocr_debug'] = $ocrDiagnostics;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    };
    $isOnlyBankQrFallbackCache = static function(array $items): bool {
        if (empty($items)) {
            return false;
        }
        foreach ($items as $item) {
            if (!is_array($item) || trim((string) ($item['BANK_QR_FALLBACK'] ?? '')) !== '1') {
                return false;
            }
        }
        return true;
    };
    // If line items already stored, return them without invoking OCR again.
    if (!empty($row['line_items'])) {
        $items = json_decode($row['line_items'], true);
        if (!is_array($items)) {
            $items = [];
        }
        if (!empty($items)) {
            $ocrProviderForCache = getSetting('ocr_provider', 'tesseract');
            if (!$skipOcr && $ocrProviderForCache === 'textract' && $isOnlyBankQrFallbackCache($items)) {
                $stmtClearFallbackCache = $pdo->prepare('UPDATE accounting_imports SET line_items = NULL WHERE id = ?');
                $stmtClearFallbackCache->execute([$id]);
                $ocrDiagnostics['provider'] = $ocrProviderForCache;
                $ocrDiagnostics['ignored_cache'] = 'bank_qr_fallback';
                logOcrMessage('Ignoring bank QR fallback cache to retry Textract for accounting_imports.id=' . (int) $id);
            } else {
                $items = applySupplierDocumentMappings($pdo, $row['field_A'] ?? '', $row['field_B'] ?? '', $items);
                $respondWithLines($items, 'cache');
            }
        } else {
            $stmtClearEmptyCache = $pdo->prepare('UPDATE accounting_imports SET line_items = NULL WHERE id = ?');
            $stmtClearEmptyCache->execute([$id]);
        }
    }

    $path = dirname(__DIR__) . '/' . $row['filename'];
    $ocrProvider = getSetting('ocr_provider', 'tesseract');
    $ocrDiagnostics['provider'] = $ocrProvider;
    $ocrDiagnostics['path_exists'] = file_exists($path) ? 1 : 0;
    $ocrDiagnostics['path_size'] = file_exists($path) ? filesize($path) : null;
    logOcrMessage(
        'Lines request id=' . (int) $id
        . ' provider=' . $ocrProvider
        . ' skip=' . ($skipOcr ? '1' : '0')
        . ' file_exists=' . ($ocrDiagnostics['path_exists'] ? '1' : '0')
        . ' I2=' . (string) ($row['field_I2'] ?? '')
        . ' M=' . (string) ($row['field_M'] ?? '')
        . ' N=' . (string) ($row['field_N'] ?? '')
        . ' O=' . (string) ($row['field_O'] ?? '')
    );

    if ($ocrProvider !== 'textract') {
        $fallbackItems = $buildBankQrFallbackLines($row);
        if (!empty($fallbackItems)) {
            $stmt = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
            $stmt->execute([json_encode($fallbackItems, JSON_UNESCAPED_UNICODE), $id]);
            logOcrMessage('Using bank QR fallback because OCR provider is ' . $ocrProvider . ' for accounting_imports.id=' . (int) $id);
            $respondWithLines($fallbackItems, 'bank_qr_fallback_no_textract');
        }
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'OCR provider inválido']);
        exit;
    }

    if ($skipOcr) {
        $fallbackItems = $buildBankQrFallbackLines($row);
        if (!empty($fallbackItems)) {
            $stmt = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
            $stmt->execute([json_encode($fallbackItems, JSON_UNESCAPED_UNICODE), $id]);
            $respondWithLines($fallbackItems, 'bank_qr_fallback_skip_ocr');
        }
        $respondWithLines([], 'skip_ocr');
    }

    try {
        $textractDiagnostics = [];
        $items = parseInvoiceLineTextract($path, $textractDiagnostics);
        $ocrDiagnostics['textract'] = $textractDiagnostics;
        if (!is_array($items)) {
            $items = [];
        }
        $items = applySupplierDocumentMappings($pdo, $row['field_A'] ?? '', $row['field_B'] ?? '', $items);
        // Store only useful OCR results so a transient empty parse can be retried.
        if (!empty($items)) {
            $stmt = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
            $stmt->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $id]);
            logOcrMessage('Textract stored ' . count($items) . ' line items for accounting_imports.id=' . (int) $id);
            $respondWithLines($items, 'textract');
        } else {
            logOcrMessage('Textract returned no line items for accounting_imports.id=' . (int) $id . ' diagnostics=' . json_encode($textractDiagnostics, JSON_UNESCAPED_UNICODE));
            $fallbackItems = $buildBankQrFallbackLines($row);
            if (!empty($fallbackItems)) {
                $stmt = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
                $stmt->execute([json_encode($fallbackItems, JSON_UNESCAPED_UNICODE), $id]);
                $respondWithLines($fallbackItems, 'bank_qr_fallback_empty_textract');
            }
        }
        $respondWithLines($items, 'textract_empty');
    } catch (Throwable $e) {
        $ocrDiagnostics['textract'] = $textractDiagnostics ?? [];
        $ocrDiagnostics['exception'] = $e->getMessage();
        logOcrMessage('Textract OCR error: ' . $e->getMessage() . ' diagnostics=' . json_encode($ocrDiagnostics, JSON_UNESCAPED_UNICODE));
        $fallbackItems = $buildBankQrFallbackLines($row);
        if (!empty($fallbackItems)) {
            $stmt = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
            $stmt->execute([json_encode($fallbackItems, JSON_UNESCAPED_UNICODE), $id]);
            $respondWithLines($fallbackItems, 'bank_qr_fallback_textract_error');
        }
        http_response_code(500);
        header('Content-Type: application/json');
        $payload = ['error' => 'Erro no OCR: ' . $e->getMessage()];
        if ($debugOcr) {
            $payload['ocr_debug'] = $ocrDiagnostics;
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json');

if (!isLoggedIn() || getCompanySlug() === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

if ($action === 'get') {
    $token = $_GET['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $a = $_GET['A'] ?? '';
    $b = $_GET['B'] ?? '';
    $d = $_GET['D'] ?? '';
    $id = $_GET['id'] ?? '';
    $tenantKey = $_GET['tenant_key'] ?? ($_GET['acquirer_database'] ?? '');
    $documentFieldsJson = $_GET['document_fields'] ?? '{}';
    $decodedDocumentFields = json_decode((string) $documentFieldsJson, true);
    $submittedDocumentFields = normalizeSubmittedEditableAccountingImportFields($decodedDocumentFields);
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $idValue = is_numeric($id) ? (int) $id : 0;
    requireCtbClassificationPermission($pdo, $idValue > 0 ? $idValue : null);
    $rowAccounts = normalizeAccountingAccounts(null);
    $rowMetadata = normalizeAccountingMetadata(null);
    $rowCostCenters = buildEmptyCostCenterMap();
    $originalSnapshot = [];
    $summaries = [];
    $rowRequirements = [];
    $importRow = [];
    if ($id !== '') {
        $stmtRow = $pdo->prepare('SELECT * FROM accounting_imports WHERE id = ? LIMIT 1');
        $stmtRow->execute([$id]);
        $importRow = $stmtRow->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!empty($importRow)) {
            foreach ($submittedDocumentFields as $fieldName => $fieldValue) {
                $importRow[$fieldName] = $fieldValue;
            }
            $rawImportRow = $importRow;
            applyDefaultEditableAccountingImportFields($importRow);
            $a = (string) ($importRow['field_A'] ?? $a);
            $b = (string) ($importRow['field_B'] ?? $b);
            $d = (string) ($importRow['field_D'] ?? $d);
        }
        $rowAccounts = normalizeAccountingAccounts($importRow['account'] ?? '');
        $rowMetadata = normalizeAccountingMetadata($importRow['account'] ?? '');
        if (($rowMetadata['ignore_detected_rates'] ?? '0') === '1') {
            $rowAccounts = filterVisibleAccountingRates($rowAccounts);
        }
        $rowHasDocumentIdentity = false;
        foreach (['field_A', 'field_B', 'field_D', 'field_F', 'field_G', 'field_H', 'field_R'] as $identityField) {
            if (trim((string) (($rawImportRow[$identityField] ?? $importRow[$identityField] ?? ''))) !== '') {
                $rowHasDocumentIdentity = true;
                break;
            }
        }
        $rowHasEmitterAndAcquirerOnly = trim((string) (($rawImportRow['field_A'] ?? $importRow['field_A'] ?? ''))) !== ''
            && trim((string) (($rawImportRow['field_B'] ?? $importRow['field_B'] ?? ''))) !== ''
            && trim((string) (($rawImportRow['field_D'] ?? $importRow['field_D'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_F'] ?? $importRow['field_F'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_G'] ?? $importRow['field_G'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_H'] ?? $importRow['field_H'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_R'] ?? $importRow['field_R'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_N'] ?? $importRow['field_N'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_O'] ?? $importRow['field_O'] ?? ''))) === '';
        $rowMetadata['manual_document_fields'] = (($rowMetadata['manual_document_fields'] ?? '0') === '1' || !$rowHasDocumentIdentity || $rowHasEmitterAndAcquirerOnly) ? '1' : '0';
        $rowCostCenters = normalizeCostCenters($importRow['cost_center'] ?? '');
        $rowCostCenterBreakdowns = normalizeCostCenterBreakdowns($importRow['cost_center'] ?? '');
        $summaries = computeImportRateSummaries($importRow);
        [, $rowRequirements] = buildClassificationRequirements($summaries, $rowAccounts, $rowMetadata);

        $decodedOriginal = [];
        if (array_key_exists('account_original', $importRow) && $importRow['account_original'] !== null) {
            $candidate = json_decode((string) $importRow['account_original'], true);
            if (is_array($candidate)) {
                $decodedOriginal = $candidate;
            }
        }
        $originalSnapshot = mergeOriginalRateSnapshot($decodedOriginal, $summaries);
    } elseif (!empty($submittedDocumentFields)) {
        $importRow = $submittedDocumentFields;
        applyDefaultEditableAccountingImportFields($importRow);
        $a = (string) ($importRow['field_A'] ?? $a);
        $b = (string) ($importRow['field_B'] ?? $b);
        $d = (string) ($importRow['field_D'] ?? $d);
    }

    ensureAccountingEntity($pdo, (string) ($importRow['field_A'] ?? $a));
    $classificationPayload = fetchClassificationAccountPayload($pdo, $a, $b, $d, $importRow);
    $classificationAccounts = normalizeAccountingAccounts($classificationPayload);
    $classificationMetadata = normalizeAccountingMetadata($classificationPayload);
    if (($classificationMetadata['ignore_detected_rates'] ?? '0') === '1') {
        $classificationAccounts = filterVisibleAccountingRates($classificationAccounts);
    }

    if (empty($originalSnapshot) && !empty($summaries)) {
        $originalSnapshot = mergeOriginalRateSnapshot([], $summaries);
    }

    $classificationTotalAccount = resolveClassificationTotalAccountForContext(
        $classificationMetadata,
        $rowMetadata['has_receipt_companion'] ?? '0'
    );

    if ($classificationTotalAccount === '') {
        $historicalTotalAccount = suggestHistoricalTotalAccount($pdo, [
            'emitter' => $importRow['field_A'] ?? $a,
            'emitter_nif' => $importRow['field_C'] ?? '',
            'acquirer' => $importRow['field_B'] ?? $b,
            'acquirer_nif' => $importRow['field_B'] ?? $b,
            'doc_type' => $importRow['field_D'] ?? $d,
            'has_receipt_companion' => $rowMetadata['has_receipt_companion'] ?? '0',
        ], $idValue);
        if ($historicalTotalAccount !== '') {
            $classificationTotalAccount = $historicalTotalAccount;
        }
    }

    $suggestedCostCenters = suggestHistoricalCostCenters(
        $pdo,
        (string) $a,
        (string) $b,
        (string) $d,
        $idValue
    );

    echo json_encode([
        'rates' => $classificationAccounts,
        'row_rates' => $rowAccounts,
        'row_requirements' => $rowRequirements,
        'cost_center' => serializeCostCenters($rowCostCenters, $rowCostCenterBreakdowns),
        'cost_centers' => $rowCostCenters,
        'cost_center_breakdowns' => $rowCostCenterBreakdowns,
        'suggested_cost_centers' => $suggestedCostCenters,
        'total_account' => $classificationTotalAccount,
        'row_total_account' => $rowMetadata['total_account'] ?? '',
        'has_receipt_companion' => $rowMetadata['has_receipt_companion'] ?? '0',
        'ignore_detected_rates' => $rowMetadata['ignore_detected_rates'] ?? '0',
        'classification_model_name' => $rowMetadata['classification_model_name'] ?? '',
        'classification_models' => loadSharedClassificationModels($pdo, $a, $b, $d, (string) $tenantKey),
        'emitter_type' => resolveEmitterTypeValue($pdo, (string) ($importRow['field_A'] ?? $a), $importRow),
        'original_rates' => $originalSnapshot,
        'document_fields' => extractEditableAccountingImportFields($importRow),
        'show_document_fields' => $rowMetadata['manual_document_fields'] ?? '0',
        'csrf_token' => generateCsrfToken()
    ]);
    exit;
} elseif ($action === 'delete_model') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }

    $a = $_POST['A'] ?? '';
    $b = $_POST['B'] ?? '';
    $d = $_POST['D'] ?? '';
    $name = $_POST['model_name'] ?? '';
    $tenantKey = $_POST['tenant_key'] ?? ($_POST['acquirer_database'] ?? '');

    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }

    $idValue = isset($_POST['id']) && is_numeric($_POST['id']) ? (int) $_POST['id'] : 0;
    requireCtbClassificationPermission($pdo, $idValue > 0 ? $idValue : null);

    if (!deleteSharedClassificationModel($pdo, $a, $b, $d, $name, (string) $tenantKey)) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Modelo não encontrado.',
            'classification_models' => loadSharedClassificationModels($pdo, $a, $b, $d, (string) $tenantKey),
            'csrf_token' => generateCsrfToken(true)
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'classification_models' => loadSharedClassificationModels($pdo, $a, $b, $d, (string) $tenantKey),
        'csrf_token' => generateCsrfToken(true)
    ]);
    exit;
} elseif ($action === 'save') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $id = $_POST['id'] ?? '';
    $a = $_POST['A'] ?? '';
    $b = $_POST['B'] ?? '';
    $d = $_POST['D'] ?? '';
    $tenantKey = $_POST['tenant_key'] ?? ($_POST['acquirer_database'] ?? '');
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $idValue = is_numeric($id) ? (int) $id : 0;
    requireCtbClassificationPermission($pdo, $idValue > 0 ? $idValue : null);
    ensureAccountingEntity($pdo, (string) $a);
    try {
        $pdo->beginTransaction();

        $ratesJson = $_POST['rates'] ?? '[]';
        $ratesData = json_decode($ratesJson, true);
        if (!is_array($ratesData)) {
            $ratesData = [];
        }
        $bankLoanConversionSubmitted = false;
        foreach ($ratesData as $rateData) {
            if (!is_array($rateData)) {
                continue;
            }
            if (trim((string) ($rateData['bank_loan_conversion'] ?? '')) === '1') {
                $bankLoanConversionSubmitted = true;
                break;
            }
        }
        $submittedRates = sanitizeAccountInput($ratesData);

        $originalJson = $_POST['original_rates'] ?? '[]';
        $originalData = json_decode($originalJson, true);
        if (!is_array($originalData)) {
            $originalData = [];
        }
        $submittedOriginal = normalizeOriginalRatesPayload($originalData);

        $removedJson = $_POST['removed_rates'] ?? '[]';
        $removedRates = json_decode($removedJson, true);
        if (!is_array($removedRates)) {
            $removedRates = [];
        }
        $removedRates = array_values(array_filter(array_map(
            static function ($rate) {
                if (is_string($rate) || is_numeric($rate)) {
                    $string = trim((string) $rate);
                    if ($string !== '') {
                        return $string;
                    }
                }
                return null;
            },
            $removedRates
        )));

        $costCentersJson = $_POST['cost_centers'] ?? '';
        $costCentersData = [];
        if ($costCentersJson !== '') {
            $decodedCostCenters = json_decode($costCentersJson, true);
            if (!is_array($decodedCostCenters)) {
                $decodedCostCenters = [];
            }
            $costCentersData = sanitizeCostCenterValues($decodedCostCenters);
        } else {
            $legacyCostCenter = isset($_POST['cost_center']) ? trim((string) $_POST['cost_center']) : '';
            if ($legacyCostCenter === '') {
                $costCentersData = sanitizeCostCenterValues([]);
            } else {
                $costCentersData = sanitizeCostCenterValues($legacyCostCenter);
            }
        }

        $costCenterBreakdownsJson = $_POST['cost_center_breakdowns'] ?? '';
        $costCenterBreakdownsData = [];

        $selectedModelName = sanitizeClassificationModelName($_POST['classification_model_name'] ?? '');
        $saveModelName = sanitizeClassificationModelName($_POST['save_model_name'] ?? '');
        $submittedEmitterType = normalizeEmitterTypeValue($_POST['emitter_type'] ?? '');
        $ignoreDetectedRates = trim((string) ($_POST['ignore_detected_rates'] ?? '0'));
        $manualDocumentFieldsProvided = array_key_exists('manual_document_fields', $_POST);
        $manualDocumentFieldsValue = ($manualDocumentFieldsProvided && trim((string) ($_POST['manual_document_fields'] ?? '')) === '1') ? '1' : '0';
        $documentFieldsJson = $_POST['document_fields'] ?? '{}';
        $decodedDocumentFields = json_decode($documentFieldsJson, true);
        $submittedDocumentFields = normalizeSubmittedEditableAccountingImportFields($decodedDocumentFields);
        $submittedMetadata = sanitizeAccountingMetadata([
            'total_account' => $_POST['total_account'] ?? '',
            'ignore_detected_rates' => ($ignoreDetectedRates === '1' || $selectedModelName !== '' || $saveModelName !== '') ? '1' : '0',
            'classification_model_name' => $saveModelName !== '' ? $saveModelName : $selectedModelName,
        ]);

        $stmtRow = $pdo->prepare('SELECT * FROM accounting_imports WHERE id = ? LIMIT 1');
        $stmtRow->execute([$id]);
        $importRow = $stmtRow->fetch(PDO::FETCH_ASSOC);
        if (!$importRow) {
            throw new RuntimeException('Importação inexistente');
        }
        $rowHadDocumentIdentity = false;
        foreach (['field_A', 'field_B', 'field_D', 'field_F', 'field_G', 'field_H', 'field_R'] as $identityField) {
            if (trim((string) ($importRow[$identityField] ?? '')) !== '') {
                $rowHadDocumentIdentity = true;
                break;
            }
        }
        foreach ($submittedDocumentFields as $fieldName => $fieldValue) {
            $importRow[$fieldName] = $fieldValue;
        }
        $rawImportRow = $importRow;
        applyDefaultEditableAccountingImportFields($importRow);
        $a = (string) ($importRow['field_A'] ?? $a);
        $b = (string) ($importRow['field_B'] ?? $b);
        $d = (string) ($importRow['field_D'] ?? $d);
        ensureAccountingEntity($pdo, (string) $a);
        if ($submittedEmitterType !== '') {
            persistEmitterTypeValue($pdo, (string) $a, $submittedEmitterType);
        }
        if ($bankLoanConversionSubmitted) {
            markAccountingEntityAsBankEntity($pdo, (string) $a);
        }
        [$classificationEmitter, $classificationAcquirer, $classificationDocType] = resolveClassificationStorageIdentifiers($a, $b, $d, $importRow);
        $existingClassRaw = fetchClassificationAccountPayload($pdo, $a, $b, $d, $importRow);
        $existingClass = normalizeAccountingAccounts($existingClassRaw);
        $existingClassMetadata = normalizeAccountingMetadata($existingClassRaw);
        if ($costCenterBreakdownsJson !== '') {
            $decodedCostCenterBreakdowns = json_decode($costCenterBreakdownsJson, true);
            if (!is_array($decodedCostCenterBreakdowns)) {
                $decodedCostCenterBreakdowns = [];
            }
            $costCenterBreakdownsData = sanitizeCostCenterBreakdownValues($decodedCostCenterBreakdowns);
        } else {
            $costCenterBreakdownsData = normalizeCostCenterBreakdowns($importRow['cost_center'] ?? '');
        }
        $existingRow = normalizeAccountingAccounts($importRow['account'] ?? '');
        $existingRowMetadata = normalizeAccountingMetadata($importRow['account'] ?? '');
        $existingRowMetadata['manual_review_required'] = (($existingRowMetadata['manual_review_required'] ?? '0') === '1') ? '1' : '0';
        $existingRowMetadata['has_receipt_companion'] = (($existingRowMetadata['has_receipt_companion'] ?? '0') === '1') ? '1' : '0';
        $rowHadEmitterAndAcquirerOnly = trim((string) (($rawImportRow['field_A'] ?? $importRow['field_A'] ?? ''))) !== ''
            && trim((string) (($rawImportRow['field_B'] ?? $importRow['field_B'] ?? ''))) !== ''
            && trim((string) (($rawImportRow['field_D'] ?? $importRow['field_D'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_F'] ?? $importRow['field_F'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_G'] ?? $importRow['field_G'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_H'] ?? $importRow['field_H'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_R'] ?? $importRow['field_R'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_N'] ?? $importRow['field_N'] ?? ''))) === ''
            && trim((string) (($rawImportRow['field_O'] ?? $importRow['field_O'] ?? ''))) === '';
        $existingRowMetadata['manual_document_fields'] = (($existingRowMetadata['manual_document_fields'] ?? '0') === '1' || !$rowHadDocumentIdentity || $rowHadEmitterAndAcquirerOnly) ? '1' : '0';
        $submittedMetadata['has_receipt_companion'] = $existingRowMetadata['has_receipt_companion'];
        $submittedMetadata['manual_document_fields'] = $manualDocumentFieldsProvided
            ? $manualDocumentFieldsValue
            : $existingRowMetadata['manual_document_fields'];

        $existingOriginalRaw = [];
        if (array_key_exists('account_original', $importRow) && $importRow['account_original'] !== null) {
            $candidate = json_decode((string) $importRow['account_original'], true);
            if (is_array($candidate)) {
                $existingOriginalRaw = $candidate;
            }
        }
        $summaries = computeImportRateSummaries($importRow);
        $existingOriginal = mergeOriginalRateSnapshot($existingOriginalRaw, $summaries);
        [$existingPayload, $existingRequirements] = buildClassificationRequirements($summaries, $existingRow, $existingRowMetadata);
        $existingRowCostCenters = normalizeCostCenters($importRow['cost_center'] ?? '');
        $existingRowWasReady = determineClassificationButtonClass($existingRequirements, $existingPayload, $existingRowMetadata, $existingRowCostCenters) === 'btn-success';

        $rowAccounts = mergeAccountingAccounts($existingRow, $submittedRates);
        $classAccounts = stripAccountingAmounts(mergeAccountingAccounts($existingClass, $submittedRates));

        foreach ($removedRates as $rate) {
            unset($rowAccounts[$rate], $classAccounts[$rate], $costCentersData[$rate]);
            unset($costCenterBreakdownsData[$rate]);
            unset($existingOriginal[$rate]);
        }

        foreach ($submittedOriginal as $rate => $values) {
            if (!array_key_exists($rate, $existingOriginal)) {
                $existingOriginal[$rate] = [
                    'iva_account' => '',
                    'general_account' => '',
                    'base' => '',
                    'iva' => '',
                ];
            }
            if (array_key_exists('base', $values)) {
                $existingOriginal[$rate]['base'] = $values['base'];
            }
            if (array_key_exists('iva', $values)) {
                $existingOriginal[$rate]['iva'] = $values['iva'];
            }
        }

        foreach (buildDerivedEditableAccountingImportAmountFields($rowAccounts) as $fieldName => $fieldValue) {
            $importRow[$fieldName] = $fieldValue;
        }
        $summaries = computeImportRateSummaries($importRow);
        [, $rowRequirements] = buildClassificationRequirements($summaries, $rowAccounts, $submittedMetadata);
        $missingCostCenterRates = [];
        foreach ($rowRequirements as $rate => $requirement) {
            if (empty($requirement['cost_center'])) {
                continue;
            }
            $costCenterValue = trim((string) ($costCentersData[$rate] ?? ''));
            $distributionRows = sanitizeCostCenterBreakdownRows($costCenterBreakdownsData[$rate] ?? []);
            if ($costCenterValue === '' && empty($distributionRows)) {
                $missingCostCenterRates[] = (string) $rate;
            }
        }

        $normalizeRatesForComparison = static function (array $rates): array {
            $normalized = sanitizeAccountInput($rates);
            ksort($normalized);
            foreach ($normalized as &$rateData) {
                if (is_array($rateData)) {
                    ksort($rateData);
                }
            }
            unset($rateData);
            return $normalized;
        };
        $normalizeCostCentersForComparison = static function ($value): array {
            $normalized = sanitizeCostCenterValues($value);
            ksort($normalized);
            return $normalized;
        };
        $normalizeCostCenterBreakdownsForComparison = static function ($value): array {
            $normalized = sanitizeCostCenterBreakdownValues($value);
            ksort($normalized);
            foreach ($normalized as &$rows) {
                if (is_array($rows)) {
                    usort($rows, static function (array $a, array $b): int {
                        return strcmp(
                            ($a['cost_center'] ?? '') . '|' . ($a['percentage'] ?? '') . '|' . ($a['value'] ?? ''),
                            ($b['cost_center'] ?? '') . '|' . ($b['percentage'] ?? '') . '|' . ($b['value'] ?? '')
                        );
                    });
                }
            }
            unset($rows);
            return $normalized;
        };

        $existingRowNormalized = $normalizeRatesForComparison($existingRow);
        $rowAccountsNormalized = $normalizeRatesForComparison($rowAccounts);
        $rowAccountsChanged = $existingRowNormalized !== $rowAccountsNormalized;
        $existingCostCentersNormalized = $normalizeCostCentersForComparison($importRow['cost_center'] ?? '');
        $submittedCostCentersNormalized = $normalizeCostCentersForComparison($costCentersData);
        $costCentersChanged = $existingCostCentersNormalized !== $submittedCostCentersNormalized;
        $existingCostCenterBreakdownsNormalized = $normalizeCostCenterBreakdownsForComparison($importRow['cost_center'] ?? '');
        $submittedCostCenterBreakdownsNormalized = $normalizeCostCenterBreakdownsForComparison($costCenterBreakdownsData);
        $costCenterBreakdownsChanged = $existingCostCenterBreakdownsNormalized !== $submittedCostCenterBreakdownsNormalized;
        $existingTotalAccount = trim((string) ($existingRowMetadata['total_account'] ?? ''));
        $submittedTotalAccount = trim((string) ($submittedMetadata['total_account'] ?? ''));
        $totalAccountChanged = $existingTotalAccount !== $submittedTotalAccount;
        $hasManualChanges = $rowAccountsChanged || $costCentersChanged || $costCenterBreakdownsChanged || $totalAccountChanged || !empty($removedRates);

        $submittedMetadata['manual_review_required'] = (($existingRowMetadata['manual_review_required'] ?? '0') === '1') ? '1' : '0';
        if ($existingRowWasReady && $hasManualChanges) {
            $submittedMetadata['manual_review_required'] = '1';
        }

        $serializedRow = serializeAccountingAccounts($rowAccounts, $submittedMetadata, $existingRowMetadata);
        $classMetadata = $submittedMetadata;
        $classMetadata['manual_review_required'] = '0';
        $classMetadata['receipt_total_account'] = $existingClassMetadata['receipt_total_account'] ?? '';
        if (($submittedMetadata['has_receipt_companion'] ?? '0') === '1') {
            $classMetadata['total_account'] = $existingClassMetadata['total_account'] ?? '';
            $classMetadata['receipt_total_account'] = $submittedMetadata['total_account'] ?? '';
        }
        $classMetadata['has_receipt_companion'] = '0';
        $serializedClass = serializeAccountingAccounts($classAccounts, $classMetadata, $existingClassMetadata);
        $shouldUpdateSharedClassification = shouldPersistSharedClassification(
            $rowRequirements,
            $rowAccounts,
            $submittedMetadata,
            $costCentersData
        );
        $serializedCostCenters = serializeCostCenters($costCentersData, $costCenterBreakdownsData);
        $serializedOriginal = serializeAccountingAccounts($existingOriginal);
        $responseRowRates = (($submittedMetadata['ignore_detected_rates'] ?? '0') === '1')
            ? filterVisibleAccountingRates($rowAccounts)
            : $rowAccounts;

        $savedModel = null;
        if ($saveModelName !== '') {
            $savedModel = upsertSharedClassificationModel($pdo, [
                'name' => $saveModelName,
                'emitter' => $a,
                'acquirer' => $b,
                'doc_type' => $d,
                'tenant_key' => $tenantKey,
                'acquirer_database' => $tenantKey,
                'rates' => stripAccountingAmounts($rowAccounts),
                'cost_centers' => $costCentersData,
                'cost_center_breakdowns' => $costCenterBreakdownsData,
                'total_account' => $submittedMetadata['total_account'] ?? '',
            ]);
        }

        $editableFieldColumns = getEditableAccountingImportFieldColumns();
        $updateAssignments = [];
        $updateValues = [];
        foreach ($editableFieldColumns as $fieldName) {
            $updateAssignments[] = $fieldName . ' = ?';
            $updateValues[] = (string) ($importRow[$fieldName] ?? '');
        }
        $updateAssignments[] = 'account = ?';
        $updateAssignments[] = 'cost_center = ?';
        $updateAssignments[] = 'account_original = ?';
        $updateValues[] = $serializedRow;
        $updateValues[] = $serializedCostCenters;
        $updateValues[] = $serializedOriginal;
        $updateValues[] = $id;

        $stmt = $pdo->prepare('UPDATE accounting_imports SET ' . implode(', ', $updateAssignments) . ' WHERE id = ?');
        $stmt->execute($updateValues);

        if ($shouldUpdateSharedClassification) {
            $stmt2 = $pdo->prepare(
                'INSERT INTO accounting_classifications (emitter, acquirer, doc_type, account) '
                . 'VALUES (?, ?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE account = VALUES(account)'
            );
            $stmt2->execute([$classificationEmitter, $classificationAcquirer, $classificationDocType, $serializedClass]);
        }
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'csrf_token' => generateCsrfToken(),
            'row_rates' => $responseRowRates,
            'requirements' => $rowRequirements,
            'cost_center' => $serializedCostCenters,
            'cost_centers' => $costCentersData,
            'cost_center_breakdowns' => $costCenterBreakdownsData,
            'original_rates' => $existingOriginal,
            'total_account' => $submittedMetadata['total_account'] ?? '',
            'row_total_account' => $submittedMetadata['total_account'] ?? '',
            'has_receipt_companion' => $submittedMetadata['has_receipt_companion'] ?? '0',
            'manual_review_required' => $submittedMetadata['manual_review_required'] ?? '0',
            'ignore_detected_rates' => $submittedMetadata['ignore_detected_rates'] ?? '0',
            'classification_model_name' => $submittedMetadata['classification_model_name'] ?? '',
            'classification_models' => loadSharedClassificationModels($pdo, $a, $b, $d, (string) $tenantKey),
            'emitter_type' => resolveEmitterTypeValue($pdo, (string) ($importRow['field_A'] ?? $a), $importRow),
            'document_fields' => extractEditableAccountingImportFields($importRow),
            'show_document_fields' => $submittedMetadata['manual_document_fields'] ?? '0',
            'saved_model_name' => $savedModel['name'] ?? ''
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        // Log the underlying exception for debugging and expose the message in
        // the JSON response so the caller can act accordingly.
        error_log('save-analysis error: ' . $e->getMessage());
        echo json_encode([
            'error' => 'Erro ao guardar: ' . $e->getMessage(),
            'csrf_token' => generateCsrfToken()
        ]);
    }
    exit;
} elseif ($action === 'save_lines') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $id = $_POST['id'] ?? '';
    $linesJson = $_POST['lines'] ?? '[]';
    $lines = json_decode($linesJson, true);
    if (!is_array($lines)) {
        $lines = [];
    }
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $idValue = is_numeric($id) ? (int) $id : 0;
    requireCtbClassificationPermission($pdo, $idValue > 0 ? $idValue : null);
    $stmtImport = $pdo->prepare('SELECT field_A, field_B FROM accounting_imports WHERE id = ? LIMIT 1');
    $stmtImport->execute([$id]);
    $importRow = $stmtImport->fetch(PDO::FETCH_ASSOC);
    if (!$importRow) {
        http_response_code(404);
        echo json_encode(['error' => 'Importação inexistente', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }

    foreach ($lines as &$line) {
        if (!is_array($line)) {
            continue;
        }
        if (array_key_exists('ERP', $line)) {
            $line['ERP'] = normalizeErpCodeValue($line['ERP']);
        }
    }
    unset($line);

    $normalizedEmitter = normalizeSupplierPartyValue($importRow['field_A'] ?? '');
    $normalizedAcquirer = normalizeSupplierPartyValue($importRow['field_B'] ?? '');

    try {
        $pdo->beginTransaction();

        $stmtUpdate = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
        $stmtUpdate->execute([json_encode($lines, JSON_UNESCAPED_UNICODE), $id]);

        if ($normalizedEmitter !== '' && $normalizedAcquirer !== '') {
            $stmtDoc = $pdo->prepare(
                'INSERT INTO supplier_documents (emitter, acquirer, doc_codigo, erp_codigo) ' .
                'VALUES (?, ?, ?, ?) ' .
                'ON DUPLICATE KEY UPDATE erp_codigo = VALUES(erp_codigo)'
            );

            foreach ($lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $docCode = extractDocumentCodeFromLine($line);
                $erpCode = normalizeErpCodeValue($line['ERP'] ?? '');
                if ($docCode === '' || $erpCode === '') {
                    continue;
                }
                $stmtDoc->execute([$normalizedEmitter, $normalizedAcquirer, $docCode, $erpCode]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'csrf_token' => generateCsrfToken()]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('save-analysis save_lines error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao guardar linhas', 'csrf_token' => generateCsrfToken()]);
    }
    exit;
} elseif ($action === 'toggle_skip_ocr') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $emitter = $_POST['emitter'] ?? '';
    $acquirer = $_POST['acquirer'] ?? '';
    $docType = $_POST['doc_type'] ?? '';
    if ($emitter === '' || $acquirer === '' || $docType === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Dados insuficientes para guardar preferência', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $skip = isset($_POST['skip']) ? (int) $_POST['skip'] : 1;
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    try {
        updateOcrLinesPreference($pdo, $emitter, $acquirer, $docType, $skip === 1);
        echo json_encode([
            'success' => true,
            'skip' => $skip ? 1 : 0,
            'csrf_token' => generateCsrfToken()
        ]);
    } catch (Throwable $e) {
        logOcrMessage('Erro ao atualizar preferência OCR: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao atualizar preferência', 'csrf_token' => generateCsrfToken()]);
    }
    exit;
} elseif ($action === 'remove') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $id = $_POST['id'] ?? '';
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $idValue = is_numeric($id) ? (int) $id : 0;
    requireCtbClassificationPermission($pdo, $idValue > 0 ? $idValue : null);
    try {
        $stmt = $pdo->prepare('DELETE FROM accounting_imports WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'csrf_token' => generateCsrfToken()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao remover', 'csrf_token' => generateCsrfToken()]);
    }
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida', 'csrf_token' => generateCsrfToken()]);
    exit;
}
