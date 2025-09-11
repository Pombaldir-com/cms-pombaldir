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


$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'lines') {
    if (!isLoggedIn() || getCompanySlug() === null) {
        http_response_code(403);
        exit;
    }
    $id = $_GET['id'] ?? '';
    try {
        $pdo = getPDO();
        dropLegacyAccountColumns($pdo);
    } catch (RuntimeException $e) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, filename FROM accounting_imports WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        http_response_code(404);
        exit;
    }
    $path = dirname(__DIR__) . '/' . $row['filename'];
    $ocrProvider = getSetting('ocr_provider', 'tesseract');
    if ($ocrProvider === 'textract') {

        try {
            $items = parseInvoiceLineTextract($path);
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="ocr_lines_' . $row['id'] . '.csv"');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['line_number', 'text', 'arm', 'codigo_artigo', 'descricao', 'quantidade', 'unidade', 'preco_unitario', 'percentagem_desconto', 'desconto_valor', 'valor_liquido', 'imposto']);
            foreach ($items as $i => $fields) {
                fputcsv($output, [
                    $i + 1,
                    $fields['text'] ?? '',
                    $fields['arm'] ?? '',
                    $fields['codigo_artigo'] ?? '',
                    $fields['descricao'] ?? '',
                    $fields['quantidade'] ?? '',
                    $fields['unidade'] ?? '',
                    $fields['preco_unitario'] ?? '',
                    $fields['percentagem_desconto'] ?? '',
                    $fields['desconto_valor'] ?? '',
                    $fields['valor_liquido'] ?? '',
                    $fields['imposto'] ?? '',
                ]);
            }
            exit;
        } catch (Throwable $e) {
            error_log('Textract OCR error: ' . $e->getMessage());
            // Fallback to Tesseract below
        }
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $text = '';
    try {
        if ($extension === 'pdf') {
            if (!class_exists('Imagick')) {
                throw new RuntimeException('Extensão Imagick não disponível');
            }
            $imagick = new Imagick();
            $imagick->setResolution(300, 300);
            $imagick->readImage($path);
            $imagick->setImageFormat('png');
            $tmpBase = sys_get_temp_dir() . '/ocr_' . uniqid();
            $imagick->writeImages($tmpBase . '.png', false);
            $imagick->clear();
            $imagick->destroy();
            foreach (glob($tmpBase . '-*.png') as $imgFile) {
                $ocr = new thiagoalessio\TesseractOCR\TesseractOCR($imgFile);
                $text .= $ocr->run() . PHP_EOL;
                unlink($imgFile);
            }
        } else {
            $ocr = new thiagoalessio\TesseractOCR\TesseractOCR($path);
            $text = $ocr->run();
        }
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Erro no OCR: ' . $e->getMessage()]);
        exit;
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ocr_lines_' . $row['id'] . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['line_number', 'text', 'arm', 'codigo_artigo', 'descricao', 'quantidade', 'unidade', 'preco_unitario', 'percentagem_desconto', 'desconto_valor', 'valor_liquido', 'imposto']);
    $lines = explode(PHP_EOL, $text);
    $inTable = false;
    $normalize = static function (string $str): string {
        return strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $str));
    };
    $unique = [];
    foreach ($lines as $i => $line) {
        if (! $inTable) {
            $norm = $normalize($line);
            if (strpos($norm, 'descricao') !== false
                || strpos($norm, 'unidade') !== false
                || strpos($norm, 'taxa') !== false) {
                $inTable = true;
                continue;
            }
            $tokens = preg_split('/\s+/', trim($line));
            if (count($tokens) >= 3 && preg_match('/\d/', $line)) {
                $inTable = true;
            } else {
                continue;
            }
        }
        if (strpos($normalize($line), 'mercadoria') !== false) {
            $inTable = false;
            continue;
        }

        if (trim($line) === '') {
            continue;
        }
        $tokens = preg_split('/\s+/', trim($line));
        if (count($tokens) < 3) {
            continue;
        }
        try {
            $fields = parseInvoiceLineText($line);
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

        $key = trim($normalize($line));
        if (isset($unique[$key])) {
            continue;
        }
        $unique[$key] = true;

        fputcsv(
            $output,
            array_merge(
                [$i + 1, $line],
                array_values($fields)
            )
        );
    }
    fclose($output);
    exit;
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
    try {
        $pdo = getPDO();
        dropLegacyAccountColumns($pdo);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $stmt = $pdo->prepare(
        'SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
    );
    $stmt->execute([$a, $b, $d]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $accounts = json_decode($row['account'] ?? '', true) ?: [];
    echo json_encode([
        'iva6' => $accounts['iva6'] ?? '',
        'iva13' => $accounts['iva13'] ?? '',
        'iva23' => $accounts['iva23'] ?? '',
        'novat' => $accounts['novat'] ?? '',
        'csrf_token' => generateCsrfToken()
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
        dropLegacyAccountColumns($pdo);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    try {
        $pdo->beginTransaction();

        // Load existing classifications so new entries do not wipe out
        // previously stored tax accounts for the same emitter/acquirer/doc type.
        $stmtExisting = $pdo->prepare(
            'SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
        );
        $stmtExisting->execute([$a, $b, $d]);
        $existingClass = json_decode($stmtExisting->fetchColumn() ?: '', true) ?: [];

        $stmtRow = $pdo->prepare('SELECT account FROM accounting_imports WHERE id = ?');
        $stmtRow->execute([$id]);
        $existingRow = json_decode($stmtRow->fetchColumn() ?: '', true) ?: [];

        // Merge existing accounts, giving priority to row-specific values and
        // any values explicitly submitted in this request (even empty ones).
        $accounts = array_merge($existingClass, $existingRow);
        foreach (['iva6', 'iva13', 'iva23', 'novat'] as $key) {
            if (array_key_exists($key, $_POST)) {
                $accounts[$key] = $_POST[$key];
            }
        }

        $serialized = json_encode($accounts);

        $stmt = $pdo->prepare('UPDATE accounting_imports SET account = ? WHERE id = ?');
        $stmt->execute([$serialized, $id]);

        $stmt2 = $pdo->prepare(
            'INSERT INTO accounting_classifications (emitter, acquirer, doc_type, account) '
            . 'VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE account = VALUES(account)'
        );
        $stmt2->execute([$a, $b, $d, $serialized]);
        $pdo->commit();
        echo json_encode(['success' => true, 'csrf_token' => generateCsrfToken()]);
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
        dropLegacyAccountColumns($pdo);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
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
