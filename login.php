<?php
// Handles login and logout actions for the CMS. When accessed normally it
// displays the login form; when invoked with "action=logout" it logs out the
// current user and redirects back to the login page.

require_once __DIR__ . '/data/db.php';
require_once __DIR__ . '/functions.php';

startSession();
$csrfToken = generateCsrfToken();

if (($_GET['action'] ?? '') === 'logout') {
    logoutUser();
    header('Location: ' . BASE_URL . 'login');
    exit;
}

$error = '';
// If the form is posted attempt to authenticate the user.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }
    $csrfToken = generateCsrfToken();
    $nif = trim($_POST['nif'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($nif !== '' && $username !== '' && $password !== '') {
        require_once __DIR__ . '/companies.php';
        $company = getCompanyByNif($nif);
        if ($company) {
            setCompanyContext($company);
            if (loginUser($username, $password)) {
                // Redirect to dashboard or to a previously requested page
                $redirect = normalizeRedirectTarget($_GET['redirect'] ?? null);
                if ($redirect === null) {
                    $redirect = BASE_URL . 'dashboard';
                }
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = 'Nome de utilizador ou palavra‑passe inválidos.';
                clearCompanyContext();
            }
        } else {
            $error = 'Empresa não encontrada.';
        }
    } else {
        $error = 'Preencha todos os campos.';
    }
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CMS – Login</title>
    <link rel="stylesheet" href="vendors/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="vendors/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="vendors/nprogress/nprogress.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://colorlibhq.github.io/gentelella/build/css/gentelella.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <!-- Layout follows the Gentelella theme -->
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

      .login .login_wrapper {
        max-width: 430px;
        padding-top: 6vh;
      }

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

      .login .x_content {
        padding: 24px 22px 18px;
      }

      .login .input-group-addon {
        background: #f3f6fa;
        border: 1px solid #d3dde7;
        color: #5f738c;
        min-width: 42px;
      }

      .login .form-control {
        border: 1px solid #d3dde7;
        height: 42px;
      }

      .login .btn-login {
        width: 100%;
        height: 42px;
        font-weight: 600;
        letter-spacing: 0.2px;
      }

      .login .login-note {
        margin: 14px 0 0;
        text-align: center;
        color: #73879c;
        font-size: 12px;
      }
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
                  <i class="fa fa-lock"></i> <span id="login-company-title">Acesso ao Sistema</span>
                  <small>Introduza os seus dados para continuar</small>
                </h2>
                <div class="clearfix"></div>
              </div>
              <div class="x_content">
                <form method="post" action="">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                  <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                      <i class="fa fa-exclamation-triangle"></i>
                      <?php echo htmlspecialchars($error); ?>
                    </div>
                  <?php endif; ?>
                  <div class="form-group">
                    <label class="control-label">NIF da empresa</label>
                    <div class="input-group">
                      <span class="input-group-addon"><i class="fa fa-building-o"></i></span>
                      <input type="text" class="form-control" placeholder="Ex.: 500000000" name="nif" value="<?php echo htmlspecialchars($nif ?? ''); ?>" required autofocus />
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="control-label">Utilizador</label>
                    <div class="input-group">
                      <span class="input-group-addon"><i class="fa fa-user"></i></span>
                      <input type="text" class="form-control" placeholder="Nome de utilizador" name="username" value="<?php echo htmlspecialchars($username ?? ''); ?>" required />
                    </div>
                  </div>
                  <div class="form-group">
                    <label class="control-label">Palavra-passe</label>
                    <div class="input-group">
                      <span class="input-group-addon"><i class="fa fa-key"></i></span>
                      <input type="password" class="form-control" placeholder="Palavra-passe" name="password" required />
                    </div>
                  </div>
                  <div class="form-group">
                    <button class="btn btn-primary btn-login" type="submit">
                      <i class="fa fa-sign-in"></i> Entrar
                    </button>
                  </div>
                  <p class="login-note">
                    <a href="<?php echo htmlspecialchars(BASE_URL . 'recuperar-password'); ?>">Esqueceu a palavra-passe?</a>
                  </p>
                  <p class="login-note">
                    <i class="fa fa-shield"></i> Sessao protegida com validacao CSRF
                  </p>
                </form>
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
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const titleEl = document.getElementById('login-company-title');
        if (titleEl) {
          const storedCompanyName = localStorage.getItem('last_company_name');
          if (storedCompanyName && storedCompanyName.trim() !== '') {
            titleEl.textContent = storedCompanyName.trim();
          }
        }

        const nifInput = document.querySelector('input[name="nif"]');
        if (!nifInput) return;

        const savedNif = localStorage.getItem('nif');
        if (savedNif) {
          nifInput.value = savedNif;

          const usernameInput = document.querySelector('input[name="username"]');
          if (usernameInput) {
            usernameInput.focus();
          }
        }

        const form = nifInput.form;
        if (form) {
          form.addEventListener('submit', function () {
            localStorage.setItem('nif', nifInput.value);
          });
        }
      });
    </script>
</body>
</html>
