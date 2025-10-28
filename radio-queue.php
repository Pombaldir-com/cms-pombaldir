<?php
require_once __DIR__ . '/functions.php';
startSession();
requireLogin();

$useDataTables = true;
require_once __DIR__ . '/header.php';
?>
<div class="container-fluid">
    <div class="page-title">
        <div class="title_left">
            <h3>Fila da Rádio</h3>
        </div>
    </div>
    <div class="clearfix"></div>

    <div class="row">
        <div class="col-12">
            <div class="x_panel">
                <div class="x_title">
                    <h2>Fila de Reprodução</h2>
                    <ul class="nav navbar-right panel_toolbox">
                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                        <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                    </ul>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div class="alert alert-warning d-none" id="radio-queue-error" role="alert"></div>
                    <table id="radio-queue-table" class="table table-striped table-bordered" style="width:100%">
                        <thead>
                            <tr>
                                <th>Posição</th>
                                <th>Música</th>
                                <th>Artista</th>
                                <th>Duração</th>
                                <th>Pedido por</th>
                                <th>Agendado</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function($) {
    function formatDuration(seconds) {
        if (!seconds || seconds < 0) {
            return '—';
        }
        var mins = Math.floor(seconds / 60);
        var secs = Math.floor(seconds % 60);
        return mins + ':' + (secs < 10 ? '0' + secs : secs);
    }

    var table = $('#radio-queue-table').DataTable({
        ajax: {
            url: 'data/radio_queue.php',
            dataSrc: function(json) {
                var $alert = $('#radio-queue-error');
                if (json.error) {
                    $alert.text(json.error).removeClass('d-none');
                } else {
                    $alert.addClass('d-none');
                }
                return json.data || [];
            }
        },
        columns: [
            { data: 'position', className: 'text-center', width: '80px' },
            { data: 'title' },
            { data: 'artist' },
            {
                data: 'duration',
                className: 'text-center',
                render: function(data) {
                    return formatDuration(data);
                }
            },
            { data: 'requester', defaultContent: '', className: 'text-center' },
            { data: 'scheduled_for', className: 'text-center' },
            { data: 'actions', orderable: false, searchable: false, className: 'text-center' }
        ],
        order: [[0, 'asc']],
        language: {
            url: 'vendors/datatables.net/i18n/pt-PT.json'
        }
    });

    setInterval(function() {
        table.ajax.reload(null, false);
    }, 30000);

    $('#radio-queue-table').on('click', '.js-radio-action', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var queueId = $btn.data('queue-id');
        var action = $btn.data('action');
        if (!queueId || !action) {
            return;
        }
        $btn.prop('disabled', true);
        $.ajax({
            method: 'POST',
            url: 'data/radio_queue_action.php',
            data: {
                action: action,
                queue_id: queueId
            }
        }).done(function(response) {
            if (response && response.success) {
                table.ajax.reload(null, false);
            } else if (response && response.error) {
                alert(response.error);
            } else {
                alert('Não foi possível concluir a ação.');
            }
        }).fail(function() {
            alert('Erro na comunicação com o servidor.');
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
