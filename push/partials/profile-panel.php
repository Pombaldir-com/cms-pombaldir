<?php
// Perfil (users.php?profile): "Notificacoes neste dispositivo" (Web Push).
// O interruptor e o botao de teste sao tratados por assets/js/push.js.
// Ver push/functions.php e docs/calendario.md, "Notificacoes push".

require_once dirname(__DIR__) . '/functions.php';

$pushPanelReady = pushHasTable() && pushSupported();
$pushPanelDevices = $pushPanelReady ? pushDeviceCount(getPDO(), (int) ($_SESSION['user_id'] ?? 0)) : 0;
$pushPanelAppName = trim((string) getSetting('app_name', '')) ?: 'a app';
$pushPanelEsc = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="row" id="notificacoes">
    <div class="col-md-8 col-sm-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-bell"></i> Notificações neste dispositivo</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?php if (!$pushPanelReady): ?>
                    <p class="text-muted mb-0">Execute as migrações pendentes para ativar as notificações push.</p>
                <?php else: ?>
                <div class="d-flex align-items-start gap-3">
                    <div class="form-check form-switch mb-0 pt-1">
                        <input class="form-check-input" type="checkbox" role="switch" id="push-switch" disabled>
                        <label class="form-check-label visually-hidden" for="push-switch">Notificações push</label>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold" style="color:#2a3f54">Receber convites e lembretes do calendário</div>
                        <p class="small mb-0 text-muted" id="push-switch-status">A verificar…</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mb-0 d-none" id="push-test"><i class="fa fa-paper-plane-o"></i> Testar</button>
                </div>
                <details class="small text-muted mt-3">
                    <summary>Instalar no telemóvel</summary>
                    <ul class="mt-2 mb-0 ps-3">
                        <li><strong>iPhone / iPad</strong> (iOS 16.4 ou mais recente): abra <?= $pushPanelEsc($pushPanelAppName); ?> no Safari, entre com a sua conta,
                            toque em Partilhar → Adicionar ao ecrã principal. Abra pelo novo ícone, volte a esta página e ligue o interruptor.</li>
                        <li><strong>Android</strong>: no Chrome, menu ⋮ → Instalar app (ou Adicionar ao ecrã principal), e ligue o interruptor.</li>
                        <li>Cada dispositivo liga-se em separado. Dispositivos com notificações ligadas: <strong><?= (int) $pushPanelDevices; ?></strong>.</li>
                    </ul>
                </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
