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

// Redirect to the creation form when requested via act=ad for backward compatibility
$action = isset($_GET['act']) ? $_GET['act'] : '';
if ($action === 'ad') {
    header('Location: content_type_form.php');
    exit;
}

// Handle deletion of a content type
$delId = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;

if ($delId) {
    $associated = countContentByContentType($delId);
    deleteContentType($delId);
    $params = 'deleted=1';
    if ($associated) {
        $params .= '&associated=' . $associated;
    }
    header('Location: content_types.php?' . $params);
    exit;
}

// Recupera todos os tipos de conteúdo
$types = getContentTypes();
$deleted = isset($_GET['deleted']);
$associated = isset($_GET['associated']) ? (int)$_GET['associated'] : 0;

// Include the shared header (navigation/sidebar) from the template
require_once __DIR__ . '/header.php';
?>

<!-- Conteúdo da página -->
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
        <a class="btn btn-primary" href="content_type_form.php"><i class="fa fa-plus"></i> Criar novo tipo de conteúdo</a>
 
    <table class="table table-striped datatable" data-no-sort-last="true">
        <thead><tr><th>Rótulo</th><th>Slug</th><th>Ícone</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($types as $type): ?>
            <?php
                $cnt = countContentByContentType($type['id']);
                $confirmMsg = $cnt ? "Eliminar este tipo? Existem $cnt conteúdos associados." : 'Eliminar este tipo?';
            ?>
            <tr>
                <td><?php echo htmlspecialchars($type['label']); ?></td>
                <td><?php echo htmlspecialchars($type['name']); ?></td>
                <td><i class="<?php echo htmlspecialchars($type['icon']); ?>"></i></td>
                <td>
                    <a href="custom_fields.php?type_id=<?php echo $type['id']; ?>" class="btn btn-sm btn-info"><i class="fa fa-list-alt"></i> Campos</a>
                    <a href="add_content.php?type_id=<?php echo $type['id']; ?>" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Adicionar</a>
                    <a href="list_content.php?type_id=<?php echo $type['id']; ?>" class="btn btn-sm btn-secondary"><i class="fa fa-list"></i> Listar</a>
                    <a href="content_type_taxonomies.php?type_id=<?php echo $type['id']; ?>" class="btn btn-sm btn-warning"><i class="fa fa-tags"></i> Taxonomias</a>
                    <a href="content_type_form.php?id=<?php echo $type['id']; ?>" class="btn btn-sm btn-primary"><i class="fa fa-pencil"></i> Editar</a>
                    <a href="content_types.php?delete_id=<?php echo $type['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES); ?>');"><i class="fa fa-trash"></i> Eliminar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
// Include the shared footer to close the template structure
require_once __DIR__ . '/footer.php';
