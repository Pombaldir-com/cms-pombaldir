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

    foreach (['nif', 'vat', 'contrib'] as $queryKeyword) {
        if (preg_match('/[?&][^=]*' . $queryKeyword . '[^=]*=$/i', $url)) {
            return $url . $encodedNif;
        }
    }

    if (substr($url, -1) === '?' || substr($url, -1) === '&') {
        return $url . 'nif=' . $encodedNif;
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
 * @param array  $payload     Raw payload returned by the ERP webservice.
 * @param string $nif         VAT number requested.
 * @param string $sourceLabel Human readable label for logging purposes.
 * @param bool   $logEmpty    Whether to log when no usable data is found.
 * @return array|null Associative array with the extracted data or null if none was found.
 */
function parseErpEntityPayload(array $payload, string $nif, string $sourceLabel = 'Webservice ERP-SINC', bool $logEmpty = true): ?array {
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
        $dbKeys = ['erp_database', 'erpdatabase', 'database', 'db', 'bd', 'base_dados', 'basedados', 'intcodigo'];
        foreach ($dbKeys as $dbKey) {
            if (array_key_exists($dbKey, $normalisedCandidate)) {
                $erpDatabase = trim((string) $normalisedCandidate[$dbKey]);
                break;
            }
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
            'entity_type' => $entityType,
        ];
    }

    if ($logEmpty) {
        logErpMessage($sourceLabel . ' sem dados reconhecíveis para o NIF ' . $nif);
    }
    return null;
}

/**
 * Retrieve accounting entity information from the NIF.pt service.
 *
 * This legacy helper is kept for backwards compatibility and is not used by the
 * current ERP-SINC synchronisation flow.
 *
 * @param string $nif    VAT number requested.
 * @param string $reason Contextual reason for logging when the fallback is used.
 * @return array|null Associative array with the extracted data or null when unavailable.
 */
function fetchAccountingEntityFromNifPt(string $nif, string $reason = ''): ?array {
    $token = getSetting('erp_nif_pt', '');
    if ($token === null) {
        $token = '';
    }

    $token = trim($token);
    if ($token === '') {
        if ($reason !== '') {
            logErpMessage('Não é possível recorrer ao NIF.pt para o NIF ' . $nif . ' (' . $reason . '): token não configurado.');
        }
        return null;
    }

    if (!function_exists('curl_init')) {
        logErpMessage('Extensão cURL não disponível para sincronizar entidade ' . $nif . ' via NIF.pt.');
        return null;
    }

    $query = [
        'json' => '1',
        'q'    => $nif,
        'key'  => $token,
    ];

    $url = 'https://www.nif.pt/?' . http_build_query($query);
    $handle = curl_init($url);
    if ($handle === false) {
        logErpMessage('Falha ao inicializar pedido ao NIF.pt para o NIF ' . $nif . '.');
        return null;
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'cms-pombaldir/1.0 (+https://github.com/Pombaldir-com/cms-pombaldir)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($handle);
    if ($response === false) {
        logErpMessage('Erro cURL ao obter entidade ' . $nif . ' do NIF.pt: ' . curl_error($handle));
        curl_close($handle);
        return null;
    }

    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($status >= 400) {
        logErpMessage('NIF.pt devolveu HTTP ' . $status . ' para o NIF ' . $nif . '.');
        return null;
    }

    if ($response === '') {
        logErpMessage('NIF.pt devolveu resposta vazia para o NIF ' . $nif . '.');
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        logErpMessage('Resposta NIF.pt inválida para o NIF ' . $nif . ': ' . substr($response, 0, 200));
        return null;
    }

    $entity = parseErpEntityPayload($data, $nif, 'NIF.pt', false);
    if ($entity !== null) {
        $context = $reason !== '' ? ' (' . $reason . ')' : '';
        logErpMessage('Dados do NIF ' . $nif . ' sincronizados via NIF.pt' . $context . '.');
        return $entity;
    }

    $records = [];
    if (isset($data['records']) && is_array($data['records'])) {
        if (isset($data['records'][$nif]) && is_array($data['records'][$nif])) {
            $records[] = $data['records'][$nif];
        }
        foreach ($data['records'] as $record) {
            if (is_array($record)) {
                $records[] = $record;
            }
        }
    } elseif (isset($data['result']) && is_array($data['result'])) {
        $records[] = $data['result'];
    }

    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }

        $candidateNif = extractVatNumber((string) ($record['nif'] ?? $record['numero_contribuinte'] ?? $record['numerocontribuinte'] ?? $record['contribuinte'] ?? ''));
        if ($candidateNif !== '' && $candidateNif !== $nif) {
            continue;
        }

        $name = trim((string) ($record['nome'] ?? $record['name'] ?? $record['title'] ?? ''));
        if ($name === '') {
            continue;
        }

        $context = $reason !== '' ? ' (' . $reason . ')' : '';
        logErpMessage('Dados do NIF ' . $nif . ' sincronizados via NIF.pt' . $context . '.');

        return [
            'nif' => $candidateNif !== '' ? $candidateNif : $nif,
            'name' => $name,
            'erp_database' => '',
            'entity_type' => '',
        ];
    }

    logErpMessage('NIF.pt sem dados reconhecíveis para o NIF ' . $nif . '.');
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
        $headers[] = 'X-Auth-Token: ' . $token;
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
    $stmt = $pdo->prepare('SELECT id, name, nif, erp_database, entity_type, created_at FROM accounting_entities WHERE nif = ? LIMIT 1');
    $stmt->execute([$nif]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row !== false ? $row : null;
}

