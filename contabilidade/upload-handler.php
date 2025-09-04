<?php 
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/functions.php';

startSession();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['error' => 'Token CSRF inválido']);
    exit;
}

$newToken = generateCsrfToken();

if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Ficheiro não enviado']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['file']['tmp_name']);
if ($mime !== 'application/pdf') {
    http_response_code(400);
    echo json_encode(['error' => 'Apenas ficheiros PDF são permitidos']);
    exit;
}

$slug = getCompanySlug();
if (!$slug) {
    http_response_code(500);
    echo json_encode(['error' => 'Empresa não selecionada']);
    exit;
}

$year = date('Y');
$month = date('m');
$uploadDir = dirname(__DIR__) . '/uploads/' . $slug . '/accounting/' . $year . '/' . $month . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = bin2hex(random_bytes(16)) . '.pdf';
$targetPath = $uploadDir . $filename;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao guardar o ficheiro']);
    exit;
}

// Extract text and invoice fields
$analysis = extractInvoiceFields($targetPath);
$text = $analysis['text'];
unset($analysis['text']);

$qrText = extractQrStringFromPdf($targetPath);

$relativePath = 'uploads/' . $slug . '/accounting/' . $year . '/' . $month . '/' . $filename;

$fields = array_merge(['qr_code' => $qrText], $analysis);

echo json_encode([
    'success' => true,
    'file' => $relativePath,
    'text' => $text,
    'qr_text' => $qrText,
    'fields' => $fields,
    'csrf_token' => $newToken,
]);
