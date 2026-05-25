<?php
require_once __DIR__ . '/../functions.php';

startSession();
$tenantSlug = trim((string) ($_GET['tenant_slug'] ?? ''));
if ($tenantSlug === '' || !ensureTenantCompanyBySlug($tenantSlug)) {
    http_response_code(404);
    exit('Tenant invalida.');
}

if (!empty($_SESSION['client_user_id']) && strcasecmp((string) ($_SESSION['client_user_tenant_slug'] ?? ''), $tenantSlug) === 0) {
    header('Location: ' . BASE_URL . 't/' . rawurlencode($tenantSlug) . '/cliente/dashboard');
    exit;
}

$error = '';
$csrfToken = generateCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(400);
        exit('Token CSRF invalido');
    }
    $csrfToken = generateCsrfToken(true);
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = trim((string) ($_POST['password'] ?? ''));
    if ($username === '' || $password === '') {
        $error = 'Preencha utilizador e password.';
    } elseif (!clientLogin($username, $password, $tenantSlug)) {
        $error = 'Credenciais invalidas ou conta inativa.';
    } else {
        header('Location: ' . BASE_URL . 't/' . rawurlencode($tenantSlug) . '/cliente/dashboard');
        exit;
    }
}
$appName = getSetting('app_name', 'Portal Cliente');
$appLogo = trim((string) getSetting('app_logo', ''));
if ($appLogo !== '' && !file_exists(__DIR__ . '/../' . $appLogo)) {
    $appLogo = '';
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($appName); ?> - Login Cliente</title>
    <base href="<?= BASE_URL ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>vendors/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>vendors/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/custom.css">
    <style>
      .client-login {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background:
          linear-gradient(rgba(244, 247, 251, 0.9), rgba(235, 244, 250, 0.86)),
          radial-gradient(circle at 10% 15%, rgba(52, 152, 219, 0.18), transparent 34%),
          radial-gradient(circle at 85% 78%, rgba(26, 188, 156, 0.14), transparent 36%),
          linear-gradient(140deg, #f7fafd 0%, #ecf3f9 50%, #e9f2f8 100%);
      }
      .client-login .login_wrapper {
        max-width: 470px;
        width: 100%;
        padding-top: 0;
        margin: 0 auto;
        transform: translateY(-24px);
      }
      .client-login .login_wrapper section {
        margin: 0;
      }
      .client-login .x_panel.login-panel {
        border: 1px solid #d7e2ed;
        border-radius: 14px;
        box-shadow: 0 22px 60px rgba(24, 37, 52, 0.14);
        overflow: hidden;
      }
      .client-login .x_title {
        border-bottom: 1px solid #e4ebf2;
        padding: 18px 22px;
        background: linear-gradient(90deg, #2a3f54 0%, #334d67 100%);
      }
      .client-login .x_title h2 {
        margin: 0;
        color: #fff;
        font-size: 22px;
        font-weight: 700;
        letter-spacing: 0.2px;
      }
      .client-login .x_content {
        padding: 6px 22px 14px;
      }
      .client-login .tenant-logo-wrap {
        text-align: center;
        margin: 20px 0 20px;
        line-height: 0;
      }
      .client-login .tenant-logo {
        max-height: 80px;
        max-width: 240px;
        width: auto;
        display: inline-block;
      }
      .client-login form {
        margin-top: 0;
      }
      .client-login .input-group-addon {
        min-width: 46px;
        width: 46px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: #5f738c;
      }
      .client-login .input-group-addon i {
        line-height: 1;
      }
      .client-login .control-label {
        font-size: 19px;
        font-weight: 600;
        color: #6a8098;
        margin-bottom: 6px;
        letter-spacing: 0.1px;
      }
      .client-login .form-control {
        height: 48px;
        font-size: 16px;
        font-weight: 500;
        color: #2a3f54;
      }
      .client-login .btn-login {
        width: 100%;
        height: 52px;
        font-size: 20px;
        font-weight: 700;
        letter-spacing: 0.2px;
      }
    </style>
</head>
<body class="login client-login">
<div class="login_wrapper">
    <section>
        <div class="x_panel login-panel">
            <div class="x_title">
                <h2><i class="fa fa-lock"></i> <?= htmlspecialchars($appName); ?></h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?php if ($appLogo !== ''): ?>
                    <div class="tenant-logo-wrap">
                        <img src="<?= htmlspecialchars(BASE_URL . ltrim($appLogo, '/')); ?>" alt="<?= htmlspecialchars($appName); ?>" class="tenant-logo">
                    </div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><i class="fa fa-warning"></i> <?= htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="control-label">Utilizador</label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="fa fa-user"></i></span>
                            <input type="text" class="form-control" name="username" placeholder="Utilizador" autocomplete="username" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="control-label">Password</label>
                        <div class="input-group">
                            <span class="input-group-addon"><i class="fa fa-key"></i></span>
                            <input type="password" class="form-control" name="password" placeholder="Password" autocomplete="current-password" required>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 14px;">
                        <button class="btn btn-primary btn-login" type="submit"><i class="fa fa-sign-in"></i> Entrar</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
<script src="<?= BASE_URL ?>vendors/jquery/dist/jquery.min.js"></script>
<script src="<?= BASE_URL ?>vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
