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
    if (($user['role'] ?? 3) > 2) {
        $users = array_filter($users, fn($u) => $u['id'] !== 1);
    }
    $useSelect2 = false;
    $useDataTables = true;
    require_once __DIR__ . '/header.php';
    $roleLabels = [
        1 => ['Superadmin', 'bg-danger'],
        2 => ['Administrador', 'bg-warning text-dark'],
        3 => ['Utilizador', 'bg-secondary'],
    ];
    ?>
<div class="page-title">
    <div class="title_left">
        <h3>Utilizadores <small>Gestão de acessos e perfis</small></h3>
    </div>
</div>
<div class="clearfix"></div>

<?php foreach ($listErrors as $err): ?>
<div class="alert alert-danger alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars($err); ?>
</div>
<?php endforeach; ?>
<?php if ($usersFlash = pullSessionFlash('users')): ?>
<div class="alert alert-<?= htmlspecialchars((string) ($usersFlash['type'] ?? 'info')); ?> alert-dismissible" role="alert">
    <button type="button" class="close" data-bs-dismiss="alert" aria-label="Fechar"><span aria-hidden="true">&times;</span></button>
    <?= htmlspecialchars((string) ($usersFlash['message'] ?? '')); ?>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-users"></i> Lista de utilizadores <small><?= count($users); ?> registo<?= count($users) === 1 ? '' : 's'; ?></small></h2>
                <ul class="nav navbar-right panel_toolbox" style="min-width: auto;">
                    <li>
                        <a href="<?= BASE_URL ?>users/add" class="btn btn-success btn-sm" style="color: #fff;"><i class="fa fa-plus"></i> Adicionar utilizador</a>
                    </li>
                </ul>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <style>
                    .users-avatar {
                        display: inline-flex; align-items: center; justify-content: center;
                        width: 32px; height: 32px; border-radius: 50%; margin-right: 10px;
                        background: #2a3f54; color: #fff; font-size: 12px; font-weight: 600; flex-shrink: 0;
                    }
                    .users-identity { display: flex; align-items: center; }
                    .users-identity strong { color: #2a3f54; display: block; line-height: 1.2; }
                    .users-identity small { color: #73879c; }
                    .users-table td { vertical-align: middle !important; }
                    .users-actions { white-space: nowrap; text-align: right; }
                    .users-actions form { display: inline; }
                    .users-actions .btn { margin: 0 0 0 4px; }
                </style>
                <table class="table table-striped table-hover users-table datatable" data-order-column="0" data-order-dir="asc" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Utilizador</th>
                            <th>E-mail</th>
                            <th>Departamentos</th>
                            <th>Nível</th>
                            <th data-orderable="false"></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u):
                        [$roleLabel, $roleClass] = $roleLabels[(int) $u['role']] ?? $roleLabels[3];
                        $displayName = trim((string) ($u['name'] ?? '')) !== '' ? (string) $u['name'] : (string) $u['username'];
                        $initials = '';
                        foreach (array_slice(preg_split('/\s+/', trim($displayName)) ?: [], 0, 2) as $part) {
                            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
                        }
                    ?>
                        <tr>
                            <td data-order="<?= htmlspecialchars(mb_strtolower($displayName)); ?>">
                                <div class="users-identity">
                                    <span class="users-avatar"><?= htmlspecialchars($initials); ?></span>
                                    <div>
                                        <strong><?= htmlspecialchars($displayName); ?></strong>
                                        <small><?= htmlspecialchars($u['username']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?= !empty($u['email']) ? '<a href="mailto:' . htmlspecialchars($u['email']) . '">' . htmlspecialchars($u['email']) . '</a>' : '<span class="text-muted">—</span>'; ?></td>
                            <td><?= !empty($u['department_names']) ? htmlspecialchars($u['department_names']) : '<span class="text-muted">—</span>'; ?></td>
                            <td data-order="<?= (int) $u['role']; ?>"><span class="badge <?= $roleClass; ?>"><?= $roleLabel; ?></span></td>
                            <td class="users-actions">
                                <a href="<?= BASE_URL ?>users/edit/<?= $u['id']; ?>" class="btn btn-primary btn-xs" title="Editar"><i class="fa fa-pencil"></i> Editar</a>
                                <?php if ($u['id'] !== ($user['id'] ?? 0)): ?>
                                <form method="post" action="<?= BASE_URL ?>users">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="delete_user_id" value="<?= $u['id']; ?>">
                                    <button type="submit" class="btn btn-danger btn-xs" title="Eliminar" onclick="return confirm('Eliminar o utilizador <?= htmlspecialchars(addslashes($displayName), ENT_QUOTES); ?>?');"><i class="fa fa-trash"></i></button>
                                </form>
                                <?php else: ?>
                                <span title="Não pode eliminar o próprio utilizador"><button type="button" class="btn btn-danger btn-xs" disabled><i class="fa fa-trash"></i></button></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
    require_once __DIR__ . '/footer.php';
    return;
}

$editing = $id !== null;
$userData = $editing ? getUserById($id) : [
    'username' => '',
    'name' => '',
    'email' => '',
    'phone' => '',
    'role' => 3,
    'photo' => '',
    'department_term_ids' => [],
    'ai_chat_floating' => 0,
    'ai_read_only' => 1,
];
$departments = getDepartmentTerms();

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
    $departmentTermIds = $selfEdit ? ($userData['department_term_ids'] ?? []) : ($_POST['department_term_ids'] ?? []);
    if (!is_array($departmentTermIds)) {
        $departmentTermIds = [];
    }
    $departmentTermIds = array_values(array_filter(array_map('intval', $departmentTermIds), static fn($value) => $value > 0));
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
        'department_term_ids' => $departmentTermIds,
    ];

    // Convite por e-mail (link para o utilizador definir a password): por
    // defeito ao criar; na edicao serve para reenviar o acesso.
    $sendInvite = !$selfEdit && !empty($_POST['send_invite']);
    if ($sendInvite && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Para enviar o convite indique um e-mail válido.';
    }

    if (!$editing && $password === '' && !$sendInvite) {
        $errors[] = 'A password é obrigatória (ou envie o convite por e-mail para o utilizador a definir).';
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
        $aiChatFloating = isset($_POST['ai_chat_floating']) ? 1 : 0;
        $aiReadOnly = isset($_POST['ai_read_only']) ? 1 : 0;
        if ($editing) {
            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
            updateUser($id, $hash, $name, $email, $phone, $role, $photoPath, $departmentTermIds, $aiChatFloating, $aiReadOnly);
            $savedUserId = (int) $id;
        } else {
            // Sem password definida pelo admin: hash aleatorio inutilizavel
            // ate o utilizador a definir pelo link do convite.
            $hash = password_hash($password !== '' ? $password : bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $savedUserId = createUser($username, $hash, $name, $email, $phone, $role, $photoPath, $departmentTermIds, $aiChatFloating, $aiReadOnly);
        }
        if ($sendInvite) {
            try {
                $sentTo = sendUserInvitationEmail($savedUserId);
                setSessionFlash('users', ['type' => 'success', 'message' => 'Convite enviado para ' . $sentTo . '.']);
            } catch (Throwable $e) {
                setSessionFlash('users', ['type' => 'warning', 'message' => 'Utilizador guardado, mas o convite não foi enviado: ' . $e->getMessage()]);
            }
        }
        $redirect = $profileMode ? 'users/profile' : 'users';
        header('Location: ' . BASE_URL . $redirect);
        exit;
    }
}

