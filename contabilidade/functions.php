<?php
// Error display is no longer forced on here: it follows the `debug_mode` setting
// and is applied by startSession(), which every page calls right after this
// include. Printing errors unconditionally corrupted the JSON responses of the
// accounting AJAX endpoints and leaked stack traces in production.
require_once __DIR__ . '/../functions.php';

use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Append an OCR-related message to a log file.
 *
 * @param string $message Message to append.
 * @return void
 */
function logOcrMessage(string $message): void {
    $logFile = __DIR__ . '/../data/ocr.log';
    rotateLogFileIfNeeded($logFile);
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $logFile);
}

/**
 * Append a message related to ERP webservice synchronisation to a log file.
 *
 * @param string $message Message to append.
 * @return void
 */
function logErpMessage(string $message): void {
    $logFile = __DIR__ . '/../data/erp.log';
    rotateLogFileIfNeeded($logFile);
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $logFile);
}

/**
 * TTL (seconds) for cached ERP suggestion/lookup responses. Overridable via the
 * `erp_suggestion_cache_ttl` setting; defaults to 6 hours (the underlying ERP
 * reference data — rubric links, plano de contas — changes rarely).
 *
 * @return int
 */
function erpSuggestionCacheTtlSeconds(): int {
    $configured = (int) getSetting('erp_suggestion_cache_ttl', '0');
    return $configured > 0 ? $configured : 21600;
}

/**
 * Whether the per-tenant ERP suggestion cache table exists. Memoised per request
 * so the existence probe (SHOW TABLES) runs at most once.
 *
 * @return bool
 */
function erpSuggestionCacheAvailable(): bool {
    if (!array_key_exists('erp_suggestion_cache_available', $GLOBALS)) {
        try {
            $GLOBALS['erp_suggestion_cache_available'] = hasTable('erp_suggestion_cache');
        } catch (Throwable $e) {
            $GLOBALS['erp_suggestion_cache_available'] = false;
        }
    }
    return (bool) $GLOBALS['erp_suggestion_cache_available'];
}

/**
 * Read a cached ERP suggestion response. Returns null on miss/expiry/error so the
 * caller falls back to a live ERP call. Cache failures must never break a request.
 *
 * @param string $key
 * @return array|null
 */
