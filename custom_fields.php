<?php
/**
 * Gestão de campos personalizados para um tipo de conteúdo.
 */

require_once __DIR__ . '/functions.php';
startSession();
requireLogin();
requireRole(2);
$csrfToken = generateCsrfToken();

// Obtém parâmetros básicos
$typeId = isset($_GET['type_id']) ? (int) $_GET['type_id'] : 0;
$act    = $_GET['act'] ?? '';
$editId = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : 0;
$deleteId = isset($_GET['delete_id']) ? (int) $_GET['delete_id'] : 0;

$isLayout = isset($_GET['layout']);

// Se apenas o ID do campo foi fornecido, descobrir o tipo pelo próprio campo
$editField = null;
if ($editId) {
    $editField = getCustomField($editId);
    if ($editField) {
        if (!$typeId) {
            $typeId = (int) $editField['content_type_id'];
            $_GET['type_id'] = $typeId; // para consistência em links/formulários
        } elseif ((int) $editField['content_type_id'] !== $typeId) {
            $editField = null; // campo não pertence a este tipo
        }
    }
}

$type   = $typeId ? getContentType($typeId) : null;
if (!$type) {
    echo 'Tipo de conteúdo inválido.';
    exit;
}

if ($isLayout) {
    $fields = getCustomFields($typeId);
    $taxonomies = getTaxonomiesForContentType($typeId);

    // Excluir taxonomias que já estejam como campos adicionais
    $taxonomyFieldIds = array_map(
        function ($f) {
            return (int) $f['options'];
        },
        array_filter($fields, function ($f) {
            return ($f['type'] === 'taxonomy' || $f['type'] === 'multitaxonomy') && !empty($f['options']);
        })
    );

    $taxonomies = array_filter($taxonomies, function ($tax) use ($taxonomyFieldIds) {
        return !in_array((int) $tax['id'], $taxonomyFieldIds, true);
    });

    $taxonomyFields = array_map(function ($tax) {
        return [
            'id' => 'tax_' . $tax['id'],
            'label' => $tax['label'],
            'grid_row' => $tax['grid_row'] ?? 0,
            'grid_col' => $tax['grid_col'] ?? 0,
            'grid_width' => $tax['grid_width'] ?? 12,
        ];
    }, $taxonomies);

    $fields = array_merge([
        [
            'id' => 'title',
            'label' => 'Título',
            'grid_row' => $type['title_grid_row'] ?? 0,
            'grid_col' => $type['title_grid_col'] ?? 0,
            'grid_width' => $type['title_grid_width'] ?? 12,
        ],
        [
            'id' => 'body',
            'label' => 'Texto',
            'grid_row' => $type['body_grid_row'] ?? 0,
            'grid_col' => $type['body_grid_col'] ?? 0,
            'grid_width' => $type['body_grid_width'] ?? 12,
        ],
    ], $fields, $taxonomyFields);

    require_once __DIR__ . '/header.php';
    ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gridstack@9.2.2/dist/gridstack.min.css">
    <div class="container-fluid">
        <h2 class="mt-3">Layout de campos para <?= htmlspecialchars($type['label']) ?></h2>
        <div class="grid-stack">
            <?php foreach ($fields as $field): ?>
                <div class="grid-stack-item" gs-id="<?= $field['id'] ?>" gs-x="<?= $field['grid_col'] ?>" gs-y="<?= $field['grid_row'] ?>" gs-w="<?= $field['grid_width'] ?>" gs-h="1">
                    <div class="grid-stack-item-content">
                        <?= htmlspecialchars($field['label']) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <a href="<?= BASE_URL . 'fields/' . $typeId; ?>" class="btn btn-secondary mt-3"><i class="fa fa-arrow-left"></i> Voltar</a>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/gridstack@9.2.2/dist/gridstack-all.js"></script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const grid = GridStack.init({float: true});
        grid.on('change', function(event, items) {
            items.forEach(item => {
                const fieldId = item.id || (item.el ? item.el.getAttribute('gs-id') : '');
                if (!fieldId) {
                    return;
                }
                const params = new URLSearchParams();
                params.append('field_id', fieldId);
                params.append('type_id', <?= $typeId ?>);
                params.append('row', item.y);
                params.append('col', item.x);
                params.append('width', item.w);
                fetch('<?= BASE_URL ?>fields/save-layout', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: params.toString()
                });
            });
        });
    });
    </script>
    <?php require_once __DIR__ . '/footer.php'; ?>
    <?php
    exit;
}

