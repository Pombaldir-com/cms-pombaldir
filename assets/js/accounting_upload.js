document.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('multi-upload');
    var csrfInput = form.querySelector('input[name="csrf_token"]');

    var dz = new Dropzone('#multi-upload', {
        url: 'contabilidade/upload-handler.php',
        acceptedFiles: 'application/pdf',
        parallelUploads: 1
    });

    dz.on('success', function(file, response) {
        var data = response;
        if (typeof response === 'string') {
            try { data = JSON.parse(response); } catch (e) { data = {}; }
        }
        if (data.csrf_token && csrfInput) {
            csrfInput.value = data.csrf_token;
        }
    });
});
