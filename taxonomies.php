<?php
/**
 * Gestão de taxonomias e termos.
 *
 * Esta página permite criar novas taxonomias e gerir os termos de cada
 * taxonomia seleccionada. Se um parâmetro `taxonomy_id` for passado, a
 * interface apresenta os termos dessa taxonomia. Para adicionar novos termos,
 * utilizar `taxonomies.php?taxonomy_id={ID}&act=ad`. Caso contrário, lista-se
 * todas as taxonomias existentes. Para criar uma nova taxonomia, utilizar
 * `taxonomies.php?act=ad`.
 */

require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

 $error = '';
 $taxonomyId = isset($_GET['taxonomy_id']) ? (int)$_GET['taxonomy_id'] : 0;
 $act = $_GET['act'] ?? '';
 $taxonomy = null;
if ($taxonomyId) {
    // Gestão de termos para uma taxonomia específica
    if ($act === 'ad' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['term_name'])) {
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

    if (($act === 'ad' || $editing) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
        $name  = trim($_POST['name']);
        $label = trim($_POST['label'] ?? '');
        if ($name !== '' && $label !== '') {
            if ($editing) {
                updateTaxonomy($editing['id'], $name, $label);
            } else {
                createTaxonomy($name, $label);
            }
            header('Location: taxonomies.php');
            exit;
        } else {
            $error = 'Nome e rótulo são obrigatórios.';
        }
    }

    if ($act !== 'ad' && !$editing) {
        $taxonomies = getTaxonomies();
    }
}

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
<?php if ($taxonomyId && $taxonomy): ?>
    <?php if ($act === 'ad'): ?>
        <h2 class="mt-3">Adicionar novo termo a <?php echo htmlspecialchars($taxonomy['label']); ?></h2>
        <div class="card p-3 mt-4">
            <form method="post" action="?taxonomy_id=<?php echo $taxonomyId; ?>&act=ad">
                <div class="mb-3">
                    <label class="form-label" for="term_name">Nome</label>
                    <input type="text" class="form-control" id="term_name" name="term_name" required>
                </div>
                <button type="submit" class="btn btn-primary">Adicionar</button>
                <a href="taxonomies.php?taxonomy_id=<?php echo $taxonomyId; ?>" class="btn btn-secondary">Voltar</a>
            </form>
        </div>
    <?php else: ?>
        <h2 class="mt-3">Termos de <?php echo htmlspecialchars($taxonomy['label']); ?></h2>
        <a href="taxonomies.php?taxonomy_id=<?php echo $taxonomyId; ?>&act=ad" class="btn btn-success mb-3">Adicionar termo</a>
        <table class="table table-striped datatable">
            <thead><tr><th>Nome</th></tr></thead>
            <tbody>
            <?php foreach ($terms as $term): ?>
                <tr><td><?php echo htmlspecialchars($term['name']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <a href="taxonomies.php" class="btn btn-secondary">Voltar</a>
    <?php endif; ?>
<?php else: ?>
    <?php if ($act === 'ad' || $editing): ?>
        <h2 class="mt-3"><?php echo $editing ? 'Editar taxonomia' : 'Criar nova taxonomia'; ?></h2>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <div class="card p-3 mt-4">
            <form method="post" action="<?php echo $editing ? '?edit_id=' . $editing['id'] : '?act=ad'; ?>">
                <div class="mb-3">
                    <label class="form-label" for="name">Slug</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="label">Rótulo</label>
                    <input type="text" class="form-control" id="label" name="label" value="<?php echo htmlspecialchars($editing['label'] ?? ''); ?>" required>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Guardar' : 'Criar'; ?></button>
                <a href="taxonomies.php" class="btn btn-secondary">Voltar</a>
            </form>
        </div>
    <?php else: ?>
        <h2 class="mt-3">Taxonomias</h2>
        <a href="taxonomies.php?act=ad" class="btn btn-success mb-3">Adicionar taxonomia</a>
        <table class="table table-striped datatable">
            <thead><tr><th>Slug</th><th>Rótulo</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($taxonomies as $tax): ?>
                <tr>
                    <td><?php echo htmlspecialchars($tax['name']); ?></td>
                    <td><?php echo htmlspecialchars($tax['label']); ?></td>
                    <td>
                        <a href="taxonomies.php?taxonomy_id=<?php echo $tax['id']; ?>">Gerir termos</a> |
                        <a href="taxonomies.php?edit_id=<?php echo $tax['id']; ?>">Editar</a> |
                        <a href="taxonomies.php?delete_id=<?php echo $tax['id']; ?>" onclick="return confirm('Eliminar esta taxonomia?');">Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

