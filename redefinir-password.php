<?php
// Password reset landing page: validates the token from the recovery email
// (scoped to the company identified by "nif") and lets the user set a new
// password.

require_once __DIR__ . '/data/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/companies.php';

startSession();

$nif = trim($_GET['nif'] ?? $_POST['nif'] ?? '');
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$error = '';
$done = false;
$validToken = false;

$company = $nif !== '' ? getCompanyByNif($nif) : null;
if ($company) {
    setCompanyContext($company);
    $user = findUserByPasswordResetToken($token);
    $validToken = $user !== null;
} else {
    $error = 'Ligação inválida.';
}

$csrfToken = generateCsrfToken();

if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }
    $csrfToken = generateCsrfToken();
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $error = 'A palavra-passe deve ter pelo menos 8 caracteres.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'As palavras-passe não coincidem.';
    } else {
        try {
            consumePasswordResetToken($token, password_hash($password, PASSWORD_DEFAULT));
            $done = true;
            $validToken = false;
        } catch (Throwable $e) {
            $error = 'Este link já foi utilizado ou expirou. Solicite uma nova recuperação.';
            $validToken = false;
        }
    }
}

if ($company) {
    clearCompanyContext();
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CMS – Redefinir Palavra-passe</title>
    <link rel="stylesheet" href="vendors/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="vendors/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="vendors/nprogress/nprogress.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://colorlibhq.github.io/gentelella/build/css/gentelella.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <style>
      .login {
        min-height: 100vh;
        background:
          linear-gradient(rgba(245, 247, 251, 0.9), rgba(234, 245, 241, 0.82)),
          radial-gradient(circle at 12% 18%, rgba(52, 152, 219, 0.18), transparent 35%),
          radial-gradient(circle at 88% 82%, rgba(26, 188, 156, 0.16), transparent 38%),
          linear-gradient(135deg, #f5f7fb 0%, #ecf3f8 50%, #eaf5f1 100%);
        background-attachment: fixed;
      }
      .login .login_wrapper { max-width: 430px; padding-top: 6vh; }
      .login .x_panel.login-panel {
        border: 1px solid #d9e3ec;
        border-radius: 14px;
        box-shadow: 0 20px 55px rgba(20, 32, 44, 0.14);
        overflow: hidden;
        margin-bottom: 0;
      }
      .login .x_panel.login-panel .x_title {
        border-bottom: 1px solid #e7edf3;
        padding: 18px 22px;
        background: linear-gradient(90deg, #2a3f54 0%, #304a63 100%);
      }
      .login .x_panel.login-panel .x_title h2 {
        margin: 0;
        color: #fff;
        font-weight: 600;
        font-size: 18px;
      }
      .login .x_panel.login-panel .x_title h2 small {
        display: block;
        margin-top: 4px;
        color: rgba(255, 255, 255, 0.72);
        font-size: 12px;
      }
      .login .x_content { padding: 24px 22px 18px; }
      .login .input-group-addon {
        background: #f3f6fa;
        border: 1px solid #d3dde7;
        color: #5f738c;
        min-width: 42px;
      }
      .login .form-control { border: 1px solid #d3dde7; height: 42px; }
      .login .btn-login { width: 100%; height: 42px; font-weight: 600; letter-spacing: 0.2px; }
      .login .login-note { margin: 14px 0 0; text-align: center; color: #73879c; font-size: 12px; }
    </style>
</head>
<body class="login">
    <div class="login-page">
      <a class="hiddenanchor" id="signin"></a>
      <div class="login_wrapper">
        <div class="animate form login_form">
          <section>
            <div class="x_panel login-panel">
              <div class="x_title">
                <h2>
                  <i class="fa fa-lock"></i> Redefinir Palavra-passe
                  <small>Escolha uma nova palavra-passe</small>
                </h2>
                <div class="clearfix"></div>
              </div>
              <div class="x_content">
                <?php if ($done): ?>
                  <div class="alert alert-success" role="alert">
                    <i class="fa fa-check-circle"></i>
                    Palavra-passe redefinida com sucesso.
                  </div>
                  <p class="login-note">
                    <a href="<?php echo htmlspecialchars(BASE_URL . 'login'); ?>"><i class="fa fa-sign-in"></i> Iniciar sessão</a>
                  </p>
                <?php elseif (!$validToken): ?>
                  <div class="alert alert-danger" role="alert">
                    <i class="fa fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error !== '' ? $error : 'Este link de recuperação é inválido ou já expirou.'); ?>
                  </div>
                  <p class="login-note">
                    <a href="<?php echo htmlspecialchars(BASE_URL . 'recuperar-password'); ?>"><i class="fa fa-refresh"></i> Pedir novo link</a>
                  </p>
                <?php else: ?>
                  <form method="post" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="nif" value="<?php echo htmlspecialchars($nif); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                    <?php if ($error): ?>
                      <div class="alert alert-danger" role="alert">
                        <i class="fa fa-exclamation-triangle"></i>
                        <?php echo htmlspecialchars($error); ?>
                      </div>
                    <?php endif; ?>
                    <div class="form-group">
                      <label class="control-label">Nova palavra-passe</label>
                      <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-key"></i></span>
                        <input type="password" class="form-control" name="password" minlength="8" required autofocus />
                      </div>
                    </div>
                    <div class="form-group">
                      <label class="control-label">Confirmar palavra-passe</label>
                      <div class="input-group">
                        <span class="input-group-addon"><i class="fa fa-key"></i></span>
                        <input type="password" class="form-control" name="password_confirm" minlength="8" required />
                      </div>
                    </div>
                    <div class="form-group">
                      <button class="btn btn-primary btn-login" type="submit">
                        <i class="fa fa-check"></i> Redefinir palavra-passe
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>

    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="vendors/nprogress/nprogress.js"></script>
    <script src="https://colorlibhq.github.io/gentelella/build/js/custom.min.js"></script>
</body>
</html>
