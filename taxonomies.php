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
    $termEditId   = isset($_GET['term_edit_id']) ? (int)$_GET['term_edit_id'] : 0;
    $termDeleteId = isset($_GET['term_delete_id']) ? (int)$_GET['term_delete_id'] : 0;
    $editingTerm  = $termEditId ? getTerm($termEditId) : null;
    if ($editingTerm && $editingTerm['taxonomy_id'] != $taxonomyId) {
        $editingTerm = null;
    }

    if ($termDeleteId) {
        deleteTerm($termDeleteId);
        header('Location: taxonomies.php?taxonomy_id=' . $taxonomyId);
        exit;
    }

    if ($act === 'ad' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['term_name'])) {
        $termName = trim($_POST['term_name']);
        if ($termName !== '') {
            if ($editingTerm) {
                updateTerm($editingTerm['id'], $termName);
            } else {
                createTerm($taxonomyId, $termName);
            }
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
        $associated = countContentByTaxonomy($delId);
        deleteTaxonomy($delId);
        $params = 'deleted=1';
        if ($associated) {
            $params .= '&associated=' . $associated;
        }
        header('Location: taxonomies.php?' . $params);
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
    $deleted = isset($_GET['deleted']);
    $associated = isset($_GET['associated']) ? (int)$_GET['associated'] : 0;
}

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
<?php if ($taxonomyId && $taxonomy): ?>
    <?php if ($act === 'ad'): ?>
        <h2 class="mt-3"><?php echo $editingTerm ? 'Editar termo' : 'Adicionar novo termo a ' . htmlspecialchars($taxonomy['label']); ?></h2>
        <div class="card p-3 mt-4">
            <form method="post" action="?taxonomy_id=<?php echo $taxonomyId; ?>&act=ad<?php echo $editingTerm ? '&term_edit_id=' . $editingTerm['id'] : ''; ?>">
                <div class="mb-3">
                    <label class="form-label" for="term_name">Nome</label>
                    <input type="text" class="form-control" id="term_name" name="term_name" value="<?php echo htmlspecialchars($editingTerm['name'] ?? ''); ?>" required>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa <?php echo $editingTerm ? 'fa-save' : 'fa-plus'; ?>"></i> <?php echo $editingTerm ? 'Guardar' : 'Adicionar'; ?></button>
                <a href="taxonomies.php?taxonomy_id=<?php echo $taxonomyId; ?>" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Voltar</a>
            </form>
        </div>
    <?php else: ?>
        <h2 class="mt-3">Termos de <?php echo htmlspecialchars($taxonomy['label']); ?></h2>
        <a href="taxonomies.php?taxonomy_id=<?php echo $taxonomyId; ?>&act=ad" class="btn btn-success mb-3"><i class="fa fa-plus"></i> Adicionar termo</a>
        <table class="table table-striped datatable">
            <thead><tr><th>Nome</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($terms as $term): ?>
                <tr>
                    <td><?php echo htmlspecialchars($term['name']); ?></td>
                    <td>
                        <a href="taxonomies.php?taxonomy_id=<?php echo $taxonomyId; ?>&act=ad&term_edit_id=<?php echo $term['id']; ?>" class="btn btn-sm btn-primary"><i class="fa fa-pencil"></i> Editar</a>
                        <a href="taxonomies.php?taxonomy_id=<?php echo $taxonomyId; ?>&term_delete_id=<?php echo $term['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Eliminar este termo?');"><i class="fa fa-trash"></i> Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <a href="taxonomies.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Voltar</a>
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
                <button type="submit" class="btn btn-primary"><i class="fa <?php echo $editing ? 'fa-save' : 'fa-plus'; ?>"></i> <?php echo $editing ? 'Guardar' : 'Criar'; ?></button>
                <a href="taxonomies.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Voltar</a>
            </form>
        </div>
    <?php else: ?>
        <?php if ($deleted): ?>
            <div class="alert alert-warning mt-3">
                <?php if ($associated): ?>
                    Esta taxonomia tinha <?php echo $associated; ?> conteúdos associados e foi removida.
                <?php else: ?>
                    Taxonomia removida.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <h2 class="mt-3">Taxonomias</h2>
        <a href="taxonomies.php?act=ad" class="btn btn-success mb-3"><i class="fa fa-plus"></i> Adicionar taxonomia</a>
        <table class="table table-striped datatable">
            <thead><tr><th>Rótulo</th><th>Slug</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($taxonomies as $tax): ?>
                <?php
                    $cnt = countContentByTaxonomy($tax['id']);
                    $confirmMsg = $cnt ? "Eliminar esta taxonomia? Existem $cnt conteúdos associados." : 'Eliminar esta taxonomia?';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($tax['label']); ?></td>
                    <td><?php echo htmlspecialchars($tax['name']); ?></td>
                    <td>
                        <a href="taxonomies.php?taxonomy_id=<?php echo $tax['id']; ?>" class="btn btn-sm btn-info"><i class="fa fa-tags"></i> Gerir termos</a>
                        <a href="taxonomies.php?edit_id=<?php echo $tax['id']; ?>" class="btn btn-sm btn-secondary"><i class="fa fa-pencil"></i> Editar</a>
                        <a href="taxonomies.php?delete_id=<?php echo $tax['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES); ?>');"><i class="fa fa-trash"></i> Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

