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
                var keys = ['A','B','C','D','E','F','G','H','I1','I3','I4','I5','I6','I7','I8','N','O','Q','R'];
                data.qr_texts.forEach(function(qrText){
                    var qrData = extractQR(qrText);
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
                    }
                });
                if (importBtn && table && table.rows().data().length) {
                    importBtn.style.display = 'inline-block';
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

