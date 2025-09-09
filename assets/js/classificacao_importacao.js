window.addEventListener('load', function() {
    var csrfInput = document.getElementById('csrf_token');
    var table = $('#classify-table').DataTable({
        orderCellsTop: true,
        language: { url: 'vendors/datatables.net/i18n/pt-PT.json' },
        columnDefs: [
            { targets: [ 2, 4, 7, 8, 17, 18 ], visible: false },
            { targets: [0, 1], className: 'text-start' },
            { targets: [9, 10, 11, 12, 13, 14, 15, 16], orderable: false },
            { targets: [ -1, -2 ], orderable: false, searchable: false }
        ]
    });

    function updateButtonClass(btn) {

        var iva6 = parseInt(btn.getAttribute('data-iva6')) || 0;
        var iva13 = parseInt(btn.getAttribute('data-iva13')) || 0;
        var iva23 = parseInt(btn.getAttribute('data-iva23')) || 0;
        var novat = parseInt(btn.getAttribute('data-novat')) || 0;
        var amtIva6 = parseFloat(btn.getAttribute('data-amt-iva6')) || 0;
        var amtIva13 = parseFloat(btn.getAttribute('data-amt-iva13')) || 0;
        var amtIva23 = parseFloat(btn.getAttribute('data-amt-iva23')) || 0;

        var needIva6 = amtIva6 > 0;
        var needIva13 = amtIva13 > 0;
        var needIva23 = amtIva23 > 0;
        var needNovat = btn.getAttribute('data-req-novat') === '1';
        var requires = needIva6 || needIva13 || needIva23 || needNovat;
        var allFilled = true;
        if (needIva6 && iva6 === '') { allFilled = false; }
        if (needIva13 && iva13 === '') { allFilled = false; }
        if (needIva23 && iva23 === '') { allFilled = false; }
        if (needNovat && novat === '') { allFilled = false; }
        if (!requires) { allFilled = false; }
        btn.classList.toggle('btn-success', allFilled);
        btn.classList.toggle('btn-warning', !allFilled);
    }

    function refreshButtonClasses() {
        $('#classify-table').find('.classify-row').each(function() {
            updateButtonClass(this);
        });
    }

    refreshButtonClasses();
    table.on('draw.dt', refreshButtonClasses);

    var classifyModal = new bootstrap.Modal(document.getElementById('classifyModal'));
    var form = document.getElementById('classify-form');
    var currentBtn = null;

    $('#classify-table').on('click', '.classify-row', function() {
        var btn = this;
        currentBtn = btn;
        var emitter = btn.getAttribute('data-emitter');
        var acquirer = btn.getAttribute('data-acquirer');
        var docType = btn.getAttribute('data-doctype');
        var params = new URLSearchParams({
            action: 'get',
            A: emitter,
            B: acquirer,
            D: docType,
            csrf_token: csrfInput.value
        });
        fetch('contabilidade/save-analysis.php?' + params.toString())
            .then(function(res) { return res.json(); })
            .then(function(res) {
                if (res.csrf_token) {
                    csrfInput.value = res.csrf_token;
                }
                form.iva6.value = btn.getAttribute('data-iva6') || res.iva6 || '';
                form.iva13.value = btn.getAttribute('data-iva13') || res.iva13 || '';
                form.iva23.value = btn.getAttribute('data-iva23') || res.iva23 || '';
                form.novat.value = btn.getAttribute('data-novat') || res.novat || '';
                classifyModal.show();
            });
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!currentBtn) {
            return;
        }
        var iva6 = form.iva6.value.trim();
        var iva13 = form.iva13.value.trim();
        var iva23 = form.iva23.value.trim();
        var novat = form.novat.value.trim();
        var body = new URLSearchParams({
            id: currentBtn.getAttribute('data-id'),
            A: currentBtn.getAttribute('data-emitter'),
            B: currentBtn.getAttribute('data-acquirer'),
            D: currentBtn.getAttribute('data-doctype'),
            iva6: iva6,
            iva13: iva13,
            iva23: iva23,
            novat: novat,
            csrf_token: csrfInput.value
        });
        fetch('contabilidade/save-analysis.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (res.csrf_token) {
                csrfInput.value = res.csrf_token;
            }
            if (res.success) {
                currentBtn.setAttribute('data-iva6', iva6);
                currentBtn.setAttribute('data-iva13', iva13);
                currentBtn.setAttribute('data-iva23', iva23);
                currentBtn.setAttribute('data-novat', novat);
                updateButtonClass(currentBtn);
                classifyModal.hide();
            } else {
                console.log(res);
                alert(res.error || 'Erro ao guardar');
            }
        });
    });

    $('#classify-table').on('click', '.remove-row', function() {
        var btn = this;
        if (!confirm('Remover este registo?')) {
            return;
        }
        var body = new URLSearchParams({
            id: btn.getAttribute('data-id'),
            csrf_token: csrfInput.value
        });
        fetch('contabilidade/save-analysis.php?action=remove', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (res.csrf_token) {
                csrfInput.value = res.csrf_token;
            }
            if (res.success) {
                table.row($(btn).closest('tr')).remove().draw();
            } else {
                alert(res.error || 'Erro ao remover');
            }
        });
    });
});