// Listas auxiliares de taxonomias e tipos de conteúdo
$taxonomies      = getTaxonomies();
$contentTypesAll = getContentTypes();

$selectedContentType = 0;
$selectedFilterField = 0;
$selectedFilterValue = '';
if ($editField && ($editField['type'] === 'content' || $editField['type'] === 'multicontent')) {
    $optData = json_decode($editField['options'], true);
    if (is_array($optData)) {
        $selectedContentType = (int)($optData['type_id'] ?? 0);
        if (!empty($optData['filter'])) {
            $selectedFilterField = (int)($optData['filter']['field_id'] ?? 0);
            $selectedFilterValue = $optData['filter']['value'] ?? '';
        }
    } else {
        $selectedContentType = (int)$editField['options'];
    }
}

// Ação de apagar
if ($deleteId) {
    $field = getCustomField($deleteId);
    if ($field && (int) $field['content_type_id'] === $typeId) {
        deleteCustomField($deleteId);
    }
    header('Location: ' . BASE_URL . 'fields/' . $typeId);
    exit;
}

// Processa submissão do formulário para criar ou atualizar um campo
$error = '';
if (($_SERVER['REQUEST_METHOD'] === 'POST') && ($act === 'ad' || $editField)) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(400);
        exit('Token CSRF inválido');
    }
    $csrfToken = generateCsrfToken();
    $fieldId   = isset($_POST['field_id']) ? (int) $_POST['field_id'] : 0;
    $name      = isset($_POST['name']) ? trim($_POST['name']) : '';
    $label     = isset($_POST['label']) ? trim($_POST['label']) : '';
    $fieldType = isset($_POST['field_type']) ? trim($_POST['field_type']) : '';
    $options   = '';
    if ($fieldType === 'select') {
        $options = isset($_POST['options_text']) ? trim($_POST['options_text']) : '';
    } elseif ($fieldType === 'taxonomy' || $fieldType === 'multitaxonomy') {
        $options = isset($_POST['options_taxonomy']) ? trim($_POST['options_taxonomy']) : '';
    } elseif ($fieldType === 'content' || $fieldType === 'multicontent') {
        $contentType = isset($_POST['options_content']) ? (int) $_POST['options_content'] : 0;

        $filterField = $_POST['content_filter_field'] ?? '';
        $filterValue = $_POST['content_filter_value'] ?? '';
        $optArr = ['type_id' => $contentType];
        if ($filterField !== '' && $filterValue !== '') {

            $optArr['filter'] = [
                'field_id' => $filterField,
                'value' => $filterValue,
            ];
        }
        $options = json_encode($optArr);
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
        header('Location: ' . BASE_URL . 'fields/' . $typeId);
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

        <form method="post" action="<?php echo $editField ? BASE_URL . 'fields/edit-field/' . $editField['id'] : BASE_URL . 'fields/' . $typeId . '/ad'; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
                    <option value="image" <?php echo isset($editField['type']) && $editField['type'] === 'image' ? 'selected' : ''; ?>>Imagem</option>
                    <option value="select" <?php echo isset($editField['type']) && $editField['type'] === 'select' ? 'selected' : ''; ?>>Select (opções separadas por vírgula)</option>
                    <option value="taxonomy" <?php echo isset($editField['type']) && $editField['type'] === 'taxonomy' ? 'selected' : ''; ?>>Select Taxonomia</option>
                    <option value="multitaxonomy" <?php echo isset($editField['type']) && $editField['type'] === 'multitaxonomy' ? 'selected' : ''; ?>>Multi-select Taxonomia</option>
                    <option value="content" <?php echo isset($editField['type']) && $editField['type'] === 'content' ? 'selected' : ''; ?>>Select Conteúdo</option>
                    <option value="multicontent" <?php echo isset($editField['type']) && $editField['type'] === 'multicontent' ? 'selected' : ''; ?>>Multi-select Conteúdo</option>
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
                        <option value="<?php echo $tax['id']; ?>" <?php echo isset($editField) && ($editField['type'] === 'taxonomy' || $editField['type'] === 'multitaxonomy') && $editField['options'] == $tax['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($tax['label']); ?></option>

                    <?php endforeach; ?>
                </select>
            </div>
              <div class="mb-3" id="options_content_wrapper">
                  <label class="form-label" for="options_content">Tipo de Conteúdo</label>
                  <select class="form-select" id="options_content" name="options_content">
                      <option value="">-- Selecione --</option>
                      <?php foreach ($contentTypesAll as $ct): ?>
                          <option value="<?php echo $ct['id']; ?>" <?php echo ($selectedContentType == $ct['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ct['label']); ?></option>
                      <?php endforeach; ?>
                  </select>
              </div>
              <div class="mb-3" id="content_filter_wrapper" style="display:none;">
                  <label class="form-label" for="content_filter_field">Filtro por campo</label>
                  <select class="form-select mb-2" id="content_filter_field" name="content_filter_field">
                      <option value="">-- Sem filtro --</option>
                  </select>
                  <select class="form-select" id="content_filter_value" name="content_filter_value" style="display:none;">
                      <option value="">-- Selecione --</option>
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
                        <a href="<?= BASE_URL . 'fields/' . $typeId; ?>" class="btn btn-secondary ms-2"><i class="fa fa-arrow-left"></i> Voltar</a>

            <button type="submit" class="btn btn-primary"><i class="fa <?php echo $editField ? 'fa-save' : 'fa-plus'; ?>"></i> <?php echo $editField ? 'Guardar' : 'Adicionar'; ?></button>
        </form>
    </div>
<?php else: ?>
    <h2 class="mt-3">Campos personalizados para <?php echo htmlspecialchars($type['label']); ?></h2>
    <table class="table table-striped datatable">
        <thead>
            <tr><th>Slug</th><th>Rótulo</th><th>Tipo</th><th>Opções</th><th>Obrigatório</th><th>Listagem</th><th data-orderable="false">Ações</th></tr>
        </thead>
        <tbody>
        <?php foreach ($fields as $field): ?>
            <tr>
                <td><?php echo htmlspecialchars($field['name']); ?></td>
                <td><?php echo htmlspecialchars($field['label']); ?></td>
                <td><?php echo htmlspecialchars($field['type']); ?></td>
                <td>
                    <?php if ($field['type'] === 'taxonomy' || $field['type'] === 'multitaxonomy'): ?>
                        <?php
                            $opt = '';
                            foreach ($taxonomies as $tax) {
                                if ($tax['id'] == $field['options']) { $opt = $tax['label']; break; }
                            }
                            echo htmlspecialchars($opt);
                        ?>
                    <?php elseif ($field['type'] === 'content' || $field['type'] === 'multicontent'): ?>
                        <?php
                            $opt = '';
                            $optData = json_decode($field['options'], true);
                            $typeOpt = is_array($optData) ? ($optData['type_id'] ?? 0) : $field['options'];
                            foreach ($contentTypesAll as $ct) {
                                if ($ct['id'] == $typeOpt) { $opt = $ct['label']; break; }
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
                    <a href="<?= BASE_URL . 'fields/edit-field/' . $field['id']; ?>" class="btn btn-sm btn-secondary"><i class="fa fa-pencil"></i> Editar</a>
                    <a href="<?= BASE_URL . 'fields/' . $typeId; ?>?delete_id=<?php echo $field['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Apagar este campo?');"><i class="fa fa-trash"></i> Apagar</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="mt-3">
        <a href="<?= BASE_URL ?>content-types" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Voltar</a>
        <a href="<?= BASE_URL . 'fields/layout/' . $typeId; ?>" class="btn btn-warning ms-2"><i class="fa fa-th"></i> Layout</a>
        <a href="<?= BASE_URL . 'fields/' . $typeId; ?>/ad" class="btn btn-success ms-2"><i class="fa fa-plus"></i> Adicionar campo</a>
    </div>
<?php endif; ?>
</div>
<?php if ($act === 'ad' || $editField): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSel = document.getElementById('field_type');
    const textWrap = document.getElementById('options_text_wrapper');
    const taxWrap = document.getElementById('options_taxonomy_wrapper');
    const contentWrap = document.getElementById('options_content_wrapper');
    const filterWrap = document.getElementById('content_filter_wrapper');
    const contentTypeSel = document.getElementById('options_content');
    const filterFieldSel = document.getElementById('content_filter_field');
    const filterValueSel = document.getElementById('content_filter_value');
    let selectFields = [];
    let preFilterField = <?= json_encode($selectedFilterField) ?>;
    let preFilterValue = <?= json_encode($selectedFilterValue) ?>;

    function updateOpts() {
        textWrap.style.display = typeSel.value === 'select' ? 'block' : 'none';
        taxWrap.style.display = (typeSel.value === 'taxonomy' || typeSel.value === 'multitaxonomy') ? 'block' : 'none';
        contentWrap.style.display = (typeSel.value === 'content' || typeSel.value === 'multicontent') ? 'block' : 'none';
        if (typeSel.value !== 'content' && typeSel.value !== 'multicontent') {
            filterWrap.style.display = 'none';
        }
    }

    typeSel.addEventListener('change', function() {
        updateOpts();
        if (typeSel.value === 'content' || typeSel.value === 'multicontent') {
            loadSelectFields(contentTypeSel.value);
        }
    });

    contentTypeSel.addEventListener('change', function() {
        loadSelectFields(this.value);
    });

    filterFieldSel.addEventListener('change', function() {
        populateValues(this.value);
    });

    updateOpts();
    if (typeSel.value === 'content' || typeSel.value === 'multicontent') {
        loadSelectFields(contentTypeSel.value);
    }

    async function loadSelectFields(typeId) {
        filterFieldSel.innerHTML = '<option value="">-- Sem filtro --</option>';
        filterValueSel.style.display = 'none';
        selectFields = [];
        if (!typeId) {
            filterWrap.style.display = 'none';
            return;
        }
        try {
            const resp = await fetch('<?= BASE_URL ?>data/select_fields.php?type_id=' + encodeURIComponent(typeId));
            const data = await resp.json();
            selectFields = data.fields || [];
            selectFields.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.label;

                if (String(preFilterField) === String(f.id)) {

                    opt.selected = true;
                }
                filterFieldSel.appendChild(opt);
            });
            filterWrap.style.display = selectFields.length ? 'block' : 'none';
            if (preFilterField) {
                populateValues(preFilterField);

                preFilterField = '';

            }
        } catch (e) {
            filterWrap.style.display = 'none';
        }
    }

    function populateValues(fieldId) {
        const field = selectFields.find(f => String(f.id) === String(fieldId));
        if (!field) {
            filterValueSel.style.display = 'none';
            return;
        }
        filterValueSel.innerHTML = '<option value="">-- Selecione --</option>';
        field.options.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v.value;
            opt.textContent = v.label;
            if (String(preFilterValue) === String(v.value)) {
                opt.selected = true;
            }
            filterValueSel.appendChild(opt);
        });
        filterValueSel.style.display = 'block';
    }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
