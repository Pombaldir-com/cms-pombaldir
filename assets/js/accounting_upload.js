window.addEventListener('load', function() {
    var form = document.getElementById('multi-upload');
    var csrfInput = form.querySelector('input[name="csrf_token"]');

    var table;
    if ($.fn.dataTable.isDataTable('#qr-table')) {
        table = $('#qr-table').DataTable();
    } else {
        table = $('#qr-table').DataTable({
            orderCellsTop: true,
            columnDefs: [
                { targets: [ 2, 4, 7, 8, 13, 14], visible: false },
                { targets: [0, 1], className: 'text-start' },
                { targets: -1, orderable: false, searchable: false }
            ]
        });
    }

    var dz = new Dropzone('#multi-upload', {
        url: 'contabilidade/upload-handler.php',
        acceptedFiles: 'application/pdf',
        parallelUploads: 1,
        dictDefaultMessage: 'Arraste e solte os ficheiros aqui ou clique para selecionar'
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
        if (data.qr_text) {
            var qrData = extractQR(data.qr_text);
            var keys = ['A','B','C','D','E','F','G','H','I1','I7','I8','N','O','Q','R'];
            var row = keys.map(function(key) {
                var value = qrData[key] || '';
                if (key === 'F') {
                    value = formatDate(value);
                }
                return value;
            });
            var actions = '<button type="button" class="btn btn-sm btn-danger delete-row" data-file="' + data.file + '">Eliminar</button> ' +
                '<a href="' + data.file + '" target="_blank" class="btn btn-sm btn-secondary">Ver PDF</a>';
            row.push(actions);
            table.row.add(row).draw();
        } else {
            alert('QR code não encontrado');
            if (data.file) {
                $.ajax({
                    type: 'POST',
                    url: 'contabilidade/delete-file.php',
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
        if (xhr && xhr.status === 500) {
            alert('Erro ao processar o ficheiro');
            dz.removeFile(file);
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
            url: 'contabilidade/delete-file.php',
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
            }
        });
    });

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
