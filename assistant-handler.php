<?php
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

header('Content-Type: application/json; charset=UTF-8');

$aiEnabled = getSetting('ai_enabled', '0') === '1';
if (!$aiEnabled || !userHasDepartmentPermission('ai_assistant')) {
    http_response_code(403);
    echo json_encode(['message' => 'Acesso negado.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['message' => 'Pedido invalido.']);
    exit;
}

$csrfToken = trim((string) ($payload['csrf_token'] ?? ''));
if ($csrfToken === '' || !validateCsrfToken($csrfToken)) {
    http_response_code(400);
    echo json_encode(['message' => 'Token invalido. Recarregue a pagina.']);
    exit;
}

$action = (string) ($payload['action'] ?? '');
$message = trim((string) ($payload['message'] ?? ''));
if ($message === '' && $action === '') {
    http_response_code(400);
    echo json_encode(['message' => 'Mensagem vazia.']);
    exit;
}

$sessionId = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($payload['session_id'] ?? ''));
if ($sessionId === '') {
    $sessionId = bin2hex(random_bytes(8));
}

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);
$userRole = (int) ($user['role'] ?? 3);
$readOnly = (int) ($user['ai_read_only'] ?? (int) getSetting('ai_default_read_only', '1')) === 1;

$canCreateTasks = userHasDepartmentPermission('ai_create_tasks');
$canOpenLancamentos = userHasDepartmentPermission('ai_open_lancamentos');
$canApproveDocs = userHasDepartmentPermission('ai_approve_docs');
$canSuggestVat = userHasDepartmentPermission('ai_suggest_vat');

$openAiKey = trim((string) getSetting('openai_api_key', ''));
$openAiModel = trim((string) getSetting('openai_model', 'gpt-4.1-mini'));
$erpBaseUrl = trim((string) getSetting('erp_webservice_url', ''));
$erpToken = trim((string) getSetting('erp_token', ''));

if ($openAiKey === '') {
    http_response_code(400);
    echo json_encode(['message' => 'A chave da OpenAI nao esta configurada.']);
    exit;
}

if (!isset($_SESSION['ai_sessions'])) {
    $_SESSION['ai_sessions'] = [];
}
if (!isset($_SESSION['ai_sessions'][$sessionId])) {
    $_SESSION['ai_sessions'][$sessionId] = [];
}

$messages = $_SESSION['ai_sessions'][$sessionId];
$messages[] = [
    'role' => 'user',
    'content' => $message,
];

$basePrompt = '';
$basePromptPath = __DIR__ . '/AI_ASSISTANT.md';
if (is_file($basePromptPath)) {
    $basePrompt = trim((string) file_get_contents($basePromptPath));
}
$extraPrompt = trim((string) getSetting('ai_prompt_extra', ''));

$systemPrompt = "E um assistente de AI para um escritorio de contabilidade. Responde sempre em PT-PT.\n"
    . "Respeita as permissoes do utilizador e o modo seguro.\n"
    . "Se o modo seguro estiver ativo, nao executes tarefas que alterem dados.\n"
    . "Pede os dados em falta antes de executar acoes.\n"
    . "Resumo interno: fornece respostas curtas e claras.";

if ($basePrompt !== '') {
    $systemPrompt .= "\n\n" . $basePrompt;
}
if ($extraPrompt !== '') {
    $systemPrompt .= "\n\n" . $extraPrompt;
}

