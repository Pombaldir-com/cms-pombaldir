<?php
/**
 * list_content.php
 *
 * Displays a list of content entries for a given content type. It shows the title, author,
 * creation date, and values of custom fields along with taxonomy terms for each entry.
 */

require_once 'db.php';
require_once 'functions.php';

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

// Get custom fields, taxonomies and content list
$customFields = getCustomFields($typeId);
$contents = getContentList($typeId);
$allTaxonomies = getAllTaxonomies();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>List <?php echo htmlspecialchars($contentType['name']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Gentelella CSS -->
    <link href="https://colorlibhq.github.io/gentelella/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://colorlibhq.github.io/gentelella/css/custom.min.css" rel="stylesheet">
</head>
<body class="nav-md">
    <div class="container body">
        <div class="main_container">
            <div class="right_col" role="main">
                <div class="page-title">
                    <div class="title_left">
                        <h3><?php echo htmlspecialchars($contentType['name']); ?> List</h3>
                    </div>
                </div>
                <div class="clearfix"></div>
                <div class="row">
                    <div class="col-md-12 col-sm-12 ">
                        <div class="x_panel">
                            <div class="x_content">
                                <a href="add_content.php?type_id=<?php echo $typeId; ?>" class="btn btn-success mb-3">Add New</a>
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Author</th>
                                            <th>Date</th>
                                            <?php foreach ($customFields as $field): ?>
                                                <th><?php echo htmlspecialchars($field['name']); ?></th>
                                            <?php endforeach; ?>
                                            <?php foreach ($allTaxonomies as $tax): ?>
                                                <th><?php echo htmlspecialchars($tax['name']); ?></th>
                                            <?php endforeach; ?>
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
        </div>
    </div>
</body>
</html>