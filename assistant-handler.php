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

if ($action === '') {
    $memoryCommand = parseAssistantMemoryCommand($message);
    if ($memoryCommand !== null) {
        $commandType = (string) ($memoryCommand['type'] ?? '');
        if ($commandType === 'save') {
            $memoryText = trim((string) ($memoryCommand['text'] ?? ''));
            if ($memoryText === '') {
                echo json_encode(['message' => 'Indique o texto a memorizar.', 'csrf_token' => generateCsrfToken(true)]);
                exit;
            }
            if (!savePersistentAssistantMemory($userId, $sessionId, $memoryText)) {
                echo json_encode(['message' => 'Nao foi possivel guardar a memoria (tabela de logs indisponivel).', 'csrf_token' => generateCsrfToken(true)]);
                exit;
            }
            logAuditAction('ai_memory_save', 'ai_assistant_logs', null, ['session' => $sessionId]);
            echo json_encode(['message' => 'Memorizado. Vou usar esta instrucao nas proximas sessoes.', 'csrf_token' => generateCsrfToken(true), 'actions' => [['type' => 'memory_save']]]);
            exit;
        }
        if ($commandType === 'list') {
            $memories = getPersistentAssistantMemories($userId, 20);
            if (!$memories) {
                echo json_encode(['message' => 'Nao tens memorias guardadas.', 'csrf_token' => generateCsrfToken(true)]);
                exit;
            }
            $lines = [];
            foreach ($memories as $index => $item) {
                $lines[] = ($index + 1) . '. ' . $item;
            }
            echo json_encode(['message' => "Memorias guardadas:\n" . implode("\n", $lines), 'csrf_token' => generateCsrfToken(true), 'actions' => [['type' => 'memory_list', 'count' => count($memories)]]]);
            exit;
        }
        if ($commandType === 'delete') {
            $memoryText = trim((string) ($memoryCommand['text'] ?? ''));
            $deleted = deletePersistentAssistantMemory($userId, $memoryText);
            $reply = $deleted > 0
                ? 'Memoria removida.'
                : 'Nao encontrei essa memoria.';
            logAuditAction('ai_memory_delete', 'ai_assistant_logs', null, ['deleted' => $deleted, 'session' => $sessionId]);
            echo json_encode(['message' => $reply, 'csrf_token' => generateCsrfToken(true), 'actions' => [['type' => 'memory_delete', 'deleted' => $deleted]]]);
            exit;
        }
        if ($commandType === 'clear_all') {
            $deleted = clearPersistentAssistantMemories($userId);
            logAuditAction('ai_memory_clear', 'ai_assistant_logs', null, ['deleted' => $deleted, 'session' => $sessionId]);
            echo json_encode(['message' => 'Memorias removidas: ' . $deleted . '.', 'csrf_token' => generateCsrfToken(true), 'actions' => [['type' => 'memory_clear', 'deleted' => $deleted]]]);
            exit;
        }
    }

    if (shouldAutoMemorizeMessage($message)) {
        savePersistentAssistantMemory($userId, $sessionId, $message);
    } elseif (isForgetOrWrongIntent($message)) {
        deleteLatestPersistentAssistantMemory($userId);
    }
}

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
if (!isset($_SESSION['ai_pending_accounting_import']) || !is_array($_SESSION['ai_pending_accounting_import'])) {
    $_SESSION['ai_pending_accounting_import'] = [];
}

$messages = $_SESSION['ai_sessions'][$sessionId];
$messages[] = [
    'role' => 'user',
    'content' => $message,
];

$requestedAttachmentIds = isset($payload['attachments']) && is_array($payload['attachments']) ? $payload['attachments'] : [];
$resolvedAttachments = resolveAssistantAttachmentsForRequest($sessionId, $requestedAttachmentIds);
$requestContextMessages = [];
if (!empty($resolvedAttachments)) {
    $requestContextMessages[] = [
        'role' => 'user',
        'content' => buildAssistantAttachmentContextMessage($resolvedAttachments),
    ];
}

$pendingImport = getPendingAccountingImportIntent($sessionId);
if ($action === '' && is_array($pendingImport)) {
    if (!empty($pendingImport['awaiting_document_date'])) {
        $parsedDate = parseAssistantDocumentDateInput($message);
        if ($parsedDate === '') {
            $finalMessage = "Para FT/FTR preciso da data do documento antes de importar.\n\n"
                . "Indique a data em formato YYYY-MM-DD (ex.: 2026-03-02) ou DD/MM/YYYY.";

            $messages[] = [
                'role' => 'assistant',
                'content' => $finalMessage,
            ];
            $_SESSION['ai_sessions'][$sessionId] = array_slice($messages, -12);
            echo json_encode([
                'message' => $finalMessage,
                'csrf_token' => generateCsrfToken(true),
                'actions' => [['type' => 'assistant_import_waiting_document_date']],
            ]);
            exit;
        }

        $autoImport = runAssistantAccountingUploadImportFlow(
            $sessionId,
            $pendingImport,
            $resolvedAttachments,
            $erpBaseUrl,
            $erpToken,
            $parsedDate
        );
        $finalMessage = (string) ($autoImport['message'] ?? 'Nao foi possivel concluir a importacao.');
        $actions = isset($autoImport['actions']) && is_array($autoImport['actions']) ? $autoImport['actions'] : [];
        if (!empty($autoImport['clear_pending'])) {
            clearPendingAccountingImportIntent($sessionId);
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $finalMessage,
        ];
        $_SESSION['ai_sessions'][$sessionId] = array_slice($messages, -12);

        $summary = 'Pedido: ' . substr($message, 0, 200);
        if ($actions) {
            $summary .= ' | Acoes: ' . implode(', ', array_map(function ($actionItem) {
                return $actionItem['type'];
            }, $actions));
        }
        $loggedActions = $actions;
        $loggedActions[] = [
            'type' => 'chat_exchange',
            'user_message' => $message,
            'assistant_message' => $finalMessage,
        ];
        logAiInteraction($userId, $sessionId, $summary, $loggedActions, 'chat', $actions ? ['chat'] : []);
        $taskMemorySummary = buildAccountingTaskMemorySummary($message, $actions);
        if ($taskMemorySummary !== '' && !isForgetOrWrongIntent($message)) {
            saveAccountingTaskMemory($userId, $sessionId, $taskMemorySummary);
        }
        logAuditAction('ai_assistant', 'assistant', null, ['session' => $sessionId, 'auto_import' => 1, 'date_from_user' => 1]);

        echo json_encode([
            'message' => $finalMessage,
            'csrf_token' => generateCsrfToken(true),
            'actions' => $actions,
        ]);
        exit;
    }

    if (isAssistantAffirmativeIntent($message)) {
        $autoImport = runAssistantAccountingUploadImportFlow(
            $sessionId,
            $pendingImport,
            $resolvedAttachments,
            $erpBaseUrl,
            $erpToken,
            ''
        );
        $finalMessage = (string) ($autoImport['message'] ?? 'Nao foi possivel concluir a importacao.');
        $actions = isset($autoImport['actions']) && is_array($autoImport['actions']) ? $autoImport['actions'] : [];
        if (!empty($autoImport['clear_pending'])) {
            clearPendingAccountingImportIntent($sessionId);
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => $finalMessage,
        ];
        $_SESSION['ai_sessions'][$sessionId] = array_slice($messages, -12);

        $summary = 'Pedido: ' . substr($message, 0, 200);
        if ($actions) {
            $summary .= ' | Acoes: ' . implode(', ', array_map(function ($actionItem) {
                return $actionItem['type'];
            }, $actions));
        }
        $loggedActions = $actions;
        $loggedActions[] = [
            'type' => 'chat_exchange',
            'user_message' => $message,
            'assistant_message' => $finalMessage,
        ];
        logAiInteraction($userId, $sessionId, $summary, $loggedActions, 'chat', $actions ? ['chat'] : []);
        $taskMemorySummary = buildAccountingTaskMemorySummary($message, $actions);
        if ($taskMemorySummary !== '' && !isForgetOrWrongIntent($message)) {
            saveAccountingTaskMemory($userId, $sessionId, $taskMemorySummary);
        }
        logAuditAction('ai_assistant', 'assistant', null, ['session' => $sessionId, 'auto_import' => 1]);

        echo json_encode([
            'message' => $finalMessage,
            'csrf_token' => generateCsrfToken(true),
            'actions' => $actions,
        ]);
        exit;
    }
    if (isAssistantNegativeIntent($message)) {
        clearPendingAccountingImportIntent($sessionId);
    }
}

function buildMarkdownKnowledgePrompt(string $rootDir, int $maxChars = 180000): string {
    $rootRealPath = realpath($rootDir);
    if ($rootRealPath === false) {
        return '';
    }

    $skipSegments = [
        '/.git/',
        '/node_modules/',
        '/vendor/',
        '/vendors/',
    ];

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootRealPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $extension = strtolower((string) $fileInfo->getExtension());
        if ($extension !== 'md') {
            continue;
        }

        $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
        $relativePath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen($rootRealPath))), '/');
        $normalizedRelativePath = '/' . $relativePath . '/';

        $shouldSkip = false;
        foreach ($skipSegments as $segment) {
            if (strpos($normalizedRelativePath, $segment) !== false) {
                $shouldSkip = true;
                break;
            }
        }
        if ($shouldSkip) {
            continue;
        }

        $files[] = [
            'absolute' => $absolutePath,
            'relative' => $relativePath,
            'priority' => strtolower($relativePath) === 'ai_assistant.md' ? 0 : 1,
        ];
    }

    if (empty($files)) {
        return '';
    }

    usort($files, static function (array $a, array $b): int {
        if ($a['priority'] !== $b['priority']) {
            return $a['priority'] <=> $b['priority'];
        }
        return strcmp($a['relative'], $b['relative']);
    });

    $parts = [];
    $usedChars = 0;
    foreach ($files as $file) {
        $content = @file_get_contents($file['absolute']);
        if (!is_string($content)) {
            continue;
        }

        $content = trim($content);
        if ($content === '') {
            continue;
        }

        $header = "### " . $file['relative'] . "\n";
        $remaining = $maxChars - $usedChars - strlen($header) - 2;
        if ($remaining <= 0) {
            break;
        }

        if (strlen($content) > $remaining) {
            $content = rtrim(substr($content, 0, $remaining)) . "\n[conteudo truncado por limite de contexto]";
        }

        $part = $header . $content;
        $parts[] = $part;
        $usedChars += strlen($part) + 2;

        if ($usedChars >= $maxChars) {
            break;
        }
    }

    if (empty($parts)) {
        return '';
    }

    return "Documentacao Markdown interna do sistema (usar como contexto):\n\n" . implode("\n\n", $parts);
}

$markdownPrompt = buildMarkdownKnowledgePrompt(__DIR__);
$extraPrompt = trim((string) getSetting('ai_prompt_extra', ''));

$systemPrompt = "E um assistente de AI para um escritorio de contabilidade. Responde sempre em PT-PT.\n"
    . "Respeita as permissoes do utilizador e o modo seguro.\n"
    . "Se o modo seguro estiver ativo, nao executes tarefas que alterem dados.\n"
    . "Pede os dados em falta antes de executar acoes.\n"
    . "Quando o utilizador enviar anexos PDF/documentos, usa read_uploaded_document para extrair texto util.\n"
    . "Sempre que houver NIF de emitente/adquirente no documento, identifica e indica tambem o nome usando os dados/ferramentas da app.\n"
    . "Se read_uploaded_document falhar, explica o erro tecnico concreto e apresenta as hints devolvidas pela ferramenta.\n"
    . "Se read_uploaded_document devolver method=qr_only, apresenta de imediato os campos estruturados extraidos do QR (NIFs, tipo, numero, data e totais) antes de sugerir proximos passos.\n"
    . "Quando o tipo documental for FT ou FR (fatura/fatura-recibo), pergunta explicitamente se o utilizador pretende importar para Contabilidade > Classificacao (contabilidade/classificacao-importacao?import_type=1), respeitando o workflow e permissoes atuais.\n"
    . "Neste fluxo FT/FR, nao pedir ID de documento para iniciar; orientar para menu e link de classificacao/importacao existentes.\n"
    . "Resumo interno: fornece respostas curtas e claras.";

if ($markdownPrompt !== '') {
    $systemPrompt .= "\n\n" . $markdownPrompt;
}
if ($extraPrompt !== '') {
    $systemPrompt .= "\n\n" . $extraPrompt;
}

$persistentMemories = getPersistentAssistantMemories($userId, 12);
if ($persistentMemories) {
    $systemPrompt .= "\n\nMemorias persistentes do utilizador (aplica estas instrucoes em todas as respostas):";
    foreach ($persistentMemories as $memory) {
        $systemPrompt .= "\n- " . $memory;
    }
}
$taskMemories = getAccountingTaskMemories($userId, 12);
if ($taskMemories) {
    $systemPrompt .= "\n\nTarefas contabilisticas memorizadas do utilizador (usar nas proximas respostas quando relevante):";
    foreach ($taskMemories as $taskMemory) {
        $systemPrompt .= "\n- " . $taskMemory;
    }
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
                    'acquirer_raw' => ['type' => 'string'],
                    'emitter' => ['type' => 'string'],
                    'emitter_nif' => ['type' => 'string'],
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
            'name' => 'erp_clientes_search',
            'description' => 'Pesquisar clientes no ERP-SINC.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'db' => ['type' => 'string'],
                    'q' => ['type' => 'string'],
                    'searchField' => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'erp_fornecedores_search',
            'description' => 'Pesquisar fornecedores no ERP-SINC.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'db' => ['type' => 'string'],
                    'q' => ['type' => 'string'],
                    'searchField' => ['type' => 'string'],
                    'limit' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                ],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'erp_exercicios_list',
            'description' => 'Listar exercicios no ERP-SINC.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                    'order' => ['type' => 'string'],
                    'dtmInicio' => ['type' => 'string'],
                    'dtmFim' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'erp_empresas_list',
            'description' => 'Listar bases de dados de empresas no ERP-SINC (contabilidade/listDBemp).',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'erp_api_get',
            'description' => 'Consulta GET generica ao ERP-SINC para endpoints suportados (clientes, fornecedores, contabilidade, tabelas).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                    'db' => ['type' => 'string'],
                    'params' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['path'],
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
    [
        'type' => 'function',
        'function' => [
            'name' => 'read_php_function',
            'description' => 'Ler o codigo fonte de uma funcao PHP local para explicar procedimentos tecnicos ou calculos.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'function_name' => ['type' => 'string'],
                    'file_hint' => ['type' => 'string'],
                ],
                'required' => ['function_name'],
                'additionalProperties' => false,
            ],
        ],
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'read_uploaded_document',
            'description' => 'Ler e extrair texto de um anexo carregado no chat (PDF e texto) para analise documental no assistente.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'attachment_id' => ['type' => 'string'],
                    'attachment_name' => ['type' => 'string'],
                    'max_chars' => ['type' => 'integer'],
                ],
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
    count($messages) <= 1 ? getPersistentChatContext($userId, 6) : [],
    $requestContextMessages,
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

