<?php
// List and manage users. Accessible only to role 1 (superadmin) and 2 (administrator).
require_once __DIR__ . '/functions.php';
startSession();
requireRole(2);

// Handle user deletion if requested
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    if ($id !== (int)($_SESSION['user_id'] ?? 0)) { // prevent deleting self
        deleteUser($id);
    }
    header('Location: ' . BASE_URL . 'users');
    exit;
}

$users = getUsers();
require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <h2>Utilizadores</h2>
    <a href="<?= BASE_URL ?>user/add" class="btn btn-primary mb-3"><i class="fa fa-plus"></i> Adicionar Utilizador</a>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Utilizador</th>
                <th>Nome</th>
                <th>Nível</th>
                <th>Ações</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= $u['id']; ?></td>
                <td><?= htmlspecialchars($u['username']); ?></td>
                <td><?= htmlspecialchars($u['name'] ?? ''); ?></td>
                <td><?php
                    switch ($u['role']) {
                        case 1: echo 'Superadmin'; break;
                        case 2: echo 'Administrador'; break;
                        default: echo 'Utilizador';
                    }
                ?></td>
                <td>
                    <a href="<?= BASE_URL ?>user/edit/<?= $u['id']; ?>" class="btn btn-sm btn-secondary">Editar</a>
                    <?php if ($u['id'] !== ($user['id'] ?? 0)): ?>
                    <a href="<?= BASE_URL ?>user/delete/<?= $u['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Eliminar utilizador?');">Eliminar</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
