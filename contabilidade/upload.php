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

function fetchUploadDatabaseCompanyId(string $database): string {
    $database = trim($database);
    if ($database === '') {
        return '';
    }
    if (!function_exists('curl_init')) {
        return '';
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    $token = trim((string) getSetting('erp_token', ''));
    if ($baseUrl === '' || $token === '') {
        return '';
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, 'contabilidade');
    if ($endpoint === '') {
        return '';
    }

    $payload = ['act' => 'listDBemp'];
    $companyParams = buildErpCompanyQueryParams();
    if (!empty($companyParams['EMP'])) {
        $payload['EMP'] = $companyParams['EMP'];
    }
    if (!empty($companyParams['db'])) {
        $payload['db'] = $companyParams['db'];
        $payload['database'] = $companyParams['db'];
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        return '';
    }

    $handle = curl_init($endpoint);
    if ($handle === false) {
        return '';
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json; charset=utf-8',
            'X-API-KEY: ' . $token,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encoded,
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response) || $response === '' || $status >= 400) {
        return '';
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return '';
    }

    $candidates = [];
    if (isset($decoded['options']) && is_array($decoded['options'])) {
        $candidates = $decoded['options'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $candidates = $decoded['data'];
    } elseif (isset($decoded['result']) && is_array($decoded['result'])) {
        $candidates = $decoded['result'];
    } elseif (isset($decoded['list']) && is_array($decoded['list'])) {
        $candidates = $decoded['list'];
    } elseif (isset($decoded['aaData']) && is_array($decoded['aaData'])) {
        $candidates = $decoded['aaData'];
    } elseif (array_keys($decoded) === range(0, count($decoded) - 1)) {
        $candidates = $decoded;
    }

    $sampleRows = [];
    $sampleCount = 0;
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        if ($sampleCount < 3) {
            $sampleRows[] = $candidate;
            $sampleCount += 1;
        }
        $normalized = [];
        foreach ($candidate as $key => $value) {
            if (is_string($key)) {
                $normalized[strtolower($key)] = $value;
            }
        }

        $candidateDb = '';
        $dbKeys = [
            'db',
            'database',
            'strbasedados',
            'basedados',
            'nomebase',
            'strdb',
            'strdatabasename',
            'strbd',
        ];
        foreach ($dbKeys as $dbKey) {
            if (array_key_exists($dbKey, $normalized) && trim((string) $normalized[$dbKey]) !== '') {
                $candidateDb = trim((string) $normalized[$dbKey]);
                break;
            }
        }
        $candidateValue = trim((string) ($normalized['value'] ?? ''));

        $matchesDatabase = false;
        if ($candidateDb !== '' && strcasecmp($candidateDb, $database) === 0) {
            $matchesDatabase = true;
        } elseif ($candidateValue !== '' && strcasecmp($candidateValue, $database) === 0) {
            $matchesDatabase = true;
        } elseif ($candidateDb !== '' && stripos($candidateDb, $database) !== false) {
            $matchesDatabase = true;
        }

        if (!$matchesDatabase) {
            continue;
        }
        $id = '';
        $idKeys = [
            'id',
            'intcodigo',
            'codigo',
            'empresaid',
            'idempresa',
            'companyid',
            'empid',
        ];
        foreach ($idKeys as $idKey) {
            if (array_key_exists($idKey, $normalized) && trim((string) $normalized[$idKey]) !== '') {
                $id = trim((string) $normalized[$idKey]);
                break;
            }
        }
        if ($id === '' && preg_match('/^\d+$/', $candidateValue)) {
            $id = $candidateValue;
        }
        if ($id !== '') {
            return $id;
        }
    }

    if (!empty($sampleRows)) {
        $sampleJson = json_encode($sampleRows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($sampleJson)) {
            logErpMessage('listDBemp sem mapeamento de ID para db=' . $database . ' | amostra=' . $sampleJson);
        }
    } else {
        logErpMessage('listDBemp sem candidatos para resolver ID da db=' . $database);
    }

    return '';
}

