<?php
/**
 * Gestão de tipos de conteúdo.
 *
 * Esta página lista todos os tipos de conteúdo existentes.
 */
// Load helper functions and start session.  The session and login check
// will also be enforced again in header.php, but we start the session
// here to allow form handling before any output.
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

// Handle deletion of a content type
$delId = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;

if ($delId) {
    deleteContentType($delId);
    header('Location: content_types.php');
    exit;
}

// Recupera todos os tipos de conteúdo
$types = getContentTypes();

// Include the shared header (navigation/sidebar) from the template
require_once __DIR__ . '/header.php';
?>

<!-- Conteúdo da página -->
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mt-3">
        <h2>Tipos de Conteúdo</h2>
        <a class="btn btn-primary" href="content_type_form.php">Criar novo tipo de conteúdo</a>
    </div>
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
                    <a href="content_type_form.php?id=<?php echo $type['id']; ?>">Editar</a> |
                    <a href="content_types.php?delete_id=<?php echo $type['id']; ?>" onclick="return confirm('Eliminar este tipo?');">Eliminar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
// Include the shared footer to close the template structure
require_once __DIR__ . '/footer.php';