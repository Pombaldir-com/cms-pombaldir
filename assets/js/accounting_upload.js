Dropzone.autoDiscover = false;

window.addEventListener('load', function() {
    var form = document.getElementById('multi-upload');
    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var importBtn = document.getElementById('import-btn');

    var table;
    if ($.fn.dataTable.isDataTable('#qr-table')) {
        table = $('#qr-table').DataTable();
    } else {
        table = $('#qr-table').DataTable({
            orderCellsTop: true,
            language: { url: 'vendors/datatables.net/i18n/pt-PT.json' },
            columnDefs: [
                { targets: [ 2, 4, 7, 8, 17, 18], visible: false },
                { targets: [0, 1], className: 'text-start' },
                { targets: [9, 10, 11, 12, 13, 14, 15, 16], orderable: false },
                { targets: [ -1 ], orderable: false, searchable: false }
            ]
        });
    }

    window.accountingTable = table;

    var dz = new Dropzone('#multi-upload', {
        url: 'contabilidade/upload-handler.php',
        acceptedFiles: 'application/pdf',
        parallelUploads: 1,
        dictDefaultMessage: 'Arraste e solte os ficheiros aqui ou clique para selecionar'
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
        if (data.file) {
            file.serverFile = data.file;
        }
        if (data.qr_texts && data.qr_texts.length) {
            var keys = ['A','B','C','D','E','F','G','H','I1','I3','I4','I5','I6','I7','I8','N','O','Q','R'];
            data.qr_texts.forEach(function(qrText) {
                var qrData = extractQR(qrText);
                var row = keys.map(function(key) {
                    var value = qrData[key] || '';
                    if (key === 'F') {
                        value = formatDate(value);
                    }
                    return value;
                });
                var actions = '<button type="button" class="btn btn-xs btn-danger delete-row" data-file="' + data.file + '">Eliminar</button> ' +
                    '<a href="' + data.file + '" target="_blank" class="btn btn-xs btn-secondary">Ver PDF</a>';
                row.push(actions);
                table.row.add(row).draw(false);
            });
        } else {
            alert('QR code não encontrado');
            if (data.file) {
                    $.ajax({
                        type: 'POST',
                        url: 'contabilidade/upload.php?action=delete',
                        data: { file: data.file, csrf_token: csrfInput.value },
                        dataType: 'json',
                        success: function(res) {
                            if (res.csrf_token) {
                                csrfInput.value = res.csrf_token;
                            }
                        }
                    });
            }
            dz.removeFile(file);
        }
        console.log(data);
    });

    dz.on('error', function(file, errorMessage, xhr) {
        var msg = 'Erro ao processar o ficheiro ' + file.name;
        var tokenUpdated = false;
        if (xhr && xhr.responseText) {
            try {
                var errData = JSON.parse(xhr.responseText);
                if (errData.error) {
                    msg = errData.error;
                }
                if (errData.csrf_token && csrfInput) {
                    csrfInput.value = errData.csrf_token;
                    tokenUpdated = true;
                }
            } catch (e) {}
        }
        if (!tokenUpdated && csrfInput) {
            $.ajax({
                type: 'POST',
                url: 'contabilidade/upload-handler.php',
                data: { csrf_token: csrfInput.value },
                dataType: 'json',
                success: function(res) {
                    if (res.csrf_token) {
                        csrfInput.value = res.csrf_token;
                    }
                },
                error: function(xhr) {
                    try {
                        var errData = JSON.parse(xhr.responseText);
                        if (errData.csrf_token) {
                            csrfInput.value = errData.csrf_token;
                        }
                    } catch (e) {}
                }
            });
        }
        alert(msg);
        dz.removeFile(file);
    });

    dz.on('queuecomplete', function() {
        if (importBtn && table.rows().data().length) {
            importBtn.style.display = 'inline-block';
        }
    });

    $('#qr-table').on('click', '.delete-row', function() {
        if (!confirm('Eliminar ficheiro?')) {
            return;
        }
        var file = $(this).data('file');
        var row = table.row($(this).parents('tr'));
        $.ajax({
            type: 'POST',
            url: 'contabilidade/upload.php?action=delete',
            data: { file: file, csrf_token: csrfInput.value },
            dataType: 'json',
            success: function(res) {
                if (res.csrf_token) {
                    csrfInput.value = res.csrf_token;
                }
                if (res.success) {
                    row.remove().draw();
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
            var nodes = table.rows().nodes().toArray();
            if (!nodes.length) {
                alert('Não há dados para importar');
                return;
            }
            var keys = ['A','B','C','D','E','F','G','H','I1','I3','I4','I5','I6','I7','I8','N','O','Q','R'];
            var payload = nodes.map(function(node) {
                var data = table.row(node).data() || [];
                var obj = {};
                for (var i = 0; i < keys.length; i++) {
                    obj[keys[i]] = data[i] || '';
                }
                obj['filename'] = $(node).find('.delete-row').data('file') || '';
                return obj;
            });
            fetch('contabilidade/upload.php?action=import', {
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

function extractQR(str) {
    var parts = str.split('*');
    var obj = {};
    parts.forEach(function(part) {
        var del = part.split(':', 2);
        if (del.length === 2) {
            var key = del[0].trim();
            var val = del[1].trim();
            obj[key] = val;
        }
    });
    return obj;
}

function formatDate(dateStr) {
    if (!dateStr) return '';
    if (/^\d{8}$/.test(dateStr)) {
        return dateStr.slice(0, 4) + '-' + dateStr.slice(4, 6) + '-' + dateStr.slice(6, 8);
    }
    var date = new Date(dateStr);
    if (!isNaN(date)) {
        var y = date.getFullYear();
        var m = ('0' + (date.getMonth() + 1)).slice(-2);
        var d = ('0' + date.getDate()).slice(-2);
        return y + '-' + m + '-' + d;
    }
    return dateStr;
}
