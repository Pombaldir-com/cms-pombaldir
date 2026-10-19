<?php
// Tarefa "Confirmacao de Pasta": colaboradores conferem, por empresa e por
// mes, os valores da pasta (fita) com os lancamentos de cada diario no ERP e
// os totais do SAF-T com o balancete, e fecham o mes (permissao
// ctb_confirmacao_pasta, gerida na ficha da empresa em Entidades > Empresas >
// Admin). Administradores veem todas as empresas. Equivalente a fase 4
// ("Lancamento de docs") da intranet legacy.
// Ver contabilidade/CONFIRMACAO_PASTA.md.

require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/apuramento-iva-functions.php';
require_once __DIR__ . '/confirmacao-pasta-functions.php';

startSession();
requireLogin();

$user = currentUser();
$isAdmin = ((int) ($user['role'] ?? 3)) <= 2;
$userId = (int) ($user['id'] ?? 0);

if (!$isAdmin && !userHasAccountingEntityTaskPermission('ctb_confirmacao_pasta')) {
    http_response_code(403);
    echo 'Acesso negado.';
    exit;
}

$pdo = getPDO();

if (!hasTable('accounting_folder_confirmations') || !hasTable('accounting_folder_tape_values')) {
    http_response_code(500);
    echo 'As tabelas da Confirmação de Pasta ainda não existem. Execute as migrações.';
    exit;
}

$feedback = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }
    if (($_POST['action'] ?? '') === 'save_settings') {
        if (!$isAdmin) {
            http_response_code(403);
            echo 'Acesso negado.';
            exit;
        }
        $creditFormula = normalizeFolderSaftFormula((string) ($_POST['saft_credit_formula'] ?? ''));
        $debitFormula = normalizeFolderSaftFormula((string) ($_POST['saft_debit_formula'] ?? ''));
        try {
            parseAccountingVatFieldFormula($creditFormula);
            parseAccountingVatFieldFormula($debitFormula);
            setSetting('confirmacao_pasta_saft_credit_formula', $creditFormula);
            setSetting('confirmacao_pasta_saft_debit_formula', $debitFormula);
            logAuditAction('update', 'setting', null, [
                'context' => 'confirmacao_pasta_saft_formulas',
                'changed_by' => $userId,
            ]);
            $feedback = ['type' => 'success', 'message' => 'Configurações guardadas.'];
        } catch (InvalidArgumentException $e) {
            $feedback = ['type' => 'danger', 'message' => $e->getMessage()];
        }
    }
}

$entities = getIvaTaskEntities($pdo, $isAdmin, $userId, 'ctb_confirmacao_pasta');
$allowedEntityIds = array_map(static fn($row) => (int) $row['id'], $entities);
$entitiesById = [];
$closedPeriodsByEntity = [];
foreach ($entities as $entityRow) {
    $entitiesById[(int) $entityRow['id']] = $entityRow;
    $closedPeriodsByEntity[(int) $entityRow['id']] = getClosedFolderPeriods($pdo, (int) $entityRow['id']);
}

