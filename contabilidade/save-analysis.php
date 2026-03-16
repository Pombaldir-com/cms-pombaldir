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

function buildClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType): string {
    $parts = [
        trim((string) ($companySlug ?? '')),
        normalizeSupplierPartyValue($emitter),
        normalizeSupplierPartyValue($acquirer),
        normalizeDocTypeValue($docType),
    ];
    return implode('|', $parts);
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
        $name = sanitizeClassificationModelName($model['name'] ?? '');
        $emitter = normalizeSupplierPartyValue($model['emitter'] ?? '');
        $acquirer = normalizeSupplierPartyValue($model['acquirer'] ?? '');
        $docType = normalizeDocTypeValue($model['doc_type'] ?? '');
        if ($name === '') {
            continue;
        }

        $rates = sanitizeAccountInput(is_array($model['rates'] ?? null) ? $model['rates'] : []);
        $costCenters = sanitizeCostCenterValues($model['cost_centers'] ?? []);
        $costCenterBreakdowns = sanitizeCostCenterBreakdownValues($model['cost_center_breakdowns'] ?? []);
        $metadata = sanitizeAccountingMetadata([
            'total_account' => $model['total_account'] ?? '',
            'ignore_detected_rates' => '1',
            'classification_model_name' => $name,
        ]);

        $result[] = [
            'company_slug' => $companySlug,
            'name' => $name,
            'emitter' => $emitter,
            'acquirer' => $acquirer,
            'doc_type' => $docType,
            'scope_key' => buildClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType),
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

function loadSharedClassificationModels($emitter, $acquirer, $docType): array {
    $companySlug = (string) (getCompanySlug() ?? '');
    $scopeKey = buildClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType);
    if ($scopeKey === '|||') {
        return [];
    }

    $models = loadAllSharedClassificationModels();
    $filtered = [];
    foreach ($models as $model) {
        if (($model['scope_key'] ?? '') !== $scopeKey) {
            continue;
        }
        $filtered[] = $model;
    }

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

function upsertSharedClassificationModel(array $model): array {
    $companySlug = (string) (getCompanySlug() ?? '');
    $name = sanitizeClassificationModelName($model['name'] ?? '');
    $emitter = normalizeSupplierPartyValue($model['emitter'] ?? '');
    $acquirer = normalizeSupplierPartyValue($model['acquirer'] ?? '');
    $docType = normalizeDocTypeValue($model['doc_type'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Indique um nome para o modelo.');
    }
    if ($emitter === '' || $acquirer === '') {
        throw new RuntimeException('Modelo sem emitente/adquirente válido.');
    }

    $models = loadAllSharedClassificationModels();
    $normalized = [
        'company_slug' => $companySlug,
        'name' => $name,
        'emitter' => $emitter,
        'acquirer' => $acquirer,
        'doc_type' => $docType,
        'scope_key' => buildClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType),
        'rates' => sanitizeAccountInput(is_array($model['rates'] ?? null) ? $model['rates'] : []),
        'cost_centers' => sanitizeCostCenterValues($model['cost_centers'] ?? []),
        'cost_center_breakdowns' => sanitizeCostCenterBreakdownValues($model['cost_center_breakdowns'] ?? []),
        'total_account' => trim((string) ($model['total_account'] ?? '')),
        'ignore_detected_rates' => '1',
        'updated_at' => date('c'),
    ];

    $updated = false;
    foreach ($models as $index => $existing) {
        if (
            strcasecmp((string) ($existing['name'] ?? ''), $name) === 0
            && (string) ($existing['scope_key'] ?? '') === $normalized['scope_key']
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

function deleteSharedClassificationModel($emitter, $acquirer, $docType, $name): bool {
    $companySlug = (string) (getCompanySlug() ?? '');
    $scopeKey = buildClassificationModelScopeKey($companySlug, $emitter, $acquirer, $docType);
    $modelName = sanitizeClassificationModelName($name);
    if ($scopeKey === '|||' || $modelName === '') {
        return false;
    }

    $models = loadAllSharedClassificationModels();
    $filtered = [];
    $deleted = false;

    foreach ($models as $model) {
        $sameScope = (string) ($model['scope_key'] ?? '') === $scopeKey;
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
    $stmt = $pdo->prepare('SELECT id, filename, line_items, field_A, field_B FROM accounting_imports WHERE id = ?');
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

    $respondWithLines = static function(array $items) use ($row, $skipOcr, $emitterRaw, $acquirerRaw, $docTypeRaw) {
        header('Content-Type: application/json');
        echo json_encode([
            'lines' => $items,
            'skip_ocr' => $skipOcr,
            'emitter' => $emitterRaw,
            'emitter_display' => $row['field_A'] ?? '',
            'acquirer' => $acquirerRaw,
            'doc_type' => $docTypeRaw,
            'csrf_token' => generateCsrfToken()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    };
    // If line items already stored, return them without invoking OCR again.
    if (!empty($row['line_items'])) {
        $items = json_decode($row['line_items'], true);
        if (!is_array($items)) {
            $items = [];
        }
        $items = applySupplierDocumentMappings($pdo, $row['field_A'] ?? '', $row['field_B'] ?? '', $items);
        $respondWithLines($items);
    }

    $path = dirname(__DIR__) . '/' . $row['filename'];
    $ocrProvider = getSetting('ocr_provider', 'tesseract');

    if ($ocrProvider !== 'textract') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'OCR provider inválido']);
        exit;
    }

    if ($skipOcr) {
        $respondWithLines([]);
    }

    try {
        $items = parseInvoiceLineTextract($path);
        if (!is_array($items)) {
            $items = [];
        }
        $items = applySupplierDocumentMappings($pdo, $row['field_A'] ?? '', $row['field_B'] ?? '', $items);
        // Store OCR result so subsequent requests can reuse it.
        $stmt = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
        $stmt->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $id]);
        $respondWithLines($items);
    } catch (Throwable $e) {
        logOcrMessage('Textract OCR error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Erro no OCR: ' . $e->getMessage()]);
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
    $stmt = $pdo->prepare(
        'SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
    );
    $stmt->execute([$a, $b, $d]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $classificationAccounts = normalizeAccountingAccounts($row['account'] ?? '');
    $classificationMetadata = normalizeAccountingMetadata($row['account'] ?? '');
    if (($classificationMetadata['ignore_detected_rates'] ?? '0') === '1') {
        $classificationAccounts = filterVisibleAccountingRates($classificationAccounts);
    }

    $rowAccounts = normalizeAccountingAccounts(null);
    $rowMetadata = normalizeAccountingMetadata(null);
    $rowCostCenters = buildEmptyCostCenterMap();
    $originalSnapshot = [];
    $summaries = [];
    $rowRequirements = [];
    if ($id !== '') {
        $stmtRow = $pdo->prepare('SELECT * FROM accounting_imports WHERE id = ? LIMIT 1');
        $stmtRow->execute([$id]);
        $importRow = $stmtRow->fetch(PDO::FETCH_ASSOC) ?: [];
        $rowAccounts = normalizeAccountingAccounts($importRow['account'] ?? '');
        $rowMetadata = normalizeAccountingMetadata($importRow['account'] ?? '');
        if (($rowMetadata['ignore_detected_rates'] ?? '0') === '1') {
            $rowAccounts = filterVisibleAccountingRates($rowAccounts);
        }
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
    }

    if (empty($originalSnapshot) && !empty($summaries)) {
        $originalSnapshot = mergeOriginalRateSnapshot([], $summaries);
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
        'total_account' => $classificationMetadata['total_account'] ?? '',
        'row_total_account' => $rowMetadata['total_account'] ?? '',
        'ignore_detected_rates' => $rowMetadata['ignore_detected_rates'] ?? '0',
        'classification_model_name' => $rowMetadata['classification_model_name'] ?? '',
        'classification_models' => loadSharedClassificationModels($a, $b, $d),
        'original_rates' => $originalSnapshot,
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

    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }

    $idValue = isset($_POST['id']) && is_numeric($_POST['id']) ? (int) $_POST['id'] : 0;
    requireCtbClassificationPermission($pdo, $idValue > 0 ? $idValue : null);

    if (!deleteSharedClassificationModel($a, $b, $d, $name)) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Modelo não encontrado.',
            'classification_models' => loadSharedClassificationModels($a, $b, $d),
            'csrf_token' => generateCsrfToken(true)
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'classification_models' => loadSharedClassificationModels($a, $b, $d),
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
        $ignoreDetectedRates = trim((string) ($_POST['ignore_detected_rates'] ?? '0'));
        $submittedMetadata = sanitizeAccountingMetadata([
            'total_account' => $_POST['total_account'] ?? '',
            'ignore_detected_rates' => ($ignoreDetectedRates === '1' || $selectedModelName !== '' || $saveModelName !== '') ? '1' : '0',
            'classification_model_name' => $saveModelName !== '' ? $saveModelName : $selectedModelName,
        ]);

        $stmtExisting = $pdo->prepare(
            'SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
        );
        $stmtExisting->execute([$a, $b, $d]);
        $existingClassRaw = $stmtExisting->fetchColumn() ?: '';
        $existingClass = normalizeAccountingAccounts($existingClassRaw);
        $existingClassMetadata = normalizeAccountingMetadata($existingClassRaw);

        $stmtRow = $pdo->prepare('SELECT * FROM accounting_imports WHERE id = ? LIMIT 1');
        $stmtRow->execute([$id]);
        $importRow = $stmtRow->fetch(PDO::FETCH_ASSOC);
        if (!$importRow) {
            throw new RuntimeException('Importação inexistente');
        }
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
        $classAccounts = mergeAccountingAccounts($existingClass, $submittedRates);

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
        $serializedClass = serializeAccountingAccounts($classAccounts, $classMetadata, $existingClassMetadata);
        $serializedCostCenters = serializeCostCenters($costCentersData, $costCenterBreakdownsData);
        $serializedOriginal = serializeAccountingAccounts($existingOriginal);
        $responseRowRates = (($submittedMetadata['ignore_detected_rates'] ?? '0') === '1')
            ? filterVisibleAccountingRates($rowAccounts)
            : $rowAccounts;

        $savedModel = null;
        if ($saveModelName !== '') {
            $savedModel = upsertSharedClassificationModel([
                'name' => $saveModelName,
                'emitter' => $a,
                'acquirer' => $b,
                'doc_type' => $d,
                'rates' => $rowAccounts,
                'cost_centers' => $costCentersData,
                'cost_center_breakdowns' => $costCenterBreakdownsData,
                'total_account' => $submittedMetadata['total_account'] ?? '',
            ]);
        }

        $stmt = $pdo->prepare('UPDATE accounting_imports SET account = ?, cost_center = ?, account_original = ? WHERE id = ?');
        $stmt->execute([$serializedRow, $serializedCostCenters, $serializedOriginal, $id]);

        $stmt2 = $pdo->prepare(
            'INSERT INTO accounting_classifications (emitter, acquirer, doc_type, account) '
            . 'VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE account = VALUES(account)'
        );
        $stmt2->execute([$a, $b, $d, $serializedClass]);
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
            'manual_review_required' => $submittedMetadata['manual_review_required'] ?? '0',
            'ignore_detected_rates' => $submittedMetadata['ignore_detected_rates'] ?? '0',
            'classification_model_name' => $submittedMetadata['classification_model_name'] ?? '',
            'classification_models' => loadSharedClassificationModels($a, $b, $d),
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
