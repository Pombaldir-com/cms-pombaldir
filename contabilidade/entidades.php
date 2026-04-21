<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
requireRole(2);

if (!isModuleActive('contabilidade')) {
    http_response_code(404);
    exit('Modulo nao ativo.');
}

$useDataTables = true;
$useSwitchery = true;

$pdo = getPDO();
$hasBankEntityColumn = hasColumn('accounting_entities', 'is_bank_entity');
$user = currentUser();
$isSuperAdmin = ((int) ($user['role'] ?? 3)) === 1;
$typeSlug = trim((string) ($_GET['tipo'] ?? 'empresas'));
$typeSlug = $typeSlug !== '' ? $typeSlug : 'empresas';
$entityTypeMap = [
    'empresas' => 'acquirer',
];
$entityType = $entityTypeMap[$typeSlug] ?? $typeSlug;
$supplierCompanyId = isset($_GET['fornecedores']) ? (int) $_GET['fornecedores'] : 0;
$isSupplierList = $typeSlug === 'empresas' && $supplierCompanyId > 0;
$csrfToken = generateCsrfToken();

$flashType = trim((string) ($_GET['status'] ?? ''));
$flashMessage = trim((string) ($_GET['msg'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['csrf_token'] ?? ''));
    if (!validateCsrfToken($token)) {
        http_response_code(400);
        exit('Token invalido.');
    }

    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'save-ai-instructions') {
        header('Content-Type: application/json; charset=utf-8');

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

        $stmt = $pdo->prepare('SELECT id, nif, name FROM accounting_entities WHERE id = ? AND entity_type = ? LIMIT 1');
        $stmt->execute([$supplierCompanyId, 'acquirer']);
        $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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

    if ($action === 'toggle-bank-entity') {
        if (!$hasBankEntityColumn) {
            header('Location: ' . buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyId, 'error', 'Coluna de entidade bancaria em falta.'));
            exit;
        }

        $entityId = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
        $isBankEntity = trim((string) ($_POST['is_bank_entity'] ?? '0')) === '1' ? 1 : 0;
        if ($entityId <= 0) {
            header('Location: ' . buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyId, 'error', 'Entidade invalida.'));
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, nif, name, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $stmt->execute([$entityId]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entity) {
            header('Location: ' . buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyId, 'error', 'Entidade nao encontrada.'));
            exit;
        }

        $expectedBankEntityType = $isSupplierList ? 'emitter' : $entityType;
        if (($entity['entity_type'] ?? '') !== $expectedBankEntityType) {
            header('Location: ' . buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyId, 'error', 'Tipo de entidade invalido.'));
            exit;
        }

        $stmt = $pdo->prepare('UPDATE accounting_entities SET is_bank_entity = ? WHERE id = ?');
        $stmt->execute([$isBankEntity, $entityId]);
        logAuditAction(
            'update',
            'accounting_entity',
            $entityId,
            [
                'field' => 'is_bank_entity',
                'value' => $isBankEntity,
                'nif' => trim((string) ($entity['nif'] ?? '')),
                'entity_type' => $expectedBankEntityType,
            ]
        );

        header('Location: ' . buildAccountingEntitiesReturnUrl($typeSlug, $supplierCompanyId, 'success', 'Entidade bancaria atualizada.'));
        exit;
    }

    if ($action === 'delete-entity') {
        if (!$isSuperAdmin) {
            http_response_code(403);
            exit('Sem permissoes para eliminar entidades.');
        }

        $entityId = isset($_POST['entity_id']) ? (int) $_POST['entity_id'] : 0;
        if ($entityId <= 0) {
            header('Location: ' . BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '?status=error&msg=' . rawurlencode('Entidade invalida.'));
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, nif, name, entity_type FROM accounting_entities WHERE id = ? LIMIT 1');
        $stmt->execute([$entityId]);
        $entity = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$entity) {
            header('Location: ' . BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '?status=error&msg=' . rawurlencode('Entidade nao encontrada.'));
            exit;
        }

        if (($entity['entity_type'] ?? '') !== $entityType) {
            header('Location: ' . BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '?status=error&msg=' . rawurlencode('Tipo de entidade invalido.'));
            exit;
        }

        $nif = trim((string) ($entity['nif'] ?? ''));
        if ($nif === '') {
            header('Location: ' . BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '?status=error&msg=' . rawurlencode('NIF em falta para eliminar entidade.'));
            exit;
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
            header('Location: ' . BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '?status=success&msg=' . rawurlencode($okMessage));
            exit;
        } catch (Throwable $e) {
            $errorMessage = 'Falha ao eliminar a empresa: ' . $e->getMessage();
            header('Location: ' . BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug) . '?status=error&msg=' . rawurlencode($errorMessage));
            exit;
        }
    }
}

