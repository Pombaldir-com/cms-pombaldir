window.addEventListener('load', function() {
    var csrfInput = document.getElementById('csrf_token');
    var table = $('#classify-table').DataTable({
        orderCellsTop: true,
        language: { url: 'vendors/datatables.net/i18n/pt-PT.json' },
        columnDefs: [
            { targets: [ 2, 4, 7, 8, 13, 14 ], visible: false },
            { targets: [0, 1], className: 'text-start' },
            { targets: [ -1, -2 ], orderable: false, searchable: false }
        ]
    });

    function checkClassification(emitter, acquirer, docType, btn) {
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
                if (res.account) {
                    btn.classList.remove('btn-warning');
                    btn.classList.add('btn-success');
                } else {
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-warning');
                }
            });
    }

    $('#classify-table').find('.classify-row').each(function() {
        var btn = this;
        var emitter = btn.getAttribute('data-emitter');
        var acquirer = btn.getAttribute('data-acquirer');
        var docType = btn.getAttribute('data-doctype');
        checkClassification(emitter, acquirer, docType, btn);
    });

    $('#classify-table').on('click', '.classify-row', function() {
        var btn = this;
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
                var current = res.account || '';
                var account = prompt('N\u00ba conta:', current);
                if (account !== null) {
                    var body = new URLSearchParams({
                        A: emitter,
                        B: acquirer,
                        D: docType,
                        account: account,
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
                            checkClassification(emitter, acquirer, docType, btn);
                        } else {
                            alert(res.error || 'Erro ao guardar');
                        }
                    });
                }
            });
    });
});

