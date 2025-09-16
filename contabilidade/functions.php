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
            'require_general' => abs($base0) > 0.0001,
            'require_iva' => false,
        ],
        '6' => [
            'base_value' => $base6,
            'iva_value' => $iva6,
            'base_display' => $formatAmount($base6, $row['field_I3'] ?? null),
            'iva_display' => $formatAmount($iva6, $row['field_I4'] ?? null),
            'require_general' => abs($base6) > 0.0001,
            'require_iva' => abs($iva6) > 0.0001,
        ],
        '13' => [
            'base_value' => $base13,
            'iva_value' => $iva13,
            'base_display' => $formatAmount($base13, $row['field_I5'] ?? null),
            'iva_display' => $formatAmount($iva13, $row['field_I6'] ?? null),
            'require_general' => abs($base13) > 0.0001,
            'require_iva' => abs($iva13) > 0.0001,
        ],
        '23' => [
            'base_value' => $base23,
            'iva_value' => $iva23,
            'base_display' => $formatAmount($base23, $row['field_I7'] ?? null),
            'iva_display' => $formatAmount($iva23, $row['field_I8'] ?? null),
            'require_general' => abs($base23) > 0.0001,
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