/**
 * Persist accounting entity information locally.
 *
 * @param PDO  $pdo    Active database connection.
 * @param array $data  Associative array with entity fields.
 * @return void
 */
function saveAccountingEntity(PDO $pdo, array $data): void {
    $stmt = $pdo->prepare(
        'INSERT INTO accounting_entities (nif, name, erp_database, entity_type) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE name = VALUES(name), erp_database = VALUES(erp_database), entity_type = VALUES(entity_type)'
    );
    $stmt->execute([
        $data['nif'],
        $data['name'],
        $data['erp_database'],
        $data['entity_type'],
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
        $entityType = 'emitente';
    }

    $data = [
        'nif' => $nif,
        'name' => $name,

        'erp_database' => trim((string) ($remote['erp_database'] ?? '')),

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
 * Remove legacy account columns from accounting tables if they exist.
 *
 * Previous iterations stored account information directly in dedicated
 * columns.  The current schema uses a JSON field instead, so these old
 * columns should be dropped.  The function is safe to run multiple
 * times because it checks for a column's existence before attempting
 * to drop it.
 *
 * @param PDO $pdo Active database connection
 * @return void
 */
function dropLegacyAccountColumns(PDO $pdo): void {
    $legacyCols = ['account_iva6', 'account_iva13', 'account_iva23', 'account_novat'];
    $tables = ['accounting_imports', 'accounting_classifications'];

    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($legacyCols as $col) {
            if (in_array($col, $existing, true)) {
                $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$col}");
            }
        }
    }
}

/**
 * Normalize stored account information into a structure keyed by VAT rate.
 *
 * @param string|null $json JSON-encoded account data.
 * @return array<string,array<string,string>>
 */
function normalizeAccountingAccounts(?string $json): array {
    $rates = ['0', '6', '13', '23'];
    $result = [];
    foreach ($rates as $rate) {
        $result[$rate] = [
            'iva_account' => '',
            'general_account' => '',
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
    } else {
        $sources[] = $data;
    }

    foreach ($sources as $source) {
        foreach ($source as $key => $value) {
            $keyString = (string) $key;
            if (in_array($keyString, $rates, true)) {
                if (is_array($value)) {
                    if (array_key_exists('iva_account', $value)) {
                        $result[$keyString]['iva_account'] = (string) $value['iva_account'];
                    } elseif (array_key_exists('iva', $value)) {
                        $result[$keyString]['iva_account'] = (string) $value['iva'];
                    }
                    if (array_key_exists('general_account', $value)) {
                        $result[$keyString]['general_account'] = (string) $value['general_account'];
                    } elseif (array_key_exists('general', $value)) {
                        $result[$keyString]['general_account'] = (string) $value['general'];
                    }
                } elseif (is_string($value) || is_numeric($value)) {
                    $result[$keyString]['general_account'] = (string) $value;
                }
                continue;
            }

            switch ($keyString) {
                case 'iva6':
                    $result['6']['iva_account'] = (string) $value;
                    break;
                case 'iva13':
                    $result['13']['iva_account'] = (string) $value;
                    break;
                case 'iva23':
                    $result['23']['iva_account'] = (string) $value;
                    break;
                case 'novat':
                    $result['0']['general_account'] = (string) $value;
                    break;
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
    $rates = ['0', '6', '13', '23'];
    $result = [];
    foreach ($rates as $rate) {
        $rateInput = $input[$rate] ?? [];
        if (!is_array($rateInput)) {
            $rateInput = [];
        }
        $result[$rate] = [
            'iva_account' => isset($rateInput['iva_account']) ? trim((string) $rateInput['iva_account']) : '',
            'general_account' => isset($rateInput['general_account']) ? trim((string) $rateInput['general_account']) : '',
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

    foreach (['0', '6', '13', '23'] as $rate) {
        foreach (['iva_account', 'general_account'] as $field) {
            if (array_key_exists($field, $overrideSanitized[$rate])) {
                $baseSanitized[$rate][$field] = $overrideSanitized[$rate][$field];
            }
        }
    }

    return $baseSanitized;
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

    foreach ($summaries as $rate => $info) {
        $payload[$rate] = [
            'base' => $info['base_display'] ?? '',
            'iva' => $info['iva_display'] ?? '',
            'iva_account' => $accounts[$rate]['iva_account'] ?? '',
            'general_account' => $accounts[$rate]['general_account'] ?? '',
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
