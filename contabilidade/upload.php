<?php
$useDropzone = true;
$useDataTables = true;
require_once __DIR__ . '/../header.php';
$csrfToken = generateCsrfToken();
?>
<form id="multi-upload" class="dropzone">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>">
</form>

<div id="qr-results">
    <table id="qr-table" class="datatable table table-striped">
        <thead>
            <tr>
                <th>[A]</th>
                <th>[B]</th>
                <th>[C]</th>
                <th>[D]</th>
                <th>[E]</th>
                <th>[F]</th>
                <th>[G]</th>
                <th>[H]</th>
                <th>[I1]</th>
                <th>[I7]</th>
                <th>[I8]</th>
                <th>[N]</th>
                <th>[O]</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
    </div>



<?php

function checkQrRequirements(array &$messages): bool {
    $messages = [];
    $ok = true;

    // 1. Extensão Imagick ou GD
    $hasImagick = extension_loaded('imagick');
    $hasGd      = extension_loaded('gd');
    if (!$hasImagick && !$hasGd) {
        $messages[] = 'Falta Imagick ou GD. Pelo menos um é necessário.';
        $ok = false;
    }

    // 2. Verificar a existência do pdftoppm
    $hasPdftoppm = false;
    if (function_exists('proc_open')) {
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open('command -v pdftoppm', $descriptor, $pipes);
        if (is_resource($process)) {
            $output = stream_get_contents($pipes[1]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $status = proc_close($process);
            $hasPdftoppm = ($status === 0 && !empty(trim($output)));
        }
    } elseif (function_exists('shell_exec')) {
        $hasPdftoppm = !empty(shell_exec('command -v pdftoppm'));
    }

    if (!$hasPdftoppm && !$hasImagick) {
        $messages[] = 'Nem pdftoppm nem Imagick estão disponíveis para converter PDFs.';
        $ok = false;
    }

    return $ok;
}


$messages = [];
if (!checkQrRequirements($messages)) {
    foreach ($messages as $message) {
        echo $message . '<br>';
    }
    echo 'Dependências ausentes. Verifique seu ambiente MAMP.';
}

?>

<script src="assets/js/accounting_upload.js"></script>
<?php require_once __DIR__ . '/../footer.php'; ?>
