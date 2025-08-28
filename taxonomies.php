<?php
/**
 * Gestão de taxonomias e termos.
 *
 * Esta página permite criar novas taxonomias e gerir os termos de cada
 * taxonomia seleccionada. Se um parâmetro `taxonomy_id` for passado, a
 * interface apresenta os termos dessa taxonomia e um formulário para
 * adicionar novos termos. Caso contrário, lista-se todas as taxonomias
 * existentes e um formulário para criar uma nova.
 */

require_once 'functions.php';
startSession();
requireLogin();

$taxonomyId = isset($_GET['taxonomy_id']) ? (int)$_GET['taxonomy_id'] : 0;
$taxonomy = null;
if ($taxonomyId) {
    // Gestão de termos para uma taxonomia específica
    // Processa criação de novo termo
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['term_name'])) {
        $termName = trim($_POST['term_name']);
        if ($termName !== '') {
            createTerm($taxonomyId, $termName);
            header('Location: taxonomies.php?taxonomy_id=' . $taxonomyId);
            exit;
        }
    }
    // Recupera a taxonomia e os termos associados
    $taxonomy = null;
    $taxonomies = getTaxonomies();
    foreach ($taxonomies as $t) {
        if ($t['id'] == $taxonomyId) { $taxonomy = $t; break; }
    }
    if (!$taxonomy) {
        echo "Taxonomia inválida.";
        exit;
    }
    $terms = getTerms($taxonomyId);
} else {
    // Criação de nova taxonomia
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
        $name = trim($_POST['name']);
        if ($name !== '') {
            createTaxonomy($name);
            header('Location: taxonomies.php');
            exit;
        }
    }
    $taxonomies = getTaxonomies();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Taxonomias</title>
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
        <?php if ($taxonomyId && $taxonomy): ?>
          <h2 class="mt-3">Termos de <?php echo htmlspecialchars($taxonomy['name']); ?></h2>
          <table class="table table-striped">
            <thead><tr><th>Nome</th></tr></thead>
            <tbody>
            <?php foreach ($terms as $term): ?>
              <tr>
                <td><?php echo htmlspecialchars($term['name']); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <div class="card p-3 mt-4">
            <h5>Adicionar novo termo</h5>
            <form method="post" action="">
              <div class="mb-3">
                <label class="form-label" for="term_name">Nome</label>
                <input type="text" class="form-control" id="term_name" name="term_name" required>
              </div>
              <button type="submit" class="btn btn-primary">Adicionar</button>
              <a href="taxonomies.php" class="btn btn-secondary">Voltar</a>
            </form>
          </div>
        <?php else: ?>
          <h2 class="mt-3">Taxonomias</h2>
          <table class="table table-striped">
            <thead><tr><th>Nome</th><th>Ações</th></tr></thead>
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
            <form method="post" action="">
              <div class="mb-3">
                <label class="form-label" for="name">Nome</label>
                <input type="text" class="form-control" id="name" name="name" required>
              </div>
              <button type="submit" class="btn btn-primary">Criar</button>
              <a href="dashboard.php" class="btn btn-secondary">Voltar</a>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.2.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>