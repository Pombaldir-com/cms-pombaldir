<?php
// Ecra "Ver detalhes" da tarefa "Apuramento de IVA": comparacao
// campo-a-campo (C{n}-DP vs. Ctr Ctb) por empresa/periodo, equivalente ao
// ecra de window.php?act=wkfloproc (task=6) da intranet legacy, seguido do
// resultado do periodo (a pagar / em credito, fase 8 do legacy) e do fecho.
//
// "Ctr Ctb" e calculado com as formulas de accounting_vat_field_formulas
// contra o balancete do ERP-SINC (GET contabilidade/saldos). "C{n}-DP" vem
// da Declaracao Periodica gerada no ERP (GET contabilidade/declperiodica);
// quando o ERP nao devolve DP para o periodo, os valores podem ser
// introduzidos manualmente (accounting_vat_settlement_field_values).
// Ver contabilidade/APURAMENTO_IVA.md.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/apuramento-iva-functions.php';

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

$entities = getIvaTaskEntities($pdo, $isAdmin, $userId);
$entitiesById = [];
foreach ($entities as $entityRow) {
    $entitiesById[(int) $entityRow['id']] = $entityRow;
}

$entityId = (int) ($_GET['entity_id'] ?? $_POST['entity_id'] ?? 0);
$entity = $entitiesById[$entityId] ?? null;

if (!$entity) {
    http_response_code(404);
    echo 'Empresa inválida ou sem permissão.';
    exit;
}

$periodType = ((string) $entity['vat_periodicity']) === 'trimestral' ? 'trimestral' : 'mensal';
$currentYear = (int) date('Y');
$periodYear = (int) ($_GET['period_year'] ?? $_POST['period_year'] ?? $currentYear);
$defaultRef = $periodType === 'trimestral' ? (int) ceil(((int) date('n')) / 3) : (int) date('n');
$periodRef = (int) ($_GET['period_ref'] ?? $_POST['period_ref'] ?? $defaultRef);
$maxRef = $periodType === 'trimestral' ? 4 : 12;
if ($periodYear < 2000 || $periodYear > 2100) {
    $periodYear = $currentYear;
}
if ($periodRef < 1 || $periodRef > $maxRef) {
    $periodRef = $defaultRef;
}
$periodLabel = buildVatPeriodLabel($periodType, $periodYear, $periodRef);

$feedback = null;
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strcasecmp((string) $_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }

    if (($_POST['action'] ?? '') === 'save_field_values') {
        if (!hasTable('accounting_vat_settlement_field_values')) {
            $feedback = ['type' => 'danger', 'message' => 'A tabela accounting_vat_settlement_field_values ainda não existe. Execute as migrações.'];
        } else {
            $dpValues = is_array($_POST['dp_value'] ?? null) ? $_POST['dp_value'] : [];
            $stmt = $pdo->prepare(
                'INSERT INTO accounting_vat_settlement_field_values (accounting_entity_id, period_label, field_number, dp_value, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE dp_value = VALUES(dp_value), updated_by = VALUES(updated_by)'
            );
            foreach ($dpValues as $fieldNumber => $rawValue) {
                $fieldNumber = (int) $fieldNumber;
                if ($fieldNumber <= 0) {
                    continue;
                }
                $value = (float) str_replace(',', '.', (string) $rawValue);
                $stmt->execute([$entityId, $periodLabel, $fieldNumber, $value, $userId]);
            }
            logAuditAction('update', 'accounting_vat_settlement_field_values', $entityId, [
                'accounting_entity_id' => $entityId,
                'period_label' => $periodLabel,
                'changed_by' => $userId,
            ]);
            $feedback = ['type' => 'success', 'message' => 'Valores do período ' . $periodLabel . ' guardados.'];
        }
    }
}

