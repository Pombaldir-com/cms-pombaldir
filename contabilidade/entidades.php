<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

if (!isModuleActive('contabilidade')) {
    http_response_code(404);
    exit('Modulo nao ativo.');
}

$useDataTables = true;
$useSwitchery = true;
$useSelect2 = true;

$pdo = getPDO();
$emitterTypeColumn = getAccountingEmitterTypeColumn();
$hasEmitterTypeColumn = $emitterTypeColumn !== '';
$user = currentUser();
$isSuperAdmin = ((int) ($user['role'] ?? 3)) === 1;
$canManageEntityAiInstructions = ((int) ($user['role'] ?? 3)) <= 2;
$canManageEmitterType = ((int) ($user['role'] ?? 3)) <= 2;
$canManageClientExtranet = ((int) ($user['role'] ?? 3)) <= 2;
$canManageClientAdmin = ((int) ($user['role'] ?? 3)) <= 2;
// Permite guardar alteracoes na ficha da empresa/entidade. Admins (role <= 2)
// passam sempre via userHasDepartmentPermission; nao-admins precisam da
// permissao de departamento "Entidades - Editar".
$canEditEntities = userHasDepartmentPermission('entidades_editar');
// Ver o separador "Campos Adicionais" (inclui campos do tipo senha) e uma
// permissao base atribuida a todos os tecnicos pelo departamento "Tecnico
// (Base)" (ver getBaselineDepartmentId() em functions.php).
$canViewAdditionalFields = userHasDepartmentPermission('entidades_campos_adicionais_ver');
$adminTaskDefinitions = getAccountingEntityAdminTaskDefinitions();
$typeSlug = trim((string) ($_GET['tipo'] ?? 'empresas'));
$typeSlug = $typeSlug !== '' ? $typeSlug : 'empresas';
$entityTypeMap = [
    'empresas' => 'acquirer',
];
$entityType = $entityTypeMap[$typeSlug] ?? $typeSlug;
$supplierCompanyRouteKey = trim((string) ($_GET['fornecedores'] ?? ''));
$isSupplierList = $typeSlug === 'empresas' && $supplierCompanyRouteKey !== '';
$csrfToken = generateCsrfToken();

$flashType = trim((string) ($_GET['status'] ?? ''));
$flashMessage = trim((string) ($_GET['msg'] ?? ''));
$pageScripts = '';
$erpClientFormOverride = null;
$erpClientDatabase = '';
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strcasecmp((string) $_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['csrf_token'] ?? ''));
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        exit('Token invalido.');
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save-ai-instructions') {
        header('Content-Type: application/json; charset=utf-8');

        if (!$canManageEntityAiInstructions) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Sem permissoes para guardar instrucoes IA.', 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$isSupplierList) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Contexto de fornecedores invalido.', 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!hasTable('accounting_entity_ai_instructions')) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Tabela de instrucoes IA em falta. Execute as migracoes.', 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $emitterNif = extractVatNumber((string) ($_POST['emitter_nif'] ?? ''));
        $instructions = trim((string) ($_POST['instructions'] ?? ''));

        $company = findAccountingEntityByRouteKey($pdo, $supplierCompanyRouteKey, 'acquirer');
        $acquirerNif = $company ? extractVatNumber((string) ($company['nif'] ?? '')) : '';
        if ($acquirerNif === '' || $emitterNif === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Emitente/adquirente invalido.', 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($instructions === '') {
            $stmt = $pdo->prepare('DELETE FROM accounting_entity_ai_instructions WHERE acquirer_nif = ? AND emitter_nif = ?');
            $stmt->execute([$acquirerNif, $emitterNif]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO accounting_entity_ai_instructions (acquirer_nif, emitter_nif, instructions)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE instructions = VALUES(instructions), updated_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$acquirerNif, $emitterNif, $instructions]);
        }

        logAuditAction('update', 'accounting_entity_ai_instructions', null, [
            'acquirer_nif' => $acquirerNif,
            'emitter_nif' => $emitterNif,
            'has_instructions' => $instructions !== '' ? 1 : 0,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Instrucoes IA guardadas.',
            'instructions' => $instructions,
            'csrf_token' => generateCsrfToken(true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update-erp-client-details') {
        if (!$canEditEntities) {
            $denyMessage = 'Sem permissoes para guardar alteracoes na entidade.';
            if ($isAjaxRequest) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => $denyMessage, 'csrf_token' => generateCsrfToken(true)], JSON_UNESCAPED_UNICODE);
                exit;
            }
            http_response_code(403);
            exit($denyMessage);
        }
        $entityId = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
        $erpRecordId = isset($_POST['erp_record_id']) ? (int) $_POST['erp_record_id'] : 0;
        $returnUrl = BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug);
        $extranetSettingsSaved = false;

        $erpClientFormOverride = [
            'id' => $erpRecordId > 0 ? (string) $erpRecordId : '',
            'nif' => trim((string) ($_POST['nif'] ?? '')),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'number' => trim((string) ($_POST['number'] ?? '')),
            'address' => trim((string) ($_POST['address'] ?? '')),
            'address2' => trim((string) ($_POST['address2'] ?? '')),
            'postal_code' => trim((string) ($_POST['postal_code'] ?? '')),
            'city' => trim((string) ($_POST['city'] ?? '')),
            'zone' => strtoupper(trim((string) ($_POST['zone'] ?? ''))),
            'subzone' => strtoupper(trim((string) ($_POST['subzone'] ?? ''))),
            'phone' => trim((string) ($_POST['phone'] ?? '')),
            'mobile' => trim((string) ($_POST['mobile'] ?? '')),
            'email' => trim((string) ($_POST['email'] ?? '')),
            'tec' => trim((string) ($_POST['tec'] ?? '')),
        ];

        if ($entityId <= 0) {
            $flashType = 'error';
            $flashMessage = 'Entidade invalida.';
        } else {
            $stmt = $pdo->prepare(
                'SELECT ' . appendAccountingEntityUuidSelectColumn('id, nif, name, erp_database, erp_client_code, entity_type') . ' FROM accounting_entities WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$entityId]);
            $entity = ensureAccountingEntityRouteRow($pdo, $stmt->fetch(PDO::FETCH_ASSOC) ?: null);
            if ($entity) {
                $returnUrl .= '/' . rawurlencode(getAccountingEntityRouteKey($entity));
            }

            if (!$entity) {
                $flashType = 'error';
                $flashMessage = 'Entidade nao encontrada.';
            } elseif (($entity['entity_type'] ?? '') !== 'acquirer') {
                $flashType = 'error';
                $flashMessage = 'Tipo de entidade invalido.';
            } else {
                if ($canManageClientExtranet && hasAccountingEntityExtranetSettingsTable()) {
                    try {
                        saveAccountingEntityExtranetSettings($entityId, [
                            'erp_software' => trim((string) ($_POST['erp_software'] ?? '')),
                            'erp_api_url' => trim((string) ($_POST['erp_api_url'] ?? '')),
                            'erp_api_username' => trim((string) ($_POST['erp_api_username'] ?? '')),
                            'erp_api_password' => trim((string) ($_POST['erp_api_password'] ?? '')),
                            'erp_api_token' => trim((string) ($_POST['erp_api_token'] ?? '')),
                            'support_enabled' => (string) ($_POST['support_enabled'] ?? '0') === '1' ? 1 : 0,
                            'support_user_id' => (int) ($_POST['support_user_id'] ?? 0),
                        ]);
                        $extranetSettingsSaved = true;
                    } catch (Throwable $e) {
                        $flashType = 'error';
                        $flashMessage = 'Falha ao guardar configuracoes Extranet: ' . $e->getMessage();
                    }
                }

                if ($flashType === 'error' && $flashMessage !== '') {
                    // Extranet save failed; do not continue with ERP update in this request.
                } elseif ($erpRecordId <= 0) {
                    if ($extranetSettingsSaved) {
                        $successMessage = 'Configuracoes Extranet guardadas.';
                        if ($isAjaxRequest) {
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode([
                                'success' => true,
                                'message' => $successMessage,
                                'csrf_token' => generateCsrfToken(true),
                                'replace_url' => $returnUrl,
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                        header('Location: ' . $returnUrl . '?status=success&msg=' . rawurlencode($successMessage));
                        exit;
                    }
                    $flashType = 'error';
                    $flashMessage = 'ID do cliente ERP em falta.';
                } else {
                $submittedErpDatabase = normalizeAccountingEntityDatabaseKey((string) ($_POST['erp_database'] ?? ''));
                $entityErpDatabase = normalizeAccountingEntityDatabaseKey((string) ($entity['erp_database'] ?? ''));
                $erpDatabase = $submittedErpDatabase !== '' ? $submittedErpDatabase : $entityErpDatabase;
                if ($erpDatabase === '') {
                    $flashType = 'error';
                    $flashMessage = 'A empresa nao tem base de dados ERP configurada.';
                } else {
                    $payload = [
                        'strMorada_lin1' => $erpClientFormOverride['address'],
                        'strMorada_lin2' => $erpClientFormOverride['address2'],
                        'strPostal' => $erpClientFormOverride['postal_code'],
                        'strLocalidade' => $erpClientFormOverride['city'],
                        'strAbrevSubZona' => $erpClientFormOverride['subzone'],
                        'strTelefone' => $erpClientFormOverride['phone'],
                        'strTelemovel' => $erpClientFormOverride['mobile'],
                        'strEmail' => $erpClientFormOverride['email'],
                    ];

                    $updateResponse = updateErpClientDetails($erpRecordId, $payload, $erpDatabase);
                    if (!empty($updateResponse['success'])) {
                        $additionalFields = getAccountingAdditionalFields('client');
                        foreach ($additionalFields as $additionalField) {
                            $additionalFieldId = (int) ($additionalField['id'] ?? 0);
                            if ($additionalFieldId <= 0) {
                                continue;
                            }
                            $rawValue = normalizeAccountingAdditionalFieldSubmittedValue(
                                $additionalField,
                                $_POST['additional_fields'][$additionalFieldId] ?? null
                            );
                            saveAccountingEntityAdditionalValue($entityId, $additionalFieldId, $rawValue);
                        }

                        logAuditAction('update', 'accounting_entity_erp_client', $entityId, [
                            'erp_record_id' => $erpRecordId,
                            'erp_database' => $erpDatabase,
                            'fields' => array_keys($payload),
                            'additional_field_count' => count($additionalFields),
                        ]);

                        $successMessage = 'Dados do cliente atualizados com sucesso.';
                        $responseData = $updateResponse['data'] ?? null;
                        if (is_array($responseData) && !empty($responseData['message']) && is_scalar($responseData['message'])) {
                            $successMessage = trim((string) $responseData['message']);
                        }

                        if ($isAjaxRequest) {
                            header('Content-Type: application/json; charset=utf-8');
                            echo json_encode([
                                'success' => true,
                                'message' => $successMessage,
                                'csrf_token' => generateCsrfToken(true),
                                'replace_url' => $returnUrl,
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        }

                        header('Location: ' . $returnUrl . '?status=success&msg=' . rawurlencode($successMessage));
                        exit;
                    }

                    $flashType = 'error';
                    $flashMessage = trim((string) ($updateResponse['error'] ?? 'Falha ao atualizar o cliente no ERP.'));
                }
            }
            }
        }

        if ($isAjaxRequest && $flashMessage !== '') {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $flashMessage,
                'csrf_token' => generateCsrfToken(true),
                'replace_url' => $returnUrl,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if ($action === 'save-client-user') {
        $returnUrl = normalizeRedirectTarget((string) ($_POST['return_url'] ?? ''));
        if ($returnUrl === null) {
            $returnUrl = buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey);
        }
        if (!$canManageClientExtranet) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Sem permissoes para gerir extranet.', 403);
        }

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $clientUserId = (int) ($_POST['client_user_id'] ?? 0);
        $username = trim((string) ($_POST['username'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($entityId <= 0) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Entidade invalida.', 400);
        }
        $entityStmt = $pdo->prepare('SELECT id, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $entityStmt->execute([$entityId]);
        $entityRow = $entityStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entityRow || ($entityRow['entity_type'] ?? '') !== 'acquirer') {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Apenas entidades adquirentes suportam extranet.', 400);
        }
        $tenantSlug = trim((string) (getCompanySlug() ?? ''));
        if ($tenantSlug === '') {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Tenant sem slug valida.', 400);
        }

        if ($clientUserId <= 0 && $username === '') {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Utilizador e obrigatorio.', 400);
        }
        if ($clientUserId <= 0 && $password === '') {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Password e obrigatoria ao criar conta.', 400);
        }
        if ($password !== '' && !isStrongPassword($password)) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Password fraca. Min 8 chars com maiusculas, minusculas e numero.', 400);
        }

        $existing = null;
        $clientUser = null;
        try {
            if ($clientUserId > 0) {
                $existing = getClientUserById($clientUserId);
                if (!$existing || (int) ($existing['accounting_entity_id'] ?? 0) !== $entityId) {
                    throw new RuntimeException('Conta cliente nao encontrada para esta entidade.');
                }
                $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
                updateClientUser($clientUserId, $hash, $name, $email, $isActive);
                $clientUser = getClientUserById($clientUserId);
                $message = 'Conta cliente atualizada.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $clientUserId = createClientUser($entityId, $tenantSlug, $username, $hash, $name, $email, $isActive);
                $clientUser = getClientUserById($clientUserId);
                $message = 'Conta cliente criada.';
            }
        } catch (Throwable $e) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Falha ao guardar conta cliente: ' . $e->getMessage(), 400);
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => $message,
                'csrf_token' => generateCsrfToken(true),
                'client_user' => $clientUser ?? null,
                'operation' => $existing ? 'update' : 'create',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        respondAccountingEntitiesPost(false, $returnUrl, 'success', $message);
    }

    if ($action === 'delete-client-user') {
        $returnUrl = normalizeRedirectTarget((string) ($_POST['return_url'] ?? ''));
        if ($returnUrl === null) {
            $returnUrl = buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey);
        }
        if (!$canManageClientExtranet) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Sem permissoes para gerir extranet.', 403);
        }

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $clientUserId = (int) ($_POST['client_user_id'] ?? 0);
        if ($entityId <= 0 || $clientUserId <= 0) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Dados invalidos.', 400);
        }

        $entityStmt = $pdo->prepare('SELECT id, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $entityStmt->execute([$entityId]);
        $entityRow = $entityStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entityRow || ($entityRow['entity_type'] ?? '') !== 'acquirer') {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Apenas entidades adquirentes suportam extranet.', 400);
        }

        $existing = getClientUserById($clientUserId);
        if (!$existing || (int) ($existing['accounting_entity_id'] ?? 0) !== $entityId) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Conta cliente nao encontrada para esta entidade.', 404);
        }

        $existing = getClientUserById($clientUserId);

        try {
            deleteClientUser($clientUserId);
        } catch (Throwable $e) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Falha ao eliminar conta cliente: ' . $e->getMessage(), 400);
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Conta cliente eliminada.',
                'csrf_token' => generateCsrfToken(true),
                'client_user' => $existing,
                'operation' => 'delete',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: ' . $returnUrl . '?status=success&msg=' . rawurlencode('Conta cliente eliminada.'));
        exit;
    }

    if ($action === 'impersonate-client-user') {
        $returnUrl = normalizeRedirectTarget((string) ($_POST['return_url'] ?? ''));
        if ($returnUrl === null) {
            $returnUrl = buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey);
        }
        if (!$canManageClientExtranet) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Sem permissoes para impersonar utilizadores.', 403);
        }

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $clientUserId = (int) ($_POST['client_user_id'] ?? 0);
        if ($entityId <= 0 || $clientUserId <= 0) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Dados invalidos.', 400);
        }

        $entityStmt = $pdo->prepare('SELECT id, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $entityStmt->execute([$entityId]);
        $entityRow = $entityStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entityRow || ($entityRow['entity_type'] ?? '') !== 'acquirer') {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Apenas entidades adquirentes suportam extranet.', 400);
        }

        $existing = getClientUserById($clientUserId);
        if (!$existing || (int) ($existing['accounting_entity_id'] ?? 0) !== $entityId) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Conta cliente nao encontrada para esta entidade.', 404);
        }
        if ((int) ($existing['is_active'] ?? 0) !== 1) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Conta cliente inativa; nao e possivel impersonar.', 400);
        }

        $impersonated = startClientImpersonation($clientUserId, (int) ($user['id'] ?? 0));
        if (!$impersonated) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Falha ao iniciar a impersonacao.', 400);
        }

        $_SESSION['client_impersonator_return_url'] = $returnUrl;
        $tenantSlug = trim((string) ($impersonated['tenant_slug'] ?? ''));
        header('Location: ' . BASE_URL . 't/' . rawurlencode($tenantSlug) . '/cliente/dashboard');
        exit;
    }

    if ($action === 'save-client-admin-task-users') {
        $returnUrl = normalizeRedirectTarget((string) ($_POST['return_url'] ?? ''));
        if ($returnUrl === null) {
            $returnUrl = buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey);
        }
        if (!$canManageClientAdmin) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Sem permissoes para gerir Admin.', 403);
        }
        if (!hasAccountingEntityAdminTaskPermissionsTable()) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Tabela de permissões Admin em falta.', 400);
        }

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        $permissionKey = trim((string) ($_POST['permission_key'] ?? ''));
        $userIds = $_POST['user_ids'] ?? [];
        if ($entityId <= 0) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Entidade invalida.', 400);
        }
        if (!isset($adminTaskDefinitions[$permissionKey])) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Tarefa administrativa invalida.', 400);
        }
        $entityStmt = $pdo->prepare('SELECT id, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $entityStmt->execute([$entityId]);
        $entityRow = $entityStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entityRow || ($entityRow['entity_type'] ?? '') !== 'acquirer') {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Apenas entidades adquirentes suportam Admin.', 400);
        }

        if (!is_array($userIds)) {
            $userIds = [$userIds];
        }
        $userIds = array_values(array_filter(array_map('intval', $userIds), static fn($value) => $value > 0));
        foreach ($userIds as $userId) {
            if (!getUserById($userId)) {
                respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Um dos utilizadores selecionados nao foi encontrado.', 404);
            }
        }

        try {
            saveAccountingEntityAdminTaskPermissions($entityId, $permissionKey, $userIds);
        } catch (Throwable $e) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Falha ao guardar permissões Admin: ' . $e->getMessage(), 400);
        }

        $assignedUsers = [];
        foreach ($userIds as $userId) {
            $userRow = getUserById($userId);
            if (!$userRow) {
                continue;
            }
            $assignedUsers[] = [
                'id' => (int) ($userRow['id'] ?? $userId),
                'label' => trim((string) (($userRow['name'] ?? '') !== '' ? $userRow['name'] : ($userRow['username'] ?? ''))),
            ];
        }

        if ($isAjaxRequest) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'message' => 'Permissões Admin guardadas.',
                'csrf_token' => generateCsrfToken(true),
                'permission_key' => $permissionKey,
                'assigned_users' => $assignedUsers,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'success', 'Permissões Admin guardadas.');
    }

    if ($action === 'save-client-extranet-settings') {
        $returnUrl = normalizeRedirectTarget((string) ($_POST['return_url'] ?? ''));
        if ($returnUrl === null) {
            $returnUrl = buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey);
        }
        if (!$canManageClientExtranet) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Sem permissoes para gerir extranet.', 403);
        }
        if (!hasAccountingEntityExtranetSettingsTable()) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Tabela de configuracao Extranet em falta.', 400);
        }

        $entityId = (int) ($_POST['entity_id'] ?? 0);
        if ($entityId <= 0) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Entidade invalida.', 400);
        }
        $entityStmt = $pdo->prepare('SELECT id, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $entityStmt->execute([$entityId]);
        $entityRow = $entityStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entityRow || ($entityRow['entity_type'] ?? '') !== 'acquirer') {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Apenas entidades adquirentes suportam extranet.', 400);
        }

        try {
            saveAccountingEntityExtranetSettings($entityId, [
                'erp_software' => trim((string) ($_POST['erp_software'] ?? '')),
                'erp_api_url' => trim((string) ($_POST['erp_api_url'] ?? '')),
                'erp_api_username' => trim((string) ($_POST['erp_api_username'] ?? '')),
                'erp_api_password' => trim((string) ($_POST['erp_api_password'] ?? '')),
                'erp_api_token' => trim((string) ($_POST['erp_api_token'] ?? '')),
                'support_enabled' => (string) ($_POST['support_enabled'] ?? '0') === '1' ? 1 : 0,
                'support_user_id' => (int) ($_POST['support_user_id'] ?? 0),
            ]);
        } catch (Throwable $e) {
            respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'error', 'Falha ao guardar configuracao Extranet: ' . $e->getMessage(), 400);
        }
        respondAccountingEntitiesPost($isAjaxRequest, $returnUrl, 'success', 'Configuracao Extranet guardada.');
    }

    if ($action === 'update-emitter-type' || $action === 'toggle-bank-entity') {
        if (!$canManageEmitterType) {
            respondAccountingEntitiesPost($isAjaxRequest, buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey), 'error', 'Sem permissoes para alterar o tipo de emitente.', 403);
        }

        if (!$hasEmitterTypeColumn) {
            respondAccountingEntitiesPost($isAjaxRequest, buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey), 'error', 'Coluna de tipo de emitente em falta.', 400);
        }

        $entityId = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
        $emitterType = trim((string) ($_POST['emitter_type'] ?? ($_POST['is_bank_entity'] ?? '0')));
        $emitterType = in_array($emitterType, ['1', '2'], true) ? $emitterType : '0';
        if ($entityId <= 0) {
            respondAccountingEntitiesPost($isAjaxRequest, buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey), 'error', 'Entidade invalida.', 400);
        }

        $stmt = $pdo->prepare('SELECT id, nif, name, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $stmt->execute([$entityId]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entity) {
            respondAccountingEntitiesPost($isAjaxRequest, buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey), 'error', 'Entidade nao encontrada.', 404);
        }

        $expectedBankEntityType = $isSupplierList ? 'emitter' : $entityType;
        if (($entity['entity_type'] ?? '') !== $expectedBankEntityType) {
            respondAccountingEntitiesPost($isAjaxRequest, buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey), 'error', 'Tipo de entidade invalido.', 400);
        }

        $stmt = $pdo->prepare('UPDATE accounting_entities SET ' . $emitterTypeColumn . ' = ? WHERE id = ?');
        $stmt->execute([$emitterType, $entityId]);
        logAuditAction(
            'update',
            'accounting_entity',
            $entityId,
            [
                'field' => 'emitter_type',
                'value' => $emitterType,
                'nif' => trim((string) ($entity['nif'] ?? '')),
                'entity_type' => $expectedBankEntityType,
            ]
        );

        respondAccountingEntitiesPost($isAjaxRequest, buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyRouteKey), 'success', 'Tipo de emitente atualizado.');
    }

    if ($action === 'delete-entity') {
        if (!$isSuperAdmin) {
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', 'Sem permissoes para eliminar entidades.', 403);
        }

        $entityId = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
        if ($entityId <= 0) {
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', 'Entidade invalida.', 400);
        }

        $stmt = $pdo->prepare('SELECT id, nif, name, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $stmt->execute([$entityId]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entity) {
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', 'Entidade nao encontrada.', 404);
        }

        if (($entity['entity_type'] ?? '') !== $entityType) {
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', 'Tipo de entidade invalido.', 400);
        }

        $nif = trim((string) ($entity['nif'] ?? ''));
        if ($nif === '') {
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', 'NIF em falta para eliminar entidade.', 400);
        }

        try {
            $result = deleteEntityByNifWithReferences($pdo, $nif, $entityType);
            logAuditAction(
                'delete',
                'accounting_entity',
                (int) $entity['id'],
                [
                    'nif' => $nif,
                    'entity_type' => $entityType,
                    'deleted_rows' => $result['total_deleted'],
                    'deleted_by_table' => $result['deleted_by_table'],
                ]
            );
            $okMessage = 'Empresa eliminada com sucesso. Registos apagados: ' . (int) $result['total_deleted'] . '.';
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'success', $okMessage);
        } catch (Throwable $e) {
            $errorMessage = 'Falha ao eliminar a empresa: ' . $e->getMessage();
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', $errorMessage, 400);
        }
    }

    if ($action === 'merge-duplicate-acquirer-database') {
        if (!$canManageEmitterType) {
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', 'Sem permissoes para fundir empresas duplicadas.', 403);
        }

        $erpDatabase = normalizeAccountingEntityDatabaseKey((string) ($_POST['erp_database'] ?? ''));
        $keepId = isset($_POST['keep_id']) ? (int) $_POST['keep_id'] : 0;
        if ($typeSlug !== 'empresas' || $erpDatabase === '') {
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', 'Dados invalidos para fundir empresas.', 400);
        }

        try {
            $result = mergeAccountingAcquirerEntitiesByDatabase($pdo, $erpDatabase, $keepId);
            $mergedCount = (int) ($result['merged'] ?? 0);
            if ($mergedCount <= 0) {
                $message = 'Nao existiam duplicados por fundir para a base ' . $erpDatabase . '.';
            } else {
                $message = 'Duplicados fundidos com sucesso para a base ' . $erpDatabase . '. Registos removidos: ' . $mergedCount . '.';
            }
            logAuditAction('merge', 'accounting_entity_duplicate_database', (int) ($result['kept_id'] ?? 0), [
                'erp_database' => $erpDatabase,
                'merged' => $mergedCount,
                'removed_ids' => $result['removed_ids'] ?? [],
                'removed_nifs' => $result['removed_nifs'] ?? [],
                'kept_nif' => $result['kept_nif'] ?? '',
            ]);
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'success', $message);
        } catch (Throwable $e) {
            respondAccountingEntitiesPost($isAjaxRequest, BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug), 'error', 'Falha ao fundir duplicados: ' . $e->getMessage(), 400);
        }
    }
}

