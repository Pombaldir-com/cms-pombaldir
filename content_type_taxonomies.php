<?php
/**
 * Associate taxonomies with a specific content type.
 */

require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

$typeId = isset($_GET['type_id']) ? (int)$_GET['type_id'] : 0;
$type   = $typeId ? getContentType($typeId) : null;
if (!$type) {
    echo "Tipo de conteúdo inválido.";
    exit;
}

$allTaxonomies = getTaxonomies();
$fields = getCustomFields($typeId);
$usedTaxonomies = [];
foreach ($fields as $field) {
    if ($field['type'] === 'taxonomy') {
        $usedTaxonomies[] = (int)$field['options'];
    }
}
$allTaxonomies = array_filter($allTaxonomies, fn($t) => !in_array((int)$t['id'], $usedTaxonomies));
$current = array_map(fn($t) => (int)$t['id'], getTaxonomiesForContentType($typeId));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = isset($_POST['taxonomies']) ? array_map('intval', (array)$_POST['taxonomies']) : [];
    setContentTypeTaxonomies($typeId, $selected);
    header('Location: content_type_taxonomies.php?type_id=' . $typeId);
    exit;
}

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <h2 class="mt-3">Taxonomias para <?php echo htmlspecialchars($type['label']); ?></h2>
    <form method="post">
        <?php foreach ($allTaxonomies as $tax): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="tax_<?php echo $tax['id']; ?>" name="taxonomies[]" value="<?php echo $tax['id']; ?>" <?php echo in_array($tax['id'], $current) ? 'checked' : ''; ?>>
                <label class="form-check-label" for="tax_<?php echo $tax['id']; ?>"><?php echo htmlspecialchars($tax['label']); ?></label>
            </div>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary mt-3"><i class="fa fa-save"></i> Guardar</button>
        <a href="content_types.php" class="btn btn-secondary mt-3 ms-2"><i class="fa fa-arrow-left"></i> Voltar</a>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
