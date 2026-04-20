Dropzone.autoDiscover = false;

window.addEventListener('load', function() {
    var form = document.getElementById('multi-upload');
    if (!form) {
        return;
    }

    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var importBtn = document.getElementById('import-btn');
    var importComprasBtn = document.getElementById('import-compras-btn');
    var previewUrl = window.accountingUploadPreviewUrl || 'contabilidade/upload.php?action=preview-page';
    var manualQrUrl = window.accountingUploadManualQrUrl || 'contabilidade/upload.php?action=manual-qr';
    var efaturaSearchUrl = window.accountingUploadEfaturaSearchUrl || 'contabilidade/upload.php?action=efatura-search';
    var ocrAcquirerUrl = window.accountingUploadOcrAcquirerUrl || 'contabilidade/upload.php?action=suggest-acquirer-ocr';
    var ocrEmitterUrl = window.accountingUploadOcrEmitterUrl || 'contabilidade/upload.php?action=suggest-emitter-ocr';
    var deleteUrl = window.accountingUploadDeleteUrl || 'contabilidade/upload.php?action=delete';
    var acquirerCompanies = Array.isArray(window.accountingUploadAcquirerCompanies) ? window.accountingUploadAcquirerCompanies : [];
    var selectedAcquirerId = parseInt(window.accountingUploadSelectedAcquirerId, 10) || 0;
    var parallelUploads = parseInt(window.accountingUploadParallelUploads, 10) || 2;
    var debugEnabled = window.accountingUploadDebug === true;
    var navigationGuardEnabled = true;
    var suppressNextPopstateGuard = false;

    var acquirerDatabaseResolved = {};
    var acquirerDatabasePending = {};
    var acquirerQueue = [];
    var acquirerModalActive = false;
    var acquirerCurrentItem = null;
    var acquirerModalEl = document.getElementById('uploadAcquirerDatabaseModal');
    var acquirerForm = document.getElementById('uploadAcquirerDatabaseForm');
    var acquirerInput = document.getElementById('uploadAcquirerDatabaseInput');
    var acquirerMessage = document.getElementById('uploadAcquirerDatabaseMessage');
    var acquirerError = document.getElementById('uploadAcquirerDatabaseError');
    var acquirerConfirmBtn = document.getElementById('uploadAcquirerDatabaseConfirmBtn');
    var acquirerModal = (acquirerModalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function')
        ? new window.bootstrap.Modal(acquirerModalEl)
        : null;

    var manualModalEl = document.getElementById('manualQrModal');
    var manualModal = (manualModalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function')
        ? new window.bootstrap.Modal(manualModalEl, { backdrop: 'static', keyboard: false })
        : null;
    var manualPreviewImage = document.getElementById('manualQrPreviewImage');
    var manualCanvasWrap = document.getElementById('manualQrCanvasWrap');
    var manualSelectionBox = document.getElementById('manualQrSelectionBox');
    var manualDecodeBtn = document.getElementById('manualQrDecodeBtn');
    var manualClearBtn = document.getElementById('manualQrClearBtn');
    var manualDiscardBtn = document.getElementById('manualQrDiscardBtn');
    var manualImportAsIsBtn = document.getElementById('manualQrImportAsIsBtn');
    var manualPrevPageBtn = document.getElementById('manualQrPrevPageBtn');
    var manualNextPageBtn = document.getElementById('manualQrNextPageBtn');
    var manualZoom100Btn = document.getElementById('manualQrZoom100Btn');
    var manualZoom150Btn = document.getElementById('manualQrZoom150Btn');
    var manualZoom200Btn = document.getElementById('manualQrZoom200Btn');
    var manualPageLabel = document.getElementById('manualQrPageLabel');
    var manualQueueLabel = document.getElementById('manualQrQueueLabel');
    var manualFileName = document.getElementById('manualQrFileName');
    var manualError = document.getElementById('manualQrError');
    var manualLoading = document.getElementById('manualQrLoading');
    var manualOpenFileBtn = document.getElementById('manualQrOpenFileBtn');
    var manualAcquirerSelect = document.getElementById('manualQrAcquirerSelect');
    var manualEfaturaSelect = document.getElementById('manualQrEfaturaSelect');
    var manualEfaturaApplyBtn = document.getElementById('manualQrApplyEfaturaBtn');
    var manualEfaturaInfo = document.getElementById('manualQrEfaturaInfo');
    var manualEfaturaError = document.getElementById('manualQrEfaturaError');
    var manualEmitterModalEl = document.getElementById('manualQrEmitterModal');
    var manualEmitterModal = (manualEmitterModalEl && window.bootstrap && typeof window.bootstrap.Modal === 'function')
        ? new window.bootstrap.Modal(manualEmitterModalEl, { backdrop: 'static', keyboard: false })
        : null;
    var manualEmitterForm = document.getElementById('manualQrEmitterForm');
    var manualEmitterInput = document.getElementById('manualQrEmitterNifInput');
    var manualEmitterHint = document.getElementById('manualQrEmitterHint');
    var manualEmitterError = document.getElementById('manualQrEmitterError');
    var manualEmitterConfirmBtn = document.getElementById('manualQrEmitterConfirmBtn');
    var manualSelectedEfaturaDocument = null;
    var manualSelectedAcquirer = null;
    var manualImportAsIsPending = false;
    var manualAcquirerOcrRequest = 0;
    var manualCloseMode = 'finish';
    var manualOcrCandidateNifs = [];
    var qrKeys = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I1', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'N', 'O', 'Q', 'R'];

    var manualQueue = [];
    var manualActive = null;
    var manualSequence = 0;
    var manualTotal = 0;
    var uploadProcessingCount = 0;
    var manualPage = 1;
    var manualPageCount = 1;
    var manualSelection = null;
    var manualPointer = null;
    var manualZoom = 1;
    var debugStats = {
        batchStartedAt: 0,
        files: {},
        order: [],
        automaticSuccess: 0,
        manualQueued: 0,
        manualSuccess: 0,
        manualDiscarded: 0,
        hardFailures: 0
    };

    function ensureDebugFile(file) {
        var key = file && (file.upload && file.upload.uuid ? file.upload.uuid : file.name) ? (file.upload && file.upload.uuid ? file.upload.uuid : file.name) : String(Date.now());
        if (!debugStats.files[key]) {
            debugStats.files[key] = {
                key: key,
                name: file && file.name ? file.name : key,
                queuedAt: 0,
                startedAt: 0,
                finishedAt: 0,
                ms: 0,
                queueMs: 0,
                backendMs: 0,
                frontendMs: 0,
                state: 'pending',
                attempts: []
            };
            debugStats.order.push(key);
        }
        return debugStats.files[key];
    }

    function printDebugSummary() {
        if (!debugEnabled || !debugStats.order.length) {
            return;
        }
        var rows = debugStats.order.map(function(key) {
            var item = debugStats.files[key];
            return {
                ficheiro: item.name,
                ms: item.ms,
                fila_ms: item.queueMs || 0,
                backend_ms: item.backendMs || 0,
                frontend_ms: item.frontendMs || 0,
                segundos: Number((item.ms / 1000).toFixed(2)),
                estado: item.state,
                dpis: item.attempts.join(', ')
            };
        });
        var totalMs = rows.reduce(function(sum, row) { return sum + (row.ms || 0); }, 0);
        var totalQueueMs = rows.reduce(function(sum, row) { return sum + (row.fila_ms || 0); }, 0);
        var totalBackendMs = rows.reduce(function(sum, row) { return sum + (row.backend_ms || 0); }, 0);
        var totalFrontendMs = rows.reduce(function(sum, row) { return sum + (row.frontend_ms || 0); }, 0);
        var avgMs = rows.length ? Math.round(totalMs / rows.length) : 0;
        var avgQueueMs = rows.length ? Math.round(totalQueueMs / rows.length) : 0;
        var avgBackendMs = rows.length ? Math.round(totalBackendMs / rows.length) : 0;
        var avgFrontendMs = rows.length ? Math.round(totalFrontendMs / rows.length) : 0;
        console.groupCollapsed('[Upload QR] Estatisticas');
        console.table(rows);
        console.log({
            total_ficheiros: rows.length,
            sucesso_automatico: debugStats.automaticSuccess,
            fila_manual: debugStats.manualQueued,
            sucesso_manual: debugStats.manualSuccess,
            ignorados_manualmente: debugStats.manualDiscarded,
            falhas_duras: debugStats.hardFailures,
            tempo_total_ms: totalMs,
            tempo_medio_ms: avgMs,
            fila_total_ms: totalQueueMs,
            fila_media_ms: avgQueueMs,
            backend_total_ms: totalBackendMs,
            backend_medio_ms: avgBackendMs,
            frontend_total_ms: totalFrontendMs,
            frontend_medio_ms: avgFrontendMs
        });
        console.groupEnd();
    }

    function showImportButtons() {
        if (importBtn) {
            importBtn.style.display = 'inline-block';
        }
        if (importComprasBtn) {
            importComprasBtn.style.display = 'inline-block';
        }
    }

    function hideImportButtons() {
        if (importBtn) {
            importBtn.style.display = 'none';
        }
        if (importComprasBtn) {
            importComprasBtn.style.display = 'none';
        }
    }

    function buildActionsHtml(filePath, imported, hasReceiptCompanion) {
        var safeFile = String(filePath || '');
        var receiptFlag = hasReceiptCompanion ? '1' : '0';
        var pdfBtnClass = imported ? 'btn-success' : 'btn-secondary';
        var pdfBtn = '<a href="' + safeFile + '" target="_blank" class="btn btn-xs ' + pdfBtnClass + ' open-file" data-file="' + safeFile + '" data-has-receipt-companion="' + receiptFlag + '"><i class="fa fa-file-pdf-o"></i></a>';
        if (imported) {
            return pdfBtn;
        }
        return '<button type="button" class="btn btn-xs btn-danger delete-row" data-file="' + safeFile + '" data-has-receipt-companion="' + receiptFlag + '"><i class="fa fa-trash"></i></button> ' + pdfBtn;
    }

    function getRowFilePath(node) {
        var filePath = $(node).find('.delete-row').data('file');
        if (!filePath) {
            filePath = $(node).find('.open-file').data('file');
        }
        return filePath || '';
    }

    function getPendingRowNodes() {
        return table.rows().nodes().toArray().filter(function(node) {
            return $(node).find('.delete-row').length > 0;
        });
    }

    function removePendingRowsForFile(filePath, fallbackRow) {
        var targetFile = String(filePath || '').trim();
        var matchingNodes = targetFile ? getPendingRowNodes().filter(function(node) {
            return getRowFilePath(node) === targetFile;
        }) : [];

        if (matchingNodes.length) {
            table.rows(matchingNodes).remove().draw(false);
            return;
        }

        if (fallbackRow) {
            fallbackRow.remove().draw(false);
        }
    }

    function getRowHasReceiptCompanion(node) {
        var source = $(node).find('.delete-row');
        if (!source.length) {
            source = $(node).find('.open-file');
        }
        var value = source.attr('data-has-receipt-companion');
        return String(value || '').trim() === '1';
    }

    function hasPendingRows() {
        return getPendingRowNodes().length > 0;
    }

    function hasPendingUploadWork() {
        return uploadProcessingCount > 0 || !!manualActive || manualQueue.length > 0 || hasPendingRows();
    }

    function getPendingNavigationMessage() {
        if (!hasPendingUploadWork()) {
            return '';
        }
        return 'Existem ficheiros ainda não importados. Pretende mesmo sair desta página e perder os ficheiros pendentes?';
    }

    function refreshUploadActionState() {
        if (hasPendingRows()) {
            showImportButtons();
        } else {
            hideImportButtons();
        }
    }

    function markCurrentRowsAsImported() {
        table.rows().every(function() {
            var data = this.data() || [];
            if (!data.length) {
                return;
            }
            var filePath = getRowFilePath(this.node());
            data[data.length - 1] = buildActionsHtml(filePath, true, getRowHasReceiptCompanion(this.node()));
            this.data(data);
        });
        table.draw(false);
        pruneReceiptRowsAcrossTable();
        refreshUploadActionState();
    }

    function notifySuccess(message) {
        var text = message || 'Operação concluída';
        if (window.PNotify && typeof window.PNotify.alert === 'function') {
            window.PNotify.alert({ text: text, type: 'success', styling: 'bootstrap3' });
            return;
        }
        if (window.PNotify && typeof window.PNotify === 'function') {
            window.PNotify({ text: text, type: 'success', styling: 'bootstrap3' });
            return;
        }
        console.info(text);
    }

    function showAlert(message) {
        alert(message);
    }

    function setManualError(message) {
        if (!manualError) {
            if (message) {
                showAlert(message);
            }
            return;
        }
        manualError.textContent = message || '';
        manualError.classList.toggle('d-none', !message);
    }

    function setManualEfaturaError(message) {
        if (!manualEfaturaError) {
            if (message) {
                showAlert(message);
            }
            return;
        }
        manualEfaturaError.textContent = message || '';
        manualEfaturaError.classList.toggle('d-none', !message);
    }

    function setManualEmitterError(message) {
        if (!manualEmitterError) {
            if (message) {
                showAlert(message);
            }
            return;
        }
        manualEmitterError.textContent = message || '';
        manualEmitterError.classList.toggle('d-none', !message);
    }

    function setManualEmitterHint(message) {
        if (!manualEmitterHint) {
            return;
        }
        manualEmitterHint.textContent = message || '';
        manualEmitterHint.classList.toggle('d-none', !message);
    }

    function setManualEmitterBusy(busy) {
        if (manualEmitterInput) {
            manualEmitterInput.disabled = !!busy;
        }
        if (manualEmitterConfirmBtn) {
            manualEmitterConfirmBtn.disabled = !!busy;
        }
    }

    function renderManualEfaturaInfo(doc) {
        if (!manualEfaturaInfo) {
            return;
        }
        if (!doc) {
            manualEfaturaInfo.textContent = '';
            manualEfaturaInfo.classList.add('d-none');
            return;
        }
        var parts = [];
        if (doc.issuer_vat || doc.issuer_name) {
            parts.push((doc.issuer_vat || '') + (doc.issuer_name ? ' - ' + doc.issuer_name : ''));
        }
        if (doc.invoice_no) {
            parts.push('Doc ' + doc.invoice_no);
        }
        if (doc.invoice_date) {
            parts.push('Data ' + doc.invoice_date);
        }
        if (doc.gross_total) {
            parts.push('Total ' + doc.gross_total);
        }
        manualEfaturaInfo.textContent = parts.join(' | ');
        manualEfaturaInfo.classList.toggle('d-none', parts.length === 0);
    }

    function resetManualEfaturaSelection() {
        manualSelectedEfaturaDocument = null;
        setManualEfaturaError('');
        renderManualEfaturaInfo(null);
        if (window.jQuery && manualEfaturaSelect) {
            jQuery(manualEfaturaSelect).val(null).trigger('change');
        } else if (manualEfaturaSelect) {
            manualEfaturaSelect.value = '';
        }
    }

    function findAcquirerCompanyById(id) {
        var numericId = parseInt(id, 10) || 0;
        if (!numericId) {
            return null;
        }
        for (var i = 0; i < acquirerCompanies.length; i += 1) {
            if ((parseInt(acquirerCompanies[i].id, 10) || 0) === numericId) {
                return acquirerCompanies[i];
            }
        }
        return null;
    }

    function findAcquirerCompanyByNif(nif) {
        var normalized = String(nif || '').replace(/\D+/g, '');
        if (!normalized) {
            return null;
        }
        for (var i = 0; i < acquirerCompanies.length; i += 1) {
            if (String(acquirerCompanies[i].nif || '').replace(/\D+/g, '') === normalized) {
                return acquirerCompanies[i];
            }
        }
        return null;
    }

    function resolveTopbarAcquirerSelection() {
        var topbarSelect = document.getElementById('efatura-top-empresa');
        if (!topbarSelect) {
            return null;
        }

        var selectedOption = topbarSelect.options && topbarSelect.selectedIndex >= 0
            ? topbarSelect.options[topbarSelect.selectedIndex]
            : null;
        var topbarValue = topbarSelect.value || '';
        var byId = findAcquirerCompanyById(topbarValue);
        if (byId) {
            return byId;
        }

        var optionNif = selectedOption && selectedOption.getAttribute ? selectedOption.getAttribute('data-nif') : '';
        var byOptionNif = findAcquirerCompanyByNif(optionNif || '');
        if (byOptionNif) {
            return byOptionNif;
        }

        var optionText = selectedOption && selectedOption.text ? selectedOption.text : '';
        var nifMatch = optionText.match(/(\d{9})/);
        if (nifMatch && nifMatch[1]) {
            return findAcquirerCompanyByNif(nifMatch[1]);
        }

        return null;
    }

    function syncManualAcquirerFromSelect() {
        if (!manualAcquirerSelect) {
            manualSelectedAcquirer = null;
            return;
        }
        manualSelectedAcquirer = findAcquirerCompanyById(manualAcquirerSelect.value);
    }

    function applyManualAcquirerSelection(company, triggerChange) {
        manualSelectedAcquirer = company || null;
        selectedAcquirerId = company && company.id ? (parseInt(company.id, 10) || 0) : 0;
        if (!manualAcquirerSelect) {
            return;
        }

        var nextValue = company && company.id ? String(company.id) : '';
        if (window.jQuery && jQuery.fn.select2) {
            jQuery(manualAcquirerSelect).val(nextValue).trigger(triggerChange ? 'change' : 'change.select2');
        } else {
            manualAcquirerSelect.value = nextValue;
            if (triggerChange) {
                var event = document.createEvent('HTMLEvents');
                event.initEvent('change', true, false);
                manualAcquirerSelect.dispatchEvent(event);
            } else {
                syncManualAcquirerFromSelect();
            }
        }
    }

    function getDefaultManualAcquirerCompany() {
        var topbarCompany = resolveTopbarAcquirerSelection();
        if (topbarCompany) {
            return topbarCompany;
        }
        if (selectedAcquirerId > 0) {
            return findAcquirerCompanyById(selectedAcquirerId);
        }
        return null;
    }

    function updateManualEfaturaSelectAvailability() {
        var hasCompany = !!(manualSelectedAcquirer && manualSelectedAcquirer.id);
        if (manualEfaturaSelect) {
            manualEfaturaSelect.disabled = !hasCompany;
        }
        if (manualEfaturaApplyBtn) {
            manualEfaturaApplyBtn.disabled = !hasCompany;
        }
        if (hasCompany && !manualSelectedEfaturaDocument) {
            setManualEfaturaError('');
        }
    }

    function runManualAcquirerOcrSuggestion() {
        if (!manualActive || !manualActive.file || !ocrAcquirerUrl) {
            applyManualAcquirerSelection(null, false);
            updateManualEfaturaSelectAvailability();
            return;
        }

        var requestId = manualAcquirerOcrRequest + 1;
        manualAcquirerOcrRequest = requestId;

        fetch(ocrAcquirerUrl + '&file=' + encodeURIComponent(manualActive.file), {
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (requestId !== manualAcquirerOcrRequest || !manualActive) {
                return;
            }
            manualOcrCandidateNifs = Array.isArray(res && res.candidate_nifs) ? res.candidate_nifs : [];
            if (!res || !res.success || !res.company || !res.company.id) {
                applyManualAcquirerSelection(null, false);
                updateManualEfaturaSelectAvailability();
                return;
            }

            var currentId = manualSelectedAcquirer && manualSelectedAcquirer.id
                ? (parseInt(manualSelectedAcquirer.id, 10) || 0)
                : 0;
            var nextCompany = findAcquirerCompanyById(res.company.id) || res.company;
            if (!nextCompany || !nextCompany.id) {
                return;
            }
            if (currentId === (parseInt(nextCompany.id, 10) || 0)) {
                return;
            }

            applyManualAcquirerSelection(nextCompany, true);
        })
        .catch(function() {
            manualOcrCandidateNifs = [];
            applyManualAcquirerSelection(null, false);
            updateManualEfaturaSelectAvailability();
            // OCR suggestion is best-effort only.
        });
    }

    function openManualEmitterPrompt(prefillNif) {
        if (!manualEmitterInput) {
            return;
        }
        setManualEmitterError('');
        setManualEmitterHint(manualOcrCandidateNifs.length ? ('Sugestões OCR: ' + manualOcrCandidateNifs.join(', ')) : '');
        manualEmitterInput.value = prefillNif || '';
        if (manualEmitterModal) {
            manualEmitterModal.show();
        }
        window.setTimeout(function() {
            manualEmitterInput.focus();
            manualEmitterInput.select();
        }, 150);
    }

    function setManualLoading(loading) {
        if (manualLoading) {
            manualLoading.classList.toggle('d-none', !loading);
        }
        if (manualDecodeBtn) {
            manualDecodeBtn.disabled = loading;
        }
    }

    function updateCsrfToken(res) {
        if (res && res.csrf_token && csrfInput) {
            csrfInput.value = res.csrf_token;
        }
    }

    function setManualImportAsIsBusy(busy) {
        manualImportAsIsPending = !!busy;
        if (manualImportAsIsBtn) {
            manualImportAsIsBtn.disabled = manualImportAsIsPending;
        }
        if (manualDiscardBtn) {
            manualDiscardBtn.disabled = manualImportAsIsPending;
        }
    }

    function hideAcquirerError() {
        if (acquirerError) {
            acquirerError.classList.add('d-none');
            acquirerError.textContent = '';
        }
    }

    function showAcquirerError(text) {
        if (acquirerError) {
            acquirerError.textContent = text || 'Erro ao guardar a base de dados do adquirente.';
            acquirerError.classList.remove('d-none');
            return;
        }
        showAlert(text || 'Erro ao guardar a base de dados do adquirente.');
    }

    function finishAcquirerStep(nif, resolved) {
        if (nif) {
            acquirerDatabasePending[nif] = false;
            if (resolved) {
                acquirerDatabaseResolved[nif] = true;
            }
        }
        acquirerCurrentItem = null;
        acquirerModalActive = false;
        processNextAcquirerQueue();
    }

    function processNextAcquirerQueue() {
        if (acquirerModalActive) {
            return;
        }
        if (!acquirerQueue.length) {
            return;
        }
        var item = acquirerQueue.shift();
        if (!item || !item.nif) {
            processNextAcquirerQueue();
            return;
        }
        if (acquirerDatabaseResolved[item.nif]) {
            acquirerDatabasePending[item.nif] = false;
            processNextAcquirerQueue();
            return;
        }

        acquirerCurrentItem = item;
        acquirerModalActive = true;
        hideAcquirerError();

        var label = (item.name || '').toString().trim();
        var message = 'O NIF do adquirente <strong>' + item.nif + '</strong>' + (label ? ' (' + label + ')' : '') + ' nao existe nas entidades. Indique a base de dados ERP.';
        if (acquirerMessage) {
            acquirerMessage.innerHTML = message;
        }

        var initialDb = (item.erp_database || '').toString().trim();
        if (!initialDb && typeof window.erpDatabase === 'string') {
            initialDb = window.erpDatabase.trim();
        }

        if (acquirerInput) {
            acquirerInput.value = initialDb;
            window.setTimeout(function() {
                acquirerInput.focus();
                acquirerInput.select();
            }, 100);
        }

        if (acquirerModal) {
            acquirerModal.show();
        } else {
            var selectedDb = window.prompt(
                'O NIF do adquirente ' + item.nif + (label ? ' (' + label + ')' : '') + ' nao existe nas entidades.\nIndique a base de dados ERP (ex: emp_236):',
                initialDb || ''
            );
            if (selectedDb === null || selectedDb.trim() === '') {
                finishAcquirerStep(item.nif, false);
                return;
            }
            submitAcquirerDatabase(item, selectedDb.trim());
        }
    }

    function submitAcquirerDatabase(item, selectedDb) {
        var nif = item && item.nif ? item.nif : '';
        if (!nif || !selectedDb) {
            showAcquirerError('NIF e base de dados sao obrigatorios.');
            return;
        }

        hideAcquirerError();
        if (acquirerConfirmBtn) {
            acquirerConfirmBtn.disabled = true;
        }

        var body = new URLSearchParams();
        body.append('csrf_token', csrfInput.value);
        body.append('action', 'set-acquirer-database');
        body.append('acquirer_nif', nif);
        body.append('acquirer_value', (item.name || '').toString());
        body.append('database', selectedDb);

        fetch('contabilidade/upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            updateCsrfToken(res);
            if (!res || !res.success) {
                showAcquirerError((res && res.error) ? res.error : 'Nao foi possivel guardar a base de dados do adquirente.');
                return;
            }
            if (acquirerModal) {
                acquirerModal.hide();
            }
            notifySuccess('Base de dados ERP guardada para o adquirente ' + nif + '.');
            finishAcquirerStep(nif, true);
        })
        .catch(function() {
            showAcquirerError('Erro ao guardar a base de dados do adquirente.');
        })
        .finally(function() {
            if (acquirerConfirmBtn) {
                acquirerConfirmBtn.disabled = false;
            }
        });
    }

    function askAcquirerDatabase(acquirerInfo) {
        if (!acquirerInfo || !acquirerInfo.nif || !csrfInput) {
            return;
        }
        var nif = String(acquirerInfo.nif).trim();
        if (!nif || acquirerDatabaseResolved[nif] || acquirerDatabasePending[nif]) {
            return;
        }
        acquirerDatabasePending[nif] = true;
        acquirerQueue.push({
            nif: nif,
            name: (acquirerInfo.name || '').toString().trim(),
            erp_database: (acquirerInfo.erp_database || '').toString().trim()
        });
        processNextAcquirerQueue();
    }

    if (acquirerForm) {
        acquirerForm.addEventListener('submit', function(ev) {
            ev.preventDefault();
            if (!acquirerCurrentItem || !acquirerInput) {
                return;
            }
            var selectedDb = acquirerInput.value.trim();
            if (!selectedDb) {
                showAcquirerError('Indique uma base de dados ERP valida.');
                return;
            }
            submitAcquirerDatabase(acquirerCurrentItem, selectedDb);
        });
    }

    if (acquirerModalEl) {
        acquirerModalEl.addEventListener('hidden.bs.modal', function() {
            var nif = acquirerCurrentItem && acquirerCurrentItem.nif ? acquirerCurrentItem.nif : '';
            if (nif && !acquirerDatabaseResolved[nif]) {
                finishAcquirerStep(nif, false);
            }
            hideAcquirerError();
        });
    }

    window.ensureAcquirerDatabase = askAcquirerDatabase;

    function syncEntity(value, type, acquirerValue) {
        var entityValue = (value || '').trim();
        if (!entityValue || !csrfInput) {
            return;
        }
        var body = new URLSearchParams();
        body.append('csrf_token', csrfInput.value);
        body.append('value', entityValue);
        body.append('entity_type', type || 'emitter');
        if (typeof window.erpDatabase === 'string' && window.erpDatabase.trim() !== '') {
            body.append('database', window.erpDatabase.trim());
        }
        if (acquirerValue) {
            body.append('acquirer_value', acquirerValue);
        }
        body.append('action', 'sync-entity');

        fetch('contabilidade/upload.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: body.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            updateCsrfToken(res);
            if (res && res.requires_acquirer_database && res.acquirer) {
                askAcquirerDatabase(res.acquirer);
            }
        })
        .catch(function() {});
    }

    var table;
    if ($.fn.dataTable.isDataTable('#qr-table')) {
        table = $('#qr-table').DataTable();
    } else {
        table = $('#qr-table').DataTable({
            orderCellsTop: true,
            language: { url: 'vendors/datatables.net/i18n/pt-PT.json' },
            columnDefs: [
                { targets: [2, 4, 7, 8, 17, 18], visible: false },
                { targets: [0, 1], className: 'text-start' },
                { targets: [9, 10, 11, 12, 13, 14, 15, 16], orderable: false },
                { targets: [-1], orderable: false, searchable: false }
            ]
        });
    }

    window.accountingTable = table;

    function formatDate(dateStr) {
        if (!dateStr) {
            return '';
        }
        if (/^\d{8}$/.test(dateStr)) {
            return dateStr.slice(0, 4) + '-' + dateStr.slice(4, 6) + '-' + dateStr.slice(6, 8);
        }
        var date = new Date(dateStr);
        if (!isNaN(date)) {
            var y = date.getFullYear();
            var m = ('0' + (date.getMonth() + 1)).slice(-2);
            var d = ('0' + date.getDate()).slice(-2);
            return y + '-' + m + '-' + d;
        }
        return dateStr;
    }

    function extractQR(str) {
        var parts = String(str || '').split('*');
        var obj = {};
        parts.forEach(function(part) {
            var del = part.split(':', 2);
            if (del.length === 2) {
                obj[del[0].trim()] = del[1].trim();
            }
        });
        return obj;
    }

    function extractVatFromPartyValue(value) {
        var digits = String(value || '').match(/\d{9}/);
        return digits ? digits[0] : String(value || '').trim();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function buildEmitterCellHtml(value) {
        var rawValue = String(value || '').trim();
        var nifValue = extractVatFromPartyValue(rawValue);
        return '<span class="emitter-cell" data-raw="' + escapeHtml(rawValue) + '">' + escapeHtml(nifValue) + '</span>';
    }

    function normalizeUploadDocType(value) {
        var normalized = String(value || '').trim().toUpperCase();
        if (!normalized) {
            return '';
        }
        if (normalized === 'FTR' || normalized === 'FATURA-RECIBO' || normalized === 'FATURA RECIBO' || normalized === 'FACTURA-RECIBO') {
            return 'FR';
        }
        if (normalized === 'FATURA' || normalized === 'FACTURA') {
            return 'FT';
        }
        if (normalized === 'RECIBO' || normalized === 'RG') {
            return 'RC';
        }
        return normalized;
    }

    function isInvoiceDocType(value) {
        var normalized = normalizeUploadDocType(value);
        return normalized === 'FT' || normalized === 'FR';
    }

    function isReceiptDocType(value) {
        return normalizeUploadDocType(value) === 'RC';
    }

    function filterRowsToPreferInvoices(rows) {
        var structuredRows = Array.isArray(rows) ? rows.slice() : [];
        var hasInvoice = structuredRows.some(function(row) {
            return row && typeof row === 'object' && isInvoiceDocType(row.D || '');
        });
        if (!hasInvoice) {
            return structuredRows;
        }
        return structuredRows.filter(function(row) {
            return !(row && typeof row === 'object' && isReceiptDocType(row.D || ''));
        });
    }

    function fileHasPendingInvoiceRow(filePath) {
        return getPendingRowNodes().some(function(node) {
            if (getRowFilePath(node) !== filePath) {
                return false;
            }
            var data = table.row(node).data() || [];
            return isInvoiceDocType(data[3] || '');
        });
    }

    function setReceiptCompanionFlagForFile(filePath, hasReceiptCompanion) {
        var targetFile = String(filePath || '').trim();
        if (!targetFile) {
            return;
        }

        var matchingNodes = table.rows().nodes().toArray().filter(function(node) {
            return getRowFilePath(node) === targetFile;
        });
        if (!matchingNodes.length) {
            return;
        }

        matchingNodes.forEach(function(node) {
            var rowApi = table.row(node);
            var data = rowApi.data() || [];
            if (!data.length) {
                return;
            }
            var imported = $(node).find('.delete-row').length === 0;
            data[data.length - 1] = buildActionsHtml(targetFile, imported, hasReceiptCompanion);
            rowApi.data(data);
        });

        table.draw(false);
    }

    function decodeTableCellRawValue(cellHtml) {
        var html = String(cellHtml || '');
        var match = html.match(/data-raw="([^"]*)"/i);
        if (!match) {
            return html.replace(/<[^>]+>/g, '').trim();
        }
        var textarea = document.createElement('textarea');
        textarea.innerHTML = match[1];
        return textarea.value;
    }

    function pruneReceiptRowsForFile(filePath) {
        var targetFile = String(filePath || '').trim();
        if (!targetFile) {
            return;
        }

        var matchingNodes = getPendingRowNodes().filter(function(node) {
            return getRowFilePath(node) === targetFile;
        });
        if (!matchingNodes.length) {
            return;
        }

        var hasInvoice = matchingNodes.some(function(node) {
            var data = table.row(node).data() || [];
            return isInvoiceDocType(data[3] || '');
        });
        if (!hasInvoice) {
            return;
        }

        var receiptNodes = matchingNodes.filter(function(node) {
            var data = table.row(node).data() || [];
            return isReceiptDocType(data[3] || '');
        });
        if (!receiptNodes.length) {
            return;
        }

        table.rows(receiptNodes).remove().draw(false);
    }

    function pruneReceiptRowsAcrossTable() {
        var allNodes = table.rows().nodes().toArray();
        if (!allNodes.length) {
            return;
        }

        var grouped = {};
        allNodes.forEach(function(node) {
            var filePath = getRowFilePath(node);
            if (!filePath) {
                return;
            }
            if (!grouped[filePath]) {
                grouped[filePath] = {
                    invoiceNodes: [],
                    receiptNodes: []
                };
            }
            var data = table.row(node).data() || [];
            if (isInvoiceDocType(data[3] || '')) {
                grouped[filePath].invoiceNodes.push(node);
            } else if (isReceiptDocType(data[3] || '')) {
                grouped[filePath].receiptNodes.push(node);
            }
        });

        var nodesToRemove = [];
        var shouldRedraw = false;
        Object.keys(grouped).forEach(function(filePath) {
            var group = grouped[filePath];
            if (!group || !group.invoiceNodes.length || !group.receiptNodes.length) {
                return;
            }

            group.invoiceNodes.forEach(function(node) {
                var rowApi = table.row(node);
                var data = rowApi.data() || [];
                if (!data.length) {
                    return;
                }
                var imported = $(node).find('.delete-row').length === 0;
                data[data.length - 1] = buildActionsHtml(filePath, imported, true);
                rowApi.data(data);
                shouldRedraw = true;
            });

            nodesToRemove = nodesToRemove.concat(group.receiptNodes);
        });

        if (nodesToRemove.length) {
            table.rows(nodesToRemove).remove();
            shouldRedraw = true;
        }

        if (shouldRedraw) {
            table.draw(false);
        }
        refreshUploadActionState();
    }

    function addStructuredRows(rows, filePath) {
        var added = 0;
        var rowsToAdd = [];
        var originalRows = Array.isArray(rows) ? rows.slice() : [];
        var batchHasInvoice = originalRows.some(function(row) {
            return row && typeof row === 'object' && isInvoiceDocType(row.D || '');
        });
        var batchHasReceipt = originalRows.some(function(row) {
            return row && typeof row === 'object' && isReceiptDocType(row.D || '');
        });
        var hasReceiptCompanion = batchHasReceipt && (batchHasInvoice || fileHasPendingInvoiceRow(filePath));

        filterRowsToPreferInvoices(originalRows).forEach(function(qrData) {
            if (!qrData || typeof qrData !== 'object') {
                return;
            }
            var hasContent = qrKeys.some(function(key) {
                return (qrData[key] || '').toString().trim() !== '';
            });
            if (!hasContent) {
                return;
            }
            var row = qrKeys.map(function(key) {
                var value = qrData[key] || '';
                if (key === 'F') {
                    value = formatDate(value);
                }
                if (key === 'A') {
                    return buildEmitterCellHtml(value);
                }
                return value;
            });
            var actions = buildActionsHtml(filePath, false, hasReceiptCompanion);
            row.push(actions);
            rowsToAdd.push(row);
            added += 1;
            syncEntity(qrData.A || '', 'emitter', qrData.B || '');
        });

        if (rowsToAdd.length) {
            table.rows.add(rowsToAdd).draw(false);
        }
        if (hasReceiptCompanion) {
            setReceiptCompanionFlagForFile(filePath, true);
        }
        pruneReceiptRowsForFile(filePath);
        pruneReceiptRowsAcrossTable();

        refreshUploadActionState();
        return added;
    }

    function addRowsFromQrTexts(qrTexts, filePath) {
        var rows = [];
        (qrTexts || []).forEach(function(qrText) {
            var trimmed = (qrText || '').trim();
            if (!trimmed) {
                return;
            }
            rows.push(extractQR(trimmed));
        });
        return addStructuredRows(rows, filePath);
    }

    function removeUploadedFile(filePath, callback) {
        $.ajax({
            type: 'POST',
            url: deleteUrl,
            data: { file: filePath, csrf_token: csrfInput.value },
            dataType: 'json',
            success: function(res) {
                updateCsrfToken(res);
                if (callback) {
                    callback(true, res);
                }
            },
            error: function(xhr) {
                var errData = {};
                try {
                    errData = JSON.parse(xhr.responseText);
                } catch (e) {}
                updateCsrfToken(errData);
                if (callback) {
                    callback(false, errData);
                }
            }
        });
    }

    function removeDropzoneFileByServerPath(filePath) {
        var dzFiles = dz.files;
        for (var i = 0; i < dzFiles.length; i += 1) {
            if (dzFiles[i].serverFile === filePath) {
                dz.removeFile(dzFiles[i]);
                break;
            }
        }
    }

    function setDropzoneQrState(file, state) {
        if (!file || !file.previewElement) {
            return;
        }
        file.previewElement.classList.remove('qr-auto-detected');
        file.previewElement.classList.remove('qr-manual-required');
        if (state === 'auto') {
            file.previewElement.classList.add('qr-auto-detected');
        } else if (state === 'manual') {
            file.previewElement.classList.add('qr-manual-required');
        }
    }

    function resetManualSelection() {
        manualSelection = null;
        manualPointer = null;
        if (manualSelectionBox) {
            manualSelectionBox.style.display = 'none';
            manualSelectionBox.style.left = '0';
            manualSelectionBox.style.top = '0';
            manualSelectionBox.style.width = '0';
            manualSelectionBox.style.height = '0';
        }
    }

    function updateManualSelectionBox(selection) {
        if (!manualSelectionBox || !selection) {
            return;
        }
        var rect = manualCanvasWrap ? manualCanvasWrap.getBoundingClientRect() : null;
        var left = selection.left;
        var top = selection.top;
        var width = selection.width;
        var height = selection.height;
        if (rect && rect.width > 0 && rect.height > 0) {
            left = selection.x * rect.width;
            top = selection.y * rect.height;
            width = selection.w * rect.width;
            height = selection.h * rect.height;
        }
        manualSelectionBox.style.display = 'block';
        manualSelectionBox.style.left = left + 'px';
        manualSelectionBox.style.top = top + 'px';
        manualSelectionBox.style.width = width + 'px';
        manualSelectionBox.style.height = height + 'px';
    }

    function applyManualZoom(zoom) {
        manualZoom = zoom;
        if (manualPreviewImage) {
            manualPreviewImage.style.width = (zoom * 100) + '%';
            manualPreviewImage.style.maxWidth = 'none';
        }
        if (manualZoom100Btn) {
            manualZoom100Btn.classList.toggle('btn-primary', zoom === 1);
            manualZoom100Btn.classList.toggle('btn-default', zoom !== 1);
        }
        if (manualZoom150Btn) {
            manualZoom150Btn.classList.toggle('btn-primary', zoom === 1.5);
            manualZoom150Btn.classList.toggle('btn-default', zoom !== 1.5);
        }
        if (manualZoom200Btn) {
            manualZoom200Btn.classList.toggle('btn-primary', zoom === 2);
            manualZoom200Btn.classList.toggle('btn-default', zoom !== 2);
        }
        if (manualSelection) {
            updateManualSelectionBox(manualSelection);
        }
    }

    function getWrapPoint(ev) {
        if (!manualCanvasWrap) {
            return null;
        }
        var rect = manualCanvasWrap.getBoundingClientRect();
        var point = ev.touches && ev.touches[0] ? ev.touches[0] : ev;
        var x = point.clientX - rect.left;
        var y = point.clientY - rect.top;
        x = Math.max(0, Math.min(rect.width, x));
        y = Math.max(0, Math.min(rect.height, y));
        return { x: x, y: y, width: rect.width, height: rect.height };
    }

    function startSelection(ev) {
        if (!manualActive || !manualPreviewImage || !manualPreviewImage.getAttribute('src')) {
            return;
        }
        var point = getWrapPoint(ev);
        if (!point || point.width <= 0 || point.height <= 0) {
            return;
        }
        setManualError('');
        manualPointer = { startX: point.x, startY: point.y, boundsWidth: point.width, boundsHeight: point.height };
        manualSelection = { left: point.x, top: point.y, width: 0, height: 0, x: point.x / point.width, y: point.y / point.height, w: 0, h: 0 };
        updateManualSelectionBox(manualSelection);
        ev.preventDefault();
    }

    function moveSelection(ev) {
        if (!manualPointer) {
            return;
        }
        var point = getWrapPoint(ev);
        if (!point) {
            return;
        }
        var left = Math.min(manualPointer.startX, point.x);
        var top = Math.min(manualPointer.startY, point.y);
        var width = Math.abs(point.x - manualPointer.startX);
        var height = Math.abs(point.y - manualPointer.startY);
        manualSelection = {
            left: left,
            top: top,
            width: width,
            height: height,
            x: left / point.width,
            y: top / point.height,
            w: width / point.width,
            h: height / point.height
        };
        updateManualSelectionBox(manualSelection);
        ev.preventDefault();
    }

    function endSelection() {
        if (!manualSelection || manualSelection.w < 0.01 || manualSelection.h < 0.01) {
            resetManualSelection();
        }
        manualPointer = null;
    }

    function updateManualPageLabel() {
        if (manualPageLabel) {
            manualPageLabel.textContent = 'Página ' + manualPage + ' de ' + manualPageCount;
        }
        if (manualPrevPageBtn) {
            manualPrevPageBtn.disabled = manualPage <= 1;
        }
        if (manualNextPageBtn) {
            manualNextPageBtn.disabled = manualPage >= manualPageCount;
        }
    }

    function updateManualQueueInfo() {
        if (manualQueueLabel) {
            if (manualActive && manualTotal > 0) {
                manualQueueLabel.textContent = 'Ficheiro ' + manualSequence + ' de ' + manualTotal;
            } else {
                manualQueueLabel.textContent = 'Ficheiro 0 de 0';
            }
        }
        if (manualFileName) {
            manualFileName.textContent = manualActive && manualActive.name ? manualActive.name : '-';
        }
    }

    updateManualQueueInfo();

    function loadManualPreview(page) {
        if (!manualActive) {
            return;
        }
        manualPage = page;
        resetManualSelection();
        setManualError('');
        resetManualEfaturaSelection();
        setManualLoading(true);
        if (manualPreviewImage) {
            manualPreviewImage.removeAttribute('src');
            manualPreviewImage.classList.add('is-hidden');
        }

        fetch(previewUrl + '&file=' + encodeURIComponent(manualActive.file) + '&page=' + encodeURIComponent(String(page)), {
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (!res || !res.success) {
                throw new Error((res && res.error) ? res.error : 'Falha ao gerar pré-visualização.');
            }
            manualPage = parseInt(res.page, 10) || page;
            manualPageCount = parseInt(res.page_count, 10) || 1;
            updateManualPageLabel();
            if (manualPreviewImage) {
                manualPreviewImage.onload = function() {
                    manualPreviewImage.classList.remove('is-hidden');
                    setManualLoading(false);
                };
                manualPreviewImage.onerror = function() {
                    setManualError('Falha ao carregar a pré-visualização da imagem.');
                    setManualLoading(false);
                };
                manualPreviewImage.src = res.preview_url + (res.preview_url.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
            }
            if (manualOpenFileBtn) {
                manualOpenFileBtn.href = res.document_url || manualActive.file;
            }
        })
        .catch(function(err) {
            setManualError(err && err.message ? err.message : 'Falha ao gerar pré-visualização.');
            setManualLoading(false);
        });
    }

    function showNextManualQueue() {
        if (manualActive || !manualQueue.length) {
            return;
        }
        manualActive = manualQueue.shift();
        manualCloseMode = 'discard';
        if (manualSequence <= 0 || manualQueue.length + 1 > manualTotal) {
            manualTotal = manualQueue.length + 1;
            manualSequence = 0;
        }
        manualSequence += 1;
        manualPage = 1;
        manualPageCount = 1;
        applyManualZoom(1);
        resetManualSelection();
        setManualError('');
        resetManualEfaturaSelection();
        applyManualAcquirerSelection(null, false);
        updateManualEfaturaSelectAvailability();
        updateManualPageLabel();
        updateManualQueueInfo();
        if (manualModal) {
            manualModal.show();
        }
        runManualAcquirerOcrSuggestion();
        loadManualPreview(1);
    }

    function queueManualQr(file, filePath) {
        setDropzoneQrState(file, 'manual');
        manualQueue.push({
            dropzoneFile: file,
            file: filePath,
            name: file && file.name ? String(file.name) : String(filePath || '').split('/').pop()
        });
        if (!manualActive) {
            manualTotal = manualQueue.length;
            manualSequence = 0;
        } else {
            manualTotal += 1;
        }
        updateManualQueueInfo();
    }

    function finishManualQueueItem() {
        manualCloseMode = 'finish';
        setManualImportAsIsBusy(false);
        setManualEmitterBusy(false);
        manualOcrCandidateNifs = [];
        manualActive = null;
        manualPage = 1;
        manualPageCount = 1;
        applyManualZoom(1);
        resetManualSelection();
        setManualError('');
        resetManualEfaturaSelection();
        if (manualPreviewImage) {
            manualPreviewImage.onload = null;
            manualPreviewImage.onerror = null;
            manualPreviewImage.removeAttribute('src');
            manualPreviewImage.classList.add('is-hidden');
        }
        if (!manualQueue.length) {
            manualSequence = 0;
            manualTotal = 0;
            printDebugSummary();
        }
        updateManualQueueInfo();
        showNextManualQueue();
    }

    function discardManualActiveFile() {
        if (!manualActive || manualImportAsIsPending) {
            return;
        }
        manualCloseMode = 'finish';
        var filePath = manualActive.file;
        if (debugEnabled && manualActive.dropzoneFile) {
            ensureDebugFile(manualActive.dropzoneFile).state = 'manual_discarded';
            debugStats.manualDiscarded += 1;
        }
        removeUploadedFile(filePath, function(success) {
            if (!success) {
                showAlert('Falha ao eliminar ficheiro.');
                return;
            }
            removeDropzoneFileByServerPath(filePath);
            if (manualModal) {
                manualModal.hide();
            } else {
                finishManualQueueItem();
            }
        });
    }

    function submitImportRows(rows, type) {
        return fetch('contabilidade/upload.php?action=import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rows: rows, import_type: type, csrf_token: csrfInput.value })
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            updateCsrfToken(res);
            if (!res || !res.success) {
                throw new Error((res && res.error) ? res.error : 'Falha na importação');
            }
            return res;
        });
    }

    function importManualActiveFileAsIs() {
        if (!manualActive || manualImportAsIsPending) {
            return;
        }

        if (!manualSelectedAcquirer || !manualSelectedAcquirer.nif) {
            setManualEfaturaError('Selecione primeiro a empresa/adquirente.');
            return;
        }

        var prefillEmitterNif = '';
        for (var i = 0; i < manualOcrCandidateNifs.length; i += 1) {
            var candidate = String(manualOcrCandidateNifs[i] || '').replace(/\D+/g, '');
            if (candidate && candidate !== String(manualSelectedAcquirer.nif || '').replace(/\D+/g, '')) {
                prefillEmitterNif = candidate;
                break;
            }
        }

        if (prefillEmitterNif) {
            openManualEmitterPrompt(prefillEmitterNif);
            return;
        }

        if (!manualActive.file || !ocrEmitterUrl) {
            openManualEmitterPrompt('');
            return;
        }

        setManualEmitterBusy(true);
        fetch(ocrEmitterUrl + '&file=' + encodeURIComponent(manualActive.file) + '&acquirer_nif=' + encodeURIComponent(manualSelectedAcquirer.nif || ''), {
            credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            manualOcrCandidateNifs = Array.isArray(res && res.candidate_nifs) ? res.candidate_nifs : manualOcrCandidateNifs;
            openManualEmitterPrompt((res && res.emitter_nif) ? res.emitter_nif : '');
        })
        .catch(function() {
            openManualEmitterPrompt('');
        })
        .finally(function() {
            setManualEmitterBusy(false);
        });
    }

    function confirmManualImportAsIs() {
        if (!manualActive || manualImportAsIsPending || !manualSelectedAcquirer || !manualSelectedAcquirer.nif) {
            return;
        }

        var emitterNif = String((manualEmitterInput && manualEmitterInput.value) || '').replace(/\D+/g, '');
        if (!emitterNif) {
            setManualEmitterError('Indique o NIF do emitente.');
            return;
        }

        var filePath = manualActive.file;
        if (!filePath) {
            setManualEmitterError('Ficheiro inválido para importar.');
            return;
        }

        setManualEmitterError('');
        setManualError('');
        setManualImportAsIsBusy(true);
        setManualEmitterBusy(true);

        submitImportRows([{
            filename: filePath,
            A: emitterNif,
            B: String(manualSelectedAcquirer.nif || '').replace(/\D+/g, '')
        }], 1)
            .then(function() {
                if (manualEmitterModal) {
                    manualEmitterModal.hide();
                }
                if (manualActive && manualActive.dropzoneFile) {
                    removeDropzoneFileByServerPath(filePath);
                    if (debugEnabled) {
                        var manualImportItem = ensureDebugFile(manualActive.dropzoneFile);
                        manualImportItem.finishedAt = performance.now();
                        manualImportItem.ms = Math.max(0, Math.round(manualImportItem.finishedAt - manualImportItem.startedAt));
                        manualImportItem.frontendMs = Math.max(0, manualImportItem.ms - (manualImportItem.backendMs || 0));
                        manualImportItem.state = 'manual_import_as_is';
                        debugStats.manualSuccess += 1;
                    }
                } else {
                    removeDropzoneFileByServerPath(filePath);
                }
                refreshUploadActionState();
                notifySuccess('Documento enviado para Classificação.');
                manualCloseMode = 'finish';
                if (manualModal) {
                    manualModal.hide();
                } else {
                    finishManualQueueItem();
                }
            })
            .catch(function(err) {
                setManualEmitterError((err && err.message) ? err.message : 'Falha ao enviar o documento para Classificação.');
                setManualImportAsIsBusy(false);
                setManualEmitterBusy(false);
            });
    }

    if (manualCanvasWrap) {
        manualCanvasWrap.addEventListener('mousedown', startSelection);
        manualCanvasWrap.addEventListener('touchstart', startSelection, { passive: false });
    }
    document.addEventListener('mousemove', moveSelection);
    document.addEventListener('touchmove', moveSelection, { passive: false });
    document.addEventListener('mouseup', endSelection);
    document.addEventListener('touchend', endSelection);

    if (manualClearBtn) {
        manualClearBtn.addEventListener('click', function() {
            resetManualSelection();
            setManualError('');
        });
    }

    if (manualDiscardBtn) {
        manualDiscardBtn.addEventListener('click', function() {
            discardManualActiveFile();
        });
    }

    if (manualImportAsIsBtn) {
        manualImportAsIsBtn.addEventListener('click', function() {
            importManualActiveFileAsIs();
        });
    }

    if (manualEmitterForm) {
        manualEmitterForm.addEventListener('submit', function(ev) {
            ev.preventDefault();
            confirmManualImportAsIs();
        });
    }

    if (manualPrevPageBtn) {
        manualPrevPageBtn.addEventListener('click', function() {
            if (manualPage > 1) {
                loadManualPreview(manualPage - 1);
            }
        });
    }

    if (manualNextPageBtn) {
        manualNextPageBtn.addEventListener('click', function() {
            if (manualPage < manualPageCount) {
                loadManualPreview(manualPage + 1);
            }
        });
    }

    if (manualZoom100Btn) {
        manualZoom100Btn.addEventListener('click', function() {
            applyManualZoom(1);
        });
    }

    if (manualZoom150Btn) {
        manualZoom150Btn.addEventListener('click', function() {
            applyManualZoom(1.5);
        });
    }

    if (manualZoom200Btn) {
        manualZoom200Btn.addEventListener('click', function() {
            applyManualZoom(2);
        });
    }

    if (manualDecodeBtn) {
        manualDecodeBtn.addEventListener('click', function() {
            if (!manualActive || manualImportAsIsPending) {
                return;
            }
            if (!manualSelection || manualSelection.w <= 0 || manualSelection.h <= 0) {
                setManualError('Desenhe primeiro um retângulo sobre o QR Code.');
                return;
            }
            setManualError('');
            setManualLoading(true);

            var body = new URLSearchParams();
            body.append('csrf_token', csrfInput.value);
            body.append('file', manualActive.file);
            body.append('page', String(manualPage));
            body.append('x', String(manualSelection.x));
            body.append('y', String(manualSelection.y));
            body.append('w', String(manualSelection.w));
            body.append('h', String(manualSelection.h));

            fetch(manualQrUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: body.toString()
            })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                updateCsrfToken(res);
                if (!res || res.success === false) {
                    throw new Error((res && res.error) ? res.error : 'Falha ao ler QR selecionado.');
                }
                var added = addRowsFromQrTexts(res.qr_texts || [], manualActive.file);
                if (!added) {
                    setManualError('Não foi possível ler um QR válido nessa zona. Ajuste o retângulo e tente novamente.');
                    return;
                }
                if (manualActive.dropzoneFile) {
                    setDropzoneQrState(manualActive.dropzoneFile, 'auto');
                if (debugEnabled) {
                    var manualSuccessItem = ensureDebugFile(manualActive.dropzoneFile);
                    manualSuccessItem.finishedAt = performance.now();
                    manualSuccessItem.ms = Math.max(0, Math.round(manualSuccessItem.finishedAt - manualSuccessItem.startedAt));
                    manualSuccessItem.frontendMs = Math.max(0, manualSuccessItem.ms - (manualSuccessItem.backendMs || 0));
                    manualSuccessItem.state = 'manual_success';
                    debugStats.manualSuccess += 1;
                }
                }
                notifySuccess('QR Code lido manualmente com sucesso.');
                manualCloseMode = 'finish';
                if (manualModal) {
                    manualModal.hide();
                } else {
                    finishManualQueueItem();
                }
            })
            .catch(function(err) {
                setManualError(err && err.message ? err.message : 'Falha ao ler QR selecionado.');
            })
            .finally(function() {
                setManualLoading(false);
            });
        });
    }

    if (window.jQuery && jQuery.fn.select2 && manualAcquirerSelect) {
        jQuery(manualAcquirerSelect).select2({
            width: '100%',
            dropdownParent: manualModalEl ? jQuery(manualModalEl) : null,
            placeholder: 'Selecionar empresa',
            allowClear: true
        });

        jQuery(manualAcquirerSelect).on('change', function() {
            syncManualAcquirerFromSelect();
            selectedAcquirerId = manualSelectedAcquirer && manualSelectedAcquirer.id
                ? (parseInt(manualSelectedAcquirer.id, 10) || 0)
                : 0;
            resetManualEfaturaSelection();
            updateManualEfaturaSelectAvailability();
        });
    } else if (manualAcquirerSelect) {
        manualAcquirerSelect.addEventListener('change', function() {
            syncManualAcquirerFromSelect();
            selectedAcquirerId = manualSelectedAcquirer && manualSelectedAcquirer.id
                ? (parseInt(manualSelectedAcquirer.id, 10) || 0)
                : 0;
            resetManualEfaturaSelection();
            updateManualEfaturaSelectAvailability();
        });
    }

    if (window.jQuery && jQuery.fn.select2 && manualEfaturaSelect) {
        jQuery(manualEfaturaSelect).select2({
            width: '100%',
            dropdownParent: manualModalEl ? jQuery(manualModalEl) : null,
            placeholder: 'Pesquisar por NIF, nome, ATCUD ou numero do documento',
            minimumInputLength: 0,
            allowClear: true,
            ajax: {
                url: efaturaSearchUrl,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    var payload = { q: params.term || '' };
                    if (manualSelectedAcquirer && manualSelectedAcquirer.id) {
                        payload.acquirer_entity_id = manualSelectedAcquirer.id;
                    }
                    if (manualSelectedAcquirer && manualSelectedAcquirer.nif) {
                        payload.acquirer_nif = manualSelectedAcquirer.nif;
                    }
                    return payload;
                },
                processResults: function(data) {
                    return { results: (data && data.results) ? data.results : [] };
                }
            }
        });

        jQuery(manualEfaturaSelect).on('select2:select', function(ev) {
            manualSelectedEfaturaDocument = ev && ev.params && ev.params.data ? ev.params.data : null;
            setManualEfaturaError('');
            renderManualEfaturaInfo(manualSelectedEfaturaDocument);
        });

        jQuery(manualEfaturaSelect).on('select2:clear', function() {
            manualSelectedEfaturaDocument = null;
            setManualEfaturaError('');
            renderManualEfaturaInfo(null);
        });
    }

    syncManualAcquirerFromSelect();
    updateManualEfaturaSelectAvailability();

    if (manualEfaturaApplyBtn) {
        manualEfaturaApplyBtn.addEventListener('click', function() {
            if (!manualActive || manualImportAsIsPending) {
                return;
            }
            if (!manualSelectedAcquirer || !manualSelectedAcquirer.id) {
                setManualEfaturaError('Selecione primeiro a empresa/adquirente.');
                return;
            }
            if (!manualSelectedEfaturaDocument || !manualSelectedEfaturaDocument.mapped_row) {
                setManualEfaturaError('Selecione primeiro um documento importado do E-fatura.');
                return;
            }
            var added = addStructuredRows([manualSelectedEfaturaDocument.mapped_row], manualActive.file);
            if (!added) {
                setManualEfaturaError('Nao foi possivel associar o documento E-fatura selecionado.');
                return;
            }
            if (manualActive.dropzoneFile) {
                setDropzoneQrState(manualActive.dropzoneFile, 'auto');
                if (debugEnabled) {
                    var manualEfaturaItem = ensureDebugFile(manualActive.dropzoneFile);
                    manualEfaturaItem.finishedAt = performance.now();
                    manualEfaturaItem.ms = Math.max(0, Math.round(manualEfaturaItem.finishedAt - manualEfaturaItem.startedAt));
                    manualEfaturaItem.frontendMs = Math.max(0, manualEfaturaItem.ms - (manualEfaturaItem.backendMs || 0));
                    manualEfaturaItem.state = 'manual_success';
                    debugStats.manualSuccess += 1;
                }
            }
            notifySuccess('Documento associado a partir do E-fatura com sucesso.');
            manualCloseMode = 'finish';
            if (manualModal) {
                manualModal.hide();
            } else {
                finishManualQueueItem();
            }
        });
    }

    if (manualModalEl) {
        manualModalEl.addEventListener('hidden.bs.modal', function() {
            if (manualActive && manualCloseMode === 'discard') {
                var filePath = manualActive.file;
                if (debugEnabled && manualActive.dropzoneFile) {
                    ensureDebugFile(manualActive.dropzoneFile).state = 'manual_discarded';
                    debugStats.manualDiscarded += 1;
                }
                manualCloseMode = 'finish';
                removeUploadedFile(filePath, function(success) {
                    if (success) {
                        removeDropzoneFileByServerPath(filePath);
                    }
                    finishManualQueueItem();
                });
                return;
            }
            finishManualQueueItem();
        });
    }

    var dz = new Dropzone('#multi-upload', {
        url: 'contabilidade/upload-handler.php',
        paramName: 'file',
        acceptedFiles: 'application/pdf',
        maxFilesize: 20,
        parallelUploads: parallelUploads,
        dictFileTooBig: 'O ficheiro excede o tamanho máximo permitido (20 MB).',
        dictDefaultMessage: 'Arraste e solte os ficheiros aqui ou clique para selecionar'
    });

    dz.on('addedfile', function(file) {
        if (!debugEnabled) {
            return;
        }
        var item = ensureDebugFile(file);
        if (!item.queuedAt) {
            item.queuedAt = performance.now();
            item.state = 'queued';
        }
    });

    dz.on('sending', function(file, xhr, formData) {
        if (debugEnabled) {
            if (!debugStats.batchStartedAt) {
                debugStats.batchStartedAt = performance.now();
            }
            var item = ensureDebugFile(file);
            if (!item.queuedAt) {
                item.queuedAt = performance.now();
            }
            item.startedAt = performance.now();
            item.queueMs = Math.max(0, Math.round(item.startedAt - item.queuedAt));
            item.state = 'uploading';
        }
        uploadProcessingCount += 1;
        if (csrfInput) {
            formData.append('csrf_token', csrfInput.value);
        }
    });

    dz.on('success', function(file, response) {
        var data = response;
        if (typeof response === 'string') {
            try {
                data = JSON.parse(response);
            } catch (e) {
                data = {};
            }
        }
        updateCsrfToken(data);
        if (data.file) {
            file.serverFile = data.file;
        }
        if (debugEnabled) {
            var successItem = ensureDebugFile(file);
            successItem.attempts = Array.isArray(data.qr_attempted_dpis) ? data.qr_attempted_dpis.slice() : [];
            successItem.backendMs = data && data.timings && data.timings.total_ms ? parseInt(data.timings.total_ms, 10) || 0 : 0;
        }

        var added = addRowsFromQrTexts(data.qr_texts || [], data.file || '');
        if (added) {
            setDropzoneQrState(file, 'auto');
            if (debugEnabled) {
                var autoItem = ensureDebugFile(file);
                autoItem.finishedAt = performance.now();
                autoItem.ms = Math.max(0, Math.round(autoItem.finishedAt - autoItem.startedAt));
                autoItem.frontendMs = Math.max(0, autoItem.ms - (autoItem.backendMs || 0));
                autoItem.state = 'automatic_success';
                debugStats.automaticSuccess += 1;
            }
        } else if (data.file) {
            queueManualQr(file, data.file);
            if (debugEnabled) {
                var manualItem = ensureDebugFile(file);
                manualItem.state = 'manual_queue';
                debugStats.manualQueued += 1;
            }
        } else if (!added) {
            showAlert('QR code não encontrado');
            dz.removeFile(file);
            if (debugEnabled) {
                var failItem = ensureDebugFile(file);
                failItem.finishedAt = performance.now();
                failItem.ms = Math.max(0, Math.round(failItem.finishedAt - failItem.startedAt));
                failItem.frontendMs = Math.max(0, failItem.ms - (failItem.backendMs || 0));
                failItem.state = 'hard_failure';
                debugStats.hardFailures += 1;
            }
        }
        uploadProcessingCount = Math.max(0, uploadProcessingCount - 1);
    });

    dz.on('error', function(file, errorMessage, xhr) {
        var msg = 'Erro ao processar o ficheiro ' + file.name;
        var tokenUpdated = false;
        if (xhr && xhr.responseText) {
            try {
                var errData = JSON.parse(xhr.responseText);
                if (errData.error) {
                    msg = errData.error;
                }
                if (errData.csrf_token && csrfInput) {
                    csrfInput.value = errData.csrf_token;
                    tokenUpdated = true;
                }
            } catch (e) {}
        }
        if (!tokenUpdated && csrfInput) {
            $.ajax({
                type: 'POST',
                url: 'contabilidade/upload-handler.php',
                data: { action: 'refresh_csrf', csrf_token: csrfInput.value },
                dataType: 'json',
                success: function(res) { updateCsrfToken(res); },
                error: function(xhrObj) {
                    try {
                        updateCsrfToken(JSON.parse(xhrObj.responseText));
                    } catch (e) {}
                }
            });
        }
        showAlert(msg);
        dz.removeFile(file);
        if (debugEnabled) {
            var errorItem = ensureDebugFile(file);
            errorItem.finishedAt = performance.now();
            errorItem.ms = Math.max(0, Math.round(errorItem.finishedAt - errorItem.startedAt));
            errorItem.frontendMs = Math.max(0, errorItem.ms - (errorItem.backendMs || 0));
            errorItem.state = 'request_error';
            debugStats.hardFailures += 1;
        }
        uploadProcessingCount = Math.max(0, uploadProcessingCount - 1);
    });

    dz.on('queuecomplete', function() {
        refreshUploadActionState();
        if (uploadProcessingCount === 0 && !manualActive && manualQueue.length) {
            manualTotal = manualQueue.length;
            manualSequence = 0;
            showNextManualQueue();
        } else if (uploadProcessingCount === 0 && !manualActive) {
            printDebugSummary();
        }
    });

    $('#qr-table').on('click', '.delete-row', function() {
        if (!confirm('Eliminar ficheiro?')) {
            return;
        }
        var filePath = $(this).data('file');
        var row = table.row($(this).parents('tr'));
        removeUploadedFile(filePath, function(success, res) {
            if (!success) {
                showAlert((res && res.error) ? res.error : 'Erro ao eliminar ficheiro');
                return;
            }
            removePendingRowsForFile(filePath, row);
            removeDropzoneFileByServerPath(filePath);
            refreshUploadActionState();
        });
    });

    function handleImport(type) {
        var nodes = getPendingRowNodes();
        if (!nodes.length) {
            showAlert('Não há dados para importar');
            return;
        }
        var payload = nodes.map(function(node) {
            var data = table.row(node).data() || [];
            var obj = {};
            for (var i = 0; i < qrKeys.length; i += 1) {
                obj[qrKeys[i]] = (qrKeys[i] === 'A') ? decodeTableCellRawValue(data[i] || '') : (data[i] || '');
            }
            obj.filename = $(node).find('.delete-row').data('file') || '';
            obj.has_receipt_companion = getRowHasReceiptCompanion(node) ? '1' : '0';
            return obj;
        });
        submitImportRows(payload, type)
        .then(function() {
            notifySuccess('Importação concluída');
            markCurrentRowsAsImported();
        })
        .catch(function(err) {
            showAlert((err && err.message) ? err.message : 'Falha na importação');
        });
    }

    if (importBtn) {
        importBtn.addEventListener('click', function() { handleImport(1); });
    }
    if (importComprasBtn) {
        importComprasBtn.addEventListener('click', function() { handleImport(2); });
    }

    window.addEventListener('beforeunload', function(ev) {
        if (!navigationGuardEnabled) {
            return;
        }
        var message = getPendingNavigationMessage();
        if (!message) {
            return;
        }
        ev.preventDefault();
        ev.returnValue = message;
        return message;
    });

    try {
        window.history.replaceState({ accountingUploadGuard: 'root' }, '', window.location.href);
        window.history.pushState({ accountingUploadGuard: 'stay' }, '', window.location.href);
    } catch (err) {}

    window.addEventListener('popstate', function() {
        if (suppressNextPopstateGuard) {
            suppressNextPopstateGuard = false;
            return;
        }
        if (!navigationGuardEnabled) {
            return;
        }
        var message = getPendingNavigationMessage();
        if (!message) {
            return;
        }
        var shouldLeave = window.confirm(message);
        if (!shouldLeave) {
            try {
                window.history.pushState({ accountingUploadGuard: 'stay' }, '', window.location.href);
            } catch (err) {}
            return;
        }
        navigationGuardEnabled = false;
        suppressNextPopstateGuard = true;
        window.history.back();
    });

    refreshUploadActionState();
});
