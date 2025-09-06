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
<form id="multi-upload" class="dropzone d-none d-md-block">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
</form>

<div id="mobile-upload-actions" class="d-flex gap-2 mb-3 d-md-none">
    <button id="camera-btn" class="btn btn-secondary">Usar câmara</button>
    <button id="mobile-file-btn" class="btn btn-primary">Selecionar PDF</button>
</div>

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

<script src="assets/js/mobile_capture.js"></script>

<?php require_once __DIR__ . '/../footer.php'; ?>
