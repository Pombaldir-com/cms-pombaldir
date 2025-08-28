<?php
// Login page for the CMS.  Presents a simple form asking for a
// username and password.  When submitted it attempts to authenticate
// the user and redirects to the dashboard on success.  Any
// authentication errors are displayed at the top of the form.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

startSession();

$error = '';
// If the form is posted attempt to authenticate the user.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username !== '' && $password !== '') {
        if (loginUser($username, $password)) {
            // Redirect to dashboard or to a previously requested page
            $redirect = $_GET['redirect'] ?? 'dashboard.php';
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = 'Nome de utilizador ou palavra‑passe inválidos.';
        }
    } else {
        $error = 'Preencha ambos os campos.';
    }
}

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CMS – Login</title>
    <link rel="stylesheet" href="vendors/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="vendors/@fortawesome/fontawesome-free/css/all.min.css">
    <link rel="stylesheet" href="https://colorlibhq.github.io/gentelella/build/css/gentelella.min.css">
    <style>
      .login-bg {
        background: linear-gradient(135deg, #2A3F54 0%, #34495e 100%);
        min-height: 100vh;
      }
      .login-input-group .form-control,
      .login-input-group .input-group-text,
      .login-input-group .btn {
        height: 38px;
        line-height: 1.5;
      }
      .login-input-group .form-control {
        border-radius: 0;
      }
      .login-input-group .input-group-text {
        border-radius: 0.375rem 0 0 0.375rem;
      }
      .login-input-group .btn {
        border-radius: 0 0.375rem 0.375rem 0;
        border-color: rgb(222, 226, 230) !important;
      }
      .login-input-group .form-control:focus {
        box-shadow: none;
        border-color: #86b7fe;
      }
      .login-input-group .form-control::placeholder {
        padding-left: 8px;
      }
    </style>
</head>
<body class="login-bg">
    <div class="container-fluid d-flex align-items-center justify-content-center min-vh-100">
        <div class="row justify-content-center w-100">
            <div class="col-xl-4 col-lg-5 col-md-6 col-sm-8 col-10">
                <div class="card shadow-lg border-0">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <h3 class="mb-0 fw-bold text-dark">CMS</h3>
                            <p class="text-muted">Introduza as suas credenciais</p>
                        </div>
                        <?php if ($error): ?>
                            <div class="alert alert-danger" role="alert">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        <form method="post" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label text-muted">Utilizador</label>
                                <div class="input-group login-input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-user text-muted"></i>
                                    </span>
                                    <input type="text" name="username" id="username" class="form-control border-start-0 ps-0" placeholder="Utilizador" required autofocus>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="password" class="form-label text-muted">Palavra‑passe</label>
                                <div class="input-group login-input-group">
                                    <span class="input-group-text bg-light border-end-0">
                                        <i class="fas fa-lock text-muted"></i>
                                    </span>
                                    <input type="password" name="password" id="password" class="form-control border-start-0 ps-0" placeholder="Palavra‑passe" required>
                                </div>
                            </div>
                            <div class="d-grid mb-3">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>Entrar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://colorlibhq.github.io/gentelella/build/js/gentelella.min.js"></script>
</body>
</html>