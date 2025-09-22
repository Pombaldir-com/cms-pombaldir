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
    var importType = importTypeInput ? parseInt(importTypeInput.value, 10) : 1;
    if (isNaN(importType)) {
        importType = 1;
    }
    var showLineCostCenter = importType === 1;
    var table = $('#classify-table').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: 'contabilidade/classificacao-importacao/data',
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

    function parseJsonAttribute(el, attr) {
        var value = el.getAttribute(attr);
        if (!value) {
            return null;
        }
        try {
            return JSON.parse(value);
        } catch (e) {
            return null;
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }


    function updateButtonClass(btn) {
        var rateData = parseJsonAttribute(btn, 'data-rates') || {};
        var requirements = parseJsonAttribute(btn, 'data-requirements') || {};

        var requires = false;
        var allFilled = true;
        var hasAny = false;

        Object.keys(requirements).forEach(function(rate) {
            var req = requirements[rate] || {};
            var data = rateData[rate] || {};
            if (req.general) {
                requires = true;
                var general = (data.general_account || '').trim();
                if (!general) {
                    allFilled = false;
                } else {
                    hasAny = true;
                }
            }
            if (req.iva) {
                requires = true;
                var iva = (data.iva_account || '').trim();
                if (!iva) {
                    allFilled = false;
                } else {
                    hasAny = true;
                }
            }
        });

        btn.classList.remove('btn-success', 'btn-warning', 'btn-secondary');
        if (!requires || allFilled) {
            btn.classList.add('btn-success');
        } else if (hasAny) {
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

    var classifyModalEl = document.getElementById('classifyModal');
    var classifyModal = classifyModalEl ? new bootstrap.Modal(classifyModalEl) : null;
    var modalTitleEl = document.getElementById('classifyModalLabel');
    var form = document.getElementById('classify-form');
    var rateInputs = {};
    var rateKeys = [];
    var currentRateData = {};
    var currentCostCenters = {};

    function forEachRate(callback) {
        var keys = rateKeys.length ? rateKeys : Object.keys(rateInputs);
        keys.forEach(callback);
    }

    function createEmptyCostCenters() {
        var result = {};
        forEachRate(function(rate) {
            result[rate] = '';
        });
        return result;
    }

    function normalizeCostCenterValues(value) {
        var normalized = createEmptyCostCenters();
        var keys = Object.keys(normalized);
        if (keys.length === 0) {
            return normalized;
        }

        if (value === null || value === undefined) {
            return normalized;
        }

        if (typeof value === 'string' || typeof value === 'number') {
            var stringValue = String(value).trim();
            keys.forEach(function(rate) {
                normalized[rate] = stringValue;
            });
            return normalized;
        }

        if (Array.isArray(value)) {
            value.forEach(function(entry, index) {
                var rate = keys[index];
                if (rate !== undefined) {
                    normalized[rate] = entry === null || entry === undefined ? '' : String(entry).trim();
                }
            });
            return normalized;
        }

        if (typeof value === 'object') {
            var source = value;
            if (source && typeof source.rates === 'object') {
                source = source.rates;
            }
            keys.forEach(function(rate) {
                if (!Object.prototype.hasOwnProperty.call(source, rate)) {
                    return;
                }
                var entry = source[rate];
                if (entry !== null && typeof entry === 'object') {
                    if (Object.prototype.hasOwnProperty.call(entry, 'cost_center')) {
                        normalized[rate] = String(entry.cost_center || '').trim();
                        return;
                    }
                    if (Object.prototype.hasOwnProperty.call(entry, 'value')) {
                        normalized[rate] = String(entry.value || '').trim();
                        return;
                    }
                }
                normalized[rate] = entry === null || entry === undefined ? '' : String(entry).trim();
            });
            return normalized;
        }

        return normalized;
    }

    function applyCostCenterValues(value) {
        var normalized = normalizeCostCenterValues(value);
        forEachRate(function(rate) {
            currentCostCenters[rate] = normalized[rate];
            var info = rateInputs[rate];
            if (info && info.costCenter && info.costCenter.value !== normalized[rate]) {
                info.costCenter.value = normalized[rate];
            }
        });
    }

    function getCostCenterValues() {
        var values = createEmptyCostCenters();
        forEachRate(function(rate) {
            var info = rateInputs[rate];
            if (info && info.costCenter) {
                values[rate] = info.costCenter.value.trim();
            } else if (Object.prototype.hasOwnProperty.call(currentCostCenters, rate)) {
                values[rate] = (currentCostCenters[rate] || '').trim();
            }
        });
        return values;
    }

    function hasAnyCostCenterValue() {
        var values = getCostCenterValues();
        return Object.keys(values).some(function(rate) {
            return values[rate] !== '';
        });
    }

    if (form) {
        var rateRows = Array.prototype.slice.call(form.querySelectorAll('tbody tr[data-rate]'));
        rateRows.forEach(function(row) {
            var rate = row.getAttribute('data-rate');
            rateInputs[rate] = {
                base: row.querySelector('.base-field'),
                iva: row.querySelector('.iva-field'),
                ivaAccount: row.querySelector('.iva-account-field'),
                generalAccount: row.querySelector('.general-account-field'),
                costCenter: row.querySelector('.cost-center-field')
            };
        });
        rateKeys = Object.keys(rateInputs);
        currentCostCenters = createEmptyCostCenters();
        rateKeys.forEach(function(rate) {
            var info = rateInputs[rate];
            if (!info || !info.costCenter) {
                return;
            }
            var input = info.costCenter;
            input.setAttribute('type', 'text');
            input.removeAttribute('readonly');
            input.removeAttribute('disabled');
            input.readOnly = false;
            input.disabled = false;
            input.addEventListener('input', function() {
                currentCostCenters[rate] = input.value;
            });
        });
    }
    if (classifyModalEl) {
        classifyModalEl.addEventListener('shown.bs.modal', function() {
            for (var i = 0; i < rateKeys.length; i += 1) {
                var info = rateInputs[rateKeys[i]];
                if (info && info.costCenter) {
                    info.costCenter.focus();
                    info.costCenter.select();
                    break;
                }
            }
        });
    }

    var currentBtn = null;
    var linesModal = new bootstrap.Modal(document.getElementById('linesModal'));
    var linesContainer = document.getElementById('linesContainer');
    var confirmLinesBtn = document.getElementById('confirmLinesBtn');
    var currentLinesId = null;

    $('#classify-table').on('click', '.classify-row', function() {
        var btn = this;
        currentBtn = btn;
        var emitter = btn.getAttribute('data-emitter') || '';
        var acquirer = btn.getAttribute('data-acquirer') || '';
        var docType = btn.getAttribute('data-doctype') || '';


        currentRateData = parseJsonAttribute(btn, 'data-rates') || {};

        Object.keys(rateInputs).forEach(function(rate) {
            var info = rateInputs[rate];
            var data = currentRateData[rate] || {};
            if (info.base) { info.base.value = data.base || ''; }
            if (info.iva) { info.iva.value = data.iva || ''; }
            if (info.ivaAccount) { info.ivaAccount.value = data.iva_account || ''; }
            if (info.generalAccount) { info.generalAccount.value = data.general_account || ''; }
        });

        var btnCostCenters = parseJsonAttribute(btn, 'data-cost-centers');
        if (!btnCostCenters && btn.hasAttribute('data-cost-center')) {
            btnCostCenters = btn.getAttribute('data-cost-center') || '';
        }
        applyCostCenterValues(btnCostCenters);

        var params = new URLSearchParams({
            action: 'get',
            id: btn.getAttribute('data-id') || '',
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

                var rowRates = res.row_rates || {};
                Object.keys(rateInputs).forEach(function(rate) {
                    var info = rateInputs[rate];
                    var rowData = rowRates[rate] || {};
                    if (info.ivaAccount && Object.prototype.hasOwnProperty.call(rowData, 'iva_account')) {
                        info.ivaAccount.value = rowData.iva_account || '';
                    }
                    if (info.generalAccount && Object.prototype.hasOwnProperty.call(rowData, 'general_account')) {
                        info.generalAccount.value = rowData.general_account || '';
                    }
                });

                var defaults = res.rates || {};
                Object.keys(rateInputs).forEach(function(rate) {
                    var info = rateInputs[rate];
                    var defaultData = defaults[rate] || {};
                    if (info.ivaAccount && !info.ivaAccount.value) {
                        info.ivaAccount.value = defaultData.iva_account || '';
                    }
                    if (info.generalAccount && !info.generalAccount.value) {
                        info.generalAccount.value = defaultData.general_account || '';
                    }
                });

                var serverCostCenters = null;
                if (Object.prototype.hasOwnProperty.call(res, 'cost_centers')) {
                    serverCostCenters = res.cost_centers;
                } else if (Object.prototype.hasOwnProperty.call(res, 'cost_center')) {
                    serverCostCenters = res.cost_center;
                }
                if (serverCostCenters !== null && serverCostCenters !== undefined && !hasAnyCostCenterValue()) {
                    applyCostCenterValues(serverCostCenters);
                }

                currentCostCenters = getCostCenterValues();
                Object.keys(rateInputs).forEach(function(rate) {
                    var info = rateInputs[rate];
                    if (!currentRateData[rate]) {
                        currentRateData[rate] = {};
                    }
                    currentRateData[rate].base = info.base ? info.base.value : '';
                    currentRateData[rate].iva = info.iva ? info.iva.value : '';
                    currentRateData[rate].iva_account = info.ivaAccount ? info.ivaAccount.value : '';
                    currentRateData[rate].general_account = info.generalAccount ? info.generalAccount.value : '';
                });


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

        var ratesPayload = {};
        Object.keys(rateInputs).forEach(function(rate) {
            var info = rateInputs[rate];
            ratesPayload[rate] = {
                iva_account: info.ivaAccount ? info.ivaAccount.value.trim() : '',
                general_account: info.generalAccount ? info.generalAccount.value.trim() : ''
            };
        });

        var costCentersPayload = getCostCenterValues();
        var body = new URLSearchParams({
            id: currentBtn.getAttribute('data-id') || '',
            A: currentBtn.getAttribute('data-emitter') || '',
            B: currentBtn.getAttribute('data-acquirer') || '',
            D: currentBtn.getAttribute('data-doctype') || '',
            rates: JSON.stringify(ratesPayload),
            cost_centers: JSON.stringify(costCentersPayload),
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
                var responseCostCenters = null;
                if (Object.prototype.hasOwnProperty.call(res, 'cost_centers')) {
                    responseCostCenters = res.cost_centers;
                } else if (Object.prototype.hasOwnProperty.call(res, 'cost_center')) {
                    responseCostCenters = res.cost_center;
                } else {
                    responseCostCenters = costCentersPayload;
                }
                applyCostCenterValues(responseCostCenters);
                currentCostCenters = getCostCenterValues();

                if (res.row_rates && typeof res.row_rates === 'object') {
                    Object.keys(res.row_rates).forEach(function(rate) {
                        var info = rateInputs[rate];
                        if (!currentRateData[rate]) {
                            currentRateData[rate] = {};
                        }
                        currentRateData[rate].base = info && info.base ? info.base.value : '';
                        currentRateData[rate].iva = info && info.iva ? info.iva.value : '';
                        currentRateData[rate].iva_account = (res.row_rates[rate] && res.row_rates[rate].iva_account) || '';
                        currentRateData[rate].general_account = (res.row_rates[rate] && res.row_rates[rate].general_account) || '';
                        if (info && info.ivaAccount) {
                            info.ivaAccount.value = currentRateData[rate].iva_account;
                        }
                        if (info && info.generalAccount) {
                            info.generalAccount.value = currentRateData[rate].general_account;
                        }
                    });
                } else {
                    Object.keys(rateInputs).forEach(function(rate) {
                        var info = rateInputs[rate];
                        if (!currentRateData[rate]) {
                            currentRateData[rate] = {};
                        }
                        currentRateData[rate].base = info.base ? info.base.value : '';
                        currentRateData[rate].iva = info.iva ? info.iva.value : '';
                        currentRateData[rate].iva_account = ratesPayload[rate].iva_account;
                        currentRateData[rate].general_account = ratesPayload[rate].general_account;
                    });
                }

                currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
                currentBtn.setAttribute('data-cost-centers', JSON.stringify(currentCostCenters));
                updateButtonClass(currentBtn);
                if (classifyModal) {
                    classifyModal.hide();
                }
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
            '<th>Preço</th>';
        if (showLineCostCenter) {
            html += '<th>Centro de Custo</th>';
        }
        html += '</tr></thead><tbody>';
        lines.forEach(function(line) {
            var erp = line.ERP || '';
            var iva = line.IVA_TAXA || line.OTHER || '';
            var productCode = line.PRODUCT_CODE || '';
            var item = line.ITEM || (line.ITEM_QUANTITY_UNIT_PRICE && line.ITEM_QUANTITY_UNIT_PRICE.ITEM) || '';
            var quantity = line.QUANTITY || (line.ITEM_QUANTITY_UNIT_PRICE && line.ITEM_QUANTITY_UNIT_PRICE.QUANTITY) || '';
            var unitPrice = line.UNIT_PRICE || (line.ITEM_QUANTITY_UNIT_PRICE && line.ITEM_QUANTITY_UNIT_PRICE.UNIT_PRICE) || '';
            var price = line.PRICE || '';
            var priceVat = line.PRICE_VAT || '';
            var costCenter = '';
            if (showLineCostCenter) {
                costCenter = line.COST_CENTER || line.cost_center || '';
            }
            if (!priceVat) {
                var priceNum = parseFloat(String(price).replace(',', '.'));
                var ivaNum = parseFloat(String(iva).replace(',', '.'));
                if (!isNaN(priceNum) && !isNaN(ivaNum)) {
                    priceVat = (priceNum * (1 + ivaNum / 100)).toFixed(2);
                }
            }
            html += '<tr>' +
                '<td><input type="text" class="form-control erp-input" value="' + escapeHtml(erp) + '"><input type="hidden" class="price-vat" value="' + escapeHtml(priceVat) + '"></td>' +
                '<td class="iva-taxa">' + escapeHtml(iva) + '</td>' +
                '<td class="product-code">' + escapeHtml(productCode) + '</td>' +
                '<td class="item">' + escapeHtml(item) + '</td>' +
                '<td class="quantity">' + escapeHtml(quantity) + '</td>' +
                '<td class="unit-price">' + escapeHtml(unitPrice) + '</td>' +
                '<td class="price">' + escapeHtml(price) + '</td>';
            if (showLineCostCenter) {
                html += '<td><input type="text" class="form-control cost-center-input" value="' + escapeHtml(costCenter) + '"></td>';
            }
            html += '</tr>';
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
            var costCenterInputEl = showLineCostCenter ? row.querySelector('.cost-center-input') : null;
            var costCenter = costCenterInputEl ? costCenterInputEl.value.trim() : '';
            if (!erp) {
                allErpFilled = false;
            }
            var linePayload = {
                ERP: erp,
                IVA_TAXA: iva,
                PRODUCT_CODE: productCode,
                ITEM: item,
                QUANTITY: quantity,
                UNIT_PRICE: unitPrice,
                PRICE: price,
                PRICE_VAT: priceVat
            };
            if (showLineCostCenter) {
                linePayload.COST_CENTER = costCenter;
            }
            linesToSave.push(linePayload);
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

