<?php
/**
 * Painel principal do CMS.
 *
 * Este script apresenta um painel simples com a lista de tipos de conteúdo e
 * taxonomias existentes, fornecendo links para gerir campos personalizados,
 * adicionar conteúdos e listar conteúdos de cada tipo. Também permite criar
 * novos tipos de conteúdo e taxonomias. Requer que o utilizador esteja
 * autenticado para aceder.
 */

require_once 'functions.php';

// Inicia sessão e verifica se o utilizador está autenticado
startSession();
requireLogin();

// Recupera os tipos de conteúdo e taxonomias existentes
$types = getContentTypes();
$taxonomies = getTaxonomies();

?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Painel de Administração</title>
    <!-- Inclui CSS do Bootstrap e do Gentelella -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.2.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/gentelella@2.0.0/build/css/custom.min.css" rel="stylesheet">
</head>
<body class="nav-md">
<div class="container body">
  <div class="main_container">
    <!-- Barra de navegação superior -->
    <div class="top_nav">
      <div class="nav_menu">
        <nav class="" role="navigation">
          <ul class="navbar-nav float-end">
            <li class="nav-item">
            <?php
              // Recupera o utilizador autenticado (array com id e username)
              $user = currentUser();
            ?>
            <a class="nav-link" href="logout.php">
              Terminar sessão (<?php echo htmlspecialchars($user['username']); ?>)
            </a>
            </li>
          </ul>
        </nav>
      </div>
    </div>
    <!-- Conteúdo da página -->
    <div class="right_col" role="main">
      <div class="container-fluid">
        <h2 class="mt-3">Tipos de Conteúdo</h2>
        <table class="table table-striped">
          <thead>
            <tr><th>Nome</th><th>Ações</th></tr>
          </thead>
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
          <form method="post" action="content_types.php">
            <div class="mb-3">
              <label class="form-label" for="name">Nome</label>
              <input type="text" class="form-control" id="name" name="name" required>
            </div>
            <button type="submit" class="btn btn-primary">Criar</button>
          </form>
        </div>
        <h2 class="mt-5">Taxonomias</h2>
        <table class="table table-striped">
          <thead>
            <tr><th>Nome</th><th>Ações</th></tr>
          </thead>
          <tbody>
          <?php foreach ($taxonomies as $tax): ?>
            <tr>
              <td><?php echo htmlspecialchars($tax['name']); ?></td>
              <td><a href="taxonomies.php?taxonomy_id=<?php echo $tax['id']; ?>">Gerir termos</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="card p-3 mt-4">
          <h5>Criar nova taxonomia</h5>
          <form method="post" action="taxonomies.php">
            <div class="mb-3">
              <label class="form-label" for="tname">Nome</label>
              <input type="text" class="form-control" id="tname" name="name" required>
            </div>
            <button type="submit" class="btn btn-primary">Criar</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Scripts do Bootstrap -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.2.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>