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
$generalErrors = [];
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

        $debugMode = isset($_POST['debug_mode']) ? '1' : '0';
        setSetting('debug_mode', $debugMode);

        $qrDpi = (int) ($_POST['qr_dpi'] ?? 150);
        if ($qrDpi <= 0) {
            $qrDpi = 150;
        }
        setSetting('qr_dpi', (string) $qrDpi);

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

        if (!empty($_FILES['app_logo']['tmp_name'])) {
            $fileTmp  = $_FILES['app_logo']['tmp_name'];
            $fileSize = $_FILES['app_logo']['size'] ?? 0;
            $extension = strtolower(pathinfo($_FILES['app_logo']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['png', 'jpg', 'jpeg'];

            if ($fileSize > 2 * 1024 * 1024) {
                $generalErrors[] = 'O logotipo excede 2 MB.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($fileTmp);
                $allowedMime = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

                if (!in_array($extension, $allowedExt, true) || !array_key_exists($mimeType, $allowedMime)) {
                    $generalErrors[] = 'Formato de logotipo invalido.';
                } else {
                    $slug = getCompanySlug() ?: 'default';
                    $year = date('Y');
                    $month = date('m');
                    $uploadDir = __DIR__ . '/uploads/' . $slug . '/branding/' . $year . '/' . $month . '/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $filename = 'logo_' . bin2hex(random_bytes(12)) . '.' . $allowedMime[$mimeType];
                    $targetPath = $uploadDir . $filename;

                    $image = ($mimeType === 'image/png') ? imagecreatefrompng($fileTmp) : imagecreatefromjpeg($fileTmp);
                    if ($image !== false) {
                        $maxDim = 800;
                        $width = imagesx($image);
                        $height = imagesy($image);
                        if ($width > $maxDim || $height > $maxDim) {
                            $ratio = min($maxDim / $width, $maxDim / $height);
                            $newWidth = (int) ($width * $ratio);
                            $newHeight = (int) ($height * $ratio);
                            $newImage = imagecreatetruecolor($newWidth, $newHeight);
                            if ($mimeType === 'image/png') {
                                imagealphablending($newImage, false);
                                imagesavealpha($newImage, true);
                            }
                            imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                            imagedestroy($image);
                            $image = $newImage;
                        }
                        $saved = ($mimeType === 'image/png') ? imagepng($image, $targetPath) : imagejpeg($image, $targetPath, 90);
                        imagedestroy($image);
                    } else {
                        $saved = false;
                    }

                    if ($saved) {
                        $logoPath = 'uploads/' . $slug . '/branding/' . $year . '/' . $month . '/' . $filename;
                        setSetting('app_logo', $logoPath);
                    } else {
                        $generalErrors[] = 'Erro ao guardar o logotipo.';
                    }
                }
            }
        }

        $generalSaved = empty($generalErrors);
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

        setSetting('erp_webservice_url', $erpWebserviceUrl);
        setSetting('erp_token', $erpToken);

        $erpSaved = true;
    }
    if (isset($_POST['modules_save']) && ($user['role'] ?? 3) == 1) {
        $selectedModules = array_keys($_POST['modules'] ?? []);
        setSetting('active_modules', json_encode($selectedModules));

        $accountingEnabled = in_array('contabilidade', $selectedModules, true);
        setSetting('accounting_enabled', $accountingEnabled ? '1' : '0');

        $accountingBaseCompany = trim($_POST['accounting_base_company'] ?? '');
        $accountingDiary = trim($_POST['accounting_diary'] ?? '');
        $accountingPostingDateMode = trim((string) ($_POST['accounting_posting_date_mode'] ?? 'document'));
        if (!in_array($accountingPostingDateMode, ['document', 'month_end'], true)) {
            $accountingPostingDateMode = 'document';
        }
        if ($accountingEnabled) {
            setSetting('accounting_base_company', $accountingBaseCompany);
            setSetting('accounting_diary', $accountingDiary);
            setSetting('accounting_posting_date_mode', $accountingPostingDateMode);
        } else {
            setSetting('accounting_base_company', '');
            setSetting('accounting_diary', '');
            setSetting('accounting_posting_date_mode', 'document');
        }

        if (in_array('compras', $selectedModules, true)) {
            $comprasSection = trim($_POST['compras_section'] ?? '');
            $comprasWarehouse = trim($_POST['compras_warehouse'] ?? '');
            $comprasDocumentType = trim($_POST['compras_document_type'] ?? '');

            setSetting('compras_section', $comprasSection);
            setSetting('compras_warehouse', $comprasWarehouse);
            setSetting('compras_document_type', $comprasDocumentType);
        }

        $modulesSaved = true;
    }
}
$currentAppName = getSetting('app_name', '');
$currentAppLogo = getSetting('app_logo', '');
$currentDebugMode = (int)getSetting('debug_mode', '0');
$currentQrDpi = (int)getSetting('qr_dpi', '150');
$currentApiEnabled = (int)getSetting('api_enabled', '0');
$currentApiToken = getSetting('api_token', '');
$currentAccountingBaseCompany = getSetting('accounting_base_company', '');
$currentAccountingDiary = getSetting('accounting_diary', '');
$currentAccountingPostingDateMode = getSetting('accounting_posting_date_mode', 'document');
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
$currentModules = getActiveModules();
$currentComprasSection = getSetting('compras_section', '');
$currentComprasWarehouse = getSetting('compras_warehouse', '');
$currentComprasDocumentType = getSetting('compras_document_type', '');
require_once __DIR__ . '/header.php';
?>
<div class="container-fluid settings-page">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h2 class="mb-1">Definições</h2>
            <p class="mb-0 text-muted">Controle o comportamento do CMS, integrações e módulos ativos.</p>
        </div>
        <div>
            <span class="badge bg-light text-dark">Perfil: <?= htmlspecialchars($user['role'] ?? 3); ?></span>
            <?php if (($user['role'] ?? 3) == 1): ?>
            <span class="badge bg-warning text-dark">Superadmin</span>
            <?php endif; ?>
        </div>
    </div>

    <ul class="nav nav-tabs bar_tabs right settings-tabs" id="settings-tab" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="geral-tab" data-bs-toggle="tab" href="#geral" role="tab" aria-controls="geral" aria-selected="true"><i class="fa fa-sliders"></i> Geral</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="email-tab" data-bs-toggle="tab" href="#email" role="tab" aria-controls="email" aria-selected="false"><i class="fa fa-envelope"></i> Serviços</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="erp-tab" data-bs-toggle="tab" href="#erp" role="tab" aria-controls="erp" aria-selected="false"><i class="fa fa-exchange"></i> ERP</a>
        </li>
        <?php if (($user['role'] ?? 3) == 1): ?>
        <li class="nav-item">
            <a class="nav-link" id="modules-tab" data-bs-toggle="tab" href="#modules" role="tab" aria-controls="modules" aria-selected="false"><i class="fa fa-cubes"></i> Módulos</a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content settings-content" id="settings-tabContent">
        <div class="tab-pane fade show active" id="geral" role="tabpanel" aria-labelledby="geral-tab">
            <?php if ($generalSaved): ?>
                <div class="alert alert-success mt-3">Definições guardadas.</div>
            <?php endif; ?>
            <?php foreach ($generalErrors as $err): ?>
                <div class="alert alert-danger mt-3"><?= htmlspecialchars($err); ?></div>
            <?php endforeach; ?>
            <form method="post" class="mt-3" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                <div class="row g-4 settings-panels">
                    <div class="col-12 col-lg-6">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-cogs"></i> Aplicação</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="mb-3">
                                    <label for="app_name" class="form-label">Nome da app</label>
                                    <input type="text" class="form-control" id="app_name" name="app_name" value="<?= htmlspecialchars($currentAppName); ?>">
                                    <small class="text-muted">Aparece no cabeçalho e no título do browser.</small>
                                </div>
                                <div class="mb-3">
                                    <label for="app_logo" class="form-label">Logotipo</label>
                                    <?php if (!empty($currentAppLogo)): ?>
                                        <div class="mb-2">
                                            <img src="<?= htmlspecialchars($currentAppLogo); ?>" alt="" class="img-thumbnail" style="max-width: 160px;">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" class="form-control" id="app_logo" name="app_logo" accept="image/png,image/jpeg">
                                    <small class="text-muted">Usado como imagem predefinida para utilizadores sem foto.</small>
                                </div>
                                <div class="mb-3">
                                    <label for="qr_dpi" class="form-label">QR DPI</label>
                                    <input type="number" class="form-control" id="qr_dpi" name="qr_dpi" min="72" max="600" step="1" value="<?= htmlspecialchars((string) $currentQrDpi); ?>">
                                </div>
                                <div class="form-check form-switch setting-switch">
                                    <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode" value="1" <?= $currentDebugMode ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="debug_mode">Modo debug</label>
                                    <div class="text-muted small">Ativa diagnósticos extra para suporte.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-plug"></i> API pública</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="form-check form-switch setting-switch mb-3">
                                    <input class="form-check-input" type="checkbox" id="api_enabled" name="api_enabled" value="1" <?= $currentApiEnabled ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="api_enabled">Ativar API</label>
                                    <div class="text-muted small">Disponibiliza endpoints públicos com token.</div>
                                </div>
                                <div id="api-settings" class="api-settings-box" style="<?= $currentApiEnabled ? '' : 'display:none;'; ?>">
                                    <label for="api_token" class="form-label">Token</label>
                                    <div class="input-group mb-3">
                                        <input type="text" class="form-control" id="api_token" name="api_token" value="<?= htmlspecialchars($currentApiToken); ?>">
                                        <button class="btn btn-outline-secondary" type="button" id="generate_token"><i class="fa fa-random"></i> Gerar</button>
                                    </div>
                                    <div class="api-endpoints">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <span class="form-label mb-0">Endpoints</span>
                                            <span class="text-muted small"><?= count($contentTypes); ?> ativos</span>
                                        </div>
                                        <div class="row g-2">
                                            <?php foreach ($contentTypes as $type): ?>
                                            <div class="col-12 col-md-6">
                                                <div class="endpoint-tile">
                                                    <div class="form-check form-switch mb-0">
                                                        <input class="form-check-input" type="checkbox" id="api_content_<?= $type['id']; ?>" name="api_content[<?= $type['id']; ?>]" value="1" <?= !empty($contentTypeApi[$type['id']]) ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="api_content_<?= $type['id']; ?>"><?= htmlspecialchars($type['label']); ?></label>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-lg"><i class="fa fa-save"></i> Guardar definições</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="tab-pane fade" id="email" role="tabpanel" aria-labelledby="email-tab">
            <?php if ($emailSaved): ?>
                <div class="alert alert-success mt-3">Definições de serviços guardadas.</div>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                <div class="row g-4 settings-panels">
                    <div class="col-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-paper-plane"></i> SMTP</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="row g-3">
                                    <div class="col-12 col-lg-3">
                                        <label for="smtp_host" class="form-label">Servidor SMTP</label>
                                        <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="<?= htmlspecialchars($currentSmtpHost); ?>">
                                    </div>
                                    <div class="col-12 col-sm-6 col-lg-2">
                                        <label for="smtp_port" class="form-label">Porta</label>
                                        <input type="text" class="form-control" id="smtp_port" name="smtp_port" value="<?= htmlspecialchars($currentSmtpPort); ?>">
                                    </div>
                                    <div class="col-12 col-lg-3">
                                        <label for="smtp_user" class="form-label">Utilizador</label>
                                        <input type="text" class="form-control" id="smtp_user" name="smtp_user" value="<?= htmlspecialchars($currentSmtpUser); ?>">
                                    </div>
                                    <div class="col-12 col-lg-3">
                                        <label for="smtp_pass" class="form-label">Senha</label>
                                        <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" value="<?= htmlspecialchars($currentSmtpPass); ?>">
                                    </div>
                                    <div class="col-12 col-sm-6 col-lg-1">
                                        <label for="smtp_encryption" class="form-label">TLS/SSL</label>
                                        <select class="form-select" id="smtp_encryption" name="smtp_encryption">
                                            <option value="" <?= $currentSmtpEncryption === '' ? 'selected' : ''; ?>>-</option>
                                            <option value="ssl" <?= $currentSmtpEncryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                            <option value="tls" <?= $currentSmtpEncryption === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-eye"></i> OCR e AWS</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="row g-3">
                                    <div class="col-12 col-lg-3">
                                        <label for="ocr_provider" class="form-label">Fornecedor OCR</label>
                                        <select class="form-select" id="ocr_provider" name="ocr_provider">
                                            <option value="tesseract" <?= $currentOcrProvider === 'tesseract' ? 'selected' : ''; ?>>Tesseract</option>
                                            <option value="textract" <?= $currentOcrProvider === 'textract' ? 'selected' : ''; ?>>AWS Textract</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-lg-3">
                                        <label for="aws_access_key_id" class="form-label">AWS Access Key ID</label>
                                        <input type="text" class="form-control" id="aws_access_key_id" name="aws_access_key_id" value="<?= htmlspecialchars($currentAwsAccessKeyId); ?>">
                                    </div>
                                    <div class="col-12 col-lg-3">
                                        <label for="aws_secret_access_key" class="form-label">AWS Secret Access Key</label>
                                        <input type="password" class="form-control" id="aws_secret_access_key" name="aws_secret_access_key" value="<?= htmlspecialchars($currentAwsSecretAccessKey); ?>">
                                    </div>
                                    <div class="col-12 col-lg-3">
                                        <label for="aws_region" class="form-label">AWS Region</label>
                                        <input type="text" class="form-control" id="aws_region" name="aws_region" value="<?= htmlspecialchars($currentAwsRegion); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-lg"><i class="fa fa-save"></i> Guardar definições</button>
                    </div>
                </div>
            </form>
        </div>
        <div class="tab-pane fade" id="erp" role="tabpanel" aria-labelledby="erp-tab">
            <?php if ($erpSaved): ?>
                <div class="alert alert-success mt-3">Definições do ERP guardadas.</div>
            <?php endif; ?>
            <form method="post" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                <div class="row g-4 settings-panels">
                    <div class="col-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2><i class="fa fa-link"></i> Ligação ao ERP</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="row g-3">
                                    <div class="col-12 col-lg-8">
                                        <label for="erp_webservice_url" class="form-label">URL do webservice</label>
                                        <input type="text" class="form-control" id="erp_webservice_url" name="erp_webservice_url" value="<?= htmlspecialchars($currentErpWebserviceUrl); ?>">
                                    </div>
                                    <div class="col-12 col-lg-4">
                                        <label for="erp_token" class="form-label">Token</label>
                                        <input type="text" class="form-control" id="erp_token" name="erp_token" value="<?= htmlspecialchars($currentErpToken); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-lg"><i class="fa fa-save"></i> Guardar definições</button>
                    </div>
                </div>
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
                <div class="row g-4 settings-panels">
                    <?php $accountingActive = in_array('contabilidade', $currentModules, true); ?>
                    <div class="col-12 col-lg-6">
                        <div class="x_panel module-card">
                            <div class="x_title">
                                <h2><i class="fa fa-line-chart"></i> Contabilidade</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="module-toggle">
                                    <div>
                                        <p class="text-muted mb-1">Processamento e conciliação de documentos.</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="module_contabilidade" name="modules[contabilidade]" value="1" <?= $accountingActive ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="module_contabilidade">Ativo</label>
                                    </div>
                                </div>
                                <div id="contabilidade-settings" class="module-settings mt-3" style="<?= $accountingActive ? '' : 'display:none;'; ?>">
                                    <div class="row g-2 module-settings-row">
                                        <div class="col-12 col-sm-6">
                                            <label for="accounting_base_company" class="form-label">Empresa base</label>
                                            <input type="text" class="form-control input-compact" id="accounting_base_company" name="accounting_base_company" maxlength="8" value="<?= htmlspecialchars($currentAccountingBaseCompany); ?>" <?= $accountingActive ? '' : 'disabled'; ?>>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label for="accounting_diary" class="form-label">Diário</label>
                                            <input type="text" class="form-control input-compact" id="accounting_diary" name="accounting_diary" maxlength="10" value="<?= htmlspecialchars($currentAccountingDiary); ?>" <?= $accountingActive ? '' : 'disabled'; ?>>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label for="accounting_posting_date_mode" class="form-label">Data de lançamento</label>
                                            <select class="form-select" id="accounting_posting_date_mode" name="accounting_posting_date_mode" <?= $accountingActive ? '' : 'disabled'; ?>>
                                                <option value="document" <?= $currentAccountingPostingDateMode === 'document' ? 'selected' : ''; ?>>Data do documento</option>
                                                <option value="month_end" <?= $currentAccountingPostingDateMode === 'month_end' ? 'selected' : ''; ?>>Ultimo dia do mes</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php $comprasActive = in_array('compras', $currentModules, true); ?>
                    <div class="col-12 col-lg-6">
                        <div class="x_panel module-card">
                            <div class="x_title">
                                <h2><i class="fa fa-shopping-basket"></i> Compras</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="module-toggle">
                                    <div>
                                        <p class="text-muted mb-1">Importação e classificação de compras.</p>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="module_compras" name="modules[compras]" value="1" <?= $comprasActive ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="module_compras">Ativo</label>
                                    </div>
                                </div>
                                <div id="compras-settings" class="module-settings mt-3" style="<?= $comprasActive ? '' : 'display:none;'; ?>">
                                    <div class="row g-2 module-settings-row">
                                        <div class="col-12">
                                            <label for="compras_section" class="form-label">Secção</label>
                                            <input type="text" class="form-control" id="compras_section" name="compras_section" value="<?= htmlspecialchars($currentComprasSection); ?>" <?= $comprasActive ? '' : 'disabled'; ?>>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label for="compras_warehouse" class="form-label">Armazém</label>
                                            <input type="text" class="form-control" id="compras_warehouse" name="compras_warehouse" value="<?= htmlspecialchars($currentComprasWarehouse); ?>" <?= $comprasActive ? '' : 'disabled'; ?>>
                                        </div>
                                        <div class="col-12 col-sm-6">
                                            <label for="compras_document_type" class="form-label">Tipo de documento</label>
                                            <input type="text" class="form-control" id="compras_document_type" name="compras_document_type" value="<?= htmlspecialchars($currentComprasDocumentType); ?>" <?= $comprasActive ? '' : 'disabled'; ?>>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success btn-lg"><i class="fa fa-save"></i> Guardar módulos</button>
                    </div>
                </div>
            </form>
            <script>
            document.addEventListener('DOMContentLoaded', function () {
                var accountingCheckbox = document.getElementById('module_contabilidade');
                var accountingSettings = document.getElementById('contabilidade-settings');
                if (accountingCheckbox && accountingSettings) {
                    var accountingInputs = accountingSettings.querySelectorAll('input, select, textarea');

                    function setAccountingInputsDisabled(disabled) {
                        for (var i = 0; i < accountingInputs.length; i++) {
                            accountingInputs[i].disabled = disabled;
                        }
                    }

                    function toggleAccountingSettings() {
                        if (accountingCheckbox.checked) {
                            accountingSettings.style.display = '';
                            setAccountingInputsDisabled(false);
                        } else {
                            accountingSettings.style.display = 'none';
                            setAccountingInputsDisabled(true);
                        }
                    }

                    toggleAccountingSettings();
                    accountingCheckbox.addEventListener('change', toggleAccountingSettings);
                }

                var comprasCheckbox = document.getElementById('module_compras');
                var comprasSettings = document.getElementById('compras-settings');
                if (comprasCheckbox && comprasSettings) {
                    var comprasInputs = comprasSettings.querySelectorAll('input, select, textarea');

                    function setComprasInputsDisabled(disabled) {
                        for (var i = 0; i < comprasInputs.length; i++) {
                            comprasInputs[i].disabled = disabled;
                        }
                    }

                    function toggleComprasSettings() {
                        if (comprasCheckbox.checked) {
                            comprasSettings.style.display = '';
                            setComprasInputsDisabled(false);
                        } else {
                            comprasSettings.style.display = 'none';
                            setComprasInputsDisabled(true);
                        }
                    }

                    toggleComprasSettings();
                    comprasCheckbox.addEventListener('change', toggleComprasSettings);
                }
            });
            </script>
        </div>
        <?php endif; ?>
    </div>
