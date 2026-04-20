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
if (!userHasDepartmentPermission('compras_upload')) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissao para upload de compras.']);
    exit;
}

$newToken = generateCsrfToken();
$debugEnabled = (int) getSetting('debug_mode', '0') === 1 || !empty($_GET['debug']);
$qrDpi = (int) getSetting('qr_dpi', '150');
if ($qrDpi <= 0) {
    $qrDpi = 150;
}
$qrRetryDpi = (int) getSetting('qr_retry_dpi', (string) max(300, $qrDpi * 2));
if ($qrRetryDpi <= $qrDpi) {
    $qrRetryDpi = max(300, $qrDpi * 2);
}
$qrAutoMaxPages = (int) getSetting('qr_auto_max_pages', '1');
if ($qrAutoMaxPages < 0) {
    $qrAutoMaxPages = 1;
}
$qrAutoMaxAttempts = (int) getSetting('qr_auto_max_attempts', '6');
if ($qrAutoMaxAttempts <= 0) {
    $qrAutoMaxAttempts = 6;
}
$qrRetryMaxPages = (int) getSetting('qr_retry_max_pages', '2');
if ($qrRetryMaxPages < 0) {
    $qrRetryMaxPages = 2;
}
$qrRetryMaxAttempts = (int) getSetting('qr_retry_max_attempts', '12');
if ($qrRetryMaxAttempts <= 0) {
    $qrRetryMaxAttempts = 12;
}

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

// Detectar QR codes com Python
$qrTexts = [];
$timings = [
    'started_at_ms' => (int) round(microtime(true) * 1000),
];
$script = __DIR__ . '/detectar_qr.py';
$popplerPath = getenv('POPPLER_PATH');
$envPrefix = $popplerPath ? ('POPPLER_PATH=' . escapeshellarg($popplerPath) . ' ') : '';

$detectQr = static function (int $dpi, int $maxPages, int $maxAttempts, bool $receiptPriority, int $page = 1, bool $singlePageScan = False) use ($envPrefix, $script, $targetPath): array {
    $cmd = $envPrefix . escapeshellcmd("python3 $script")
        . ' ' . escapeshellarg($targetPath)
        . ' --dpi ' . escapeshellarg((string) $dpi)
        . ' --max-pages ' . escapeshellarg((string) $maxPages)
        . ' --max-attempts ' . escapeshellarg((string) $maxAttempts)
        . ' --page ' . escapeshellarg((string) $page)
        . ($singlePageScan ? ' --single-page-scan' : '')
        . ($receiptPriority ? ' --receipt-priority' : '')
        . ' 2>&1';
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);

    $texts = [];
    if ($ret === 0 && !empty($output)) {
        foreach ($output as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $texts[] = $line;
            }
        }
        $texts = array_values(array_unique(array_map('trim', $texts)));
    }

    return [
        'dpi' => $dpi,
        'max_pages' => $maxPages,
        'max_attempts' => $maxAttempts,
        'receipt_priority' => $receiptPriority ? 1 : 0,
        'page' => $page,
        'single_page_scan' => $singlePageScan ? 1 : 0,
        'cmd' => $cmd,
        'ret' => $ret,
        'output' => $output,
        'texts' => $texts,
    ];
};

$mergeQrTexts = static function (array ...$groups): array {
    $merged = [];
    $seen = [];
    foreach ($groups as $group) {
        foreach ($group as $text) {
            $value = trim((string) $text);
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $merged[] = $value;
        }
    }
    return $merged;
};

$completePdfAttempt = static function (array $attempt) use ($mime, $detectQr, $qrRetryDpi, $qrRetryMaxAttempts): ?array {
    if ($mime !== 'application/pdf') {
        return null;
    }

    $texts = isset($attempt['texts']) && is_array($attempt['texts']) ? $attempt['texts'] : [];
    if (empty($texts)) {
        return null;
    }

    $attemptMaxPages = (int) ($attempt['max_pages'] ?? 0);
    if ($attemptMaxPages === 0) {
        return null;
    }

    $completionDpi = max((int) ($attempt['dpi'] ?? 0), $qrRetryDpi);
    if ($completionDpi <= 0) {
        return null;
    }

    $completionMaxAttempts = (int) ($attempt['max_attempts'] ?? 0);
    if ($completionMaxAttempts <= 0) {
        $completionMaxAttempts = $qrRetryMaxAttempts;
    } else {
        $completionMaxAttempts = max($completionMaxAttempts, $qrRetryMaxAttempts);
    }

    return $detectQr(
        $completionDpi,
        0,
        $completionMaxAttempts,
        !empty($attempt['receipt_priority'])
    );
};

