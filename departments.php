<?php
require_once __DIR__ . '/functions.php';
startSession();
requireRole(2);

$csrfToken = generateCsrfToken();
$errors = [];
$listErrors = [];

$taxonomyId = getDepartmentTaxonomyId();
if (!$taxonomyId) {
    require_once __DIR__ . '/header.php';
    ?>
    <div class="container-fluid">
        <div class="alert alert-danger" role="alert">
            Tabelas de taxonomias inexistentes. Verifique o esquema da base de dados.
        </div>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_department_id'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        $listErrors[] = 'Token CSRF invalido.';
        $csrfToken = generateCsrfToken(true);
    } else {
        $idDel = (int) $_POST['delete_department_id'];
        $term = getTerm($idDel);
        if (!$term || (int) $term['taxonomy_id'] !== $taxonomyId) {
            $listErrors[] = 'Departamento invalido.';
        } elseif (isDepartmentTermInUse($idDel)) {
            $listErrors[] = 'Nao pode eliminar departamentos com utilizadores associados.';
        } else {
            deleteTerm($idDel);
            header('Location: ' . BASE_URL . 'tabelas/departamentos');
            exit;
        }
    }
}

$action = $_GET['action'] ?? 'list';
$id = $action === 'edit' ? (int) ($_GET['id'] ?? 0) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_department'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF invalido');
    }
    $csrfToken = generateCsrfToken();
    $name = trim($_POST['name'] ?? '');

    if ($name === '') {
        $errors[] = 'O nome do departamento e obrigatorio.';
    }

    if (!$errors) {
        if ($id) {
            $term = getTerm($id);
            if ($term && (int) $term['taxonomy_id'] === $taxonomyId) {
                updateTerm($id, $name);
            }
        } else {
            createTerm($taxonomyId, $name);
        }
        header('Location: ' . BASE_URL . 'tabelas/departamentos');
        exit;
    }
}

if ($action === 'edit') {
    $term = $id ? getTerm($id) : null;
    if (!$term || (int) $term['taxonomy_id'] !== $taxonomyId) {
        $action = 'list';
    }
}

require_once __DIR__ . '/header.php';

if ($action === 'list'):
    $departments = getDepartmentTermsWithCounts();
?>
<div class="container-fluid departments-page">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h2 class="mb-1">Departamentos</h2>
            <p class="mb-0 text-muted">Organize equipas e atribua utilizadores por area.</p>
        </div>
        <a href="<?= BASE_URL ?>tabelas/departamentos/add" class="btn btn-primary"><i class="fa fa-plus"></i> Adicionar departamento</a>
    </div>
    <?php foreach ($listErrors as $err): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-building"></i> Lista de departamentos</h2>
            <div class="pull-right">
                <a href="<?= BASE_URL ?>tabelas/departamentos/add" class="btn btn-sm btn-primary"><i class="fa fa-plus"></i> Adicionar departamento</a>
            </div>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <table class="table table-striped jambo_table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nome</th>
                        <th>Utilizadores</th>
                        <th class="text-end">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$departments): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted">
                            Sem departamentos. <a href="<?= BASE_URL ?>tabelas/departamentos/add">Criar o primeiro</a>.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($departments as $department): ?>
                    <tr>
                        <td><?= (int) $department['id']; ?></td>
                        <td><?= htmlspecialchars($department['name']); ?></td>
                        <td><?= (int) ($department['user_count'] ?? 0); ?></td>
                        <td class="text-end">
                            <a href="<?= BASE_URL ?>tabelas/departamentos/edit/<?= (int) $department['id']; ?>" class="btn btn-sm btn-secondary"><i class="fa fa-pencil"></i> Editar</a>
                            <form method="post" action="<?= BASE_URL ?>tabelas/departamentos" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="delete_department_id" value="<?= (int) $department['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Eliminar departamento?');"><i class="fa fa-trash"></i> Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else:
    $term = $term ?? ['name' => ''];
?>
<div class="container-fluid departments-page">
    <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
        <div>
            <h2 class="mb-1"><?= $id ? 'Editar departamento' : 'Adicionar departamento'; ?></h2>
            <p class="mb-0 text-muted">Defina o nome do departamento.</p>
        </div>
        <a href="<?= BASE_URL ?>tabelas/departamentos" class="btn btn-outline-secondary"><i class="fa fa-arrow-left"></i> Voltar</a>
    </div>
    <?php foreach ($errors as $err): ?>
        <div class="alert alert-danger" role="alert"><?= htmlspecialchars($err); ?></div>
    <?php endforeach; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
        <input type="hidden" name="save_department" value="1">
        <div class="row">
            <div class="col-12 col-lg-8">
                <div class="x_panel">
                    <div class="x_title">
                        <h2><i class="fa fa-building"></i> Dados do departamento</h2>
                        <div class="clearfix"></div>
                    </div>
                    <div class="x_content">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nome</label>
                            <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($term['name']); ?>" required>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-success btn-lg"><i class="fa fa-save"></i> Guardar</button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
