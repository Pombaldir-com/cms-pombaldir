<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
//ini_set('max_execution_time', 120); set_time_limit(120);
startSession();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

$newToken = generateCsrfToken();

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Ficheiro não enviado',
        'csrf_token' => $newToken,
    ]);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['file']['tmp_name']);
if ($mime !== 'application/pdf') {
    http_response_code(400);
    echo json_encode([
        'error' => 'Apenas ficheiros PDF são permitidos',
        'csrf_token' => $newToken,
    ]);
    exit;
}

$slug = getCompanySlug();
if (!$slug) {
    http_response_code(500);
    $fileName = $_FILES['file']['name'] ?? 'desconhecido';
    echo json_encode([
        'error' => 'Empresa não selecionada para o ficheiro ' . $fileName,
        'csrf_token' => $newToken,
    ]);
    exit;
}

$year = date('Y');
$month = date('m');
$uploadDir = dirname(__DIR__) . '/uploads/' . $slug . '/accounting/' . $year . '/' . $month . '/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true)) {
        error_log('Failed to create upload directory: ' . $uploadDir);
        http_response_code(500);
        $fileName = $_FILES['file']['name'] ?? 'desconhecido';
        echo json_encode([
            'error' => 'Erro ao criar diretório de upload para o ficheiro ' . $fileName,
            'csrf_token' => $newToken,
        ]);
        exit;
    }
}

$filename = bin2hex(random_bytes(16)) . '.pdf';
$targetPath = $uploadDir . $filename;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
    http_response_code(500);
    $fileName = $_FILES['file']['name'] ?? 'desconhecido';
    echo json_encode([
        'error' => 'Erro ao guardar o ficheiro ' . $fileName,
        'csrf_token' => $newToken,
    ]);
    exit;
}

$text = '';
try {
    $ocr = new thiagoalessio\TesseractOCR\TesseractOCR($targetPath);
    $text = $ocr->run();
} catch (Throwable $e) {
    // OCR failed; return empty text
}

$qrText = null;
$script = __DIR__ . '/detectar_qr.py';
$cmd = escapeshellcmd("python3 {$script}") . ' ' . escapeshellarg($targetPath);
$output = [];
$ret = 0;
exec($cmd, $output, $ret);
if ($ret === 0 && !empty($output)) {
    $qrText = trim($output[0]);
}

$relativePath = 'uploads/' . $slug . '/accounting/' . $year . '/' . $month . '/' . $filename;

echo json_encode([
    'success' => true,
    'file' => $relativePath,
    'text' => $text,
    'qr_text' => $qrText,
    'fields' => ['qr_code' => $qrText],
    'csrf_token' => $newToken,
]);
