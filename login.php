<?php
// Handles login and logout actions for the CMS. When accessed normally it
// displays the login form; when invoked with "action=logout" it logs out the
// current user and redirects back to the login page.

require_once __DIR__ . '/data/db.php';
require_once __DIR__ . '/functions.php';

startSession();

if (($_GET['action'] ?? '') === 'logout') {
    logoutUser();
    header('Location: ' . BASE_URL . 'login');
    exit;
}

$error = '';
// If the form is posted attempt to authenticate the user.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    if ($username !== '' && $password !== '') {
        if (loginUser($username, $password)) {
            // Redirect to dashboard or to a previously requested page
            $redirect = $_GET['redirect'] ?? (BASE_URL . 'dashboard');
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
</head>
<body class="login">
    <div>
      <a class="hiddenanchor" id="signin"></a>
      <div class="login_wrapper">
        <div class="animate form login_form">
          <section class="login_content">
            <form method="post" action="">
              <h1>Login</h1>
              <?php if ($error): ?>
                <div class="alert alert-danger" role="alert">
                  <?php echo htmlspecialchars($error); ?>
                </div>
              <?php endif; ?>
              <div>
                <input type="text" class="form-control" placeholder="Utilizador" name="username" required autofocus />
              </div>
              <div>
                <input type="password" class="form-control" placeholder="Palavra‑passe" name="password" required />
              </div>
              <div>
                <button class="btn btn-primary submit" type="submit"><i class="fa fa-sign-in"></i> Entrar</button>
              </div>
              <div class="clearfix"></div>
            </form>
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