function erpSuggestionCacheGet(string $key): ?array {
    if (!erpSuggestionCacheAvailable()) {
        return null;
    }
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare('SELECT response_json, created_at FROM erp_suggestion_cache WHERE cache_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $age = time() - (int) ($row['created_at'] ?? 0);
        if ($age < 0 || $age > erpSuggestionCacheTtlSeconds()) {
            return null;
        }
        $decoded = json_decode((string) ($row['response_json'] ?? ''), true);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Store an ERP suggestion response in the cache (upsert). No-op on any failure.
 *
 * @param string $key
 * @param array $value
 * @return void
 */
function erpSuggestionCacheSet(string $key, array $value): void {
    if (!erpSuggestionCacheAvailable()) {
        return;
    }
    try {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $pdo = getPDO();
        $stmt = $pdo->prepare(
            'INSERT INTO erp_suggestion_cache (cache_key, response_json, created_at) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE response_json = VALUES(response_json), created_at = VALUES(created_at)'
        );
        $stmt->execute([$key, $json, time()]);
    } catch (Throwable $e) {
        // Cache writes are best-effort; never surface to the request.
    }
}

/**
 * Attempt to extract a VAT/NIF number from an arbitrary string.
 *
 * @param string $value Raw value that may contain a VAT number.
 * @return string Normalised VAT number or empty string when none is found.
 */
function extractVatNumber(string $value): string {
    $value = strtoupper(trim($value));
    if ($value === '') {
        return '';
    }

    if (preg_match('/(\d{9})/', $value, $matches)) {
        return $matches[1];
    }

    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === '') {
        return '';
    }

    return substr($digits, 0, 30);
}

function normalizeAccountingMatchVat(string $value): string {
    $vat = extractVatNumber($value);
    if ($vat !== '') {
        return $vat;
    }
    return preg_replace('/\D+/', '', strtoupper(trim($value)));
}

function normalizeAccountingMatchToken(string $value): string {
    $value = strtoupper(trim($value));
    if ($value === '') {
        return '';
    }
    return preg_replace('/\s+/', '', $value);
}

function normalizeAccountingDocumentNumber(string $value): string {
    $value = normalizeAccountingMatchToken($value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/^([A-Z]{1,4})\1(?=[A-Z0-9\/-])/', '$1', $value);
    $value = preg_replace('/^([A-Z]{1,4})([A-Z]{1,4})\//', '$2/', $value);
    return $value;
}

function normalizeErpEntityTypeValue(string $value): string {
    $normalized = strtolower(trim($value));
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/[^a-z0-9]+/i', '', $normalized);
    if (in_array($normalized, ['acquirer', 'cliente', 'client', 'customer', 'comprador', 'adquirente'], true)) {
        return 'acquirer';
    }
    if (in_array($normalized, ['emitter', 'emitente', 'supplier', 'fornecedor', 'vendor', 'seller'], true)) {
        return 'emitter';
    }

    return '';
}

function normalizeAccountingMatchDate(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $patterns = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'Y/m/d'];
    foreach ($patterns as $pattern) {
        $date = DateTime::createFromFormat($pattern, $value);
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

function normalizeAccountingMatchAmount($value): ?float {
    $amount = resolveAccountingLineAmount($value);
    if ($amount === null) {
        return null;
    }
    return round(abs($amount), 2);
}

function accountingAmountsMatch(?float $left, ?float $right, float $tolerance = 0.02): bool {
    if ($left === null || $right === null) {
        return false;
    }
    return abs($left - $right) <= $tolerance;
}

function accountingImportsEfaturaLinkReady(): bool {
    return hasTable('accounting_imports')
        && hasTable('efatura_documents')
        && hasColumn('accounting_imports', 'efatura_document_id');
}

function buildAccountingMatchSqlExpression(string $column, bool $stripNumericPunctuation = false): string {
    $expression = 'REPLACE(UPPER(TRIM(' . $column . ')), \' \', \'\')';
    if ($stripNumericPunctuation) {
        $expression = 'REPLACE(REPLACE(' . $expression . ', \'-\', \'\'), \'.\', \'\')';
    }
    return $expression;
}

function extractAccountingImportAcquirerVat(array $row): string {
    $candidates = [
        (string) ($row['field_C'] ?? ''),
        (string) ($row['field_B'] ?? ''),
        (string) ($row['B'] ?? ''),
        (string) ($row['C'] ?? ''),
    ];
    foreach ($candidates as $candidate) {
        $vat = extractVatNumber($candidate);
        if ($vat !== '') {
            return $vat;
        }
    }
    return '';
}

function findMatchingEfaturaDocumentForImportRow(PDO $pdo, array $importRow): ?array {
    if (!accountingImportsEfaturaLinkReady()) {
        return null;
    }

    $issuerVat = normalizeAccountingMatchVat((string) ($importRow['field_A'] ?? $importRow['A'] ?? ''));
    $acquirerVat = extractAccountingImportAcquirerVat($importRow);
    $invoiceDate = normalizeAccountingMatchDate((string) ($importRow['field_F'] ?? $importRow['F'] ?? ''));
    $invoiceNo = normalizeAccountingDocumentNumber((string) ($importRow['field_G'] ?? $importRow['G'] ?? ''));
    $atcud = normalizeAccountingMatchToken((string) ($importRow['field_H'] ?? $importRow['H'] ?? ''));
    $sourceHash = normalizeAccountingMatchToken((string) ($importRow['field_R'] ?? $importRow['R'] ?? ''));

    if ($sourceHash !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, "source_hash" AS match_method
             FROM efatura_documents
             WHERE ' . buildAccountingMatchSqlExpression('source_hash') . ' = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$sourceHash]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($match) {
            return $match;
        }
    }

    if ($issuerVat !== '' && $atcud !== '') {
        $sql = 'SELECT id, "atcud" AS match_method
                FROM efatura_documents
                WHERE ' . buildAccountingMatchSqlExpression('issuer_vat', true) . ' = ?
                  AND ' . buildAccountingMatchSqlExpression('atcud') . ' = ?';
        $params = [$issuerVat, $atcud];
        if ($invoiceDate !== '') {
            $sql .= ' AND invoice_date = ?';
            $params[] = $invoiceDate;
        }
        if ($acquirerVat !== '') {
            $sql .= ' AND ' . buildAccountingMatchSqlExpression('customer_vat', true) . ' = ?';
            $params[] = $acquirerVat;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $match = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($match) {
            return $match;
        }
    }

    if ($issuerVat !== '' && $invoiceNo !== '' && $invoiceDate !== '') {
        $sql = 'SELECT id, "document" AS match_method
                FROM efatura_documents
                WHERE ' . buildAccountingMatchSqlExpression('issuer_vat', true) . ' = ?
                  AND ' . buildAccountingMatchSqlExpression('invoice_no') . ' = ?
                  AND invoice_date = ?';
        $params = [$issuerVat, $invoiceNo, $invoiceDate];
        if ($acquirerVat !== '') {
            $sql .= ' AND ' . buildAccountingMatchSqlExpression('customer_vat', true) . ' = ?';
            $params[] = $acquirerVat;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $match = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($match) {
            return $match;
        }
    }

    return null;
}

function findMatchingAccountingImportForEfaturaDocument(PDO $pdo, array $documentRow): ?array {
    if (!accountingImportsEfaturaLinkReady()) {
        return null;
    }

    $documentId = (int) ($documentRow['id'] ?? 0);
    if ($documentId <= 0) {
        return null;
    }

    $sourceHash = normalizeAccountingMatchToken((string) ($documentRow['source_hash'] ?? ''));
    $issuerVat = normalizeAccountingMatchVat((string) ($documentRow['issuer_vat'] ?? ''));
    $customerVat = normalizeAccountingMatchVat((string) ($documentRow['customer_vat'] ?? ''));
    $invoiceDate = normalizeAccountingMatchDate((string) ($documentRow['invoice_date'] ?? ''));
    $invoiceNo = normalizeAccountingDocumentNumber((string) ($documentRow['invoice_no'] ?? ''));
    $atcud = normalizeAccountingMatchToken((string) ($documentRow['atcud'] ?? ''));
    $grossTotal = normalizeAccountingMatchAmount($documentRow['gross_total'] ?? null);
    $taxPayable = normalizeAccountingMatchAmount($documentRow['tax_payable'] ?? null);

    $linkedStmt = $pdo->prepare('SELECT id, "linked" AS match_method FROM accounting_imports WHERE efatura_document_id = ? LIMIT 1');
    $linkedStmt->execute([$documentId]);
    $linked = $linkedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($linked) {
        return $linked;
    }

    if ($sourceHash !== '') {
        $stmt = $pdo->prepare(
            'SELECT id, "source_hash" AS match_method
             FROM accounting_imports
             WHERE import_type = 1
               AND (efatura_document_id IS NULL OR efatura_document_id = ?)
               AND ' . buildAccountingMatchSqlExpression('field_R') . ' = ?
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$documentId, $sourceHash]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($match) {
            return $match;
        }
    }

    if ($issuerVat !== '' && $atcud !== '') {
        $sql = 'SELECT id, "atcud" AS match_method
                FROM accounting_imports
                WHERE import_type = 1
                  AND (efatura_document_id IS NULL OR efatura_document_id = ?)
                  AND ' . buildAccountingMatchSqlExpression('field_A', true) . ' = ?
                  AND ' . buildAccountingMatchSqlExpression('field_H') . ' = ?';
        $params = [$documentId, $issuerVat, $atcud];
        if ($invoiceDate !== '') {
            $sql .= ' AND field_F = ?';
            $params[] = $invoiceDate;
        }
        if ($customerVat !== '') {
            $sql .= ' AND ((' . buildAccountingMatchSqlExpression('field_B', true) . ' = \'\' AND ' . buildAccountingMatchSqlExpression('field_C', true) . ' = \'\')'
                . ' OR ' . buildAccountingMatchSqlExpression('field_B', true) . ' = ?'
                . ' OR ' . buildAccountingMatchSqlExpression('field_C', true) . ' = ?)';
            $params[] = $customerVat;
            $params[] = $customerVat;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $match = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($match) {
            return $match;
        }
    }

    if ($issuerVat !== '' && $invoiceNo !== '' && $invoiceDate !== '') {
        $sql = 'SELECT id, "document" AS match_method
                FROM accounting_imports
                WHERE import_type = 1
                  AND (efatura_document_id IS NULL OR efatura_document_id = ?)
                  AND ' . buildAccountingMatchSqlExpression('field_A', true) . ' = ?
                  AND ' . buildAccountingMatchSqlExpression('field_G') . ' = ?
                  AND field_F = ?';
        $params = [$documentId, $issuerVat, $invoiceNo, $invoiceDate];
        if ($customerVat !== '') {
            $sql .= ' AND ((' . buildAccountingMatchSqlExpression('field_B', true) . ' = \'\' AND ' . buildAccountingMatchSqlExpression('field_C', true) . ' = \'\')'
                . ' OR ' . buildAccountingMatchSqlExpression('field_B', true) . ' = ?'
                . ' OR ' . buildAccountingMatchSqlExpression('field_C', true) . ' = ?)';
            $params[] = $customerVat;
            $params[] = $customerVat;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $match = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($match) {
            return $match;
        }

        $fallbackStmt = $pdo->prepare(
            'SELECT id, field_G
             FROM accounting_imports
             WHERE import_type = 1
               AND (efatura_document_id IS NULL OR efatura_document_id = ?)
               AND ' . buildAccountingMatchSqlExpression('field_A', true) . ' = ?
               AND field_F = ?
             ORDER BY id DESC'
        );
        $fallbackStmt->execute([$documentId, $issuerVat, $invoiceDate]);
        foreach ($fallbackStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            if (normalizeAccountingDocumentNumber((string) ($candidate['field_G'] ?? '')) === $invoiceNo) {
                return [
                    'id' => (int) ($candidate['id'] ?? 0),
                    'match_method' => 'document_normalized',
                ];
            }
        }
    }

    if ($issuerVat !== '' && $invoiceDate !== '' && $grossTotal !== null) {
        $amountStmt = $pdo->prepare(
            'SELECT id, field_G, field_N, field_O
             FROM accounting_imports
             WHERE import_type = 1
               AND (efatura_document_id IS NULL OR efatura_document_id = ?)
               AND ' . buildAccountingMatchSqlExpression('field_A', true) . ' = ?
               AND field_F = ?
             ORDER BY id DESC'
        );
        $amountStmt->execute([$documentId, $issuerVat, $invoiceDate]);
        foreach ($amountStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
            $candidateGross = normalizeAccountingMatchAmount(computeDocumentTotalAmount($candidate));
            $candidateTax = normalizeAccountingMatchAmount($candidate['field_N'] ?? null);
            if (!accountingAmountsMatch($grossTotal, $candidateGross)) {
                continue;
            }
            if ($taxPayable !== null && $candidateTax !== null && !accountingAmountsMatch($taxPayable, $candidateTax)) {
                continue;
            }
            return [
                'id' => (int) ($candidate['id'] ?? 0),
                'match_method' => 'document_amount',
            ];
        }
    }

    return null;
}

function linkAccountingImportToEfaturaDocument(PDO $pdo, int $importId, int $documentId, string $matchMethod = ''): void {
    if (!accountingImportsEfaturaLinkReady() || $importId <= 0 || $documentId <= 0) {
        return;
    }

    $fields = ['efatura_document_id = ?'];
    $params = [$documentId];
    if (hasColumn('accounting_imports', 'efatura_match_method')) {
        $fields[] = 'efatura_match_method = ?';
        $params[] = trim($matchMethod);
    }
    if (hasColumn('accounting_imports', 'efatura_matched_at')) {
        $fields[] = 'efatura_matched_at = NOW()';
    }
    $params[] = $importId;

    $sql = 'UPDATE accounting_imports SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function reconcileAccountingImportWithEfaturaDocument(PDO $pdo, int $importId, ?array $importRow = null): ?array {
    if (!accountingImportsEfaturaLinkReady() || $importId <= 0) {
        return null;
    }
    if ($importRow === null) {
        $stmt = $pdo->prepare('SELECT * FROM accounting_imports WHERE id = ? LIMIT 1');
        $stmt->execute([$importId]);
        $importRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!is_array($importRow)) {
        return null;
    }
    $match = findMatchingEfaturaDocumentForImportRow($pdo, $importRow);
    if ($match && !empty($match['id'])) {
        linkAccountingImportToEfaturaDocument($pdo, $importId, (int) $match['id'], (string) ($match['match_method'] ?? ''));
    }
    return $match;
}

function reconcileEfaturaDocumentWithAccountingImport(PDO $pdo, int $documentId, ?array $documentRow = null): ?array {
    if (!accountingImportsEfaturaLinkReady() || $documentId <= 0) {
        return null;
    }
    if ($documentRow === null) {
        $stmt = $pdo->prepare('SELECT * FROM efatura_documents WHERE id = ? LIMIT 1');
        $stmt->execute([$documentId]);
        $documentRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!is_array($documentRow)) {
        return null;
    }
    $match = findMatchingAccountingImportForEfaturaDocument($pdo, $documentRow);
    if ($match && !empty($match['id'])) {
        linkAccountingImportToEfaturaDocument($pdo, (int) $match['id'], $documentId, (string) ($match['match_method'] ?? ''));
    }
    return $match;
}

/**
 * Resolve ERP company identifier configured in settings.
 *
 * Source:
 * - Definições > Módulos > Contabilidade (`accounting_base_company`)
 */
function getErpDefaultCompanyIdentifier(): string {
    $company = trim((string) getSetting('accounting_base_company', ''));
    if ($company !== '') {
        return $company;
    }
    return '';
}

/**
 * Normalize ERP database identifier with fallback to configured company.
 */
function resolveErpDatabaseIdentifier(string $database = ''): string {
    $database = trim($database);
    if ($database !== '') {
        return $database;
    }
    return getErpDefaultCompanyIdentifier();
}

/**
 * Build company-aware ERP query params.
 *
 * Always attempts to send `EMP` and, when available, also `db` for
 * compatibility with legacy endpoints.
 */
function buildErpCompanyQueryParams(string $database = ''): array {
    $database = normalizeAccountingEntityDatabaseKey(trim($database));
    if ($database === '') {
        $database = normalizeAccountingEntityDatabaseKey(getErpDefaultCompanyIdentifier());
    }

    $params = [];
    if ($database !== '') {
        $params['db'] = $database;
        $params['bd'] = $database;
        $params['EMP'] = $database;
    }
    return $params;
}

/**
 * Append query parameters to an URL preserving existing query string.
 */
function appendQueryParamsToUrl(string $url, array $params): string {
    if ($url === '' || empty($params)) {
        return $url;
    }
    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Build a request URL for the ERP client endpoint.
 *
 * @param string $baseUrl Base URL stored in the settings.
 * @param string $nif     VAT number to query.
 * @return string Fully qualified URL.
 */
function buildErpClientEndpoint(string $baseUrl, string $nif, string $database = ''): string {
    $url = trim($baseUrl);
    if ($url === '') {
        return '';
    }

    $encodedNif = urlencode($nif);
    $companyParams = buildErpCompanyQueryParams($database);

    $placeholderPatterns = [
        '/\{nif\}/i',
        '/\{vat\}/i',
        '/\{numero_contribuinte\}/i',
        '/\{contribuinte\}/i',
    ];

    foreach ($placeholderPatterns as $pattern) {
        if (preg_match($pattern, $url)) {
            $resolved = preg_replace($pattern, $encodedNif, $url);
            return appendQueryParamsToUrl((string) $resolved, $companyParams);
        }
    }

    if (strpos($url, '%s') !== false) {
        return appendQueryParamsToUrl(sprintf($url, $encodedNif), $companyParams);
    }

    foreach (['nif', 'vat', 'contrib', 'q'] as $queryKeyword) {
        if (preg_match('/(?:[?&][^#]*' . $queryKeyword . '[^=]*)=$/i', $url)) {
            return appendQueryParamsToUrl($url . $encodedNif, $companyParams);
        }
    }

    if (substr($url, -1) === '?' || substr($url, -1) === '&') {
        return appendQueryParamsToUrl($url . 'nif=' . $encodedNif, $companyParams);
    }

    $parsedUrl = @parse_url($url);
    if (is_array($parsedUrl)) {
        $host = strtolower($parsedUrl['host'] ?? '');
        if ($host !== '' && strpos($host, 'erpsinc') !== false) {
            $path = $parsedUrl['path'] ?? '';
            $query = $parsedUrl['query'] ?? '';

            $baseWithoutQuery = $url;
            if ($query !== '') {
                $questionPos = strpos($url, '?');
                if ($questionPos !== false) {
                    $baseWithoutQuery = substr($url, 0, $questionPos);
                }
            }
            $baseWithoutQuery = rtrim($baseWithoutQuery, '/');

            $normalizedPath = rtrim($path, '/');

            if ($normalizedPath === '' || $normalizedPath === '/' || preg_match('#/v\d+\.\d+\.\d+$#', $normalizedPath)) {
                $defaultQuery = [
                    'limit' => 1,
                    'offset' => 0,
                    'q' => $nif,
                    'searchField' => 'strNumContrib',
                ];
                $defaultQuery = array_merge($defaultQuery, $companyParams);

                return $baseWithoutQuery . '/clientes?' . http_build_query($defaultQuery, '', '&', PHP_QUERY_RFC3986);
            }

            if (substr($normalizedPath, -strlen('/clientes')) === '/clientes') {
                $queryData = [];
                if ($query !== '') {
                    parse_str($query, $queryData);
                }

                $finalQuery = [];

                if (array_key_exists('limit', $queryData)) {
                    $finalQuery['limit'] = $queryData['limit'];
                    unset($queryData['limit']);
                } else {
                    $finalQuery['limit'] = 1;
                }

                if (array_key_exists('offset', $queryData)) {
                    $finalQuery['offset'] = $queryData['offset'];
                    unset($queryData['offset']);
                } else {
                    $finalQuery['offset'] = 0;
                }

                $finalQuery['q'] = $nif;

                if (array_key_exists('searchField', $queryData)) {
                    $finalQuery['searchField'] = $queryData['searchField'];
                    unset($queryData['searchField']);
                } else {
                    $finalQuery['searchField'] = 'strNumContrib';
                }

                foreach ($queryData as $key => $value) {
                    $finalQuery[$key] = $value;
                }
                $finalQuery = array_merge($finalQuery, $companyParams);

                return $baseWithoutQuery . '?' . http_build_query($finalQuery, '', '&', PHP_QUERY_RFC3986);
            }
        }
    }

    $base = rtrim($url, '/');
    $separator = strpos($base, '?') === false ? '?' : '&';
    return appendQueryParamsToUrl($base . $separator . 'nif=' . $encodedNif, $companyParams);

}

/**
 * Build a request URL for the ERP suppliers endpoint (/fornecedores).
 *
 * @param string $baseUrl Base URL stored in the settings.
 * @param string $nif     VAT number to query.
 * @return string Fully qualified URL.
 */
function buildErpSupplierEndpoint(string $baseUrl, string $nif, string $database = ''): string {
    $url = trim($baseUrl);
    if ($url === '') {
        return '';
    }

    $database = trim($database);
    $companyParams = buildErpCompanyQueryParams($database);
    $encodedNif = urlencode($nif);
    $parsedUrl = @parse_url($url);

    $defaultQuery = [
        'limit' => 1,
        'offset' => 0,
        'q' => $nif,
        'searchField' => 'strNumContrib',
    ];
    $defaultQuery = array_merge($defaultQuery, $companyParams);

    if (is_array($parsedUrl)) {
        $host = strtolower($parsedUrl['host'] ?? '');
        $path = $parsedUrl['path'] ?? '';
        $query = $parsedUrl['query'] ?? '';

        $baseWithoutQuery = $url;
        if ($query !== '') {
            $questionPos = strpos($url, '?');
            if ($questionPos !== false) {
                $baseWithoutQuery = substr($url, 0, $questionPos);
            }
        }
        $baseWithoutQuery = rtrim($baseWithoutQuery, '/');

        $normalizedPath = rtrim($path, '/');

        $isErpSinc = $host !== '' && strpos($host, 'erpsinc') !== false;
        if ($isErpSinc) {
            if ($normalizedPath === '' || $normalizedPath === '/' || preg_match('#/v\d+\.\d+\.\d+$#', $normalizedPath)) {
                return $baseWithoutQuery . '/fornecedores?' . http_build_query($defaultQuery, '', '&', PHP_QUERY_RFC3986);
            }

            if (substr($normalizedPath, -strlen('/clientes')) === '/clientes' || substr($normalizedPath, -strlen('/fornecedores')) === '/fornecedores') {
                $queryData = [];
                if ($query !== '') {
                    parse_str($query, $queryData);
                }

                $finalQuery = [];
                $finalQuery['limit'] = array_key_exists('limit', $queryData) ? $queryData['limit'] : 1;
                $finalQuery['offset'] = array_key_exists('offset', $queryData) ? $queryData['offset'] : 0;
                $finalQuery['q'] = $nif;
                $finalQuery['searchField'] = array_key_exists('searchField', $queryData) ? $queryData['searchField'] : 'strNumContrib';

                foreach ($queryData as $key => $value) {
                    if (!array_key_exists($key, ['limit' => true, 'offset' => true, 'q' => true, 'searchField' => true])) {
                        $finalQuery[$key] = $value;
                    }
                }
                $finalQuery = array_merge($finalQuery, $companyParams);

                $basePrefix = $baseWithoutQuery;
                if (substr($basePrefix, -strlen('/clientes')) === '/clientes') {
                    $basePrefix = substr($basePrefix, 0, -strlen('/clientes')) . '/fornecedores';
                } elseif (substr($basePrefix, -strlen('/fornecedores')) !== '/fornecedores') {
                    $basePrefix .= '/fornecedores';
                }

                return rtrim($basePrefix, '/') . '?' . http_build_query($finalQuery, '', '&', PHP_QUERY_RFC3986);
            }
        }
    }

    $base = rtrim($url, '/');
    $separator = strpos($base, '?') === false ? '?' : '&';
    return $base . '/fornecedores' . $separator . http_build_query($defaultQuery, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Prepare an URL string for logging by masking sensitive query parameters.
 *
 * @param string $url Original URL.
 * @return string URL safe to expose in logs.
 */
function sanitizeUrlForLog(string $url): string {
    if ($url === '') {
        return '';
    }

    $sanitized = preg_replace_callback(
        '/([?&])([^=&#]+)=([^&#]*)/',
        function (array $matches): string {
            $param = strtolower($matches[2]);
            foreach (['token', 'key', 'secret', 'password', 'auth'] as $keyword) {
                if (strpos($param, $keyword) !== false) {
                    return $matches[1] . $matches[2] . '=***';
                }
            }

            return $matches[0];
        },
        $url
    );

    if ($sanitized === null) {
        $sanitized = $url;
    }

    $sanitized = preg_replace('/^([a-z][a-z0-9+.-]*:\/\/[^:@\/]+):[^@\/]*@/i', '$1:***@', $sanitized);
    if ($sanitized === null) {
        return $url;
    }

    return $sanitized;
}

/**
 * Combine a base ERP webservice URL with an additional path segment.
 *
 * This helper preserves any query string or fragment configured in the base URL
 * while ensuring the path is appended in the correct position.  It avoids
 * malformed URLs such as `...?tenant=foo/contabilidade/listDBemp` that occur
 * when simply concatenating strings containing query parameters.
 *
 * @param string $baseUrl Base URL stored in the application settings.
 * @param string $path    Path that should be appended to the base URL.
 * @return string Fully qualified URL.
 */
function buildErpEndpointFromBase(string $baseUrl, string $path): string {
    $normalizedPath = '/' . ltrim($path, '/');

    $parsed = @parse_url($baseUrl);
    if (is_array($parsed) && isset($parsed['scheme'], $parsed['host'])) {
        $scheme = $parsed['scheme'];
        $host = $parsed['host'];

        $userInfo = '';
        if (isset($parsed['user'])) {
            $userInfo = $parsed['user'];
            if (isset($parsed['pass'])) {
                $userInfo .= ':' . $parsed['pass'];
            }
            $userInfo .= '@';
        }

        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        $basePath = $parsed['path'] ?? '';
        $basePath = rtrim($basePath, '/');
        $combinedPath = $basePath === '' ? $normalizedPath : $basePath . $normalizedPath;
        if ($combinedPath === '' || $combinedPath[0] !== '/') {
            $combinedPath = '/' . ltrim($combinedPath, '/');
        }

        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $scheme . '://' . $userInfo . $host . $port . $combinedPath . $query . $fragment;
    }

    $trimmedBase = rtrim($baseUrl, '/');
    if ($trimmedBase === '') {
        return $normalizedPath;
    }

    return $trimmedBase . $normalizedPath;
}

/**
 * Normalise the ERP response structure and extract relevant entity data.
 *
 * @param array  $payload Raw payload returned by the ERP webservice.
 * @param string $nif     VAT number requested.
 * @return array|null Associative array with the extracted data or null if none was found.
 */
function parseErpEntityPayload(array $payload, string $nif, string $preferredEntityType = ''): ?array {
    $sourceLabel = 'Webservice ERP-SINC';
    $preferredEntityType = normalizeErpEntityTypeValue($preferredEntityType);

    if (isset($payload['success']) && $payload['success'] === false) {
        $message = isset($payload['message']) ? (string) $payload['message'] : (string) ($payload['error'] ?? 'Resposta sem sucesso');
        logErpMessage($sourceLabel . ' devolveu erro: ' . $message);
        return null;
    }

    if (isset($payload['error']) && !is_array($payload['error'])) {
        logErpMessage($sourceLabel . ' devolveu erro: ' . (string) $payload['error']);
        return null;
    }

    $candidates = [];
    $candidates[] = $payload;


    $candidateKeyMap = array_fill_keys(['data', 'cliente', 'clientes', 'result', 'results', 'record', 'records', 'aadata'], true);


    foreach ($payload as $payloadKey => $value) {
        if (!is_string($payloadKey)) {
            continue;
        }

        $normalizedKey = strtolower($payloadKey);
        if (!isset($candidateKeyMap[$normalizedKey])) {
            continue;
        }

        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if ($isList) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $candidates[] = $item;
                    }
                }
            } else {
                $candidates[] = $value;
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $candidates[] = $item;
                    }
                }
            }
        }
    }

    for ($i = 0, $total = count($candidates); $i < $total; $i++) {
        $candidate = $candidates[$i];
        if (!is_array($candidate)) {
            continue;
        }

        $candidateNif = '';

        $normalisedCandidate = [];
        $nestedCandidates = [];
        foreach ($candidate as $candidateKey => $candidateValue) {
            if (is_array($candidateValue)) {
                $nestedCandidates[] = $candidateValue;
            }

            if (!is_string($candidateKey)) {
                continue;
            }

            $lower = strtolower($candidateKey);
            $normalisedCandidate[$lower] = $candidateValue;
            $compressed = preg_replace('/[^a-z0-9]/', '', $lower);
            if ($compressed !== $lower && !array_key_exists($compressed, $normalisedCandidate)) {
                $normalisedCandidate[$compressed] = $candidateValue;
            }
        }

        foreach ($nestedCandidates as $nestedCandidate) {
            $candidates[] = $nestedCandidate;
            $total = count($candidates);
        }

        $fallbackCandidateNif = '';
        $fallbackName = '';
        $fallbackErpDatabase = '';
        $fallbackErpClientCode = '';
        $isListCandidate = array_keys($candidate) === range(0, count($candidate) - 1);
        if ($isListCandidate) {
            foreach ($candidate as $value) {
                if (!is_scalar($value) && $value !== null) {
                    continue;
                }

                $stringValue = trim((string) $value);
                if ($stringValue === '') {
                    continue;
                }

                if ($fallbackCandidateNif === '') {
                    $listCandidateNif = extractVatNumber($stringValue);
                    if ($listCandidateNif !== '') {
                        $fallbackCandidateNif = $listCandidateNif;
                    }
                }

                if ($fallbackName === '' && preg_match('/[A-Za-zÀ-ÿ]/u', $stringValue) && !preg_match('/^[0-9\s.,-]+$/', $stringValue)) {
                    $fallbackName = $stringValue;
                }

                if ($fallbackErpDatabase === '' && preg_match('/^(emp|db|bd)[_-]?[A-Za-z0-9]+$/i', $stringValue)) {
                    $fallbackErpDatabase = $stringValue;
                }

                if ($fallbackErpClientCode === '' && preg_match('/^\d{1,10}$/', $stringValue) && $stringValue !== $fallbackCandidateNif) {
                    $fallbackErpClientCode = $stringValue;
                }
            }
        }

        $nifKeys = ['nif', 'vat', 'vatnumber', 'nifcliente', 'numero_contribuinte', 'numerocontribuinte', 'contribuinte', 'strnumcontrib', 'strnumcontribuinte'];


        foreach ($nifKeys as $nifKey) {
            if (array_key_exists($nifKey, $normalisedCandidate)) {
                $candidateNif = extractVatNumber((string) $normalisedCandidate[$nifKey]);
                break;
            }
        }
        if ($candidateNif === '' && $fallbackCandidateNif !== '') {
            $candidateNif = $fallbackCandidateNif;
        }
        if ($candidateNif !== '' && $candidateNif !== $nif) {
            continue;
        }

        $name = '';


        $nameKeys = ['name', 'nome', 'nomecliente', 'razao_social', 'razaosocial', 'descricao', 'designacao', 'strnome'];


        foreach ($nameKeys as $nameKey) {
            if (array_key_exists($nameKey, $normalisedCandidate) && trim((string) $normalisedCandidate[$nameKey]) !== '') {
                $name = trim((string) $normalisedCandidate[$nameKey]);
                break;
            }
        }
        if ($name === '' && $fallbackName !== '') {
            $name = $fallbackName;
        }


        $erpDatabase = '';
        $databaseKeys = ['erp_database', 'erpdatabase', 'database', 'db', 'bd', 'base_dados', 'basedados'];
        foreach ($databaseKeys as $dbKey) {
            if (array_key_exists($dbKey, $normalisedCandidate)) {
                $erpDatabase = trim((string) $normalisedCandidate[$dbKey]);
                break;
            }
        }
        if ($erpDatabase === '' && $fallbackErpDatabase !== '') {
            $erpDatabase = $fallbackErpDatabase;
        }

        $erpClientCode = '';
        if (array_key_exists('intcodigo', $normalisedCandidate)) {
            $erpClientCode = trim((string) $normalisedCandidate['intcodigo']);
        }
        if ($erpClientCode === '' && $fallbackErpClientCode !== '') {
            $erpClientCode = $fallbackErpClientCode;
        }

        $entityType = '';
        $typeKeys = ['entity_type', 'entitytype', 'tp_entidade', 'tpentidade', 'tipo', 'tipo_entidade', 'tipoentidade'];

        foreach ($typeKeys as $typeKey) {
            if (array_key_exists($typeKey, $normalisedCandidate)) {
                $entityType = normalizeErpEntityTypeValue((string) $normalisedCandidate[$typeKey]);
                break;
            }
        }

        if ($preferredEntityType !== '' && $entityType !== '' && $entityType !== $preferredEntityType) {
            continue;
        }

        if ($candidateNif === '' && $name === '' && $erpDatabase === '' && $entityType === '') {
            continue;
        }

        return [
            'nif' => $candidateNif !== '' ? $candidateNif : $nif,
            'name' => $name,
            'erp_database' => $erpDatabase,
            'erp_client_code' => $erpClientCode,
            'entity_type' => $entityType,
        ];
    }

    logErpMessage($sourceLabel . ' sem dados reconhecíveis para o NIF ' . $nif);

    return null;
}

/**
 * Retrieve client information from the ERP-SINC webservice.
 *
 * @param string $nif VAT number to request.
 * @return array|null Entity data or null when the request fails.
 */
function fetchAccountingEntityFromErp(string $nif, string $entityType = '', bool $returnDebug = false, string $database = ''): ?array {
    if (!function_exists('curl_init')) {
        $message = 'Extensão cURL não disponível para sincronizar entidade ' . $nif . ' via ERP-SINC.';
        logErpMessage($message);
        return $returnDebug ? ['entity' => null, 'error' => $message] : null;
    }

    $baseUrl = getSetting('erp_webservice_url', '');
    if ($baseUrl === null || trim($baseUrl) === '') {
        $message = 'URL do ERP-SINC não configurada para sincronizar o NIF ' . $nif . '.';
        logErpMessage($message);
        return $returnDebug ? ['entity' => null, 'error' => $message] : null;
    }

    $normalizedType = strtolower(trim($entityType));
    $database = trim($database);
    if ($normalizedType === 'emitter') {
        $endpoint = buildErpSupplierEndpoint($baseUrl, $nif, $database);
    } else {
        $endpoint = buildErpClientEndpoint($baseUrl, $nif, $database);
    }
    if ($endpoint === '') {
        $message = 'URL do ERP-SINC inválida para o NIF ' . $nif . '.';
        logErpMessage($message);
        return $returnDebug ? ['entity' => null, 'error' => $message] : null;
    }

    $sanitizedEndpoint = sanitizeUrlForLog($endpoint);
    $endpointInfo = $sanitizedEndpoint !== '' ? ' URL: ' . $sanitizedEndpoint : '';

    $token = getSetting('erp_token', '');
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'X-API-KEY: ' . $token;
    }

    $handle = curl_init($endpoint);
    if ($handle === false) {
        logErpMessage('Falha ao inicializar pedido ao ERP-SINC para o NIF ' . $nif . '.' . $endpointInfo);
        return null;
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
    if ($response === false) {
        $message = 'Erro cURL ao obter entidade ' . $nif . ' do ERP-SINC: ' . curl_error($handle) . $endpointInfo;
        logErpMessage($message);
        curl_close($handle);
        return $returnDebug ? ['entity' => null, 'error' => $message, 'endpoint' => $sanitizedEndpoint] : null;
    }

    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($status >= 400) {
        $message = 'Webservice ERP-SINC devolveu HTTP ' . $status . ' para o NIF ' . $nif . '.' . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['entity' => null, 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint, 'response' => $response] : null;
    }

    if ($status === 204 || trim((string) $response) === '') {
        $message = 'Webservice ERP-SINC devolveu resposta vazia para o NIF ' . $nif . '.' . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['entity' => null, 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint, 'response' => $response] : null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        $message = 'Resposta ERP-SINC inválida para o NIF ' . $nif . ': ' . substr($response, 0, 200) . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['entity' => null, 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint, 'response' => $response] : null;
    }

    $entity = parseErpEntityPayload($data, $nif, $normalizedType);
    if ($entity === null) {
        $message = 'Dados do NIF ' . $nif . ' indisponíveis no ERP-SINC.' . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['entity' => null, 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint, 'response' => $response, 'payload' => $data] : null;
    }

    if ($returnDebug) {
        return [
            'entity' => $entity,
            'endpoint' => $sanitizedEndpoint,
            'status' => $status,
            'response' => $response,
            'payload' => $data,
            'error' => null,
        ];
    }

    return $entity;
}

function fetchAccountingAcquirerClientCodeFromBaseErp(string $nif, string $fallback = '', string $database = ''): string {
    static $cache = [];

    $nif = extractVatNumber($nif);
    $fallback = trim($fallback);
    $database = normalizeAccountingEntityDatabaseKey(trim($database));
    if ($nif === '') {
        return $fallback;
    }

    $cacheKey = $nif . '|' . $database;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey] !== '' ? $cache[$cacheKey] : $fallback;
    }

    $baseDatabase = $database !== '' ? $database : trim((string) getSetting('erp_database', ''));
    if ($baseDatabase === '') {
        $cache[$cacheKey] = '';
        return $fallback;
    }

    $remote = fetchAccountingEntityFromErp($nif, 'acquirer', false, $baseDatabase);
    $clientCode = is_array($remote) ? trim((string) ($remote['erp_client_code'] ?? '')) : '';
    $cache[$cacheKey] = $clientCode;

    return $clientCode !== '' ? $clientCode : $fallback;
}

/**
 * Fetch a table list from the ERP-SINC API (e.g., zonas/subzonas).
 *
 * @param string $path Path appended to the ERP base URL.
 * @param bool   $returnDebug Return debug information alongside data.
 * @param string $database Optional ERP database override.
 * @return array Associative array with data/error info.
 */
function fetchErpTableData(string $path, bool $returnDebug = false, string $database = ''): array {
    if (!function_exists('curl_init')) {
        $message = 'Extensão cURL não disponível para obter tabelas ERP-SINC.';
        logErpMessage($message);
        return $returnDebug ? ['data' => [], 'error' => $message] : ['data' => []];
    }

    $baseUrl = getSetting('erp_webservice_url', '');
    $baseUrl = trim((string) $baseUrl);
    if ($baseUrl === '') {
        $baseUrl = 'https://api.erpsinc.pt/v1.0.0';
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, $path);
    $endpoint = appendQueryParamsToUrl($endpoint, buildErpCompanyQueryParams($database));
    if ($endpoint === '') {
        $message = 'URL do ERP-SINC inválida para obter tabelas.';
        logErpMessage($message);
        return $returnDebug ? ['data' => [], 'error' => $message] : ['data' => []];
    }

    $sanitizedEndpoint = sanitizeUrlForLog($endpoint);
    $endpointInfo = $sanitizedEndpoint !== '' ? ' URL: ' . $sanitizedEndpoint : '';

    $token = getSetting('erp_token', '');
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'X-API-KEY: ' . $token;
    }

    $handle = curl_init($endpoint);
    if ($handle === false) {
        $message = 'Falha ao inicializar pedido ao ERP-SINC.' . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['data' => [], 'error' => $message] : ['data' => []];
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
    if ($response === false) {
        $message = 'Erro cURL ao obter tabelas ERP-SINC: ' . curl_error($handle) . $endpointInfo;
        logErpMessage($message);
        curl_close($handle);
        return $returnDebug ? ['data' => [], 'error' => $message, 'endpoint' => $sanitizedEndpoint] : ['data' => []];
    }

    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($status >= 400) {
        $message = 'Webservice ERP-SINC devolveu HTTP ' . $status . ' ao obter tabelas.' . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['data' => [], 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint] : ['data' => []];
    }

    $trimmedResponse = trim((string) $response);
    if ($trimmedResponse === '') {
        $message = 'Webservice ERP-SINC devolveu resposta vazia ao obter tabelas.' . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['data' => [], 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint] : ['data' => []];
    }

    $decoded = json_decode($trimmedResponse, true);
    if (!is_array($decoded)) {
        $message = 'Resposta ERP-SINC inválida ao obter tabelas.' . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['data' => [], 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint, 'response' => $response] : ['data' => []];
    }

    $payload = $decoded;
    $payloadKeyMap = [];
    foreach ($decoded as $payloadKey => $payloadValue) {
        if (is_string($payloadKey)) {
            $payloadKeyMap[strtolower($payloadKey)] = $payloadKey;
        }
    }

    foreach (['aadata', 'data', 'results', 'result', 'records'] as $key) {
        $originalKey = $payloadKeyMap[$key] ?? null;
        if ($originalKey !== null && is_array($decoded[$originalKey])) {
            $payload = $decoded[$originalKey];
            break;
        }
    }

    if (!is_array($payload)) {
        $payload = [];
    }

    $data = array_values(array_filter($payload, 'is_array'));
    if ($returnDebug) {
        return [
            'data' => $data,
            'endpoint' => $sanitizedEndpoint,
            'status' => $status,
            'response' => $response,
            'payload' => $decoded,
            'error' => null,
        ];
    }

    return ['data' => $data];
}

/**
 * Execute a JSON request against the ERP-SINC API.
 *
 * @param string     $path        Endpoint path relative to the ERP base URL.
 * @param string     $method      HTTP method.
 * @param array|null $payload     Optional JSON payload.
 * @param bool       $returnDebug Include HTTP debug information.
 * @param string     $database    Optional ERP database override.
 * @return array Normalized response with success/error information.
 */
function callErpJsonEndpoint(string $path, string $method = 'GET', ?array $payload = null, bool $returnDebug = false, string $database = ''): array {
    $method = strtoupper(trim($method));
    if ($method === '') {
        $method = 'GET';
    }

    if (!function_exists('curl_init')) {
        $message = 'Extensão cURL não disponível para comunicar com o ERP-SINC.';
        logErpMessage($message);
        return $returnDebug ? ['success' => false, 'data' => null, 'error' => $message] : ['success' => false, 'error' => $message];
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    if ($baseUrl === '') {
        $baseUrl = 'https://api.erpsinc.pt/v1.0.0';
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, $path);
    $endpoint = appendQueryParamsToUrl($endpoint, buildErpCompanyQueryParams($database));
    if ($endpoint === '') {
        $message = 'URL do ERP-SINC inválida.';
        logErpMessage($message);
        return $returnDebug ? ['success' => false, 'data' => null, 'error' => $message] : ['success' => false, 'error' => $message];
    }

    $sanitizedEndpoint = sanitizeUrlForLog($endpoint);
    $endpointInfo = $sanitizedEndpoint !== '' ? ' URL: ' . $sanitizedEndpoint : '';

    $token = getSetting('erp_token', '');
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'X-API-KEY: ' . $token;
    }

    $encodedPayload = null;
    if ($payload !== null) {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedPayload === false) {
            $message = 'Falha ao preparar o pedido para o ERP-SINC.';
            logErpMessage($message . $endpointInfo);
            return $returnDebug
                ? ['success' => false, 'data' => null, 'error' => $message, 'endpoint' => $sanitizedEndpoint]
                : ['success' => false, 'error' => $message];
        }
        $headers[] = 'Content-Type: application/json; charset=utf-8';
    }

    $handle = curl_init($endpoint);
    if ($handle === false) {
        $message = 'Falha ao inicializar pedido ao ERP-SINC.' . $endpointInfo;
        logErpMessage($message);
        return $returnDebug ? ['success' => false, 'data' => null, 'error' => $message, 'endpoint' => $sanitizedEndpoint] : ['success' => false, 'error' => $message];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($encodedPayload !== null) {
        $options[CURLOPT_POSTFIELDS] = $encodedPayload;
    }
    curl_setopt_array($handle, $options);

    $response = curl_exec($handle);
    if ($response === false) {
        $message = 'Erro cURL ao comunicar com o ERP-SINC: ' . curl_error($handle) . $endpointInfo;
        logErpMessage($message);
        curl_close($handle);
        return $returnDebug
            ? ['success' => false, 'data' => null, 'error' => $message, 'endpoint' => $sanitizedEndpoint]
            : ['success' => false, 'error' => $message];
    }

    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    $data = null;
    if (trim((string) $response) !== '') {
        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if ($status >= 400) {
        $message = '';
        if (is_array($data)) {
            foreach (['errormsg', 'error', 'message'] as $key) {
                if (!empty($data[$key]) && is_scalar($data[$key])) {
                    $message = trim((string) $data[$key]);
                    break;
                }
            }
        }
        if ($message === '') {
            $message = 'Webservice ERP-SINC devolveu HTTP ' . $status . '.';
        }
        logErpMessage($message . $endpointInfo);
        return $returnDebug
            ? ['success' => false, 'data' => $data, 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint, 'response' => $response]
            : ['success' => false, 'error' => $message, 'data' => $data];
    }

    if (is_array($data) && array_key_exists('success', $data) && !$data['success']) {
        $message = '';
        foreach (['errormsg', 'error', 'message'] as $key) {
            if (!empty($data[$key]) && is_scalar($data[$key])) {
                $message = trim((string) $data[$key]);
                break;
            }
        }
        if ($message === '') {
            $message = 'O ERP-SINC recusou a operação.';
        }
        logErpMessage($message . $endpointInfo);
        return $returnDebug
            ? ['success' => false, 'data' => $data, 'error' => $message, 'status' => $status, 'endpoint' => $sanitizedEndpoint, 'response' => $response]
            : ['success' => false, 'error' => $message, 'data' => $data];
    }

    return $returnDebug
        ? ['success' => true, 'data' => $data, 'error' => null, 'status' => $status, 'endpoint' => $sanitizedEndpoint, 'response' => $response]
        : ['success' => true, 'data' => $data];
}

/**
 * Update editable customer details in ERP-SINC.
 *
 * @param int    $id       ERP customer record ID.
 * @param array  $payload  Whitelisted editable fields.
 * @param string $database ERP database key.
 * @return array Normalized ERP response.
 */
function updateErpClientDetails(int $id, array $payload, string $database = ''): array {
    if ($id <= 0) {
        return ['success' => false, 'error' => 'ID do cliente ERP inválido.'];
    }

    $database = normalizeAccountingEntityDatabaseKey($database);
    if ($database !== '') {
        $payload['db'] = $database;
    }
    $payload['Id'] = $id;

    return callErpJsonEndpoint('/clientes/' . $id, 'PUT', $payload, true, $database);
}

function normalizeErpEInvoiceDocTypeLookupValue(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strtoupper')) {
        $value = mb_strtoupper($value, 'UTF-8');
    } else {
        $value = strtoupper($value);
    }

    if (function_exists('iconv')) {
        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($transliterated) && $transliterated !== '') {
            $value = $transliterated;
        }
    }

    $value = preg_replace('/[^A-Z0-9]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string) $value);
    return trim((string) $value);
}

function buildOfficialEfaturaDocTypeCodes(string $documentType): array {
    $normalized = normalizeErpEInvoiceDocTypeLookupValue($documentType);
    if ($normalized === '') {
        return [];
    }

    switch ($normalized) {
        case 'FT':
        case 'FATURA':
        case 'FACTURA':
            return ['FT'];
        case 'FR':
        case 'FTR':
        case 'FATURA RECIBO':
        case 'FACTURA RECIBO':
            return ['FR', 'FTR'];
        case 'FS':
        case 'FATURA SIMPLIFICADA':
        case 'FACTURA SIMPLIFICADA':
            return ['FS'];
        case 'NC':
        case 'NOTA CREDITO':
        case 'NOTA DE CREDITO':
            return ['NC'];
        case 'ND':
        case 'NOTA DEBITO':
        case 'NOTA DE DEBITO':
            return ['ND'];
        case 'RC':
        case 'RG':
        case 'RECIBO':
            return ['RC', 'RG'];
    }

    if (preg_match('/^[A-Z0-9]{2,6}$/', $normalized)) {
        return [$normalized];
    }

    return [];
}

function getAccountingQrDocTypeMappingKey(string $documentType): string {
    $officialCodes = buildOfficialEfaturaDocTypeCodes($documentType);
    if (!empty($officialCodes)) {
        return trim((string) $officialCodes[0]);
    }

    return normalizeErpEInvoiceDocTypeLookupValue($documentType);
}

function normalizeAccountingQrDocTypeMappingsPayload($rawValue): array {
    $rawValue = trim((string) $rawValue);
    if ($rawValue === '') {
        return [];
    }

    $decoded = json_decode($rawValue, true);
    if (!is_array($decoded)) {
        return [];
    }

    $normalized = [];
    foreach ($decoded as $rawKey => $rawEntry) {
        $mappingKey = getAccountingQrDocTypeMappingKey((string) $rawKey);
        if ($mappingKey === '') {
            continue;
        }

        $entry = [];
        if (is_array($rawEntry)) {
            $entry = $rawEntry;
        } elseif (is_string($rawEntry)) {
            $entry = ['erp_doc_type' => $rawEntry];
        }

        $erpDocType = trim((string) ($entry['erp_doc_type'] ?? $entry['value'] ?? ''));
        if ($erpDocType === '') {
            continue;
        }

        $normalized[$mappingKey] = [
            'qr_doc_type' => $mappingKey,
            'erp_doc_type' => $erpDocType,
            'erp_label' => trim((string) ($entry['erp_label'] ?? $entry['label'] ?? '')),
            'updated_at' => trim((string) ($entry['updated_at'] ?? '')),
        ];
    }

    return $normalized;
}

function getAccountingQrDocTypeMappings(string $database = ''): array {
    $database = resolveErpDatabaseIdentifier($database);
    if ($database === '') {
        return [];
    }

    if (
        isset($GLOBALS['accounting_qr_doc_type_mappings_cache'])
        && is_array($GLOBALS['accounting_qr_doc_type_mappings_cache'])
        && array_key_exists($database, $GLOBALS['accounting_qr_doc_type_mappings_cache'])
        && is_array($GLOBALS['accounting_qr_doc_type_mappings_cache'][$database])
    ) {
        return $GLOBALS['accounting_qr_doc_type_mappings_cache'][$database];
    }

    $pdo = getPDO();
    $entity = findAccountingAcquirerEntityByDatabase($pdo, $database);
    if (!is_array($entity)) {
        if (!isset($GLOBALS['accounting_qr_doc_type_mappings_cache']) || !is_array($GLOBALS['accounting_qr_doc_type_mappings_cache'])) {
            $GLOBALS['accounting_qr_doc_type_mappings_cache'] = [];
        }
        $GLOBALS['accounting_qr_doc_type_mappings_cache'][$database] = [];
        return [];
    }

    $normalized = normalizeAccountingQrDocTypeMappingsPayload($entity['qr_doc_type_mappings'] ?? '');
    if (!isset($GLOBALS['accounting_qr_doc_type_mappings_cache']) || !is_array($GLOBALS['accounting_qr_doc_type_mappings_cache'])) {
        $GLOBALS['accounting_qr_doc_type_mappings_cache'] = [];
    }
    $GLOBALS['accounting_qr_doc_type_mappings_cache'][$database] = $normalized;
    return $normalized;
}

function saveAccountingQrDocTypeMappings(array $mappings, string $database = ''): void {
    $database = resolveErpDatabaseIdentifier($database);
    if ($database === '') {
        throw new InvalidArgumentException('Base de dados ERP inválida para guardar a associação do tipo documental.');
    }

    $normalized = [];
    foreach ($mappings as $rawKey => $rawEntry) {
        $mappingKey = getAccountingQrDocTypeMappingKey((string) $rawKey);
        if ($mappingKey === '') {
            continue;
        }

        $entry = is_array($rawEntry) ? $rawEntry : ['erp_doc_type' => $rawEntry];
        $erpDocType = trim((string) ($entry['erp_doc_type'] ?? $entry['value'] ?? ''));
        if ($erpDocType === '') {
            continue;
        }

        $normalized[$mappingKey] = [
            'qr_doc_type' => $mappingKey,
            'erp_doc_type' => $erpDocType,
            'erp_label' => trim((string) ($entry['erp_label'] ?? $entry['label'] ?? '')),
            'updated_at' => trim((string) ($entry['updated_at'] ?? date('Y-m-d H:i:s'))),
        ];
    }

    $pdo = getPDO();
    $entity = findAccountingAcquirerEntityByDatabase($pdo, $database);
    if (!is_array($entity)) {
        throw new RuntimeException('Empresa local não encontrada para guardar a associação do tipo documental.');
    }

    saveAccountingEntity($pdo, [
        'nif' => trim((string) ($entity['nif'] ?? '')),
        'name' => trim((string) ($entity['name'] ?? '')),
        'erp_database' => resolveAccountingEntityDatabase($entity),
        'entity_type' => trim((string) ($entity['entity_type'] ?? 'acquirer')),
        'erp_client_code' => trim((string) ($entity['erp_client_code'] ?? '')),
        'qr_doc_type_mappings' => json_encode($normalized, JSON_UNESCAPED_UNICODE),
    ]);

    if (!isset($GLOBALS['accounting_qr_doc_type_mappings_cache']) || !is_array($GLOBALS['accounting_qr_doc_type_mappings_cache'])) {
        $GLOBALS['accounting_qr_doc_type_mappings_cache'] = [];
    }
    $GLOBALS['accounting_qr_doc_type_mappings_cache'][$database] = $normalized;
}

function getAccountingQrDocTypeMappingEntry(string $documentType, string $database = ''): array {
    $mappingKey = getAccountingQrDocTypeMappingKey($documentType);
    if ($mappingKey === '') {
        return [];
    }

    $mappings = getAccountingQrDocTypeMappings($database);
    if (!isset($mappings[$mappingKey]) || !is_array($mappings[$mappingKey])) {
        return [];
    }

    return $mappings[$mappingKey];
}

function setAccountingQrDocTypeMapping(string $documentType, string $erpDocType, string $erpLabel = '', string $database = ''): array {
    $mappingKey = getAccountingQrDocTypeMappingKey($documentType);
    $erpDocType = trim($erpDocType);
    if ($mappingKey === '' || $erpDocType === '') {
        return [];
    }

    $mappings = getAccountingQrDocTypeMappings($database);
    $mappings[$mappingKey] = [
        'qr_doc_type' => $mappingKey,
        'erp_doc_type' => $erpDocType,
        'erp_label' => trim($erpLabel),
        'updated_at' => date('Y-m-d H:i:s'),
    ];
    saveAccountingQrDocTypeMappings($mappings, $database);

    return $mappings[$mappingKey];
}

function buildErpEInvoiceDocTypeAliases(string $documentType): array {
    $normalized = normalizeErpEInvoiceDocTypeLookupValue($documentType);
    if ($normalized === '') {
        return [];
    }

    $aliases = [$normalized];
    $officialCodes = buildOfficialEfaturaDocTypeCodes($documentType);
    $aliases = array_merge($aliases, $officialCodes);

    foreach ($officialCodes as $officialCode) {
        switch ($officialCode) {
            case 'FT':
                $aliases = array_merge($aliases, ['FATURA', 'FACTURA']);
                break;
            case 'FR':
            case 'FTR':
                $aliases = array_merge($aliases, ['FATURA RECIBO', 'FACTURA RECIBO']);
                break;
            case 'FS':
                $aliases = array_merge($aliases, ['FATURA SIMPLIFICADA', 'FACTURA SIMPLIFICADA']);
                break;
            case 'NC':
                $aliases = array_merge($aliases, ['NOTA CREDITO', 'NOTA DE CREDITO']);
                break;
            case 'ND':
                $aliases = array_merge($aliases, ['NOTA DEBITO', 'NOTA DE DEBITO']);
                break;
            case 'RC':
            case 'RG':
                $aliases = array_merge($aliases, ['RECIBO']);
                break;
        }
    }

    $aliases = array_values(array_unique(array_filter(array_map('trim', $aliases), static function ($value): bool {
        return $value !== '';
    })));

    return $aliases;
}

function parseErpEInvoiceDocTypeListDocs(string $listDocs): array {
    $listDocs = trim($listDocs);
    if ($listDocs === '') {
        return [];
    }

    $parts = preg_split('/[\r\n,;|]+/', $listDocs);
    if (!is_array($parts)) {
        return [];
    }

    $tokens = [];
    foreach ($parts as $part) {
        $normalized = normalizeErpEInvoiceDocTypeLookupValue((string) $part);
        if ($normalized !== '') {
            $tokens[] = $normalized;
        }
    }

    return array_values(array_unique($tokens));
}

function buildErpEInvoiceDocTypeOptions(string $database = ''): array {
    $rows = fetchErpEInvoiceDocTypeRows($database);
    $byValue = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $value = trim((string) ($row['strAbrevTpDoc'] ?? ''));
        if ($value === '') {
            continue;
        }

        $key = normalizeErpEInvoiceDocTypeLookupValue($value);
        if (!isset($byValue[$key])) {
            $byValue[$key] = [
                'value' => $value,
                'titles' => [],
                'official_codes' => [],
            ];
        }

        $title = trim((string) ($row['strTpDoc'] ?? ''));
        if ($title !== '' && !in_array($title, $byValue[$key]['titles'], true)) {
            $byValue[$key]['titles'][] = $title;
        }

        foreach (parseErpEInvoiceDocTypeListDocs((string) ($row['strListDocs'] ?? '')) as $officialCode) {
            if (!in_array($officialCode, $byValue[$key]['official_codes'], true)) {
                $byValue[$key]['official_codes'][] = $officialCode;
            }
        }
    }

    $items = [];
    foreach ($byValue as $option) {
        $value = trim((string) ($option['value'] ?? ''));
        if ($value === '') {
            continue;
        }

        $titles = isset($option['titles']) && is_array($option['titles']) ? $option['titles'] : [];
        $officialCodes = isset($option['official_codes']) && is_array($option['official_codes']) ? $option['official_codes'] : [];
        sort($officialCodes, SORT_NATURAL);
        $title = !empty($titles) ? implode(' / ', $titles) : $value;
        $label = $title !== $value ? ($title . ' (' . $value . ')') : $value;
        $description = '';
        if (!empty($officialCodes)) {
            $description = 'Codigos QR: ' . implode(', ', $officialCodes);
        }

        $items[] = [
            'value' => $value,
            'title' => $title,
            'label' => $label,
            'description' => $description,
            'official_codes' => $officialCodes,
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    return $items;
}

function fetchErpEInvoiceDocTypeRows(string $database = '', bool $forceRefresh = false): array {
    static $requestCache = [];

    $database = resolveErpDatabaseIdentifier($database);
    if ($database === '') {
        return [];
    }

    $ttlSeconds = 3600;
    $now = time();

    if (!$forceRefresh && isset($requestCache[$database])) {
        $cachedEntry = $requestCache[$database];
        $fetchedAt = (int) ($cachedEntry['fetched_at'] ?? 0);
        if ($fetchedAt > 0 && ($now - $fetchedAt) < $ttlSeconds) {
            return isset($cachedEntry['rows']) && is_array($cachedEntry['rows']) ? $cachedEntry['rows'] : [];
        }
    }

    if (!$forceRefresh && session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['erp_einvoice_tpdocs'][$database])) {
        $cachedEntry = $_SESSION['erp_einvoice_tpdocs'][$database];
        $fetchedAt = (int) ($cachedEntry['fetched_at'] ?? 0);
        if ($fetchedAt > 0 && ($now - $fetchedAt) < $ttlSeconds) {
            $rows = isset($cachedEntry['rows']) && is_array($cachedEntry['rows']) ? $cachedEntry['rows'] : [];
            $requestCache[$database] = [
                'fetched_at' => $fetchedAt,
                'rows' => $rows,
            ];
            return $rows;
        }
    }

    $response = fetchErpTableData('contabilidade/eInvoice_TpDocs', true, $database);
    $rows = [];
    if (isset($response['data']) && is_array($response['data'])) {
        foreach ($response['data'] as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
    }

    $cacheEntry = [
        'fetched_at' => $now,
        'rows' => $rows,
    ];
    $requestCache[$database] = $cacheEntry;

    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!isset($_SESSION['erp_einvoice_tpdocs']) || !is_array($_SESSION['erp_einvoice_tpdocs'])) {
            $_SESSION['erp_einvoice_tpdocs'] = [];
        }
        $_SESSION['erp_einvoice_tpdocs'][$database] = $cacheEntry;
    }

    return $rows;
}

