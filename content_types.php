<?php
/**
 * Gestão de tipos de conteúdo.
 *
 * Esta página lista todos os tipos de conteúdo existentes e permite
 * criar novos tipos. Requer autenticação para aceder.
 */
require_once 'functions.php';
startSession();
requireLogin();

// Tratamento da submissão do formulário para criar um novo tipo
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    if ($name !== '') {
        createContentType($name);
        header('Location: content_types.php');
        exit;
    } else {
        $error = 'O nome é obrigatório.';
    }
}

// Recupera todos os tipos de conteúdo
$types = getContentTypes();
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Tipos de Conteúdo</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.2.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/gentelella@2.0.0/build/css/custom.min.css" rel="stylesheet">
</head>
<body class="nav-md">
<div class="container body">
  <div class="main_container">
    <div class="top_nav">
      <div class="nav_menu">
        <nav class="" role="navigation">
          <ul class="navbar-nav float-end">
            <li class="nav-item">
              <a class="nav-link" href="dashboard.php">Painel</a>
            </li>
            <li class="nav-item">
              <a class="nav-link" href="logout.php">Terminar sessão</a>
            </li>
          </ul>
        </nav>
      </div>
    </div>
    <div class="right_col" role="main">
      <div class="container-fluid">
        <h2 class="mt-3">Tipos de Conteúdo</h2>
        <?php if ($error): ?>
          <div class="alert alert-danger"> <?php echo htmlspecialchars($error); ?> </div>
        <?php endif; ?>
        <table class="table table-striped">
          <thead><tr><th>Nome</th><th>Ações</th></tr></thead>
          <tbody>
          <?php foreach ($types as $type): ?>
            <tr>
              <td><?php echo htmlspecialchars($type['name']); ?></td>
              <td>
                <a href="custom_fields.php?type_id=<?php echo $type['id']; ?>">Campos</a> |
                <a href="add_content.php?type_id=<?php echo $type['id']; ?>">Adicionar</a> |
                <a href="list_content.php?type_id=<?php echo $type['id']; ?>">Listar</a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="card p-3 mt-4">
          <h5>Criar novo tipo de conteúdo</h5>
          <form method="post" action="">
            <div class="mb-3">
              <label class="form-label" for="name">Nome</label>
              <input type="text" class="form-control" id="name" name="name" required>
            </div>
            <button type="submit" class="btn btn-primary">Criar</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.2.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>