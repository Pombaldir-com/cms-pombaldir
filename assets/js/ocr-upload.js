// Handles OCR analysis workflow after each upload
Dropzone.autoDiscover = false;

function initOcrUpload(selector) {
    var dz = new Dropzone(selector);
    var queue = [];

    function showNext() {
        if (!queue.length) {
            dz.processQueue();
            return;
        }
        var item = queue.shift();
        var data = item.data || {};
        var $modal = $('#ocrModal');
        $modal.data('file', item.file);
        $modal.find('textarea[name="ocr_text"]').val(data.text || '');
        var fields = data.fields || {};
        var $fields = $modal.find('.analysis-fields').empty();
        Object.keys(fields).forEach(function(key) {
            var group = $('<div class="mb-3"></div>');
            $('<label class="form-label"></label>').text(key).appendTo(group);
            $('<input type="text" class="form-control">').attr('name', key).val(fields[key]).appendTo(group);
            $fields.append(group);
        });
        $modal.modal('show');
    }

    dz.on('success', function(file, response) {
        var data = response;
        if (typeof response === 'string') {
            try { data = JSON.parse(response); } catch (e) { data = {}; }
        }
        queue.push({file: file, data: data});
        if (!$('#ocrModal').hasClass('show')) {
            showNext();
        }
    });

    $('#analysisConfirm').on('click', function() {
        var $modal = $('#ocrModal');
        var file = $modal.data('file');
        var payload = {
            filename: file.name,
            text: $modal.find('textarea[name="ocr_text"]').val(),
            fields: {}
        };
        $modal.find('.analysis-fields input').each(function() {
            payload.fields[this.name] = $(this).val();
        });
        $.post('contabilidade/save-analysis.php', payload).always(function() {
            $modal.modal('hide');
            dz.removeFile(file);
            showNext();
        });
    });

    $('#analysisCancel').on('click', function() {
        var $modal = $('#ocrModal');
        var file = $modal.data('file');
        $modal.modal('hide');
        dz.removeFile(file);
        showNext();
    });
}
