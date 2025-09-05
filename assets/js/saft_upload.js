Dropzone.autoDiscover = false;

window.addEventListener('load', function() {
    var form = document.getElementById('saft-upload');
    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var importBtn = document.getElementById('import-btn');

    var table;
    if ($.fn.dataTable.isDataTable('#saft-table')) {
        table = $('#saft-table').DataTable();
    } else {
        table = $('#saft-table').DataTable({
            language: { url: 'vendors/datatables.net/i18n/pt-PT.json' },
            columnDefs: [
                { targets: [3], visible: false },
                { targets: -1, orderable: false, searchable: false }
            ]
        });
    }

    var dz = new Dropzone('#saft-upload', {
        url: 'contabilidade/saft-handler.php',
        acceptedFiles: 'text/xml,application/xml',
        parallelUploads: 1,
        dictDefaultMessage: 'Arraste e solte o ficheiro SAF-T aqui ou clique para selecionar'
    });

    dz.on('sending', function(file, xhr, formData) {
        if (csrfInput) {
            formData.append('csrf_token', csrfInput.value);
        }
    });

    dz.on('success', function(file, response) {
        var data = response;
        if (typeof response === 'string') {
            try { data = JSON.parse(response); } catch (e) { data = {}; }
        }
        if (data.csrf_token && csrfInput) {
            csrfInput.value = data.csrf_token;
        }
        if (Array.isArray(data.transactions)) {
            data.transactions.forEach(function(tx) {
                var actions = '<button type="button" class="btn btn-xs btn-danger delete-row" data-file="' + data.file + '">Eliminar</button> ' +
                    '<a href="' + data.file + '" target="_blank" class="btn btn-xs btn-secondary">Ver XML</a>';
                table.row.add([
                    tx.date || '',
                    tx.description || '',
                    tx.amount || '',
                    data.file,
                    actions
                ]).draw();
            });
        }
    });

    dz.on('error', function(file, errorMessage, xhr) {
        var msg = 'Erro ao processar o ficheiro ' + file.name;
        if (xhr && xhr.responseText) {
            try {
                var errData = JSON.parse(xhr.responseText);
                if (errData.error) {
                    msg = errData.error;
                }
                if (errData.csrf_token && csrfInput) {
                    csrfInput.value = errData.csrf_token;
                }
            } catch (e) {}
        }
        alert(msg);
        dz.removeFile(file);
    });

    dz.on('queuecomplete', function() {
        if (importBtn && table.rows().data().length) {
            importBtn.style.display = 'inline-block';
        }
    });

    $('#saft-table').on('click', '.delete-row', function() {
        if (!confirm('Eliminar ficheiro?')) {
            return;
        }
        var file = $(this).data('file');
        var rows = table.rows(function(idx, data, node) {
            return data[3] === file;
        });
        $.ajax({
            type: 'POST',
            url: 'contabilidade/saft.php?action=delete',
            data: { file: file, csrf_token: csrfInput.value },
            dataType: 'json',
            success: function(res) {
                if (res.csrf_token) {
                    csrfInput.value = res.csrf_token;
                }
                if (res.success) {
                    rows.remove().draw();
                    var dzFiles = dz.files;
                    for (var i = 0; i < dzFiles.length; i++) {
                        if (dzFiles[i].serverFile === file) {
                            dz.removeFile(dzFiles[i]);
                            break;
                        }
                    }
                }
            },
            error: function(xhr) {
                var errData = {};
                try { errData = JSON.parse(xhr.responseText); } catch (e) {}
                if (errData.csrf_token && csrfInput) {
                    csrfInput.value = errData.csrf_token;
                }
                alert(errData.error || 'Erro ao eliminar ficheiro');
            }
        });
    });

    if (importBtn) {
        importBtn.addEventListener('click', function() {
            var rows = table.rows().data().toArray();
            if (!rows.length) {
                alert('Não há dados para importar');
                return;
            }
            var payload = rows.map(function(row) {
                return {
                    date: row[0] || '',
                    description: row[1] || '',
                    amount: row[2] || ''
                };
            });
            fetch('contabilidade/saft.php?action=import', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ rows: payload, csrf_token: csrfInput.value })
            })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.csrf_token) {
                    csrfInput.value = res.csrf_token;
                }
                if (res.success) {
                    alert('Importação concluída');
                    importBtn.style.display = 'none';
                    table.clear().draw();
                    dz.removeAllFiles(true);
                } else {
                    alert(res.error || 'Falha na importação');
                }
            })
            .catch(function() {
                alert('Falha na importação');
            });
        });
    }
});

