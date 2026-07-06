<?php
/**
 * Common footer for the CMS using the Gentelella admin template.
 * This file closes the HTML structure started in header.php and
 * includes necessary JavaScript files. Include this at the end
 * of every page that uses header.php.
 */
?>
        </div> <!-- /right_col -->
    </div> <!-- /main_container -->
</div> <!-- /container body -->

<?php if (!($hideOcrModal ?? false)): ?>
<!-- OCR Review Modal -->
<div class="modal fade" id="ocrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Revisar OCR</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Texto</label>
                    <textarea class="form-control" name="ocr_text" rows="5"></textarea>
                </div>
                <div class="analysis-fields"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="analysisCancel" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="analysisConfirm">Confirmar</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php $showAiFloating = ($aiEnabled ?? false) && ($aiChatFloating ?? false) && !($disableAiFloating ?? false) && function_exists('userHasDepartmentPermission') && userHasDepartmentPermission('ai_assistant'); ?>
<?php if ($showAiFloating): ?>
<button type="button" class="btn btn-primary" id="ai-float-btn" data-bs-toggle="modal" data-bs-target="#aiAssistModal" style="position: fixed; right: 24px; bottom: 24px; z-index: 1050; border-radius: 999px; padding: 10px 14px;">
    <i class="fa fa-comments"></i>
</button>
<div class="modal fade" id="aiAssistModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assistente AI</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body p-0" style="height: 70vh;">
                <?php
                    $aiPageContextParam = '';
                    if (!empty($aiPageContext) && is_array($aiPageContext)) {
                        $aiPageContextParam = '&page_context=' . rawurlencode(json_encode($aiPageContext, JSON_UNESCAPED_UNICODE));
                    }
                ?>
                <iframe src="<?= BASE_URL ?>assistant?embed=1<?= $aiPageContextParam ?>" style="width: 100%; height: 100%; border: 0;"></iframe>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (function_exists('isInternalChatEnabled') && isInternalChatEnabled() && function_exists('hasInternalChatTables') && hasInternalChatTables() && !($disableInternalChatFloating ?? false)): ?>
<?php $isInternalChatPage = strpos($_SERVER['REQUEST_URI'] ?? '', 'chat-interno') !== false; ?>
<?php if (!$isInternalChatPage): ?>
<div class="modal fade" id="internalChatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl internal-chat-modal-dialog">
        <div class="modal-content internal-chat-window">
            <div class="modal-header internal-chat-window-header">
                <h5 class="modal-title"><i class="fa fa-comments-o"></i> Chat Interno</h5>
                <div class="internal-chat-window-actions">
                    <button type="button" class="btn btn-link internal-chat-window-btn" id="internalChatMinimizeBtn" aria-label="Minimizar" title="Minimizar">
                        <i class="fa fa-window-minimize"></i>
                    </button>
                    <button type="button" class="btn btn-link internal-chat-window-btn" data-bs-dismiss="modal" aria-label="Fechar" title="Fechar">
                        <i class="fa fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body p-0 internal-chat-modal-body">
                <iframe id="internalChatFrame" src="<?= BASE_URL ?>chat-interno?embed=1" style="width: 100%; height: 100%; border: 0;"></iframe>
            </div>
        </div>
    </div>
</div>
<button type="button" class="btn btn-primary internal-chat-dock" id="internalChatDock" style="display:none;">
    <i class="fa fa-comments-o"></i> Chat Interno