function logAiInteraction(int $userId, string $sessionId, string $summary, array $actions, ?string $category = null, array $sources = [], array $suggested = []): int {
    if (!hasTable('ai_assistant_logs')) {
        return 0;
    }
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO ai_assistant_logs (user_id, session_id, summary, actions) VALUES (?, ?, ?, ?)');
    $actionsJson = $actions ? json_encode($actions, JSON_UNESCAPED_UNICODE) : null;
    $stmt->execute([$userId, $sessionId, $summary, $actionsJson]);
    $logId = (int) $pdo->lastInsertId();
    if ($logId > 0 && ( $category !== null || $sources || $suggested )) {
        $update = $pdo->prepare('UPDATE ai_assistant_logs SET category = ?, sources = ?, suggested_accounts = ? WHERE id = ?');
        $sourcesJson = $sources ? json_encode($sources, JSON_UNESCAPED_UNICODE) : null;
        $suggestedJson = $suggested ? json_encode($suggested, JSON_UNESCAPED_UNICODE) : null;
        $update->execute([$category, $sourcesJson, $suggestedJson, $logId]);
    }
    return $logId;
}

function aiLogsHasCategoryColumn(): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }
    $checked = hasTable('ai_assistant_logs') && hasColumn('ai_assistant_logs', 'category');
    return $checked;
}

function parseAssistantMemoryCommand(string $message): ?array {
    $text = trim($message);
    if ($text === '') {
        return null;
    }

    if (preg_match('/^(?:memoriza|guarda|lembra-te)\s*[:\-]\s*(.+)$/iu', $text, $m)) {
        return ['type' => 'save', 'text' => trim((string) $m[1])];
    }
    if (preg_match('/^(?:listar|lista|mostrar)\s+mem[oó]rias?$/iu', $text)) {
        return ['type' => 'list'];
    }
    if (preg_match('/^esquece\s+mem[oó]rias?$/iu', $text)) {
        return ['type' => 'clear_all'];
    }
    if (preg_match('/^esquece\s*[:\-]\s*(.+)$/iu', $text, $m)) {
        return ['type' => 'delete', 'text' => trim((string) $m[1])];
    }

    return null;
}

function getPersistentAssistantMemories(int $userId, int $limit = 12): array {
    if ($userId <= 0 || !hasTable('ai_assistant_logs')) {
        return [];
    }

    $limit = max(1, min($limit, 50));
    $pdo = getPDO();
    $rows = [];

    if (aiLogsHasCategoryColumn()) {
        $stmt = $pdo->prepare(
            'SELECT summary FROM ai_assistant_logs WHERE user_id = ? AND category = ? AND summary IS NOT NULL AND summary <> "" ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId, 'memory_instruction']);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $pdo->prepare(
            'SELECT summary FROM ai_assistant_logs WHERE user_id = ? AND summary LIKE ? ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId, 'MEMORY:%']);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $memories = [];
    foreach ((array) $rows as $row) {
        $summary = trim((string) $row);
        if ($summary === '') {
            continue;
        }
        if (stripos($summary, 'MEMORY:') === 0) {
            $summary = trim(substr($summary, 7));
        }
        if ($summary === '') {
            continue;
        }
        if (!in_array($summary, $memories, true)) {
            $memories[] = $summary;
        }
    }

    return array_reverse($memories);
}

function savePersistentAssistantMemory(int $userId, string $sessionId, string $memoryText): bool {
    if ($userId <= 0 || !hasTable('ai_assistant_logs')) {
        return false;
    }

    $memoryText = trim($memoryText);
    if ($memoryText === '') {
        return false;
    }

    if (function_exists('mb_substr')) {
        $memoryText = mb_substr($memoryText, 0, 500, 'UTF-8');
    } else {
        $memoryText = substr($memoryText, 0, 500);
    }

    $pdo = getPDO();
    if (aiLogsHasCategoryColumn()) {
        $check = $pdo->prepare('SELECT id FROM ai_assistant_logs WHERE user_id = ? AND category = ? AND summary = ? LIMIT 1');
        $check->execute([$userId, 'memory_instruction', $memoryText]);
        if ($check->fetchColumn()) {
            return true;
        }
        $stmt = $pdo->prepare('INSERT INTO ai_assistant_logs (user_id, session_id, summary, actions, category) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $sessionId, $memoryText, json_encode([['type' => 'memory_save']], JSON_UNESCAPED_UNICODE), 'memory_instruction']);
        return true;
    }

    $prefixed = 'MEMORY: ' . $memoryText;
    $check = $pdo->prepare('SELECT id FROM ai_assistant_logs WHERE user_id = ? AND summary = ? LIMIT 1');
    $check->execute([$userId, $prefixed]);
    if ($check->fetchColumn()) {
        return true;
    }
    $stmt = $pdo->prepare('INSERT INTO ai_assistant_logs (user_id, session_id, summary, actions) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $sessionId, $prefixed, json_encode([['type' => 'memory_save']], JSON_UNESCAPED_UNICODE)]);
    return true;
}

function deletePersistentAssistantMemory(int $userId, string $memoryText): int {
    if ($userId <= 0 || !hasTable('ai_assistant_logs')) {
        return 0;
    }

    $memoryText = trim($memoryText);
    if ($memoryText === '') {
        return 0;
    }

    $pdo = getPDO();
    if (aiLogsHasCategoryColumn()) {
        $stmt = $pdo->prepare('DELETE FROM ai_assistant_logs WHERE user_id = ? AND category = ? AND summary = ?');
        $stmt->execute([$userId, 'memory_instruction', $memoryText]);
        return (int) $stmt->rowCount();
    }

    $stmt = $pdo->prepare('DELETE FROM ai_assistant_logs WHERE user_id = ? AND summary = ?');
    $stmt->execute([$userId, 'MEMORY: ' . $memoryText]);
    return (int) $stmt->rowCount();
}

function clearPersistentAssistantMemories(int $userId): int {
    if ($userId <= 0 || !hasTable('ai_assistant_logs')) {
        return 0;
    }

    $pdo = getPDO();
    if (aiLogsHasCategoryColumn()) {
        $stmt = $pdo->prepare('DELETE FROM ai_assistant_logs WHERE user_id = ? AND category = ?');
        $stmt->execute([$userId, 'memory_instruction']);
        return (int) $stmt->rowCount();
    }

    $stmt = $pdo->prepare('DELETE FROM ai_assistant_logs WHERE user_id = ? AND summary LIKE ?');
    $stmt->execute([$userId, 'MEMORY:%']);
    return (int) $stmt->rowCount();
}

function isForgetOrWrongIntent(string $message): bool {
    $text = trim($message);
    if ($text === '') {
        return false;
    }
    return (bool) preg_match('/\b(esquece|ignora|desconsidera|errado|incorreto|nao\s+consid|não\s+consid)\b/iu', $text);
}

function deleteLatestPersistentAssistantMemory(int $userId): int {
    if ($userId <= 0 || !hasTable('ai_assistant_logs')) {
        return 0;
    }

    $pdo = getPDO();
    if (aiLogsHasCategoryColumn()) {
        $stmt = $pdo->prepare(
            'SELECT id FROM ai_assistant_logs WHERE user_id = ? AND category = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, 'memory_instruction']);
    } else {
        $stmt = $pdo->prepare(
            'SELECT id FROM ai_assistant_logs WHERE user_id = ? AND summary LIKE ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$userId, 'MEMORY:%']);
    }
    $id = (int) ($stmt->fetchColumn() ?: 0);
    if ($id <= 0) {
        return 0;
    }
    $delete = $pdo->prepare('DELETE FROM ai_assistant_logs WHERE id = ?');
    $delete->execute([$id]);
    return (int) $delete->rowCount();
}

function shouldAutoMemorizeMessage(string $message): bool {
    $text = trim($message);
    if ($text === '') {
        return false;
    }
    if (isForgetOrWrongIntent($text)) {
        return false;
    }
    if (parseAssistantMemoryCommand($text) !== null) {
        return false;
    }
    return true;
}

function getPersistentChatContext(int $userId, int $limit = 6): array {
    if ($userId <= 0 || !hasTable('ai_assistant_logs')) {
        return [];
    }

    $limit = max(1, min($limit, 20));
    $pdo = getPDO();

    if (aiLogsHasCategoryColumn()) {
        $stmt = $pdo->prepare(
            'SELECT actions FROM ai_assistant_logs WHERE user_id = ? AND category = ? AND actions IS NOT NULL ORDER BY id DESC LIMIT ' . ($limit * 4)
        );
        $stmt->execute([$userId, 'chat']);
    } else {
        $stmt = $pdo->prepare(
            'SELECT actions FROM ai_assistant_logs WHERE user_id = ? AND actions IS NOT NULL ORDER BY id DESC LIMIT ' . ($limit * 4)
        );
        $stmt->execute([$userId]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $pairs = [];

    foreach ((array) $rows as $rawActions) {
        if (!is_string($rawActions) || trim($rawActions) === '') {
            continue;
        }
        $decoded = json_decode($rawActions, true);
        if (!is_array($decoded)) {
            continue;
        }

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['type'] ?? '') !== 'chat_exchange') {
                continue;
            }
            $userText = trim((string) ($entry['user_message'] ?? ''));
            $assistantText = trim((string) ($entry['assistant_message'] ?? ''));
            if ($userText === '' || $assistantText === '') {
                continue;
            }
            $pairs[] = ['user' => $userText, 'assistant' => $assistantText];
            if (count($pairs) >= $limit) {
                break 2;
            }
        }
    }

    $pairs = array_reverse($pairs);
    $messages = [];
    foreach ($pairs as $pair) {
        $messages[] = ['role' => 'user', 'content' => $pair['user']];
        $messages[] = ['role' => 'assistant', 'content' => $pair['assistant']];
    }
    return $messages;
}

function getAssistantUploadDirectory(string $companySlug): string {
    $safeSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $companySlug);
    if ($safeSlug === null || $safeSlug === '') {
        $safeSlug = 'default';
    }
    return __DIR__ . '/uploads/' . $safeSlug . '/assistant/' . date('Y') . '/' . date('m') . '/';
}

function sanitizeAssistantFilename(string $filename): string {
    $filename = trim($filename);
    if ($filename === '') {
        return 'anexo.bin';
    }
    $filename = str_replace(['\\', '/'], '-', $filename);
    $filename = preg_replace('/[^a-zA-Z0-9._\- ]/', '', $filename) ?: 'anexo.bin';
    return trim($filename) !== '' ? trim($filename) : 'anexo.bin';
}

function getAssistantAllowedExtensions(): array {
    return ['pdf', 'txt', 'csv', 'json', 'xml', 'md', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'];
}

function decodeBase64Payload(string $content): string {
    $raw = trim($content);
    if ($raw === '') {
        return '';
    }
    if (strpos($raw, 'base64,') !== false) {
        $parts = explode('base64,', $raw, 2);
        $raw = $parts[1] ?? '';
    }
    $decoded = base64_decode($raw, true);
    return $decoded === false ? '' : $decoded;
}

function normalizeAttachmentExcerptText(string $text, int $maxChars = 1800): string {
    $text = preg_replace('/\s+/u', ' ', trim($text));
    if (!is_string($text) || $text === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $maxChars, 'UTF-8');
    }
    return substr($text, 0, $maxChars);
}

function extractPdfTextFromBinary(string $binaryData, int $maxChars = 1800): string {
    if ($binaryData === '' || !function_exists('shell_exec')) {
        return '';
    }

    $pdftotext = trim((string) @shell_exec('command -v pdftotext 2>/dev/null'));
    if ($pdftotext === '') {
        return '';
    }

    $tmpPdf = tempnam(sys_get_temp_dir(), 'ai_pdf_');
    if (!is_string($tmpPdf) || $tmpPdf === '') {
        return '';
    }
    $tmpTxt = $tmpPdf . '.txt';

    try {
        if (@file_put_contents($tmpPdf, $binaryData) === false) {
            return '';
        }

        $cmd = escapeshellarg($pdftotext)
            . ' -q -enc UTF-8 -nopgbrk '
            . escapeshellarg($tmpPdf) . ' '
            . escapeshellarg($tmpTxt) . ' 2>/dev/null';
        @shell_exec($cmd);

        if (!is_file($tmpTxt)) {
            return '';
        }
        $content = @file_get_contents($tmpTxt);
        if (!is_string($content) || trim($content) === '') {
            return '';
        }
        return normalizeAttachmentExcerptText($content, $maxChars);
    } finally {
        if (is_file($tmpPdf)) {
            @unlink($tmpPdf);
        }
        if (is_file($tmpTxt)) {
            @unlink($tmpTxt);
        }
    }
}

function extractAssistantAttachmentExcerpt(string $mimeType, string $binaryData, int $maxChars = 1800): string {
    $mimeType = strtolower(trim($mimeType));
    $looksLikePdf = $mimeType === 'application/pdf' || strncmp($binaryData, '%PDF-', 5) === 0;
    if ($looksLikePdf) {
        return extractPdfTextFromBinary($binaryData, $maxChars);
    }

    $isText = strpos($mimeType, 'text/') === 0
        || in_array($mimeType, ['application/json', 'application/xml'], true);
    if (!$isText) {
        return '';
    }

    return normalizeAttachmentExcerptText($binaryData, $maxChars);
}

function rememberAssistantAttachment(string $sessionId, array $attachment): void {
    if (!isset($_SESSION['ai_session_attachments']) || !is_array($_SESSION['ai_session_attachments'])) {
        $_SESSION['ai_session_attachments'] = [];
    }
    if (!isset($_SESSION['ai_session_attachments'][$sessionId]) || !is_array($_SESSION['ai_session_attachments'][$sessionId])) {
        $_SESSION['ai_session_attachments'][$sessionId] = [];
    }
    $_SESSION['ai_session_attachments'][$sessionId][] = $attachment;
    $_SESSION['ai_session_attachments'][$sessionId] = array_slice($_SESSION['ai_session_attachments'][$sessionId], -20);

    if (!isset($_SESSION['ai_recent_attachments']) || !is_array($_SESSION['ai_recent_attachments'])) {
        $_SESSION['ai_recent_attachments'] = [];
    }
    $_SESSION['ai_recent_attachments'][] = $attachment;
    $_SESSION['ai_recent_attachments'] = array_slice($_SESSION['ai_recent_attachments'], -80);
}

function resolveAssistantAttachmentsForRequest(string $sessionId, array $requestedIds = []): array {
    $attachments = $_SESSION['ai_session_attachments'][$sessionId] ?? [];
    if (!is_array($attachments) || empty($attachments)) {
        return [];
    }

    if (empty($requestedIds)) {
        return array_slice($attachments, -6);
    }

    $requestedMap = [];
    foreach ($requestedIds as $id) {
        $id = trim((string) $id);
        if ($id !== '') {
            $requestedMap[$id] = true;
        }
    }
    if (!$requestedMap) {
        return array_slice($attachments, -6);
    }

    $result = [];
    foreach ($attachments as $attachment) {
        $id = (string) ($attachment['id'] ?? '');
        if ($id !== '' && isset($requestedMap[$id])) {
            $result[] = $attachment;
        }
    }
    return $result ?: array_slice($attachments, -6);
}

function buildAssistantAttachmentContextMessage(array $attachments): string {
    $lines = ['Contexto de anexos enviados pelo utilizador (usar se relevante):'];
    foreach ($attachments as $attachment) {
        $name = (string) ($attachment['filename'] ?? 'anexo');
        $mime = (string) ($attachment['mime_type'] ?? '');
        $size = (int) ($attachment['size'] ?? 0);
        $path = (string) ($attachment['path'] ?? '');
        $excerpt = trim((string) ($attachment['excerpt'] ?? ''));
        $lines[] = '- Ficheiro: ' . $name . ' | tipo: ' . ($mime !== '' ? $mime : 'desconhecido') . ' | tamanho: ' . $size . ' bytes | caminho: ' . $path;
        if ($excerpt !== '') {
            $lines[] = '  Excerto: ' . $excerpt;
        }
    }
    return implode("\n", $lines);
}

function findAssistantAttachmentById(string $sessionId, string $attachmentId): ?array {
    $attachments = $_SESSION['ai_session_attachments'][$sessionId] ?? [];
    if (!is_array($attachments) || empty($attachments)) {
        return null;
    }
    $attachmentId = trim($attachmentId);
    if ($attachmentId === '') {
        $last = end($attachments);
        return is_array($last) ? $last : null;
    }
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        if ((string) ($attachment['id'] ?? '') === $attachmentId) {
            return $attachment;
        }
    }

    $recentAttachments = $_SESSION['ai_recent_attachments'] ?? [];
    if (is_array($recentAttachments)) {
        foreach ($recentAttachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            if ((string) ($attachment['id'] ?? '') === $attachmentId) {
                return $attachment;
            }
        }
    }

    return null;
}

function findAssistantAttachmentByFilename(string $sessionId, string $filename): ?array {
    $filename = trim($filename);
    if ($filename === '') {
        return null;
    }
    $target = strtolower($filename);

    $sources = [];
    $sessionAttachments = $_SESSION['ai_session_attachments'][$sessionId] ?? [];
    if (is_array($sessionAttachments)) {
        $sources[] = $sessionAttachments;
    }
    $recentAttachments = $_SESSION['ai_recent_attachments'] ?? [];
    if (is_array($recentAttachments)) {
        $sources[] = $recentAttachments;
    }

    foreach ($sources as $attachments) {
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $name = strtolower(trim((string) ($attachment['filename'] ?? '')));
            if ($name !== '' && $name === $target) {
                return $attachment;
            }
        }
    }

    return null;
}

function resolveAssistantAttachmentAbsolutePath(string $relativePath): ?string {
    $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
    if ($relativePath === '') {
        return null;
    }

    $root = realpath(__DIR__);
    if ($root === false) {
        return null;
    }

    $absolute = realpath($root . '/' . $relativePath);
    if ($absolute === false || !is_file($absolute) || !is_readable($absolute)) {
        return null;
    }

    $uploads = realpath($root . '/uploads');
    if ($uploads === false || strpos($absolute, $uploads) !== 0) {
        return null;
    }

    return $absolute;
}

function readAssistantAttachmentWithPython(array $attachment, int $maxChars = 5000): array {
    if (!function_exists('shell_exec')) {
        return ['ok' => false, 'error' => 'shell_exec indisponivel no servidor.'];
    }

    $relativePath = (string) ($attachment['path'] ?? '');
    $absolutePath = resolveAssistantAttachmentAbsolutePath($relativePath);
    if ($absolutePath === null) {
        return ['ok' => false, 'error' => 'Ficheiro de anexo nao encontrado.'];
    }

    $pythonBin = trim((string) @shell_exec('command -v python3 2>/dev/null'));
    if ($pythonBin === '') {
        return ['ok' => false, 'error' => 'python3 nao encontrado no servidor.'];
    }

    $scriptPath = __DIR__ . '/scripts/ai_document_reader.py';
    if (!is_file($scriptPath) || !is_readable($scriptPath)) {
        return ['ok' => false, 'error' => 'Leitor documental Python indisponivel.'];
    }

    if ($maxChars <= 0 || $maxChars > 20000) {
        $maxChars = 5000;
    }

    $mime = trim((string) ($attachment['mime_type'] ?? ''));
    $filename = trim((string) ($attachment['filename'] ?? basename($absolutePath)));

    $cmd = escapeshellarg($pythonBin)
        . ' ' . escapeshellarg($scriptPath)
        . ' --path ' . escapeshellarg($absolutePath)
        . ' --mime ' . escapeshellarg($mime)
        . ' --filename ' . escapeshellarg($filename)
        . ' --max-chars ' . (int) $maxChars
        . ' 2>&1';

    $output = @shell_exec($cmd);
    if (!is_string($output) || trim($output) === '') {
        return ['ok' => false, 'error' => 'Sem resposta do leitor documental Python.'];
    }

    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Resposta invalida do leitor documental Python.', 'raw_output' => trim($output)];
    }

    return $decoded;
}

function buildDocumentReaderHints(array $readerResult): array {
    $hints = [];
    $errors = [];

    $mainError = trim((string) ($readerResult['error'] ?? ''));
    if ($mainError !== '') {
        $errors[] = $mainError;
    }

    $details = $readerResult['details'] ?? [];
    if (is_array($details)) {
        foreach ($details as $detail) {
            $detailText = trim((string) $detail);
            if ($detailText !== '') {
                $errors[] = $detailText;
            }
        }
    }

    $all = strtolower(implode(' | ', $errors));

    if (strpos($all, 'pypdf_unavailable') !== false) {
        $hints[] = 'Dependência Python em falta: pypdf.';
    }
    if (strpos($all, 'pdftotext_unavailable') !== false) {
        $hints[] = 'Utilitário do sistema em falta: pdftotext (poppler).';
    }
    if (strpos($all, 'pdf2image_unavailable') !== false) {
        $hints[] = 'Dependência Python em falta: pdf2image.';
    }
    if (strpos($all, 'tesseract_unavailable') !== false) {
        $hints[] = 'OCR indisponível: instalar tesseract.';
    }
    if (strpos($all, 'ocr_no_text') !== false) {
        $hints[] = 'OCR executou mas não encontrou texto legível (scan com baixa qualidade ou orientação problemática).';
    }
    if (strpos($all, 'unsupported_binary_type') !== false) {
        $hints[] = 'Tipo de ficheiro binário ainda não suportado pelo leitor automático.';
    }
    if (strpos($all, 'qr_detector_script_missing') !== false) {
        $hints[] = 'Leitor de QR fiscal não disponível (contabilidade/detectar_qr.py em falta).';
    }
    if (strpos($all, 'file_not_found') !== false) {
        $hints[] = 'Anexo não encontrado no armazenamento.';
    }

    if (empty($hints)) {
        $hints[] = 'Falha genérica de extração; tente novo PDF (texto pesquisável) ou imagem mais nítida.';
    }

    return [
        'errors' => $errors,
        'hints' => array_values(array_unique($hints)),
    ];
}

function buildDocumentReaderStructuredSummary(array $readerResult): array {
    $structured = isset($readerResult['structured']) && is_array($readerResult['structured'])
        ? $readerResult['structured']
        : [];
    $totals = isset($structured['totals']) && is_array($structured['totals'])
        ? $structured['totals']
        : [];
    $issuer = isset($structured['issuer']) && is_array($structured['issuer'])
        ? $structured['issuer']
        : [];
    $buyer = isset($structured['buyer']) && is_array($structured['buyer'])
        ? $structured['buyer']
        : [];
    $qr = isset($readerResult['qr']) && is_array($readerResult['qr'])
        ? $readerResult['qr']
        : [];

    return [
        'mode' => (string) ($readerResult['method'] ?? ''),
        'document_type' => (string) ($structured['document_type_guess'] ?? ''),
        'confidence' => $structured['confidence'] ?? null,
        'document_number' => (string) ($structured['document_number'] ?? ''),
        'document_date' => (string) ($structured['document_date'] ?? ''),
        'issuer_nif' => (string) ($issuer['nif'] ?? ''),
        'buyer_nif' => (string) ($buyer['nif'] ?? ''),
        'total' => (string) ($totals['total'] ?? ''),
        'iva_total' => (string) ($totals['iva_total'] ?? ''),
        'subtotal' => (string) ($totals['subtotal'] ?? ''),
        'qr_detected' => !empty($qr['ok']),
    ];
}

function isInvoiceLikeDocumentType(string $documentType): bool {
    $value = strtolower(trim($documentType));
    if ($value === '') {
        return false;
    }
    $normalized = str_replace(['-', '_', ' '], '', $value);
    $candidates = [
        'ft',
        'fr',
        'fatura',
        'factura',
        'faturarecibo',
        'facturarecibo',
        'faturarecibo',
        'invoicereceipt',
    ];
    return in_array($normalized, $candidates, true);
}

function buildAccountingImportWorkflowHint(array $structuredSummary): ?array {
    $documentType = (string) ($structuredSummary['document_type'] ?? '');
    if (!isInvoiceLikeDocumentType($documentType)) {
        return null;
    }

    return [
        'should_ask_import' => true,
        'question' => 'Pretende importar este documento para classificacao na intranet?',
        'menu_title' => 'Contabilidade > Classificacao',
        'url' => BASE_URL . 'contabilidade/classificacao-importacao?import_type=1',
        'workflow_note' => 'Respeitar o workflow atual: classificacao por linha e importacao conforme permissoes e estado do documento.',
        'do_not_ask_document_id' => true,
        'user_guidance' => 'Nao pedir ID do documento para este passo. Encaminhar o utilizador para o menu de Classificacao/importacao existente.',
    ];
}

function normalizeAssistantIntentText(string $text): string {
    $normalized = strtolower(trim($text));
    if ($normalized === '') {
        return '';
    }
    $normalized = strtr($normalized, [
        'á' => 'a',
        'à' => 'a',
        'â' => 'a',
        'ã' => 'a',
        'é' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ]);
    $normalized = preg_replace('/\s+/u', ' ', $normalized);
    return trim((string) $normalized);
}

function isAssistantAffirmativeIntent(string $message): bool {
    $normalized = normalizeAssistantIntentText($message);
    if ($normalized === '') {
        return false;
    }
    if (preg_match('/^(sim|s|ok|confirmo|avanca|avance|forca|pode avancar|podes avancar|quero importar|importar|importa)\b/u', $normalized)) {
        return true;
    }
    return strpos($normalized, 'pode importar') !== false
        || strpos($normalized, 'podes importar') !== false;
}

function isAssistantNegativeIntent(string $message): bool {
    $normalized = normalizeAssistantIntentText($message);
    if ($normalized === '') {
        return false;
    }
    return (bool) preg_match('/^(nao|n|agora nao|depois|cancelar|cancela)\b/u', $normalized);
}

function setPendingAccountingImportIntent(string $sessionId, array $intent): void {
    if (!isset($_SESSION['ai_pending_accounting_import']) || !is_array($_SESSION['ai_pending_accounting_import'])) {
        $_SESSION['ai_pending_accounting_import'] = [];
    }
    $_SESSION['ai_pending_accounting_import'][$sessionId] = $intent;
}

function getPendingAccountingImportIntent(string $sessionId): ?array {
    if (!isset($_SESSION['ai_pending_accounting_import']) || !is_array($_SESSION['ai_pending_accounting_import'])) {
        return null;
    }
    $intent = $_SESSION['ai_pending_accounting_import'][$sessionId] ?? null;
    return is_array($intent) ? $intent : null;
}

function clearPendingAccountingImportIntent(string $sessionId): void {
    if (!isset($_SESSION['ai_pending_accounting_import']) || !is_array($_SESSION['ai_pending_accounting_import'])) {
        return;
    }
    unset($_SESSION['ai_pending_accounting_import'][$sessionId]);
}

function normalizeAssistantQrDate(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{8}$/', $value)) {
        return substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2);
    }
    return $value;
}