$fieldFormulas = [];
if (hasTable('accounting_vat_field_formulas')) {
    $stmt = $pdo->query('SELECT field_number, formula FROM accounting_vat_field_formulas ORDER BY field_number ASC');
    $fieldFormulas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$manualDpValues = [];
if (hasTable('accounting_vat_settlement_field_values')) {
    $stmt = $pdo->prepare(
        'SELECT field_number, dp_value FROM accounting_vat_settlement_field_values WHERE accounting_entity_id = ? AND period_label = ?'
    );
    $stmt->execute([$entityId, $periodLabel]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $manualDpValues[(int) $row['field_number']] = (float) $row['dp_value'];
    }
}

$periodRange = buildVatPeriodRange($periodType, $periodYear, $periodRef);
$erpDatabase = resolveAccountingEntityDatabase($entity);

$clientNotifyEmail = '';
$entityNif = trim((string) ($entity['nif'] ?? ''));
$erpClientLookupDatabase = normalizeAccountingEntityDatabaseKey(getErpDefaultCompanyIdentifier());
if ($erpClientLookupDatabase === '') {
    $erpClientLookupDatabase = $erpDatabase;
}
if ($entityNif !== '' && $erpClientLookupDatabase !== '') {
    $erpClientRemote = fetchAccountingEntityFromErp($entityNif, 'acquirer', true, $erpClientLookupDatabase);
    if (is_array($erpClientRemote) && empty($erpClientRemote['error'])) {
        $erpClientPayload = is_array($erpClientRemote['payload'] ?? null) ? $erpClientRemote['payload'] : [];
        $erpClientRow = [];
        if (isset($erpClientPayload['aaData']) && is_array($erpClientPayload['aaData']) && !empty($erpClientPayload['aaData'][0]) && is_array($erpClientPayload['aaData'][0])) {
            $erpClientRow = $erpClientPayload['aaData'][0];
        } elseif (isset($erpClientPayload['data']) && is_array($erpClientPayload['data']) && !empty($erpClientPayload['data'][0]) && is_array($erpClientPayload['data'][0])) {
            $erpClientRow = $erpClientPayload['data'][0];
        }
        if ($erpClientRow) {
            $clientNotifyEmail = trim((string) ($erpClientRow['strEmail'] ?? ''));
        }
    }
}

$erpWarnings = [];
$accountBalances = [];
$balancesLoaded = false;
$dpSource = 'manual';
$dpValuesByField = $manualDpValues;

if ($erpDatabase === '') {
    $erpWarnings[] = 'Esta empresa não tem base de dados ERP associada (Entidades > ficha da empresa). Sem balancete, "Ctr Ctb" fica a 0,00.';
} else {
    $balancesResult = fetchErpVatAccountBalances($erpDatabase, $periodYear, $periodRange['month_start'], $periodRange['month_end']);
    if ($balancesResult['success']) {
        $accountBalances = $balancesResult['balances'];
        $balancesLoaded = true;
        if (!$accountBalances) {
            $erpWarnings[] = 'O ERP não devolveu saldos para ' . $periodLabel . ' (base ' . $erpDatabase . ').';
        }
    } else {
        $erpWarnings[] = 'Balancete indisponível: ' . $balancesResult['error'];
    }

    $declarationResult = fetchErpVatDeclarationValues($erpDatabase, $periodRange['erp_period']);
    if ($declarationResult['success'] && $declarationResult['values']) {
        $dpValuesByField = $declarationResult['values'];
        $dpSource = 'erp';
    } elseif ($declarationResult['success']) {
        $erpWarnings[] = 'A Declaração Periódica de ' . $periodLabel . ' ainda não foi gerada no ERP. Os valores DP podem ser introduzidos manualmente.';
    } else {
        $erpWarnings[] = 'Declaração Periódica indisponível: ' . $declarationResult['error'];
    }
}

$evaluation = evaluateVatFieldRows($fieldFormulas, $dpValuesByField, $accountBalances);
$fieldRows = $evaluation['rows'];
$hasError = $evaluation['has_error'];
$expected = computeVatSettlementExpected($fieldFormulas, $accountBalances);

// Fecho do periodo e os dois envios por email ("Enviar" do quadro de campos
// e "Enviar" do resultado do periodo, com opcao "enviar e fechar" numa so
// acao) dependem de $fieldRows/$hasError/$expected, por isso so podem
// correr depois da avaliacao acima. O CSRF ja foi validado no bloco POST
// anterior (que so tratou save_field_values, antes de haver dados para
// avaliar).
$periodJustClosed = $periodJustClosed ?? false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'send_field_report') {
        $reportEmail = trim((string) ($_POST['report_email'] ?? ''));
        if ($reportEmail === '' || !filter_var($reportEmail, FILTER_VALIDATE_EMAIL)) {
            $feedback = ['type' => 'danger', 'message' => 'Indique um email de destino válido.'];
        } elseif (!$fieldRows) {
            $feedback = ['type' => 'danger', 'message' => 'Não há campos configurados para enviar.'];
        } else {
            try {
                sendSystemEmail(
                    $reportEmail,
                    'Apuramento de IVA ' . $entity['name'] . ' — ' . $periodLabel,
                    buildVatFieldReportEmailBody($entity, $periodLabel, $fieldRows),
                    true
                );
                logAuditAction('send_email', 'accounting_entity', $entityId, [
                    'context' => 'apuramento_iva_field_report',
                    'period_label' => $periodLabel,
                    'dest_email' => $reportEmail,
                ]);
                $feedback = ['type' => 'success', 'message' => 'Relatório enviado para ' . $reportEmail . '.'];
            } catch (Throwable $e) {
                $feedback = ['type' => 'danger', 'message' => 'Falha ao enviar o relatório: ' . $e->getMessage()];
            }
        }
    }

    if ($postAction === 'close_period') {
        if ($hasError) {
            $feedback = ['type' => 'danger', 'message' => 'Existem campos com diferença entre DP e Ctr Ctb. Corrija os lançamentos no ERP antes de fechar o período.'];
        } else {
            $resultType = ($_POST['result_type'] ?? '') === 'credito' ? 'credito' : 'pagar';
            $valorPagar = (float) str_replace(',', '.', (string) ($_POST['valor_pagar'] ?? '0'));
            $valorRecuperar = (float) str_replace(',', '.', (string) ($_POST['valor_recuperar'] ?? '0'));
            $observacao = trim((string) ($_POST['observacao'] ?? ''));
            $notifyClient = !empty($_POST['notify_client']);
            $notifyEmailsRaw = array_filter(array_map('trim', preg_split('/[;,]+/', (string) ($_POST['notify_email'] ?? ''))), static fn ($v) => $v !== '');
            $notifyEmails = [];
            $invalidNotifyEmail = null;
            foreach ($notifyEmailsRaw as $candidate) {
                if (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                    $invalidNotifyEmail = $candidate;
                    break;
                }
                $notifyEmails[] = $candidate;
            }

            if ($notifyClient && ($notifyEmails === [] || $invalidNotifyEmail !== null)) {
                $message = $invalidNotifyEmail !== null
                    ? 'Endereço de email inválido: ' . $invalidNotifyEmail . '.'
                    : 'Indique um email de destino válido para notificar o cliente, ou desmarque essa opção.';
                $feedback = ['type' => 'danger', 'message' => $message];
            } else {
                $stmt = $pdo->prepare('SELECT id FROM accounting_vat_settlements WHERE accounting_entity_id = ? AND period_label = ? LIMIT 1');
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
                        $valorRecuperar,
                        $observacao !== '' ? $observacao : null,
                        $userId,
                    ]);
                    logAuditAction('create', 'accounting_vat_settlement', (int) $pdo->lastInsertId(), [
                        'accounting_entity_id' => $entityId,
                        'period_label' => $periodLabel,
                        'result_type' => $resultType,
                    ]);
                    $feedback = ['type' => 'success', 'message' => 'Período ' . $periodLabel . ' fechado com sucesso.'];
                    $periodJustClosed = true;

                    if ($notifyClient) {
                        $notifyBody = buildVatClientNotificationEmailBody($entity, $periodLabel, $resultType, $valorPagar, $valorRecuperar, $expected);
                        $notifySubject = 'IVA ' . $periodLabel . ' — ' . $entity['name'];
                        $sentEmails = [];
                        $failedEmails = [];
                        foreach ($notifyEmails as $notifyEmail) {
                            try {
                                sendSystemEmail($notifyEmail, $notifySubject, $notifyBody, true);
                                $sentEmails[] = $notifyEmail;
                            } catch (Throwable $e) {
                                $failedEmails[] = $notifyEmail . ' (' . $e->getMessage() . ')';
                            }
                        }
                        if ($sentEmails) {
                            logAuditAction('send_email', 'accounting_entity', $entityId, [
                                'context' => 'apuramento_iva_client_notification',
                                'period_label' => $periodLabel,
                                'dest_email' => implode(', ', $sentEmails),
                            ]);
                            $feedback['message'] .= ' Notificação enviada para ' . implode(', ', $sentEmails) . '.';
                        }
                        if ($failedEmails) {
                            $feedback['message'] .= ' (Falha ao enviar a notificação para: ' . implode(', ', $failedEmails) . ')';
                        }
                    }
                }
            }
        }
    }
}