$consultRouteKey = trim((string) ($_GET['consulta'] ?? ''));
$consultEntity = null;
$erpClient = null;
$erpClientForm = [
    'id' => '',
    'nif' => '',
    'cons_final' => false,
    'name' => '',
    'address' => '',
    'address2' => '',
    'postal_code' => '',
    'city' => '',
    'zone' => '',
    'subzone' => '',
    'phone' => '',
    'mobile' => '',
    'email' => '',
    'number' => '',
    'tec' => '',
];
$erpZones = [];
$erpSubzones = [];
$zoneError = '';
$subzoneError = '';
$erpError = '';
$additionalClientFields = [];
$additionalClientValues = [];
$clientExtranetUsers = [];
$clientExtranetSettings = [];
$adminTaskPermissions = [];
$supportAssignableUsers = [];
$allInternalUsers = [];
if ($consultRouteKey !== '') {
    $consultEntity = findAccountingEntityByRouteKey($pdo, $consultRouteKey, $entityType);

    if ($consultEntity) {
        $erpDatabase = normalizeAccountingEntityDatabaseKey(getErpDefaultCompanyIdentifier());
        if ($erpDatabase === '') {
            $erpDatabase = normalizeAccountingEntityDatabaseKey((string) ($consultEntity['erp_database'] ?? ''));
        }
        $erpClientDatabase = $erpDatabase;
        $consultNif = trim((string) ($consultEntity['nif'] ?? ''));
        if ($consultNif === '') {
            $erpError = 'Entidade sem NIF definido.';
        } elseif ($erpDatabase === '') {
            $erpError = 'A entidade nao tem base de dados ERP configurada.';
        } else {
            logErpMessage(
                'Entidade ' . (int) ($consultEntity['id'] ?? 0)
                . ' (' . $consultNif . ') a consultar ERP como acquirer na base ' . $erpDatabase
                . ' | route=' . (string) $consultRouteKey
            );
            $remote = fetchAccountingEntityFromErp($consultNif, 'acquirer', true, $erpDatabase);
            if (is_array($remote)) {
                if (!empty($remote['error'])) {
                    $erpError = (string) $remote['error'];
                } else {
                    $erpClient = $remote['entity'] ?? null;
                    if (!$erpClient) {
                        $erpError = 'Dados indisponíveis no webservice.';
                    } else {
                        $payload = is_array($remote['payload'] ?? null) ? $remote['payload'] : [];
                        $row = [];
                        if (isset($payload['aaData']) && is_array($payload['aaData']) && !empty($payload['aaData'][0]) && is_array($payload['aaData'][0])) {
                            $row = $payload['aaData'][0];
                        } elseif (isset($payload['data']) && is_array($payload['data']) && !empty($payload['data'][0]) && is_array($payload['data'][0])) {
                            $row = $payload['data'][0];
                        }

                        if ($row) {
                            $erpClientForm['id'] = trim((string) ($row['Id'] ?? ''));
                            $erpClientForm['nif'] = extractVatNumber((string) ($row['strNumContrib'] ?? ''));
                            $erpClientForm['name'] = trim((string) ($row['strNome'] ?? ''));
                            $erpClientForm['address'] = trim((string) ($row['strMorada_lin1'] ?? ''));
                            $erpClientForm['address2'] = trim((string) ($row['strMorada_lin2'] ?? ''));
                            $erpClientForm['postal_code'] = trim((string) ($row['strPostal'] ?? ''));
                            $erpClientForm['city'] = trim((string) ($row['strLocalidade'] ?? ''));
                            $erpClientForm['subzone'] = trim((string) ($row['strAbrevSubZona'] ?? $row['strAbrevSubzona'] ?? ''));
                            $erpClientForm['phone'] = trim((string) ($row['strTelefone'] ?? ''));
                            $erpClientForm['mobile'] = trim((string) ($row['strTelemovel'] ?? ''));
                            $erpClientForm['email'] = trim((string) ($row['strEmail'] ?? ''));
                            $erpClientForm['number'] = trim((string) ($row['intCodigo'] ?? $row['Id'] ?? ''));
                            $erpClientForm['tec'] = trim((string) ($row['intCodVendedor'] ?? ''));
                            $consFinalRaw = trim((string) ($row['bitConsumidorFinal'] ?? ''));
                            if ($consFinalRaw !== '') {
                                $erpClientForm['cons_final'] = in_array(strtolower($consFinalRaw), ['1', 'true', 'sim', 'yes'], true);
                            }
                        }

                        if ($erpClientForm['nif'] === '') {
                            $erpClientForm['nif'] = $consultNif;
                        }
                        if ($erpClientForm['name'] === '') {
                            $erpClientForm['name'] = (string) ($erpClient['name'] ?? '');
                        }
                    }
                }
            } else {
                $erpError = 'Falha ao contactar o webservice.';
            }
        }
    }

    if ($consultEntity && $erpError === '') {
        $zonesResponse = fetchErpTableData('tabelas/zonas', true, $erpDatabase);
        if (!empty($zonesResponse['error'])) {
            $zoneError = (string) $zonesResponse['error'];
        } else {
            $erpZones = $zonesResponse['data'] ?? [];
        }

        $subzonesResponse = fetchErpTableData('tabelas/subzonas', true, $erpDatabase);
        if (!empty($subzonesResponse['error'])) {
            $subzoneError = (string) $subzonesResponse['error'];
        } else {
            $erpSubzones = $subzonesResponse['data'] ?? [];
        }
    }
}

if (is_array($erpClientFormOverride)) {
    foreach ($erpClientFormOverride as $field => $value) {
        if (array_key_exists($field, $erpClientForm)) {
            $erpClientForm[$field] = $value;
        }
    }
}

if ($consultEntity) {
    $additionalClientFields = getAccountingAdditionalFields('client');
    $additionalClientValues = getAccountingEntityAdditionalValues((int) ($consultEntity['id'] ?? 0));
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST['additional_fields'] ?? null)) {
        foreach ($additionalClientFields as $additionalField) {
            $additionalFieldId = (int) ($additionalField['id'] ?? 0);
            if ($additionalFieldId <= 0) {
                continue;
            }
            $additionalClientValues[$additionalFieldId] = normalizeAccountingAdditionalFieldSubmittedValue(
                $additionalField,
                $_POST['additional_fields'][$additionalFieldId] ?? null
            );
        }
    }
    if ($canManageClientExtranet && hasClientUsersTable() && ($consultEntity['entity_type'] ?? 'acquirer') === 'acquirer') {
        $clientExtranetUsers = getClientUsersByAccountingEntityId((int) ($consultEntity['id'] ?? 0));
    }
    if (($canManageClientExtranet || $canManageClientAdmin) && ($consultEntity['entity_type'] ?? 'acquirer') === 'acquirer') {
        $allInternalUsers = getUsers();
        foreach ($allInternalUsers as $row) {
            $supportAssignableUsers[] = [
                'id' => (int) ($row['id'] ?? 0),
                'label' => trim((string) (($row['name'] ?? '') !== '' ? $row['name'] : ($row['username'] ?? ''))),
            ];
        }
    }
    if ($canManageClientExtranet && hasAccountingEntityExtranetSettingsTable() && ($consultEntity['entity_type'] ?? 'acquirer') === 'acquirer') {
        $clientExtranetSettings = getAccountingEntityExtranetSettings((int) ($consultEntity['id'] ?? 0));
    }
    if ($canManageClientAdmin && hasAccountingEntityAdminTaskPermissionsTable() && ($consultEntity['entity_type'] ?? 'acquirer') === 'acquirer') {
        $adminTaskPermissions = getAccountingEntityAdminTaskPermissions((int) ($consultEntity['id'] ?? 0));
    }
}

if ($consultEntity && $erpError === '' && !$erpClient) {
    $fallbackNif = trim((string) ($consultEntity['nif'] ?? ''));
    $erpError = $fallbackNif !== ''
        ? ('O cliente com o NIF ' . $fallbackNif . ' não existe no ERP.')
        : 'O cliente não existe no ERP.';
}
if ($consultRouteKey !== '' && !$consultEntity && $erpError === '') {
    $erpError = 'Entidade não encontrada.';
}

