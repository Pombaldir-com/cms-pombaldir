<?php
/**
 * Página de definições do utilizador.
 */
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();
requireRole(2);
$user = currentUser();
$csrfToken = generateCsrfToken();

$contentTypes = getContentTypes();
$availableModules = [
    'contabilidade' => 'Contabilidade',
    'compras' => 'Compras',
];

$generalSaved = false;
$emailSaved = false;
$erpSaved = false;
$modulesSaved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }
    $csrfToken = generateCsrfToken();
    if (isset($_POST['app_name'])) {
        $appName = trim($_POST['app_name'] ?? '');
        setSetting('app_name', $appName);

        $apiEnabled = isset($_POST['api_enabled']) ? '1' : '0';
        setSetting('api_enabled', $apiEnabled);
        $apiToken = trim($_POST['api_token'] ?? '');
        if ($apiEnabled) {
            if ($apiToken === '') {
                $apiToken = bin2hex(random_bytes(20));
            }
            setSetting('api_token', $apiToken);
            foreach ($contentTypes as $type) {
                $enabled = isset($_POST['api_content'][$type['id']]);
                setContentTypeApi((int)$type['id'], $enabled);
            }
        } else {
            setSetting('api_token', '');
            foreach ($contentTypes as $type) {
                setContentTypeApi((int)$type['id'], false);
            }
        }

        setSetting('accounting_enabled', isset($_POST['accounting_enabled']) ? '1' : '0');

        $generalSaved = true;
    }
    if (isset($_POST['smtp_host'])) {
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = trim($_POST['smtp_port'] ?? '');
        $smtpUser = trim($_POST['smtp_user'] ?? '');
        $smtpPass = trim($_POST['smtp_pass'] ?? '');
        $smtpEncryption = trim($_POST['smtp_encryption'] ?? '');
        setSetting('smtp_host', $smtpHost);
        setSetting('smtp_port', $smtpPort);
        setSetting('smtp_user', $smtpUser);
        setSetting('smtp_pass', $smtpPass);
        setSetting('smtp_encryption', $smtpEncryption);

        $awsKey = trim($_POST['aws_access_key_id'] ?? '');
        $awsSecret = trim($_POST['aws_secret_access_key'] ?? '');
        $awsRegion = trim($_POST['aws_region'] ?? '');
        $ocrProvider = trim($_POST['ocr_provider'] ?? 'tesseract');
        setSetting('aws_access_key_id', $awsKey);
        setSetting('aws_secret_access_key', $awsSecret);
        setSetting('aws_region', $awsRegion);
        setSetting('ocr_provider', $ocrProvider);

        $emailSaved = true;
    }
    if (isset($_POST['erp_webservice_url'])) {
        $erpWebserviceUrl = trim($_POST['erp_webservice_url'] ?? '');
        $erpToken = trim($_POST['erp_token'] ?? '');
        $erpNifPt = trim($_POST['erp_nif_pt'] ?? '');

        setSetting('erp_webservice_url', $erpWebserviceUrl);
        setSetting('erp_token', $erpToken);
        setSetting('erp_nif_pt', $erpNifPt);

        $erpSaved = true;
    }
    if (isset($_POST['modules_save']) && ($user['role'] ?? 3) == 1) {
        $selectedModules = array_keys($_POST['modules'] ?? []);
        setSetting('active_modules', json_encode($selectedModules));
        $modulesSaved = true;
    }
}
$currentAppName = getSetting('app_name', '');
$currentApiEnabled = (int)getSetting('api_enabled', '0');
$currentApiToken = getSetting('api_token', '');
$currentAccountingEnabled = (int)getSetting('accounting_enabled', '0');
$contentTypes = getContentTypes();
$contentTypeApi = [];
foreach ($contentTypes as $type) {
    $contentTypeApi[$type['id']] = (int)($type['api_enabled'] ?? 0);
}
$currentSmtpHost = getSetting('smtp_host', '');
$currentSmtpPort = getSetting('smtp_port', '');
$currentSmtpUser = getSetting('smtp_user', '');
$currentSmtpPass = getSetting('smtp_pass', '');
$currentSmtpEncryption = getSetting('smtp_encryption', '');
$currentAwsAccessKeyId = getSetting('aws_access_key_id', '');
$currentAwsSecretAccessKey = getSetting('aws_secret_access_key', '');
$currentAwsRegion = getSetting('aws_region', '');
$currentOcrProvider = getSetting('ocr_provider', 'tesseract');
$currentErpWebserviceUrl = getSetting('erp_webservice_url', '');
$currentErpToken = getSetting('erp_token', '');
$currentErpNifPt = getSetting('erp_nif_pt', '');
$currentModules = getActiveModules();

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
        <li class="nav-item">
            <a class="nav-link" id="erp-tab" data-bs-toggle="tab" href="#erp" role="tab" aria-controls="erp" aria-selected="false">ERP</a>
        </li>
        <?php if (($user['role'] ?? 3) == 1): ?>
        <li class="nav-item">
            <a class="nav-link" id="modules-tab" data-bs-toggle="tab" href="#modules" role="tab" aria-controls="modules" aria-selected="false">Módulos</a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content" id="settings-tabContent">
        <div class="tab-pane fade show active" id="geral" role="tabpanel" aria-labelledby="geral-tab">
            <?php if ($generalSaved): ?>
                <div class="alert alert-success mt-3">Definições guardadas.</div>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                <div class="row">
                    <div class="mb-3 col-md-6 col-sm-12">
                        <label for="app_name" class="form-label">Nome da APP</label>
                        <input type="text" class="form-control" id="app_name" name="app_name" value="<?= htmlspecialchars($currentAppName); ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="mb-3 col-md-6 col-sm-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="api_enabled" name="api_enabled" value="1" <?= $currentApiEnabled ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="api_enabled">Ativar API</label>
                        </div>
                    </div>
                </div>
                <div id="api-settings" style="<?= $currentApiEnabled ? '' : 'display:none;'; ?>">
                    <div class="row">
                        <div class="mb-3 col-md-6 col-sm-12">
                            <label for="api_token" class="form-label">Token</label>

                            <div class="input-group">
                                <input type="text" class="form-control" id="api_token" name="api_token" value="<?= htmlspecialchars($currentApiToken); ?>">
                                <button class="btn btn-outline-secondary" type="button" id="generate_token">Gerar</button>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="mb-3 col-md-6 col-sm-12">
                            <label class="form-label">Endpoints</label>
                            <?php foreach ($contentTypes as $type): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="api_content_<?= $type['id']; ?>" name="api_content[<?= $type['id']; ?>]" value="1" <?= !empty($contentTypeApi[$type['id']]) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="api_content_<?= $type['id']; ?>"><?= htmlspecialchars($type['label']); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
 
                <div class="row">
                    <div class="mb-3 col-md-6 col-sm-12">
                        <button type="submit" class="btn btn-md btn-primary"><i class="fa fa-save"></i> Guardar</button>
                    </div>
                </div>
                
            </form>
        </div>
        <div class="tab-pane fade" id="email" role="tabpanel" aria-labelledby="email-tab">
            <?php if ($emailSaved): ?>
                <div class="alert alert-success mt-3">Definições de e-mail guardadas.</div>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                <div class="row">
                <div class="mb-3 col-md-3 col-sm-12">
                    <label for="smtp_host" class="form-label">Servidor SMTP</label>
                    <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?= htmlspecialchars($currentSmtpHost); ?>">
                </div>
                <div class="mb-3 col-md-1 col-sm-12">
                    <label for="smtp_port" class="form-label">Porta SMTP</label>
                    <input type="text" class="form-control" id="smtp_port" name="smtp_port" value="<?= htmlspecialchars($currentSmtpPort); ?>">
                </div>
                <div class="mb-3 col-md-4 col-sm-12">
                    <label for="smtp_user" class="form-label">Utilizador SMTP</label>
                    <input type="text" class="form-control" id="smtp_user" name="smtp_user" value="<?= htmlspecialchars($currentSmtpUser); ?>">
                </div>
                <div class="mb-3 col-md-3 col-sm-12">
                    <label for="smtp_pass" class="form-label">Senha SMTP</label>
                    <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" value="<?= htmlspecialchars($currentSmtpPass); ?>">
                </div>
                <div class="mb-3 col-md-1 col-sm-12">
                    <label for="smtp_encryption" class="form-label">Encriptação</label>
                    <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                        <option value="" <?= $currentSmtpEncryption === '' ? 'selected' : ''; ?>>Nenhuma</option>
                        <option value="ssl" <?= $currentSmtpEncryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        <option value="tls" <?= $currentSmtpEncryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
                    </select>
                </div>
                </div>

                <div class="row">
                <div class="mb-3 col-md-3 col-sm-12">
                    <label for="ocr_provider" class="form-label">OCR</label>
                    <select class="form-select" id="ocr_provider" name="ocr_provider">
                        <option value="tesseract" <?= $currentOcrProvider === 'tesseract' ? 'selected' : ''; ?>>Tesseract</option>
                        <option value="textract" <?= $currentOcrProvider === 'textract' ? 'selected' : ''; ?>>AWS Textract</option>
                    </select>
                </div>
                <div class="mb-3 col-md-3 col-sm-12">
                    <label for="aws_access_key_id" class="form-label">AWS Access Key ID</label>
                    <input type="text" class="form-control" id="aws_access_key_id" name="aws_access_key_id" value="<?= htmlspecialchars($currentAwsAccessKeyId); ?>">
                </div>
                <div class="mb-3 col-md-3 col-sm-12">
                    <label for="aws_secret_access_key" class="form-label">AWS Secret Access Key</label>
                    <input type="password" class="form-control" id="aws_secret_access_key" name="aws_secret_access_key" value="<?= htmlspecialchars($currentAwsSecretAccessKey); ?>">
                </div>
                <div class="mb-3 col-md-3 col-sm-12">
                    <label for="aws_region" class="form-label">AWS Region</label>
                    <input type="text" class="form-control" id="aws_region" name="aws_region" value="<?= htmlspecialchars($currentAwsRegion); ?>">
                </div>
                </div>

                <button type="submit" class="btn btn-md btn-primary"><i class="fa fa-save"></i> Guardar</button>
            </form>
        </div>
        <div class="tab-pane fade" id="erp" role="tabpanel" aria-labelledby="erp-tab">
            <?php if ($erpSaved): ?>
                <div class="alert alert-success mt-3">Definições do ERP guardadas.</div>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                <div class="row">
                    <div class="mb-3 col-md-6 col-sm-12">
                        <label for="erp_webservice_url" class="form-label">Url Webservice</label>
                        <input type="text" class="form-control" id="erp_webservice_url" name="erp_webservice_url" value="<?= htmlspecialchars($currentErpWebserviceUrl); ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="mb-3 col-md-6 col-sm-12">
                        <label for="erp_token" class="form-label">Token</label>
                        <input type="text" class="form-control" id="erp_token" name="erp_token" value="<?= htmlspecialchars($currentErpToken); ?>">
                    </div>
                </div>
                <div class="row">
                    <div class="mb-3 col-md-6 col-sm-12">
                        <label for="erp_nif_pt" class="form-label">Token NIF.PT</label>
                        <input type="text" class="form-control" id="erp_nif_pt" name="erp_nif_pt" value="<?= htmlspecialchars($currentErpNifPt); ?>">
                    </div>
                </div>
                <button type="submit" class="btn btn-md btn-primary"><i class="fa fa-save"></i> Guardar</button>
            </form>
        </div>
        <?php if (($user['role'] ?? 3) == 1): ?>
        <div class="tab-pane fade" id="modules" role="tabpanel" aria-labelledby="modules-tab">
            <?php if ($modulesSaved): ?>
                <div class="alert alert-success mt-3">Módulos guardados.</div>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="modules_save" value="1">
                <div class="row">
                    <div class="mb-3 col-md-6 col-sm-12">
                        <?php foreach ($availableModules as $key => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="module_<?= $key; ?>" name="modules[<?= $key; ?>]" value="1" <?= in_array($key, $currentModules, true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="module_<?= $key; ?>"><?= htmlspecialchars($label); ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="submit" class="btn btn-md btn-primary"><i class="fa fa-save"></i> Guardar</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
require_once __DIR__ . '/footer.php';