function preloadErpEInvoiceDocTypes(string $database = ''): void {
    fetchErpEInvoiceDocTypeRows($database);
}

function resolveErpAccountingDocumentTypeAbbreviation(string $documentType, string $database = ''): string {
    $documentType = trim($documentType);
    if ($documentType === '') {
        return '';
    }

    if (strpos($documentType, '/') !== false) {
        return $documentType;
    }

    $configuredMapping = getAccountingQrDocTypeMappingEntry($documentType, $database);
    $configuredValue = trim((string) ($configuredMapping['erp_doc_type'] ?? ''));
    if ($configuredValue !== '') {
        return $configuredValue;
    }

    $officialCodes = buildOfficialEfaturaDocTypeCodes($documentType);
    $aliases = buildErpEInvoiceDocTypeAliases($documentType);
    if (empty($aliases) && empty($officialCodes)) {
        return '';
    }

    $rows = fetchErpEInvoiceDocTypeRows($database);
    $bestMatch = '';
    $bestScore = -1;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $rowAbbreviation = trim((string) ($row['strAbrevTpDoc'] ?? ''));
        if ($rowAbbreviation === '') {
            continue;
        }

        $score = 0;
        $listDocTokens = parseErpEInvoiceDocTypeListDocs((string) ($row['strListDocs'] ?? ''));
        if (!empty($officialCodes)) {
            $primaryOfficialCode = $officialCodes[0];
            if (in_array($primaryOfficialCode, $listDocTokens, true)) {
                $score += 1000;
            } else {
                foreach ($officialCodes as $officialCode) {
                    if (in_array($officialCode, $listDocTokens, true)) {
                        $score += 900;
                        break;
                    }
                }
            }
        }

        $rowType = normalizeErpEInvoiceDocTypeLookupValue((string) ($row['strTpDoc'] ?? ''));
        if ($rowType !== '' && in_array($rowType, $aliases, true)) {
            $score += 100;
        }

        $rowAbbreviationNormalized = normalizeErpEInvoiceDocTypeLookupValue($rowAbbreviation);
        if ($rowAbbreviationNormalized !== '' && in_array($rowAbbreviationNormalized, $aliases, true)) {
            $score += 90;
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $rowAbbreviation;
        }
    }

    if ($bestScore >= 0 && $bestMatch !== '') {
        return $bestMatch;
    }

    return '';
}

/**
 * Fetch an accounting entity stored locally by VAT number.
 *
 * @param PDO    $pdo Active database connection.
 * @param string $nif VAT number.
 * @return array|null Matching entity or null when absent.
 */
