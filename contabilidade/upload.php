<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
$hasComprasUploadPermission = userHasDepartmentPermission('compras_upload');
if (!$hasComprasUploadPermission) {
    http_response_code(403);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Sem permissao para upload de compras.']);
    } else {
        echo 'Acesso negado.';
    }
    exit;
}

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

    if ($action === 'sync-entity') {
        $value = $_POST['value'] ?? '';
        $entityType = $_POST['entity_type'] ?? 'emitter';
        $database = isset($_POST['database']) ? trim((string) $_POST['database']) : trim((string) getSetting('erp_database', ''));
        $acquirerValue = $_POST['acquirer_value'] ?? '';
        if (trim($value) === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Entidade inválida', 'csrf_token' => $newToken]);
            exit;
        }

        $pdo = getPDO();
        $nif = extractVatNumber((string) $value);
        $acquirerNif = extractVatNumber((string) $acquirerValue);
        $acquirerEntity = null;
        $requiresAcquirerDatabase = false;
        if ($acquirerNif !== '') {
            $acquirerEntity = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
            if ($acquirerEntity && !empty($acquirerEntity['erp_database'])) {
                if ($database === '') {
                    $database = trim((string) $acquirerEntity['erp_database']);
                }
            } else {
                $requiresAcquirerDatabase = true;
            }
        }
        $debugRemote = null;
        if ($nif !== '') {
            $debugRemote = fetchAccountingEntityFromErp($nif, 'emitter', true, $database);
        }
        $entity = ensureAccountingEntity($pdo, (string) $value, ['entity_type' => $entityType, 'erp_database' => $database]);

        echo json_encode([
            'success' => $entity !== null,
            'entity' => $entity,
            'remote' => $debugRemote,
            'requires_acquirer_database' => $requiresAcquirerDatabase,
            'acquirer' => $acquirerNif !== '' ? [
                'nif' => $acquirerNif,
                'name' => trim((string) (($acquirerEntity['name'] ?? '') ?: deriveEntityNameFromField((string) $acquirerValue, $acquirerNif))),
                'erp_database' => trim((string) ($acquirerEntity['erp_database'] ?? '')),
            ] : null,
            'csrf_token' => $newToken,
        ]);
        exit;
    } elseif ($action === 'set-acquirer-database') {
        $acquirerNif = extractVatNumber((string) ($_POST['acquirer_nif'] ?? ''));
        $acquirerValue = trim((string) ($_POST['acquirer_value'] ?? ''));
        $selectedDatabase = trim((string) ($_POST['database'] ?? ''));

        if ($acquirerNif === '' || $selectedDatabase === '') {
            http_response_code(400);
            echo json_encode(['error' => 'NIF e base de dados são obrigatórios.', 'csrf_token' => $newToken]);
            exit;
        }

        $pdo = getPDO();
        $existing = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
        $name = trim((string) ($existing['name'] ?? ''));
        if ($name === '') {
            $source = $acquirerValue !== '' ? $acquirerValue : $acquirerNif;
            $name = deriveEntityNameFromField($source, $acquirerNif);
        }
        if ($name === '') {
            $name = 'Cliente ' . $acquirerNif;
        }

        $saveData = [
            'nif' => $acquirerNif,
            'name' => $name,
            'erp_database' => $selectedDatabase,
            'entity_type' => 'acquirer',
            'erp_client_code' => trim((string) ($existing['erp_client_code'] ?? '')),
        ];
        saveAccountingEntity($pdo, $saveData);

        $stored = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
        echo json_encode([
            'success' => true,
            'entity' => $stored ?: $saveData,
            'csrf_token' => $newToken,
        ]);
        exit;
    } elseif ($action === 'import') {
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

        // Preencher conta associada, se existir classificação e sincronizar entidade do emitente
        $stmt = $pdo->prepare('SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1');
        $entityCache = [];
        foreach ($rows as &$row) {
            $a = $row['A'] ?? '';
            $b = $row['B'] ?? '';
            $d = $row['D'] ?? '';
            if ($a !== '') {
                $nif = extractVatNumber((string) $a);
                if ($nif !== '' && !array_key_exists($nif, $entityCache)) {
                    $entityCache[$nif] = ensureAccountingEntity($pdo, (string) $a);
                }
            }
            $stmt->execute([$a, $b, $d]);
            $row['account'] = $stmt->fetchColumn() ?: '';
        }
        unset($row);

        // Inserir linhas na tabela accounting_imports, evitando duplicados pelo field_H e pelo tipo de importação
        $insert = $pdo->prepare('INSERT INTO accounting_imports (field_A, field_B, field_C, field_D, field_E, field_F, field_G, field_H, field_I1, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8, field_N, field_O, field_Q, field_R, account, filename, import_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $exists = $pdo->prepare('SELECT 1 FROM accounting_imports WHERE field_H = ? AND import_type = ? LIMIT 1');
        foreach ($rows as $row) {
            $fieldH = $row['H'] ?? '';
            if ($fieldH !== '') {
                // Verifica se já existe um registo com o mesmo documento e tipo de importação
                $exists->execute([$fieldH, $importType]);
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
$erpDatabase = trim((string) getSetting('erp_database', ''));
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


<div class="modal fade" id="uploadAcquirerDatabaseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="uploadAcquirerDatabaseForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa fa-database"></i> Base de dados do adquirente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p id="uploadAcquirerDatabaseMessage" class="mb-3">
                        Indique a base de dados ERP para o adquirente.
                    </p>
                    <div class="mb-3">
                        <label for="uploadAcquirerDatabaseInput" class="form-label">Base de dados ERP</label>
                        <input type="text" class="form-control" id="uploadAcquirerDatabaseInput" placeholder="Ex: emp_236" required>
                    </div>
                    <div id="uploadAcquirerDatabaseError" class="alert alert-danger d-none" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="uploadAcquirerDatabaseConfirmBtn">
                        <i class="fa fa-save"></i> Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
<script>
window.erpDatabase = <?= json_encode($erpDatabase, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>
