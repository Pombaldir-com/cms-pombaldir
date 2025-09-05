<?php
require_once __DIR__ . '/../functions.php';

startSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sessão inválida']);
        exit;
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $newToken = generateCsrfToken();

    if ($action === 'import') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Dados inválidos', 'csrf_token' => $newToken]);
            exit;
        }

        $token = $data['csrf_token'] ?? '';
        if (!validateCsrfToken($token)) {
            http_response_code(400);
            echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => $newToken]);
            exit;
        }

        $rows = $data['rows'] ?? [];
        if (!is_array($rows)) {
            http_response_code(400);
            echo json_encode(['error' => 'Dados inválidos', 'csrf_token' => $newToken]);
            exit;
        }

        $slug = getCompanySlug();
        if (!$slug) {
            http_response_code(500);
            echo json_encode(['error' => 'Empresa não selecionada', 'csrf_token' => $newToken]);
            exit;
        }

        $year = date('Y');
        $month = date('m');
        $dir = dirname(__DIR__) . '/uploads/' . $slug . '/accounting/' . $year . '/' . $month . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao criar diretório de importação', 'csrf_token' => $newToken]);
            exit;
        }

        $file = $dir . 'import.json';
        $success = file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;

        if ($success) {
            echo json_encode(['success' => true, 'csrf_token' => $newToken]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao gravar o ficheiro', 'csrf_token' => $newToken]);
        }

        exit;
    } elseif ($action === 'delete') {
        $file = $_POST['file'] ?? '';
        $slug = getCompanySlug();
        $baseDir = realpath(dirname(__DIR__) . '/uploads/' . $slug . '/accounting/');

        if ($file === '' || !$baseDir) {
            http_response_code(400);
            echo json_encode(['error' => 'Ficheiro inválido', 'csrf_token' => $newToken]);
            exit;
        }

        $fullPath = realpath(dirname(__DIR__) . '/' . ltrim($file, '/'));
        if ($fullPath === false || strpos($fullPath, $baseDir) !== 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Ficheiro inválido', 'csrf_token' => $newToken]);
            exit;
        }

        $success = file_exists($fullPath) && unlink($fullPath);
        if ($success) {
            echo json_encode(['success' => true, 'csrf_token' => $newToken]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Falha ao eliminar o ficheiro ' . $file, 'csrf_token' => $newToken]);
        }

        exit;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Ação inválida', 'csrf_token' => $newToken]);
        exit;
    }
}

$useDropzone = true;
$useDataTables = true;
require_once __DIR__ . '/../header.php';
$csrfToken = generateCsrfToken();
?>
<form id="multi-upload" class="dropzone">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
</form>

    <div id="qr-results">
        <table id="qr-table" class="table table-striped">
        <thead>
            <tr>

                <th class="text-start">Emitente</th>
                <th class="text-start">Adquirente</th>
                <th></th>
                <th width="5%" class="text-middle">TP</th>
                <th></th>
                <th width="8%" class="text-middle">Data</th>
                <th width="12%">Doc</th>
                <th></th>
                <th>País</th>
                <th width="8%">Total s/IVA</th>
                <th width="8%">IVA 23%</th>
                <th width="8%">Total IVA</th>
                <th width="8%">Total</th>
                <th></th>
                <th></th>
                <th data-orderable="false">Ações</th>
            </tr>
        </thead>
        <tbody></tbody>
        </table>
        <button id="import-btn" class="btn btn-success mt-3" style="display: none;">Importar</button>
    </div>






<?php

function checkQrRequirements(array &$messages): bool {
    $messages = [];
    $ok = true;

    // 1. Extensão Imagick ou GD
    $hasImagick = extension_loaded('imagick');
    $hasGd      = extension_loaded('gd');
    if (!$hasImagick && !$hasGd) {
        $messages[] = 'Falta Imagick ou GD. Pelo menos um é necessário.';
        $ok = false;
    }

    // 2. Verificar a existência do pdftoppm
    $hasPdftoppm = false;
    if (function_exists('proc_open')) {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open('command -v pdftoppm', $descriptor, $pipes);
        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($process);
            $hasPdftoppm = ($status === 0 && !empty(trim($output)));
        }
    } elseif (function_exists('shell_exec')) {
        $hasPdftoppm = !empty(shell_exec('command -v pdftoppm'));
    }

    if (!$hasPdftoppm && !$hasImagick) {
        $messages[] = 'Nem pdftoppm nem Imagick estão disponíveis para converter PDFs.';
        $ok = false;
    }

    return $ok;
}


$messages = [];
if (!checkQrRequirements($messages)) {
    foreach ($messages as $message) {
        echo $message . '<br>';
    }
    echo 'Dependências ausentes. Verifique seu ambiente MAMP.';
}

?>

<?php require_once __DIR__ . '/../footer.php'; ?>