$history = [];
if ($allowedEntityIds) {
    $placeholders = implode(',', array_fill(0, count($allowedEntityIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT c.*, u.name AS closed_by_name, u.username AS closed_by_username
         FROM accounting_folder_confirmations c
         LEFT JOIN users u ON u.id = c.closed_by
         WHERE c.accounting_entity_id IN ($placeholders)
         ORDER BY c.created_at DESC
         LIMIT 100"
    );
    $stmt->execute($allowedEntityIds);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$defaultPeriod = strtotime(date('Y-m-01') . ' -1 month');
$defaultYear = (int) date('Y', $defaultPeriod);
$defaultMonth = (int) date('n', $defaultPeriod);
$monthNames = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

$useDataTables = false;
require_once __DIR__ . '/../header.php';
?>

<div class="page-title">
    <div class="title_left">
        <h3>Tarefas <small>Confirmação de Pasta</small></h3>
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
                <h2><i class="fa fa-folder-open"></i> Confirmação de Pasta</h2>
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
                        <label for="cpasta-name-filter">Empresa</label>
                        <input type="search" id="cpasta-name-filter" class="form-control" placeholder="Nome ou NIF" autocomplete="off">
                    </li>
                    <li class="iva-periodicity-field">
                        <label for="cpasta-global-year">Ano</label>
                        <select id="cpasta-global-year" class="form-control">
                            <?php for ($y = $currentYear; $y >= $currentYear - 2; $y--): ?>
                                <option value="<?= $y; ?>" <?= $y === $defaultYear ? 'selected' : ''; ?>><?= $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </li>
                    <li class="iva-periodicity-field">
                        <label for="cpasta-global-month">Mês</label>
                        <select id="cpasta-global-month" class="form-control">
                            <?php foreach (range(1, 12) as $m): ?>
                                <option value="<?= $m; ?>" <?= $m === $defaultMonth ? 'selected' : ''; ?>><?= sprintf('%02d', $m); ?> - <?= $monthNames[$m]; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </li>
                    <li class="iva-periodicity-field">
                        <label for="cpasta-state-filter">Estado</label>
                        <select id="cpasta-state-filter" class="form-control">
                            <option value="">Todos</option>
                            <option value="open">Por fechar</option>
                            <option value="closed">Fechados</option>
                        </select>
                    </li>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                    <li>
                        <button type="button" class="btn btn-default btn-sm" data-bs-toggle="modal" data-bs-target="#cpasta-settings-modal" title="Configurações da tarefa">
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
                <div class="iva-entity-accordion">
                <?php foreach ($entities as $entityRow):
                    $entityId = (int) $entityRow['id'];
                ?>
                <div class="iva-entity-panel cpasta-entity-section" data-search="<?= htmlspecialchars((string) $entityRow['name'] . ' ' . (string) $entityRow['nif']); ?>" data-closed-periods="<?= htmlspecialchars(implode(',', $closedPeriodsByEntity[$entityId] ?? [])); ?>">
                    <div class="iva-entity-header">
                        <span class="iva-entity-name">
                            <?= htmlspecialchars((string) $entityRow['name']); ?>
                            <small class="text-muted">NIF <?= htmlspecialchars((string) $entityRow['nif']); ?></small>
                        </span>
                        <div class="iva-entity-actions">
                            <span class="cpasta-period-display label label-default" style="display: inline-block; padding: 6px 10px; font-size: 13px;"></span>
                            <span class="label label-success cpasta-closed-badge" style="display: none;"><i class="fa fa-check"></i> Pasta confirmada</span>
                            <button type="button" class="btn btn-primary btn-sm cpasta-detail-trigger" data-entity-id="<?= $entityId; ?>" data-bs-toggle="modal" data-bs-target="#cpasta-detail-modal">
                                <i class="fa fa-search"></i> Conferir
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <div class="alert alert-info cpasta-no-match" style="display: none;">Nenhuma empresa corresponde ao filtro.</div>
                <?php endif; ?>

                <div class="iva-history-panel">
                    <button type="button" class="iva-history-header" data-bs-toggle="collapse" data-bs-target="#cpasta-history-collapse" aria-expanded="<?= $history ? 'true' : 'false'; ?>" aria-controls="cpasta-history-collapse">
                        <i class="fa fa-chevron-right iva-history-chevron"></i>
                        <span class="iva-entity-name">Histórico de fechos</span>
                        <span class="iva-history-count"><?= count($history); ?> registo<?= count($history) === 1 ? '' : 's'; ?></span>
                    </button>
                    <div class="collapse<?= $history ? ' show' : ''; ?>" id="cpasta-history-collapse">
                        <?php if (!$history): ?>
                        <p class="text-muted" style="padding: 14px 16px; margin: 0;">Sem fechos registados ainda.</p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped" style="margin-bottom: 0;">
                                <thead>
                                    <tr>
                                        <th>Empresa</th>
                                        <th>Mês</th>
                                        <th>Divergências</th>
                                        <th>Observação</th>
                                        <th>Fechado por</th>
                                        <th>Data</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $row):
                                        $rowEntity = $entitiesById[(int) $row['accounting_entity_id']] ?? null;
                                    ?>
                                    <tr class="cpasta-history-row" data-search="<?= htmlspecialchars((string) ($rowEntity['name'] ?? '') . ' ' . (string) ($rowEntity['nif'] ?? '')); ?>">
                                        <td><?= htmlspecialchars((string) ($rowEntity['name'] ?? '—')); ?></td>
                                        <td><?= htmlspecialchars((string) $row['period_label']); ?></td>
                                        <td>
                                            <?php if ((int) $row['divergences_count'] > 0): ?>
                                                <span class="badge bg-red"><?= (int) $row['divergences_count']; ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-green">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $row['observacao'] ? htmlspecialchars((string) $row['observacao']) : '—'; ?></td>
                                        <td><?= htmlspecialchars((string) ($row['closed_by_name'] ?: $row['closed_by_username'] ?: '—')); ?></td>
                                        <td><?= htmlspecialchars((string) $row['created_at']); ?></td>
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

<div class="modal fade" id="cpasta-detail-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <style>
        @media (min-width: 992px) { #cpasta-detail-modal .modal-dialog { max-width: 960px; } }
    </style>
    <div class="modal-dialog modal-lg modal-dialog-scrollable" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirmação de Pasta</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body" id="cpasta-detail-modal-body">
                <div class="text-center text-muted" style="padding: 30px 0;">A carregar...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="cpasta-settings-modal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
                <input type="hidden" name="action" value="save_settings">
                <div class="modal-header">
                    <h5 class="modal-title">Configurações — Confirmação de Pasta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted">
                        Fórmulas do balancete comparadas com os totais do SAF-T do mês
                        (equivalente a Definições &gt; "SAFT Créditos/Débitos" do legacy). Formato
                        <code>C&lt;conta&gt;[cre|deb]&lt;+|-&gt;</code> repetido, ex.: <code>C71+C72+C78-</code>.
                        Sem <code>cre</code>/<code>deb</code>, a fórmula de créditos usa o saldo credor
                        e a de débitos o saldo devedor de cada conta.
                    </p>
                    <div class="form-group">
                        <label for="cpasta-saft-credit">Total Créditos (SAF-T)</label>
                        <input type="text" id="cpasta-saft-credit" name="saft_credit_formula" class="form-control" value="<?= htmlspecialchars((string) getSetting('confirmacao_pasta_saft_credit_formula', '')); ?>" placeholder="ex: C71+C72+">
                    </div>
                    <div class="form-group">
                        <label for="cpasta-saft-debit">Total Débitos (SAF-T)</label>
                        <input type="text" id="cpasta-saft-debit" name="saft_debit_formula" class="form-control" value="<?= htmlspecialchars((string) getSetting('confirmacao_pasta_saft_debit_formula', '')); ?>" placeholder="ex: C71+C72+">
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
    var yearSelect = document.getElementById('cpasta-global-year');
    var monthSelect = document.getElementById('cpasta-global-month');
    var stateFilter = document.getElementById('cpasta-state-filter');
    var nameFilter = document.getElementById('cpasta-name-filter');
    var monthNames = <?= json_encode($monthNames, JSON_UNESCAPED_UNICODE); ?>;

    function selectedPeriod() {
        return {
            year: yearSelect ? yearSelect.value : '<?= $defaultYear; ?>',
            ref: monthSelect ? monthSelect.value : '<?= $defaultMonth; ?>'
        };
    }

    // Pesquisa sem distinguir maiusculas/acentos, por nome ou NIF.
    function normalizeSearch(value) {
        return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
    }

    function applyGlobalPeriod() {
        var period = selectedPeriod();
        var label = period.year + '-' + ('0' + period.ref).slice(-2);
        var filter = stateFilter ? stateFilter.value : '';
        var term = normalizeSearch(nameFilter ? nameFilter.value : '');
        var sections = document.querySelectorAll('.cpasta-entity-section');
        var visibleCount = 0;
        sections.forEach(function (section) {
            var closed = (section.dataset.closedPeriods || '').split(',').indexOf(label) !== -1;
            section.querySelector('.iva-entity-header').classList.toggle('iva-entity-header-closed', closed);
            section.querySelector('.cpasta-closed-badge').style.display = closed ? '' : 'none';
            var display = section.querySelector('.cpasta-period-display');
            display.textContent = monthNames[parseInt(period.ref, 10)] + ' ' + period.year;
            display.style.display = closed ? 'none' : '';
            var isVisible = (filter === '' || (filter === 'closed') === closed)
                && (term === '' || normalizeSearch(section.dataset.search).indexOf(term) !== -1);
            section.style.display = isVisible ? '' : 'none';
            if (isVisible) { visibleCount++; }
        });
        document.querySelectorAll('.cpasta-history-row').forEach(function (row) {
            row.style.display = (term === '' || normalizeSearch(row.dataset.search).indexOf(term) !== -1) ? '' : 'none';
        });
        var noMatch = document.querySelector('.cpasta-no-match');
        if (noMatch) { noMatch.style.display = (sections.length > 0 && visibleCount === 0) ? '' : 'none'; }
    }

    [yearSelect, monthSelect, stateFilter].forEach(function (el) {
        if (el) { el.addEventListener('change', applyGlobalPeriod); }
    });
    if (nameFilter) { nameFilter.addEventListener('input', applyGlobalPeriod); }
    applyGlobalPeriod();

    var detailUrl = '<?= BASE_URL; ?>contabilidade/tarefas/confirmacao-pasta/detalhes';
    var detailModalBody = document.getElementById('cpasta-detail-modal-body');
    var detailModalEl = document.getElementById('cpasta-detail-modal');
    var periodClosed = false;

    function renderDetail(html) {
        detailModalBody.innerHTML = html;
        if (detailModalBody.querySelector('[data-cpasta-period-closed]')) { periodClosed = true; }
        var addInput = detailModalBody.querySelector('.cpasta-add-input');
        if (addInput) { addInput.focus(); }
    }

    function loadDetail(entityId, periodYear, periodRef, journal) {
        detailModalBody.innerHTML = '<div class="text-center text-muted" style="padding: 30px 0;">A carregar...</div>';
        var url = detailUrl + '?entity_id=' + encodeURIComponent(entityId)
            + '&period_year=' + encodeURIComponent(periodYear)
            + '&period_ref=' + encodeURIComponent(periodRef);
        if (journal !== undefined && journal !== null && journal !== '') { url += '&journal=' + encodeURIComponent(journal); }
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) { return response.text(); })
            .then(renderDetail)
            .catch(function () {
                detailModalBody.innerHTML = '<div class="alert alert-danger">Erro ao carregar. Tente novamente.</div>';
            });
    }

    function submitForm(form) {
        var formData = new FormData(form);
        form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
        fetch(detailUrl + '?entity_id=' + encodeURIComponent(formData.get('entity_id')), {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        })
            .then(function (response) { return response.text(); })
            .then(renderDetail)
            .catch(function () {
                detailModalBody.innerHTML = '<div class="alert alert-danger">Erro ao gravar. Tente novamente.</div>';
            });
    }

    document.querySelectorAll('.cpasta-detail-trigger').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var period = selectedPeriod();
            loadDetail(btn.dataset.entityId, period.year, period.ref);
        });
    });

    if (detailModalBody) {
        detailModalBody.addEventListener('change', function (e) {
            var periodForm = e.target.closest('.cpasta-period-form');
            if (periodForm && periodForm.tagName === 'FORM') {
                loadDetail(periodForm.dataset.entityId,
                    periodForm.querySelector('[name="period_year"]').value,
                    periodForm.querySelector('[name="period_ref"]').value);
                return;
            }
            // Edicao de um valor da fita: grava ao sair do campo.
            if (e.target.classList.contains('cpasta-autosubmit')) {
                submitForm(e.target.form);
            }
        });

        detailModalBody.addEventListener('click', function (e) {
            var link = e.target.closest('.cpasta-journal-link');
            if (!link) { return; }
            e.preventDefault();
            loadDetail(link.dataset.entityId, link.dataset.periodYear, link.dataset.periodRef, link.dataset.journal);
        });

        // Enter adiciona o valor (como a calculadora da fita do legacy);
        // Shift+Enter permite varias linhas.
        detailModalBody.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.shiftKey) { return; }
            if (e.target.classList.contains('cpasta-add-input')) {
                e.preventDefault();
                if (e.target.value.trim() !== '') { submitForm(e.target.form); }
            } else if (e.target.classList.contains('cpasta-autosubmit')) {
                e.preventDefault();
                e.target.blur();
            }
        });

        detailModalBody.addEventListener('submit', function (e) {
            var form = e.target.closest('.cpasta-ajax-form');
            if (!form) { return; }
            e.preventDefault();
            if (form.classList.contains('cpasta-close-form')) {
                var divergences = parseInt(form.dataset.divergences || '0', 10);
                var question = divergences > 0
                    ? 'Existem ' + divergences + ' divergência(s) por resolver. Fechar o mês mesmo assim?'
                    : 'Fechar a confirmação de pasta deste mês?';
                if (!window.confirm(question)) { return; }
            }
            if (form.querySelector('[name="action"]').value === 'delete_tape_value' && !window.confirm('Eliminar este valor da fita?')) { return; }
            submitForm(form);
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
