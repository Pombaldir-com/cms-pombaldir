<?php
require_once __DIR__ . '/data/db.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();
$csrfToken = generateCsrfToken();
$slug = getCompanySlug();

$useDataTables = true;

/**
 * Render an individual custom field input.
 *
 * @param array $field Field definition.
 * @param string $inputName Name attribute for the field.
 * @param mixed $value Current value (if any).
 */
function renderFieldInput(array $field, string $inputName, $value = null): void
{
    $options = $field['options'];
    $isRequired = $field['required'] ? 'required' : '';
    echo '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
    switch ($field['type']) {
        case 'text':
            echo '<input type="text" name="' . htmlspecialchars($inputName) . '" class="form-control" ' . $isRequired .
                 ' value="' . htmlspecialchars($value ?? '') . '">';
            break;
        case 'textarea':
            echo '<textarea name="' . htmlspecialchars($inputName) . '" class="form-control" ' . $isRequired . '>' .
                 htmlspecialchars($value ?? '') . '</textarea>';
            break;
        case 'number':
            echo '<input type="number" name="' . htmlspecialchars($inputName) . '" class="form-control" ' . $isRequired .
                 ' value="' . htmlspecialchars($value ?? '') . '">';
            break;
        case 'date':
            echo '<input type="date" name="' . htmlspecialchars($inputName) . '" class="form-control" ' . $isRequired .
                 ' value="' . htmlspecialchars($value ?? '') . '">';
            break;
        case 'datetime':
            $val = $value ? str_replace(' ', 'T', substr($value, 0, 16)) : '';
            echo '<input type="datetime-local" name="' . htmlspecialchars($inputName) . '" class="form-control" ' . $isRequired .
                 ' value="' . htmlspecialchars($val) . '">';
            break;
        case 'image':
            if ($value) {
                $slug = getCompanySlug();
                if (strpos($value, 'uploads/' . $slug . '/') !== 0) {
                    $value = 'uploads/' . $slug . '/' . ltrim($value, '/');
                }
                echo '<div class="mb-2"><img src="' . htmlspecialchars($value) . '" style="max-width:100px;" alt=""></div>';
            }
            $req = $field['required'] && !$value ? 'required' : '';
            echo '<input type="file" name="' . htmlspecialchars($inputName) . '" class="form-control" accept="image/*" ' . $req . '>';
            break;
        case 'select':
            echo '<select name="' . htmlspecialchars($inputName) . '" class="form-select" ' . $isRequired . '>';
            echo '<option value="">-- Select --</option>';
            foreach (explode(',', $options) as $opt) {
                $optTrim = trim($opt);
                $selected = ($value !== null && $value === $optTrim) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($optTrim) . '"' . $selected . '>' .
                     htmlspecialchars($optTrim) . '</option>';
            }
            echo '</select>';
            break;
        case 'taxonomy':
            $terms = getTerms((int)$options);
            echo '<select name="' . htmlspecialchars($inputName) . '" class="form-select" ' . $isRequired . '>';
            echo '<option value="">-- Select --</option>';
            foreach ($terms as $term) {
                $selected = ($value !== null && $value == $term['id']) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($term['id']) . '"' . $selected . '>' .
                     htmlspecialchars($term['name']) . '</option>';
            }
            echo '</select>';
            break;
        case 'multitaxonomy':
            $terms = getTerms((int)$options);
            $selected = is_array($value) ? $value : [];
            echo '<select name="' . htmlspecialchars($inputName) . '[]" class="form-select" multiple ' . $isRequired . '>';
            foreach ($terms as $term) {
                $sel = in_array($term['id'], $selected) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($term['id']) . '"' . $sel . '>' .
                     htmlspecialchars($term['name']) . '</option>';
            }
            echo '</select>';
            break;
        case 'content':
            $opts = json_decode($options, true);
            $filterValue = '';
            if (is_array($opts)) {
                $targetType  = (int)($opts['type_id'] ?? 0);
                $filterField = $opts['filter']['field_id'] ?? '';
                $filterValue = $opts['filter']['value'] ?? '';
                $filters     = [];
                if ($filterField !== '' && $filterValue !== '') {
                    $filters[$filterField] = $filterValue;
                }
            } else {
                $targetType  = (int)$options;
                $filterField = '';
                $filters     = [];
            }
            $entries    = getContentList($targetType, $filters);
            $selectedId = $value !== null ? (string)$value : '';
            $dataAttr   = ' data-target-type="' . htmlspecialchars($targetType) . '"';
            if ($filterField !== '') {
                $dataAttr .= ' data-filter-field="' . htmlspecialchars($filterField) . '"';
            }
            if ($filterValue !== '') {
                $dataAttr .= ' data-filter-value="' . htmlspecialchars($filterValue) . '"';
            }
            if ($selectedId !== '') {
                $dataAttr .= ' data-selected="' . htmlspecialchars($selectedId) . '"';
            }
            echo '<select name="' . htmlspecialchars($inputName) . '" class="form-select content-select" ' . $isRequired . $dataAttr . '>';
            echo '<option value="">-- Select --</option>';
            foreach ($entries as $entry) {
                $selected = ($value !== null && $value == $entry['id']) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($entry['id']) . '"' . $selected . '>' .
                     htmlspecialchars($entry['title']) . '</option>';
            }
            echo '</select>';
            break;
        case 'multicontent':
            $opts = json_decode($options, true);
            $filterValue = '';
            if (is_array($opts)) {
                $targetType  = (int)($opts['type_id'] ?? 0);
                $filterField = $opts['filter']['field_id'] ?? '';
                $filterValue = $opts['filter']['value'] ?? '';
                $filters     = [];
                if ($filterField !== '' && $filterValue !== '') {
                    $filters[$filterField] = $filterValue;
                }
            } else {
                $targetType  = (int)$options;
                $filterField = '';
                $filters     = [];
            }
            $entries  = getContentList($targetType, $filters);
            $selected = is_array($value) ? $value : [];
            $dataAttr = ' data-target-type="' . htmlspecialchars($targetType) . '"';
            if ($filterField !== '') {
                $dataAttr .= ' data-filter-field="' . htmlspecialchars($filterField) . '"';
            }
            if ($filterValue !== '') {
                $dataAttr .= ' data-filter-value="' . htmlspecialchars($filterValue) . '"';
            }
            if ($selected) {
                $dataAttr .= ' data-selected="' . htmlspecialchars(implode(',', $selected)) . '"';
            }
            echo '<select name="' . htmlspecialchars($inputName) . '[]" class="form-select content-select" multiple ' . $isRequired . $dataAttr . '>';
            foreach ($entries as $entry) {
                $sel = in_array($entry['id'], $selected) ? ' selected' : '';
                echo '<option value="' . htmlspecialchars($entry['id']) . '"' . $sel . '>' .
                     htmlspecialchars($entry['title']) . '</option>';
            }
            echo '</select>';
            break;
    }
}

