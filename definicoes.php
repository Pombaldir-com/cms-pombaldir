<?php
/**
 * Página de definições do utilizador.
 */
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <h2>Definições</h2>
    <div class="row mt-4">
        <div class="col-md-3">
            <div class="nav flex-column nav-pills" id="settings-tab" role="tablist" aria-orientation="vertical">
                <button class="nav-link active" id="geral-tab" data-bs-toggle="pill" data-bs-target="#geral" type="button" role="tab" aria-controls="geral" aria-selected="true">Geral</button>
                <button class="nav-link" id="email-tab" data-bs-toggle="pill" data-bs-target="#email" type="button" role="tab" aria-controls="email" aria-selected="false">E-mail</button>
            </div>
        </div>
        <div class="col-md-9">
            <div class="tab-content" id="settings-tabContent">
                <div class="tab-pane fade show active" id="geral" role="tabpanel" aria-labelledby="geral-tab">
                    <!-- Conteúdo Geral -->
                </div>
                <div class="tab-pane fade" id="email" role="tabpanel" aria-labelledby="email-tab">
                    <!-- Conteúdo E-mail -->
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require_once __DIR__ . '/footer.php';
