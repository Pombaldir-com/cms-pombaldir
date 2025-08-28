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
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CMS – Login</title>
    <!-- Bootstrap and Gentelella CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-ENjdO4Dr2bkBIFxQpeo3xXbl4ClbBZ9OezHET57ikQRAxQF93FhjV0z9WTR2xmQf" crossorigin="anonymous">
    <link rel="stylesheet" href="https://colorlibhq.github.io/gentelella/build/css/gentelella.min.css">
</head>
<body class="login">
    <div class="login_wrapper">
        <section class="login_content">
            <form method="post" action="">
                <h1>Entrar no CMS</h1>
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                <div class="mb-3">
                    <input type="text" name="username" class="form-control" placeholder="Utilizador" required autofocus>
                </div>
                <div class="mb-3">
                    <input type="password" name="password" class="form-control" placeholder="Palavra‑passe" required>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Entrar</button>
                </div>
                <div class="clearfix"></div>
            </form>
        </section>
    </div>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-QT8V3PQR9on7WZzscCvtAHvjNa3Wxj4Rtx6ATLv3b29btaLGGkpqCj1BEcLFcVVU" crossorigin="anonymous"></script>
    <!-- Gentelella JS -->
    <script src="https://colorlibhq.github.io/gentelella/build/js/gentelella.min.js"></script>
</body>
</html>