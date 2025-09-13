<?php
require_once __DIR__ . '/../functions.php';

startSession();

$comprasActive = isModuleActive('compras');

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

        $importType = isset($data['import_type']) ? (int)$data['import_type'] : 1;

        $pdo = getPDO();

        // Preencher conta associada, se existir classificação
        $stmt = $pdo->prepare('SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1');
        foreach ($rows as &$row) {
            $a = $row['A'] ?? '';
            $b = $row['B'] ?? '';
            $d = $row['D'] ?? '';
            $stmt->execute([$a, $b, $d]);
            $row['account'] = $stmt->fetchColumn() ?: '';
        }
        unset($row);

        // Inserir linhas na tabela accounting_imports, evitando duplicados pelo field_H
        $insert = $pdo->prepare('INSERT INTO accounting_imports (field_A, field_B, field_C, field_D, field_E, field_F, field_G, field_H, field_I1, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8, field_N, field_O, field_Q, field_R, account, filename, import_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $exists = $pdo->prepare('SELECT 1 FROM accounting_imports WHERE field_H = ? LIMIT 1');
        foreach ($rows as $row) {
            $fieldH = $row['H'] ?? '';
            if ($fieldH !== '') {
                $exists->execute([$fieldH]);
                if ($exists->fetchColumn()) {
                    continue; // pular se já existir
                }
            }

            $insert->execute([
                $row['A'] ?? '',
                $row['B'] ?? '',
                $row['C'] ?? '',
                $row['D'] ?? '',
                $row['E'] ?? '',
                $row['F'] ?? '',
                $row['G'] ?? '',
                $fieldH,
                $row['I1'] ?? '',
                $row['I3'] ?? '',
                $row['I4'] ?? '',
                $row['I5'] ?? '',
                $row['I6'] ?? '',
                $row['I7'] ?? '',
                $row['I8'] ?? '',
                $row['N'] ?? '',
                $row['O'] ?? '',
                $row['Q'] ?? '',
                $row['R'] ?? '',
                $row['account'] ?? '',
                $row['filename'] ?? '',
                $importType
            ]);
        }

        echo json_encode(['success' => true, 'csrf_token' => $newToken]);
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
<div class="row mb-3">
    <div class="col-12">
  
    
<form id="multi-upload" class="dropzone d-none d-md-block">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
</form>
</div>



</div>

<div class="row mb-3">

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
                <th width="8%">Base 6%</th>
                <th width="8%">IVA 6%</th>
                <th width="8%">Base 13%</th>
                <th width="8%">IVA 13%</th>
                <th width="8%">Base 23%</th>
                <th width="6%">IVA 23%</th>
                <th width="8%">Total IVA</th>
                <th width="8%">Total</th>
                <th></th>
                <th></th>
                <th data-orderable="false" width="8%" class="text-middle">Ações</th>
            </tr>
        </thead>
        <tbody></tbody>
        </table>
        <button id="import-btn" class="btn btn-success mt-3" style="display: none;">Importar</button>
        <?php if ($comprasActive): ?>
        <button id="import-compras-btn" class="btn btn-primary mt-3" style="display: none;">Importar Compras</button>
        <?php endif; ?>
    </div>

</div>

<div class="row mb-3">
<div class="col-12">
<div id="mobile-upload-actions" class="d-flex gap-2 mb-3 d-md-none">
    <button id="camera-btn" class="btn btn-secondary">Usar câmara</button>
    <button id="mobile-file-btn" class="btn btn-primary">Selecionar PDF</button>
</div>

</div>
</div>


<?php require_once __DIR__ . '/../footer.php'; ?>