$closedSettlement = null;
$stmt = $pdo->prepare(
    'SELECT s.*, u.name AS closed_by_name, u.username AS closed_by_username
     FROM accounting_vat_settlements s
     LEFT JOIN users u ON u.id = s.closed_by
     WHERE s.accounting_entity_id = ? AND s.period_label = ? LIMIT 1'
);
$stmt->execute([$entityId, $periodLabel]);
$closedSettlement = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

ob_start();
?>
<style>
    .iva-detail-warning {
        background: #fdf3e6; border: 1px solid #f8e2bd; color: #8a6417;
        border-radius: 4px; padding: 10px 14px; font-size: 12.5px; line-height: 1.5; margin-bottom: 16px;
    }
    .iva-detail-warning strong { color: #a9720f; }
    .iva-detail-period-form { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
    .iva-detail-period-form label { margin: 0; font-weight: 600; color: #2a3f54; white-space: nowrap; }
    .iva-detail-period-form select {
        height: 30px; padding: 3px 8px; font-size: 13px; line-height: 1.4; width: auto; box-sizing: border-box;
    }
    .iva-detail-table { width: 100%; border-collapse: collapse; }
    .iva-detail-table th {
        text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .03em;
        color: #979797; font-weight: 600; padding: 0 10px 6px;
    }
    .iva-detail-table th.iva-detail-col-status { text-align: center; }
    .iva-detail-table td { padding: 4px 10px; vertical-align: middle; }
    .iva-detail-table tr + tr td { border-top: 1px solid #f0f0f0; }
    .iva-detail-table tr { }
    .iva-detail-table tbody tr:hover td { background: #f9fafc; }
    .iva-detail-field-number { font-weight: 700; color: #2a3f54; white-space: nowrap; width: 56px; }
    .iva-detail-table .form-control {
        height: 32px; padding: 4px 8px; font-size: 13px; text-align: right;
    }
    .iva-detail-input-group { display: flex; align-items: stretch; }
    .iva-detail-input-group .form-control { border-top-right-radius: 0; border-bottom-right-radius: 0; }
    .iva-detail-input-group .iva-detail-currency {
        display: flex; align-items: center; padding: 0 8px; background: #eef1f5; color: #73879c;
        border: 1px solid #e5e6e7; border-left: none; border-top-right-radius: 4px; border-bottom-right-radius: 4px; font-size: 12.5px;
    }
    .iva-detail-col-status { width: 46px; text-align: center; }
    .iva-detail-status {
        width: 30px; height: 30px; border-radius: 4px; display: inline-flex; align-items: center; justify-content: center;
        color: #fff; font-size: 13px;
    }
    .iva-detail-status.ok { background: #26b99a; }
    .iva-detail-status.error { background: #e04b4a; }
    .iva-detail-status.warning { background: #f0ad4e; }
    .iva-detail-source { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .03em; }
    .iva-detail-summary {
        margin-top: 22px; padding: 14px 16px; background: #f7f9fb; border: 1px solid #e6e9ed; border-radius: 4px;
    }
    .iva-detail-summary h5 { margin: 0 0 10px; font-weight: 700; color: #2a3f54; }
    .iva-detail-summary .form-inline { display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap; }
    .iva-detail-summary .form-inline label { display: block; }
    .iva-detail-summary .form-control { height: 32px; font-size: 13px; }
    .iva-detail-expected { font-size: 13px; color: #73879c; margin-bottom: 14px; }
    .iva-detail-expected-box {
        display: flex; align-items: baseline; gap: 8px; border-radius: 6px; padding: 14px 18px; margin-bottom: 14px;
    }
    .iva-detail-expected-box.pagar { background: #fdf3e6; border: 1px solid #f8e2bd; }
    .iva-detail-expected-box.credito { background: #eef9f6; border: 1px solid #cdeee5; }
    .iva-detail-expected-box-label {
        font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #73879c; display: block; margin-bottom: 2px;
    }
    .iva-detail-expected-box-value { font-size: 22px; font-weight: 700; }
    .iva-detail-expected-box.pagar .iva-detail-expected-box-value { color: #a9720f; }
    .iva-detail-expected-box.credito .iva-detail-expected-box-value { color: #26b99a; }
    .iva-detail-expected-box-note { font-size: 12px; color: #97a3b3; }
    .iva-detail-actions { display: flex; align-items: center; gap: 12px; margin-top: 16px; }
</style>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($feedback['message']); ?>
</div>
<?php endif; ?>

<?php if ($periodJustClosed): ?>
<div data-iva-period-closed="1" hidden></div>
<?php endif; ?>

<?php foreach ($erpWarnings as $warning): ?>
<div class="iva-detail-warning"><?= htmlspecialchars($warning); ?></div>
<?php endforeach; ?>

<form method="get" class="iva-detail-period-form" data-entity-id="<?= $entityId; ?>">
    <label><?= $periodType === 'trimestral' ? 'Trimestre:' : 'Mês:'; ?></label>
    <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
    <select name="period_year" class="form-control">
        <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
            <option value="<?= $y; ?>" <?= $y === $periodYear ? 'selected' : ''; ?>><?= $y; ?></option>
        <?php endfor; ?>
    </select>
    <select name="period_ref" class="form-control">
        <?php if ($periodType === 'trimestral'): ?>
            <?php foreach ([1, 2, 3, 4] as $q): ?>
                <option value="<?= $q; ?>" <?= $q === $periodRef ? 'selected' : ''; ?>><?= $q; ?>º Trimestre</option>
            <?php endforeach; ?>
        <?php else: ?>
            <?php foreach (range(1, 12) as $m): ?>
                <option value="<?= $m; ?>" <?= $m === $periodRef ? 'selected' : ''; ?>><?= sprintf('%02d', $m); ?></option>
            <?php endforeach; ?>
        <?php endif; ?>
    </select>
    <span class="text-muted" style="font-size: 12px;"><?= htmlspecialchars($periodRange['start']); ?> a <?= htmlspecialchars($periodRange['end']); ?></span>
    <noscript><button type="submit" class="btn btn-default btn-sm">Filtrar</button></noscript>
</form>

<?php if (!$fieldFormulas): ?>
<div class="alert alert-info">
    Ainda não há campos configurados. Configura o mapeamento em
    <strong>Apuramento de IVA &gt; Configurações da tarefa</strong> (admin).
</div>
<?php else: ?>
<form method="post" class="iva-detail-form" data-entity-id="<?= $entityId; ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
    <input type="hidden" name="action" value="save_field_values">
    <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
    <input type="hidden" name="period_year" value="<?= $periodYear; ?>">
    <input type="hidden" name="period_ref" value="<?= $periodRef; ?>">

    <table class="iva-detail-table">
        <thead>
            <tr>
                <th>Campo</th>
                <th>DP <span class="iva-detail-source <?= $dpSource === 'erp' ? 'text-success' : 'text-warning'; ?>">(<?= $dpSource === 'erp' ? 'ERP' : 'manual'; ?>)</span></th>
                <th>Ctr Ctb</th>
                <th class="iva-detail-col-status">Estado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($fieldRows as $row):
                $statusIcon = $row['status'] === 'ok' ? 'fa-check' : ($row['status'] === 'warning' ? 'fa-exclamation' : 'fa-exclamation-triangle');
                $dpReadonly = $dpSource === 'erp' || $row['field_number'] === 93;
            ?>
            <tr>
                <td class="iva-detail-field-number">C<?= $row['field_number']; ?></td>
                <td>
                    <div class="iva-detail-input-group">
                        <input type="text" name="dp_value[<?= $row['field_number']; ?>]" class="form-control" value="<?= number_format($row['dp_value'], 2, ',', ''); ?>" <?= $dpReadonly ? 'readonly' : ''; ?>>
                        <span class="iva-detail-currency">€</span>
                    </div>
                </td>
                <td>
                    <div class="iva-detail-input-group">
                        <input type="text" class="form-control" value="<?= number_format($row['ctb_value'], 2, ',', ''); ?>" readonly title="<?= htmlspecialchars($row['formula_error']); ?>">
                        <span class="iva-detail-currency">€</span>
                    </div>
                </td>
                <td class="iva-detail-col-status">
                    <span class="iva-detail-status <?= $row['status']; ?>" title="<?= htmlspecialchars($row['note'] !== '' ? $row['note'] : 'sem diferença'); ?>">
                        <i class="fa <?= $statusIcon; ?>"></i>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="iva-detail-actions">
        <?php if ($dpSource !== 'erp'): ?>
        <button type="submit" class="btn btn-primary">Guardar valores DP</button>
        <?php endif; ?>
        <?php if ($hasError): ?>
        <span class="text-danger"><i class="fa fa-exclamation-triangle"></i> Existem campos com diferença entre DP e Ctr Ctb. Corrija os lançamentos no ERP antes de fechar o período.</span>
        <?php elseif ($balancesLoaded && $dpSource === 'erp'): ?>
        <span class="text-success"><i class="fa fa-check"></i> Todos os campos batem certo.</span>
        <?php endif; ?>
    </div>
</form>

<form method="post" class="iva-detail-form iva-detail-report-form form-inline" data-entity-id="<?= $entityId; ?>" style="margin-top: 10px; display: flex; align-items: flex-end; gap: 8px;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
    <input type="hidden" name="action" value="send_field_report">
    <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
    <input type="hidden" name="period_year" value="<?= $periodYear; ?>">
    <input type="hidden" name="period_ref" value="<?= $periodRef; ?>">
    <div>
        <label class="control-label" style="display: block;">Enviar relatório (Campo/DP/Ctb) para</label>
        <input type="email" name="report_email" class="form-control iva-report-email" style="width: 240px;" placeholder="email@exemplo.pt">
    </div>
    <div>
        <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-envelope"></i> Enviar</button>
    </div>
</form>

<div class="iva-detail-summary">
    <h5><i class="fa fa-euro"></i> Resultado do período <?= htmlspecialchars($periodLabel); ?></h5>
    <?php if ($closedSettlement): ?>
    <p class="text-muted" style="margin: 0;">
        Período fechado em <?= htmlspecialchars((string) $closedSettlement['created_at']); ?>
        por <?= htmlspecialchars((string) ($closedSettlement['closed_by_name'] ?: $closedSettlement['closed_by_username'] ?: '—')); ?>:
        <strong><?= $closedSettlement['result_type'] === 'credito' ? 'em crédito' : 'a pagar'; ?></strong>
        <?php if ($closedSettlement['result_type'] === 'credito'): ?>
            — reembolso pedido <?= number_format((float) $closedSettlement['valor_recuperar'], 2, ',', '.'); ?> €
        <?php else: ?>
            — <?= number_format((float) $closedSettlement['valor_pagar'], 2, ',', '.'); ?> €
        <?php endif; ?>
        <?php if (!empty($closedSettlement['observacao'])): ?>
            <br><em><?= htmlspecialchars((string) $closedSettlement['observacao']); ?></em>
        <?php endif; ?>
    </p>
    <?php else: ?>
    <?php if (!$expected['available']): ?>
    <div class="iva-detail-expected">
        Configure as fórmulas dos campos <strong>93</strong> (a pagar) e <strong>94</strong> (a recuperar) para o cálculo automático do resultado.
    </div>
    <?php elseif (!$balancesLoaded): ?>
    <div class="iva-detail-expected">
        Sem balancete do ERP não é possível calcular o valor apurado.
    </div>
    <?php else: ?>
    <div class="iva-detail-expected-box <?= $expected['type']; ?>">
        <div>
            <span class="iva-detail-expected-box-label">Valor apurado pela contabilidade (campo <?= $expected['field']; ?>)</span>
            <span class="iva-detail-expected-box-value"><?= number_format($expected['value'], 2, ',', '.'); ?> €</span>
        </div>
        <span class="iva-detail-expected-box-note"><?= $expected['type'] === 'credito' ? 'em crédito (a recuperar)' : 'a pagar'; ?></span>
    </div>
    <?php endif; ?>
    <form method="post" class="iva-detail-form iva-detail-close-form form-inline" data-entity-id="<?= $entityId; ?>" data-expected-value="<?= number_format($expected['value'], 2, '.', ''); ?>" data-expected-type="<?= $expected['type']; ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
        <input type="hidden" name="action" value="close_period">
        <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
        <input type="hidden" name="period_year" value="<?= $periodYear; ?>">
        <input type="hidden" name="period_ref" value="<?= $periodRef; ?>">
        <div>
            <label class="control-label">Resultado</label>
            <select name="result_type" class="form-control iva-close-result-type">
                <option value="pagar" <?= $expected['type'] === 'pagar' ? 'selected' : ''; ?>>A pagar</option>
                <option value="credito" <?= $expected['type'] === 'credito' ? 'selected' : ''; ?>>Em crédito</option>
            </select>
        </div>
        <div class="iva-close-field-pagar">
            <label class="control-label">Valor a pagar (€)</label>
            <input type="text" name="valor_pagar" class="form-control" style="width: 130px;" value="<?= $expected['type'] === 'pagar' ? number_format($expected['value'], 2, '.', '') : '0.00'; ?>">
        </div>
        <div class="iva-close-field-recuperar">
            <label class="control-label">Valor reembolso (€)</label>
            <input type="text" name="valor_recuperar" class="form-control" style="width: 130px;" value="0.00" placeholder="Opcional">
        </div>
        <div style="flex: 1 1 200px;">
            <label class="control-label">Observação</label>
            <input type="text" name="observacao" class="form-control" placeholder="Opcional" style="width: 100%;">
        </div>
        <div style="flex-basis: 100%; display: flex; align-items: center; gap: 10px; margin-top: 6px;">
            <label style="font-weight: 400; display: flex; align-items: center; gap: 6px; margin: 0;">
                <input type="checkbox" name="notify_client" value="1" class="iva-close-notify-checkbox"> Enviar notificação ao cliente
            </label>
            <input type="text" name="notify_email" class="form-control iva-close-notify-email" style="width: 280px; display: none;" placeholder="email@exemplo.pt; email2@exemplo.pt" title="Vários endereços separados por ; ou ," value="<?= htmlspecialchars($clientNotifyEmail); ?>">
        </div>
        <div>
            <button type="submit" class="btn btn-success" <?= $hasError ? 'disabled title="Existem campos com erro"' : ''; ?>>
                <i class="fa fa-lock"></i> Fechar período
            </button>
        </div>
        <div class="iva-close-hint text-danger" style="flex-basis: 100%; font-size: 12px;"></div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php
$ivaDetailFragment = ob_get_clean();

if ($isAjaxRequest) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h4 style="margin-top: 0;">Apuramento IVA - ' . htmlspecialchars((string) $entity['name']) . '</h4>';
    echo $ivaDetailFragment;
    exit;
}

$useDataTables = false;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Apuramento de IVA — Detalhes</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-list-alt"></i> Tarefa: Apuramento IVA - <?= htmlspecialchars((string) $entity['name']); ?></h2>
                <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                    <li>
                        <a href="<?= BASE_URL; ?>contabilidade/tarefas/apuramento-iva" class="btn btn-default btn-sm">
                            <i class="fa fa-arrow-left"></i> Voltar
                        </a>
                    </li>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?= $ivaDetailFragment; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