function fetchUploadCompanyConfigCandidate(string $database): array {
    $database = trim($database);
    if ($database === '') {
        return ['id' => '', 'name' => ''];
    }
    if (!function_exists('curl_init')) {
        return ['id' => '', 'name' => ''];
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    $token = trim((string) getSetting('erp_token', ''));
    if ($baseUrl === '' || $token === '') {
        return ['id' => '', 'name' => ''];
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, '/tabelas/configEmpresa');
    if ($endpoint === '') {
        return ['id' => '', 'name' => ''];
    }
    $endpoint = appendQueryParamsToUrl($endpoint, [
        'db' => $database,
        'limit' => 5000,
        'offset' => 0,
    ]);

    $handle = curl_init($endpoint);
    if ($handle === false) {
        return ['id' => '', 'name' => ''];
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-KEY: ' . $token,
        ],
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if (!is_string($response) || $response === '' || $status >= 400) {
        return ['id' => '', 'name' => ''];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['id' => '', 'name' => ''];
    }
    $rows = [];
    if (isset($decoded['aaData']) && is_array($decoded['aaData'])) {
        $rows = $decoded['aaData'];
    }
    if (empty($rows)) {
        return ['id' => '', 'name' => ''];
    }

    $best = ['id' => '', 'name' => '', 'score' => -1];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['Id'] ?? ''));
        $name = trim((string) ($row['strValor'] ?? ''));
        if ($id === '' || $name === '') {
            continue;
        }
        if (!preg_match('/[A-Za-zÀ-ÿ]/u', $name)) {
            continue;
        }
        if (preg_match('/^[0-9\\s.,-]+$/', $name)) {
            continue;
        }
        if (preg_match('/[\\\\\\/\\|@]/', $name)) {
            continue;
        }
        $score = 10;
        if (preg_match('/\\b(LDA|UNIPESSOAL|S\\.?A\\.?|SOCIEDADE)\\b/i', $name)) {
            $score += 30;
        }
        if (mb_strlen($name) >= 15) {
            $score += 10;
        }
        if (mb_strlen($name) >= 25) {
            $score += 10;
        }
        if ($score > $best['score']) {
            $best = ['id' => $id, 'name' => $name, 'score' => $score];
        }
    }

    return ['id' => (string) ($best['id'] ?? ''), 'name' => (string) ($best['name'] ?? '')];
}

function fetchUploadCompanyNameByConfigEmpresaId(string $database, string $companyId, string $baseUrl, string $token): array {
    $database = trim($database);
    $companyId = trim($companyId);
    if ($database === '' || $companyId === '') {
        return ['ok' => false, 'name' => '', 'error' => ''];
    }

    $endpoint = buildErpEndpointFromBase($baseUrl, '/tabelas/configEmpresa');
    if ($endpoint === '') {
        return ['ok' => false, 'name' => '', 'error' => 'URL ERP inválida.'];
    }
    $endpoint = appendQueryParamsToUrl($endpoint, [
        'db' => $database,
        'q' => $companyId,
        'searchField' => 'Id',
    ]);

    $handle = curl_init($endpoint);
    if ($handle === false) {
        return ['ok' => false, 'name' => '', 'error' => 'Não foi possível iniciar a validação da base de dados ERP.'];
    }

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-KEY: ' . $token,
        ],
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $curlError = $response === false ? curl_error($handle) : '';
    curl_close($handle);

    if ($response === false) {
        return ['ok' => false, 'name' => '', 'error' => 'Erro ao comunicar com ERP: ' . $curlError];
    }
    if ($status >= 400) {
        return ['ok' => false, 'name' => '', 'error' => 'ERP devolveu erro HTTP ' . $status . ' ao validar a base de dados.'];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'name' => '', 'error' => 'Resposta inválida do ERP ao validar a base de dados.'];
    }

    $successFlag = isset($decoded['success']) ? trim((string) $decoded['success']) : '';
    if ($successFlag === '0' || $successFlag === 'false') {
        $message = trim((string) ($decoded['message'] ?? 'Falha ao validar base de dados no ERP.'));
        return ['ok' => false, 'name' => '', 'error' => $message !== '' ? $message : 'Falha ao validar base de dados no ERP.'];
    }

    $rows = [];
    if (isset($decoded['aaData']) && is_array($decoded['aaData'])) {
        $rows = $decoded['aaData'];
    } elseif (isset($decoded['data']) && is_array($decoded['data'])) {
        $rows = $decoded['data'];
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = trim((string) ($row['strValor'] ?? $row['name'] ?? $row['nome'] ?? ''));
        if ($candidate !== '') {
            return ['ok' => true, 'name' => $candidate, 'error' => ''];
        }
    }

    return ['ok' => false, 'name' => '', 'error' => ''];
}

