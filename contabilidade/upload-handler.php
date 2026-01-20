<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../vendor/autoload.php';
use setasign\FPDF\FPDF;

ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '20M');
set_time_limit(300);
startSession();

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

$newToken = generateCsrfToken();
$debugEnabled = (int) getSetting('debug_mode', '0') === 1 || !empty($_GET['debug']);
$qrDpi = (int) getSetting('qr_dpi', '150');
if ($qrDpi <= 0) {
    $qrDpi = 150;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    $errorMessage = 'Ficheiro não enviado';
    if (isset($_FILES['file']['error']) && in_array($_FILES['file']['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        $errorMessage = 'Ficheiro excede o tamanho máximo permitido';
    }
    http_response_code(400);
    echo json_encode(['error' => $errorMessage, 'csrf_token' => $newToken]);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['file']['tmp_name']);
$allowed = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Tipo de ficheiro inválido', 'csrf_token' => $newToken]);
    exit;
}

$slug = getCompanySlug();
if (!$slug) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Empresa não selecionada para o ficheiro ' . ($_FILES['file']['name'] ?? 'desconhecido'),
        'csrf_token' => $newToken,
    ]);
    exit;
}

$year = date('Y');
$month = date('m');
$uploadDir = dirname(__DIR__) . "/uploads/$slug/accounting/$year/$month/";
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
    error_log("Failed to create upload directory: $uploadDir");
    http_response_code(500);
    echo json_encode([
        'error' => 'Erro ao criar diretório de upload para o ficheiro ' . ($_FILES['file']['name'] ?? 'desconhecido'),
        'csrf_token' => $newToken,
    ]);
    exit;
}

$filename = bin2hex(random_bytes(16)) . '.pdf';
$targetPath = $uploadDir . $filename;

if ($mime === 'application/pdf') {
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erro ao guardar o ficheiro ' . ($_FILES['file']['name'] ?? 'desconhecido'),
            'csrf_token' => $newToken,
        ]);
        exit;
    }
} else {
    $imgInfo = getimagesize($_FILES['file']['tmp_name']);
    if ($imgInfo === false) {
        http_response_code(400);
        echo json_encode(['error' => 'Imagem inválida', 'csrf_token' => $newToken]);
        exit;
    }
    $width = $imgInfo[0] * 0.264583;
    $height = $imgInfo[1] * 0.264583;
    $orientation = $width > $height ? 'L' : 'P';
    $pdf = new FPDF($orientation, 'mm', [$width, $height]);
    $pdf->AddPage();
    $pdf->Image($_FILES['file']['tmp_name'], 0, 0, $width, $height);
    $pdf->Output('F', $targetPath);
    if (!file_exists($targetPath)) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Erro ao converter a imagem ' . ($_FILES['file']['name'] ?? 'desconhecido'),
            'csrf_token' => $newToken,
        ]);
        exit;
    }
}

// Detectar QR codes com Python
$qrTexts = [];

$script = __DIR__ . '/detectar_qr.py';
$popplerPath = getenv('POPPLER_PATH');
$envPrefix = $popplerPath ? ('POPPLER_PATH=' . escapeshellarg($popplerPath) . ' ') : '';
$cmd = $envPrefix . escapeshellcmd("python3 $script") . ' ' . escapeshellarg($targetPath) . ' --dpi ' . escapeshellarg((string) $qrDpi) . ' 2>&1';
$output = [];
$ret = 0;
exec($cmd, $output, $ret);

// Log de debug (opcional)
if ($debugEnabled) {
    file_put_contents(__DIR__ . '/debug_qr.txt', "CMD: $cmd\nRET: $ret\n" . implode(PHP_EOL, $output));
}

if ($ret === 0 && !empty($output)) {
    foreach ($output as $line) {
        $line = trim($line);
        if ($line !== '') {
            $qrTexts[] = $line;
        }
    }
    $qrTexts = array_unique(array_map('trim', $qrTexts));
} else {
    error_log("Erro ao executar detectar_qr.py\nRet: $ret\nSaída:\n" . implode(PHP_EOL, $output));
}

$relativePath = "uploads/$slug/accounting/$year/$month/$filename";

echo json_encode([
    'success' => true,
    'file' => $relativePath,
    'qr_texts' => $qrTexts,
    'csrf_token' => $newToken,
]);
