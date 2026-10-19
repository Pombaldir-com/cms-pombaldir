<?php
// Ecra "Enviar" da tarefa "Envio DP IVA": email ao cliente com o valor de
// IVA do periodo (a pagar / em credito + reembolso pedido) e, opcionalmente,
// a guia de pagamento em anexo, seguido do fecho da tarefa. Equivalente a
// workflow_mail.php?act=message&task=8 da intranet legacy (com a checkbox
// "Fechar Tarefa" -> data/workflow.php accaop=closeWF).
//
// O valor e pre-preenchido a partir do fecho do "Apuramento de IVA" do mesmo
// periodo (accounting_vat_settlements) e/ou do balancete do ERP (campo 94,
// senao 93), tal como o legacy calculava em window.php (task=8).
// Ver contabilidade/APURAMENTO_IVA.md, secao "Tarefa Envio DP IVA".

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/apuramento-iva-functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);
$signerInfo = [
    'name' => trim((string) ($user['name'] ?? '')),
    'email' => trim((string) ($user['email'] ?? '')),
];
$signerReplyTo = filter_var($signerInfo['email'], FILTER_VALIDATE_EMAIL) ? $signerInfo['email'] : '';

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_envio_dp_iva')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();

if (!hasTable('accounting_vat_dp_submissions')) {
    http_response_code(500);
    echo 'A tabela accounting_vat_dp_submissions ainda não existe. Execute as migrações.';
    exit;
}

$entities = getIvaTaskEntities($pdo, $isAdmin, $userId, 'ctb_envio_dp_iva');
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
$periodRange = buildVatPeriodRange($periodType, $periodYear, $periodRef);
$paymentDeadline = computeVatPaymentDeadline($periodType, $periodYear, $periodRef);

$feedback = null;
$periodJustClosed = false;
$isAjaxRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strcasecmp((string) $_SERVER['HTTP_X_REQUESTED_WITH'], 'XMLHttpRequest') === 0;

