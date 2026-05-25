<?php
$currentClientPage = 'dashboard';
require_once __DIR__ . '/header.php';
$clientUser = currentClientUser();
$tenantSlug = (string) ($_GET['tenant_slug'] ?? '');
?>

<div class="clearfix"></div>

<div class="row tile_count">
    <div class="col-md-5 col-sm-12 tile_stats_count">
        <span class="count_top"><i class="fa fa-building-o"></i> Entidade</span>
        <div class="count" style="font-size: 24px;"><?= htmlspecialchars((string) ($clientUser['entity_name'] ?? '-')); ?></div>
        <span class="count_bottom">Cliente associado à sessão atual</span>
    </div>
    <div class="col-md-3 col-sm-6 tile_stats_count">
        <span class="count_top"><i class="fa fa-id-card-o"></i> NIF</span>
        <div class="count"><?= htmlspecialchars((string) ($clientUser['entity_nif'] ?? '-')); ?></div>
        <span class="count_bottom">Identificador fiscal</span>
    </div>
    <div class="col-md-4 col-sm-6 tile_stats_count">
        <span class="count_top"><i class="fa fa-user"></i> Utilizador</span>
        <div class="count" style="font-size: 24px;"><?= htmlspecialchars((string) ($clientUser['name'] ?: $clientUser['username'])); ?></div>
        <span class="count_bottom">Conta de acesso ao portal</span>
    </div>
</div>

<div class="row">
    <div class="col-md-8 col-sm-12 col-xs-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-folder-open-o"></i> Documentos contabilísticos</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <p class="text-muted" style="margin-bottom: 14px;">
                    Consulte os documentos disponíveis para a entidade associada à sua conta.
                </p>
                <a class="btn btn-success" href="<?= BASE_URL ?>t/<?= rawurlencode($tenantSlug); ?>/cliente/documentos">
                    <i class="fa fa-arrow-right"></i> Aceder à listagem
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4 col-sm-12 col-xs-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-info-circle"></i> Sessão</h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <table class="table table-striped" style="margin-bottom: 0;">
                    <tbody>
                        <tr>
                            <th>Utilizador</th>
                            <td><?= htmlspecialchars((string) ($clientUser['username'] ?? '')); ?></td>
                        </tr>
                        <tr>
                            <th>Estado</th>
                            <td><span class="label label-success">Ativo</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
