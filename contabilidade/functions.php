<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../functions.php';

use thiagoalessio\TesseractOCR\TesseractOCR;
use Aws\Textract\TextractClient;
use Aws\Textract\Exception\TextractException;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

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
 * Extract invoice lines using AWS Textract. Returns an array of line items
 * with the same structure as parseInvoiceLineText along with the raw text.
 *
 * @param string $filePath Path to the document image or PDF.
 * @return array<int,array<string,mixed>> Parsed line items.
 * @throws RuntimeException When Textract fails.
 */
function parseInvoiceLineTextract(string $filePath): array {
    if (!class_exists(TextractClient::class) || !class_exists(S3Client::class)) {
        throw new RuntimeException('AWS SDK para PHP não instalado');
    }
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

    $config = ['version' => 'latest', 'region' => $region];
    if ($key && $secret) {
        $config['credentials'] = ['key' => $key, 'secret' => $secret];
    }

    $s3 = new S3Client($config);

    try {

        $s3->headBucket(['Bucket' => $bucket]);
    } catch (AwsException $e) {
        if ($e->getStatusCode() === 404) {

            $params = ['Bucket' => $bucket];
            if ($region !== 'us-east-1') {
                $params['CreateBucketConfiguration'] = ['LocationConstraint' => $region];
            }
            $s3->createBucket($params);
            $s3->waitUntil('BucketExists', ['Bucket' => $bucket]);

        } else {
            throw $e;
        }
    }

    $textract = new TextractClient($config);

    $objectKey = 'textract/' . uniqid('', true) . '.' . $extension;
    $s3->putObject([
        'Bucket' => $bucket,
        'Key' => $objectKey,
        'SourceFile' => $filePath,
    ]);

    try {
        try {
            $start = $textract->startExpenseAnalysis([
                'DocumentLocation' => ['S3Object' => ['Bucket' => $bucket, 'Name' => $objectKey]],
            ]);
            $jobId = $start['JobId'];
            do {
                sleep(1);
                $result = $textract->getExpenseAnalysis(['JobId' => $jobId]);
                $status = $result['JobStatus'] ?? 'IN_PROGRESS';
            } while ($status === 'IN_PROGRESS');

            if ($status === 'SUCCEEDED') {
                $lines = [];
                foreach ($result['ExpenseDocuments'][0]['LineItemGroups'] ?? [] as $group) {
                    foreach ($group['LineItems'] ?? [] as $item) {
                        $line = [
                            'arm' => '',
                            'codigo_artigo' => '',
                            'descricao' => '',
                            'quantidade' => '',
                            'unidade' => '',
                            'preco_unitario' => '',
                            'percentagem_desconto' => '',
                            'desconto_valor' => '',
                            'valor_liquido' => '',
                            'imposto' => '',
                            'text' => '',
                        ];
                        foreach ($item['LineItemExpenseFields'] ?? [] as $field) {
                            $type = $field['Type']['Text'] ?? '';
                            $value = $field['ValueDetection']['Text'] ?? '';
                            $num = (float) str_replace(',', '.', $value);
                            switch ($type) {
                                case 'ITEM':
                                    $line['descricao'] = $value;
                                    break;
                                case 'QUANTITY':
                                    $line['quantidade'] = $num;
                                    break;
                                case 'UNIT_PRICE':
                                case 'PRICE':
                                    $line['preco_unitario'] = $num;
                                    break;
                                case 'UNIT':
                                    $line['unidade'] = $value;
                                    break;
                                case 'AMOUNT':
                                    $line['valor_liquido'] = $num;
                                    break;
                                case 'TAX_RATE':
                                    $line['imposto'] = $num;
                                    break;
                                case 'TAX':
                                    // Textract sometimes labels the tax rate simply as "TAX".
                                    // When the value represents a percentage we treat it as the
                                    // tax rate rather than the tax amount.
                                    if (in_array('PERCENTAGE', $field['EntityTypes'] ?? [], true)
                                        || strpos($value, '%') !== false) {
                                        $line['imposto'] = $num;
                                    }
                                    break;
                                case 'DISCOUNT':
                                    $line['desconto_valor'] = $num;
                                    break;
                            }
                        }
                        $line['text'] = trim($line['descricao']);
                        $lines[] = $line;
                    }
                }
                if ($lines) {
                    return $lines;
                }
            }
        } catch (TextractException $e) {
            if ($e->getAwsErrorCode() === 'UnsupportedDocumentException') {
                throw new RuntimeException('Formato de arquivo não suportado pelo Textract', 0, $e);
            }
            logOcrMessage('Textract StartExpenseAnalysis error: ' . $e->getMessage());
        } catch (Throwable $e) {
            logOcrMessage('Textract StartExpenseAnalysis error: ' . $e->getMessage());
        }

        $start = $textract->startDocumentTextDetection([
            'DocumentLocation' => ['S3Object' => ['Bucket' => $bucket, 'Name' => $objectKey]],
        ]);
        $jobId = $start['JobId'];
        $blocks = [];
        $nextToken = null;
        do {
            sleep(1);
            $params = ['JobId' => $jobId];
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
            }
            $result = $textract->getDocumentTextDetection($params);
            $status = $result['JobStatus'] ?? 'IN_PROGRESS';
            $blocks = array_merge($blocks, $result['Blocks'] ?? []);
            $nextToken = $result['NextToken'] ?? null;
        } while ($status === 'IN_PROGRESS' || $nextToken);

        if ($status !== 'SUCCEEDED') {
            throw new RuntimeException('Falha no OCR Textract');
        }

        $lines = [];
        foreach ($blocks as $block) {
            if (($block['BlockType'] ?? '') !== 'LINE') {
                continue;
            }
            $text = $block['Text'] ?? '';
            try {
                $fields = parseInvoiceLineText($text);
            } catch (RuntimeException $e) {
                $fields = [
                    'arm' => '',
                    'codigo_artigo' => '',
                    'descricao' => '',
                    'quantidade' => '',
                    'unidade' => '',
                    'preco_unitario' => '',
                    'percentagem_desconto' => '',
                    'desconto_valor' => '',
                    'valor_liquido' => '',
                    'imposto' => '',
                ];
            }
            $fields['text'] = $text;
            $lines[] = $fields;
        }
        return $lines;
    } catch (TextractException $e) {
        if ($e->getAwsErrorCode() === 'UnsupportedDocumentException') {
            throw new RuntimeException('Formato de arquivo não suportado pelo Textract', 0, $e);
        }
        logOcrMessage('Textract DetectDocumentText error: ' . $e->getMessage());
        throw new RuntimeException('Falha no OCR Textract', 0, $e);
    } catch (Throwable $e) {
        logOcrMessage('Textract DetectDocumentText error: ' . $e->getMessage());
        throw new RuntimeException('Falha no OCR Textract', 0, $e);
    } finally {
        try {
            $s3->deleteObject(['Bucket' => $bucket, 'Key' => $objectKey]);
        } catch (Throwable $e) {
            // ignore
        }
    }
}

/**
 * Remove legacy per-IVA columns so only the serialized `account` column remains.
 */
function dropLegacyAccountColumns(PDO $pdo): void {
    $tables = ['accounting_imports', 'accounting_classifications'];
    $legacy = ['account_iva6', 'account_iva13', 'account_iva23', 'account_novat'];

    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $drops = [];
        foreach ($legacy as $col) {
            if (in_array($col, $columns, true)) {
                $drops[] = "DROP COLUMN `{$col}`";
            }
        }
        if ($drops) {
            $pdo->exec("ALTER TABLE {$table} " . implode(', ', $drops));
        }
    }
}

?>
