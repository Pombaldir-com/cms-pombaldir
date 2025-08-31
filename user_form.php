<?php
// Form to add or edit a user.
require_once __DIR__ . '/functions.php';
startSession();

// When "profile" is set we are editing the logged in user's own profile.
$profileMode = isset($_GET['profile']);
if ($profileMode) {
    requireLogin();
    $id = $_SESSION['user_id'] ?? null;
} else {
    requireRole(2);
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
}

$editing = $id !== null;
$userData = $editing ? getUserById($id) : ['username' => '', 'name' => '', 'email' => '', 'phone' => '', 'role' => 3, 'photo' => ''];
$selfEdit = $profileMode;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $selfEdit ? $userData['role'] : (int)($_POST['role'] ?? 3);
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['password_confirm'] ?? '');
    $photoPath = $userData['photo'] ?? null;

    // Preserve submitted data to repopulate the form in case of errors
    $userData = [
        'username' => $username,
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'role' => $role,
        'photo' => $photoPath,
    ];

    if (!$editing && $password === '') {
        $errors[] = 'A password é obrigatória.';
    }

    if ($password !== '') {
        if ($password !== $confirmPassword) {
            $errors[] = 'As passwords não coincidem.';
        } elseif (!isStrongPassword($password)) {
            $errors[] = 'A password deve ter pelo menos 8 caracteres e incluir letras maiúsculas, minúsculas e números.';
        }
    }

    if (!empty($_FILES['photo']['tmp_name'])) {
        $uploadDir = __DIR__ . '/assets/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $filename = uniqid('photo_') . '-' . basename($_FILES['photo']['name']);
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
            $photoPath = 'assets/uploads/' . $filename;
            $userData['photo'] = $photoPath;
        }
    }

    if (!$errors) {
        if ($editing) {
            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
            updateUser($id, $username, $hash, $name, $email, $phone, $role, $photoPath);
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            createUser($username, $hash, $name, $email, $phone, $role, $photoPath);
        }
        $redirect = $profileMode ? 'editar-perfil' : 'users';
        header('Location: ' . BASE_URL . $redirect);
        exit;
    }
}

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <h2><?= $editing ? 'Editar Utilizador' : 'Adicionar Utilizador'; ?></h2>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
    <form method="post" enctype="multipart/form-data" class="w-50">
        <div class="mb-3">
            <label for="username" class="form-label">Utilizador</label>
            <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($userData['username']); ?>" required>
        </div>
        <div class="mb-3">
            <label for="photo" class="form-label">Foto</label><br>
            <?php if (!empty($userData['photo'])): ?>
                <img src="<?= htmlspecialchars($userData['photo']); ?>" alt="" class="img-thumbnail mb-2" style="max-width: 150px;">
            <?php endif; ?>
            <input type="file" class="form-control" id="photo" name="photo">
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="name" class="form-label">Nome</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($userData['name']); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="email" class="form-label">E-mail</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($userData['email']); ?>">
            </div>
        </div>
        <div class="mb-3">
            <label for="phone" class="form-label">Telefone</label>
            <input type="text" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($userData['phone']); ?>">
        </div>
        <?php if (!$selfEdit): ?>
        <div class="mb-3">
            <label for="role" class="form-label">Nível</label>
            <?php if ($editing && $id == 1): ?>
                <input type="text" class="form-control" value="Superadmin" disabled>
                <input type="hidden" name="role" value="1">
            <?php else: ?>
                <select class="form-control" id="role" name="role">
                    <option value="1" <?= $userData['role'] == 1 ? 'selected' : ''; ?>>Superadmin</option>
                    <option value="2" <?= $userData['role'] == 2 ? 'selected' : ''; ?>>Administrador</option>
                    <option value="3" <?= $userData['role'] == 3 ? 'selected' : ''; ?>>Utilizador</option>
                </select>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="mb-3">
            <label for="password" class="form-label">Password <?= $editing ? '(deixe em branco para manter)' : ''; ?></label>
            <input type="password" class="form-control" id="password" name="password" <?= $editing ? '' : 'required'; ?>>
        </div>
        <div class="mb-3">
            <label for="password_confirm" class="form-label">Confirmar Password <?= $editing ? '(deixe em branco para manter)' : ''; ?></label>
            <input type="password" class="form-control" id="password_confirm" name="password_confirm" <?= $editing ? '' : 'required'; ?>>
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
