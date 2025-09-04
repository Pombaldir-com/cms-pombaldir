document.addEventListener('DOMContentLoaded', function() {
    new Dropzone('#multi-upload', {
        url: 'contabilidade/upload-handler.php',
        acceptedFiles: 'application/pdf',
        parallelUploads: 1
    });
});
