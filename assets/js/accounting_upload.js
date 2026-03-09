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
    var deleteUrl = window.accountingUploadDeleteUrl || 'contabilidade/upload.php?action=delete';
    var debugEnabled = window.accountingUploadDebug === true;

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
                startedAt: 0,
                finishedAt: 0,
                ms: 0,
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
                segundos: Number((item.ms / 1000).toFixed(2)),
                estado: item.state,
                dpis: item.attempts.join(', ')
            };
        });
        var totalMs = rows.reduce(function(sum, row) { return sum + (row.ms || 0); }, 0);
        var avgMs = rows.length ? Math.round(totalMs / rows.length) : 0;
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
            tempo_medio_ms: avgMs
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

    function addRowsFromQrTexts(qrTexts, filePath) {
        var keys = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I1', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'N', 'O', 'Q', 'R'];
        var added = 0;
        (qrTexts || []).forEach(function(qrText) {
            var trimmed = (qrText || '').trim();
            if (!trimmed) {
                return;
            }
            var qrData = extractQR(trimmed);
            var hasContent = keys.some(function(key) {
                return (qrData[key] || '').toString().trim() !== '';
            });
            if (!hasContent) {
                return;
            }
            var row = keys.map(function(key) {
                var value = qrData[key] || '';
                if (key === 'F') {
                    value = formatDate(value);
                }
                return value;
            });
            var actions = '<button type="button" class="btn btn-xs btn-danger delete-row" data-file="' + filePath + '"><i class="fa fa-trash"></i></button> ' +
                '<a href="' + filePath + '" target="_blank" class="btn btn-xs btn-secondary"><i class="fa fa-file-pdf-o"></i></a>';
            row.push(actions);
            table.row.add(row).draw(false);
            added += 1;
            syncEntity(qrData.A || '', 'emitter', qrData.B || '');
        });

        if (added && table.rows().data().length) {
            showImportButtons();
        }
        return added;
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
        updateManualPageLabel();
        updateManualQueueInfo();
        if (manualModal) {
            manualModal.show();
        }
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
        manualActive = null;
        manualPage = 1;
        manualPageCount = 1;
        applyManualZoom(1);
        resetManualSelection();
        setManualError('');
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
        if (!manualActive) {
            return;
        }
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
            if (!manualActive) {
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
                        manualSuccessItem.state = 'manual_success';
                        debugStats.manualSuccess += 1;
                    }
                }
                notifySuccess('QR Code lido manualmente com sucesso.');
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

    if (manualModalEl) {
        manualModalEl.addEventListener('hidden.bs.modal', function() {
            finishManualQueueItem();
        });
    }

    var dz = new Dropzone('#multi-upload', {
        url: 'contabilidade/upload-handler.php',
        acceptedFiles: 'application/pdf',
        parallelUploads: 1,
        dictDefaultMessage: 'Arraste e solte os ficheiros aqui ou clique para selecionar'
    });

    dz.on('sending', function(file, xhr, formData) {
        if (debugEnabled) {
            if (!debugStats.batchStartedAt) {
                debugStats.batchStartedAt = performance.now();
            }
            var item = ensureDebugFile(file);
            item.startedAt = performance.now();
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
        }

        var added = addRowsFromQrTexts(data.qr_texts || [], data.file || '');
        if (added) {
            setDropzoneQrState(file, 'auto');
            if (debugEnabled) {
                var autoItem = ensureDebugFile(file);
                autoItem.finishedAt = performance.now();
                autoItem.ms = Math.max(0, Math.round(autoItem.finishedAt - autoItem.startedAt));
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
                data: { csrf_token: csrfInput.value },
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
            errorItem.state = 'request_error';
            debugStats.hardFailures += 1;
        }
        uploadProcessingCount = Math.max(0, uploadProcessingCount - 1);
    });

    dz.on('queuecomplete', function() {
        if (table.rows().data().length) {
            showImportButtons();
        }
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
            row.remove().draw();
            removeDropzoneFileByServerPath(filePath);
        });
    });

    function handleImport(type) {
        var nodes = table.rows().nodes().toArray();
        if (!nodes.length) {
            showAlert('Não há dados para importar');
            return;
        }
        var keys = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I1', 'I3', 'I4', 'I5', 'I6', 'I7', 'I8', 'N', 'O', 'Q', 'R'];
        var payload = nodes.map(function(node) {
            var data = table.row(node).data() || [];
            var obj = {};
            for (var i = 0; i < keys.length; i += 1) {
                obj[keys[i]] = data[i] || '';
            }
            obj.filename = $(node).find('.delete-row').data('file') || '';
            return obj;
        });
        fetch('contabilidade/upload.php?action=import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rows: payload, import_type: type, csrf_token: csrfInput.value })
        })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            updateCsrfToken(res);
            if (res.success) {
                notifySuccess('Importação concluída');
                if (importBtn) {
                    importBtn.style.display = 'none';
                }
                if (importComprasBtn) {
                    importComprasBtn.style.display = 'none';
                }
                table.clear().draw();
                dz.removeAllFiles(true);
            } else {
                showAlert(res.error || 'Falha na importação');
            }
        })
        .catch(function() {
            showAlert('Falha na importação');
        });
    }

    if (importBtn) {
        importBtn.addEventListener('click', function() { handleImport(1); });
    }
    if (importComprasBtn) {
        importComprasBtn.addEventListener('click', function() { handleImport(2); });
    }
});
