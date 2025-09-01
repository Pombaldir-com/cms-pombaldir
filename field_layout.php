<?php
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();
requireRole(2);

$typeId = isset($_GET['type_id']) ? (int) $_GET['type_id'] : 0;
$type = $typeId ? getContentType($typeId) : null;
if (!$type) {
    echo 'Tipo de conteúdo inválido.';
    exit;
}

$fields = getCustomFields($typeId);

require_once __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gridstack@9.2.2/dist/gridstack.min.css">
<div class="container-fluid">
    <h2 class="mt-3">Layout de campos para <?= htmlspecialchars($type['label']) ?></h2>
    <div class="grid-stack">
        <?php foreach ($fields as $field): ?>
            <div class="grid-stack-item" data-gs-id="<?= $field['id'] ?>" data-gs-x="<?= $field['grid_col'] ?>" data-gs-y="<?= $field['grid_row'] ?>" data-gs-width="<?= $field['grid_width'] ?>" data-gs-height="1">
                <div class="grid-stack-item-content">
                    <?= htmlspecialchars($field['label']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <a href="<?= BASE_URL . 'fields/' . $typeId; ?>" class="btn btn-secondary mt-3"><i class="fa fa-arrow-left"></i> Voltar</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/gridstack@9.2.2/dist/gridstack-all.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const grid = GridStack.init({float: true});
    grid.on('change', function(event, items) {
        items.forEach(item => {
            const params = new URLSearchParams();
            params.append('field_id', item.id);
            params.append('row', item.y);
            params.append('col', item.x);
            params.append('width', item.w);
            fetch('<?= BASE_URL ?>fields/save-layout', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: params.toString()
            });
        });
    });
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