function findVatDpSubmission(PDO $pdo, int $entityId, string $periodLabel): ?array {
    $stmt = $pdo->prepare(
        'SELECT s.*, u.name AS closed_by_name, u.username AS closed_by_username
         FROM accounting_vat_dp_submissions s
         LEFT JOIN users u ON u.id = s.closed_by
         WHERE s.accounting_entity_id = ? AND s.period_label = ? LIMIT 1'
    );
    $stmt->execute([$entityId, $periodLabel]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }

    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'send_dp' || $postAction === 'close_only') {
        $resultType = ($_POST['result_type'] ?? '') === 'credito' ? 'credito' : 'pagar';
        $valor = round(abs((float) str_replace(',', '.', (string) ($_POST['valor'] ?? '0'))), 2);
        $valorReembolso = $resultType === 'credito'
            ? round(abs((float) str_replace(',', '.', (string) ($_POST['valor_reembolso'] ?? '0'))), 2)
            : 0.0;
        $observacao = trim((string) ($_POST['observacao'] ?? ''));
        $closeTask = $postAction === 'close_only' || !empty($_POST['close_task']);
        $errors = [];

        if ($closeTask && findVatDpSubmission($pdo, $entityId, $periodLabel)) {
            $errors[] = 'A tarefa deste período já se encontra fechada para esta empresa.';
        }

        $toEmails = [];
        $ccEmails = [];
        $subject = '';
        $messageText = '';
        $attachment = null;
        if ($postAction === 'send_dp' && !$errors) {
            $toParsed = parseVatTaskEmailList((string) ($_POST['email_to'] ?? ''));
            $ccParsed = parseVatTaskEmailList((string) ($_POST['email_cc'] ?? ''));
            $toEmails = $toParsed['emails'];
            $ccEmails = array_values(array_diff($ccParsed['emails'], $toEmails));
            $subject = trim((string) ($_POST['email_subject'] ?? ''));
            $messageText = trim(str_replace("\r\n", "\n", (string) ($_POST['email_message'] ?? '')));

            if ($toParsed['invalid'] !== null) {
                $errors[] = 'Endereço de email inválido: ' . $toParsed['invalid'] . '.';
            } elseif (!$toEmails) {
                $errors[] = 'Indique o email do cliente.';
            }
            if ($ccParsed['invalid'] !== null) {
                $errors[] = 'Endereço de email CC inválido: ' . $ccParsed['invalid'] . '.';
            }
            if ($subject === '') {
                $errors[] = 'Indique o assunto do email.';
            }
            if ($messageText === '') {
                $errors[] = 'A mensagem não pode estar vazia.';
            }

            $upload = $_FILES['attachment'] ?? null;
            if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
                    $errors[] = 'Falha no upload do anexo.';
                } elseif ((int) $upload['size'] > 10 * 1024 * 1024) {
                    $errors[] = 'O anexo excede o limite de 10 MB.';
                } else {
                    $mime = function_exists('mime_content_type') ? (string) @mime_content_type((string) $upload['tmp_name']) : '';
                    $attachment = [
                        'content' => (string) file_get_contents((string) $upload['tmp_name']),
                        'filename' => basename((string) $upload['name']),
                        'mime' => $mime !== '' ? $mime : 'application/octet-stream',
                    ];
                }
            }
        }

        if ($errors) {
            $feedback = ['type' => 'danger', 'message' => implode(' ', $errors)];
        } else {
            $sentEmails = [];
            $failedEmails = [];
            if ($postAction === 'send_dp') {
                $bodyHtml = buildVatEmailTemplate(
                    'Declaração periódica de IVA',
                    (string) $entity['name'] . ' — ' . formatVatPeriodForClient($periodType, $periodYear, $periodRef),
                    nl2br(htmlspecialchars($messageText)),
                    $signerInfo
                );
                foreach (array_merge($toEmails, $ccEmails) as $recipient) {
                    try {
                        if ($attachment) {
                            sendSystemEmailWithAttachment($recipient, $subject, $bodyHtml, $attachment['content'], $attachment['filename'], $attachment['mime'], true, $signerReplyTo);
                        } else {
                            sendSystemEmail($recipient, $subject, $bodyHtml, true, $signerReplyTo);
                        }
                        $sentEmails[] = $recipient;
                    } catch (Throwable $e) {
                        $failedEmails[] = $recipient . ' (' . $e->getMessage() . ')';
                    }
                }
                $sentToClient = array_values(array_intersect($toEmails, $sentEmails));
                if ($sentEmails) {
                    logAuditAction('send_email', 'accounting_entity', $entityId, [
                        'context' => 'envio_dp_iva',
                        'period_label' => $periodLabel,
                        'dest_email' => implode(', ', $sentEmails),
                        'attachment' => $attachment['filename'] ?? null,
                    ]);
                }
            } else {
                $sentToClient = [];
            }

            $messages = [];
            if ($postAction === 'send_dp') {
                if ($sentToClient) {
                    $messages[] = 'Email enviado para ' . implode(', ', $sentEmails) . '.';
                }
                if ($failedEmails) {
                    $messages[] = 'Falha ao enviar para: ' . implode(', ', $failedEmails) . '.';
                }
            }

            // So fecha se o email chegou a pelo menos um destinatario do
            // cliente (ou se foi pedido "Fechar sem enviar").
            if ($closeTask && ($postAction === 'close_only' || $sentToClient)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO accounting_vat_dp_submissions
                        (accounting_entity_id, period_type, period_year, period_ref, period_label, result_type, valor, valor_reembolso, sent_to, email_subject, attachment_filename, observacao, closed_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $entityId,
                    $periodType,
                    $periodYear,
                    $periodRef,
                    $periodLabel,
                    $resultType,
                    $valor,
                    $valorReembolso,
                    $sentToClient ? implode(', ', $sentToClient) : null,
                    $sentToClient ? mb_substr($subject, 0, 255) : null,
                    $sentToClient && $attachment ? mb_substr($attachment['filename'], 0, 255) : null,
                    $observacao !== '' ? $observacao : null,
                    $userId,
                ]);
                logAuditAction('create', 'accounting_vat_dp_submission', (int) $pdo->lastInsertId(), [
                    'accounting_entity_id' => $entityId,
                    'period_label' => $periodLabel,
                    'result_type' => $resultType,
                    'valor' => $valor,
                    'sent' => (bool) $sentToClient,
                ]);
                $messages[] = 'Tarefa do período ' . $periodLabel . ' fechada.';
                $periodJustClosed = true;
            } elseif ($closeTask) {
                $messages[] = 'A tarefa não foi fechada porque o email não foi enviado ao cliente.';
            }

            $feedbackType = 'success';
            if ($failedEmails || ($closeTask && !$periodJustClosed)) {
                $feedbackType = ($sentEmails || $periodJustClosed) ? 'warning' : 'danger';
            }
            $feedback = ['type' => $feedbackType, 'message' => implode(' ', $messages)];
        }
    }
}

