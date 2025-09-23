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
    var allowDynamicLines = importType === 1;
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

    function trimmedString(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).trim();
    }

    function extractLineValue(line, keys) {
        if (!line || typeof line !== 'object') {
            return '';
        }
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            if (Object.prototype.hasOwnProperty.call(line, key)) {
                var directValue = line[key];
                if (directValue !== null && directValue !== undefined) {
                    var directString = trimmedString(directValue);
                    if (directString !== '') {
                        return directString;
                    }
                }
            }
            var lowerKey = typeof key === 'string' ? key.toLowerCase() : key;
            if (lowerKey !== key && Object.prototype.hasOwnProperty.call(line, lowerKey)) {
                var lowerValue = line[lowerKey];
                if (lowerValue !== null && lowerValue !== undefined) {
                    var lowerString = trimmedString(lowerValue);
                    if (lowerString !== '') {
                        return lowerString;
                    }
                }
            }
            var upperKey = typeof key === 'string' ? key.toUpperCase() : key;
            if (upperKey !== key && Object.prototype.hasOwnProperty.call(line, upperKey)) {
                var upperValue = line[upperKey];
                if (upperValue !== null && upperValue !== undefined) {
                    var upperString = trimmedString(upperValue);
                    if (upperString !== '') {
                        return upperString;
                    }
                }
            }
        }
        return '';
    }

    function normalizeRateDisplay(value) {
        var rate = trimmedString(value);
        if (rate.endsWith('%')) {
            rate = rate.slice(0, -1).trim();
        }
        return rate;
    }

    function parseLocalizedNumber(value) {
        if (value === null || value === undefined) {
            return null;
        }
        var stringValue = String(value).trim();
        if (!stringValue) {
            return null;
        }
        stringValue = stringValue.replace(/%/g, '').replace(/\s+/g, '');
        var commaIndex = stringValue.lastIndexOf(',');
        var dotIndex = stringValue.lastIndexOf('.');
        if (commaIndex > -1 && dotIndex > -1) {
            if (commaIndex > dotIndex) {
                stringValue = stringValue.replace(/\./g, '').replace(',', '.');
            } else {
                stringValue = stringValue.replace(/,/g, '');
            }
        } else if (commaIndex > -1) {
            stringValue = stringValue.replace(/\./g, '').replace(',', '.');
        }
        var result = parseFloat(stringValue);
        return Number.isNaN(result) ? null : result;
    }

    function formatLocalizedNumber(value) {
        if (typeof value !== 'number' || !isFinite(value)) {
            return '';
        }
        return value.toFixed(2);
    }

    function buildLineRow(line) {
        var data = line && typeof line === 'object' ? line : {};
        var erp = extractLineValue(data, ['ERP']);
        var taxRate = normalizeRateDisplay(extractLineValue(data, ['TAXA', 'IVA_TAXA', 'IVA', 'TAX_RATE', 'IVA TAXA', 'OTHER']));
        var baseValue = extractLineValue(data, ['BASE', 'BASE_IMPONIVEL', 'BASE IMPONIVEL', 'PRICE', 'PRECO', 'VALOR']);
        var ivaValue = extractLineValue(data, ['IVA', 'IVA_TOTAL', 'IVA_VALOR', 'IVA VALOR', 'TAX_AMOUNT']);
        var ivaAccount = extractLineValue(data, ['CONTA_IVA', 'CONTA IVA', 'IVA_ACCOUNT']);
        var generalAccount = extractLineValue(data, ['CONTA_GERAL', 'CONTA GERAL', 'GENERAL_ACCOUNT']);
        var costCenter = extractLineValue(data, ['CENTRO_CUSTO', 'CENTRO DE CUSTO', 'CENTRO_DE_CUSTO', 'COST_CENTER']);
        var productCode = extractLineValue(data, ['PRODUCT_CODE', 'CODIGO', 'CÓDIGO', 'COD ARTIGO']);
        var item = extractLineValue(data, ['ITEM', 'DESCRICAO', 'DESCRIÇÃO']);
        var quantity = extractLineValue(data, ['QUANTITY', 'QTD', 'QUANTIDADE']);
        var unitPrice = extractLineValue(data, ['UNIT_PRICE', 'PRECO_UNITARIO', 'PREÇO_UNITARIO', 'PREÇO UNITÁRIO']);
        var price = extractLineValue(data, ['PRICE', 'PRECO', 'VALOR']);
        if (data.ITEM_QUANTITY_UNIT_PRICE && typeof data.ITEM_QUANTITY_UNIT_PRICE === 'object') {
            var nested = data.ITEM_QUANTITY_UNIT_PRICE;
            if (!item) {
                item = trimmedString(extractLineValue(nested, ['ITEM', 'DESCRICAO', 'DESCRIÇÃO']));
            }
            if (!quantity) {
                quantity = trimmedString(extractLineValue(nested, ['QUANTITY', 'QTD', 'QUANTIDADE']));
            }
            if (!unitPrice) {
                unitPrice = trimmedString(extractLineValue(nested, ['UNIT_PRICE', 'PRECO_UNITARIO', 'PREÇO_UNITARIO', 'PREÇO UNITÁRIO']));
            }
            if (!price) {
                price = trimmedString(extractLineValue(nested, ['PRICE', 'PRECO', 'VALOR']));
            }
        }
        if (!baseValue && price) {
            baseValue = price;
        }
        var priceVat = extractLineValue(data, ['PRICE_VAT', 'TOTAL_COM_IVA', 'TOTAL IVA', 'TOTAL']);
        var baseNumber = parseLocalizedNumber(baseValue);
        var ivaNumber = parseLocalizedNumber(ivaValue);
        var rateNumber = parseLocalizedNumber(taxRate);
        if (!ivaValue && baseNumber !== null && rateNumber !== null) {
            ivaValue = formatLocalizedNumber(baseNumber * (rateNumber / 100));
            ivaNumber = parseLocalizedNumber(ivaValue);
        }
        if (!priceVat) {
            if (baseNumber !== null && ivaNumber !== null) {
                priceVat = formatLocalizedNumber(baseNumber + ivaNumber);
            } else if (baseNumber !== null && rateNumber !== null) {
                priceVat = formatLocalizedNumber(baseNumber * (1 + rateNumber / 100));
            }
        }

        var row = document.createElement('tr');
        row.dataset.productCode = productCode;
        row.dataset.item = item;
        row.dataset.quantity = quantity;
        row.dataset.unitPrice = unitPrice;
        row.dataset.price = price || baseValue;
        row.dataset.priceVat = priceVat || '';

        var cells = [
            { className: 'erp-input', value: erp },
            { className: 'tax-rate-input', value: taxRate },
            { className: 'base-input', value: baseValue },
            { className: 'iva-amount-input', value: ivaValue },
            { className: 'iva-account-input', value: ivaAccount },
            { className: 'general-account-input', value: generalAccount }
        ];

        if (showLineCostCenter) {
            cells.push({ className: 'cost-center-input', value: costCenter });
        }


        cells.forEach(function(cell) {
            var td = document.createElement('td');
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm ' + cell.className;
            input.value = cell.value || '';
            td.appendChild(input);
            row.appendChild(td);
        });

        if (allowDynamicLines) {
            var actionsTd = document.createElement('td');
            actionsTd.className = 'text-center';
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-sm btn-outline-danger remove-line-btn';
            removeBtn.innerHTML = '<i class="fa fa-trash"></i>';
            actionsTd.appendChild(removeBtn);
            row.appendChild(actionsTd);
        }

        return row;
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
    var linesModalEl = document.getElementById('linesModal');
    var linesModal = linesModalEl ? new bootstrap.Modal(linesModalEl) : null;
    var linesContainer = document.getElementById('linesContainer');
    var confirmLinesBtn = document.getElementById('confirmLinesBtn');
    var addLineBtn = document.getElementById('addLineBtn');
    var currentLinesId = null;

    if (addLineBtn) {
        addLineBtn.disabled = true;
        if (!allowDynamicLines) {
            addLineBtn.classList.add('d-none');
        } else {
            addLineBtn.classList.remove('d-none');
        }
    }

    if (linesModalEl) {
        linesModalEl.addEventListener('hidden.bs.modal', function() {
            currentLinesId = null;
            if (addLineBtn) {
                addLineBtn.disabled = true;
                addLineBtn.onclick = null;
            }
            if (linesContainer) {
                linesContainer.innerHTML = '';
            }
        });
    }

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
        if (!linesModal || !linesContainer) {
            return;
        }
        currentLinesId = id;
        linesContainer.innerHTML = '<div class="d-flex justify-content-center my-3"><div class="spinner-border" role="status"><span class="visually-hidden">A carregar...</span></div></div>';
        if (addLineBtn) {
            addLineBtn.disabled = true;
            addLineBtn.onclick = null;
        }
        linesModal.show();
        var params = new URLSearchParams({
            action: 'lines',
            id: id
        });
        fetchJson('contabilidade/save-analysis.php?' + params.toString())
            .then(function(res) {
                if (res.error) {
                    if (linesModal) {
                        linesModal.hide();
                    }
                    showError(res.error);
                    return;
                }
                renderLines(res);
            })
            .catch(function(err) {
                if (linesModal) {
                    linesModal.hide();
                }
                showError(err.message || 'Erro na análise');
            });
    });

    function normalizeLinesPayload(raw) {
        if (Array.isArray(raw)) {
            return raw;
        }
        if (raw && typeof raw === 'object') {
            if (Array.isArray(raw.lines)) {
                return raw.lines;
            }
            if (Array.isArray(raw.items)) {
                return raw.items;
            }
        }
        return [];
    }

    function renderLines(lines) {

        var normalized = normalizeLinesPayload(lines);
        linesContainer.innerHTML = '';

        var wrapper = document.createElement('div');
        var addButton = null;

        if (allowDynamicLines) {
            var actionsRow = document.createElement('div');
            actionsRow.className = 'd-flex justify-content-end mb-2';
            addButton = document.createElement('button');
            addButton.type = 'button';
            addButton.className = 'btn btn-sm btn-outline-primary add-line-btn';
            addButton.textContent = 'Adicionar linha';
            actionsRow.appendChild(addButton);
            wrapper.appendChild(actionsRow);
        }

        var table = document.createElement('table');
        table.className = 'table table-striped align-middle';
        var thead = document.createElement('thead');
        var headerRow = document.createElement('tr');
        var headerLabels = ['ERP', 'Taxa', 'Base', 'IVA', 'Conta IVA', 'Conta Geral'];
        if (showLineCostCenter) {
            headerLabels.push('Centro de Custo');
        }
        if (allowDynamicLines) {
            headerLabels.push('');
        }
        headerLabels.forEach(function(label) {
            var th = document.createElement('th');
            if (label === '') {
                th.className = 'text-center';
            } else {
                th.textContent = label;
            }
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');
        table.appendChild(tbody);
        wrapper.appendChild(table);
        linesContainer.appendChild(wrapper);

        function appendRow(data) {
            tbody.appendChild(buildLineRow(data));
        }

        if (normalized.length > 0) {
            normalized.forEach(function(line) {
                appendRow(line);
            });
        } else {
            appendRow({});
        }

        if (allowDynamicLines) {
            if (addButton) {
                addButton.addEventListener('click', function() {
                    appendRow({});
                });
            }
            if (addLineBtn) {
                addLineBtn.disabled = false;
                addLineBtn.onclick = function() {
                    appendRow({});
                };
            }
            tbody.addEventListener('click', function(event) {
                var btn = event.target.closest('.remove-line-btn');
                if (!btn) {
                    return;
                }

                var row = btn.closest('tr');
                if (!row) {
                    return;
                }
                row.remove();
                if (!tbody.querySelector('tr')) {
                    appendRow({});
                }
            });
        } else if (addLineBtn) {
            addLineBtn.disabled = true;
            addLineBtn.onclick = null;
        }
    }

    if (confirmLinesBtn) {
        confirmLinesBtn.addEventListener('click', function() {
            if (!currentLinesId) {
                return;
            }
            var rows = linesContainer.querySelectorAll('tbody tr');
            var linesToSave = [];


            var allLinesComplete = true;
            rows.forEach(function(row) {
                var erp = row.querySelector('.erp-input').value.trim();
                var taxRate = row.querySelector('.tax-rate-input').value.trim();
                var base = row.querySelector('.base-input').value.trim();
                var ivaAmount = row.querySelector('.iva-amount-input').value.trim();
                var ivaAccount = row.querySelector('.iva-account-input').value.trim();
                var generalAccount = row.querySelector('.general-account-input').value.trim();
                var costCenterInputEl = row.querySelector('.cost-center-input');
                var costCenter = costCenterInputEl ? costCenterInputEl.value.trim() : '';

                var productCode = row.dataset.productCode || '';
                var item = row.dataset.item || '';
                var quantity = row.dataset.quantity || '';
                var unitPrice = row.dataset.unitPrice || '';
                var price = row.dataset.price || base;
                var priceVat = row.dataset.priceVat || '';

                var baseNumber = parseLocalizedNumber(base);
                var ivaNumber = parseLocalizedNumber(ivaAmount);
                var rateNumber = parseLocalizedNumber(taxRate);
                if (!price && base !== '') {
                    price = base;

                }
                if (!priceVat) {
                    if (baseNumber !== null && ivaNumber !== null) {
                        priceVat = formatLocalizedNumber(baseNumber + ivaNumber);
                    } else if (baseNumber !== null && rateNumber !== null) {
                        priceVat = formatLocalizedNumber(baseNumber * (1 + rateNumber / 100));
                    }
                }

                row.dataset.price = price;
                row.dataset.priceVat = priceVat;

                var linePayload = {
                    ERP: erp,
                    IVA_TAXA: taxRate,
                    TAXA: taxRate,
                    BASE: base,
                    IVA: ivaAmount,
                    CONTA_IVA: ivaAccount,
                    CONTA_GERAL: generalAccount,
                    PRODUCT_CODE: productCode,
                    ITEM: item,
                    QUANTITY: quantity,
                    UNIT_PRICE: unitPrice,
                    PRICE: price,
                    PRICE_VAT: priceVat,
                    COST_CENTER: costCenter,
                    CENTRO_CUSTO: costCenter
                };
                if (ivaAccount) {
                    linePayload.IVA_ACCOUNT = ivaAccount;
                }
                if (generalAccount) {
                    linePayload.GENERAL_ACCOUNT = generalAccount;
                }
                linesToSave.push(linePayload);

                var rowComplete = erp !== '' || (
                    taxRate !== '' &&
                    base !== '' &&
                    ivaAmount !== '' &&
                    ivaAccount !== '' &&
                    generalAccount !== ''
                );
                if (!rowComplete) {
                    allLinesComplete = false;
                }
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
                    if (linesModal) {
                        linesModal.hide();
                    }
                    var analyzeBtn = document.querySelector('.analyze-lines[data-id="' + currentLinesId + '"]');
                    if (analyzeBtn) {
                        analyzeBtn.classList.remove('btn-info', 'btn-success');
                        analyzeBtn.classList.add(allLinesComplete ? 'btn-success' : 'btn-info');
                    }
                } else {
                    showError(res.error || 'Erro ao guardar linhas');
                }
            })
            .catch(function(err) {
                showError(err.message || 'Erro ao guardar linhas');
            });
        });
    }
});

