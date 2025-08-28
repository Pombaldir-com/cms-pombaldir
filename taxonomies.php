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

$error = '';
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
    $editId  = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
    $delId   = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;
    $editing = $editId ? getTaxonomy($editId) : null;

    if ($delId) {
        deleteTaxonomy($delId);
        header('Location: taxonomies.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
        $name  = trim($_POST['name']);
        $label = trim($_POST['label'] ?? '');
        $icon  = trim($_POST['icon'] ?? '');
        if ($name !== '' && $label !== '') {
            if ($editing) {
                updateTaxonomy($editing['id'], $name, $label, $icon);
            } else {
                createTaxonomy($name, $label, $icon);
            }
            header('Location: taxonomies.php');
            exit;
        } else {
            $error = 'Nome e rótulo são obrigatórios.';
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
    <?php if ($error): ?>
        <div class="alert alert-danger"> <?php echo htmlspecialchars($error); ?> </div>
    <?php endif; ?>
    <table class="table table-striped datatable">
        <thead><tr><th>Slug</th><th>Rótulo</th><th>Ícone</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($taxonomies as $tax): ?>
            <tr>
                <td><?php echo htmlspecialchars($tax['name']); ?></td>
                <td><?php echo htmlspecialchars($tax['label']); ?></td>
                <td><i class="fa <?php echo htmlspecialchars($tax['icon'] ?: 'fa-tag'); ?>"></i></td>
                <td>
                    <a href="taxonomies.php?taxonomy_id=<?php echo $tax['id']; ?>">Gerir termos</a> |
                    <a href="taxonomies.php?edit_id=<?php echo $tax['id']; ?>">Editar</a> |
                    <a href="taxonomies.php?delete_id=<?php echo $tax['id']; ?>" onclick="return confirm('Eliminar esta taxonomia?');">Eliminar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card p-3 mt-4">
        <h5><?php echo isset($editing) && $editing ? 'Editar taxonomia' : 'Criar nova taxonomia'; ?></h5>
        <form method="post" action="<?php echo isset($editing) && $editing ? '?edit_id=' . $editing['id'] : ''; ?>">
            <div class="mb-3">
                <label class="form-label" for="name">Slug</label>
                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="label">Rótulo</label>
                <input type="text" class="form-control" id="label" name="label" value="<?php echo htmlspecialchars($editing['label'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="icon">Ícone (classe Font Awesome)</label>
                <input type="text" class="form-control" id="icon" name="icon" value="<?php echo htmlspecialchars($editing['icon'] ?? ''); ?>">
            </div>
            <button type="submit" class="btn btn-primary"><?php echo isset($editing) && $editing ? 'Guardar' : 'Criar'; ?></button>
            <?php if (isset($editing) && $editing): ?>
                <a href="taxonomies.php" class="btn btn-secondary">Cancelar</a>
            <?php else: ?>
                <a href="dashboard.php" class="btn btn-secondary">Voltar</a>
            <?php endif; ?>
        </form>
    </div>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

