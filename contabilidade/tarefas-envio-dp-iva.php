<?php
// Tarefa "Envio DP IVA": colaboradores enviam ao cliente, por empresa e por
// periodo (mensal ou trimestral consoante a empresa), o valor de IVA a pagar
// ou em credito e fecham a tarefa (permissao ctb_envio_dp_iva, gerida na
// ficha da empresa em Entidades > Empresas > Admin). Administradores veem
// todas as empresas. Equivalente a fase 8 ("Envio DP IVA") da intranet
// legacy. Ver contabilidade/APURAMENTO_IVA.md.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/apuramento-iva-functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_envio_dp_iva')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();

if (!hasTable('accounting_vat_dp_submissions')) {
    http_response_code(500);
    echo 'A tabela accounting_vat_dp_submissions ainda nao existe. Execute as migracoes.';
    exit;
}

$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }
    if (($_POST['action'] ?? '') === 'save_email_template') {
        if (!$isAdmin) {
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }
        $subject = trim((string) ($_POST['email_subject'] ?? ''));
        $body = trim(str_replace("\r\n", "\n", (string) ($_POST['email_body'] ?? '')));
        setSetting('envio_dp_iva_email_subject', $subject);
        setSetting('envio_dp_iva_email_body', $body);
        logAuditAction('update', 'setting', null, [
            'context' => 'envio_dp_iva_email_template',
            'changed_by' => $userId,
        ]);
        $feedback = ['type' => 'success', 'message' => 'Modelo de email guardado.'];
    }
}

$entities = getIvaTaskEntities($pdo, $isAdmin, $userId, 'ctb_envio_dp_iva');
$allowedEntityIds = array_map(static fn($row) => (int) $row['id'], $entities);
$entitiesById = [];
foreach ($entities as $entityRow) {
    $entitiesById[(int) $entityRow['id']] = $entityRow;
}

$hasSettlementsTable = hasTable('accounting_vat_settlements');
$closedPeriodsByEntity = [];
$settledPeriodsByEntity = [];
foreach ($entities as $entityRow) {
    $closedPeriodsByEntity[(int) $entityRow['id']] = getClosedVatDpPeriods($pdo, (int) $entityRow['id']);
    $settledPeriodsByEntity[(int) $entityRow['id']] = $hasSettlementsTable ? getClosedVatPeriods($pdo, (int) $entityRow['id']) : [];
}

