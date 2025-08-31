<?php
/**
 * Gestão de tipos de conteúdo.
 *
 * Este ficheiro reúne a listagem dos tipos de conteúdo, o formulário
 * de criação/edição e a associação de taxonomias num único local.
 */

require_once __DIR__ . '/functions.php';
startSession();
requireLogin();
requireRole(2);

$error   = '';
$action  = $_GET['act'] ?? '';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;          // para editar
$typeTax = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0; // para taxonomias
$delId   = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;

if ($delId) {
    $associated = countContentByContentType($delId);
    deleteContentType($delId);
    $params = 'deleted=1';
    if ($associated) {
        $params .= '&associated=' . $associated;
    }
    header('Location: ' . BASE_URL . 'content-types?' . $params);
    exit;
}

// -- Taxonomias associadas a um tipo de conteúdo --------------------------
if ($typeTax) {
    $type = getContentType($typeTax);
    if (!$type) {
        echo "Tipo de conteúdo inválido.";
        exit;
    }

    $allTaxonomies = getTaxonomies();
    $fields = getCustomFields($typeTax);
    $usedTaxonomies = [];
    foreach ($fields as $field) {
        if ($field['type'] === 'taxonomy') {
            $usedTaxonomies[] = (int)$field['options'];
        }
    }
    $allTaxonomies = array_filter($allTaxonomies, fn($t) => !in_array((int)$t['id'], $usedTaxonomies));
    $current = array_map(fn($t) => (int)$t['id'], getTaxonomiesForContentType($typeTax));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selected = isset($_POST['taxonomies']) ? array_map('intval', (array)$_POST['taxonomies']) : [];
        setContentTypeTaxonomies($typeTax, $selected);
        header('Location: ' . BASE_URL . 'content-types/taxonomies/' . $typeTax);
        exit;
    }

    require_once __DIR__ . '/header.php';
    ?>
    <div class="container-fluid">
        <h2 class="mt-3">Taxonomias para <?php echo htmlspecialchars($type['label']); ?></h2>
        <form method="post">
            <?php foreach ($allTaxonomies as $tax): ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="tax_<?php echo $tax['id']; ?>" name="taxonomies[]" value="<?php echo $tax['id']; ?>" <?php echo in_array($tax['id'], $current) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="tax_<?php echo $tax['id']; ?>"><?php echo htmlspecialchars($tax['label']); ?></label>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-primary mt-3"><i class="fa fa-save"></i> Guardar</button>
            <a href="<?= BASE_URL ?>content-types" class="btn btn-secondary mt-3 ms-2"><i class="fa fa-arrow-left"></i> Voltar</a>
        </form>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    return;
}

// -- Formulário de criação/edição ----------------------------------------
if ($action === 'ad' || $id) {
    $editing = $id ? getContentType($id) : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name  = trim($_POST['name'] ?? '');
        $label = trim($_POST['label'] ?? '');
        $icon  = trim($_POST['icon'] ?? 'fa fa-file-text');
        $showAuthor = isset($_POST['show_author']);
        $showDate   = isset($_POST['show_date']);
        $sortOrder  = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;

        if ($name !== '' && $label !== '') {
            if ($id) {
                updateContentType($id, $name, $label, $icon === '' ? 'fa fa-file-text' : $icon, $showAuthor, $showDate, $sortOrder);
            } else {
                createContentType($name, $label, $icon === '' ? 'fa fa-file-text' : $icon, $showAuthor, $showDate, $sortOrder);
            }
            header('Location: ' . BASE_URL . 'content-types');
            exit;
        } else {
            $error = 'Nome e rótulo são obrigatórios.';
        }
    }

    require_once __DIR__ . '/header.php';
    ?>
    <div class="container-fluid">
        <h2 class="mt-3"><?php echo $id ? 'Editar tipo de conteúdo' : 'Criar novo tipo de conteúdo'; ?></h2>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post" action="">
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
                <input type="text" class="form-control" id="icon" name="icon" value="<?php echo htmlspecialchars($editing['icon'] ?? ''); ?>" placeholder="fa fa-file-text">
            </div>
            <div class="mb-3">
                <label class="form-label" for="sort_order">Ordem no menu</label>
                <input type="number" class="form-control" id="sort_order" name="sort_order" value="<?php echo htmlspecialchars($editing['sort_order'] ?? 0); ?>">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="show_author" name="show_author" <?php echo !empty($editing['show_author']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="show_author">Mostrar autor na listagem</label>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="show_date" name="show_date" <?php echo !empty($editing['show_date']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="show_date">Mostrar data na listagem</label>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa <?php echo $id ? 'fa-save' : 'fa-plus'; ?>"></i> <?php echo $id ? 'Atualizar' : 'Criar'; ?></button>
            <a href="<?= BASE_URL ?>content-types" class="btn btn-secondary ms-2"><i class="fa fa-arrow-left"></i> Voltar</a>
        </form>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    return;
}

// -- Listagem -------------------------------------------------------------
$types = getContentTypes();
$deleted = isset($_GET['deleted']);
$associated = isset($_GET['associated']) ? (int)$_GET['associated'] : 0;

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <?php if ($deleted): ?>
        <div class="alert alert-warning mt-3">
            <?php if ($associated): ?>
                Este tipo de conteúdo tinha <?php echo $associated; ?> conteúdos associados e foi removido.
            <?php else: ?>
                Tipo de conteúdo removido.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <h2>Tipos de Conteúdo</h2>
    <table class="table table-striped datatable" data-no-sort-last="true">
        <thead><tr><th>Ordem</th><th>Rótulo</th><th>Slug</th><th data-orderable="false">Ícone</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($types as $type): ?>
            <?php
                $cnt = countContentByContentType($type['id']);
                $confirmMsg = $cnt ? "Eliminar este tipo? Existem $cnt conteúdos associados." : 'Eliminar este tipo?';
            ?>
            <tr data-id="<?php echo $type['id']; ?>">
                <td><?php echo htmlspecialchars($type['sort_order']); ?></td>
                <td><?php echo htmlspecialchars($type['label']); ?></td>
                <td><?php echo htmlspecialchars($type['name']); ?></td>
                <td><i class="<?php echo htmlspecialchars($type['icon']); ?>"></i></td>
                <td>
                    <a href="<?= BASE_URL . 'fields/' . $type['id']; ?>" class="btn btn-sm btn-info"><i class="fa fa-list-alt"></i> Campos</a>
                    <a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($type['name'])); ?>/add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Adicionar</a>
                    <a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($type['name'])); ?>" class="btn btn-sm btn-secondary"><i class="fa fa-list"></i> Listar</a>
                    <a href="<?= BASE_URL ?>content-types/taxonomies/<?php echo $type['id']; ?>" class="btn btn-sm btn-warning"><i class="fa fa-tags"></i> Taxonomias</a>
                    <a href="<?= BASE_URL ?>content-types/edit/<?php echo $type['id']; ?>" class="btn btn-sm btn-primary"><i class="fa fa-pencil"></i> Editar</a>
                    <a href="<?= BASE_URL ?>content-types?delete_id=<?php echo $type['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES); ?>');"><i class="fa fa-trash"></i> Eliminar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="mt-3">
                <a href="<?= BASE_URL ?>dashboard" class="btn btn-secondary ms-2"><i class="fa fa-arrow-left"></i> Voltar</a>

        <a class="btn btn-success" href="<?= BASE_URL ?>content-types/add"><i class="fa fa-plus"></i> Criar novo tipo de conteúdo</a>
    </div>
</div>
<?php
require_once __DIR__ . '/footer.php';