$consultId = isset($_GET['consulta']) ? (int) $_GET['consulta'] : 0;
$consultEntity = null;
$erpClient = null;
$erpClientForm = [
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
if ($consultId > 0) {
    $stmt = $pdo->prepare(
        "SELECT id, nif, name, erp_database, erp_client_code FROM accounting_entities WHERE id = ? AND entity_type = ? LIMIT 1"
    );
    $stmt->execute([$consultId, $entityType]);
    $consultEntity = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($consultEntity) {
        $erpDatabase = normalizeAccountingEntityDatabaseKey(getErpDefaultCompanyIdentifier());
        $consultNif = trim((string) ($consultEntity['nif'] ?? ''));
        if ($consultNif === '') {
            $erpError = 'Entidade sem NIF definido.';
        } else {
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
        $zonesResponse = fetchErpTableData('tabelas/zonas', true);
        if (!empty($zonesResponse['error'])) {
            $zoneError = (string) $zonesResponse['error'];
        } else {
            $erpZones = $zonesResponse['data'] ?? [];
        }

        $subzonesResponse = fetchErpTableData('tabelas/subzonas', true);
        if (!empty($subzonesResponse['error'])) {
            $subzoneError = (string) $subzonesResponse['error'];
        } else {
            $erpSubzones = $subzonesResponse['data'] ?? [];
        }
    }
}

if ($consultEntity && $erpError === '' && !$erpClient) {
    $fallbackNif = trim((string) ($consultEntity['nif'] ?? ''));
    $erpError = $fallbackNif !== ''
        ? ('O cliente com o NIF ' . $fallbackNif . ' não existe no ERP.')
        : 'O cliente não existe no ERP.';
}
if ($consultId > 0 && !$consultEntity && $erpError === '') {
    $erpError = 'Entidade não encontrada.';
}

$entitySelectColumns = 'id, nif, name, erp_database, erp_client_code';
if ($hasBankEntityColumn) {
    $entitySelectColumns .= ', is_bank_entity';
}
$stmt = $pdo->prepare(
    "SELECT $entitySelectColumns FROM accounting_entities WHERE entity_type = ? ORDER BY name ASC, nif ASC"
);
$stmt->execute([$entityType]);
$entities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$supplierCompany = null;
$supplierEntities = [];
if ($isSupplierList) {
    $supplierCompanyColumns = 'id, nif, name, erp_database, erp_client_code';
    if ($hasBankEntityColumn) {
        $supplierCompanyColumns .= ', is_bank_entity';
    }
    $stmt = $pdo->prepare(
        "SELECT $supplierCompanyColumns FROM accounting_entities WHERE id = ? AND entity_type = ? LIMIT 1"
    );
    $stmt->execute([$supplierCompanyId, 'acquirer']);
    $supplierCompany = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($supplierCompany) {
        $supplierEntities = loadAccountingSupplierEntitiesForCompany($pdo, $supplierCompany, $hasBankEntityColumn);
    } elseif ($flashMessage === '') {
        $flashType = 'error';
        $flashMessage = 'Empresa nao encontrada.';
    }
}

require_once __DIR__ . '/../header.php';
?>
<div class="container-fluid">
    <?php if ($flashMessage !== ''): ?>
        <div class="x_panel">
            <div class="x_content">
                <div class="alert <?= $flashType === 'success' ? 'alert-success' : 'alert-danger'; ?>" role="alert" style="margin-bottom: 0;">
                    <?= htmlspecialchars($flashMessage); ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
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
            <?php if ($consultId > 0 && ($erpError !== '' || !$erpClient)): ?>
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
                    <div class="x_panel">
                        <div class="x_title">
                            <h2><i class="fa fa-cloud"></i> Dados do Cliente</h2>
                            <div class="clearfix"></div>
                        </div>
                        <div class="x_content">
                            <ul class="nav nav-tabs bar_tabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" data-bs-toggle="tab" href="#cliente-detalhes" role="tab">Detalhes</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link disabled" href="javascript:void(0)" tabindex="-1" aria-disabled="true">Outro</a>
                                </li>
                            </ul>
                            <div class="tab-content">
                                <div class="tab-pane fade active show" id="cliente-detalhes" role="tabpanel">
                                    <form class="form-horizontal mt-3">
                                        <div class="row">
                                    <div class="col-md-2 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">NIF</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['nif']); ?>" readonly>
                                        </div>
                                    </div>


   <div class="col-md-7 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">Nome</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['name']); ?>" readonly>
                                        </div>
                                    </div>

