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

// Get custom fields, taxonomies and content list
$customFields = getCustomFields($typeId);
$contents = getContentList($typeId);
$allTaxonomies = getAllTaxonomies();

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
                    <table class="table table-striped datatable">
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
                        <tbody>
                            <?php foreach ($contents as $content): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($content['title']); ?></td>
                                    <td><?php echo htmlspecialchars($content['author_name']); ?></td>
                                    <td><?php echo htmlspecialchars($content['created_at']); ?></td>
                                    <?php foreach ($customFields as $field): ?>
                                        <?php
                                            $fieldId = $field['id'];
                                            $fieldValue = '';
                                            foreach ($content['fields'] as $cv) {
                                                if ($cv['field_id'] == $fieldId) {
                                                    $fieldValue = $cv['value'];
                                                    break;
                                                }
                                            }
                                        ?>
                                        <td><?php echo htmlspecialchars($fieldValue); ?></td>
                                    <?php endforeach; ?>
                                    <?php foreach ($allTaxonomies as $tax): ?>
                                        <?php
                                            $termsList = [];
                                            foreach ($content['taxonomies'] as $assoc) {
                                                if ($assoc['taxonomy_id'] == $tax['id']) {
                                                    $termsList[] = $assoc['term_name'];
                                                }
                                            }
                                        ?>
                                        <td><?php echo htmlspecialchars(implode(', ', $termsList)); ?></td>
                                    <?php endforeach; ?>
                                    <td>
                                        <a href="edit_content.php?id=<?php echo $content['id']; ?>" class="btn btn-sm btn-primary">Editar</a>
                                        <a href="list_content.php?type_id=<?php echo $typeId; ?>&delete=<?php echo $content['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Apagar este conteúdo?');">Apagar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <a href="dashboard.php" class="btn btn-secondary">Back</a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>