$submission = findVatDpSubmission($pdo, $entityId, $periodLabel);
$erpDatabase = resolveAccountingEntityDatabase($entity);
$erpWarnings = [];

$settlement = null;
if (hasTable('accounting_vat_settlements')) {
    $stmt = $pdo->prepare(
        'SELECT s.*, u.name AS closed_by_name, u.username AS closed_by_username
         FROM accounting_vat_settlements s
         LEFT JOIN users u ON u.id = s.closed_by
         WHERE s.accounting_entity_id = ? AND s.period_label = ? LIMIT 1'
    );
    $stmt->execute([$entityId, $periodLabel]);
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$expected = ['type' => 'pagar', 'field' => 93, 'value' => 0.0, 'available' => false];
$balancesLoaded = false;
$clientEmail = '';
if (!$submission) {
    $fieldFormulas = [];
    if (hasTable('accounting_vat_field_formulas')) {
        $stmt = $pdo->query('SELECT field_number, formula FROM accounting_vat_field_formulas WHERE field_number IN (93, 94)');
        $fieldFormulas = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if ($erpDatabase === '') {
        $erpWarnings[] = 'Esta empresa não tem base de dados ERP associada (Entidades > ficha da empresa). O valor tem de ser introduzido manualmente.';
    } elseif ($fieldFormulas) {
        $balancesResult = fetchErpVatAccountBalances($erpDatabase, $periodYear, $periodRange['month_start'], $periodRange['month_end']);
        if ($balancesResult['success']) {
            $balancesLoaded = true;
            $expected = computeVatSettlementExpected($fieldFormulas, $balancesResult['balances']);
        } else {
            $erpWarnings[] = 'Balancete indisponível: ' . $balancesResult['error'];
        }
    }
    $clientEmail = fetchVatTaskClientEmail($entity, $erpDatabase);
    if ($clientEmail === '') {
        $erpWarnings[] = 'Não foi encontrado o email do cliente na ficha do ERP. Indique-o manualmente.';
    }
    if (!$settlement) {
        $erpWarnings[] = 'O Apuramento de IVA de ' . $periodLabel . ' ainda não foi fechado. Confirme os valores antes de enviar.';
    }
}

// Valores pre-preenchidos: o fecho do Apuramento de IVA tem prioridade (foi
// validado pelo colaborador); o balancete do ERP completa o que falta (o
// valor em credito nao e guardado no fecho do apuramento).
$formResultType = $settlement ? (string) $settlement['result_type'] : (string) $expected['type'];
if ($formResultType === 'pagar' && $settlement) {
    $formValor = (float) $settlement['valor_pagar'];
} else {
    $formValor = ($expected['available'] && $expected['type'] === $formResultType) ? (float) $expected['value'] : 0.0;
}
$formReembolso = ($settlement && $formResultType === 'credito') ? (float) $settlement['valor_recuperar'] : 0.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $feedback && $feedback['type'] === 'danger') {
    // Manter o que o utilizador escreveu quando a submissao falha.
    $formResultType = ($_POST['result_type'] ?? '') === 'credito' ? 'credito' : 'pagar';
    $formValor = (float) str_replace(',', '.', (string) ($_POST['valor'] ?? '0'));
    $formReembolso = (float) str_replace(',', '.', (string) ($_POST['valor_reembolso'] ?? '0'));
}

$templates = getVatDpEmailTemplates();
$templateVars = [
    'nome_cliente' => (string) $entity['name'],
    'nif' => (string) $entity['nif'],
    'periodo' => formatVatPeriodForClient($periodType, $periodYear, $periodRef),
    'nome_utilizador' => $signerInfo['name'],
    'data' => date('d-m-Y'),
    'data_limite' => date('d-m-Y', strtotime($paymentDeadline)),
];
$formSubject = renderVatDpEmailTemplate($templates['subject'], $templateVars + ['mensagem_valores' => '']);
// Modelo com todos os marcadores resolvidos exceto {mensagem_valores}, que o
// JS volta a gerar quando o utilizador altera os valores.
$bodyTemplate = renderVatDpEmailTemplate($templates['body'], $templateVars);
$formMessage = renderVatDpEmailTemplate($bodyTemplate, [
    'mensagem_valores' => buildVatDpValuesMessage($formResultType, $formValor, $formReembolso, $paymentDeadline),
]);
$formTo = $clientEmail;
$formCc = $signerReplyTo;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $feedback && $feedback['type'] === 'danger') {
    $formSubject = (string) ($_POST['email_subject'] ?? $formSubject);
    $formMessage = (string) ($_POST['email_message'] ?? $formMessage);
    $formTo = (string) ($_POST['email_to'] ?? $formTo);
    $formCc = (string) ($_POST['email_cc'] ?? $formCc);
}