function findAccountingEntity(PDO $pdo, string $nif): ?array {
    $selectColumns = appendAccountingEmitterTypeSelectColumn(
        appendAccountingEntityUuidSelectColumn('id, name, nif, erp_database, entity_type, erp_client_code, qr_doc_type_mappings, created_at')
    );
    $stmt = $pdo->prepare('SELECT ' . $selectColumns . ' FROM accounting_entities WHERE nif = ? LIMIT 1');
    $stmt->execute([$nif]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? ensureAccountingEntityRouteRow($pdo, $row) : null;
}

/**
 * Fetch an accounting entity by VAT and type.
 *
 * @param PDO    $pdo        Active database connection.
 * @param string $nif        VAT number.
 * @param string $entityType Entity type to match.
 * @return array|null Matching entity or null when absent.
 */
function findAccountingEntityByType(PDO $pdo, string $nif, string $entityType): ?array {
    $normalizedType = trim($entityType);
    if ($normalizedType === '') {
        return findAccountingEntity($pdo, $nif);
    }
    $selectColumns = appendAccountingEmitterTypeSelectColumn(
        appendAccountingEntityUuidSelectColumn('id, name, nif, erp_database, entity_type, erp_client_code, qr_doc_type_mappings, created_at')
    );
    $stmt = $pdo->prepare('SELECT ' . $selectColumns . ' FROM accounting_entities WHERE nif = ? AND entity_type = ? LIMIT 1');
    $stmt->execute([$nif, $normalizedType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? ensureAccountingEntityRouteRow($pdo, $row) : null;
}

function normalizeAccountingEntityDatabaseKey(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d+$/', $value)) {
        return 'emp_' . $value;
    }

    if (preg_match('/^emp[_-]?(\d+)$/i', $value, $matches)) {
        return 'emp_' . $matches[1];
    }

    return $value;
}

function isValidAccountingEntityUuid(string $value): bool {
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        trim($value)
    );
}

function generateAccountingEntityUuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function hasAccountingEntityUuidColumn(): bool {
    return hasColumn('accounting_entities', 'uuid');
}

function appendAccountingEntityUuidSelectColumn(string $selectColumns): string {
    if (!hasAccountingEntityUuidColumn()) {
        return $selectColumns;
    }

    return $selectColumns . ', uuid';
}

function ensureAccountingEntityUuid(PDO $pdo, int $entityId, string $currentUuid = ''): string {
    if ($entityId <= 0 || !hasAccountingEntityUuidColumn()) {
        return '';
    }

    $currentUuid = trim($currentUuid);
    if (isValidAccountingEntityUuid($currentUuid)) {
        return strtolower($currentUuid);
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $uuid = generateAccountingEntityUuid();
        $stmt = $pdo->prepare('UPDATE accounting_entities SET uuid = ? WHERE id = ? AND COALESCE(NULLIF(TRIM(uuid), \'\'), \'\') = \'\'');
        try {
            $stmt->execute([$uuid, $entityId]);
        } catch (Throwable $e) {
            continue;
        }

        $checkStmt = $pdo->prepare('SELECT uuid FROM accounting_entities WHERE id = ? LIMIT 1');
        $checkStmt->execute([$entityId]);
        $storedUuid = trim((string) ($checkStmt->fetchColumn() ?: ''));
        if (isValidAccountingEntityUuid($storedUuid)) {
            return strtolower($storedUuid);
        }
    }

    return '';
}

function ensureAccountingEntityRouteRow(PDO $pdo, ?array $entity): ?array {
    if (!is_array($entity)) {
        return null;
    }

    $entityId = (int) ($entity['id'] ?? 0);
    if ($entityId <= 0 || !hasAccountingEntityUuidColumn()) {
        return $entity;
    }

    $uuid = trim((string) ($entity['uuid'] ?? ''));
    if (!isValidAccountingEntityUuid($uuid)) {
        $entity['uuid'] = ensureAccountingEntityUuid($pdo, $entityId, $uuid);
    } else {
        $entity['uuid'] = strtolower($uuid);
    }

    return $entity;
}

function ensureAccountingEntityRouteRows(PDO $pdo, array $entities): array {
    foreach ($entities as $index => $entity) {
        $entities[$index] = ensureAccountingEntityRouteRow($pdo, is_array($entity) ? $entity : null);
    }

    return $entities;
}

function getAccountingEntityRouteKey(array $entity): string {
    $uuid = trim((string) ($entity['uuid'] ?? ''));
    if (isValidAccountingEntityUuid($uuid)) {
        return strtolower($uuid);
    }

    return (string) ((int) ($entity['id'] ?? 0));
}

function findAccountingEntityByRouteKey(PDO $pdo, string $routeKey, string $entityType = ''): ?array {
    $routeKey = trim($routeKey);
    if ($routeKey === '') {
        return null;
    }

    $selectColumns = appendAccountingEmitterTypeSelectColumn(
        appendAccountingEntityUuidSelectColumn('id, name, nif, erp_database, entity_type, erp_client_code, qr_doc_type_mappings, created_at')
    );

    if (ctype_digit($routeKey)) {
        $sql = 'SELECT ' . $selectColumns . ' FROM accounting_entities WHERE id = ?';
        $params = [(int) $routeKey];
        if ($entityType !== '') {
            $sql .= ' AND entity_type = ?';
            $params[] = $entityType;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? ensureAccountingEntityRouteRow($pdo, $row) : null;
    }

    if (!hasAccountingEntityUuidColumn() || !isValidAccountingEntityUuid($routeKey)) {
        return null;
    }

    $sql = 'SELECT ' . $selectColumns . ' FROM accounting_entities WHERE uuid = ?';
    $params = [strtolower($routeKey)];
    if ($entityType !== '') {
        $sql .= ' AND entity_type = ?';
        $params[] = $entityType;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? ensureAccountingEntityRouteRow($pdo, $row) : null;
}

function getAccountingEmitterTypeColumn(): string {
    if (hasColumn('accounting_entities', 'emitter_type')) {
        return 'emitter_type';
    }
    if (hasColumn('accounting_entities', 'is_bank_entity')) {
        return 'is_bank_entity';
    }
    return '';
}

function appendAccountingEmitterTypeSelectColumn(string $selectColumns): string {
    $column = getAccountingEmitterTypeColumn();
    if ($column === '') {
        return $selectColumns;
    }
    if ($column === 'emitter_type') {
        return $selectColumns . ', emitter_type';
    }
    return $selectColumns . ', is_bank_entity AS emitter_type';
}

function normalizeAccountingEntityStoragePayload(array $data): array {
    $entityType = trim((string) ($data['entity_type'] ?? ''));
    if ($entityType === '') {
        $entityType = 'acquirer';
    }

    $erpDatabase = normalizeAccountingEntityDatabaseKey((string) ($data['erp_database'] ?? ''));
    $erpClientCode = trim((string) ($data['erp_client_code'] ?? ''));

    // `erp_client_code` stores the entity code inside the ERP base and must not
    // keep legacy `emp_XXX` values.
    if (preg_match('/^emp[_-]?\d+$/i', $erpClientCode)) {
        $erpClientCode = '';
    }

    $data['entity_type'] = $entityType;
    $data['erp_database'] = $erpDatabase;
    $data['erp_client_code'] = $erpClientCode;
    $emitterTypeRaw = $data['emitter_type'] ?? ($data['is_bank_entity'] ?? '0');
    $emitterTypeString = trim((string) $emitterTypeRaw);
    if ($emitterTypeString === '2') {
        $data['emitter_type'] = '2';
    } else {
        $data['emitter_type'] = (
            $emitterTypeString === '1'
            || $emitterTypeRaw === true
        ) ? '1' : '0';
    }

    return $data;
}

function findAccountingAcquirerEntityByDatabase(PDO $pdo, string $database): ?array {
    $database = normalizeAccountingEntityDatabaseKey($database);
    if ($database === '') {
        return null;
    }

    $selectColumns = appendAccountingEmitterTypeSelectColumn(
        appendAccountingEntityUuidSelectColumn('id, name, nif, erp_database, entity_type, erp_client_code, qr_doc_type_mappings, created_at')
    );
    $stmt = $pdo->prepare(
        'SELECT ' . $selectColumns . '
         FROM accounting_entities
         WHERE entity_type = ?
           AND erp_database = ?
         ORDER BY id ASC
         LIMIT 1'
    );
    $stmt->execute(['acquirer', $database]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? ensureAccountingEntityRouteRow($pdo, $row) : null;
}

function getAccountingAcquirerDuplicateGroups(PDO $pdo): array {
    if (!hasTable('accounting_entities') || !hasColumn('accounting_entities', 'entity_type') || !hasColumn('accounting_entities', 'erp_database')) {
        return [];
    }

    $groupStmt = $pdo->query(
        "SELECT erp_database
         FROM accounting_entities
         WHERE entity_type = 'acquirer'
           AND COALESCE(NULLIF(TRIM(erp_database), ''), '') <> ''
         GROUP BY erp_database
         HAVING COUNT(*) > 1
         ORDER BY erp_database ASC"
    );
    $databases = $groupStmt ? $groupStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    if (!$databases) {
        return [];
    }

    $groups = [];
    $rowStmt = $pdo->prepare(
        'SELECT id, nif, name, erp_database, erp_client_code, entity_type
         FROM accounting_entities
         WHERE entity_type = ?
           AND erp_database = ?
         ORDER BY id ASC'
    );

    foreach ($databases as $database) {
        $database = normalizeAccountingEntityDatabaseKey((string) $database);
        if ($database === '') {
            continue;
        }
        $rowStmt->execute(['acquirer', $database]);
        $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows || count($rows) <= 1) {
            continue;
        }
        $groups[] = [
            'erp_database' => $database,
            'rows' => $rows,
            'keep_id' => (int) ($rows[0]['id'] ?? 0),
        ];
    }

    return $groups;
}

function mergeAccountingAcquirerEntitiesByDatabase(PDO $pdo, string $database, int $keepId = 0): array {
    $database = normalizeAccountingEntityDatabaseKey($database);
    if ($database === '') {
        throw new InvalidArgumentException('Base ERP invalida para fusao.');
    }

    $stmt = $pdo->prepare(
        'SELECT id, nif, name, erp_database, erp_client_code, entity_type
         FROM accounting_entities
         WHERE entity_type = ?
           AND erp_database = ?
         ORDER BY id ASC'
    );
    $stmt->execute(['acquirer', $database]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows || count($rows) <= 1) {
        return ['merged' => 0, 'kept_id' => 0, 'removed_ids' => [], 'kept_nif' => '', 'removed_nifs' => []];
    }

    $keepRow = null;
    if ($keepId > 0) {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $keepId) {
                $keepRow = $row;
                break;
            }
        }
    }
    if ($keepRow === null) {
        $keepRow = $rows[0];
        $keepId = (int) ($keepRow['id'] ?? 0);
    }
    if ($keepId <= 0) {
        throw new RuntimeException('Nao foi possivel determinar o registo a manter.');
    }

    $dropRows = [];
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) !== $keepId) {
            $dropRows[] = $row;
        }
    }
    if (!$dropRows) {
        return ['merged' => 0, 'kept_id' => $keepId, 'removed_ids' => [], 'kept_nif' => (string) ($keepRow['nif'] ?? ''), 'removed_nifs' => []];
    }

    $keptNif = trim((string) ($keepRow['nif'] ?? ''));
    $removedIds = [];
    $removedNifs = [];

    $pdo->beginTransaction();
    try {
        foreach ($dropRows as $dropRow) {
            $dropId = (int) ($dropRow['id'] ?? 0);
            $dropNif = trim((string) ($dropRow['nif'] ?? ''));
            if ($dropId <= 0) {
                continue;
            }

            if (hasTable('accounting_entity_additional_values')) {
                $pdo->prepare(
                    'INSERT INTO accounting_entity_additional_values (entity_id, field_id, value)
                     SELECT ?, field_id, value
                     FROM accounting_entity_additional_values
                     WHERE entity_id = ?
                     ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
                )->execute([$keepId, $dropId]);
                $pdo->prepare('DELETE FROM accounting_entity_additional_values WHERE entity_id = ?')->execute([$dropId]);
            }

            if (hasTable('efatura_company_credentials') && hasColumn('efatura_company_credentials', 'entity_id')) {
                $keepCredential = $pdo->prepare('SELECT id FROM efatura_company_credentials WHERE entity_id = ? LIMIT 1');
                $keepCredential->execute([$keepId]);
                $hasKeepCredential = (bool) $keepCredential->fetchColumn();

                if ($hasKeepCredential) {
                    $pdo->prepare('DELETE FROM efatura_company_credentials WHERE entity_id = ?')->execute([$dropId]);
                } else {
                    $pdo->prepare('UPDATE efatura_company_credentials SET entity_id = ? WHERE entity_id = ?')->execute([$keepId, $dropId]);
                }
            }

            if (hasTable('efatura_sync_jobs') && hasColumn('efatura_sync_jobs', 'entity_id')) {
                $pdo->prepare('UPDATE efatura_sync_jobs SET entity_id = ? WHERE entity_id = ?')->execute([$keepId, $dropId]);
            }

            if (hasTable('efatura_documents') && hasColumn('efatura_documents', 'entity_id')) {
                $docStmt = $pdo->prepare('SELECT id, source_hash FROM efatura_documents WHERE entity_id = ? ORDER BY id ASC');
                $docStmt->execute([$dropId]);
                $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($documents as $document) {
                    $documentId = (int) ($document['id'] ?? 0);
                    $sourceHash = trim((string) ($document['source_hash'] ?? ''));
                    if ($documentId <= 0) {
                        continue;
                    }

                    $existingDocStmt = $pdo->prepare('SELECT id FROM efatura_documents WHERE entity_id = ? AND source_hash = ? LIMIT 1');
                    $existingDocStmt->execute([$keepId, $sourceHash]);
                    $existingDocId = (int) ($existingDocStmt->fetchColumn() ?: 0);

                    if ($existingDocId > 0) {
                        if (hasTable('efatura_document_lines') && hasColumn('efatura_document_lines', 'document_id')) {
                            $pdo->prepare('DELETE FROM efatura_document_lines WHERE document_id = ?')->execute([$documentId]);
                        }
                        $pdo->prepare('DELETE FROM efatura_documents WHERE id = ?')->execute([$documentId]);
                    } else {
                        $pdo->prepare('UPDATE efatura_documents SET entity_id = ? WHERE id = ?')->execute([$keepId, $documentId]);
                    }
                }
            }

            if ($dropNif !== '' && $keptNif !== '' && hasTable('accounting_entity_ai_instructions')) {
                $pdo->prepare(
                    'INSERT INTO accounting_entity_ai_instructions (acquirer_nif, emitter_nif, instructions)
                     SELECT ?, emitter_nif, instructions
                     FROM accounting_entity_ai_instructions
                     WHERE acquirer_nif = ?
                     ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP'
                )->execute([$keptNif, $dropNif]);
                $pdo->prepare('DELETE FROM accounting_entity_ai_instructions WHERE acquirer_nif = ?')->execute([$dropNif]);
            }

            if ($dropNif !== '' && $keptNif !== '' && hasTable('supplier_documents')) {
                $pdo->prepare(
                    'INSERT IGNORE INTO supplier_documents (emitter, acquirer, doc_codigo, erp_codigo, created_at, updated_at)
                     SELECT emitter, ?, doc_codigo, erp_codigo, created_at, updated_at
                     FROM supplier_documents
                     WHERE acquirer = ?'
                )->execute([$keptNif, $dropNif]);
                $pdo->prepare('DELETE FROM supplier_documents WHERE acquirer = ?')->execute([$dropNif]);
            }

            if ($dropNif !== '' && $keptNif !== '' && hasTable('accounting_classifications') && hasColumn('accounting_classifications', 'acquirer')) {
                $pdo->prepare('UPDATE accounting_classifications SET acquirer = ? WHERE acquirer = ?')->execute([$keptNif, $dropNif]);
            }

            if ($dropNif !== '' && $keptNif !== '' && hasTable('accounting_imports') && hasColumn('accounting_imports', 'field_B')) {
                $pdo->prepare('UPDATE accounting_imports SET field_B = ? WHERE field_B = ?')->execute([$keptNif, $dropNif]);
            }

            $pdo->prepare('DELETE FROM accounting_entities WHERE id = ?')->execute([$dropId]);
            $removedIds[] = $dropId;
            $removedNifs[] = $dropNif;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'merged' => count($removedIds),
        'kept_id' => $keepId,
        'removed_ids' => $removedIds,
        'kept_nif' => $keptNif,
        'removed_nifs' => $removedNifs,
    ];
}

function resolveAccountingEntityDatabase(array $entity): string {
    return normalizeAccountingEntityDatabaseKey((string) ($entity['erp_database'] ?? ''));
}

/**
 * Persist accounting entity information locally.
 *
 * @param PDO  $pdo    Active database connection.
 * @param array $data  Associative array with entity fields, including the ERP client code.
 * @return void
 */
