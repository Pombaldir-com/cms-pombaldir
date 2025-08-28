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

require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

$taxonomyId = isset($_GET['taxonomy_id']) ? (int)$_GET['taxonomy_id'] : 0;
$taxonomy = null;
if ($taxonomyId) {
    // Gestão de termos para uma taxonomia específica
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['term_name'])) {
        $termName = trim($_POST['term_name']);
        if ($termName !== '') {
            createTerm($taxonomyId, $termName);
            header('Location: taxonomies.php?taxonomy_id=' . $taxonomyId);
            exit;
        }
    }
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
        $name  = trim($_POST['name']);
        $label = trim($_POST['label'] ?? '');
        if ($name !== '' && $label !== '') {
            createTaxonomy($name, $label);
            header('Location: taxonomies.php');
            exit;
        }
    }
    $taxonomies = getTaxonomies();
}

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
<?php if ($taxonomyId && $taxonomy): ?>
    <h2 class="mt-3">Termos de <?php echo htmlspecialchars($taxonomy['label']); ?></h2>
    <table class="table table-striped datatable">
        <thead><tr><th>Nome</th></tr></thead>
        <tbody>
        <?php foreach ($terms as $term): ?>
            <tr><td><?php echo htmlspecialchars($term['name']); ?></td></tr>
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
    <table class="table table-striped datatable">
        <thead><tr><th>Slug</th><th>Rótulo</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($taxonomies as $tax): ?>
            <tr>
                <td><?php echo htmlspecialchars($tax['name']); ?></td>
                <td><?php echo htmlspecialchars($tax['label']); ?></td>
                <td><a href="taxonomies.php?taxonomy_id=<?php echo $tax['id']; ?>">Gerir termos</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card p-3 mt-4">
        <h5>Criar nova taxonomia</h5>
        <form method="post" action="">
            <div class="mb-3">
                <label class="form-label" for="name">Slug</label>
                <input type="text" class="form-control" id="name" name="name" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="label">Rótulo</label>
                <input type="text" class="form-control" id="label" name="label" required>
            </div>
            <button type="submit" class="btn btn-primary">Criar</button>
            <a href="dashboard.php" class="btn btn-secondary">Voltar</a>
        </form>
    </div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

