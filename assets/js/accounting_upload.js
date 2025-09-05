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
                { targets: [0, 1], className: 'text-start' }
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
            table.row.add(row).draw();
        }
        console.log(data);
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