function fetchUploadAcquirerCompanyName(string $database, string $companyId = ''): array {
    $database = trim($database);
    if ($database === '') {
        return ['ok' => false, 'name' => '', 'error' => 'Base de dados ERP inválida.'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'name' => '', 'error' => 'Extensão cURL não disponível.'];
    }

    $baseUrl = trim((string) getSetting('erp_webservice_url', ''));
    $token = trim((string) getSetting('erp_token', ''));
    if ($baseUrl === '' || $token === '') {
        return ['ok' => false, 'name' => '', 'error' => 'Serviço ERP indisponível para validar a base de dados.'];
    }

    $companyId = trim($companyId);
    $primaryId = $companyId !== '' ? $companyId : '384';
    $primaryLookup = fetchUploadCompanyNameByConfigEmpresaId($database, $primaryId, $baseUrl, $token);
    if (!empty($primaryLookup['ok']) && trim((string) ($primaryLookup['name'] ?? '')) !== '') {
        return ['ok' => true, 'name' => trim((string) $primaryLookup['name']), 'error' => ''];
    }
    if (!empty($primaryLookup['error'])) {
        return ['ok' => false, 'name' => '', 'error' => trim((string) $primaryLookup['error'])];
    }

    $candidateName = '';
    if ($companyId === '') {
        $companyId = fetchUploadDatabaseCompanyId($database);
        if ($companyId === '') {
            $fallback = fetchUploadCompanyConfigCandidate($database);
            $companyId = trim((string) ($fallback['id'] ?? ''));
            $candidateName = trim((string) ($fallback['name'] ?? ''));
        }
    }
    if ($companyId === '') {
        if ($candidateName !== '') {
            return ['ok' => true, 'name' => $candidateName, 'error' => ''];
        }
        return ['ok' => false, 'name' => '', 'error' => 'Não foi possível obter o nome da empresa para a base selecionada.'];
    }
    $fallbackLookup = fetchUploadCompanyNameByConfigEmpresaId($database, $companyId, $baseUrl, $token);
    if (!empty($fallbackLookup['ok']) && trim((string) ($fallbackLookup['name'] ?? '')) !== '') {
        return ['ok' => true, 'name' => trim((string) $fallbackLookup['name']), 'error' => ''];
    }
    if ($candidateName !== '') {
        return ['ok' => true, 'name' => $candidateName, 'error' => ''];
    }
    if (!empty($fallbackLookup['error'])) {
        return ['ok' => false, 'name' => '', 'error' => trim((string) $fallbackLookup['error'])];
    }

    return ['ok' => false, 'name' => '', 'error' => 'Não foi possível obter o nome da empresa para a base selecionada.'];
}

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
        $isDebugMode = getSetting('debug_mode', '0') === '1';
        if ($isDebugMode && $nif !== '' && $database !== '') {
            $debugRemote = fetchAccountingEntityFromErp($nif, 'emitter', true, $database);
        }

        $entity = null;
        if ($database !== '') {
            $entity = ensureAccountingEntity($pdo, (string) $value, ['entity_type' => $entityType, 'erp_database' => $database]);
        } elseif ($nif !== '') {
            $entity = findAccountingEntity($pdo, $nif);
            if ($entity === null) {
                $fallbackName = deriveEntityNameFromField((string) $value, $nif);
                $fallbackData = [
                    'nif' => $nif,
                    'name' => $fallbackName !== '' ? $fallbackName : ('Cliente ' . $nif),
                    'erp_database' => '',
                    'entity_type' => $entityType,
                ];
                saveAccountingEntity($pdo, $fallbackData);
                $entity = findAccountingEntity($pdo, $nif);
            }
        }

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
        $selectedDatabaseId = trim((string) ($_POST['database_id'] ?? ''));

        if ($acquirerNif === '' || $selectedDatabase === '') {
            http_response_code(400);
            echo json_encode(['error' => 'NIF e base de dados são obrigatórios.', 'csrf_token' => $newToken]);
            exit;
        }

        $pdo = getPDO();
        $companyValidation = fetchUploadAcquirerCompanyName($selectedDatabase, $selectedDatabaseId);
        if (empty($companyValidation['ok'])) {
            http_response_code(400);
            echo json_encode([
                'error' => trim((string) ($companyValidation['error'] ?? 'Não foi possível validar a base de dados ERP selecionada.')),
                'csrf_token' => $newToken
            ]);
            exit;
        }

        $existing = findAccountingEntityByType($pdo, $acquirerNif, 'acquirer');
        $name = trim((string) ($companyValidation['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($existing['name'] ?? ''));
        }
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
