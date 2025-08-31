<?php
/**
 * Página de definições do utilizador.
 */
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();
 
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appName = trim($_POST['app_name'] ?? '');
    setSetting('app_name', $appName);
    $saved = true;
}
$currentAppName = getSetting('app_name', '');

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <h2>Definições</h2>

    <ul class="nav nav-tabs bar_tabs right" id="settings-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="geral-tab" data-bs-toggle="tab" href="#geral" role="tab" aria-controls="geral" aria-selected="true">Geral</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="email-tab" data-bs-toggle="tab" href="#email" role="tab" aria-controls="email" aria-selected="false">E-mail</a>
        </li>
    </ul>

    <div class="tab-content" id="settings-tabContent">
        <div class="tab-pane fade show active" id="geral" role="tabpanel" aria-labelledby="geral-tab">
            <?php if ($saved): ?>
                <div class="alert alert-success mt-3">Definições guardadas.</div>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <div class="mb-3 col-md-6 col-sm-12">
                    <label for="app_name" class="form-label">APP Nome</label>
                    <input type="text" class="form-control" id="app_name" name="app_name" value="<?= htmlspecialchars($currentAppName); ?>">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Guardar</button>
            </form>
        </div>
        <div class="tab-pane fade" id="email" role="tabpanel" aria-labelledby="email-tab">
            <!-- Conteúdo E-mail -->
        </div>
    </div>
</div>
<?php
require_once __DIR__ . '/footer.php';
