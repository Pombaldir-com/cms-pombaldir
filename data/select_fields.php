<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../functions.php';

startSession();
requireLogin();

header('Content-Type: application/json');

$typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
if (!$typeId) {
    echo json_encode(['fields' => []]);
    exit;
}

$fields = getCustomFields($typeId);
$result = [];

foreach ($fields as $field) {
    if ($field['type'] === 'select') {
        $opts = array_map('trim', array_filter(explode(',', $field['options'])));
        $options = [];
        foreach ($opts as $o) {
            $options[] = ['value' => $o, 'label' => $o];
        }
        $result[] = [
            'id' => (string)$field['id'],
            'label' => $field['label'],
            'options' => $options,
        ];
    } elseif ($field['type'] === 'taxonomy' || $field['type'] === 'multitaxonomy') {
        $taxId = (int)$field['options'];
        $terms = getTerms($taxId);
        if ($terms) {
            $options = [];
            foreach ($terms as $term) {
                $options[] = ['value' => (string)$term['id'], 'label' => $term['name']];
            }
            $result[] = [
                'id' => 'tax_' . $taxId,
                'label' => $field['label'],
                'options' => $options,
            ];
        }
    }
}

echo json_encode(['fields' => $result]);