$entitySelectColumns = appendAccountingEntityUuidSelectColumn('id, nif, name, erp_database, erp_client_code');
if ($hasEmitterTypeColumn) {
    $entitySelectColumns .= ', ' . $emitterTypeColumn . ' AS emitter_type';
}
$stmt = $pdo->prepare(
    "SELECT $entitySelectColumns FROM accounting_entities WHERE entity_type = ? ORDER BY name ASC, nif ASC"
);
$stmt->execute([$entityType]);
$entities = ensureAccountingEntityRouteRows($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));
$duplicateAcquirerGroups = (!$consultEntity && $typeSlug === 'empresas')
    ? getAccountingAcquirerDuplicateGroups($pdo)
    : [];

$supplierCompany = null;
$supplierEntities = [];
if ($isSupplierList) {
    $supplierCompanyColumns = appendAccountingEntityUuidSelectColumn('id, nif, name, erp_database, erp_client_code');
    if ($hasEmitterTypeColumn) {
        $supplierCompanyColumns .= ', ' . $emitterTypeColumn . ' AS emitter_type';
    }
    $supplierCompany = findAccountingEntityByRouteKey($pdo, $supplierCompanyRouteKey, 'acquirer');
    if ($supplierCompany) {
        $supplierEntities = loadAccountingSupplierEntitiesForCompany($pdo, $supplierCompany, $emitterTypeColumn);
    } elseif ($flashMessage === '') {
        $flashType = 'error';
        $flashMessage = 'Empresa nao encontrada.';
    }
}

