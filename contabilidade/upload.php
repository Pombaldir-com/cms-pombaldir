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
    <table id="qr-table" class="table table-striped">
        <thead>
            <tr>

                <th class="text-start">Emitente</th>
                <th class="text-start">Adquirente</th>
                <th></th>
                <th width="5%">TP</th>
                <th></th>
                <th width="5%">Data</th>
                <th width="12%">Doc</th>
                <th></th>
                <th>País</th>
                <th width="8%">Total s/IVA</th>
                <th width="8%">IVA 23%</th>
                <th width="8%">Total IVA</th>
                <th width="8%">Total</th>
                <th></th>
                <th></th>
                <th data-orderable="false">Ações</th>
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
