<?php
// Unified user management: list users, add/edit users, and edit profile.
require_once __DIR__ . '/functions.php';
startSession();
$csrfToken = generateCsrfToken();

$profileMode = isset($_GET['profile']);
$action = $_GET['action'] ?? 'list';
$errors = [];
$listErrors = [];
$slug = getCompanySlug();

if ($profileMode) {
    requireLogin();
    $action = 'edit';
    $id = $_SESSION['user_id'] ?? null;
    $selfEdit = true;
} else {
    requireRole(2);
    $selfEdit = false;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            $listErrors[] = 'Token CSRF inválido.';
            $csrfToken = generateCsrfToken(true);
        } else {
            $idDel = (int)$_POST['delete_user_id'];
            if ($idDel === (int)($_SESSION['user_id'] ?? 0)) {
                $listErrors[] = 'Não pode eliminar o próprio utilizador.';
                $csrfToken = generateCsrfToken(true);
            } else {
                deleteUser($idDel);
                header('Location: ' . BASE_URL . 'users');
                exit;
            }
        }
    }

    $id = $action === 'edit' ? (int)($_GET['id'] ?? 0) : null;
}

if ($action === 'list') {
    $user = currentUser();
    $users = getUsers();
    if (($user['id'] ?? 0) !== 1) {
        $users = array_filter($users, fn($u) => $u['id'] !== 1);
    }
    require_once __DIR__ . '/header.php';
    ?>
<div class="container-fluid">
    <h2>Utilizadores</h2>
    <?php foreach ($listErrors as $err): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
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
                    <a href="<?= BASE_URL ?>users/edit/<?= $u['id']; ?>" class="btn btn-sm btn-secondary">Editar</a>
                    <?php if ($u['id'] !== ($user['id'] ?? 0)): ?>
                    <form method="post" action="<?= BASE_URL ?>users" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="delete_user_id" value="<?= $u['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Eliminar utilizador?');">Eliminar</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <a href="<?= BASE_URL ?>users/add" class="btn btn-primary mt-3"><i class="fa fa-plus"></i> Adicionar Utilizador</a>
</div>
<?php
    require_once __DIR__ . '/footer.php';
    return;
}

$editing = $id !== null;
$userData = $editing ? getUserById($id) : ['username' => '', 'name' => '', 'email' => '', 'phone' => '', 'role' => 3, 'photo' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }
    $csrfToken = generateCsrfToken();
    $username = $editing ? $userData['username'] : trim($_POST['username'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $selfEdit ? $userData['role'] : (int)($_POST['role'] ?? 3);
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['password_confirm'] ?? '');
    $photoPath = $userData['photo'] ?? null;

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
        $fileTmp  = $_FILES['photo']['tmp_name'];
        $fileSize = $_FILES['photo']['size'] ?? 0;
        $extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowedExt = ['png', 'jpg', 'jpeg'];

        if ($fileSize > 2 * 1024 * 1024) {
            $errors[] = 'A foto excede 2 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($fileTmp);
            $allowedMime = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

            if (!in_array($extension, $allowedExt, true) || !array_key_exists($mimeType, $allowedMime)) {
                $errors[] = 'Formato de imagem inválido.';
            } else {
                $year = date('Y');
                $month = date('m');
                $uploadDir = __DIR__ . '/uploads/' . $slug . '/' . $year . '/' . $month . '/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $filename = bin2hex(random_bytes(16)) . '.' . $allowedMime[$mimeType];
                $targetPath = $uploadDir . $filename;

                $image = ($mimeType === 'image/png') ? imagecreatefrompng($fileTmp) : imagecreatefromjpeg($fileTmp);
                if ($image !== false) {
                    $maxDim = 800;
                    $width = imagesx($image);
                    $height = imagesy($image);
                    if ($width > $maxDim || $height > $maxDim) {
                        $ratio = min($maxDim / $width, $maxDim / $height);
                        $newWidth = (int)($width * $ratio);
                        $newHeight = (int)($height * $ratio);
                        $newImage = imagecreatetruecolor($newWidth, $newHeight);
                        if ($mimeType === 'image/png') {
                            imagealphablending($newImage, false);
                            imagesavealpha($newImage, true);
                        }
                        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                        imagedestroy($image);
                        $image = $newImage;
                    }
                    $saved = ($mimeType === 'image/png') ? imagepng($image, $targetPath) : imagejpeg($image, $targetPath, 90);
                    imagedestroy($image);
                } else {
                    $saved = false;
                }

                if ($saved) {
                    $photoPath = 'uploads/' . $slug . '/' . $year . '/' . $month . '/' . $filename;
                    $userData['photo'] = $photoPath;
                } else {
                    $errors[] = 'Erro ao guardar a foto.';
                }
            }
        }
    }

    if (!$errors) {
        if ($editing) {
            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
            updateUser($id, $hash, $name, $email, $phone, $role, $photoPath);
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            createUser($username, $hash, $name, $email, $phone, $role, $photoPath);
        }
        $redirect = $profileMode ? 'users/profile' : 'users';
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
        <div class="mb-3">
            <label for="username" class="form-label">Utilizador</label>
            <?php if ($editing): ?>
                <input type="text" class="form-control" id="username" value="<?= htmlspecialchars($userData['username']); ?>" disabled>
            <?php else: ?>
                <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($userData['username']); ?>" required>
            <?php endif; ?>
        </div>
        <div class="mb-3">
            <label for="photo" class="form-label">Foto</label><br>
            <?php if (!empty($userData['photo'])):
                $photo = $userData['photo'];
                if (strpos($photo, 'uploads/' . $slug . '/') !== 0) {
                    $photo = 'uploads/' . $slug . '/' . ltrim($photo, '/');
                }
            ?>
                <img src="<?= htmlspecialchars($photo); ?>" alt="" class="img-thumbnail mb-2" style="max-width: 150px;">
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