</div>
<style>
.settings-page {
    padding-bottom: 2.5rem;
}
.settings-tabs {
    margin-bottom: 1.5rem;
}
.settings-tabs .nav-link i {
    margin-right: 0.35rem;
}
.settings-content .x_panel {
    border-radius: 16px;
    box-shadow: 0 14px 30px rgba(30, 60, 80, 0.12);
    border: 1px solid rgba(25, 60, 80, 0.12);
}
.settings-content .x_title h2 {
    font-size: 1.1rem;
}
.setting-switch .form-check-input,
.module-card .form-check-input {
    width: 3rem;
    height: 1.5rem;
}
.api-settings-box {
    padding: 1rem;
    border-radius: 14px;
    border: 1px dashed rgba(15, 110, 109, 0.35);
    background: rgba(15, 110, 109, 0.08);
}
.endpoint-tile {
    padding: 0.6rem 0.75rem;
    border-radius: 12px;
    border: 1px solid rgba(15, 110, 109, 0.2);
    background: #f7fbfb;
}
.module-card .module-toggle {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.module-settings {
    padding: 0.85rem;
    border-radius: 12px;
    background: #f8fafc;
    border: 1px solid rgba(90, 110, 130, 0.15);
}
.module-settings-row .input-compact {
    max-width: 12ch;
}
@media (max-width: 575.98px) {
    .module-settings-row .input-compact {
        max-width: 100%;
    }
    .module-card .module-toggle {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>
<?php require_once __DIR__ . '/footer.php';
