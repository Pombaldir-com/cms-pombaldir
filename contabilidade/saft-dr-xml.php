<?php
// Gera/envia por email o XML da Declaracao Recapitulativa de IVA (schema
// DRIVAWeb da AT), a partir das vendas intracomunitarias/pais terceiro
// detetadas no popup de envio de SAF-T (contabilidade/tarefas-envio-saft.php).
// Ver saftBuildDeclaracaoRecapitulativaXml() em saft-envio-functions.php.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/saft-envio-functions.php';

startSession();
requireLogin();

$action = (string) ($_POST['action'] ?? 'download');
$isSendAction = $action === 'send';

// Para o pedido AJAX ("Enviar Ficheiro") a resposta tem de ser sempre JSON,
// mesmo nos erros de validacao — caso contrario o fetch() do modal falha o
// parse e mostra sempre a mensagem generica "Falha ao enviar o ficheiro.",
// escondendo a causa real. O download continua a sair como texto simples.
$fail = function (int $httpCode, string $message) use ($isSendAction): void {
    http_response_code($httpCode);
    if ($isSendAction) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
    } else {
        echo $message;
    }
    exit;
};

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_envio_saft')) {
    $fail(403, 'Acesso negado.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $fail(400, 'Token CSRF inválido.');
}

$pdo = getPDO();

$entityId = (int) ($_POST['entity_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, nif, name FROM accounting_entities WHERE id = ? AND entity_type = 'acquirer' LIMIT 1");
$stmt->execute([$entityId]);
$entity = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$entity) {
    $fail(404, 'Empresa não encontrada.');
}

if (!$isAdmin) {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM accounting_entity_admin_task_permissions
         WHERE accounting_entity_id = ? AND permission_key = 'ctb_envio_saft' AND user_id = ? LIMIT 1"
    );
    $stmt->execute([$entityId, $userId]);
    if (!$stmt->fetchColumn()) {
        $fail(403, 'Sem permissão para esta empresa.');
    }
}

$year = (int) ($_POST['year'] ?? 0);
$month = (int) ($_POST['month'] ?? 0);
if ($year <= 0 || $month <= 0 || $month > 12) {
    $fail(400, 'Período inválido.');
}

$rowsJson = (string) ($_POST['rows'] ?? '[]');
$rawRows = json_decode($rowsJson, true);
if (!is_array($rawRows)) {
    $fail(400, 'Linhas inválidas.');
}

$rows = [];
$rowErrors = [];
foreach ($rawRows as $row) {
    $country = strtoupper(trim((string) ($row['country'] ?? '')));
    $nif = trim((string) ($row['nif'] ?? ''));
    $type = trim((string) ($row['type'] ?? ''));
    $value = (float) ($row['value'] ?? 0);
    if ($country === '' || $nif === '' || !in_array($type, ['1', '4', '5'], true) || $value <= 0) {
        $rowErrors[] = ($country !== '' ? $country : '?') . '/' . ($nif !== '' ? $nif : 'NIF em falta');
        continue;
    }
    $rows[] = ['country' => $country, 'nif' => $nif, 'value' => $value, 'type' => $type];
}
if (!$rows) {
    $message = 'Nenhuma linha válida para gerar a declaração (preencha o país, o NIF do adquirente e um valor superior a 0 em cada linha).';
    if ($rowErrors) {
        $message .= ' Linhas rejeitadas: ' . implode(', ', $rowErrors) . '.';
    }
    $fail(400, $message);
}

$accountantNif = trim((string) ($_POST['accountant_nif'] ?? ''));

$xml = saftBuildDeclaracaoRecapitulativaXml([
    'nif' => (string) $entity['nif'],
    'year' => $year,
    'month' => $month,
    'accountant_nif' => $accountantNif,
    'rows' => $rows,
]);

$filename = 'DR-' . preg_replace('/[^A-Za-z0-9]+/', '', (string) $entity['nif']) . '-' . $year . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '.xml';

if ($isSendAction) {
    $destEmail = trim((string) ($_POST['dest_email'] ?? ''));
    if ($destEmail === '' || !filter_var($destEmail, FILTER_VALIDATE_EMAIL)) {
        $fail(400, 'Indique um email de destino válido.');
    }
    try {
        sendSystemEmailWithAttachment(
            $destEmail,
            'Declaração Recapitulativa ' . $entity['name'] . ' — ' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '/' . $year,
            'Segue em anexo o ficheiro XML da Declaração Recapitulativa de IVA de ' . $entity['name']
                . ' (NIF ' . $entity['nif'] . '), referente ao período ' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '/' . $year . '.',
            $xml,
            $filename,
            'text/xml'
        );
        logAuditAction('send_email', 'accounting_entity', $entityId, [
            'context' => 'declaracao_recapitulativa',
            'period' => $year . '-' . $month,
            'dest_email' => $destEmail,
        ]);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

header('Content-Type: text/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($xml));
echo $xml;
