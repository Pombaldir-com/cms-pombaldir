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

    function showSuccess(message) {
        if (window.PNotify) {
            new PNotify({
                title: 'Sucesso',
                text: message,
                type: 'success',
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

    function debugJson(label, value) {
        if (!window.console) {
            return;
        }
        var prefix = '[Classificação] ' + label;
        try {
            var safe = JSON.parse(JSON.stringify(value));
            if (typeof console.debug === 'function') {
                console.debug(prefix, safe);
            } else {
                console.log(prefix, safe);
            }
        } catch (err) {
            if (typeof console.debug === 'function') {
                console.debug(prefix, value);
            } else {
                console.log(prefix, value);
            }
        }
    }
    var csrfInput = document.getElementById('csrf_token');
    var importTypeInput = document.getElementById('import_type');
    var importType = importTypeInput ? parseInt(importTypeInput.value, 10) : 1;
    if (isNaN(importType)) {
        importType = 1;
    }
    var erpWebserviceUrl = '';
    if (typeof window.erpWebserviceUrl === 'string') {
        erpWebserviceUrl = window.erpWebserviceUrl;
    }

    var importCtbRelativeUrl = 'contabilidade/classificacao-importacao/import-ctb';

    function buildImportCtbUrl() {
        if (typeof erpWebserviceUrl === 'string' && erpWebserviceUrl.trim() !== '') {
            try {
                var parsedUrl = new URL(erpWebserviceUrl, window.location.href);
                if (parsedUrl.origin === window.location.origin) {
                    return parsedUrl.href.replace(/\/+$/, '') + '/ctb/import-ctb';
                }
                if (typeof window.console !== 'undefined' && typeof window.console.warn === 'function') {
                    window.console.warn('[Classificação] ERP webservice URL com origem diferente detectada. A usar endpoint local para evitar problemas de CORS.');
                }
            } catch (urlError) {
                if (typeof window.console !== 'undefined' && typeof window.console.warn === 'function') {
                    window.console.warn('[Classificação] ERP webservice URL inválida. A usar endpoint local.', urlError);
                }
            }
        }
        return importCtbRelativeUrl;
    }
    var showLineCostCenter = importType === 1;
    var importCtbButton = $('#importCtbButton');
    var importCtbWrapper = $('#importCtbButtonWrapper');

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

    function hideImportButtonWrapper() {
        if (importCtbWrapper.length) {
            importCtbWrapper.addClass('d-none').attr('aria-hidden', 'true');
        }
    }

    function showImportButtonWrapper() {
        if (importCtbWrapper.length) {
            importCtbWrapper.removeClass('d-none').removeAttr('aria-hidden');
        }
    }

    function moveImportButtonToFilter() {
        if (!importCtbButton.length || importType !== 1) {
            showImportButtonWrapper();
            return;
        }

        var container = table.table().container();
        if (!container) {
            showImportButtonWrapper();
            return;
        }

        var layoutEnd = $(container).find('.dt-layout-end').first();
        if (layoutEnd.length) {
            layoutEnd.addClass('d-md-flex justify-content-between align-items-center gap-2 flex-wrap');
            if (!layoutEnd.find('#importCtbButton').length) {
                importCtbButton.prependTo(layoutEnd);
            }

            var dtSearch = layoutEnd.find('.dt-search').first();
            if (dtSearch.length) {
                dtSearch.addClass('d-flex align-items-center flex-wrap gap-2');
                var dtLabel = dtSearch.find('label').first();
                if (dtLabel.length) {
                    dtLabel.addClass('mb-0 d-flex align-items-center flex-wrap gap-2');
                }
            }

            hideImportButtonWrapper();
            return;
        }

        var filter = $(container).find('div.dataTables_filter');
        if (!filter.length) {
            showImportButtonWrapper();
            return;
        }

        filter.addClass('d-flex align-items-center justify-content-end flex-wrap gap-2');

        var label = filter.find('label');
        if (label.length) {
            label.addClass('mb-0 d-flex align-items-center flex-wrap gap-2');
            if (!label.find('#importCtbButton').length) {
                importCtbButton.prependTo(label);
            }
        } else if (!filter.find('#importCtbButton').length) {
            importCtbButton.prependTo(filter);
        }

        hideImportButtonWrapper();
    }

    function updateImportButtonState() {
        if (!importCtbButton.length || importType !== 1) {
            return;
        }
        if (importCtbButton.data('loading')) {
            importCtbButton.prop('disabled', true);
            return;
        }
        var readyCount = $('#classify-table').find('.classify-row.btn-success').length;
        importCtbButton.prop('disabled', readyCount === 0);
    }

    function handleImportCtbClick() {
        if (!importCtbButton.length || importCtbButton.data('loading')) {
            return;
        }
        var ids = [];
        $('#classify-table').find('.classify-row.btn-success').each(function() {
            var id = this.getAttribute('data-id');
            if (id) {
                ids.push(id);
            }
        });
        if (ids.length === 0) {
            showError('Não existem linhas prontas para importar.');
            return;
        }
        importCtbButton.data('loading', true);
        importCtbButton.prop('disabled', true);
        var payload = {
            ids: ids,
            import_type: importType,
            csrf_token: csrfInput ? csrfInput.value : '',
            act: 'import-ctb'
        };
        debugJson('Import CTB request payload', payload);
        var importUrl = buildImportCtbUrl();
        if (!importUrl) {
            if (typeof window.console !== 'undefined') {
                console.error('[Classificação] URL do webservice ERP não configurada.');
            }
            showError('URL do webservice ERP não está configurada.');
            importCtbButton.data('loading', false);
            updateImportButtonState();
            return;
        }
        fetchJson(importUrl, {

            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function(res) {
                debugJson('Import CTB response payload', res);
                if (res && res.csrf_token && csrfInput) {
                    csrfInput.value = res.csrf_token;
                }
                if (!res || !res.success) {
                    var error = (res && res.error) ? res.error : 'Erro ao importar';
                    throw new Error(error);
                }
                if (res && res.service_response) {
                    debugJson('Import CTB service response', res.service_response);
                }
                var message = (res && res.message) ? res.message : 'OK';
                showSuccess(message);
                if (typeof window.console !== 'undefined') {
                    console.log('[Classificação] Import CTB concluído. HTTP:', res.http_status, 'IDs:', ids);
                }
                table.ajax.reload(null, false);
            })
            .catch(function(err) {
                if (typeof window.console !== 'undefined') {
                    console.error('[Classificação] Erro na importação CTB:', err);
                }
                showError(err.message || 'Erro ao importar');
            })
            .finally(function() {
                if (importCtbButton.length) {
                    importCtbButton.data('loading', false);
                }
                updateImportButtonState();
            });
    }

    if (importCtbButton.length) {
        importCtbButton.on('click', handleImportCtbClick);
    }

    table.on('draw', function() {
        updateImportButtonState();
        moveImportButtonToFilter();
    });

    table.on('init.dt', function() {
        moveImportButtonToFilter();
        updateImportButtonState();

    });

    moveImportButtonToFilter();
    updateImportButtonState();
    moveImportButtonToFilter();

    function decodeHtmlEntities(value) {
        if (typeof value !== 'string' || value.indexOf('&') === -1) {
            return value;
        }
        var textarea = document.createElement('textarea');
        textarea.innerHTML = value;
        return textarea.value;
    }

    function tryParseJson(value) {
        if (typeof value !== 'string' || value.trim() === '') {
            return null;
        }
        try {
            return JSON.parse(value);
        } catch (err) {
            return null;
        }
    }

    function parseJsonAttribute(el, attr) {
        var rawValue = el.getAttribute(attr);
        if (!rawValue) {
            return null;
        }

        var candidates = [];
        candidates.push(rawValue);

        var decoded = decodeHtmlEntities(rawValue);
        if (decoded !== rawValue) {
            candidates.push(decoded);
        }

        if (decoded.length >= 2 && decoded[0] === '"' && decoded[decoded.length - 1] === '"') {
            candidates.push(decoded.slice(1, -1));
        }

        for (var i = 0; i < candidates.length; i += 1) {
            var parsed = tryParseJson(candidates[i]);
            if (parsed !== null) {
                return parsed;
            }
        }

        return null;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function extractScalarFromMixed(value) {
        if (typeof value === 'string' || typeof value === 'number') {
            return String(value).trim();
        }
        if (!value || typeof value !== 'object') {
            return '';
        }
        if (Array.isArray(value)) {
            for (var i = 0; i < value.length; i += 1) {
                var nested = extractScalarFromMixed(value[i]);
                if (nested !== '') {
                    return nested;
                }
            }
            return '';
        }
        if (Object.prototype.hasOwnProperty.call(value, 'value')) {
            var viaValue = extractScalarFromMixed(value.value);
            if (viaValue !== '') {
                return viaValue;
            }
        }
        var preferredKeys = ['account', 'code', 'label', 'text'];
        for (var k = 0; k < preferredKeys.length; k += 1) {
            var key = preferredKeys[k];
            if (Object.prototype.hasOwnProperty.call(value, key)) {
                var preferred = extractScalarFromMixed(value[key]);
                if (preferred !== '') {
                    return preferred;
                }
            }
        }
        return '';
    }

    function hasMeaningfulRateEntry(entry) {
        if (!entry || typeof entry !== 'object') {
            return false;
        }
        var fields = ['iva_account', 'general_account', 'label', 'base', 'iva', 'base_value', 'iva_value', 'cost_center', 'value'];
        for (var i = 0; i < fields.length; i += 1) {
            var field = fields[i];
            if (!Object.prototype.hasOwnProperty.call(entry, field)) {
                continue;
            }
            if (extractScalarFromMixed(entry[field]) !== '') {
                return true;
            }
        }
        return false;
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

        updateImportButtonState();

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
    var defaultModalTitle = '';
    if (modalTitleEl) {
        defaultModalTitle = (modalTitleEl.textContent || '').trim();
    }
    var form = document.getElementById('classify-form');
    var addVatLineBtn = document.getElementById('addVatLineBtn');
    var vatRateRowTemplate = document.getElementById('vatRateRowTemplate');
    var customRateRowTemplate = document.getElementById('customRateRowTemplate');
    var rateInputs = {};
    var currentRateData = {};
    var currentCostCenters = {};
    var storedRowRates = {};
    var storedDefaultRates = {};
    var originalRateValues = {};
    var serverOriginalRates = {};
    var removedRates = {};
    var dynamicRateCounter = 0;
    var originalRatesStoragePrefix = 'classificationOriginalRates:v1:';
    var currentOriginalRatesKey = null;
    var canUseOriginalRatesStorage = (function() {
        try {
            var storage = window.localStorage;
            if (!storage) {
                return false;
            }
            var testKey = originalRatesStoragePrefix + 'test';
            storage.setItem(testKey, '1');
            storage.removeItem(testKey);
            return true;
        } catch (err) {
            return false;
        }
    })();
    var defaultRateLabels = {
        '0': '0%',
        '6': '6%',
        '13': '13%',
        '23': '23%'
    };
    var defaultRates = Object.keys(defaultRateLabels);

    function ensureRateData(rate) {
        if (!currentRateData[rate] || typeof currentRateData[rate] !== 'object') {
            currentRateData[rate] = {};
        }
        var data = currentRateData[rate];
        if (data.base === undefined && data.base_value !== undefined) {
            data.base = data.base_value;
        }
        if (data.iva === undefined && data.iva_value !== undefined) {
            data.iva = data.iva_value;
        }
        return data;
    }

    function parsePercentageValue(value) {
        if (value === null || value === undefined) {
            return null;
        }
        var stringValue = String(value).trim();
        if (stringValue === '') {
            return null;
        }
        var normalized = stringValue.replace(/,/g, '.');
        var match = normalized.match(/-?\d+(?:\.\d+)?/);
        if (!match) {
            return null;
        }
        var number = parseFloat(match[0]);
        return isNaN(number) ? null : number;
    }

    function parseDecimalValue(value) {
        if (value === null || value === undefined) {
            return null;
        }
        var stringValue = String(value).trim();
        if (stringValue === '') {
            return null;
        }
        var sanitized = stringValue.replace(/\s+/g, '');
        var hasComma = sanitized.indexOf(',') !== -1;
        var hasDot = sanitized.indexOf('.') !== -1;
        if (hasComma && hasDot) {
            if (sanitized.lastIndexOf(',') > sanitized.lastIndexOf('.')) {
                sanitized = sanitized.replace(/\./g, '');
                sanitized = sanitized.replace(/,/g, '.');
            } else {
                sanitized = sanitized.replace(/,/g, '');
            }
        } else if (hasComma) {
            sanitized = sanitized.replace(/,/g, '.');
        }
        sanitized = sanitized.replace(/[^0-9.\-]/g, '');
        var firstDot = sanitized.indexOf('.');
        if (firstDot !== -1) {
            var before = sanitized.slice(0, firstDot + 1);
            var after = sanitized.slice(firstDot + 1).replace(/\./g, '');
            sanitized = before + after;
        }
        var number = parseFloat(sanitized);
        return isNaN(number) ? null : number;
    }

    function formatDecimalValue(value) {
        if (typeof value !== 'number' || !isFinite(value)) {
            return '';
        }
        return value.toFixed(2);
    }

    function extractPercentageFromData(data) {
        if (!data || typeof data !== 'object') {
            return null;
        }
        var fields = ['rate', 'tax', 'percentage', 'value', 'label'];
        for (var i = 0; i < fields.length; i += 1) {
            var field = fields[i];
            if (!Object.prototype.hasOwnProperty.call(data, field)) {
                continue;
            }
            var parsed = parsePercentageValue(data[field]);
            if (parsed !== null) {
                return parsed;
            }
        }
        return null;
    }

    function getRatePercentage(rate) {
        if (!rate) {
            return null;
        }
        var rateString = String(rate).trim();
        if (rateString !== '') {
            var compact = rateString.replace(/\s+/g, '');
            if (/^[-+]?\d+(?:[.,]\d+)?%?$/.test(compact)) {
                var strict = parsePercentageValue(rateString);
                if (strict !== null) {
                    return strict;
                }
            }

        }
        var info = rateInputs[rate];
        if (info) {
            if (info.labelInput) {
                var fromInput = parsePercentageValue(info.labelInput.value);
                if (fromInput !== null) {
                    return fromInput;
                }
            }
            if (info.labelText) {
                var fromText = parsePercentageValue(info.labelText.textContent || '');
                if (fromText !== null) {
                    return fromText;
                }
            }
        }
        var dataSources = [currentRateData[rate], storedRowRates[rate], storedDefaultRates[rate]];
        for (var i = 0; i < dataSources.length; i += 1) {
            var source = dataSources[i];
            var parsed = extractPercentageFromData(source);
            if (parsed !== null) {
                return parsed;
            }
        }
        return null;
    }

    function recalculateVatForRate(rate, options) {
        var info = rateInputs[rate];
        if (!info || !info.base) {
            return;
        }
        var opts = options || {};
        var rateData = ensureRateData(rate);
        var rawBaseValue = info.base.value;
        rateData.base = rawBaseValue;
        rateData.base_value = rawBaseValue;
        var baseNumber = parseDecimalValue(rawBaseValue);
        var percentage = getRatePercentage(rate);
        if (rawBaseValue.trim() === '' || baseNumber === null || percentage === null) {
            if (info.iva) {
                info.iva.value = '';
            }
            rateData.iva = '';
            rateData.iva_value = '';
            if (opts.formatBase && rawBaseValue.trim() === '') {
                info.base.value = '';
                rateData.base = '';
                rateData.base_value = '';
            }
            return;
        }
        var ivaValue = baseNumber * (percentage / 100);
        var formattedIva = formatDecimalValue(ivaValue);
        if (info.iva && info.iva.value !== formattedIva) {
            info.iva.value = formattedIva;
        }
        rateData.iva = formattedIva;
        rateData.iva_value = formattedIva;
        if (opts.formatBase && baseNumber !== null) {
            var formattedBase = formatDecimalValue(baseNumber);
            if (info.base.value !== formattedBase) {
                info.base.value = formattedBase;
            }
            rateData.base = info.base.value;
            rateData.base_value = info.base.value;
        }
    }

    function normalizeAmountForComparison(value) {
        if (value === null || value === undefined) {
            return '';
        }
        var stringValue = String(value).trim();
        if (stringValue === '') {
            return '';
        }
        var parsed = parseDecimalValue(stringValue);
        if (parsed === null) {
            return stringValue;
        }
        return formatDecimalValue(parsed);
    }

    function normalizeServerOriginalRates(source) {
        var result = {};
        if (!source || typeof source !== 'object') {
            return result;
        }
        Object.keys(source).forEach(function(rate) {
            var entry = source[rate];
            if (!entry || typeof entry !== 'object') {
                return;
            }
            var base = '';
            if (entry.base !== undefined && entry.base !== null) {
                base = String(entry.base);
            } else if (entry.base_value !== undefined && entry.base_value !== null) {
                base = String(entry.base_value);
            }
            var iva = '';
            if (entry.iva !== undefined && entry.iva !== null) {
                iva = String(entry.iva);
            } else if (entry.iva_value !== undefined && entry.iva_value !== null) {
                iva = String(entry.iva_value);
            }
            result[String(rate)] = {
                base: base,
                iva: iva
            };
        });
        return result;
    }

    function cloneOriginalRates(source) {
        var clone = {};
        if (!source || typeof source !== 'object') {
            return clone;
        }
        Object.keys(source).forEach(function(rate) {
            var entry = source[rate];
            if (!entry || typeof entry !== 'object') {
                return;
            }
            var base = '';
            if (entry.base !== undefined && entry.base !== null) {
                base = String(entry.base);
            }
            var iva = '';
            if (entry.iva !== undefined && entry.iva !== null) {
                iva = String(entry.iva);
            }
            clone[String(rate)] = {
                base: base,
                iva: iva
            };
        });
        return clone;
    }

    function updateRowDirtyState(rate) {
        var info = rateInputs[rate];
        if (!info || !info.row || !info.base) {
            return;

        }
        var originalEntry = originalRateValues[rate];
        var restoreBtn = info.restoreBaseBtn || null;

        if (info.custom) {
            info.row.classList.add('table-warning');
            if (restoreBtn) {
                restoreBtn.classList.remove('d-none');
                restoreBtn.disabled = false;
            }
            return;
        }

        if (!originalEntry) {
            info.row.classList.add('table-warning');
            if (restoreBtn) {
                restoreBtn.classList.remove('d-none');
                restoreBtn.disabled = false;
            }
            return;
        }

        var originalNormalized = normalizeAmountForComparison(originalEntry.base);
        if (originalNormalized === '') {
            info.row.classList.remove('table-warning');
            if (restoreBtn) {
                restoreBtn.classList.add('d-none');
            }
            return;
        }


        var currentNormalized = normalizeAmountForComparison(info.base.value);
        if (originalNormalized !== currentNormalized) {
            info.row.classList.add('table-warning');
            if (restoreBtn) {
                restoreBtn.classList.remove('d-none');
                restoreBtn.disabled = false;
            }
        } else {
            info.row.classList.remove('table-warning');
            if (restoreBtn) {
                restoreBtn.classList.add('d-none');
            }
        }
    }

    function encodeOriginalRatesKeyPart(value) {
        return encodeURIComponent(String(value === undefined || value === null ? '' : value));
    }

    function buildOriginalRatesStorageKey(btn) {
        if (!btn) {
            return null;
        }
        var parts = [
            importType,
            btn.getAttribute('data-id') || '',
            btn.getAttribute('data-emitter') || '',
            btn.getAttribute('data-acquirer') || '',
            btn.getAttribute('data-doctype') || ''
        ];
        return originalRatesStoragePrefix + parts.map(encodeOriginalRatesKeyPart).join('|');
    }

    function loadStoredOriginalRates(key) {
        if (!canUseOriginalRatesStorage || !key) {
            return null;
        }
        try {
            var raw = window.localStorage.getItem(key);
            if (!raw) {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                return parsed;
            }
        } catch (err) {
            return null;
        }
        return null;
    }

    function persistOriginalRates(key, values) {
        if (!canUseOriginalRatesStorage || !key) {
            return;
        }
        try {
            window.localStorage.setItem(key, JSON.stringify(values || {}));
        } catch (err) {
            // Ignore persistence errors to avoid disrupting the workflow
        }
    }

    function buildOriginalRatesFromInputs() {
        var result = {};
        getRateKeys().forEach(function(rate) {
            var info = rateInputs[rate];
            if (!info) {
                return;
            }
            if (info.custom) {
                return;
            }
            result[rate] = {
                base: info.base ? String(info.base.value || '') : '',
                iva: info.iva ? String(info.iva.value || '') : ''
            };
        });
        return result;
    }

    function refreshAllDirtyStates() {
        getRateKeys().forEach(function(rate) {
            updateRowDirtyState(rate);
        });
    }

    function captureOriginalRateValues(options) {
        var opts = options || {};
        if (opts.initialize === true) {
            var stored = loadStoredOriginalRates(currentOriginalRatesKey);
            if (stored) {
                originalRateValues = stored;
            } else if (opts.allowCreate !== false) {
                originalRateValues = buildOriginalRatesFromInputs();
                persistOriginalRates(currentOriginalRatesKey, originalRateValues);
            } else {
                originalRateValues = originalRateValues || {};
            }
            if (opts.refresh !== false) {
                refreshAllDirtyStates();
            }
            return;
        }

        originalRateValues = buildOriginalRatesFromInputs();
        if (opts.persist !== false) {
            persistOriginalRates(currentOriginalRatesKey, originalRateValues);
        }

        if (opts.resetHighlights === true) {
            getRateKeys().forEach(function(rate) {
                var info = rateInputs[rate];
                if (!info || !info.row) {
                    return;
                }
                info.row.classList.remove('table-warning');
                if (info.restoreBaseBtn) {
                    info.restoreBaseBtn.classList.add('d-none');
                }
            });
        } else if (opts.refresh !== false) {
            refreshAllDirtyStates();
        }
    }

    function captureOriginalRateValues(options) {
        var opts = options || {};
        if (opts.initialize === true) {
            if (serverOriginalRates && typeof serverOriginalRates === 'object' && Object.keys(serverOriginalRates).length > 0) {
                originalRateValues = cloneOriginalRates(serverOriginalRates);
            } else if (opts.allowCreate !== false) {
                originalRateValues = buildOriginalRatesFromInputs();
                serverOriginalRates = cloneOriginalRates(originalRateValues);
            } else if (!originalRateValues || typeof originalRateValues !== 'object') {
                originalRateValues = {};
            }
            if (opts.refresh !== false) {
                refreshAllDirtyStates();
            }
            return;
        }

        originalRateValues = buildOriginalRatesFromInputs();
        serverOriginalRates = cloneOriginalRates(originalRateValues);

        if (opts.resetHighlights === true) {
            getRateKeys().forEach(function(rate) {
                var info = rateInputs[rate];
                if (!info || !info.row) {
                    return;
                }
                info.row.classList.remove('table-warning');
                if (info.restoreBaseBtn) {
                    info.restoreBaseBtn.classList.add('d-none');
                }
            });
        } else if (opts.refresh !== false) {
            refreshAllDirtyStates();
        }
    }

    function updateDynamicCounter(rate) {
        var match = /^custom_(\d+)$/.exec(rate);
        if (match) {
            var num = parseInt(match[1], 10);
            if (!isNaN(num) && num > dynamicRateCounter) {
                dynamicRateCounter = num;
            }
        }
    }

    function generateCustomRateKey() {
        dynamicRateCounter += 1;
        return 'custom_' + dynamicRateCounter;
    }

    function getRateKeys() {
        return Object.keys(rateInputs);
    }

    function getRateLabel(rate) {
        var info = rateInputs[rate];
        if (!info) {
            return '';
        }
        if (info.labelInput) {
            return info.labelInput.value.trim();
        }
        if (info.labelText) {
            return info.labelText.textContent.trim();
        }
        if (currentRateData[rate] && typeof currentRateData[rate].label === 'string') {
            return currentRateData[rate].label;
        }
        return '';
    }

    function getDefaultRateLabel(rate) {
        if (Object.prototype.hasOwnProperty.call(defaultRateLabels, rate)) {
            return defaultRateLabels[rate];
        }
        return '';
    }

    function registerRateRow(row, explicitRate) {
        if (!row) {
            return null;
        }
        var rate = explicitRate || row.getAttribute('data-rate') || '';
        if (!rate) {
            rate = generateCustomRateKey();
            row.setAttribute('data-rate', rate);
        }
        if (rateInputs[rate]) {
            return rateInputs[rate];
        }
        updateDynamicCounter(rate);
        var info = {
            row: row,
            base: row.querySelector('.base-field'),
            iva: row.querySelector('.iva-field'),
            ivaAccount: row.querySelector('.iva-account-field'),
            generalAccount: row.querySelector('.general-account-field'),
            costCenter: row.querySelector('.cost-center-field') || null,
            labelInput: row.querySelector('.rate-label-field') || null,
            labelText: row.querySelector('.rate-label-static') || null,
            removeBtn: row.querySelector('.remove-rate-row') || null,
            restoreBaseBtn: row.querySelector('.restore-base-btn') || null,
            custom: row.getAttribute('data-custom-rate') === '1'
        };
        info.rate = rate;
        info.key = rate;
        rateInputs[rate] = info;
        if (Object.prototype.hasOwnProperty.call(removedRates, rate)) {
            delete removedRates[rate];
        }
        if (!Object.prototype.hasOwnProperty.call(currentCostCenters, rate)) {
            currentCostCenters[rate] = '';
        }
        if (info.costCenter) {
            info.costCenter.setAttribute('type', 'text');
            info.costCenter.removeAttribute('readonly');
            info.costCenter.removeAttribute('disabled');
            info.costCenter.readOnly = false;
            info.costCenter.disabled = false;
            info.costCenter.addEventListener('input', function() {
                currentCostCenters[rate] = info.costCenter.value;
            });
        }
        if (info.base) {
            info.base.removeAttribute('readonly');
            info.base.readOnly = false;
            info.base.addEventListener('input', function() {
                var rateData = ensureRateData(rate);
                rateData.base = info.base.value;
                rateData.base_value = info.base.value;
                recalculateVatForRate(rate);
                updateRowDirtyState(rate);
            });
            info.base.addEventListener('blur', function() {
                recalculateVatForRate(rate, { formatBase: true });
                updateRowDirtyState(rate);
            });
        }
        if (info.iva) {
            info.iva.readOnly = true;
        }
        if (info.labelInput) {
            info.labelInput.addEventListener('input', function() {
                var rateData = ensureRateData(rate);
                rateData.label = info.labelInput.value;
                recalculateVatForRate(rate);
            });
        }
        if (info.removeBtn) {
            info.removeBtn.addEventListener('click', function() {
                removeRateRow(rate);
            });
        }
        if (info.labelText && info.labelText.textContent.trim() === '') {
            info.labelText.textContent = getDefaultRateLabel(rate);
        }
        if (info.restoreBaseBtn) {
            info.restoreBaseBtn.addEventListener('click', function() {
                var original = originalRateValues[rate] || {};
                var originalBase = original.base !== undefined ? String(original.base) : '';
                var originalIva = original.iva !== undefined ? String(original.iva) : '';
                if (!Object.prototype.hasOwnProperty.call(originalRateValues, rate) && info.custom) {
                    removeRateRow(rate);
                    refreshAllDirtyStates();
                    return;
                }
                if (info.base && info.base.value !== originalBase) {
                    info.base.value = originalBase;
                }
                if (info.iva && info.iva.value !== originalIva) {
                    info.iva.value = originalIva;
                }
                var rateData = ensureRateData(rate);
                rateData.base = info.base ? info.base.value : '';
                rateData.base_value = rateData.base;
                rateData.iva = info.iva ? info.iva.value : '';
                rateData.iva_value = rateData.iva;
                updateRowDirtyState(rate);
                if (info.base) {
                    info.base.focus();
                    info.base.select();
                }
            });
        }
        updateRowDirtyState(rate);
        return info;
    }

    function removeRateRow(rate) {
        var info = rateInputs[rate];
        if (!info) {
            return;
        }
        if (info.row && info.row.parentNode) {
            info.row.parentNode.removeChild(info.row);
        }
        delete rateInputs[rate];
        delete currentCostCenters[rate];
        if (Object.prototype.hasOwnProperty.call(originalRateValues, rate)) {
            delete originalRateValues[rate];
        }
        if (Object.prototype.hasOwnProperty.call(serverOriginalRates, rate)) {
            delete serverOriginalRates[rate];
        }
        if (defaultRates.indexOf(rate) === -1 || !Object.prototype.hasOwnProperty.call(storedRowRates, rate)) {
            delete currentRateData[rate];
        }
        if (
            Object.prototype.hasOwnProperty.call(storedRowRates, rate) ||
            Object.prototype.hasOwnProperty.call(storedDefaultRates, rate)
        ) {
            removedRates[rate] = true;
        }
    }

    function createDefaultRateRow(rate, label) {
        if (!vatRateRowTemplate || !form) {
            return null;
        }
        var fragment = vatRateRowTemplate.content ? vatRateRowTemplate.content.cloneNode(true) : null;
        if (!fragment) {
            return null;
        }
        var row = fragment.querySelector('tr');
        if (!row) {
            return null;
        }
        row.setAttribute('data-rate', rate);
        row.setAttribute('data-custom-rate', '0');
        var labelEl = row.querySelector('.rate-label-static');
        if (labelEl) {
            labelEl.textContent = label || getDefaultRateLabel(rate);
        }
        var tbody = form.querySelector('tbody');
        if (!tbody) {
            return null;
        }
        tbody.appendChild(row);
        return registerRateRow(row, rate);
    }

    function createDynamicRateRow(rate, label) {
        if (!customRateRowTemplate || !form) {
            return null;
        }
        var fragment = customRateRowTemplate.content ? customRateRowTemplate.content.cloneNode(true) : null;
        if (!fragment) {
            return null;
        }
        var row = fragment.querySelector('tr');
        var key = rate && !rateInputs[rate] ? rate : null;
        if (!key) {
            key = generateCustomRateKey();
        }
        row.setAttribute('data-rate', key);
        row.setAttribute('data-custom-rate', '1');
        var shouldFocus = !rate || rate === '';
        var tbody = form.querySelector('tbody');
        if (!tbody) {
            return null;
        }
        tbody.appendChild(row);
        var info = registerRateRow(row, key);
        if (info && info.labelInput) {
            info.labelInput.value = label || '';
            info.labelInput.dispatchEvent(new Event('input'));
            if (shouldFocus) {
                info.labelInput.focus();
                info.labelInput.select();
            }
        }
        if (!currentRateData[key]) {
            currentRateData[key] = {};
        }
        return info;
    }

    function ensureRateRow(rate, data, options) {
        if (!rate) {
            return null;
        }
        var opts = options || {};
        var allowCreate = opts.allowCreate === true;
        var info = rateInputs[rate];
        if (info) {
            if (info.labelInput && data && typeof data.label === 'string') {
                info.labelInput.value = data.label;
            } else if (info.labelText && data && typeof data.label === 'string' && data.label !== '') {
                info.labelText.textContent = data.label;
            }
            return info;
        }
        if (!allowCreate) {
            return null;
        }
        var label = data && typeof data.label === 'string' ? data.label : '';
        if (Object.prototype.hasOwnProperty.call(defaultRateLabels, rate)) {
            if (!label) {
                label = getDefaultRateLabel(rate);
            }
            return createDefaultRateRow(rate, label);
        }
        return createDynamicRateRow(rate, label);
    }

    function ensureRowsForRates(value, options) {
        if (!value) {
            return;
        }
        var opts = options || {};
        if (Array.isArray(value)) {
            value.forEach(function(entry) {
                ensureRowsForRates(entry, opts);
            });
            return;
        }
        if (typeof value !== 'object') {
            return;
        }
        var source = value;
        if (source && typeof source.rates === 'object') {
            source = source.rates;
        }
        var metadataKeys = {
            version: true,
            rates: true,
            label: true,
            labels: true,
            title: true
        };
        Object.keys(source).forEach(function(key) {
            var normalizedKey = String(key).toLowerCase();
            if (Object.prototype.hasOwnProperty.call(metadataKeys, normalizedKey)) {
                return;
            }
            var rate = String(key);
            if (rateInputs[rate]) {
                return;
            }
            var entry = source[key];
            if (!hasMeaningfulRateEntry(entry)) {
                return;
            }
            ensureRateRow(rate, entry && typeof entry === 'object' ? entry : null, opts);
        });
    }

    function createEmptyCostCenters() {
        var result = {};
        defaultRates.forEach(function(rate) {
            result[rate] = '';
        });
        Object.keys(currentCostCenters).forEach(function(rate) {
            if (!Object.prototype.hasOwnProperty.call(result, rate)) {
                result[rate] = '';
            }
        });
        getRateKeys().forEach(function(rate) {
            result[rate] = '';
        });
        Object.keys(currentRateData).forEach(function(rate) {
            if (!Object.prototype.hasOwnProperty.call(result, rate)) {
                result[rate] = '';
            }
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
            Object.keys(source).forEach(function(rate) {
                if (!Object.prototype.hasOwnProperty.call(normalized, rate)) {
                    normalized[rate] = '';
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

    function applyCostCenterValues(value, options) {
        var opts = options || {};
        if (!opts.skipEnsure) {
            ensureRowsForRates(value, opts);
        }
        var normalized = normalizeCostCenterValues(value);
        currentCostCenters = {};
        Object.keys(normalized).forEach(function(rate) {
            currentCostCenters[rate] = normalized[rate];
        });
        getRateKeys().forEach(function(rate) {
            var info = rateInputs[rate];
            if (!info || !info.costCenter) {
                return;
            }
            var newValue = Object.prototype.hasOwnProperty.call(normalized, rate) ? normalized[rate] : '';
            if (info.costCenter.value !== newValue) {
                info.costCenter.value = newValue;
            }
        });
    }

    function getCostCenterValues() {
        var values = {};
        Object.keys(currentCostCenters).forEach(function(rate) {
            values[rate] = (currentCostCenters[rate] || '').trim();
        });
        getRateKeys().forEach(function(rate) {
            var info = rateInputs[rate];
            if (info && info.costCenter) {
                values[rate] = info.costCenter.value.trim();
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

    function hasAccountData(entry) {
        if (!entry || typeof entry !== 'object') {
            return false;
        }
        var iva = typeof entry.iva_account === 'string' ? entry.iva_account.trim() : '';
        var general = typeof entry.general_account === 'string' ? entry.general_account.trim() : '';
        return iva !== '' || general !== '';
    }

    function rateHasStoredAccounts(rate) {
        if (Object.prototype.hasOwnProperty.call(storedRowRates, rate) && hasAccountData(storedRowRates[rate])) {
            return true;
        }
        if (Object.prototype.hasOwnProperty.call(storedDefaultRates, rate) && hasAccountData(storedDefaultRates[rate])) {
            return true;
        }
        return false;
    }

    function rateHasBaseValues(rate) {
        if (!currentRateData[rate]) {
            return false;
        }
        var baseAmount = getEntryAmount(currentRateData[rate], 'base');
        var ivaAmount = getEntryAmount(currentRateData[rate], 'iva');
        var baseString = baseAmount !== null && baseAmount !== undefined ? String(baseAmount).trim() : '';
        var ivaString = ivaAmount !== null && ivaAmount !== undefined ? String(ivaAmount).trim() : '';
        return baseString !== '' || ivaString !== '';
    }

    function normalizeAmountValue(value) {
        if (value === null || value === undefined) {
            return null;
        }
        if (typeof value === 'number') {
            return isFinite(value) ? value : null;
        }
        if (typeof value === 'string') {
            var trimmed = value.trim();
            if (trimmed === '') {
                return null;
            }
            return trimmed;
        }
        return null;
    }

    function getEntryAmount(entry, field) {
        if (!entry || typeof entry !== 'object') {
            return null;
        }
        var direct = normalizeAmountValue(entry[field]);
        if (direct !== null && direct !== undefined) {
            return direct;
        }
        var altField = field === 'base' ? 'base_value' : 'iva_value';
        return normalizeAmountValue(entry[altField]);
    }

    function populateRateRow(rate) {
        var info = rateInputs[rate];
        if (!info) {
            return;
        }
        var baseData = ensureRateData(rate);
        var rowData = storedRowRates[rate] || {};
        var defaultData = storedDefaultRates[rate] || {};
        var resolvedBase = getEntryAmount(baseData, 'base');
        if (resolvedBase === null) {
            resolvedBase = getEntryAmount(rowData, 'base');
        }
        if (resolvedBase === null) {
            resolvedBase = getEntryAmount(defaultData, 'base');
        }
        if (info.base) {
            info.base.value = resolvedBase !== null ? String(resolvedBase) : '';
        }
        var resolvedIva = getEntryAmount(baseData, 'iva');
        if (resolvedIva === null) {
            resolvedIva = getEntryAmount(rowData, 'iva');
        }
        if (resolvedIva === null) {
            resolvedIva = getEntryAmount(defaultData, 'iva');
        }
        if (info.iva) {
            info.iva.value = resolvedIva !== null ? String(resolvedIva) : '';
        }
        var ivaAccount = rowData.iva_account || baseData.iva_account || defaultData.iva_account || '';
        var generalAccount = rowData.general_account || baseData.general_account || defaultData.general_account || '';
        if (info.ivaAccount && info.ivaAccount.value !== ivaAccount) {
            info.ivaAccount.value = ivaAccount;
        }
        if (info.generalAccount && info.generalAccount.value !== generalAccount) {
            info.generalAccount.value = generalAccount;
        }
        var label = '';
        if (typeof baseData.label === 'string' && baseData.label.trim() !== '') {
            label = baseData.label.trim();
        } else if (typeof rowData.label === 'string' && rowData.label.trim() !== '') {
            label = rowData.label.trim();
        } else if (typeof defaultData.label === 'string' && defaultData.label.trim() !== '') {
            label = defaultData.label.trim();
        } else {
            label = getDefaultRateLabel(rate);
        }
        if (info.labelInput && info.labelInput.value !== label) {
            info.labelInput.value = label;
        }
        if (info.labelText) {
            info.labelText.textContent = label;
        }
        currentRateData[rate].label = label;
        if (info.ivaAccount) {
            currentRateData[rate].iva_account = info.ivaAccount.value;
        }
        if (info.generalAccount) {
            currentRateData[rate].general_account = info.generalAccount.value;
        }
        if (info.base) {
            currentRateData[rate].base = info.base.value;
            currentRateData[rate].base_value = info.base.value;
        }
        if (info.iva) {
            currentRateData[rate].iva = info.iva.value;
            currentRateData[rate].iva_value = info.iva.value;
        }
        if (info.costCenter) {
            var storedValue = Object.prototype.hasOwnProperty.call(currentCostCenters, rate) ? currentCostCenters[rate] : '';
            if (info.costCenter.value !== storedValue) {
                info.costCenter.value = storedValue;
            }
        }
        if (info.base && info.iva) {
            var baseValue = info.base.value !== undefined ? String(info.base.value).trim() : '';
            if (baseValue === '') {
                if (info.iva.value !== '') {
                    info.iva.value = '';
                }
                currentRateData[rate].iva = '';
                currentRateData[rate].iva_value = '';

            } else {
                var baseNumber = parseDecimalValue(info.base.value);
                var percentage = getRatePercentage(rate);
                if (baseNumber !== null && percentage !== null) {
                    var expectedIva = formatDecimalValue(baseNumber * (percentage / 100));
                    if (info.iva.value !== expectedIva) {
                        info.iva.value = expectedIva;
                    }
                    currentRateData[rate].iva = expectedIva;
                    currentRateData[rate].iva_value = expectedIva;
                }
            }
        }
        updateRowDirtyState(rate);
    }

    function focusRateInput(info) {
        if (!info) {
            return;
        }
        var focusTarget = null;
        if (info.custom) {
            focusTarget = info.labelInput || info.ivaAccount || info.generalAccount || info.costCenter;
        } else {
            focusTarget = info.ivaAccount || info.generalAccount || info.costCenter || info.labelInput;
        }
        if (focusTarget && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
            if (typeof focusTarget.select === 'function') {
                focusTarget.select();
            }
        }
    }

    function addVatRowForRate(rate) {
        var data = currentRateData[rate] || storedRowRates[rate] || storedDefaultRates[rate] || {};
        var info = ensureRateRow(rate, data, { allowCreate: true });
        if (info) {
            populateRateRow(rate);
        }
        return info;
    }

    function findNextDefaultRate() {
        var missing = defaultRates.filter(function(rate) { return !rateInputs[rate]; });
        if (missing.length === 0) {
            return null;
        }
        var prioritized = missing.filter(function(rate) {
            return rateHasStoredAccounts(rate) || rateHasBaseValues(rate);
        });
        if (prioritized.length > 0) {
            return prioritized[0];
        }
        return missing[0];
    }

    function resetRateRows() {
        Object.keys(rateInputs).forEach(function(rate) {
            var info = rateInputs[rate];
            if (info && info.row && info.row.parentNode) {
                info.row.parentNode.removeChild(info.row);
            }
        });
        rateInputs = {};
        dynamicRateCounter = 0;
        removedRates = {};
        originalRateValues = {};
        serverOriginalRates = {};
    }

    function restoreSavedRates() {
        var created = [];
        Object.keys(storedRowRates).forEach(function(rate) {
            if (!rateHasStoredAccounts(rate) && !rateHasBaseValues(rate)) {
                return;
            }
            var info = addVatRowForRate(rate);
            if (info) {
                created.push(rate);
            }
        });
        Object.keys(storedDefaultRates).forEach(function(rate) {
            if (rateInputs[rate] || (!rateHasStoredAccounts(rate) && !rateHasBaseValues(rate))) {
                return;
            }
            var info = addVatRowForRate(rate);
            if (info) {
                created.push(rate);
            }
        });
        return created;
    }

    if (form) {
        currentCostCenters = {};
    }

    if (addVatLineBtn) {
        addVatLineBtn.addEventListener('click', function() {
            var nextRate = findNextDefaultRate();
            if (nextRate) {
                var info = addVatRowForRate(nextRate);
                focusRateInput(info);
                return;
            }
            var customInfo = createDynamicRateRow('', '');
            if (customInfo) {
                populateRateRow(customInfo.key);
                focusRateInput(customInfo);
            }
        });
    }

    if (classifyModalEl) {
        classifyModalEl.addEventListener('shown.bs.modal', function() {
            var keys = getRateKeys();
            if (keys.length > 0) {
                focusRateInput(rateInputs[keys[0]]);
            }
        });
        classifyModalEl.addEventListener('hidden.bs.modal', function() {
            if (modalTitleEl) {
                modalTitleEl.textContent = defaultModalTitle || 'Classificar';
            }
            table.ajax.reload(null, false);
            currentBtn = null;
            currentOriginalRatesKey = null;
        });
    }

    var currentBtn = null;
    var linesModalEl = document.getElementById('linesModal');
    var linesModal = linesModalEl ? new bootstrap.Modal(linesModalEl) : null;
    var linesContainer = document.getElementById('linesContainer');
    var confirmLinesBtn = document.getElementById('confirmLinesBtn');
    var currentLinesId = null;

    if (linesModalEl) {
        linesModalEl.addEventListener('hidden.bs.modal', function() {
            table.ajax.reload(null, false);
        });
    }

    $('#classify-table').on('click', '.classify-row', function() {
        var btn = this;
        currentBtn = btn;
        currentOriginalRatesKey = buildOriginalRatesStorageKey(btn);
        var emitter = btn.getAttribute('data-emitter') || '';
        var emitterNif = btn.getAttribute('data-emitter-nif') || '';
        var acquirer = btn.getAttribute('data-acquirer') || '';
        var docType = btn.getAttribute('data-doctype') || '';
        var docNumber = btn.getAttribute('data-doc-number') || '';

        if (modalTitleEl) {
            var baseTitle = defaultModalTitle || 'Classificar';
            var docPart = docNumber.trim();
            var emitterPart = emitter.trim();
            var emitterNifPart = emitterNif.trim();
            if (!emitterPart && emitterNifPart) {
                emitterPart = emitterNifPart;
            }
            var titleParts = [baseTitle];
            if (docPart) {
                titleParts.push('Doc. ' + docPart);
            }
            if (emitterPart) {
                titleParts.push(emitterPart);
            }
            modalTitleEl.textContent = titleParts.join(' - ');
        }

        resetRateRows();
        storedRowRates = {};
        storedDefaultRates = {};
        currentCostCenters = {};
        removedRates = {};

        currentRateData = parseJsonAttribute(btn, 'data-rates') || {};
        if (!currentRateData || typeof currentRateData !== 'object') {
            currentRateData = {};
        }
        Object.keys(currentRateData).forEach(function(rate) {
            if (!currentRateData[rate] || typeof currentRateData[rate] !== 'object') {
                currentRateData[rate] = {};
            }
            var entry = currentRateData[rate];
            if (entry.base_value === undefined && entry.base !== undefined) {
                entry.base_value = entry.base;
            }
            if (entry.iva_value === undefined && entry.iva !== undefined) {
                entry.iva_value = entry.iva;
            }
        });
        debugJson('dados iniciais do botão', currentRateData);
        defaultRates.forEach(function(rate) {
            if (!currentRateData[rate]) {
                currentRateData[rate] = {};
            }
            if (typeof currentRateData[rate].label !== 'string' || currentRateData[rate].label.trim() === '') {
                currentRateData[rate].label = getDefaultRateLabel(rate);
            }
        });

        defaultRates.forEach(function(rate) {
            if (!rateInputs[rate]) {
                addVatRowForRate(rate);
            }
        });
        ensureRowsForRates(currentRateData, { allowCreate: true });
        getRateKeys().forEach(function(rate) {
            populateRateRow(rate);
        });
        captureOriginalRateValues({ initialize: true, refresh: false, allowCreate: false });

        var btnCostCenters = parseJsonAttribute(btn, 'data-cost-centers');
        if (!btnCostCenters && btn.hasAttribute('data-cost-center')) {
            btnCostCenters = btn.getAttribute('data-cost-center') || '';
        }
        applyCostCenterValues(btnCostCenters, { skipEnsure: true });

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

                debugJson('resposta save-analysis', res);

                storedRowRates = (res.row_rates && typeof res.row_rates === 'object') ? res.row_rates : {};
                storedDefaultRates = (res.rates && typeof res.rates === 'object') ? res.rates : {};
                removedRates = {};
                serverOriginalRates = normalizeServerOriginalRates(res.original_rates);

                Object.keys(storedRowRates).forEach(function(rate) {
                    if (!currentRateData[rate]) {
                        currentRateData[rate] = {};
                    }
                    var rowData = storedRowRates[rate];
                    if (rowData && typeof rowData === 'object') {
                        if (rowData.iva_account) {
                            currentRateData[rate].iva_account = rowData.iva_account;
                        }
                        if (rowData.general_account) {
                            currentRateData[rate].general_account = rowData.general_account;
                        }
                        if (rowData.label) {
                            currentRateData[rate].label = rowData.label;
                        }
                        var rowBase = getEntryAmount(rowData, 'base');
                        if (rowBase !== null && rowBase !== undefined) {
                            currentRateData[rate].base = String(rowBase);
                            currentRateData[rate].base_value = String(rowBase);
                        }
                        var rowIva = getEntryAmount(rowData, 'iva');
                        if (rowIva !== null && rowIva !== undefined) {
                            currentRateData[rate].iva = String(rowIva);
                            currentRateData[rate].iva_value = String(rowIva);
                        }
                    }
                });

                Object.keys(storedDefaultRates).forEach(function(rate) {
                    if (!currentRateData[rate]) {
                        currentRateData[rate] = {};
                    }
                    var defaultData = storedDefaultRates[rate];
                    if (defaultData && typeof defaultData === 'object') {
                        if (defaultData.iva_account) {
                            currentRateData[rate].iva_account = defaultData.iva_account;
                        }
                        if (defaultData.general_account) {
                            currentRateData[rate].general_account = defaultData.general_account;
                        }
                        if (defaultData.label) {
                            currentRateData[rate].label = defaultData.label;
                        }
                    }
                });

                var serverCostCenters = null;
                if (Object.prototype.hasOwnProperty.call(res, 'cost_centers')) {
                    serverCostCenters = res.cost_centers;
                } else if (Object.prototype.hasOwnProperty.call(res, 'cost_center')) {
                    serverCostCenters = res.cost_center;
                }
                if (serverCostCenters !== null && serverCostCenters !== undefined) {
                    if (!hasAnyCostCenterValue()) {
                        applyCostCenterValues(serverCostCenters, { skipEnsure: true });
                    }
                }

                debugJson('dados de taxas após merge', currentRateData);

                var restored = restoreSavedRates();
                getRateKeys().forEach(function(rate) {
                    populateRateRow(rate);
                });
                captureOriginalRateValues({ initialize: true });
                currentCostCenters = getCostCenterValues();

                if (restored.length > 0) {
                    focusRateInput(rateInputs[restored[0]]);
                }

                classifyModal.show();

            })
            .catch(function(err) {
                showError(err.message || 'Erro ao carregar');
            });
    });

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!currentBtn) {
                return;
            }

            getRateKeys().forEach(function(rate) {
                recalculateVatForRate(rate, { formatBase: true });
                updateRowDirtyState(rate);
            });

            var ratesPayload = {};
            getRateKeys().forEach(function(rate) {
                var info = rateInputs[rate];
                var baseValue = info.base ? String(info.base.value || '').trim() : '';
                var ivaValue = info.iva ? String(info.iva.value || '').trim() : '';
                ratesPayload[rate] = {
                    iva_account: info.ivaAccount ? info.ivaAccount.value.trim() : '',
                    general_account: info.generalAccount ? info.generalAccount.value.trim() : '',
                    label: getRateLabel(rate),
                    base: baseValue,
                    iva: ivaValue,
                    base_value: baseValue,
                    iva_value: ivaValue
                };
            });

            var removedPayload = Object.keys(removedRates).filter(function(rate) {
                return removedRates[rate];
            });

            var costCentersPayload = getCostCenterValues();
            var body = new URLSearchParams({
                id: currentBtn.getAttribute('data-id') || '',
                A: currentBtn.getAttribute('data-emitter') || '',
                B: currentBtn.getAttribute('data-acquirer') || '',
                D: currentBtn.getAttribute('data-doctype') || '',
                rates: JSON.stringify(ratesPayload),
                removed_rates: JSON.stringify(removedPayload),
                original_rates: JSON.stringify(originalRateValues),
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
                applyCostCenterValues(responseCostCenters, { skipEnsure: true });
                currentCostCenters = getCostCenterValues();

                if (res.row_rates && typeof res.row_rates === 'object') {
                    storedRowRates = res.row_rates;
                    Object.keys(res.row_rates).forEach(function(rate) {
                        if (!currentRateData[rate]) {
                            currentRateData[rate] = {};
                        }
                        var rowData = res.row_rates[rate] || {};
                        currentRateData[rate].iva_account = rowData.iva_account || '';
                        currentRateData[rate].general_account = rowData.general_account || '';
                        if (rowData.label) {
                            currentRateData[rate].label = rowData.label;
                        }
                        var savedBase = getEntryAmount(rowData, 'base');
                        if (savedBase !== null && savedBase !== undefined) {
                            currentRateData[rate].base = String(savedBase);
                            currentRateData[rate].base_value = String(savedBase);
                        }
                        var savedIva = getEntryAmount(rowData, 'iva');
                        if (savedIva !== null && savedIva !== undefined) {
                            currentRateData[rate].iva = String(savedIva);
                            currentRateData[rate].iva_value = String(savedIva);
                        }
                        if (rateInputs[rate]) {
                            populateRateRow(rate);
                        }
                    });
                } else {
                    getRateKeys().forEach(function(rate) {
                        var info = rateInputs[rate];
                        if (!info) {
                            return;
                        }
                        if (!currentRateData[rate]) {
                            currentRateData[rate] = {};
                        }
                        currentRateData[rate].base = info.base ? info.base.value : '';
                        currentRateData[rate].base_value = currentRateData[rate].base;
                        currentRateData[rate].iva = info.iva ? info.iva.value : '';
                        currentRateData[rate].iva_value = currentRateData[rate].iva;
                        currentRateData[rate].iva_account = ratesPayload[rate] ? ratesPayload[rate].iva_account : '';
                        currentRateData[rate].general_account = ratesPayload[rate] ? ratesPayload[rate].general_account : '';
                        currentRateData[rate].label = getRateLabel(rate);
                    });
                }

                refreshAllDirtyStates();

                removedPayload.forEach(function(rate) {
                    delete currentRateData[rate];
                });

                Object.keys(currentRateData).forEach(function(rate) {
                    if (!rateInputs[rate] && defaultRates.indexOf(rate) === -1 && !Object.prototype.hasOwnProperty.call(storedRowRates, rate)) {
                        delete currentRateData[rate];
                    }
                });

                currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
                currentBtn.setAttribute('data-cost-centers', JSON.stringify(currentCostCenters));
                updateButtonClass(currentBtn);
                removedRates = {};
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
    }

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

