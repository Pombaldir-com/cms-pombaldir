<?php
/**
 * list_content.php
 *
 * Displays a list of content entries for a given content type. It shows the title and,
 * depending on the content type settings, the author and creation date. Custom fields
 * marked for listing and taxonomy terms are also displayed for each entry.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

// Determine content type via id or slug
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

// Handle deletion of a content entry
if (isset($_GET['delete'])) {
    $deleteId = (int)$_GET['delete'];
    $content = getContent($deleteId);
    if ($content && (int)$content['content_type_id'] === $typeId) {
        deleteContent($deleteId);
    }
    header('Location: ' . BASE_URL . rawurlencode($typeSlug));
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
                    <a href="<?= BASE_URL ?><?php echo htmlspecialchars(rawurlencode($typeSlug)); ?>/add" class="btn btn-success mb-3"><i class="fa fa-plus"></i> Add New</a>
                    <table class="table table-striped datatable" data-source="data/list_content.php" data-type-id="<?php echo $typeId; ?>">
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
                                <?php foreach ($allTaxonomies as $tax): ?>
                                    <th><?php echo htmlspecialchars($tax['label']); ?></th>
                                <?php endforeach; ?>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <a href="<?= BASE_URL ?>dashboard" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Back</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

