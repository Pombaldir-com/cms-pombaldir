<?php
/**
 * add_content.php
 *
 * This page allows administrators to create new content entries for a chosen content type.
 * It dynamically generates form inputs for each custom field defined for the content type
 * and provides multi-select lists for taxonomy terms associated with any defined taxonomies.
 * After submitting the form, it saves the content, custom field values, and taxonomy
 * assignments using functions from functions.php.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

// Ensure a content type ID is provided
$typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
if (!$typeId) {
    header('Location: dashboard.php');
    exit;
}

// Fetch content type and associated fields
$contentType = getContentType($typeId);
if (!$contentType) {
    echo 'Content type not found';
    exit;
}
// Get custom fields and taxonomies
$customFields = getCustomFields($typeId);
$allTaxonomies = getTaxonomiesForContentType($typeId);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body'] ?? '');
    if ($title === '') {
        $error = 'Title is required';
    } else {
        // Create content entry
        $contentId = createContent($typeId, currentUser()['id'], $title, $body);
        // Save custom field values
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
        // Save taxonomy term selections
        foreach ($allTaxonomies as $taxonomy) {
            $termsKey = 'taxonomy_' . $taxonomy['id'];
            $termIds = isset($_POST[$termsKey]) ? (array)$_POST[$termsKey] : [];
            setContentTaxonomyTerms($contentId, $taxonomy['id'], $termIds);
        }
        header('Location: list_content.php?type_id=' . $typeId);
        exit;
    }
}

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <div class="page-title">
        <div class="title_left">
            <h3>Add <?php echo htmlspecialchars($contentType['label']); ?></h3>
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
                            <input type="text" id="title" name="title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="body" class="form-label">Body</label>
                            <textarea id="body" name="body" class="form-control" rows="4"></textarea>
                        </div>
                        <?php foreach ($customFields as $field): ?>
                            <?php
                                $inputName = 'field_' . $field['id'];
                                $options   = $field['options'];
                                $isRequired = $field['required'] ? 'required' : '';
                            ?>
                            <div class="mb-3">
                                <label class="form-label"><?php echo htmlspecialchars($field['label']); ?></label>
                                <?php if ($field['type'] === 'text'): ?>
                                    <input type="text" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" <?php echo $isRequired; ?>>
                                <?php elseif ($field['type'] === 'textarea'): ?>
                                    <textarea name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" <?php echo $isRequired; ?>></textarea>
                                <?php elseif ($field['type'] === 'number'): ?>
                                    <input type="number" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" <?php echo $isRequired; ?>>
                                <?php elseif ($field['type'] === 'date'): ?>
                                    <input type="date" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" <?php echo $isRequired; ?>>
                                <?php elseif ($field['type'] === 'datetime'): ?>
                                    <input type="datetime-local" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control" <?php echo $isRequired; ?>>
                                <?php elseif ($field['type'] === 'select'): ?>
                                    <select name="<?php echo htmlspecialchars($inputName); ?>" class="form-select" <?php echo $isRequired; ?>>
                                        <option value="">-- Select --</option>
                                        <?php foreach (explode(',', $options) as $opt): ?>
                                            <option value="<?php echo htmlspecialchars(trim($opt)); ?>"><?php echo htmlspecialchars(trim($opt)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($field['type'] === 'taxonomy'): ?>
                                    <?php $terms = getTerms((int)$options); ?>
                                    <select name="<?php echo htmlspecialchars($inputName); ?>" class="form-select" <?php echo $isRequired; ?>>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($terms as $term): ?>
                                            <option value="<?php echo htmlspecialchars($term['id']); ?>"><?php echo htmlspecialchars($term['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($field['type'] === 'content'): ?>
                                    <?php $entries = getContentList((int)$options); ?>
                                    <select name="<?php echo htmlspecialchars($inputName); ?>" class="form-select" <?php echo $isRequired; ?>>
                                        <option value="">-- Select --</option>
                                        <?php foreach ($entries as $entry): ?>
                                            <option value="<?php echo htmlspecialchars($entry['id']); ?>"><?php echo htmlspecialchars($entry['title']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($allTaxonomies as $taxonomy): ?>
                            <div class="mb-3">
                                <label class="form-label">Select <?php echo htmlspecialchars($taxonomy['label']); ?></label>
                                <?php $terms = getTerms($taxonomy['id']); ?>
                                <select name="taxonomy_<?php echo htmlspecialchars($taxonomy['id']); ?>[]" class="form-select" multiple>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo htmlspecialchars($term['id']); ?>"><?php echo htmlspecialchars($term['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-success"><i class="fa fa-save"></i> Save</button>
                        <a href="dashboard.php" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

