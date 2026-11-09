<?php
/**
 * Painel principal do CMS.
 *
 * Página temporariamente em branco. Requer que o utilizador esteja
 * autenticado para aceder.
 */

// Usar as funções comuns
require_once __DIR__ . '/functions.php';

// Inicia sessão e verifica se o utilizador está autenticado
startSession();
requireLogin();

// Calendario (calendario/functions.php, docs/calendario.md): FullCalendar e
// Select2 so quando as tabelas existem.
require_once __DIR__ . '/calendario/functions.php';
$showCalendar = calendarHasTables();
$calendarConfig = [];
$calendarUpcoming = [];
if ($showCalendar) {
    $calendarUserId = (int) ($_SESSION['user_id'] ?? 0);
    $calendarRole = (int) ($_SESSION['user_role'] ?? 3);
    $calendarOpen = strtolower(trim((string) ($_GET['evento'] ?? '')));
    $calendarConfig = [
        'handlerUrl' => BASE_URL . 'calendario/handler',
        'csrfToken' => generateCsrfToken(),
        'userId' => $calendarUserId,
        'isAdmin' => calendarIsAdmin($calendarRole),
        'clientsEnabled' => calendarClientsEnabled(),
        'users' => array_values(array_filter(calendarUsers(getPDO()), static fn (array $u): bool => $u['id'] !== $calendarUserId)),
        'reminders' => array_map(static fn (int $min, string $label): array => ['value' => $min, 'label' => $label], array_keys(CALENDAR_REMINDER_OPTIONS), CALENDAR_REMINDER_OPTIONS),
        'reminderDefault' => CALENDAR_REMINDER_DEFAULT,
        // dashboard?evento={uuid} (link dos avisos): abre esse evento.
        'open' => calendarIsUuid($calendarOpen) ? $calendarOpen : '',
    ];
    $calendarUpcoming = calendarUpcoming(getPDO(), $calendarUserId, 8);
    $useFullCalendar = true;
    $useSelect2 = true;
}

// Inclui o cabeçalho comum do template
require_once __DIR__ . '/header.php';

$importDocsCount = 0;
$classifDocsCount = 0;
$acquirerCompanyCount = 0;
$showImportCard = isModuleActive('contabilidade') && hasTable('accounting_imports');
$showClassifCard = $showImportCard;
$showCompaniesCard = isModuleActive('contabilidade') && hasTable('accounting_entities');
$accountingCards = [];
if ($showImportCard || $showClassifCard || $showCompaniesCard) {
    if ($showImportCard || $showClassifCard) {
        require_once __DIR__ . '/contabilidade/functions.php';
    }
    $pdo = getPDO();
    if ($showImportCard) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM accounting_imports '
            . 'WHERE import_type = :importType AND (cab_id IS NULL OR cab_id = \'\')'
        );
        $stmt->execute([':importType' => 1]);
        $importDocsCount = (int) $stmt->fetchColumn();
    }
    if ($showClassifCard) {
        $stmt = $pdo->prepare(
            'SELECT account, field_I3, field_I4, field_I5, field_I6, field_I7, field_I8, field_N, field_O '
            . 'FROM accounting_imports '
            . 'WHERE import_type = :importType AND (cab_id IS NULL OR cab_id = \'\')'
        );
        $stmt->execute([':importType' => 1]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $classifDocsCount = 0;
        foreach ($rows as $row) {
            $accounts = normalizeAccountingAccounts($row['account'] ?? '');
            $accountMetadata = normalizeAccountingMetadata($row['account'] ?? '');
            $summaries = computeImportRateSummaries($row);
            [$payload, $requirements] = buildRatePayload($summaries, $accounts);
            $btnClass = determineClassificationButtonClass($requirements, $payload, $accountMetadata);
            if ($btnClass !== 'btn-success') {
                $classifDocsCount++;
            }
        }
    }
    if ($showCompaniesCard) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM accounting_entities WHERE entity_type = 'acquirer' AND erp_database IS NOT NULL AND erp_database <> ''"
        );
        $stmt->execute();
        $acquirerCompanyCount = (int) $stmt->fetchColumn();
    }

    if ($showImportCard) {
        $accountingCards[] = [
            'title' => 'Documentos para importacao',
            'value' => $importDocsCount,
            'icon' => 'fa-file-text-o',
            'accent' => 'primary',
            'description' => 'Documentos pendentes e ainda disponiveis para tratamento.',
            'link' => BASE_URL . 'contabilidade/classificacao-importacao?import_type=1',
            'link_label' => 'Ver documentos',
        ];
    }

    if ($showClassifCard) {
        $accountingCards[] = [
            'title' => 'Documentos p/ Classif.',
            'value' => $classifDocsCount,
            'icon' => 'fa-tags',
            'accent' => 'warning',
            'description' => 'Linhas que ainda precisam de classificacao antes da importacao.',
            'link' => BASE_URL . 'contabilidade/classificacao-importacao?import_type=1',
            'link_label' => 'Abrir classificacao',
        ];
    }

    if ($showCompaniesCard) {
        $accountingCards[] = [
            'title' => 'Empresas integradas',
            'value' => $acquirerCompanyCount,
            'icon' => 'fa-building',
            'accent' => 'success',
            'description' => 'Entidades com base de dados ERP associada e pronta a integrar.',
            'link' => null,
            'link_label' => 'Empresas integradas',
        ];
    }
}