$tools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'create_task',
            'description' => 'Criar uma tarefa interna no sistema.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'assigned_to' => ['type' => 'integer'],
                    'due_date' => ['type' => 'string', 'description' => 'Formato YYYY-MM-DD'],
                ],
                'required' => ['title'],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'suggest_accounts',
            'description' => 'Sugerir contas IVA e gerais com base em historico e ERP.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'acquirer_nif' => ['type' => 'string'],
                    'doc_type' => ['type' => 'string'],
                    'rates' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'key' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'base' => ['type' => 'string'],
                                'iva' => ['type' => 'string'],
                            ],
                            'required' => ['key'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['acquirer_nif', 'doc_type', 'rates'],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'get_accounting_examples',
            'description' => 'Obter exemplos de contas anteriores (MySQL) para sugerir contas IVA e gerais.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'acquirer_nif' => ['type' => 'string'],
                    'doc_type' => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'erp_movimentos_search',
            'description' => 'Pesquisar movimentos no ERP-SINC (webservice) para obter contas usadas.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'db' => ['type' => 'string'],
                    'strCodExercicio' => ['type' => 'string'],
                    'intCodDiario' => ['type' => 'string'],
                    'intMes' => ['type' => 'string'],
                    'strAbrevTpDoc' => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                ],
                'required' => ['db'],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'erp_planocontas_search',
            'description' => 'Pesquisar plano de contas no ERP-SINC.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'db' => ['type' => 'string'],
                    'strCodExercicio' => ['type' => 'string'],
                    'strNumContrib' => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                ],
                'required' => ['db'],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'erp_taxonomias_search',
            'description' => 'Obter taxonomias do ERP-SINC para apoiar classificacao.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'db' => ['type' => 'string'],
                ],
                'required' => ['db'],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'set_document_approval',
            'description' => 'Aprovar ou rejeitar documentos de contabilidade.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'document_id' => ['type' => 'integer'],
                    'status' => ['type' => 'string', 'enum' => ['approved', 'rejected']],
                    'note' => ['type' => 'string'],
                ],
                'required' => ['document_id', 'status'],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'open_lancamentos',
            'description' => 'Abrir a pagina de lancamentos.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'empresa' => ['type' => 'string'],
                    'exercicio' => ['type' => 'string'],
                    'diario' => ['type' => 'string'],
                    'mes' => ['type' => 'string'],
                    'tipo_doc' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'read_sql',
            'description' => 'Executar uma query SELECT de leitura (apenas admins).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        ],
    ],
];

$messagesForModel = array_merge(
    [
        [
            'role' => 'system',
            'content' => $systemPrompt
                . "\nUtilizador: " . ($user['username'] ?? 'desconhecido')
                . "\nRole: " . $userRole
                . "\nModo seguro: " . ($readOnly ? 'sim' : 'nao')
                . "\nPermissoes: criar_tarefas=" . ($canCreateTasks ? 'sim' : 'nao')
                . ", abrir_lancamentos=" . ($canOpenLancamentos ? 'sim' : 'nao')
                . ", aprovar_docs=" . ($canApproveDocs ? 'sim' : 'nao')
                . ", sugerir_contas=" . ($canSuggestVat ? 'sim' : 'nao'),
        ],
    ],
    $messages
);

function callOpenAi(array $payload, string $apiKey): array {
    $handle = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($response === false || $status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'error' => $error ?: 'Erro na chamada OpenAI.',
            'status' => $status,
            'body' => $response,
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'error' => 'Resposta invalida da OpenAI.',
            'status' => $status,
        ];
    }

    return ['ok' => true, 'data' => $decoded, 'raw' => $response];
}

