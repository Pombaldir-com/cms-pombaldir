<?php
require_once __DIR__ . '/../functions.php';
// Load Composer's autoloader if available. This prevents fatal errors in
// environments where the dependencies have not been installed yet.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}
require_once __DIR__ . '/functions.php';

startSession();


$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'lines') {
    if (!isLoggedIn() || getCompanySlug() === null) {
        http_response_code(403);
        exit;
    }
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ID inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id, filename, line_items FROM accounting_imports WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (! $row) {
        http_response_code(404);
        exit;
    }
    // If line items already stored, return them without invoking OCR again.
    if (!empty($row['line_items'])) {
        $items = json_decode($row['line_items'], true);
        if (!is_array($items)) {
            $items = [];
        }
        header('Content-Type: application/json');
        echo json_encode($items, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $path = dirname(__DIR__) . '/' . $row['filename'];
    $ocrProvider = getSetting('ocr_provider', 'tesseract');

    if ($ocrProvider !== 'textract') {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'OCR provider inválido']);
        exit;
    }

    try {
        $items = parseInvoiceLineTextract($path);
        // Store OCR result so subsequent requests can reuse it.
        $stmt = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
        $stmt->execute([json_encode($items, JSON_UNESCAPED_UNICODE), $id]);
        header('Content-Type: application/json');
        echo json_encode($items, JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        logOcrMessage('Textract OCR error: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Erro no OCR: ' . $e->getMessage()]);
        exit;
    }
}

header('Content-Type: application/json');

if (!isLoggedIn() || getCompanySlug() === null) {
    http_response_code(403);
    echo json_encode(['error' => 'Sessão inválida']);
    exit;
}

if ($action === 'get') {
    $token = $_GET['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $a = $_GET['A'] ?? '';
    $b = $_GET['B'] ?? '';
    $d = $_GET['D'] ?? '';
    $id = $_GET['id'] ?? '';
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    ensureAccountingEntity($pdo, (string) $a);
    $stmt = $pdo->prepare(
        'SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
    );
    $stmt->execute([$a, $b, $d]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $classificationAccounts = normalizeAccountingAccounts($row['account'] ?? '');

    $rowAccounts = normalizeAccountingAccounts(null);
    $rowCostCenters = buildEmptyCostCenterMap();
    if ($id !== '') {
        $stmtRow = $pdo->prepare('SELECT account, cost_center FROM accounting_imports WHERE id = ? LIMIT 1');
        $stmtRow->execute([$id]);
        $importRow = $stmtRow->fetch(PDO::FETCH_ASSOC) ?: [];
        $rowAccounts = normalizeAccountingAccounts($importRow['account'] ?? '');
        $rowCostCenters = normalizeCostCenters($importRow['cost_center'] ?? '');
    }

    echo json_encode([
        'rates' => $classificationAccounts,
        'row_rates' => $rowAccounts,
        'cost_center' => serializeCostCenters($rowCostCenters),
        'cost_centers' => $rowCostCenters,
        'csrf_token' => generateCsrfToken()
    ]);
    exit;
} elseif ($action === 'save') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $id = $_POST['id'] ?? '';
    $a = $_POST['A'] ?? '';
    $b = $_POST['B'] ?? '';
    $d = $_POST['D'] ?? '';
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    ensureAccountingEntity($pdo, (string) $a);
    try {
        $pdo->beginTransaction();

        $ratesJson = $_POST['rates'] ?? '[]';
        $ratesData = json_decode($ratesJson, true);
        if (!is_array($ratesData)) {
            $ratesData = [];
        }
        $submittedRates = sanitizeAccountInput($ratesData);

        $costCentersJson = $_POST['cost_centers'] ?? '';
        $costCentersData = [];
        if ($costCentersJson !== '') {
            $decodedCostCenters = json_decode($costCentersJson, true);
            if (!is_array($decodedCostCenters)) {
                $decodedCostCenters = [];
            }
            $costCentersData = sanitizeCostCenterValues($decodedCostCenters);
        } else {
            $legacyCostCenter = isset($_POST['cost_center']) ? trim((string) $_POST['cost_center']) : '';
            if ($legacyCostCenter === '') {
                $costCentersData = sanitizeCostCenterValues([]);
            } else {
                $costCentersData = sanitizeCostCenterValues($legacyCostCenter);
            }
        }

        $stmtExisting = $pdo->prepare(
            'SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1'
        );
        $stmtExisting->execute([$a, $b, $d]);
        $existingClass = normalizeAccountingAccounts($stmtExisting->fetchColumn() ?: '');

        $stmtRow = $pdo->prepare('SELECT account FROM accounting_imports WHERE id = ?');
        $stmtRow->execute([$id]);
        $existingRow = normalizeAccountingAccounts($stmtRow->fetchColumn() ?: '');

        $rowAccounts = mergeAccountingAccounts($existingRow, $submittedRates);
        $classAccounts = mergeAccountingAccounts($existingClass, $submittedRates);

        $serializedRow = serializeAccountingAccounts($rowAccounts);
        $serializedClass = serializeAccountingAccounts($classAccounts);
        $serializedCostCenters = serializeCostCenters($costCentersData);

        $stmt = $pdo->prepare('UPDATE accounting_imports SET account = ?, cost_center = ? WHERE id = ?');
        $stmt->execute([$serializedRow, $serializedCostCenters, $id]);

        $stmt2 = $pdo->prepare(
            'INSERT INTO accounting_classifications (emitter, acquirer, doc_type, account) '
            . 'VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE account = VALUES(account)'
        );
        $stmt2->execute([$a, $b, $d, $serializedClass]);
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'csrf_token' => generateCsrfToken(),
            'row_rates' => $rowAccounts,
            'cost_center' => $serializedCostCenters,
            'cost_centers' => $costCentersData
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        // Log the underlying exception for debugging and expose the message in
        // the JSON response so the caller can act accordingly.
        error_log('save-analysis error: ' . $e->getMessage());
        echo json_encode([
            'error' => 'Erro ao guardar: ' . $e->getMessage(),
            'csrf_token' => generateCsrfToken()
        ]);
    }
    exit;
} elseif ($action === 'save_lines') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $id = $_POST['id'] ?? '';
    $linesJson = $_POST['lines'] ?? '[]';
    $lines = json_decode($linesJson, true);
    if (!is_array($lines)) {
        $lines = [];
    }
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE accounting_imports SET line_items = ? WHERE id = ?');
    $stmt->execute([json_encode($lines, JSON_UNESCAPED_UNICODE), $id]);
    echo json_encode(['success' => true, 'csrf_token' => generateCsrfToken()]);
    exit;
} elseif ($action === 'remove') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        echo json_encode(['error' => 'Token CSRF inválido', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $id = $_POST['id'] ?? '';
    try {
        $pdo = getPDO();
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Empresa não selecionada']);
        exit;
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM accounting_imports WHERE id = ?');
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'csrf_token' => generateCsrfToken()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Erro ao remover', 'csrf_token' => generateCsrfToken()]);
    }
    exit;
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Ação inválida', 'csrf_token' => generateCsrfToken()]);
    exit;
}
