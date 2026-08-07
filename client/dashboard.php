<?php
$currentClientPage = 'dashboard';
require_once __DIR__ . '/header.php';
$clientUser = currentClientUser();
$tenantSlug = (string) ($_GET['tenant_slug'] ?? '');
$entityId = (int) ($clientUser['accounting_entity_id'] ?? 0);

// Evolucao mensal de creditos/debitos extraidos dos ficheiros SAF-T
// (accounting_saft_submissions.saft_total_debit/saft_total_credit), somados
// quando ha varios ficheiros no mesmo periodo. Ultimos 12 meses com dados.
$saftChartLabels = [];
$saftChartDebits = [];
$saftChartCredits = [];
if ($entityId > 0 && hasTable('accounting_saft_submissions')) {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        'SELECT period_year, period_month,
                SUM(saft_total_debit) AS total_debit,
                SUM(saft_total_credit) AS total_credit
         FROM accounting_saft_submissions
         WHERE accounting_entity_id = ?
         GROUP BY period_year, period_month
         ORDER BY period_year ASC, period_month ASC'
    );
    $stmt->execute([$entityId]);
    $saftChartRows = array_slice($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], -12);

    $monthNamesShort = [1 => 'Jan', 2 => 'Fev', 3 => 'Mar', 4 => 'Abr', 5 => 'Mai', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ago', 9 => 'Set', 10 => 'Out', 11 => 'Nov', 12 => 'Dez'];
    foreach ($saftChartRows as $row) {
        $saftChartLabels[] = ($monthNamesShort[(int) $row['period_month']] ?? $row['period_month']) . '/' . substr((string) $row['period_year'], -2);
        $saftChartDebits[] = round((float) $row['total_debit'], 2);
        $saftChartCredits[] = round((float) $row['total_credit'], 2);
    }
}
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

<div class="row">
    <div class="col-md-12">
        <div class="x_panel">
            <div class="x_title">
                <h2><i class="fa fa-line-chart"></i> Evolução de Créditos e Débitos <small>(SAF-T)</small></h2>
                <div class="clearfix"></div>
            </div>
            <div class="x_content">
                <?php if ($saftChartLabels): ?>
                    <canvas id="saftEvolutionChart" height="90"></canvas>
                <?php else: ?>
                    <p class="text-muted" style="margin-bottom: 0;">
                        Ainda não há dados de SAF-T suficientes para mostrar a evolução de créditos e débitos.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($saftChartLabels): ?>
<script src="<?= BASE_URL; ?>vendors/chart.js/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var ctx = document.getElementById('saftEvolutionChart');
    if (!ctx || !window.Chart) { return; }
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($saftChartLabels); ?>,
            datasets: [
                {
                    label: 'Débitos',
                    data: <?= json_encode($saftChartDebits); ?>,
                    borderColor: '#e34724',
                    backgroundColor: 'rgba(227, 71, 36, 0.1)',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true
                },
                {
                    label: 'Créditos',
                    data: <?= json_encode($saftChartCredits); ?>,
                    borderColor: '#1abb9c',
                    backgroundColor: 'rgba(26, 187, 156, 0.1)',
                    borderWidth: 2,
                    tension: 0.25,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (value) {
                            return value.toLocaleString('pt-PT', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) + ' €';
                        }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
