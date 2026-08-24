<?php
// Tarefa "Apuramento de IVA": colaboradores fecham, por empresa e por
// periodo (mensal ou trimestral consoante a empresa), o apuramento de IVA
// (permissao ctb_apuramento_iva, gerida na ficha da empresa em
// Entidades > Empresas > Admin). Administradores veem todas as empresas.
//
// A reconciliacao automatica campo-a-campo (Declaracao Periodica vs.
// Balancete), tal como existia na intranet legacy, depende de um endpoint
// ERP-SINC ainda nao disponivel (equivalente a declPeriodica/balancete).
// Por isso, para ja, o fecho do periodo e feito com introducao manual dos
// valores (a pagar / a recuperar). Ver contabilidade/APURAMENTO_IVA.md.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_apuramento_iva')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();

if (!hasTable('accounting_vat_settlements')) {
    http_response_code(500);
    echo 'A tabela accounting_vat_settlements ainda nao existe. Execute as migracoes.';
    exit;
}

function getIvaTaskEntities(PDO $pdo, bool $isAdmin, int $userId): array {
    $periodicityColumn = hasColumn('accounting_entities', 'vat_periodicity') ? 'vat_periodicity' : "'mensal'";
    if ($isAdmin) {
        $stmt = $pdo->query(
            "SELECT id, nif, name, $periodicityColumn AS vat_periodicity FROM accounting_entities
             WHERE entity_type = 'acquirer'
             ORDER BY name ASC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $stmt = $pdo->prepare(
        "SELECT ae.id, ae.nif, ae.name, ae.$periodicityColumn AS vat_periodicity
         FROM accounting_entities ae
         INNER JOIN accounting_entity_admin_task_permissions aep
             ON aep.accounting_entity_id = ae.id
         WHERE ae.entity_type = 'acquirer'
           AND aep.permission_key = 'ctb_apuramento_iva'
           AND aep.user_id = ?
         ORDER BY ae.name ASC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$entities = getIvaTaskEntities($pdo, $isAdmin, $userId);
$allowedEntityIds = array_map(static fn($row) => (int) $row['id'], $entities);
$entitiesById = [];
foreach ($entities as $entityRow) {
    $entitiesById[(int) $entityRow['id']] = $entityRow;
}

function buildVatPeriodLabel(string $periodType, int $year, int $ref): string {
    return $periodType === 'trimestral' ? "$year-T$ref" : sprintf('%04d-%02d', $year, $ref);
}

function getClosedVatPeriods(PDO $pdo, int $entityId): array {
    $stmt = $pdo->prepare(
        'SELECT period_label FROM accounting_vat_settlements WHERE accounting_entity_id = ?'
    );
    $stmt->execute([$entityId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

$feedback = null; // ['type' => 'success'|'danger', 'message' => string]
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strcasecmp((string) $_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;

function respondVatFieldFormulaAjax(bool $success, string $message, array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
        'csrf_token' => generateCsrfToken(true),
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($isAjaxRequest) {
            http_response_code(400);
            respondVatFieldFormulaAjax(false, 'Token CSRF inválido.');
        }
        http_response_code(400);
        exit('Token CSRF inválido');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'close_period') {
        $entityId = (int) ($_POST['entity_id'] ?? 0);

        if ($entityId <= 0 || !isset($entitiesById[$entityId])) {
            $feedback = ['type' => 'danger', 'message' => 'Empresa inválida ou sem permissão.'];
        } else {
            $entityRow = $entitiesById[$entityId];
            $periodType = ((string) $entityRow['vat_periodicity']) === 'trimestral' ? 'trimestral' : 'mensal';
            $periodYear = (int) ($_POST['period_year'] ?? 0);
            $periodRef = (int) ($_POST['period_ref'] ?? 0);
            $resultType = ($_POST['result_type'] ?? '') === 'credito' ? 'credito' : 'pagar';
            $valorPagar = (float) str_replace(',', '.', (string) ($_POST['valor_pagar'] ?? '0'));
            $valorRecuperar = (float) str_replace(',', '.', (string) ($_POST['valor_recuperar'] ?? '0'));
            $observacao = trim((string) ($_POST['observacao'] ?? ''));

            $maxRef = $periodType === 'trimestral' ? 4 : 12;
            if ($periodYear < 2000 || $periodYear > 2100 || $periodRef < 1 || $periodRef > $maxRef) {
                $feedback = ['type' => 'danger', 'message' => 'Período inválido.'];
            } else {
                $periodLabel = buildVatPeriodLabel($periodType, $periodYear, $periodRef);
                $stmt = $pdo->prepare(
                    'SELECT id FROM accounting_vat_settlements WHERE accounting_entity_id = ? AND period_label = ? LIMIT 1'
                );
                $stmt->execute([$entityId, $periodLabel]);
                if ($stmt->fetchColumn()) {
                    $feedback = ['type' => 'danger', 'message' => 'Este período já se encontra fechado para esta empresa.'];
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO accounting_vat_settlements
                            (accounting_entity_id, period_type, period_year, period_ref, period_label, result_type, valor_pagar, valor_recuperar, observacao, closed_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $entityId,
                        $periodType,
                        $periodYear,
                        $periodRef,
                        $periodLabel,
                        $resultType,
                        $resultType === 'pagar' ? $valorPagar : 0,
                        $resultType === 'credito' ? $valorRecuperar : 0,
                        $observacao !== '' ? $observacao : null,
                        $userId,
                    ]);
                    logAuditAction('create', 'accounting_vat_settlement', (int) $pdo->lastInsertId(), [
                        'accounting_entity_id' => $entityId,
                        'period_label' => $periodLabel,
                        'result_type' => $resultType,
                    ]);
                    $feedback = ['type' => 'success', 'message' => 'Período ' . $periodLabel . ' fechado com sucesso.'];
                }
            }
        }
    }

    if ($action === 'save_field_formula') {
        if (!$isAdmin) {
            if ($isAjaxRequest) {
                http_response_code(403);
                respondVatFieldFormulaAjax(false, 'Acesso negado.');
            }
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }

        $originalFieldNumber = (int) ($_POST['original_field_number'] ?? 0);
        $fieldNumber = (int) ($_POST['field_number'] ?? 0);
        $formula = trim((string) ($_POST['formula'] ?? ''));
        $formulaError = null;
        try {
            parseAccountingVatFieldFormula($formula);
        } catch (InvalidArgumentException $e) {
            $formulaError = $e->getMessage();
        }

        if ($fieldNumber <= 0) {
            $feedback = ['type' => 'danger', 'message' => 'Número de campo inválido.'];
            if ($isAjaxRequest) {
                respondVatFieldFormulaAjax(false, $feedback['message']);
            }
        } elseif ($formulaError !== null) {
            $feedback = ['type' => 'danger', 'message' => $formulaError];
            if ($isAjaxRequest) {
                respondVatFieldFormulaAjax(false, $formulaError);
            }
        } elseif (!hasTable('accounting_vat_field_formulas')) {
            $feedback = ['type' => 'danger', 'message' => 'A tabela accounting_vat_field_formulas ainda não existe. Execute as migrações.'];
            if ($isAjaxRequest) {
                respondVatFieldFormulaAjax(false, $feedback['message']);
            }
        } else {
            if ($originalFieldNumber > 0 && $originalFieldNumber !== $fieldNumber) {
                // Renumerar uma linha existente: confirmar que o novo numero nao colide com outra linha.
                $stmt = $pdo->prepare('SELECT id FROM accounting_vat_field_formulas WHERE field_number = ? LIMIT 1');
                $stmt->execute([$fieldNumber]);
                if ($stmt->fetchColumn()) {
                    $message = 'Já existe uma fórmula para o campo ' . $fieldNumber . '.';
                    if ($isAjaxRequest) {
                        respondVatFieldFormulaAjax(false, $message);
                    }
                    $feedback = ['type' => 'danger', 'message' => $message];
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE accounting_vat_field_formulas SET field_number = ?, formula = ?, updated_by = ? WHERE field_number = ?'
                    );
                    $stmt->execute([$fieldNumber, $formula, $userId, $originalFieldNumber]);
                    logAuditAction('update', 'accounting_vat_field_formula', $fieldNumber, [
                        'original_field_number' => $originalFieldNumber,
                        'field_number' => $fieldNumber,
                        'formula' => $formula,
                        'changed_by' => $userId,
                    ]);
                    $message = 'Fórmula do campo ' . $fieldNumber . ' guardada.';
                    if ($isAjaxRequest) {
                        respondVatFieldFormulaAjax(true, $message, ['field_number' => $fieldNumber, 'formula' => $formula, 'original_field_number' => $originalFieldNumber]);
                    }
                    $feedback = ['type' => 'success', 'message' => $message];
                }
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO accounting_vat_field_formulas (field_number, formula, updated_by)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE formula = VALUES(formula), updated_by = VALUES(updated_by)'
                );
                $stmt->execute([$fieldNumber, $formula, $userId]);
                logAuditAction('update', 'accounting_vat_field_formula', $fieldNumber, [
                    'field_number' => $fieldNumber,
                    'formula' => $formula,
                    'changed_by' => $userId,
                ]);
                $message = 'Fórmula do campo ' . $fieldNumber . ' guardada.';
                if ($isAjaxRequest) {
                    respondVatFieldFormulaAjax(true, $message, ['field_number' => $fieldNumber, 'formula' => $formula, 'original_field_number' => $originalFieldNumber]);
                }
                $feedback = ['type' => 'success', 'message' => $message];
            }
        }
    }

    if ($action === 'delete_field_formula') {
        if (!$isAdmin) {
            if ($isAjaxRequest) {
                http_response_code(403);
                respondVatFieldFormulaAjax(false, 'Acesso negado.');
            }
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }

        $fieldNumber = (int) ($_POST['field_number'] ?? 0);

        if ($fieldNumber <= 0 || !hasTable('accounting_vat_field_formulas')) {
            $message = 'Não foi possível eliminar a fórmula.';
            if ($isAjaxRequest) {
                respondVatFieldFormulaAjax(false, $message);
            }
            $feedback = ['type' => 'danger', 'message' => $message];
        } else {
            $stmt = $pdo->prepare('DELETE FROM accounting_vat_field_formulas WHERE field_number = ?');
            $stmt->execute([$fieldNumber]);
            logAuditAction('delete', 'accounting_vat_field_formula', $fieldNumber, [
                'field_number' => $fieldNumber,
                'changed_by' => $userId,
            ]);
            $message = 'Fórmula do campo ' . $fieldNumber . ' eliminada.';
            if ($isAjaxRequest) {
                respondVatFieldFormulaAjax(true, $message, ['field_number' => $fieldNumber]);
            }
            $feedback = ['type' => 'success', 'message' => $message];
        }
    }
}

$closedPeriodsByEntity = [];
foreach ($entities as $entityRow) {
    $closedPeriodsByEntity[(int) $entityRow['id']] = getClosedVatPeriods($pdo, (int) $entityRow['id']);
}

$settlementsHistory = [];
if ($allowedEntityIds) {
    $placeholders = implode(',', array_fill(0, count($allowedEntityIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT s.*, u.name AS closed_by_name, u.username AS closed_by_username
         FROM accounting_vat_settlements s
         LEFT JOIN users u ON u.id = s.closed_by
         WHERE s.accounting_entity_id IN ($placeholders)
         ORDER BY s.created_at DESC
         LIMIT 100"
    );
    $stmt->execute($allowedEntityIds);
    $settlementsHistory = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$vatFieldFormulasTableExists = hasTable('accounting_vat_field_formulas');
$vatFieldFormulas = [];
if ($isAdmin && $vatFieldFormulasTableExists) {
    $stmt = $pdo->query('SELECT field_number, formula FROM accounting_vat_field_formulas ORDER BY field_number ASC');
    $vatFieldFormulas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$useDataTables = false;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Apuramento de IVA</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($feedback['message']); ?>
</div>
<?php endif; ?>

<?php if (!$entities): ?>
<div class="row">
    <div class="col-md-12">
        <div class="alert alert-info">
            Não tem empresas atribuídas para esta tarefa. Contacte o administrador.
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-calculator"></i> Apuramento de IVA</h2>
                <style>
                    .iva-title-toolbox { min-width: auto; display: flex !important; align-items: center; gap: 10px; }
                    .iva-title-toolbox > li { display: flex; align-items: center; }
                    .iva-periodicity-field { display: flex; align-items: center; gap: 8px; }
                    .iva-periodicity-field label {
                        margin: 0; white-space: nowrap; font-size: 13px; font-weight: 600; color: #73879c;
                    }
                    .iva-periodicity-field select {
                        height: 30px; padding: 4px 8px; font-size: 13px; line-height: 1.4;
                        width: auto; min-width: 130px; box-sizing: border-box;
                    }
                </style>
                <ul class="nav navbar-right panel_toolbox iva-title-toolbox">
                    <?php if ($entities):
                        $currentYear = (int) date('Y');
                    ?>
                    <li class="iva-periodicity-field">
                        <label for="iva-global-year">Ano</label>
                        <select id="iva-global-year" class="form-control">
                            <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
                                <option value="<?= $y; ?>"><?= $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </li>
                    <li class="iva-periodicity-field iva-global-month-field">
                        <label for="iva-global-month">Mês</label>
                        <select id="iva-global-month" class="form-control">
                            <option value="">-</option>
                            <?php foreach (range(1, 12) as $m): ?>
                                <option value="<?= $m; ?>"><?= sprintf('%02d', $m); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </li>
                    <li class="iva-periodicity-field iva-global-quarter-field">
                        <label for="iva-global-quarter">Trimestre</label>
                        <select id="iva-global-quarter" class="form-control">
                            <option value="">-</option>
                            <?php foreach ([1, 2, 3, 4] as $q): ?>
                                <option value="<?= $q; ?>"><?= $q; ?>º Trimestre</option>
                            <?php endforeach; ?>
                        </select>
                    </li>
                    <?php endif; ?>
                    <li class="iva-periodicity-field">
                        <label for="iva-periodicity-filter">Periodicidade</label>
                        <select id="iva-periodicity-filter" class="form-control">
                            <option value="">Todos</option>
                            <option value="mensal">Mensal</option>
                            <option value="trimestral">Trimestral</option>
                        </select>
                    </li>
                    <?php if ($isAdmin): ?>
                    <li>
                        <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#iva-settings-modal" title="Configurações da tarefa">
                            <i class="fa fa-cog"></i>
                        </button>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <div class="alert alert-warning">
                    <strong>Reconciliação automática indisponível.</strong>
                    A comparação campo-a-campo entre a Declaração Periódica e a
                    contabilidade (equivalente ao "Apuramento IVA" da intranet
                    legacy) depende de um endpoint do webservice ERP-SINC que
                    ainda não existe (equivalente a <code>declPeriodica</code> /
                    <code>balancete</code>). Até esse endpoint estar disponível,
                    o fecho do período é feito com introdução manual dos
                    valores apurados.
                </div>

                <?php if ($entities): ?>
                <?php foreach ($entities as $entityRow):
                    $entityId = (int) $entityRow['id'];
                    $periodType = ((string) $entityRow['vat_periodicity']) === 'trimestral' ? 'trimestral' : 'mensal';
                    $closedLabels = $closedPeriodsByEntity[$entityId] ?? [];
                ?>
                <div class="erp-form-section vat-entity-section" data-vat-periodicity="<?= $periodType; ?>" style="margin-bottom: 22px; padding-bottom: 18px; border-bottom: 1px solid #e6e9ed;">
                    <h4 style="margin-top: 0;">
                        <?= htmlspecialchars((string) $entityRow['name']); ?>
                        <small class="text-muted">NIF <?= htmlspecialchars((string) $entityRow['nif']); ?> &middot; periodicidade <?= $periodType; ?></small>
                    </h4>
                    <form method="post" class="form-inline vat-close-form" data-period-type="<?= $periodType; ?>" style="display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
                        <input type="hidden" name="action" value="close_period">
                        <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
                        <input type="hidden" name="period_year" class="vat-close-period-year">
                        <input type="hidden" name="period_ref" class="vat-close-period-ref">

                        <div>
                            <label class="control-label" style="display: block; visibility: hidden;">Período</label>
                            <span class="vat-close-period-display label label-default" style="display: inline-block; padding: 6px 10px; font-size: 13px;"></span>
                        </div>

                        <div>
                            <label class="control-label" style="display: block;">Resultado</label>
                            <select name="result_type" class="form-control vat-result-type">
                                <option value="pagar">A pagar</option>
                                <option value="credito">Em crédito</option>
                            </select>
                        </div>

                        <div class="vat-field-pagar">
                            <label class="control-label" style="display: block;">Valor a pagar (€)</label>
                            <input type="text" name="valor_pagar" class="form-control" style="width: 130px;" placeholder="0.00">
                        </div>

                        <div class="vat-field-recuperar" style="display: none;">
                            <label class="control-label" style="display: block;">Valor a recuperar (€)</label>
                            <input type="text" name="valor_recuperar" class="form-control" style="width: 130px;" placeholder="0.00">
                        </div>

                        <div style="flex: 1 1 220px;">
                            <label class="control-label" style="display: block;">Observação</label>
                            <input type="text" name="observacao" class="form-control" placeholder="Opcional">
                        </div>

                        <div>
                            <button type="submit" class="btn btn-success">Fechar período</button>
                        </div>
                    </form>
                    <?php if ($closedLabels): ?>
                    <p class="text-muted" style="margin: 10px 0 0;">
                        Períodos já fechados: <?= htmlspecialchars(implode(', ', $closedLabels)); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <h4>Histórico de fechos</h4>
                <?php if (!$settlementsHistory): ?>
                <p class="text-muted">Sem períodos fechados ainda.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped jambo_table">
                        <thead>
                            <tr>
                                <th>Empresa</th>
                                <th>Período</th>
                                <th>Resultado</th>
                                <th>A pagar</th>
                                <th>A recuperar</th>
                                <th>Fechado por</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($settlementsHistory as $settlement):
                                $settlementEntity = $entitiesById[(int) $settlement['accounting_entity_id']] ?? null;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) ($settlementEntity['name'] ?? '—')); ?></td>
                                <td><?= htmlspecialchars((string) $settlement['period_label']); ?></td>
                                <td><?= $settlement['result_type'] === 'credito' ? 'Em crédito' : 'A pagar'; ?></td>
                                <td><?= number_format((float) $settlement['valor_pagar'], 2, ',', '.'); ?> €</td>
                                <td><?= number_format((float) $settlement['valor_recuperar'], 2, ',', '.'); ?> €</td>
                                <td><?= htmlspecialchars((string) ($settlement['closed_by_name'] ?: $settlement['closed_by_username'] ?: '—')); ?></td>
                                <td><?= htmlspecialchars((string) $settlement['created_at']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="iva-settings-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Configurações — Apuramento de IVA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted" style="margin-bottom: 18px;">
                    Formato: termos <code>C&lt;conta&gt;[cre|deb]&lt;+|-&gt;</code>
                    concatenados sem espaços — <code>cre</code>/<code>deb</code>
                    usam só o saldo credor/devedor da conta (omitir para saldo
                    líquido). Ex.: <code>C2432319cre-C243234deb+</code>.
                </p>

                <?php if (!$vatFieldFormulasTableExists): ?>
                <div class="alert alert-warning">
                    A tabela <code>accounting_vat_field_formulas</code> ainda não existe. Execute as migrações.
                </div>
                <?php else: ?>
                <style>
                    .vat-field-formula-table th, .vat-field-formula-table td { vertical-align: middle; }
                    .vat-field-formula-table td.vat-field-formula-action { text-align: center; white-space: nowrap; }
                    .vat-field-formula-table td.vat-field-formula-action .btn { margin: 0 2px; }
                    .vat-field-formula-table input[type="number"].vat-field-formula-number-input { text-align: center; }
                    .vat-field-formula-new-row td { background: #f9fafc; }
                </style>
                <div id="iva-settings-feedback"></div>
                <div class="table-responsive">
                    <table class="table table-striped jambo_table vat-field-formula-table">
                        <thead>
                            <tr>
                                <th style="width: 90px;">Campo</th>
                                <th>Fórmula (ex: C2432113+ soma a conta 2432113)</th>
                                <th style="width: 120px;">Ação</th>
                            </tr>
                        </thead>
                        <tbody id="vat-field-formula-tbody">
                            <?php foreach ($vatFieldFormulas as $formulaRow): ?>
                            <tr class="vat-field-formula-row" data-original-field-number="<?= (int) $formulaRow['field_number']; ?>">
                                <td>
                                    <input type="number" class="form-control vat-field-formula-number-input" min="1" value="<?= (int) $formulaRow['field_number']; ?>">
                                </td>
                                <td>
                                    <input type="text" class="form-control vat-field-formula-formula-input" value="<?= htmlspecialchars((string) $formulaRow['formula']); ?>" placeholder="ex: C2432113+ soma a conta 2432113">
                                </td>
                                <td class="vat-field-formula-action">
                                    <button type="button" class="btn btn-primary btn-sm vat-field-formula-save-btn">Editar</button>
                                    <button type="button" class="btn btn-danger btn-sm vat-field-formula-delete-btn" title="Eliminar"><i class="fa fa-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <tr class="vat-field-formula-new-row">
                                <td>
                                    <input type="number" class="form-control vat-field-formula-number-input" min="1" placeholder="nº">
                                </td>
                                <td>
                                    <input type="text" class="form-control vat-field-formula-formula-input" placeholder="ex: C2432113+ soma a conta 2432113">
                                </td>
                                <td class="vat-field-formula-action">
                                    <button type="button" class="btn btn-success btn-sm vat-field-formula-add-btn" title="Adicionar campo"><i class="fa fa-plus"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <script>
                (function () {
                    var csrfToken = <?= json_encode(generateCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;
                    var feedbackEl = document.getElementById('iva-settings-feedback');
                    var tbody = document.getElementById('vat-field-formula-tbody');

                    function showFeedback(type, message) {
                        feedbackEl.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible" role="alert" style="margin-bottom: 14px;">' +
                            '<button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>' +
                            message + '</div>';
                    }

                    function post(payload) {
                        var formData = new FormData();
                        Object.keys(payload).forEach(function (key) { formData.append(key, payload[key]); });
                        formData.append('csrf_token', csrfToken);
                        return fetch(window.location.href, {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' },
                            body: formData
                        }).then(function (response) { return response.json(); }).then(function (data) {
                            if (data.csrf_token) { csrfToken = data.csrf_token; }
                            return data;
                        });
                    }

                    function buildRow(fieldNumber, formula) {
                        var tr = document.createElement('tr');
                        tr.className = 'vat-field-formula-row';
                        tr.dataset.originalFieldNumber = fieldNumber;
                        tr.innerHTML =
                            '<td><input type="number" class="form-control vat-field-formula-number-input" min="1" value="' + fieldNumber + '"></td>' +
                            '<td><input type="text" class="form-control vat-field-formula-formula-input" value="' + formula.replace(/"/g, '&quot;') + '" placeholder="ex: C2432113+ soma a conta 2432113"></td>' +
                            '<td class="vat-field-formula-action">' +
                                '<button type="button" class="btn btn-primary btn-sm vat-field-formula-save-btn">Editar</button>' +
                                '<button type="button" class="btn btn-danger btn-sm vat-field-formula-delete-btn" title="Eliminar"><i class="fa fa-trash"></i></button>' +
                            '</td>';
                        return tr;
                    }

                    tbody.addEventListener('click', function (e) {
                        var saveBtn = e.target.closest('.vat-field-formula-save-btn');
                        var deleteBtn = e.target.closest('.vat-field-formula-delete-btn');
                        var addBtn = e.target.closest('.vat-field-formula-add-btn');

                        if (saveBtn) {
                            var row = saveBtn.closest('tr');
                            var originalFieldNumber = row.dataset.originalFieldNumber;
                            var fieldNumber = row.querySelector('.vat-field-formula-number-input').value;
                            var formula = row.querySelector('.vat-field-formula-formula-input').value;
                            post({ action: 'save_field_formula', original_field_number: originalFieldNumber, field_number: fieldNumber, formula: formula })
                                .then(function (data) {
                                    showFeedback(data.success ? 'success' : 'danger', data.message);
                                    if (data.success) {
                                        row.dataset.originalFieldNumber = data.field_number;
                                    }
                                })
                                .catch(function () { showFeedback('danger', 'Erro de comunicação. Tente novamente.'); });
                            return;
                        }

                        if (deleteBtn) {
                            var delRow = deleteBtn.closest('tr');
                            var delFieldNumber = delRow.dataset.originalFieldNumber;
                            if (!window.confirm('Eliminar a fórmula do campo ' + delFieldNumber + '?')) { return; }
                            post({ action: 'delete_field_formula', field_number: delFieldNumber })
                                .then(function (data) {
                                    showFeedback(data.success ? 'success' : 'danger', data.message);
                                    if (data.success) { delRow.remove(); }
                                })
                                .catch(function () { showFeedback('danger', 'Erro de comunicação. Tente novamente.'); });
                            return;
                        }

                        if (addBtn) {
                            var newRow = addBtn.closest('tr');
                            var newFieldNumber = newRow.querySelector('.vat-field-formula-number-input').value;
                            var newFormula = newRow.querySelector('.vat-field-formula-formula-input').value;
                            post({ action: 'save_field_formula', original_field_number: 0, field_number: newFieldNumber, formula: newFormula })
                                .then(function (data) {
                                    showFeedback(data.success ? 'success' : 'danger', data.message);
                                    if (data.success) {
                                        tbody.insertBefore(buildRow(data.field_number, data.formula), newRow);
                                        newRow.querySelector('.vat-field-formula-number-input').value = '';
                                        newRow.querySelector('.vat-field-formula-formula-input').value = '';
                                    }
                                })
                                .catch(function () { showFeedback('danger', 'Erro de comunicação. Tente novamente.'); });
                        }
                    });
                })();
                </script>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.vat-close-form').forEach(function (form) {
        var resultSelect = form.querySelector('.vat-result-type');
        var pagarField = form.querySelector('.vat-field-pagar');
        var recuperarField = form.querySelector('.vat-field-recuperar');
        function toggleFields() {
            var isCredito = resultSelect.value === 'credito';
            pagarField.style.display = isCredito ? 'none' : '';
            recuperarField.style.display = isCredito ? '' : 'none';
        }
        resultSelect.addEventListener('change', toggleFields);
        toggleFields();
    });

    var entitySections = document.querySelectorAll('.vat-entity-section');
    var periodicityFilter = document.getElementById('iva-periodicity-filter');
    var yearSelect = document.getElementById('iva-global-year');
    var monthSelect = document.getElementById('iva-global-month');
    var quarterSelect = document.getElementById('iva-global-quarter');
    var monthField = document.querySelector('.iva-global-month-field');
    var quarterField = document.querySelector('.iva-global-quarter-field');
    var monthNames = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    function applyGlobalPeriod() {
        document.querySelectorAll('.vat-close-form').forEach(function (form) {
            var isTrimestral = form.dataset.periodType === 'trimestral';
            var year = yearSelect ? yearSelect.value : '';
            var ref = isTrimestral ? (quarterSelect ? quarterSelect.value : '') : (monthSelect ? monthSelect.value : '');
            form.querySelector('.vat-close-period-year').value = ref ? year : '';
            form.querySelector('.vat-close-period-ref').value = ref;
            var display = form.querySelector('.vat-close-period-display');
            if (display) {
                display.textContent = !ref ? 'Selecionar período' : (isTrimestral ? (ref + 'º Trimestre ' + year) : (monthNames[parseInt(ref, 10)] + ' ' + year));
            }
        });
    }

    function applyPeriodicityFilter() {
        var value = periodicityFilter ? periodicityFilter.value : '';
        entitySections.forEach(function (section) {
            section.style.display = (value === '' || section.dataset.vatPeriodicity === value) ? '' : 'none';
        });
        if (monthField) { monthField.style.display = (value === 'trimestral') ? 'none' : ''; }
        if (quarterField) { quarterField.style.display = (value === 'mensal') ? 'none' : ''; }
    }

    if (yearSelect) { yearSelect.addEventListener('change', applyGlobalPeriod); }
    if (monthSelect) {
        monthSelect.addEventListener('change', function () {
            if (monthSelect.value && quarterSelect) { quarterSelect.value = ''; }
            applyGlobalPeriod();
        });
    }
    if (quarterSelect) {
        quarterSelect.addEventListener('change', function () {
            if (quarterSelect.value && monthSelect) { monthSelect.value = ''; }
            applyGlobalPeriod();
        });
    }
    if (periodicityFilter) { periodicityFilter.addEventListener('change', function () { applyPeriodicityFilter(); applyGlobalPeriod(); }); }

    applyPeriodicityFilter();
    applyGlobalPeriod();
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
