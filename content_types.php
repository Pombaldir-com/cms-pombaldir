<?php
/**
 * Gestão de tipos de conteúdo.
 *
 * Esta página lista todos os tipos de conteúdo existentes e permite
 * criar novos tipos. Requer autenticação para aceder.
 */
// Load helper functions and start session.  The session and login check
// will also be enforced again in header.php, but we start the session
// here to allow form handling before any output.
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

// Handling edit/delete actions and form submissions
$error   = '';
$editId  = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$delId   = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;
$editing = $editId ? getContentType($editId) : null;

if ($delId) {
    deleteContentType($delId);
    header('Location: content_types.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = isset($_POST['name']) ? trim($_POST['name']) : '';
    $label = isset($_POST['label']) ? trim($_POST['label']) : '';

    $icon  = isset($_POST['icon']) ? trim($_POST['icon']) : 'fa fa-file-text';
    if ($name !== '' && $label !== '') {
        createContentType($name, $label, $icon === '' ? 'fa fa-file-text' : $icon);

        header('Location: content_types.php');
        exit;
    } else {
        $error = 'Nome e rótulo são obrigatórios.';
    }
}

// Recupera todos os tipos de conteúdo
$types = getContentTypes();

// Include the shared header (navigation/sidebar) from the template
require_once __DIR__ . '/header.php';
?>

<!-- Conteúdo da página -->
<div class="container-fluid">
    <h2 class="mt-3">Tipos de Conteúdo</h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"> <?php echo htmlspecialchars($error); ?> </div>
    <?php endif; ?>
    <table class="table table-striped datatable">
        <thead><tr><th>Slug</th><th>Rótulo</th><th>Ícone</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($types as $type): ?>
            <tr>
                <td><?php echo htmlspecialchars($type['name']); ?></td>
                <td><?php echo htmlspecialchars($type['label']); ?></td>

                <td><i class="<?php echo htmlspecialchars($type['icon']); ?>"></i></td>

                <td>
                    <a href="custom_fields.php?type_id=<?php echo $type['id']; ?>">Campos</a> |
                    <a href="add_content.php?type_id=<?php echo $type['id']; ?>">Adicionar</a> |
                    <a href="list_content.php?type_id=<?php echo $type['id']; ?>">Listar</a> |
                    <a href="content_type_taxonomies.php?type_id=<?php echo $type['id']; ?>">Taxonomias</a> |
                    <a href="content_types.php?edit_id=<?php echo $type['id']; ?>">Editar</a> |
                    <a href="content_types.php?delete_id=<?php echo $type['id']; ?>" onclick="return confirm('Eliminar este tipo?');">Eliminar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card p-3 mt-4">
        <h5><?php echo $editing ? 'Editar tipo de conteúdo' : 'Criar novo tipo de conteúdo'; ?></h5>
        <form method="post" action="<?php echo $editing ? '?edit_id=' . $editing['id'] : ''; ?>">
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

            <div class="mb-3">
                <label class="form-label" for="icon">Ícone (classe CSS)</label>
                <input type="text" class="form-control" id="icon" name="icon" placeholder="fa fa-file-text">
            </div>
            <button type="submit" class="btn btn-primary">Criar</button>

        </form>
    </div>
</div>

<?php
// Include the shared footer to close the template structure
require_once __DIR__ . '/footer.php';