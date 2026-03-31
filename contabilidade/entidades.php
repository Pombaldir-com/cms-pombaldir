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
$user = currentUser();
$isSuperAdmin = ((int) ($user['role'] ?? 3)) === 1;
$typeSlug = trim((string) ($_GET['tipo'] ?? 'empresas'));
$typeSlug = $typeSlug !== '' ? $typeSlug : 'empresas';
$entityTypeMap = [
    'empresas' => 'acquirer',
];
$entityType = $entityTypeMap[$typeSlug] ?? $typeSlug;
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
        $erpDatabase = resolveAccountingEntityDatabase($consultEntity);
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

$stmt = $pdo->prepare(
    "SELECT id, nif, name, erp_database, erp_client_code FROM accounting_entities WHERE entity_type = ? ORDER BY name ASC, nif ASC"
);
$stmt->execute([$entityType]);
$entities = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            <h2><i class="fa fa-building"></i> <?= htmlspecialchars(ucfirst($typeSlug)); ?></h2>
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
            <?php if (!$consultEntity): ?>
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

require_once __DIR__ . '/../footer.php';
