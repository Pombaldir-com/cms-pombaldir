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
                <th>Dt</th>
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

<div id="qr-info">
    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Coluna</th>
                <th>Descrição</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>A</td><td>NIF do emitente (quem emite a fatura).</td></tr>
            <tr><td>B</td><td>NIF do adquirente (cliente).</td></tr>
            <tr><td>C</td><td>País do emitente (código ISO, ex.: <code>PT</code>).</td></tr>
            <tr><td>D</td><td>Tipo de documento.<br><strong>FT</strong> – Fatura<br><strong>FS</strong> – Fatura Simplificada<br><strong>FR</strong> – Fatura/Recibo</td></tr>
            <tr><td>E</td><td>Estado do documento.<br><strong>N</strong> – Normal<br><strong>A</strong> – Anulado</td></tr>
            <tr><td>F (Dt)</td><td>Data do documento no formato <code>YYYYMMDD</code>.</td></tr>
            <tr><td>G</td><td>Identificação única do documento (série + numeração, ex.: <code>FACDG+232122/990</code>).</td></tr>
            <tr><td>H</td><td>Retenção na fonte; usar <code>0</code> se não houver.</td></tr>
            <tr><td>I1</td><td>País do adquirente (código ISO, ex.: <code>PT</code>, <code>ES</code>).</td></tr>
            <tr><td>I7</td><td>Total com IVA à taxa normal (base tributável).</td></tr>
            <tr><td>I8</td><td>IVA calculado à taxa normal.</td></tr>
            <tr><td>N</td><td>Valor total de IVA (soma de todos os tipos de IVA).</td></tr>
            <tr><td>O</td><td>Total do documento (valor global da fatura).</td></tr>
            <tr><td>Q</td><td>Assinatura/Hash do documento gerado pelo software certificado.</td></tr>
            <tr><td>R</td><td>Código de validação do software certificado atribuído pela AT.</td></tr>
        </tbody>
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