function logAiDebug(array $details): void {
    $debugEnabled = (int) getSetting('debug_mode', '0') === 1;
    if (!$debugEnabled) {
        return;
    }
    $payload = '[' . date('Y-m-d H:i:s') . '] ' . json_encode($details, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    $path = __DIR__ . '/contabilidade/debug_ai.txt';
    file_put_contents($path, $payload, FILE_APPEND);
}

function safeToolResponse(array $payload): string {
    return json_encode($payload, JSON_UNESCAPED_UNICODE);
}

function logAiInteraction(int $userId, string $sessionId, string $summary, array $actions): void {
    if (!hasTable('ai_assistant_logs')) {
        return;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO ai_assistant_logs (user_id, session_id, summary, actions) VALUES (?, ?, ?, ?)');
    $actionsJson = $actions ? json_encode($actions, JSON_UNESCAPED_UNICODE) : null;
    $stmt->execute([$userId, $sessionId, $summary, $actionsJson]);
}

function extractAccountsFromPayload(string $json): array {
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $rates = [];
    $source = $decoded;
    if (isset($decoded['rates']) && is_array($decoded['rates'])) {
        $source = $decoded['rates'];
    }
    foreach ($source as $key => $value) {
        $rateKey = (string) $key;
        if (in_array(strtolower($rateKey), ['rates', 'version', 'meta', 'label', 'labels', 'title', 'total_account'], true)) {
            continue;
        }
        if (!is_array($value)) {
            continue;
        }
        $iva = trim((string) ($value['iva_account'] ?? $value['iva'] ?? ''));
        $general = trim((string) ($value['general_account'] ?? $value['general'] ?? ''));
        if ($iva === '' && $general === '') {
            continue;
        }
        $rates[$rateKey] = [
            'iva_account' => $iva,
            'general_account' => $general,
        ];
    }
    return $rates;
}

function buildSuggestedAccounts(array $examples): array {
    $tally = [];
    foreach ($examples as $example) {
        $rates = $example['rates'] ?? [];
        foreach ($rates as $rateKey => $accounts) {
            if (!isset($tally[$rateKey])) {
                $tally[$rateKey] = [
                    'iva' => [],
                    'general' => [],
                ];
            }
            $iva = trim((string) ($accounts['iva_account'] ?? ''));
            $general = trim((string) ($accounts['general_account'] ?? ''));
            if ($iva !== '') {
                $tally[$rateKey]['iva'][$iva] = ($tally[$rateKey]['iva'][$iva] ?? 0) + 1;
            }
            if ($general !== '') {
                $tally[$rateKey]['general'][$general] = ($tally[$rateKey]['general'][$general] ?? 0) + 1;
            }
        }
    }
    $suggested = [];
    foreach ($tally as $rateKey => $counts) {
        $iva = '';
        $general = '';
        if (!empty($counts['iva'])) {
            arsort($counts['iva']);
            $iva = (string) array_key_first($counts['iva']);
        }
        if (!empty($counts['general'])) {
            arsort($counts['general']);
            $general = (string) array_key_first($counts['general']);
        }
        if ($iva !== '' || $general !== '') {
            $suggested[$rateKey] = [
                'iva_account' => $iva,
                'general_account' => $general,
            ];
        }
    }
    return $suggested;
}

function resolveAccountByRateKey(array $suggested, string $rateKey): array {
    if (isset($suggested[$rateKey])) {
        return $suggested[$rateKey];
    }
    $normalized = trim(str_replace('%', '', $rateKey));
    if ($normalized !== '' && isset($suggested[$normalized])) {
        return $suggested[$normalized];
    }
    return ['iva_account' => '', 'general_account' => ''];
}

function mergeSuggestedAccounts(array $primary, array $fallback): array {
    $result = $primary;
    foreach ($fallback as $rate => $accounts) {
        if (!isset($result[$rate])) {
            $result[$rate] = $accounts;
            continue;
        }
        if (($result[$rate]['iva_account'] ?? '') === '' && ($accounts['iva_account'] ?? '') !== '') {
            $result[$rate]['iva_account'] = $accounts['iva_account'];
        }
        if (($result[$rate]['general_account'] ?? '') === '' && ($accounts['general_account'] ?? '') !== '') {
            $result[$rate]['general_account'] = $accounts['general_account'];
        }
    }
    return $result;
}

function getErpDatabaseForNif(string $nif): ?string {
    if ($nif === '') {
        return null;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT erp_database FROM accounting_entities WHERE nif = ? LIMIT 1');
    $stmt->execute([$nif]);
    $db = $stmt->fetchColumn();
    if (is_string($db) && trim($db) !== '') {
        return trim($db);
    }
    return null;
}

function fetchPlanAccounts(string $baseUrl, string $token, string $db, string $year, string $nif): array {
    $query = [
        'db' => $db,
        'strCodExercicio' => $year,
        'strNumContrib' => $nif,
        'limit' => 50,
        'offset' => 0,
    ];
    $endpoint = rtrim($baseUrl, '/') . '/contabilidade/planocontas?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $response = callErpWebservice($endpoint, $token);
    if (!$response['ok']) {
        return [];
    }
    $data = $response['data']['aaData'] ?? [];
    if (!is_array($data)) {
        return [];
    }
    $results = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $account = trim((string) ($row['strConta'] ?? ''));
        if ($account === '') {
            continue;
        }
        $movimenta = trim((string) ($row['bitMovimenta'] ?? ''));
        if ($movimenta !== '' && $movimenta !== '1' && $movimenta !== 1) {
            continue;
        }
        $results[] = [
            'account' => $account,
            'description' => (string) ($row['strDescricao'] ?? ''),
            'iva_account' => (string) ($row['strConta_Iva'] ?? ''),
            'tax_rate' => (string) ($row['fltVatRate'] ?? ''),
        ];
    }
    return $results;
}

function findIvaAccountForGeneral(array $planAccounts, string $generalAccount): string {
    if ($generalAccount === '') {
        return '';
    }
    foreach ($planAccounts as $row) {
        $account = trim((string) ($row['account'] ?? ''));
        if ($account !== '' && $account === $generalAccount) {
            $iva = trim((string) ($row['iva_account'] ?? ''));
            if ($iva !== '') {
                return $iva;
            }
        }
    }
    return '';
}

function findIvaAccountForRate(array $planAccounts, array $rateInfo): string {
    if (!$planAccounts) {
        return '';
    }
    $label = $rateInfo['label'] ?? '';
    $rateKey = $rateInfo['key'] ?? '';
    $normalized = trim(str_replace('%', '', $label ?: $rateKey));
    if ($normalized === '') {
        $normalized = trim(str_replace('%', '', $rateKey));
    }
    $target = $normalized !== '' ? (float) str_replace(',', '.', $normalized) : null;
    if ($target !== null && $target > 0 && $target < 1) {
        $target = $target * 100;
    }
    foreach ($planAccounts as $row) {
        $vatRate = trim((string) ($row['tax_rate'] ?? ''));
        if ($vatRate === '') {
            continue;
        }
        $vatRate = str_replace(',', '.', $vatRate);
        $value = (float) $vatRate;
        if ($target !== null && abs($value - $target) < 0.001) {
            $iva = trim((string) ($row['iva_account'] ?? ''));
            if ($iva !== '') {
                return $iva;
            }
        }
    }
    return '';
}

function pickGeneralAccountFromPlan(array $planAccounts, array $rateInfo): string {
    if (!$planAccounts) {
        return '';
    }
    $label = $rateInfo['label'] ?? '';
    $rateKey = $rateInfo['key'] ?? '';
    $normalized = trim(str_replace('%', '', $label ?: $rateKey));
    if ($normalized === '') {
        $normalized = trim(str_replace('%', '', $rateKey));
    }
    $target = $normalized !== '' ? (float) str_replace(',', '.', $normalized) : null;
    if ($target !== null && $target > 0 && $target < 1) {
        $target = $target * 100;
    }
    $best = '';
    foreach ($planAccounts as $row) {
        $vatRate = trim((string) ($row['tax_rate'] ?? ''));
        if ($vatRate === '') {
            continue;
        }
        $vatRate = str_replace(',', '.', $vatRate);
        $value = (float) $vatRate;
        if ($target !== null && abs($value - $target) < 0.001) {
            $best = (string) ($row['account'] ?? '');
            if ($best !== '') {
                break;
            }
        }
    }
    if ($best !== '') {
        return $best;
    }

    // Fallback: prefer expense accounts (62/63/6) over liabilities (21).
    $preferredPrefixes = ['62', '63', '6'];
    foreach ($preferredPrefixes as $prefix) {
        foreach ($planAccounts as $row) {
            $account = trim((string) ($row['account'] ?? ''));
            if ($account === '') {
                continue;
            }
            if (strpos($account, $prefix) === 0) {
                return $account;
            }
        }
    }

    foreach ($planAccounts as $row) {
        $account = trim((string) ($row['account'] ?? ''));
        if ($account === '') {
            continue;
        }
        return $account;
    }
    return '';
}

function buildSuggestionsFromExamples(array $examples, array $rates): array {
    $suggested = buildSuggestedAccounts($examples);
    $result = [];
    foreach ($rates as $rateInfo) {
        $rateKey = (string) ($rateInfo['key'] ?? '');
        if ($rateKey === '') {
            continue;
        }
        $accounts = resolveAccountByRateKey($suggested, $rateKey);
        if (!empty($accounts['iva_account']) || !empty($accounts['general_account'])) {
            $result[$rateKey] = $accounts;
        }
    }
    return $result;
}

function runSuggestAccounts(array $args, bool $canSuggestVat, string $erpBaseUrl, string $erpToken): array {
    if (!$canSuggestVat) {
        return ['ok' => false, 'error' => 'Sem permissao para sugerir contas.'];
    }
    $acquirerNif = trim((string) ($args['acquirer_nif'] ?? ''));
    $docType = trim((string) ($args['doc_type'] ?? ''));
    $rateItems = $args['rates'] ?? [];
    if ($acquirerNif === '' || !is_array($rateItems)) {
        return ['ok' => false, 'error' => 'Parametros invalidos.'];
    }
    $limit = 15;
    $pdo = getPDO();
    $sql = 'SELECT id, field_B, field_D, account FROM accounting_imports WHERE account <> \'\'';
    $params = [];
    if ($acquirerNif !== '') {
        $sql .= ' AND field_B = ?';
        $params[] = $acquirerNif;
    }
    if ($docType !== '') {
        $sql .= ' AND field_D = ?';
        $params[] = $docType;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $examples = [];
    foreach ($rows as $row) {
        $accountJson = (string) ($row['account'] ?? '');
        if ($accountJson === '') {
            continue;
        }
        $rates = extractAccountsFromPayload($accountJson);
        if (!$rates) {
            continue;
        }
        $examples[] = [
            'id' => (int) ($row['id'] ?? 0),
            'acquirer_nif' => (string) ($row['field_B'] ?? ''),
            'doc_type' => (string) ($row['field_D'] ?? ''),
            'rates' => $rates,
        ];
    }
    $suggestedFromHistory = buildSuggestionsFromExamples($examples, $rateItems);

    $finalSuggested = $suggestedFromHistory;
    $planAccounts = [];
    $planDb = '';
    if ($erpBaseUrl !== '' && $erpToken !== '') {
        $planDb = getErpDatabaseForNif($acquirerNif) ?? '';
        if ($planDb !== '') {
            $year = date('Y');
            $planAccounts = fetchPlanAccounts($erpBaseUrl, $erpToken, $planDb, $year, $acquirerNif);
            if ($planAccounts) {
                foreach ($rateItems as $rateInfo) {
                    $rateKey = (string) ($rateInfo['key'] ?? '');
                    if ($rateKey === '') {
                        continue;
                    }
                    if (!isset($finalSuggested[$rateKey])) {
                        $finalSuggested[$rateKey] = ['iva_account' => '', 'general_account' => ''];
                    }
                    if (($finalSuggested[$rateKey]['general_account'] ?? '') === '') {
                        $general = pickGeneralAccountFromPlan($planAccounts, $rateInfo);
                        if ($general !== '') {
                            $finalSuggested[$rateKey]['general_account'] = $general;
                        }
                    }
                    if (($finalSuggested[$rateKey]['iva_account'] ?? '') === '') {
                        $iva = '';
                        $generalSelected = $finalSuggested[$rateKey]['general_account'] ?? '';
                        if ($generalSelected !== '') {
                            $iva = findIvaAccountForGeneral($planAccounts, $generalSelected);
                        }
                        if ($iva === '') {
                            $iva = findIvaAccountForRate($planAccounts, $rateInfo);
                        }
                        if ($iva !== '') {
                            $finalSuggested[$rateKey]['iva_account'] = $iva;
                        }
                    }
                }
            }
        }
    }
    return [
        'ok' => true,
        'suggested' => $finalSuggested,
        'history_count' => count($examples),
        'plan_db' => $planDb,
        'plan_accounts' => count($planAccounts),
    ];
}

if ($action === 'suggest_accounts') {
    $args = is_array($payload['payload'] ?? null) ? $payload['payload'] : [];
    $result = runSuggestAccounts($args, $canSuggestVat, $erpBaseUrl, $erpToken);
    if (!$result['ok']) {
        http_response_code(400);
        echo json_encode(['message' => $result['error'] ?? 'Erro ao sugerir contas.', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $suggested = $result['suggested'] ?? [];
    logAiInteraction($userId, $sessionId, 'Sugestao de contas', [['type' => 'suggest_accounts']]);
    echo json_encode([
        'message' => json_encode(['rates' => $suggested], JSON_UNESCAPED_UNICODE),
        'csrf_token' => generateCsrfToken(true),
        'actions' => [
            ['type' => 'suggest_accounts', 'history' => $result['history_count'] ?? 0, 'plan_db' => $result['plan_db'] ?? ''],
        ],
    ]);
    exit;
}
function callErpWebservice(string $endpoint, string $token): array {
    $handle = curl_init($endpoint);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-KEY: ' . $token,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($response === false || $status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $error ?: 'Erro ao chamar o ERP.',
            'body' => $response,
        ];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'Resposta invalida do ERP.',
            'body' => $response,
        ];
    }
    return ['ok' => true, 'data' => $decoded];
}

$actions = [];
$lastSuggestedAccounts = [];
$finalMessage = '';
$loopCount = 0;
$currentMessages = $messagesForModel;

do {
    $payload = [
        'model' => $openAiModel,
        'messages' => $currentMessages,
        'tools' => $tools,
        'tool_choice' => 'auto',
        'temperature' => 0.2,
    ];

    $response = callOpenAi($payload, $openAiKey);
    if (!$response['ok']) {
        $statusCode = (int) ($response['status'] ?? 0);
        $body = $response['body'] ?? '';
        $errorMessage = 'Nao foi possivel contactar a OpenAI. ';
        if ($statusCode === 401) {
            $errorMessage .= 'A chave pode estar invalida.';
        } elseif ($statusCode === 404) {
            $errorMessage .= 'Modelo nao encontrado.';
        } elseif ($statusCode === 429) {
            $errorMessage .= 'Limite de pedidos atingido.';
        } elseif ($statusCode >= 500 && $statusCode < 600) {
            $errorMessage .= 'Servico indisponivel.';
        } else {
            $errorMessage .= 'Verifique a ligacao e as definicoes.';
        }
        logAiDebug([
            'status' => $statusCode,
            'curl_error' => $response['error'] ?? '',
            'body' => $body,
        ]);
        http_response_code(500);
        echo json_encode(['message' => $errorMessage]);
        exit;
    }

    $data = $response['data'];
    $choice = $data['choices'][0] ?? null;
    $assistantMessage = $choice['message'] ?? [];
    $toolCalls = $assistantMessage['tool_calls'] ?? [];

    if (!$toolCalls) {
        $finalMessage = trim((string) ($assistantMessage['content'] ?? ''));
        break;
    }

    $currentMessages[] = $assistantMessage;

    foreach ($toolCalls as $toolCall) {
        $toolName = $toolCall['function']['name'] ?? '';
        $rawArgs = $toolCall['function']['arguments'] ?? '';
        $toolCallId = $toolCall['id'] ?? '';
        $args = json_decode($rawArgs, true);
        if (!is_array($args)) {
            $toolResult = ['ok' => false, 'error' => 'Argumentos invalidos.'];
            $currentMessages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCallId,
                'name' => $toolName,
                'content' => safeToolResponse($toolResult),
            ];
            continue;
        }

        switch ($toolName) {
            case 'create_task':
                if ($readOnly || !$canCreateTasks) {
                    $toolResult = ['ok' => false, 'error' => 'Sem permissao para criar tarefas.'];
                    break;
                }
                $title = trim((string) ($args['title'] ?? ''));
                $description = trim((string) ($args['description'] ?? ''));
                $assignedTo = isset($args['assigned_to']) ? (int) $args['assigned_to'] : null;
                $dueDate = trim((string) ($args['due_date'] ?? ''));
                if ($title === '') {
                    $toolResult = ['ok' => false, 'error' => 'Titulo obrigatorio.'];
                    break;
                }
                $pdo = getPDO();
                $stmt = $pdo->prepare('INSERT INTO ai_tasks (title, description, assigned_to, created_by, due_date) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$title, $description !== '' ? $description : null, $assignedTo ?: null, $userId ?: null, $dueDate !== '' ? $dueDate : null]);
                $taskId = (int) $pdo->lastInsertId();
                $toolResult = ['ok' => true, 'task_id' => $taskId];
                $actions[] = ['type' => 'create_task', 'task_id' => $taskId, 'title' => $title];
                logAuditAction('ai_create_task', 'ai_tasks', $taskId, ['title' => $title]);
                break;

            case 'suggest_accounts':
                $toolResult = runSuggestAccounts($args, $canSuggestVat, $erpBaseUrl, $erpToken);
                if ($toolResult['ok']) {
                    $actions[] = ['type' => 'suggest_accounts', 'history' => $toolResult['history_count'] ?? 0];
                }
                break;

            case 'set_document_approval':
                if ($readOnly || !$canApproveDocs) {
                    $toolResult = ['ok' => false, 'error' => 'Sem permissao para aprovar/rejeitar documentos.'];
                    break;
                }
                $docId = (int) ($args['document_id'] ?? 0);
                $status = (string) ($args['status'] ?? '');
                $note = trim((string) ($args['note'] ?? ''));
                if ($docId <= 0 || !in_array($status, ['approved', 'rejected'], true)) {
                    $toolResult = ['ok' => false, 'error' => 'Parametros invalidos.'];
                    break;
                }
                $pdo = getPDO();
                $check = $pdo->prepare('SELECT id FROM accounting_imports WHERE id = ? LIMIT 1');
                $check->execute([$docId]);
                if (!$check->fetch()) {
                    $toolResult = ['ok' => false, 'error' => 'Documento nao encontrado.'];
                    break;
                }
                $stmt = $pdo->prepare('UPDATE accounting_imports SET approval_status = ?, approval_note = ?, approved_by = ?, approved_at = NOW() WHERE id = ?');
                $stmt->execute([$status, $note !== '' ? $note : null, $userId ?: null, $docId]);
                $toolResult = ['ok' => true, 'document_id' => $docId, 'status' => $status];
                $actions[] = ['type' => 'document_' . $status, 'document_id' => $docId];
                logAuditAction('ai_doc_' . $status, 'accounting_imports', $docId, ['note' => $note]);
                break;

            case 'open_lancamentos':
                if (!$canOpenLancamentos) {
                    $toolResult = ['ok' => false, 'error' => 'Sem permissao para abrir lancamentos.'];
                    break;
                }
                $url = BASE_URL . 'contabilidade/lancamentos';
                $toolResult = ['ok' => true, 'url' => $url];
                $actions[] = ['type' => 'open_lancamentos', 'url' => $url];
                break;

            case 'read_sql':
                if ($userRole > 2) {
                    $toolResult = ['ok' => false, 'error' => 'Sem permissao para ler a base de dados.'];
                    break;
                }
                $query = trim((string) ($args['query'] ?? ''));
                if ($query === '' || !preg_match('/^\s*select\b/i', $query)) {
                    $toolResult = ['ok' => false, 'error' => 'Apenas queries SELECT sao permitidas.'];
                    break;
                }
                if (preg_match('/\b(insert|update|delete|drop|alter|truncate|create)\b/i', $query)) {
                    $toolResult = ['ok' => false, 'error' => 'Apenas leitura e permitida.'];
                    break;
                }
                if (!preg_match('/\blimit\b/i', $query)) {
                    $query .= ' LIMIT 200';
                }
                $pdo = getPDO();
                $rows = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
                $toolResult = ['ok' => true, 'rows' => $rows];
                $actions[] = ['type' => 'read_sql', 'rows' => count($rows)];
                logAuditAction('ai_read_sql', 'database', null, ['rows' => count($rows)]);
                break;

            case 'get_accounting_examples':
                if (!$canSuggestVat) {
                    $toolResult = ['ok' => false, 'error' => 'Sem permissao para sugerir contas.'];
                    break;
                }
                $acquirerNif = trim((string) ($args['acquirer_nif'] ?? ''));
                $docType = trim((string) ($args['doc_type'] ?? ''));
                $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
                if ($limit <= 0 || $limit > 50) {
                    $limit = 10;
                }
                $pdo = getPDO();
                $sql = 'SELECT id, field_B, field_D, account FROM accounting_imports WHERE account <> \'\'';
                $params = [];
                if ($acquirerNif !== '') {
                    $sql .= ' AND field_B = ?';
                    $params[] = $acquirerNif;
                }
                if ($docType !== '') {
                    $sql .= ' AND field_D = ?';
                    $params[] = $docType;
                }
                $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $examples = [];
                foreach ($rows as $row) {
                    $accountJson = (string) ($row['account'] ?? '');
                    if ($accountJson === '') {
                        continue;
                    }
                    $rates = extractAccountsFromPayload($accountJson);
                    if (!$rates) {
                        continue;
                    }
                    $examples[] = [
                        'id' => (int) ($row['id'] ?? 0),
                        'acquirer_nif' => (string) ($row['field_B'] ?? ''),
                        'doc_type' => (string) ($row['field_D'] ?? ''),
                        'rates' => $rates,
                    ];
                }
                $suggested = buildSuggestedAccounts($examples);
                $toolResult = [
                    'ok' => true,
                    'examples' => $examples,
                    'suggested' => $suggested,
                ];
                if ($suggested) {
                    $lastSuggestedAccounts = $suggested;
                }
                $actions[] = ['type' => 'get_accounting_examples', 'count' => count($examples)];
                break;

            case 'erp_movimentos_search':
                if ($erpBaseUrl === '' || $erpToken === '') {
                    $toolResult = ['ok' => false, 'error' => 'ERP nao configurado.'];
                    break;
                }
                $allowed = ['db', 'strCodExercicio', 'intCodDiario', 'intMes', 'strAbrevTpDoc', 'limit', 'offset'];
                $query = [];
                foreach ($allowed as $key) {
                    if (!isset($args[$key]) || $args[$key] === '') {
                        continue;
                    }
                    $query[$key] = $args[$key];
                }
                if (!isset($query['limit'])) {
                    $query['limit'] = 20;
                }
                if (!isset($query['offset'])) {
                    $query['offset'] = 0;
                }
                $base = rtrim($erpBaseUrl, '/');
                $endpoint = $base . '/contabilidade/movimentos?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                $erpResponse = callErpWebservice($endpoint, $erpToken);
                if (!$erpResponse['ok']) {
                    $toolResult = [
                        'ok' => false,
                        'status' => $erpResponse['status'] ?? 0,
                        'error' => $erpResponse['error'] ?? 'Erro ERP',
                    ];
                    break;
                }
                $toolResult = [
                    'ok' => true,
                    'endpoint' => $endpoint,
                    'data' => $erpResponse['data'],
                ];
                $actions[] = ['type' => 'erp_movimentos_search'];
                break;

            case 'erp_planocontas_search':
                if ($erpBaseUrl === '' || $erpToken === '') {
                    $toolResult = ['ok' => false, 'error' => 'ERP nao configurado.'];
                    break;
                }
                $query = [];
                $planAllowed = ['db', 'strCodExercicio', 'strNumContrib', 'limit', 'offset'];
                foreach ($planAllowed as $key) {
                    if (!isset($args[$key]) || $args[$key] === '') {
                        continue;
                    }
                    $query[$key] = $args[$key];
                }
                if (!isset($query['limit'])) {
                    $query['limit'] = 20;
                }
                if (!isset($query['offset'])) {
                    $query['offset'] = 0;
                }
                $base = rtrim($erpBaseUrl, '/');
                $endpoint = $base . '/contabilidade/planocontas?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
                $erpResponse = callErpWebservice($endpoint, $erpToken);
                if (!$erpResponse['ok']) {
                    $toolResult = [
                        'ok' => false,
                        'status' => $erpResponse['status'] ?? 0,
                        'error' => $erpResponse['error'] ?? 'Erro ERP',
                    ];
                    break;
                }
                $toolResult = [
                    'ok' => true,
                    'endpoint' => $endpoint,
                    'data' => $erpResponse['data'],
                ];
                $actions[] = ['type' => 'erp_planocontas_search'];
                break;

            case 'erp_taxonomias_search':
                if ($erpBaseUrl === '' || $erpToken === '') {
                    $toolResult = ['ok' => false, 'error' => 'ERP nao configurado.'];
                    break;
                }
                $db = trim((string) ($args['db'] ?? ''));
                if ($db === '') {
                    $toolResult = ['ok' => false, 'error' => 'DB obrigatoria.'];
                    break;
                }
                $base = rtrim($erpBaseUrl, '/');
                $endpoint = $base . '/contabilidade/taxonomias?' . http_build_query(['db' => $db], '', '&', PHP_QUERY_RFC3986);
                $erpResponse = callErpWebservice($endpoint, $erpToken);
                if (!$erpResponse['ok']) {
                    $toolResult = [
                        'ok' => false,
                        'status' => $erpResponse['status'] ?? 0,
                        'error' => $erpResponse['error'] ?? 'Erro ERP',
                    ];
                    break;
                }
                $toolResult = [
                    'ok' => true,
                    'endpoint' => $endpoint,
                    'data' => $erpResponse['data'],
                ];
                $actions[] = ['type' => 'erp_taxonomias_search'];
                break;

            default:
                $toolResult = ['ok' => false, 'error' => 'Ferramenta desconhecida.'];
        }

        $currentMessages[] = [
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'name' => $toolName,
            'content' => safeToolResponse($toolResult),
        ];
    }

    $loopCount++;
} while ($loopCount < 3);

if ($finalMessage === '') {
    if (!empty($lastSuggestedAccounts)) {
        $finalMessage = json_encode(['rates' => $lastSuggestedAccounts], JSON_UNESCAPED_UNICODE);
    } else {
        $finalMessage = 'Nao foi possivel obter resposta do assistente.';
    }
}

$messages[] = [
    'role' => 'assistant',
    'content' => $finalMessage,
];
$_SESSION['ai_sessions'][$sessionId] = array_slice($messages, -12);

$summary = 'Pedido: ' . substr($message, 0, 200);
if ($actions) {
    $summary .= ' | Acoes: ' . implode(', ', array_map(function ($action) {
        return $action['type'];
    }, $actions));
}

logAiInteraction($userId, $sessionId, $summary, $actions);
logAuditAction('ai_assistant', 'assistant', null, ['session' => $sessionId]);

$newToken = generateCsrfToken(true);
echo json_encode([
    'message' => $finalMessage,
    'csrf_token' => $newToken,
    'actions' => $actions,
]);
