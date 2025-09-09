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
        var rates = [6, 13, 23];
        var requires = false;
        var allFilled = true;

        rates.forEach(function(rate) {
            var amount = parseFloat(btn.getAttribute('data-amt-iva' + rate)) || 0;
            if (amount > 0) {
                requires = true;
                if (!(btn.getAttribute('data-iva' + rate) || '')) {
                    allFilled = false;
                }
            }
        });

        if (btn.getAttribute('data-req-novat') === '1') {
            requires = true;
            if (!(btn.getAttribute('data-novat') || '')) {
                allFilled = false;
            }
        }

        var isComplete = requires && allFilled;
        btn.classList.toggle('btn-success', isComplete);
        btn.classList.toggle('btn-warning', !isComplete);
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

