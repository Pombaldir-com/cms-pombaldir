<?php
// Modal de registo de ponto + configuracao do assets/js/assiduidade-ponto.js.
// Incluido pelo footer.php quando o modulo Assiduidade esta ativo e o
// utilizador regista ponto ($attendanceTopbar definido no header.php).
?>
<div class="modal fade" id="attendancePunchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="attendancePunchForm">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-clock-o"></i> Registo de Ponto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <div class="attendance-clock" id="attendancePunchTime">--:--</div>
                    <div class="text-muted small" id="attendancePunchSchedule"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="attendancePunchPeriod">Período</label>
                    <select class="form-select" name="period" id="attendancePunchPeriod"></select>
                </div>
                <div class="alert alert-warning" id="attendancePunchLate" style="display:none;">
                    <i class="fa fa-exclamation-triangle"></i> <span id="attendancePunchLateText"></span>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="attendancePunchJustification">Observações / motivo</label>
                    <textarea class="form-control" name="justification" id="attendancePunchJustification" rows="2" maxlength="1000"></textarea>
                </div>
                <div class="alert alert-danger mt-3 mb-0" id="attendancePunchError" style="display:none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-check"></i> Registar</button>
            </div>
        </form>
    </div>
</div>
<script>
window.attendanceConfig = <?= json_encode([
    'handlerUrl' => BASE_URL . 'assiduidade/handler',
    'csrfToken' => generateCsrfToken(),
    'periods' => ATTENDANCE_PERIODS,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>;
</script>
<script src="assets/js/assiduidade-ponto.js?v=<?= (int) @filemtime(dirname(__DIR__, 2) . '/assets/js/assiduidade-ponto.js'); ?>"></script>