$submissionsHistory = [];
if ($allowedEntityIds) {
    $placeholders = implode(',', array_fill(0, count($allowedEntityIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT s.*, u.name AS closed_by_name, u.username AS closed_by_username
         FROM accounting_vat_dp_submissions s
         LEFT JOIN users u ON u.id = s.closed_by
         WHERE s.accounting_entity_id IN ($placeholders)
         ORDER BY s.created_at DESC
         LIMIT 100"
    );
    $stmt->execute($allowedEntityIds);
    $submissionsHistory = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$emailTemplates = getVatDpEmailTemplates();

$useDataTables = false;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Envio DP IVA</small></h3>
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
                <h2><i class="fa fa-envelope"></i> Envio DP IVA</h2>
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
                    .iva-periodicity-field input[type="search"] {
                        height: 30px; padding: 4px 8px; font-size: 13px; width: 200px; box-sizing: border-box;
                    }
                </style>
                <ul class="nav navbar-right panel_toolbox iva-title-toolbox">
                    <?php if ($entities):
                        $currentYear = (int) date('Y');
                    ?>
                    <li class="iva-periodicity-field">
                        <label for="dpiva-name-filter">Empresa</label>
                        <input type="search" id="dpiva-name-filter" class="form-control" placeholder="Nome ou NIF" autocomplete="off">
                    </li>
                    <li class="iva-periodicity-field">
                        <label for="dpiva-global-year">Ano</label>
                        <select id="dpiva-global-year" class="form-control">
                            <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
                                <option value="<?= $y; ?>"><?= $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </li>
                    <li class="iva-periodicity-field dpiva-global-month-field">
                        <label for="dpiva-global-month">Mês</label>
                        <select id="dpiva-global-month" class="form-control">
                            <option value="">-</option>
                            <?php foreach (range(1, 12) as $m): ?>
                                <option value="<?= $m; ?>"><?= sprintf('%02d', $m); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </li>
                    <li class="iva-periodicity-field dpiva-global-quarter-field">
                        <label for="dpiva-global-quarter">Trimestre</label>
                        <select id="dpiva-global-quarter" class="form-control">
                            <option value="">-</option>
                            <?php foreach ([1, 2, 3, 4] as $q): ?>
                                <option value="<?= $q; ?>"><?= $q; ?>º Trimestre</option>
                            <?php endforeach; ?>
                        </select>
                    </li>
                    <?php endif; ?>
                    <li class="iva-periodicity-field">
                        <label for="dpiva-periodicity-filter">Periodicidade</label>
                        <select id="dpiva-periodicity-filter" class="form-control">
                            <option value="">Todos</option>
                            <option value="mensal">Mensal</option>
                            <option value="trimestral">Trimestral</option>
                        </select>
                    </li>
                    <?php if ($isAdmin): ?>
                    <li>
                        <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#dpiva-settings-modal" title="Configurações da tarefa">
                            <i class="fa fa-cog"></i>
                        </button>
                    </li>
                    <?php endif; ?>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <style>
                    .iva-entity-accordion { margin-top: 4px; }
                    .iva-entity-panel { border: 1px solid #e6e9ed; border-radius: 3px; margin-bottom: 10px; }
                    .iva-entity-header {
                        display: flex; align-items: center; flex-wrap: wrap; gap: 10px 14px;
                        width: 100%; padding: 12px 16px; background: #f7f9fb; border-radius: 3px;
                        transition: background-color .15s ease;
                    }
                    .iva-entity-header-closed { background: #eafaf6; }
                    .iva-entity-name { font-weight: 600; color: #2a3f54; }
                    .iva-entity-name small { font-weight: 400; }
                    .iva-entity-actions { display: flex; align-items: center; gap: 10px; margin-left: auto; }
                    .dpiva-unsettled-alert {
                        display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 3px;
                        background: #fdf3e6; border: 1px solid #f8e2bd; color: #a9720f; font-size: 12.5px; font-weight: 600;
                    }
                    .iva-entity-header-unsettled { background: #fffaf3; }
                    .label-default-outline { background: #eef1f5 !important; color: #73879c !important; font-weight: 600; }
                    .iva-history-panel { border: 1px solid #e6e9ed; border-radius: 3px; }
                    .iva-history-header {
                        display: flex; align-items: center; gap: 10px; width: 100%;
                        padding: 12px 16px; background: #f7f9fb; border: none; text-align: left; border-radius: 3px;
                        cursor: pointer;
                    }
                    .iva-history-header:hover { background: #eef1f5; }
                    .iva-history-chevron { transition: transform .15s ease; color: #73879c; }
                    .iva-history-header[aria-expanded="true"] .iva-history-chevron { transform: rotate(90deg); }
                    .iva-history-count { margin-left: auto; font-size: 12px; color: #73879c; }
                </style>

                <?php if ($entities): ?>
                <div class="alert alert-warning dpiva-unsettled-summary" style="display: none;">
                    <i class="fa fa-exclamation-triangle"></i>
                    <span class="dpiva-unsettled-summary-text"></span>
                </div>
                <div class="iva-entity-accordion">
                <?php foreach ($entities as $entityRow):
                    $entityId = (int) $entityRow['id'];
                    $periodType = ((string) $entityRow['vat_periodicity']) === 'trimestral' ? 'trimestral' : 'mensal';
                    $closedLabels = $closedPeriodsByEntity[$entityId] ?? [];
                    $settledLabels = $settledPeriodsByEntity[$entityId] ?? [];
                ?>
                <div class="iva-entity-panel dpiva-entity-section" data-vat-periodicity="<?= $periodType; ?>"
                     data-search="<?= htmlspecialchars((string) $entityRow['name'] . ' ' . (string) $entityRow['nif']); ?>"
                     data-closed-periods="<?= htmlspecialchars(implode(',', $closedLabels)); ?>"
                     data-settled-periods="<?= htmlspecialchars(implode(',', $settledLabels)); ?>">
                    <div class="iva-entity-header">
                        <span class="iva-entity-name">
                            <?= htmlspecialchars((string) $entityRow['name']); ?>
                            <small class="text-muted">NIF <?= htmlspecialchars((string) $entityRow['nif']); ?></small>
                        </span>
                        <span class="label label-default-outline"><?= $periodType === 'trimestral' ? 'Trimestral' : 'Mensal'; ?></span>
                        <div class="iva-entity-actions dpiva-entity-period" data-period-type="<?= $periodType; ?>">
                            <span class="dpiva-period-display label label-default" style="display: inline-block; padding: 6px 10px; font-size: 13px;"></span>
                            <span class="label label-info dpiva-settled-badge" style="display: none;" title="Apuramento de IVA fechado para este período"><i class="fa fa-calculator"></i> IVA apurado</span>
                            <span class="dpiva-unsettled-alert" style="display: none;" title="O Apuramento de IVA deste período ainda não foi fechado. Confirme os valores antes de enviar.">
                                <i class="fa fa-exclamation-triangle"></i> Apuramento de IVA por fazer
                            </span>
                            <span class="label label-success dpiva-closed-badge" style="display: none;"><i class="fa fa-check"></i> DP enviada</span>
                            <button type="button" class="btn btn-primary btn-sm dpiva-detail-trigger" data-entity-id="<?= $entityId; ?>" data-bs-toggle="modal" data-bs-target="#dpiva-detail-modal">
                                <i class="fa fa-envelope"></i> Enviar
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <div class="alert alert-info dpiva-no-match" style="display: none;">Nenhuma empresa corresponde ao filtro.</div>
                <?php endif; ?>

                <div class="iva-history-panel">
                    <button type="button" class="iva-history-header" data-bs-toggle="collapse" data-bs-target="#dpiva-history-collapse" aria-expanded="<?= $submissionsHistory ? 'true' : 'false'; ?>" aria-controls="dpiva-history-collapse">
                        <i class="fa fa-chevron-right iva-history-chevron"></i>
                        <span class="iva-entity-name">Histórico de envios</span>
                        <span class="iva-history-count"><?= count($submissionsHistory); ?> registo<?= count($submissionsHistory) === 1 ? '' : 's'; ?></span>
                    </button>
                    <div class="collapse<?= $submissionsHistory ? ' show' : ''; ?>" id="dpiva-history-collapse">
                        <?php if (!$submissionsHistory): ?>
                        <p class="text-muted" style="padding: 14px 16px; margin: 0;">Sem envios registados ainda.</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped" style="margin-bottom: 0;">
                                <thead>
                                    <tr>
                                        <th>Empresa</th>
                                        <th>Período</th>
                                        <th>Resultado</th>
                                        <th>Valor</th>
                                        <th>Reembolso</th>
                                        <th>Enviado para</th>
                                        <th>Fechado por</th>
                                        <th>Data</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($submissionsHistory as $submission):
                                        $submissionEntity = $entitiesById[(int) $submission['accounting_entity_id']] ?? null;
                                    ?>
                                    <tr class="dpiva-history-row" data-search="<?= htmlspecialchars((string) ($submissionEntity['name'] ?? '') . ' ' . (string) ($submissionEntity['nif'] ?? '')); ?>">
                                        <td><?= htmlspecialchars((string) ($submissionEntity['name'] ?? '—')); ?></td>
                                        <td><?= htmlspecialchars((string) $submission['period_label']); ?></td>
                                        <td><?= $submission['result_type'] === 'credito' ? 'Em crédito' : 'A pagar'; ?></td>
                                        <td><?= number_format((float) $submission['valor'], 2, ',', '.'); ?> €</td>
                                        <td><?= (float) $submission['valor_reembolso'] > 0 ? number_format((float) $submission['valor_reembolso'], 2, ',', '.') . ' €' : '—'; ?></td>
                                        <td><?= $submission['sent_to'] ? htmlspecialchars((string) $submission['sent_to']) : '<em class="text-muted">sem envio</em>'; ?></td>
                                        <td><?= htmlspecialchars((string) ($submission['closed_by_name'] ?: $submission['closed_by_username'] ?: '—')); ?></td>
                                        <td><?= htmlspecialchars((string) $submission['created_at']); ?></td>
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
    </div>
</div>

<div class="modal fade" id="dpiva-detail-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <style>
        @media (min-width: 992px) { #dpiva-detail-modal .modal-dialog { max-width: 960px; } }
    </style>
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Envio DP IVA</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="dpiva-detail-modal-body">
                <div class="text-center text-muted" style="padding: 30px 0;">A carregar...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="dpiva-settings-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="action" value="save_email_template">
                <div class="modal-header">
                    <h5 class="modal-title">Configurações — Envio DP IVA</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">
                        Modelo do email enviado ao cliente (editável antes de cada envio). Marcadores:
                        <code>{nome_cliente}</code>, <code>{nif}</code>, <code>{periodo}</code>,
                        <code>{mensagem_valores}</code> (valor a pagar/em crédito, reembolso e prazo),
                        <code>{data_limite}</code>, <code>{nome_utilizador}</code>, <code>{data}</code>.
                        Deixar vazio para usar o modelo por defeito. O cabeçalho e a assinatura são
                        acrescentados automaticamente.
                    </p>
                    <div class="form-group">
                        <label for="dpiva-template-subject">Assunto</label>
                        <input type="text" id="dpiva-template-subject" name="email_subject" class="form-control" value="<?= htmlspecialchars((string) getSetting('envio_dp_iva_email_subject', '')); ?>" placeholder="<?= htmlspecialchars($emailTemplates['default_subject']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="dpiva-template-body">Mensagem</label>
                        <textarea id="dpiva-template-body" name="email_body" class="form-control" rows="9" placeholder="<?= htmlspecialchars($emailTemplates['default_body']); ?>"><?= htmlspecialchars((string) getSetting('envio_dp_iva_email_body', '')); ?></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var entitySections = document.querySelectorAll('.dpiva-entity-section');
    var periodicityFilter = document.getElementById('dpiva-periodicity-filter');
    var nameFilter = document.getElementById('dpiva-name-filter');
    var yearSelect = document.getElementById('dpiva-global-year');
    var monthSelect = document.getElementById('dpiva-global-month');
    var quarterSelect = document.getElementById('dpiva-global-quarter');
    var monthField = document.querySelector('.dpiva-global-month-field');
    var quarterField = document.querySelector('.dpiva-global-quarter-field');
    var monthNames = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    function selectedPeriodFor(periodType) {
        var isTrimestral = periodType === 'trimestral';
        var year = yearSelect ? yearSelect.value : '';
        var ref = isTrimestral ? (quarterSelect ? quarterSelect.value : '') : (monthSelect ? monthSelect.value : '');
        return { year: ref ? year : '', ref: ref, isTrimestral: isTrimestral };
    }

    // Periodo efetivo de cada empresa: o escolhido no cabecalho ou, sem
    // escolha, o periodo por defeito do modal "Enviar" (mes/trimestre atual).
    function effectivePeriodFor(periodType) {
        var period = selectedPeriodFor(periodType);
        if (!period.ref) {
            var now = new Date();
            period.year = String(now.getFullYear());
            period.ref = String(period.isTrimestral ? Math.ceil((now.getMonth() + 1) / 3) : (now.getMonth() + 1));
        }
        return period;
    }

    function buildPeriodLabel(period) {
        if (!period.ref) { return ''; }
        return period.isTrimestral ? (period.year + '-T' + period.ref) : (period.year + '-' + ('0' + period.ref).slice(-2));
    }

    function applyGlobalPeriod() {
        var unsettledCount = 0;
        document.querySelectorAll('.dpiva-entity-period').forEach(function (block) {
            var period = effectivePeriodFor(block.dataset.periodType);
            var display = block.querySelector('.dpiva-period-display');
            var section = block.closest('.dpiva-entity-section');
            var header = section ? section.querySelector('.iva-entity-header') : null;
            var periodLabel = buildPeriodLabel(period);
            var closedPeriods = section ? (section.dataset.closedPeriods || '').split(',') : [];
            var settledPeriods = section ? (section.dataset.settledPeriods || '').split(',') : [];
            var isClosed = periodLabel !== '' && closedPeriods.indexOf(periodLabel) !== -1;
            var isSettled = periodLabel !== '' && settledPeriods.indexOf(periodLabel) !== -1;

            var isUnsettled = !isClosed && !isSettled;
            var isVisible = section && section.style.display !== 'none';

            if (display) {
                display.textContent = period.isTrimestral ? (period.ref + 'º Trimestre ' + period.year) : (monthNames[parseInt(period.ref, 10)] + ' ' + period.year);
                display.style.display = isClosed ? 'none' : '';
            }
            if (header) {
                header.classList.toggle('iva-entity-header-closed', isClosed);
                header.classList.toggle('iva-entity-header-unsettled', isUnsettled);
            }
            block.querySelector('.dpiva-closed-badge').style.display = isClosed ? '' : 'none';
            block.querySelector('.dpiva-settled-badge').style.display = (!isClosed && isSettled) ? '' : 'none';
            block.querySelector('.dpiva-unsettled-alert').style.display = isUnsettled ? '' : 'none';
            if (isUnsettled && isVisible) { unsettledCount++; }
        });

        var summary = document.querySelector('.dpiva-unsettled-summary');
        if (summary) {
            summary.style.display = unsettledCount > 0 ? '' : 'none';
            summary.querySelector('.dpiva-unsettled-summary-text').textContent = unsettledCount === 1
                ? '1 empresa ainda não tem o Apuramento de IVA fechado para o período. Confirme os valores antes de enviar.'
                : unsettledCount + ' empresas ainda não têm o Apuramento de IVA fechado para o período. Confirme os valores antes de enviar.';
        }
    }

    // Pesquisa sem distinguir maiusculas/acentos, por nome ou NIF.
    function normalizeSearch(value) {
        return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    }

    function applyPeriodicityFilter() {
        var value = periodicityFilter ? periodicityFilter.value : '';
        var term = normalizeSearch(nameFilter ? nameFilter.value : '');
        var visibleCount = 0;
        entitySections.forEach(function (section) {
            var matchesPeriodicity = value === '' || section.dataset.vatPeriodicity === value;
            var matchesName = term === '' || normalizeSearch(section.dataset.search).indexOf(term) !== -1;
            var isVisible = matchesPeriodicity && matchesName;
            section.style.display = isVisible ? '' : 'none';
            if (isVisible) { visibleCount++; }
        });
        document.querySelectorAll('.dpiva-history-row').forEach(function (row) {
            row.style.display = (term === '' || normalizeSearch(row.dataset.search).indexOf(term) !== -1) ? '' : 'none';
        });
        var noMatch = document.querySelector('.dpiva-no-match');
        if (noMatch) { noMatch.style.display = (entitySections.length > 0 && visibleCount === 0) ? '' : 'none'; }
        if (monthField) { monthField.style.display = (value === 'trimestral') ? 'none' : ''; }
        if (quarterField) { quarterField.style.display = (value === 'mensal') ? 'none' : ''; }
    }

    [yearSelect, monthSelect, quarterSelect].forEach(function (el) {
        if (el) { el.addEventListener('change', applyGlobalPeriod); }
    });
    if (periodicityFilter) { periodicityFilter.addEventListener('change', function () { applyPeriodicityFilter(); applyGlobalPeriod(); }); }
    if (nameFilter) { nameFilter.addEventListener('input', function () { applyPeriodicityFilter(); applyGlobalPeriod(); }); }

    applyPeriodicityFilter();
    applyGlobalPeriod();

    var detailUrl = '<?= BASE_URL; ?>contabilidade/tarefas/envio-dp-iva/detalhes';
    var detailModalBody = document.getElementById('dpiva-detail-modal-body');
    var detailModalEl = document.getElementById('dpiva-detail-modal');
    var periodClosed = false;

    function loadDetail(entityId, periodYear, periodRef) {
        detailModalBody.innerHTML = '<div class="text-center text-muted" style="padding: 30px 0;">A carregar...</div>';
        var url = detailUrl + '?entity_id=' + encodeURIComponent(entityId);
        if (periodYear) { url += '&period_year=' + encodeURIComponent(periodYear); }
        if (periodRef) { url += '&period_ref=' + encodeURIComponent(periodRef); }
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.text(); })
            .then(function (html) { detailModalBody.innerHTML = html; bindSendForm(); })
            .catch(function () {
                detailModalBody.innerHTML = '<div class="alert alert-danger">Erro ao carregar. Tente novamente.</div>';
            });
    }

    document.querySelectorAll('.dpiva-detail-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var section = btn.closest('.dpiva-entity-section');
            var period = selectedPeriodFor(section ? section.dataset.vatPeriodicity : 'mensal');
            loadDetail(btn.dataset.entityId, period.year, period.ref);
        });
    });

    function parseAmount(value) {
        var n = parseFloat(String(value || '0').replace(',', '.'));
        return isNaN(n) ? 0 : Math.abs(n);
    }
    function formatEuro(value) {
        return value.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €';
    }

    // Replica buildVatDpValuesMessage() (apuramento-iva-functions.php) para
    // atualizar a mensagem quando os valores mudam.
    function buildValuesMessage(resultType, valor, reembolso, deadline) {
        var lines = [];
        if (valor > 0) {
            lines.push('Tem um valor de IVA ' + (resultType === 'credito' ? 'em crédito' : 'a pagar') + ' de: ' + formatEuro(valor) + '.');
        } else if (resultType === 'pagar') {
            lines.push('Não tem IVA a pagar neste período.');
        }
        if (resultType === 'credito' && reembolso > 0) {
            lines.push('Foi solicitado reembolso de: ' + formatEuro(reembolso) + '.');
        }
        if (resultType === 'pagar' && valor > 0) {
            lines.push('Enviamos em anexo a respetiva guia de pagamento.' + (deadline ? ' Para liquidar até ' + deadline + '.' : ''));
        }
        return lines.join('\n');
    }

    function bindSendForm() {
        var form = detailModalBody.querySelector('.dpiva-send-form');
        if (!form) { return; }
        var resultSelect = form.querySelector('.dpiva-result-type');
        var valorInput = form.querySelector('.dpiva-valor');
        var valorLabel = form.querySelector('.dpiva-valor-label');
        var reembolsoField = form.querySelector('.dpiva-reembolso-field');
        var reembolsoInput = form.querySelector('.dpiva-reembolso');
        var messageInput = form.querySelector('.dpiva-email-message');
        var templateEl = form.querySelector('.dpiva-body-template');
        var toInput = form.querySelector('.dpiva-email-to');
        var hint = form.querySelector('.dpiva-hint');
        var expectedValue = parseFloat(form.dataset.expectedValue || '0');
        var expectedType = form.dataset.expectedType || 'pagar';
        var expectedAvailable = form.dataset.expectedAvailable === '1';
        var messageEdited = false;

        var toKey = 'dpIvaEmailTo:' + form.dataset.entityId;
        if (!toInput.value.trim() && window.localStorage) {
            var lastTo = localStorage.getItem(toKey);
            if (lastTo) { toInput.value = lastTo; }
        }

        messageInput.addEventListener('input', function () { messageEdited = true; });

        function refresh() {
            var isCredito = resultSelect.value === 'credito';
            var valor = parseAmount(valorInput.value);
            var reembolso = isCredito ? parseAmount(reembolsoInput.value) : 0;
            reembolsoField.style.display = isCredito ? '' : 'none';
            valorLabel.textContent = isCredito ? 'IVA em crédito (€)' : 'IVA a pagar (€)';

            var message = '';
            if (expectedAvailable) {
                if (isCredito !== (expectedType === 'credito')) {
                    message = 'A contabilidade indica um período ' + (expectedType === 'credito' ? 'em crédito' : 'a pagar') + '.';
                } else if (Math.abs(valor - expectedValue) > 0.01) {
                    message = 'O valor deveria ser ' + formatEuro(expectedValue) + ' (calculado pela contabilidade).';
                } else if (isCredito && reembolso > expectedValue + 0.01) {
                    message = 'O reembolso pedido excede o crédito apurado (' + formatEuro(expectedValue) + ').';
                }
            }
            hint.textContent = message;

            if (!messageEdited && templateEl) {
                messageInput.value = templateEl.value.split('{mensagem_valores}').join(buildValuesMessage(resultSelect.value, valor, reembolso, form.dataset.deadline || ''));
            }
        }
        resultSelect.addEventListener('change', refresh);
        valorInput.addEventListener('input', refresh);
        reembolsoInput.addEventListener('input', refresh);
        refresh();
    }

    if (detailModalBody) {
        detailModalBody.addEventListener('change', function (e) {
            var periodForm = e.target.closest('.dpiva-period-form');
            if (periodForm && (e.target.name === 'period_year' || e.target.name === 'period_ref')) {
                var y = periodForm.querySelector('[name="period_year"]').value;
                var r = periodForm.querySelector('[name="period_ref"]').value;
                loadDetail(periodForm.dataset.entityId, y, r);
            }
        });

        detailModalBody.addEventListener('submit', function (e) {
            var form = e.target.closest('.dpiva-detail-form');
            if (!form) { return; }
            e.preventDefault();
            var submitter = e.submitter || form.querySelector('button[name="action"][value="send_dp"]');
            var action = submitter ? submitter.value : 'send_dp';
            if (action === 'close_only' && !window.confirm('Fechar a tarefa sem enviar email ao cliente?')) { return; }
            var formData = new FormData(form);
            formData.set('action', action);
            if (action === 'send_dp' && window.localStorage) {
                var toInput = form.querySelector('.dpiva-email-to');
                if (toInput && toInput.value.trim()) { localStorage.setItem('dpIvaEmailTo:' + form.dataset.entityId, toInput.value.trim()); }
            }
            form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
            if (submitter) { submitter.innerHTML = '<i class="fa fa-spinner fa-spin"></i> A processar...'; }
            fetch(detailUrl + '?entity_id=' + encodeURIComponent(form.dataset.entityId), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData
            })
                .then(function (response) { return response.text(); })
                .then(function (html) {
                    detailModalBody.innerHTML = html;
                    if (detailModalBody.querySelector('[data-dpiva-period-closed]')) { periodClosed = true; }
                    bindSendForm();
                })
                .catch(function () {
                    detailModalBody.innerHTML = '<div class="alert alert-danger">Erro ao enviar. Tente novamente.</div>';
                });
        });
    }

    if (detailModalEl) {
        detailModalEl.addEventListener('hidden.bs.modal', function () {
            detailModalBody.innerHTML = '<div class="text-center text-muted" style="padding: 30px 0;">A carregar...</div>';
            if (periodClosed) { window.location.reload(); }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