<div class="col-md-1 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">Nº Cliente</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['number']); ?>" readonly>
                                        </div>
                                    </div>


                                    
                                 

                                    <div class="col-md-2 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">Cons. Final</label>
                                            <div>
                                                <input type="checkbox" class="js-switch" <?= $erpClientForm['cons_final'] ? 'checked' : ''; ?> disabled>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">Morada</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['address']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">&nbsp;</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['address2']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-2 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">CP</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['postal_code']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">Localidade</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['city']); ?>" readonly>
                                        </div>
                                    </div>
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
                                    <div class="col-md-3 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">Zona</label>
                                            <select class="form-control" disabled>
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
                                            <select class="form-control" disabled>
                                                <option value="">-</option>
                                                <?php foreach ($filteredSubzoneOptions as $code => $meta): ?>
                                                    <option value="<?= htmlspecialchars($code); ?>" <?= strtoupper($code) === $selectedSubzone ? 'selected' : ''; ?>>
                                                        <?= htmlspecialchars((string) ($meta['label'] ?? $code)); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">Telefone</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['phone']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">Telemovel</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['mobile']); ?>" readonly>
                                        </div>
                                    </div>
                                    <div class="col-md-3 col-sm-12">
                                        <div class="form-group">
                                            <label class="control-label">E-mail</label>
                                            <input type="text" class="form-control" value="<?= htmlspecialchars((string) $erpClientForm['email']); ?>" readonly>
                                        </div>
                                    </div>
                                    
                                    
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="mt-3">
                                <a href="javascript:history.back()" class="btn btn-default">
                                    <i class="fa fa-arrow-left"></i> Voltar
                                </a>
                            </div>
                        </div>
                    </div>
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
                            <?php if ($hasBankEntityColumn): ?>
                                <th data-orderable="false">Banco</th>
                            <?php endif; ?>
                            <th data-orderable="false" class="text-right">IA</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($supplierEntities as $supplier): ?>
                        <tr>
                            <td><?= htmlspecialchars($supplier['nif'] ?? ''); ?></td>
                            <td><?= htmlspecialchars($supplier['name'] ?? ''); ?></td>
                            <?php if ($hasBankEntityColumn): ?>
                                <td>
                                    <?php if (!empty($supplier['id'])): ?>
                                        <form method="post" style="margin: 0;">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="action" value="toggle-bank-entity">
                                            <input type="hidden" name="entity_id" value="<?= (int) $supplier['id']; ?>">
                                            <input type="hidden" name="is_bank_entity" value="<?= !empty($supplier['is_bank_entity']) ? '0' : '1'; ?>">
                                            <input type="checkbox" class="js-switch" <?= !empty($supplier['is_bank_entity']) ? 'checked' : ''; ?> onchange="this.form.submit();">
                                        </form>
                                    <?php else: ?>
                                        <span class="label label-default">Sem ficha local</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td class="text-right">
                                <button type="button"
                                        class="btn btn-xs btn-primary accounting-entity-ai-btn"
                                        data-emitter-nif="<?= htmlspecialchars((string) ($supplier['nif'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-emitter-name="<?= htmlspecialchars((string) ($supplier['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        title="Instrucoes IA">
                                    <i class="fa fa-android" aria-hidden="true"></i> IA
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="modal fade" id="accountingEntityAiModal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <form id="accountingEntityAiForm">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="fa fa-robot"></i> Instrucoes IA</h5>
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
                                <a href="<?= BASE_URL ?>contabilidade/entidades/<?= rawurlencode($typeSlug); ?>/<?= (int) $entity['id']; ?>" class="btn btn-xs btn-primary">
                                    <i class="fa fa-search"></i> Consulta
                                </a>
                                <?php if ($typeSlug === 'empresas'): ?>
                                    <a href="<?= BASE_URL ?>contabilidade/entidades/<?= rawurlencode($typeSlug); ?>/<?= (int) $entity['id']; ?>/fornecedores" class="btn btn-xs btn-info">
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

function buildAccountingEntitiesReturnUrl(string $typeSlug, int $supplierCompanyId = 0, string $status = '', string $message = ''): string {
    $url = BASE_URL . 'contabilidade/entidades/' . rawurlencode($typeSlug);
    if ($supplierCompanyId > 0) {
        $url .= '/' . $supplierCompanyId . '/fornecedores';
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

function loadAccountingSupplierEntitiesForCompany(PDO $pdo, array $company, bool $hasBankEntityColumn): array {
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
    if ($hasBankEntityColumn) {
        $selectColumns .= ', is_bank_entity';
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
                'is_bank_entity' => 0,
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

require_once __DIR__ . '/../footer.php';