if ($flashMessage !== '') {
    $pnotifyType = $flashType === 'success' ? 'success' : ($flashType === 'warning' ? 'notice' : 'error');
    $pageScripts .= 'document.addEventListener("DOMContentLoaded", function () {' . "\n"
        . '    if (!window.PNotify) { return; }' . "\n"
        . '    var options = {' . "\n"
        . '        text: ' . json_encode($flashMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',' . "\n"
        . '        type: ' . json_encode($pnotifyType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ',' . "\n"
        . '        styling: "bootstrap3",' . "\n"
        . '        delay: 6000,' . "\n"
        . '        hide: true,' . "\n"
        . '        animate: { animate: true, in_class: "fadeInDown", out_class: "fadeOutUp" }' . "\n"
        . '    };' . "\n"
        . '    if (typeof window.PNotify.alert === "function") { window.PNotify.alert(options); return; }' . "\n"
        . '    if (typeof window.PNotify === "function") { window.PNotify(options); }' . "\n"
        . '});' . "\n";
}

require_once __DIR__ . '/../header.php';
if (!$consultEntity && !$isSupplierList) {
?>
<div class="container-fluid">
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-building"></i> <?= htmlspecialchars(ucfirst($typeSlug)); ?></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <?php if ($duplicateAcquirerGroups): ?>
                <div class="alert alert-danger">
                    <strong>Foram detetadas empresas duplicadas pela mesma base ERP.</strong>
                    <?php foreach ($duplicateAcquirerGroups as $duplicateGroup): ?>
                        <?php
                            $duplicateRows = is_array($duplicateGroup['rows'] ?? null) ? $duplicateGroup['rows'] : [];
                            $keepId = (int) ($duplicateGroup['keep_id'] ?? 0);
                            $details = [];
                            foreach ($duplicateRows as $duplicateRow) {
                                $details[] = '#' . (int) ($duplicateRow['id'] ?? 0)
                                    . ' ' . trim((string) ($duplicateRow['nif'] ?? ''))
                                    . ' - ' . trim((string) ($duplicateRow['name'] ?? ''));
                            }
                        ?>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.25);">
                            <div><strong><?= htmlspecialchars((string) ($duplicateGroup['erp_database'] ?? '')); ?></strong>: <?= htmlspecialchars(implode(' | ', $details)); ?></div>
                            <form method="post" style="margin-top: 8px;" onsubmit="return confirm('Fundir os registos duplicados desta base ERP e manter o registo mais antigo?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="merge-duplicate-acquirer-database">
                                <input type="hidden" name="erp_database" value="<?= htmlspecialchars((string) ($duplicateGroup['erp_database'] ?? '')); ?>">
                                <input type="hidden" name="keep_id" value="<?= $keepId; ?>">
                                <button type="submit" class="btn btn-default btn-sm">
                                    <i class="fa fa-compress"></i> Fundir duplicados desta base
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <table class="table table-striped datatable" data-no-sort-last="1" data-order-column="1" data-order-dir="asc">
                <thead>
                    <tr>
                        <th>NIF</th>
                        <th>Nome</th>
                        <th>ERP Database</th>
                        <th data-orderable="false" class="text-right">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entities as $entity): ?>
                    <tr>
                        <td><?= htmlspecialchars($entity['nif'] ?? ''); ?></td>
                        <td><?= htmlspecialchars($entity['name'] ?? ''); ?></td>
                        <td><?= htmlspecialchars(resolveAccountingEntityDatabase($entity)); ?></td>
                        <td class="text-right">
                            <a href="<?= BASE_URL ?>contabilidade/entidades/<?= rawurlencode($typeSlug); ?>/<?= rawurlencode(getAccountingEntityRouteKey($entity)); ?>" class="btn btn-xs btn-primary">
                                <i class="fa fa-pencil"></i> Editar
                            </a>
                            <?php if ($typeSlug === 'empresas'): ?>
                                <a href="<?= BASE_URL ?>contabilidade/entidades/<?= rawurlencode($typeSlug); ?>/<?= rawurlencode(getAccountingEntityRouteKey($entity)); ?>/fornecedores" class="btn btn-xs btn-info">
                                    <i class="fa fa-truck"></i> Fornecedores
                                </a>
                            <?php endif; ?>
                            <?php if ($isSuperAdmin && $typeSlug === 'empresas'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Eliminar a empresa e todos os registos relacionados com este NIF? Esta acao nao pode ser revertida.');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="delete-entity">
                                    <input type="hidden" name="entity_id" value="<?= (int) $entity['id']; ?>">
                                    <button type="submit" class="btn btn-xs btn-danger">
                                        <i class="fa fa-trash"></i> Eliminar
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
require_once __DIR__ . '/../footer.php';
return;
}
?>
<div class="container-fluid">
    <?php if (!$consultEntity): ?>
    <div class="x_panel">
        <div class="x_title">
            <?php if ($isSupplierList && $supplierCompany): ?>
                <h2><i class="fa fa-truck"></i> Fornecedores <small><?= htmlspecialchars($supplierCompany['name'] ?? ''); ?></small></h2>
            <?php else: ?>
                <h2><i class="fa fa-building"></i> <?= htmlspecialchars(ucfirst($typeSlug)); ?></h2>
            <?php endif; ?>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
    <?php endif; ?>
            <?php if (!$consultEntity && $duplicateAcquirerGroups): ?>
                <div class="alert alert-danger">
                    <strong>Foram detetadas empresas duplicadas pela mesma base ERP.</strong>
                    <?php foreach ($duplicateAcquirerGroups as $duplicateGroup): ?>
                        <?php
                            $duplicateRows = is_array($duplicateGroup['rows'] ?? null) ? $duplicateGroup['rows'] : [];
                            $keepId = (int) ($duplicateGroup['keep_id'] ?? 0);
                            $details = [];
                            foreach ($duplicateRows as $duplicateRow) {
                                $details[] = '#' . (int) ($duplicateRow['id'] ?? 0)
                                    . ' ' . trim((string) ($duplicateRow['nif'] ?? ''))
                                    . ' - ' . trim((string) ($duplicateRow['name'] ?? ''));
                            }
                        ?>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.25);">
                            <div><strong><?= htmlspecialchars((string) ($duplicateGroup['erp_database'] ?? '')); ?></strong>: <?= htmlspecialchars(implode(' | ', $details)); ?></div>
                            <form method="post" style="margin-top: 8px;" onsubmit="return confirm('Fundir os registos duplicados desta base ERP e manter o registo mais antigo?');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="merge-duplicate-acquirer-database">
                                <input type="hidden" name="erp_database" value="<?= htmlspecialchars((string) ($duplicateGroup['erp_database'] ?? '')); ?>">
                                <input type="hidden" name="keep_id" value="<?= $keepId; ?>">
                                <button type="submit" class="btn btn-default btn-sm">
                                    <i class="fa fa-compress"></i> Fundir duplicados desta base
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($consultRouteKey !== '' && ($erpError !== '' || !$erpClient)): ?>
                <?php
                    $erpErrorDisplay = preg_replace('/\\s*URL:\\s*\\S+\\s*/i', ' ', $erpError);
                    if (trim((string) $erpErrorDisplay) === '') {
                        $erpErrorDisplay = $erpError;
                    }
                    if (preg_match('/Dados do NIF\\s*(\\d+)/i', (string) $erpErrorDisplay, $matches)) {
                        $erpErrorDisplay = 'O cliente com o NIF ' . $matches[1] . ' não existe no ERP.';
                    }
                    if (trim((string) $erpErrorDisplay) === '') {
                        if ($consultEntity && !empty($consultEntity['nif'])) {
                            $erpErrorDisplay = 'O cliente com o NIF ' . $consultEntity['nif'] . ' não existe no ERP.';
                        } elseif ($consultEntity) {
                            $erpErrorDisplay = 'O cliente não existe no ERP.';
                        } else {
                            $erpErrorDisplay = 'Entidade não encontrada.';
                        }
                    }
                ?>
                <div class="row">
                    <div class="col-md-12"><br><br><br><br><br>
                        <div class="alert alert-warning text-center" style="margin-bottom: 0; font-size: 20px; font-weight: 600; background-color: #f39c12; color: #fff;">
                            <?= htmlspecialchars(trim((string) $erpErrorDisplay)); ?>
                        </div>
                    </div>
                </div>
            <?php elseif ($consultEntity): ?>
                <?php if ($erpClient): ?>
                    <style>
                        .erp-client-form .erp-form-section {
                            border: 1px solid #e6e9ed;
                            border-radius: 3px;
                            padding: 15px 15px 5px;
                            margin-bottom: 18px;
                            background: #fff;
                        }
                        .erp-client-form .erp-form-section-title {
                            margin: 0 0 12px;
                            font-size: 15px;
                            font-weight: 600;
                            color: #34495e;
                        }
                        .erp-client-form .erp-form-section-title i {
                            margin-right: 6px;
                            color: #1abb9c;
                        }
                        .erp-client-form .erp-additional-fields-row {
                            display: flex;
                            flex-wrap: wrap;
                            align-items: flex-start;
                        }
                        .erp-client-form .erp-additional-fields-row > [class*="col-"] {
                            float: none;
                        }
                        .erp-client-form .erp-readonly-field {
                            background-color: #f5f7fa;
                            border-color: #dfe6ec;
                            color: #5a738e;
                            font-weight: 600;
                        }
                        .erp-client-form .erp-form-actions {
                            display: flex;
                            align-items: center;
                            justify-content: flex-end;
                            gap: 12px;
                            flex-wrap: wrap;
                            padding-top: 12px;
                            border-top: 1px solid #e6e9ed;
                        }
                        .erp-client-form .erp-form-actions-primary,
                        .erp-client-form .erp-form-actions-secondary {
                            display: flex;
                            align-items: center;
                            gap: 10px;
                            flex-wrap: wrap;
                        }
                        .erp-client-form .erp-form-actions-secondary {
                            margin-right: auto;
                        }
                        .erp-client-form .erp-form-actions .btn {
                            margin-bottom: 0;
                        }
                        .erp-client-form .erp-form-actions .btn-success {
                            min-width: 190px;
                        }
                        .erp-client-form .password-toggle-btn {
                            min-width: 42px;
                        }
                        .extranet-section .help-block {
                            margin-top: 4px;
                            margin-bottom: 0;
                            color: #73879c;
                            font-size: 12px;
                        }
                        .extranet-create-grid .form-group {
                            margin-bottom: 14px;
                        }
                        .extranet-create-actions {
                            display: flex;
                            align-items: center;
                            justify-content: flex-start;
                            gap: 12px;
                            flex-wrap: wrap;
                            margin-top: 6px;
                        }
                        .extranet-create-actions .checkbox {
                            margin: 0;
                        }
                        .extranet-users-table > thead > tr > th {
                            white-space: nowrap;
                            vertical-align: middle;
                        }
                        .extranet-users-table > tbody > tr > td {
                            vertical-align: middle;
                        }
                        .extranet-user-edit .row {
                            margin-left: -6px;
                            margin-right: -6px;
                        }
                        .extranet-user-edit .row > [class*="col-"] {
                            padding-left: 6px;
                            padding-right: 6px;
                        }
                        .extranet-user-edit .form-control {
                            margin-bottom: 6px;
                        }
                        .extranet-user-edit .extranet-user-actions {
                            display: flex;
                            align-items: center;
                            justify-content: space-between;
                            gap: 8px;
                            flex-wrap: wrap;
                        }
                        .extranet-user-edit .extranet-user-status-toggle {
                            display: inline-flex;
                            align-items: center;
                            gap: 6px;
                            font-size: 12px;
                            color: #5a738e;
                        }
                        @media (max-width: 991px) {
                            .extranet-users-table > tbody > tr > td {
                                white-space: normal;
                            }
                            .extranet-user-edit .btn {
                                width: 100%;
                            }
                        }
                        .extranet-edit-trigger {
                            white-space: nowrap;
                        }
                        .extranet-kpis {
                            margin: 0 -8px 14px;
                        }
                        .extranet-kpi-card {
                            background: #f8fafc;
                            border: 1px solid #dfe7ef;
                            border-radius: 4px;
                            padding: 10px 12px;
                            min-height: 78px;
                        }
                        .extranet-kpi-card .count {
                            display: block;
                            font-size: 24px;
                            font-weight: 700;
                            color: #2a3f54;
                            line-height: 1.1;
                        }
                        .extranet-kpi-card .label {
                            display: inline-block;
                            margin-top: 6px;
                            font-size: 11px;
                        }
                        .extranet-users-table .label {
                            font-size: 11px;
                            letter-spacing: 0.2px;
                        }
                        .admin-users-table .label,
                        .admin-task-table .label {
                            font-size: 11px;
                            letter-spacing: 0.2px;
                        }
                    </style>
                    <div class="x_panel">
                        <div class="x_title">
                            <h2><i class="fa fa-cloud"></i> Dados do Cliente</h2>
                            <div class="clearfix"></div>
                        </div>
                        <div class="x_content">
                            <ul class="nav nav-tabs bar_tabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" data-bs-toggle="tab" data-bs-target="#cliente-detalhes" href="javascript:void(0);" role="tab">Detalhes</a>
                                </li>
                                <?php if ($additionalClientFields && $canViewAdditionalFields): ?>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" data-bs-target="#cliente-campos-adicionais" href="javascript:void(0);" role="tab">Campos Adicionais</a>
                                    </li>
                                <?php endif; ?>
                                <?php if ($canManageClientExtranet): ?>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" data-bs-target="#cliente-extranet" href="javascript:void(0);" role="tab">Extranet</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" data-bs-target="#cliente-admin" href="javascript:void(0);" role="tab">Admin</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                            <?php
                                $zoneOptions = [];
                                foreach ($erpZones as $zone) {
                                    if (!is_array($zone)) {
                                        continue;
                                    }
                                    $code = trim((string) ($zone['strAbreviatura'] ?? $zone['strabreviatura'] ?? ''));
                                    if ($code === '') {
                                        continue;
                                    }
                                    $label = trim((string) ($zone['strDescricao'] ?? $zone['strdescricao'] ?? $code));
                                    $zoneOptions[strtoupper($code)] = $label;
                                }

                                $subzoneOptions = [];
                                $subzoneToZone = [];
                                foreach ($erpSubzones as $subzone) {
                                    if (!is_array($subzone)) {
                                        continue;
                                    }
                                    $code = trim((string) ($subzone['strAbreviatura'] ?? $subzone['strabreviatura'] ?? ''));
                                    if ($code === '') {
                                        continue;
                                    }
                                    $label = trim((string) ($subzone['strDescricao'] ?? $subzone['strdescricao'] ?? $code));
                                    $zoneCode = trim((string) ($subzone['strAbrevZona'] ?? $subzone['strabrevzona'] ?? ''));
                                    $normalizedCode = strtoupper($code);
                                    $subzoneOptions[$normalizedCode] = [
                                        'label' => $label,
                                        'zone' => strtoupper($zoneCode),
                                    ];
                                    if ($zoneCode !== '') {
                                        $subzoneToZone[$normalizedCode] = strtoupper($zoneCode);
                                    }
                                }

                                $selectedSubzone = strtoupper(trim((string) $erpClientForm['subzone']));
                                $selectedZone = strtoupper(trim((string) $erpClientForm['zone']));
                                if ($selectedZone === '' && $selectedSubzone !== '' && isset($subzoneToZone[$selectedSubzone])) {
                                    $selectedZone = strtoupper((string) $subzoneToZone[$selectedSubzone]);
                                }

                                if ($selectedSubzone !== '' && !isset($subzoneOptions[$selectedSubzone])) {
                                    $subzoneOptions[$selectedSubzone] = [
                                        'label' => $selectedSubzone,
                                        'zone' => $selectedZone,
                                    ];
                                }

                                $filteredSubzoneOptions = [];
                                if ($selectedZone !== '') {
                                    foreach ($subzoneOptions as $code => $meta) {
                                        if (strtoupper((string) ($meta['zone'] ?? '')) === $selectedZone) {
                                            $filteredSubzoneOptions[$code] = $meta;
                                        }
                                    }
                                }

                                if (!$filteredSubzoneOptions) {
                                    $filteredSubzoneOptions = $subzoneOptions;
                                }
                            ?>
                            <form class="form-horizontal erp-client-form" id="erpClientMainForm" method="post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="update-erp-client-details">
                                <input type="hidden" name="entity_id" value="<?= (int) ($consultEntity['id'] ?? 0); ?>">
                                <input type="hidden" name="erp_record_id" value="<?= (int) ($erpClientForm['id'] ?? 0); ?>">
                                <input type="hidden" name="erp_database" value="<?= htmlspecialchars((string) $erpClientDatabase); ?>">
                                <input type="hidden" name="return_url" value="<?= htmlspecialchars(BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '/' . rawurlencode(getAccountingEntityRouteKey($consultEntity))); ?>">
                                <input type="hidden" name="nif" value="<?= htmlspecialchars((string) $erpClientForm['nif']); ?>">
                                <input type="hidden" name="name" value="<?= htmlspecialchars((string) $erpClientForm['name']); ?>">
                                <input type="hidden" name="number" value="<?= htmlspecialchars((string) $erpClientForm['number']); ?>">
                                <input type="hidden" name="tec" value="<?= htmlspecialchars((string) $erpClientForm['tec']); ?>">
                                <div class="tab-content">
                                    <div class="tab-pane fade active show" id="cliente-detalhes" role="tabpanel">
                                        <div class="erp-form-section">
                                            <h3 class="erp-form-section-title"><i class="fa fa-building-o"></i> Identificação</h3>
                                            <div class="row">
                                                <div class="col-md-3 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">NIF</label>
                                                        <input type="text" class="form-control erp-readonly-field" value="<?= htmlspecialchars((string) $erpClientForm['nif']); ?>" readonly>
                                                    </div>
                                                </div>
                                                <div class="col-md-6 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Nome</label>
                                                        <input type="text" class="form-control erp-readonly-field" value="<?= htmlspecialchars((string) $erpClientForm['name']); ?>" readonly>
                                                    </div>
                                                </div>
                                                <div class="col-md-3 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Nº Cliente</label>
                                                        <input type="text" class="form-control erp-readonly-field" value="<?= htmlspecialchars((string) $erpClientForm['number']); ?>" readonly>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="erp-form-section">
                                            <h3 class="erp-form-section-title"><i class="fa fa-map-marker"></i> Morada e Localização</h3>
                                            <div class="row">
                                                <div class="col-md-6 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Morada</label>
                                                        <input type="text" class="form-control" name="address" value="<?= htmlspecialchars((string) $erpClientForm['address']); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Morada 2</label>
                                                        <input type="text" class="form-control" name="address2" value="<?= htmlspecialchars((string) $erpClientForm['address2']); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-2 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">CP</label>
                                                        <input type="text" class="form-control" name="postal_code" value="<?= htmlspecialchars((string) $erpClientForm['postal_code']); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Localidade</label>
                                                        <input type="text" class="form-control" name="city" value="<?= htmlspecialchars((string) $erpClientForm['city']); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-3 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Zona</label>
                                                        <select class="form-control" name="zone" id="erpClientZoneSelect">
                                                            <option value="">-</option>
                                                            <?php foreach ($zoneOptions as $code => $label): ?>
                                                                <option value="<?= htmlspecialchars($code); ?>" <?= strtoupper($code) === $selectedZone ? 'selected' : ''; ?>>
                                                                    <?= htmlspecialchars((string) $label); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="col-md-3 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Subzona</label>
                                                        <select class="form-control" name="subzone" id="erpClientSubzoneSelect">
                                                            <option value="">-</option>
                                                            <?php foreach ($filteredSubzoneOptions as $code => $meta): ?>
                                                                <option value="<?= htmlspecialchars($code); ?>" <?= strtoupper($code) === $selectedSubzone ? 'selected' : ''; ?>>
                                                                    <?= htmlspecialchars((string) ($meta['label'] ?? $code)); ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="erp-form-section">
                                            <h3 class="erp-form-section-title"><i class="fa fa-phone"></i> Contactos</h3>
                                            <div class="row">
                                                <div class="col-md-4 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Telefone</label>
                                                        <input type="text" class="form-control" name="phone" value="<?= htmlspecialchars((string) $erpClientForm['phone']); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">Telemovel</label>
                                                        <input type="text" class="form-control" name="mobile" value="<?= htmlspecialchars((string) $erpClientForm['mobile']); ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-4 col-sm-12">
                                                    <div class="form-group">
                                                        <label class="control-label">E-mail</label>
                                                        <input type="text" class="form-control" name="email" value="<?= htmlspecialchars((string) $erpClientForm['email']); ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($additionalClientFields && $canViewAdditionalFields): ?>
                                        <div class="tab-pane fade" id="cliente-campos-adicionais" role="tabpanel">
                                            <div class="erp-form-section">
                                                <div class="row erp-additional-fields-row">
                                                    <?php foreach ($additionalClientFields as $additionalField): ?>
                                                        <?php
                                                            $additionalFieldId = (int) ($additionalField['id'] ?? 0);
                                                            $additionalFieldType = trim((string) ($additionalField['type'] ?? 'text'));
                                                            $additionalFieldName = 'additional_fields[' . $additionalFieldId . ']';
                                                            $additionalFieldValue = (string) ($additionalClientValues[$additionalFieldId] ?? '');
                                                            $additionalFieldStoredValues = getAccountingAdditionalFieldStoredValues($additionalField, $additionalFieldValue);
                                                            $additionalFieldOptions = getAccountingAdditionalFieldOptions($additionalField);
                                                            $additionalFieldLabel = trim((string) ($additionalField['label'] ?? 'Campo'));
                                                            $additionalFieldBootstrapCol = normalizeAccountingAdditionalFieldBootstrapColumn($additionalField['bootstrap_col'] ?? 6);
                                                            $additionalFieldBootstrapOffset = normalizeAccountingAdditionalFieldBootstrapOffset($additionalField['bootstrap_offset'] ?? 0);
                                                            $additionalFieldColumnClass = 'col-md-' . (int) $additionalFieldBootstrapCol . ' col-sm-12';
                                                            $additionalFieldInputId = 'additional-field-' . $additionalFieldId;
                                                            if ($additionalFieldBootstrapOffset > 0) {
                                                                $additionalFieldColumnClass .= ' col-md-offset-' . (int) $additionalFieldBootstrapOffset;
                                                                $additionalFieldColumnClass .= ' offset-md-' . (int) $additionalFieldBootstrapOffset;
                                                            }
                                                        ?>
                                                        <div class="<?= htmlspecialchars($additionalFieldColumnClass); ?>">
                                                            <div class="form-group">
                                                                <label class="control-label"><?= htmlspecialchars($additionalFieldLabel); ?></label>
                                                                <?php if ($additionalFieldType === 'textarea'): ?>
                                                                    <textarea name="<?= htmlspecialchars($additionalFieldName); ?>" class="form-control" rows="3" autocomplete="off"><?= htmlspecialchars($additionalFieldValue); ?></textarea>
                                                                <?php elseif ($additionalFieldType === 'multiselect'): ?>
                                                                    <select name="<?= htmlspecialchars($additionalFieldName); ?>[]" class="form-control" multiple size="<?= max(3, min(8, count($additionalFieldOptions))); ?>">
                                                                        <?php foreach ($additionalFieldOptions as $additionalOption): ?>
                                                                            <option value="<?= htmlspecialchars((string) $additionalOption['value']); ?>" <?= in_array((string) $additionalOption['value'], $additionalFieldStoredValues, true) ? 'selected' : ''; ?>>
                                                                                <?= htmlspecialchars((string) $additionalOption['label']); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                <?php elseif ($additionalFieldType === 'select' || $additionalFieldType === 'taxonomy' || $additionalFieldType === 'boolean_select'): ?>
                                                                    <select name="<?= htmlspecialchars($additionalFieldName); ?>" class="form-control">
                                                                        <option value="">-</option>
                                                                        <?php foreach ($additionalFieldOptions as $additionalOption): ?>
                                                                            <option value="<?= htmlspecialchars((string) $additionalOption['value']); ?>" <?= $additionalFieldValue === (string) $additionalOption['value'] ? 'selected' : ''; ?>>
                                                                                <?= htmlspecialchars((string) $additionalOption['label']); ?>
                                                                            </option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                <?php elseif ($additionalFieldType === 'password'): ?>
                                                                    <div class="input-group">
                                                                        <input
                                                                            type="password"
                                                                            id="<?= htmlspecialchars($additionalFieldInputId); ?>"
                                                                            name="<?= htmlspecialchars($additionalFieldName); ?>"
                                                                            class="form-control"
                                                                            autocomplete="new-password"
                                                                            value="<?= htmlspecialchars($additionalFieldValue); ?>"
                                                                        >
                                                                        <button
                                                                            type="button"
                                                                            class="btn btn-default password-toggle-btn"
                                                                            data-target="#<?= htmlspecialchars($additionalFieldInputId); ?>"
                                                                            aria-label="Mostrar password"
                                                                        >
                                                                            <i class="fa fa-eye"></i>
                                                                        </button>
                                                                    </div>
                                                                <?php elseif ($additionalFieldType === 'integer'): ?>
                                                                    <input type="number" step="1" name="<?= htmlspecialchars($additionalFieldName); ?>" class="form-control" autocomplete="off" value="<?= htmlspecialchars($additionalFieldValue); ?>">
                                                                <?php elseif ($additionalFieldType === 'decimal'): ?>
                                                                    <input type="number" step="0.01" name="<?= htmlspecialchars($additionalFieldName); ?>" class="form-control" autocomplete="off" value="<?= htmlspecialchars($additionalFieldValue); ?>">
                                                                <?php else: ?>
                                                                    <input type="text" name="<?= htmlspecialchars($additionalFieldName); ?>" class="form-control" autocomplete="off" value="<?= htmlspecialchars($additionalFieldValue); ?>">
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($canManageClientExtranet): ?>
                                        <div class="tab-pane fade" id="cliente-extranet" role="tabpanel">
                                            <?php if (!hasClientUsersTable()): ?>
                                                <div class="alert alert-warning" style="margin-top: 15px;">
                                                    A tabela <code>client_users</code> ainda nao existe nesta tenant. Execute as migracoes.
                                                </div>
                                            <?php else: ?>
                                                <?php
                                                    $extranetTotalUsers = count($clientExtranetUsers);
                                                    $extranetActiveUsers = 0;
                                                    foreach ($clientExtranetUsers as $accountRow) {
                                                        if ((int) ($accountRow['is_active'] ?? 0) === 1) {
                                                            $extranetActiveUsers++;
                                                        }
                                                    }
                                                    $extranetInactiveUsers = max(0, $extranetTotalUsers - $extranetActiveUsers);
                                                    $erpSoftwareSelected = (string) ($clientExtranetSettings['erp_software'] ?? '');
                                                    $supportEnabled = (int) ($clientExtranetSettings['support_enabled'] ?? 0) === 1;
                                                    $supportUserId = (int) ($clientExtranetSettings['support_user_id'] ?? 0);
                                                ?>
                                                <div class="erp-form-section extranet-section" style="margin-top: 12px;">
                                                    <div class="x_title" style="border-bottom: 1px solid #e6e9ed; margin: 0 0 14px; padding: 0 0 10px;">
                                                        <h3 class="erp-form-section-title" style="margin: 0;"><i class="fa fa-cogs"></i> Configuração Extranet</h3>
                                                        <div class="clearfix"></div>
                                                    </div>
                                                    <?php if (!hasAccountingEntityExtranetSettingsTable()): ?>
                                                        <div class="alert alert-warning">
                                                            A tabela <code>accounting_entity_extranet_settings</code> ainda nao existe nesta tenant. Execute as migracoes.
                                                        </div>
                                                    <?php else: ?>
                                                            <div class="row">
                                                                <div class="col-md-2 col-sm-12">
                                                                    <div class="form-group">
                                                                        <label class="control-label">ERP</label>
                                                                        <select name="erp_software" class="form-control" id="extranetErpSoftwareSelect">
                                                                            <option value="">-</option>
                                                                            <option value="Eticadata" <?= $erpSoftwareSelected === 'Eticadata' ? 'selected' : ''; ?>>Eticadata</option>
                                                                            <option value="Techsul" <?= $erpSoftwareSelected === 'Techsul' ? 'selected' : ''; ?>>Techsul</option>
                                                                            <option value="Wintouch" <?= $erpSoftwareSelected === 'Wintouch' ? 'selected' : ''; ?>>Wintouch</option>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-4 col-sm-12">
                                                                    <div class="form-group">
                                                                        <label class="control-label">API URL</label>
                                                                        <input type="text" name="erp_api_url" id="extranetErpApiUrlInput" class="form-control" value="<?= htmlspecialchars((string) ($clientExtranetSettings['erp_api_url'] ?? '')); ?>" placeholder="https://api.exemplo.com" <?= $erpSoftwareSelected === '' ? 'disabled' : ''; ?>>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-2 col-sm-12">
                                                                    <div class="form-group">
                                                                        <label class="control-label">API Utilizador</label>
                                                                        <input type="text" name="erp_api_username" id="extranetErpApiUserInput" class="form-control" value="<?= htmlspecialchars((string) ($clientExtranetSettings['erp_api_username'] ?? '')); ?>" <?= $erpSoftwareSelected === '' ? 'disabled' : ''; ?>>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-2 col-sm-12">
                                                                    <div class="form-group">
                                                                        <label class="control-label">API Password</label>
                                                                        <input type="password" name="erp_api_password" id="extranetErpApiPasswordInput" class="form-control" value="<?= htmlspecialchars((string) ($clientExtranetSettings['erp_api_password'] ?? '')); ?>" <?= $erpSoftwareSelected === '' ? 'disabled' : ''; ?>>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-2 col-sm-12">
                                                                    <div class="form-group">
                                                                        <label class="control-label">API Token</label>
                                                                        <input type="text" name="erp_api_token" id="extranetErpApiTokenInput" class="form-control" value="<?= htmlspecialchars((string) ($clientExtranetSettings['erp_api_token'] ?? '')); ?>" <?= $erpSoftwareSelected === '' ? 'disabled' : ''; ?>>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <div class="row">
                                                                <div class="col-md-2 col-sm-12">
                                                                    <div class="form-group">
                                                                        <label class="control-label">Suporte Online</label>
                                                                        <div style="margin-top: 8px; display: flex; align-items: center;">
                                                                            <input type="checkbox" class="form-control extranet-support-switch" name="support_enabled" id="extranetSupportEnabledSwitch" value="1" <?= $supportEnabled ? 'checked' : ''; ?>>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-2 col-sm-12">
                                                                    <div class="form-group">
                                                                        <label class="control-label">Técnico responsável</label>
                                                                        <select name="support_user_id" class="form-control" id="extranetSupportUserSelect" <?= $supportEnabled ? '' : 'disabled'; ?>>
                                                                            <option value="">-</option>
                                                                            <?php foreach ($supportAssignableUsers as $supportUser): ?>
                                                                                <?php $supportUserIdOption = (int) ($supportUser['id'] ?? 0); ?>
                                                                                <option value="<?= $supportUserIdOption; ?>" <?= $supportUserIdOption === $supportUserId ? 'selected' : ''; ?>>
                                                                                    <?= htmlspecialchars((string) ($supportUser['label'] ?? '')); ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                        </select>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                        <script>
                                                        (function () {
                                                            var erpSelect = document.getElementById('extranetErpSoftwareSelect');
                                                            var erpApiInputs = [
                                                                document.getElementById('extranetErpApiUrlInput'),
                                                                document.getElementById('extranetErpApiUserInput'),
                                                                document.getElementById('extranetErpApiPasswordInput'),
                                                                document.getElementById('extranetErpApiTokenInput')
                                                            ];
                                                            var supportSwitch = document.getElementById('extranetSupportEnabledSwitch');
                                                            var supportUserSelect = document.getElementById('extranetSupportUserSelect');
                                                            var mainForm = supportSwitch ? supportSwitch.closest('form') : null;
                                                            var supportSwitchery = null;
                                                            var supportStateSyncing = false;

                                                            function toggleErpApiInputs() {
                                                                if (!erpSelect) {
                                                                    return;
                                                                }
                                                                var hasErp = (erpSelect.value || '').trim() !== '';
                                                                erpApiInputs.forEach(function (input) {
                                                                    if (!input) {
                                                                        return;
                                                                    }
                                                                    input.disabled = !hasErp;
                                                                    input.style.opacity = hasErp ? '1' : '0.65';
                                                                });
                                                            }

                                                            function toggleSupportUserSelect() {
                                                                if (!supportSwitch || !supportUserSelect) {
                                                                    return;
                                                                }
                                                                var enabled = !!supportSwitch.checked;
                                                                supportUserSelect.disabled = !enabled;
                                                                if (enabled) {
                                                                    supportUserSelect.removeAttribute('disabled');
                                                                } else {
                                                                    supportUserSelect.setAttribute('disabled', 'disabled');
                                                                    supportUserSelect.value = '';
                                                                }
                                                                supportUserSelect.style.opacity = enabled ? '1' : '0.65';
                                                            }

                                                            function syncSupportSwitchVisual() {
                                                                if (supportSwitchery && typeof supportSwitchery.setPosition === 'function') {
                                                                    supportSwitchery.setPosition(false);
                                                                }
                                                            }

                                                            function setSupportSwitchState(enabled) {
                                                                if (!supportSwitch) {
                                                                    return;
                                                                }
                                                                supportStateSyncing = true;
                                                                supportSwitch.checked = !!enabled;
                                                                syncSupportSwitchVisual();
                                                                toggleSupportUserSelect();
                                                                supportStateSyncing = false;
                                                            }

                                                            function initSupportSwitchery(force) {
                                                                if (!supportSwitch || !window.Switchery) {
                                                                    return;
                                                                }
                                                                if (supportSwitch.dataset && supportSwitch.dataset.switcheryReady === '1' && !force) {
                                                                    return;
                                                                }
                                                                var existing = supportSwitch.nextElementSibling;
                                                                if (existing && existing.classList && existing.classList.contains('switchery')) {
                                                                    existing.remove();
                                                                }
                                                                try {
                                                                    supportSwitchery = new Switchery(supportSwitch, { color: '#26B99A' });
                                                                    if (supportSwitch.dataset) {
                                                                        supportSwitch.dataset.switcheryReady = '1';
                                                                    }
                                                                    if (supportSwitchery.switcher) {
                                                                        supportSwitchery.switcher.addEventListener('click', function () {
                                                                            window.setTimeout(function () {
                                                                                toggleSupportUserSelect();
                                                                            }, 0);
                                                                        });
                                                                    }
                                                                } catch (error) {
                                                                    supportSwitchery = null;
                                                                }
                                                            }

                                                            if (erpSelect) {
                                                                erpSelect.addEventListener('change', toggleErpApiInputs);
                                                            }
                                                            toggleErpApiInputs();
                                                            if (supportSwitch) {
                                                                supportSwitch.addEventListener('change', toggleSupportUserSelect);
                                                                supportSwitch.addEventListener('change', function () {
                                                                    if (supportStateSyncing) {
                                                                        return;
                                                                    }
                                                                    toggleSupportUserSelect();
                                                                });
                                                                toggleSupportUserSelect();
                                                                window.setTimeout(toggleSupportUserSelect, 120);
                                                                initSupportSwitchery(false);
                                                            }
                                                            if (supportUserSelect) {
                                                                supportUserSelect.addEventListener('change', function () {
                                                                    if (supportUserSelect.value && supportUserSelect.value !== '') {
                                                                        setSupportSwitchState(true);
                                                                    } else {
                                                                        setSupportSwitchState(false);
                                                                    }
                                                                });
                                                            }
                                                            if (mainForm) {
                                                                mainForm.addEventListener('submit', function () {
                                                                    toggleSupportUserSelect();
                                                                    if (supportUserSelect && !supportSwitch.checked) {
                                                                        supportUserSelect.value = '';
                                                                    }
                                                                });
                                                            }
                                                            document.addEventListener('shown.bs.tab', function (event) {
                                                                var target = event.target || null;
                                                                if (!target || !(target.getAttribute && target.getAttribute('data-bs-target') === '#cliente-extranet')) {
                                                                    return;
                                                                }
                                                                window.setTimeout(function () {
                                                                    toggleErpApiInputs();
                                                                    toggleSupportUserSelect();
                                                                    initSupportSwitchery(true);
                                                                }, 0);
                                                            });
                                                        }());
                                                        </script>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="row extranet-kpis" style="margin-top: 12px;">
                                                    <div class="col-md-4 col-sm-12" style="padding: 0 8px 10px;">
                                                        <div class="extranet-kpi-card">
                                                            <span class="count"><?= (int) $extranetTotalUsers; ?></span>
                                                            <span class="label label-primary">Total de contas</span>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-12" style="padding: 0 8px 10px;">
                                                        <div class="extranet-kpi-card">
                                                            <span class="count"><?= (int) $extranetActiveUsers; ?></span>
                                                            <span class="label label-success">Contas ativas</span>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-4 col-sm-12" style="padding: 0 8px 10px;">
                                                        <div class="extranet-kpi-card">
                                                            <span class="count"><?= (int) $extranetInactiveUsers; ?></span>
                                                            <span class="label label-default">Contas inativas</span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="erp-form-section extranet-section">
                                                    <div class="x_title" style="border-bottom: 1px solid #e6e9ed; margin: 0 0 14px; padding: 0 0 10px;">
                                                        <h3 class="erp-form-section-title" style="margin: 0;"><i class="fa fa-users"></i> Gestão de utilizadores</h3>
                                                        <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                                                            <li>
                                                                <button type="button" class="btn btn-primary btn-sm extranet-create-trigger">
                                                                    <i class="fa fa-plus"></i> Adicionar utilizador
                                                                </button>
                                                            </li>
                                                        </ul>
                                                        <div class="clearfix"></div>
                                                    </div>
                                                    <div class="table-responsive">
                                                        <table class="table table-striped jambo_table bulk_action extranet-users-table">
                                                            <thead>
                                                                <tr data-admin-task-key="<?= htmlspecialchars($permissionKey, ENT_QUOTES); ?>">
                                                                    <th>ID</th>
                                                                    <th>Utilizador</th>
                                                                    <th>Nome</th>
                                                                    <th>Email</th>
                                                                    <th>Estado</th>
                                                                    <th class="text-right">Ações</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                            <?php if ($clientExtranetUsers): ?>
                                                                <?php foreach ($clientExtranetUsers as $clientAccount): ?>
                                                                    <tr data-client-user-id="<?= (int) ($clientAccount['id'] ?? 0); ?>" data-client-active="<?= (int) ($clientAccount['is_active'] ?? 0); ?>">
                                                                        <td><?= (int) ($clientAccount['id'] ?? 0); ?></td>
                                                                        <td><?= htmlspecialchars((string) ($clientAccount['username'] ?? '')); ?></td>
                                                                        <td><?= htmlspecialchars((string) ($clientAccount['name'] ?? '')); ?></td>
                                                                        <td><?= htmlspecialchars((string) ($clientAccount['email'] ?? '')); ?></td>
                                                                        <td>
                                                                            <?php if ((int) ($clientAccount['is_active'] ?? 0) === 1): ?>
                                                                                <span class="label label-success">Ativo</span>
                                                                            <?php else: ?>
                                                                                <span class="label label-default">Inativo</span>
                                                                            <?php endif; ?>
                                                                        </td>
                                                                        <td class="text-right">
                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-xs btn-success extranet-impersonate-trigger"
                                                                                data-client-user-id="<?= (int) ($clientAccount['id'] ?? 0); ?>"
                                                                                title="Entrar na area reservada deste utilizador sem credenciais"
                                                                                <?= (int) ($clientAccount['is_active'] ?? 0) === 1 ? '' : 'disabled'; ?>
                                                                            >
                                                                                <i class="fa fa-sign-in"></i> Impersonar
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-xs btn-info extranet-edit-trigger"
                                                                                data-client-user-id="<?= (int) ($clientAccount['id'] ?? 0); ?>"
                                                                                data-client-username="<?= htmlspecialchars((string) ($clientAccount['username'] ?? ''), ENT_QUOTES); ?>"
                                                                                data-client-name="<?= htmlspecialchars((string) ($clientAccount['name'] ?? ''), ENT_QUOTES); ?>"
                                                                                data-client-email="<?= htmlspecialchars((string) ($clientAccount['email'] ?? ''), ENT_QUOTES); ?>"
                                                                                data-client-active="<?= (int) ($clientAccount['is_active'] ?? 0); ?>"
                                                                            >
                                                                                <i class="fa fa-pencil"></i> Editar
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                class="btn btn-xs btn-danger extranet-delete-trigger"
                                                                                data-client-user-id="<?= (int) ($clientAccount['id'] ?? 0); ?>"
                                                                                data-client-username="<?= htmlspecialchars((string) ($clientAccount['username'] ?? ''), ENT_QUOTES); ?>"
                                                                                data-client-name="<?= htmlspecialchars((string) ($clientAccount['name'] ?? ''), ENT_QUOTES); ?>"
                                                                            >
                                                                                <i class="fa fa-trash"></i> Eliminar
                                                                            </button>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php else: ?>
                                                                <tr class="extranet-empty-row">
                                                                    <td colspan="6" class="text-center text-muted" style="padding: 24px 12px;">
                                                                        Sem utilizadores da Extranet nesta entidade.
                                                                    </td>
                                                                </tr>
                                                            <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>

                                                </div>
                                    <?php endif; ?>
                                    <?php endif; /* fecha if ($canManageClientExtranet) do separador Extranet */ ?>
                                    <?php if ($canManageClientAdmin): ?>
                                        <div class="tab-pane fade" id="cliente-admin" role="tabpanel">
                                            <?php if (!hasAccountingEntityAdminTaskPermissionsTable()): ?>
                                                <div class="alert alert-warning" style="margin-top: 15px;">
                                                    A tabela <code>accounting_entity_admin_task_permissions</code> ainda nao existe nesta tenant. Execute as migracoes.
                                                </div>
                                            <?php else: ?>
                                                <?php
                                                    $adminTaskRows = $adminTaskPermissions;
                                                    $adminTaskDefinitionsList = $adminTaskDefinitions;
                                                    $adminTaskTotalAssignments = 0;
                                                    foreach ($adminTaskRows as $adminTaskRow) {
                                                        $adminTaskTotalAssignments += count($adminTaskRow['user_ids'] ?? []);
                                                    }
                                                ?>
                                                <div class="erp-form-section admin-section" style="margin-top: 12px;">
                                                    <div class="x_title" style="border-bottom: 1px solid #e6e9ed; margin: 0 0 14px; padding: 0 0 10px;">
                                                        <h3 class="erp-form-section-title" style="margin: 0;"><i class="fa fa-shield"></i> Tarefas administrativas</h3>
                                                        <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                                                            <li>
                                                                <button type="button" class="btn btn-primary btn-sm admin-task-create-trigger">
                                                                    <i class="fa fa-plus"></i> Gerir permissões
                                                                </button>
                                                            </li>
                                                        </ul>
                                                        <div class="clearfix"></div>
                                                    </div>
                                                    <div class="alert alert-info" style="margin-bottom: 15px;">
                                                        Cada tarefa pode ser atribuída a vários utilizadores da mesma empresa. Um utilizador pode ter mais do que uma tarefa.
                                                    </div>
                                                    <div class="table-responsive">
                                                        <table class="table table-striped jambo_table bulk_action admin-task-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>Tarefa</th>
                                                                    <th>Utilizadores atribuídos</th>
                                                                    <th class="text-right">Ações</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                            <?php foreach ($adminTaskDefinitionsList as $permissionKey => $definition): ?>
                                                                <?php
                                                                    $taskRow = $adminTaskRows[$permissionKey] ?? [
                                                                        'user_ids' => [],
                                                                        'users' => [],
                                                                        'label' => $definition['label'] ?? $permissionKey,
                                                                        'description' => $definition['description'] ?? '',
                                                                    ];
                                                                    $assignedUsers = is_array($taskRow['users'] ?? null) ? $taskRow['users'] : [];
                                                                    $assignedUserIds = array_values(array_unique(array_map('intval', $taskRow['user_ids'] ?? [])));
                                                                    $assignedBadges = [];
                                                                    foreach ($assignedUsers as $assignedUser) {
                                                                        $assignedLabel = trim((string) (($assignedUser['name'] ?? '') !== '' ? $assignedUser['name'] : ($assignedUser['username'] ?? '')));
                                                                        if ($assignedLabel === '') {
                                                                            continue;
                                                                        }
                                                                        $assignedBadges[] = '<span class="label label-info" style="display:inline-block; margin:0 4px 4px 0;">' . htmlspecialchars($assignedLabel) . '</span>';
                                                                    }
                                                                ?>
                                                                <tr>
                                                                    <td>
                                                                        <strong><?= htmlspecialchars((string) ($definition['label'] ?? $permissionKey)); ?></strong><br>
                                                                        <small class="text-muted"><?= htmlspecialchars((string) ($definition['description'] ?? '')); ?></small>
                                                                    </td>
                                                                    <td>
                                                                        <?= $assignedBadges ? implode('', $assignedBadges) : '<span class="label label-default">Sem utilizadores atribuídos</span>'; ?>
                                                                    </td>
                                                                    <td class="text-right">
                                                                        <button
                                                                            type="button"
                                                                            class="btn btn-xs btn-primary admin-task-edit-trigger"
                                                                            data-admin-task-key="<?= htmlspecialchars($permissionKey, ENT_QUOTES); ?>"
                                                                            data-admin-task-label="<?= htmlspecialchars((string) ($definition['label'] ?? $permissionKey), ENT_QUOTES); ?>"
                                                                            data-admin-task-user-ids="<?= htmlspecialchars(implode(',', $assignedUserIds), ENT_QUOTES); ?>"
                                                                        >
                                                                            <i class="fa fa-users"></i> Gerir utilizadores
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="form-group erp-form-actions">
                                        <div class="erp-form-actions-secondary">
                                            <a href="javascript:history.back()" class="btn btn-default">
                                                <i class="fa fa-arrow-left"></i> Voltar
                                            </a>
                                        </div>
                                        <div class="erp-form-actions-primary">
                                            <a href="<?= BASE_URL ?>contabilidade/entidades/<?= rawurlencode($typeSlug); ?>/<?= rawurlencode(getAccountingEntityRouteKey($consultEntity)); ?>" class="btn btn-default">
                                                <i class="fa fa-refresh"></i> Repor
                                            </a>
                                            <?php if ($canEditEntities): ?>
                                            <button type="submit" class="btn btn-success" form="erpClientMainForm">
                                                <i class="fa fa-save"></i> Guardar Alterações
                                            </button>
                                            <?php else: ?>
                                            <span class="text-muted small"><i class="fa fa-lock"></i> Sem permissao para guardar alteracoes.</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    <?php if ($canManageClientExtranet): /* modais de Extranet/Admin: apenas administradores */ ?>
                    <div class="modal fade" id="createClientUserModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form method="post" class="form-horizontal">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fa fa-user-plus"></i> Adicionar utilizador da Extranet</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="save-client-user">
                                        <input type="hidden" name="entity_id" value="<?= (int) ($consultEntity['id'] ?? 0); ?>">
                                        <input type="hidden" name="return_url" value="<?= htmlspecialchars(BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '/' . rawurlencode(getAccountingEntityRouteKey($consultEntity))); ?>">
                                        <div class="row extranet-create-grid">
                                            <div class="col-md-6 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Utilizador</label>
                                                    <input type="text" name="username" class="form-control" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Nome</label>
                                                    <input type="text" name="name" class="form-control">
                                                </div>
                                            </div>
                                            <div class="col-md-6 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Email</label>
                                                    <input type="email" name="email" class="form-control">
                                                </div>
                                            </div>
                                            <div class="col-md-6 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Password</label>
                                                    <input type="password" name="password" class="form-control" required>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="checkbox" style="margin-top: 4px;">
                                            <label><input type="checkbox" name="is_active" value="1" checked> Ativo</label>
                                        </div>
                                        <p class="help-block">A password deve ter pelo menos 8 caracteres, incluindo maiúsculas, minúsculas e número.</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary"><i class="fa fa-plus"></i> Criar conta</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="modal fade" id="editClientUserModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form method="post" class="form-horizontal">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fa fa-user"></i> Editar utilizador da Extranet</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="save-client-user">
                                        <input type="hidden" name="entity_id" value="<?= (int) ($consultEntity['id'] ?? 0); ?>">
                                        <input type="hidden" name="client_user_id" value="">
                                        <input type="hidden" name="return_url" value="<?= htmlspecialchars(BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '/' . rawurlencode(getAccountingEntityRouteKey($consultEntity))); ?>">

                                        <div class="row extranet-create-grid">
                                            <div class="col-md-6 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Utilizador</label>
                                                    <input type="text" class="form-control" name="username" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-6 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Nome</label>
                                                    <input type="text" name="name" class="form-control">
                                                </div>
                                            </div>
                                            <div class="col-md-6 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Email</label>
                                                    <input type="email" name="email" class="form-control">
                                                </div>
                                            </div>
                                            <div class="col-md-6 col-sm-12">
                                                <div class="form-group">
                                                    <label class="control-label">Nova password</label>
                                                    <input type="password" name="password" class="form-control" placeholder="Preencher apenas para alterar">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="checkbox" style="margin-top: 4px;">
                                            <label><input type="checkbox" name="is_active" value="1"> Ativo</label>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="modal fade" id="deleteClientUserModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-md">
                            <div class="modal-content">
                                <form method="post" class="form-horizontal">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fa fa-trash"></i> Eliminar utilizador da Extranet</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete-client-user">
                                        <input type="hidden" name="entity_id" value="<?= (int) ($consultEntity['id'] ?? 0); ?>">
                                        <input type="hidden" name="client_user_id" value="">
                                        <input type="hidden" name="return_url" value="<?= htmlspecialchars(BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '/' . rawurlencode(getAccountingEntityRouteKey($consultEntity))); ?>">
                                        <p class="text-danger" style="font-size: 15px; margin-bottom: 0;">
                                            Tem a certeza que pretende eliminar o utilizador <strong data-delete-user-label></strong>?
                                        </p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-danger"><i class="fa fa-trash"></i> Eliminar</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <div class="modal fade" id="adminTaskPermissionModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form method="post" class="form-horizontal">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fa fa-shield"></i> Gerir tarefa administrativa</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="save-client-admin-task-users">
                                        <input type="hidden" name="entity_id" value="<?= (int) ($consultEntity['id'] ?? 0); ?>">
                                        <input type="hidden" name="permission_key" value="">
                                        <input type="hidden" name="return_url" value="<?= htmlspecialchars(BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '/' . rawurlencode(getAccountingEntityRouteKey($consultEntity))); ?>">
                                        <div class="form-group">
                                            <label class="control-label">Tarefa</label>
                                            <input type="text" class="form-control" data-admin-task-label readonly>
                                        </div>
                                        <div class="form-group">
                                            <label class="control-label">Utilizadores com acesso</label>
                                            <select name="user_ids[]" class="form-control admin-task-user-select" multiple="multiple" style="width: 100%;">
                                                <?php foreach ($supportAssignableUsers as $supportUser): ?>
                                                    <?php $supportUserOptionId = (int) ($supportUser['id'] ?? 0); ?>
                                                    <option value="<?= $supportUserOptionId; ?>"><?= htmlspecialchars((string) ($supportUser['label'] ?? '')); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <p class="help-block" style="margin-bottom: 0;">Use Ctrl/Cmd para selecionar vários utilizadores. Um utilizador pode estar atribuído a várias tarefas.</p>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Guardar permissões</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; /* fecha if ($canManageClientExtranet) dos modais de Extranet/Admin */ ?>
                    <script>
                    (function () {
                        var entityTabKey = <?= json_encode(
                            $consultEntity ? ('accounting_entity_tab:' . (string) getAccountingEntityRouteKey($consultEntity)) : '',
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ); ?>;
                        var tabSelector = '.nav-tabs a[data-bs-toggle="tab"]';
                        var storedTabTarget = '';
                        if (entityTabKey && window.localStorage) {
                            try {
                                storedTabTarget = window.localStorage.getItem(entityTabKey) || '';
                            } catch (error) {
                                storedTabTarget = '';
                            }
                        }

                        function saveActiveTab(targetSelector) {
                            if (!entityTabKey || !targetSelector || !window.localStorage) {
                                return;
                            }
                            try {
                                window.localStorage.setItem(entityTabKey, targetSelector);
                            } catch (error) {
                                // Ignore storage errors.
                            }
                        }

                        function getCurrentActiveTabTarget() {
                            var activeTabLink = document.querySelector(tabSelector + '.active');
                            if (!activeTabLink) {
                                activeTabLink = document.querySelector(tabSelector + '[aria-selected="true"]');
                            }
                            return activeTabLink ? (activeTabLink.getAttribute('data-bs-target') || '') : '';
                        }

                        function activateStoredTab() {
                            if (!storedTabTarget) {
                                return;
                            }
                            var storedTabLink = document.querySelector(tabSelector + '[data-bs-target="' + storedTabTarget.replace(/"/g, '\\"') + '"]');
                            if (!storedTabLink) {
                                return;
                            }
                            if (window.bootstrap && window.bootstrap.Tab) {
                                window.bootstrap.Tab.getOrCreateInstance(storedTabLink).show();
                            } else if (storedTabLink.click) {
                                storedTabLink.click();
                            }
                            if (window.history && window.history.replaceState) {
                                try {
                                    window.history.replaceState({}, '', window.location.pathname + window.location.search);
                                } catch (error) {
                                    // Ignore history errors.
                                }
                            }
                        }

                        document.querySelectorAll(tabSelector).forEach(function (tabLink) {
                            tabLink.addEventListener('click', function (event) {
                                event.preventDefault();
                            });
                            tabLink.addEventListener('shown.bs.tab', function (event) {
                                var target = event.target || tabLink;
                                saveActiveTab(target.getAttribute('data-bs-target') || '');
                                if (window.history && window.history.replaceState) {
                                    try {
                                        window.history.replaceState({}, '', window.location.pathname + window.location.search);
                                    } catch (error) {
                                        // Ignore history errors.
                                    }
                                }
                            });
                        });

                        if (storedTabTarget) {
                            window.setTimeout(activateStoredTab, 0);
                        }

                        var zoneSelect = document.getElementById('erpClientZoneSelect');
                        var subzoneSelect = document.getElementById('erpClientSubzoneSelect');
                        var createClientUserModal = document.getElementById('createClientUserModal');
                        var createClientUserTrigger = document.querySelector('.extranet-create-trigger');
                        var extranetUsersTable = document.querySelector('.extranet-users-table');
                        var extranetKpiCounters = document.querySelectorAll('.extranet-kpis .count');
                        var extranetImpersonateEntityId = <?= (int) ($consultEntity['id'] ?? 0); ?>;
                        var extranetImpersonateReturnUrl = <?= json_encode(BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '/' . rawurlencode(getAccountingEntityRouteKey($consultEntity)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

                        function escapeHtml(value) {
                            return String(value == null ? '' : value)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;')
                                .replace(/"/g, '&quot;')
                                .replace(/'/g, '&#039;');
                        }

                        function syncCsrfTokens(token) {
                            if (!token) {
                                return;
                            }
                            document.querySelectorAll('input[name="csrf_token"]').forEach(function (input) {
                                input.value = token;
                            });
                        }

                        function getActiveTabTarget() {
                            return getCurrentActiveTabTarget();
                        }

                        function updateCounter(index, value) {
                            var counter = extranetKpiCounters && extranetKpiCounters[index] ? extranetKpiCounters[index] : null;
                            if (!counter) {
                                return;
                            }
                            counter.textContent = String(Math.max(0, value));
                        }

                        function getCounterValue(index) {
                            var counter = extranetKpiCounters && extranetKpiCounters[index] ? extranetKpiCounters[index] : null;
                            if (!counter) {
                                return 0;
                            }
                            var parsed = parseInt(counter.textContent || '0', 10);
                            return isNaN(parsed) ? 0 : parsed;
                        }

                        function refreshExtranetCounters(deltaTotal, deltaActive, deltaInactive) {
                            updateCounter(0, getCounterValue(0) + (deltaTotal || 0));
                            updateCounter(1, getCounterValue(1) + (deltaActive || 0));
                            updateCounter(2, getCounterValue(2) + (deltaInactive || 0));
                        }

                        function ensureExtranetEmptyState(tbody) {
                            if (!tbody) {
                                return;
                            }
                            var existingRows = tbody.querySelectorAll('tr[data-client-user-id]');
                            var emptyRow = tbody.querySelector('.extranet-empty-row');
                            if (existingRows.length === 0) {
                                if (!emptyRow) {
                                    tbody.innerHTML = '' +
                                        '<tr class="extranet-empty-row">' +
                                            '<td colspan="6" class="text-center text-muted" style="padding: 24px 12px;">' +
                                                'Sem utilizadores da Extranet nesta entidade.' +
                                            '</td>' +
                                        '</tr>';
                                }
                            } else if (emptyRow) {
                                emptyRow.parentNode.removeChild(emptyRow);
                            }
                        }

                        function buildExtranetUserRowHtml(clientUser) {
                            var userId = parseInt(clientUser && clientUser.id ? clientUser.id : 0, 10) || 0;
                            var username = escapeHtml(clientUser && clientUser.username ? clientUser.username : '');
                            var name = escapeHtml(clientUser && clientUser.name ? clientUser.name : '');
                            var email = escapeHtml(clientUser && clientUser.email ? clientUser.email : '');
                            var isActive = String(clientUser && clientUser.is_active ? clientUser.is_active : '0') === '1';
                            var activeLabel = isActive
                                ? '<span class="label label-success">Ativo</span>'
                                : '<span class="label label-default">Inativo</span>';
                            var impersonateForm = isActive
                                ? ('<button type="button" class="btn btn-xs btn-success extranet-impersonate-trigger" data-client-user-id="' + userId + '" title="Entrar na area reservada deste utilizador sem credenciais">' +
                                        '<i class="fa fa-sign-in"></i> Impersonar' +
                                    '</button> ')
                                : '';
                            return '' +
                                '<tr data-client-user-id="' + userId + '" data-client-active="' + (isActive ? '1' : '0') + '">' +
                                    '<td>' + userId + '</td>' +
                                    '<td>' + username + '</td>' +
                                    '<td>' + name + '</td>' +
                                    '<td>' + email + '</td>' +
                                    '<td>' + activeLabel + '</td>' +
                                    '<td class="text-right">' +
                                        impersonateForm +
                                        '<button type="button" class="btn btn-xs btn-info extranet-edit-trigger" data-client-user-id="' + userId + '" data-client-username="' + username + '" data-client-name="' + name + '" data-client-email="' + email + '" data-client-active="' + (isActive ? '1' : '0') + '">' +
                                            '<i class="fa fa-pencil"></i> Editar' +
                                        '</button> ' +
                                        '<button type="button" class="btn btn-xs btn-danger extranet-delete-trigger" data-client-user-id="' + userId + '" data-client-username="' + username + '" data-client-name="' + name + '">' +
                                            '<i class="fa fa-trash"></i> Eliminar' +
                                        '</button>' +
                                    '</td>' +
                                '</tr>';
                        }

                        function upsertExtranetUserRow(clientUser, operation) {
                            if (!clientUser || !extranetUsersTable) {
                                return;
                            }
                            var userId = parseInt(clientUser.id || 0, 10) || 0;
                            if (!userId) {
                                return;
                            }
                            var tbody = extranetUsersTable.querySelector('tbody');
                            if (!tbody) {
                                return;
                            }
                            var existingRow = tbody.querySelector('tr[data-client-user-id="' + userId + '"]');
                            var isActive = String(clientUser.is_active || '0') === '1';
                            var previousActive = existingRow ? String(existingRow.getAttribute('data-client-active') || '0') === '1' : null;
                            if (operation === 'create') {
                                refreshExtranetCounters(1, isActive ? 1 : 0, isActive ? 0 : 1);
                            } else if (operation === 'update' && previousActive !== null && previousActive !== isActive) {
                                refreshExtranetCounters(0, isActive ? 1 : -1, isActive ? -1 : 1);
                            }
                            var rowHtml = buildExtranetUserRowHtml(clientUser);
                            if (existingRow) {
                                existingRow.outerHTML = rowHtml;
                            } else {
                                var emptyRow = tbody.querySelector('.extranet-empty-row');
                                if (emptyRow) {
                                    emptyRow.parentNode.removeChild(emptyRow);
                                }
                                tbody.insertAdjacentHTML('beforeend', rowHtml);
                            }
                            ensureExtranetEmptyState(tbody);
                        }

                        function removeExtranetUserRow(clientUser) {
                            if (!clientUser || !extranetUsersTable) {
                                return;
                            }
                            var userId = parseInt(clientUser.id || 0, 10) || 0;
                            if (!userId) {
                                return;
                            }
                            var tbody = extranetUsersTable.querySelector('tbody');
                            if (!tbody) {
                                return;
                            }
                            var row = tbody.querySelector('tr[data-client-user-id="' + userId + '"]');
                            if (!row) {
                                return;
                            }
                            var wasActive = String(row.getAttribute('data-client-active') || '0') === '1';
                            refreshExtranetCounters(-1, wasActive ? -1 : 0, wasActive ? 0 : -1);
                            row.parentNode.removeChild(row);
                            ensureExtranetEmptyState(tbody);
                        }

                        function escapeHtml(str) {
                            if (str === null || str === undefined) {
                                return '';
                            }
                            return String(str).replace(/[&<>\"']/g, function (m) {
                                return ({
                                    '&': '&amp;',
                                    '<': '&lt;',
                                    '>': '&gt;',
                                    '\"': '&quot;',
                                    "'": '&#39;'
                                })[m];
                            });
                        }

                        function updateAdminTaskRow(permissionKey, selectedUsers) {
                            if (!permissionKey) {
                                return;
                            }
                            var rows = document.querySelectorAll('.admin-task-table tbody tr');
                            var targetRow = null;
                            Array.prototype.slice.call(rows).some(function (row) {
                                if (row.getAttribute('data-admin-task-key') === permissionKey) {
                                    targetRow = row;
                                    return true;
                                }
                                return false;
                            });
                            if (!targetRow) {
                                return;
                            }
                            var badgesCell = targetRow.children && targetRow.children.length > 1 ? targetRow.children[1] : null;
                            var editButton = targetRow.querySelector('.admin-task-edit-trigger');
                            var labels = Array.isArray(selectedUsers) ? selectedUsers : [];
                            var badgeHtml = '';
                            if (labels.length) {
                                badgeHtml = labels.map(function (item) {
                                    return '<span class="label label-info" style="display:inline-block; margin:0 4px 4px 0;">' + escapeHtml(item.label || '') + '</span>';
                                }).join('');
                            } else {
                                badgeHtml = '<span class="label label-default">Sem utilizadores atribuídos</span>';
                            }
                            if (badgesCell) {
                                badgesCell.innerHTML = badgeHtml;
                            }
                            if (editButton) {
                                editButton.setAttribute('data-admin-task-user-ids', labels.map(function (item) { return String(item.id || ''); }).filter(function (value) { return value !== ''; }).join(','));
                            }
                        }

                        function submitAjaxModalForm(modalElement, form, options) {
                            if (!form) {
                                return;
                            }
                            var submitStateKey = '__ajax_submit_bound__';
                            if (form[submitStateKey]) {
                                return;
                            }
                            form[submitStateKey] = true;
                            var config = options || {};
                            form.addEventListener('submit', function (event) {
                                event.preventDefault();

                                var submitButton = form.querySelector('button[type="submit"]');
                                var originalLabel = submitButton ? submitButton.innerHTML : '';
                                var formData = new FormData(form);

                                if (submitButton) {
                                    submitButton.disabled = true;
                                    submitButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> A guardar';
                                }

                                fetch(window.location.href, {
                                    method: 'POST',
                                    body: formData,
                                    headers: {
                                        'X-Requested-With': 'XMLHttpRequest'
                                    }
                                }).then(function (response) {
                                    return response.json().then(function (data) {
                                        return { status: response.status, data: data };
                                    });
                                }).then(function (result) {
                                    var payload = result && result.data ? result.data : {};
                                    if (payload && payload.csrf_token) {
                                        syncCsrfTokens(payload.csrf_token);
                                    }

                                    if (!payload || payload.success !== true) {
                                        var errorMessage = (payload && (payload.error || payload.message)) ? (payload.error || payload.message) : 'Nao foi possivel guardar.';
                                        if (window.PNotify && typeof window.PNotify.alert === 'function') {
                                            window.PNotify.alert({
                                                text: errorMessage,
                                                type: 'error',
                                                styling: 'bootstrap3',
                                                delay: 5000,
                                                hide: true
                                            });
                                        } else {
                                            alert(errorMessage);
                                        }
                                        return;
                                    }

                                    if (typeof config.onSuccess === 'function') {
                                        config.onSuccess(payload, form);
                                    }

                                    if (window.bootstrap && window.bootstrap.Modal && modalElement) {
                                        window.bootstrap.Modal.getOrCreateInstance(modalElement).hide();
                                    }

                                    var message = payload.message || config.successMessage || 'Alteracoes guardadas.';
                                    if (window.PNotify && typeof window.PNotify.alert === 'function') {
                                        window.PNotify.alert({
                                            text: message,
                                            type: 'success',
                                            styling: 'bootstrap3',
                                            delay: 3500,
                                            hide: true
                                        });
                                    }
                                }).catch(function () {
                                    var errorMessage = 'Falha ao guardar.';
                                    if (window.PNotify && typeof window.PNotify.alert === 'function') {
                                        window.PNotify.alert({
                                            text: errorMessage,
                                            type: 'error',
                                            styling: 'bootstrap3',
                                            delay: 5000,
                                            hide: true
                                        });
                                    } else {
                                        alert(errorMessage);
                                    }
                                }).finally(function () {
                                    if (submitButton) {
                                        submitButton.disabled = false;
                                        submitButton.innerHTML = originalLabel;
                                    }
                                });
                            });
                        }
                        function submitExtranetModalForm(modalElement, form, successMessage) {
                            submitAjaxModalForm(modalElement, form, {
                                successMessage: successMessage,
                                onSuccess: function (payload) {
                                    if (payload && payload.client_user) {
                                        if (payload.operation === 'delete') {
                                            removeExtranetUserRow(payload.client_user);
                                        } else {
                                            upsertExtranetUserRow(payload.client_user, payload.operation || 'update');
                                        }
                                    }
                                }
                            });
                        }

                        function openExtranetEditModal(button) {
                            if (!editClientUserModal || !button) {
                                return;
                            }
                            var setValue = function (selector, value) {
                                var input = editClientUserModal.querySelector(selector);
                                if (input) {
                                    input.value = value || '';
                                }
                            };

                            setValue('input[name="client_user_id"]', button.getAttribute('data-client-user-id') || '');
                            setValue('input[name="username"]', button.getAttribute('data-client-username') || '');
                            setValue('input[name="name"]', button.getAttribute('data-client-name') || '');
                            setValue('input[name="email"]', button.getAttribute('data-client-email') || '');
                            setValue('input[name="password"]', '');

                            var activeInput = editClientUserModal.querySelector('input[name="is_active"]');
                            if (activeInput) {
                                activeInput.checked = String(button.getAttribute('data-client-active') || '0') === '1';
                            }

                            if (window.bootstrap && window.bootstrap.Modal) {
                                window.bootstrap.Modal.getOrCreateInstance(editClientUserModal).show();
                            }
                        }

                        function openExtranetDeleteModal(button) {
                            if (!deleteClientUserModal || !button) {
                                return;
                            }
                            var clientUserIdInput = deleteClientUserModal.querySelector('input[name="client_user_id"]');
                            if (clientUserIdInput) {
                                clientUserIdInput.value = button.getAttribute('data-client-user-id') || '';
                            }
                            var deleteLabel = deleteClientUserModal.querySelector('[data-delete-user-label]');
                            if (deleteLabel) {
                                deleteLabel.textContent = (button.getAttribute('data-client-name') || button.getAttribute('data-client-username') || '').trim();
                            }
                            if (window.bootstrap && window.bootstrap.Modal) {
                                window.bootstrap.Modal.getOrCreateInstance(deleteClientUserModal).show();
                            }
                        }

                        function submitExtranetImpersonate(clientUserId) {
                            var userId = parseInt(clientUserId, 10) || 0;
                            if (!userId) {
                                return;
                            }
                            var csrfTokenInput = document.querySelector('input[name="csrf_token"]');
                            var csrfToken = csrfTokenInput ? csrfTokenInput.value : '';
                            var form = document.createElement('form');
                            form.method = 'post';
                            form.target = '_blank';
                            form.style.display = 'none';
                            var fields = {
                                csrf_token: csrfToken,
                                action: 'impersonate-client-user',
                                entity_id: extranetImpersonateEntityId,
                                client_user_id: userId,
                                return_url: extranetImpersonateReturnUrl
                            };
                            Object.keys(fields).forEach(function (name) {
                                var input = document.createElement('input');
                                input.type = 'hidden';
                                input.name = name;
                                input.value = fields[name];
                                form.appendChild(input);
                            });
                            document.body.appendChild(form);
                            form.submit();
                            document.body.removeChild(form);
                        }

                        if (extranetUsersTable) {
                            extranetUsersTable.addEventListener('click', function (event) {
                                var impersonateButton = event.target.closest('.extranet-impersonate-trigger');
                                if (impersonateButton && extranetUsersTable.contains(impersonateButton)) {
                                    event.preventDefault();
                                    if (impersonateButton.disabled) {
                                        return;
                                    }
                                    submitExtranetImpersonate(impersonateButton.getAttribute('data-client-user-id'));
                                    return;
                                }

                                var editButton = event.target.closest('.extranet-edit-trigger');
                                if (editButton && extranetUsersTable.contains(editButton)) {
                                    event.preventDefault();
                                    openExtranetEditModal(editButton);
                                    return;
                                }

                                var deleteButton = event.target.closest('.extranet-delete-trigger');
                                if (deleteButton && extranetUsersTable.contains(deleteButton)) {
                                    event.preventDefault();
                                    openExtranetDeleteModal(deleteButton);
                                }
                            });
                        }

                        var hasZoneFields = !!(zoneSelect && subzoneSelect);

                        var subzoneOptions = <?= json_encode($subzoneOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
                        var initialSubzone = <?= json_encode($selectedSubzone, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

                        function renderSubzones() {
                            var selectedZone = (zoneSelect.value || '').toUpperCase();
                            var currentSubzone = (subzoneSelect.value || initialSubzone || '').toUpperCase();
                            var matched = false;
                            var hasZoneMatches = false;
                            var fragment = document.createDocumentFragment();
                            var placeholder = document.createElement('option');
                            placeholder.value = '';
                            placeholder.textContent = '-';
                            fragment.appendChild(placeholder);

                            Object.keys(subzoneOptions).sort().forEach(function (code) {
                                var meta = subzoneOptions[code] || {};
                                var optionZone = String(meta.zone || '').toUpperCase();
                                if (selectedZone && optionZone !== selectedZone) {
                                    return;
                                }
                                hasZoneMatches = true;
                                var selected = code.toUpperCase() === currentSubzone;
                                if (selected) {
                                    matched = true;
                                }
                                var option = document.createElement('option');
                                option.value = code;
                                option.textContent = String(meta.label || code);
                                option.selected = selected;
                                fragment.appendChild(option);
                            });

                            if (!hasZoneMatches && selectedZone) {
                                Object.keys(subzoneOptions).sort().forEach(function (code) {
                                    var meta = subzoneOptions[code] || {};
                                    var selected = code.toUpperCase() === currentSubzone;
                                    if (selected) {
                                        matched = true;
                                    }
                                    var option = document.createElement('option');
                                    option.value = code;
                                    option.textContent = String(meta.label || code);
                                    option.selected = selected;
                                    fragment.appendChild(option);
                                });
                            }

                            subzoneSelect.innerHTML = '';
                            subzoneSelect.appendChild(fragment);
                            if (!matched) {
                                subzoneSelect.value = '';
                            }
                        }

                        if (hasZoneFields) {
                            zoneSelect.addEventListener('change', renderSubzones);
                            renderSubzones();
                        }

                        document.querySelectorAll('.password-toggle-btn').forEach(function (button) {
                            button.addEventListener('click', function () {
                                var targetSelector = button.getAttribute('data-target') || '';
                                if (!targetSelector) {
                                    return;
                                }
                                var input = document.querySelector(targetSelector);
                                if (!input) {
                                    return;
                                }
                                var isHidden = input.getAttribute('type') === 'password';
                                input.setAttribute('type', isHidden ? 'text' : 'password');
                                button.setAttribute('aria-label', isHidden ? 'Esconder password' : 'Mostrar password');
                                var icon = button.querySelector('i');
                                if (icon) {
                                    icon.className = isHidden ? 'fa fa-eye-slash' : 'fa fa-eye';
                                }
                            });
                        });

                        var editClientUserModal = document.getElementById('editClientUserModal');
                        var deleteClientUserModal = document.getElementById('deleteClientUserModal');
                        if (editClientUserModal) {
                            submitExtranetModalForm(editClientUserModal, editClientUserModal.querySelector('form'), 'Conta da Extranet guardada.');
                        }

                        if (deleteClientUserModal) {
                            submitExtranetModalForm(deleteClientUserModal, deleteClientUserModal.querySelector('form'), 'Conta da Extranet eliminada.');
                        }

                        if (createClientUserModal && createClientUserTrigger) {
                            createClientUserTrigger.addEventListener('click', function (event) {
                                event.preventDefault();
                                if (window.bootstrap && window.bootstrap.Modal) {
                                    window.bootstrap.Modal.getOrCreateInstance(createClientUserModal).show();
                                }
                            });
                            createClientUserModal.addEventListener('show.bs.modal', function () {
                                var form = createClientUserModal.querySelector('form');
                                if (!form) {
                                    return;
                                }
                                form.reset();
                                var activeInput = form.querySelector('input[name="is_active"]');
                                if (activeInput) {
                                    activeInput.checked = true;
                                }
                            });

                            submitExtranetModalForm(createClientUserModal, createClientUserModal.querySelector('form'), 'Conta da Extranet criada.');
                        }

                        var adminTaskPermissionModal = document.getElementById('adminTaskPermissionModal');
                        var adminTaskUserSelect = adminTaskPermissionModal ? adminTaskPermissionModal.querySelector('.admin-task-user-select') : null;
                        var adminTaskLabelInput = adminTaskPermissionModal ? adminTaskPermissionModal.querySelector('[data-admin-task-label]') : null;
                        var adminTaskPermissionKeyInput = adminTaskPermissionModal ? adminTaskPermissionModal.querySelector('input[name="permission_key"]') : null;
                        var adminTaskFirstTrigger = document.querySelector('.admin-task-edit-trigger');
                        var adminTaskCreateTrigger = document.querySelector('.admin-task-create-trigger');

                        function initAdminTaskSelect2() {
                            if (!adminTaskUserSelect || !window.jQuery || !jQuery.fn.select2) {
                                return;
                            }
                            if (jQuery(adminTaskUserSelect).data('select2')) {
                                return;
                            }
                            jQuery(adminTaskUserSelect).select2({
                                width: '100%',
                                dropdownParent: jQuery(adminTaskPermissionModal)
                            });
                        }

                        function setAdminTaskSelectedUsers(userIds) {
                            var normalizedIds = Array.isArray(userIds) ? userIds : [];
                            if (!adminTaskUserSelect) {
                                return;
                            }
                            if (window.jQuery && jQuery.fn.select2) {
                                jQuery(adminTaskUserSelect).val(normalizedIds).trigger('change');
                                return;
                            }
                            Array.prototype.slice.call(adminTaskUserSelect.options || []).forEach(function (option) {
                                option.selected = normalizedIds.indexOf(option.value) !== -1;
                            });
                        }

                        function openAdminTaskModal(button) {
                            if (!adminTaskPermissionModal || !button) {
                                return;
                            }

                            var taskKey = button.getAttribute('data-admin-task-key') || '';
                            var taskLabel = button.getAttribute('data-admin-task-label') || '';
                            var userIdsRaw = button.getAttribute('data-admin-task-user-ids') || '';
                            var selectedUserIds = userIdsRaw ? userIdsRaw.split(',').map(function (value) {
                                return String(value || '').trim();
                            }).filter(function (value) {
                                return value !== '';
                            }) : [];

                            var form = adminTaskPermissionModal.querySelector('form');
                            if (form) {
                                form.reset();
                            }
                            if (adminTaskPermissionKeyInput) {
                                adminTaskPermissionKeyInput.value = taskKey;
                            }
                            if (adminTaskLabelInput) {
                                adminTaskLabelInput.value = taskLabel;
                            }

                            initAdminTaskSelect2();
                            setAdminTaskSelectedUsers(selectedUserIds);

                            if (window.bootstrap && window.bootstrap.Modal) {
                                window.bootstrap.Modal.getOrCreateInstance(adminTaskPermissionModal).show();
                            }
                        }

                        if (adminTaskPermissionModal && adminTaskUserSelect) {
                            adminTaskPermissionModal.addEventListener('show.bs.modal', function () {
                                initAdminTaskSelect2();
                            });
                        }

                        if (adminTaskFirstTrigger) {
                            adminTaskFirstTrigger.addEventListener('click', function (event) {
                                event.preventDefault();
                                openAdminTaskModal(adminTaskFirstTrigger);
                            });
                        }

                        if (adminTaskCreateTrigger && adminTaskFirstTrigger) {
                            adminTaskCreateTrigger.addEventListener('click', function (event) {
                                event.preventDefault();
                                openAdminTaskModal(adminTaskFirstTrigger);
                            });
                        }

                        document.querySelectorAll('.admin-task-edit-trigger').forEach(function (button) {
                            button.addEventListener('click', function (event) {
                                event.preventDefault();
                                openAdminTaskModal(button);
                            });
                        });

                        if (adminTaskPermissionModal) {
                            var adminTaskForm = adminTaskPermissionModal.querySelector('form');
                            var adminTaskPendingUpdate = null;
                            adminTaskPermissionModal.addEventListener('hidden.bs.modal', function () {
                                if (!adminTaskPendingUpdate) {
                                    return;
                                }

                                var pendingUpdate = adminTaskPendingUpdate;
                                adminTaskPendingUpdate = null;
                                updateAdminTaskRow(pendingUpdate.permissionKey, pendingUpdate.selectedUsers || []);

                                if (pendingUpdate.message && window.PNotify && typeof window.PNotify.alert === 'function') {
                                    window.PNotify.alert({
                                        text: pendingUpdate.message,
                                        type: 'success',
                                        styling: 'bootstrap3',
                                        delay: 3500,
                                        hide: true
                                    });
                                }
                            });
                            if (adminTaskForm) {
                                adminTaskForm.addEventListener('submit', function (event) {
                                    event.preventDefault();

                                    var submitButton = adminTaskForm.querySelector('button[type="submit"]');
                                    var originalLabel = submitButton ? submitButton.innerHTML : '';
                                    var formData = new FormData(adminTaskForm);

                                    if (submitButton) {
                                        submitButton.disabled = true;
                                        submitButton.innerHTML = '<i class="fa fa-spinner fa-spin"></i> A guardar';
                                    }

                                    fetch(window.location.href, {
                                        method: 'POST',
                                        body: formData,
                                        headers: {
                                            'X-Requested-With': 'XMLHttpRequest'
                                        }
                                    }).then(function (response) {
                                        return response.json().then(function (data) {
                                            return { status: response.status, data: data };
                                        });
                                    }).then(function (result) {
                                        var payload = result && result.data ? result.data : {};
                                        if (payload && payload.csrf_token) {
                                            syncCsrfTokens(payload.csrf_token);
                                        }

                                        if (!payload || payload.success !== true) {
                                            var errorMessage = (payload && (payload.error || payload.message)) ? (payload.error || payload.message) : 'Não foi possível guardar as permissões.';
                                            if (window.PNotify && typeof window.PNotify.alert === 'function') {
                                                window.PNotify.alert({
                                                    text: errorMessage,
                                                    type: 'error',
                                                    styling: 'bootstrap3',
                                                    delay: 5000,
                                                    hide: true
                                                });
                                            } else {
                                                alert(errorMessage);
                                            }
                                            return;
                                        }

                                        var selectedUsers = [];
                                        if (payload && Array.isArray(payload.assigned_users)) {
                                            selectedUsers = payload.assigned_users;
                                        } else if (adminTaskUserSelect) {
                                            Array.prototype.slice.call(adminTaskUserSelect.options || []).forEach(function (option) {
                                                if (option.selected) {
                                                    selectedUsers.push({
                                                        id: option.value || '',
                                                        label: option.textContent || ''
                                                    });
                                                }
                                            });
                                        }
                                        adminTaskPendingUpdate = {
                                            permissionKey: adminTaskPermissionKeyInput ? adminTaskPermissionKeyInput.value : '',
                                            selectedUsers: selectedUsers,
                                            message: payload.message || 'Permissões guardadas.'
                                        };

                                        if (window.bootstrap && window.bootstrap.Modal) {
                                            window.bootstrap.Modal.getOrCreateInstance(adminTaskPermissionModal).hide();
                                        }
                                    }).catch(function () {
                                        var errorMessage = 'Falha ao guardar as permissões.';
                                        if (window.PNotify && typeof window.PNotify.alert === 'function') {
                                            window.PNotify.alert({
                                                text: errorMessage,
                                                type: 'error',
                                                styling: 'bootstrap3',
                                                delay: 5000,
                                                hide: true
                                            });
                                        } else {
                                            alert(errorMessage);
                                        }
                                    }).finally(function () {
                                        if (submitButton) {
                                            submitButton.disabled = false;
                                            submitButton.innerHTML = originalLabel;
                                        }
                                    });
                                });
                            }
                        }

                    }());
                    </script>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$consultEntity && $isSupplierList && $supplierCompany): ?>
                <div class="row" style="margin-bottom: 15px;">
                    <div class="col-md-9 col-sm-12">
                        <p class="text-muted" style="margin: 7px 0 0;">
                            Emitentes associados ao NIF <?= htmlspecialchars(extractVatNumber((string) ($supplierCompany['nif'] ?? ''))); ?>
                            <?php $companyDatabase = resolveAccountingEntityDatabase($supplierCompany); ?>
                            <?php if ($companyDatabase !== ''): ?>
                                na base <?= htmlspecialchars($companyDatabase); ?>.
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-3 col-sm-12 text-right">
                        <a href="<?= BASE_URL ?>contabilidade/entidades/<?= rawurlencode($typeSlug); ?>" class="btn btn-default pull-right">
                            <i class="fa fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
                <table class="table table-striped datatable" data-no-sort-last="1" data-order-column="1" data-order-dir="asc">
                    <thead>
                        <tr>
                            <th>NIF</th>
                            <th>Nome</th>
                            <?php if ($hasEmitterTypeColumn): ?>
                                <th data-orderable="false">Tipo</th>
                            <?php endif; ?>
                            <?php if ($canManageEntityAiInstructions): ?>
                                <th data-orderable="false" class="text-right">IA</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($supplierEntities as $supplier): ?>
                        <tr>
                            <td><?= htmlspecialchars($supplier['nif'] ?? ''); ?></td>
                            <td><?= htmlspecialchars($supplier['name'] ?? ''); ?></td>
                            <?php if ($hasEmitterTypeColumn): ?>
                                <td>
                                    <?php if (!empty($supplier['id'])): ?>
                                        <?php $supplierEmitterType = trim((string) ($supplier['emitter_type'] ?? '0')); ?>
                                        <?php
                                            $supplierEmitterTypeLabel = 'Normal';
                                            $supplierEmitterTypeClass = 'label-default';
                                            if ($supplierEmitterType === '1') {
                                                $supplierEmitterTypeLabel = 'Banco';
                                                $supplierEmitterTypeClass = 'label-info';
                                            } elseif ($supplierEmitterType === '2') {
                                                $supplierEmitterTypeLabel = 'Seguradora';
                                                $supplierEmitterTypeClass = 'label-warning';
                                            }
                                        ?>
                                        <span class="label <?= htmlspecialchars($supplierEmitterTypeClass); ?>"><?= htmlspecialchars($supplierEmitterTypeLabel); ?></span>
                                    <?php else: ?>
                                        <span class="label label-default">Sem ficha local</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <?php if ($canManageEntityAiInstructions): ?>
                                <td class="text-right">
                                    <button type="button"
                                            class="btn btn-xs btn-primary accounting-entity-ai-btn"
                                            data-emitter-nif="<?= htmlspecialchars((string) ($supplier['nif'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-emitter-name="<?= htmlspecialchars((string) ($supplier['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            title="Instrucoes IA">
                                        <i class="fa fa-android" aria-hidden="true"></i> IA
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($canManageEntityAiInstructions): ?>
                    <div class="modal fade" id="accountingEntityAiModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <form id="accountingEntityAiForm">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fa fa-magic"></i> Instrucoes IA</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="alert alert-info">
                                            Estas instrucoes aplicam-se apenas a esta combinacao de emitente/adquirente e sao lidas juntamente com as instrucoes gerais de <strong>Definicoes &gt; AI</strong>.
                                        </div>
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="save-ai-instructions">
                                        <input type="hidden" name="emitter_nif" id="accountingEntityAiEmitterNif" value="">
                                        <div class="form-group">
                                            <label class="control-label">Combinacao</label>
                                            <input type="text" class="form-control" id="accountingEntityAiContext" value="" readonly>
                                        </div>
                                        <div class="form-group">
                                            <label class="control-label" for="accountingEntityAiInstructions">Instrucoes adicionais para sugestao de classificacao</label>
                                            <textarea class="form-control" id="accountingEntityAiInstructions" name="instructions" rows="8"></textarea>
                                        </div>
                                        <div class="alert alert-danger d-none" id="accountingEntityAiError" role="alert"></div>
                                        <div class="alert alert-success d-none" id="accountingEntityAiSuccess" role="alert"></div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fa fa-save"></i> Guardar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <script>
                    window.accountingEntityAiConfig = {
                        csrfToken: <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                        acquirerName: <?= json_encode((string) ($supplierCompany['name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                        acquirerNif: <?= json_encode(extractVatNumber((string) ($supplierCompany['nif'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
                        instructions: <?= json_encode(buildAccountingEntityAiInstructionClientMap($supplierEntities), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
                    };
                    </script>
                <?php endif; ?>
            <?php elseif (!$consultEntity): ?>
                <table class="table table-striped datatable" data-no-sort-last="1" data-order-column="1" data-order-dir="asc">
                    <thead>
                        <tr>
                            <th>NIF</th>
                            <th>Nome</th>
                            <th>ERP Database</th>
                            <th data-orderable="false" class="text-right">Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entities as $entity): ?>
                        <tr>
                            <td><?= htmlspecialchars($entity['nif'] ?? ''); ?></td>
                            <td><?= htmlspecialchars($entity['name'] ?? ''); ?></td>
                            <td><?= htmlspecialchars(resolveAccountingEntityDatabase($entity)); ?></td>
                            <td class="text-right">
                                <a href="<?= BASE_URL ?>contabilidade/entidades/<?= rawurlencode($typeSlug); ?>/<?= rawurlencode(getAccountingEntityRouteKey($entity)); ?>" class="btn btn-xs btn-primary">
                                    <i class="fa fa-pencil"></i> Editar
                                </a>
                                <?php if ($typeSlug === 'empresas'): ?>
                                    <a href="<?= BASE_URL ?>contabilidade/entidades/<?= rawurlencode($typeSlug); ?>/<?= rawurlencode(getAccountingEntityRouteKey($entity)); ?>/fornecedores" class="btn btn-xs btn-info">
                                        <i class="fa fa-truck"></i> Fornecedores
                                    </a>
                                <?php endif; ?>
                                <?php if ($isSuperAdmin && $typeSlug === 'empresas'): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminar a empresa e todos os registos relacionados com este NIF? Esta acao nao pode ser revertida.');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete-entity">
                                        <input type="hidden" name="entity_id" value="<?= (int) $entity['id']; ?>">
                                        <button type="submit" class="btn btn-xs btn-danger">
                                            <i class="fa fa-trash"></i> Eliminar
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php if (!$consultEntity): ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
function deleteEntityByNifWithReferences(PDO $pdo, string $nif, string $entityType): array {
    $normalizedNif = extractVatNumber($nif);
    if ($normalizedNif === '') {
        throw new RuntimeException('NIF invalido.');
    }

    $nifRegex = '(^|[^0-9])' . $normalizedNif . '([^0-9]|$)';
    $deletedByTable = [];
    $totalDeleted = 0;

    try {
        $pdo->beginTransaction();

        if (hasTable('supplier_documents') && hasColumn('supplier_documents', 'acquirer')) {
            $stmt = $pdo->prepare('DELETE FROM supplier_documents WHERE acquirer = ? OR acquirer REGEXP ?');
            $stmt->execute([$normalizedNif, $nifRegex]);
            $deletedByTable['supplier_documents'] = $stmt->rowCount();
            $totalDeleted += (int) $stmt->rowCount();
        }

        if (hasTable('accounting_classifications') && hasColumn('accounting_classifications', 'acquirer')) {
            $stmt = $pdo->prepare('DELETE FROM accounting_classifications WHERE acquirer = ? OR acquirer REGEXP ?');
            $stmt->execute([$normalizedNif, $nifRegex]);
            $deletedByTable['accounting_classifications'] = $stmt->rowCount();
            $totalDeleted += (int) $stmt->rowCount();
        }

        if (hasTable('accounting_imports') && hasColumn('accounting_imports', 'field_B')) {
            $stmt = $pdo->prepare('DELETE FROM accounting_imports WHERE field_B = ? OR field_B REGEXP ?');
            $stmt->execute([$normalizedNif, $nifRegex]);
            $deletedByTable['accounting_imports'] = $stmt->rowCount();
            $totalDeleted += (int) $stmt->rowCount();
        }

        if (hasTable('accounting_entities')) {
            if (hasColumn('accounting_entities', 'entity_type')) {
                $stmt = $pdo->prepare('DELETE FROM accounting_entities WHERE nif = ? AND entity_type = ?');
                $stmt->execute([$normalizedNif, $entityType]);
            } else {
                $stmt = $pdo->prepare('DELETE FROM accounting_entities WHERE nif = ?');
                $stmt->execute([$normalizedNif]);
            }
            $deletedByTable['accounting_entities'] = $stmt->rowCount();
            $totalDeleted += (int) $stmt->rowCount();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'total_deleted' => $totalDeleted,
        'deleted_by_table' => $deletedByTable,
    ];
}

function respondAccountingEntitiesPost(bool $isAjaxRequest, string $returnUrl, string $status, string $message, int $httpStatus = 200): void {
    if ($isAjaxRequest) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpStatus);
        echo json_encode([
            'success' => $status === 'success',
            'message' => $status === 'success' ? $message : '',
            'error' => $status === 'success' ? '' : $message,
            'csrf_token' => generateCsrfToken(true),
            'replace_url' => $returnUrl,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $separator = strpos($returnUrl, '?') === false ? '?' : '&';
    header('Location: ' . $returnUrl . $separator . http_build_query([
        'status' => $status,
        'msg' => $message,
    ], '', '&', PHP_QUERY_RFC3986));
    exit;
}

function buildAccountingEntitiesReturnUrl(string $typeSlug, string $supplierCompanyRouteKey = '', string $status = '', string $message = ''): string {
    $url = BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug);
    if ($supplierCompanyRouteKey !== '') {
        $url .= '/' . rawurlencode($supplierCompanyRouteKey) . '/fornecedores';
    }

    $query = [];
    if ($status !== '') {
        $query['status'] = $status;
    }
    if ($message !== '') {
        $query['msg'] = $message;
    }

    if ($query) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function loadAccountingSupplierEntitiesForCompany(PDO $pdo, array $company, string $emitterTypeColumn): array {
    $companyNif = extractVatNumber((string) ($company['nif'] ?? ''));
    $companyDatabase = resolveAccountingEntityDatabase($company);
    $supplierRefs = [];

    $addSupplierRef = static function ($rawEmitter, string $sourceLabel) use (&$supplierRefs): void {
        $rawEmitter = trim((string) $rawEmitter);
        $supplierNif = extractVatNumber($rawEmitter);
        if ($supplierNif === '') {
            return;
        }

        if (!isset($supplierRefs[$supplierNif])) {
            $supplierRefs[$supplierNif] = [
                'raw' => $rawEmitter,
                'sources' => [],
            ];
        }

        if ($sourceLabel !== '' && !in_array($sourceLabel, $supplierRefs[$supplierNif]['sources'], true)) {
            $supplierRefs[$supplierNif]['sources'][] = $sourceLabel;
        }
    };

    if ($companyNif !== '') {
        $nifRegex = '(^|[^0-9])' . $companyNif . '([^0-9]|$)';

        if (hasTable('supplier_documents') && hasColumn('supplier_documents', 'emitter') && hasColumn('supplier_documents', 'acquirer')) {
            $stmt = $pdo->prepare('SELECT DISTINCT emitter FROM supplier_documents WHERE acquirer = ? OR acquirer REGEXP ?');
            $stmt->execute([$companyNif, $nifRegex]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rawEmitter) {
                $addSupplierRef($rawEmitter, 'Documentos');
            }
        }

        if (hasTable('accounting_classifications') && hasColumn('accounting_classifications', 'emitter') && hasColumn('accounting_classifications', 'acquirer')) {
            $stmt = $pdo->prepare('SELECT DISTINCT emitter FROM accounting_classifications WHERE acquirer = ? OR acquirer REGEXP ?');
            $stmt->execute([$companyNif, $nifRegex]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rawEmitter) {
                $addSupplierRef($rawEmitter, 'Classificacoes');
            }
        }

        if (hasTable('accounting_imports') && hasColumn('accounting_imports', 'field_A') && hasColumn('accounting_imports', 'field_B')) {
            $where = 'field_B = ? OR field_B REGEXP ?';
            $params = [$companyNif, $nifRegex];
            if (hasColumn('accounting_imports', 'field_C')) {
                $where = '(' . $where . ') OR field_C = ? OR field_C REGEXP ?';
                $params[] = $companyNif;
                $params[] = $nifRegex;
            }
            $stmt = $pdo->prepare('SELECT DISTINCT field_A FROM accounting_imports WHERE ' . $where);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $rawEmitter) {
                $addSupplierRef($rawEmitter, 'Importacoes');
            }
        }
    }

    $selectColumns = 'id, nif, name, erp_database, erp_client_code, entity_type';
    if ($emitterTypeColumn !== '') {
        $selectColumns .= ', ' . $emitterTypeColumn . ' AS emitter_type';
    }

    $entitiesByNif = [];
    if ($companyDatabase !== '') {
        $stmt = $pdo->prepare(
            'SELECT ' . $selectColumns . ' FROM accounting_entities WHERE entity_type = ? AND erp_database = ? ORDER BY name ASC, nif ASC'
        );
        $stmt->execute(['emitter', $companyDatabase]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $supplierNif = extractVatNumber((string) ($row['nif'] ?? ''));
            if ($supplierNif === '') {
                continue;
            }
            $row['sources'] = ['Base ERP'];
            $entitiesByNif[$supplierNif] = $row;
        }
    }

    if ($supplierRefs) {
        $nifs = array_keys($supplierRefs);
        $placeholders = implode(',', array_fill(0, count($nifs), '?'));
        $stmt = $pdo->prepare(
            'SELECT ' . $selectColumns . ' FROM accounting_entities WHERE entity_type = ? AND nif IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge(['emitter'], $nifs));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $supplierNif = extractVatNumber((string) ($row['nif'] ?? ''));
            if ($supplierNif === '') {
                continue;
            }

            $existingSources = isset($entitiesByNif[$supplierNif]['sources']) && is_array($entitiesByNif[$supplierNif]['sources'])
                ? $entitiesByNif[$supplierNif]['sources']
                : [];
            $refSources = $supplierRefs[$supplierNif]['sources'] ?? [];
            $row['sources'] = array_values(array_unique(array_merge($existingSources, $refSources)));
            $entitiesByNif[$supplierNif] = $row;
        }

        foreach ($supplierRefs as $supplierNif => $ref) {
            if (isset($entitiesByNif[$supplierNif])) {
                continue;
            }
            $raw = trim((string) ($ref['raw'] ?? ''));
            $entitiesByNif[$supplierNif] = [
                'id' => 0,
                'nif' => $supplierNif,
                'name' => deriveEntityNameFromField($raw, $supplierNif),
                'erp_database' => '',
                'erp_client_code' => '',
                'entity_type' => 'emitter',
                'emitter_type' => 0,
                'sources' => $ref['sources'] ?? [],
            ];
        }
    }

    if ($entitiesByNif) {
        $instructionMap = loadAccountingEntityAiInstructionMap($pdo, $companyNif, array_keys($entitiesByNif));
        foreach ($entitiesByNif as $supplierNif => $entity) {
            $entitiesByNif[$supplierNif]['ai_instructions'] = (string) ($instructionMap[$supplierNif] ?? '');
        }
    }

    $entities = array_values($entitiesByNif);
    usort($entities, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $entities;
}

function loadAccountingEntityAiInstructionMap(PDO $pdo, string $acquirerNif, array $emitterNifs): array {
    $acquirerNif = extractVatNumber($acquirerNif);
    $emitterNifs = array_values(array_unique(array_filter(array_map(static function ($value): string {
        return extractVatNumber((string) $value);
    }, $emitterNifs), static function ($value): bool {
        return $value !== '';
    })));

    if ($acquirerNif === '' || !$emitterNifs || !hasTable('accounting_entity_ai_instructions')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($emitterNifs), '?'));
    $stmt = $pdo->prepare(
        'SELECT emitter_nif, instructions
         FROM accounting_entity_ai_instructions
         WHERE acquirer_nif = ? AND emitter_nif IN (' . $placeholders . ')'
    );
    $stmt->execute(array_merge([$acquirerNif], $emitterNifs));

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $emitterNif = extractVatNumber((string) ($row['emitter_nif'] ?? ''));
        if ($emitterNif !== '') {
            $map[$emitterNif] = (string) ($row['instructions'] ?? '');
        }
    }

    return $map;
}

function buildAccountingEntityAiInstructionClientMap(array $supplierEntities): array {
    $map = [];
    foreach ($supplierEntities as $supplier) {
        $supplierNif = extractVatNumber((string) ($supplier['nif'] ?? ''));
        if ($supplierNif === '') {
            continue;
        }
        $map[$supplierNif] = (string) ($supplier['ai_instructions'] ?? '');
    }
    return $map;
}

if ($consultEntity) {
    // Contexto passado ao assistente AI flutuante: so o identificador
    // (nif/uuid) segue para o browser, os dados reais sao sempre resolvidos
    // no servidor a partir daqui (ver assistant-handler.php).
    $aiPageContext = [
        'type' => 'accounting_entity',
        'nif' => trim((string) ($consultEntity['nif'] ?? '')),
        'uuid' => trim((string) ($consultEntity['uuid'] ?? '')),
    ];
}

require_once __DIR__ . '/../footer.php';