$recentActivities = [];
$showRecentActivities = false;
if (isModuleActive('contabilidade')) {
    require_once __DIR__ . '/contabilidade/atividades-functions.php';
    if (hasAccountingActivityTables()) {
        $showRecentActivities = true;
        $recentActivities = getRecentActivities(getPDO(), 8);
    }
}
?>
<div class="container-fluid">
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-dashboard"></i> Dashboard <small>Resumo</small></h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <?php if (!empty($accountingCards)): ?>
                <style>
                    .dashboard-section-title {
                        margin: 0 0 18px;
                        color: #2a3f54;
                        font-weight: 600;
                    }
                    .dashboard-summary-grid {
                        display: flex;
                        flex-wrap: wrap;
                        margin: 0 -10px;
                    }
                    .dashboard-summary-col {
                        width: 33.3333%;
                        padding: 0 10px 20px;
                        display: flex;
                    }
                    .dashboard-summary-card {
                        position: relative;
                        width: 100%;
                        display: flex;
                        flex-direction: column;
                        min-height: 220px;
                        padding: 22px 22px 18px;
                        border: 1px solid #e6ecf3;
                        border-radius: 12px;
                        background: #fff;
                        box-shadow: 0 10px 24px rgba(32, 45, 64, 0.08);
                    }
                    .dashboard-summary-card:before {
                        content: "";
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        height: 4px;
                        border-radius: 12px 12px 0 0;
                        background: #337ab7;
                    }
                    .dashboard-summary-card.accent-warning:before {
                        background: #f0ad4e;
                    }
                    .dashboard-summary-card.accent-success:before {
                        background: #26b99a;
                    }
                    .dashboard-summary-head {
                        display: flex;
                        align-items: flex-start;
                        justify-content: space-between;
                        gap: 12px;
                        margin-bottom: 18px;
                    }
                    .dashboard-summary-label {
                        display: block;
                        margin-bottom: 6px;
                        color: #7b8a9a;
                        font-size: 12px;
                        font-weight: 700;
                        letter-spacing: 0.04em;
                        text-transform: uppercase;
                    }
                    .dashboard-summary-title {
                        margin: 0;
                        color: #2a3f54;
                        font-size: 21px;
                        line-height: 1.25;
                        font-weight: 600;
                    }
                    .dashboard-summary-icon {
                        width: 52px;
                        height: 52px;
                        flex: 0 0 52px;
                        border-radius: 14px;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        background: rgba(51, 122, 183, 0.12);
                        color: #337ab7;
                        font-size: 22px;
                    }
                    .dashboard-summary-card.accent-warning .dashboard-summary-icon {
                        background: rgba(240, 173, 78, 0.16);
                        color: #f0ad4e;
                    }
                    .dashboard-summary-card.accent-success .dashboard-summary-icon {
                        background: rgba(38, 185, 154, 0.16);
                        color: #26b99a;
                    }
                    .dashboard-summary-value {
                        margin: 0 0 10px;
                        color: #2a3f54;
                        font-size: 42px;
                        line-height: 1;
                        font-weight: 700;
                    }
                    .dashboard-summary-description {
                        min-height: 42px;
                        margin: 0 0 18px;
                        color: #73879c;
                        font-size: 14px;
                        line-height: 1.55;
                    }
                    .dashboard-summary-footer {
                        margin-top: auto;
                    }
                    .dashboard-summary-card .btn {
                        border-radius: 20px;
                        padding-left: 14px;
                        padding-right: 14px;
                    }
                    @media (max-width: 991px) {
                        .dashboard-summary-col {
                            width: 50%;
                        }
                    }
                    @media (max-width: 767px) {
                        /* Telemovel: cada cartao fica numa faixa compacta (titulo + icone,
                           numero + botao), para o calendario aparecer logo a seguir. */
                        .dashboard-section-title {
                            margin-bottom: 10px;
                            font-size: 15px;
                        }
                        .dashboard-summary-grid {
                            margin: 0;
                        }
                        .dashboard-summary-col {
                            width: 100%;
                            padding: 0 0 10px;
                        }
                        .dashboard-summary-card {
                            min-height: 0;
                            padding: 14px 14px 12px;
                            display: grid;
                            grid-template-columns: 1fr auto;
                            grid-template-areas: "head head" "body footer";
                            align-items: center;
                            column-gap: 10px;
                            border-radius: 10px;
                            box-shadow: 0 4px 12px rgba(32, 45, 64, 0.06);
                        }
                        .dashboard-summary-head {
                            grid-area: head;
                            margin-bottom: 6px;
                            align-items: center;
                        }
                        .dashboard-summary-label,
                        .dashboard-summary-description {
                            display: none;
                        }
                        .dashboard-summary-title {
                            font-size: 15px;
                        }
                        .dashboard-summary-icon {
                            width: 34px;
                            height: 34px;
                            flex-basis: 34px;
                            border-radius: 10px;
                            font-size: 15px;
                        }
                        .dashboard-summary-body {
                            grid-area: body;
                        }
                        .dashboard-summary-value {
                            margin: 0;
                            font-size: 28px;
                        }
                        .dashboard-summary-footer {
                            grid-area: footer;
                            margin-top: 0;
                            text-align: right;
                        }
                        .dashboard-summary-card .btn {
                            padding: 4px 12px;
                            font-size: 13px;
                            margin: 0;
                        }
                        .dashboard-summary-footer .text-muted {
                            display: none;
                        }
                    }
                </style>
                <h4 class="dashboard-section-title">Contabilidade</h4>
                <div class="dashboard-summary-grid">
                    <?php foreach ($accountingCards as $card): ?>
                    <div class="dashboard-summary-col">
                        <div class="dashboard-summary-card accent-<?= htmlspecialchars($card['accent'], ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="dashboard-summary-head">
                                <div>
                                    <span class="dashboard-summary-label">Resumo</span>
                                    <h3 class="dashboard-summary-title"><?= htmlspecialchars($card['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                </div>
                                <span class="dashboard-summary-icon">
                                    <i class="fa <?= htmlspecialchars($card['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                                </span>
                            </div>
                            <div class="dashboard-summary-body">
                                <p class="dashboard-summary-value"><?= number_format((int) $card['value'], 0, ',', '.'); ?></p>
                                <p class="dashboard-summary-description"><?= htmlspecialchars($card['description'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <div class="dashboard-summary-footer">
                                <?php if (!empty($card['link'])): ?>
                                <a class="btn btn-sm btn-<?= htmlspecialchars($card['accent'], ENT_QUOTES, 'UTF-8'); ?>" href="<?= htmlspecialchars((string) $card['link'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <i class="fa fa-arrow-right"></i> <?= htmlspecialchars($card['link_label'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php else: ?>
                                <span class="text-muted"><?= htmlspecialchars($card['link_label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">Sem dados disponíveis para importação.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($showCalendar): ?>
    <style>
        .dash-cal .x_title { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .dash-cal .x_title h2 { flex: 1 1 auto; }
        .dash-cal-tools { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .dash-cal-tools .form-select { width: auto; min-width: 150px; }
        .dash-cal-tools .btn { margin: 0; }
        #dash-calendar { min-height: 420px; }
        #dash-calendar .fc-toolbar-title { font-size: 18px; color: #2a3f54; font-weight: 600; }
        #dash-calendar .fc-toolbar-title::first-letter { text-transform: uppercase; }
        #dash-calendar .fc-button-primary { background: #2a3f54; border-color: #2a3f54; }
        #dash-calendar .fc-button-primary:not(:disabled).fc-button-active,
        #dash-calendar .fc-button-primary:not(:disabled):active { background: #1abb9c; border-color: #1abb9c; }
        #dash-calendar .fc-event { cursor: pointer; }
        #dash-calendar .fc-daygrid-day.fc-day-today, #dash-calendar .fc-timegrid-col.fc-day-today { background: #f3faf8; }
        .dash-cal-side { display: flex; flex-direction: column; gap: 12px; }
        .dash-cal-side .card-header { background: #fff; font-weight: 600; color: #2a3f54; }
        .dash-cal-legend span.badge { width: 14px; height: 14px; display: inline-block; margin-right: 6px; vertical-align: -2px; }
        .dash-cal-up { list-style: none; margin: 0; padding: 0; }
        .dash-cal-up li { border-bottom: 1px solid #eef0f2; }
        .dash-cal-up li:last-child { border-bottom: 0; }
        .dash-cal-up a { display: flex; gap: 10px; padding: 9px 12px; color: inherit; text-decoration: none; }
        .dash-cal-up a:hover { background: #f7f9fb; }
        .dash-cal-up .dot { flex: 0 0 8px; height: 8px; border-radius: 50%; margin-top: 6px; }
        .dash-cal-up .t { font-weight: 600; color: #2a3f54; line-height: 1.3; overflow-wrap: anywhere; }
        .dash-cal-up .m { font-size: 12px; color: #73879c; }
        .dash-cal-empty { padding: 16px 12px; color: #8a949e; font-size: 13px; }
        #calendar-event-modal .select2-container { width: 100% !important; }
        #calendar-event-modal .select2-container .select2-selection--single { height: 38px; padding-top: 4px; border-color: #ced4da; }
        #calendar-event-modal .select2-container .select2-selection__arrow { height: 36px; }
        #calendar-event-modal .form-label { font-weight: 600; color: #2a3f54; }
        .cal-client-link { font-size: 12px; }
        @media (max-width: 767px) {
            .dash-cal .x_content { padding-left: 0; padding-right: 0; }
            .dash-cal .x_title { margin-bottom: 8px; }
            .dash-cal-tools { width: 100%; }
            .dash-cal-tools .form-select { flex: 1 1 100%; min-width: 0; }
            .dash-cal-tools .btn { flex: 1 1 auto; }
            #dash-calendar { min-height: 0; }
            #dash-calendar .fc-toolbar { flex-wrap: wrap; gap: 6px; }
            #dash-calendar .fc-toolbar-title { font-size: 15px; }
            #dash-calendar .fc-list-day-cushion { padding: 6px 10px; }
            #dash-calendar .fc-list-event td { padding: 8px 6px; }
            #dash-calendar .fc-list-event-time { white-space: nowrap; font-size: 13px; color: #2a3f54; font-weight: 600; }
            #dash-calendar .fc-list-event-title { white-space: normal; overflow-wrap: anywhere; }
            /* No telemovel a lista da semana ja mostra as cores: a legenda so ocupa espaco. */
            .dash-cal-legend { display: none; }
        }
    </style>
    <div class="x_panel dash-cal">
        <div class="x_title">
            <h2><i class="fa fa-calendar"></i> Calendário <small>Agenda e eventos</small></h2>
            <div class="dash-cal-tools">
                <?php if ($calendarConfig['isAdmin']): ?>
                <select class="form-select form-select-sm" id="dash-cal-scope" aria-label="Eventos a mostrar">
                    <option value="mine">Os meus eventos</option>
                    <option value="all">Todos os utilizadores</option>
                </select>
                <?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="dash-cal-notify" title="Receber os lembretes como notificação do sistema">
                    <i class="fa fa-bell-o"></i> Ativar notificações
                </button>
                <button type="button" class="btn btn-sm btn-success" id="dash-cal-new"><i class="fa fa-plus"></i> Novo evento</button>
            </div>
        </div>
        <div class="x_content">
            <div class="row">
                <div class="col-lg-9 col-md-8 col-sm-12 mb-3">
                    <div id="dash-calendar"></div>
                </div>
                <div class="col-lg-3 col-md-4 col-sm-12 dash-cal-side">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header">Próximos eventos</div>
                        <div class="card-body p-0">
                            <?php if (!$calendarUpcoming): ?>
                            <div class="dash-cal-empty">Sem eventos agendados.</div>
                            <?php else: ?>
                            <ul class="dash-cal-up">
                                <?php foreach ($calendarUpcoming as $upcoming): ?>
                                <li>
                                    <a href="<?= BASE_URL ?>dashboard?evento=<?= htmlspecialchars((string) $upcoming['uuid'], ENT_QUOTES, 'UTF-8'); ?>" data-cal-open="<?= htmlspecialchars((string) $upcoming['uuid'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <span class="dot" style="background:<?= $upcoming['own'] ? CALENDAR_COLOR_OWN : CALENDAR_COLOR_INVITE; ?>"></span>
                                        <span>
                                            <span class="t"><?= htmlspecialchars((string) $upcoming['title'], ENT_QUOTES, 'UTF-8'); ?></span><br>
                                            <span class="m"><?= htmlspecialchars($upcoming['when'], ENT_QUOTES, 'UTF-8'); ?><?= (string) $upcoming['client_name'] !== '' ? ' · ' . htmlspecialchars((string) $upcoming['client_name'], ENT_QUOTES, 'UTF-8') : ''; ?></span>
                                        </span>
                                    </a>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm dash-cal-legend">
                        <div class="card-header">Legenda</div>
                        <div class="card-body">
                            <div class="mb-2"><span class="badge" style="background:<?= CALENDAR_COLOR_OWN; ?>">&nbsp;</span> Criado por mim</div>
                            <div class="mb-2"><span class="badge" style="background:<?= CALENDAR_COLOR_INVITE; ?>">&nbsp;</span> Convite</div>
                            <?php if ($calendarConfig['isAdmin']): ?>
                            <div class="mb-2"><span class="badge" style="background:<?= CALENDAR_COLOR_OTHER; ?>">&nbsp;</span> Outros utilizadores</div>
                            <?php endif; ?>
                            <div class="small text-muted mt-2">Clique num dia ou horário para criar um evento; arraste para mudar a hora.</div>
                            <div class="small mt-2"><a href="<?= BASE_URL ?>users/profile#calendario-telemovel"><i class="fa fa-mobile"></i> Ver no telemóvel</a></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="calendar-event-modal" tabindex="-1" aria-labelledby="calendar-event-modal-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
            <form class="modal-content" id="calendar-event-form" novalidate>
                <div class="modal-header">
                    <h5 class="modal-title" id="calendar-event-modal-title">Novo evento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="uuid" id="cal-uuid">
                    <div class="mb-3">
                        <label class="form-label" for="cal-title">Título <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="title" id="cal-title" maxlength="190" required>
                    </div>
                    <?php if ($calendarConfig['clientsEnabled']): ?>
                    <div class="mb-3">
                        <label class="form-label" for="cal-client">Cliente</label>
                        <select class="form-control" name="entity_id" id="cal-client"></select>
                        <a href="#" class="cal-client-link d-none" id="cal-client-link" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> Abrir ficha do cliente</a>
                    </div>
                    <?php endif; ?>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="all_day" value="1" id="cal-all-day">
                        <label class="form-check-label" for="cal-all-day">Dia inteiro</label>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="cal-start">Início <span class="text-danger">*</span></label>
                            <input type="datetime-local" class="form-control" name="start" id="cal-start" required>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="cal-end">Fim</label>
                            <input type="datetime-local" class="form-control" name="end" id="cal-end">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="cal-location">Local</label>
                            <input type="text" class="form-control" name="location" id="cal-location" maxlength="190">
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="form-label" for="cal-reminder">Lembrete</label>
                            <select class="form-select" name="reminder_minutes" id="cal-reminder"></select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="cal-members">Utilizadores convidados</label>
                        <select class="form-control" name="members[]" id="cal-members" multiple></select>
                        <div class="form-text">Os convidados veem o evento no seu calendário, recebem um aviso agora e o lembrete antes do início.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="cal-description">Observações</label>
                        <textarea class="form-control" name="description" id="cal-description" rows="3" maxlength="5000"></textarea>
                    </div>
                    <p class="small text-muted mt-3 mb-0 d-none" id="cal-info"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-danger me-auto d-none" id="cal-delete"><i class="fa fa-trash"></i> Eliminar</button>
                    <button type="button" class="btn btn-default" data-bs-dismiss="modal">Fechar</button>
                    <button type="submit" class="btn btn-success" id="cal-save"><i class="fa fa-save"></i> Gravar</button>
                </div>
            </form>
        </div>
    </div>
    <script type="application/json" id="dash-calendar-config"><?= json_encode($calendarConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
    <?php endif; ?>
    <?php if ($showRecentActivities): ?>
    <style>
        .dash-act-list { list-style: none; margin: 0; padding: 0; }
        .dash-act-item { display: flex; align-items: center; gap: 14px; padding: 12px 6px; border-bottom: 1px solid #eef0f2; border-radius: 6px; color: inherit; }
        .dash-act-item:hover { background: #f7f9fb; color: inherit; }
        .dash-act-list li:last-child .dash-act-item { border-bottom: 0; }
        .dash-act-avatar { flex: 0 0 40px; width: 40px; height: 40px; border-radius: 50%; background: #26b99a; color: #fff; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; }
        .dash-act-main { flex: 1 1 auto; min-width: 0; }
        .dash-act-client { font-weight: 600; color: #2a3f54; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .dash-act-client small { font-weight: 500; color: #8a949e; margin-left: 6px; }
        .dash-act-cats { margin-top: 3px; }
        .dash-act-cat { display: inline-block; background: #eef3f8; color: #2f5f8f; border-radius: 12px; padding: 1px 8px; font-size: 11px; margin: 1px 3px 1px 0; white-space: nowrap; }
        .dash-act-side { flex: 0 0 auto; text-align: right; font-size: 12px; color: #8a949e; }
        .dash-act-side strong { display: block; color: #2a3f54; font-weight: 600; font-size: 13px; }
        .dash-act-empty { text-align: center; padding: 26px 10px; color: #8a949e; }
        .dash-act-empty i { font-size: 34px; color: #cfd6dd; display: block; margin-bottom: 8px; }
        @media (max-width: 575px) { .dash-act-side { display: none; } }
    </style>
    <div class="x_panel">
        <div class="x_title d-flex align-items-center justify-content-between">
            <h2><i class="fa fa-calendar-check-o"></i> Últimas atividades</h2>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>contabilidade/atividades/novo" class="btn btn-sm btn-primary mb-0"><i class="fa fa-plus"></i> Registar</a>
                <a href="<?= BASE_URL ?>contabilidade/atividades" class="btn btn-sm btn-outline-secondary mb-0">Ver todas</a>
            </div>
        </div>
        <div class="x_content">
            <?php if (!$recentActivities): ?>
            <div class="dash-act-empty">
                <i class="fa fa-calendar-o"></i>
                Ainda não existem atividades registadas.
            </div>
            <?php else: ?>
            <ul class="dash-act-list">
                <?php foreach ($recentActivities as $recent): ?>
                <li>
                    <a class="dash-act-item text-decoration-none" href="<?= BASE_URL ?>contabilidade/atividades/<?= htmlspecialchars((string) $recent['uuid'], ENT_QUOTES, 'UTF-8'); ?>">
                        <span class="dash-act-avatar"><?= htmlspecialchars(activityInitials((string) $recent['client_name']), ENT_QUOTES, 'UTF-8'); ?></span>
                        <div class="dash-act-main">
                            <div class="dash-act-client">
                                <?= htmlspecialchars((string) $recent['client_name'], ENT_QUOTES, 'UTF-8'); ?>
                                <small><?= htmlspecialchars((string) $recent['code'], ENT_QUOTES, 'UTF-8'); ?></small>
                            </div>
                            <div class="dash-act-cats">
                                <?php foreach ($recent['lines'] as $recentLine): ?>
                                <span class="dash-act-cat"><?= htmlspecialchars($recentLine['title'] . ($recentLine['value'] !== '' ? ' · ' . $recentLine['value'] : ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="dash-act-side" title="<?= htmlspecialchars(formatActivityDateTime((string) $recent['created_at']), ENT_QUOTES, 'UTF-8'); ?>">
                            <strong><?= htmlspecialchars(formatActivityRelative((string) $recent['created_at']), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?= htmlspecialchars((string) $recent['responsible_label'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
// Inclui o rodapé comum do template
require_once __DIR__ . '/footer.php';
