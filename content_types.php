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

// Tratamento da submissão do formulário para criar um novo tipo
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    if ($name !== '') {
        createContentType($name);
        header('Location: content_types.php');
        exit;
    } else {
        $error = 'O nome é obrigatório.';
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
        <thead><tr><th>Nome</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($types as $type): ?>
            <tr>
                <td><?php echo htmlspecialchars($type['name']); ?></td>
                <td>
                    <a href="custom_fields.php?type_id=<?php echo $type['id']; ?>">Campos</a> |
                    <a href="add_content.php?type_id=<?php echo $type['id']; ?>">Adicionar</a> |
                    <a href="list_content.php?type_id=<?php echo $type['id']; ?>">Listar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card p-3 mt-4">
        <h5>Criar novo tipo de conteúdo</h5>
        <form method="post" action="">
            <div class="mb-3">
                <label class="form-label" for="name">Nome</label>
                <input type="text" class="form-control" id="name" name="name" required>
            </div>
            <button type="submit" class="btn btn-primary">Criar</button>
        </form>
    </div>
</div>

<?php
// Include the shared footer to close the template structure
require_once __DIR__ . '/footer.php';