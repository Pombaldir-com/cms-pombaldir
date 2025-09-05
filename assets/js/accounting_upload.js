document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('multi-upload');
    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var resultsDiv = document.getElementById('qr-results');

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
        if (data.qr_text && resultsDiv) {
            var qrData = extractQR(data.qr_text);
            var table = document.createElement('table');
            var tbody = document.createElement('tbody');

            if (qrData['B']) {
                var trB = document.createElement('tr');
                var thB = document.createElement('th');
                thB.textContent = '[B]';
                var tdB = document.createElement('td');
                tdB.textContent = qrData['B'];
                trB.appendChild(thB);
                trB.appendChild(tdB);
                tbody.appendChild(trB);
            }

            Object.keys(qrData).forEach(function(key) {
                if (key === 'B') return;
                var tr = document.createElement('tr');
                var th = document.createElement('th');
                th.textContent = '[' + key + ']';
                var td = document.createElement('td');
                td.textContent = qrData[key];
                tr.appendChild(th);
                tr.appendChild(td);
                tbody.appendChild(tr);
            });

            table.appendChild(tbody);
            resultsDiv.appendChild(table);
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
