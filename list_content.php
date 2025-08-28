<?php
/**
 * list_content.php
 *
 * Displays a list of content entries for a given content type. It shows the title, author,
 * creation date, and values of custom fields along with taxonomy terms for each entry.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

// Content type ID from query
$typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
if (!$typeId) {
    header('Location: dashboard.php');
    exit;
}

// Fetch content type
$contentType = getContentType($typeId);
if (!$contentType) {
    echo 'Content type not found';
    exit;
}

// Handle deletion of a content entry
if (isset($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    $content = getContent($deleteId);
    if ($content && (int)$content['content_type_id'] === $typeId) {
        deleteContent($deleteId);
    }
    header('Location: list_content.php?type_id=' . $typeId);
    exit;
}

// Get custom fields (only those marked to show in list) and taxonomies
$customFields = array_values(array_filter(getCustomFields($typeId), function ($f) {
    return !empty($f['show_in_list']);
}));
$allTaxonomies = getTaxonomiesForContentType($typeId);

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <div class="page-title">
        <div class="title_left">
            <h3><?php echo htmlspecialchars($contentType['label']); ?> List</h3>
        </div>
    </div>
    <div class="clearfix"></div>
    <div class="row">
        <div class="col-md-12 col-sm-12">
            <div class="x_panel">
                <div class="x_content">
                    <a href="add_content.php?type_id=<?php echo $typeId; ?>" class="btn btn-success mb-3">Add New</a>
                    <table class="table table-striped datatable" data-source="data/list_content.php" data-type-id="<?php echo $typeId; ?>">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Author</th>
                                <th>Date</th>
                                <?php foreach ($customFields as $field): ?>
                                    <th><?php echo htmlspecialchars($field['label']); ?></th>
                                <?php endforeach; ?>
                                <?php foreach ($allTaxonomies as $tax): ?>
                                    <th><?php echo htmlspecialchars($tax['label']); ?></th>
                                <?php endforeach; ?>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <a href="dashboard.php" class="btn btn-secondary">Back</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

