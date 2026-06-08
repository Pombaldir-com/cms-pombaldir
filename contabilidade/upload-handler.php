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
header('X-Upload-Handler-Version: 20260420-csrf-refresh');

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}
if (!userHasDepartmentPermission('compras_upload')) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissao para upload de compras.']);
    exit;
}

$newToken = generateCsrfToken();
$debugEnabled = (int) getSetting('debug_mode', '0') === 1 || !empty($_GET['debug']);

// NOTA: este handler apenas GRAVA o ficheiro e responde de imediato (resposta rapida).
// A leitura de QR codes (lenta) corre num pedido AJAX separado em
// upload.php?action=detect-qr, evitando timeouts (524) com muitos ficheiros.

function uploadSizeToBytes(string $value): int {
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    switch ($unit) {
        case 'g':
            $number *= 1024;
            // no break
        case 'm':
            $number *= 1024;
            // no break
        case 'k':
            $number *= 1024;
            break;
    }

    return (int) $number;
}

function formatUploadBytes(int $bytes): string {
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $value = (float) $bytes;
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }

    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$index];
}

function describeUploadErrorCode(int $errorCode): string {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Ficheiro excede o tamanho máximo permitido';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload incompleto. Volte a enviar o ficheiro.';
        case UPLOAD_ERR_NO_FILE:
            return 'Ficheiro não enviado';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Diretório temporário de upload indisponível no servidor';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Falha ao gravar o upload no servidor';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload bloqueado por uma extensão PHP';
    }

    return 'Ficheiro não enviado';
}

$isCsrfRefreshRequest = ($_POST['action'] ?? '') === 'refresh_csrf'
    || (
        empty($_FILES)
        && isset($_POST['csrf_token'])
        && count($_POST) === 1
        && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded') !== false
    );
if ($isCsrfRefreshRequest) {
    $csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
    if ($csrfToken === '' || !validateCsrfToken($csrfToken, false)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Token CSRF inválido',
            'csrf_token' => generateCsrfToken(true),
            'handler_version' => '20260420-csrf-refresh',
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'csrf_token' => generateCsrfToken(),
        'handler_version' => '20260420-csrf-refresh',
    ]);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postMaxSize = (string) ini_get('post_max_size');
    $uploadMaxFilesize = (string) ini_get('upload_max_filesize');
    $postMaxBytes = uploadSizeToBytes($postMaxSize);
    $errorCode = isset($_FILES['file']['error']) ? (int) $_FILES['file']['error'] : null;
    $errorMessage = $errorCode !== null ? describeUploadErrorCode($errorCode) : 'Ficheiro não enviado';

    if ($errorCode === null && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        $errorMessage = 'Ficheiro excede o tamanho máximo permitido pelo servidor'
            . ' (pedido: ' . formatUploadBytes($contentLength)
            . ', limite: ' . $postMaxSize . ').';
    }

    $response = ['error' => $errorMessage, 'csrf_token' => $newToken];
    if ($debugEnabled) {
        $response['upload_debug'] = [
            'content_length' => $contentLength,
            'content_length_human' => formatUploadBytes($contentLength),
            'post_max_size' => $postMaxSize,
            'upload_max_filesize' => $uploadMaxFilesize,
            'file_error' => $errorCode,
            'files_keys' => array_keys($_FILES),
            'post_keys' => array_keys($_POST),
            'content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        ];
    }
    http_response_code(400);
    echo json_encode($response);
    exit;
}

$csrfToken = trim((string) ($_POST['csrf_token'] ?? ''));
if ($csrfToken === '' || !validateCsrfToken($csrfToken, false)) {
    http_response_code(400);
    echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
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

$relativePath = "uploads/$slug/accounting/$year/$month/$filename";

// Resposta rapida: o ficheiro esta' gravado. A leitura de QR e' feita a seguir
// pelo cliente via upload.php?action=detect-qr (pedido AJAX separado).
echo json_encode([
    'success' => true,
    'file' => $relativePath,
    'csrf_token' => $newToken,
]);
