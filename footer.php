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

<?php if (($aiEnabled ?? false) && ($aiChatFloating ?? false) && !($disableAiFloating ?? false) && function_exists('userHasDepartmentPermission') && userHasDepartmentPermission('ai_assistant')): ?>
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
                <iframe src="<?= BASE_URL ?>assistant?embed=1" style="width: 100%; height: 100%; border: 0;"></iframe>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

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
<script src="assets/js/accounting_upload.js"></script>
<script src="assets/js/mobile_capture.js"></script>

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

<script src="assets/js/pnotify_theme_adapter.js"></script>
<script src="assets/js/custom.js"></script>
<?php if (!empty($pageScripts)): ?>
<script>
<?= $pageScripts ?>
</script>
<?php endif; ?>

</body>
</html>
