window.addEventListener('load', function() {
    var form = document.getElementById('multi-upload');
    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var table = $('#qr-table').DataTable();

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
            var rowData = [
                qrData['A'] || '',
                qrData['B'] || '',
                qrData['C'] || '',
                qrData['D'] || '',
                qrData['E'] || '',
                qrData['F'] || '',
                qrData['G'] || '',
                qrData['H'] || '',
                qrData['I1'] || '',
                qrData['I7'] || '',
                qrData['I8'] || '',
                qrData['N'] || '',
                qrData['O'] || ''
            ];
            table.row.add(rowData).draw();
        }
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
