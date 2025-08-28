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
$allTaxonomies = getAllTaxonomies();

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
            <h3>Add <?php echo htmlspecialchars($contentType['name']); ?></h3>
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
                                $options = $field['options'];
                            ?>
                            <div class="mb-3">
                                <label class="form-label"><?php echo htmlspecialchars($field['name']); ?></label>
                                <?php if ($field['field_type'] === 'text'): ?>
                                    <input type="text" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control">
                                <?php elseif ($field['field_type'] === 'textarea'): ?>
                                    <textarea name="<?php echo htmlspecialchars($inputName); ?>" class="form-control"></textarea>
                                <?php elseif ($field['field_type'] === 'number'): ?>
                                    <input type="number" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control">
                                <?php elseif ($field['field_type'] === 'date'): ?>
                                    <input type="date" name="<?php echo htmlspecialchars($inputName); ?>" class="form-control">
                                <?php elseif ($field['field_type'] === 'select'): ?>
                                    <select name="<?php echo htmlspecialchars($inputName); ?>" class="form-select">
                                        <option value="">-- Select --</option>
                                        <?php foreach (explode(',', $options) as $opt): ?>
                                            <option value="<?php echo htmlspecialchars(trim($opt)); ?>"><?php echo htmlspecialchars(trim($opt)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($allTaxonomies as $taxonomy): ?>
                            <div class="mb-3">
                                <label class="form-label">Select <?php echo htmlspecialchars($taxonomy['name']); ?></label>
                                <?php $terms = getTerms($taxonomy['id']); ?>
                                <select name="taxonomy_<?php echo htmlspecialchars($taxonomy['id']); ?>[]" class="form-select" multiple>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo htmlspecialchars($term['id']); ?>"><?php echo htmlspecialchars($term['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-success">Save</button>
                        <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

