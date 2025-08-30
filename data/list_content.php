<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

header('Content-Type: application/json');

$typeId = isset($_POST['type_id']) ? (int)$_POST['type_id'] : 0;
if (!$typeId) {
    echo json_encode(['data' => []]);
    exit;
}

$contentType = getContentType($typeId);
if (!$contentType) {
    echo json_encode(['data' => []]);
    exit;
}

$customFields = array_values(array_filter(getCustomFields($typeId), function ($f) {
    return !empty($f['show_in_list']);
}));
$allTaxonomies = getTaxonomiesForContentType($typeId);
$contents = getContentList($typeId);

$data = [];
foreach ($contents as $content) {
    $row = [htmlspecialchars($content['title'])];
    if (!empty($contentType['show_author'])) {
        $row[] = htmlspecialchars($content['author_name']);
    }
    if (!empty($contentType['show_date'])) {
        $row[] = htmlspecialchars($content['created_at']);
    }

    foreach ($customFields as $field) {
        $fieldId = $field['id'];
        $fieldValue = '';
        foreach ($content['fields'] as $cv) {
            if ($cv['field_id'] == $fieldId) {
                $fieldValue = $cv['value'];
                break;
            }
        }

        if ($field['type'] === 'taxonomy' && $fieldValue !== '') {
            $term = getTerm((int)$fieldValue);
            $fieldValue = $term ? $term['name'] : $fieldValue;
        } elseif ($field['type'] === 'content' && $fieldValue !== '') {
            $related = getContent((int)$fieldValue);
            $fieldValue = $related ? $related['title'] : $fieldValue;
        }

        $row[] = htmlspecialchars($fieldValue);
    }

    foreach ($allTaxonomies as $tax) {
        $termsList = [];
        foreach ($content['taxonomies'] as $assoc) {
            if ($assoc['taxonomy_id'] == $tax['id']) {
                $termsList[] = $assoc['term_name'];
            }
        }
        $row[] = htmlspecialchars(implode(', ', $termsList));
    }

    $actions = '<a href="edit_content.php?id=' . $content['id'] . '" class="btn btn-sm btn-primary"><i class="fa fa-pencil"></i> Editar</a> ';
    $actions .= '<a href="list_content.php?type_id=' . $typeId . '&delete=' . $content['id'] . '" class="btn btn-sm btn-danger" onclick="return confirm(\'Apagar este conteúdo?\');"><i class="fa fa-trash"></i> Apagar</a>';
    $row[] = $actions;

    $data[] = $row;
}

echo json_encode(['data' => $data]);
