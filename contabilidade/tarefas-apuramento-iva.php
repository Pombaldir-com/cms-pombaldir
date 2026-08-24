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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
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
                <?php if ($isAdmin): ?>
                <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                    <li>
                        <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#iva-settings-modal" title="Configurações da tarefa">
                            <i class="fa fa-cog"></i>
                        </button>
                    </li>
                </ul>
                <?php endif; ?>
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
                    $currentYear = (int) date('Y');
                ?>
                <div class="erp-form-section" style="margin-bottom: 22px; padding-bottom: 18px; border-bottom: 1px solid #e6e9ed;">
                    <h4 style="margin-top: 0;">
                        <?= htmlspecialchars((string) $entityRow['name']); ?>
                        <small class="text-muted">NIF <?= htmlspecialchars((string) $entityRow['nif']); ?> &middot; periodicidade <?= $periodType; ?></small>
                    </h4>
                    <form method="post" class="form-inline vat-close-form" style="display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
                        <input type="hidden" name="action" value="close_period">
                        <input type="hidden" name="entity_id" value="<?= $entityId; ?>">

                        <div>
                            <label class="control-label" style="display: block;">Ano</label>
                            <select name="period_year" class="form-control">
                                <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
                                    <option value="<?= $y; ?>"><?= $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div>
                            <label class="control-label" style="display: block;"><?= $periodType === 'trimestral' ? 'Trimestre' : 'Mês'; ?></label>
                            <select name="period_ref" class="form-control">
                                <?php if ($periodType === 'trimestral'): ?>
                                    <?php foreach ([1, 2, 3, 4] as $q): ?>
                                        <option value="<?= $q; ?>"><?= $q; ?>º Trimestre</option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach (range(1, 12) as $m): ?>
                                        <option value="<?= $m; ?>"><?= sprintf('%02d', $m); ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
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
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Configurações — Apuramento de IVA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <p>
                    O mapeamento de contas por campo da Declaração Periódica de
                    IVA (equivalente à aba "DP IVA" da intranet legacy) ficará
                    disponível aqui assim que o webservice ERP-SINC expuser os
                    dados necessários (Declaração Periódica e Balancete).
                </p>
                <p class="text-muted">
                    A periodicidade de IVA (mensal/trimestral) de cada empresa
                    é definida na ficha da empresa, em Entidades &gt; separador
                    Admin.
                </p>
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
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