$attempts = [];
$attempts[] = $detectQr($qrDpi, $qrAutoMaxPages, $qrAutoMaxAttempts, false);
$qrTexts = $attempts[0]['texts'];

$completionAttempt = $completePdfAttempt($attempts[0]);
if (is_array($completionAttempt)) {
    $attempts[] = $completionAttempt;
    $qrTexts = $mergeQrTexts($qrTexts, $completionAttempt['texts'] ?? []);
}

if (empty($qrTexts)) {
    $attempts[] = $detectQr($qrRetryDpi, $qrRetryMaxPages, $qrRetryMaxAttempts, false);
    $qrTexts = $attempts[count($attempts) - 1]['texts'];

    $completionAttempt = $completePdfAttempt($attempts[count($attempts) - 1]);
    if (is_array($completionAttempt)) {
        $attempts[] = $completionAttempt;
        $qrTexts = $mergeQrTexts($qrTexts, $completionAttempt['texts'] ?? []);
    }
}

if (empty($qrTexts)) {
    $attempts[] = $detectQr($qrRetryDpi, $qrRetryMaxPages, $qrRetryMaxAttempts, true);
    $qrTexts = $attempts[count($attempts) - 1]['texts'];

    $completionAttempt = $completePdfAttempt($attempts[count($attempts) - 1]);
    if (is_array($completionAttempt)) {
        $attempts[] = $completionAttempt;
        $qrTexts = $mergeQrTexts($qrTexts, $completionAttempt['texts'] ?? []);
    }
}

$timings['finished_at_ms'] = (int) round(microtime(true) * 1000);
$timings['total_ms'] = max(0, (int) ($timings['finished_at_ms'] - $timings['started_at_ms']));

if ($debugEnabled) {
    $debugLog = [];
    foreach ($attempts as $index => $attempt) {
        $debugLog[] = 'ATTEMPT ' . ($index + 1) . ' | DPI: ' . $attempt['dpi'];
        $debugLog[] = 'PAGE: ' . $attempt['page'] . ' | SINGLE_PAGE_SCAN: ' . $attempt['single_page_scan'];
        $debugLog[] = 'MAX_PAGES: ' . $attempt['max_pages'] . ' | MAX_ATTEMPTS: ' . $attempt['max_attempts'] . ' | RECEIPT_PRIORITY: ' . $attempt['receipt_priority'];
        $debugLog[] = 'CMD: ' . $attempt['cmd'];
        $debugLog[] = 'RET: ' . $attempt['ret'];
        $debugLog[] = implode(PHP_EOL, $attempt['output']);
        $debugLog[] = '';
    }
    file_put_contents(__DIR__ . '/debug_qr.txt', implode(PHP_EOL, $debugLog));
}

if (empty($qrTexts)) {
    foreach ($attempts as $attempt) {
        error_log(
            "Erro ao executar detectar_qr.py\nDPI: " . $attempt['dpi']
            . "\nMAX_PAGES: " . $attempt['max_pages']
            . "\nMAX_ATTEMPTS: " . $attempt['max_attempts']
            . "\nRet: " . $attempt['ret']
            . "\nSaída:\n" . implode(PHP_EOL, $attempt['output'])
        );
    }
}

$relativePath = "uploads/$slug/accounting/$year/$month/$filename";

echo json_encode([
    'success' => true,
    'file' => $relativePath,
    'qr_texts' => $qrTexts,
    'qr_attempted_dpis' => array_values(array_map(static function (array $attempt): int {
        return (int) ($attempt['dpi'] ?? 0);
    }, $attempts)),
    'qr_attempts' => array_values(array_map(static function (array $attempt): array {
        return [
            'dpi' => (int) ($attempt['dpi'] ?? 0),
            'max_pages' => (int) ($attempt['max_pages'] ?? 0),
            'max_attempts' => (int) ($attempt['max_attempts'] ?? 0),
            'receipt_priority' => !empty($attempt['receipt_priority']),
            'page' => (int) ($attempt['page'] ?? 1),
            'single_page_scan' => !empty($attempt['single_page_scan']),
            'ret' => (int) ($attempt['ret'] ?? 0),
        ];
    }, $attempts)),
    'timings' => $timings,
    'csrf_token' => $newToken,
]);
