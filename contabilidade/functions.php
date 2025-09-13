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

?>