</button>
<style>
.internal-chat-window {
    border: 1px solid #d7e2ee;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 28px 70px rgba(17, 31, 44, 0.28);
}
.internal-chat-window-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 16px;
    border-bottom: 1px solid #dde7f1;
    background: linear-gradient(180deg, #f9fbfe 0%, #f2f6fb 100%);
    cursor: move;
    user-select: none;
}
.internal-chat-window-header .modal-title {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 16px;
    font-weight: 700;
    color: #33475b;
    margin: 0;
}
.internal-chat-window-actions {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.internal-chat-window-btn {
    color: #607790;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
}
.internal-chat-window-btn:hover,
.internal-chat-window-btn:focus {
    background: #eaf1f8;
    color: #223548;
    text-decoration: none;
}
.internal-chat-modal-dialog {
    position: fixed;
    top: 6vh;
    left: 50%;
    margin: 0;
    transform: translateX(-50%);
    max-width: min(1100px, calc(100vw - 32px));
    width: min(1100px, calc(100vw - 32px));
}
.internal-chat-modal-body {
    height: 75vh;
    background: #f4f7fb;
}
.internal-chat-dock {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 1080;
    border-radius: 999px;
    padding: 10px 16px;
    box-shadow: 0 16px 32px rgba(17, 31, 44, 0.24);
}
@media (max-width: 991px) {
    .internal-chat-modal-dialog {
        position: fixed;
        top: 12px;
        left: 8px;
        right: 8px;
        width: auto;
        max-width: calc(100vw - 16px);
        transform: none !important;
    }
    .internal-chat-modal-body {
        height: calc(100vh - 110px);
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalElement = document.getElementById('internalChatModal');
    var dialogElement = modalElement ? modalElement.querySelector('.internal-chat-modal-dialog') : null;
    var headerElement = modalElement ? modalElement.querySelector('.internal-chat-window-header') : null;
    var minimizeButton = document.getElementById('internalChatMinimizeBtn');
    var dockButton = document.getElementById('internalChatDock');

    if (!modalElement || !dialogElement || !headerElement || !minimizeButton || !dockButton || typeof bootstrap === 'undefined') {
        return;
    }

    var chatModal = bootstrap.Modal.getOrCreateInstance(modalElement);
    var dragState = {
        active: false,
        startX: 0,
        startY: 0,
        left: 0,
        top: 0
    };
    var hasCustomPosition = false;

    function resetDialogPosition() {
        if (window.innerWidth <= 991) {
            dialogElement.style.left = '8px';
            dialogElement.style.top = '12px';
            dialogElement.style.transform = 'none';
            hasCustomPosition = false;
            return;
        }
        if (hasCustomPosition) {
            return;
        }
        dialogElement.style.left = '50%';
        dialogElement.style.top = '6vh';
        dialogElement.style.transform = 'translateX(-50%)';
    }

    function clampDialogPosition(left, top) {
        var maxLeft = Math.max(8, window.innerWidth - dialogElement.offsetWidth - 8);
        var maxTop = Math.max(8, window.innerHeight - dialogElement.offsetHeight - 8);
        return {
            left: Math.min(Math.max(8, left), maxLeft),
            top: Math.min(Math.max(8, top), maxTop)
        };
    }

    function startDrag(event) {
        if (window.innerWidth <= 991) {
            return;
        }
        if (event.target.closest('.internal-chat-window-actions')) {
            return;
        }

        var rect = dialogElement.getBoundingClientRect();
        dragState.active = true;
        dragState.startX = event.clientX;
        dragState.startY = event.clientY;
        dragState.left = rect.left;
        dragState.top = rect.top;
        dialogElement.style.transform = 'none';
        dialogElement.style.left = rect.left + 'px';
        dialogElement.style.top = rect.top + 'px';
        hasCustomPosition = true;
        document.body.classList.add('internal-chat-dragging');
    }

    function onDrag(event) {
        if (!dragState.active) {
            return;
        }
        var nextLeft = dragState.left + (event.clientX - dragState.startX);
        var nextTop = dragState.top + (event.clientY - dragState.startY);
        var clamped = clampDialogPosition(nextLeft, nextTop);
        dialogElement.style.left = clamped.left + 'px';
        dialogElement.style.top = clamped.top + 'px';
    }

    function stopDrag() {
        dragState.active = false;
        document.body.classList.remove('internal-chat-dragging');
    }

    headerElement.addEventListener('mousedown', startDrag);
    window.addEventListener('mousemove', onDrag);
    window.addEventListener('mouseup', stopDrag);
    window.addEventListener('resize', function () {
        if (!dragState.active) {
            resetDialogPosition();
        }
    });

    modalElement.addEventListener('shown.bs.modal', function () {
        dockButton.style.display = 'none';
        resetDialogPosition();
    });

    minimizeButton.addEventListener('click', function () {
        dockButton.style.display = 'inline-flex';
        chatModal.hide();
    });

    dockButton.addEventListener('click', function () {
        dockButton.style.display = 'none';
        chatModal.show();
    });
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php
$accountingUploadScriptVersion = @filemtime(__DIR__ . '/assets/js/accounting_upload.js');
$mobileCaptureScriptVersion = @filemtime(__DIR__ . '/assets/js/mobile_capture.js');
?>
<script src="vendors/jquery/dist/jquery.min.js"></script>
<script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($useDataTables): ?>
<script src="vendors/datatables.net/js/dataTables.min.js"></script>
<script src="vendors/datatables.net-bs5/js/dataTables.bootstrap5.min.js"></script>
<script src="vendors/datatables.net-responsive/js/dataTables.responsive.min.js"></script>
<script src="vendors/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js"></script>
<?php endif; ?>

<?php if ($useDropzone): ?>
<script src="vendors/dropzone/dist/dropzone-min.js"></script>
<?php if (!empty($dropzoneScript)): ?>
<script src="assets/js/<?= htmlspecialchars($dropzoneScript); ?>"></script>
<?php else: ?>
<script src="assets/js/accounting_upload.js<?= $accountingUploadScriptVersion ? '?v=' . rawurlencode((string) $accountingUploadScriptVersion) : ''; ?>"></script>
<script src="assets/js/mobile_capture.js<?= $mobileCaptureScriptVersion ? '?v=' . rawurlencode((string) $mobileCaptureScriptVersion) : ''; ?>"></script>

<?php endif; ?>
<?php endif; ?>

<?php if ($useSelect2): ?>
<script src="vendors/select2/dist/js/select2.full.min.js"></script>
<?php endif; ?>
<?php if ($useSwitchery): ?>
<script src="vendors/switchery/standalone/switchery.js"></script>
<script>
    if (typeof window.Switchery === 'undefined' && typeof window.require === 'function') {
        window.Switchery = window.require('switchery');
    }
</script>
<?php endif; ?>
<?php if ($useDateRangePicker): ?>
<script src="vendors/moment/min/moment.min.js"></script>
<script src="vendors/bootstrap-daterangepicker/daterangepicker.js"></script>
<?php endif; ?>

<?php if (function_exists('isInternalChatEnabled') && isInternalChatEnabled() && function_exists('hasInternalChatTables') && hasInternalChatTables()): ?>
<script>
window.internalChatGlobalConfig = {
    enabled: true,
    embedded: false,
    userId: <?= (int) (($user['id'] ?? 0)); ?>,
    summaryUrl: <?= json_encode(BASE_URL . 'chat-interno-handler?action=summary', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    heartbeatUrl: <?= json_encode(BASE_URL . 'chat-interno-handler', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    chatUrl: <?= json_encode(BASE_URL . 'chat-interno', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    serviceWorkerUrl: <?= json_encode(BASE_URL . 'internal-chat-sw.js', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    csrfToken: <?= json_encode(generateCsrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    appName: <?= json_encode((string) ($appName ?? 'CMS'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
};
</script>
<?php endif; ?>

<script src="assets/js/pnotify_theme_adapter.js"></script>
<script src="assets/js/custom.js"></script>
<?php if (!empty($pageScripts)): ?>
<script>
<?= $pageScripts ?>
</script>
<?php endif; ?>

</body>
</html>
