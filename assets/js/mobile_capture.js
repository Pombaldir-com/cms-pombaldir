// Mobile camera capture and upload

document.addEventListener('DOMContentLoaded', function () {
    var ua = navigator.userAgent || '';
    var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua);
    if (!isMobile) {
        return;
    }

    var cameraBtn = document.getElementById('camera-btn');
    var fileBtn = document.getElementById('mobile-file-btn');
    var csrfInput = document.querySelector('#multi-upload input[name="csrf_token"]');
    var importBtn = document.getElementById('import-btn');
    var importComprasBtn = document.getElementById('import-compras-btn');
    function showImportButtons() {
        if (importBtn) {
            importBtn.style.display = 'inline-block';
        }
        if (importComprasBtn) {
            importComprasBtn.style.display = 'inline-block';
        }
    }
    var table = window.accountingTable;

    if (!cameraBtn || !fileBtn) {
        return;
    }

    var fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'application/pdf';
    fileInput.style.display = 'none';
    document.body.appendChild(fileInput);

    var camInput = document.createElement('input');
    camInput.type = 'file';
    camInput.accept = 'image/*';
    camInput.capture = 'environment';
    camInput.style.display = 'none';
    document.body.appendChild(camInput);

    fileBtn.addEventListener('click', function () {
        fileInput.click();
    });

    cameraBtn.addEventListener('click', function () {
        camInput.click();
    });

    fileInput.addEventListener('change', function () {
        if (fileInput.files[0]) {
            uploadFile(fileInput.files[0]);
            fileInput.value = '';
        }
    });

    camInput.addEventListener('change', function () {
        if (camInput.files[0]) {
            uploadFile(camInput.files[0]);
            camInput.value = '';
        }
    });

    function uploadFile(file) {
        var formData = new FormData();
        formData.append('file', file);
        if (csrfInput) {
            formData.append('csrf_token', csrfInput.value);
        }

        fetch('contabilidade/upload-handler.php', {
            method: 'POST',
            body: formData
        })
        .then(function (res) {  return res.json(); })
        .then(function (data) { 
            if (data.csrf_token && csrfInput) {
                csrfInput.value = data.csrf_token;
            }
            if (data.error) {
                alert(data.error);
                return;
            } 
            if (data.qr_texts && data.qr_texts.length) {
                var keys = ['A','B','C','D','E','F','G','H','I1','I2','I3','I4','I5','I6','I7','I8','M','N','O','Q','R'];
                var added = 0;
                var syncEntity = function(value, type, acquirerValue) {
                    var entityValue = (value || '').trim();
                    if (!entityValue || !csrfInput) {
                        return;
                    }
                    console.log('[sync-entity] pedido', entityValue, type || 'emitter');
                    var body = new URLSearchParams();
                    body.append('csrf_token', csrfInput.value);
                    body.append('value', entityValue);
                    body.append('entity_type', type || 'emitter');
                    if (typeof window.erpDatabase === 'string' && window.erpDatabase.trim() !== '') {
                        body.append('database', window.erpDatabase.trim());
                    }
                    if (acquirerValue) {
                        body.append('acquirer_value', acquirerValue);
                    }
                    body.append('action', 'sync-entity');
                    fetch('contabilidade/upload.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: body.toString()
                    })
                    .then(function(res){ return res.json(); })
                    .then(function(res){
                        if (res && res.csrf_token && csrfInput) {
                            csrfInput.value = res.csrf_token;
                        }
                        if (res && res.requires_acquirer_database && res.acquirer && typeof window.ensureAcquirerDatabase === 'function') {
                            window.ensureAcquirerDatabase(res.acquirer);
                        }
                        console.log('[sync-entity] resposta', res);
                    })
                    .catch(function(){});
                };
                data.qr_texts.forEach(function(qrText){
                    var trimmed = (qrText || '').trim();
                    if (!trimmed) {
                        return;
                    }
                    var qrData = extractQR(trimmed);
                    var hasContent = keys.some(function(key) {
                        return (qrData[key] || '').toString().trim() !== '';
                    });
                    if (!hasContent) {
                        return;
                    }
                    var row = keys.map(function(key){
                        var value = qrData[key] || '';
                        if (key === 'F') {
                            value = formatDate(value);
                        }
                        return value;
                    });
                    var actions = '<button type="button" class="btn btn-xs btn-danger delete-row" data-file="' + data.file + '">Eliminar</button> '
                        + '<a href="' + data.file + '" target="_blank" class="btn btn-xs btn-secondary">Ver PDF</a>';
                    row.push(actions);
                    if (table) {
                        table.row.add(row).draw(); 
                        added++;
                        syncEntity(qrData['A'] || '', 'emitter', qrData['B'] || '');
                    }
                });
                if (added && table && table.rows().data().length) {
                    showImportButtons();
                } else {
                    alert('QR code não encontrado');
                    if (data.file) {
                        fetch('contabilidade/upload.php?action=delete', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'file=' + encodeURIComponent(data.file) + '&csrf_token=' + encodeURIComponent(csrfInput ? csrfInput.value : '')
                        }).then(function(res){ return res.json(); }).then(function(res){
                            if (res.csrf_token && csrfInput) {
                                csrfInput.value = res.csrf_token;
                            }
                        }).catch(function(){});
                    }
                }
            } else {
                alert('QR code não encontrado');
            }
        })
        .catch(function () {
            alert('Erro ao enviar ficheiro');
        });
    }
});