// If the request is for content type management (formerly handled by
// content_types.php) delegate to that logic and exit early.
if (isset($_GET['manage_types'])) {
    requireRole(2);

    $error   = '';
    $action  = $_GET['act'] ?? '';
    $id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;          // editar
    $typeTax = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0; // taxonomias
    $delId   = isset($_GET['delete_id']) ? (int)$_GET['delete_id'] : 0;

    if ($delId) {
        $associated = countContentByContentType($delId);
        deleteContentType($delId);
        $params = 'deleted=1';
        if ($associated) {
            $params .= '&associated=' . $associated;
        }
        header('Location: ' . BASE_URL . 'content-types?' . $params);
        exit;
    }

    // -- Taxonomias associadas a um tipo de conteúdo ------------------
    if ($typeTax) {
        $type = getContentType($typeTax);
        if (!$type) {
            echo "Tipo de conteúdo inválido.";
            exit;
        }

        $allTaxonomies = getTaxonomies();
        $fields = sortFieldsByGrid(getCustomFields($typeTax));
        $usedTaxonomies = [];
        foreach ($fields as $field) {
            if ($field['type'] === 'taxonomy' || $field['type'] === 'multitaxonomy') {
                $usedTaxonomies[] = (int)$field['options'];
            }
        }
        $allTaxonomies = array_filter($allTaxonomies, fn($t) => !in_array((int)$t['id'], $usedTaxonomies));
        $current = array_map(fn($t) => (int)$t['id'], getTaxonomiesForContentType($typeTax));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
                http_response_code(400);
                exit('Token CSRF inválido');
            }
            $csrfToken = generateCsrfToken();
            $selected = isset($_POST['taxonomies']) ? array_map('intval', (array)$_POST['taxonomies']) : [];
            setContentTypeTaxonomies($typeTax, $selected);
            header('Location: ' . BASE_URL . 'content-types/taxonomies/' . $typeTax);
            exit;
        }

        require_once __DIR__ . '/header.php';
        ?>
        <div class="container-fluid">
            <h2 class="mt-3">Taxonomias para <?php echo htmlspecialchars($type['label']); ?></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <?php foreach ($allTaxonomies as $tax): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="tax_<?php echo $tax['id']; ?>" name="taxonomies[]" value="<?php echo $tax['id']; ?>" <?php echo in_array($tax['id'], $current) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="tax_<?php echo $tax['id']; ?>"><?php echo htmlspecialchars($tax['label']); ?></label>
                    </div>
                <?php endforeach; ?>
                <a href="<?= BASE_URL ?>content-types" class="btn btn-secondary mt-3 ms-2"><i class="fa fa-arrow-left"></i> Voltar</a>

                <button type="submit" class="btn btn-primary mt-3"><i class="fa fa-save"></i> Guardar</button>
            </form>
        </div>
        <?php
        require_once __DIR__ . '/footer.php';
        return;
    }

    // -- Formulário de criação/edição ---------------------------------
    if ($action === 'ad' || $id) {
        $editing = $id ? getContentType($id) : null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
                http_response_code(400);
                exit('Token CSRF inválido');
            }
            $csrfToken = generateCsrfToken();
            $name  = trim($_POST['name'] ?? '');
            $label = trim($_POST['label'] ?? '');
            $icon  = trim($_POST['icon'] ?? 'fa fa-file-text');
            $showAuthor = isset($_POST['show_author']);
            $showDate   = isset($_POST['show_date']);
            $showTax    = isset($_POST['show_taxonomies']);

            if ($name !== '' && $label !== '') {
                if ($id) {
                    $sortOrder = isset($_POST['sort_order']) ? (int)$_POST['sort_order'] : 0;
                    updateContentType($id, $name, $label, $icon === '' ? 'fa fa-file-text' : $icon, $showAuthor, $showDate, $showTax, $sortOrder);
                } else {
                    $sortOrder = getNextContentTypeSortOrder();
                    createContentType($name, $label, $icon === '' ? 'fa fa-file-text' : $icon, $showAuthor, $showDate, $showTax, $sortOrder);
                }
                header('Location: ' . BASE_URL . 'content-types');
                exit;
            } else {
                $error = 'Nome e rótulo são obrigatórios.';
            }
        }

        require_once __DIR__ . '/header.php';
        ?>
        <div class="container-fluid">
            <h2 class="mt-3"><?php echo $id ? 'Editar tipo de conteúdo' : 'Criar novo tipo de conteúdo'; ?></h2>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <div class="mb-3">
                    <label class="form-label" for="name">Slug</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($editing['name'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="label">Rótulo</label>
                    <input type="text" class="form-control" id="label" name="label" value="<?php echo htmlspecialchars($editing['label'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="icon">Ícone (classe Font Awesome)</label>
                    <input type="text" class="form-control" id="icon" name="icon" value="<?php echo htmlspecialchars($editing['icon'] ?? ''); ?>" placeholder="fa fa-file-text">
                </div>
                <?php if ($id): ?>
                <div class="mb-3">
                    <label class="form-label" for="sort_order">Ordem no menu</label>
                    <input type="number" class="form-control" id="sort_order" name="sort_order" value="<?php echo htmlspecialchars($editing['sort_order'] ?? 0); ?>">
                </div>
                <?php endif; ?>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="show_author" name="show_author" <?php echo !empty($editing['show_author']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="show_author">Mostrar autor na listagem</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="show_date" name="show_date" <?php echo !empty($editing['show_date']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="show_date">Mostrar data na listagem</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="show_taxonomies" name="show_taxonomies" <?php echo !isset($editing['show_taxonomies']) || !empty($editing['show_taxonomies']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="show_taxonomies">Mostrar taxonomias na listagem</label>
                </div>
                <a href="<?= BASE_URL ?>content-types" class="btn btn-secondary ms-2"><i class="fa fa-arrow-left"></i> Voltar</a>

                <button type="submit" class="btn btn-primary"><i class="fa <?php echo $id ? 'fa-save' : 'fa-plus'; ?>"></i> <?php echo $id ? 'Atualizar' : 'Criar'; ?></button>
            </form>
        </div>
        <?php
        require_once __DIR__ . '/footer.php';
        return;
    }

    // -- Listagem ------------------------------------------------------
    $types = getContentTypes();
    $deleted = isset($_GET['deleted']);
    $associated = isset($_GET['associated']) ? (int)$_GET['associated'] : 0;

    require_once __DIR__ . '/header.php';
    ?>
    <div class="container-fluid">
        <?php if ($deleted): ?>
            <div class="alert alert-warning mt-3">
                <?php if ($associated): ?>
                    Este tipo de conteúdo tinha <?php echo $associated; ?> conteúdos associados e foi removido.
                <?php else: ?>
                    Tipo de conteúdo removido.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <h2>Tipos de Conteúdo</h2>
        <table class="table table-striped datatable" data-no-sort-last="true">
            <thead><tr><th>Ordem</th><th>Rótulo</th><th>Slug</th><th data-orderable="false">Ícone</th><th>Ações</th></tr></thead>
            <tbody>
            <?php foreach ($types as $type): ?>
                <?php
                    $cnt = countContentByContentType($type['id']);
                    $confirmMsg = $cnt ? "Eliminar este tipo? Existem $cnt conteúdos associados." : 'Eliminar este tipo?';
                ?>
                <tr data-id="<?php echo $type['id']; ?>">
                    <td><?php echo htmlspecialchars($type['sort_order']); ?></td>
                    <td><?php echo htmlspecialchars($type['label']); ?></td>
                    <td><?php echo htmlspecialchars($type['name']); ?></td>
                    <td><i class="<?php echo htmlspecialchars($type['icon']); ?>"></i></td>
                    <td>
                        <a href="<?= BASE_URL . 'fields/' . $type['id']; ?>" class="btn btn-sm btn-info"><i class="fa fa-list-alt"></i> Campos</a>
                        <a href="<?= BASE_URL . 'fields/layout/' . $type['id']; ?>" class="btn btn-sm btn-warning"><i class="fa fa-th"></i> Layout</a>
                      <!--  <a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($type['name'])); ?>/add" class="btn btn-sm btn-success"><i class="fa fa-plus"></i> Adicionar</a>
                        <a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($type['name'])); ?>" class="btn btn-sm btn-secondary"><i class="fa fa-list"></i> Listar</a>
                        <a href="<?= BASE_URL ?>content-types/taxonomies/<?php echo $type['id']; ?>" class="btn btn-sm btn-warning"><i class="fa fa-tags"></i> Taxonomias</a> -->
                        <a href="<?= BASE_URL ?>content-types/edit/<?php echo $type['id']; ?>" class="btn btn-sm btn-primary"><i class="fa fa-pencil"></i> Editar</a>
                        <a href="<?= BASE_URL ?>content-types?delete_id=<?php echo $type['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES); ?>');"><i class="fa fa-trash"></i> Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="mt-3">
            <a href="<?= BASE_URL ?>dashboard" class="btn btn-secondary ms-2"><i class="fa fa-arrow-left"></i> Voltar</a>

            <a class="btn btn-success" href="<?= BASE_URL ?>content-types/add"><i class="fa fa-plus"></i> Adicionar</a>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    return;
}

// Determine content type via id or slug for content entry management
$typeId = 0;
if (isset($_GET['type_slug'])) {
    $contentType = getContentTypeBySlug($_GET['type_slug']);
    if (!$contentType) {
        echo 'Content type not found';
        exit;
    }
    $typeId = (int)$contentType['id'];
} else {
    $typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
    if (!$typeId) {
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }
    $contentType = getContentType($typeId);
    if (!$contentType) {
        echo 'Content type not found';
        exit;
    }
}
$typeSlug = $contentType['name'];
$action = $_GET['action'] ?? '';
$error = '';

if ($action === 'add') {
    $customFields = sortFieldsByGrid(getCustomFields($typeId));
    $usedTaxonomies = [];
    foreach ($customFields as $field) {
        if ($field['type'] === 'taxonomy' || $field['type'] === 'multitaxonomy') {
            $usedTaxonomies[] = (int)$field['options'];
        }
    }
    $allTaxonomies = array_values(array_filter(
        getTaxonomiesForContentType($typeId),
        fn($t) => !in_array((int)$t['id'], $usedTaxonomies)
    ));
    $taxonomyFields = array_map(function ($tax) {
        return [
            'id' => 'tax_' . $tax['id'],
            'label' => $tax['label'],
            'taxonomy_id' => $tax['id'],
            'grid_row' => $tax['grid_row'] ?? 0,
            'grid_col' => $tax['grid_col'] ?? 0,
            'grid_width' => $tax['grid_width'] ?? 12,
        ];
    }, $allTaxonomies);
    $fields = sortFieldsByGrid(array_merge([
        [
            'id' => 'title',
            'label' => 'Título',
            'grid_row' => $contentType['title_grid_row'] ?? 0,
            'grid_col' => $contentType['title_grid_col'] ?? 0,
            'grid_width' => $contentType['title_grid_width'] ?? 12,
        ],
        [
            'id' => 'body',
            'label' => 'Texto',
            'grid_row' => $contentType['body_grid_row'] ?? 0,
            'grid_col' => $contentType['body_grid_col'] ?? 0,
            'grid_width' => $contentType['body_grid_width'] ?? 12,
        ],
    ], $customFields, $taxonomyFields));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            exit('Token CSRF inválido');
        }
        $csrfToken = generateCsrfToken();
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        if ($title === '') {
            $error = 'Title is required';
        } else {
            $contentId = createContent($typeId, currentUser()['id'], $title, $body);
            foreach ($customFields as $field) {
                $fieldName = 'field_' . $field['id'];
                $value = null;
                if ($field['type'] === 'taxonomy' || $field['type'] === 'multitaxonomy') {
                    if ($field['type'] === 'taxonomy') {
                        $single = $_POST[$fieldName] ?? '';
                        $termIds = $single !== '' ? [(int)$single] : [];
                    } else {
                        $termIds = isset($_POST[$fieldName]) ? array_map('intval', (array)$_POST[$fieldName]) : [];
                    }
                    setContentTaxonomyTerms($contentId, (int)$field['options'], $termIds);
                    continue;
                }
                if ($field['type'] === 'image') {
                    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
                        $year = date('Y');
                        $month = date('m');
                        $uploadDir = __DIR__ . '/uploads/' . $slug . '/' . $year . '/' . $month . '/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $filename = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES[$fieldName]['name']);
                        $targetPath = $uploadDir . $filename;
                        if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) {
                            $value = 'uploads/' . $slug . '/' . $year . '/' . $month . '/' . $filename;
                        }
                    }
                } else {
                    $value = $_POST[$fieldName] ?? null;
                    if ($value !== null && $field['type'] === 'datetime' && $value !== '') {
                        $value = str_replace('T', ' ', substr($value, 0, 16));
                    }
                    if ($field['type'] === 'multicontent') {
                        foreach ((array)$value as $val) {
                            saveCustomValue($contentId, $field['id'], (string)$val);
                        }
                        continue;
                    }
                }
                if ($value !== null) {
                    saveCustomValue($contentId, $field['id'], $value);
                }
            }
            foreach ($allTaxonomies as $taxonomy) {
                $termsKey = 'taxonomy_' . $taxonomy['id'];
                $termIds = isset($_POST[$termsKey]) ? (array)$_POST[$termsKey] : [];
                setContentTaxonomyTerms($contentId, $taxonomy['id'], $termIds);
            }
            header('Location: ' . BASE_URL . rawurlencode($typeSlug));
            exit;
        }
    }

    require_once __DIR__ . '/header.php';
    ?>
    <div class="container-fluid">
        <div class="page-title">
            <div class="title_left">
                <h3>Adicionar <?php echo htmlspecialchars($contentType['label']); ?></h3>
            </div>
        </div>
        <div class="clearfix"></div>
        <div class="row">
            <div class="col-md-12 col-sm-12">
                <div class="x_panel">
                    <div class="x_content">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <?php
                                $hasGrid = false;
                                foreach ($fields as $f) {
                                    if (!empty($f['grid_row']) || !empty($f['grid_width'])) {
                                        $hasGrid = true;
                                        break;
                                    }
                                }
                                if ($hasGrid) {
                                    $currentRow = null;
                                    $currentCol = 0;
                                    foreach ($fields as $field) {
                                        $row = $field['grid_row'] ?? 0;
                                        $col = $field['grid_col'] ?? 0;
                                        $width = $field['grid_width'] ?? 12;
                                        if ($row !== $currentRow) {
                                            if ($currentRow !== null) { echo '</div>'; }
                                            echo '<div class="row custom-field-row">';
                                            $currentRow = $row;
                                            $currentCol = 0;
                                        }
                                        $offset = max(0, $col - $currentCol);
                                        $classes = 'col-md-' . (int)$width . ' mb-3';
                                        if ($offset > 0) {
                                            $classes .= ' offset-md-' . $offset;
                                        }
                                        echo '<div class="' . $classes . '">';
                                        if ($field['id'] === 'title') {
                                            echo '<label for="title" class="form-label">Título</label>';
                                            echo '<input type="text" id="title" name="title" class="form-control" required>';
                                        } elseif ($field['id'] === 'body') {
                                            echo '<label for="body" class="form-label">Texto</label>';
                                            echo '<textarea id="body" name="body" class="form-control" rows="4"></textarea>';
                                        } elseif (isset($field['taxonomy_id'])) {
                                            $terms = getTerms($field['taxonomy_id']);
                                            echo '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
                                            echo '<select name="taxonomy_' . htmlspecialchars($field['taxonomy_id']) . '[]" class="form-select" multiple>';
                                            foreach ($terms as $term) {
                                                echo '<option value="' . htmlspecialchars($term['id']) . '">' . htmlspecialchars($term['name']) . '</option>';
                                            }
                                            echo '</select>';
                                        } else {
                                            $inputName = 'field_' . $field['id'];
                                            renderFieldInput($field, $inputName);
                                        }
                                        echo '</div>';
                                        $currentCol = $col + $width;
                                    }
                                    if ($currentRow !== null) { echo '</div>'; }
                                } else {
                                    foreach ($fields as $field) {
                                        echo '<div class="mb-3">';
                                        if ($field['id'] === 'title') {
                                            echo '<label for="title" class="form-label">Título</label>';
                                            echo '<input type="text" id="title" name="title" class="form-control" required>';
                                        } elseif ($field['id'] === 'body') {
                                            echo '<label for="body" class="form-label">Texto</label>';
                                            echo '<textarea id="body" name="body" class="form-control" rows="4"></textarea>';
                                        } elseif (isset($field['taxonomy_id'])) {
                                            $terms = getTerms($field['taxonomy_id']);
                                            echo '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
                                            echo '<select name="taxonomy_' . htmlspecialchars($field['taxonomy_id']) . '[]" class="form-select" multiple>';
                                            foreach ($terms as $term) {
                                                echo '<option value="' . htmlspecialchars($term['id']) . '">' . htmlspecialchars($term['name']) . '</option>';
                                            }
                                            echo '</select>';
                                        } else {
                                            $inputName = 'field_' . $field['id'];
                                            renderFieldInput($field, $inputName);
                                        }
                                        echo '</div>';
                                    }
                                }
                            ?>
                                                        <a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($typeSlug)); ?>" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Cancelar</a>

                            <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar</button>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . '/footer.php';
    exit;
}