$fmtEuro = static fn (float $v): string => number_format($v, 2, ',', '.') . ' €';

ob_start();
?>
<style>
    .dpiva-warning {
        background: #fdf3e6; border: 1px solid #f8e2bd; color: #8a6417;
        border-radius: 4px; padding: 10px 14px; font-size: 12.5px; line-height: 1.5; margin-bottom: 12px;
    }
    .dpiva-period-form { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
    .dpiva-period-form label { margin: 0; font-weight: 600; color: #2a3f54; white-space: nowrap; }
    .dpiva-period-form select { height: 30px; padding: 3px 8px; font-size: 13px; width: auto; }
    .dpiva-source { font-size: 12.5px; color: #73879c; margin-bottom: 14px; }
    .dpiva-source i { color: #26b99a; }
    .dpiva-values { display: flex; align-items: flex-end; gap: 14px; flex-wrap: wrap; margin-bottom: 6px; }
    .dpiva-values .form-control { height: 32px; font-size: 13px; }
    .dpiva-hint { font-size: 12px; min-height: 18px; margin-bottom: 10px; }
    .dpiva-email .form-group { margin-bottom: 12px; }
    .dpiva-email label { font-weight: 600; color: #2a3f54; }
    .dpiva-email textarea { font-size: 13px; line-height: 1.5; }
    .dpiva-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 6px; }
    .dpiva-actions .dpiva-close-check { display: flex; align-items: center; gap: 6px; margin: 0 auto 0 0; font-weight: 400; }
    .dpiva-closed {
        padding: 14px 16px; background: #eafaf6; border: 1px solid #cdeee5; border-radius: 4px; color: #2a3f54;
    }
    .dpiva-closed dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 14px; margin: 10px 0 0; font-size: 13px; }
    .dpiva-closed dt { color: #73879c; font-weight: 600; }
    .dpiva-closed dd { margin: 0; }
</style>

<?php if ($feedback): ?>
<div class="alert alert-<?= htmlspecialchars($feedback['type']); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($feedback['message']); ?>
</div>
<?php endif; ?>

<?php if ($periodJustClosed): ?>
<div data-dpiva-period-closed="1" hidden></div>
<?php endif; ?>

<form method="get" class="dpiva-period-form" data-entity-id="<?= $entityId; ?>">
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

<?php if ($submission): ?>
<div class="dpiva-closed">
    <strong><i class="fa fa-check"></i> Tarefa fechada</strong>
    em <?= htmlspecialchars((string) $submission['created_at']); ?>
    por <?= htmlspecialchars((string) ($submission['closed_by_name'] ?: $submission['closed_by_username'] ?: '—')); ?>
    <dl>
        <dt><?= $submission['result_type'] === 'credito' ? 'IVA em crédito' : 'IVA a pagar'; ?></dt>
        <dd><?= $fmtEuro((float) $submission['valor']); ?></dd>
        <?php if ($submission['result_type'] === 'credito' && (float) $submission['valor_reembolso'] > 0): ?>
        <dt>Reembolso pedido</dt>
        <dd><?= $fmtEuro((float) $submission['valor_reembolso']); ?></dd>
        <?php endif; ?>
        <dt>Enviado para</dt>
        <dd><?= $submission['sent_to'] ? htmlspecialchars((string) $submission['sent_to']) : '<em>Fechada sem envio de email</em>'; ?></dd>
        <?php if (!empty($submission['attachment_filename'])): ?>
        <dt>Anexo</dt>
        <dd><?= htmlspecialchars((string) $submission['attachment_filename']); ?></dd>
        <?php endif; ?>
        <?php if (!empty($submission['observacao'])): ?>
        <dt>Observação</dt>
        <dd><?= htmlspecialchars((string) $submission['observacao']); ?></dd>
        <?php endif; ?>
    </dl>
</div>
<?php else: ?>

<?php foreach ($erpWarnings as $warning): ?>
<div class="dpiva-warning"><?= htmlspecialchars($warning); ?></div>
<?php endforeach; ?>

<?php if ($settlement): ?>
<div class="dpiva-source">
    <i class="fa fa-check-circle"></i>
    Apuramento de IVA fechado em <?= htmlspecialchars((string) $settlement['created_at']); ?>
    por <?= htmlspecialchars((string) ($settlement['closed_by_name'] ?: $settlement['closed_by_username'] ?: '—')); ?>.
    <?php if ($expected['available'] && $balancesLoaded): ?>
    Contabilidade (campo <?= (int) $expected['field']; ?>): <?= $fmtEuro((float) $expected['value']); ?> <?= $expected['type'] === 'credito' ? 'em crédito' : 'a pagar'; ?>.
    <?php endif; ?>
</div>
<?php elseif ($expected['available'] && $balancesLoaded): ?>
<div class="dpiva-source">
    Valor apurado pela contabilidade (campo <?= (int) $expected['field']; ?>):
    <strong><?= $fmtEuro((float) $expected['value']); ?></strong> <?= $expected['type'] === 'credito' ? 'em crédito' : 'a pagar'; ?>.
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="dpiva-detail-form dpiva-send-form" data-entity-id="<?= $entityId; ?>"
      data-expected-value="<?= number_format((float) $expected['value'], 2, '.', ''); ?>"
      data-expected-type="<?= htmlspecialchars((string) $expected['type']); ?>"
      data-expected-available="<?= ($expected['available'] && $balancesLoaded) ? '1' : '0'; ?>"
      data-deadline="<?= htmlspecialchars(date('d-m-Y', strtotime($paymentDeadline))); ?>">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
    <input type="hidden" name="entity_id" value="<?= $entityId; ?>">
    <input type="hidden" name="period_year" value="<?= $periodYear; ?>">
    <input type="hidden" name="period_ref" value="<?= $periodRef; ?>">
    <textarea class="dpiva-body-template" hidden><?= htmlspecialchars($bodyTemplate); ?></textarea>

    <div class="dpiva-values">
        <div>
            <label class="control-label">Resultado</label>
            <select name="result_type" class="form-control dpiva-result-type">
                <option value="pagar" <?= $formResultType === 'pagar' ? 'selected' : ''; ?>>A pagar</option>
                <option value="credito" <?= $formResultType === 'credito' ? 'selected' : ''; ?>>Em crédito</option>
            </select>
        </div>
        <div>
            <label class="control-label dpiva-valor-label"><?= $formResultType === 'credito' ? 'IVA em crédito (€)' : 'IVA a pagar (€)'; ?></label>
            <input type="text" name="valor" class="form-control dpiva-valor" style="width: 130px;" value="<?= number_format($formValor, 2, '.', ''); ?>">
        </div>
        <div class="dpiva-reembolso-field">
            <label class="control-label">Valor reembolso (€)</label>
            <input type="text" name="valor_reembolso" class="form-control dpiva-reembolso" style="width: 130px;" value="<?= number_format($formReembolso, 2, '.', ''); ?>">
        </div>
        <div style="flex: 1 1 180px;">
            <label class="control-label">Observação</label>
            <input type="text" name="observacao" class="form-control" placeholder="Opcional" style="width: 100%;">
        </div>
    </div>
    <div class="dpiva-hint text-danger"></div>

    <div class="dpiva-email">
        <div class="row">
            <div class="col-md-7 form-group">
                <label for="dpiva-email-to-<?= $entityId; ?>">Email do cliente <span class="text-danger">*</span></label>
                <input type="text" id="dpiva-email-to-<?= $entityId; ?>" name="email_to" class="form-control dpiva-email-to" value="<?= htmlspecialchars($formTo); ?>" placeholder="email@cliente.pt; outro@cliente.pt" title="Vários endereços separados por ; ou ,">
            </div>
            <div class="col-md-5 form-group">
                <label for="dpiva-email-cc-<?= $entityId; ?>">CC</label>
                <input type="text" id="dpiva-email-cc-<?= $entityId; ?>" name="email_cc" class="form-control" value="<?= htmlspecialchars($formCc); ?>" placeholder="Opcional">
            </div>
        </div>
        <div class="form-group">
            <label for="dpiva-email-subject-<?= $entityId; ?>">Assunto <span class="text-danger">*</span></label>
            <input type="text" id="dpiva-email-subject-<?= $entityId; ?>" name="email_subject" class="form-control" value="<?= htmlspecialchars($formSubject); ?>">
        </div>
        <div class="form-group">
            <label for="dpiva-email-message-<?= $entityId; ?>">Mensagem <span class="text-danger">*</span></label>
            <textarea id="dpiva-email-message-<?= $entityId; ?>" name="email_message" class="form-control dpiva-email-message" rows="9"><?= htmlspecialchars($formMessage); ?></textarea>
            <small class="text-muted">O cabeçalho e a assinatura (<?= htmlspecialchars($signerInfo['name'] ?: 'utilizador'); ?>) são acrescentados automaticamente.</small>
        </div>
        <div class="form-group">
            <label for="dpiva-attachment-<?= $entityId; ?>">Anexo (guia de pagamento)</label>
            <input type="file" id="dpiva-attachment-<?= $entityId; ?>" name="attachment" class="form-control" accept=".pdf,image/*">
        </div>
    </div>

    <div class="dpiva-actions">
        <label class="dpiva-close-check">
            <input type="checkbox" name="close_task" value="1" checked> Fechar tarefa após o envio
        </label>
        <button type="submit" class="btn btn-default dpiva-close-only-btn" name="action" value="close_only" title="Fechar a tarefa sem enviar email ao cliente">
            <i class="fa fa-lock"></i> Fechar sem enviar
        </button>
        <button type="submit" class="btn btn-success" name="action" value="send_dp">
            <i class="fa fa-envelope"></i> Enviar
        </button>
    </div>
</form>
<?php endif; ?>
<?php
$detailFragment = ob_get_clean();

if ($isAjaxRequest) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h4 style="margin-top: 0;">Envio DP IVA - ' . htmlspecialchars((string) $entity['name']) . '</h4>';
    echo $detailFragment;
    exit;
}

$useDataTables = false;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Envio DP IVA</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-envelope"></i> Tarefa: Envio DP IVA - <?= htmlspecialchars((string) $entity['name']); ?></h2>
                <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                    <li>
                        <a href="<?= BASE_URL; ?>contabilidade/tarefas/envio-dp-iva" class="btn btn-default btn-sm">
                            <i class="fa fa-arrow-left"></i> Voltar
                        </a>
                    </li>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?= $detailFragment; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