function isFtOrFtrDocumentType(string $documentType): bool {
    $normalized = normalizeAssistantIntentText($documentType);
    if ($normalized === '') {
        return false;
    }
    $normalized = str_replace(['-', '_', ' '], '', $normalized);
    return in_array($normalized, ['ft', 'fr', 'ftr', 'faturarecibo', 'facturarecibo'], true);
}

function parseAssistantDocumentDateInput(string $message): string {
    $value = trim($message);
    if ($value === '') {
        return '';
    }

    if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $value, $m)) {
        if (checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
    }

    if (preg_match('/\b(\d{2})[\/\-](\d{2})[\/\-](\d{4})\b/', $value, $m)) {
        if (checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
    }

    return '';
}

function copyAttachmentToAccountingUploadPath(array $attachment): array {
    $sourceAbsolute = resolveAssistantStoredPath($attachment);
    if ($sourceAbsolute === null || !is_file($sourceAbsolute)) {
        return ['ok' => false, 'error' => 'anexo_nao_encontrado'];
    }

    $slug = getCompanySlug() ?: getConfiguredCompanySlug();
    $safeSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $slug);
    if ($safeSlug === '') {
        return ['ok' => false, 'error' => 'empresa_nao_selecionada'];
    }

    $year = date('Y');
    $month = date('m');
    $targetDir = __DIR__ . '/uploads/' . $safeSlug . '/accounting/' . $year . '/' . $month . '/';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
        return ['ok' => false, 'error' => 'falha_criar_diretorio'];
    }

    $extension = strtolower((string) pathinfo((string) ($attachment['filename'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === '') {
        $extension = 'pdf';
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $targetAbsolute = $targetDir . $storedName;

    if (!@copy($sourceAbsolute, $targetAbsolute)) {
        return ['ok' => false, 'error' => 'falha_copiar_anexo'];
    }

    return [
        'ok' => true,
        'absolute_path' => $targetAbsolute,
        'relative_path' => 'uploads/' . $safeSlug . '/accounting/' . $year . '/' . $month . '/' . $storedName,
    ];
}

function buildAccountingRowFromReaderResult(array $readerResult, array $attachment): ?array {
    $qr = isset($readerResult['qr']) && is_array($readerResult['qr']) ? $readerResult['qr'] : [];
    $payload = isset($qr['payload']) && is_array($qr['payload']) ? $qr['payload'] : [];
    if (empty($payload)) {
        return null;
    }

    $keys = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I1', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'N', 'O', 'Q', 'R'];
    $row = [];
    foreach ($keys as $key) {
        $row[$key] = trim((string) ($payload[$key] ?? ''));
    }

    $structured = isset($readerResult['structured']) && is_array($readerResult['structured']) ? $readerResult['structured'] : [];
    $issuer = isset($structured['issuer']) && is_array($structured['issuer']) ? $structured['issuer'] : [];
    $buyer = isset($structured['buyer']) && is_array($structured['buyer']) ? $structured['buyer'] : [];
    $totals = isset($structured['totals']) && is_array($structured['totals']) ? $structured['totals'] : [];

    if ($row['A'] === '') {
        $row['A'] = trim((string) ($issuer['nif'] ?? ''));
    }
    if ($row['B'] === '') {
        $row['B'] = trim((string) ($buyer['nif'] ?? ''));
    }
    if ($row['D'] === '') {
        $row['D'] = trim((string) ($structured['document_type_guess'] ?? ''));
    }
    if ($row['E'] === '') {
        $row['E'] = trim((string) ($structured['document_number'] ?? ''));
    }
    if ($row['F'] === '') {
        $row['F'] = trim((string) ($structured['document_date'] ?? ''));
    }
    if ($row['H'] === '') {
        $row['H'] = trim((string) ($structured['document_number'] ?? ''));
    }
    if ($row['O'] === '') {
        $row['O'] = trim((string) ($totals['total'] ?? ''));
    }
    if ($row['Q'] === '') {
        $row['Q'] = trim((string) ($totals['iva_total'] ?? ''));
    }
    if ($row['N'] === '') {
        $row['N'] = trim((string) ($totals['subtotal'] ?? ''));
    }

    $row['F'] = normalizeAssistantQrDate($row['F']);
    $row['filename'] = trim((string) ($attachment['path'] ?? ''));
    if ($row['filename'] === '') {
        $row['filename'] = trim((string) ($attachment['url'] ?? ''));
    }

    $hasCore = $row['A'] !== '' || $row['B'] !== '' || $row['D'] !== '' || $row['H'] !== '';
    if (!$hasCore) {
        return null;
    }

    return $row;
}

function importAssistantAccountingRow(array $row, int $importType = 1): array {
    if (!hasTable('accounting_imports')) {
        return ['ok' => false, 'error' => 'Tabela accounting_imports indisponivel.'];
    }

    $importType = $importType > 0 ? $importType : 1;
    $pdo = getPDO();

    $account = '';
    if (hasTable('accounting_classifications')) {
        $stmt = $pdo->prepare('SELECT account FROM accounting_classifications WHERE emitter = ? AND acquirer = ? AND doc_type = ? LIMIT 1');
        $stmt->execute([
            (string) ($row['A'] ?? ''),
            (string) ($row['B'] ?? ''),
            (string) ($row['D'] ?? ''),
        ]);
        $account = (string) ($stmt->fetchColumn() ?: '');
    }

    $fieldH = trim((string) ($row['H'] ?? ''));
    if ($fieldH !== '') {
        $exists = $pdo->prepare('SELECT id FROM accounting_imports WHERE field_H = ? AND import_type = ? LIMIT 1');
        $exists->execute([$fieldH, $importType]);
        $existingId = (int) ($exists->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return ['ok' => true, 'duplicate' => true, 'id' => $existingId];
        }
    }

    $insert = $pdo->prepare('INSERT INTO accounting_imports (field_A, field_B, field_C, field_D, field_E, field_F, field_G, field_H, field_I1, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8, field_N, field_O, field_Q, field_R, account, filename, import_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insert->execute([
        (string) ($row['A'] ?? ''),
        (string) ($row['B'] ?? ''),
        (string) ($row['C'] ?? ''),
        (string) ($row['D'] ?? ''),
        (string) ($row['E'] ?? ''),
        (string) ($row['F'] ?? ''),
        (string) ($row['G'] ?? ''),
        $fieldH,
        (string) ($row['I1'] ?? ''),
        (string) ($row['I3'] ?? ''),
        (string) ($row['I4'] ?? ''),
        (string) ($row['I5'] ?? ''),
        (string) ($row['I6'] ?? ''),
        (string) ($row['I7'] ?? ''),
        (string) ($row['I8'] ?? ''),
        (string) ($row['N'] ?? ''),
        (string) ($row['O'] ?? ''),
        (string) ($row['Q'] ?? ''),
        (string) ($row['R'] ?? ''),
        $account,
        (string) ($row['filename'] ?? ''),
        $importType,
    ]);

    return ['ok' => true, 'duplicate' => false, 'id' => (int) $pdo->lastInsertId()];
}

function runAssistantAccountingUploadImportFlow(string $sessionId, array $pendingIntent, array $resolvedAttachments, string $erpBaseUrl, string $erpToken, string $forcedDocumentDate = ''): array {
    if (!userHasDepartmentPermission('compras_upload')) {
        return [
            'ok' => false,
            'clear_pending' => false,
            'actions' => [['type' => 'assistant_import_upload_denied']],
            'message' => "Sem permissao para importar pelo fluxo de Upload.\n\nMenu: Contabilidade > Upload\nLink: " . BASE_URL . 'contabilidade/upload',
        ];
    }

    $attachmentId = trim((string) ($pendingIntent['attachment_id'] ?? ''));
    $attachment = null;
    if ($attachmentId !== '') {
        $attachment = findAssistantAttachmentById($sessionId, $attachmentId);
    }
    if (!$attachment && !empty($resolvedAttachments)) {
        $candidate = end($resolvedAttachments);
        if (is_array($candidate)) {
            $attachment = $candidate;
        }
    }
    if (!$attachment) {
        $recent = $_SESSION['ai_recent_attachments'] ?? [];
        if (is_array($recent) && !empty($recent)) {
            $candidate = end($recent);
            if (is_array($candidate)) {
                $attachment = $candidate;
            }
        }
    }
    if (!$attachment) {
        return [
            'ok' => false,
            'clear_pending' => true,
            'actions' => [['type' => 'assistant_import_upload_missing_attachment']],
            'message' => 'Nao encontrei o anexo desta conversa para importar. Envie novamente o ficheiro e eu trato do upload/importacao automaticamente.',
        ];
    }

    $readerResult = readAssistantAttachmentWithPython($attachment, 8000);
    if (empty($readerResult['ok'])) {
        $diagnostics = buildDocumentReaderHints($readerResult);
        $hint = '';
        if (!empty($diagnostics['hints'][0])) {
            $hint = (string) $diagnostics['hints'][0];
        }
        return [
            'ok' => false,
            'clear_pending' => false,
            'actions' => [['type' => 'assistant_import_upload_extract_failed']],
            'message' => 'Nao consegui extrair dados suficientes do documento para importar no fluxo de Upload.' . ($hint !== '' ? "\nMotivo: " . $hint : ''),
        ];
    }

    $row = buildAccountingRowFromReaderResult($readerResult, $attachment);
    if (!is_array($row)) {
        return [
            'ok' => false,
            'clear_pending' => false,
            'actions' => [['type' => 'assistant_import_upload_no_qr']],
            'message' => "Nao foi encontrado QR fiscal com dados estruturados para importar no fluxo de Upload.\n\nMenu: Contabilidade > Upload\nLink: " . BASE_URL . 'contabilidade/upload',
        ];
    }
    if ($forcedDocumentDate !== '') {
        $row['F'] = $forcedDocumentDate;
    }
    $docType = trim((string) ($row['D'] ?? ''));
    $docDate = trim((string) ($row['F'] ?? ''));
    if ($docDate === '' && isFtOrFtrDocumentType($docType)) {
        setPendingAccountingImportIntent($sessionId, [
            'attachment_id' => (string) ($attachment['id'] ?? ''),
            'awaiting_document_date' => 1,
            'document_type' => $docType,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return [
            'ok' => false,
            'clear_pending' => false,
            'actions' => [['type' => 'assistant_import_ask_document_date']],
            'message' => "Data do documento nao disponivel para " . ($docType !== '' ? $docType : 'FT/FTR') . ".\n\nIndique a data do documento (YYYY-MM-DD ou DD/MM/YYYY) para eu concluir a importacao.",
        ];
    }

    $copied = copyAttachmentToAccountingUploadPath($attachment);
    if (empty($copied['ok'])) {
        return [
            'ok' => false,
            'clear_pending' => false,
            'actions' => [['type' => 'assistant_import_upload_copy_failed']],
            'message' => 'Nao foi possivel preparar o ficheiro no diretorio de Upload de contabilidade.',
        ];
    }
    $row['filename'] = (string) ($copied['relative_path'] ?? ($row['filename'] ?? ''));

    $import = importAssistantAccountingRow($row, 1);
    if (empty($import['ok'])) {
        return [
            'ok' => false,
            'clear_pending' => false,
            'actions' => [['type' => 'assistant_import_upload_failed']],
            'message' => 'Falha ao importar para Classificacao: ' . (string) ($import['error'] ?? 'erro interno'),
        ];
    }

    $structuredSummary = buildDocumentReaderStructuredSummary($readerResult);
    $issuerInfo = resolvePartyNameWithAppTools((string) ($structuredSummary['issuer_nif'] ?? ''), $erpBaseUrl, $erpToken);
    $buyerInfo = resolvePartyNameWithAppTools((string) ($structuredSummary['buyer_nif'] ?? ''), $erpBaseUrl, $erpToken);
    $header = buildIssuerBuyerHeader([
        'parties' => [
            'issuer' => $issuerInfo,
            'buyer' => $buyerInfo,
        ],
    ]);

    $docId = (int) ($import['id'] ?? 0);
    $isDuplicate = !empty($import['duplicate']);
    $statusLine = $isDuplicate
        ? 'Documento ja existente na Classificacao (duplicado por identificador SAF-T).'
        : ('Documento importado com sucesso para Classificacao (ID ' . $docId . ').');

    $message = '';
    if ($header !== '') {
        $message .= $header . "\n\n";
    }
    $message .= $statusLine . "\n\n";
    $message .= "Menu: Contabilidade > Classificacao\n";
    $message .= 'Link: ' . BASE_URL . 'contabilidade/classificacao-importacao?import_type=1';

    $actions = [[
        'type' => 'assistant_import_upload',
        'document_id' => $docId,
        'duplicate' => $isDuplicate ? 1 : 0,
        'attachment_id' => (string) ($attachment['id'] ?? ''),
    ]];

    logAuditAction('ai_import_upload', 'accounting_imports', $docId > 0 ? $docId : null, [
        'duplicate' => $isDuplicate ? 1 : 0,
        'attachment_id' => (string) ($attachment['id'] ?? ''),
    ]);

    return [
        'ok' => true,
        'clear_pending' => true,
        'actions' => $actions,
        'message' => $message,
    ];
}

function getLocalAccountingEntityNameByNif(string $nif): string {
    $nif = preg_replace('/\D+/', '', trim($nif));
    if ($nif === '' || !hasTable('accounting_entities')) {
        return '';
    }

    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT name FROM accounting_entities WHERE nif = ? LIMIT 1');
    $stmt->execute([$nif]);
    $name = $stmt->fetchColumn();
    return is_string($name) ? trim($name) : '';
}

function extractFirstErpRowFromPayload(array $payload): ?array {
    foreach (['aaData', 'data', 'result', 'results'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key]) && !empty($payload[$key])) {
            $first = $payload[$key][0] ?? null;
            if (is_array($first)) {
                return $first;
            }
        }
    }

    if (array_keys($payload) === range(0, count($payload) - 1) && !empty($payload[0]) && is_array($payload[0])) {
        return $payload[0];
    }

    return null;
}

function extractPartyNameFromErpRow(array $row): string {
    foreach (['strNome', 'name', 'nome', 'strName', 'strDenominacao', 'strDescricao'] as $key) {
        if (isset($row[$key])) {
            $value = trim((string) $row[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function extractPartyNifFromErpRow(array $row): string {
    foreach (['strNumContrib', 'nif', 'numContrib', 'strNif', 'vat'] as $key) {
        if (!isset($row[$key])) {
            continue;
        }
        $value = preg_replace('/\D+/', '', trim((string) $row[$key]));
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }
    return '';
}

function extractDatabaseCandidatesFromPayload(array $payload): array {
    $rows = [];
    foreach (['aaData', 'data', 'result', 'results'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $rows = $payload[$key];
            break;
        }
    }
    if (empty($rows) && array_keys($payload) === range(0, count($payload) - 1)) {
        $rows = $payload;
    }

    $result = [];
    foreach ($rows as $row) {
        if (is_string($row)) {
            $candidate = trim($row);
            if ($candidate !== '') {
                $result[] = $candidate;
            }
            continue;
        }
        if (!is_array($row)) {
            continue;
        }
        foreach (['db', 'database', 'base_dados', 'strBaseDados', 'strDatabase', 'EMP', 'emp'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $candidate = trim((string) $row[$key]);
            if ($candidate !== '') {
                $result[] = $candidate;
                break;
            }
        }
    }

    return array_values(array_unique($result));
}

function getErpDatabaseCandidates(string $erpBaseUrl, string $erpToken): array {
    if ($erpBaseUrl === '' || $erpToken === '') {
        return [];
    }

    $endpoint = buildErpGetEndpoint($erpBaseUrl, '/contabilidade/listDBemp', [], '');
    $response = callErpWebservice($endpoint, $erpToken);
    if (empty($response['ok']) || !is_array($response['data'] ?? null)) {
        return [];
    }

    return extractDatabaseCandidatesFromPayload($response['data']);
}

function resolveErpDatabaseForNifWithAppTools(string $nif, string $erpBaseUrl, string $erpToken): string {
    static $cache = [];

    $normalizedNif = preg_replace('/\D+/', '', trim($nif));
    if (!is_string($normalizedNif) || $normalizedNif === '') {
        return '';
    }
    if (isset($cache[$normalizedNif])) {
        return (string) $cache[$normalizedNif];
    }

    $localDb = getErpDatabaseForNif($normalizedNif);
    if (is_string($localDb) && trim($localDb) !== '') {
        $cache[$normalizedNif] = trim($localDb);
        return (string) $cache[$normalizedNif];
    }

    $dbCandidates = getErpDatabaseCandidates($erpBaseUrl, $erpToken);
    if (empty($dbCandidates)) {
        $cache[$normalizedNif] = '';
        return '';
    }

    $query = [
        'q' => $normalizedNif,
        'searchField' => 'strNumContrib',
        'limit' => 5,
        'offset' => 0,
    ];

    $maxChecks = 120;
    $checks = 0;
    foreach ($dbCandidates as $db) {
        if ($checks >= $maxChecks) {
            break;
        }

        foreach (['/clientes', '/fornecedores'] as $path) {
            if ($checks >= $maxChecks) {
                break;
            }
            $checks++;
            $endpoint = buildErpGetEndpoint($erpBaseUrl, $path, $query, (string) $db);
            $response = callErpWebservice($endpoint, $erpToken);
            if (empty($response['ok']) || !is_array($response['data'] ?? null)) {
                continue;
            }
            $row = extractFirstErpRowFromPayload($response['data']);
            if (!is_array($row)) {
                continue;
            }
            $rowNif = extractPartyNifFromErpRow($row);
            if ($rowNif === '' || $rowNif === $normalizedNif) {
                $cache[$normalizedNif] = (string) $db;
                return (string) $cache[$normalizedNif];
            }
        }
    }

    $cache[$normalizedNif] = '';
    return '';
}

function resolvePartyNameWithAppTools(string $nif, string $erpBaseUrl, string $erpToken): array {
    $normalizedNif = preg_replace('/\D+/', '', trim($nif));
    if ($normalizedNif === '') {
        return ['nif' => '', 'name' => '', 'source' => 'none', 'erp_database' => ''];
    }

    $resolvedDb = resolveErpDatabaseForNifWithAppTools($normalizedNif, $erpBaseUrl, $erpToken);
    $localName = getLocalAccountingEntityNameByNif($normalizedNif);
    if ($localName !== '') {
        return [
            'nif' => $normalizedNif,
            'name' => $localName,
            'source' => 'mysql_accounting_entities',
            'erp_database' => $resolvedDb,
        ];
    }

    if ($erpBaseUrl === '' || $erpToken === '') {
        return [
            'nif' => $normalizedNif,
            'name' => '',
            'source' => 'none',
            'erp_database' => $resolvedDb,
        ];
    }

    $dbHint = $resolvedDb;
    $commonQuery = [
        'q' => $normalizedNif,
        'searchField' => 'strNumContrib',
        'limit' => 1,
        'offset' => 0,
    ];

    $clientesEndpoint = buildErpGetEndpoint($erpBaseUrl, '/clientes', $commonQuery, $dbHint);
    $clientesResponse = callErpWebservice($clientesEndpoint, $erpToken);
    if (!empty($clientesResponse['ok']) && is_array($clientesResponse['data'] ?? null)) {
        $row = extractFirstErpRowFromPayload($clientesResponse['data']);
        if (is_array($row)) {
            $name = extractPartyNameFromErpRow($row);
            if ($name !== '') {
                return [
                    'nif' => $normalizedNif,
                    'name' => $name,
                    'source' => 'erp_clientes',
                    'erp_database' => $resolvedDb,
                ];
            }
        }
    }

    $fornecedoresEndpoint = buildErpGetEndpoint($erpBaseUrl, '/fornecedores', $commonQuery, $dbHint);
    $fornecedoresResponse = callErpWebservice($fornecedoresEndpoint, $erpToken);
    if (!empty($fornecedoresResponse['ok']) && is_array($fornecedoresResponse['data'] ?? null)) {
        $row = extractFirstErpRowFromPayload($fornecedoresResponse['data']);
        if (is_array($row)) {
            $name = extractPartyNameFromErpRow($row);
            if ($name !== '') {
                return [
                    'nif' => $normalizedNif,
                    'name' => $name,
                    'source' => 'erp_fornecedores',
                    'erp_database' => $resolvedDb,
                ];
            }
        }
    }

    return [
        'nif' => $normalizedNif,
        'name' => '',
        'source' => 'none',
        'erp_database' => $resolvedDb,
    ];
}

function getAccountingTaskMemories(int $userId, int $limit = 12): array {
    if ($userId <= 0 || !hasTable('ai_assistant_logs')) {
        return [];
    }

    $limit = max(1, min($limit, 50));
    $pdo = getPDO();
    if (aiLogsHasCategoryColumn()) {
        $stmt = $pdo->prepare(
            'SELECT summary FROM ai_assistant_logs WHERE user_id = ? AND category = ? AND summary IS NOT NULL AND summary <> "" ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId, 'accounting_task_memory']);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $stmt = $pdo->prepare(
            'SELECT summary FROM ai_assistant_logs WHERE user_id = ? AND summary LIKE ? ORDER BY id DESC LIMIT ' . $limit
        );
        $stmt->execute([$userId, 'TASK_MEMORY:%']);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    $memories = [];
    foreach ((array) $rows as $row) {
        $summary = trim((string) $row);
        if ($summary === '') {
            continue;
        }
        if (stripos($summary, 'TASK_MEMORY:') === 0) {
            $summary = trim(substr($summary, 12));
        }
        if ($summary === '' || in_array($summary, $memories, true)) {
            continue;
        }
        $memories[] = $summary;
    }
    return array_reverse($memories);
}

function saveAccountingTaskMemory(int $userId, string $sessionId, string $summary): bool {
    if ($userId <= 0 || !hasTable('ai_assistant_logs')) {
        return false;
    }
    $summary = trim($summary);
    if ($summary === '') {
        return false;
    }
    if (function_exists('mb_substr')) {
        $summary = mb_substr($summary, 0, 500, 'UTF-8');
    } else {
        $summary = substr($summary, 0, 500);
    }

    $pdo = getPDO();
    if (aiLogsHasCategoryColumn()) {
        $check = $pdo->prepare('SELECT id FROM ai_assistant_logs WHERE user_id = ? AND category = ? AND summary = ? LIMIT 1');
        $check->execute([$userId, 'accounting_task_memory', $summary]);
        if ($check->fetchColumn()) {
            return true;
        }
        $stmt = $pdo->prepare('INSERT INTO ai_assistant_logs (user_id, session_id, summary, actions, category) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $sessionId, $summary, json_encode([['type' => 'task_memory_save']], JSON_UNESCAPED_UNICODE), 'accounting_task_memory']);
        return true;
    }

    $prefixed = 'TASK_MEMORY: ' . $summary;
    $check = $pdo->prepare('SELECT id FROM ai_assistant_logs WHERE user_id = ? AND summary = ? LIMIT 1');
    $check->execute([$userId, $prefixed]);
    if ($check->fetchColumn()) {
        return true;
    }
    $stmt = $pdo->prepare('INSERT INTO ai_assistant_logs (user_id, session_id, summary, actions) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $sessionId, $prefixed, json_encode([['type' => 'task_memory_save']], JSON_UNESCAPED_UNICODE)]);
    return true;
}

function buildAccountingTaskMemorySummary(string $userMessage, array $actions): string {
    $taskTypes = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $type = trim((string) ($action['type'] ?? ''));
        if ($type === '') {
            continue;
        }
        if (in_array($type, ['create_task', 'suggest_accounts', 'document_approved', 'document_rejected', 'open_lancamentos', 'erp_movimentos_search', 'erp_planocontas_search', 'erp_taxonomias_search', 'erp_clientes_search', 'erp_fornecedores_search', 'erp_exercicios_list', 'erp_empresas_list', 'erp_api_get', 'get_accounting_examples'], true)) {
            $taskTypes[] = $type;
        }
    }
    $taskTypes = array_values(array_unique($taskTypes));

    if (empty($taskTypes) && !preg_match('/\b(tarefa|classifica|importa|contabil|lancamento|lançamento)\b/iu', $userMessage)) {
        return '';
    }

    $summary = 'Pedido contabilistico: ' . trim($userMessage);
    if ($taskTypes) {
        $summary .= ' | Acoes executadas: ' . implode(', ', $taskTypes);
    }
    return $summary;
}

function isLikelyJsonMessage(string $message): bool {
    $trimmed = trim($message);
    if ($trimmed === '') {
        return false;
    }
    return ($trimmed[0] === '{' || $trimmed[0] === '[');
}

function buildIssuerBuyerHeader(array $action): string {
    $parties = isset($action['parties']) && is_array($action['parties']) ? $action['parties'] : [];
    $issuer = isset($parties['issuer']) && is_array($parties['issuer']) ? $parties['issuer'] : [];
    $buyer = isset($parties['buyer']) && is_array($parties['buyer']) ? $parties['buyer'] : [];

    $issuerNif = trim((string) ($issuer['nif'] ?? ''));
    $issuerName = trim((string) ($issuer['name'] ?? ''));
    $buyerNif = trim((string) ($buyer['nif'] ?? ''));
    $buyerName = trim((string) ($buyer['name'] ?? ''));

    if ($issuerNif === '' && $issuerName === '' && $buyerNif === '' && $buyerName === '') {
        return '';
    }

    $issuerLabel = trim(($issuerNif !== '' ? $issuerNif : '-') . ($issuerName !== '' ? ' - ' . $issuerName : ''));
    $buyerLabel = trim(($buyerNif !== '' ? $buyerNif : '-') . ($buyerName !== '' ? ' - ' . $buyerName : ''));

    return "Emitente: " . $issuerLabel . "\nAdquirente: " . $buyerLabel;
}

function listReadablePhpFiles(string $rootDir): array {
    $rootRealPath = realpath($rootDir);
    if ($rootRealPath === false) {
        return [];
    }

    $skipSegments = ['/vendor/', '/vendors/', '/node_modules/', '/.git/', '/uploads/'];
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootRealPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        if (strtolower((string) $fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
        $relativePath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen($rootRealPath))), '/');
        $normalized = '/' . $relativePath . '/';

        $skip = false;
        foreach ($skipSegments as $segment) {
            if (strpos($normalized, $segment) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        $files[] = ['absolute' => $absolutePath, 'relative' => $relativePath];
    }

    usort($files, static fn(array $a, array $b): int => strcmp($a['relative'], $b['relative']));
    return $files;
}

function extractNamedFunctionFromPhp(string $source, string $functionName): ?array {
    $tokens = token_get_all($source);
    $offset = 0;
    $total = count($tokens);
    $target = strtolower($functionName);

    for ($i = 0; $i < $total; $i++) {
        $token = $tokens[$i];
        $tokenText = is_array($token) ? (string) $token[1] : (string) $token;
        $tokenLen = strlen($tokenText);

        if (is_array($token) && $token[0] === T_FUNCTION) {
            $functionStart = $offset;
            $j = $i + 1;
            $name = '';
            while ($j < $total) {
                $look = $tokens[$j];
                if (is_array($look) && in_array($look[0], [T_WHITESPACE, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                    $j++;
                    continue;
                }
                if (is_array($look) && $look[0] === T_STRING) {
                    $name = (string) $look[1];
                }
                break;
            }

            if ($name !== '' && strtolower($name) === $target) {
                $k = $j;
                while ($k < $total) {
                    $current = $tokens[$k];
                    $text = is_array($current) ? (string) $current[1] : (string) $current;
                    if ($text === '{') {
                        $braceDepth = 1;
                        $k++;
                        while ($k < $total && $braceDepth > 0) {
                            $braceToken = $tokens[$k];
                            $braceText = is_array($braceToken) ? (string) $braceToken[1] : (string) $braceToken;
                            if ($braceText === '{') {
                                $braceDepth++;
                            } elseif ($braceText === '}') {
                                $braceDepth--;
                            }
                            $k++;
                        }

                        $endOffset = 0;
                        for ($x = 0; $x < $k; $x++) {
                            $tx = $tokens[$x];
                            $endOffset += strlen(is_array($tx) ? (string) $tx[1] : (string) $tx);
                        }

                        return [
                            'name' => $name,
                            'code' => substr($source, $functionStart, max(0, $endOffset - $functionStart)),
                        ];
                    }
                    if ($text === ';') {
                        $endOffset = 0;
                        for ($x = 0; $x <= $k; $x++) {
                            $tx = $tokens[$x];
                            $endOffset += strlen(is_array($tx) ? (string) $tx[1] : (string) $tx);
                        }

                        return [
                            'name' => $name,
                            'code' => substr($source, $functionStart, max(0, $endOffset - $functionStart)),
                        ];
                    }
                    $k++;
                }
            }
        }

        $offset += $tokenLen;
    }

    return null;
}

function readPhpFunctionFromProject(string $rootDir, string $functionName, string $fileHint = ''): array {
    $functionName = trim($functionName);
    if ($functionName === '') {
        return ['ok' => false, 'error' => 'Nome da funcao vazio.'];
    }

    $files = listReadablePhpFiles($rootDir);
    if (empty($files)) {
        return ['ok' => false, 'error' => 'Nao foram encontrados ficheiros PHP para leitura.'];
    }

    $fileHint = trim($fileHint);
    if ($fileHint !== '') {
        $files = array_values(array_filter($files, static function (array $file) use ($fileHint): bool {
            return stripos($file['relative'], $fileHint) !== false;
        }));
        if (empty($files)) {
            return ['ok' => false, 'error' => 'Nenhum ficheiro corresponde ao file_hint indicado.'];
        }
    }

    foreach ($files as $file) {
        $content = @file_get_contents($file['absolute']);
        if (!is_string($content) || $content === '') {
            continue;
        }
        $match = extractNamedFunctionFromPhp($content, $functionName);
        if ($match !== null) {
            $code = trim((string) ($match['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            if (function_exists('mb_substr') && mb_strlen($code, 'UTF-8') > 8000) {
                $code = mb_substr($code, 0, 8000, 'UTF-8') . "\n// [trecho truncado por limite]";
            } elseif (strlen($code) > 8000) {
                $code = substr($code, 0, 8000) . "\n// [trecho truncado por limite]";
            }

            return [
                'ok' => true,
                'function_name' => $functionName,
                'file' => $file['relative'],
                'code' => $code,
            ];
        }
    }

    return ['ok' => false, 'error' => 'Funcao nao encontrada nos ficheiros PHP permitidos.'];
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

function getErpDefaultEmp(): string {
    $company = trim((string) getSetting('accounting_base_company', ''));
    if ($company !== '') {
        return $company;
    }
    return '';
}

function applyErpCompanyParams(array $query, string $dbHint = ''): array {
    $dbHint = trim($dbHint);
    $db = trim((string) ($query['db'] ?? ''));
    if ($db === '' && $dbHint !== '') {
        $db = $dbHint;
    }

    $emp = getErpDefaultEmp();
    if ($emp === '' && $db !== '') {
        $emp = $db;
    }

    if ($db !== '') {
        $query['db'] = $db;
    }
    if ($emp !== '') {
        $query['EMP'] = $emp;
    }

    return $query;
}

function buildErpGetEndpoint(string $baseUrl, string $path, array $query = [], string $dbHint = ''): string {
    $base = rtrim($baseUrl, '/');
    $path = '/' . ltrim($path, '/');
    $query = applyErpCompanyParams($query, $dbHint);
    if (empty($query)) {
        return $base . $path;
    }
    return $base . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function fetchPlanAccounts(string $baseUrl, string $token, string $db, string $year, string $nif = ''): array {
    $query = [
        'strCodExercicio' => $year,
        'limit' => 200,
        'offset' => 0,
    ];
    if ($nif !== '') {
        $query['strNumContrib'] = $nif;
    }
    $results = [];
    $page = 0;
    while ($page < 20) {
        $endpoint = buildErpGetEndpoint($baseUrl, '/contabilidade/planocontas', $query, $db);
        $response = callErpWebservice($endpoint, $token);
        if (!$response['ok']) {
            break;
        }
        $data = $response['data']['aaData'] ?? [];
        if (!is_array($data) || !$data) {
            break;
        }
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
        if (count($data) < $query['limit']) {
            break;
        }
        $page += 1;
        $query['offset'] = $page * $query['limit'];
    }
    return $results;
}

function extractVatLikeValue(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/(\d{9})/', $value, $matches)) {
        return $matches[1];
    }
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === '') {
        return '';
    }
    return substr($digits, 0, 30);
}

function normalizePartyToken(string $value): string {
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/\d+/', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    return $value;
}

function extractTotalAccountFromPayload(string $json): string {
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return '';
    }
    $candidate = '';
    if (isset($decoded['meta']) && is_array($decoded['meta'])) {
        $candidate = trim((string) ($decoded['meta']['total_account'] ?? ''));
    }
    if ($candidate === '') {
        $candidate = trim((string) ($decoded['total_account'] ?? ''));
    }
    return $candidate;
}

function fetchHistoryExamples(string $acquirerNif, string $docType, int $limit, string $mode = 'strict', array $context = []): array {
    $pdo = getPDO();
    $acquirerNif = extractVatLikeValue($acquirerNif);
    $docType = trim($docType);
    $emitterHint = normalizePartyToken((string) ($context['emitter'] ?? ''));
    $acquirerHint = normalizePartyToken((string) ($context['acquirer_raw'] ?? ''));

    $sql = 'SELECT id, field_A, field_B, field_C, field_D, account, line_items FROM accounting_imports WHERE account <> \'\'';
    $params = [];

    if ($mode === 'strict') {
        if ($acquirerNif !== '') {
            $sql .= ' AND (field_B = ? OR field_B LIKE ? OR field_C = ? OR field_C LIKE ?)';
            $params[] = $acquirerNif;
            $params[] = '%' . $acquirerNif . '%';
            $params[] = $acquirerNif;
            $params[] = '%' . $acquirerNif . '%';
        }
        if ($docType !== '') {
            $sql .= ' AND field_D = ?';
            $params[] = $docType;
        }
    } elseif ($mode === 'acquirer' && $acquirerNif !== '') {
        $sql .= ' AND (field_B = ? OR field_B LIKE ? OR field_C = ? OR field_C LIKE ?)';
        $params[] = $acquirerNif;
        $params[] = '%' . $acquirerNif . '%';
        $params[] = $acquirerNif;
        $params[] = '%' . $acquirerNif . '%';
    } elseif ($mode === 'doctype' && $docType !== '') {
        $sql .= ' AND field_D = ?';
        $params[] = $docType;
    }

    $sql .= ' ORDER BY id DESC LIMIT ' . max(10, $limit * 4);
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

        $rowAcquirerNif = extractVatLikeValue((string) ($row['field_B'] ?? ''));
        if ($rowAcquirerNif === '') {
            $rowAcquirerNif = extractVatLikeValue((string) ($row['field_C'] ?? ''));
        }

        $score = 0;
        if ($acquirerNif !== '' && $rowAcquirerNif !== '' && $acquirerNif === $rowAcquirerNif) {
            $score += 6;
        }
        if ($docType !== '' && trim((string) ($row['field_D'] ?? '')) === $docType) {
            $score += 4;
        }
        if ($emitterHint !== '') {
            $rowEmitter = normalizePartyToken((string) ($row['field_A'] ?? ''));
            if ($rowEmitter !== '' && strpos($rowEmitter, $emitterHint) !== false) {
                $score += 3;
            }
        }
        if ($acquirerHint !== '') {
            $rowAcquirer = normalizePartyToken((string) ($row['field_B'] ?? ''));
            if ($rowAcquirer !== '' && strpos($rowAcquirer, $acquirerHint) !== false) {
                $score += 2;
            }
        }
        if (trim((string) ($row['line_items'] ?? '')) !== '') {
            $score += 1;
        }

        $examples[] = [
            'id' => (int) ($row['id'] ?? 0),
            'acquirer_nif' => $rowAcquirerNif,
            'doc_type' => (string) ($row['field_D'] ?? ''),
            'rates' => $rates,
            'score' => $score,
            'total_account' => extractTotalAccountFromPayload($accountJson),
        ];
    }

    usort($examples, static function (array $a, array $b): int {
        $scoreCmp = (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
    });

    return array_slice($examples, 0, $limit);
}

function fetchClassificationRuleExamples(string $docType, string $emitter, string $acquirer, int $limit = 20): array {
    if (!hasTable('accounting_classifications')) {
        return [];
    }
    $docType = trim($docType);
    $emitter = trim($emitter);
    $acquirer = trim($acquirer);

    $pdo = getPDO();
    $sql = 'SELECT id, emitter, acquirer, doc_type, account FROM accounting_classifications WHERE 1=1';
    $params = [];
    if ($docType !== '') {
        $sql .= ' AND doc_type = ?';
        $params[] = $docType;
    }
    $sql .= ' ORDER BY id DESC LIMIT ' . max(1, $limit * 3);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $normalizedEmitter = normalizePartyToken($emitter);
    $normalizedAcquirer = normalizePartyToken($acquirer);
    $examples = [];
    foreach ($rows as $row) {
        $accountPayload = trim((string) ($row['account'] ?? ''));
        if ($accountPayload === '') {
            continue;
        }
        $rates = extractAccountsFromPayload($accountPayload);
        $totalAccount = extractTotalAccountFromPayload($accountPayload);
        if (!$rates && $totalAccount === '') {
            continue;
        }

        $score = 0;
        if ($docType !== '' && trim((string) ($row['doc_type'] ?? '')) === $docType) {
            $score += 4;
        }
        $ruleEmitter = normalizePartyToken((string) ($row['emitter'] ?? ''));
        $ruleAcquirer = normalizePartyToken((string) ($row['acquirer'] ?? ''));
        if ($normalizedEmitter !== '' && $ruleEmitter !== '' && (strpos($ruleEmitter, $normalizedEmitter) !== false || strpos($normalizedEmitter, $ruleEmitter) !== false)) {
            $score += 3;
        }
        if ($normalizedAcquirer !== '' && $ruleAcquirer !== '' && (strpos($ruleAcquirer, $normalizedAcquirer) !== false || strpos($normalizedAcquirer, $ruleAcquirer) !== false)) {
            $score += 2;
        }

        $examples[] = [
            'id' => (int) ($row['id'] ?? 0),
            'acquirer_nif' => '',
            'doc_type' => (string) ($row['doc_type'] ?? ''),
            'rates' => $rates,
            'score' => $score,
            'total_account' => $totalAccount,
        ];
    }

    usort($examples, static function (array $a, array $b): int {
        $scoreCmp = (int) ($b['score'] ?? 0) <=> (int) ($a['score'] ?? 0);
        if ($scoreCmp !== 0) {
            return $scoreCmp;
        }
        return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
    });

    return array_slice($examples, 0, $limit);
}

function buildExpectedLinesFromExamples(array $examples): array {
    $rateTally = [];
    $totalTally = [];
    foreach ($examples as $example) {
        $rates = is_array($example['rates'] ?? null) ? $example['rates'] : [];
        foreach ($rates as $rateKey => $accounts) {
            if (!isset($rateTally[$rateKey])) {
                $rateTally[$rateKey] = ['general' => [], 'iva' => []];
            }
            $general = trim((string) ($accounts['general_account'] ?? ''));
            $iva = trim((string) ($accounts['iva_account'] ?? ''));
            if ($general !== '') {
                $rateTally[$rateKey]['general'][$general] = ($rateTally[$rateKey]['general'][$general] ?? 0) + 1;
            }
            if ($iva !== '') {
                $rateTally[$rateKey]['iva'][$iva] = ($rateTally[$rateKey]['iva'][$iva] ?? 0) + 1;
            }
        }
        $total = trim((string) ($example['total_account'] ?? ''));
        if ($total !== '') {
            $totalTally[$total] = ($totalTally[$total] ?? 0) + 1;
        }
    }

    $expected = ['rates' => [], 'total_account' => '', 'sample_size' => count($examples)];
    foreach ($rateTally as $rateKey => $counts) {
        $general = '';
        $iva = '';
        if (!empty($counts['general'])) {
            arsort($counts['general']);
            $general = (string) array_key_first($counts['general']);
        }
        if (!empty($counts['iva'])) {
            arsort($counts['iva']);
            $iva = (string) array_key_first($counts['iva']);
        }
        if ($general !== '' || $iva !== '') {
            $expected['rates'][$rateKey] = [
                'general_account' => $general,
                'iva_account' => $iva,
            ];
        }
    }
    if (!empty($totalTally)) {
        arsort($totalTally);
        $expected['total_account'] = (string) array_key_first($totalTally);
    }
    return $expected;
}

function collectAccountLikeTokens($value, string $key = '', array &$bucket = []): void {
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            collectAccountLikeTokens($v, is_string($k) ? $k : '', $bucket);
        }
        return;
    }
    if (!is_string($value) && !is_numeric($value)) {
        return;
    }
    $text = trim((string) $value);
    if ($text === '') {
        return;
    }

    $normalizedKey = strtolower($key);
    $looksLikeAccountField = strpos($normalizedKey, 'conta') !== false || strpos($normalizedKey, 'account') !== false;
    if (!$looksLikeAccountField && !preg_match('/^\d{3,}$/', $text)) {
        return;
    }

    if (!preg_match('/^\d{3,}$/', $text)) {
        return;
    }

    $bucket[$text] = ($bucket[$text] ?? 0) + 1;
}

function fetchErpMovementAccountHints(string $baseUrl, string $token, string $db, string $docType, string $acquirerNif): array {
    if ($baseUrl === '' || $token === '' || $db === '') {
        return ['general' => [], 'iva' => [], 'count' => 0];
    }
    $query = [
        'limit' => 120,
        'offset' => 0,
    ];
    if ($docType !== '') {
        $query['strAbrevTpDoc'] = $docType;
    }
    $acquirerNif = extractVatLikeValue($acquirerNif);
    if ($acquirerNif !== '') {
        $query['strNumContrib'] = $acquirerNif;
    }

    $endpoint = buildErpGetEndpoint($baseUrl, '/contabilidade/movimentos', $query, $db);
    $response = callErpWebservice($endpoint, $token);
    if (!$response['ok']) {
        return ['general' => [], 'iva' => [], 'count' => 0];
    }
    $payload = $response['data'];
    $rows = [];
    foreach (['aaData', 'data', 'result', 'results'] as $key) {
        if (isset($payload[$key]) && is_array($payload[$key])) {
            $rows = $payload[$key];
            break;
        }
    }
    if (empty($rows) && array_keys($payload) === range(0, count($payload) - 1)) {
        $rows = $payload;
    }

    $accountCounts = [];
    foreach ($rows as $row) {
        collectAccountLikeTokens($row, '', $accountCounts);
    }
    if (empty($accountCounts)) {
        return ['general' => [], 'iva' => [], 'count' => is_array($rows) ? count($rows) : 0];
    }

    arsort($accountCounts);
    $general = [];
    $iva = [];
    foreach ($accountCounts as $account => $count) {
        if (strpos($account, '243') === 0) {
            if (count($iva) < 5) {
                $iva[] = $account;
            }
            continue;
        }
        if (strpos($account, '6') === 0 || strpos($account, '62') === 0 || strpos($account, '63') === 0) {
            if (count($general) < 8) {
                $general[] = $account;
            }
        }
    }

    return ['general' => $general, 'iva' => $iva, 'count' => is_array($rows) ? count($rows) : 0];
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

    return pickExpenseAccountFromPlan($planAccounts);
}

function pickExpenseAccountFromPlan(array $planAccounts): string {
    if (!$planAccounts) {
        return '';
    }
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
    return '';
}

function pickGeneralAccountByIvaAccount(array $planAccounts, string $ivaAccount): string {
    $ivaAccount = trim($ivaAccount);
    if ($ivaAccount === '' || !$planAccounts) {
        return '';
    }
    $preferredPrefixes = ['62', '63', '6'];
    foreach ($preferredPrefixes as $prefix) {
        foreach ($planAccounts as $row) {
            $account = trim((string) ($row['account'] ?? ''));
            $iva = trim((string) ($row['iva_account'] ?? ''));
            if ($account === '' || $iva === '') {
                continue;
            }
            if ($iva === $ivaAccount && strpos($account, $prefix) === 0) {
                return $account;
            }
        }
    }
    foreach ($planAccounts as $row) {
        $account = trim((string) ($row['account'] ?? ''));
        $iva = trim((string) ($row['iva_account'] ?? ''));
        if ($account === '' || $iva === '') {
            continue;
        }
        if ($iva === $ivaAccount) {
            return $account;
        }
    }
    return '';
}

function pickFallbackGeneralByRateKey(string $rateKey): string {
    $rateKey = normalizeRateKey($rateKey);
    if ($rateKey === '6') {
        return '624118';
    }
    if ($rateKey === '13') {
        return '624113';
    }
    if ($rateKey === '23') {
        return '622111';
    }
    return '';
}

function normalizeRateKey(string $value): string {
    $clean = trim(str_replace('%', '', $value));
    if ($clean === '') {
        return '';
    }
    $clean = str_replace(',', '.', $clean);
    if (!is_numeric($clean)) {
        return $clean;
    }
    $num = (float) $clean;
    if ($num > 0 && $num <= 1) {
        $num = $num * 100;
    }
    $num = round($num, 2);
    if (abs($num - round($num)) < 0.001) {
        return (string) (int) round($num);
    }
    return rtrim(rtrim(number_format($num, 2, '.', ''), '0'), '.');
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
    $acquirerRaw = trim((string) ($args['acquirer_raw'] ?? ''));
    $acquirerNif = trim((string) ($args['acquirer_nif'] ?? ''));
    $acquirerNif = extractVatLikeValue($acquirerNif !== '' ? $acquirerNif : $acquirerRaw);
    $emitter = trim((string) ($args['emitter'] ?? ''));
    $emitterNif = extractVatLikeValue((string) ($args['emitter_nif'] ?? ''));
    $docType = trim((string) ($args['doc_type'] ?? ''));
    $rateItems = $args['rates'] ?? [];
    if ($acquirerNif === '' || !is_array($rateItems)) {
        return ['ok' => false, 'error' => 'Parametros invalidos.'];
    }
    $context = [
        'emitter' => $emitter,
        'emitter_nif' => $emitterNif,
        'acquirer_raw' => $acquirerRaw,
    ];
    $limit = 18;
    $examples = fetchHistoryExamples($acquirerNif, $docType, $limit, 'strict', $context);
    $ruleExamples = fetchClassificationRuleExamples($docType, $emitter, $acquirerRaw, 12);
    $expectedLines = buildExpectedLinesFromExamples(array_merge($examples, $ruleExamples));
    $suggestedFromHistory = buildSuggestionsFromExamples($examples, $rateItems);
    if (!empty($ruleExamples)) {
        $ruleSuggested = buildSuggestionsFromExamples($ruleExamples, $rateItems);
        $suggestedFromHistory = mergeSuggestedAccounts($suggestedFromHistory, $ruleSuggested);
    }
    $missingRates = [];
    foreach ($rateItems as $rateInfo) {
        $rateKey = (string) ($rateInfo['key'] ?? '');
        if ($rateKey === '') {
            continue;
        }
        $entry = $suggestedFromHistory[$rateKey] ?? [];
        if (empty($entry['general_account'])) {
            $missingRates[$rateKey] = true;
        }
    }
    if ($missingRates) {
        $extraExamples = fetchHistoryExamples($acquirerNif, $docType, 24, 'acquirer', $context);
        $extraSuggested = buildSuggestionsFromExamples($extraExamples, $rateItems);
        $suggestedFromHistory = mergeSuggestedAccounts($suggestedFromHistory, $extraSuggested);
    }
    $missingRates = [];
    foreach ($rateItems as $rateInfo) {
        $rateKey = (string) ($rateInfo['key'] ?? '');
        if ($rateKey === '') {
            continue;
        }
        $entry = $suggestedFromHistory[$rateKey] ?? [];
        if (empty($entry['general_account'])) {
            $missingRates[$rateKey] = true;
        }
    }
    if ($missingRates) {
        $extraExamples = fetchHistoryExamples($acquirerNif, $docType, 24, 'doctype', $context);
        $extraSuggested = buildSuggestionsFromExamples($extraExamples, $rateItems);
        $suggestedFromHistory = mergeSuggestedAccounts($suggestedFromHistory, $extraSuggested);
    }

    $finalSuggested = $suggestedFromHistory;
    $planAccounts = [];
    $planDb = '';
    $movementHints = ['general' => [], 'iva' => [], 'count' => 0];
    if ($erpBaseUrl !== '' && $erpToken !== '') {
        $planDb = getErpDatabaseForNif($acquirerNif) ?? '';
        if ($planDb !== '') {
            $year = date('Y');
            $movementHints = fetchErpMovementAccountHints($erpBaseUrl, $erpToken, $planDb, $docType, $acquirerNif);
            $planAccounts = fetchPlanAccounts($erpBaseUrl, $erpToken, $planDb, $year, $acquirerNif);
            if ($planAccounts) {
                $missingRates = [];
                foreach ($rateItems as $rateInfo) {
                    $rateKey = (string) ($rateInfo['key'] ?? '');
                    if ($rateKey === '') {
                        continue;
                    }
                    if (!isset($finalSuggested[$rateKey])) {
                        $finalSuggested[$rateKey] = ['iva_account' => '', 'general_account' => ''];
                    }
                    if (($finalSuggested[$rateKey]['general_account'] ?? '') === '') {
                        $ivaForRate = $finalSuggested[$rateKey]['iva_account'] ?? '';
                        $general = '';
                        if ($ivaForRate !== '') {
                            $general = pickGeneralAccountByIvaAccount($planAccounts, $ivaForRate);
                        }
                        if ($general === '') {
                            $general = pickGeneralAccountFromPlan($planAccounts, $rateInfo);
                        }
                        if ($general !== '') {
                            $finalSuggested[$rateKey]['general_account'] = $general;
                        } else {
                            $missingRates[] = $rateInfo;
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
                if ($missingRates) {
                    $globalPlan = fetchPlanAccounts($erpBaseUrl, $erpToken, $planDb, $year, '');
                    if ($globalPlan) {
                        foreach ($missingRates as $rateInfo) {
                            $rateKey = (string) ($rateInfo['key'] ?? '');
                            if ($rateKey === '') {
                                continue;
                            }
                            if (!isset($finalSuggested[$rateKey])) {
                                $finalSuggested[$rateKey] = ['iva_account' => '', 'general_account' => ''];
                            }
                            if (($finalSuggested[$rateKey]['general_account'] ?? '') === '') {
                                $ivaForRate = $finalSuggested[$rateKey]['iva_account'] ?? '';
                                $general = '';
                                if ($ivaForRate !== '') {
                                    $general = pickGeneralAccountByIvaAccount($globalPlan, $ivaForRate);
                                }
                                if ($general === '') {
                                    $general = pickGeneralAccountFromPlan($globalPlan, $rateInfo);
                                }
                                if ($general !== '') {
                                    $finalSuggested[$rateKey]['general_account'] = $general;
                                }
                            }
                            if (($finalSuggested[$rateKey]['iva_account'] ?? '') === '') {
                                $iva = '';
                                $generalSelected = $finalSuggested[$rateKey]['general_account'] ?? '';
                                if ($generalSelected !== '') {
                                    $iva = findIvaAccountForGeneral($globalPlan, $generalSelected);
                                }
                                if ($iva === '') {
                                    $iva = findIvaAccountForRate($globalPlan, $rateInfo);
                                }
                                if ($iva !== '') {
                                    $finalSuggested[$rateKey]['iva_account'] = $iva;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if (!empty($expectedLines['rates']) && is_array($expectedLines['rates'])) {
        foreach ($rateItems as $rateInfo) {
            $rateKey = (string) ($rateInfo['key'] ?? '');
            if ($rateKey === '') {
                continue;
            }
            if (!isset($finalSuggested[$rateKey])) {
                $finalSuggested[$rateKey] = ['iva_account' => '', 'general_account' => ''];
            }
            $expected = $expectedLines['rates'][$rateKey] ?? $expectedLines['rates'][normalizeRateKey($rateKey)] ?? null;
            if (is_array($expected)) {
                if (($finalSuggested[$rateKey]['general_account'] ?? '') === '' && !empty($expected['general_account'])) {
                    $finalSuggested[$rateKey]['general_account'] = (string) $expected['general_account'];
                }
                if (($finalSuggested[$rateKey]['iva_account'] ?? '') === '' && !empty($expected['iva_account'])) {
                    $finalSuggested[$rateKey]['iva_account'] = (string) $expected['iva_account'];
                }
            }
        }
    }

    if (!empty($movementHints['general']) || !empty($movementHints['iva'])) {
        $movementGeneral = is_array($movementHints['general']) ? $movementHints['general'] : [];
        $movementIva = is_array($movementHints['iva']) ? $movementHints['iva'] : [];
        foreach ($rateItems as $rateInfo) {
            $rateKey = (string) ($rateInfo['key'] ?? '');
            if ($rateKey === '') {
                continue;
            }
            if (!isset($finalSuggested[$rateKey])) {
                $finalSuggested[$rateKey] = ['iva_account' => '', 'general_account' => ''];
            }
            if (($finalSuggested[$rateKey]['general_account'] ?? '') === '' && !empty($movementGeneral)) {
                $finalSuggested[$rateKey]['general_account'] = (string) $movementGeneral[0];
            }
            if (($finalSuggested[$rateKey]['iva_account'] ?? '') === '' && !empty($movementIva)) {
                $finalSuggested[$rateKey]['iva_account'] = (string) $movementIva[0];
            }
        }
    }

    foreach ($rateItems as $rateInfo) {
        $rawKey = (string) ($rateInfo['key'] ?? '');
        if ($rawKey === '' && isset($rateInfo['label'])) {
            $rawKey = (string) $rateInfo['label'];
        }
        if ($rawKey === '') {
            continue;
        }
        $rateKey = normalizeRateKey($rawKey);
        if ($rateKey === '') {
            $rateKey = $rawKey;
        }
        foreach (array_unique([$rawKey, $rateKey]) as $keyVariant) {
            if ($keyVariant === '') {
                continue;
            }
            if (!isset($finalSuggested[$keyVariant])) {
                $finalSuggested[$keyVariant] = ['iva_account' => '', 'general_account' => ''];
            }
        }
        if (($finalSuggested[$rateKey]['general_account'] ?? '') === '') {
            $fallback = pickFallbackGeneralByRateKey($rateKey);
            if ($fallback !== '') {
                $finalSuggested[$rateKey]['general_account'] = $fallback;
                if ($rawKey !== $rateKey) {
                    $finalSuggested[$rawKey]['general_account'] = $fallback;
                }
            }
        }
    }
    return [
        'ok' => true,
        'suggested' => $finalSuggested,
        'history_count' => count($examples),
        'rule_count' => count($ruleExamples),
        'plan_db' => $planDb,
        'plan_accounts' => count($planAccounts),
        'expected_lines' => $expectedLines,
        'erp_movement_rows' => (int) ($movementHints['count'] ?? 0),
    ];
}

if ($action === 'log_feedback') {
    $logId = isset($payload['log_id']) ? (int) $payload['log_id'] : 0;
    $rating = isset($payload['rating']) ? (int) $payload['rating'] : null;
    $feedback = isset($payload['feedback']) ? trim((string) $payload['feedback']) : null;
    $category = isset($payload['category']) ? trim((string) $payload['category']) : null;
    $sources = isset($payload['sources']) && is_array($payload['sources']) ? $payload['sources'] : null;
    $accepted = isset($payload['accepted']) ? (int) $payload['accepted'] : null;
    $correctedAfter = isset($payload['corrected_after']) ? (int) $payload['corrected_after'] : null;
    $correctedAccounts = isset($payload['corrected_accounts']) && is_array($payload['corrected_accounts']) ? $payload['corrected_accounts'] : null;
    $suggestedAccounts = isset($payload['suggested_accounts']) && is_array($payload['suggested_accounts']) ? $payload['suggested_accounts'] : null;

    if (!hasTable('ai_assistant_logs')) {
        echo json_encode(['message' => 'Logs indisponiveis.', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $pdo = getPDO();
    if ($logId <= 0) {
        $stmt = $pdo->prepare('SELECT id FROM ai_assistant_logs WHERE user_id = ? AND session_id = ? ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$userId, $sessionId]);
        $logId = (int) ($stmt->fetchColumn() ?: 0);
    }
    if ($logId <= 0) {
        echo json_encode(['message' => 'Log nao encontrado.', 'csrf_token' => generateCsrfToken(true)]);
        exit;
    }
    $stmt = $pdo->prepare(
        'UPDATE ai_assistant_logs SET rating = COALESCE(?, rating), feedback = COALESCE(?, feedback), ' .
        'category = COALESCE(?, category), sources = COALESCE(?, sources), accepted = COALESCE(?, accepted), ' .
        'corrected_after = COALESCE(?, corrected_after), corrected_accounts = COALESCE(?, corrected_accounts), ' .
        'suggested_accounts = COALESCE(?, suggested_accounts) WHERE id = ?'
    );
    $stmt->execute([
        $rating !== null && $rating !== 0 ? $rating : null,
        $feedback !== '' ? $feedback : null,
        $category !== '' ? $category : null,
        $sources ? json_encode($sources, JSON_UNESCAPED_UNICODE) : null,
        $accepted !== null ? $accepted : null,
        $correctedAfter !== null ? $correctedAfter : null,
        $correctedAccounts ? json_encode($correctedAccounts, JSON_UNESCAPED_UNICODE) : null,
        $suggestedAccounts ? json_encode($suggestedAccounts, JSON_UNESCAPED_UNICODE) : null,
        $logId,
    ]);
    echo json_encode(['message' => 'Feedback registado.', 'csrf_token' => generateCsrfToken(true), 'log_id' => $logId]);
    exit;
}

if ($action === 'upload_attachment') {
    $filename = sanitizeAssistantFilename((string) ($payload['filename'] ?? 'anexo.bin'));
    $mimeType = trim((string) ($payload['mime_type'] ?? 'application/octet-stream'));
    $contentBase64 = (string) ($payload['content_base64'] ?? '');

    $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, getAssistantAllowedExtensions(), true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Extensao de ficheiro nao permitida.',
            'csrf_token' => generateCsrfToken(true),
        ]);
        exit;
    }

    $binaryData = decodeBase64Payload($contentBase64);
    if ($binaryData === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Conteudo do ficheiro invalido.',
            'csrf_token' => generateCsrfToken(true),
        ]);
        exit;
    }

    $size = strlen($binaryData);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'O ficheiro excede o limite de 10MB.',
            'csrf_token' => generateCsrfToken(true),
        ]);
        exit;
    }

    if (class_exists(finfo::class)) {
        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($binaryData);
        if (is_string($detected) && trim($detected) !== '') {
            $mimeType = trim($detected);
        }
    }

    $companySlug = getCompanySlug() ?: getConfiguredCompanySlug();
    $uploadDir = getAssistantUploadDirectory((string) $companySlug);
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Nao foi possivel criar o diretorio de anexos.',
            'csrf_token' => generateCsrfToken(true),
        ]);
        exit;
    }

    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $absolutePath = $uploadDir . $storedName;
    if (file_put_contents($absolutePath, $binaryData) === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Nao foi possivel guardar o ficheiro.',
            'csrf_token' => generateCsrfToken(true),
        ]);
        exit;
    }

    $relativePath = str_replace('\\', '/', 'uploads/' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $companySlug) . '/assistant/' . date('Y') . '/' . date('m') . '/' . $storedName);
    $attachmentId = bin2hex(random_bytes(8));
    $attachment = [
        'id' => $attachmentId,
        'filename' => $filename,
        'mime_type' => $mimeType,
        'size' => $size,
        'path' => $relativePath,
        'url' => BASE_URL . ltrim($relativePath, '/'),
        'excerpt' => extractAssistantAttachmentExcerpt($mimeType, $binaryData),
        'uploaded_at' => date('Y-m-d H:i:s'),
    ];

    rememberAssistantAttachment($sessionId, $attachment);
    logAuditAction('ai_upload_attachment', 'assistant_attachment', null, [
        'session' => $sessionId,
        'path' => $relativePath,
        'size' => $size,
        'mime_type' => $mimeType,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Anexo carregado com sucesso.',
        'attachment' => $attachment,
        'csrf_token' => generateCsrfToken(true),
    ]);
    exit;
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
    $sources = [];
    if (!empty($result['history_count'])) {
        $sources[] = 'mysql_history';
    }
    if (!empty($result['rule_count'])) {
        $sources[] = 'mysql_classification_rules';
    }
    if (!empty($result['plan_db'])) {
        $sources[] = 'erp_planocontas';
    }
    if (!empty($result['erp_movement_rows'])) {
        $sources[] = 'erp_movimentos';
    }
    $logId = logAiInteraction($userId, $sessionId, 'Sugestao de contas', [['type' => 'suggest_accounts']], 'suggest_accounts', $sources, $suggested);
    $expectedLines = is_array($result['expected_lines'] ?? null) ? $result['expected_lines'] : [];
    $totalAccountSuggestion = trim((string) ($expectedLines['total_account'] ?? ''));
    echo json_encode([
        'message' => json_encode([
            'rates' => $suggested,
            'total_account' => $totalAccountSuggestion,
        ], JSON_UNESCAPED_UNICODE),
        'csrf_token' => generateCsrfToken(true),
        'expected_lines' => $expectedLines,
        'total_account' => $totalAccountSuggestion,
        'actions' => [
            [
                'type' => 'suggest_accounts',
                'history' => $result['history_count'] ?? 0,
                'rules' => $result['rule_count'] ?? 0,
                'plan_db' => $result['plan_db'] ?? '',
                'erp_movimentos' => $result['erp_movement_rows'] ?? 0,
                'total_account' => $totalAccountSuggestion,
                'log_id' => $logId
            ],
        ],
        'log_id' => $logId,
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
                    $actions[] = [
                        'type' => 'suggest_accounts',
                        'history' => $toolResult['history_count'] ?? 0,
                        'rules' => $toolResult['rule_count'] ?? 0,
                        'plan_db' => $toolResult['plan_db'] ?? '',
                        'erp_movimentos' => $toolResult['erp_movement_rows'] ?? 0,
                    ];
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

            case 'read_php_function':
                $functionName = trim((string) ($args['function_name'] ?? ''));
                $fileHint = trim((string) ($args['file_hint'] ?? ''));
                if ($functionName === '') {
                    $toolResult = ['ok' => false, 'error' => 'function_name obrigatorio.'];
                    break;
                }
                $toolResult = readPhpFunctionFromProject(__DIR__, $functionName, $fileHint);
                if (!empty($toolResult['ok'])) {
                    $actions[] = [
                        'type' => 'read_php_function',
                        'function_name' => $functionName,
                        'file' => $toolResult['file'] ?? '',
                    ];
                }
                break;

            case 'read_uploaded_document':
                $attachmentId = trim((string) ($args['attachment_id'] ?? ''));
                $attachmentName = trim((string) ($args['attachment_name'] ?? ''));
                $maxChars = isset($args['max_chars']) ? (int) $args['max_chars'] : 5000;
                $attachment = null;
                if ($attachmentId !== '') {
                    $attachment = findAssistantAttachmentById($sessionId, $attachmentId);
                }
                if (!$attachment && $attachmentName !== '') {
                    $attachment = findAssistantAttachmentByFilename($sessionId, $attachmentName);
                }
                if (!$attachment && !empty($resolvedAttachments) && is_array($resolvedAttachments)) {
                    $lastAttachment = end($resolvedAttachments);
                    if (is_array($lastAttachment)) {
                        $attachment = $lastAttachment;
                    }
                }
                if (!$attachment) {
                    $recentAttachments = $_SESSION['ai_recent_attachments'] ?? [];
                    if (is_array($recentAttachments) && !empty($recentAttachments)) {
                        $lastRecent = end($recentAttachments);
                        if (is_array($lastRecent)) {
                            $attachment = $lastRecent;
                        }
                    }
                }
                if (!$attachment) {
                    $toolResult = ['ok' => false, 'error' => 'Anexo nao encontrado na sessao atual nem na lista recente.'];
                    break;
                }
                $readerResult = readAssistantAttachmentWithPython($attachment, $maxChars);
                if (!empty($readerResult['ok'])) {
                    $structuredSummary = buildDocumentReaderStructuredSummary($readerResult);
                    $issuerInfo = resolvePartyNameWithAppTools((string) ($structuredSummary['issuer_nif'] ?? ''), $erpBaseUrl, $erpToken);
                    $buyerInfo = resolvePartyNameWithAppTools((string) ($structuredSummary['buyer_nif'] ?? ''), $erpBaseUrl, $erpToken);
                    if (($issuerInfo['name'] ?? '') !== '') {
                        $structuredSummary['issuer_name'] = (string) $issuerInfo['name'];
                    }
                    if (($buyerInfo['name'] ?? '') !== '') {
                        $structuredSummary['buyer_name'] = (string) $buyerInfo['name'];
                    }
                    if (($buyerInfo['erp_database'] ?? '') !== '') {
                        $structuredSummary['buyer_erp_database'] = (string) $buyerInfo['erp_database'];
                    }
                    $workflowHint = buildAccountingImportWorkflowHint($structuredSummary);
                    $toolResult = [
                        'ok' => true,
                        'attachment' => [
                            'id' => (string) ($attachment['id'] ?? ''),
                            'filename' => (string) ($attachment['filename'] ?? ''),
                            'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                            'size' => (int) ($attachment['size'] ?? 0),
                        ],
                        'extraction' => $readerResult,
                        'structured_summary' => $structuredSummary,
                        'parties' => [
                            'issuer' => $issuerInfo,
                            'buyer' => $buyerInfo,
                        ],
                    ];
                    if (is_array($workflowHint)) {
                        $toolResult['workflow_hint'] = $workflowHint;
                    }
                    if (is_array($workflowHint) && !empty($workflowHint['should_ask_import'])) {
                        setPendingAccountingImportIntent($sessionId, [
                            'attachment_id' => (string) ($attachment['id'] ?? ''),
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                    } else {
                        clearPendingAccountingImportIntent($sessionId);
                    }
                    $actions[] = [
                        'type' => 'read_uploaded_document',
                        'attachment_id' => (string) ($attachment['id'] ?? ''),
                        'method' => (string) ($readerResult['method'] ?? ''),
                        'parties' => [
                            'issuer' => $issuerInfo,
                            'buyer' => $buyerInfo,
                        ],
                        'ask_import' => is_array($workflowHint) && !empty($workflowHint['should_ask_import']) ? 1 : 0,
                    ];
                } else {
                    clearPendingAccountingImportIntent($sessionId);
                    $diagnostics = buildDocumentReaderHints($readerResult);
                    $toolResult = [
                        'ok' => false,
                        'error' => (string) ($readerResult['error'] ?? 'Falha ao ler anexo.'),
                        'attachment_id' => (string) ($attachment['id'] ?? ''),
                        'attachment' => [
                            'filename' => (string) ($attachment['filename'] ?? ''),
                            'mime_type' => (string) ($attachment['mime_type'] ?? ''),
                            'size' => (int) ($attachment['size'] ?? 0),
                        ],
                        'diagnostics' => $diagnostics,
                        'details' => $readerResult,
                    ];
                }
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
                $dbHint = trim((string) ($query['db'] ?? ''));
                $endpoint = buildErpGetEndpoint($erpBaseUrl, '/contabilidade/movimentos', $query, $dbHint);
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
                $dbHint = trim((string) ($query['db'] ?? ''));
                $endpoint = buildErpGetEndpoint($erpBaseUrl, '/contabilidade/planocontas', $query, $dbHint);
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
                $endpoint = buildErpGetEndpoint($erpBaseUrl, '/contabilidade/taxonomias', ['db' => $db], $db);
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

            case 'erp_clientes_search':
                if ($erpBaseUrl === '' || $erpToken === '') {
                    $toolResult = ['ok' => false, 'error' => 'ERP nao configurado.'];
                    break;
                }
                $query = [];
                foreach (['db', 'q', 'searchField', 'limit', 'offset'] as $key) {
                    if (isset($args[$key]) && $args[$key] !== '') {
                        $query[$key] = $args[$key];
                    }
                }
                if (!isset($query['limit'])) {
                    $query['limit'] = 20;
                }
                if (!isset($query['offset'])) {
                    $query['offset'] = 0;
                }
                $dbHint = trim((string) ($query['db'] ?? ''));
                $endpoint = buildErpGetEndpoint($erpBaseUrl, '/clientes', $query, $dbHint);
                $erpResponse = callErpWebservice($endpoint, $erpToken);
                if (!$erpResponse['ok']) {
                    $toolResult = ['ok' => false, 'status' => $erpResponse['status'] ?? 0, 'error' => $erpResponse['error'] ?? 'Erro ERP'];
                    break;
                }
                $toolResult = ['ok' => true, 'endpoint' => $endpoint, 'data' => $erpResponse['data']];
                $actions[] = ['type' => 'erp_clientes_search'];
                break;

            case 'erp_fornecedores_search':
                if ($erpBaseUrl === '' || $erpToken === '') {
                    $toolResult = ['ok' => false, 'error' => 'ERP nao configurado.'];
                    break;
                }
                $query = [];
                foreach (['db', 'q', 'searchField', 'limit', 'offset'] as $key) {
                    if (isset($args[$key]) && $args[$key] !== '') {
                        $query[$key] = $args[$key];
                    }
                }
                if (!isset($query['limit'])) {
                    $query['limit'] = 20;
                }
                if (!isset($query['offset'])) {
                    $query['offset'] = 0;
                }
                $dbHint = trim((string) ($query['db'] ?? ''));
                $endpoint = buildErpGetEndpoint($erpBaseUrl, '/fornecedores', $query, $dbHint);
                $erpResponse = callErpWebservice($endpoint, $erpToken);
                if (!$erpResponse['ok']) {
                    $toolResult = ['ok' => false, 'status' => $erpResponse['status'] ?? 0, 'error' => $erpResponse['error'] ?? 'Erro ERP'];
                    break;
                }
                $toolResult = ['ok' => true, 'endpoint' => $endpoint, 'data' => $erpResponse['data']];
                $actions[] = ['type' => 'erp_fornecedores_search'];
                break;

            case 'erp_exercicios_list':
                if ($erpBaseUrl === '' || $erpToken === '') {
                    $toolResult = ['ok' => false, 'error' => 'ERP nao configurado.'];
                    break;
                }
                $query = [];
                foreach (['limit', 'offset', 'order', 'dtmInicio', 'dtmFim'] as $key) {
                    if (isset($args[$key]) && $args[$key] !== '') {
                        $query[$key] = $args[$key];
                    }
                }
                if (!isset($query['limit'])) {
                    $query['limit'] = 20;
                }
                if (!isset($query['offset'])) {
                    $query['offset'] = 0;
                }
                $endpoint = buildErpGetEndpoint($erpBaseUrl, '/tabelas/exercicios', $query, '');
                $erpResponse = callErpWebservice($endpoint, $erpToken);
                if (!$erpResponse['ok']) {
                    $toolResult = ['ok' => false, 'status' => $erpResponse['status'] ?? 0, 'error' => $erpResponse['error'] ?? 'Erro ERP'];
                    break;
                }
                $toolResult = ['ok' => true, 'endpoint' => $endpoint, 'data' => $erpResponse['data']];
                $actions[] = ['type' => 'erp_exercicios_list'];
                break;

            case 'erp_empresas_list':
                if ($erpBaseUrl === '' || $erpToken === '') {
                    $toolResult = ['ok' => false, 'error' => 'ERP nao configurado.'];
                    break;
                }
                $endpoint = buildErpGetEndpoint($erpBaseUrl, '/contabilidade/listDBemp', [], '');
                $erpResponse = callErpWebservice($endpoint, $erpToken);
                if (!$erpResponse['ok']) {
                    $toolResult = ['ok' => false, 'status' => $erpResponse['status'] ?? 0, 'error' => $erpResponse['error'] ?? 'Erro ERP'];
                    break;
                }
                $toolResult = ['ok' => true, 'endpoint' => $endpoint, 'data' => $erpResponse['data']];
                $actions[] = ['type' => 'erp_empresas_list'];
                break;

            case 'erp_api_get':
                if ($erpBaseUrl === '' || $erpToken === '') {
                    $toolResult = ['ok' => false, 'error' => 'ERP nao configurado.'];
                    break;
                }
                $path = trim((string) ($args['path'] ?? ''));
                if ($path === '' || $path[0] !== '/') {
                    $toolResult = ['ok' => false, 'error' => 'path invalido. Use formato /endpoint.' ];
                    break;
                }
                $allowedPrefixes = ['/clientes', '/fornecedores', '/contabilidade', '/tabelas', '/artigos', '/stocks', '/compras', '/vendas', '/encomendas', '/referencias'];
                $allowed = false;
                foreach ($allowedPrefixes as $prefix) {
                    if (strpos($path, $prefix) === 0) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) {
                    $toolResult = ['ok' => false, 'error' => 'Endpoint fora dos caminhos permitidos.'];
                    break;
                }
                $query = isset($args['params']) && is_array($args['params']) ? $args['params'] : [];
                $dbHint = trim((string) ($args['db'] ?? ($query['db'] ?? '')));
                if ($dbHint !== '' && !isset($query['db'])) {
                    $query['db'] = $dbHint;
                }
                $endpoint = buildErpGetEndpoint($erpBaseUrl, $path, $query, $dbHint);
                $erpResponse = callErpWebservice($endpoint, $erpToken);
                if (!$erpResponse['ok']) {
                    $toolResult = ['ok' => false, 'status' => $erpResponse['status'] ?? 0, 'error' => $erpResponse['error'] ?? 'Erro ERP'];
                    break;
                }
                $toolResult = ['ok' => true, 'endpoint' => $endpoint, 'data' => $erpResponse['data']];
                $actions[] = ['type' => 'erp_api_get', 'path' => $path];
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

$lastDocumentReadAction = null;
for ($idx = count($actions) - 1; $idx >= 0; $idx--) {
    $candidateAction = $actions[$idx] ?? null;
    if (!is_array($candidateAction)) {
        continue;
    }
    if (($candidateAction['type'] ?? '') === 'read_uploaded_document') {
        $lastDocumentReadAction = $candidateAction;
        break;
    }
}

if (is_array($lastDocumentReadAction) && !isLikelyJsonMessage($finalMessage)) {
    $issuerBuyerHeader = buildIssuerBuyerHeader($lastDocumentReadAction);
    if ($issuerBuyerHeader !== '') {
        $lowerMessage = strtolower($finalMessage);
        if (strpos($lowerMessage, 'emitente:') === false && strpos($lowerMessage, 'adquirente:') === false) {
            $finalMessage = $issuerBuyerHeader . "\n\n" . ltrim($finalMessage);
        }
    }
}

if (!isLikelyJsonMessage($finalMessage)) {
    $lowerFinal = strtolower($finalMessage);
    $asksForId = strpos($lowerFinal, 'id do documento') !== false
        || strpos($lowerFinal, 'id na base de dados') !== false;
    if ($asksForId) {
        $finalMessage = "Para avançar com este fluxo, nao preciso do ID nesta fase.\n\n"
            . "Se quiser, posso importar automaticamente o ficheiro pelo fluxo de Upload e enviar para Classificacao.\n\n"
            . "Menu: Contabilidade > Classificacao\n"
            . "Link: " . BASE_URL . "contabilidade/classificacao-importacao?import_type=1\n\n"
            . "Pretende que eu avance ja com a importacao? (Sim/Não)";
    }
}

if (is_array($lastDocumentReadAction) && !isLikelyJsonMessage($finalMessage)) {
    $mustAskImport = !empty($lastDocumentReadAction['ask_import']) && (int) $lastDocumentReadAction['ask_import'] === 1;
    if ($mustAskImport) {
        $question = 'Pretende importar já para Classificação? (Sim/Não)';
        if (stripos($finalMessage, $question) === false) {
            $trimmed = rtrim($finalMessage);
            if ($trimmed !== '') {
                $finalMessage = $trimmed . "\n\n" . $question;
            } else {
                $finalMessage = $question;
            }
        }
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

$loggedActions = $actions;
$loggedActions[] = [
    'type' => 'chat_exchange',
    'user_message' => $message,
    'assistant_message' => $finalMessage,
];
logAiInteraction($userId, $sessionId, $summary, $loggedActions, 'chat', $actions ? ['chat'] : []);

$taskMemorySummary = buildAccountingTaskMemorySummary($message, $actions);
if ($taskMemorySummary !== '' && !isForgetOrWrongIntent($message)) {
    saveAccountingTaskMemory($userId, $sessionId, $taskMemorySummary);
}

logAuditAction('ai_assistant', 'assistant', null, ['session' => $sessionId]);

$newToken = generateCsrfToken(true);
echo json_encode([
    'message' => $finalMessage,
    'csrf_token' => $newToken,
    'actions' => $actions,
]);