if ($action === 'edit') {
    $contentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $content = $contentId ? getContent($contentId) : null;
    if (!$content) {
        header('Location: ' . BASE_URL . 'dashboard');
        exit;
    }

    $customFields = sortFieldsByGrid(getCustomFields($typeId));
    $usedTaxonomies = [];
    foreach ($customFields as $field) {
        if ($field['type'] === 'taxonomy' || $field['type'] === 'multitaxonomy') {
            $usedTaxonomies[] = (int)$field['options'];
        }
    }
    $allTaxonomies = array_values(array_filter(
        getTaxonomiesForContentType($typeId),
        fn($t) => !in_array((int)$t['id'], $usedTaxonomies)
    ));
    $taxonomyFields = array_map(function ($tax) {
        return [
            'id' => 'tax_' . $tax['id'],
            'label' => $tax['label'],
            'taxonomy_id' => $tax['id'],
            'grid_row' => $tax['grid_row'] ?? 0,
            'grid_col' => $tax['grid_col'] ?? 0,
            'grid_width' => $tax['grid_width'] ?? 12,
        ];
    }, $allTaxonomies);
    $fields = sortFieldsByGrid(array_merge([
        [
            'id' => 'title',
            'label' => 'Título',
            'grid_row' => $contentType['title_grid_row'] ?? 0,
            'grid_col' => $contentType['title_grid_col'] ?? 0,
            'grid_width' => $contentType['title_grid_width'] ?? 12,
        ],
        [
            'id' => 'body',
            'label' => 'Texto',
            'grid_row' => $contentType['body_grid_row'] ?? 0,
            'grid_col' => $contentType['body_grid_col'] ?? 0,
            'grid_width' => $contentType['body_grid_width'] ?? 12,
        ],
    ], $customFields, $taxonomyFields));
    $customValues = getCustomValuesForContent($contentId);
    $taxonomyMap = getContentTaxonomy($contentId);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            http_response_code(400);
            exit('Token CSRF inválido');
        }
        $csrfToken = generateCsrfToken();
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        if ($title === '') {
            $error = 'Title is required';
        } else {
            updateContent($contentId, $title, $body);
            deleteCustomValuesForContent($contentId);
            foreach ($customFields as $field) {
                $fieldName = 'field_' . $field['id'];
                $value = null;
                if ($field['type'] === 'taxonomy' || $field['type'] === 'multitaxonomy') {
                    if ($field['type'] === 'taxonomy') {
                        $single = $_POST[$fieldName] ?? '';
                        $termIds = $single !== '' ? [(int)$single] : [];
                    } else {
                        $termIds = isset($_POST[$fieldName]) ? array_map('intval', (array)$_POST[$fieldName]) : [];
                    }
                    setContentTaxonomyTerms($contentId, (int)$field['options'], $termIds);
                    continue;
                }
                if ($field['type'] === 'image') {
                    $existing = $customValues[$field['id']][0] ?? '';
                    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
                        $year = date('Y');
                        $month = date('m');
                        $uploadDir = __DIR__ . '/uploads/' . $slug . '/' . $year . '/' . $month . '/';
                        if (!is_dir($uploadDir)) {
                            mkdir($uploadDir, 0755, true);
                        }
                        $filename = uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $_FILES[$fieldName]['name']);
                        $targetPath = $uploadDir . $filename;
                        if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) {
                            $value = 'uploads/' . $slug . '/' . $year . '/' . $month . '/' . $filename;
                        }
                    } else {
                        $value = $existing;
                        if ($value !== '' && strpos($value, 'uploads/' . $slug . '/') !== 0) {
                            $value = 'uploads/' . $slug . '/' . ltrim($value, '/');
                        }
                    }
                } else {
                    $value = $_POST[$fieldName] ?? null;
                    if ($value !== null && $field['type'] === 'datetime' && $value !== '') {
                        $value = str_replace('T', ' ', substr($value, 0, 16));
                    }
                    if ($field['type'] === 'multicontent') {
                        foreach ((array)$value as $val) {
                            saveCustomValue($contentId, $field['id'], (string)$val);
                        }
                        continue;
                    }
                }
                if ($value !== null) {
                    saveCustomValue($contentId, $field['id'], $value);
                }
            }
            foreach ($allTaxonomies as $taxonomy) {
                $termsKey = 'taxonomy_' . $taxonomy['id'];
                $termIds = isset($_POST[$termsKey]) ? (array)$_POST[$termsKey] : [];
                setContentTaxonomyTerms($contentId, $taxonomy['id'], $termIds);
            }
            header('Location: ' . BASE_URL . rawurlencode($typeSlug));
            exit;
        }
    }

    require_once __DIR__ . '/header.php';
    ?>
    <div class="container-fluid">
        <div class="page-title">
            <div class="title_left">
                <h3>Editar <?php echo htmlspecialchars($contentType['label']); ?></h3>
            </div>
        </div>
        <div class="clearfix"></div>
        <div class="row">
            <div class="col-md-12 col-sm-12">
                <div class="x_panel">
                    <div class="x_content">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                        <?php endif; ?>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <?php
                                $hasGrid = false;
                                foreach ($fields as $f) {
                                    if (!empty($f['grid_row']) || !empty($f['grid_width'])) {
                                        $hasGrid = true;
                                        break;
                                    }
                                }
                                if ($hasGrid) {
                                    $currentRow = null;
                                    $currentCol = 0;
                                    foreach ($fields as $field) {
                                        $row = $field['grid_row'] ?? 0;
                                        $col = $field['grid_col'] ?? 0;
                                        $width = $field['grid_width'] ?? 12;
                                        if ($row !== $currentRow) {
                                            if ($currentRow !== null) { echo '</div>'; }
                                            echo '<div class="row custom-field-row">';
                        $currentRow = $row;
                        $currentCol = 0;
                                        }
                                        $offset = max(0, $col - $currentCol);
                                        $classes = 'col-md-' . (int)$width . ' mb-3';
                                        if ($offset > 0) {
                                            $classes .= ' offset-md-' . $offset;
                                        }
                                        echo '<div class="' . $classes . '">';
                                        if ($field['id'] === 'title') {
                                            echo '<label for="title" class="form-label">Título</label>';
                                            echo '<input type="text" id="title" name="title" class="form-control" value="' . htmlspecialchars($content['title']) . '" required>';
                                        } elseif ($field['id'] === 'body') {
                                            echo '<label for="body" class="form-label">Texto</label>';
                                            echo '<textarea id="body" name="body" class="form-control" rows="4">' . htmlspecialchars($content['body']) . '</textarea>';
                                        } elseif (isset($field['taxonomy_id'])) {
                                            $terms = getTerms($field['taxonomy_id']);
                                            $selected = $taxonomyMap[$field['taxonomy_id']] ?? [];
                                            echo '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
                                            echo '<select name="taxonomy_' . htmlspecialchars($field['taxonomy_id']) . '[]" class="form-select" multiple>';
                                            foreach ($terms as $term) {
                                                $sel = in_array($term['id'], $selected) ? ' selected' : '';
                                                echo '<option value="' . htmlspecialchars($term['id']) . '"' . $sel . '>' . htmlspecialchars($term['name']) . '</option>';
                                            }
                                            echo '</select>';
                                        } else {
                                            $inputName = 'field_' . $field['id'];
                                            if ($field['type'] === 'taxonomy') {
                                                $taxonomyId = (int)$field['options'];
                                                $value = $taxonomyMap[$taxonomyId][0] ?? '';
                                            } elseif ($field['type'] === 'multitaxonomy') {
                                                $taxonomyId = (int)$field['options'];
                                                $value = $taxonomyMap[$taxonomyId] ?? [];
                                            } else {
                                                $raw = $customValues[$field['id']] ?? [];
                                                $value = $field['type'] === 'multicontent' ? $raw : ($raw[0] ?? '');
                                            }
                                            renderFieldInput($field, $inputName, $value);
                                        }
                                        echo '</div>';
                                        $currentCol = $col + $width;
                                    }
                                    if ($currentRow !== null) { echo '</div>'; }
                                } else {
                                    foreach ($fields as $field) {
                                        echo '<div class="mb-3">';
                                        if ($field['id'] === 'title') {
                                            echo '<label for="title" class="form-label">Título</label>';
                                            echo '<input type="text" id="title" name="title" class="form-control" value="' . htmlspecialchars($content['title']) . '" required>';
                                        } elseif ($field['id'] === 'body') {
                                            echo '<label for="body" class="form-label">Texto</label>';
                                            echo '<textarea id="body" name="body" class="form-control" rows="4">' . htmlspecialchars($content['body']) . '</textarea>';
                                        } elseif (isset($field['taxonomy_id'])) {
                                            $terms = getTerms($field['taxonomy_id']);
                                            $selected = $taxonomyMap[$field['taxonomy_id']] ?? [];
                                            echo '<label class="form-label">' . htmlspecialchars($field['label']) . '</label>';
                        echo '<select name="taxonomy_' . htmlspecialchars($field['taxonomy_id']) . '[]" class="form-select" multiple>';
                        foreach ($terms as $term) {
                            $sel = in_array($term['id'], $selected) ? ' selected' : '';
                            echo '<option value="' . htmlspecialchars($term['id']) . '"' . $sel . '>' . htmlspecialchars($term['name']) . '</option>';
                        }
                        echo '</select>';
                                        } else {
                                            $inputName = 'field_' . $field['id'];
                                            if ($field['type'] === 'taxonomy') {
                                                $taxonomyId = (int)$field['options'];
                                                $value = $taxonomyMap[$taxonomyId][0] ?? '';
                                            } elseif ($field['type'] === 'multitaxonomy') {
                                                $taxonomyId = (int)$field['options'];
                                                $value = $taxonomyMap[$taxonomyId] ?? [];
                                            } else {
                                                $raw = $customValues[$field['id']] ?? [];
                                                $value = $field['type'] === 'multicontent' ? $raw : ($raw[0] ?? '');
                                            }
                                            renderFieldInput($field, $inputName, $value);
                                        }
                                        echo '</div>';
                                    }
                                }
                            ?>
                                                        <a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($typeSlug)); ?>" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Cancelar</a>

                            <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Guardar</button>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php require_once __DIR__ . '/footer.php';
    exit;
}

