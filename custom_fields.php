<?php
/**
 * Gestão de campos personalizados para um tipo de conteúdo.
 */

require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

// Obtém parâmetros básicos
$typeId = isset($_GET['type_id']) ? (int) $_GET['type_id'] : 0;
$type   = $typeId ? getContentType($typeId) : null;
$act    = $_GET['act'] ?? '';
$editId = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
$deleteId = isset($_GET['delete_id']) ? (int) $_GET['delete_id'] : 0;

if (!$type) {
    echo 'Tipo de conteúdo inválido.';
    exit;
}

// Listas auxiliares de taxonomias e tipos de conteúdo
$taxonomies      = getTaxonomies();
$contentTypesAll = getContentTypes();

// Ação de apagar
if ($deleteId) {
    $field = getCustomField($deleteId);
    if ($field && (int) $field['content_type_id'] === $typeId) {
        deleteCustomField($deleteId);
    }
    header('Location: custom_fields.php?type_id=' . $typeId);
    exit;
}

// Campo em edição, se aplicável
$editField = null;
if ($editId) {
    $editField = getCustomField($editId);
    if (!$editField || (int) $editField['content_type_id'] !== $typeId) {
        $editField = null;
    }
}

// Processa submissão do formulário para criar ou atualizar um campo
$error = '';
if (($_SERVER['REQUEST_METHOD'] === 'POST') && ($act === 'ad' || $editField)) {
    $fieldId   = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
    $name      = isset($_POST['name']) ? trim($_POST['name']) : '';
    $label     = isset($_POST['label']) ? trim($_POST['label']) : '';
    $fieldType = isset($_POST['field_type']) ? trim($_POST['field_type']) : '';
    $options   = '';
    if ($fieldType === 'select') {
        $options = isset($_POST['options_text']) ? trim($_POST['options_text']) : '';
    } elseif ($fieldType === 'taxonomy') {
        $options = isset($_POST['options_taxonomy']) ? trim($_POST['options_taxonomy']) : '';
    } elseif ($fieldType === 'content') {
        $options = isset($_POST['options_content']) ? trim($_POST['options_content']) : '';
    }
    $required   = isset($_POST['required']);
    $showInList = isset($_POST['show_in_list']);
    $sortable = isset($_POST['sortable']);
    if ($name !== '' && $label !== '' && $fieldType !== '') {
        if ($fieldId) {
            $existing = getCustomField($fieldId);

            if ($existing && (int)$existing['content_type_id'] === $typeId) {
                updateCustomField($fieldId, $name, $label, $fieldType, $options, $required, $showInList, $sortable);
            }
        } else {
            createCustomField($typeId, $name, $label, $fieldType, $options, $required, $showInList, $sortable);
        }
        header('Location: custom_fields.php?type_id=' . $typeId);
        exit;
    } else {
        $error = 'Nome, rótulo e tipo são obrigatórios.';
    }
}

