<?php
/**
 * Página para editar um conteúdo existente.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$contentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$content = $contentId ? getContent($contentId) : null;
if (!$content) {
    header('Location: dashboard.php');
    exit;
}

$typeId = (int)$content['content_type_id'];
$contentType = getContentType($typeId);
$typeSlug = $contentType['name'];
$customFields = getCustomFields($typeId);
$allTaxonomies = getTaxonomiesForContentType($typeId);
$customValues = getCustomValuesForContent($contentId);
$taxonomyMap = getContentTaxonomy($contentId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    if ($title === '') {
        $error = 'Title is required';
    } else {
        updateContent($contentId, $title, $body);
        deleteCustomValuesForContent($contentId);
        foreach ($customFields as $field) {
            $fieldName = 'field_' . $field['id'];
            $value = $_POST[$fieldName] ?? null;
            if ($value !== null) {
                if ($field['type'] === 'datetime' && $value !== '') {
                    $value = str_replace('T', ' ', substr($value, 0, 16));
                }
                saveCustomValue($contentId, $field['id'], $value);
            }
        }
        foreach ($allTaxonomies as $taxonomy) {
            $termsKey = 'taxonomy_' . $taxonomy['id'];
            $termIds = isset($_POST[$termsKey]) ? (array)$_POST[$termsKey] : [];
            setContentTaxonomyTerms($contentId, $taxonomy['id'], $termIds);
        }
        header('Location: /tipode-conteudo/' . rawurlencode($typeSlug));
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
                    <form method="post">
                        <div class="mb-3">
                            <label for="title" class="form-label">Title</label>
                            <input type="text" id="title" name="title" class="form-control" value="<?php echo htmlspecialchars($content['title']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="body" class="form-label">Body</label>
                            <textarea id="body" name="body" class="form-control" rows="4"><?php echo htmlspecialchars($content['body']); ?></textarea>
                        </div>
                        <?php foreach ($customFields as $field): ?>
                            <?php
                                $inputName = 'field_' . $field['id'];
                                $options   = $field['options'];
                                $isRequired = $field['required'] ? 'required' : '';
                                $value = $customValues[$field['id']] ?? '';
                            ?>
                            <div class="mb-3">
                                <label class="form-label"><?php echo htmlspecialchars($field['label']); ?></label>
                                <?php if ($field['type'] === 'text'): ?>
                                    <input type="text" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" value="<?php echo htmlspecialchars($value); ?>" <?php echo $isRequired; ?>>
                                <?php elseif ($field['type'] === 'textarea'): ?>
                                    <textarea name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" <?php echo $isRequired; ?>><?php echo htmlspecialchars($value); ?></textarea>
                                <?php elseif ($field['type'] === 'number'): ?>
                                    <input type="number" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" value="<?php echo htmlspecialchars($value); ?>" <?php echo $isRequired; ?>>
                                <?php elseif ($field['type'] === 'date'): ?>
                                    <input type="date" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" value="<?php echo htmlspecialchars($value); ?>" <?php echo $isRequired; ?>>
                                <?php elseif ($field['type'] === 'datetime'): ?>
                                    <?php $formatted = $value ? str_replace(' ', 'T', substr($value, 0, 16)) : ''; ?>
                                    <input type="datetime-local" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" value="<?php echo htmlspecialchars($formatted); ?>" <?php echo $isRequired; ?>>
                                <?php elseif ($field['type'] === 'select'): ?>
                                    <select name="<?php echo htmlspecialchars($inputName); ?>" class="form-select" <?php echo $isRequired; ?>>
                                        <option value="">-- Select --</option>
                                        <?php foreach (explode(',', $options) as $opt): $optTrim = trim($opt); ?>
                                            <option value="<?php echo htmlspecialchars($optTrim); ?>" <?php echo $value === $optTrim ? 'selected' : ''; ?>><?php echo htmlspecialchars($optTrim); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($field['type'] === 'taxonomy'): ?>
                                    <?php $terms = getTerms((int)$options); ?>
                                    <select name="<?php echo htmlspecialchars($inputName); ?>" class="form-select" <?php echo $isRequired; ?>>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($terms as $term): ?>
                                            <option value="<?php echo htmlspecialchars($term['id']); ?>" <?php echo $value == $term['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($term['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($field['type'] === 'content'): ?>
                                    <?php $entries = getContentList((int)$options); ?>
                                    <select name="<?php echo htmlspecialchars($inputName); ?>" class="form-select" <?php echo $isRequired; ?>>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($entries as $entry): ?>
                                            <option value="<?php echo htmlspecialchars($entry['id']); ?>" <?php echo $value == $entry['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($entry['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($allTaxonomies as $taxonomy): ?>
                            <?php $terms = getTerms($taxonomy['id']); $selected = $taxonomyMap[$taxonomy['id']] ?? []; ?>
                            <div class="mb-3">
                                <label class="form-label">Select <?php echo htmlspecialchars($taxonomy['label']); ?></label>
                                <select name="taxonomy_<?php echo htmlspecialchars($taxonomy['id']); ?>[]" class="form-select" multiple>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo htmlspecialchars($term['id']); ?>" <?php echo in_array($term['id'], $selected) ? 'selected' : ''; ?>><?php echo htmlspecialchars($term['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Save</button>
                        <a href="/tipode-conteudo/<?php echo htmlspecialchars(rawurlencode($typeSlug)); ?>" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