if (isset($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    $content = getContent($deleteId);
    if ($content && (int)$content['content_type_id'] === $typeId) {
        deleteContent($deleteId);
    }
    header('Location: ' . BASE_URL . rawurlencode($typeSlug));
    exit;
}

$customFields = array_values(array_filter(sortFieldsByGrid(getCustomFields($typeId)), function ($f) {
    return !empty($f['show_in_list']);
}));
$showTax = !empty($contentType['show_taxonomies']);
$allTaxonomies = $showTax ? getTaxonomiesForContentType($typeId) : [];

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <div class="page-title">
        <div class="title_left">
            <h3><?php echo htmlspecialchars($contentType['label']); ?> </h3>
        </div>
    </div>
    <div class="clearfix"></div>
    <div class="row">
        <div class="col-md-12 col-sm-12">
            <div class="x_panel">
                <div class="x_content">

                    <table class="table table-striped datatable" data-source="data/list_content.php" data-type-id="<?php echo $typeId; ?>" data-no-sort-last="true">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <?php if (!empty($contentType['show_author'])): ?>
                                    <th>Author</th>
                                <?php endif; ?>
                                <?php if (!empty($contentType['show_date'])): ?>
                                    <th>Date</th>
                                <?php endif; ?>
                                <?php foreach ($customFields as $field): ?>
                                    <th<?php echo empty($field['sortable']) ? ' data-orderable="false"' : ''; ?>><?php echo htmlspecialchars($field['label']); ?></th>
                                <?php endforeach; ?>
                                <?php if ($showTax): ?>
                                    <?php foreach ($allTaxonomies as $tax): ?>
                                        <th><?php echo htmlspecialchars($tax['label']); ?></th>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <th data-orderable="false">Ações</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div class="mt-3">
                        <a href="<?= BASE_URL ?>dashboard" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Voltar</a>
                        <a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($typeSlug)); ?>/add" class="btn btn-success ms-2"><i class="fa fa-plus"></i> Adicionar</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