$useSelect2 = true;
$useSwitchery = true;
require_once __DIR__ . '/header.php';
$appLogo = getSetting('app_logo', '');
if ($appLogo && !file_exists(__DIR__ . '/' . $appLogo)) {
    $appLogo = '';
}
$canManageUsers = ((int) (currentUser()['role'] ?? 3)) <= 2;
?>
<div class="page-title">
    <div class="title_left">
        <h3>
            <?= $profileMode ? 'O meu perfil' : ($editing ? 'Editar utilizador' : 'Adicionar utilizador'); ?>
            <small><?= $editing ? htmlspecialchars($userData['username']) : 'Novo acesso à aplicação'; ?></small>
        </h3>
    </div>
    <div class="title_right" style="text-align: right;">
        <?php if (!$profileMode || $canManageUsers): ?>
        <a href="<?= BASE_URL ?>users" class="btn btn-default btn-sm"><i class="fa fa-arrow-left"></i> Utilizadores</a>
        <?php endif; ?>
    </div>
</div>
<div class="clearfix"></div>

<?php if ($errors): ?>
<div class="alert alert-danger" role="alert">
    <?php foreach ($errors as $err): ?>
        <div><i class="fa fa-exclamation-triangle"></i> <?= htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form-horizontal form-label-left users-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
    <div class="row">
        <div class="col-md-8 col-sm-12">
            <div class="x_panel">
                <div class="x_title">
                    <h2><i class="fa fa-user"></i> Dados principais</h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="username" class="form-label">Utilizador <?= $editing ? '' : '<span class="text-danger">*</span>'; ?></label>
                            <?php if ($editing): ?>
                                <input type="text" class="form-control" id="username" value="<?= htmlspecialchars($userData['username']); ?>" disabled>
                            <?php else: ?>
                                <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($userData['username']); ?>" required autocomplete="off">
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="name" class="form-label">Nome</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($userData['name']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">E-mail</label>
                            <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($userData['email']); ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Telefone</label>
                            <input type="text" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($userData['phone']); ?>">
                        </div>
                        <?php if (!$selfEdit): ?>
                        <div class="col-md-6 mb-3">
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
                        <div class="col-md-6 mb-3">
                            <label for="department_term_ids" class="form-label">Departamentos</label>
                            <select class="form-control js-departments" id="department_term_ids" name="department_term_ids[]" multiple>
                                <?php foreach ($departments as $department):
                                    $termId = (int) $department['id'];
                                    $isSelected = in_array($termId, $userData['department_term_ids'] ?? [], true);
                                ?>
                                    <option value="<?= $termId; ?>" <?= $isSelected ? 'selected' : ''; ?>><?= htmlspecialchars($department['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="x_panel">
                <div class="x_title">
                    <h2><i class="fa fa-lock"></i> Acesso</h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <?php if (!$selfEdit):
                        $inviteChecked = $_SERVER['REQUEST_METHOD'] === 'POST' ? !empty($_POST['send_invite']) : !$editing;
                    ?>
                    <div class="users-switch-row">
                        <input type="checkbox" class="js-switch" id="send_invite" name="send_invite" value="1" <?= $inviteChecked ? 'checked' : ''; ?>>
                        <div>
                            <label><?= $editing ? 'Reenviar acesso por e-mail' : 'Enviar convite por e-mail'; ?></label>
                            <p class="text-muted">
                                Envia o NIF da empresa, o nome de utilizador e um link (válido 7 dias) para o utilizador definir a password.
                                <?= $editing ? '' : 'Com o convite ativo, a password abaixo é opcional.'; ?>
                            </p>
                        </div>
                    </div>
                    <div class="ln_solid"></div>
                    <?php endif; ?>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" autocomplete="new-password" placeholder="<?= $editing ? 'Deixe em branco para manter' : ''; ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="password_confirm" class="form-label">Confirmar password</label>
                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" autocomplete="new-password" placeholder="<?= $editing ? 'Deixe em branco para manter' : ''; ?>">
                        </div>
                    </div>
                    <p class="text-muted" style="margin: 0;"><i class="fa fa-info-circle"></i> Pelo menos 8 caracteres, com maiúsculas, minúsculas e números.</p>
                </div>
            </div>

            <div class="x_panel">
                <div class="x_title">
                    <h2><i class="fa fa-comments-o"></i> Assistente AI</h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div class="users-switch-row">
                        <input type="checkbox" class="js-switch" id="ai_chat_floating" name="ai_chat_floating" value="1" <?= !empty($userData['ai_chat_floating']) ? 'checked' : ''; ?>>
                        <div><label>Chat flutuante ativo</label></div>
                    </div>
                    <div class="users-switch-row">
                        <input type="checkbox" class="js-switch" id="ai_read_only" name="ai_read_only" value="1" <?= !empty($userData['ai_read_only']) ? 'checked' : ''; ?>>
                        <div><label>Modo seguro (apenas leitura)</label></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4 col-sm-12">
            <div class="x_panel">
                <div class="x_title">
                    <h2><i class="fa fa-camera"></i> Foto</h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content" style="text-align: center;">
                    <?php if (!empty($userData['photo'])):
                        $photo = $userData['photo'];
                        if (strpos($photo, 'uploads/' . $slug . '/') !== 0) {
                            $photo = 'uploads/' . $slug . '/' . ltrim($photo, '/');
                        }
                    ?>
                        <img src="<?= htmlspecialchars($photo); ?>" alt="" class="img-circle users-avatar-preview">
                    <?php elseif (!empty($appLogo)): ?>
                        <img src="<?= htmlspecialchars($appLogo); ?>" alt="" class="img-circle users-avatar-preview">
                    <?php else: ?>
                        <div class="users-avatar-placeholder"><i class="fa fa-user"></i></div>
                    <?php endif; ?>
                    <div style="text-align: left;">
                        <label for="photo" class="form-label">Carregar foto</label>
                        <input type="file" class="form-control" id="photo" name="photo" accept=".jpg,.jpeg,.png">
                        <p class="text-muted" style="margin: 6px 0 0;">JPG ou PNG, até 2 MB.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="ln_solid"></div>
    <div class="users-form-actions">
        <?php if (!$profileMode): ?>
        <a href="<?= BASE_URL ?>users" class="btn btn-default">Cancelar</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar</button>
    </div>
</form>
<style>
.users-form .form-label { font-weight: 600; color: #2a3f54; }
.users-switch-row { display: flex; align-items: flex-start; gap: 12px; margin-bottom: 12px; }
.users-switch-row:last-child { margin-bottom: 0; }
.users-switch-row label { font-weight: 600; color: #2a3f54; margin: 2px 0 0; }
.users-switch-row p { margin: 2px 0 0; font-size: 12px; }
.users-avatar-preview { width: 140px; height: 140px; object-fit: cover; margin-bottom: 14px; border: 3px solid #e6e9ed; }
.users-avatar-placeholder {
    width: 140px; height: 140px; border-radius: 50%; margin: 0 auto 14px;
    background: #f7f9fb; border: 3px solid #e6e9ed;
    display: flex; align-items: center; justify-content: center; color: #b4bcc8; font-size: 52px;
}
.users-form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-bottom: 20px; }
.users-form-actions .btn { margin: 0; }
.users-form .select2-container { width: 100% !important; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('.js-departments').select2({
            placeholder: 'Selecione departamentos',
            allowClear: true,
            width: '100%'
        });
    }
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