function saveAccountingEntity(PDO $pdo, array $data): void {
    $hasSubmittedEmitterType = array_key_exists('emitter_type', $data) || array_key_exists('is_bank_entity', $data);
    $data = normalizeAccountingEntityStoragePayload($data);
    $nif = trim((string) ($data['nif'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $erpDatabase = normalizeAccountingEntityDatabaseKey((string) ($data['erp_database'] ?? ''));
    $entityType = trim((string) ($data['entity_type'] ?? ''));
    $erpClientCode = trim((string) ($data['erp_client_code'] ?? ''));
    $emitterTypeValue = trim((string) ($data['emitter_type'] ?? '0'));
    $emitterType = in_array($emitterTypeValue, ['1', '2'], true) ? $emitterTypeValue : '0';
    $emitterTypeColumn = getAccountingEmitterTypeColumn();

    if ($nif === '') {
        throw new InvalidArgumentException('NIF inválido para guardar entidade contabilística.');
    }
    if ($entityType === '') {
        $entityType = 'acquirer';
    }

    $existing = findAccountingEntityByType($pdo, $nif, $entityType);
    if ($existing === null) {
        $existing = findAccountingEntity($pdo, $nif);
    }

    if ($entityType === 'acquirer') {
        if ($erpDatabase === '' && is_array($existing) && !empty($existing['id'])) {
            // An update that omits erp_database must never blank out a value the
            // entity already had — only an explicit non-empty value may change it.
            $erpDatabase = normalizeAccountingEntityDatabaseKey((string) ($existing['erp_database'] ?? ''));
        }
        if ($erpDatabase === '') {
            throw new RuntimeException('E obrigatorio indicar a base de dados ERP (empresa) desta entidade.');
        }
    }

    if ($entityType === 'acquirer' && $erpDatabase !== '') {
        $existingByDatabase = findAccountingAcquirerEntityByDatabase($pdo, $erpDatabase);
        if (
            is_array($existingByDatabase)
            && !empty($existingByDatabase['id'])
            && (int) ($existingByDatabase['id'] ?? 0) !== (int) ($existing['id'] ?? 0)
        ) {
            throw new RuntimeException(
                'Ja existe uma empresa associada a esta base ERP (' . $erpDatabase . '): '
                . trim((string) ($existingByDatabase['name'] ?? ''))
                . ' [' . trim((string) ($existingByDatabase['nif'] ?? '')) . '].'
            );
        }
    }

    if (is_array($existing) && !empty($existing['id'])) {
        $qrDocTypeMappings = array_key_exists('qr_doc_type_mappings', $data)
            ? (string) $data['qr_doc_type_mappings']
            : (string) ($existing['qr_doc_type_mappings'] ?? '');
        $updateSql = 'UPDATE accounting_entities SET name = ?, erp_database = ?, entity_type = ?, erp_client_code = ?, qr_doc_type_mappings = ?';
        $updateValues = [
            $name,
            $erpDatabase,
            $entityType,
            $erpClientCode,
            $qrDocTypeMappings,
        ];
        if ($emitterTypeColumn !== '') {
            $updateSql .= ', ' . $emitterTypeColumn . ' = ?';
            $existingEmitterType = trim((string) ($existing['emitter_type'] ?? '0'));
            $updateValues[] = $hasSubmittedEmitterType
                ? $emitterType
                : (in_array($existingEmitterType, ['1', '2'], true) ? $existingEmitterType : '0');
        }
        $updateSql .= ' WHERE id = ?';
        $updateValues[] = (int) $existing['id'];
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute($updateValues);
        return;
    }

    $qrDocTypeMappings = array_key_exists('qr_doc_type_mappings', $data)
        ? (string) $data['qr_doc_type_mappings']
        : '';
    if ($emitterTypeColumn !== '') {
        if (hasAccountingEntityUuidColumn()) {
            $stmt = $pdo->prepare(
                'INSERT INTO accounting_entities (uuid, nif, name, erp_database, entity_type, erp_client_code, ' . $emitterTypeColumn . ', qr_doc_type_mappings) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([generateAccountingEntityUuid(), $nif, $name, $erpDatabase, $entityType, $erpClientCode, $emitterType, $qrDocTypeMappings]);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO accounting_entities (nif, name, erp_database, entity_type, erp_client_code, ' . $emitterTypeColumn . ', qr_doc_type_mappings) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$nif, $name, $erpDatabase, $entityType, $erpClientCode, $emitterType, $qrDocTypeMappings]);
        return;
    }

    if (hasAccountingEntityUuidColumn()) {
        $stmt = $pdo->prepare(
            'INSERT INTO accounting_entities (uuid, nif, name, erp_database, entity_type, erp_client_code, qr_doc_type_mappings) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([generateAccountingEntityUuid(), $nif, $name, $erpDatabase, $entityType, $erpClientCode, $qrDocTypeMappings]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO accounting_entities (nif, name, erp_database, entity_type, erp_client_code, qr_doc_type_mappings) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$nif, $name, $erpDatabase, $entityType, $erpClientCode, $qrDocTypeMappings]);
}

function findAccountingEntityNameFromEfatura(PDO $pdo, string $nif): string {
    $normalizedNif = preg_replace('/\D+/', '', trim($nif)) ?? '';
    if ($normalizedNif === '' || !hasTable('efatura_documents')) {
        return '';
    }

    $stmt = $pdo->prepare(
        'SELECT issuer_name
         FROM efatura_documents
         WHERE REPLACE(REPLACE(REPLACE(TRIM(issuer_vat), \' \', \'\'), \'-\', \'\'), \'.\', \'\') = ?
           AND TRIM(COALESCE(issuer_name, \'\')) <> \'\'
         ORDER BY invoice_date DESC, id DESC
         LIMIT 1'
    );
    $stmt->execute([$normalizedNif]);
    $name = $stmt->fetchColumn();
    return is_string($name) ? trim($name) : '';
}

/**
 * Derive a human readable name for the entity from the raw field value.
 *
 * @param string $rawFieldValue Original emitter/acquirer field.
 * @param string $nif           VAT number extracted from the field value.
 * @return string Derived name.
 */
function deriveEntityNameFromField(string $rawFieldValue, string $nif): string {
    $value = trim($rawFieldValue);
    if ($value === '') {
        return 'Cliente ' . $nif;
    }

    $value = preg_replace('/' . preg_quote($nif, '/') . '/', '', $value);
    if (strpos($value, '-') !== false) {
        $parts = array_map('trim', explode('-', $value));
        foreach ($parts as $part) {
            if ($part !== '' && !preg_match('/^\d+$/', $part)) {
                return preg_replace('/\s+/', ' ', $part);
            }
        }
    }

    $value = preg_replace('/\d+/', '', $value);
    $value = preg_replace('/[\-–—,:]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    $value = trim($value);

    return $value !== '' ? $value : 'Cliente ' . $nif;
}

/**
 * Determine whether a stored entity name is just a placeholder derived from the VAT number.
 *
 * @param string|null $name Stored name.
 * @param string      $nif  VAT number associated with the entity.
 * @return bool Whether the name should be refreshed from the ERP webservice.
 */
function isPlaceholderAccountingEntityName(?string $name, string $nif): bool {
    $normalized = trim((string) $name);
    if ($normalized === '') {
        return true;
    }

    $normalized = strtoupper(preg_replace('/\s+/', ' ', $normalized));
    $compact = preg_replace('/[^A-Z0-9]/', '', $normalized);
    $expectedCompact = 'CLIENTE' . $nif;

    return $compact === $expectedCompact;
}

/**
 * Determine whether a `strValor` returned by `/tabelas/configEmpresa` can plausibly
 * be a company name.
 *
 * `configEmpresa` maps to `Tbl_Configuracao_Empresa(Id, strValor)`, a generic
 * key/value settings table: the meaning of a given `Id` differs from one `emp_XXX`
 * database to the next. Looking up a fixed `Id` therefore sometimes returns an
 * unrelated setting instead of the company name — most visibly a boolean, which the
 * ERP serialises as the literal string `False`/`True`. Callers use this guard to
 * reject such values and fall through to the heuristic scan of every config row.
 *
 * @param string $value Raw `strValor` returned by the ERP.
 * @return bool Whether the value looks like a real company name.
 */
function isPlausibleErpCompanyName(string $value): bool {
    $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if ($normalized === '' || mb_strlen($normalized) < 3) {
        return false;
    }

    // Booleans and numeric flags surfaced by the ERP as strings.
    $upper = mb_strtoupper($normalized, 'UTF-8');
    $booleanish = ['FALSE', 'TRUE', 'FALSO', 'VERDADEIRO', 'NULL', 'NONE', 'N/A', 'SIM', 'NAO', 'NÃO'];
    if (in_array($upper, $booleanish, true)) {
        return false;
    }

    // A name must contain letters; pure numbers, dates, money or symbols are settings.
    if (!preg_match('/[A-Za-zÀ-ÿ]/u', $normalized)) {
        return false;
    }

    // Paths, URLs and connection strings are common config values.
    if (preg_match('#[\\\\/|@]|https?:#i', $normalized)) {
        return false;
    }

    return true;
}

/**
 * Ensure that an accounting entity exists locally, fetching it from the ERP
 * when necessary.
 *
 * @param PDO    $pdo              Active database connection.
 * @param string $entityFieldValue Raw entity value from the import (e.g., field_A).
 * @param array|null $defaults     Optional default values (e.g., entity_type, erp_database).
 * @return array|null Entity information if available.
 */
function ensureAccountingEntity(PDO $pdo, string $entityFieldValue, ?array $defaults = null): ?array {
    static $cache = [];

    $nif = extractVatNumber($entityFieldValue);
    if ($nif === '') {
        return null;
    }

    $defaults = is_array($defaults) ? $defaults : [];
    $defaultEntityType = trim((string) ($defaults['entity_type'] ?? ''));
    if ($defaultEntityType === '') {
        $defaultEntityType = 'emitter';
    }
    $defaultErpDatabase = null;
    if (array_key_exists('erp_database', $defaults)) {
        $defaultErpDatabase = trim((string) $defaults['erp_database']);
    }

    if (array_key_exists($nif, $cache)) {
        $cachedEntity = $cache[$nif];
        if (
            is_array($cachedEntity)
            && $defaultErpDatabase !== null
            && $defaultErpDatabase !== ''
            && resolveAccountingEntityDatabase($cachedEntity) === ''
        ) {
            unset($cache[$nif]);
        } else {
            return $cachedEntity ?: null;
        }
    }

    $existing = null;
    try {
        $existing = findAccountingEntity($pdo, $nif);
        if ($existing !== null) {
            $existingDatabase = resolveAccountingEntityDatabase($existing);
            $requiresDatabaseUpdate = $defaultErpDatabase !== null
                && $defaultErpDatabase !== ''
                && $existingDatabase === '';
            $existingEntityType = trim((string) ($existing['entity_type'] ?? '')) !== ''
                ? trim((string) ($existing['entity_type'] ?? ''))
                : $defaultEntityType;
            $existingDatabaseForCode = resolveAccountingEntityDatabase($existing);
            if ($existingDatabaseForCode === '' && $defaultErpDatabase !== null && $defaultErpDatabase !== '') {
                $existingDatabaseForCode = $defaultErpDatabase;
            }
            $resolvedClientCode = trim((string) ($existing['erp_client_code'] ?? ''));
            if ($existingEntityType === 'acquirer') {
                $resolvedClientCode = fetchAccountingAcquirerClientCodeFromBaseErp($nif, $resolvedClientCode, $existingDatabaseForCode);
            }
            $requiresClientCodeUpdate = $existingEntityType === 'acquirer'
                && $resolvedClientCode !== trim((string) ($existing['erp_client_code'] ?? ''));

            if ($requiresDatabaseUpdate || $requiresClientCodeUpdate) {
                saveAccountingEntity($pdo, [
                    'nif' => $nif,
                    'name' => trim((string) ($existing['name'] ?? '')),
                    'erp_database' => $requiresDatabaseUpdate ? $defaultErpDatabase : $existingDatabase,
                    'erp_client_code' => $resolvedClientCode,
                    'entity_type' => $existingEntityType,
                    'qr_doc_type_mappings' => array_key_exists('qr_doc_type_mappings', $existing)
                        ? (string) ($existing['qr_doc_type_mappings'] ?? '')
                        : '',
                ]);
                $existing = findAccountingEntity($pdo, $nif);
            }

            if ($existing !== null && !isPlaceholderAccountingEntityName($existing['name'] ?? '', $nif)) {
                $cache[$nif] = $existing;
                return $existing;
            }
        }
    } catch (Throwable $e) {
        logErpMessage('Erro ao pesquisar entidade ' . $nif . ': ' . $e->getMessage());
        $cache[$nif] = null;
        return null;
    }

    $remote = fetchAccountingEntityFromErp($nif, $defaultEntityType, false, $defaultErpDatabase ?? '');
    if ($remote === null) {
        $efaturaName = findAccountingEntityNameFromEfatura($pdo, $nif);
        if ($efaturaName !== '') {
            $fallbackEntityType = trim((string) ($existing['entity_type'] ?? '')) !== ''
                ? trim((string) ($existing['entity_type'] ?? ''))
                : $defaultEntityType;
            $fallbackClientCode = trim((string) ($existing['erp_client_code'] ?? ''));
            if ($fallbackEntityType === 'acquirer') {
                $fallbackDatabase = trim((string) ($existing['erp_database'] ?? ($defaultErpDatabase ?? '')));
                $fallbackClientCode = fetchAccountingAcquirerClientCodeFromBaseErp($nif, $fallbackClientCode, $fallbackDatabase);
            }
            $fallbackData = [
                'nif' => $nif,
                'name' => $efaturaName,
                'erp_database' => trim((string) ($existing['erp_database'] ?? ($defaultErpDatabase ?? ''))),
                'erp_client_code' => $fallbackClientCode,
                'entity_type' => $fallbackEntityType,
            ];

            try {
                saveAccountingEntity($pdo, $fallbackData);
                $stored = findAccountingEntity($pdo, $nif);
                if ($stored !== null) {
                    $cache[$nif] = $stored;
                    return $stored;
                }
            } catch (Throwable $e) {
                logErpMessage('Erro ao guardar nome e-Fatura da entidade ' . $nif . ': ' . $e->getMessage());
            }
        }

        if ($existing !== null) {
            $cache[$nif] = $existing;
            return $existing;
        }

        $cache[$nif] = null;
        return null;
    }

    $name = trim((string) ($remote['name'] ?? ''));
    if ($name === '') {
        $name = findAccountingEntityNameFromEfatura($pdo, $nif);
    }
    if ($name === '') {
        $name = deriveEntityNameFromField($entityFieldValue, $nif);
    }

    $entityType = trim((string) ($remote['entity_type'] ?? ''));
    if ($entityType === '') {
        $entityType = $defaultEntityType;
    }

    $erpDatabase = trim((string) ($remote['erp_database'] ?? ''));
    if ($erpDatabase === '' && $defaultErpDatabase !== null) {
        $erpDatabase = $defaultErpDatabase;
    }

    $erpClientCode = trim((string) ($remote['erp_client_code'] ?? ''));
    if ($entityType === 'acquirer') {
        $erpClientCode = fetchAccountingAcquirerClientCodeFromBaseErp($nif, $erpClientCode, $erpDatabase);
    }

    $data = [
        'nif' => $nif,
        'name' => $name,
        'erp_database' => $erpDatabase,
        'erp_client_code' => $erpClientCode,
        'entity_type' => $entityType,
    ];

    try {
        saveAccountingEntity($pdo, $data);
        $stored = findAccountingEntity($pdo, $nif);
        $cache[$nif] = $stored;
        return $stored;
    } catch (Throwable $e) {
        logErpMessage('Erro ao guardar entidade ' . $nif . ': ' . $e->getMessage());
        $cache[$nif] = null;
        return null;
    }
}

/**
 * Parse an invoice line from a text string produced by OCR.
 *
 * @param string $text OCR text for a single invoice line.
 * @return array Associative array with extracted fields.
 * @throws RuntimeException If the text does not contain the expected columns.
 */
function parseInvoiceLineText(string $text): array {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    $tokens = explode(' ', $text);
    if (count($tokens) < 10) {
        throw new RuntimeException('Unexpected OCR output: ' . $text);
    }
    // Extract trailing numeric columns
    $imposto = array_pop($tokens);
    $valorLiquido = array_pop($tokens);
    $descontoValor = array_pop($tokens);
    $percentDesc = array_pop($tokens);
    $precoUnitario = array_pop($tokens);
    $unidade = array_pop($tokens);
    $quantidade = array_pop($tokens);
    // Remaining tokens contain arm, product code and description
    $arm = array_shift($tokens);
    $codigo = array_shift($tokens);
    $descricao = implode(' ', $tokens);
    $toFloat = fn(string $value): float => (float) str_replace(',', '.', $value);
    return [
        'arm' => (int) $arm,
        'codigo_artigo' => $codigo,
        'descricao' => $descricao,
        'quantidade' => $toFloat($quantidade),
        'unidade' => $unidade,
        'preco_unitario' => $toFloat($precoUnitario),
        'percentagem_desconto' => $toFloat($percentDesc),
        'desconto_valor' => $toFloat($descontoValor),
        'valor_liquido' => $toFloat($valorLiquido),
        'imposto' => $toFloat($imposto),
    ];
}

/**
 * Parse an invoice line directly from an image by running OCR.
 *
 * @param string $imagePath Path to the image file containing a single line.
 * @return array Parsed invoice line data.
 */
function parseInvoiceLineImage(string $imagePath): array {
    $text = (new TesseractOCR($imagePath))
        ->lang('por')
        ->run();
    return parseInvoiceLineText($text);
}

/**
 * Extract raw OCR text from an image using Tesseract.
 *
 * @param string $imagePath Path to the image file.
 * @param string $language OCR language code.
 * @return string
 */
function extractOcrTextFromImage(string $imagePath, string $language = 'por'): string {
    if (!is_file($imagePath)) {
        throw new RuntimeException('Imagem OCR inválida.');
    }

    if (class_exists(TesseractOCR::class)) {
        $ocr = new TesseractOCR($imagePath);
        if (trim($language) !== '') {
            $ocr->lang($language);
        }

        return trim((string) $ocr->run());
    }

    $tesseractBin = trim((string) shell_exec('command -v tesseract 2>/dev/null'));
    if ($tesseractBin === '') {
        throw new RuntimeException('Tesseract não disponível.');
    }

    $command = escapeshellarg($tesseractBin)
        . ' ' . escapeshellarg($imagePath)
        . ' stdout';

    if (trim($language) !== '') {
        $command .= ' -l ' . escapeshellarg($language);
    }

    $command .= ' 2>/dev/null';
    $output = shell_exec($command);
    if (!is_string($output)) {
        throw new RuntimeException('Falha ao executar Tesseract CLI.');
    }

    return trim($output);
}

/**
 * Extract invoice lines using AWS Textract via a Python helper script.
 * Returns an array of line items with the same structure as
 * parseInvoiceLineText along with the raw text.
 *
 * @param string $filePath Path to the document image or PDF.
 * @return array<int,array<string,mixed>> Parsed line items.
 * @throws RuntimeException When Textract fails.
 */
function parseInvoiceLineTextract(string $filePath, ?array &$diagnostics = null): array {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'tiff', 'tif'];
    if (! in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Formato de arquivo não suportado pelo Textract');
    }

    $key = getSetting('aws_access_key_id', getenv('AWS_ACCESS_KEY_ID') ?: '');
    $secret = getSetting('aws_secret_access_key', getenv('AWS_SECRET_ACCESS_KEY') ?: '');
    $region = getSetting('aws_region', getenv('AWS_REGION') ?: 'us-east-1');
    $bucket = getSetting('aws_textract_bucket', getenv('AWS_TEXTRACT_BUCKET') ?: '');

    if (! $bucket) {
        $slug = getCompanySlug();
        if ($slug) {
            $bucket = $slug;
        }
    }

    if (! $bucket) {
        throw new RuntimeException('Bucket S3 para Textract não configurado');
    }

    $env = array_filter([
        'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: null,
        'AWS_ACCESS_KEY_ID' => $key,
        'AWS_SECRET_ACCESS_KEY' => $secret,
        'AWS_REGION' => $region,
        'AWS_DEFAULT_REGION' => $region,
        'AWS_TEXTRACT_BUCKET' => $bucket,
    ], static function ($value): bool {
        return $value !== null;
    });
    $diagnostics = [
        'file_exists' => file_exists($filePath),
        'file_size' => file_exists($filePath) ? filesize($filePath) : null,
        'extension' => $extension,
        'region' => $region,
        'bucket_configured' => $bucket !== '',
        'key_configured' => $key !== '',
        'secret_configured' => $secret !== '',
        'path_configured' => trim((string) ($env['PATH'] ?? '')) !== '',
    ];

    $pythonBinary = getenv('TEXTRACT_PYTHON_BIN') ?: '';
    $pythonSource = 'env';
    if ($pythonBinary === '' && function_exists('shell_exec')) {
        $candidate = trim((string) shell_exec('command -v python3 2>/dev/null'));
        if ($candidate !== '' && is_executable($candidate)) {
            $pythonBinary = $candidate;
            $pythonSource = 'command -v python3';
        }
    }
    if ($pythonBinary === '' && is_executable('/usr/bin/python3')) {
        $pythonBinary = '/usr/bin/python3';
        $pythonSource = '/usr/bin/python3';
    }
    if ($pythonBinary === '') {
        $pythonBinary = 'python3';
        $pythonSource = 'fallback python3';
    }
    $diagnostics['python_binary'] = $pythonBinary;
    $diagnostics['python_source'] = $pythonSource;
    logOcrMessage('Textract python selected: ' . $pythonBinary . ' (source=' . $pythonSource . ') for file ' . basename($filePath));

    $script = __DIR__ . '/textract.py';
    $cmd = escapeshellcmd($pythonBinary) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($filePath);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($cmd, $descriptor, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Falha ao iniciar script Textract');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $diagnostics['exit_status'] = $status;
    $diagnostics['stderr'] = trim($error);
    $diagnostics['stdout_length'] = strlen((string) $output);
    if ($status !== 0) {
        $errorSummary = trim((string) $error);
        if ($errorSummary === '') {
            $errorSummary = 'exit_status=' . (string) $status;
        }
        if (function_exists('mb_substr')) {
            $errorSummary = mb_substr($errorSummary, 0, 500, 'UTF-8');
        } else {
            $errorSummary = substr($errorSummary, 0, 500);
        }
        logOcrMessage('Textract script error: ' . $errorSummary);
        throw new RuntimeException('Falha no OCR Textract: ' . $errorSummary);
    }
    $data = json_decode($output, true);
    if (! is_array($data)) {
        $diagnostics['json_error'] = json_last_error_msg();
        throw new RuntimeException('Saída inválida do Textract');
    }
    $diagnostics['line_count'] = count($data);
    return $data;
}

/**
 * Retrieve the default VAT rates and their display labels.
 *
 * @return array<string,string>
 */
function getDefaultVatRates(): array {
    return [
        '0' => '0%',
        '6' => '6%',
        '13' => '13%',
        '23' => '23%',
    ];
}

/**
 * Build a fallback label for a VAT rate when none is explicitly provided.
 */
function buildVatRateLabel(string $rate): string {
    $defaults = getDefaultVatRates();
    if (array_key_exists($rate, $defaults)) {
        return $defaults[$rate];
    }

    $trimmed = trim($rate);
    if ($trimmed === '') {
        return '';
    }

    $normalized = str_replace(',', '.', $trimmed);
    if (is_numeric($normalized)) {
        $formatted = rtrim(rtrim($normalized, '0'), '.');
        if ($formatted === '') {
            $formatted = '0';
        }
        return $formatted . '%';
    }

    return $trimmed;
}

/**
 * Normalize a VAT rate key into a canonical string representation.
 */
function normalizeAccountingRateKey(string $value): string {
    $clean = trim(str_replace('%', '', $value));
    if ($clean === '') {
        return '';
    }

    $clean = str_replace(',', '.', $clean);
    if (!is_numeric($clean)) {
        return $clean;
    }

    $num = (float) $clean;
    if ($num > 0 && $num <= 1) {
        $num *= 100;
    }
    $num = round($num, 2);

    if (abs($num - round($num)) < 0.001) {
        return (string) (int) round($num);
    }

    return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
}

/**
 * Attempt to extract a trimmed string value from a mixed data structure.
 *
 * Some legacy accounting configurations store account identifiers inside
 * nested arrays (e.g. {"value": "123"}). Newer payloads provide plain
 * strings. This helper normalises both representations while ignoring
 * unexpected structures to avoid PHP "Array to string conversion" notices.
 *
 * @param mixed $value Raw value to normalise.
 * @param string[] $preferredKeys Keys that should be inspected first when
 *                                 traversing nested arrays.
 * @return string|null Trimmed string or null when no scalar value is present.
 */
function extractStringValue($value, array $preferredKeys = []): ?string {
    if (is_string($value) || is_numeric($value)) {
        return trim((string) $value);
    }

    if (!is_array($value)) {
        return null;
    }

    foreach ($preferredKeys as $key) {
        if (array_key_exists($key, $value)) {
            $candidate = extractStringValue($value[$key], $preferredKeys);
            if ($candidate !== null) {
                return $candidate;
            }
        }
    }

    if (array_key_exists('value', $value)) {
        $candidate = extractStringValue($value['value'], $preferredKeys);
        if ($candidate !== null) {
            return $candidate;
        }
    }

    foreach ($value as $nested) {
        $candidate = extractStringValue($nested, $preferredKeys);
        if ($candidate !== null) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Determine whether the provided value resembles an account reference.
 *
 * Older payloads may store account identifiers inside the generic "iva"
 * field. To keep backwards compatibility while allowing the same key to
 * represent monetary amounts, this helper attempts to differentiate both
 * cases by looking at the structure and characters present in the value.
 *
 * @param mixed $value
 */
function looksLikeAccountReference($value): bool {
    if (is_array($value)) {
        return array_key_exists('account', $value) || array_key_exists('code', $value);
    }

    if (!is_string($value) && !is_numeric($value)) {
        return false;
    }

    $string = trim((string) $value);
    if ($string === '') {
        return false;
    }

    // Numeric VAT amounts are always normalised with decimal separators.
    if (strpos($string, '.') !== false || strpos($string, ',') !== false) {
        return false;
    }

    return true;
}

/**
 * Return the default metadata structure used alongside VAT rate mappings.
 */
function normalizeAccountingMetadataFlag($value): string {
    $flag = trim((string) $value);
    return ($flag === '1' || strcasecmp($flag, 'true') === 0) ? '1' : '0';
}

function resolveAccountingVatDeductionPercent(array $rateConfig): float {
    $rubricCode = normalizeAccountingRubricCodeValue($rateConfig['erp_rubric_code'] ?? '');
    if ($rubricCode !== '' && isAccountingFuelRubricCode($rubricCode)) {
        return 50.0;
    }

    return 100.0;
}

function reverseAccountingVatDeductionToGrossAmounts(?float $baseAmount, ?float $ivaAmount, string $rate): array {
    $resolvedBase = $baseAmount;
    $resolvedIva = $ivaAmount;
    $normalizedRate = normalizeAccountingRateKey($rate);
    if ($normalizedRate === '' || !is_numeric($normalizedRate)) {
        return [
            'base' => $resolvedBase,
            'iva' => $resolvedIva,
        ];
    }

    $ratePercent = (float) $normalizedRate;
    if (!is_finite($ratePercent) || $ratePercent <= 0.00001) {
        return [
            'base' => $resolvedBase,
            'iva' => $resolvedIva,
        ];
    }

    if ($resolvedBase !== null) {
        $resolvedBase = round($resolvedBase * (100 / (100 + ($ratePercent / 2))), 2);
    } elseif ($resolvedIva !== null) {
        $resolvedBase = round(($resolvedIva * 200) / $ratePercent, 2);
    }

    if ($resolvedIva !== null) {
        $resolvedIva = round($resolvedIva * 2, 2);
    } elseif ($resolvedBase !== null) {
        $resolvedIva = round($resolvedBase * ($ratePercent / 100), 2);
    }

    return [
        'base' => $resolvedBase,
        'iva' => $resolvedIva,
    ];
}

function normalizeAccountingVatAdjustmentState(string $rate, array $entry): array {
    $isAdjusted = normalizeAccountingMetadataFlag($entry['vat_amounts_adjusted'] ?? '0') === '1';
    if (!$isAdjusted) {
        unset($entry['vat_amounts_adjusted']);
        return $entry;
    }

    if (resolveAccountingVatDeductionPercent($entry) < 99.999) {
        $entry['vat_amounts_adjusted'] = '1';
        return $entry;
    }

    $baseAmount = resolveAccountingLineAmount($entry['base'] ?? '', null);
    $ivaAmount = resolveAccountingLineAmount($entry['iva'] ?? '', null);
    $reverted = reverseAccountingVatDeductionToGrossAmounts($baseAmount, $ivaAmount, $rate);

    if ($reverted['base'] !== null) {
        $entry['base'] = number_format((float) $reverted['base'], 2, '.', '');
    }
    if ($reverted['iva'] !== null) {
        $entry['iva'] = number_format((float) $reverted['iva'], 2, '.', '');
    }

    unset($entry['vat_amounts_adjusted']);

    return $entry;
}

function isAccountingVatAmountsAdjusted(array $rateConfig): bool {
    return normalizeAccountingMetadataFlag($rateConfig['vat_amounts_adjusted'] ?? '0') === '1';
}

function shouldAdjustAccountingVatAmountsForDisplay(array $rateConfig): bool {
    if (resolveAccountingVatDeductionPercent($rateConfig) >= 99.999) {
        return false;
    }

    $generalAccount = trim((string) ($rateConfig['general_account'] ?? ''));
    $ivaAccount = trim((string) ($rateConfig['iva_account'] ?? ''));

    return preg_match('/^\d{3,}$/', $generalAccount) === 1
        && preg_match('/^\d{3,}$/', $ivaAccount) === 1;
}

function applyAccountingVatDeductionToAmounts(?float $baseAmount, ?float $ivaAmount, array $rateConfig): array {
    $resolvedBase = $baseAmount;
    $resolvedIva = $ivaAmount;
    $deductionPercent = resolveAccountingVatDeductionPercent($rateConfig);

    if ($resolvedIva === null || $deductionPercent >= 99.999) {
        return [
            'base' => $resolvedBase,
            'iva' => $resolvedIva,
            'vat_deduction_percent' => $deductionPercent,
        ];
    }

    $deductibleShare = max(0.0, min(1.0, $deductionPercent / 100));
    $deductibleIva = round($resolvedIva * $deductibleShare, 2);
    $nonDeductibleIva = round($resolvedIva - $deductibleIva, 2);

    if ($resolvedBase === null) {
        $resolvedBase = 0.0;
    }

    return [
        'base' => round($resolvedBase + $nonDeductibleIva, 2),
        'iva' => $deductibleIva,
        'vat_deduction_percent' => $deductionPercent,
    ];
}

function adjustAccountingRatesForDisplay(array $rates): array {
    $sanitized = sanitizeAccountInput($rates);

    foreach ($sanitized as $rate => $entry) {
        if (!is_array($entry) || isAccountingVatAmountsAdjusted($entry)) {
            continue;
        }

        if (!shouldAdjustAccountingVatAmountsForDisplay($entry)) {
            continue;
        }

        $baseAmount = resolveAccountingLineAmount($entry['base'] ?? '', null);
        $ivaAmount = resolveAccountingLineAmount($entry['iva'] ?? '', null);
        if ($baseAmount === null && $ivaAmount === null) {
            continue;
        }

        $adjusted = applyAccountingVatDeductionToAmounts($baseAmount, $ivaAmount, $entry);
        if ($adjusted['base'] !== null) {
            $sanitized[$rate]['base'] = number_format((float) $adjusted['base'], 2, '.', '');
        }
        if ($adjusted['iva'] !== null) {
            $sanitized[$rate]['iva'] = number_format((float) $adjusted['iva'], 2, '.', '');
        }
        $sanitized[$rate]['vat_amounts_adjusted'] = '1';
    }

    return $sanitized;
}

function adjustAccountingOriginalRatesForDisplay(array $originalRates, array $accounts): array {
    $result = normalizeOriginalRatesPayload($originalRates);
    $normalizedAccounts = sanitizeAccountInput($accounts);

    foreach ($result as $rate => $entry) {
        $accountEntry = $normalizedAccounts[$rate] ?? null;
        if (!is_array($accountEntry) || isAccountingVatAmountsAdjusted($accountEntry)) {
            continue;
        }

        if (!shouldAdjustAccountingVatAmountsForDisplay($accountEntry)) {
            continue;
        }

        $baseAmount = resolveAccountingLineAmount($entry['base'] ?? '', null);
        $ivaAmount = resolveAccountingLineAmount($entry['iva'] ?? '', null);
        if ($baseAmount === null && $ivaAmount === null) {
            continue;
        }

        $adjusted = applyAccountingVatDeductionToAmounts($baseAmount, $ivaAmount, $accountEntry);
        if ($adjusted['base'] !== null) {
            $result[$rate]['base'] = number_format((float) $adjusted['base'], 2, '.', '');
        }
        if ($adjusted['iva'] !== null) {
            $result[$rate]['iva'] = number_format((float) $adjusted['iva'], 2, '.', '');
        }
    }

    return $result;
}

/**
 * Return the default metadata structure used alongside VAT rate mappings.
 */
function defaultAccountingMetadata(): array {
    return [
        'total_account' => '',
        'receipt_total_account' => '',
        'manual_review_required' => '0',
        'ignore_detected_rates' => '0',
        'classification_model_name' => '',
        'has_receipt_companion' => '0',
        'manual_document_fields' => '0',
    ];
}

/**
 * Extract metadata (e.g. total account) from a stored accounting JSON blob.
 *
 * @param string|null $json Raw JSON stored in the database.
 * @return array<string,string>
 */
function normalizeAccountingMetadata(?string $json): array {
    $result = defaultAccountingMetadata();
    if ($json === null || trim($json) === '') {
        return $result;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $result;
    }

    $candidates = [$decoded];
    if (isset($decoded['meta']) && is_array($decoded['meta'])) {
        $candidates[] = $decoded['meta'];
    }

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        if (array_key_exists('total_account', $candidate)) {
            $value = extractStringValue($candidate['total_account'], ['account', 'code', 'value']);
            if ($value !== null) {
                $result['total_account'] = $value;
            }
        }
        if (array_key_exists('receipt_total_account', $candidate)) {
            $value = extractStringValue($candidate['receipt_total_account'], ['account', 'code', 'value']);
            if ($value !== null) {
                $result['receipt_total_account'] = $value;
            }
        }
        if (array_key_exists('manual_review_required', $candidate)) {
            $result['manual_review_required'] = normalizeAccountingMetadataFlag($candidate['manual_review_required']);
        }
        if (array_key_exists('ignore_detected_rates', $candidate)) {
            $result['ignore_detected_rates'] = normalizeAccountingMetadataFlag($candidate['ignore_detected_rates']);
        }
        if (array_key_exists('classification_model_name', $candidate)) {
            $value = extractStringValue($candidate['classification_model_name'], ['value', 'name', 'label']);
            if ($value !== null) {
                $result['classification_model_name'] = $value;
            }
        }
        if (array_key_exists('has_receipt_companion', $candidate)) {
            $result['has_receipt_companion'] = normalizeAccountingMetadataFlag($candidate['has_receipt_companion']);
        }
        if (array_key_exists('manual_document_fields', $candidate)) {
            $result['manual_document_fields'] = normalizeAccountingMetadataFlag($candidate['manual_document_fields']);
        }
    }

    return $result;
}

/**
 * Normalise arbitrary metadata input (string or array) into the expected shape.
 *
 * @param mixed $input
 * @return array<string,string>
 */
function sanitizeAccountingMetadata($input): array {
    $result = defaultAccountingMetadata();
    $source = null;

    if (is_array($input)) {
        $source = $input;
        if (isset($input['meta']) && is_array($input['meta'])) {
            $source = array_merge($input['meta'], $input);
        }
    } elseif ($input !== null) {
        $source = ['total_account' => $input];
    }

    if (is_array($source) && array_key_exists('total_account', $source)) {
        $candidate = extractStringValue($source['total_account'], ['account', 'code', 'value']);
        if ($candidate !== null) {
            $result['total_account'] = $candidate;
        }
    }

    if (is_array($source) && array_key_exists('receipt_total_account', $source)) {
        $candidate = extractStringValue($source['receipt_total_account'], ['account', 'code', 'value']);
        if ($candidate !== null) {
            $result['receipt_total_account'] = $candidate;
        }
    }

    if (is_array($source) && array_key_exists('manual_review_required', $source)) {
        $result['manual_review_required'] = normalizeAccountingMetadataFlag($source['manual_review_required']);
    }

    if (is_array($source) && array_key_exists('ignore_detected_rates', $source)) {
        $result['ignore_detected_rates'] = normalizeAccountingMetadataFlag($source['ignore_detected_rates']);
    }

    if (is_array($source) && array_key_exists('classification_model_name', $source)) {
        $candidate = extractStringValue($source['classification_model_name'], ['value', 'name', 'label']);
        if ($candidate !== null) {
            $result['classification_model_name'] = $candidate;
        }
    }

    if (is_array($source) && array_key_exists('has_receipt_companion', $source)) {
        $result['has_receipt_companion'] = normalizeAccountingMetadataFlag($source['has_receipt_companion']);
    }

    if (is_array($source) && array_key_exists('manual_document_fields', $source)) {
        $result['manual_document_fields'] = normalizeAccountingMetadataFlag($source['manual_document_fields']);
    }

    return $result;
}

/**
 * Merge metadata structures, giving precedence to override values.
 */
function mergeAccountingMetadata(array $base, array $override): array {
    $normalizedBase = sanitizeAccountingMetadata($base);
    $normalizedOverride = sanitizeAccountingMetadata($override);

    return array_merge($normalizedBase, $normalizedOverride);
}

/**
 * Determine whether any metadata field contains a non-empty value.
 */
function hasAccountingMetadataValue(array $metadata): bool {
    foreach ($metadata as $value) {
        if (is_string($value) && trim($value) !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Normalise decimal amounts into strings with two decimal places.
 *
 * @param mixed $value
 * @return string|null Returns null when no numeric value can be extracted.
 */
function extractDecimalAmount($value): ?string {
    if (is_array($value)) {
        $preferredKeys = ['value', 'amount', 'base', 'iva'];
        foreach ($preferredKeys as $key) {
            if (array_key_exists($key, $value)) {
                $candidate = extractDecimalAmount($value[$key]);
                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }
        foreach ($value as $nested) {
            $candidate = extractDecimalAmount($nested);
            if ($candidate !== null) {
                return $candidate;
            }
        }
        return null;
    }

    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $string = trim((string) $value);
    if ($string === '') {
        return '';
    }

    $normalized = preg_replace('/\s+/u', '', $string);
    if ($normalized === null) {
        $normalized = $string;
    }

    $hasComma = strpos($normalized, ',') !== false;
    $hasDot = strpos($normalized, '.') !== false;
    if ($hasComma && $hasDot) {
        if (strrpos($normalized, ',') > strrpos($normalized, '.')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }
    } elseif ($hasComma) {
        $normalized = str_replace(',', '.', $normalized);
    } elseif ($hasDot && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $normalized) === 1) {
        // Formato portugues sem casas decimais ("1.234" = mil duzentos e trinta
        // e quatro). Sem isto o ponto era lido como separador decimal e o valor
        // ficava mil vezes menor. Montantes reais escrevem-se "1.234,56", pelo
        // que grupos de exactamente 3 digitos so podem ser milhares.
        $normalized = str_replace('.', '', $normalized);
    }

    $normalized = preg_replace('/[^0-9.\-]/', '', $normalized);
    if ($normalized === null) {
        return null;
    }

    if ($normalized === '' || $normalized === '-' || $normalized === '.') {
        return '';
    }

    $firstDot = strpos($normalized, '.');
    if ($firstDot !== false) {
        $before = substr($normalized, 0, $firstDot + 1);
        $after = substr($normalized, $firstDot + 1);
        $after = str_replace('.', '', $after);
        $normalized = $before . $after;
    }

    if (!is_numeric($normalized)) {
        return null;
    }

    $number = (float) $normalized;
    if (!is_finite($number)) {
        return null;
    }

    return number_format($number, 2, '.', '');
}

/**
 * Normalize stored account information into a structure keyed by VAT rate.
 *
 * @param string|null $json JSON-encoded account data.
 * @return array<string,array<string,string>>
 */
function normalizeAccountingAccounts(?string $json): array {
    $defaults = getDefaultVatRates();
    $result = [];
    foreach ($defaults as $rate => $label) {
        $result[$rate] = [
            'iva_account' => '',
            'general_account' => '',
            'label' => $label,
            'base' => '',
            'iva' => '',
        ];
    }

    if ($json === null || $json === '') {
        return $result;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $result;
    }

    $sources = [];
    if (isset($data['rates']) && is_array($data['rates'])) {
        $sources[] = $data['rates'];
    }
    $sources[] = $data;

    $metadataKeys = ['version', 'rates', 'label', 'labels', 'title', 'meta', 'total_account'];

    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($source as $key => $value) {
            $keyString = (string) $key;
            if (in_array(strtolower($keyString), $metadataKeys, true)) {
                continue;
            }
            if (!array_key_exists($keyString, $result)) {
                $result[$keyString] = [
                    'iva_account' => '',
                    'general_account' => '',
                    'label' => buildVatRateLabel($keyString),
                    'base' => '',
                    'iva' => '',
                ];
            }
            if (is_array($value)) {
                $ivaAccount = null;
                if (array_key_exists('iva_account', $value)) {
                    $ivaAccount = extractStringValue($value['iva_account'], ['account', 'code']);
                } elseif (array_key_exists('iva', $value) && looksLikeAccountReference($value['iva'])) {
                    $ivaAccount = extractStringValue($value['iva'], ['account', 'code']);
                }
                if ($ivaAccount !== null) {
                    $result[$keyString]['iva_account'] = $ivaAccount;
                }

                $generalAccount = null;
                if (array_key_exists('general_account', $value)) {
                    $generalAccount = extractStringValue($value['general_account'], ['account', 'code']);
                } elseif (array_key_exists('general', $value)) {
                    $generalAccount = extractStringValue($value['general'], ['account', 'code']);
                }
                if ($generalAccount !== null) {
                    $result[$keyString]['general_account'] = $generalAccount;
                }

                if (array_key_exists('label', $value)) {
                    $labelValue = extractStringValue($value['label'], ['value', 'label', 'text']);
                    if ($labelValue !== null && $labelValue !== '') {
                        $result[$keyString]['label'] = $labelValue;
                    }
                }

                $erpRubricCode = null;
                if (array_key_exists('erp_rubric_code', $value)) {
                    $erpRubricCode = extractStringValue($value['erp_rubric_code'], ['value', 'code', 'label', 'text']);
                } elseif (array_key_exists('rubric_code', $value)) {
                    $erpRubricCode = extractStringValue($value['rubric_code'], ['value', 'code', 'label', 'text']);
                }
                if ($erpRubricCode !== null) {
                    $erpRubricCode = normalizeAccountingRubricCodeValue($erpRubricCode);
                    if ($erpRubricCode !== '') {
                        $result[$keyString]['erp_rubric_code'] = $erpRubricCode;
                    }
                }

                if (array_key_exists('vat_amounts_adjusted', $value)) {
                    $result[$keyString]['vat_amounts_adjusted'] = normalizeAccountingMetadataFlag($value['vat_amounts_adjusted']);
                }
                if (array_key_exists('bank_loan_conversion', $value)) {
                    $result[$keyString]['bank_loan_conversion'] = normalizeAccountingMetadataFlag($value['bank_loan_conversion']);
                }

                if (array_key_exists('cost_center_required', $value)) {
                    $costCenterFlag = trim((string) $value['cost_center_required']);
                    if ($costCenterFlag === '1' || strcasecmp($costCenterFlag, 'true') === 0) {
                        $result[$keyString]['cost_center_required'] = '1';
                    }
                }
                if (array_key_exists('base_source_field', $value)) {
                    $baseSourceCandidate = extractStringValue($value['base_source_field'], ['field', 'value', 'name', 'code']);
                    if ($baseSourceCandidate !== null && trim($baseSourceCandidate) !== '') {
                        $result[$keyString]['base_source_field'] = trim($baseSourceCandidate);
                    }
                }

                $baseValue = null;
                if (array_key_exists('base_value', $value)) {
                    $baseValue = extractDecimalAmount($value['base_value']);
                }
                if ($baseValue === null && array_key_exists('base', $value)) {
                    $baseValue = extractDecimalAmount($value['base']);
                }
                if ($baseValue !== null) {
                    $result[$keyString]['base'] = $baseValue;
                }

                $ivaValue = null;
                if (array_key_exists('iva_value', $value)) {
                    $ivaValue = extractDecimalAmount($value['iva_value']);
                }
                if ($ivaValue === null && array_key_exists('iva', $value) && !looksLikeAccountReference($value['iva'])) {
                    $ivaValue = extractDecimalAmount($value['iva']);
                }
                if ($ivaValue !== null) {
                    $result[$keyString]['iva'] = $ivaValue;
                }
            } else {
                $generalAccount = extractStringValue($value, ['account', 'code']);
                if ($generalAccount !== null) {
                    $result[$keyString]['general_account'] = $generalAccount;
                }
            }
        }
    }

    $legacyMap = [
        'iva6' => ['rate' => '6', 'field' => 'iva_account'],
        'iva13' => ['rate' => '13', 'field' => 'iva_account'],
        'iva23' => ['rate' => '23', 'field' => 'iva_account'],
        'novat' => ['rate' => '0', 'field' => 'general_account'],
    ];
    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }
        foreach ($legacyMap as $legacyKey => $info) {
            if (!array_key_exists($legacyKey, $source)) {
                continue;
            }
            $rate = $info['rate'];
            if (!array_key_exists($rate, $result)) {
                $result[$rate] = [
                    'iva_account' => '',
                    'general_account' => '',
                    'label' => buildVatRateLabel($rate),
                ];
            }
            $legacyValue = extractStringValue($source[$legacyKey], ['account', 'code']);
            if ($legacyValue !== null) {
                $result[$rate][$info['field']] = $legacyValue;
            }
        }
    }

    foreach ($result as $rate => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $result[$rate] = normalizeAccountingVatAdjustmentState((string) $rate, $entry);
    }

    return $result;
}

/**
 * Sanitize raw account input ensuring expected VAT rates are present.
 *
 * @param array<string,mixed> $input
 * @return array<string,array<string,string>>
 */
function sanitizeAccountInput(array $input): array {
    if (isset($input['rates']) && is_array($input['rates'])) {
        $input = $input['rates'];
    }

    $defaults = getDefaultVatRates();
    $detectedRates = array_keys($defaults);
    foreach ($input as $key => $_) {
        $detectedRates[] = (string) $key;
    }
    $detectedRates = array_values(array_unique(array_map('strval', $detectedRates)));

    $metadataKeys = ['version', 'rates', 'label', 'labels', 'title', 'meta', 'total_account'];

    $result = [];
    foreach ($detectedRates as $rate) {
        $normalizedRateKey = strtolower((string) $rate);
        if (in_array($normalizedRateKey, $metadataKeys, true)) {
            continue;
        }
        $rateInput = $input[$rate] ?? ($input[$normalizedRateKey] ?? null);
        $ivaAccount = '';
        $generalAccount = '';
        $label = '';
        $baseValue = '';
        $ivaValue = '';
        $erpRubricCode = '';
        $vatAmountsAdjusted = '0';
        $bankLoanConversion = '0';
        $costCenterRequired = false;
        $baseSourceField = '';

        if (is_array($rateInput)) {
            $ivaAccountCandidate = null;
            if (array_key_exists('iva_account', $rateInput)) {
                $ivaAccountCandidate = $rateInput['iva_account'];
            } elseif (array_key_exists('iva', $rateInput) && looksLikeAccountReference($rateInput['iva'])) {
                $ivaAccountCandidate = $rateInput['iva'];
            }
            if ($ivaAccountCandidate !== null) {
                $ivaCandidate = extractStringValue($ivaAccountCandidate, ['account', 'code']);
                if ($ivaCandidate !== null) {
                    $ivaAccount = $ivaCandidate;
                }
            }

            $generalCandidate = null;
            if (array_key_exists('general_account', $rateInput)) {
                $generalCandidate = extractStringValue($rateInput['general_account'], ['account', 'code']);
            } elseif (array_key_exists('general', $rateInput)) {
                $generalCandidate = extractStringValue($rateInput['general'], ['account', 'code']);
            }
            if ($generalCandidate !== null) {
                $generalAccount = $generalCandidate;
            }

            if (array_key_exists('label', $rateInput)) {
                $labelCandidate = extractStringValue($rateInput['label'], ['value', 'label', 'text']);
                if ($labelCandidate !== null) {
                    $label = $labelCandidate;
                }
            }

            $erpRubricCandidate = null;
            if (array_key_exists('erp_rubric_code', $rateInput)) {
                $erpRubricCandidate = extractStringValue($rateInput['erp_rubric_code'], ['value', 'code', 'label', 'text']);
            } elseif (array_key_exists('rubric_code', $rateInput)) {
                $erpRubricCandidate = extractStringValue($rateInput['rubric_code'], ['value', 'code', 'label', 'text']);
            }
            if ($erpRubricCandidate !== null) {
                $erpRubricCode = normalizeAccountingRubricCodeValue($erpRubricCandidate);
            }

            if (array_key_exists('vat_amounts_adjusted', $rateInput)) {
                $vatAmountsAdjusted = normalizeAccountingMetadataFlag($rateInput['vat_amounts_adjusted']);
            }
            if (array_key_exists('bank_loan_conversion', $rateInput)) {
                $bankLoanConversion = normalizeAccountingMetadataFlag($rateInput['bank_loan_conversion']);
            }

            if (array_key_exists('base_value', $rateInput)) {
                $baseCandidate = extractDecimalAmount($rateInput['base_value']);
                if ($baseCandidate !== null) {
                    $baseValue = $baseCandidate;
                }
            }
            if ($baseValue === '' && array_key_exists('base', $rateInput)) {
                $baseCandidate = extractDecimalAmount($rateInput['base']);
                if ($baseCandidate !== null) {
                    $baseValue = $baseCandidate;
                }
            }

            if (array_key_exists('iva_value', $rateInput)) {
                $ivaCandidateValue = extractDecimalAmount($rateInput['iva_value']);
                if ($ivaCandidateValue !== null) {
                    $ivaValue = $ivaCandidateValue;
                }
            }
            if ($ivaValue === '' && array_key_exists('iva', $rateInput) && !looksLikeAccountReference($rateInput['iva'])) {
                $ivaCandidateValue = extractDecimalAmount($rateInput['iva']);
                if ($ivaCandidateValue !== null) {
                    $ivaValue = $ivaCandidateValue;
                }
            }
            if (array_key_exists('cost_center_required', $rateInput)) {
                $flag = trim((string) $rateInput['cost_center_required']);
                $costCenterRequired = ($flag === '1' || strcasecmp($flag, 'true') === 0);
            }
            if (array_key_exists('base_source_field', $rateInput)) {
                $candidate = extractStringValue($rateInput['base_source_field'], ['field', 'value', 'name', 'code']);
                if ($candidate !== null) {
                    $baseSourceField = trim($candidate);
                }
            }
        } elseif ($rateInput !== null) {
            $generalCandidate = extractStringValue($rateInput, ['account', 'code']);
            if ($generalCandidate !== null) {
                $generalAccount = $generalCandidate;
            }
        }

        if ($rate === '6' && isset($input['iva6']) && $ivaAccount === '') {
            $fallback = extractStringValue($input['iva6'], ['account', 'code']);
            if ($fallback !== null) {
                $ivaAccount = $fallback;
            }
        } elseif ($rate === '13' && isset($input['iva13']) && $ivaAccount === '') {
            $fallback = extractStringValue($input['iva13'], ['account', 'code']);
            if ($fallback !== null) {
                $ivaAccount = $fallback;
            }
        } elseif ($rate === '23' && isset($input['iva23']) && $ivaAccount === '') {
            $fallback = extractStringValue($input['iva23'], ['account', 'code']);
            if ($fallback !== null) {
                $ivaAccount = $fallback;
            }
        }
        if ($rate === '0' && isset($input['novat']) && $generalAccount === '') {
            $fallback = extractStringValue($input['novat'], ['account', 'code']);
            if ($fallback !== null) {
                $generalAccount = $fallback;
            }
        }

        $effectiveLabel = $label !== '' ? $label : buildVatRateLabel($rate);

        $result[$rate] = [
            'iva_account' => $ivaAccount,
            'general_account' => $generalAccount,
            'base' => $baseValue,
            'iva' => $ivaValue,
        ];
        if ($effectiveLabel !== '') {
            $result[$rate]['label'] = $effectiveLabel;
        }
        if ($erpRubricCode !== '') {
            $result[$rate]['erp_rubric_code'] = $erpRubricCode;
        }
        if ($vatAmountsAdjusted === '1') {
            $result[$rate]['vat_amounts_adjusted'] = '1';
        }
        if ($bankLoanConversion === '1') {
            $result[$rate]['bank_loan_conversion'] = '1';
        }
        if ($costCenterRequired) {
            $result[$rate]['cost_center_required'] = '1';
        }
        if (!empty($baseSourceField)) {
            $result[$rate]['base_source_field'] = $baseSourceField;
        }

        $result[$rate] = normalizeAccountingVatAdjustmentState((string) $rate, $result[$rate]);
    }

    return $result;
}

/**
 * Merge a stored original-rate snapshot with computed document summaries.
 *
 * Ensures that every detected VAT rate contains a normalized base/IVA value so
 * the client can compare against a stable baseline even when the snapshot has
 * not been persisted yet.
 *
 * @param array<string,mixed> $original
 * @param array<string,array<string,mixed>> $summaries
 * @return array<string,array<string,string>>
 */
function mergeOriginalRateSnapshot(array $original, array $summaries = []): array {
    $sanitized = sanitizeAccountInput($original);

    foreach ($summaries as $rate => $summary) {
        $rateKey = (string) $rate;
        if (!array_key_exists($rateKey, $sanitized)) {
            $sanitized[$rateKey] = [
                'iva_account' => '',
                'general_account' => '',
                'base' => '',
                'iva' => '',
            ];
        }

        $baseCandidate = null;
        if (array_key_exists('base', $sanitized[$rateKey]) && $sanitized[$rateKey]['base'] !== '') {
            $baseCandidate = $sanitized[$rateKey]['base'];
        }
        if ($baseCandidate === null || $baseCandidate === '') {
            if (array_key_exists('base_display', $summary)) {
                $baseCandidate = extractDecimalAmount($summary['base_display']);
            }
            if (($baseCandidate === null || $baseCandidate === '') && array_key_exists('base_value', $summary)) {
                $baseCandidate = extractDecimalAmount($summary['base_value']);
            }
            if ($baseCandidate !== null && $baseCandidate !== '') {
                $sanitized[$rateKey]['base'] = $baseCandidate;
            }
        }

        $ivaCandidate = null;
        if (array_key_exists('iva', $sanitized[$rateKey]) && $sanitized[$rateKey]['iva'] !== '') {
            $ivaCandidate = $sanitized[$rateKey]['iva'];
        }
        if ($ivaCandidate === null || $ivaCandidate === '') {
            if (array_key_exists('iva_display', $summary)) {
                $ivaCandidate = extractDecimalAmount($summary['iva_display']);
            }
            if (($ivaCandidate === null || $ivaCandidate === '') && array_key_exists('iva_value', $summary)) {
                $ivaCandidate = extractDecimalAmount($summary['iva_value']);
            }
            if ($ivaCandidate !== null && $ivaCandidate !== '') {
                $sanitized[$rateKey]['iva'] = $ivaCandidate;
            }
        }
    }

    return $sanitized;
}

/**
 * Normalize the original-rate payload submitted by the client.
 *
 * @param mixed $payload
 * @return array<string,array<string,string>>
 */
function normalizeOriginalRatesPayload($payload): array {
    if (!is_array($payload)) {
        return [];
    }

    $result = [];
    foreach ($payload as $rate => $entry) {
        $rateKey = (string) $rate;
        if (!is_array($entry)) {
            continue;
        }

        $base = '';
        if (array_key_exists('base', $entry)) {
            $candidate = extractDecimalAmount($entry['base']);
            if ($candidate !== null) {
                $base = $candidate;
            }
        }
        if ($base === '' && array_key_exists('base_value', $entry)) {
            $candidate = extractDecimalAmount($entry['base_value']);
            if ($candidate !== null) {
                $base = $candidate;
            }
        }

        $iva = '';
        if (array_key_exists('iva', $entry)) {
            $candidate = extractDecimalAmount($entry['iva']);
            if ($candidate !== null) {
                $iva = $candidate;
            }
        }
        if ($iva === '' && array_key_exists('iva_value', $entry)) {
            $candidate = extractDecimalAmount($entry['iva_value']);
            if ($candidate !== null) {
                $iva = $candidate;
            }
        }

        $result[$rateKey] = [
            'base' => $base,
            'iva' => $iva,
        ];
    }

    return $result;
}

/**
 * Merge two account configurations, giving precedence to override values.
 *
 * @param array<string,mixed> $base
 * @param array<string,mixed> $override
 * @return array<string,array<string,string>>
 */
function mergeAccountingAccounts(array $base, array $override): array {
    $baseSanitized = sanitizeAccountInput($base);
    $overrideSanitized = sanitizeAccountInput($override);

    // sanitizeAccountInput preenche sempre as taxas por omissao (0/6/13/23) com
    // valores vazios. Sem esta distincao, uma taxa que o formulario nem sequer
    // mostrou entraria no override como "vazia" e apagaria a conta aprendida
    // para essa taxa. So se aplica o override as taxas realmente submetidas; a
    // remocao explicita de uma taxa e tratada a parte, via removed_rates.
    $overrideRaw = (isset($override['rates']) && is_array($override['rates'])) ? $override['rates'] : $override;
    $explicitOverrideRates = [];
    foreach (array_keys($overrideRaw) as $overrideRateKey) {
        $explicitOverrideRates[(string) $overrideRateKey] = true;
    }

    $allRates = array_unique(array_merge(array_keys($baseSanitized), array_keys($overrideSanitized)));
    $result = [];

    foreach ($allRates as $rate) {
        $result[$rate] = [
            'iva_account' => $baseSanitized[$rate]['iva_account'] ?? '',
            'general_account' => $baseSanitized[$rate]['general_account'] ?? '',
            'base' => $baseSanitized[$rate]['base'] ?? '',
            'iva' => $baseSanitized[$rate]['iva'] ?? '',
        ];
        if (isset($baseSanitized[$rate]['label'])) {
            $result[$rate]['label'] = $baseSanitized[$rate]['label'];
        }
        if (isset($baseSanitized[$rate]['erp_rubric_code'])) {
            $result[$rate]['erp_rubric_code'] = $baseSanitized[$rate]['erp_rubric_code'];
        }
        if (isset($baseSanitized[$rate]['vat_amounts_adjusted'])) {
            $result[$rate]['vat_amounts_adjusted'] = $baseSanitized[$rate]['vat_amounts_adjusted'];
        }
        if (isset($baseSanitized[$rate]['bank_loan_conversion'])) {
            $result[$rate]['bank_loan_conversion'] = $baseSanitized[$rate]['bank_loan_conversion'];
        }
        if (isset($baseSanitized[$rate]['cost_center_required'])) {
            $result[$rate]['cost_center_required'] = $baseSanitized[$rate]['cost_center_required'];
        }
        if (isset($baseSanitized[$rate]['base_source_field'])) {
            $result[$rate]['base_source_field'] = $baseSanitized[$rate]['base_source_field'];
        }

        if (array_key_exists($rate, $overrideSanitized) && isset($explicitOverrideRates[(string) $rate])) {
            foreach (['iva_account', 'general_account', 'base', 'iva', 'erp_rubric_code', 'vat_amounts_adjusted', 'bank_loan_conversion', 'cost_center_required', 'base_source_field'] as $field) {
                if (array_key_exists($field, $overrideSanitized[$rate])) {
                    $result[$rate][$field] = $overrideSanitized[$rate][$field];
                }
            }
            if (array_key_exists('label', $overrideSanitized[$rate]) && $overrideSanitized[$rate]['label'] !== '') {
                $result[$rate]['label'] = $overrideSanitized[$rate]['label'];
            }
        }

        if (!array_key_exists('label', $result[$rate]) || $result[$rate]['label'] === '') {
            $result[$rate]['label'] = buildVatRateLabel($rate);
        }
    }

    return $result;
}

/**
 * Remove document-specific amounts from reusable accounting mappings.
 *
 * Reusable templates/classifications must only persist the account structure;
 * base and IVA values are always derived from the current document.
 *
 * @param array<string,mixed> $rates
 * @return array<string,array<string,string>>
 */
function stripAccountingAmounts(array $rates): array {
    $sanitized = sanitizeAccountInput($rates);

    foreach ($sanitized as $rate => $entry) {
        $sanitized[$rate]['base'] = '';
        $sanitized[$rate]['iva'] = '';
        unset($sanitized[$rate]['vat_amounts_adjusted']);
    }

    return $sanitized;
}

/**
 * Remove empty/default rows from a normalized account payload.
 *
 * @param array<string,mixed> $rates
 * @return array<string,array<string,string>>
 */
function filterVisibleAccountingRates(array $rates): array {
    $sanitized = sanitizeAccountInput($rates);
    $result = [];

    foreach ($sanitized as $rate => $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $general = trim((string) ($entry['general_account'] ?? ''));
        $iva = trim((string) ($entry['iva_account'] ?? ''));
        $base = trim((string) ($entry['base'] ?? ''));
        $ivaValue = trim((string) ($entry['iva'] ?? ''));
        $label = trim((string) ($entry['label'] ?? ''));
        $costCenterRequired = trim((string) ($entry['cost_center_required'] ?? ''));
        $baseSourceField = trim((string) ($entry['base_source_field'] ?? ''));
        $erpRubricCode = normalizeAccountingRubricCodeValue($entry['erp_rubric_code'] ?? '');
        $vatAmountsAdjusted = normalizeAccountingMetadataFlag($entry['vat_amounts_adjusted'] ?? '0');
        $bankLoanConversion = normalizeAccountingMetadataFlag($entry['bank_loan_conversion'] ?? '0');

        if ($general === '' && $iva === '' && $base === '' && $ivaValue === '' && $costCenterRequired === '' && $baseSourceField === '') {
            continue;
        }

        $result[(string) $rate] = [
            'iva_account' => $iva,
            'general_account' => $general,
            'base' => $base,
            'iva' => $ivaValue,
        ];
        if ($label !== '') {
            $result[(string) $rate]['label'] = $label;
        }
        if ($erpRubricCode !== '') {
            $result[(string) $rate]['erp_rubric_code'] = $erpRubricCode;
        }
        if ($vatAmountsAdjusted === '1') {
            $result[(string) $rate]['vat_amounts_adjusted'] = '1';
        }
        if ($bankLoanConversion === '1') {
            $result[(string) $rate]['bank_loan_conversion'] = '1';
        }
        if ($costCenterRequired !== '') {
            $result[(string) $rate]['cost_center_required'] = $costCenterRequired;
        }
        if ($baseSourceField !== '') {
            $result[(string) $rate]['base_source_field'] = $baseSourceField;
        }
    }

    return $result;
}

function accountingRatesContainBankLoanConversion(array $rates): bool {
    $sanitized = sanitizeAccountInput($rates);
    foreach ($sanitized as $entry) {
        if (is_array($entry) && normalizeAccountingMetadataFlag($entry['bank_loan_conversion'] ?? '0') === '1') {
            return true;
        }
    }
    return false;
}

function parseAccountingBankLoanAmount($value): ?float {
    $amount = extractDecimalAmount($value);
    if ($amount === null || $amount === '') {
        return null;
    }
    $number = (float) $amount;
    return is_finite($number) ? $number : null;
}

/**
 * Serialize normalized account information as JSON.
 *
 * @param array<string,mixed> $rates
 * @param array<string,mixed> $metadata
 * @param array<string,mixed> $existingMetadata
 * @return string
 */
function serializeAccountingAccounts(array $rates, array $metadata = [], array $existingMetadata = []): string {
    $sanitized = sanitizeAccountInput($rates);
    $baseMetadata = sanitizeAccountingMetadata($existingMetadata);
    $incomingMetadata = sanitizeAccountingMetadata($metadata);
    $finalMetadata = mergeAccountingMetadata($baseMetadata, $incomingMetadata);

    $payload = [
        'version' => 3,
        'rates' => $sanitized,
    ];

    if (hasAccountingMetadataValue($finalMetadata)) {
        $payload['meta'] = $finalMetadata;
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE);
}

/**
 * Build an empty cost centre map keyed by VAT rate.
 *
 * @return array<string,string>
 */
function buildEmptyCostCenterMap(array $additionalRates = []): array {
    $baseRates = array_keys(getDefaultVatRates());
    $allRates = [];
    foreach (array_merge($baseRates, $additionalRates) as $rate) {
        $allRates[] = (string) $rate;
    }
    $allRates = array_values(array_unique($allRates));

    $result = [];
    foreach ($allRates as $rate) {
        $result[$rate] = '';
    }

    return $result;
}

/**
 * Build an empty detailed cost centre distribution map keyed by VAT rate.
 *
 * @return array<string,array<int,array<string,string>>>
 */
function buildEmptyCostCenterBreakdownMap(array $additionalRates = []): array {
    $base = buildEmptyCostCenterMap($additionalRates);
    $result = [];
    foreach ($base as $rate => $_) {
        $result[(string) $rate] = [];
    }
    return $result;
}

/**
 * Sanitize a detailed cost centre distribution row list.
 *
 * @param mixed $rows
 * @return array<int,array<string,string>>
 */
function sanitizeCostCenterBreakdownRows($rows): array {
    if (!is_array($rows)) {
        return [];
    }

    $result = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $costCenter = '';
        foreach (['cost_center', 'code', 'strConta_CCusto', 'value'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $candidate = trim((string) $row[$key]);
            if ($candidate !== '') {
                $costCenter = $candidate;
                break;
            }
        }
        if ($costCenter === '') {
            continue;
        }

        $percentage = null;
        foreach (['percentage', 'fltPercentagem', 'percent'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $candidate = extractDecimalAmount($row[$key]);
            if ($candidate !== null && $candidate !== '') {
                $percentage = (float) $candidate;
                break;
            }
        }

        $value = null;
        foreach (['amount', 'value_amount', 'fltValor', 'value'] as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $candidate = extractDecimalAmount($row[$key]);
            if ($candidate !== null && $candidate !== '') {
                $value = (float) $candidate;
                break;
            }
        }

        $entry = [
            'cost_center' => $costCenter,
            'percentage' => $percentage === null ? '' : number_format($percentage, 2, '.', ''),
            'value' => $value === null ? '' : number_format($value, 2, '.', ''),
        ];

        if ($entry['percentage'] === '' && $entry['value'] === '') {
            continue;
        }

        $result[] = $entry;
    }

    return $result;
}

/**
 * Sanitize arbitrary detailed cost centre distributions keyed by VAT rate.
 *
 * @param mixed $input
 * @return array<string,array<int,array<string,string>>>
 */
function sanitizeCostCenterBreakdownValues($input): array {
    $detectedRates = array_keys(getDefaultVatRates());

    if (is_array($input)) {
        $source = $input;
        if (isset($input['rates']) && is_array($input['rates'])) {
            $source = $input['rates'];
        }
        foreach ($source as $key => $_) {
            $detectedRates[] = (string) $key;
        }
    }

    $result = buildEmptyCostCenterBreakdownMap($detectedRates);

    if (!is_array($input)) {
        return $result;
    }

    if (isset($input['rates']) && is_array($input['rates'])) {
        $input = $input['rates'];
    }

    foreach ($input as $rate => $value) {
        $rateKey = (string) $rate;
        if (!array_key_exists($rateKey, $result)) {
            $result[$rateKey] = [];
        }

        if (is_array($value) && isset($value['distribution']) && is_array($value['distribution'])) {
            $result[$rateKey] = sanitizeCostCenterBreakdownRows($value['distribution']);
            continue;
        }

        if (is_array($value) && isset($value['entries']) && is_array($value['entries'])) {
            $result[$rateKey] = sanitizeCostCenterBreakdownRows($value['entries']);
            continue;
        }

        if (is_array($value) && array_keys($value) === range(0, count($value) - 1)) {
            $result[$rateKey] = sanitizeCostCenterBreakdownRows($value);
        }
    }

    return $result;
}

/**
 * Sanitize arbitrary cost centre input ensuring expected VAT keys exist.
 *
 * The function accepts the different shapes that may appear either from
 * previously stored JSON blobs or user-submitted payloads and always
 * returns an array keyed by the supported VAT rates containing strings.
 *
 * @param mixed $input
 * @return array<string,string>
 */
function sanitizeCostCenterValues($input): array {
    $detectedRates = array_keys(getDefaultVatRates());

    if (is_array($input)) {
        $source = $input;
        if (isset($input['rates']) && is_array($input['rates'])) {
            $source = $input['rates'];
        }
        foreach ($source as $key => $_) {
            $detectedRates[] = (string) $key;
        }
    }

    $result = buildEmptyCostCenterMap($detectedRates);

    if (is_string($input) || is_numeric($input)) {
        $value = trim((string) $input);
        if ($value === '') {
            return $result;
        }

        foreach ($result as $rate => $_) {
            $result[$rate] = $value;
        }

        return $result;
    }

    if (!is_array($input)) {
        return $result;
    }

    if (isset($input['rates']) && is_array($input['rates'])) {
        $input = $input['rates'];
    }

    foreach ($input as $rate => $value) {
        $rateKey = (string) $rate;
        if (!array_key_exists($rateKey, $result)) {
            $result[$rateKey] = '';
        }

        if (is_array($value)) {
            if (array_key_exists('cost_center', $value)) {
                $value = $value['cost_center'];
            } elseif (array_key_exists('distribution', $value) && is_array($value['distribution'])) {
                $distribution = sanitizeCostCenterBreakdownRows($value['distribution']);
                $value = $distribution[0]['cost_center'] ?? '';
            } elseif (array_key_exists('entries', $value) && is_array($value['entries'])) {
                $distribution = sanitizeCostCenterBreakdownRows($value['entries']);
                $value = $distribution[0]['cost_center'] ?? '';
            } elseif (array_key_exists('value', $value)) {
                $value = $value['value'];
            }
        }

        if ($value === null) {
            $result[$rateKey] = '';
        } else {
            $result[$rateKey] = trim((string) $value);
        }
    }

    return $result;
}

/**
 * Normalize stored cost centre information into a predictable structure.
 *
 * @param string|null $json JSON-encoded cost centre data or legacy value.
 * @return array<string,string>
 */
function normalizeCostCenters(?string $json): array {
    if ($json === null) {
        return buildEmptyCostCenterMap();
    }

    $trimmed = trim($json);
    if ($trimmed === '') {
        return buildEmptyCostCenterMap();
    }

    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return sanitizeCostCenterValues($decoded);
    }

    return sanitizeCostCenterValues($trimmed);
}

/**
 * Normalize stored detailed cost centre information into a predictable structure.
 *
 * @param string|null $json JSON-encoded cost centre data or legacy value.
 * @return array<string,array<int,array<string,string>>>
 */
function normalizeCostCenterBreakdowns(?string $json): array {
    if ($json === null) {
        return buildEmptyCostCenterBreakdownMap();
    }

    $trimmed = trim($json);
    if ($trimmed === '') {
        return buildEmptyCostCenterBreakdownMap();
    }

    $decoded = json_decode($trimmed, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        return sanitizeCostCenterBreakdownValues($decoded);
    }

    return buildEmptyCostCenterBreakdownMap();
}

/**
 * Serialize cost centre values to be stored in the database.
 *
 * @param array<string,mixed> $centers
 * @return string
 */
function serializeCostCenters(array $centers, array $breakdowns = []): string {
    $sanitized = sanitizeCostCenterValues($centers);
    $sanitizedBreakdowns = sanitizeCostCenterBreakdownValues($breakdowns);

    $ratesPayload = [];
    foreach ($sanitized as $rate => $costCenter) {
        $distribution = $sanitizedBreakdowns[(string) $rate] ?? [];
        if (!empty($distribution)) {
            $ratesPayload[(string) $rate] = [
                'cost_center' => $costCenter,
                'distribution' => array_values($distribution),
            ];
            continue;
        }
        $ratesPayload[(string) $rate] = $costCenter;
    }

    return json_encode([
        'version' => 2,
        'rates' => $ratesPayload,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Calculate VAT rate amounts and requirements for an imported document row.
 *
 * @param array<string,mixed> $row
 * @return array<string,array<string,mixed>>
 */
function computeImportRateSummaries(array $row): array {
    $parseAmount = static function ($value): float {
        if ($value === null) {
            return 0.0;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return 0.0;
        }
        return (float) str_replace(',', '.', $stringValue);
    };

    $formatAmount = static function (?float $value, $original = null): string {
        $originalStr = is_string($original) ? trim($original) : '';
        if ($originalStr !== '') {
            return $originalStr;
        }
        if ($value === null) {
            return '';
        }
        return number_format($value, 2, '.', '');
    };

    $base6 = $parseAmount($row['field_I3'] ?? null);
    $iva6 = $parseAmount($row['field_I4'] ?? null);
    $base13 = $parseAmount($row['field_I5'] ?? null);
    $iva13 = $parseAmount($row['field_I6'] ?? null);
    $base23 = $parseAmount($row['field_I7'] ?? null);
    $iva23 = $parseAmount($row['field_I8'] ?? null);
    $totalIva = $parseAmount($row['field_N'] ?? null);
    $total = $parseAmount($row['field_O'] ?? null);

    $totalBase = $total - $totalIva;
    if (!is_finite($totalBase)) {
        $totalBase = 0.0;
    }

    // Base isenta: o QR da AT ja fornece o valor no campo I2. Usa-se sempre que
    // esteja presente; a subtracao (total - impostos - bases tributaveis) fica
    // como recurso para documentos manuais/sem QR. Derivar por subtracao quando
    // o I2 existe faz com que qualquer imprecisao nos totais (imposto do selo,
    // taxas regionais) seja silenciosamente absorvida pela linha do isento.
    $declaredBase0 = extractDecimalAmount($row['field_I2'] ?? null);
    if ($declaredBase0 !== null && $declaredBase0 !== '') {
        $base0 = (float) $declaredBase0;
    } else {
        $base0 = $totalBase - $base6 - $base13 - $base23;
    }
    if (!is_finite($base0)) {
        $base0 = 0.0;
    }
    if (abs($base0) < 0.005) {
        $base0 = 0.0;
    }

    return [
        '0' => [
            'base_value' => $base0,
            'iva_value' => 0.0,
            'base_display' => $formatAmount($base0),
            'iva_display' => $formatAmount(0.0),
            // Havendo base isenta o documento tem de ter conta de gasto: sem ela
            // a linha nao e gerada para o ERP e o lancamento fica desequilibrado
            // exactamente nesse valor.
            'require_general' => abs($base0) > 0.005,
            'require_iva' => false,
        ],
        '6' => [
            'base_value' => $base6,
            'iva_value' => $iva6,
            'base_display' => $formatAmount($base6, $row['field_I3'] ?? null),
            'iva_display' => $formatAmount($iva6, $row['field_I4'] ?? null),

            'require_general' => abs($iva6) > 0.0001,
            'require_iva' => abs($iva6) > 0.0001,

        ],
        '13' => [
            'base_value' => $base13,
            'iva_value' => $iva13,
            'base_display' => $formatAmount($base13, $row['field_I5'] ?? null),
            'iva_display' => $formatAmount($iva13, $row['field_I6'] ?? null),
            'require_general' => abs($iva13) > 0.0001,
            'require_iva' => abs($iva13) > 0.0001,

        ],
        '23' => [
            'base_value' => $base23,
            'iva_value' => $iva23,
            'base_display' => $formatAmount($base23, $row['field_I7'] ?? null),
            'iva_display' => $formatAmount($iva23, $row['field_I8'] ?? null),
            'require_general' => abs($iva23) > 0.0001,
            'require_iva' => abs($iva23) > 0.0001,
        ],
    ];
}

/**
 * Determine the best-effort total amount for an imported document.
 *
 * @param array<string,mixed> $document
 * @return float|null
 */
function computeDocumentTotalAmount(array $document): ?float {
    $directTotal = extractDecimalAmount($document['field_O'] ?? null);
    if ($directTotal !== null && $directTotal !== '') {
        $value = (float) $directTotal;
        if (is_finite($value) && abs($value) >= 0.00001) {
            return $value;
        }
    }

    $summaries = computeImportRateSummaries($document);
    $baseSum = 0.0;
    $ivaSum = 0.0;

    foreach ($summaries as $summary) {
        $baseSum += (float) ($summary['base_value'] ?? 0.0);
        $ivaSum += (float) ($summary['iva_value'] ?? 0.0);
    }

    $calculatedTotal = $baseSum + $ivaSum;
    if (!is_finite($calculatedTotal) || abs($calculatedTotal) < 0.00001) {
        return null;
    }

    return $calculatedTotal;
}

/**
 * Build payload and requirement metadata for modal rendering.
 *
 * @param array<string,array<string,mixed>> $summaries
 * @param array<string,array<string,string>> $accounts
 * @return array{0: array<string,array<string,string>>, 1: array<string,array<string,bool>>}
 */
function buildRatePayload(array $summaries, array $accounts): array {
    $payload = [];
    $requirements = [];

    $allRates = array_unique(array_merge(array_keys($summaries), array_keys($accounts)));
    foreach ($allRates as $rate) {
        $info = $summaries[$rate] ?? [];
        $accountInfo = $accounts[$rate] ?? [];
        $label = '';
        if (is_array($accountInfo) && array_key_exists('label', $accountInfo)) {
            $label = trim((string) $accountInfo['label']);
        }
        if ($label === '') {
            $label = buildVatRateLabel((string) $rate);
        }

        $baseDisplay = $info['base_display'] ?? '';
        $ivaDisplay = $info['iva_display'] ?? '';

        if (is_array($accountInfo) && array_key_exists('base', $accountInfo)) {
            $storedBase = trim((string) $accountInfo['base']);
            if ($storedBase !== '') {
                $baseDisplay = $storedBase;
            }
        }
        if (is_array($accountInfo) && array_key_exists('iva', $accountInfo)) {
            $storedIva = trim((string) $accountInfo['iva']);
            if ($storedIva !== '') {
                $ivaDisplay = $storedIva;
            }
        }
        $hasStoredAdjustedAmounts = (
            trim((string) ($accountInfo['base'] ?? '')) !== ''
            || trim((string) ($accountInfo['iva'] ?? '')) !== ''
        );

        $normalizedRateKey = normalizeAccountingRateKey((string) $rate);
        $ivaAccount = $accountInfo['iva_account'] ?? '';
        $isBankLoanConversionRate = normalizeAccountingMetadataFlag($accountInfo['bank_loan_conversion'] ?? '0') === '1';
        if ($normalizedRateKey === '0' || $isBankLoanConversionRate) {
            $ivaAccount = '';
        }
        if ($isBankLoanConversionRate) {
            $ivaDisplay = '';
        }

        $payload[$rate] = [
            'label' => $label,
            'base' => $baseDisplay,
            'iva' => $ivaDisplay,
            'base_value' => $baseDisplay,
            'iva_value' => $ivaDisplay,
            'iva_account' => $ivaAccount,
            'general_account' => $accountInfo['general_account'] ?? '',
            'erp_rubric_code' => $accountInfo['erp_rubric_code'] ?? '',
            'vat_amounts_adjusted' => ($hasStoredAdjustedAmounts && normalizeAccountingMetadataFlag($accountInfo['vat_amounts_adjusted'] ?? '0') === '1') ? '1' : '0',
        ];
        if ($isBankLoanConversionRate) {
            $payload[$rate]['bank_loan_conversion'] = '1';
        }
        $requirements[$rate] = [
            'general' => !empty($info['require_general']),
            'iva' => ($normalizedRateKey !== '0' && !$isBankLoanConversionRate && !empty($info['require_iva'])),
            'cost_center' => (trim((string) ($accountInfo['cost_center_required'] ?? '')) === '1'),
        ];
    }

    return [$payload, $requirements];
}

/**
 * Build requirements directly from the rows defined by the user.
 *
 * @param array<string,array<string,mixed>> $payload
 * @return array<string,array<string,bool>>
 */
function buildManualClassificationRequirements(array $payload): array {
    $requirements = [];

    foreach ($payload as $rate => $data) {
        $rateKey = (string) $rate;
        if (!is_array($data)) {
            continue;
        }

        $general = trim((string) ($data['general_account'] ?? ''));
        $iva = trim((string) ($data['iva_account'] ?? ''));
        $base = trim((string) ($data['base'] ?? $data['base_value'] ?? ''));
        $ivaValue = trim((string) ($data['iva'] ?? $data['iva_value'] ?? ''));
        $costCenterRequired = trim((string) ($data['cost_center_required'] ?? '')) === '1';
        $isBankLoanConversionRate = normalizeAccountingMetadataFlag($data['bank_loan_conversion'] ?? '0') === '1';
        $normalizedRateKey = normalizeAccountingRateKey($rateKey);

        if ($normalizedRateKey === '0' || $isBankLoanConversionRate) {
            $iva = '';
        }

        if ($general === '' && $iva === '' && $base === '' && $ivaValue === '') {
            continue;
        }

        $requirements[$rateKey] = [
            'general' => true,
            'iva' => ($normalizedRateKey !== '0' && !$isBankLoanConversionRate),
            'cost_center' => $costCenterRequired,
        ];
    }

    return $requirements;
}

/**
 * Build the effective requirements for a classification row.
 *
 * @param array<string,array<string,mixed>> $summaries
 * @param array<string,array<string,mixed>> $accounts
 * @param array<string,string> $metadata
 * @return array{0: array<string,array<string,mixed>>, 1: array<string,array<string,bool>>}
 */
function buildClassificationRequirements(array $summaries, array $accounts, array $metadata = []): array {
    [$payload, $requirements] = buildRatePayload($summaries, $accounts);

    if (($metadata['ignore_detected_rates'] ?? '0') === '1' || accountingRatesContainBankLoanConversion($accounts)) {
        $payload = filterVisibleAccountingRates($accounts);
        $manualRequirements = buildManualClassificationRequirements($payload);
        if (!empty($manualRequirements)) {
            $requirements = $manualRequirements;
        }
    }

    return [$payload, $requirements];
}

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

/**
 * Limpa a cache de regras de classificacao do pedido actual. Tem de ser
 * chamada por qualquer rotina que altere `accounting_classifications` no
 * mesmo pedido, para que as leituras seguintes nao devolvam valores antigos.
 */
function resetClassificationAccountPayloadCache(): void {
    fetchClassificationAccountPayload_cache(true);
}

/**
 * Armazenamento da cache. Separado da funcao de leitura para poder ser
 * limpo sem depender de uma variavel estatica de outra funcao.
 *
 * @return array<string,string>
 */
function &fetchClassificationAccountPayload_cache(bool $reset = false): array {
    static $cache = [];
    if ($reset) {
        $cache = [];
    }
    return $cache;
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
    $stmt = null;
    // Esta funcao e chamada uma vez por linha da listagem, com ate 3 queries
    // cada. Numa vista de importacao com milhares de documentos pendentes
    // isso sao dezenas de milhares de queries por pedido, quase todas
    // repetidas (o mesmo fornecedor aparece em muitos documentos). A cache e
    // por pedido; ver resetClassificationAccountPayloadCache().
    $cache = &fetchClassificationAccountPayload_cache();

    foreach ($candidates as $candidate) {
        [$candidateEmitter, $candidateAcquirer, $candidateDocType] = $candidate;
        $signature = $candidateEmitter . '|' . $candidateAcquirer . '|' . $candidateDocType;
        if ($candidateEmitter === '' || $candidateAcquirer === '' || $candidateDocType === '' || isset($seen[$signature])) {
            continue;
        }
        $seen[$signature] = true;

        if (!array_key_exists($signature, $cache)) {
            if ($stmt === null) {
                $stmt = $pdo->prepare(
                    'SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
                );
            }
            $stmt->execute([$candidateEmitter, $candidateAcquirer, $candidateDocType]);
            $payload = $stmt->fetchColumn();
            $cache[$signature] = is_string($payload) ? $payload : '';
        }

        if (trim($cache[$signature]) !== '') {
            return $cache[$signature];
        }
    }

    return '';
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

function resolveDisplayClassificationAccounts(array $defaultAccounts, array $rowAccounts): array {
    $baseSanitized = sanitizeAccountInput($defaultAccounts);
    $rowSanitized = sanitizeAccountInput($rowAccounts);
    $allRates = array_values(array_unique(array_merge(array_keys($baseSanitized), array_keys($rowSanitized))));
    $result = [];

    foreach ($allRates as $rate) {
        $defaultEntry = $baseSanitized[$rate] ?? [];
        $rowEntry = $rowSanitized[$rate] ?? [];
        $entry = $defaultEntry;

        foreach (['iva_account', 'general_account', 'base', 'iva', 'label', 'base_source_field', 'erp_rubric_code', 'vat_amounts_adjusted', 'bank_loan_conversion'] as $field) {
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

/**
 * Same effective-configuration merge as classificacao-importacao.php's
 * resolveEffectiveDocumentAccountingConfiguration(), minus the fuel rubric
 * code lookup (resolveDocumentLigacaoRubricCodes), which calls the ERP
 * webservice live. Used by contexts that render many rows at once (e.g. the
 * e-fatura documents list) where a per-row ERP round trip is not acceptable;
 * accounts missing an erp_rubric_code purely because that lookup was skipped
 * are the one known accuracy trade-off.
 */
function resolveEffectiveDocumentAccountingConfigurationWithoutErpLookup(PDO $pdo, array $document): string {
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

    if (trim($classificationPayload) === '' && $effectiveAccounts === $rowAccounts) {
        return $rowPayload;
    }

    return serializeAccountingAccounts($effectiveAccounts, $effectiveMetadata, $rowMetadata);
}

/**
 * Determine the button class for a classification row based on requirements.
 *
 * @param array<string,array<string,bool>> $requirements
 * @param array<string,array<string,string>> $payload
 * @return string
 */
function determineClassificationButtonClass(array $requirements, array $payload, array $metadata = [], array $costCenters = []): string {
    $requires = false;
    $allFilled = true;
    $hasAny = false;
    $hasMissingBaseAmount = false;


    foreach ($requirements as $rate => $req) {
        $data = $payload[$rate] ?? [];
        $normalizedRateKey = normalizeAccountingRateKey((string) $rate);
        $isBankLoanConversionRate = normalizeAccountingMetadataFlag($data['bank_loan_conversion'] ?? '0') === '1';
        $hasRelevantConfiguration = false;
        if (!empty($req['general'])) {
            $requires = true;
            $hasRelevantConfiguration = true;
            $general = trim((string) ($data['general_account'] ?? ''));
            if ($general === '') {
                $allFilled = false;
            } else {
                $hasAny = true;
            }
        }
        if ($normalizedRateKey !== '0' && !$isBankLoanConversionRate && !empty($req['iva'])) {
            $requires = true;
            $hasRelevantConfiguration = true;
            $iva = trim((string) ($data['iva_account'] ?? ''));
            if ($iva === '') {
                $allFilled = false;
            } else {
                $hasAny = true;
            }
        }
        if (!empty($req['cost_center'])) {
            $requires = true;
            $hasRelevantConfiguration = true;
            $costCenterValue = trim((string) ($costCenters[$rate] ?? ''));
            if ($costCenterValue === '') {
                $allFilled = false;
            } else {
                $hasAny = true;
            }
        }
        if (!$hasRelevantConfiguration) {
            $general = trim((string) ($data['general_account'] ?? ''));
            $iva = ($normalizedRateKey === '0' || $isBankLoanConversionRate) ? '' : trim((string) ($data['iva_account'] ?? ''));
            $costCenterValue = trim((string) ($costCenters[$rate] ?? ''));
            $hasRelevantConfiguration = ($general !== '' || $iva !== '' || $costCenterValue !== '');
        }
        if ($hasRelevantConfiguration) {
            $baseValue = extractDecimalAmount($data['base'] ?? ($data['base_value'] ?? ''));
            if ($baseValue === null || $baseValue === '' || abs((float) $baseValue) < 0.00001) {
                $allFilled = false;
                $hasMissingBaseAmount = true;
            } else {
                $hasAny = true;
            }
        }
    }

    $totalAccount = '';
    if (isset($metadata['total_account'])) {
        $totalAccount = trim((string) $metadata['total_account']);
    }

    if ($totalAccount === '') {
        $requires = true;
        $allFilled = false;
    } else {
        $hasAny = true;
    }

    if ((!$requires || $allFilled) && !$hasMissingBaseAmount) {
        return 'btn-success';
    }

    if ($hasAny) {
        return 'btn-warning';
    }

    return 'btn-secondary';
}

/**
 * Build a human-readable description for an ERP accounting line.
 */
function buildAccountingLineDescription(array $document, string $rate, string $componentLabel, ?string $customLabel = null): string {
    $docNumber = trim((string) ($document['field_G'] ?? ''));
    $baseLabel = $customLabel !== null && $customLabel !== '' ? $customLabel : buildVatRateLabel($rate);
    if ($baseLabel === '') {
        $baseLabel = strtoupper($componentLabel);
    } else {
        $baseLabel = strtoupper($componentLabel) . ' ' . $baseLabel;
    }

    if ($docNumber !== '') {
        return 'Doc ' . $docNumber . ' - ' . $baseLabel;
    }

    $emitter = trim((string) ($document['field_A'] ?? ''));
    if ($emitter !== '') {
        return $emitter . ' - ' . $baseLabel;
    }

    return $baseLabel;
}

/**
 * Build a description for the total accounting line appended to ERP payloads.
 */
function buildTotalAccountingLineDescription(array $document, string $nif): string {
    $parts = [];

    $docNumber = trim((string) ($document['field_G'] ?? ''));
    if ($docNumber !== '') {
        $parts[] = 'Doc ' . $docNumber;
    }

    if ($nif !== '') {
        $parts[] = 'NIF ' . $nif;
    }

    $emitter = trim((string) ($document['field_A'] ?? ''));
    if ($docNumber === '' && $emitter !== '') {
        $parts[] = $emitter;
    }

    if (empty($parts)) {
        return 'Total';
    }

    return 'Total - ' . implode(' - ', $parts);
}

/**
 * Normalise an amount stored in the document/classification to a float value.
 *
 * @param mixed $primaryValue  User-provided value stored in the classification.
 * @param mixed $fallbackValue Value inferred from the document totals.
 */
function resolveAccountingLineAmount($primaryValue, $fallbackValue = null): ?float {
    $amountString = extractDecimalAmount($primaryValue);
    if (($amountString === null || $amountString === '') && $fallbackValue !== null) {
        if (is_string($fallbackValue) || is_numeric($fallbackValue)) {
            $amountString = extractDecimalAmount($fallbackValue);
        }
    }

    if ($amountString === null || $amountString === '') {
        return null;
    }

    $amount = (float) $amountString;
    if (!is_finite($amount) || abs($amount) < 0.00001) {
        return null;
    }

    return $amount;
}

/**
 * Assemble a single ERP accounting line entry.
 *
 * @param string      $account
 * @param float       $amount
 * @param string      $description
 * @param string|null $costCenter
 * @param string|null $rate
 * @param string      $component Either 'base' or 'iva'.
 */
function buildAccountingLineEntry(string $account, float $amount, string $description, ?string $costCenter, ?string $rate, string $component, array $costCenterDistribution = []): array {
    $entryDirection = 'D';
    if ($component === 'total') {
        $entryDirection = $amount >= 0 ? 'C' : 'D';
    } else {
        $entryDirection = $amount >= 0 ? 'D' : 'C';
    }

    $entry = [
        'strConta' => $account,
        'fltValor' => round(abs($amount), 2),
        'strDeb_Cre' => $entryDirection,
        'strDescricao' => $description,
        'line_component' => $component,
    ];

    if ($costCenter !== null && $costCenter !== '') {
        $entry['strCentroCusto'] = $costCenter;
    }

    if ($rate !== null && $rate !== '') {
        $entry['tax_rate'] = $rate;
    }

    $distributionRows = sanitizeCostCenterBreakdownRows($costCenterDistribution);
    if (!empty($distributionRows)) {
        $movCc = [];
        foreach ($distributionRows as $index => $distributionRow) {
            $movCc[] = [
                'intNumLinha_CC' => $index + 1,
                'strConta_CCusto' => $distributionRow['cost_center'],
                'fltPercentagem' => (float) ($distributionRow['percentage'] !== '' ? $distributionRow['percentage'] : 0),
                'fltValor' => (float) ($distributionRow['value'] !== '' ? $distributionRow['value'] : 0),
                'strDeb_Cre' => $entry['strDeb_Cre'],
            ];
        }
        $entry['mov_cc'] = $movCc;
    }

    return $entry;
}

function isCreditAccountingDocumentType(string $docType): bool {
    $normalized = strtoupper(trim($docType));
    if ($normalized === '') {
        return false;
    }

    $normalized = str_replace(['-', '_', ' '], '', $normalized);

    return in_array($normalized, [
        'NC',
        'RC',
        'CN',
        'CREDITNOTE',
        'NOTACREDITO',
        'NOTADECREDITO',
    ], true);
}

/**
 * Convert stored classification data into ERP accounting lines.
 *
 * @param array<string,mixed> $document
 * @return array<int,array<string,mixed>>
 */
function buildDocumentAccountingLines(array $document): array {
    $accounts = normalizeAccountingAccounts($document['account'] ?? '');
    $metadata = normalizeAccountingMetadata($document['account'] ?? '');
    $costCenters = normalizeCostCenters($document['cost_center'] ?? '');
    $costCenterBreakdowns = normalizeCostCenterBreakdowns($document['cost_center'] ?? '');
    $summaries = computeImportRateSummaries($document);
    $docType = (string) ($document['field_D'] ?? $document['invoice_type'] ?? '');
    $documentSign = isCreditAccountingDocumentType($docType) ? -1 : 1;
    $lines = [];

    foreach ($accounts as $rate => $config) {
        $rateKey = (string) $rate;
        $label = '';
        if (isset($config['label']) && is_string($config['label'])) {
            $label = trim($config['label']);
        }
        $summary = $summaries[$rateKey] ?? null;
        $rateCostCenter = $costCenters[$rateKey] ?? '';
        $rateCostCenterBreakdown = $costCenterBreakdowns[$rateKey] ?? [];

        $generalAccount = trim((string) ($config['general_account'] ?? ''));
        $baseAmount = resolveAccountingLineAmount($config['base'] ?? '', $summary['base_value'] ?? null);
        $ivaAmount = resolveAccountingLineAmount($config['iva'] ?? '', $summary['iva_value'] ?? null);
        if (isAccountingVatAmountsAdjusted($config)) {
            $adjustedAmounts = [
                'base' => $baseAmount,
                'iva' => $ivaAmount,
                'vat_deduction_percent' => resolveAccountingVatDeductionPercent($config),
            ];
        } else {
            $adjustedAmounts = applyAccountingVatDeductionToAmounts($baseAmount, $ivaAmount, $config);
        }
        $baseAmount = $adjustedAmounts['base'];
        if ($generalAccount !== '' && $baseAmount !== null) {
            $baseAmount *= $documentSign;
            $description = buildAccountingLineDescription($document, $rateKey, 'Base', $label);
            $lines[] = buildAccountingLineEntry(
                $generalAccount,
                $baseAmount,
                $description,
                $rateCostCenter,
                $rateKey,
                'base',
                $rateCostCenterBreakdown
            );
        }

        $ivaAccount = trim((string) ($config['iva_account'] ?? ''));
        $ivaAmount = $adjustedAmounts['iva'];
        if ($ivaAccount !== '' && $ivaAmount !== null) {
            $ivaAmount *= $documentSign;
            $description = buildAccountingLineDescription($document, $rateKey, 'IVA', $label);
            $lines[] = buildAccountingLineEntry(
                $ivaAccount,
                $ivaAmount,
                $description,
                '',
                $rateKey,
                'iva'
            );
        }
    }

    $totalAccount = trim((string) ($metadata['total_account'] ?? ''));
    if ($totalAccount !== '') {
        $totalAmount = null;
        if (accountingRatesContainBankLoanConversion($accounts)) {
            $bankLoanTotal = 0.0;
            foreach ($lines as $lineEntry) {
                if (!is_array($lineEntry)) {
                    continue;
                }
                $lineAmount = isset($lineEntry['fltValor']) ? (float) $lineEntry['fltValor'] : 0.0;
                if (!is_finite($lineAmount) || abs($lineAmount) < 0.00001) {
                    continue;
                }
                $bankLoanTotal += abs($lineAmount);
            }
            if (abs($bankLoanTotal) >= 0.00001) {
                $totalAmount = $bankLoanTotal;
            }
        }
        if ($totalAmount === null) {
            $totalAmount = computeDocumentTotalAmount($document);
        }
        if ($totalAmount !== null) {
            $totalAmount *= $documentSign;
            $nif = '';
            foreach (['emitter_nif_normalized', 'field_A', 'field_C'] as $nifKey) {
                if (!array_key_exists($nifKey, $document)) {
                    continue;
                }
                $candidate = extractVatNumber((string) $document[$nifKey]);
                if ($candidate !== '') {
                    $nif = $candidate;
                    break;
                }
            }
            $description = buildTotalAccountingLineDescription($document, $nif);
            $totalLine = buildAccountingLineEntry(
                $totalAccount,
                $totalAmount,
                $description,
                null,
                null,
                'total'
            );
            $totalLine['strNumContrib'] = $nif;
            $totalLine['intGrp_Terc'] = 1;
            $lines[] = $totalLine;
        }
    }

    return $lines;
}

/**
 * Diferenca entre o total a debito e o total a credito de um conjunto de linhas.
 *
 * Um lancamento valido tem de fechar a zero. Valores positivos indicam excesso
 * de debito (tipicamente base/IVA classificados acima do total do documento);
 * negativos indicam falta de debito (tipicamente uma base sem conta atribuida,
 * como a linha de isento).
 *
 * @param array<int,array<string,mixed>> $lines
 */
function computeAccountingLinesImbalance(array $lines): float {
    $debit = 0.0;
    $credit = 0.0;

    foreach ($lines as $line) {
        if (!is_array($line)) {
            continue;
        }
        $amount = isset($line['fltValor']) ? (float) $line['fltValor'] : 0.0;
        if (!is_finite($amount)) {
            continue;
        }
        if (strtoupper(trim((string) ($line['strDeb_Cre'] ?? 'D'))) === 'C') {
            $credit += $amount;
        } else {
            $debit += $amount;
        }
    }

    return round($debit - $credit, 2);
}

/**
 * Verifica se as linhas fecham (debito = credito), com a tolerancia habitual de
 * arredondamento ao centimo.
 *
 * @param array<int,array<string,mixed>> $lines
 */
function accountingLinesAreBalanced(array $lines, float $tolerance = 0.01): bool {
    return abs(computeAccountingLinesImbalance($lines)) <= $tolerance;
}

?>
