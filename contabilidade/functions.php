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

/**
 * Build a request URL for the ERP client endpoint.
 *
 * @param string $baseUrl Base URL stored in the settings.
 * @param string $nif     VAT number to query.
 * @return string Fully qualified URL.
 */
function buildErpClientEndpoint(string $baseUrl, string $nif): string {
    $url = trim($baseUrl);
    if ($url === '') {
        return '';
    }

    $encodedNif = urlencode($nif);

    $placeholderPatterns = [
        '/\{nif\}/i',
        '/\{vat\}/i',
        '/\{numero_contribuinte\}/i',
        '/\{contribuinte\}/i',
    ];

    foreach ($placeholderPatterns as $pattern) {
        if (preg_match($pattern, $url)) {
            return preg_replace($pattern, $encodedNif, $url);
        }
    }

    if (strpos($url, '%s') !== false) {
        return sprintf($url, $encodedNif);
    }

    foreach (['nif', 'vat', 'contrib', 'q'] as $queryKeyword) {
        if (preg_match('/(?:[?&][^#]*' . $queryKeyword . '[^=]*)=$/i', $url)) {
            return $url . $encodedNif;
        }
    }

    if (substr($url, -1) === '?' || substr($url, -1) === '&') {
        return $url . 'nif=' . $encodedNif;
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

                return $baseWithoutQuery . '?' . http_build_query($finalQuery, '', '&', PHP_QUERY_RFC3986);
            }
        }
    }

    $base = rtrim($url, '/');
    $separator = strpos($base, '?') === false ? '?' : '&';
    return $base . $separator . 'nif=' . $encodedNif;

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


    $candidateKeyMap = array_fill_keys(['data', 'cliente', 'clientes', 'result', 'results', 'record', 'records'], true);


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

        $nifKeys = ['nif', 'vat', 'vatnumber', 'nifcliente', 'numero_contribuinte', 'numerocontribuinte', 'contribuinte', 'strnumcontrib'];


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
function fetchAccountingEntityFromErp(string $nif): ?array {
    if (!function_exists('curl_init')) {
        logErpMessage('Extensão cURL não disponível para sincronizar entidade ' . $nif . ' via ERP-SINC.');
        return null;
    }

    $baseUrl = getSetting('erp_webservice_url', '');
    if ($baseUrl === null || trim($baseUrl) === '') {
        logErpMessage('URL do ERP-SINC não configurada para sincronizar o NIF ' . $nif . '.');
        return null;
    }

    $endpoint = buildErpClientEndpoint($baseUrl, $nif);
    if ($endpoint === '') {
        logErpMessage('URL do ERP-SINC inválida para o NIF ' . $nif . '.');
        return null;
    }

    $sanitizedEndpoint = sanitizeUrlForLog($endpoint);
    $endpointInfo = $sanitizedEndpoint !== '' ? ' URL: ' . $sanitizedEndpoint : '';

    $token = getSetting('erp_token', '');
    $headers = ['Accept: application/json'];
    if ($token !== null && $token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
        $headers[] = 'X-Api-Key: ' . $token;
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
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $response = curl_exec($handle);
    if ($response === false) {
        logErpMessage('Erro cURL ao obter entidade ' . $nif . ' do ERP-SINC: ' . curl_error($handle) . $endpointInfo);
        curl_close($handle);
        return null;
    }

    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($status >= 400) {
        logErpMessage('Webservice ERP-SINC devolveu HTTP ' . $status . ' para o NIF ' . $nif . '.' . $endpointInfo);
        return null;
    }

    if ($status === 204 || trim((string) $response) === '') {
        logErpMessage('Webservice ERP-SINC devolveu resposta vazia para o NIF ' . $nif . '.' . $endpointInfo);
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        logErpMessage('Resposta ERP-SINC inválida para o NIF ' . $nif . ': ' . substr($response, 0, 200) . $endpointInfo);
        return null;
    }

    $entity = parseErpEntityPayload($data, $nif);
    if ($entity === null) {
        logErpMessage('Dados do NIF ' . $nif . ' indisponíveis no ERP-SINC.' . $endpointInfo);
        return null;
    }

    return $entity;
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
 * Persist accounting entity information locally.
 *
 * @param PDO  $pdo    Active database connection.
 * @param array $data  Associative array with entity fields, including the ERP client code.
 * @return void
 */
function saveAccountingEntity(PDO $pdo, array $data): void {
    $stmt = $pdo->prepare(
        'INSERT INTO accounting_entities (nif, name, erp_database, entity_type, erp_client_code) VALUES (?, ?, ?, ?, ?)'
        . ' ON DUPLICATE KEY UPDATE name = VALUES(name), erp_database = VALUES(erp_database), entity_type = VALUES(entity_type), erp_client_code = VALUES(erp_client_code)'
    );
    $stmt->execute([
        $data['nif'],
        $data['name'],
        $data['erp_database'],
        $data['entity_type'],
        $data['erp_client_code'] ?? '',
    ]);
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
 * @return array|null Entity information if available.
 */
function ensureAccountingEntity(PDO $pdo, string $entityFieldValue): ?array {
    static $cache = [];

    $nif = extractVatNumber($entityFieldValue);
    if ($nif === '') {
        return null;
    }

    if (array_key_exists($nif, $cache)) {
        return $cache[$nif] ?: null;
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

    $remote = fetchAccountingEntityFromErp($nif);
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
        $name = deriveEntityNameFromField($entityFieldValue, $nif);
    }

    $entityType = trim((string) ($remote['entity_type'] ?? ''));
    if ($entityType === '') {
        $entityType = 'emitter';
    }

    $data = [
        'nif' => $nif,
        'name' => $name,
        'erp_database' => '',
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

    $metadataKeys = ['version', 'rates', 'label', 'labels', 'title'];

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

    $metadataKeys = ['version', 'rates', 'label', 'labels', 'title'];

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
 * Serialize normalized account information as JSON.
 *
 * @param array<string,mixed> $rates
 * @return string
 */
function serializeAccountingAccounts(array $rates): string {
    $sanitized = sanitizeAccountInput($rates);
    return json_encode([
        'version' => 2,
        'rates' => $sanitized,
    ], JSON_UNESCAPED_UNICODE);
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
 * Serialize cost centre values to be stored in the database.
 *
 * @param array<string,mixed> $centers
 * @return string
 */
function serializeCostCenters(array $centers): string {
    $sanitized = sanitizeCostCenterValues($centers);

    return json_encode([
        'version' => 1,
        'rates' => $sanitized,
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

        $payload[$rate] = [
            'label' => $label,
            'base' => $baseDisplay,
            'iva' => $ivaDisplay,
            'base_value' => $baseDisplay,
            'iva_value' => $ivaDisplay,
            'iva_account' => $accountInfo['iva_account'] ?? '',
            'general_account' => $accountInfo['general_account'] ?? '',
        ];
        $requirements[$rate] = [
            'general' => !empty($info['require_general']),
            'iva' => !empty($info['require_iva']),
        ];
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
function determineClassificationButtonClass(array $requirements, array $payload): string {
    $requires = false;
    $allFilled = true;
    $hasAny = false;


    foreach ($requirements as $rate => $req) {
        $data = $payload[$rate] ?? [];
        if (!empty($req['general'])) {
            $requires = true;
            $general = trim((string) ($data['general_account'] ?? ''));
            if ($general === '') {
                $allFilled = false;
            } else {
                $hasAny = true;
            }
        }
        if (!empty($req['iva'])) {
            $requires = true;
            $iva = trim((string) ($data['iva_account'] ?? ''));
            if ($iva === '') {
                $allFilled = false;
            } else {
                $hasAny = true;
            }
        }
    }

    if (!$requires || $allFilled) {
        return 'btn-success';
    }

    if ($hasAny) {
        return 'btn-warning';
    }

    return 'btn-secondary';
}

?>
