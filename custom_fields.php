<?php
/**
 * Gestão de campos personalizados para um tipo de conteúdo.
 */

require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

// Obtém o ID do tipo de conteúdo a partir do parâmetro GET
$typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
$type = $typeId ? getContentType($typeId) : null;
if (!$type) {
    echo "Tipo de conteúdo inválido.";
    exit;
}

// Processa submissão do formulário para criar um novo campo
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = isset($_POST['name']) ? trim($_POST['name']) : '';
    $label    = isset($_POST['label']) ? trim($_POST['label']) : '';
    $fieldType = isset($_POST['field_type']) ? trim($_POST['field_type']) : '';
    $options  = isset($_POST['options']) ? trim($_POST['options']) : '';
    $required = isset($_POST['required']);
    if ($name !== '' && $label !== '' && $fieldType !== '') {
        createCustomField($typeId, $name, $label, $fieldType, $options, $required);
        header('Location: custom_fields.php?type_id=' . $typeId);
        exit;
    } else {
        $error = 'Nome, rótulo e tipo são obrigatórios.';
    }
}

// Recupera os campos existentes
$fields = getCustomFields($typeId);

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <h2 class="mt-3">Campos personalizados para <?php echo htmlspecialchars($type['label']); ?></h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <table class="table table-striped datatable">
        <thead>
            <tr><th>Slug</th><th>Rótulo</th><th>Tipo</th><th>Opções</th><th>Obrigatório</th></tr>
        </thead>
        <tbody>
        <?php foreach ($fields as $field): ?>
            <tr>
                <td><?php echo htmlspecialchars($field['name']); ?></td>
                <td><?php echo htmlspecialchars($field['label']); ?></td>
                <td><?php echo htmlspecialchars($field['type']); ?></td>
                <td><?php echo htmlspecialchars($field['options']); ?></td>
                <td><?php echo $field['required'] ? 'Sim' : 'Não'; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="card p-3 mt-4">
        <h5>Adicionar novo campo</h5>
        <form method="post" action="">
            <div class="mb-3">
                <label class="form-label" for="name">Slug</label>
                <input type="text" class="form-control" id="name" name="name" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="label">Rótulo</label>
                <input type="text" class="form-control" id="label" name="label" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="field_type">Tipo</label>
                <select class="form-select" id="field_type" name="field_type" required>
                    <option value="text">Texto</option>
                    <option value="textarea">Textarea</option>
                    <option value="number">Número</option>
                    <option value="date">Data</option>
                    <option value="select">Select (opções separadas por vírgula)</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="options">Opções (apenas para Select)</label>
                <input type="text" class="form-control" id="options" name="options" placeholder="opção1,opção2,opção3">
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="required" name="required">
                <label class="form-check-label" for="required">Obrigatório</label>
            </div>
            <button type="submit" class="btn btn-primary">Adicionar</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

