<?php
// Perfil (users.php?profile): "Calendario no telemovel" (CalDAV). Os
// formularios publicam em calendario/handler (caldav_create / caldav_revoke)
// e voltam aqui; a password de um acesso novo vem uma unica vez no flash
// 'caldav'. Ver calendario/caldav-functions.php e docs/calendario.md.

require_once dirname(__DIR__) . '/caldav-functions.php';

$caldavPanelReady = calendarHasTables() && calendarHasCaldavTable();
$caldavFlash = pullSessionFlash('caldav');
$caldavPanelUserId = (int) ($_SESSION['user_id'] ?? 0);
$caldavAccesses = $caldavPanelReady ? caldavListAccesses(getPDO(), $caldavPanelUserId) : [];
$caldavServerUrl = appAbsoluteBaseUrl() . 'caldav/';
$caldavEsc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$caldavCsrf = generateCsrfToken();
$caldavHandler = BASE_URL . 'calendario/handler';
$caldavAppName = calendarAppName();
// Link de uso unico para instalar o perfil a partir da app instalada no iOS
// (abre no Safari, que nao tem a sessao da app): calendario/profile-download.php.
$caldavProfileLink = '';
if ($caldavPanelReady && caldavAvailable()) {
    $caldavProfileLink = appAbsoluteBaseUrl() . 'caldav-perfil/' . caldavProfileTokenCreate(caldavCurrentCompanyNif(), $caldavPanelUserId);
}
?>
<div class="row" id="calendario-telemovel">
    <div class="col-md-8 col-sm-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-mobile"></i> Calendário no telemóvel <small>CalDAV</small></h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?php if (!$caldavPanelReady): ?>
                    <p class="text-muted mb-0">Execute as migrações pendentes para ativar o calendário no telemóvel.</p>
                <?php elseif (!caldavAvailable()): ?>
                    <p class="text-muted mb-0">O servidor CalDAV não está instalado (falta <code>composer install</code>).</p>
                <?php else: ?>
                <p class="text-muted">
                    Veja e edite no calendário do telemóvel os eventos que criou no dashboard e, só para ver, os eventos
                    para que foi convidado. Os lembretes passam como alarmes do telemóvel. Cada dispositivo tem a sua própria password.
                </p>
                <?php if (is_array($caldavFlash) && ($caldavFlash['type'] ?? '') === 'new'): ?>
                    <div class="alert alert-warning">
                        <strong>Acesso criado para "<?= $caldavEsc($caldavFlash['name'] ?? ''); ?>".</strong> Anote a password: não volta a ser mostrada.
                        <dl class="row mb-0 mt-2 small">
                            <dt class="col-sm-3">Servidor</dt><dd class="col-sm-9"><code><?= $caldavEsc($caldavServerUrl); ?></code></dd>
                            <dt class="col-sm-3">Utilizador</dt><dd class="col-sm-9"><code><?= $caldavEsc($caldavFlash['login'] ?? ''); ?></code></dd>
                            <dt class="col-sm-3">Password</dt><dd class="col-sm-9"><code style="font-size:15px"><?= $caldavEsc($caldavFlash['password'] ?? ''); ?></code></dd>
                        </dl>
                    </div>
                <?php elseif (is_array($caldavFlash) && !empty($caldavFlash['message'])): ?>
                    <div class="alert alert-<?= ($caldavFlash['type'] ?? '') === 'success' ? 'success' : 'danger'; ?>"><?= $caldavEsc($caldavFlash['message']); ?></div>
                <?php endif; ?>

                <form method="post" action="<?= $caldavEsc($caldavHandler); ?>" class="row g-2 align-items-end mb-3">
                    <input type="hidden" name="action" value="caldav_create">
                    <input type="hidden" name="csrf_token" value="<?= $caldavEsc($caldavCsrf); ?>">
                    <div class="col-sm-5">
                        <label class="form-label" for="caldav-name">Nome do dispositivo</label>
                        <input type="text" class="form-control" id="caldav-name" name="name" maxlength="120" placeholder="ex.: iPhone do trabalho">
                    </div>
                    <div class="col-sm-7 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-outline-primary mb-0" name="device" value="ios" id="caldav-ios-button"><i class="fa fa-apple"></i> Instalar no iPhone / iPad / Mac</button>
                        <?php if ($caldavProfileLink !== ''): ?>
                        <a href="<?= $caldavEsc($caldavProfileLink); ?>" target="_blank" rel="noopener" class="btn btn-primary mb-0 d-none" id="caldav-ios-link" data-base="<?= $caldavEsc($caldavProfileLink); ?>"><i class="fa fa-apple"></i> Instalar no iPhone / iPad</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-outline-secondary mb-0" name="device" value="manual" id="caldav-manual-button"><i class="fa fa-android"></i> Android / outro</button>
                    </div>
                </form>
                <div class="alert alert-info small d-none" id="caldav-standalone-note">
                    Abre no Safari e descarrega o perfil. Depois toque em <strong>Permitir</strong> e vá a
                    Definições &gt; Geral &gt; VPN e gestão de dispositivos &gt; perfil "<?= $caldavEsc($caldavAppName); ?>" &gt; Instalar.
                    O link serve uma vez: para outro dispositivo, volte a abrir esta página.
                </div>
                <script>
                (function () {
                    // App instalada no ecra principal (standalone) num iPhone/iPad: o iOS mostra o
                    // .mobileconfig como texto e o Safari nao tem a sessao da app. O botao passa a ser
                    // o link de uso unico (calendario/profile-download.php), aberto no Safari.
                    var ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
                    var standalone = window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
                    var link = document.getElementById('caldav-ios-link');
                    if (!ios || !standalone || !link) {
                        return;
                    }
                    document.getElementById('caldav-ios-button').classList.add('d-none');
                    link.classList.remove('d-none');
                    document.getElementById('caldav-standalone-note').classList.remove('d-none');
                    var manualButton = document.getElementById('caldav-manual-button');
                    if (manualButton) { manualButton.innerHTML = '<i class="fa fa-key"></i> Configurar manualmente'; }
                    var nameInput = document.getElementById('caldav-name');
                    var refresh = function () {
                        var name = nameInput ? nameInput.value.trim() : '';
                        link.href = link.getAttribute('data-base') + (name !== '' ? '?n=' + encodeURIComponent(name) : '');
                    };
                    if (nameInput) { nameInput.addEventListener('input', refresh); }
                    // Depois de usado, o link deixa de servir: recarregar a pagina gera outro.
                    link.addEventListener('click', function () {
                        window.setTimeout(function () { window.location.reload(); }, 4000);
                    });
                })();
                </script>

                <details class="small text-muted mb-3">
                    <summary>Como instalar</summary>
                    <ul class="mt-2 mb-0 ps-3">
                        <li><strong>iPhone / iPad:</strong> abra esta página no Safari do próprio aparelho e toque em "Instalar no iPhone / iPad / Mac".
                            Depois vá a Definições &gt; Geral &gt; VPN e gestão de dispositivos, toque no perfil descarregado e em Instalar.
                            Os calendários "<?= $caldavEsc($caldavAppName); ?> · Eventos" e "<?= $caldavEsc($caldavAppName); ?> · Convites" aparecem na app Calendário.
                            Na app instalada no ecrã principal o perfil não instala: use o Safari, ou "Configurar manualmente"
                            (Definições &gt; Calendário &gt; Contas &gt; Adicionar conta &gt; Outra &gt; Adicionar conta CalDAV, com o servidor, o utilizador e a password indicados).</li>
                        <li><strong>Android:</strong> instale a app DAVx⁵, toque em "Android / outro" e, no DAVx⁵, use "Iniciar sessão com URL e nome de utilizador"
                            com o servidor, o utilizador e a password indicados.</li>
                        <li>Os eventos criados no telemóvel vão para "<?= $caldavEsc($caldavAppName); ?> · Eventos". Os convidados e o cliente escolhem-se no dashboard. Eventos repetidos não são aceites.</li>
                    </ul>
                </details>

                <?php if (!$caldavAccesses): ?>
                    <p class="text-muted small mb-0">Nenhum dispositivo com acesso.</p>
                <?php else: ?>
                <ul class="list-group">
                    <?php foreach ($caldavAccesses as $caldavAccess): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div class="small">
                            <div class="fw-semibold"><?= $caldavEsc($caldavAccess['name']); ?></div>
                            <div class="text-muted">
                                Criado em <?= $caldavEsc(date('d/m/Y H:i', strtotime((string) $caldavAccess['created_at']) ?: time())); ?> ·
                                <?= $caldavAccess['last_used_at'] !== null ? 'último uso ' . $caldavEsc(date('d/m/Y H:i', strtotime((string) $caldavAccess['last_used_at']) ?: time())) : 'ainda não usado'; ?>
                            </div>
                        </div>
                        <form method="post" action="<?= $caldavEsc($caldavHandler); ?>" class="mb-0" onsubmit="return confirm('O dispositivo deixa de sincronizar o calendário. Revogar?');">
                            <input type="hidden" name="action" value="caldav_revoke">
                            <input type="hidden" name="csrf_token" value="<?= $caldavEsc($caldavCsrf); ?>">
                            <input type="hidden" name="access_id" value="<?= (int) $caldavAccess['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger mb-0">Revogar</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
