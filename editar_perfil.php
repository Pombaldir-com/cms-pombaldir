<?php
/**
 * Página para editar o perfil do utilizador.
 */
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

$user = currentUser();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $photoPath = null;

    if (!empty($_FILES['photo']['tmp_name'])) {
        $uploadDir = __DIR__ . '/assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $filename = uniqid('photo_') . '-' . basename($_FILES['photo']['name']);
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
            $photoPath = 'assets/uploads/' . $filename;
        }
    }

    updateUserProfile($user['id'], $name, $email, $phone, $photoPath);
    $success = 'Perfil atualizado com sucesso.';
    $user = currentUser();
}

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <h2>Editar Perfil</h2>
    <?php if ($success): ?>
        <div class="alert alert-success" role="alert">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="w-50">
        <div class="mb-3">
            <label for="photo" class="form-label">Foto</label><br>
            <?php if (!empty($user['photo'])): ?>
                <img src="<?php echo htmlspecialchars($user['photo']); ?>" alt="Foto de perfil" class="img-thumbnail mb-2" style="max-width: 150px;">
            <?php endif; ?>
            <input type="file" class="form-control" id="photo" name="photo">
        </div>
        <div class="mb-3">
            <label for="name" class="form-label">Nome</label>
            <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>">
        </div>
        <div class="mb-3">
            <label for="email" class="form-label">E-mail</label>
            <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label">Telefone</label>
            <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>
<?php
require_once __DIR__ . '/footer.php';
