<?php
require_once __DIR__ . '/../header.php';
$csrfToken = generateCsrfToken();
?>
<form id="multi-upload" class="dropzone">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
</form>

<script src="vendors/dropzone/dist/dropzone-min.js"></script>

<script src="assets/js/accounting_upload.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>
