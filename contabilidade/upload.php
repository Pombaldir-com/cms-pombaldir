<?php
$useDropzone = true;
require_once __DIR__ . '/../header.php';
$csrfToken = generateCsrfToken();
?>
<form id="multi-upload" class="dropzone">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
</form>



<?php

function checkQrRequirements() {
    $ok = true;

    // 1. Extensão Imagick ou GD
    $hasImagick = extension_loaded('imagick');
    $hasGd      = extension_loaded('gd');
    if (!$hasImagick && !$hasGd) {
        echo "Falta Imagick ou GD. Pelo menos um é necessário.<br>";
        $ok = false;
    }

    // 2. Extensão Imagick para leitura de PDF, caso o pdftoppm não exista
    $hasPdftoppm = !empty(shell_exec('which pdftoppm'));
    if (!$hasPdftoppm && !$hasImagick) {
        echo "Nem pdftoppm nem Imagick estão disponíveis para converter PDFs.<br>";
        $ok = false;
    }

    return $ok;
}


if (!checkQrRequirements()) {
    echo "Dependências ausentes. Verifique seu ambiente MAMP.";
} 

?>

<script src="assets/js/accounting_upload.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>
