<?php
// Helpers partilhados para os downloads gerados pelo assistente AI.
// Incluido por assistant-handler.php (criacao) e assistant-download.php (entrega).
// Apenas definicoes de funcoes; sem efeitos colaterais.

require_once __DIR__ . '/functions.php';

function hasAssistantDownloadsTable(): bool
{
    return hasTable('ai_assistant_downloads');
}

/**
 * Formatos suportados para os ficheiros gerados pelo assistente.
 *
 * @return array<string, array{ext:string, mime:string}>
 */
function assistantDownloadFormats(): array
{
    return [
        'csv' => ['ext' => 'csv', 'mime' => 'text/csv; charset=utf-8'],
        'txt' => ['ext' => 'txt', 'mime' => 'text/plain; charset=utf-8'],
        'md'  => ['ext' => 'md',  'mime' => 'text/markdown; charset=utf-8'],
        'json' => ['ext' => 'json', 'mime' => 'application/json; charset=utf-8'],
        'pdf' => ['ext' => 'pdf', 'mime' => 'application/pdf'],
    ];
}

function assistantResolveDownloadFormat(string $format): array
{
    $format = strtolower(trim($format));
    $formats = assistantDownloadFormats();
    return $formats[$format] ?? $formats['txt'];
}

function buildAssistantDownloadUrl(string $token): string
{
    return BASE_URL . 'assistant/download/' . rawurlencode($token);
}

/**
 * Garante que o nome do ficheiro tem a extensao certa e e seguro.
 */
function assistantNormalizeDownloadFilename(string $filename, string $ext): string
{
    $filename = trim($filename);
    if ($filename === '') {
        $filename = 'export';
    }
    $filename = str_replace(['\\', '/'], '-', $filename);
    $filename = preg_replace('/[^a-zA-Z0-9._\- ]/', '', $filename);
    $filename = trim((string) $filename);
    if ($filename === '') {
        $filename = 'export';
    }
    if (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== strtolower($ext)) {
        $filename .= '.' . $ext;
    }
    return $filename;
}

/**
 * Construir um CSV (separador ';', decimais com virgula, BOM UTF-8) amigavel
 * para Excel PT-PT a partir de linhas associativas.
 *
 * @param array<int, array<string, mixed>> $rows
 * @param array<int, string> $columns Ordem/colunas a exportar; vazio = chaves da 1a linha.
 * @param array<int, string> $headerLabels Cabecalhos a apresentar; vazio = nomes das colunas.
 */
function assistantBuildCsv(array $rows, array $columns = [], array $headerLabels = []): string
{
    if (!$columns && $rows) {
        $first = reset($rows);
        if (is_array($first)) {
            $columns = array_keys($first);
        }
    }
    $labels = $headerLabels ?: $columns;
    $lines = [];
    $lines[] = implode(';', array_map('assistantCsvCell', $labels));
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cells = [];
        foreach ($columns as $col) {
            $value = $row[$col] ?? '';
            if (is_float($value) || (is_int($value))) {
                $value = number_format((float) $value, 2, ',', '');
            }
            $cells[] = assistantCsvCell((string) $value);
        }
        $lines[] = implode(';', $cells);
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

function assistantCsvCell(string $value): string
{
    if (preg_match('/[";\r\n]/', $value)) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

/**
 * Gerar um PDF simples (texto monoespacado) a partir de um titulo e corpo.
 * Usa FPDF (setasign/fpdf), disponivel via composer.
 */
function assistantBuildPdfFromText(string $title, string $body): string
{
    if (!class_exists('FPDF')) {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
    if (!class_exists('FPDF')) {
        return '';
    }
    $toLatin = static function (string $text): string {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
        return $converted === false ? $text : $converted;
    };
    $pdf = new FPDF();
    $pdf->AddPage();
    if (trim($title) !== '') {
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->MultiCell(0, 7, $toLatin($title));
        $pdf->Ln(2);
    }
    $pdf->SetFont('Courier', '', 9);
    $lines = preg_split('/\r\n|\r|\n/', $body);
    foreach ($lines as $line) {
        $pdf->MultiCell(0, 5, $toLatin($line) === '' ? ' ' : $toLatin($line));
    }
    return (string) $pdf->Output('', 'S');
}

/**
 * Criar um download para o utilizador: grava o conteudo em BD e devolve o link.
 *
 * @return array{ok:bool, token?:string, filename?:string, url?:string, size?:int, mime?:string, error?:string}
 */
function createAssistantDownload(
    PDO $pdo,
    int $userId,
    string $sessionId,
    string $filename,
    string $mime,
    string $content,
    int $ttlSeconds = 86400
): array {
    if (!hasAssistantDownloadsTable()) {
        return ['ok' => false, 'error' => 'Tabela de downloads em falta. Execute as migracoes.'];
    }
    if ($content === '') {
        return ['ok' => false, 'error' => 'Conteudo vazio; nada para descarregar.'];
    }
    $maxBytes = 8 * 1024 * 1024; // 8 MB
    if (strlen($content) > $maxBytes) {
        return ['ok' => false, 'error' => 'Ficheiro demasiado grande para download.'];
    }
    $token = bin2hex(random_bytes(24));
    $now = time();
    $expires = $ttlSeconds > 0 ? $now + $ttlSeconds : null;
    $stmt = $pdo->prepare(
        'INSERT INTO ai_assistant_downloads (token, user_id, session_id, filename, mime, content, size_bytes, created_at, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bindValue(1, $token);
    $stmt->bindValue(2, $userId, PDO::PARAM_INT);
    $stmt->bindValue(3, $sessionId);
    $stmt->bindValue(4, $filename);
    $stmt->bindValue(5, $mime);
    $stmt->bindValue(6, $content, PDO::PARAM_LOB);
    $stmt->bindValue(7, strlen($content), PDO::PARAM_INT);
    $stmt->bindValue(8, $now, PDO::PARAM_INT);
    if ($expires === null) {
        $stmt->bindValue(9, null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(9, $expires, PDO::PARAM_INT);
    }
    $stmt->execute();

    return [
        'ok' => true,
        'token' => $token,
        'filename' => $filename,
        'url' => buildAssistantDownloadUrl($token),
        'size' => strlen($content),
        'mime' => $mime,
    ];
}

/**
 * Obter um download por token (para o endpoint de entrega).
 *
 * @return array<string, mixed>|null
 */
function fetchAssistantDownload(PDO $pdo, string $token): ?array
{
    if (!hasAssistantDownloadsTable() || $token === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT id, token, user_id, filename, mime, content, size_bytes, expires_at FROM ai_assistant_downloads WHERE token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
