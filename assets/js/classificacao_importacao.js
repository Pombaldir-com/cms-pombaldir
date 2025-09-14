window.addEventListener('load', function() {
    function showError(message) {
        if (window.PNotify) {
            new PNotify({
                title: 'Erro',
                text: message,
                type: 'error',
                styling: 'bootstrap3'
            });
        } else {
            alert(message);
        }
    }

    function fetchJson(url, options) {
        return fetch(url, options).then(function(res) {
            return res.text().then(function(text) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error(text || 'Resposta inválida do servidor');
                }
            });
        });
    }
    var csrfInput = document.getElementById('csrf_token');
    var importTypeInput = document.getElementById('import_type');
    var importType = importTypeInput ? importTypeInput.value : 1;
    var table = $('#classify-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: 'contabilidade/classificacao-importacao-data.php',
            data: function(d) { d.import_type = importType; }
        },
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
        var iva6 = btn.getAttribute('data-iva6') || '';
        var iva13 = btn.getAttribute('data-iva13') || '';
        var iva23 = btn.getAttribute('data-iva23') || '';
        var novat = btn.getAttribute('data-novat') || '';


        var amtIva6 = Math.abs(parseFloat(btn.getAttribute('data-amt-iva6'))) || 0;
        var amtIva13 = Math.abs(parseFloat(btn.getAttribute('data-amt-iva13'))) || 0;
        var amtIva23 = Math.abs(parseFloat(btn.getAttribute('data-amt-iva23'))) || 0;
        var needNovat = btn.getAttribute('data-req-novat') === '1';

        var needIva6 = amtIva6 > 0;
        var needIva13 = amtIva13 > 0;
        var needIva23 = amtIva23 > 0;

        var hasIva6 = parseInt(iva6, 10) > 0;
        var hasIva13 = parseInt(iva13, 10) > 0;
        var hasIva23 = parseInt(iva23, 10) > 0;
        var hasNovat = parseInt(novat, 10) > 0;

        var requires = needIva6 || needIva13 || needIva23 || needNovat;
        var allFilled = true;

        if (needIva6 && !hasIva6) { allFilled = false; }
        if (needIva13 && !hasIva13) { allFilled = false; }
        if (needIva23 && !hasIva23) { allFilled = false; }
        if (needNovat && !hasNovat) { allFilled = false; }

        var hasAnyAccount = hasIva6 || hasIva13 || hasIva23 || hasNovat;
        btn.classList.remove('btn-success', 'btn-warning', 'btn-secondary');
        if (!requires) {
            btn.classList.add('btn-success');
        } else if (allFilled) {
            btn.classList.add('btn-success');
        } else if (hasAnyAccount) {
            btn.classList.add('btn-warning');
        } else {
            btn.classList.add('btn-secondary');
        }

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
    var linesModal = new bootstrap.Modal(document.getElementById('linesModal'));
    var linesContainer = document.getElementById('linesContainer');
    var confirmLinesBtn = document.getElementById('confirmLinesBtn');
    var currentLinesId = null;

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
        fetchJson('contabilidade/save-analysis.php?' + params.toString())
            .then(function(res) {
                if (res.csrf_token) {
                    csrfInput.value = res.csrf_token;
                }
                form.iva6.value = btn.getAttribute('data-iva6') || res.iva6 || '';
                form.iva13.value = btn.getAttribute('data-iva13') || res.iva13 || '';
                form.iva23.value = btn.getAttribute('data-iva23') || res.iva23 || '';
                form.novat.value = btn.getAttribute('data-novat') || res.novat || '';
                classifyModal.show();
            })
            .catch(function(err) {
                showError(err.message || 'Erro ao carregar');
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
        fetchJson('contabilidade/save-analysis.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
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
                showError(res.error || 'Erro ao guardar');
            }
        })
        .catch(function(err) {
            showError(err.message || 'Erro ao guardar');
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
        fetchJson('contabilidade/save-analysis.php?action=remove', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(res) {
            if (res.csrf_token) {
                csrfInput.value = res.csrf_token;
            }
            if (res.success) {
                table.ajax.reload(null, false);
            } else {
                showError(res.error || 'Erro ao remover');
            }
        })
        .catch(function(err) {
            showError(err.message || 'Erro ao remover');
        });
    });

    // Handle line analysis (import type 2)
    $('#classify-table').on('click', '.analyze-lines', function() {
        var btn = this;
        var id = btn.getAttribute('data-id');
        currentLinesId = id;
        linesContainer.innerHTML = '<div class="d-flex justify-content-center my-3"><div class="spinner-border" role="status"><span class="visually-hidden">A carregar...</span></div></div>';
        linesModal.show();
        var params = new URLSearchParams({
            action: 'lines',
            id: id
        });
        fetchJson('contabilidade/save-analysis.php?' + params.toString())
            .then(function(res) {
                if (res.error) {
                    linesModal.hide();
                    showError(res.error);
                    return;
                }
                renderLines(res);
            })
            .catch(function(err) {
                linesModal.hide();
                showError(err.message || 'Erro na análise');
            });
    });

    function renderLines(lines) {
        if (!Array.isArray(lines) || lines.length === 0) {
            linesContainer.innerHTML = '<p>Sem linhas detectadas</p>';
            return;
        }
        var html = '<table class="table table-striped"><thead><tr>' +
            '<th>ERP</th>' +
            '<th>IVA</th>' +
            '<th>Código</th>' +
            '<th>Descrição</th>' +
            '<th>Qtd.</th>' +
            '<th>P. Un.</th>' +
            '<th>Preço</th>' +
            '</tr></thead><tbody>';
        lines.forEach(function(line) {
            var erp = line.ERP || '';
            var iva = line.IVA_TAXA || line.OTHER || '';
            var productCode = line.PRODUCT_CODE || '';
            var item = line.ITEM || (line.ITEM_QUANTITY_UNIT_PRICE && line.ITEM_QUANTITY_UNIT_PRICE.ITEM) || '';
            var quantity = line.QUANTITY || (line.ITEM_QUANTITY_UNIT_PRICE && line.ITEM_QUANTITY_UNIT_PRICE.QUANTITY) || '';
            var unitPrice = line.UNIT_PRICE || (line.ITEM_QUANTITY_UNIT_PRICE && line.ITEM_QUANTITY_UNIT_PRICE.UNIT_PRICE) || '';
            var price = line.PRICE || '';
            var priceVat = line.PRICE_VAT || '';
            if (!priceVat) {
                var priceNum = parseFloat(String(price).replace(',', '.'));
                var ivaNum = parseFloat(String(iva).replace(',', '.'));
                if (!isNaN(priceNum) && !isNaN(ivaNum)) {
                    priceVat = (priceNum * (1 + ivaNum / 100)).toFixed(2);
                }
            }
            html += '<tr>' +
                '<td><input type="text" class="form-control erp-input" value="' + erp + '"><input type="hidden" class="price-vat" value="' + priceVat + '"></td>' +
                '<td class="iva-taxa">' + iva + '</td>' +
                '<td class="product-code">' + productCode + '</td>' +
                '<td class="item">' + item + '</td>' +
                '<td class="quantity">' + quantity + '</td>' +
                '<td class="unit-price">' + unitPrice + '</td>' +
                '<td class="price">' + price + '</td>' +
                '</tr>';
        });
        html += '</tbody></table>';
        linesContainer.innerHTML = html;
    }

    confirmLinesBtn.addEventListener('click', function() {
        if (!currentLinesId) {
            return;
        }
        var rows = linesContainer.querySelectorAll('tbody tr');
        var linesToSave = [];
        var allErpFilled = true;
        rows.forEach(function(row) {
            var erp = row.querySelector('.erp-input').value.trim();
            var iva = row.querySelector('.iva-taxa').textContent.trim();
            var productCode = row.querySelector('.product-code').textContent.trim();
            var item = row.querySelector('.item').textContent.trim();
            var quantity = row.querySelector('.quantity').textContent.trim();
            var unitPrice = row.querySelector('.unit-price').textContent.trim();
            var price = row.querySelector('.price').textContent.trim();
            var priceVat = row.querySelector('.price-vat').value;
            if (!erp) {
                allErpFilled = false;
            }
            linesToSave.push({
                ERP: erp,
                IVA_TAXA: iva,
                PRODUCT_CODE: productCode,
                ITEM: item,
                QUANTITY: quantity,
                UNIT_PRICE: unitPrice,
                PRICE: price,
                PRICE_VAT: priceVat
            });
        });
        var body = new URLSearchParams({
            action: 'save_lines',
            id: currentLinesId,
            lines: JSON.stringify(linesToSave),
            csrf_token: csrfInput.value
        });
        fetchJson('contabilidade/save-analysis.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function(res) {
            if (res.csrf_token) {
                csrfInput.value = res.csrf_token;
            }
            if (res.success) {
                linesModal.hide();
                var analyzeBtn = document.querySelector('.analyze-lines[data-id="' + currentLinesId + '"]');
                if (analyzeBtn) {
                    analyzeBtn.classList.remove('btn-info', 'btn-success');
                    analyzeBtn.classList.add(allErpFilled ? 'btn-success' : 'btn-info');
                }
            } else {
                showError(res.error || 'Erro ao guardar linhas');
            }
        })
        .catch(function(err) {
            showError(err.message || 'Erro ao guardar linhas');
        });
    });
});

