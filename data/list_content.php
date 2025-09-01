<?php
require_once __DIR__ . '/db.php';
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
$typeSlug = $contentType['name'];

$customFields = array_values(array_filter(
    sortFieldsByGrid(getCustomFields($typeId)),
    function ($f) {
        return !empty($f['show_in_list']);
    }
));
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
        // Taxonomy-type custom fields store their selections in the
        // content_taxonomy table rather than custom_values. Fetch the
        // term name from the preloaded taxonomy assignments.
        if ($field['type'] === 'taxonomy') {
            $termName = '';
            $taxonomyId = (int)$field['options'];
            foreach ($content['taxonomies'] as $assoc) {
                if ($assoc['taxonomy_id'] == $taxonomyId) {
                    $termName = $assoc['term_name'] !== null ? $assoc['term_name'] : 'Removido';
                    break;
                }
            }
            $row[] = htmlspecialchars($termName);
            continue;
        }

        $fieldId = $field['id'];
        $fieldValue = '';
        foreach ($content['fields'] as $cv) {
            if ($cv['field_id'] == $fieldId) {
                $fieldValue = $cv['value'];
                break;
            }
        }

        if ($field['type'] === 'content' && $fieldValue !== '') {
            $related = getContent((int)$fieldValue);
            $fieldValue = $related ? $related['title'] : 'Removido';
        }

        if ($field['type'] === 'image' && $fieldValue !== '') {
            $row[] = '<img src="' . htmlspecialchars($fieldValue) . '" style="max-width:100px;">';
        } else {
            $row[] = htmlspecialchars($fieldValue);
        }
    }

    foreach ($allTaxonomies as $tax) {
        $termsList = [];
        foreach ($content['taxonomies'] as $assoc) {
            if ($assoc['taxonomy_id'] == $tax['id']) {
                $termsList[] = $assoc['term_name'] !== null ? $assoc['term_name'] : 'Removido';
            }
        }
        $row[] = htmlspecialchars(implode(', ', $termsList));
    }

    // BASE_URL inside this script may include a trailing "/data" segment
    // because the file lives in a subdirectory. Remove any trailing
    // "/data" so that generated links point to the CMS root.
    $cmsBase  = preg_replace('#/data/?$#', '', rtrim(BASE_URL, '/')) . '/';
    $typeBase = $cmsBase . rawurlencode($typeSlug);
    $editUrl  = $typeBase . '/edit/' . $content['id'];
    $deleteUrl = $typeBase . '?delete=' . $content['id'];
    $actions = '<a href="' . $editUrl . '" class="btn btn-sm btn-primary"><i class="fa fa-pencil"></i> Editar</a> ';
    $actions .= '<a href="' . $deleteUrl . '" class="btn btn-sm btn-danger" onclick="return confirm(\'Apagar este conteúdo?\');"><i class="fa fa-trash"></i> Apagar</a>';
    $row[] = $actions;

    $data[] = $row;
}

echo json_encode(['data' => $data]);
