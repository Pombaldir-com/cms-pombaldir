<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

startSession();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'lines') {
    if (!isLoggedIn()) {
        http_response_code(403);
        exit;
    }
    $id = $_GET['id'] ?? '';
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT id, filename FROM accounting_imports WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="ocr_lines_' . $row['id'] . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['id', 'filename', 'line_number', 'text']);
    $path = dirname(__DIR__) . '/' . $row['filename'];
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $text = '';
    if ($extension === 'pdf') {
        if (!class_exists('Imagick')) {
            http_response_code(500);
            fclose($output);
            exit;
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
    $lines = explode(PHP_EOL, $text);
    $inTable = false;
    foreach ($lines as $i => $line) {
        if (! $inTable) {
            if (stripos($line, 'Descrição') !== false
                || stripos($line, 'Unidade') !== false
                || stripos($line, 'Taxa') !== false) {
                $inTable = true;
            }
            continue;
        }
        if (stripos($line, 'Mercadoria') !== false) {
            $inTable = false;
            continue;
        }
        if (trim($line) === '') {
            continue;
        }
        $tokens = preg_split('/\s+/', trim($line));
        if (count($tokens) < 10) {
            continue;
        }
        fputcsv($output, [$row['id'], $row['filename'], $i + 1, $line]);
    }
    fclose($output);
    exit;
}

header('Content-Type: application/json');

if (!isLoggedIn()) {
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
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1');
    $stmt->execute([$a, $b, $d]);
    $account = $stmt->fetchColumn() ?: '';
    echo json_encode(['account' => $account, 'csrf_token' => generateCsrfToken()]);
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
    $account = $_POST['account'] ?? '';
    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE accounting_imports SET account = ? WHERE id = ?');
        $stmt->execute([$account, $id]);
        $stmt2 = $pdo->prepare('INSERT INTO accounting_classifications (emitter, acquirer, doc_type, account) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE account = VALUES(account)');
        $stmt2->execute([$a, $b, $d, $account]);
        $pdo->commit();
        echo json_encode(['success' => true, 'csrf_token' => generateCsrfToken()]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao guardar', 'csrf_token' => generateCsrfToken()]);
    }
    exit;
} elseif ($action === 'parse-line') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $text = $_POST['text'] ?? '';
    try {
        $fields = parseInvoiceLineText($text);
        echo json_encode(['fields' => $fields, 'csrf_token' => generateCsrfToken()]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Linha inválida', 'csrf_token' => generateCsrfToken()]);
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
    $pdo = getPDO();
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
