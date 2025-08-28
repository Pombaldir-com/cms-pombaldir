<?php
/**
 * Formulário para criar ou editar um tipo de conteúdo.
 *
 * Requer autenticação para aceder.
 */
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

$error = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$editing = $id ? getContentType($id) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $label = isset($_POST['label']) ? trim($_POST['label']) : '';
    $icon  = isset($_POST['icon']) ? trim($_POST['icon']) : 'fa fa-file-text';
    $showAuthor = isset($_POST['show_author']);
    $showDate   = isset($_POST['show_date']);

    if ($name !== '' && $label !== '') {
        if ($id) {
            updateContentType($id, $name, $label, $icon === '' ? 'fa fa-file-text' : $icon, $showAuthor, $showDate);
        } else {
            createContentType($name, $label, $icon === '' ? 'fa fa-file-text' : $icon, $showAuthor, $showDate);
        }
        header('Location: content_types.php');
        exit;
    } else {
        $error = 'Nome e rótulo são obrigatórios.';
    }
}

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <h2 class="mt-3"><?php echo $editing ? 'Editar tipo de conteúdo' : 'Criar novo tipo de conteúdo'; ?></h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="post" action="<?php echo $editing ? '?id=' . $editing['id'] : ''; ?>">
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
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="show_author" name="show_author" <?php echo !empty($editing['show_author']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="show_author">Mostrar autor na listagem</label>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="show_date" name="show_date" <?php echo !empty($editing['show_date']) ? 'checked' : ''; ?>>
            <label class="form-check-label" for="show_date">Mostrar data na listagem</label>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Atualizar' : 'Criar'; ?></button>
    </form>
</div>
<?php
require_once __DIR__ . '/footer.php';
?>