// Recupera os campos existentes para exibição
$fields = getCustomFields($typeId);

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
<?php if ($act === 'ad' || $editField): ?>
    <h2 class="mt-3"><?php echo $editField ? 'Editar campo' : 'Adicionar novo campo a ' . htmlspecialchars($type['label']); ?></h2>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card p-3 mt-4">
        <form method="post" action="<?php echo $editField ? '?type_id=' . $typeId . '&edit_id=' . $editField['id'] : '?type_id=' . $typeId . '&act=ad'; ?>">
            <?php if ($editField): ?>
                <input type="hidden" name="field_id" value="<?php echo $editField['id']; ?>">
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="name">Slug</label>
                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($editField['name'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="label">Rótulo</label>
                <input type="text" class="form-control" id="label" name="label" value="<?php echo htmlspecialchars($editField['label'] ?? ''); ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="field_type">Tipo</label>
                <select class="form-select" id="field_type" name="field_type" required>
                    <option value="text" <?php echo isset($editField['type']) && $editField['type'] === 'text' ? 'selected' : ''; ?>>Texto</option>
                    <option value="textarea" <?php echo isset($editField['type']) && $editField['type'] === 'textarea' ? 'selected' : ''; ?>>Textarea</option>
                    <option value="number" <?php echo isset($editField['type']) && $editField['type'] === 'number' ? 'selected' : ''; ?>>Número</option>
                    <option value="date" <?php echo isset($editField['type']) && $editField['type'] === 'date' ? 'selected' : ''; ?>>Data</option>
                    <option value="datetime" <?php echo isset($editField['type']) && $editField['type'] === 'datetime' ? 'selected' : ''; ?>>Data e Hora</option>
                    <option value="select" <?php echo isset($editField['type']) && $editField['type'] === 'select' ? 'selected' : ''; ?>>Select (opções separadas por vírgula)</option>
                    <option value="taxonomy" <?php echo isset($editField['type']) && $editField['type'] === 'taxonomy' ? 'selected' : ''; ?>>Select Taxonomia</option>
                    <option value="content" <?php echo isset($editField['type']) && $editField['type'] === 'content' ? 'selected' : ''; ?>>Select Conteúdo</option>
                </select>
            </div>
            <div class="mb-3" id="options_text_wrapper">
                <label class="form-label" for="options_text">Opções (apenas para Select)</label>
                <input type="text" class="form-control" id="options_text" name="options_text" placeholder="opção1,opção2,opção3" value="<?php echo isset($editField) && $editField['type'] === 'select' ? htmlspecialchars($editField['options']) : ''; ?>">
            </div>
            <div class="mb-3" id="options_taxonomy_wrapper">
                <label class="form-label" for="options_taxonomy">Taxonomia</label>
                <select class="form-select" id="options_taxonomy" name="options_taxonomy">
                    <option value="">-- Selecione --</option>
                    <?php foreach ($taxonomies as $tax): ?>
                        <option value="<?php echo $tax['id']; ?>" <?php echo isset($editField) && $editField['type'] === 'taxonomy' && $editField['options'] == $tax['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tax['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3" id="options_content_wrapper">
                <label class="form-label" for="options_content">Tipo de Conteúdo</label>
                <select class="form-select" id="options_content" name="options_content">
                    <option value="">-- Selecione --</option>
                    <?php foreach ($contentTypesAll as $ct): ?>
                        <option value="<?php echo $ct['id']; ?>" <?php echo isset($editField) && $editField['type'] === 'content' && $editField['options'] == $ct['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($ct['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="required" name="required" <?php echo !empty($editField['required']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="required">Obrigatório</label>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="show_in_list" name="show_in_list" <?php echo !empty($editField['show_in_list']) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="show_in_list">Mostrar na listagem</label>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="sortable" name="sortable" <?php
                    echo isset($editField['sortable']) ? (!empty($editField['sortable']) ? 'checked' : '') : 'checked'; ?>>
                <label class="form-check-label" for="sortable">Permitir ordenação</label>
            </div>
            <button type="submit" class="btn btn-primary"><?php echo $editField ? 'Guardar' : 'Adicionar'; ?></button>
            <a href="custom_fields.php?type_id=<?php echo $typeId; ?>" class="btn btn-secondary ms-2">Voltar</a>
        </form>
    </div>
<?php else: ?>
    <h2 class="mt-3">Campos personalizados para <?php echo htmlspecialchars($type['label']); ?></h2>
    <a href="custom_fields.php?type_id=<?php echo $typeId; ?>&act=ad" class="btn btn-success mb-3">Adicionar campo</a>
    <table class="table table-striped datatable">
        <thead>
            <tr><th>Slug</th><th>Rótulo</th><th>Tipo</th><th>Opções</th><th>Obrigatório</th><th>Listagem</th><th>Ações</th></tr>
        </thead>
        <tbody>
        <?php foreach ($fields as $field): ?>
            <tr>
                <td><?php echo htmlspecialchars($field['name']); ?></td>
                <td><?php echo htmlspecialchars($field['label']); ?></td>
                <td><?php echo htmlspecialchars($field['type']); ?></td>
                <td>
                    <?php if ($field['type'] === 'taxonomy'): ?>
                        <?php
                            $opt = '';
                            foreach ($taxonomies as $tax) {
                                if ($tax['id'] == $field['options']) { $opt = $tax['label']; break; }
                            }
                            echo htmlspecialchars($opt);
                        ?>
                    <?php elseif ($field['type'] === 'content'): ?>
                        <?php
                            $opt = '';
                            foreach ($contentTypesAll as $ct) {
                                if ($ct['id'] == $field['options']) { $opt = $ct['label']; break; }
                            }
                            echo htmlspecialchars($opt);
                        ?>
                    <?php else: ?>
                        <?php echo htmlspecialchars($field['options']); ?>
                    <?php endif; ?>
                </td>
                <td><?php echo $field['required'] ? 'Sim' : 'Não'; ?></td>
                <td><?php echo !empty($field['show_in_list']) ? 'Sim' : 'Não'; ?></td>
                <td>
                    <a href="custom_fields.php?type_id=<?php echo $typeId; ?>&edit_id=<?php echo $field['id']; ?>" class="btn btn-sm btn-secondary">Editar</a>
                    <a href="custom_fields.php?type_id=<?php echo $typeId; ?>&delete_id=<?php echo $field['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Apagar este campo?');">Apagar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>
<?php if ($act === 'ad' || $editField): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSel = document.getElementById('field_type');
    const textWrap = document.getElementById('options_text_wrapper');
    const taxWrap = document.getElementById('options_taxonomy_wrapper');
    const contentWrap = document.getElementById('options_content_wrapper');
    function updateOpts() {
        textWrap.style.display = typeSel.value === 'select' ? 'block' : 'none';
        taxWrap.style.display = typeSel.value === 'taxonomy' ? 'block' : 'none';
        contentWrap.style.display = typeSel.value === 'content' ? 'block' : 'none';
    }
    typeSel.addEventListener('change', updateOpts);
    updateOpts();
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>

