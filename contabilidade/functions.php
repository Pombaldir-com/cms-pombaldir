<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $logFile);
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
    $database = trim($database);
    $emp = getErpDefaultCompanyIdentifier();
    if ($emp === '' && $database !== '') {
        $emp = $database;
    }

    $params = [];
    if ($database !== '') {
        $params['db'] = $database;
        $params['bd'] = $database;
    }
    if ($emp !== '') {
        $params['EMP'] = $emp;
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
function parseErpEntityPayload(array $payload, string $nif): ?array {
    $sourceLabel = 'Webservice ERP-SINC';

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

        $nifKeys = ['nif', 'vat', 'vatnumber', 'nifcliente', 'numero_contribuinte', 'numerocontribuinte', 'contribuinte', 'strnumcontrib', 'strnumcontribuinte'];


        foreach ($nifKeys as $nifKey) {
            if (array_key_exists($nifKey, $normalisedCandidate)) {
                $candidateNif = extractVatNumber((string) $normalisedCandidate[$nifKey]);
                break;
            }
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


        $erpDatabase = '';
        $databaseKeys = ['erp_database', 'erpdatabase', 'database', 'db', 'bd', 'base_dados', 'basedados'];
        foreach ($databaseKeys as $dbKey) {
            if (array_key_exists($dbKey, $normalisedCandidate)) {
                $erpDatabase = trim((string) $normalisedCandidate[$dbKey]);
                break;
            }
        }

        $erpClientCode = '';
        if (array_key_exists('intcodigo', $normalisedCandidate)) {
            $erpClientCode = trim((string) $normalisedCandidate['intcodigo']);
        }

        $entityType = '';
        $typeKeys = ['entity_type', 'entitytype', 'tp_entidade', 'tpentidade', 'tipo', 'tipo_entidade', 'tipoentidade'];

        foreach ($typeKeys as $typeKey) {
            if (array_key_exists($typeKey, $normalisedCandidate)) {
                $entityType = trim((string) $normalisedCandidate[$typeKey]);
                break;
            }
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

    $entity = parseErpEntityPayload($data, $nif);
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

/**
 * Fetch a table list from the ERP-SINC API (e.g., zonas/subzonas).
 *
 * @param string $path Path appended to the ERP base URL.
 * @param bool   $returnDebug Return debug information alongside data.
 * @return array Associative array with data/error info.
 */
function fetchErpTableData(string $path, bool $returnDebug = false): array {
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
    $endpoint = appendQueryParamsToUrl($endpoint, buildErpCompanyQueryParams());
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
 * Fetch an accounting entity stored locally by VAT number.
 *
 * @param PDO    $pdo Active database connection.
 * @param string $nif VAT number.
 * @return array|null Matching entity or null when absent.
 */
function findAccountingEntity(PDO $pdo, string $nif): ?array {
    $stmt = $pdo->prepare('SELECT id, name, nif, erp_database, entity_type, erp_client_code, created_at FROM accounting_entities WHERE nif = ? LIMIT 1');
    $stmt->execute([$nif]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
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
    $stmt = $pdo->prepare('SELECT id, name, nif, erp_database, entity_type, erp_client_code, created_at FROM accounting_entities WHERE nif = ? AND entity_type = ? LIMIT 1');
    $stmt->execute([$nif, $normalizedType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

function resolveAccountingEntityDatabase(array $entity): string {
    $entityType = trim((string) ($entity['entity_type'] ?? ''));
    $erpDatabase = trim((string) ($entity['erp_database'] ?? ''));
    $erpClientCode = trim((string) ($entity['erp_client_code'] ?? ''));

    if ($entityType === 'acquirer' && $erpClientCode !== '') {
        return $erpClientCode;
    }
    if ($erpDatabase !== '') {
        return $erpDatabase;
    }
    if ($erpClientCode !== '') {
        return $erpClientCode;
    }
    return '';
}

/**
 * Persist accounting entity information locally.
 *
 * @param PDO  $pdo    Active database connection.
 * @param array $data  Associative array with entity fields, including the ERP client code.
 * @return void
 */
function saveAccountingEntity(PDO $pdo, array $data): void {
    $nif = trim((string) ($data['nif'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $erpDatabase = trim((string) ($data['erp_database'] ?? ''));
    $entityType = trim((string) ($data['entity_type'] ?? ''));
    $erpClientCode = trim((string) ($data['erp_client_code'] ?? ''));

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

    if (is_array($existing) && !empty($existing['id'])) {
        $stmt = $pdo->prepare(
            'UPDATE accounting_entities SET name = ?, erp_database = ?, entity_type = ?, erp_client_code = ? WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $erpDatabase,
            $entityType,
            $erpClientCode,
            (int) $existing['id'],
        ]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO accounting_entities (nif, name, erp_database, entity_type, erp_client_code) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$nif, $name, $erpDatabase, $entityType, $erpClientCode]);
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

    if (array_key_exists($nif, $cache)) {
        return $cache[$nif] ?: null;
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

    $existing = null;
    try {
        $existing = findAccountingEntity($pdo, $nif);
        if ($existing !== null && !isPlaceholderAccountingEntityName($existing['name'] ?? '', $nif)) {
            $cache[$nif] = $existing;
            return $existing;
        }
    } catch (Throwable $e) {
        logErpMessage('Erro ao pesquisar entidade ' . $nif . ': ' . $e->getMessage());
        $cache[$nif] = null;
        return null;
    }

    $remote = fetchAccountingEntityFromErp($nif, $defaultEntityType, false, $defaultErpDatabase ?? '');
    if ($remote === null) {
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

    $data = [
        'nif' => $nif,
        'name' => $name,
        'erp_database' => $erpDatabase,
        'erp_client_code' => trim((string) ($remote['erp_client_code'] ?? '')),
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
 * Extract invoice lines using AWS Textract via a Python helper script.
 * Returns an array of line items with the same structure as
 * parseInvoiceLineText along with the raw text.
 *
 * @param string $filePath Path to the document image or PDF.
 * @return array<int,array<string,mixed>> Parsed line items.
 * @throws RuntimeException When Textract fails.
 */
function parseInvoiceLineTextract(string $filePath): array {
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

    $env = [
        'AWS_ACCESS_KEY_ID' => $key,
        'AWS_SECRET_ACCESS_KEY' => $secret,
        'AWS_REGION' => $region,
        'AWS_TEXTRACT_BUCKET' => $bucket,
    ];

    $script = __DIR__ . '/textract.py';
    $cmd = 'python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($filePath);
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
    if ($status !== 0) {
        logOcrMessage('Textract script error: ' . $error);
        throw new RuntimeException('Falha no OCR Textract');
    }
    $data = json_decode($output, true);
    if (! is_array($data)) {
        throw new RuntimeException('Saída inválida do Textract');
    }
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
        if ($costCenterRequired) {
            $result[$rate]['cost_center_required'] = '1';
        }
        if (!empty($baseSourceField)) {
            $result[$rate]['base_source_field'] = $baseSourceField;
        }
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

        if (array_key_exists($rate, $overrideSanitized)) {
            foreach (['iva_account', 'general_account', 'base', 'iva'] as $field) {
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
        if ($costCenterRequired !== '') {
            $result[(string) $rate]['cost_center_required'] = $costCenterRequired;
        }
        if ($baseSourceField !== '') {
            $result[(string) $rate]['base_source_field'] = $baseSourceField;
        }
    }

    return $result;
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

    $base0 = $totalBase - $base6 - $base13 - $base23;
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
            'require_general' => false,
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

        $normalizedRateKey = normalizeAccountingRateKey((string) $rate);
        $ivaAccount = $accountInfo['iva_account'] ?? '';
        if ($normalizedRateKey === '0') {
            $ivaAccount = '';
        }

        $payload[$rate] = [
            'label' => $label,
            'base' => $baseDisplay,
            'iva' => $ivaDisplay,
            'base_value' => $baseDisplay,
            'iva_value' => $ivaDisplay,
            'iva_account' => $ivaAccount,
            'general_account' => $accountInfo['general_account'] ?? '',
        ];
        $requirements[$rate] = [
            'general' => !empty($info['require_general']),
            'iva' => ($normalizedRateKey !== '0' && !empty($info['require_iva'])),
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
        $normalizedRateKey = normalizeAccountingRateKey($rateKey);

        if ($normalizedRateKey === '0') {
            $iva = '';
        }

        if ($general === '' && $iva === '' && $base === '' && $ivaValue === '') {
            continue;
        }

        $requirements[$rateKey] = [
            'general' => true,
            'iva' => ($normalizedRateKey !== '0'),
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

    if (($metadata['ignore_detected_rates'] ?? '0') === '1') {
        $payload = filterVisibleAccountingRates($accounts);
        $manualRequirements = buildManualClassificationRequirements($payload);
        if (!empty($manualRequirements)) {
            $requirements = $manualRequirements;
        }
    }

    return [$payload, $requirements];
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
        if ($normalizedRateKey !== '0' && !empty($req['iva'])) {
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
            $iva = $normalizedRateKey === '0' ? '' : trim((string) ($data['iva_account'] ?? ''));
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
        $ivaAmount = resolveAccountingLineAmount($config['iva'] ?? '', $summary['iva_value'] ?? null);
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
        $totalAmount = computeDocumentTotalAmount($document);
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

?>
