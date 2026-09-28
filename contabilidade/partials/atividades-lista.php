<?php
// Listagem de atividades (contabilidade/atividades). Incluido por
// contabilidade/atividades.php; usa $listStats, $users, $categoriesAll, $userId.
$statTiles = [
    ['label' => 'Hoje', 'value' => $listStats['today'], 'icon' => 'fa-clock-o', 'accent' => 'blue', 'preset' => 'today', 'mine' => false],
    ['label' => 'Este mês', 'value' => $listStats['month'], 'icon' => 'fa-calendar', 'accent' => 'green', 'preset' => 'month', 'mine' => false],
    ['label' => 'As minhas este mês', 'value' => $listStats['mine_month'], 'icon' => 'fa-user', 'accent' => 'purple', 'preset' => 'month', 'mine' => true],
    ['label' => 'Anuladas este mês', 'value' => $listStats['cancelled_month'], 'icon' => 'fa-ban', 'accent' => 'red', 'preset' => 'month', 'status' => 'cancelled', 'mine' => false],
];
?>
<style>
    .actl-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 14px; }
    .actl-stat {
        display: flex; align-items: center; gap: 14px; padding: 16px 18px; background: #fff;
        border: 1px solid #e6ecf3; border-radius: 10px; cursor: pointer; text-align: left; width: 100%;
        transition: box-shadow .15s, border-color .15s;
    }
    .actl-stat:hover { border-color: #cfd8e3; box-shadow: 0 6px 18px rgba(32, 45, 64, .08); }
    .actl-stat-icon { flex: 0 0 44px; width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; }
    .actl-stat-blue .actl-stat-icon { background: rgba(59, 130, 196, .12); color: #3b82c4; }
    .actl-stat-green .actl-stat-icon { background: rgba(38, 185, 154, .14); color: #1a9a80; }
    .actl-stat-purple .actl-stat-icon { background: rgba(126, 87, 194, .12); color: #7e57c2; }
    .actl-stat-red .actl-stat-icon { background: rgba(217, 83, 79, .12); color: #d9534f; }
    .actl-stat-value { font-size: 26px; font-weight: 700; color: #2a3f54; line-height: 1; }
    .actl-stat-label { font-size: 12px; color: #8a949e; margin-top: 4px; }
    .actl-filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding-bottom: 14px; margin-bottom: 6px; border-bottom: 1px solid #eef0f2; }
    .actl-filters .actl-filter { min-width: 170px; flex: 1 1 170px; max-width: 240px; }
    .actl-filters label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #8a949e; font-weight: 600; margin-bottom: 4px; display: block; }
    .actl-custom-dates { display: none; gap: 6px; }
    .actl-custom-dates.show { display: flex; }
    #activities-table tbody tr { cursor: pointer; }
    #activities-table tbody tr.actl-cancelled td { color: #9aa3ad; }
    #activities-table tbody tr.actl-cancelled .actl-client-name { text-decoration: line-through; }
    .actl-code { font-weight: 600; color: #2a3f54; white-space: nowrap; }
    .actl-client { display: flex; align-items: center; gap: 10px; min-width: 220px; }
    .actl-avatar { flex: 0 0 34px; width: 34px; height: 34px; border-radius: 50%; background: #26b99a; color: #fff; font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; justify-content: center; }
    .actl-client-name { font-weight: 600; color: #2a3f54; line-height: 1.25; }
    .actl-client-meta { font-size: 12px; color: #8a949e; }
    .actl-cat { display: inline-block; background: #eef3f8; color: #2f5f8f; border-radius: 12px; padding: 2px 9px; font-size: 12px; margin: 1px 3px 1px 0; white-space: nowrap; }
    .actl-cat small { color: #6b86a3; }
    .actl-date { display: block; white-space: nowrap; line-height: 1.35; }
    .actl-date small { display: block; color: #8a949e; font-size: 11px; line-height: 1.35; }
    #activities-table td { padding-top: 12px; padding-bottom: 12px; }
    .actl-stat:focus { outline: none; }
    .actl-stat:focus-visible { box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .2); }
    #activities-table_wrapper .dt-search input { width: 320px; max-width: 100%; }
    .actl-status { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
    .actl-status-active { background: #e3f6f0; color: #1a8a72; }
    .actl-status-cancelled { background: #fdeaea; color: #c9302c; }
    .actl-empty { text-align: center; padding: 30px 10px; color: #8a949e; }
    .actl-empty i { font-size: 34px; color: #cfd6dd; display: block; margin-bottom: 8px; }
    @media (max-width: 991px) { .actl-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 575px) { .actl-filters .actl-filter { max-width: none; } }
</style>

<div class="actl-stats">
    <?php foreach ($statTiles as $tile): ?>
    <button type="button" class="actl-stat actl-stat-<?= $tile['accent']; ?>"
            data-preset="<?= $tile['preset']; ?>" data-mine="<?= $tile['mine'] ? '1' : '0'; ?>" data-status="<?= $tile['status'] ?? 'active'; ?>">
        <span class="actl-stat-icon"><i class="fa <?= $tile['icon']; ?>"></i></span>
        <span>
            <div class="actl-stat-value"><?= number_format((int) $tile['value'], 0, ',', '.'); ?></div>
            <div class="actl-stat-label"><?= htmlspecialchars($tile['label']); ?></div>
        </span>
    </button>
    <?php endforeach; ?>
</div>

<div class="x_panel">
    <div class="x_title">
        <h2><i class="fa fa-list"></i> Atividades registadas</h2>
        <ul class="nav navbar-right panel_toolbox">
            <li><a href="#" id="actl-reset" title="Repor filtros (as minhas atividades)"><i class="fa fa-eraser"></i></a></li>
        </ul>
        <div class="clearfix"></div>
    </div>
    <div class="x_content">
        <div class="actl-filters">
            <div class="actl-filter">
                <label for="actl-responsible">Colaborador</label>
                <select id="actl-responsible" class="form-select form-select-sm">
                    <option value="">Todos</option>
                    <?php foreach ($users as $option): ?>
                    <option value="<?= $option['id']; ?>"<?= $option['id'] === $userId ? ' selected' : ''; ?>><?= htmlspecialchars($option['label']); ?><?= $option['id'] === $userId ? ' (eu)' : ''; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actl-filter">
                <label for="actl-category">Categoria</label>
                <select id="actl-category" class="form-select form-select-sm">
                    <option value="">Todas</option>
                    <?php foreach ($categoriesAll as $category): ?>
                    <option value="<?= (int) $category['id']; ?>"><?= htmlspecialchars((string) $category['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="actl-filter">
                <label for="actl-period">Período</label>
                <select id="actl-period" class="form-select form-select-sm">
                    <option value="">Qualquer data</option>
                    <option value="today">Hoje</option>
                    <option value="7d">Últimos 7 dias</option>
                    <option value="month">Este mês</option>
                    <option value="prev_month">Mês anterior</option>
                    <option value="year">Este ano</option>
                    <option value="custom">Personalizado...</option>
                </select>
            </div>
            <div class="actl-custom-dates" id="actl-custom-dates">
                <div><label for="actl-date-from">De</label><input type="date" id="actl-date-from" class="form-control form-control-sm"></div>
                <div><label for="actl-date-to">Até</label><input type="date" id="actl-date-to" class="form-control form-control-sm"></div>
            </div>
            <div class="actl-filter">
                <label for="actl-status">Estado</label>
                <select id="actl-status" class="form-select form-select-sm">
                    <option value="active">Ativos</option>
                    <option value="cancelled">Anulados</option>
                    <option value="all">Todos</option>
                </select>
            </div>
        </div>

        <table id="activities-table" class="table table-hover align-middle w-100">
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Data</th>
                    <th>Cliente</th>
                    <th>Categorias</th>
                    <th>Colaborador</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';
    var endpoint = <?= json_encode(BASE_URL . 'contabilidade/atividades', JSON_UNESCAPED_SLASHES); ?>;
    var currentUserId = <?= (int) $userId; ?>;
    // Por defeito filtra pelo utilizador atual (como no legado).
    var storageKey = 'accounting_activities_list_filters_v2';
    var $table = $('#activities-table');
    var filters = {
        responsible: document.getElementById('actl-responsible'),
        category: document.getElementById('actl-category'),
        period: document.getElementById('actl-period'),
        dateFrom: document.getElementById('actl-date-from'),
        dateTo: document.getElementById('actl-date-to'),
        status: document.getElementById('actl-status')
    };

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function isoDate(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function periodRange() {
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        switch (filters.period.value) {
            case 'today':
                return [isoDate(today), isoDate(today)];
            case '7d':
                return [isoDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6)), isoDate(today)];
            case 'month':
                return [isoDate(new Date(today.getFullYear(), today.getMonth(), 1)), isoDate(today)];
            case 'prev_month':
                return [isoDate(new Date(today.getFullYear(), today.getMonth() - 1, 1)), isoDate(new Date(today.getFullYear(), today.getMonth(), 0))];
            case 'year':
                return [isoDate(new Date(today.getFullYear(), 0, 1)), isoDate(today)];
            case 'custom':
                return [filters.dateFrom.value, filters.dateTo.value];
            default:
                return ['', ''];
        }
    }

    function saveFilters() {
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                responsible: filters.responsible.value,
                category: filters.category.value,
                period: filters.period.value,
                dateFrom: filters.dateFrom.value,
                dateTo: filters.dateTo.value,
                status: filters.status.value
            }));
        } catch (e) { /* armazenamento indisponivel */ }
    }

    function restoreFilters() {
        try {
            var saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (saved) {
                Object.keys(saved).forEach(function (key) {
                    if (filters[key] && saved[key] != null) {
                        filters[key].value = saved[key];
                        if (filters[key].value !== saved[key]) {
                            filters[key].value = '';
                        }
                    }
                });
                if (!filters.status.value) {
                    filters.status.value = 'active';
                }
            }
        } catch (e) { /* ignorar */ }
        toggleCustomDates();
    }

    function toggleCustomDates() {
        document.getElementById('actl-custom-dates').classList.toggle('show', filters.period.value === 'custom');
    }

    function renderLines(lines) {
        if (!lines || !lines.length) {
            return '<span class="text-muted">—</span>';
        }
        return lines.map(function (line) {
            var extra = [];
            if (line.value) {
                extra.push(line.value);
            }
            if (line.fixed_assets_sold === 1) {
                extra.push('c/ imobilizado');
            }
            return '<span class="actl-cat">' + escapeHtml(line.title)
                + (extra.length ? ' <small>· ' + escapeHtml(extra.join(', ')) + '</small>' : '') + '</span>';
        }).join('');
    }

    restoreFilters();

    var table = $table.DataTable({
        serverSide: true,
        processing: true,
        responsive: true,
        order: [[1, 'desc']],
        pageLength: 25,
        lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'Todos']],
        dom: '<"row mb-2"<"col-sm-6"l><"col-sm-6"f>>rt<"row mt-2"<"col-md-5"i><"col-md-7 d-flex justify-content-end"p>>',
        ajax: {
            url: endpoint,
            data: function (d) {
                var range = periodRange();
                d.action = 'list-data';
                d.responsible = filters.responsible.value;
                d.category = filters.category.value;
                d.status = filters.status.value;
                d.date_from = range[0];
                d.date_to = range[1];
            }
        },
        columns: [
            {
                data: 'code',
                responsivePriority: 2,
                render: function (data, type, row) {
                    return '<a class="actl-code" href="' + escapeHtml(row.view_url) + '">' + escapeHtml(data) + '</a>';
                }
            },
            {
                data: 'created_at',
                responsivePriority: 3,
                render: function (data, type, row) {
                    return '<span class="actl-date" title="' + escapeHtml(data) + '">' + escapeHtml(row.created_relative)
                        + '<small>' + escapeHtml(data) + '</small></span>';
                }
            },
            {
                data: 'client_name',
                responsivePriority: 1,
                render: function (data, type, row) {
                    var meta = [row.client_code ? 'Cód. ' + row.client_code : '', row.client_nif ? 'NIF ' + row.client_nif : ''].filter(Boolean).join(' · ');
                    return '<div class="actl-client"><span class="actl-avatar">' + escapeHtml(row.client_initials) + '</span><div>'
                        + '<div class="actl-client-name">' + escapeHtml(data) + '</div>'
                        + (meta ? '<div class="actl-client-meta">' + escapeHtml(meta) + '</div>' : '')
                        + '</div></div>';
                }
            },
            { data: 'lines', orderable: false, render: function (data) { return renderLines(data); } },
            { data: 'responsible', render: function (data) { return data ? escapeHtml(data) : '<span class="text-muted">—</span>'; } },
            {
                data: 'status',
                render: function (data) {
                    return data === 1
                        ? '<span class="actl-status actl-status-active">Ativo</span>'
                        : '<span class="actl-status actl-status-cancelled">Anulado</span>';
                }
            },
            {
                data: 'view_url',
                orderable: false,
                responsivePriority: 4,
                className: 'text-end',
                render: function (data) {
                    return '<a href="' + escapeHtml(data) + '" class="btn btn-sm btn-outline-secondary" title="Ver evento"><i class="fa fa-eye"></i></a>';
                }
            }
        ],
        createdRow: function (row, data) {
            if (data.status !== 1) {
                row.classList.add('actl-cancelled');
            }
            row.dataset.href = data.view_url;
        },
        language: {
            processing: 'A carregar...',
            emptyTable: '<div class="actl-empty"><i class="fa fa-calendar-o"></i>Ainda não existem atividades registadas.</div>',
            zeroRecords: '<div class="actl-empty"><i class="fa fa-search"></i>Nenhuma atividade corresponde aos filtros.</div>',
            lengthMenu: '_MENU_ por página',
            search: '',
            searchPlaceholder: 'Pesquisar evento, cliente, código ou NIF',
            info: 'A mostrar _START_ a _END_ de _TOTAL_ atividades',
            infoEmpty: 'Sem atividades',
            infoFiltered: '(filtrado de _MAX_)',
            paginate: { first: '«', last: '»', next: '›', previous: '‹' }
        }
    });

    $table.on('click', 'tbody tr', function (e) {
        if (e.target.closest('a, button') || !this.dataset.href) {
            return;
        }
        window.location.href = this.dataset.href;
    });

    function reload() {
        saveFilters();
        table.ajax.reload();
    }

    [filters.responsible, filters.category, filters.status, filters.dateFrom, filters.dateTo].forEach(function (el) {
        el.addEventListener('change', reload);
    });
    filters.period.addEventListener('change', function () {
        toggleCustomDates();
        reload();
    });

    document.getElementById('actl-reset').addEventListener('click', function (e) {
        e.preventDefault();
        filters.responsible.value = String(currentUserId);
        filters.category.value = '';
        filters.period.value = '';
        filters.dateFrom.value = '';
        filters.dateTo.value = '';
        filters.status.value = 'active';
        toggleCustomDates();
        table.search('');
        reload();
    });

    document.querySelectorAll('.actl-stat').forEach(function (tile) {
        tile.addEventListener('click', function () {
            filters.period.value = tile.dataset.preset;
            filters.status.value = tile.dataset.status || 'active';
            filters.responsible.value = tile.dataset.mine === '1' ? String(currentUserId) : '';
            filters.category.value = '';
            toggleCustomDates();
            reload();
        });
    });
});
</script>
