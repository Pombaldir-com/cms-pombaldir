window.addEventListener('load', function() {
    function normalizeNoticeType(type) {
        var normalized = typeof type === 'string' ? type.trim().toLowerCase() : '';
        switch (normalized) {
            case 'success':
                return 'success';
            case 'warning':
            case 'warn':
                return 'warning';
            case 'danger':
            case 'error':
                return 'danger';
            case 'info':
            case 'notice':
                return 'info';
            default:
                return 'info';
        }
    }

    function triggerPNotify(typeOrOptions, maybeOptions) {
        var options = {};
        if (typeof typeOrOptions === 'string') {
            options = typeof maybeOptions === 'object' && maybeOptions !== null ? Object.assign({}, maybeOptions) : {};
            options.type = normalizeNoticeType(typeOrOptions);
        } else if (typeOrOptions && typeof typeOrOptions === 'object') {
            options = Object.assign({}, typeOrOptions);
            if (options.type) {
                options.type = normalizeNoticeType(options.type);
            }
        } else {
            return false;
        }

        if (!options.type) {
            options.type = 'info';
        }

        if (!window.PNotify) {
            return false;
        }
        if (typeof window.PNotify.alert === 'function') {
            window.PNotify.alert(options);
            return true;
        }
        if (typeof window.PNotify === 'function') {
            window.PNotify(options);
            return true;
        }
        return false;
    }

    function isDomElement(value) {
        return !!(value && typeof value === 'object' && value.nodeType === 1);
    }

    function installResizeObserverElementGuard() {
        if (typeof window.ResizeObserver !== 'function') {
            return;
        }
        if (window.ResizeObserver.__classificationObserveGuardInstalled) {
            return;
        }
        var observe = window.ResizeObserver.prototype && window.ResizeObserver.prototype.observe;
        if (typeof observe !== 'function') {
            return;
        }
        window.ResizeObserver.prototype.observe = function(target) {
            if (!isDomElement(target)) {
                return;
            }
            return observe.apply(this, arguments);
        };
        window.ResizeObserver.__classificationObserveGuardInstalled = true;
    }

    function showNotice(type, message) {
        var normalizedType = normalizeNoticeType(type);
        var text = typeof message === 'string' && message.trim() !== '' ? message.trim() : '';
        var noticeDelay = 10000;
        if (text === '') {
            text = normalizedType === 'danger' ? 'Ocorreu um erro' : 'Operação concluída';
        }
        var title = 'Informação';
        var icon = 'info';
        if (normalizedType === 'success') {
            title = 'Sucesso';
            icon = 'success';
        } else if (normalizedType === 'danger') {
            title = 'Erro';
            icon = 'error';
        } else if (normalizedType === 'warning') {
            title = 'Aviso';
            icon = 'warning';
        }
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                icon: icon,
                title: title,
                html: '<div style="white-space: pre-line; text-align: left;">' + escapeHtml(text) + '</div>',
                timer: noticeDelay,
                timerProgressBar: true
            });
            return;
        }
        if (triggerPNotify({
            title: title,
            text: text,
            type: normalizedType,
            styling: 'bootstrap3',
            delay: noticeDelay
        })) {
            return;
        }
        alert(text);
    }

    function showError(message) {
        showNotice('danger', message);
    }

    function showSuccess(message) {
        showNotice('success', message);
    }

    function resolveNoticeTypeFromResponse(res) {
        var sources = [res];
        if (res && res.service_payload) {
            sources.push(res.service_payload);
        }
        if (res && res.service_response) {
            sources.push(res.service_response);
        }
        if (res && Array.isArray(res.batches)) {
            res.batches.forEach(function(batch) {
                if (!batch || typeof batch !== 'object') {
                    return;
                }
                sources.push(batch);
                if (batch.service_payload) {
                    sources.push(batch.service_payload);
                }
                if (batch.service_response) {
                    sources.push(batch.service_response);
                }
            });
        }
        var resolvedType = '';
        var resolvedPriority = -1;
        var typePriority = {
            success: 0,
            info: 1,
            warning: 2,
            danger: 3
        };
        for (var i = 0; i < sources.length; i += 1) {
            var source = sources[i];
            if (source && typeof source === 'object' && source.type) {
                var normalizedType = normalizeNoticeType(source.type);
                var priority = Object.prototype.hasOwnProperty.call(typePriority, normalizedType) ? typePriority[normalizedType] : -1;
                if (priority > resolvedPriority) {
                    resolvedPriority = priority;
                    resolvedType = normalizedType;
                }
            }
        }
        if (resolvedType !== '') {
            return resolvedType;
        }
        if (res && typeof res.success !== 'undefined') {
            return res.success ? 'success' : 'danger';
        }
        return 'success';
    }

    installResizeObserverElementGuard();

    function fetchJson(url, options) {
        var fetchOptions = options ? Object.assign({}, options) : {};
        if (!fetchOptions.credentials) {
            fetchOptions.credentials = 'same-origin';
        }
        if (fetchOptions.headers) {
            fetchOptions.headers = Object.assign({}, fetchOptions.headers);
        }
        return fetch(url, fetchOptions).then(function(res) {
            var status = typeof res.status === 'number' ? res.status : null;
            var statusText = typeof res.statusText === 'string' ? res.statusText.trim() : '';
            return res.text().then(function(text) {
                var trimmed = typeof text === 'string' ? text.trim() : '';
                if (!trimmed) {
                    var httpInfo = '';
                    if (status !== null) {
                        httpInfo = ' (HTTP ' + status + (statusText ? ' ' + statusText : '') + ')';
                    }
                    throw new Error('O webservice de contabilidade devolveu uma resposta vazia' + httpInfo + '.');
                }
                try {
                    return JSON.parse(trimmed);
                } catch (e) {
                    if (trimmed) {
                        throw new Error(trimmed);
                    }
                    throw new Error('Resposta inválida do servidor' + (status !== null ? ' (HTTP ' + status + ')' : ''));
                }
            });
        });
    }

    function debugJson(label, value) {
        if (!window.console) {
            return;
        }
        var prefix = '[Classificação] ' + label;
        var logFn = typeof console.log === 'function' ? console.log : console.debug;
        try {
            var safe = JSON.parse(JSON.stringify(value));
            logFn.call(console, prefix, safe);
        } catch (err) {
            logFn.call(console, prefix, value);
        }
    }

    function collectErrorMessages(candidate, target, visited) {
        if (candidate === null || candidate === undefined) {
            return;
        }
        if (!Array.isArray(target)) {
            return;
        }
        if (!Array.isArray(visited)) {
            visited = [];
        }
        if (typeof candidate === 'string') {
            var trimmedMessage = candidate.trim();
            if (trimmedMessage && target.indexOf(trimmedMessage) === -1) {
                target.push(trimmedMessage);
            }
            return;
        }
        if (Array.isArray(candidate)) {
            for (var i = 0; i < candidate.length; i += 1) {
                collectErrorMessages(candidate[i], target, visited);
            }
            return;
        }
        if (typeof candidate === 'object') {
            if (visited.indexOf(candidate) !== -1) {
                return;
            }
            visited.push(candidate);
            var prioritizedKeys = ['error', 'errors', 'mensagem', 'message', 'msg', 'mensagem_erro', 'descricao', 'descricao_erro', 'detalhes'];
            for (var j = 0; j < prioritizedKeys.length; j += 1) {
                var key = prioritizedKeys[j];
                if (Object.prototype.hasOwnProperty.call(candidate, key)) {
                    collectErrorMessages(candidate[key], target, visited);
                }
            }
            var keys = Object.keys(candidate);
            for (var k = 0; k < keys.length; k += 1) {
                var objectKey = keys[k];
                if (prioritizedKeys.indexOf(objectKey) !== -1) {
                    continue;
                }
                collectErrorMessages(candidate[objectKey], target, visited);
            }
        }
    }

    function extractErrorMessages(responsePayload) {
        var messages = [];
        var visited = [];
        if (!responsePayload || typeof responsePayload !== 'object') {
            collectErrorMessages(responsePayload, messages, visited);
            return messages;
        }
        var directFields = ['error', 'errors', 'mensagem', 'message', 'msg', 'mensagem_erro', 'descricao', 'descricao_erro', 'detalhes'];
        for (var i = 0; i < directFields.length; i += 1) {
            var field = directFields[i];
            if (Object.prototype.hasOwnProperty.call(responsePayload, field)) {
                collectErrorMessages(responsePayload[field], messages, visited);
            }
        }
        var nestedContainers = ['resultado', 'service_payload', 'service_response'];
        for (var j = 0; j < nestedContainers.length; j += 1) {
            var container = nestedContainers[j];
            if (Object.prototype.hasOwnProperty.call(responsePayload, container)) {
                collectErrorMessages(responsePayload[container], messages, visited);
            }
        }
        return messages;
    }

    function normalizeCostCenterOptionRows(items) {
        if (!Array.isArray(items)) {
            return [];
        }
        var seen = {};
        var normalized = [];
        items.forEach(function(item) {
            if (!item || typeof item !== 'object') {
                return;
            }
            var code = String(item.code || item.strConta || '').trim();
            if (!code || seen[code]) {
                return;
            }
            seen[code] = true;
            var description = String(item.description || item.strDescricao || '').trim();
            var label = String(item.label || '').trim();
            if (!label) {
                label = description ? (code + ' - ' + description) : code;
            }
            normalized.push({
                code: code,
                description: description,
                label: label
            });
        });
        return normalized;
    }

    function setCostCenterFieldOptions(field, selectedValue) {
        if (!field) {
            return;
        }
        var value = selectedValue === null || selectedValue === undefined ? '' : String(selectedValue).trim();
        var options = Array.isArray(currentCostCenterOptions) ? currentCostCenterOptions : [];
        var html = '<option value="">Selecione o centro de custo</option>';
        options.forEach(function(option) {
            var code = String(option.code || '').trim();
            if (!code) {
                return;
            }
            var label = String(option.label || code).trim();
            html += '<option value="' + escapeHtml(code) + '">' + escapeHtml(label) + '</option>';
        });
        if (value && !options.some(function(option) { return String(option.code || '').trim() === value; })) {
            html += '<option value="' + escapeHtml(value) + '">' + escapeHtml(value) + ' (atual)</option>';
        }
        field.innerHTML = html;
        field.value = value;
    }

    function refreshCostCenterFields() {
        getRateKeys().forEach(function(rate) {
            var info = rateInputs[rate];
            if (!info || !info.costCenter) {
                return;
            }
            var currentValue = currentCostCenters[rate] || info.costCenter.value || '';
            setCostCenterFieldOptions(info.costCenter, currentValue);
        });
    }

    function loadCostCenterCatalogForDocument(dbValue, docDateValue, opts) {
        var options = opts || {};
        var database = String(dbValue || '').trim();
        var docDate = String(docDateValue || '').trim();
        var requestKey = [database, docDate].join('|');
        if (!database) {
            currentCostCenterOptions = [];
            currentCostCenterContextKey = '';
            refreshCostCenterFields();
            return Promise.resolve([]);
        }
        if (!options.forceRefresh && currentCostCenterContextKey === requestKey && currentCostCenterOptions.length > 0) {
            refreshCostCenterFields();
            return Promise.resolve(currentCostCenterOptions);
        }

        var query = new URLSearchParams({
            db: database
        });
        if (docDate) {
            query.set('doc_date', docDate);
        }
        return fetchJson('contabilidade/classificacao-importacao/cost-centers?' + query.toString())
            .then(function(res) {
                if (res && res.csrf_token && csrfInput) {
                    csrfInput.value = res.csrf_token;
                }
                if (!res || !res.success) {
                    throw new Error((res && (res.error || res.message)) || 'Falha ao carregar centros de custo.');
                }
                currentCostCenterOptions = normalizeCostCenterOptionRows(res.items || []);
                currentCostCenterContextKey = requestKey;
                refreshCostCenterFields();
                return currentCostCenterOptions;
            })
            .catch(function(err) {
                currentCostCenterOptions = [];
                currentCostCenterContextKey = '';
                refreshCostCenterFields();
                if (!options.silent) {
                    showError(err && err.message ? err.message : 'Falha ao carregar centros de custo.');
                }
                return [];
            });
    }

    function buildCostCenterSelectHtml(selectedValue, cssClass) {
        var selected = selectedValue === null || selectedValue === undefined ? '' : String(selectedValue).trim();
        var className = cssClass ? String(cssClass) : 'form-control cost-center-input';
        var html = '<select class="' + escapeHtml(className) + '">';
        html += '<option value="">Selecione o centro de custo</option>';
        var hasSelected = false;
        (currentCostCenterOptions || []).forEach(function(option) {
            var code = String(option.code || '').trim();
            if (!code) {
                return;
            }
            var label = String(option.label || code).trim();
            var isSelected = selected !== '' && selected === code;
            if (isSelected) {
                hasSelected = true;
            }
            html += '<option value="' + escapeHtml(code) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
        });
        if (selected !== '' && !hasSelected) {
            html += '<option value="' + escapeHtml(selected) + '" selected>' + escapeHtml(selected) + ' (atual)</option>';
        }
        html += '</select>';
        return html;
    }

    var csrfInput = document.getElementById('csrf_token');
    var importTypeInput = document.getElementById('import_type');
    var viewModeInput = document.getElementById('view_mode');
    var importType = importTypeInput ? parseInt(importTypeInput.value, 10) : 1;
    var viewMode = viewModeInput ? String(viewModeInput.value || '').trim().toLowerCase() : '';
    if (isNaN(importType)) {
        importType = 1;
    }
    var isClassificationOnlyView = importType === 1 && viewMode !== 'import';
    var importTypeAllowsImport = importType === 1 || importType === 2;

    function getSelectedCompanyNif() {
        var el = document.getElementById('company-filter');
        if (!el) {
            return '';
        }
        return String(el.value || '').trim();
    }
    var importCtbRelativeUrl = 'contabilidade/classificacao-importacao/import-ctb';
    var erpBaseCompany = window.erpBaseCompany ? String(window.erpBaseCompany).trim() : '';
    var erpDefaultDatabase = window.erpDefaultDatabase ? String(window.erpDefaultDatabase).trim() : '';
    var classificacaoImportDebugMode = window.classificacaoImportDebugMode === true;
    var accountingFuelRubricCodeMap = {};
    var currentCostCenterOptions = [];
    var currentCostCenterContextKey = '';

    function normalizeAccountingRubricCodeValue(value) {
        var string = String(value || '').trim();
        if (!string) {
            return '';
        }
        if (typeof string.normalize === 'function') {
            string = string.normalize('NFC');
        }
        return string.replace(/\s+/g, ' ').toUpperCase();
    }

    if (Array.isArray(window.accountingFuelRubricCodes)) {
        window.accountingFuelRubricCodes.forEach(function(code) {
            var normalized = normalizeAccountingRubricCodeValue(code);
            if (normalized) {
                accountingFuelRubricCodeMap[normalized] = true;
            }
        });
    }

    function isFuelRubricCode(code) {
        var normalized = normalizeAccountingRubricCodeValue(code);
        return normalized !== '' && Object.prototype.hasOwnProperty.call(accountingFuelRubricCodeMap, normalized);
    }

    function updateCsrfTokenFromResponse(res) {
        if (res && res.csrf_token && csrfInput) {
            csrfInput.value = res.csrf_token;
        }
    }

    function isInvalidCsrfResponse(res) {
        var messages = extractErrorMessages(res);
        if (!messages.length) {
            return false;
        }
        return messages.some(function(message) {
            var normalized = String(message || '').trim().toLowerCase();
            return normalized.indexOf('token invalido') !== -1 || normalized.indexOf('token csrf invalido') !== -1;
        });
    }

    function postAssistantRequest(body, hasRetried) {
        var requestBody = body && typeof body === 'object' ? Object.assign({}, body) : {};
        requestBody.csrf_token = csrfInput ? csrfInput.value : '';
        return fetchJson('assistant-handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(requestBody)
        }).then(function(res) {
            updateCsrfTokenFromResponse(res);
            if (!hasRetried && isInvalidCsrfResponse(res) && csrfInput && csrfInput.value) {
                return postAssistantRequest(body, true);
            }
            return res;
        });
    }

    function getCurrentEmitterTypeForCorrection() {
        return emitterTypeSelect ? String(emitterTypeSelect.value || '').trim() || 'normal' : 'normal';
    }

    function getClassificationCorrectionExamples() {
        var emitterType = getCurrentEmitterTypeForCorrection();
        if (emitterType === 'insurance') {
            return [
                'Seguros sem LigacaoCteTipoDoc: conta geral 626312 e total 1205',
                'Seguradora com imposto do selo: criar 1 linha unica, base = total do documento e sem Conta IVA',
                'Medis/Multicare/Fidelidade: tratar como Seguradora e nunca como Banco'
            ];
        }
        if (emitterType === 'bank') {
            return [
                'Banco com CAPITAL cria linha adicional',
                'Banco com CAPITAL, JUROS e COMISSOES: CAPITAL usa conta ERP, JUROS = 6911, COMISSOES = 698812',
                'Banco sem separar juros/comissoes no OCR: usar 1 linha unica com taxa 0%'
            ];
        }
        return [
            'Fornecedor recorrente sem historico: usar a conta geral habitual deste tipo de despesa',
            'Se a taxa for 0% e nao houver Conta IVA, manter a linha sem conta IVA',
            'Nao usar contas de Banco ou Seguradora fora desses contextos'
        ];
    }

    function buildCorrectionSuggestionHtml(examples) {
        var html = '<div class="text-start">';
        html += '<p class="mb-2">Descreva a regra de forma curta e reutilizável. Exemplos:</p>';
        html += '<div class="mb-3">';
        examples.forEach(function(example) {
            html += '<div class="text-muted small mb-2">'
                + escapeHtml(example)
                + '</div>';
        });
        html += '</div>';
        html += '<textarea id="aiCorrectionSuggestionText" class="form-control" rows="9" maxlength="2000" style="min-height:220px; width:100%; resize:vertical;" placeholder="Ex.: Seguros sem LigacaoCteTipoDoc: conta geral 626312 e total 1205"></textarea>';
        html += '<small class="text-muted d-block mt-2">A sugestão fica memorizada por grupo de entidade para melhorar futuras sugestões. Limite: 2000 caracteres.</small>';
        html += '</div>';
        return html;
    }

    function buildEntityPairAiInstructionsHtml(examples, existingText) {
        var html = '<div class="text-start">';
        html += '<p class="mb-2">Estas instruções aplicam-se apenas a este emitente/adquirente.</p>';
        html += '<div class="d-flex flex-column gap-2 mb-3">';
        examples.forEach(function(example, index) {
            html += '<button type="button" class="btn btn-sm btn-outline-secondary text-start ai-pair-instruction-example-btn" data-example-index="' + index + '">'
                + escapeHtml(example)
                + '</button>';
        });
        html += '</div>';
        html += '<textarea id="entityPairAiInstructionsText" class="form-control" rows="10" style="min-height:240px; width:100%; resize:vertical;" placeholder="Escreva aqui as regras personalizadas para este emitente/adquirente...">'
            + escapeHtml(existingText || '')
            + '</textarea>';
        html += '<small class="text-muted d-block mt-2">Estas instruções são lidas juntamente com as instruções gerais de Definições &gt; AI.</small>';
        html += '</div>';
        return html;
    }

    function saveClassificationCorrectionSuggestion(instruction) {
        if (!instruction) {
            return;
        }
        postAssistantRequest({
            action: 'save_classification_correction',
            instruction: instruction,
            context: {
                emitter_type: getCurrentEmitterTypeForCorrection(),
                emitter_nif: currentBtn ? String(currentBtn.getAttribute('data-emitter-nif') || '').trim() : '',
                acquirer_nif: currentBtn ? String(currentBtn.getAttribute('data-acquirer') || '').trim() : '',
                doc_type: currentBtn ? String(currentBtn.getAttribute('data-doctype') || '').trim() : ''
            },
            session_id: 'ai_suggest_accounts'
        }).then(function(res) {
            if (!res || res.success === false) {
                showError((res && (res.message || res.error)) || 'Não foi possível memorizar a correção.');
                return;
            }
            showSuccess((res && res.message) || 'Correção memorizada para próximas sugestões.');
        }).catch(function(err) {
            showError((err && err.message) || 'Erro ao memorizar a correção.');
        });
    }

    function saveEntityPairAiInstructions(instructions) {
        if (!currentBtn) {
            return;
        }
        var body = new URLSearchParams({
            id: currentBtn.getAttribute('data-id') || '',
            A: currentBtn.getAttribute('data-emitter') || '',
            B: currentBtn.getAttribute('data-acquirer') || '',
            emitter_nif: currentBtn.getAttribute('data-emitter-nif') || '',
            acquirer_nif: currentBtn.getAttribute('data-acquirer') || '',
            instructions: instructions || '',
            csrf_token: csrfInput ? csrfInput.value : ''
        });
        fetchJson('contabilidade/save-analysis.php?action=save_entity_ai_instructions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function(res) {
            if (res && res.csrf_token && csrfInput) {
                csrfInput.value = res.csrf_token;
            }
            if (!res || res.success === false) {
                showError((res && (res.error || res.message)) || 'Não foi possível guardar as Instruções IA.');
                return;
            }
            currentEntityPairAiInstructions = String((res && res.instructions) || '').trim();
            showSuccess((res && res.message) || 'Instruções IA guardadas.');
        }).catch(function(err) {
            showError((err && err.message) || 'Erro ao guardar as Instruções IA.');
        });
    }

    function openEntityPairAiInstructionsDialog() {
        if (!currentBtn) {
            showNotice('warning', 'Selecione um documento antes de editar Instruções IA.');
            return;
        }
        var examples = getClassificationCorrectionExamples();
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                title: 'Instruções IA',
                html: buildEntityPairAiInstructionsHtml(examples, currentEntityPairAiInstructions),
                width: 960,
                showCancelButton: true,
                confirmButtonText: 'Guardar',
                cancelButtonText: 'Cancelar',
                didOpen: function() {
                    var textarea = document.getElementById('entityPairAiInstructionsText');
                    var buttons = document.querySelectorAll('.ai-pair-instruction-example-btn');
                    buttons.forEach(function(button, index) {
                        button.addEventListener('click', function() {
                            if (!textarea) {
                                return;
                            }
                            var prefix = textarea.value && String(textarea.value).trim() !== '' ? textarea.value.replace(/\s+$/, '') + '\n' : '';
                            textarea.value = prefix + (examples[index] || '');
                            textarea.focus();
                        });
                    });
                },
                preConfirm: function() {
                    var textarea = document.getElementById('entityPairAiInstructionsText');
                    return textarea ? String(textarea.value || '').trim() : '';
                }
            }).then(function(result) {
                if (!result || !result.isConfirmed) {
                    return;
                }
                saveEntityPairAiInstructions(String(result.value || '').trim());
            });
            return;
        }
        var value = window.prompt('Instruções IA para este emitente/adquirente:', currentEntityPairAiInstructions || '');
        if (value !== null) {
            saveEntityPairAiInstructions(String(value || '').trim());
        }
    }

    var correctionSuggestModalEl = null;
    var correctionSuggestModalBody = null;
    var correctionSuggestModalTextarea = null;
    var correctionSuggestModalSaveBtn = null;
    var correctionSuggestModal = null;
    var correctionSuggestModalExamples = [];

    function ensureCorrectionSuggestModal() {
        if (correctionSuggestModalEl) {
            return true;
        }
        if (!document.body || !window.bootstrap || typeof window.bootstrap.Modal !== 'function') {
            return false;
        }

        correctionSuggestModalEl = document.createElement('div');
        correctionSuggestModalEl.className = 'modal fade';
        correctionSuggestModalEl.id = 'correctionSuggestModal';
        correctionSuggestModalEl.tabIndex = -1;
        correctionSuggestModalEl.setAttribute('aria-hidden', 'true');
        correctionSuggestModalEl.innerHTML = ''
            + '<div class="modal-dialog modal-lg">'
            + '  <div class="modal-content">'
            + '    <div class="modal-header">'
            + '      <h5 class="modal-title">Sugerir correção</h5>'
            + '      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>'
            + '    </div>'
            + '    <div class="modal-body"></div>'
            + '    <div class="modal-footer">'
            + '      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>'
            + '      <button type="button" class="btn btn-primary" id="correctionSuggestModalSaveBtn">Memorizar</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';

        document.body.appendChild(correctionSuggestModalEl);
        correctionSuggestModalBody = correctionSuggestModalEl.querySelector('.modal-body');
        correctionSuggestModalSaveBtn = correctionSuggestModalEl.querySelector('#correctionSuggestModalSaveBtn');
        correctionSuggestModal = new window.bootstrap.Modal(correctionSuggestModalEl);

        if (correctionSuggestModalSaveBtn) {
            correctionSuggestModalSaveBtn.addEventListener('click', function() {
                var value = correctionSuggestModalTextarea ? String(correctionSuggestModalTextarea.value || '').trim() : '';
                if (!value) {
                    showNotice('warning', 'Indique a correção a memorizar.');
                    return;
                }
                correctionSuggestModal.hide();
                saveClassificationCorrectionSuggestion(value);
            });
        }

        return !!correctionSuggestModalBody;
    }

    function showCorrectionSuggestModal(examples) {
        if (!ensureCorrectionSuggestModal()) {
            return false;
        }
        correctionSuggestModalExamples = Array.isArray(examples) ? examples.slice() : [];
        correctionSuggestModalBody.innerHTML = buildCorrectionSuggestionHtml(correctionSuggestModalExamples);
        correctionSuggestModalTextarea = correctionSuggestModalBody.querySelector('#aiCorrectionSuggestionText');
        if (correctionSuggestModalTextarea) {
            correctionSuggestModalTextarea.style.minHeight = '260px';
            correctionSuggestModalTextarea.style.width = '100%';
            correctionSuggestModalTextarea.style.resize = 'vertical';
        }
        correctionSuggestModal.show();
        return true;
    }

    function openClassificationCorrectionDialog() {
        var examples = getClassificationCorrectionExamples();
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                title: 'Sugerir correção',
                html: buildCorrectionSuggestionHtml(examples),
                width: 920,
                showCancelButton: true,
                confirmButtonText: 'Memorizar',
                cancelButtonText: 'Cancelar',
                didOpen: function() {
                    var textarea = document.getElementById('aiCorrectionSuggestionText');
                    var popup = window.Swal.getPopup ? window.Swal.getPopup() : null;
                    if (popup) {
                        popup.style.maxWidth = '920px';
                    }
                    if (textarea) {
                        textarea.style.minHeight = '220px';
                        textarea.style.width = '100%';
                        textarea.style.resize = 'vertical';
                    }
                },
                preConfirm: function() {
                    var textarea = document.getElementById('aiCorrectionSuggestionText');
                    var value = textarea ? String(textarea.value || '').trim() : '';
                    if (!value) {
                        window.Swal.showValidationMessage('Indique a correção a memorizar.');
                        return false;
                    }
                    return value;
                }
            }).then(function(result) {
                if (!result || !result.isConfirmed || !result.value) {
                    return;
                }
                saveClassificationCorrectionSuggestion(String(result.value || '').trim());
            });
            return;
        }
        if (showCorrectionSuggestModal(examples)) {
            return;
        }
        var fallbackMessage = 'Sugira uma correção para futuras classificações.\n\nExemplos:\n- ' + examples.join('\n- ');
        var value = window.prompt(fallbackMessage, examples[0] || '');
        if (value && String(value).trim()) {
            saveClassificationCorrectionSuggestion(String(value).trim());
        }
    }

    function buildImportCtbUrl() {
        return importCtbRelativeUrl;
    }

    function extractCompanyCodeFromDatabase(database) {
        var normalized = String(database || '').trim();
        if (!normalized) {
            return '';
        }
        var match = normalized.match(/^emp[_-]?(\d+)$/i);
        if (match && match[1]) {
            return String(match[1]).trim();
        }
        return normalized;
    }

    function updateClassifyModalCompanyBadge(companyCode) {
        if (!modalCompanyBadgeEl) {
            return;
        }

        var normalized = String(companyCode || '').trim();
        if (normalized) {
            modalCompanyBadgeEl.textContent = 'EMP: ' + normalized;
            modalCompanyBadgeEl.classList.remove('d-none');
            return;
        }

        modalCompanyBadgeEl.textContent = '';
        modalCompanyBadgeEl.classList.add('d-none');
    }

    var showLineCostCenter = importType === 1;
    var importCtbButton = $('#importCtbButton');
    var importCtbWrapper = $('#importCtbButtonWrapper');
    var importCtbParamInfo = $('<small id="importCtbParamInfo" class="text-muted ms-2"></small>');
    var importCtbButtonLabel = importCtbButton.find('.import-ctb-button-label');
    var acquirerDatabaseModalEl = document.getElementById('acquirerDatabaseModal');
    var acquirerDatabaseModal = acquirerDatabaseModalEl ? new bootstrap.Modal(acquirerDatabaseModalEl) : null;
    var acquirerDatabaseForm = document.getElementById('acquirerDatabaseForm');
    var acquirerDatabaseSelect = document.getElementById('acquirerDatabaseSelect');
    var acquirerDatabaseMessage = document.getElementById('acquirerDatabaseMessage');
    var acquirerDatabaseError = document.getElementById('acquirerDatabaseError');
    var acquirerDatabaseLoadingIndicator = document.getElementById('acquirerDatabaseLoading');
    var confirmAcquirerDatabaseBtn = document.getElementById('confirmAcquirerDatabaseBtn');
    var qrDocTypeMappingModalEl = document.getElementById('qrDocTypeMappingModal');
    var qrDocTypeMappingModal = qrDocTypeMappingModalEl ? new bootstrap.Modal(qrDocTypeMappingModalEl) : null;
    var qrDocTypeMappingForm = document.getElementById('qrDocTypeMappingForm');
    var qrDocTypeMappingMessage = document.getElementById('qrDocTypeMappingMessage');
    var qrDocTypeMappingContainer = document.getElementById('qrDocTypeMappingContainer');
    var qrDocTypeMappingError = document.getElementById('qrDocTypeMappingError');
    var confirmQrDocTypeMappingBtn = document.getElementById('confirmQrDocTypeMappingBtn');
    var acquirerDatabaseOptionsCache = null;
    var pendingImportIds = null;
    var pendingAcquirerEntity = null;
    var acquirerDatabasePending = false;
    var acquirerDatabaseSelectionResolved = false;
    var qrDocTypeMappingPending = false;
    var qrDocTypeMappingResolved = false;
    var pendingQrDocTypeMappingContext = null;
    var readyIdsCache = {
        ids: null,
        fetchedAt: 0,
        promise: null
    };
    var readyIdsCacheTtlMs = 3000;

    function buildClassificationTableStateKey() {
        return ['datatable_state', window.location.pathname, 'classify-table'].join(':');
    }

    function clearClassificationTableState() {
        try {
            if (window.localStorage) {
                window.localStorage.removeItem(buildClassificationTableStateKey());
            }
        } catch (error) {
            // Ignore storage cleanup errors to avoid blocking table rendering.
        }
    }

    clearClassificationTableState();

    var table = $('#classify-table').DataTable({
        serverSide: true,
        processing: true,
        stateSave: false,
        displayStart: 0,
        ajax: function(requestData, callback) {
            var draw = requestData && typeof requestData.draw !== 'undefined' ? requestData.draw : 0;
            var payload = $.extend(true, {}, requestData || {});
            payload.import_type = importType;
            if (viewMode !== '') {
                payload.view_mode = viewMode;
            }
            var selectedCompany = getSelectedCompanyNif();
            if (selectedCompany !== '') {
                payload.company = selectedCompany;
            }
            var queryString = $.param(payload);

            fetchJson('contabilidade/classificacao-importacao/data?' + queryString)
                .then(function(json) {
                    if (!json || typeof json !== 'object') {
                        throw new Error('Resposta inválida do servidor');
                    }

                    if (!Array.isArray(json.data)) {
                        json.data = [];
                    }
                    if (typeof json.recordsTotal !== 'number') {
                        json.recordsTotal = json.data.length;
                    }
                    if (typeof json.recordsFiltered !== 'number') {
                        json.recordsFiltered = json.recordsTotal;
                    }
                    if (typeof json.draw === 'undefined') {
                        json.draw = draw;
                    }

                    if (json.error) {
                        var errorMessage = typeof json.error === 'string' ? json.error : 'Erro ao carregar dados da tabela.';
                        showError(errorMessage);
                        if (typeof window.console !== 'undefined' && typeof window.console.warn === 'function') {
                            window.console.warn('[Classificação] Resposta de listagem com erro', json);
                        }
                    }

                    callback(json);
                })
                .catch(function(err) {
                    var message = err && err.message ? err.message : 'Erro ao carregar dados da tabela.';
                    showError(message);
                    if (typeof window.console !== 'undefined' && typeof window.console.error === 'function') {
                        window.console.error('[Classificação] Falha ao carregar dados da listagem', err);
                    }
                    callback({
                        draw: draw,
                        recordsTotal: 0,
                        recordsFiltered: 0,
                        data: []
                    });
                });
        },
        orderCellsTop: true,
        language: { url: 'vendors/datatables.net/i18n/pt-PT.json' },
        // language.url loads the i18n JSON asynchronously, so DataTables defers
        // building the dom (and its header slots) until it arrives. Wire the
        // header controls in initComplete, once the slots actually exist.
        initComplete: function() {
            wireHeaderControls(this.api());
        },
        dom: "<'row mb-2 align-items-center'" +
                "<'col-sm-12 col-md-1'l>" +
                "<'col-sm-12 col-md-6 classify-company-slot'>" +
                "<'col-sm-12 col-md-2 classify-action-slot'>" +
                "<'col-sm-12 col-md-3'f>" +
             ">" +
             "rt" +
             "<'row mt-2'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>",
        columnDefs: [
            { targets: [ 2, 4, 7, 8 ], visible: false },
            { targets: [0, 1], className: 'text-start' },
            { targets: [9, 10, 11, 12, 13, 14, 15, 16], orderable: false },
            { targets: [ -1, -2 ], orderable: false, searchable: false }
        ]
    });

    // Move the company filter and the global action button into the DataTables
    // header (positioned via the dom above) instead of a separate container.
    // Called from initComplete because the header slots only exist after the
    // (async) language.url JSON has loaded and the dom has been built.
    function wireHeaderControls(dtApi) {
        // DataTables 2.x wraps the table in .dt-container (the old DT 1.x
        // .dataTables_wrapper class no longer exists).
        var $dtWrapper = $('#classify-table').closest('.dt-container');
        $('#companyFilterWrapper').appendTo($dtWrapper.find('.classify-company-slot'));
        $('#importCtbButtonWrapper').appendTo($dtWrapper.find('.classify-action-slot'));

        var $companyFilter = $('#company-filter');
        if ($companyFilter.length && typeof $companyFilter.select2 === 'function') {
            $companyFilter.select2({
                width: '100%',
                placeholder: 'Todas as empresas',
                allowClear: true
            });
        }
        $companyFilter.on('change', function() {
            dtApi.ajax.reload();
        });
        $('#companyFilterWrapper, #importCtbButtonWrapper').removeClass('dt-hidden-until-ready');
    }

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

    function resolveCurrentImportParams() {
        var emp = erpBaseCompany !== '' ? erpBaseCompany : 'n/d';
        var db = 'auto';
        if (pendingAcquirerEntity && typeof pendingAcquirerEntity.erp_database === 'string') {
            var selectedDb = pendingAcquirerEntity.erp_database.trim();
            if (selectedDb !== '') {
                db = selectedDb;
            }
        }
        return { emp: emp, db: db };
    }

    function renderImportParamInfo() {
        // The ERP parameters (EMP/db) are a debugging aid only and must not be
        // shown next to the button, so keep the element out of the DOM. In debug
        // mode the values are logged to the console instead.
        importCtbParamInfo.detach();
        if (!importCtbButton.length || !importTypeAllowsImport || !classificacaoImportDebugMode) {
            return;
        }
        var params = resolveCurrentImportParams();
        if (typeof window.console !== 'undefined' && typeof window.console.debug === 'function') {
            window.console.debug('[Classificação] ERP: EMP=' + params.emp + ' | db=' + params.db);
        }
    }

    function normalizeDatabaseOptions(payload) {
        var result = [];
        var seenValues = {};
        if (!payload) {
            return result;
        }

        var candidates = [];
        if (Array.isArray(payload)) {
            candidates = payload;
        } else if (typeof payload === 'object') {
            if (Array.isArray(payload.options)) {
                candidates = payload.options;
            } else if (Array.isArray(payload.data)) {
                candidates = payload.data;
            } else if (Array.isArray(payload.result)) {
                candidates = payload.result;
            } else if (Array.isArray(payload.list)) {
                candidates = payload.list;
            } else {
                Object.keys(payload).forEach(function(key) {
                    if (/^\d+$/.test(key) && payload[key] && typeof payload[key] === 'object') {
                        candidates.push(payload[key]);
                    }
                });
                if (!candidates.length) {
                    candidates = Object.keys(payload).map(function(key) {
                        return payload[key];
                    }).filter(function(item) {
                        return item && typeof item === 'object';
                    });
                }
            }
        }

        candidates.forEach(function(candidate) {
            if (!candidate || typeof candidate !== 'object') {
                return;
            }

            var normalized = {};
            Object.keys(candidate).forEach(function(key) {
                if (typeof key === 'string') {
                    normalized[key.toLowerCase()] = candidate[key];
                }
            });

            var optionValue = '';
            if (Object.prototype.hasOwnProperty.call(candidate, 'value') && String(candidate.value).trim() !== '') {
                optionValue = String(candidate.value).trim();
            } else if (Object.prototype.hasOwnProperty.call(normalized, 'value') && String(normalized.value).trim() !== '') {
                optionValue = String(normalized.value).trim();
            }

            if (optionValue === '' || Object.prototype.hasOwnProperty.call(seenValues, optionValue)) {
                return;
            }

            var optionLabel = '';
            var labelKeys = ['db', 'label', 'descricao', 'description', 'nome', 'name'];
            for (var i = 0; i < labelKeys.length; i += 1) {
                var labelKey = labelKeys[i];
                if (Object.prototype.hasOwnProperty.call(candidate, labelKey) && String(candidate[labelKey]).trim() !== '') {
                    optionLabel = String(candidate[labelKey]).trim();
                    break;
                }
                if (Object.prototype.hasOwnProperty.call(normalized, labelKey) && String(normalized[labelKey]).trim() !== '') {
                    optionLabel = String(normalized[labelKey]).trim();
                    break;
                }
            }

            if (optionLabel === '') {
                optionLabel = optionValue;
            }

            var optionId = '';
            var idKeys = ['id', 'intcodigo', 'codigo', 'code'];
            for (var j = 0; j < idKeys.length; j += 1) {
                var idKey = idKeys[j];
                if (Object.prototype.hasOwnProperty.call(candidate, idKey) && String(candidate[idKey]).trim() !== '') {
                    optionId = String(candidate[idKey]).trim();
                    break;
                }
                if (Object.prototype.hasOwnProperty.call(normalized, idKey) && String(normalized[idKey]).trim() !== '') {
                    optionId = String(normalized[idKey]).trim();
                    break;
                }
            }

            seenValues[optionValue] = true;
            result.push({
                value: optionValue,
                label: optionLabel,
                id: optionId
            });
        });

        return result;
    }

    function fetchAcquirerDatabaseOptions() {
        if (Array.isArray(acquirerDatabaseOptionsCache) && acquirerDatabaseOptionsCache.length > 0) {
            return Promise.resolve(acquirerDatabaseOptionsCache.slice());
        }

        return fetchJson('contabilidade/listDBemp')
            .then(function(res) {
                if (!res || typeof res !== 'object') {
                    throw new Error('Resposta inválida do serviço de contabilidade.');
                }
                if (res.success === false) {
                    throw new Error(res.error || 'Não foi possível obter a lista de bases de dados.');
                }

                var payload = res;
                if (Array.isArray(res.options)) {
                    payload = res.options;
                } else if (Array.isArray(res.data)) {
                    payload = res.data;
                }

                var options = normalizeDatabaseOptions(payload);
                if (!options.length) {
                    throw new Error('Nenhuma base de dados disponível.');
                }

                acquirerDatabaseOptionsCache = options;
                debugJson('Opções de base de dados do adquirente', options);
                return options.slice();
            });
    }

    function populateAcquirerDatabaseOptions(options) {
        if (!acquirerDatabaseSelect) {
            return;
        }

        acquirerDatabaseSelect.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = 'Selecione uma base de dados';
        placeholder.disabled = true;
        placeholder.selected = true;
        acquirerDatabaseSelect.appendChild(placeholder);

        options.forEach(function(option) {
            if (!option || typeof option.value === 'undefined') {
                return;
            }
            var opt = document.createElement('option');
            opt.value = String(option.value);
            if (option.id) {
                opt.setAttribute('data-id', String(option.id));
            }
            var label = option.label || option.value;
            var text = String(label);
            if (option.label && option.label !== option.value) {
                text = String(option.label) + ' (' + option.value + ')';
            }
            opt.textContent = text;
            acquirerDatabaseSelect.appendChild(opt);
        });
    }

    function resetAcquirerDatabaseModal() {
        if (acquirerDatabaseError) {
            acquirerDatabaseError.classList.add('d-none');
            acquirerDatabaseError.textContent = '';
        }
        if (acquirerDatabaseSelect) {
            acquirerDatabaseSelect.value = '';
            acquirerDatabaseSelect.classList.remove('is-invalid');
            acquirerDatabaseSelect.disabled = false;
        }
        if (acquirerDatabaseLoadingIndicator) {
            acquirerDatabaseLoadingIndicator.classList.add('d-none');
        }
        if (confirmAcquirerDatabaseBtn) {
            confirmAcquirerDatabaseBtn.disabled = false;
        }
    }

    function showAcquirerDatabaseModal(entity) {
        if (!acquirerDatabaseModal) {
            acquirerDatabasePending = false;
            updateImportButtonState();
            showError('Não foi possível apresentar a seleção de base de dados.');
            return;
        }

        resetAcquirerDatabaseModal();
        acquirerDatabaseSelectionResolved = false;

        if (acquirerDatabaseSelect) {
            acquirerDatabaseSelect.disabled = true;
        }
        if (confirmAcquirerDatabaseBtn) {
            confirmAcquirerDatabaseBtn.disabled = true;
        }
        if (acquirerDatabaseLoadingIndicator) {
            acquirerDatabaseLoadingIndicator.classList.remove('d-none');
        }

        if (acquirerDatabaseMessage) {
            var messageParts = [];
            if (entity && typeof entity.display_name === 'string' && entity.display_name.trim() !== '') {
                messageParts.push(entity.display_name.trim());
            } else if (entity && typeof entity.name === 'string' && entity.name.trim() !== '') {
                messageParts.push(entity.name.trim());
            }
            if (entity && typeof entity.nif === 'string' && entity.nif.trim() !== '') {
                messageParts.push('NIF ' + entity.nif.trim());
            }
            var message = 'Selecione a base de dados do adquirente.';
            if (messageParts.length) {
                message = 'Selecione a base de dados do adquirente: ' + messageParts.join(' - ');
            }
            acquirerDatabaseMessage.textContent = message;
        }

        acquirerDatabaseModal.show();

        fetchAcquirerDatabaseOptions()
            .then(function(options) {
                populateAcquirerDatabaseOptions(options);
                if (acquirerDatabaseSelect) {
                    acquirerDatabaseSelect.disabled = false;
                }
                if (confirmAcquirerDatabaseBtn) {
                    confirmAcquirerDatabaseBtn.disabled = false;
                }
            })
            .catch(function(err) {
                if (acquirerDatabaseError) {
                    acquirerDatabaseError.textContent = err && err.message ? err.message : 'Falha ao carregar bases de dados.';
                    acquirerDatabaseError.classList.remove('d-none');
                } else {
                    showError(err.message || 'Falha ao carregar bases de dados.');
                }
            })
            .finally(function() {
                if (acquirerDatabaseLoadingIndicator) {
                    acquirerDatabaseLoadingIndicator.classList.add('d-none');
                }
            });
    }

    function ensureAcquirerDatabase(ids) {
        var payload = {
            ids: ids,
            import_type: importType,
            csrf_token: csrfInput ? csrfInput.value : '',
            mode: 'check'
        };
        if (isClassificationOnlyView) {
            payload.allow_classified_flow = 1;
        }
        debugJson('Pedido de validação da base de dados do adquirente', payload);
        return fetchJson('contabilidade/classificacao-importacao/acquirer-database', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        }).then(function(res) {
            debugJson('Resposta de validação da base de dados do adquirente', res);
            if (!res || typeof res !== 'object') {
                throw new Error('Resposta inválida ao validar a base de dados do adquirente.');
            }
            if (res.csrf_token && csrfInput) {
                csrfInput.value = res.csrf_token;
            }
            if (res.success === false) {
                throw new Error(res.error || 'Não foi possível validar o adquirente.');
            }
            return {
                requiresSelection: !!res.requires_selection,
                entity: res.entity || null
            };
        });
    }

    function updateAcquirerDatabase(selectedDatabase) {
        if (!Array.isArray(pendingImportIds) || !pendingImportIds.length) {
            return Promise.reject(new Error('Nenhuma linha seleccionada.'));
        }

        var selectedDatabaseId = '';
        if (acquirerDatabaseSelect && acquirerDatabaseSelect.selectedIndex >= 0) {
            var selectedOption = acquirerDatabaseSelect.options[acquirerDatabaseSelect.selectedIndex];
            if (selectedOption) {
                selectedDatabaseId = String(selectedOption.getAttribute('data-id') || '').trim();
            }
        }

        var payload = {
            ids: pendingImportIds,
            import_type: importType,
            selected_database: selectedDatabase,
            selected_database_id: selectedDatabaseId,
            csrf_token: csrfInput ? csrfInput.value : '',
            mode: 'update'
        };
        if (isClassificationOnlyView) {
            payload.allow_classified_flow = 1;
        }
        debugJson('Pedido de atualização da base de dados do adquirente', payload);

        return fetchJson('contabilidade/classificacao-importacao/acquirer-database', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        }).then(function(res) {
            debugJson('Resposta de atualização da base de dados do adquirente', res);
            if (!res || typeof res !== 'object') {
                throw new Error('Resposta inválida ao guardar a base de dados do adquirente.');
            }
            if (res.csrf_token && csrfInput) {
                csrfInput.value = res.csrf_token;
            }
            if (res.success === false) {
                throw new Error(res.error || 'Não foi possível guardar a base de dados do adquirente.');
            }
            return res;
        });
    }

    function resetQrDocTypeMappingModal() {
        pendingQrDocTypeMappingContext = null;
        if (qrDocTypeMappingForm) {
            qrDocTypeMappingForm.reset();
        }
        if (qrDocTypeMappingContainer) {
            qrDocTypeMappingContainer.innerHTML = '';
        }
        if (qrDocTypeMappingMessage) {
            qrDocTypeMappingMessage.textContent = 'Associe o tipo de documento E-fatura lido no QR ao tipo documental ERP.';
        }
        if (qrDocTypeMappingError) {
            qrDocTypeMappingError.classList.add('d-none');
            qrDocTypeMappingError.textContent = '';
        }
        if (confirmQrDocTypeMappingBtn) {
            confirmQrDocTypeMappingBtn.disabled = false;
        }
    }

    function resolvePendingImportDatabase() {
        if (pendingQrDocTypeMappingContext && typeof pendingQrDocTypeMappingContext.database === 'string' && pendingQrDocTypeMappingContext.database.trim() !== '') {
            return pendingQrDocTypeMappingContext.database.trim();
        }
        if (pendingAcquirerEntity && typeof pendingAcquirerEntity.erp_database === 'string' && pendingAcquirerEntity.erp_database.trim() !== '') {
            return pendingAcquirerEntity.erp_database.trim();
        }
        return '';
    }

    function showQrDocTypeMappingModal(context) {
        if (!qrDocTypeMappingModal || !qrDocTypeMappingContainer) {
            throw new Error('A modal de associação do tipo documental não está disponível.');
        }

        resetQrDocTypeMappingModal();
        pendingQrDocTypeMappingContext = context || null;

        var groups = context && Array.isArray(context.groups) ? context.groups.filter(function(group) {
            return group && Array.isArray(group.items) && group.items.length;
        }) : [];
        if (!groups.length) {
            groups = [{
                items: context && Array.isArray(context.items) ? context.items : [],
                options: context && Array.isArray(context.options) ? context.options : [],
                database: context && typeof context.database === 'string' ? context.database.trim() : ''
            }];
        }

        if (!groups.length || !Array.isArray(groups[0].items) || !groups[0].items.length) {
            throw new Error('Não existem tipos documentais QR por associar.');
        }

        var isMultiGroup = groups.length > 1;
        if (qrDocTypeMappingMessage) {
            if (isMultiGroup) {
                qrDocTypeMappingMessage.textContent = 'Associe os tipos de documento E-fatura lidos do QR aos tipos ERP de cada base adquirente.';
            } else {
                var database = groups[0] && typeof groups[0].database === 'string' ? groups[0].database.trim() : '';
                qrDocTypeMappingMessage.textContent = database !== ''
                    ? 'Associe os tipos de documento E-fatura lidos do QR aos tipos ERP disponíveis na base ' + database + '.'
                    : 'Associe os tipos de documento E-fatura lidos do QR aos tipos ERP disponíveis.';
            }
        }

        var html = '';
        groups.forEach(function(group, groupIndex) {
            var groupDatabase = group && typeof group.database === 'string' ? group.database.trim() : '';
            var groupItems = group && Array.isArray(group.items) ? group.items : [];
            var groupOptions = group && Array.isArray(group.options) ? group.options : [];
            if (!groupItems.length) {
                return;
            }

            if (isMultiGroup) {
                html += '<div class="border rounded p-3 mb-3">';
                html += '<div class="fw-semibold mb-3">Base ERP: ' + escapeHtml(groupDatabase || 'n/d') + '</div>';
            }

            groupItems.forEach(function(item, itemIndex) {
                var qrDocType = item && item.qr_doc_type ? String(item.qr_doc_type).trim() : '';
                if (!qrDocType) {
                    return;
                }
                var rawDocType = item && item.raw_doc_type ? String(item.raw_doc_type).trim() : '';
                var suggestedValue = item && item.suggested_value ? String(item.suggested_value).trim() : '';
                var selectId = 'qrDocTypeMappingSelect_' + groupIndex + '_' + itemIndex;
                html += '<div class="mb-3">';
                html += '<label class="form-label" for="' + selectId + '">Tipo de documento E-fatura: ' + escapeHtml(qrDocType) + '</label>';
                if (rawDocType && rawDocType !== qrDocType) {
                    html += '<div class="text-muted small mb-1">Detetado no documento: ' + escapeHtml(rawDocType) + '</div>';
                }
                html += '<select class="form-select qr-doc-type-mapping-select" id="' + selectId + '" data-database="' + escapeHtml(groupDatabase) + '" data-qr-doc-type="' + escapeHtml(qrDocType) + '" required>';
                html += '<option value="">Selecionar tipo documental ERP</option>';
                groupOptions.forEach(function(option) {
                    var value = option && option.value ? String(option.value).trim() : '';
                    if (!value) {
                        return;
                    }
                    var label = option && option.label ? String(option.label).trim() : value;
                    var description = option && option.description ? String(option.description).trim() : '';
                    var selected = suggestedValue !== '' && suggestedValue === value ? ' selected' : '';
                    html += '<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(label) + (description ? ' [' + escapeHtml(description) + ']' : '') + '</option>';
                });
                html += '</select>';
                html += '</div>';
            });

            if (isMultiGroup) {
                html += '</div>';
            }
        });

        qrDocTypeMappingContainer.innerHTML = html;
        qrDocTypeMappingModal.show();
    }

    function ensureQrDocTypeMappings(ids, database) {
        var payload = {
            ids: ids,
            import_type: importType,
            database: String(database || '').trim(),
            csrf_token: csrfInput ? csrfInput.value : '',
            mode: 'check'
        };
        if (isClassificationOnlyView) {
            payload.allow_classified_flow = 1;
        }

        debugJson('Pedido de validação de associações de tipos QR', payload);
        return fetchJson('contabilidade/classificacao-importacao/qr-doc-type-mapping', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        }).then(function(res) {
            debugJson('Resposta de validação de associações de tipos QR', res);
            if (!res || typeof res !== 'object') {
                throw new Error('Resposta inválida ao validar os tipos documentais do QR.');
            }
            if (res.csrf_token && csrfInput) {
                csrfInput.value = res.csrf_token;
            }
            if (res.success === false) {
                throw new Error(res.error || 'Não foi possível validar os tipos documentais do QR.');
            }
            return {
                requiresMapping: !!res.requires_mapping,
                items: Array.isArray(res.items) ? res.items : [],
                options: Array.isArray(res.options) ? res.options : [],
                groups: Array.isArray(res.groups) ? res.groups : [],
                database: typeof res.database === 'string' ? res.database.trim() : String(database || '').trim()
            };
        });
    }

    function saveQrDocTypeMappings(mappings, database, groupMappings) {
        var payload = {
            ids: Array.isArray(pendingImportIds) ? pendingImportIds.slice() : [],
            import_type: importType,
            database: String(database || '').trim(),
            mappings: mappings,
            csrf_token: csrfInput ? csrfInput.value : '',
            mode: 'save'
        };
        if (groupMappings && typeof groupMappings === 'object' && Object.keys(groupMappings).length) {
            payload.group_mappings = groupMappings;
        }
        if (isClassificationOnlyView) {
            payload.allow_classified_flow = 1;
        }

        debugJson('Pedido de gravação de associações de tipos QR', payload);
        return fetchJson('contabilidade/classificacao-importacao/qr-doc-type-mapping', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        }).then(function(res) {
            debugJson('Resposta de gravação de associações de tipos QR', res);
            if (!res || typeof res !== 'object') {
                throw new Error('Resposta inválida ao guardar a associação do tipo documental.');
            }
            if (res.csrf_token && csrfInput) {
                csrfInput.value = res.csrf_token;
            }
            if (res.success === false) {
                throw new Error(res.error || 'Não foi possível guardar a associação do tipo documental.');
            }
            return res;
        });
    }

    function runPendingImportWithDocTypeMappings(ids) {
        var resolvedIds = Array.isArray(ids) ? ids.slice() : [];
        if (!resolvedIds.length) {
            return Promise.resolve();
        }

        var database = resolvePendingImportDatabase();
        return ensureQrDocTypeMappings(resolvedIds, database)
            .then(function(mappingResult) {
                if (mappingResult && mappingResult.requiresMapping) {
                    qrDocTypeMappingPending = true;
                    pendingQrDocTypeMappingContext = mappingResult;
                    if (importCtbButton.length) {
                        importCtbButton.data('loading', false);
                    }
                    updateImportButtonState();
                    showQrDocTypeMappingModal(mappingResult);
                    return null;
                }

                return performImport(resolvedIds);
            });
    }

    function moveImportButtonToFilter() {
        if (!importCtbButton.length || !importTypeAllowsImport) {
            showImportButtonWrapper();
            return;
        }

        // The import button lives inside #importCtbButtonWrapper, which
        // wireHeaderControls() moves into the DataTables header slot
        // (.classify-action-slot) once, at init. The button is not relocated
        // out of its wrapper, so here we only ensure the wrapper is visible and
        // refresh the (debug) ERP parameter info next to the button.
        showImportButtonWrapper();
        renderImportParamInfo();
    }

    function getImportReadySelector() {
        if (importType === 2) {
            return '.analyze-lines.btn-success';
        }
        return '.classify-row.btn-success';
    }

    function buildReadyIdsUrl() {
        var query = new URLSearchParams();
        query.set('import_type', String(importType));
        if (viewMode !== '') {
            query.set('view_mode', viewMode);
        }
        return 'contabilidade/classificacao-importacao/ready-ids?' + query.toString();
    }

    function invalidateReadyImportIdsCache() {
        readyIdsCache.ids = null;
        readyIdsCache.fetchedAt = 0;
        readyIdsCache.promise = null;
    }

    function updateImportButtonLabel(readyCount) {
        if (!importCtbButton.length || !importCtbButtonLabel.length) {
            return;
        }

        var baseLabel = String(importCtbButton.data('base-label') || '').trim();
        if (baseLabel === '') {
            baseLabel = String(importCtbButtonLabel.text() || '').replace(/\s*\(\d+\)\s*$/, '').trim();
        }

        var count = parseInt(readyCount, 10);
        if (isNaN(count) || count < 0) {
            count = 0;
        }

        var nextLabel = baseLabel;
        if (isClassificationOnlyView && count > 0) {
            nextLabel += ' (' + count + ')';
        }

        importCtbButtonLabel.text(nextLabel);
    }

    function fetchReadyImportIds(forceRefresh) {
        var now = Date.now();
        if (!forceRefresh && Array.isArray(readyIdsCache.ids) && (now - readyIdsCache.fetchedAt) < readyIdsCacheTtlMs) {
            return Promise.resolve(readyIdsCache.ids.slice());
        }

        if (!forceRefresh && readyIdsCache.promise) {
            return readyIdsCache.promise.then(function(ids) {
                return Array.isArray(ids) ? ids.slice() : [];
            });
        }

        readyIdsCache.promise = fetchJson(buildReadyIdsUrl())
            .then(function(res) {
                if (!res || res.success === false) {
                    throw new Error((res && res.error) ? res.error : 'Não foi possível obter as linhas prontas para importar.');
                }

                if (!Array.isArray(res.ids)) {
                    readyIdsCache.ids = [];
                    readyIdsCache.fetchedAt = Date.now();
                    return [];
                }

                readyIdsCache.ids = res.ids.map(function(id) {
                    return String(id);
                }).filter(function(id) {
                    return id !== '';
                });
                readyIdsCache.fetchedAt = Date.now();
                return readyIdsCache.ids.slice();
            })
            .finally(function() {
                readyIdsCache.promise = null;
            });

        return readyIdsCache.promise.then(function(ids) {
            return Array.isArray(ids) ? ids.slice() : [];
        });
    }

    function updateImportButtonState(forceRefresh) {
        if (!importCtbButton.length || !importTypeAllowsImport) {
            return;
        }
        renderImportParamInfo();
        if (importCtbButton.data('loading')) {
            importCtbButton.prop('disabled', true);
            return;
        }
        if (acquirerDatabasePending || qrDocTypeMappingPending) {
            importCtbButton.prop('disabled', true);
            return;
        }
        importCtbButton.prop('disabled', true);

        fetchReadyImportIds(!!forceRefresh)
            .then(function(ids) {
                updateImportButtonLabel(ids.length);
                if (importCtbButton.data('loading') || acquirerDatabasePending || qrDocTypeMappingPending) {
                    importCtbButton.prop('disabled', true);
                    return;
                }
                importCtbButton.prop('disabled', ids.length === 0);
            })
            .catch(function() {
                updateImportButtonLabel(0);
                importCtbButton.prop('disabled', true);
            });
    }

    function performImport(ids) {
        if (!importCtbButton.length) {
            return Promise.resolve();
        }
        importCtbButton.data('loading', true);
        importCtbButton.prop('disabled', true);
        acquirerDatabasePending = false;

        var payload = {
            ids: ids,
            import_type: importType,
            csrf_token: csrfInput ? csrfInput.value : '',
            act: 'importMovim'
        };
        if (isClassificationOnlyView) {
            payload.allow_classified_flow = 1;
        }
        if (pendingAcquirerEntity && typeof pendingAcquirerEntity.erp_database === 'string') {
            var databaseValue = pendingAcquirerEntity.erp_database.trim();
            if (databaseValue !== '') {
                payload.database = databaseValue;
            }
        }
        renderImportParamInfo();
        debugJson('Import CTB request payload', payload);
        var importUrl = buildImportCtbUrl();
        var requestOptions = {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        };

        return fetchJson(importUrl, requestOptions)
            .then(function(res) {
                debugJson('Import CTB response payload', res);
                if (res && res.csrf_token && csrfInput) {
                    csrfInput.value = res.csrf_token;
                }
                if (!res || !res.success) {
                    var errorMessages = res ? extractErrorMessages(res) : [];
                    var error = 'Erro ao importar';
                    if (res) {
                        var resultadoMessages = [];
                        if (res.resultado && typeof res.resultado === 'object') {
                            resultadoMessages = extractErrorMessages(res.resultado);
                        }
                        if (resultadoMessages.length) {
                            error = resultadoMessages.join('\n');
                        } else if (errorMessages.length) {
                            error = errorMessages.join('\n');
                        } else {
                            var errorFields = ['error', 'mensagem', 'message', 'msg'];
                            for (var eIndex = 0; eIndex < errorFields.length; eIndex += 1) {
                                var errorField = errorFields[eIndex];
                                if (Object.prototype.hasOwnProperty.call(res, errorField)) {
                                    var errorCandidate = res[errorField];
                                    if (typeof errorCandidate === 'string' && errorCandidate.trim() !== '') {
                                        error = errorCandidate.trim();
                                        break;
                                    }
                                }
                            }
                        }
                    } else if (errorMessages.length) {
                        error = errorMessages.join('\n');
                    }
                    throw new Error(error);
                }
                //console.log(res);
                if (res && res.service_response) {
                    debugJson('Import CTB service response', res.service_response);
                }

                var recDetails = extractRecDetails(res.service_payload) || extractRecDetails(res.service_response);
                if (recDetails && recDetails.errors && recDetails.errors.length) {
                    throw new Error(recDetails.errors.join('\n'));
                }
                var message = 'OK';
                if (res) {
                    if (res.service_payload) {
                        var payloadMessage = extractMessageFromPayload(res.service_payload);
                        if (payloadMessage) {
                            message = payloadMessage;
                        }
                    }
                    if (message === 'OK') {
                        if (typeof res.message === 'string' && res.message.trim() !== '') {
                            message = res.message.trim();
                        } else {
                            var topLevelFields = ['mensagem', 'msg'];
                            for (var j = 0; j < topLevelFields.length; j += 1) {
                                var topField = topLevelFields[j];
                                if (Object.prototype.hasOwnProperty.call(res, topField)) {
                                    var topCandidate = res[topField];
                                    if (typeof topCandidate === 'string' && topCandidate.trim() !== '') {
                                        message = topCandidate.trim();
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
                message = buildImportResultMessage(res, message);
                var noticeType = resolveNoticeTypeFromResponse(res);
                showNotice(noticeType, message);
                if (typeof window.console !== 'undefined') {
                    console.log('[Classificação] Import CTB concluído. HTTP:', res.http_status, 'Tipo:', noticeType, 'IDs:', ids);
                }
                invalidateReadyImportIdsCache();
                table.ajax.reload(null, false);
            })
            .catch(function(err) {
                if (typeof window.console !== 'undefined') {
                    console.error('[Classificação] Erro na importação CTB:', err);
                }
                showError(err && err.message ? err.message : 'Erro ao importar');
            })
            .finally(function() {
                if (importCtbButton.length) {
                    importCtbButton.data('loading', false);
                }
                pendingImportIds = null;
                pendingAcquirerEntity = null;
                acquirerDatabasePending = false;
                qrDocTypeMappingPending = false;
                pendingQrDocTypeMappingContext = null;
                updateImportButtonState();
            });
    }

    function handleImportCtbClick() {
        if (!importCtbButton.length || importCtbButton.data('loading')) {
            return;
        }
        importCtbButton.data('loading', true);
        importCtbButton.prop('disabled', true);

        fetchReadyImportIds(true)
            .then(function(ids) {
                if (ids.length === 0) {
                    throw new Error('Não existem linhas prontas para importar.');
                }

                pendingImportIds = ids.slice();
                pendingAcquirerEntity = null;
                acquirerDatabasePending = false;
                qrDocTypeMappingPending = false;
                pendingQrDocTypeMappingContext = null;

                return ensureAcquirerDatabase(ids)
                    .then(function(result) {
                        if (result && result.entity) {
                            pendingAcquirerEntity = result.entity;
                            renderImportParamInfo();
                        }
                        if (result && result.requiresSelection) {
                            acquirerDatabasePending = true;
                            importCtbButton.data('loading', false);
                            updateImportButtonState();
                            showAcquirerDatabaseModal(pendingAcquirerEntity);
                            return null;
                        }
                        return runPendingImportWithDocTypeMappings(ids);
                    });
            })
            .catch(function(err) {
                if (typeof window.console !== 'undefined') {
                    console.error('[Classificação] Erro ao validar base de dados do adquirente:', err);
                }
                showError(err && err.message ? err.message : 'Erro ao validar o adquirente.');
                pendingImportIds = null;
                pendingAcquirerEntity = null;
                acquirerDatabasePending = false;
                qrDocTypeMappingPending = false;
                pendingQrDocTypeMappingContext = null;
                renderImportParamInfo();
                if (importCtbButton.length) {
                    importCtbButton.data('loading', false);
                }
                updateImportButtonState();
            });
    }

    if (importCtbButton.length) {
        importCtbButton.on('click', handleImportCtbClick);
    }

    if (acquirerDatabaseModalEl) {
        acquirerDatabaseModalEl.addEventListener('hidden.bs.modal', function() {
            resetAcquirerDatabaseModal();
            if (!acquirerDatabaseSelectionResolved) {
                pendingImportIds = null;
                pendingAcquirerEntity = null;
                acquirerDatabasePending = false;
                qrDocTypeMappingPending = false;
                pendingQrDocTypeMappingContext = null;
                if (importCtbButton.length) {
                    importCtbButton.data('loading', false);
                }
                updateImportButtonState();
            }
            acquirerDatabaseSelectionResolved = false;
        });
    }

    if (acquirerDatabaseForm) {
        acquirerDatabaseForm.addEventListener('submit', function(event) {
            event.preventDefault();
            if (!acquirerDatabaseSelect) {
                return;
            }
            var selectedValue = acquirerDatabaseSelect.value;
            if (!selectedValue) {
                acquirerDatabaseSelect.classList.add('is-invalid');
                return;
            }
            acquirerDatabaseSelect.classList.remove('is-invalid');
            if (acquirerDatabaseError) {
                acquirerDatabaseError.classList.add('d-none');
                acquirerDatabaseError.textContent = '';
            }
            if (confirmAcquirerDatabaseBtn) {
                confirmAcquirerDatabaseBtn.disabled = true;
            }

            updateAcquirerDatabase(selectedValue)
                .then(function(res) {
                    acquirerDatabaseSelectionResolved = true;
                    acquirerDatabasePending = false;
                    if (res && res.entity) {
                        pendingAcquirerEntity = res.entity;
                        renderImportParamInfo();
                    }
                    if (acquirerDatabaseModal) {
                        acquirerDatabaseModal.hide();
                    }
                    if (Array.isArray(pendingImportIds) && pendingImportIds.length) {
                        return runPendingImportWithDocTypeMappings(pendingImportIds.slice());
                    } else {
                        pendingImportIds = null;
                        if (importCtbButton.length) {
                            importCtbButton.data('loading', false);
                        }
                        updateImportButtonState();
                        return null;
                    }
                })
                .catch(function(err) {
                    if (confirmAcquirerDatabaseBtn) {
                        confirmAcquirerDatabaseBtn.disabled = false;
                    }
                    if (acquirerDatabaseModalEl && acquirerDatabaseModalEl.classList.contains('show') && acquirerDatabaseError) {
                        acquirerDatabaseError.textContent = err && err.message ? err.message : 'Não foi possível guardar a base de dados.';
                        acquirerDatabaseError.classList.remove('d-none');
                    } else {
                        showError(err && err.message ? err.message : 'Não foi possível guardar a base de dados.');
                    }
                });
        });
    }

    if (qrDocTypeMappingModalEl) {
        qrDocTypeMappingModalEl.addEventListener('hidden.bs.modal', function() {
            resetQrDocTypeMappingModal();
            if (!qrDocTypeMappingResolved) {
                pendingImportIds = null;
                pendingAcquirerEntity = null;
                qrDocTypeMappingPending = false;
                pendingQrDocTypeMappingContext = null;
                if (importCtbButton.length) {
                    importCtbButton.data('loading', false);
                }
                updateImportButtonState();
            }
            qrDocTypeMappingResolved = false;
        });
    }

    if (qrDocTypeMappingForm) {
        qrDocTypeMappingForm.addEventListener('submit', function(event) {
            event.preventDefault();
            var contextGroups = pendingQrDocTypeMappingContext && Array.isArray(pendingQrDocTypeMappingContext.groups)
                ? pendingQrDocTypeMappingContext.groups.filter(function(group) {
                    return group && Array.isArray(group.items) && group.items.length;
                })
                : [];
            var hasSingleContextItems = pendingQrDocTypeMappingContext && Array.isArray(pendingQrDocTypeMappingContext.items) && pendingQrDocTypeMappingContext.items.length;
            if (!pendingQrDocTypeMappingContext || (!contextGroups.length && !hasSingleContextItems)) {
                showError('Não existe nenhuma associação pendente para guardar.');
                return;
            }

            var selects = qrDocTypeMappingContainer ? qrDocTypeMappingContainer.querySelectorAll('.qr-doc-type-mapping-select') : [];
            var mappings = {};
            var groupMappings = {};
            var hasInvalid = false;
            var useGroupedMappings = contextGroups.length > 1;

            Array.prototype.forEach.call(selects, function(selectEl) {
                var database = String(selectEl.getAttribute('data-database') || '').trim();
                var qrDocType = String(selectEl.getAttribute('data-qr-doc-type') || '').trim();
                var value = String(selectEl.value || '').trim();
                if (!value) {
                    selectEl.classList.add('is-invalid');
                    hasInvalid = true;
                    return;
                }
                selectEl.classList.remove('is-invalid');
                if (qrDocType) {
                    if (useGroupedMappings) {
                        if (!groupMappings[database]) {
                            groupMappings[database] = {};
                        }
                        groupMappings[database][qrDocType] = value;
                    } else {
                        mappings[qrDocType] = value;
                    }
                }
            });

            var hasMappings = useGroupedMappings ? Object.keys(groupMappings).length > 0 : Object.keys(mappings).length > 0;
            if (hasInvalid || !hasMappings) {
                if (qrDocTypeMappingError) {
                    qrDocTypeMappingError.textContent = 'Selecione um tipo documental ERP para todos os tipos QR apresentados.';
                    qrDocTypeMappingError.classList.remove('d-none');
                }
                return;
            }

            if (qrDocTypeMappingError) {
                qrDocTypeMappingError.classList.add('d-none');
                qrDocTypeMappingError.textContent = '';
            }
            if (confirmQrDocTypeMappingBtn) {
                confirmQrDocTypeMappingBtn.disabled = true;
            }

            saveQrDocTypeMappings(mappings, pendingQrDocTypeMappingContext.database || '', groupMappings)
                .then(function() {
                    qrDocTypeMappingResolved = true;
                    qrDocTypeMappingPending = false;
                    var idsToImport = Array.isArray(pendingImportIds) ? pendingImportIds.slice() : [];
                    if (qrDocTypeMappingModal) {
                        qrDocTypeMappingModal.hide();
                    }
                    if (idsToImport.length) {
                        return performImport(idsToImport);
                    }
                    return null;
                })
                .catch(function(err) {
                    if (confirmQrDocTypeMappingBtn) {
                        confirmQrDocTypeMappingBtn.disabled = false;
                    }
                    if (qrDocTypeMappingError) {
                        qrDocTypeMappingError.textContent = err && err.message ? err.message : 'Não foi possível guardar a associação do tipo documental.';
                        qrDocTypeMappingError.classList.remove('d-none');
                    } else {
                        showError(err && err.message ? err.message : 'Não foi possível guardar a associação do tipo documental.');
                    }
                });
        });
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

    function normalizeServiceObject(value) {
        if (!value) {
            return null;
        }
        if (typeof value === 'object') {
            return value;
        }
        if (typeof value === 'string') {
            var parsed = tryParseJson(value);
            if (parsed && typeof parsed === 'object') {
                return parsed;
            }
        }
        return null;
    }

    function extractRecDetails(value) {
        var data = normalizeServiceObject(value);
        if (!data || typeof data !== 'object') {
            return null;
        }
        var recs = data.recs || data.recList || null;
        if (!recs || typeof recs !== 'object') {
            return null;
        }

        var collectList = function(container, keys) {
            var list = [];
            keys.forEach(function(key) {
                var entry = container[key];
                if (Array.isArray(entry)) {
                    entry.forEach(function(item) {
                        if (typeof item === 'string' && item.trim() !== '') {
                            list.push(item.trim());
                        }
                    });
                } else if (typeof entry === 'string' && entry.trim() !== '') {
                    list.push(entry.trim());
                }
            });
            return list;
        };

        var errors = collectList(recs, ['error', 'errors', 'erros']);
        var existing = collectList(recs, ['exist', 'existing', 'duplicated']);
        var created = collectList(recs, ['novo', 'novos', 'created', 'success']);

        if (!errors.length && !existing.length && !created.length) {
            return null;
        }

        return {
            errors: errors,
            existing: existing,
            created: created
        };
    }

    function extractMessageFromPayload(payload) {
        if (!payload) {
            return '';
        }
        if (typeof payload === 'string') {
            return payload.trim();
        }
        if (typeof payload !== 'object') {
            return '';
        }

        var messageFields = ['mensagem', 'message', 'msg', 'mensagem_erro'];
        for (var i = 0; i < messageFields.length; i += 1) {
            var field = messageFields[i];
            if (!Object.prototype.hasOwnProperty.call(payload, field)) {
                continue;
            }
            var candidate = payload[field];
            if (typeof candidate === 'string' && candidate.trim() !== '') {
                return candidate.trim();
            }
        }

        return '';
    }

    function buildImportResultMessage(res, fallbackMessage) {
        var summary = typeof fallbackMessage === 'string' ? fallbackMessage.trim() : '';
        if (!res || !Array.isArray(res.batches) || !res.batches.length) {
            return summary || 'OK';
        }

        var detailLines = [];
        var seenLines = {};
        var appendLine = function(line) {
            var normalized = typeof line === 'string' ? line.trim() : '';
            if (!normalized || seenLines[normalized]) {
                return;
            }
            seenLines[normalized] = true;
            detailLines.push(normalized);
        };

        res.batches.forEach(function(batch) {
            if (!batch || typeof batch !== 'object') {
                return;
            }

            var databaseLabel = typeof batch.database === 'string' && batch.database.trim() !== '' ? batch.database.trim() + ': ' : '';
            var recDetails = extractRecDetails(batch.service_payload) || extractRecDetails(batch.service_response);

            if (recDetails) {
                if (Array.isArray(recDetails.errors)) {
                    recDetails.errors.forEach(function(item) {
                        appendLine(databaseLabel + item);
                    });
                }
                if (Array.isArray(recDetails.existing)) {
                    recDetails.existing.forEach(function(item) {
                        appendLine(databaseLabel + item);
                    });
                }
                if ((!recDetails.errors || !recDetails.errors.length) && (!recDetails.existing || !recDetails.existing.length) && Array.isArray(recDetails.created)) {
                    recDetails.created.forEach(function(item) {
                        appendLine(databaseLabel + item);
                    });
                }
            }

            if (!recDetails || ((!recDetails.errors || !recDetails.errors.length) && (!recDetails.existing || !recDetails.existing.length) && (!recDetails.created || !recDetails.created.length))) {
                var batchMessage = '';
                if (typeof batch.message === 'string' && batch.message.trim() !== '') {
                    batchMessage = batch.message.trim();
                }
                if (batchMessage === '') {
                    batchMessage = extractMessageFromPayload(batch.service_payload) || extractMessageFromPayload(batch.service_response);
                }
                if (batchMessage !== '') {
                    appendLine(databaseLabel + batchMessage);
                }
            }
        });

        if (!detailLines.length) {
            return summary || 'OK';
        }

        if (summary !== '') {
            if (detailLines.length === 1 && detailLines[0] === summary) {
                return summary;
            }
            return summary + '\n' + detailLines.join('\n');
        }

        return detailLines.join('\n');
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
        var fields = ['iva_account', 'general_account', 'base', 'iva', 'base_value', 'iva_value', 'cost_center', 'value'];
        for (var i = 0; i < fields.length; i += 1) {
            var field = fields[i];
            if (!Object.prototype.hasOwnProperty.call(entry, field)) {
                continue;
            }
            var scalar = extractScalarFromMixed(entry[field]);
            if (scalar === '') {
                continue;
            }
            if (field === 'base' || field === 'iva' || field === 'base_value' || field === 'iva_value' || field === 'value') {
                var numeric = parseDecimalValue(scalar);
                if (numeric !== null && Math.abs(numeric) < 0.00001) {
                    continue;
                }
            }
            if (scalar !== '') {
                return true;
            }
        }
        return false;
    }

    function hasBankLoanConversionRates(rateData) {
        if (!rateData || typeof rateData !== 'object') {
            return false;
        }
        return Object.keys(rateData).some(function(rate) {
            var entry = rateData[rate];
            if (!entry || typeof entry !== 'object') {
                return false;
            }
            var flag = String(entry.bank_loan_conversion || '').trim().toLowerCase();
            return flag === '1' || flag === 'true';
        });
    }

    function isBankLoanConversionRate(rate, entry) {
        var sources = [
            entry,
            currentRateData && currentRateData[rate],
            storedRowRates && storedRowRates[rate],
            storedDefaultRates && storedDefaultRates[rate]
        ];
        return sources.some(function(source) {
            if (!source || typeof source !== 'object') {
                return false;
            }
            var flag = String(source.bank_loan_conversion || '').trim().toLowerCase();
            return flag === '1' || flag === 'true';
        });
    }

    function clearBankLoanVatForRate(rate) {
        var info = rateInputs[rate] || null;
        var rateData = ensureRateData(rate);
        if (info && info.iva) {
            info.iva.value = '';
        }
        if (info && info.ivaAccount) {
            info.ivaAccount.value = '';
        }
        rateData.iva = '';
        rateData.iva_value = '';
        rateData.iva_account = '';
        rateData.bank_loan_conversion = '1';
    }

    function updateButtonClass(btn) {
        var rateData = parseJsonAttribute(btn, 'data-rates') || {};
        var requirements = parseJsonAttribute(btn, 'data-requirements') || {};
        var costCenters = normalizeCostCenterValues(parseJsonAttribute(btn, 'data-cost-centers') || {});

        var requires = false;
        var allFilled = true;
        var hasAny = false;
        var hasMissingBaseAmount = false;
        var totalAccount = '';

        Object.keys(requirements).forEach(function(rate) {
            var req = requirements[rate] || {};
            var data = rateData[rate] || {};
            var normalizedRate = normalizeRateToken(rate);
            var isZeroRate = normalizedRate !== null && Math.abs(normalizedRate) < 0.00001;
            var isBankLoanRate = String(data.bank_loan_conversion || '').trim() === '1';
            var hasRelevantConfiguration = false;
            if (req.general) {
                requires = true;
                hasRelevantConfiguration = true;
                var general = (data.general_account || '').trim();
                if (!general) {
                    allFilled = false;
                } else {
                    hasAny = true;
                }
            }
            if (req.iva && !isZeroRate && !isBankLoanRate) {
                requires = true;
                hasRelevantConfiguration = true;
                var iva = (data.iva_account || '').trim();
                if (!iva) {
                    allFilled = false;
                } else {
                    hasAny = true;
                }
            }
            if (req.cost_center) {
                requires = true;
                hasRelevantConfiguration = true;
                var cc = (costCenters[rate] || '').trim();
                if (!cc) {
                    allFilled = false;
                } else {
                    hasAny = true;
                }
            }
            if (!hasRelevantConfiguration) {
                hasRelevantConfiguration = String(data.general_account || '').trim() !== ''
                    || (!isZeroRate && !isBankLoanRate && String(data.iva_account || '').trim() !== '')
                    || String(costCenters[rate] || '').trim() !== '';
            }
            if (hasRelevantConfiguration) {
                var baseCandidate = resolveBaseSourceForRate(rate, data);
                var baseValue = baseCandidate ? baseCandidate.base : getEntryAmount(data, 'base');
                if (baseValue === null || Math.abs(parseFloat(baseValue)) < 0.00001) {
                    allFilled = false;
                    hasMissingBaseAmount = true;
                } else {
                    hasAny = true;
                }
            }
        });

        if (importType === 1) {
            totalAccount = (btn.getAttribute('data-total-account') || '').trim();
            if (totalAccount === '') {
                requires = true;
                allFilled = false;
            } else {
                hasAny = true;
            }
        }

        btn.classList.remove('btn-success', 'btn-warning', 'btn-secondary');
        if ((!requires || allFilled) && !hasMissingBaseAmount) {
            btn.classList.add('btn-success');
        } else if (hasAny) {
            btn.classList.add('btn-warning');
        } else {
            btn.classList.add('btn-secondary');
        }

        var manualReview = (btn.getAttribute('data-manual-review') || '').trim();
        var isManualReview = manualReview === '1';
        var isSuccess = btn.classList.contains('btn-success');
        var isAutoImportReady = isSuccess && !isManualReview;
        btn.setAttribute('data-auto-import', isAutoImportReady ? '1' : '0');
        btn.textContent = isAutoImportReady ? 'Classificado' : 'Classificar';

        updateImportButtonState();

    }

    function applyCostCenterRequirementMap(requiredRates) {
        if (!currentBtn || !requiredRates || typeof requiredRates !== 'object') {
            return;
        }
        var requirements = parseJsonAttribute(currentBtn, 'data-requirements') || {};
        var changed = false;
        Object.keys(requiredRates).forEach(function(rateKey) {
            var raw = requiredRates[rateKey];
            var enabled = !!raw && String(raw).trim() !== '0' && String(raw).trim().toLowerCase() !== 'false';
            if (!enabled) {
                return;
            }
            var resolvedKey = String(rateKey);
            if (!requirements[resolvedKey] && findRateKeyByToken(rateKey)) {
                resolvedKey = findRateKeyByToken(rateKey);
            }
            if (!requirements[resolvedKey]) {
                requirements[resolvedKey] = {};
            }
            if (!requirements[resolvedKey].cost_center) {
                requirements[resolvedKey].cost_center = true;
                changed = true;
            }
            var rateData = ensureRateData(resolvedKey);
            if (rateData.cost_center_required !== '1') {
                rateData.cost_center_required = '1';
                changed = true;
            }
        });
        if (changed) {
            currentBtn.setAttribute('data-requirements', JSON.stringify(requirements));
            updateButtonClass(currentBtn);
            getRateKeys().forEach(function(rate) {
                updateCostCenterFieldMode(rate);
            });
        }
    }

    function enrichRequirementsFromRates(baseRequirements, ratesSource) {
        var requirements = baseRequirements && typeof baseRequirements === 'object' ? baseRequirements : {};
        if (!ratesSource || typeof ratesSource !== 'object') {
            return requirements;
        }
        Object.keys(ratesSource).forEach(function(rate) {
            var entry = ratesSource[rate];
            if (!entry || typeof entry !== 'object') {
                return;
            }
            var flag = String(entry.cost_center_required || '').trim();
            if (flag !== '1' && flag.toLowerCase() !== 'true') {
                return;
            }
            if (!requirements[rate]) {
                requirements[rate] = {};
            }
            requirements[rate].cost_center = true;
        });
        return requirements;
    }

    function buildRequirementsFromCurrentRates() {
        var requirements = {};
        Object.keys(currentRateData).forEach(function(rate) {
            var entry = currentRateData[rate];
            if (!entry || typeof entry !== 'object') {
                return;
            }
            var general = String(entry.general_account || '').trim();
            var iva = String(entry.iva_account || '').trim();
            var base = String(entry.base_value || entry.base || '').trim();
            var ivaValue = String(entry.iva_value || entry.iva || '').trim();
            var hasValues = general !== '' || iva !== '' || base !== '' || ivaValue !== '';
            if (!hasValues) {
                return;
            }
            var normalizedRate = normalizeRateToken(rate);
            var isZeroRate = normalizedRate !== null && Math.abs(normalizedRate) < 0.00001;
            var isBankLoanRate = String(entry.bank_loan_conversion || '').trim() === '1';
            requirements[rate] = {
                general: true,
                iva: !isZeroRate && !isBankLoanRate,
                cost_center: String(entry.cost_center_required || '').trim() === '1'
            };
        });
        return requirements;
    }

    function rebuildRequirementsForCurrentButton() {
        if (!currentBtn) {
            return {};
        }
        var requirements = currentIgnoreDetectedRates ? buildRequirementsFromCurrentRates() : (parseJsonAttribute(currentBtn, 'data-requirements') || {});
        requirements = enrichRequirementsFromRates(requirements, currentRateData);
        requirements = enrichRequirementsFromRates(requirements, storedRowRates);
        requirements = enrichRequirementsFromRates(requirements, storedDefaultRates);
        currentBtn.setAttribute('data-requirements', JSON.stringify(requirements));
        return requirements;
    }

    function refreshButtonClasses() {
        $('#classify-table').find('.classify-row').each(function() {
            updateButtonClass(this);
        });
    }

    var classifyModalEl = document.getElementById('classifyModal');
    var classifyModal = classifyModalEl ? new bootstrap.Modal(classifyModalEl) : null;
    var costCenterDistributionModalEl = document.getElementById('costCenterDistributionModal');
    var costCenterDistributionModal = costCenterDistributionModalEl ? new bootstrap.Modal(costCenterDistributionModalEl) : null;
    var costCenterDistributionDialogEl = costCenterDistributionModalEl ? costCenterDistributionModalEl.querySelector('.modal-dialog') : null;
    var costCenterDistributionHeaderEl = costCenterDistributionModalEl ? costCenterDistributionModalEl.querySelector('.modal-header') : null;
    var costCenterDistributionResizeObserver = null;
    var modalTitleEl = document.getElementById('classifyModalLabel');
    var modalCompanyBadgeEl = document.getElementById('classifyModalCompanyBadge');
    var classifyDocumentPreviewFrame = document.getElementById('classifyDocumentPreviewFrame');
    var classifyDocumentPreviewEmpty = document.getElementById('classifyDocumentPreviewEmpty');
    var classifyDocumentOpenBtn = document.getElementById('classifyDocumentOpenBtn');
    var costCenterDistributionDocumentInfoEl = document.getElementById('ccDistributionDocumentInfo');
    var costCenterDistributionDateInfoEl = document.getElementById('ccDistributionDateInfo');
    var costCenterDistributionTypeInfoEl = document.getElementById('ccDistributionTypeInfo');
    var costCenterDistributionEmitterInfoEl = document.getElementById('ccDistributionEmitterInfo');
    var costCenterDistributionAccountInfoEl = document.getElementById('ccDistributionAccountInfo');
    var costCenterDistributionAccountLabelInfoEl = document.getElementById('ccDistributionAccountLabelInfo');
    var costCenterDistributionAmountInfoEl = document.getElementById('ccDistributionAmountInfo');
    var costCenterDistributionRateInfoEl = document.getElementById('ccDistributionRateInfo');
    var costCenterDistributionTableBody = document.getElementById('ccDistributionTableBody');
    var costCenterDistributionPercentAssignedEl = document.getElementById('ccDistributionPercentAssigned');
    var costCenterDistributionPercentRemainingEl = document.getElementById('ccDistributionPercentRemaining');
    var costCenterDistributionAmountRemainingEl = document.getElementById('ccDistributionAmountRemaining');
    var costCenterDistributionAddRowBtn = document.getElementById('ccDistributionAddRowBtn');
    var costCenterDistributionSaveBtn = document.getElementById('ccDistributionSaveBtn');
    var costCenterDistributionRowTemplate = document.getElementById('costCenterDistributionRowTemplate');
    var defaultModalTitle = '';
    if (modalTitleEl) {
        defaultModalTitle = (modalTitleEl.textContent || '').trim();
    }
    var form = document.getElementById('classify-form');
    var addVatLineBtn = document.getElementById('addVatLineBtn');
    var aiSuggestBtn = document.getElementById('aiSuggestAccountsBtn');
    var aiSuggestCorrectionBtn = document.getElementById('aiSuggestCorrectionBtn');
    var entityPairAiInstructionsBtn = document.getElementById('entityPairAiInstructionsBtn');
    var classifyTableOverlay = document.getElementById('classifyTableOverlay');
    var aiSuggestionExplainBtn = document.getElementById('aiSuggestionExplainBtn');
    var vatRateRowTemplate = document.getElementById('vatRateRowTemplate');
    var customRateRowTemplate = document.getElementById('customRateRowTemplate');
    var rateInputs = {};
    var currentRateData = {};
    var currentCostCenters = {};
    var currentCostCenterBreakdowns = {};
    var currentTotalAccount = '';
    var currentIgnoreDetectedRates = false;
    var currentBankLoanConversionActive = false;
    var currentBankLoanResolvedAmounts = null;
    var currentBankLoanCapitalAccount = '';
    var currentClassificationModelName = '';
    var classificationModels = [];
    var currentEntityPairAiInstructions = '';
    var currentCanManageEntityPairAiInstructions = false;
    var currentDocumentFieldValues = {};
    var currentBankLoanDocumentLines = [];
    var documentFieldsGridEl = document.getElementById('classifyDocumentFieldsGrid');
    var documentFieldsPanelEl = document.getElementById('classifyDocumentFieldsPanel');
    var toggleDocumentFieldsSwitch = document.getElementById('toggleDocumentFieldsSwitch');
    var currentCostCenterDistributionRate = '';
    var totalAccountInput = document.getElementById('totalAccountInput');
    var classificationModelSelect = document.getElementById('classificationModelSelect');
    var applyClassificationModelBtn = document.getElementById('applyClassificationModelBtn');
    var deleteClassificationModelBtn = document.getElementById('deleteClassificationModelBtn');
    var saveClassificationModelSwitch = document.getElementById('saveClassificationModelSwitch');
    var classificationModelNameInput = document.getElementById('classificationModelNameInput');
    var emitterTypeSelect = document.getElementById('emitterTypeSelect');
    var storedRowRates = {};
    var storedDefaultRates = {};
    var originalRateValues = {};
    var serverOriginalRates = {};
    var removedRates = {};
    var dynamicRateCounter = 0;
    var originalRatesStoragePrefix = 'classificationOriginalRates:v1:';
    var currentOriginalRatesKey = null;
    var documentFieldDisplayOrder = [
        'FIELD_A',
        'FIELD_B',
        'FIELD_C',
        'FIELD_D',
        'FIELD_E',
        'FIELD_F',
        'FIELD_G',
        'FIELD_H',
        'FIELD_I1',
        'FIELD_I3',
        'FIELD_I4',
        'FIELD_I5',
        'FIELD_I6',
        'FIELD_I7',
        'FIELD_I8',
        'FIELD_N',
        'FIELD_O',
        'FIELD_Q',
        'FIELD_R'
    ];
    var documentFieldLabelMap = {
        FIELD_A: 'Emitente',
        FIELD_B: 'Adquirente',
        FIELD_C: 'NIF Emitente',
        FIELD_D: 'Tipo Documento',
        FIELD_E: 'Campo E',
        FIELD_F: 'Data Documento',
        FIELD_G: 'Numero Documento',
        FIELD_H: 'ATCUD / Ref.',
        FIELD_I1: 'Pais',
        FIELD_I3: 'Base 6%',
        FIELD_I4: 'IVA 6%',
        FIELD_I5: 'Base 13%',
        FIELD_I6: 'IVA 13%',
        FIELD_I7: 'Base 23%',
        FIELD_I8: 'IVA 23%',
        FIELD_N: 'Total IVA',
        FIELD_O: 'Total Documento',
        FIELD_Q: 'Campo Q',
        FIELD_R: 'Campo R'
    };
    var documentFieldDocTypeOptions = [
        { value: '', label: 'Selecionar tipo' },
        { value: 'FT', label: 'FT - Fatura' },
        { value: 'FR', label: 'FR - Fatura-Recibo' },
        { value: 'RC', label: 'RC - Recibo' },
        { value: 'NC', label: 'NC - Nota de Credito' },
        { value: 'ND', label: 'ND - Nota de Debito' }
    ];
    var documentFieldVatAutofillMap = {
        FIELD_I3: { target: 'FIELD_I4', rate: 6 },
        FIELD_I5: { target: 'FIELD_I6', rate: 13 },
        FIELD_I7: { target: 'FIELD_I8', rate: 23 }
    };
    var documentFieldHiddenKeys = {
        FIELD_C: true,
        FIELD_E: true,
        FIELD_I3: true,
        FIELD_I4: true,
        FIELD_I5: true,
        FIELD_I6: true,
        FIELD_I7: true,
        FIELD_I8: true,
        FIELD_I1: true,
        FIELD_N: true,
        FIELD_O: true,
        FIELD_Q: true,
        FIELD_R: true
    };
    var classificationAcquirerOptions = Array.isArray(window.classificationAcquirerOptions) ? window.classificationAcquirerOptions : [];
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
        '0': '0',
        '6': '6',
        '13': '13',
        '23': '23'
    };
    var defaultRates = Object.keys(defaultRateLabels);
    var qrFieldMatchPriority = ['FIELD_O', 'FIELD_N', 'FIELD_M', 'FIELD_I8', 'FIELD_I7', 'FIELD_I6', 'FIELD_I5', 'FIELD_I4', 'FIELD_I3', 'FIELD_R', 'FIELD_Q', 'FIELD_I2', 'FIELD_I1'];

    function clampCostCenterDistributionDialogPosition(left, top) {
        if (!costCenterDistributionModalEl || !costCenterDistributionDialogEl) {
            return { left: 0, top: 0 };
        }

        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var dialogRect = costCenterDistributionDialogEl.getBoundingClientRect();
        var dialogWidth = dialogRect.width || costCenterDistributionDialogEl.offsetWidth || 0;
        var dialogHeight = dialogRect.height || costCenterDistributionDialogEl.offsetHeight || 0;
        var minVisibleWidth = Math.min(Math.max(120, dialogWidth * 0.2), dialogWidth);
        var minVisibleHeight = Math.min(72, dialogHeight);
        var minLeft = Math.min(0, viewportWidth - dialogWidth);
        var maxLeft = Math.max(minLeft, viewportWidth - minVisibleWidth);
        var minTop = Math.min(0, viewportHeight - dialogHeight);
        var maxTop = Math.max(minTop, viewportHeight - minVisibleHeight);

        return {
            left: Math.min(Math.max(left, minLeft), maxLeft),
            top: Math.min(Math.max(top, minTop), maxTop)
        };
    }

    function setCostCenterDistributionDialogPosition(left, top) {
        if (!costCenterDistributionDialogEl) {
            return;
        }

        var position = clampCostCenterDistributionDialogPosition(left, top);
        costCenterDistributionDialogEl.style.left = position.left + 'px';
        costCenterDistributionDialogEl.style.top = position.top + 'px';
    }

    function fixCostCenterDistributionDialogSize() {
        if (!costCenterDistributionDialogEl) {
            return;
        }

        var rect = costCenterDistributionDialogEl.getBoundingClientRect();
        var width = rect.width || costCenterDistributionDialogEl.offsetWidth || 0;
        var height = rect.height || costCenterDistributionDialogEl.offsetHeight || 0;
        if (width > 0 && !costCenterDistributionDialogEl.style.width) {
            costCenterDistributionDialogEl.style.width = width + 'px';
        }
        if (height > 0 && !costCenterDistributionDialogEl.style.height) {
            costCenterDistributionDialogEl.style.height = height + 'px';
        }
    }

    function resetCostCenterDistributionDialogPosition() {
        if (!costCenterDistributionDialogEl) {
            return;
        }

        fixCostCenterDistributionDialogSize();
        costCenterDistributionDialogEl.style.position = 'fixed';
        costCenterDistributionDialogEl.style.margin = '0';
        costCenterDistributionDialogEl.style.transform = 'none';

        var viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
        var viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
        var dialogWidth = costCenterDistributionDialogEl.offsetWidth || 0;
        var dialogHeight = costCenterDistributionDialogEl.offsetHeight || 0;
        var left = Math.max((viewportWidth - dialogWidth) / 2, 0);
        var top = Math.max(Math.min((viewportHeight - dialogHeight) / 2, 24), 16);
        setCostCenterDistributionDialogPosition(left, top);
    }

    function initializeCostCenterDistributionDrag() {
        if (!costCenterDistributionModalEl || !costCenterDistributionDialogEl || !costCenterDistributionHeaderEl) {
            return;
        }
        if (costCenterDistributionModalEl.__dragInitialized) {
            return;
        }

        var dragState = {
            active: false,
            offsetX: 0,
            offsetY: 0
        };

        function getPointerPoint(event) {
            if (event.touches && event.touches.length) {
                return event.touches[0];
            }
            if (event.changedTouches && event.changedTouches.length) {
                return event.changedTouches[0];
            }
            return event;
        }

        function shouldIgnoreDragStart(target) {
            if (!target || !(target instanceof Element)) {
                return false;
            }
            return Boolean(target.closest('button, .btn, a, input, select, textarea, label'));
        }

        function startDrag(event) {
            if (event.type === 'mousedown' && event.button !== 0) {
                return;
            }
            if (shouldIgnoreDragStart(event.target)) {
                return;
            }

            var point = getPointerPoint(event);
            var rect = costCenterDistributionDialogEl.getBoundingClientRect();
            dragState.active = true;
            dragState.offsetX = point.clientX - rect.left;
            dragState.offsetY = point.clientY - rect.top;
            costCenterDistributionModalEl.classList.add('is-dragging');

            if (event.cancelable) {
                event.preventDefault();
            }
        }

        function moveDrag(event) {
            if (!dragState.active) {
                return;
            }

            var point = getPointerPoint(event);
            setCostCenterDistributionDialogPosition(
                point.clientX - dragState.offsetX,
                point.clientY - dragState.offsetY
            );

            if (event.cancelable) {
                event.preventDefault();
            }
        }

        function stopDrag() {
            if (!dragState.active) {
                return;
            }
            dragState.active = false;
            costCenterDistributionModalEl.classList.remove('is-dragging');
        }

        costCenterDistributionHeaderEl.addEventListener('mousedown', startDrag);
        costCenterDistributionHeaderEl.addEventListener('touchstart', startDrag, { passive: false });
        document.addEventListener('mousemove', moveDrag);
        document.addEventListener('touchmove', moveDrag, { passive: false });
        document.addEventListener('mouseup', stopDrag);
        document.addEventListener('touchend', stopDrag);
        document.addEventListener('touchcancel', stopDrag);

        costCenterDistributionModalEl.addEventListener('shown.bs.modal', function() {
            resetCostCenterDistributionDialogPosition();
        });
        costCenterDistributionModalEl.addEventListener('hidden.bs.modal', function() {
            stopDrag();
        });
        window.addEventListener('resize', function() {
            if (!costCenterDistributionModalEl.classList.contains('show')) {
                return;
            }
            var rect = costCenterDistributionDialogEl.getBoundingClientRect();
            setCostCenterDistributionDialogPosition(rect.left, rect.top);
        });

        costCenterDistributionModalEl.__dragInitialized = true;
    }

    initializeCostCenterDistributionDrag();
    var erpWebserviceUrl = typeof window.erpWebserviceUrl === 'string' ? window.erpWebserviceUrl.trim() : '';
    var erpWebserviceToken = typeof window.erpWebserviceToken === 'string' ? window.erpWebserviceToken.trim() : '';
    var erpBaseCompany = typeof window.erpBaseCompany === 'string' ? window.erpBaseCompany.trim() : '';
    var erpDefaultDatabase = typeof window.erpDefaultDatabase === 'string' ? window.erpDefaultDatabase.trim() : '';

    function resetClassifyDocumentPreview() {
        if (classifyDocumentPreviewFrame) {
            classifyDocumentPreviewFrame.removeAttribute('src');
            classifyDocumentPreviewFrame.classList.add('d-none');
        }
        if (classifyDocumentPreviewEmpty) {
            classifyDocumentPreviewEmpty.style.display = 'none';
        }
        if (classifyDocumentOpenBtn) {
            classifyDocumentOpenBtn.setAttribute('href', '#');
            classifyDocumentOpenBtn.classList.add('d-none');
        }
    }

    function setClassifyDocumentPreview(fileUrl) {
        resetClassifyDocumentPreview();

        var normalizedUrl = typeof fileUrl === 'string' ? fileUrl.trim() : '';
        if (!normalizedUrl) {
            if (classifyDocumentPreviewEmpty) {
                classifyDocumentPreviewEmpty.style.display = 'flex';
            }
            return;
        }

        if (classifyDocumentOpenBtn) {
            classifyDocumentOpenBtn.setAttribute('href', normalizedUrl);
            classifyDocumentOpenBtn.classList.remove('d-none');
        }

        if (classifyDocumentPreviewFrame) {
            classifyDocumentPreviewFrame.src = normalizedUrl;
            classifyDocumentPreviewFrame.classList.remove('d-none');
        } else if (classifyDocumentPreviewEmpty) {
            classifyDocumentPreviewEmpty.style.display = 'flex';
        }
    }
    var planAccountsCache = {};
    var planAutocompleteCounter = 0;
    var currentPlanContext = {
        documentId: '',
        acquirerNif: '',
        database: '',
        exercise: String(new Date().getFullYear()),
        cacheKey: '',
        loadingPromise: null,
        entries: [],
        lastError: ''
    };

    refreshButtonClasses();
    table.on('draw.dt', refreshButtonClasses);

    function normalizePlanSearchToken(value) {
        var text = String(value || '').toLowerCase();
        if (typeof text.normalize === 'function') {
            text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return text.trim();
    }

    function extractErpRowsFromPayloadClient(payload) {
        if (!payload || typeof payload !== 'object') {
            return [];
        }
        var keys = ['aaData', 'data', 'result', 'results'];
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            if (Array.isArray(payload[key])) {
                return payload[key];
            }
        }
        if (Array.isArray(payload)) {
            return payload;
        }
        return [];
    }

    function resolvePlanAccountCode(row) {
        if (!row || typeof row !== 'object') {
            return '';
        }
        var candidates = [
            'strConta', 'strconta', 'conta', 'account', 'codigo', 'strCodConta', 'strcodconta',
            'strConta_Iva', 'strconta_iva', 'conta_iva'
        ];
        for (var i = 0; i < candidates.length; i += 1) {
            var key = candidates[i];
            if (!Object.prototype.hasOwnProperty.call(row, key)) {
                continue;
            }
            var code = String(row[key] || '').trim();
            if (code !== '') {
                return code;
            }
        }
        return '';
    }

    function resolvePlanAccountLabel(row, accountCode) {
        var description = '';
        var keys = ['strDescricao', 'strdescricao', 'descricao', 'description', 'strDesignacao', 'strdesignacao', 'strDescConta', 'strdescconta', 'name'];
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            if (!row || typeof row !== 'object' || !Object.prototype.hasOwnProperty.call(row, key)) {
                continue;
            }
            var value = String(row[key] || '').trim();
            if (value !== '') {
                description = value;
                break;
            }
        }
        return description !== '' ? (accountCode + ' - ' + description) : accountCode;
    }

    function resolvePlanAccountDescription(row) {
        if (!row || typeof row !== 'object') {
            return '';
        }
        var keys = ['strDescricao', 'strdescricao', 'descricao', 'description', 'strDesignacao', 'strdesignacao', 'strDescConta', 'strdescconta', 'name'];
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            if (!Object.prototype.hasOwnProperty.call(row, key)) {
                continue;
            }
            var value = String(row[key] || '').trim();
            if (value !== '') {
                return value;
            }
        }
        return '';
    }

    function normalizePlanEntries(rows) {
        var entries = [];
        var seen = {};
        rows.forEach(function(row) {
            if (!row || typeof row !== 'object') {
                return;
            }
            var accountCode = resolvePlanAccountCode(row);
            if (accountCode === '' || Object.prototype.hasOwnProperty.call(seen, accountCode)) {
                return;
            }
            seen[accountCode] = true;
            var description = resolvePlanAccountDescription(row);
            var label = resolvePlanAccountLabel(row, accountCode);
            entries.push({
                code: accountCode,
                label: label,
                description: description,
                search: normalizePlanSearchToken(accountCode + ' ' + label),
                descriptionSearch: normalizePlanSearchToken(description)
            });
        });
        return entries;
    }

    function buildPlanCacheKey(context) {
        return [
            String(context.database || ''),
            String(context.acquirerNif || ''),
            String(context.exercise || ''),
            String(erpBaseCompany || '')
        ].join('|');
    }

    function buildPlanContasUrl(context) {
        if (erpWebserviceUrl === '') {
            return '';
        }
        var base = erpWebserviceUrl.replace(/\/+$/, '');
        var endpoint = base + '/contabilidade/planocontas';
        var params = new URLSearchParams();
        params.set('limit', '500');
        params.set('offset', '0');
        if (context.exercise) {
            params.set('strCodExercicio', String(context.exercise));
        }
        if (context.acquirerNif) {
            params.set('strNumContrib', String(context.acquirerNif));
        }
        if (context.database) {
            params.set('db', String(context.database));
            params.set('bd', String(context.database));
        }
        if (erpBaseCompany) {
            params.set('EMP', erpBaseCompany);
        }
        return endpoint + '?' + params.toString();
    }

    function buildPlanContasLookupUrl(context, accountCode) {
        var normalizedCode = String(accountCode || '').trim();
        if (!normalizedCode || erpWebserviceUrl === '') {
            return '';
        }
        var base = erpWebserviceUrl.replace(/\/+$/, '');
        var endpoint = base + '/contabilidade/planocontas';
        var params = new URLSearchParams();
        params.set('limit', '10');
        params.set('offset', '0');
        params.set('strCodExercicio', String(context.exercise || new Date().getFullYear()));
        params.set('strConta', normalizedCode);
        if (context.database) {
            params.set('db', String(context.database));
            params.set('bd', String(context.database));
        }
        if (erpBaseCompany) {
            params.set('EMP', erpBaseCompany);
        }
        return endpoint + '?' + params.toString();
    }

    function buildPlanContasSearchUrl(context, queryText, limit) {
        var normalizedQuery = String(queryText || '').trim();
        if (!normalizedQuery || erpWebserviceUrl === '') {
            return '';
        }
        var base = erpWebserviceUrl.replace(/\/+$/, '');
        var endpoint = base + '/contabilidade/planocontas';
        var params = new URLSearchParams();
        params.set('limit', String(limit && limit > 0 ? limit : 20));
        params.set('offset', '0');
        params.set('strCodExercicio', String(context.exercise || new Date().getFullYear()));
        params.set('q', normalizedQuery);
        params.set('srchType', 'startswith');
        params.set('searchField', 'strConta');
        if (context.database) {
            params.set('db', String(context.database));
            params.set('bd', String(context.database));
        }
        if (erpBaseCompany) {
            params.set('EMP', erpBaseCompany);
        }
        return endpoint + '?' + params.toString();
    }

    function fetchPlanEntries(context) {
        var url = buildPlanContasUrl(context);
        if (url === '' || erpWebserviceToken === '') {
            return Promise.resolve([]);
        }
        return fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-API-KEY': erpWebserviceToken
            },
            credentials: 'omit'
        }).then(function(res) {
            return res.text().then(function(text) {
                if (!res.ok) {
                    throw new Error('Falha ao consultar plano de contas ERP (HTTP ' + res.status + ').');
                }
                if (!text || !text.trim()) {
                    return [];
                }
                var payload = {};
                try {
                    payload = JSON.parse(text);
                } catch (err) {
                    throw new Error('Resposta inválida do plano de contas ERP.');
                }
                var rows = extractErpRowsFromPayloadClient(payload);
                return normalizePlanEntries(rows);
            });
        });
    }

    function fetchPlanEntryByCodeRemote(code) {
        var normalizedCode = String(code || '').trim();
        if (!normalizedCode) {
            return Promise.resolve(null);
        }
        var url = buildPlanContasLookupUrl(currentPlanContext, normalizedCode);
        if (url === '' || erpWebserviceToken === '') {
            return Promise.resolve(null);
        }
        return fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-API-KEY': erpWebserviceToken
            },
            credentials: 'omit'
        }).then(function(res) {
            return res.text().then(function(text) {
                if (!res.ok || !text || !text.trim()) {
                    return null;
                }
                var payload = {};
                try {
                    payload = JSON.parse(text);
                } catch (err) {
                    return null;
                }
                var rows = extractErpRowsFromPayloadClient(payload);
                var entries = normalizePlanEntries(rows);
                for (var i = 0; i < entries.length; i += 1) {
                    if (String(entries[i].code || '').trim() === normalizedCode) {
                        var alreadyExists = !!findPlanEntryByCode(normalizedCode);
                        if (!alreadyExists) {
                            currentPlanContext.entries.push(entries[i]);
                            currentPlanContext.cacheKey = buildPlanCacheKey(currentPlanContext);
                            if (currentPlanContext.cacheKey) {
                                planAccountsCache[currentPlanContext.cacheKey] = currentPlanContext.entries.slice();
                            }
                        }
                        return entries[i];
                    }
                }
                return null;
            });
        }).catch(function() {
            return null;
        });
    }

    function mergePlanEntriesIntoContext(entries) {
        if (!Array.isArray(entries) || !entries.length) {
            return;
        }
        var existingByCode = {};
        (currentPlanContext.entries || []).forEach(function(entry) {
            if (!entry || !entry.code) {
                return;
            }
            existingByCode[String(entry.code).trim()] = true;
        });
        entries.forEach(function(entry) {
            if (!entry || !entry.code) {
                return;
            }
            var code = String(entry.code).trim();
            if (!code || existingByCode[code]) {
                return;
            }
            existingByCode[code] = true;
            currentPlanContext.entries.push(entry);
        });
        currentPlanContext.cacheKey = buildPlanCacheKey(currentPlanContext);
        if (currentPlanContext.cacheKey) {
            planAccountsCache[currentPlanContext.cacheKey] = currentPlanContext.entries.slice();
        }
    }

    function searchPlanEntriesRemote(queryText, limit) {
        var normalizedQuery = String(queryText || '').trim();
        if (!normalizedQuery) {
            return Promise.resolve([]);
        }
        var url = buildPlanContasSearchUrl(currentPlanContext, normalizedQuery, limit || 20);
        if (url === '' || erpWebserviceToken === '') {
            return Promise.resolve([]);
        }
        return fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-API-KEY': erpWebserviceToken
            },
            credentials: 'omit'
        }).then(function(res) {
            return res.text().then(function(text) {
                if (!res.ok || !text || !text.trim()) {
                    return [];
                }
                var payload = {};
                try {
                    payload = JSON.parse(text);
                } catch (err) {
                    return [];
                }
                var rows = extractErpRowsFromPayloadClient(payload);
                var entries = normalizePlanEntries(rows);
                mergePlanEntriesIntoContext(entries);
                return entries;
            });
        }).catch(function() {
            return [];
        });
    }

    function fetchAcquirerDatabaseForCurrentDocument() {
        if (!currentPlanContext.documentId) {
            return Promise.resolve('');
        }
        return fetchJson('contabilidade/classificacao-importacao/acquirer-database', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                ids: [currentPlanContext.documentId],
                import_type: importType,
                mode: 'check',
                allow_classified_flow: isClassificationOnlyView ? 1 : 0,
                csrf_token: csrfInput ? csrfInput.value : ''
            })
        }).then(function(res) {
            if (res && res.csrf_token && csrfInput) {
                csrfInput.value = res.csrf_token;
            }
            if (!res || res.success === false) {
                return '';
            }
            var entity = res.entity && typeof res.entity === 'object' ? res.entity : {};
            return String(entity.erp_database || '').trim();
        }).catch(function() {
            return '';
        });
    }

    function ensurePlanContextLoaded() {
        if (currentPlanContext.entries.length > 0) {
            return Promise.resolve(currentPlanContext.entries);
        }
        if (currentPlanContext.loadingPromise) {
            return currentPlanContext.loadingPromise;
        }
        if (currentPlanContext.database) {
            currentPlanContext.cacheKey = buildPlanCacheKey(currentPlanContext);
            if (currentPlanContext.cacheKey && Array.isArray(planAccountsCache[currentPlanContext.cacheKey])) {
                currentPlanContext.entries = planAccountsCache[currentPlanContext.cacheKey].slice();
                return Promise.resolve(currentPlanContext.entries);
            }
            currentPlanContext.loadingPromise = fetchPlanEntries(currentPlanContext)
                .then(function(entries) {
                    currentPlanContext.entries = entries;
                    if (currentPlanContext.cacheKey) {
                        planAccountsCache[currentPlanContext.cacheKey] = entries.slice();
                    }
                    return entries;
                })
                .catch(function(err) {
                    currentPlanContext.lastError = err && err.message ? err.message : 'Falha ao carregar plano de contas.';
                    return [];
                })
                .finally(function() {
                    currentPlanContext.loadingPromise = null;
                });
            return currentPlanContext.loadingPromise;
        }
        currentPlanContext.loadingPromise = fetchAcquirerDatabaseForCurrentDocument()
            .then(function(database) {
                currentPlanContext.database = database || '';
                currentPlanContext.cacheKey = buildPlanCacheKey(currentPlanContext);
                if (currentPlanContext.cacheKey && Array.isArray(planAccountsCache[currentPlanContext.cacheKey])) {
                    currentPlanContext.entries = planAccountsCache[currentPlanContext.cacheKey].slice();
                    return currentPlanContext.entries;
                }
                return fetchPlanEntries(currentPlanContext).then(function(entries) {
                    currentPlanContext.entries = entries;
                    if (currentPlanContext.cacheKey) {
                        planAccountsCache[currentPlanContext.cacheKey] = entries.slice();
                    }
                    return entries;
                });
            })
            .catch(function(err) {
                currentPlanContext.lastError = err && err.message ? err.message : 'Falha ao carregar plano de contas.';
                return [];
            })
            .finally(function() {
                currentPlanContext.loadingPromise = null;
            });
        return currentPlanContext.loadingPromise;
    }

    function filterPlanEntries(entries, query, limit) {
        var token = normalizePlanSearchToken(query);
        var hasLetters = /[a-z\u00c0-\u024f]/i.test(token);
        var maxItems = typeof limit === 'number' && limit > 0 ? limit : 15;
        if (!Array.isArray(entries) || entries.length === 0) {
            return [];
        }
        var ranked = [];
        entries.forEach(function(entry) {
            if (!entry || !entry.code) {
                return;
            }
            var score = 0;
            if (token === '') {
                score = 1;
            } else if (hasLetters) {
                if (entry.descriptionSearch.indexOf(token) === 0) {
                    score = 120;
                } else if (entry.descriptionSearch.indexOf(token) !== -1) {
                    score = 90;
                }
            } else if (entry.code.toLowerCase().indexOf(token) === 0) {
                score = 110;
            } else if (entry.search.indexOf(token) !== -1) {
                score = 60;
            }
            if (score > 0) {
                ranked.push({ entry: entry, score: score });
            }
        });
        ranked.sort(function(a, b) {
            if (b.score !== a.score) {
                return b.score - a.score;
            }
            return a.entry.code.localeCompare(b.entry.code);
        });
        return ranked.slice(0, maxItems).map(function(item) { return item.entry; });
    }

    function getRateKeyFromPlanInput(input) {
        if (!input || typeof input.closest !== 'function') {
            return '';
        }
        var row = input.closest('tr[data-rate]');
        if (!row) {
            return '';
        }
        return String(row.getAttribute('data-rate') || '').trim();
    }

    function rankPlanEntriesForInput(input, entries) {
        if (!input || !Array.isArray(entries) || entries.length === 0) {
            return Array.isArray(entries) ? entries : [];
        }
        if (!input.classList || !input.classList.contains('general-account-field')) {
            return entries;
        }
        var rate = getRateKeyFromPlanInput(input);
        var expectedType = getExpectedPlanRateType(rate);
        if (expectedType === '') {
            return entries;
        }
        return entries.slice().sort(function(a, b) {
            var aType = detectRateTypeFromPlanDescription(a && a.description ? a.description : '');
            var bType = detectRateTypeFromPlanDescription(b && b.description ? b.description : '');
            var aScore = aType === expectedType ? 2 : (aType === '' ? 1 : 0);
            var bScore = bType === expectedType ? 2 : (bType === '' ? 1 : 0);
            if (bScore !== aScore) {
                return bScore - aScore;
            }
            return String(a && a.code ? a.code : '').localeCompare(String(b && b.code ? b.code : ''));
        });
    }

    function renderPlanAutocompleteOptions(input, entries) {
        if (!input) {
            return;
        }
        var listId = input.getAttribute('data-plan-list-id');
        if (!listId) {
            planAutocompleteCounter += 1;
            listId = 'planAccountList' + planAutocompleteCounter;
            input.setAttribute('data-plan-list-id', listId);
            input.setAttribute('list', listId);
            var dataList = document.createElement('datalist');
            dataList.id = listId;
            document.body.appendChild(dataList);
        }
        var list = document.getElementById(listId);
        if (!list) {
            return;
        }
        list.innerHTML = '';
        entries.forEach(function(entry) {
            var option = document.createElement('option');
            option.value = entry.code;
            option.label = entry.label;
            list.appendChild(option);
        });
    }

    function findPlanEntryByCode(code) {
        var target = String(code || '').trim();
        if (!target) {
            return null;
        }
        var entries = Array.isArray(currentPlanContext.entries) ? currentPlanContext.entries : [];
        for (var i = 0; i < entries.length; i += 1) {
            var entry = entries[i];
            if (entry && String(entry.code || '').trim() === target) {
                return entry;
            }
        }
        return null;
    }

    function detectRateTypeFromPlanDescription(description) {
        var normalized = normalizePlanSearchToken(description);
        if (normalized === '') {
            return '';
        }
        if (normalized.indexOf('reduzid') !== -1) {
            return 'reduced';
        }
        if (normalized.indexOf('intermed') !== -1) {
            return 'intermediate';
        }
        if (normalized.indexOf('normal') !== -1) {
            return 'normal';
        }
        return '';
    }

    function getExpectedPlanRateType(rate) {
        var normalizedRate = String(rate || '').trim();
        if (normalizedRate === '6') {
            return 'reduced';
        }
        if (normalizedRate === '13') {
            return 'intermediate';
        }
        if (normalizedRate === '23') {
            return 'normal';
        }
        return '';
    }

    function sanitizeAccountCodeForRate(accountCode, rate) {
        var normalizedCode = String(accountCode || '').trim();
        if (normalizedCode === '') {
            return '';
        }
        var expectedType = getExpectedPlanRateType(rate);
        if (expectedType === '') {
            return normalizedCode;
        }
        var entry = findPlanEntryByCode(normalizedCode);
        if (!entry) {
            return normalizedCode;
        }
        var detectedType = detectRateTypeFromPlanDescription(entry.description || '');
        if (detectedType !== '' && detectedType !== expectedType) {
            return '';
        }
        return normalizedCode;
    }

    function clearPlanValidationState() {
        Object.keys(rateInputs).forEach(function(rate) {
            var info = rateInputs[rate] || {};
            [info.generalAccount, info.ivaAccount].forEach(function(input) {
                if (!input) {
                    return;
                }
                input.classList.remove('input-error');
                if (input.dataset && Object.prototype.hasOwnProperty.call(input.dataset, 'planValidationTitle')) {
                    input.setAttribute('title', input.dataset.planValidationTitle || '');
                    delete input.dataset.planValidationTitle;
                    if (input.getAttribute('title') === '') {
                        input.removeAttribute('title');
                    }
                }
            });
        });
        if (totalAccountInput) {
            totalAccountInput.classList.remove('input-error');
            if (totalAccountInput.dataset && Object.prototype.hasOwnProperty.call(totalAccountInput.dataset, 'planValidationTitle')) {
                totalAccountInput.setAttribute('title', totalAccountInput.dataset.planValidationTitle || '');
                delete totalAccountInput.dataset.planValidationTitle;
                if (totalAccountInput.getAttribute('title') === '') {
                    totalAccountInput.removeAttribute('title');
                }
            }
        }
    }

    function markInvalidPlanInput(input, message) {
        if (!input) {
            return;
        }
        var currentTitle = input.getAttribute('title') || '';
        if (input.dataset && !Object.prototype.hasOwnProperty.call(input.dataset, 'planValidationTitle')) {
            input.dataset.planValidationTitle = currentTitle;
        }
        input.classList.add('input-error');
        input.setAttribute('title', String(message || '').trim());
    }

    function validateAccountsAgainstCurrentPlan(ratesPayload, totalAccountValue) {
        clearPlanValidationState();
        return ensurePlanContextLoaded().then(function(entries) {
            var planEntries = Array.isArray(entries) ? entries : [];
            if (!planEntries.length) {
                if (currentPlanContext.lastError) {
                    return {
                        ok: false,
                        error: currentPlanContext.lastError
                    };
                }
                return { ok: true, invalid: [] };
            }

            var accountsToCheck = [];
            Object.keys(ratesPayload || {}).forEach(function(rate) {
                var payload = ratesPayload[rate] || {};
                var info = rateInputs[rate] || {};
                var generalAccount = String(payload.general_account || '').trim();
                var ivaAccount = String(payload.iva_account || '').trim();
                if (generalAccount) {
                    accountsToCheck.push({
                        code: generalAccount,
                        label: 'Conta geral ' + generalAccount + ' (' + getRateLabel(rate) + ')',
                        input: info.generalAccount || null
                    });
                }
                if (ivaAccount) {
                    accountsToCheck.push({
                        code: ivaAccount,
                        label: 'Conta IVA ' + ivaAccount + ' (' + getRateLabel(rate) + ')',
                        input: info.ivaAccount || null
                    });
                }
            });
            if (totalAccountValue) {
                accountsToCheck.push({
                    code: totalAccountValue,
                    label: 'Conta total ' + totalAccountValue,
                    input: totalAccountInput || null
                });
            }

            var invalid = [];
            var lookupPromises = accountsToCheck.map(function(item) {
                var existing = findPlanEntryByCode(item.code);
                if (existing) {
                    return Promise.resolve(true);
                }
                return fetchPlanEntryByCodeRemote(item.code).then(function(found) {
                    return !!found;
                }).then(function(found) {
                    if (!found) {
                        invalid.push(item);
                    }
                    return found;
                });
            });

            return Promise.all(lookupPromises).then(function() {
                if (invalid.length) {
                    invalid.forEach(function(item) {
                        markInvalidPlanInput(item.input, 'Conta não encontrada no plano da base ERP atual.');
                    });
                }

                return {
                    ok: invalid.length === 0,
                    invalid: invalid
                };
            });
        });
    }

    function updatePlanInputTitle(input) {
        if (!input) {
            return;
        }
        var code = String(input.value || '').trim();
        if (!code) {
            input.removeAttribute('title');
            return;
        }
        var applyTitle = function() {
            var entry = findPlanEntryByCode(code);
            var title = entry && entry.description ? String(entry.description).trim() : '';
            if (!title && entry && entry.label) {
                title = String(entry.label).trim();
            }
            if (title) {
                input.setAttribute('title', title);
            } else {
                input.removeAttribute('title');
            }
        };
        if (currentPlanContext.entries.length > 0) {
            applyTitle();
            return;
        }
        ensurePlanContextLoaded().then(function() {
            applyTitle();
        });
    }

    function schedulePlanAutocomplete(input) {
        if (!input) {
            return;
        }
        var timeoutHandle = input.__planAutocompleteTimer || null;
        if (timeoutHandle) {
            window.clearTimeout(timeoutHandle);
        }
        input.__planAutocompleteTimer = window.setTimeout(function() {
            ensurePlanContextLoaded().then(function(entries) {
                var query = String(input.value || '').trim();
                if (query !== '') {
                    searchPlanEntriesRemote(query, 20).then(function(remoteEntries) {
                        var mergedEntries = Array.isArray(remoteEntries) && remoteEntries.length
                            ? remoteEntries
                            : filterPlanEntries(entries, query, 20);
                        renderPlanAutocompleteOptions(input, rankPlanEntriesForInput(input, mergedEntries));
                    });
                    return;
                }
                var filtered = filterPlanEntries(entries, query, 20);
                renderPlanAutocompleteOptions(input, rankPlanEntriesForInput(input, filtered));
            });
        }, 180);
    }

    function attachPlanAutocompleteToInput(input) {
        if (!input || input.__planAutocompleteAttached) {
            return;
        }
        input.__planAutocompleteAttached = true;
        input.setAttribute('autocomplete', 'off');
        input.addEventListener('input', function() {
            schedulePlanAutocomplete(input);
            updatePlanInputTitle(input);
        });
        input.addEventListener('focus', function() {
            schedulePlanAutocomplete(input);
            updatePlanInputTitle(input);
        });
        input.addEventListener('blur', function() {
            updatePlanInputTitle(input);
        });
    }

    function updateCurrentPlanContextFromButton(btn) {
        var docId = btn ? String(btn.getAttribute('data-id') || '').trim() : '';
        var acquirerRaw = btn ? String(btn.getAttribute('data-acquirer') || '').trim() : '';
        var acquirerDb = btn ? String(btn.getAttribute('data-acquirer-db') || '').trim() : '';
        var acquirerNif = acquirerRaw.replace(/\D+/g, '');
        currentPlanContext.documentId = docId;
        currentPlanContext.acquirerNif = acquirerNif;
        currentPlanContext.database = acquirerDb;
        currentPlanContext.cacheKey = '';
        currentPlanContext.entries = [];
        currentPlanContext.loadingPromise = null;
        currentPlanContext.lastError = '';
    }

    if (totalAccountInput) {
        attachPlanAutocompleteToInput(totalAccountInput);
    }

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

    function copyRateHiddenMetadata(target, source) {
        if (!target || typeof target !== 'object' || !source || typeof source !== 'object') {
            return;
        }
        var rubricCode = String(source.erp_rubric_code || source.rubric_code || '').trim();
        if (rubricCode) {
            target.erp_rubric_code = rubricCode;
        }
        var adjustedFlag = String(source.vat_amounts_adjusted || '').trim().toLowerCase();
        if (adjustedFlag === '1' || adjustedFlag === 'true') {
            target.vat_amounts_adjusted = '1';
        }
        var bankLoanFlag = String(source.bank_loan_conversion || '').trim().toLowerCase();
        if (bankLoanFlag === '1' || bankLoanFlag === 'true') {
            target.bank_loan_conversion = '1';
        }
    }

    function isAdjustedVatRateEntry(entry) {
        if (!entry || typeof entry !== 'object') {
            return false;
        }
        var adjustedFlag = String(entry.vat_amounts_adjusted || '').trim().toLowerCase();
        return adjustedFlag === '1' || adjustedFlag === 'true';
    }

    function resolveRateRubricCode(baseData, rowData, defaultData) {
        var candidates = [rowData, defaultData, baseData];
        for (var i = 0; i < candidates.length; i += 1) {
            var candidate = candidates[i];
            if (!candidate || typeof candidate !== 'object') {
                continue;
            }
            var rubricCode = String(candidate.erp_rubric_code || candidate.rubric_code || '').trim();
            if (rubricCode !== '') {
                return rubricCode;
            }
        }
        return '';
    }

    function resolveRateAdjustedFlag(baseData, rowData, defaultData, rubricCode) {
        if (!isFuelRubricCode(rubricCode)) {
            return '0';
        }
        var candidates = [rowData, defaultData, baseData];
        for (var i = 0; i < candidates.length; i += 1) {
            var candidate = candidates[i];
            if (!candidate || typeof candidate !== 'object') {
                continue;
            }
            if (isAdjustedVatRateEntry(candidate)) {
                return '1';
            }
        }
        return '0';
    }

    function preserveAdjustedDisplayRates(targetRates, sourceRates) {
        if (!targetRates || typeof targetRates !== 'object' || !sourceRates || typeof sourceRates !== 'object') {
            return;
        }
        Object.keys(sourceRates).forEach(function(rate) {
            var sourceEntry = sourceRates[rate];
            if (!isAdjustedVatRateEntry(sourceEntry)) {
                return;
            }
            if (!targetRates[rate] || typeof targetRates[rate] !== 'object') {
                targetRates[rate] = {};
            }
            var targetEntry = targetRates[rate];
            if (!isAdjustedVatRateEntry(targetEntry)) {
                var baseValue = getEntryAmount(sourceEntry, 'base');
                if (baseValue !== null && baseValue !== undefined) {
                    targetEntry.base = String(baseValue);
                    targetEntry.base_value = String(baseValue);
                }
                var ivaValue = getEntryAmount(sourceEntry, 'iva');
                if (ivaValue !== null && ivaValue !== undefined) {
                    targetEntry.iva = String(ivaValue);
                    targetEntry.iva_value = String(ivaValue);
                }
            }
            copyRateHiddenMetadata(targetEntry, sourceEntry);
        });
    }

    function preserveAdjustedOriginalRates(originalRates, sourceRates) {
        var result = originalRates && typeof originalRates === 'object' ? originalRates : {};
        if (!sourceRates || typeof sourceRates !== 'object') {
            return result;
        }
        Object.keys(sourceRates).forEach(function(rate) {
            var sourceEntry = sourceRates[rate];
            if (!isAdjustedVatRateEntry(sourceEntry)) {
                return;
            }
            var baseValue = getEntryAmount(sourceEntry, 'base');
            var ivaValue = getEntryAmount(sourceEntry, 'iva');
            result[String(rate)] = {
                base: baseValue !== null && baseValue !== undefined ? String(baseValue) : '',
                iva: ivaValue !== null && ivaValue !== undefined ? String(ivaValue) : ''
            };
        });
        return result;
    }

    function getResolvedRateAccountValue(rate, field) {
        var info = rateInputs[rate] || null;
        var inputEl = null;
        if (field === 'general_account') {
            inputEl = info ? info.generalAccount : null;
        } else if (field === 'iva_account') {
            inputEl = info ? info.ivaAccount : null;
        }
        if (inputEl && String(inputEl.value || '').trim() !== '') {
            return String(inputEl.value || '').trim();
        }

        var rateData = ensureRateData(rate);
        if (String(rateData[field] || '').trim() !== '') {
            return String(rateData[field] || '').trim();
        }
        if (storedRowRates[rate] && String(storedRowRates[rate][field] || '').trim() !== '') {
            return String(storedRowRates[rate][field] || '').trim();
        }
        if (storedDefaultRates[rate] && String(storedDefaultRates[rate][field] || '').trim() !== '') {
            return String(storedDefaultRates[rate][field] || '').trim();
        }

        return '';
    }

    function isCompletedAccountingAccountValue(value) {
        return /^\d{3,}$/.test(String(value || '').trim());
    }

    function shouldApplyFuelRubricAdjustmentForRate(rate) {
        var normalizedRate = normalizeRateToken(rate);
        if (normalizedRate !== null && Math.abs(normalizedRate) < 0.00001) {
            return false;
        }

        var percentage = getRatePercentage(rate);
        if (percentage === null || Math.abs(percentage) < 0.00001) {
            return false;
        }

        var rateData = ensureRateData(rate);
        var rubricCode = String(rateData.erp_rubric_code || '').trim();
        if (!rubricCode && storedRowRates[rate]) {
            rubricCode = String(storedRowRates[rate].erp_rubric_code || '').trim();
        }
        if (!rubricCode && storedDefaultRates[rate]) {
            rubricCode = String(storedDefaultRates[rate].erp_rubric_code || '').trim();
        }
        if (!rubricCode) {
            return false;
        }
        if (!isFuelRubricCode(rubricCode)) {
            return false;
        }

        return isCompletedAccountingAccountValue(getResolvedRateAccountValue(rate, 'general_account'))
            && isCompletedAccountingAccountValue(getResolvedRateAccountValue(rate, 'iva_account'));
    }

    function isFuelCompanionRate(rate) {
        return String(rate || '').indexOf('fuel_nodedut_') === 0;
    }

    function ensureFuelCompanionLine(primaryRate, nonDeductibleIva) {
        var companionKey = 'fuel_nodedut_' + primaryRate;
        var generalAccount = getResolvedRateAccountValue(primaryRate, 'general_account');
        var formattedAmount = formatDecimalValue(nonDeductibleIva);

        var info = ensureRateRow(companionKey, { label: '0' }, { allowCreate: true });
        if (!info) {
            return;
        }
        var data = ensureRateData(companionKey);
        data.base = formattedAmount;
        data.base_value = formattedAmount;
        data.iva = '';
        data.iva_value = '';
        data.general_account = generalAccount;
        data.iva_account = '';

        if (info.base && info.base.value !== formattedAmount) {
            info.base.value = formattedAmount;
        }
        if (info.iva && info.iva.value !== '') {
            info.iva.value = '';
        }
        if (info.generalAccount && info.generalAccount.value !== generalAccount) {
            info.generalAccount.value = generalAccount;
            updatePlanInputTitle(info.generalAccount);
        }
        if (info.ivaAccount && info.ivaAccount.value !== '') {
            info.ivaAccount.value = '';
        }
        updateRowDirtyState(companionKey);
    }

    function removeFuelCompanionLine(primaryRate) {
        var companionKey = 'fuel_nodedut_' + primaryRate;
        if (rateInputs[companionKey]) {
            removeRateRow(companionKey);
        }
    }

    function syncFuelRubricAdjustmentForRate(rate, options) {
        if (isFuelCompanionRate(rate)) {
            return;
        }
        var info = rateInputs[rate];
        if (!info || !info.base) {
            return;
        }

        var opts = options || {};
        var rateData = ensureRateData(rate);
        var shouldAdjust = shouldApplyFuelRubricAdjustmentForRate(rate);
        var isAdjusted = isAdjustedVatRateEntry(rateData);

        if (!shouldAdjust && isAdjusted) {
            removeFuelCompanionLine(rate);
        }
        rateData.vat_amounts_adjusted = shouldAdjust ? '1' : '0';

        recalculateVatForRate(rate, { formatBase: opts.formatBase !== false });
    }

    function cloneJsonValue(value, fallback) {
        try {
            return JSON.parse(JSON.stringify(value));
        } catch (err) {
            return fallback;
        }
    }

    function normalizeClassificationModelList(models) {
        if (!Array.isArray(models)) {
            return [];
        }
        return models.map(function(model) {
            var item = model && typeof model === 'object' ? model : {};
            var normalizedRates = item.rates && typeof item.rates === 'object' ? cloneJsonValue(item.rates, {}) : {};
            var hasExplicitBaseSource = false;
            Object.keys(normalizedRates).forEach(function(rate) {
                if (!normalizedRates[rate] || typeof normalizedRates[rate] !== 'object') {
                    normalizedRates[rate] = {};
                }
                normalizedRates[rate].base = '';
                normalizedRates[rate].base_value = '';
                normalizedRates[rate].iva = '';
                normalizedRates[rate].iva_value = '';
                normalizedRates[rate].vat_amounts_adjusted = '0';
                normalizedRates[rate].base_source_field = String(normalizedRates[rate].base_source_field || '').trim();
                if (normalizedRates[rate].base_source_field !== '') {
                    hasExplicitBaseSource = true;
                }
            });
            if (!hasExplicitBaseSource) {
                var zeroRate = normalizedRates['0'] && typeof normalizedRates['0'] === 'object' ? normalizedRates['0'] : null;
                var hasOnlyZeroRateConfigured = Object.keys(normalizedRates).every(function(rate) {
                    var entry = normalizedRates[rate] || {};
                    var hasAccount = String(entry.general_account || '').trim() !== '' || String(entry.iva_account || '').trim() !== '';
                    return rate === '0' || !hasAccount;
                });
                if (zeroRate && hasOnlyZeroRateConfigured) {
                    var zeroHasConfiguration = String(zeroRate.general_account || '').trim() !== ''
                        || String(zeroRate.iva_account || '').trim() !== '';
                    if (zeroHasConfiguration) {
                        zeroRate.base_source_field = 'field_O';
                    }
                }
            }
            return {
                name: String(item.name || '').trim(),
                rates: normalizedRates,
                cost_centers: item.cost_centers && typeof item.cost_centers === 'object' ? cloneJsonValue(item.cost_centers, {}) : {},
                cost_center_breakdowns: item.cost_center_breakdowns && typeof item.cost_center_breakdowns === 'object' ? cloneJsonValue(item.cost_center_breakdowns, {}) : {},
                total_account: String(item.total_account || '').trim()
            };
        }).filter(function(model) {
            return model.name !== '';
        }).sort(function(a, b) {
            return a.name.localeCompare(b.name, 'pt', { sensitivity: 'base' });
        });
    }

    function getCurrentDocumentFieldValue(fieldName) {
        var key = String(fieldName || '').trim().toUpperCase();
        if (!key) {
            return null;
        }
        if (!currentDocumentFieldValues || typeof currentDocumentFieldValues !== 'object') {
            return null;
        }
        if (!Object.prototype.hasOwnProperty.call(currentDocumentFieldValues, key)) {
            return null;
        }
        return normalizeAmountValue(currentDocumentFieldValues[key]);
    }

    function normalizeDocumentFieldValue(fieldName, value) {
        var normalizedKey = String(fieldName || '').trim().toUpperCase();
        if (normalizedKey === 'FIELD_D') {
            return normalizeDocumentDocTypeValue(value);
        }
        return String(value || '').trim();
    }

    function normalizeDocumentFieldMap(source) {
        var result = {};
        if (!source || typeof source !== 'object') {
            return result;
        }
        Object.keys(source).forEach(function(fieldName) {
            var normalizedKey = String(fieldName || '').trim().toUpperCase();
            if (!normalizedKey) {
                return;
            }
            result[normalizedKey] = normalizeDocumentFieldValue(normalizedKey, source[fieldName]);
        });
        return result;
    }

    function normalizeDocumentDocTypeValue(value) {
        var normalized = String(value || '').trim().toUpperCase();
        if (!normalized) {
            return '';
        }
        if (normalized === 'FTR' || normalized === 'FATURA-RECIBO' || normalized === 'FATURA RECIBO' || normalized === 'FACTURA-RECIBO') {
            return 'FR';
        }
        if (normalized === 'FATURA' || normalized === 'FACTURA' || normalized === 'INVOICE') {
            return 'FT';
        }
        if (normalized === 'RECIBO' || normalized === 'RG') {
            return 'RC';
        }
        if (normalized === 'NOTA CREDITO' || normalized === 'NOTA DE CREDITO') {
            return 'NC';
        }
        if (normalized === 'NOTA DEBITO' || normalized === 'NOTA DE DÉBITO') {
            return 'ND';
        }
        return normalized;
    }

    function getCurrentIsoDateString() {
        var now = new Date();
        var year = now.getFullYear();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        return year + '-' + month + '-' + day;
    }

    function applyDocumentFieldDefaults(source) {
        var result = normalizeDocumentFieldMap(source);
        if (String(result.FIELD_C || '').trim() === '') {
            result.FIELD_C = 'PT';
        }
        if (String(result.FIELD_I1 || '').trim() === '') {
            result.FIELD_I1 = 'PT';
        }
        if (String(result.FIELD_E || '').trim() === '') {
            result.FIELD_E = 'N';
        }
        if (String(result.FIELD_F || '').trim() === '') {
            result.FIELD_F = getCurrentIsoDateString();
        }
        return result;
    }

    function getDocumentFieldRawValue(fieldName) {
        var key = String(fieldName || '').trim().toUpperCase();
        if (!key || !currentDocumentFieldValues || typeof currentDocumentFieldValues !== 'object') {
            return '';
        }
        if (!Object.prototype.hasOwnProperty.call(currentDocumentFieldValues, key)) {
            return '';
        }
        return String(currentDocumentFieldValues[key] || '');
    }

    function buildDocumentDocTypeOptionsHtml(selectedValue) {
        var normalizedSelected = normalizeDocumentDocTypeValue(selectedValue);
        var hasSelectedOption = false;
        var html = '';

        documentFieldDocTypeOptions.forEach(function(option) {
            var optionValue = String(option.value || '').trim();
            var isSelected = optionValue === normalizedSelected;
            if (isSelected) {
                hasSelectedOption = true;
            }
            html += '<option value="' + escapeHtml(optionValue) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(option.label) + '</option>';
        });

        if (normalizedSelected && !hasSelectedOption) {
            html += '<option value="' + escapeHtml(normalizedSelected) + '" selected>' + escapeHtml(normalizedSelected) + '</option>';
        }

        return html;
    }

    function buildAcquirerOptionsHtml(selectedValue) {
        var normalizedSelected = String(selectedValue || '').trim();
        var hasSelectedOption = false;
        var html = '<option value="">Selecionar adquirente</option>';

        classificationAcquirerOptions.forEach(function(option) {
            var optionValue = String((option && (option.nif || option.value)) || '').trim();
            if (!optionValue) {
                return;
            }
            var optionLabel = String((option && (option.label || option.name || optionValue)) || optionValue).trim();
            var isSelected = optionValue === normalizedSelected;
            if (isSelected) {
                hasSelectedOption = true;
            }
            html += '<option value="' + escapeHtml(optionValue) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(optionLabel) + '</option>';
        });

        if (normalizedSelected && !hasSelectedOption) {
            html += '<option value="' + escapeHtml(normalizedSelected) + '" selected>' + escapeHtml(normalizedSelected) + '</option>';
        }

        return html;
    }

    function findClassificationAcquirerOptionByNif(value) {
        var normalizedValue = String(value || '').trim();
        if (!normalizedValue) {
            return null;
        }
        for (var i = 0; i < classificationAcquirerOptions.length; i += 1) {
            var option = classificationAcquirerOptions[i];
            if (!option || typeof option !== 'object') {
                continue;
            }
            if (String(option.nif || option.value || '').trim() === normalizedValue) {
                return option;
            }
        }
        return null;
    }

    function isNumericDocumentField(fieldName) {
        return fieldName === 'FIELD_I3'
            || fieldName === 'FIELD_I4'
            || fieldName === 'FIELD_I5'
            || fieldName === 'FIELD_I6'
            || fieldName === 'FIELD_I7'
            || fieldName === 'FIELD_I8'
            || fieldName === 'FIELD_N'
            || fieldName === 'FIELD_O';
    }

    function getDocumentFieldInputElement(fieldName) {
        if (!documentFieldsGridEl) {
            return null;
        }
        return documentFieldsGridEl.querySelector('[data-field-name="' + fieldName + '"]');
    }

    function applyDocumentVatAutofill(sourceFieldName) {
        var normalizedKey = String(sourceFieldName || '').trim().toUpperCase();
        var config = documentFieldVatAutofillMap[normalizedKey];
        if (!config) {
            return;
        }

        var sourceInput = getDocumentFieldInputElement(normalizedKey);
        var targetInput = getDocumentFieldInputElement(config.target);
        if (!sourceInput || !targetInput) {
            return;
        }

        var baseValue = parseDecimalValue(sourceInput.value);
        if (baseValue === null) {
            targetInput.value = '';
            return;
        }

        targetInput.value = formatDecimalValue(baseValue * (config.rate / 100));
    }

    function applyDocumentVatAutofillDefaults() {
        var changed = false;

        Object.keys(documentFieldVatAutofillMap).forEach(function(sourceFieldName) {
            var config = documentFieldVatAutofillMap[sourceFieldName];
            var sourceInput = getDocumentFieldInputElement(sourceFieldName);
            var targetInput = getDocumentFieldInputElement(config.target);
            if (!sourceInput || !targetInput) {
                return;
            }
            if (String(targetInput.value || '').trim() !== '') {
                return;
            }

            var baseValue = parseDecimalValue(sourceInput.value);
            if (baseValue === null) {
                return;
            }

            targetInput.value = formatDecimalValue(baseValue * (config.rate / 100));
            changed = true;
        });

        return changed;
    }

    function syncDocumentFieldStateFromInputs() {
        currentDocumentFieldValues = collectDocumentFieldInputs();
        if (currentBtn) {
            updateButtonDocumentFields(currentBtn, currentDocumentFieldValues);
        }
        rebuildRequirementsForCurrentButton();
        getRateKeys().forEach(function(rate) {
            populateRateRow(rate);
        });
    }

    var documentFieldClassificationRefreshTimer = null;
    var documentFieldClassificationRefreshRequestId = 0;

    function shouldRefreshClassificationFromDocumentField(fieldName) {
        return fieldName === 'FIELD_A' || fieldName === 'FIELD_B' || fieldName === 'FIELD_D';
    }

    function applyClassificationContextResponse(res, options) {
        var response = res && typeof res === 'object' ? res : {};
        var applyOptions = options || {};
        var currentDocumentFieldsVisible = !!(documentFieldsPanelEl && !documentFieldsPanelEl.classList.contains('d-none'));

        if (response.csrf_token) {
            csrfInput.value = response.csrf_token;
        }

        currentEntityPairAiInstructions = typeof response.entity_pair_ai_instructions === 'string'
            ? String(response.entity_pair_ai_instructions || '').trim()
            : '';
        currentCanManageEntityPairAiInstructions = String(response.can_manage_entity_ai_instructions || '').trim() === '1';
        if (entityPairAiInstructionsBtn) {
            entityPairAiInstructionsBtn.disabled = !currentCanManageEntityPairAiInstructions;
            entityPairAiInstructionsBtn.classList.toggle('disabled', !currentCanManageEntityPairAiInstructions);
        }

        storedRowRates = (response.row_rates && typeof response.row_rates === 'object') ? response.row_rates : {};
        storedDefaultRates = (response.rates && typeof response.rates === 'object') ? response.rates : {};
        preserveAdjustedDisplayRates(storedRowRates, currentRateData);
        preserveAdjustedDisplayRates(storedDefaultRates, currentRateData);
        var mergedRequirements = (response.row_requirements && typeof response.row_requirements === 'object')
            ? response.row_requirements
            : (parseJsonAttribute(currentBtn, 'data-requirements') || {});
        mergedRequirements = enrichRequirementsFromRates(mergedRequirements, storedRowRates);
        mergedRequirements = enrichRequirementsFromRates(mergedRequirements, storedDefaultRates);
        currentBtn.setAttribute('data-requirements', JSON.stringify(mergedRequirements));
        removedRates = {};
        serverOriginalRates = preserveAdjustedOriginalRates(normalizeServerOriginalRates(response.original_rates), currentRateData);
        var rowTotalAccount = typeof response.row_total_account === 'string' ? response.row_total_account : '';
        var classificationTotalAccount = typeof response.total_account === 'string' ? response.total_account : '';
        var buttonTotalAccount = currentBtn ? currentBtn.getAttribute('data-total-account') : '';
        var effectiveTotalAccount = (rowTotalAccount || classificationTotalAccount || buttonTotalAccount || '').trim();
        currentTotalAccount = effectiveTotalAccount || '';
        if (currentBtn) {
            currentBtn.setAttribute('data-total-account', currentTotalAccount);
        }
        if (Object.prototype.hasOwnProperty.call(response, 'has_receipt_companion') && currentBtn) {
            currentBtn.setAttribute('data-has-receipt-companion', String(response.has_receipt_companion || '').trim() === '1' ? '1' : '0');
        }
        if (totalAccountInput) {
            totalAccountInput.value = currentTotalAccount;
            updatePlanInputTitle(totalAccountInput);
        }
        currentIgnoreDetectedRates = String(response.ignore_detected_rates || '').trim() === '1';
        currentClassificationModelName = String(response.classification_model_name || '').trim();
        classificationModels = normalizeClassificationModelList(response.classification_models);
        if (emitterTypeSelect && Object.prototype.hasOwnProperty.call(response, 'emitter_type')) {
            emitterTypeSelect.value = String(response.emitter_type || '').trim() || 'normal';
        }
        if (response.document_fields && typeof response.document_fields === 'object') {
            currentDocumentFieldValues = normalizeDocumentFieldMap(response.document_fields);
            renderDocumentFieldInputs();
            updateButtonDocumentFields(currentBtn, currentDocumentFieldValues);
        }
        if (Object.prototype.hasOwnProperty.call(response, 'show_document_fields')) {
            var showFieldsFlag = String(response.show_document_fields || '').trim() === '1';
            if (applyOptions.preserveDocumentFieldsVisibility && currentDocumentFieldsVisible) {
                showFieldsFlag = true;
            }
            updateDocumentFieldsPanelVisibility(showFieldsFlag);
            if (currentBtn) {
                currentBtn.setAttribute('data-show-document-fields', showFieldsFlag ? '1' : '0');
            }
        }
        inheritBaseSourceFieldsFromModel(storedRowRates, currentClassificationModelName);
        inheritBaseSourceFieldsFromModel(storedDefaultRates, currentClassificationModelName);
        inheritBaseSourceFieldsFromModel(currentRateData, currentClassificationModelName);
        renderClassificationModelOptions(currentClassificationModelName);

        Object.keys(storedRowRates).forEach(function(rate) {
            if (!currentRateData[rate]) {
                currentRateData[rate] = {};
            }
            var rowData = storedRowRates[rate];
            if (rowData && typeof rowData === 'object') {
                if (Object.prototype.hasOwnProperty.call(rowData, 'iva_account')) {
                    currentRateData[rate].iva_account = String(rowData.iva_account || '').trim();
                }
                if (Object.prototype.hasOwnProperty.call(rowData, 'general_account')) {
                    currentRateData[rate].general_account = String(rowData.general_account || '').trim();
                }
                if (rowData.label) {
                    currentRateData[rate].label = normalizeRateLabelForUi(rowData.label);
                }
                copyRateHiddenMetadata(currentRateData[rate], rowData);
                if (rowData.base_source_field) {
                    currentRateData[rate].base_source_field = rowData.base_source_field;
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
                if (Object.prototype.hasOwnProperty.call(defaultData, 'iva_account')) {
                    currentRateData[rate].iva_account = String(defaultData.iva_account || '').trim();
                }
                if (Object.prototype.hasOwnProperty.call(defaultData, 'general_account')) {
                    currentRateData[rate].general_account = String(defaultData.general_account || '').trim();
                }
                if (defaultData.label) {
                    currentRateData[rate].label = normalizeRateLabelForUi(defaultData.label);
                }
                copyRateHiddenMetadata(currentRateData[rate], defaultData);
            }
        });

        var serverCostCenters = null;
        if (Object.prototype.hasOwnProperty.call(response, 'cost_centers')) {
            serverCostCenters = response.cost_centers;
        } else if (Object.prototype.hasOwnProperty.call(response, 'cost_center')) {
            serverCostCenters = response.cost_center;
        }
        if (serverCostCenters !== null && serverCostCenters !== undefined) {
            if (!hasAnyCostCenterValue()) {
                applyCostCenterValues(serverCostCenters, { skipEnsure: true });
            }
        }
        if (response.cost_center_breakdowns && typeof response.cost_center_breakdowns === 'object') {
            applyCostCenterBreakdownValues(response.cost_center_breakdowns);
            currentBtn.setAttribute('data-cost-center-breakdowns', JSON.stringify(getCostCenterBreakdownValues()));
        }

        var restored = restoreSavedRates();
        rebuildRequirementsForCurrentButton();
        getRateKeys().forEach(function(rate) {
            populateRateRow(rate);
        });
        if (currentBtn) {
            currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
        }
        captureOriginalRateValues({ initialize: true });
        currentCostCenters = getCostCenterValues();
        refreshCostCenterFieldModes();

        if (applyOptions.focusRestored !== false && restored.length > 0) {
            focusRateInput(rateInputs[restored[0]]);
        }
    }

    function refreshClassificationFromDocumentFields() {
        if (!currentBtn) {
            return Promise.resolve(null);
        }

        currentDocumentFieldValues = collectDocumentFieldInputs();
        updateButtonDocumentFields(currentBtn, currentDocumentFieldValues);
        updateCurrentPlanContextFromButton(currentBtn);

        var emitter = String(currentBtn.getAttribute('data-emitter') || '').trim();
        var acquirer = String(currentBtn.getAttribute('data-acquirer') || '').trim();
        var docType = String(currentBtn.getAttribute('data-doctype') || '').trim();
        if (emitter === '' || acquirer === '' || docType === '') {
            return Promise.resolve(null);
        }

        var requestId = ++documentFieldClassificationRefreshRequestId;
        var requestBtn = currentBtn;
        var params = new URLSearchParams({
            action: 'get',
            id: currentBtn.getAttribute('data-id') || '',
            A: emitter,
            B: acquirer,
            D: docType,
            tenant_key: currentBtn.getAttribute('data-acquirer-db') || erpDefaultDatabase || '',
            document_fields: JSON.stringify(currentDocumentFieldValues),
            csrf_token: csrfInput.value
        });

        return fetchJson('contabilidade/save-analysis.php?' + params.toString())
            .then(function(res) {
                if (requestId !== documentFieldClassificationRefreshRequestId || requestBtn !== currentBtn) {
                    return null;
                }
                applyClassificationContextResponse(res, {
                    focusRestored: false,
                    preserveDocumentFieldsVisibility: true
                });
                return res;
            })
            .catch(function(err) {
                if (requestId === documentFieldClassificationRefreshRequestId) {
                    console.warn('Falha ao atualizar classificacao por campos do documento:', err);
                }
                return null;
            });
    }

    function scheduleClassificationRefreshFromDocumentFields(fieldName) {
        if (!shouldRefreshClassificationFromDocumentField(fieldName)) {
            return;
        }
        if (documentFieldClassificationRefreshTimer) {
            window.clearTimeout(documentFieldClassificationRefreshTimer);
        }
        documentFieldClassificationRefreshTimer = window.setTimeout(function() {
            documentFieldClassificationRefreshTimer = null;
            refreshClassificationFromDocumentFields();
        }, fieldName === 'FIELD_A' ? 280 : 80);
    }

    function updateDocumentFieldsPanelVisibility(visible) {
        if (!documentFieldsPanelEl) {
            return;
        }
        documentFieldsPanelEl.classList.toggle('d-none', !visible);
        if (toggleDocumentFieldsSwitch) {
            toggleDocumentFieldsSwitch.checked = !!visible;
        }
    }

    function getDocumentFieldLabel(fieldName) {
        var normalizedKey = String(fieldName || '').trim().toUpperCase();
        if (!normalizedKey) {
            return 'Campo';
        }
        if (Object.prototype.hasOwnProperty.call(documentFieldLabelMap, normalizedKey)) {
            return documentFieldLabelMap[normalizedKey];
        }
        if (normalizedKey.indexOf('FIELD_') === 0) {
            return 'Campo ' + normalizedKey.slice(6);
        }
        return normalizedKey;
    }

    function shouldDisplayDocumentField(fieldName) {
        var normalizedKey = String(fieldName || '').trim().toUpperCase();
        if (!normalizedKey) {
            return false;
        }
        return !Object.prototype.hasOwnProperty.call(documentFieldHiddenKeys, normalizedKey);
    }

    function getDocumentFieldKeysForDisplay() {
        var seen = {};
        var keys = [];

        documentFieldDisplayOrder.forEach(function(fieldName) {
            if (!shouldDisplayDocumentField(fieldName)) {
                return;
            }
            seen[fieldName] = true;
            keys.push(fieldName);
        });

        Object.keys(currentDocumentFieldValues || {}).sort().forEach(function(fieldName) {
            var normalizedKey = String(fieldName || '').trim().toUpperCase();
            if (!normalizedKey || seen[normalizedKey] || !shouldDisplayDocumentField(normalizedKey)) {
                return;
            }
            seen[normalizedKey] = true;
            keys.push(normalizedKey);
        });

        return keys;
    }

    function renderDocumentFieldInputs() {
        if (!documentFieldsGridEl) {
            return;
        }

        currentDocumentFieldValues = applyDocumentFieldDefaults(currentDocumentFieldValues || {});
        var keys = getDocumentFieldKeysForDisplay();
        if (!keys.length) {
            documentFieldsGridEl.innerHTML = '';
            return;
        }

        var html = '';
        keys.forEach(function(fieldName) {
            var normalizedKey = String(fieldName || '').trim().toUpperCase();
            var inputId = 'classifyDocumentField_' + normalizedKey;
            var label = getDocumentFieldLabel(normalizedKey);
            var value = getDocumentFieldRawValue(normalizedKey);

            html += '<div class="col-md-6 col-sm-12">';
            html += '  <div class="form-group">';
            html += '    <label class="form-label" for="' + escapeHtml(inputId) + '">' + escapeHtml(label) + '<span class="text-muted small classify-document-field-help">(' + escapeHtml(normalizedKey.toLowerCase()) + ')</span></label>';
            if (normalizedKey === 'FIELD_B' && classificationAcquirerOptions.length > 0) {
                html += '    <select class="form-control form-control-sm classify-document-field-input" id="' + escapeHtml(inputId) + '" data-field-name="' + escapeHtml(normalizedKey) + '">';
                html += buildAcquirerOptionsHtml(value);
                html += '    </select>';
            } else if (normalizedKey === 'FIELD_D') {
                html += '    <select class="form-control form-control-sm classify-document-field-input" id="' + escapeHtml(inputId) + '" data-field-name="' + escapeHtml(normalizedKey) + '">';
                html += buildDocumentDocTypeOptionsHtml(value);
                html += '    </select>';
            } else {
                var inputMode = isNumericDocumentField(normalizedKey) ? ' inputmode="decimal"' : '';
                html += '    <input type="text" class="form-control form-control-sm classify-document-field-input" id="' + escapeHtml(inputId) + '" data-field-name="' + escapeHtml(normalizedKey) + '" value="' + escapeHtml(value) + '"' + inputMode + '>';
            }
            html += '  </div>';
            html += '</div>';
        });

        documentFieldsGridEl.innerHTML = html;
        if (applyDocumentVatAutofillDefaults()) {
            currentDocumentFieldValues = collectDocumentFieldInputs();
        }
    }

    function collectDocumentFieldInputs() {
        if (!documentFieldsGridEl) {
            return applyDocumentFieldDefaults(currentDocumentFieldValues || {});
        }

        var result = applyDocumentFieldDefaults(currentDocumentFieldValues || {});
        var inputs = documentFieldsGridEl.querySelectorAll('.classify-document-field-input');
        Array.prototype.forEach.call(inputs, function(input) {
            var fieldName = String(input.getAttribute('data-field-name') || '').trim().toUpperCase();
            if (!fieldName) {
                return;
            }
            var normalizedValue = normalizeDocumentFieldValue(fieldName, input.value);
            input.value = normalizedValue;
            result[fieldName] = normalizedValue;
        });

        return applyDocumentFieldDefaults(result);
    }

    if (documentFieldsGridEl) {
        documentFieldsGridEl.addEventListener('input', function(ev) {
            var target = ev && ev.target ? ev.target : null;
            if (!target || !target.classList || !target.classList.contains('classify-document-field-input')) {
                return;
            }
            var fieldName = String(target.getAttribute('data-field-name') || '').trim().toUpperCase();
            if (!fieldName) {
                return;
            }
            target.value = normalizeDocumentFieldValue(fieldName, target.value);
            applyDocumentVatAutofill(fieldName);
            syncDocumentFieldStateFromInputs();
            scheduleClassificationRefreshFromDocumentFields(fieldName);
        });

        documentFieldsGridEl.addEventListener('change', function(ev) {
            var target = ev && ev.target ? ev.target : null;
            if (!target || !target.classList || !target.classList.contains('classify-document-field-input')) {
                return;
            }
            var fieldName = String(target.getAttribute('data-field-name') || '').trim().toUpperCase();
            if (!fieldName) {
                return;
            }
            target.value = normalizeDocumentFieldValue(fieldName, target.value);
            applyDocumentVatAutofill(fieldName);
            syncDocumentFieldStateFromInputs();
            scheduleClassificationRefreshFromDocumentFields(fieldName);
        });
    }

    if (toggleDocumentFieldsSwitch) {
        toggleDocumentFieldsSwitch.addEventListener('change', function() {
            var visible = !!toggleDocumentFieldsSwitch.checked;
            updateDocumentFieldsPanelVisibility(visible);
            if (currentBtn) {
                currentBtn.setAttribute('data-show-document-fields', visible ? '1' : '0');
            }
        });
    }

    function extractVatDigits(value) {
        var match = String(value || '').match(/\d{9}/);
        return match ? match[0] : '';
    }

    function updateButtonDocumentFields(btn, fieldMap) {
        if (!btn) {
            return;
        }

        var normalizedMap = normalizeDocumentFieldMap(fieldMap);
        var documentFieldsPayload = {};
        Object.keys(normalizedMap).forEach(function(fieldName) {
            var payloadKey = fieldName.indexOf('FIELD_') === 0 ? 'field_' + fieldName.slice(6) : fieldName.toLowerCase();
            documentFieldsPayload[payloadKey] = normalizedMap[fieldName];
        });
        var emitterVat = extractVatDigits(normalizedMap.FIELD_C || '');
        if (!emitterVat) {
            emitterVat = extractVatDigits(normalizedMap.FIELD_A || '');
        }

        btn.setAttribute('data-qr-fields', JSON.stringify(documentFieldsPayload));
        btn.setAttribute('data-emitter', String(normalizedMap.FIELD_A || '').trim());
        btn.setAttribute('data-emitter-display', String(normalizedMap.FIELD_A || '').trim());
        btn.setAttribute('data-emitter-nif', emitterVat);
        btn.setAttribute('data-acquirer', String(normalizedMap.FIELD_B || '').trim());
        var acquirerOption = findClassificationAcquirerOptionByNif(normalizedMap.FIELD_B || '');
        if (acquirerOption && String(acquirerOption.erp_database || '').trim() !== '') {
            btn.setAttribute('data-acquirer-db', String(acquirerOption.erp_database || '').trim());
        }
        btn.setAttribute('data-doctype', String(normalizedMap.FIELD_D || '').trim());
        btn.setAttribute('data-docdate', String(normalizedMap.FIELD_F || '').trim());
        btn.setAttribute('data-doc-number', String(normalizedMap.FIELD_G || '').trim());
    }

    function resolveBaseSourceForRate(rate, rateData) {
        if (!rateData || typeof rateData !== 'object') {
            return null;
        }
        var baseSourceField = String(rateData.base_source_field || '').trim().toUpperCase();
        if (!baseSourceField) {
            return null;
        }
        var resolvedBase = getCurrentDocumentFieldValue(baseSourceField);
        if (resolvedBase === null) {
            return null;
        }
        return {
            base: resolvedBase,
            iva: String(rate) === '0' ? '0.00' : null
        };
    }

    function inferBaseSourceFieldForRate(rate, baseValue) {
        var normalizedBase = normalizeAmountValue(baseValue);
        if (normalizedBase === null) {
            return '';
        }
        var candidateFields = [];
        Object.keys(currentDocumentFieldValues || {}).forEach(function(fieldName) {
            var resolvedValue = getCurrentDocumentFieldValue(fieldName);
            if (resolvedValue !== null && resolvedValue === normalizedBase) {
                candidateFields.push(String(fieldName || '').trim().toUpperCase());
            }
        });
        if (!candidateFields.length) {
            return '';
        }
        candidateFields.sort(function(a, b) {
            var indexA = qrFieldMatchPriority.indexOf(a);
            var indexB = qrFieldMatchPriority.indexOf(b);
            if (indexA === -1) {
                indexA = 999;
            }
            if (indexB === -1) {
                indexB = 999;
            }
            if (indexA !== indexB) {
                return indexA - indexB;
            }
            return a.localeCompare(b);
        });
        return candidateFields[0].toLowerCase();
    }

    function renderClassificationModelOptions(selectedName) {
        if (!classificationModelSelect) {
            return;
        }
        var currentValue = typeof selectedName === 'string' ? selectedName.trim() : '';
        classificationModelSelect.innerHTML = '';
        var emptyOption = document.createElement('option');
        emptyOption.value = '';
        emptyOption.textContent = 'Selecionar modelo';
        classificationModelSelect.appendChild(emptyOption);

        classificationModels.forEach(function(model) {
            var option = document.createElement('option');
            option.value = model.name;
            option.textContent = model.name;
            if (currentValue !== '' && model.name === currentValue) {
                option.selected = true;
            }
            classificationModelSelect.appendChild(option);
        });
    }

    function findClassificationModelByName(modelName) {
        var name = String(modelName || '').trim();
        if (!name) {
            return null;
        }
        for (var i = 0; i < classificationModels.length; i += 1) {
            var model = classificationModels[i];
            if (model && model.name === name) {
                return model;
            }
        }
        return null;
    }

    function inheritBaseSourceFieldsFromModel(targetRates, modelName) {
        if (!targetRates || typeof targetRates !== 'object') {
            return;
        }
        var selectedModel = findClassificationModelByName(modelName);
        if (!selectedModel || !selectedModel.rates || typeof selectedModel.rates !== 'object') {
            return;
        }
        Object.keys(selectedModel.rates).forEach(function(rate) {
            var modelRate = selectedModel.rates[rate];
            if (!modelRate || typeof modelRate !== 'object') {
                return;
            }
            var modelBaseSourceField = String(modelRate.base_source_field || '').trim();
            if (!modelBaseSourceField) {
                return;
            }
            if (!targetRates[rate] || typeof targetRates[rate] !== 'object') {
                targetRates[rate] = {};
            }
            if (!String(targetRates[rate].base_source_field || '').trim()) {
                targetRates[rate].base_source_field = modelBaseSourceField;
            }
        });
    }

    function toggleClassificationModelSaveFields() {
        if (!classificationModelNameInput || !saveClassificationModelSwitch) {
            return;
        }
        var enabled = !!saveClassificationModelSwitch.checked;
        classificationModelNameInput.classList.toggle('d-none', !enabled);
        if (!enabled) {
            classificationModelNameInput.value = '';
        } else if (!classificationModelNameInput.value.trim() && currentClassificationModelName) {
            classificationModelNameInput.value = currentClassificationModelName;
        }
    }

    function applyClassificationModel(modelName) {
        var name = String(modelName || '').trim();
        if (name === '') {
            showNotice('warning', 'Selecione um modelo antes de aplicar.');
            return;
        }
        var selectedModel = null;
        classificationModels.forEach(function(model) {
            if (!selectedModel && model.name === name) {
                selectedModel = model;
            }
        });
        if (!selectedModel) {
            showError('Modelo não encontrado.');
            return;
        }

        resetRateRows();
        currentRateData = cloneJsonValue(selectedModel.rates, {});
        storedRowRates = {};
        storedDefaultRates = {};
        currentCostCenters = cloneJsonValue(selectedModel.cost_centers, {});
        currentCostCenterBreakdowns = cloneJsonValue(selectedModel.cost_center_breakdowns, {});
        currentTotalAccount = selectedModel.total_account;
        currentIgnoreDetectedRates = true;
        currentClassificationModelName = selectedModel.name;

        Object.keys(currentRateData).forEach(function(rate) {
            var data = ensureRateData(rate);
            var resolvedSourceAmounts = resolveBaseSourceForRate(rate, data);
            if (resolvedSourceAmounts) {
                data.base = resolvedSourceAmounts.base;
                data.base_value = resolvedSourceAmounts.base;
                if (resolvedSourceAmounts.iva !== null) {
                    data.iva = resolvedSourceAmounts.iva;
                    data.iva_value = resolvedSourceAmounts.iva;
                }
            }
            if (!data.label) {
                data.label = getDefaultRateLabel(rate);
            }
        });

        ensureRowsForRates(currentRateData, { allowCreate: true });
        rebuildRequirementsForCurrentButton();
        getRateKeys().forEach(function(rate) {
            populateRateRow(rate);
        });
        if (totalAccountInput) {
            totalAccountInput.value = currentTotalAccount;
            updatePlanInputTitle(totalAccountInput);
        }
        applyCostCenterValues(currentCostCenters, { skipEnsure: true });
        applyCostCenterBreakdownValues(currentCostCenterBreakdowns);
        refreshCostCenterFieldModes();
        captureOriginalRateValues({ initialize: true, refresh: false, allowCreate: false });
        renderClassificationModelOptions(currentClassificationModelName);
        if (currentBtn) {
            currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
            updateButtonClass(currentBtn);
        }
    }

    function hasValidBaseAmountForRate(rate) {
        var info = rateInputs[rate] || null;
        var rateData = ensureRateData(rate);
        var resolvedSourceAmounts = resolveBaseSourceForRate(rate, rateData);
        var baseCandidate = resolvedSourceAmounts ? resolvedSourceAmounts.base : null;
        if (baseCandidate === null && info && info.base) {
            baseCandidate = normalizeAmountValue(info.base.value);
        }
        if (baseCandidate === null) {
            baseCandidate = getEntryAmount(rateData, 'base');
        }
        if (baseCandidate === null) {
            return false;
        }
        return Math.abs(parseFloat(baseCandidate)) >= 0.00001;
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

    function getDocumentFieldNumber(fieldName) {
        return parseDecimalValue(getDocumentFieldRawValue(fieldName));
    }

    function hasBankLoanStampValues() {
        var exemptBase = getDocumentFieldNumber('FIELD_I2');
        var stampTax = getDocumentFieldNumber('FIELD_M');
        var total = getDocumentFieldNumber('FIELD_O');
        var vatValues = [
            getDocumentFieldNumber('FIELD_I3'),
            getDocumentFieldNumber('FIELD_I4'),
            getDocumentFieldNumber('FIELD_I5'),
            getDocumentFieldNumber('FIELD_I6'),
            getDocumentFieldNumber('FIELD_I7'),
            getDocumentFieldNumber('FIELD_I8')
        ];
        var hasVatAmounts = vatValues.some(function(value) {
            return value !== null && Math.abs(value) >= 0.005;
        });
        if (exemptBase === null && total !== null && stampTax !== null && total > 0 && stampTax > 0) {
            exemptBase = total - stampTax;
        }
        return exemptBase !== null
            && stampTax !== null
            && total !== null
            && exemptBase > 0
            && stampTax > 0
            && !hasVatAmounts
            && Math.abs((exemptBase + stampTax) - total) < 0.03;
    }

    function isEmitterBankEntitySelected() {
        return !!(emitterTypeSelect && String(emitterTypeSelect.value || '').trim() === 'bank');
    }

    function isEmitterInsuranceEntitySelected() {
        return !!(emitterTypeSelect && String(emitterTypeSelect.value || '').trim() === 'insurance');
    }

    function hasBankLoanConversionCandidate() {
        if (isEmitterInsuranceEntitySelected()) {
            return false;
        }
        if (hasBankLoanStampValues() && isEmitterBankEntitySelected()) {
            return true;
        }
        return isEmitterBankEntitySelected() && getDocumentFieldNumber('FIELD_O') !== null;
    }

    function getLineDescriptionText(line) {
        if (!line || typeof line !== 'object') {
            return '';
        }
        var parts = [
            line.ITEM,
            line.DESCRIPTION,
            line.DESCRICAO,
            line.PRODUCT_CODE,
            line.CODE
        ];
        if (line.ITEM_QUANTITY_UNIT_PRICE && typeof line.ITEM_QUANTITY_UNIT_PRICE === 'object') {
            parts.push(line.ITEM_QUANTITY_UNIT_PRICE.ITEM);
        }
        return parts.map(function(value) {
            return String(value || '').trim();
        }).filter(function(value) {
            return value !== '';
        }).join(' ');
    }

    function getBankLoanLineRawText(line) {
        if (!line || typeof line !== 'object') {
            return '';
        }
        var values = [];
        Object.keys(line).forEach(function(key) {
            var value = line[key];
            if (value === null || value === undefined) {
                return;
            }
            if (typeof value === 'string' || typeof value === 'number') {
                values.push(String(value).trim());
                return;
            }
            if (typeof value === 'object') {
                Object.keys(value).forEach(function(nestedKey) {
                    var nestedValue = value[nestedKey];
                    if (nestedValue === null || nestedValue === undefined) {
                        return;
                    }
                    if (typeof nestedValue === 'string' || typeof nestedValue === 'number') {
                        values.push(String(nestedValue).trim());
                    }
                });
            }
        });
        return values.filter(function(value) {
            return value !== '';
        }).join(' ');
    }

    function normalizeLoanLineDescription(value) {
        return String(value || '')
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    }

    function bankLoanLinesSuggestInsurance(lines) {
        if (!Array.isArray(lines) || !lines.length) {
            return false;
        }
        var text = lines.map(function(line) {
            return normalizeLoanLineDescription(getBankLoanLineRawText(line));
        }).join(' ');
        if (!text) {
            return false;
        }
        return [
            'medis',
            'multicare',
            'fidelidade',
            'tranquilidade',
            'allianz',
            'generali',
            'ageas',
            'advancecare',
            'una seguros',
            'logo seguros',
            'seguros de saude',
            'apolice',
            'seguro',
            'seguros',
            'segurado',
            'tomador',
            'corretor',
            'mediador',
            'premio comercial',
            'premio total',
            'premio',
            'sinistro',
            'ramo',
            'companhia de seguros'
        ].some(function(needle) {
            return text.indexOf(needle) !== -1;
        });
    }

    function applyInsuranceDetectionFromLines(lines) {
        if (!bankLoanLinesSuggestInsurance(lines)) {
            return false;
        }
        if (emitterTypeSelect) {
            emitterTypeSelect.value = 'insurance';
        }
        currentBankLoanDocumentLines = Array.isArray(lines) ? cloneJsonValue(lines, []) : [];
        currentBankLoanConversionActive = false;
        return true;
    }

    function getLineNetAmount(line) {
        if (!line || typeof line !== 'object') {
            return null;
        }
        var net = parseDecimalValue(line.PRICE);
        if (net === null && line.ITEM_QUANTITY_UNIT_PRICE && typeof line.ITEM_QUANTITY_UNIT_PRICE === 'object') {
            net = parseDecimalValue(line.ITEM_QUANTITY_UNIT_PRICE.PRICE);
        }
        if (net === null) {
            net = parseDecimalValue(line.TOTAL);
        }
        return net;
    }

    function lineHasExplicitStampTax(line) {
        if (!line || typeof line !== 'object') {
            return false;
        }
        if (parseDecimalValue(line.TAX) !== null || parseDecimalValue(line.STAMP_TAX) !== null || parseDecimalValue(line.IMPOSTO_SELO) !== null) {
            return true;
        }
        var description = normalizeLoanLineDescription(getLineDescriptionText(line));
        if (description.indexOf('imposto selo') !== -1 || description.indexOf(' selo ') !== -1 || description.indexOf('selo s/') !== -1 || description.indexOf('selo sobre') !== -1) {
            return true;
        }
        var ivaTaxa = normalizeLoanLineDescription(line.IVA_TAXA || line.TAX_RATE || '');
        return ivaTaxa === 'is' || ivaTaxa.indexOf('i.s') !== -1 || ivaTaxa.indexOf('selo') !== -1;
    }

    function buildBankLoanAmountsFromLines(lines, exemptBase, stampTax, totalGross) {
        var result = {
            capital: 0,
            interest: 0,
            commission: 0,
            matched: false,
            hasInterest: false,
            hasCommission: false,
            singleLine: true,
            primaryRate: '0'
        };
        var usesLineTotalsWithStamp = false;
        if (!Array.isArray(lines) || !lines.length) {
            return result;
        }

        var combinedRawText = lines.map(function(line) {
            return normalizeLoanLineDescription(getBankLoanLineRawText(line));
        }).join(' ');
        var textSuggestsOnlyCommission = combinedRawText.indexOf('comiss') !== -1
            && combinedRawText.indexOf('juro') === -1;

        lines.forEach(function(line, index) {
            var description = normalizeLoanLineDescription(getLineDescriptionText(line));
            var net = getLineNetAmount(line);
            var isCapitalLine = description === 'capital' || description.indexOf('capital ') === 0;
            if (net === null || Math.abs(net) < 0.00001) {
                return;
            }
            if (lineHasExplicitStampTax(line)) {
                usesLineTotalsWithStamp = true;
            }
            if (isCapitalLine) {
                var capitalAmount = parseDecimalValue(line.UNIT_PRICE);
                if (capitalAmount === null || Math.abs(capitalAmount) < 0.00001) {
                    capitalAmount = net;
                }
                result.capital += capitalAmount;
                return;
            }
            if (description.indexOf('juro') !== -1) {
                result.interest += net;
                result.matched = true;
                result.hasInterest = true;
                return;
            }
            if (
                description.indexOf('com') !== -1
                || description.indexOf('comiss') !== -1
                || description.indexOf('gestao') !== -1
                || description.indexOf('prestacao fn') !== -1
            ) {
                result.commission += net;
                result.matched = true;
                result.hasCommission = true;
            }
        });

        if (!result.matched) {
            if (textSuggestsOnlyCommission) {
                return {
                    interest: totalGross,
                    commission: 0,
                    matched: true,
                    hasInterest: false,
                    hasCommission: true,
                    singleLine: true,
                    primaryRate: 'bank_loan_commission'
                };
            }
            return result;
        }
        if (!result.hasInterest || !result.hasCommission) {
            return {
                interest: totalGross,
                commission: 0,
                matched: true,
                hasInterest: result.hasInterest,
                hasCommission: result.hasCommission,
                singleLine: true,
                primaryRate: result.hasCommission && !result.hasInterest ? 'bank_loan_commission' : '0'
            };
        }
        if (
            Math.abs(result.interest) < 0.00001
            && exemptBase > 0
            && result.commission >= (exemptBase * 0.75)
        ) {
            return {
                interest: totalGross,
                commission: 0,
                matched: true,
                hasInterest: result.hasInterest,
                hasCommission: result.hasCommission,
                singleLine: true,
                primaryRate: '0'
            };
        }
        if (Math.abs(result.interest) < 0.00001 && exemptBase > result.commission) {
            result.interest = exemptBase - result.commission;
            if (result.interest > 0.00001) {
                result.matched = true;
                result.hasInterest = true;
            }
        }
        if (Math.abs(result.commission) < 0.00001 && exemptBase > result.interest) {
            result.commission = exemptBase - result.interest;
        }
        if (result.matched) {
            var netTotal = result.interest + result.commission;
            var linesAlreadyMatchGrossTotal = Math.abs(netTotal - totalGross) < 0.03;
            if (!usesLineTotalsWithStamp && !linesAlreadyMatchGrossTotal && netTotal > 0 && stampTax > 0) {
                var commissionStamp = Math.round((stampTax * (result.commission / netTotal)) * 100) / 100;
                var commissionGross = Math.round((result.commission + commissionStamp) * 100) / 100;
                result.commission = commissionGross;
                result.interest = Math.round((totalGross - commissionGross) * 100) / 100;
            }
        }
        if (result.commission >= (totalGross - 0.03) && result.interest <= 0.03) {
            return {
                interest: totalGross,
                commission: 0,
                matched: true,
                hasInterest: result.hasInterest,
                hasCommission: result.hasCommission,
                singleLine: true,
                primaryRate: '0'
            };
        }
        result.singleLine = false;
        result.primaryRate = '0';
        return result;
    }

    function buildFallbackBankLoanAmounts(totalGross, exemptBase, stampTax) {
        var base = typeof exemptBase === 'number' && isFinite(exemptBase) ? exemptBase : 0;
        var stamp = typeof stampTax === 'number' && isFinite(stampTax) ? stampTax : 0;
        if (base > 0 && stamp > 0 && Math.abs((base + stamp) - totalGross) < 0.03) {
            return {
                interest: base,
                commission: stamp,
                matched: false,
                hasInterest: false,
                hasCommission: false,
                singleLine: false,
                primaryRate: '0'
            };
        }
        return {
            interest: totalGross,
            commission: 0,
            matched: false,
            hasInterest: false,
            hasCommission: false,
            singleLine: true,
            primaryRate: '0'
        };
    }

    function fetchBankLoanLines() {
        if (!currentBtn || !csrfInput) {
            return Promise.resolve([]);
        }
        var params = new URLSearchParams({
            action: 'lines',
            id: currentBtn.getAttribute('data-id') || '',
            csrf_token: csrfInput.value
        });
        return fetchJson('contabilidade/save-analysis.php?' + params.toString())
            .then(function(res) {
                if (res && res.csrf_token) {
                    csrfInput.value = res.csrf_token;
                }
                return Array.isArray(res && res.lines) ? res.lines : [];
            })
            .catch(function() {
                return [];
            });
    }

    function clearClassificationRowsForBankLoanConversion() {
        Object.keys(currentRateData || {}).forEach(function(rate) {
            removedRates[rate] = true;
        });
        defaultRates.forEach(function(rate) {
            removedRates[rate] = true;
        });
        Object.keys(rateInputs).forEach(function(rate) {
            removedRates[rate] = true;
            removeRateRow(rate);
        });
        Object.keys(storedRowRates || {}).forEach(function(rate) {
            removedRates[rate] = true;
        });
        Object.keys(storedDefaultRates || {}).forEach(function(rate) {
            removedRates[rate] = true;
        });
        currentRateData = {};
        currentCostCenters = {};
        currentCostCenterBreakdowns = {};
        currentBankLoanDocumentLines = [];
        currentBankLoanResolvedAmounts = null;
        currentBankLoanCapitalAccount = '';
    }

    function showClassificationTableOverlay() {
        if (!classifyTableOverlay) {
            return;
        }
        classifyTableOverlay.classList.add('is-active');
        classifyTableOverlay.setAttribute('aria-hidden', 'false');
    }

    function hideClassificationTableOverlay() {
        if (!classifyTableOverlay) {
            return;
        }
        classifyTableOverlay.classList.remove('is-active');
        classifyTableOverlay.setAttribute('aria-hidden', 'true');
    }

    function setBankLoanRate(rate, label, baseValue, generalAccount) {
        var formattedBase = formatDecimalValue(baseValue);
        var resolvedGeneralAccount = String(generalAccount || '').trim();
        if (!resolvedGeneralAccount) {
            if (rate === 'bank_loan_commission') {
                resolvedGeneralAccount = '698812';
            } else if (rate === 'bank_loan_capital') {
                resolvedGeneralAccount = currentBankLoanCapitalAccount || '';
            } else {
                resolvedGeneralAccount = '6911';
            }
        }
        var ratePayload = {
            label: label,
            base: formattedBase,
            base_value: formattedBase,
            iva: '',
            iva_value: '',
            iva_account: '',
            general_account: resolvedGeneralAccount,
            bank_loan_conversion: '1'
        };
        currentRateData[rate] = Object.assign({}, ratePayload);
        var info = rate === '0'
            ? addVatRowForRate(rate)
            : createDynamicRateRow(rate, label);
        if (Object.prototype.hasOwnProperty.call(removedRates, rate)) {
            delete removedRates[rate];
        }
        if (info) {
            currentRateData[rate] = Object.assign({}, ratePayload);
            populateRateRow(rate);
            if (info.base) {
                info.base.value = formattedBase;
            }
            if (info.iva) {
                info.iva.value = '';
            }
            if (info.ivaAccount) {
                info.ivaAccount.value = '';
            }
            if (info.generalAccount) {
                info.generalAccount.value = resolvedGeneralAccount;
                updatePlanInputTitle(info.generalAccount);
            }
            currentRateData[rate].base = formattedBase;
            currentRateData[rate].base_value = formattedBase;
            currentRateData[rate].iva = '';
            currentRateData[rate].iva_value = '';
            currentRateData[rate].general_account = resolvedGeneralAccount;
            currentRateData[rate].bank_loan_conversion = '1';
        }
    }

    function requestAccountSuggestionsForCurrentRows(options) {
        var opts = options || {};
        if (!currentBtn) {
            return Promise.resolve(false);
        }
        var rateLines = Array.isArray(opts.rateLinesOverride) ? cloneJsonValue(opts.rateLinesOverride, []) : buildRateLines();
        var prompt = buildAiSuggestionPrompt(rateLines);
        if (!prompt) {
            return Promise.resolve(false);
        }

        return postAssistantRequest({
            action: 'suggest_accounts',
            payload: {
                acquirer_nif: currentBtn.getAttribute('data-acquirer') || '',
                acquirer_raw: currentBtn.getAttribute('data-acquirer') || '',
                emitter: currentBtn.getAttribute('data-emitter-display') || currentBtn.getAttribute('data-emitter') || '',
                emitter_raw: currentBtn.getAttribute('data-emitter') || '',
                emitter_nif: currentBtn.getAttribute('data-emitter-nif') || '',
                db: currentBtn.getAttribute('data-acquirer-db') || '',
                doc_type: currentBtn.getAttribute('data-doctype') || '',
                doc_date: currentBtn.getAttribute('data-docdate') || '',
                doc_number: currentBtn.getAttribute('data-doc-number') || '',
                emitter_type: emitterTypeSelect ? String(emitterTypeSelect.value || '').trim() : '',
                document_fields: getCurrentDocumentFieldsPayload(),
                document_lines: getCurrentDocumentLinesPayload(),
                has_receipt_companion: currentBtn.getAttribute('data-has-receipt-companion') || '0',
                rates: rateLines
            },
            message: prompt,
            session_id: opts.session_id || 'ai_suggest_accounts'
        }).then(function(res) {
            var message = '';
            if (res) {
                message = res.message || res.error || res.details || '';
            }
            debugJson('IA resposta', res);
            window.aiExpectedLines = (res && res.expected_lines && typeof res.expected_lines === 'object') ? res.expected_lines : null;

            var parsed = null;
            if (res && typeof res === 'object' && res.rates && typeof res.rates === 'object') {
                parsed = {
                    rates: res.rates,
                    total_account: (typeof res.total_account === 'string' ? res.total_account : '')
                };
            }
            if (res && typeof res === 'object' && typeof res.erp_ligacao_capital_account === 'string') {
                currentBankLoanCapitalAccount = String(res.erp_ligacao_capital_account || '').trim();
            }
            if (!parsed) {
                parsed = extractJsonFromText(message);
            }
            if (Array.isArray(opts.rateLinesOverride) && opts.rateLinesOverride.length) {
                materializeBankLoanRateLines(opts.rateLinesOverride, { replaceExisting: true });
            }
            if (!parsed || !applyAiSuggestions(parsed, res)) {
                return false;
            }

            if (res) {
                window.aiSuggestionLogId = res.log_id || null;
                window.aiSuggestedAccounts = parsed.rates || null;
                window.aiSuggestionSources = [];
                if (Array.isArray(res.actions)) {
                    res.actions.forEach(function(action) {
                        if (action && action.type === 'suggest_accounts') {
                            if (action.user_correction_instructions && parseInt(action.user_correction_instructions, 10) > 0) {
                                window.aiSuggestionSources.push('user_classification_corrections');
                            }
                            if (parseInt(action.bank_mode, 10) === 1) {
                                window.aiSuggestionSources.push('bank_settings_erp');
                            }
                            if (action.history && parseInt(action.history, 10) > 0) {
                                window.aiSuggestionSources.push('mysql_history');
                            }
                            if (action.plan_db) {
                                window.aiSuggestionSources.push('erp_planocontas');
                            }
                            if (action.erp_ligacao && parseInt(action.erp_ligacao, 10) > 0) {
                                window.aiSuggestionSources.push('erp_ligacao_cte_tipo_doc');
                            }
                            if (action.rules && parseInt(action.rules, 10) > 0) {
                                window.aiSuggestionSources.push('mysql_classification_rules');
                            }
                            if (action.ai_instruction_rules && parseInt(action.ai_instruction_rules, 10) > 0) {
                                window.aiSuggestionSources.push('ai_prompt_extra_classification_rules');
                            }
                            if (action.instruction_operations && parseInt(action.instruction_operations, 10) > 0) {
                                window.aiSuggestionSources.push('entity_pair_ai_instructions');
                            }
                            if (action.erp_movimentos && parseInt(action.erp_movimentos, 10) > 0) {
                                window.aiSuggestionSources.push('erp_movimentos');
                            }
                        }
                    });
                }
            }

            return true;
        }).catch(function() {
            return false;
        });
    }

    function applyBankLoanConversionFromLines(lines) {
        var qrTotal = getDocumentFieldNumber('FIELD_O');
        var qrBase = getDocumentFieldNumber('FIELD_I2');
        var qrStamp = getDocumentFieldNumber('FIELD_M');
        if (qrBase === null && qrTotal !== null && qrStamp !== null && qrTotal > 0 && qrStamp > 0) {
            qrBase = qrTotal - qrStamp;
        }
        var totalGross = qrTotal !== null ? qrTotal : ((qrBase || 0) + (qrStamp || 0));
        if (!totalGross || totalGross <= 0) {
            showError('Não foi possível obter o total do documento a partir do QR.');
            return;
        }

        currentBankLoanDocumentLines = Array.isArray(lines) ? cloneJsonValue(lines, []) : [];
        var amounts = buildBankLoanAmountsFromLines(currentBankLoanDocumentLines, qrBase || 0, qrStamp || 0, totalGross);
        if (!amounts.matched) {
            amounts = buildFallbackBankLoanAmounts(totalGross, qrBase || 0, qrStamp || 0);
        }
        if (Math.abs((amounts.interest + amounts.commission) - totalGross) >= 0.03) {
            amounts.interest = totalGross - amounts.commission;
        }
        if (amounts.interest < 0) {
            amounts.interest = 0;
        }
        if (amounts.commission < 0) {
            amounts.commission = 0;
        }
        currentBankLoanResolvedAmounts = Object.assign({}, amounts);
        currentIgnoreDetectedRates = true;
        currentBankLoanConversionActive = true;
        currentClassificationModelName = '';
        var primaryRateKey = String(amounts.primaryRate || '0').trim() || '0';
        var primaryAmount = primaryRateKey === 'bank_loan_commission' ? totalGross : amounts.interest;
        var rateLinesOverride = [
            { key: primaryRateKey, label: '0', base: formatDecimalValue(primaryAmount), iva: '', bank_loan_conversion: '1' }
        ];
        if (!amounts.singleLine) {
            rateLinesOverride.push({
                key: 'bank_loan_commission',
                label: '0',
                base: formatDecimalValue(amounts.commission),
                iva: '',
                bank_loan_conversion: '1'
            });
        }
        if ((amounts.capital || 0) > 0) {
            rateLinesOverride.push({
                key: 'bank_loan_capital',
                label: '0',
                base: formatDecimalValue(amounts.capital),
                iva: '',
                bank_loan_conversion: '1'
            });
        }
        return requestAccountSuggestionsForCurrentRows({
            session_id: 'ai_suggest_accounts',
            rateLinesOverride: rateLinesOverride
        })
            .then(function(applied) {
                if ((currentBankLoanResolvedAmounts.capital || 0) > 0 && !rateInputs.bank_loan_capital) {
                    setBankLoanRate('bank_loan_capital', '0', currentBankLoanResolvedAmounts.capital, currentBankLoanCapitalAccount || '');
                }
                if ((currentBankLoanResolvedAmounts.capital || 0) > 0 && currentRateData.bank_loan_capital) {
                    currentRateData.bank_loan_capital.general_account = currentBankLoanCapitalAccount || String(currentRateData.bank_loan_capital.general_account || '').trim();
                    if (rateInputs.bank_loan_capital && rateInputs.bank_loan_capital.generalAccount) {
                        rateInputs.bank_loan_capital.generalAccount.value = currentRateData.bank_loan_capital.general_account;
                        updatePlanInputTitle(rateInputs.bank_loan_capital.generalAccount);
                    }
                }
                applyBankLoanResolvedAmountsToRows(currentBankLoanResolvedAmounts);
                finalizeBankLoanSingleLineResult(currentBankLoanResolvedAmounts);
                enforceBankLoanAccountRulesOnCurrentRows();
                refreshCostCenterFieldModes();
                refreshAllDirtyStates();
                if (currentBtn) {
                    currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
                    updateButtonClass(currentBtn);
                }
                showSuccess(applied
                    ? 'Lançamento de empréstimo bancário preparado com sugestões de contas. Confirme e grave a classificação.'
                    : 'Lançamento de empréstimo bancário preparado. Preencha as contas em falta e grave a classificação.'
                );
            });
    }

    function getCurrentDocumentFieldsPayload() {
        if (currentDocumentFieldValues && typeof currentDocumentFieldValues === 'object') {
            return cloneJsonValue(currentDocumentFieldValues, {});
        }
        if (currentBtn) {
            return cloneJsonValue(parseJsonAttribute(currentBtn, 'data-qr-fields') || {}, {});
        }
        return {};
    }

    function getCurrentDocumentLinesPayload() {
        if (currentBankLoanDocumentLines && Array.isArray(currentBankLoanDocumentLines)) {
            return cloneJsonValue(currentBankLoanDocumentLines, []);
        }
        return [];
    }

    function formatNumberValue(value) {
        var num = typeof value === 'number' ? value : parseDecimalValue(value);
        if (num === null || !isFinite(num)) {
            num = 0;
        }
        return num.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatPercentageDisplayValue(value) {
        var num = typeof value === 'number' ? value : parseDecimalValue(value);
        if (num === null || !isFinite(num)) {
            num = 0;
        }
        var rounded = Math.round(num);
        if (Math.abs(num - rounded) < 0.0005) {
            return String(rounded);
        }
        return num.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
        if (isBankLoanConversionRate(rate, rateData)) {
            clearBankLoanVatForRate(rate);
            if (opts.formatBase && baseNumber !== null) {
                var formattedBankLoanBase = formatDecimalValue(baseNumber);
                if (info.base.value !== formattedBankLoanBase) {
                    info.base.value = formattedBankLoanBase;
                }
                rateData.base = info.base.value;
                rateData.base_value = info.base.value;
            }
            return;
        }
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
        if (isAdjustedVatRateEntry(rateData)) {
            ivaValue = baseNumber * (percentage / 100) * 0.5;
        }
        var formattedIva = formatDecimalValue(ivaValue);
        if (info.iva && info.iva.value !== formattedIva) {
            info.iva.value = formattedIva;
        }
        rateData.iva = formattedIva;
        rateData.iva_value = formattedIva;
        if (isAdjustedVatRateEntry(rateData) && !isFuelCompanionRate(rate)) {
            ensureFuelCompanionLine(rate, ivaValue);
        }
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
        var emitterKey = btn.getAttribute('data-emitter') || btn.getAttribute('data-emitter-display') || '';
        var parts = [
            importType,
            btn.getAttribute('data-id') || '',
            emitterKey,
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

    function percentagesEqual(a, b) {
        if (a === null || b === null) {
            return false;
        }
        return Math.abs(a - b) < 0.0001;
    }

    function getOrderedRateKeys() {
        var tbody = form ? form.querySelector('tbody') : null;
        if (!tbody) {
            return getRateKeys();
        }
        var ordered = [];
        tbody.querySelectorAll('tr').forEach(function(row) {
            var rate = row.getAttribute('data-rate');
            if (rate && rateInputs[rate]) {
                ordered.push(rate);
            }
        });
        return ordered.length > 0 ? ordered : getRateKeys();
    }

    function findPrimaryRateForPercentage(targetPercentage, options) {
        if (targetPercentage === null) {
            return null;
        }
        var opts = options || {};
        var ordered = getOrderedRateKeys();
        for (var i = 0; i < ordered.length; i += 1) {
            var rate = ordered[i];
            if (opts.excludeRate && rate === opts.excludeRate) {
                continue;
            }
            var ratePercentage = getRatePercentage(rate);
            if (percentagesEqual(ratePercentage, targetPercentage)) {
                return rateInputs[rate] || null;
            }
        }
        return null;
    }

    function adjustPrimaryBaseForRateChange(changedRate, previousBaseNumber, newBaseNumber) {
        if (isBankLoanConversionRate(changedRate, ensureRateData(changedRate))) {
            return;
        }
        var ratePercentage = getRatePercentage(changedRate);
        if (ratePercentage === null) {
            return;
        }
        var primaryInfo = findPrimaryRateForPercentage(ratePercentage, { excludeRate: changedRate });
        if (!primaryInfo || !primaryInfo.base) {
            return;
        }
        if (isBankLoanConversionRate(primaryInfo.rate, ensureRateData(primaryInfo.rate))) {
            return;
        }
        var previousValue = previousBaseNumber === null || previousBaseNumber === undefined ? 0 : previousBaseNumber;
        var newValue = newBaseNumber === null || newBaseNumber === undefined ? 0 : newBaseNumber;
        var delta = newValue - previousValue;
        if (delta === 0) {
            return;
        }
        var primaryRate = primaryInfo.rate;
        var primaryData = ensureRateData(primaryRate);
        var currentPrimaryValue = parseDecimalValue(primaryInfo.base.value);
        if (currentPrimaryValue === null || currentPrimaryValue === undefined) {
            currentPrimaryValue = 0;
        }
        var adjustedValue = currentPrimaryValue - delta;
        var formattedValue = formatDecimalValue(adjustedValue);
        if (primaryInfo.base.value !== formattedValue) {
            primaryInfo.base.value = formattedValue;
        }
        primaryData.base = primaryInfo.base.value;
        primaryData.base_value = primaryInfo.base.value;
        recalculateVatForRate(primaryRate, { formatBase: true });
        updateRowDirtyState(primaryRate);
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

    function normalizeRateLabelForUi(value) {
        var text = String(value || '').trim();
        if (!text) {
            return '';
        }
        var normalized = normalizeRateToken(text);
        if (normalized === null) {
            return text;
        }
        return formatPercentageDisplayValue(normalized);
    }

    function buildRateLines() {
        if (!currentBtn) {
            return [];
        }
        var ordered = getOrderedRateKeys();
        return ordered.map(function(rateKey) {
            var label = getRateLabel(rateKey) || getDefaultRateLabel(rateKey);
            var data = currentRateData[rateKey] || {};
            var baseValue = getEntryAmount(data, 'base');
            var ivaValue = getEntryAmount(data, 'iva');
            return {
                key: rateKey,
                label: label,
                base: baseValue !== null ? String(baseValue) : '',
                iva: ivaValue !== null ? String(ivaValue) : '',
                bank_loan_conversion: String(data.bank_loan_conversion || '').trim() === '1' ? '1' : '0'
            };
        });
    }

    function buildRateExplanationPayload() {
        var rateLines = buildRateLines();
        return rateLines.map(function(line) {
            var info = rateInputs[line.key] || null;
            var data = currentRateData[line.key] || {};
            var ivaAccount = '';
            var generalAccount = '';
            if (info && info.ivaAccount) {
                ivaAccount = String(info.ivaAccount.value || '').trim();
            } else if (data && typeof data.iva_account === 'string') {
                ivaAccount = data.iva_account.trim();
            }
            if (info && info.generalAccount) {
                generalAccount = String(info.generalAccount.value || '').trim();
            } else if (data && typeof data.general_account === 'string') {
                generalAccount = data.general_account.trim();
            }
            return {
                key: line.key,
                label: line.label,
                base: line.base,
                iva: line.iva,
                iva_account: ivaAccount,
                general_account: generalAccount,
                bank_loan_conversion: String((data && data.bank_loan_conversion) || '').trim() === '1' ? '1' : '0'
            };
        });
    }

    function buildAiSuggestionPrompt(rateLinesOverride) {
        if (!currentBtn) {
            return '';
        }
        var docId = currentBtn.getAttribute('data-id') || '';
        var emitter = currentBtn.getAttribute('data-emitter-display') || currentBtn.getAttribute('data-emitter') || '';
        var emitterNif = currentBtn.getAttribute('data-emitter-nif') || '';
        var acquirerNif = currentBtn.getAttribute('data-acquirer') || '';
        var docType = currentBtn.getAttribute('data-doctype') || '';
        var docNumber = currentBtn.getAttribute('data-doc-number') || '';
        var hasReceiptCompanion = (currentBtn.getAttribute('data-has-receipt-companion') || '') === '1';
        var rateLines = Array.isArray(rateLinesOverride) ? cloneJsonValue(rateLinesOverride, []) : buildRateLines();
        var isBankLoanConversion = rateLines.some(function(line) {
            return String(line.bank_loan_conversion || '').trim() === '1';
        });
        var emitterType = emitterTypeSelect ? String(emitterTypeSelect.value || '').trim() : '';

        return [
            'Sugere contas IVA, contas gerais por taxa e conta do Valor Total.',
            'Usa a ferramenta suggest_accounts com NIF do adquirente, tipo de documento e taxas.',
            'Responde APENAS em JSON com o formato:',
                '{"rates":{"<rate_key>":{"iva_account":"", "general_account":""}},"total_account":""}',
                'Usa exatamente as chaves de taxa indicadas.',
                'Nao deixes campos vazios.',
                '',
                'Documento:',
                '- id: ' + docId,
                '- emitente: ' + emitter,
                '- NIF emitente: ' + emitterNif,
                '- NIF adquirente: ' + acquirerNif,
                '- tipo: ' + docType,
                '- numero: ' + docNumber,
                '- tipo de entidade: ' + emitterType,
                '- conversao emprestimo bancario: ' + (isBankLoanConversion ? 'sim' : 'nao'),
                '- digitalizacao conjunta com recibo RC: ' + (hasReceiptCompanion ? 'sim' : 'nao'),
                '',
            'Taxas:',
            JSON.stringify(rateLines)
        ].join('\n');
    }

    function materializeBankLoanRateLines(rateLines, options) {
        var opts = options || {};
        if (!Array.isArray(rateLines) || !rateLines.length) {
            return;
        }
        if (opts.replaceExisting === true) {
            var preservedBankLoanDocumentLines = Array.isArray(currentBankLoanDocumentLines)
                ? cloneJsonValue(currentBankLoanDocumentLines, [])
                : [];
            var preservedBankLoanResolvedAmounts = currentBankLoanResolvedAmounts && typeof currentBankLoanResolvedAmounts === 'object'
                ? cloneJsonValue(currentBankLoanResolvedAmounts, {})
                : null;
            clearClassificationRowsForBankLoanConversion();
            currentBankLoanDocumentLines = preservedBankLoanDocumentLines;
            currentBankLoanResolvedAmounts = preservedBankLoanResolvedAmounts;
        }
        rateLines.forEach(function(line) {
            if (!line || typeof line !== 'object') {
                return;
            }
            var rateKey = String(line.key || '').trim();
            if (!rateKey || rateInputs[rateKey]) {
                return;
            }
            if (String(line.bank_loan_conversion || '').trim() !== '1') {
                return;
            }
            setBankLoanRate(
                rateKey,
                String(line.label || '0').trim(),
                line.base !== undefined ? line.base : '',
                line.general_account || ''
            );
        });
    }

    function enforceBankLoanAccountRulesOnCurrentRows() {
        var applied = false;
        var singleLinePrimaryRate = (
            currentBankLoanResolvedAmounts
            && typeof currentBankLoanResolvedAmounts === 'object'
            && currentBankLoanResolvedAmounts.singleLine
        )
            ? String(currentBankLoanResolvedAmounts.primaryRate || '').trim()
            : '';
        Object.keys(currentRateData || {}).forEach(function(rate) {
            var data = currentRateData[rate] || {};
            if (String(data.bank_loan_conversion || '').trim() !== '1') {
                return;
            }
            var label = normalizeLoanLineDescription(data.label || getRateLabel(rate) || rate);
            var targetAccount = '';
            if (
                (singleLinePrimaryRate === 'bank_loan_commission' && rate === '0')
                || rate === 'bank_loan_commission'
                || label.indexOf('comiss') !== -1
                || label.indexOf('gestao') !== -1
            ) {
                targetAccount = '698812';
            } else if (rate === 'bank_loan_capital') {
                targetAccount = currentBankLoanCapitalAccount || String(data.general_account || '').trim();
            } else {
                targetAccount = '6911';
            }
            if (String(data.general_account || '').trim() !== targetAccount) {
                data.general_account = targetAccount;
                applied = true;
            }
            data.iva_account = '';
            var info = rateInputs[rate];
            if (info) {
                if (info.generalAccount && String(info.generalAccount.value || '').trim() !== targetAccount) {
                    info.generalAccount.value = targetAccount;
                    updatePlanInputTitle(info.generalAccount);
                }
                if (info.ivaAccount) {
                    info.ivaAccount.value = '';
                }
                updateRowDirtyState(rate);
            }
        });
        if (applied && currentBtn) {
            currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
            updateButtonClass(currentBtn);
        }
        return applied;
    }

    function finalizeBankLoanSingleLineResult(amounts) {
        if (!amounts || typeof amounts !== 'object' || !amounts.singleLine) {
            return false;
        }
        var sourceRate = String(amounts.primaryRate || '0').trim() || '0';
        var targetRate = '0';
        var targetAmount = sourceRate === 'bank_loan_commission'
            ? (amounts.interest + amounts.commission)
            : amounts.interest;
        var targetAccount = sourceRate === 'bank_loan_commission' ? '698812' : '6911';
        var changed = false;

        Object.keys(rateInputs || {}).forEach(function(rate) {
            if (rate === targetRate || rate === 'bank_loan_capital') {
                return;
            }
            if (rateInputs[rate]) {
                removeRateRow(rate);
                changed = true;
            }
        });

        if (!rateInputs[targetRate]) {
            setBankLoanRate(targetRate, '0', targetAmount, targetAccount);
            changed = true;
        }

        var data = ensureRateData(targetRate);
        var formatted = formatDecimalValue(targetAmount);
        if (String(data.base || '').trim() !== formatted) {
            data.base = formatted;
            data.base_value = formatted;
            changed = true;
        }
        if (String(data.general_account || '').trim() !== targetAccount) {
            data.general_account = targetAccount;
            changed = true;
        }
        data.iva = '';
        data.iva_value = '';
        data.iva_account = '';
        data.bank_loan_conversion = '1';

        var info = rateInputs[targetRate];
        if (info) {
            if (info.base && String(info.base.value || '').trim() !== formatted) {
                info.base.value = formatted;
            }
            if (info.generalAccount && String(info.generalAccount.value || '').trim() !== targetAccount) {
                info.generalAccount.value = targetAccount;
                updatePlanInputTitle(info.generalAccount);
            }
            if (info.iva) {
                info.iva.value = '';
            }
            if (info.ivaAccount) {
                info.ivaAccount.value = '';
            }
            populateRateRow(targetRate);
            updateRowDirtyState(targetRate);
        }

        if (changed && currentBtn) {
            currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
            updateButtonClass(currentBtn);
        }

        return changed;
    }

    function applyBankLoanResolvedAmountsToRows(amounts) {
        if (!amounts || typeof amounts !== 'object') {
            return false;
        }
        var applied = false;
        [
            { rate: '0', amount: amounts.interest },
            { rate: 'bank_loan_commission', amount: amounts.singleLine && String(amounts.primaryRate || '') === 'bank_loan_commission' ? (amounts.interest + amounts.commission) : amounts.commission },
            { rate: 'bank_loan_capital', amount: amounts.capital }
        ].forEach(function(entry) {
            var rate = entry.rate;
            if (!currentRateData[rate] || String(currentRateData[rate].bank_loan_conversion || '').trim() !== '1') {
                return;
            }
            if (amounts.singleLine && rate !== String(amounts.primaryRate || '0')) {
                return;
            }
            var formatted = formatDecimalValue(entry.amount);
            if (String(currentRateData[rate].base || '').trim() !== formatted) {
                currentRateData[rate].base = formatted;
                currentRateData[rate].base_value = formatted;
                applied = true;
            }
            currentRateData[rate].iva = '';
            currentRateData[rate].iva_value = '';
            var info = rateInputs[rate];
            if (info) {
                if (info.base && String(info.base.value || '').trim() !== formatted) {
                    info.base.value = formatted;
                }
                if (info.iva) {
                    info.iva.value = '';
                }
                updateRowDirtyState(rate);
            }
        });
        if (applied && currentBtn) {
            currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
            updateButtonClass(currentBtn);
        }
        return applied;
    }

    function resolveTotalAccountSuggestion(payload, expectedLines, assistantResponse) {
        if (payload && typeof payload === 'object') {
            var direct = resolveSuggestionValue(payload, ['total_account', 'totalAccount', 'conta_total', 'account_total']);
            if (direct) {
                return direct;
            }
            if (payload.meta && typeof payload.meta === 'object') {
                var fromMeta = resolveSuggestionValue(payload.meta, ['total_account', 'totalAccount', 'conta_total']);
                if (fromMeta) {
                    return fromMeta;
                }
            }
        }
        if (assistantResponse && typeof assistantResponse === 'object') {
            var responseDirect = resolveSuggestionValue(assistantResponse, ['total_account', 'totalAccount', 'conta_total', 'account_total']);
            if (responseDirect) {
                return responseDirect;
            }
            if (Array.isArray(assistantResponse.actions)) {
                for (var i = 0; i < assistantResponse.actions.length; i += 1) {
                    var action = assistantResponse.actions[i];
                    if (!action || action.type !== 'suggest_accounts') {
                        continue;
                    }
                    var actionTotal = resolveSuggestionValue(action, ['total_account', 'totalAccount', 'conta_total', 'account_total']);
                    if (actionTotal) {
                        return actionTotal;
                    }
                }
            }
        }
        if (expectedLines && typeof expectedLines === 'object') {
            var expected = resolveSuggestionValue(expectedLines, ['total_account', 'totalAccount', 'conta_total']);
            if (expected) {
                return expected;
            }
        }
        return '';
    }

    function extractTotalAccountFromResponse(res) {
        if (!res || typeof res !== 'object') {
            return '';
        }
        var raw = res.total_account;
        if (typeof raw === 'string') {
            return raw.trim();
        }
        if (raw && typeof raw === 'object' && typeof raw.suggested === 'string') {
            return raw.suggested.trim();
        }
        return '';
    }

    function extractJsonFromText(text) {
        if (!text) {
            return null;
        }
        var trimmed = text.trim();
        if (trimmed[0] === '{' || trimmed[0] === '[') {
            try {
                return JSON.parse(trimmed);
            } catch (e) {
                return null;
            }
        }
        var start = trimmed.indexOf('{');
        var end = trimmed.lastIndexOf('}');
        if (start >= 0 && end > start) {
            var slice = trimmed.slice(start, end + 1);
            try {
                return JSON.parse(slice);
            } catch (err) {
                return null;
            }
        }
        return null;
    }

    function normalizeAiRatesPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }
        if (payload.rates && typeof payload.rates === 'object') {
            return payload.rates;
        }
        if (Array.isArray(payload.suggestions)) {
            var map = {};
            payload.suggestions.forEach(function(item) {
                if (!item || typeof item !== 'object') {
                    return;
                }
                var key = item.rate_key || item.key || item.rate;
                if (key) {
                    map[String(key)] = item;
                }
            });
            return map;
        }
        return null;
    }

    function resolveSuggestionValue(suggestion, keys) {
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            if (suggestion && typeof suggestion === 'object' && suggestion[key]) {
                return String(suggestion[key]).trim();
            }
        }
        return '';
    }

    function normalizeRateToken(value) {
        if (value === null || value === undefined) {
            return null;
        }
        var text = String(value).trim().toLowerCase();
        if (!text) {
            return null;
        }
        text = text.replace(',', '.');
        var numMatch = text.match(/([0-9]+(\.[0-9]+)?)/);
        if (!numMatch) {
            return null;
        }
        var num = parseFloat(numMatch[1]);
        if (isNaN(num)) {
            return null;
        }
        if (num > 0 && num <= 1) {
            num = num * 100;
        }
        return Math.round(num * 100) / 100;
    }

    function findRateKeyByToken(token) {
        if (token === null || token === undefined) {
            return null;
        }
        var normalized = normalizeRateToken(token);
        if (normalized === null) {
            return null;
        }
        var keys = Object.keys(rateInputs);
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            var label = getRateLabel(key) || getDefaultRateLabel(key);
            var labelToken = normalizeRateToken(label);
            var keyToken = normalizeRateToken(key);
            if (labelToken !== null && Math.abs(labelToken - normalized) < 0.001) {
                return key;
            }
            if (keyToken !== null && Math.abs(keyToken - normalized) < 0.001) {
                return key;
            }
        }
        return null;
    }

    function getInstructionOperationsFromResponse(response) {
        if (!response || typeof response !== 'object') {
            return null;
        }
        if (response.instruction_operations && typeof response.instruction_operations === 'object') {
            return response.instruction_operations;
        }
        if (response.operations && typeof response.operations === 'object') {
            return response.operations;
        }
        if (Array.isArray(response.actions)) {
            for (var i = 0; i < response.actions.length; i += 1) {
                var action = response.actions[i];
                if (action && action.instruction_operations && typeof action.instruction_operations === 'object') {
                    return action.instruction_operations;
                }
            }
        }
        return null;
    }

    function getResolvedBankLoanSingleLineTargetRate() {
        if (!currentBankLoanResolvedAmounts || typeof currentBankLoanResolvedAmounts !== 'object') {
            return null;
        }
        if (!currentBankLoanResolvedAmounts.singleLine) {
            return null;
        }
        return '0';
    }

    function enforceResolvedBankLoanRateShape() {
        if (!currentBankLoanConversionActive) {
            return false;
        }

        var singleTargetRate = getResolvedBankLoanSingleLineTargetRate();
        var allowedRates = singleTargetRate ? [singleTargetRate] : ['0', 'bank_loan_commission', 'bank_loan_capital'];
        var allowedMap = {};
        var changed = false;
        var targetAmount = null;
        var targetAccount = null;

        allowedRates.forEach(function(rate) {
            allowedMap[rate] = true;
        });

        Object.keys(rateInputs || {}).forEach(function(rate) {
            if (allowedMap[rate]) {
                return;
            }
            removeRateRow(rate);
            changed = true;
        });

        if (singleTargetRate && currentBankLoanResolvedAmounts) {
            targetAmount = String(currentBankLoanResolvedAmounts.primaryRate || '').trim() === 'bank_loan_commission'
                ? ((currentBankLoanResolvedAmounts.interest || 0) + (currentBankLoanResolvedAmounts.commission || 0))
                : (currentBankLoanResolvedAmounts.interest || 0);
            targetAccount = String(currentBankLoanResolvedAmounts.primaryRate || '').trim() === 'bank_loan_commission' ? '698812' : '6911';
            if (!rateInputs[singleTargetRate]) {
                setBankLoanRate(singleTargetRate, '0', targetAmount, targetAccount);
                changed = true;
            }
        }

        if (currentBankLoanResolvedAmounts && (currentBankLoanResolvedAmounts.capital || 0) > 0 && !rateInputs.bank_loan_capital) {
            setBankLoanRate('bank_loan_capital', '0', currentBankLoanResolvedAmounts.capital, currentBankLoanCapitalAccount || '');
            changed = true;
        }

        allowedRates.forEach(function(rate) {
            if (!currentRateData[rate]) {
                return;
            }
            currentRateData[rate].label = '0';
            currentRateData[rate].iva = '';
            currentRateData[rate].iva_value = '';
            currentRateData[rate].iva_account = '';
            currentRateData[rate].bank_loan_conversion = '1';
            if (rate === singleTargetRate && targetAmount !== null) {
                currentRateData[rate].base = formatDecimalValue(targetAmount);
                currentRateData[rate].base_value = currentRateData[rate].base;
                currentRateData[rate].general_account = targetAccount;
            } else if (rate === 'bank_loan_capital' && currentBankLoanResolvedAmounts) {
                currentRateData[rate].base = formatDecimalValue(currentBankLoanResolvedAmounts.capital || 0);
                currentRateData[rate].base_value = currentRateData[rate].base;
                currentRateData[rate].general_account = currentBankLoanCapitalAccount || currentRateData[rate].general_account || '';
            }
            populateRateRow(rate);
            updateRowDirtyState(rate);
        });

        if (changed && currentBtn) {
            currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
            updateButtonClass(currentBtn);
        }

        return changed;
    }

    function applyInstructionOperations(response) {
        var operations = getInstructionOperationsFromResponse(response);
        if (!operations || typeof operations !== 'object') {
            return false;
        }

        var applied = false;
        if (operations.ignore_detected_rates === true) {
            currentIgnoreDetectedRates = true;
            applied = true;
        }

        if (Array.isArray(operations.remove_rates)) {
            operations.remove_rates.forEach(function(rate) {
                rate = String(rate || '').trim();
                if (rate && rateInputs[rate]) {
                    removeRateRow(rate);
                    applied = true;
                }
            });
        }

        var rates = operations.rates && typeof operations.rates === 'object' ? operations.rates : {};
        Object.keys(rates).forEach(function(rateKey) {
            var entry = rates[rateKey];
            if (!entry || typeof entry !== 'object') {
                return;
            }
            var resolvedKey = rateInputs[rateKey] ? rateKey : (findRateKeyByToken(rateKey) || rateKey);
            var info = ensureRateRow(resolvedKey, entry, { allowCreate: true });
            if (!info) {
                return;
            }
            var data = ensureRateData(resolvedKey);
            if (typeof entry.label === 'string' && entry.label.trim() !== '') {
                data.label = entry.label.trim();
                if (currentBankLoanConversionActive) {
                    data.label = '0';
                }
                if (info.labelInput) {
                    info.labelInput.value = data.label;
                } else if (info.labelText) {
                    info.labelText.textContent = data.label;
                }
            }
            if (entry.base !== undefined || entry.base_value !== undefined) {
                var instructionBase = String(entry.base !== undefined ? entry.base : entry.base_value).trim();
                if (instructionBase !== '') {
                    data.base = instructionBase;
                    data.base_value = data.base;
                }
            }
            if (entry.iva !== undefined || entry.iva_value !== undefined) {
                var instructionIva = String(entry.iva !== undefined ? entry.iva : entry.iva_value).trim();
                if (instructionIva !== '') {
                    data.iva = instructionIva;
                    data.iva_value = data.iva;
                }
            }
            if (entry.general_account !== undefined && String(entry.general_account || '').trim() !== '') {
                data.general_account = sanitizeAccountCodeForRate(String(entry.general_account || '').trim(), resolvedKey);
            }
            if (entry.iva_account !== undefined && String(entry.iva_account || '').trim() !== '') {
                data.iva_account = sanitizeAccountCodeForRate(String(entry.iva_account || '').trim(), resolvedKey);
            }
            if (entry.erp_rubric_code !== undefined && String(entry.erp_rubric_code || '').trim() !== '') {
                data.erp_rubric_code = String(entry.erp_rubric_code || '').trim();
            }
            if (entry.cost_center !== undefined && String(entry.cost_center || '').trim() !== '') {
                currentCostCenters[resolvedKey] = String(entry.cost_center || '').trim();
            }
            if (String(entry.bank_loan_conversion || '').trim() === '1') {
                data.bank_loan_conversion = '1';
                currentBankLoanConversionActive = true;
            }
            populateRateRow(resolvedKey);
            if ((entry.iva === undefined && entry.iva_value === undefined) && data.base) {
                recalculateVatForRate(resolvedKey, { formatBase: true });
            }
            updateCostCenterFieldMode(resolvedKey);
            updateRowDirtyState(resolvedKey);
            applied = true;
        });

        if (typeof operations.total_account === 'string' && operations.total_account.trim() !== '' && totalAccountInput) {
            currentTotalAccount = operations.total_account.trim();
            totalAccountInput.value = currentTotalAccount;
            updatePlanInputTitle(totalAccountInput);
            if (currentBtn) {
                currentBtn.setAttribute('data-total-account', currentTotalAccount);
            }
            applied = true;
        }

        if (applied && currentBtn) {
            currentBtn.setAttribute('data-rates', JSON.stringify(currentRateData));
            currentBtn.setAttribute('data-cost-centers', JSON.stringify(currentCostCenters));
            rebuildRequirementsForCurrentButton();
            updateButtonClass(currentBtn);
            refreshCostCenterFieldModes();
            refreshAllDirtyStates();
            enforceResolvedBankLoanRateShape();
        }

        return applied;
    }

    function applyAiSuggestions(payload, assistantResponse) {
        var ratesPayload = normalizeAiRatesPayload(payload);
        var expectedLines = window.aiExpectedLines && typeof window.aiExpectedLines === 'object' ? window.aiExpectedLines : {};
        var totalAccountSuggested = resolveTotalAccountSuggestion(payload, expectedLines, assistantResponse);
        var instructionOperations = getInstructionOperationsFromResponse(assistantResponse);
        if (!ratesPayload && !totalAccountSuggested && !instructionOperations) {
            return false;
        }
        var applied = false;
        if (ratesPayload && typeof ratesPayload === 'object') {
            Object.keys(ratesPayload).forEach(function(rateKey) {
            var resolvedKey = rateKey;
            var info = rateInputs[resolvedKey];
            if (!info) {
                var suggestionMeta = ratesPayload[rateKey] || {};
                resolvedKey = findRateKeyByToken(rateKey)
                    || findRateKeyByToken(suggestionMeta.label)
                    || findRateKeyByToken(suggestionMeta.taxa)
                    || findRateKeyByToken(suggestionMeta.rate);
                if (resolvedKey) {
                    info = rateInputs[resolvedKey];
                }
            }
            if (!info) {
                return;
            }
            var suggestion = ratesPayload[rateKey] || {};
            var hasExplicitIvaAccount = Object.prototype.hasOwnProperty.call(suggestion, 'iva_account')
                || Object.prototype.hasOwnProperty.call(suggestion, 'ivaAccount')
                || Object.prototype.hasOwnProperty.call(suggestion, 'conta_iva')
                || Object.prototype.hasOwnProperty.call(suggestion, 'contaIVA');
            var ivaAccount = resolveSuggestionValue(suggestion, ['iva_account', 'ivaAccount', 'conta_iva', 'contaIVA']);
            var generalAccount = resolveSuggestionValue(suggestion, ['general_account', 'generalAccount', 'conta_geral', 'contaGeral', 'account']);
            ivaAccount = sanitizeAccountCodeForRate(ivaAccount, resolvedKey);
            generalAccount = sanitizeAccountCodeForRate(generalAccount, resolvedKey);
            var normalizedResolvedRate = normalizeRateToken(resolvedKey);
            if (normalizedResolvedRate !== null && Math.abs(normalizedResolvedRate) < 0.00001) {
                ivaAccount = '';
            }
            var updated = false;
            if (ivaAccount || hasExplicitIvaAccount || (normalizedResolvedRate !== null && Math.abs(normalizedResolvedRate) < 0.00001)) {
                var currentIvaAccount = info.ivaAccount ? String(info.ivaAccount.value || '').trim() : String((ensureRateData(resolvedKey).iva_account || '')).trim();
                if (info.ivaAccount) {
                    info.ivaAccount.value = ivaAccount;
                }
                ensureRateData(resolvedKey).iva_account = ivaAccount;
                if (currentIvaAccount !== ivaAccount) {
                    updated = true;
                    applied = true;
                }
            }
            if (generalAccount) {
                var currentGeneralAccount = info.generalAccount ? String(info.generalAccount.value || '').trim() : String((ensureRateData(resolvedKey).general_account || '')).trim();
                if (info.generalAccount) {
                    info.generalAccount.value = generalAccount;
                }
                ensureRateData(resolvedKey).general_account = generalAccount;
                if (currentGeneralAccount !== generalAccount) {
                    updated = true;
                    applied = true;
                }
            }
            var rubricCode = resolveSuggestionValue(suggestion, ['erp_rubric_code', 'rubric_code', 'rubrica_codigo', 'rubrica']);
            if (rubricCode) {
                var currentRubricCode = String((ensureRateData(resolvedKey).erp_rubric_code || '')).trim();
                ensureRateData(resolvedKey).erp_rubric_code = rubricCode;
                if (currentRubricCode !== rubricCode) {
                    updated = true;
                    applied = true;
                }
            }
            if (updated) {
                populateRateRow(resolvedKey);
                updateRowDirtyState(resolvedKey);
            }
            });
        }

        var requiredRates = null;
        if (assistantResponse && typeof assistantResponse === 'object' && assistantResponse.cost_center_required_rates && typeof assistantResponse.cost_center_required_rates === 'object') {
            requiredRates = assistantResponse.cost_center_required_rates;
        } else if (assistantResponse && Array.isArray(assistantResponse.actions)) {
            assistantResponse.actions.forEach(function(action) {
                if (requiredRates || !action || action.type !== 'suggest_accounts') {
                    return;
                }
                if (action.cost_center_required_rates && typeof action.cost_center_required_rates === 'object') {
                    requiredRates = action.cost_center_required_rates;
                }
            });
        }
        if (requiredRates) {
            applyCostCenterRequirementMap(requiredRates);
        }
        if (applyInstructionOperations(assistantResponse)) {
            applied = true;
        }
        // totalAccountSuggested aplica-se por ultimo para ganhar sobre instruction_operations.
        if (totalAccountSuggested && totalAccountInput) {
            var currentSuggestedTotal = String(totalAccountInput.value || '').trim();
            totalAccountInput.value = totalAccountSuggested;
            updatePlanInputTitle(totalAccountInput);
            currentTotalAccount = totalAccountSuggested;
            if (currentBtn) {
                currentBtn.setAttribute('data-total-account', totalAccountSuggested);
            }
            if (currentSuggestedTotal !== totalAccountSuggested) {
                applied = true;
            }
        }
        return applied;
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
            costCenterDistributionBtn: row.querySelector('.cost-center-distribution-btn') || null,
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
        if (!Object.prototype.hasOwnProperty.call(currentCostCenterBreakdowns, rate)) {
            currentCostCenterBreakdowns[rate] = [];
        }
        if (info.costCenter) {
            info.costCenter.removeAttribute('readonly');
            info.costCenter.disabled = false;
            setCostCenterFieldOptions(info.costCenter, currentCostCenters[rate] || '');
            info.costCenter.addEventListener('change', function() {
                currentCostCenters[rate] = info.costCenter.value;
            });
            info.costCenter.disabled = false;
            info.costCenter.addEventListener('input', function() {
                currentCostCenters[rate] = info.costCenter.value;
            });
        }
        if (info.costCenterDistributionBtn) {
            info.costCenterDistributionBtn.addEventListener('click', function() {
                openCostCenterDistributionModal(rate);
            });
        }
        if (info.base) {
            info.base.removeAttribute('readonly');
            info.base.readOnly = false;
            info.base.addEventListener('input', function() {
                var rateData = ensureRateData(rate);
                var previousBaseNumber = parseDecimalValue(rateData.base);
                rateData.base = info.base.value;
                rateData.base_value = info.base.value;
                recalculateVatForRate(rate);
                updateRowDirtyState(rate);
                adjustPrimaryBaseForRateChange(rate, previousBaseNumber, parseDecimalValue(info.base.value));
            });
            info.base.addEventListener('blur', function() {
                recalculateVatForRate(rate, { formatBase: true });
                updateRowDirtyState(rate);
            });
        }
        if (info.iva) {
            info.iva.readOnly = true;
        }
        if (info.ivaAccount) {
            attachPlanAutocompleteToInput(info.ivaAccount);
            info.ivaAccount.addEventListener('input', function() {
                var rateData = ensureRateData(rate);
                rateData.iva_account = info.ivaAccount.value;
                syncFuelRubricAdjustmentForRate(rate, { formatBase: true });
                updateCostCenterFieldMode(rate);
                updateRowDirtyState(rate);
            });
            info.ivaAccount.addEventListener('change', function() {
                var rateData = ensureRateData(rate);
                rateData.iva_account = info.ivaAccount.value;
                syncFuelRubricAdjustmentForRate(rate, { formatBase: true });
                updateCostCenterFieldMode(rate);
                updateRowDirtyState(rate);
            });
            info.ivaAccount.addEventListener('blur', function() {
                var rateData = ensureRateData(rate);
                rateData.iva_account = info.ivaAccount.value;
                syncFuelRubricAdjustmentForRate(rate, { formatBase: true });
                updateCostCenterFieldMode(rate);
                updateRowDirtyState(rate);
            });
        }
        if (info.generalAccount) {
            attachPlanAutocompleteToInput(info.generalAccount);
            info.generalAccount.addEventListener('input', function() {
                var rateData = ensureRateData(rate);
                rateData.general_account = info.generalAccount.value;
                syncFuelRubricAdjustmentForRate(rate, { formatBase: true });
                updateCostCenterFieldMode(rate);
                updateRowDirtyState(rate);
            });
            info.generalAccount.addEventListener('change', function() {
                var rateData = ensureRateData(rate);
                rateData.general_account = info.generalAccount.value;
                syncFuelRubricAdjustmentForRate(rate, { formatBase: true });
                updateCostCenterFieldMode(rate);
                updateRowDirtyState(rate);
            });
            info.generalAccount.addEventListener('blur', function() {
                var rateData = ensureRateData(rate);
                rateData.general_account = info.generalAccount.value;
                syncFuelRubricAdjustmentForRate(rate, { formatBase: true });
                updateCostCenterFieldMode(rate);
                updateRowDirtyState(rate);
            });
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
        updateCostCenterFieldMode(rate);
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
        delete currentCostCenterBreakdowns[rate];
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
        var baseRates = Array.isArray(defaultRates) ? defaultRates : Object.keys(defaultRateLabels || {});
        baseRates.forEach(function(rate) {
            result[rate] = '';
        });
        Object.keys(currentCostCenters || {}).forEach(function(rate) {
            if (!Object.prototype.hasOwnProperty.call(result, rate)) {
                result[rate] = '';
            }
        });
        getRateKeys().forEach(function(rate) {
            result[rate] = '';
        });
        Object.keys(currentRateData || {}).forEach(function(rate) {
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

    function createEmptyCostCenterBreakdowns() {
        var result = {};
        Object.keys(createEmptyCostCenters()).forEach(function(rate) {
            result[rate] = [];
        });
        return result;
    }

    function normalizeCostCenterDistributionRows(rows) {
        if (!Array.isArray(rows)) {
            return [];
        }
        var normalized = [];
        rows.forEach(function(row) {
            if (!row || typeof row !== 'object') {
                return;
            }
            var code = String(row.cost_center || row.code || row.strConta_CCusto || '').trim();
            if (!code) {
                return;
            }
            var percentage = parseDecimalValue(row.percentage || row.fltPercentagem || '');
            var value = parseDecimalValue(row.value || row.fltValor || '');
            normalized.push({
                cost_center: code,
                percentage: percentage === null ? '' : formatDecimalValue(percentage),
                value: value === null ? '' : formatDecimalValue(value)
            });
        });
        return normalized;
    }

    function normalizeCostCenterBreakdownValues(value) {
        var normalized = createEmptyCostCenterBreakdowns();
        if (!value || typeof value !== 'object') {
            return normalized;
        }
        var source = value;
        if (source.rates && typeof source.rates === 'object') {
            source = source.rates;
        }
        Object.keys(source).forEach(function(rate) {
            if (!Object.prototype.hasOwnProperty.call(normalized, rate)) {
                normalized[rate] = [];
            }
            var entry = source[rate];
            if (entry && typeof entry === 'object' && Array.isArray(entry.distribution)) {
                normalized[rate] = normalizeCostCenterDistributionRows(entry.distribution);
            } else if (entry && typeof entry === 'object' && Array.isArray(entry.entries)) {
                normalized[rate] = normalizeCostCenterDistributionRows(entry.entries);
            } else if (Array.isArray(entry)) {
                normalized[rate] = normalizeCostCenterDistributionRows(entry);
            }
        });
        return normalized;
    }

    function applyCostCenterBreakdownValues(value) {
        var normalized = normalizeCostCenterBreakdownValues(value);
        currentCostCenterBreakdowns = {};
        Object.keys(normalized).forEach(function(rate) {
            currentCostCenterBreakdowns[rate] = normalized[rate];
        });
        getRateKeys().forEach(function(rate) {
            updateCostCenterFieldMode(rate);
        });
    }

    function getCostCenterBreakdownValues() {
        var values = {};
        Object.keys(currentCostCenterBreakdowns || {}).forEach(function(rate) {
            var rows = normalizeCostCenterDistributionRows(currentCostCenterBreakdowns[rate] || []);
            var info = rateInputs[rate] || null;
            var totalAmount = info && info.base ? (parseDecimalValue(info.base.value) || 0) : 0;
            values[rate] = rows.map(function(row) {
                var percentage = parseDecimalValue(row.percentage) || 0;
                return {
                    cost_center: row.cost_center,
                    percentage: formatDecimalValue(percentage),
                    value: formatDecimalValue(totalAmount * (percentage / 100))
                };
            });
        });
        return values;
    }

    function getCostCenterOptionMeta(code) {
        var target = String(code || '').trim();
        if (!target) {
            return null;
        }
        var options = Array.isArray(currentCostCenterOptions) ? currentCostCenterOptions : [];
        for (var i = 0; i < options.length; i += 1) {
            var option = options[i];
            if (String(option.code || '').trim() === target) {
                return option;
            }
        }
        return null;
    }

    function getCostCenterOptionDescription(code) {
        var option = getCostCenterOptionMeta(code);
        if (!option) {
            return '';
        }
        return String(option.description || option.label || option.code || '').trim();
    }

    function isRateCostCenterRequired(rate) {
        var requirements = currentBtn ? (parseJsonAttribute(currentBtn, 'data-requirements') || {}) : {};
        var rateData = ensureRateData(rate);
        var info = rateInputs[rate] || null;
        var accountCandidates = [
            info && info.generalAccount ? info.generalAccount.value : '',
            info && info.ivaAccount ? info.ivaAccount.value : '',
            rateData.general_account || '',
            rateData.iva_account || '',
            storedRowRates[rate] && storedRowRates[rate].general_account ? storedRowRates[rate].general_account : '',
            storedDefaultRates[rate] && storedDefaultRates[rate].general_account ? storedDefaultRates[rate].general_account : ''
        ];
        if (requirements[rate] && requirements[rate].cost_center) {
            return true;
        }
        if (String(rateData.cost_center_required || '').trim() === '1') {
            return true;
        }
        return accountCandidates.some(function(value) {
            return String(value || '').indexOf('?') !== -1;
        });
    }

    function rateHasAssignedAccount(rate) {
        var info = rateInputs[rate] || null;
        var rateData = ensureRateData(rate);
        var candidates = [
            info && info.generalAccount ? info.generalAccount.value : '',
            info && info.ivaAccount ? info.ivaAccount.value : '',
            rateData.general_account || '',
            rateData.iva_account || ''
        ];
        return candidates.some(function(value) {
            return String(value || '').trim() !== '';
        });
    }

    function getCostCenterDistributionSummary(rate) {
        var rows = normalizeCostCenterDistributionRows(currentCostCenterBreakdowns[rate] || []);
        if (!rows.length) {
            return '';
        }
        var labels = rows.map(function(row) {
            return row.cost_center + ' (' + (row.percentage || '0.00') + '%)';
        });
        return labels.join(', ');
    }

    function updateCostCenterFieldMode(rate) {
        var info = rateInputs[rate];
        if (!info || !info.row) {
            return;
        }
        var selectEl = info.costCenter;
        var wrapEl = info.row.querySelector('.cost-center-distribution-wrap');
        var btnEl = info.row.querySelector('.cost-center-distribution-btn');
        var summaryEl = info.row.querySelector('.cost-center-distribution-summary');
        var required = isRateCostCenterRequired(rate);
        var hasValue = normalizeCostCenterDistributionRows(currentCostCenterBreakdowns[rate] || []).length > 0;
        if (selectEl) {
            selectEl.classList.add('d-none');
        }
        if (wrapEl) {
            wrapEl.classList.remove('d-none');
        }
        if (btnEl) {
            btnEl.classList.remove('btn-default', 'btn-warning', 'btn-success');
            if (hasValue) {
                btnEl.classList.add('btn-success');
            } else if (required || rateHasAssignedAccount(rate)) {
                btnEl.classList.add('btn-warning');
            } else {
                btnEl.classList.add('btn-default');
            }
        }
        if (summaryEl) {
            summaryEl.textContent = getCostCenterDistributionSummary(rate);
        }
    }

    function refreshCostCenterFieldModes() {
        getRateKeys().forEach(function(rate) {
            updateCostCenterFieldMode(rate);
        });
    }

    function setCostCenterDistributionMeta(field, value) {
        if (field) {
            field.value = value || '';
        }
    }

    function renderCostCenterDistributionOptions(selectEl, selectedValue) {
        if (!selectEl) {
            return;
        }
        var selected = String(selectedValue || '').trim();
        var html = '<option value="">Selecione o centro de custo</option>';
        (currentCostCenterOptions || []).forEach(function(option) {
            var code = String(option.code || '').trim();
            if (!code) {
                return;
            }
            var description = String(option.description || '').trim();
            var label = description ? (code + ' - ' + description) : code;
            html += '<option value="' + escapeHtml(code) + '"' + (selected === code ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
        });
        if (selected && !getCostCenterOptionMeta(selected)) {
            html += '<option value="' + escapeHtml(selected) + '" selected>' + escapeHtml(selected) + '</option>';
        }
        selectEl.innerHTML = html;
        selectEl.value = selected;
    }

    function recalculateCostCenterDistributionModal() {
        if (!costCenterDistributionTableBody) {
            return;
        }
        var rate = currentCostCenterDistributionRate;
        var info = rate ? rateInputs[rate] : null;
        var totalAmount = info && info.base ? (parseDecimalValue(info.base.value) || 0) : 0;
        var rows = costCenterDistributionTableBody.querySelectorAll('tr');
        var totalPercentage = 0;
        rows.forEach(function(row) {
            var percentageInput = row.querySelector('.cc-distribution-percentage');
            var valueCell = row.querySelector('.cc-distribution-value');
            var percentage = percentageInput ? parseDecimalValue(percentageInput.value) : null;
            if (percentage === null) {
                percentage = 0;
            }
            totalPercentage += percentage;
            var lineValue = totalAmount * (percentage / 100);
            if (valueCell) {
                valueCell.textContent = formatNumberValue(lineValue);
            }
        });
        var remainingPercentage = 100 - totalPercentage;
        if (Math.abs(remainingPercentage) <= 0.01) {
            remainingPercentage = 0;
        }
        remainingPercentage = Math.max(0, remainingPercentage);
        var remainingAmount = totalAmount * (remainingPercentage / 100);
        if (costCenterDistributionPercentAssignedEl) {
            costCenterDistributionPercentAssignedEl.textContent = formatPercentageDisplayValue(totalPercentage);
        }
        if (costCenterDistributionPercentRemainingEl) {
            costCenterDistributionPercentRemainingEl.textContent = formatPercentageDisplayValue(remainingPercentage);
        }
        if (costCenterDistributionAmountRemainingEl) {
            costCenterDistributionAmountRemainingEl.textContent = formatNumberValue(remainingAmount);
        }
    }

    function rebalancePreviousCostCenterRow(row, percentageInput) {
        if (!costCenterDistributionTableBody || !row || !percentageInput) {
            return;
        }
        var currentPercentage = parseDecimalValue(percentageInput.value);
        if (currentPercentage === null || currentPercentage < 0) {
            return;
        }
        var previousRow = row.previousElementSibling;
        if (!previousRow) {
            return;
        }
        var previousInput = previousRow.querySelector('.cc-distribution-percentage');
        if (!previousInput) {
            return;
        }
        var previousPercentage = parseDecimalValue(previousInput.value);
        if (previousPercentage === null) {
            return;
        }
        var previousWasAutoManaged = previousInput.getAttribute('data-auto-managed') === '1';
        if (!previousWasAutoManaged && Math.abs(previousPercentage - 100) > 0.0001) {
            return;
        }
        previousInput.value = formatPercentageDisplayValue(Math.max(0, 100 - currentPercentage));
        previousInput.setAttribute('data-auto-managed', '1');
    }

    function addCostCenterDistributionRow(data) {
        if (!costCenterDistributionRowTemplate || !costCenterDistributionTableBody) {
            return;
        }
        var fragment = costCenterDistributionRowTemplate.content ? costCenterDistributionRowTemplate.content.cloneNode(true) : null;
        if (!fragment) {
            return;
        }
        var row = fragment.querySelector('tr');
        if (!row) {
            return;
        }
        var codeSelect = row.querySelector('.cc-distribution-code');
        var percentageInput = row.querySelector('.cc-distribution-percentage');
        var removeBtn = row.querySelector('.cc-distribution-remove-row');
        var selectedCode = data && data.cost_center ? data.cost_center : '';
        renderCostCenterDistributionOptions(codeSelect, selectedCode);
        if (percentageInput) {
            percentageInput.value = data && data.percentage ? formatPercentageDisplayValue(data.percentage) : '';
            percentageInput.addEventListener('input', function() {
                rebalancePreviousCostCenterRow(row, percentageInput);
                recalculateCostCenterDistributionModal();
            });
            percentageInput.addEventListener('blur', function() {
                percentageInput.removeAttribute('data-auto-managed');
                var previousRow = row.previousElementSibling;
                if (!previousRow) {
                    return;
                }
                var previousInput = previousRow.querySelector('.cc-distribution-percentage');
                if (previousInput) {
                    previousInput.removeAttribute('data-auto-managed');
                }
            });
        }
        if (codeSelect) {
            codeSelect.addEventListener('change', function() {
                if (percentageInput) {
                    var allRows = costCenterDistributionTableBody ? costCenterDistributionTableBody.querySelectorAll('tr') : [];
                    var isFirstRow = allRows.length > 0 && allRows[0] === row;
                    var currentPercentage = parseDecimalValue(percentageInput.value);
                    if (isFirstRow && codeSelect.value && (currentPercentage === null || currentPercentage === 0)) {
                        percentageInput.value = '100';
                    }
                }
                renderCostCenterDistributionOptions(codeSelect, codeSelect.value);
                recalculateCostCenterDistributionModal();
            });
        }
        if (removeBtn) {
            removeBtn.addEventListener('click', function() {
                row.remove();
                recalculateCostCenterDistributionModal();
            });
        }
        costCenterDistributionTableBody.appendChild(row);
        recalculateCostCenterDistributionModal();
    }

    function openCostCenterDistributionModal(rate) {
        if (!costCenterDistributionModal || !currentBtn) {
            return;
        }
        currentCostCenterDistributionRate = rate;
        var info = rateInputs[rate] || null;
        var emitterDisplay = currentBtn.getAttribute('data-emitter-display') || currentBtn.getAttribute('data-emitter') || '';
        var docNumber = currentBtn.getAttribute('data-doc-number') || '';
        var docDate = currentBtn.getAttribute('data-docdate') || '';
        var docType = currentBtn.getAttribute('data-doctype') || '';
        var accountCode = info && info.generalAccount ? String(info.generalAccount.value || '').trim() : '';
        var accountLabel = getRateLabel(rate) || getDefaultRateLabel(rate);
        var amount = info && info.base ? (parseDecimalValue(info.base.value) || 0) : 0;

        setCostCenterDistributionMeta(costCenterDistributionDocumentInfoEl, docNumber);
        setCostCenterDistributionMeta(costCenterDistributionDateInfoEl, docDate);
        setCostCenterDistributionMeta(costCenterDistributionTypeInfoEl, docType);
        setCostCenterDistributionMeta(costCenterDistributionEmitterInfoEl, emitterDisplay);
        setCostCenterDistributionMeta(costCenterDistributionAccountInfoEl, accountCode);
        setCostCenterDistributionMeta(costCenterDistributionAccountLabelInfoEl, accountLabel);
        setCostCenterDistributionMeta(costCenterDistributionAmountInfoEl, formatNumberValue(amount));
        setCostCenterDistributionMeta(costCenterDistributionRateInfoEl, accountLabel);

        if (costCenterDistributionTableBody) {
            costCenterDistributionTableBody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">A atualizar lista de centros de custo do ERP…</td></tr>';
        }
        costCenterDistributionModal.show();

        // Pesquisa sempre a lista atual de centros de custo no ERP ao abrir a
        // modal (ignora a cache local), para que centros criados recentemente
        // no ERP fiquem disponiveis de imediato, sem recarregar a pagina.
        var documentDb = currentBtn.getAttribute('data-acquirer-db') || erpDefaultDatabase || '';
        loadCostCenterCatalogForDocument(documentDb, docDate, { silent: true, forceRefresh: true }).then(function() {
            if (costCenterDistributionTableBody) {
                costCenterDistributionTableBody.innerHTML = '';
            }
            var existingRows = normalizeCostCenterDistributionRows(currentCostCenterBreakdowns[rate] || []);
            if (!existingRows.length) {
                addCostCenterDistributionRow(null);
            } else {
                existingRows.forEach(function(row) {
                    addCostCenterDistributionRow(row);
                });
            }
            recalculateCostCenterDistributionModal();
        });
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
            setCostCenterFieldOptions(info.costCenter, newValue);
        });
        getRateKeys().forEach(function(rate) {
            updateCostCenterFieldMode(rate);
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

    function applySuggestedCostCenters(value) {
        var suggested = normalizeCostCenterValues(value);
        var current = getCostCenterValues();
        var merged = {};
        var changed = false;
        Object.keys(current).forEach(function(rate) {
            var currentValue = String(current[rate] || '').trim();
            var suggestedValue = String(suggested[rate] || '').trim();
            if (currentValue === '' && suggestedValue !== '') {
                merged[rate] = suggestedValue;
                changed = true;
            } else {
                merged[rate] = currentValue;
            }
        });
        if (changed) {
            applyCostCenterValues(merged, { skipEnsure: true });
        }
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
        if (baseAmount !== null && baseAmount !== undefined) {
            var baseNumeric = parseDecimalValue(baseAmount);
            if (baseNumeric !== null && Math.abs(baseNumeric) > 0.00001) {
                return true;
            }
        }
        if (ivaAmount !== null && ivaAmount !== undefined) {
            var ivaNumeric = parseDecimalValue(ivaAmount);
            if (ivaNumeric !== null && Math.abs(ivaNumeric) > 0.00001) {
                return true;
            }
        }
        return false;
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
        var resolvedSourceAmounts = resolveBaseSourceForRate(rate, baseData)
            || resolveBaseSourceForRate(rate, rowData)
            || resolveBaseSourceForRate(rate, defaultData);
        var resolvedBase = resolvedSourceAmounts ? resolvedSourceAmounts.base : getEntryAmount(baseData, 'base');
        if (resolvedBase === null) {
            resolvedBase = getEntryAmount(rowData, 'base');
        }
        if (resolvedBase === null) {
            resolvedBase = getEntryAmount(defaultData, 'base');
        }
        if (info.base) {
            info.base.value = resolvedBase !== null ? String(resolvedBase) : '';
        }
        var resolvedIva = resolvedSourceAmounts && resolvedSourceAmounts.iva !== null
            ? resolvedSourceAmounts.iva
            : getEntryAmount(baseData, 'iva');
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
        var resolvedRubricCode = resolveRateRubricCode(baseData, rowData, defaultData);
        var resolvedAdjustedFlag = resolveRateAdjustedFlag(baseData, rowData, defaultData, resolvedRubricCode);
        if (resolvedRubricCode !== '') {
            currentRateData[rate].erp_rubric_code = resolvedRubricCode;
        } else {
            delete currentRateData[rate].erp_rubric_code;
        }
        if (resolvedAdjustedFlag === '1') {
            currentRateData[rate].vat_amounts_adjusted = '1';
        } else {
            delete currentRateData[rate].vat_amounts_adjusted;
        }
        ivaAccount = sanitizeAccountCodeForRate(ivaAccount, rate);
        generalAccount = sanitizeAccountCodeForRate(generalAccount, rate);
        var normalizedRateValue = normalizeRateToken(rate);
        if (normalizedRateValue !== null && Math.abs(normalizedRateValue) < 0.00001) {
            ivaAccount = '';
        }
        if (baseData.iva_account && ivaAccount === '') {
            baseData.iva_account = '';
        }
        if (baseData.general_account && generalAccount === '') {
            baseData.general_account = '';
        }
        if (info.ivaAccount && info.ivaAccount.value !== ivaAccount) {
            info.ivaAccount.value = ivaAccount;
        }
        if (info.generalAccount && info.generalAccount.value !== generalAccount) {
            info.generalAccount.value = generalAccount;
        }
        if (info.ivaAccount) {
            updatePlanInputTitle(info.ivaAccount);
        }
        if (info.generalAccount) {
            updatePlanInputTitle(info.generalAccount);
        }
        var label = '';
        if (typeof baseData.label === 'string' && baseData.label.trim() !== '') {
            label = normalizeRateLabelForUi(baseData.label);
        } else if (typeof rowData.label === 'string' && rowData.label.trim() !== '') {
            label = normalizeRateLabelForUi(rowData.label);
        } else if (typeof defaultData.label === 'string' && defaultData.label.trim() !== '') {
            label = normalizeRateLabelForUi(defaultData.label);
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
        var ccRequiredFlag = '';
        if (rowData && typeof rowData.cost_center_required !== 'undefined') {
            ccRequiredFlag = String(rowData.cost_center_required || '').trim();
        }
        if (ccRequiredFlag === '' && baseData && typeof baseData.cost_center_required !== 'undefined') {
            ccRequiredFlag = String(baseData.cost_center_required || '').trim();
        }
        if (ccRequiredFlag === '' && defaultData && typeof defaultData.cost_center_required !== 'undefined') {
            ccRequiredFlag = String(defaultData.cost_center_required || '').trim();
        }
        if (ccRequiredFlag === '1' || ccRequiredFlag.toLowerCase() === 'true') {
            currentRateData[rate].cost_center_required = '1';
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
        syncFuelRubricAdjustmentForRate(rate, { formatBase: true });
        if (isBankLoanConversionRate(rate, currentRateData[rate])) {
            clearBankLoanVatForRate(rate);
        }
        updateCostCenterFieldMode(rate);
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
        currentTotalAccount = '';
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

    if (saveClassificationModelSwitch) {
        saveClassificationModelSwitch.addEventListener('change', function() {
            toggleClassificationModelSaveFields();
        });
        toggleClassificationModelSaveFields();
    }

    if (applyClassificationModelBtn) {
        applyClassificationModelBtn.addEventListener('click', function() {
            if (!classificationModelSelect) {
                return;
            }
            applyClassificationModel(classificationModelSelect.value || '');
        });
    }

    if (deleteClassificationModelBtn) {
        deleteClassificationModelBtn.addEventListener('click', function() {
            if (!classificationModelSelect || !currentBtn) {
                return;
            }
            var modelName = String(classificationModelSelect.value || '').trim();
            if (!modelName) {
                showNotice('warning', 'Selecione um modelo antes de eliminar.');
                return;
            }
            if (!window.confirm('Eliminar o modelo "' + modelName + '"?')) {
                return;
            }

            var body = new URLSearchParams({
                action: 'delete_model',
                id: currentBtn.getAttribute('data-id') || '',
                A: currentBtn.getAttribute('data-emitter') || '',
                B: currentBtn.getAttribute('data-acquirer') || '',
                D: currentBtn.getAttribute('data-doctype') || '',
                tenant_key: currentBtn.getAttribute('data-acquirer-db') || erpDefaultDatabase || '',
                model_name: modelName,
                csrf_token: csrfInput ? csrfInput.value : ''
            });

            fetchJson('contabilidade/save-analysis.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(function(res) {
                if (res && res.csrf_token && csrfInput) {
                    csrfInput.value = res.csrf_token;
                }
                classificationModels = normalizeClassificationModelList(res.classification_models);
                if (currentClassificationModelName === modelName) {
                    currentClassificationModelName = '';
                }
                renderClassificationModelOptions(currentClassificationModelName);
                showSuccess('Modelo eliminado.');
            }).catch(function(err) {
                showError((err && err.message) || 'Erro ao eliminar o modelo.');
            });
        });
    }

    if (aiSuggestBtn) {
        aiSuggestBtn.addEventListener('click', function() {
            if (!currentBtn) {
                showNotice('warning', 'Selecione um documento antes de pedir sugestoes.');
                return;
            }
            var useBankLoanFlow = hasBankLoanConversionCandidate();
            var prompt = buildAiSuggestionPrompt();
            var rateLines = buildRateLines();
            if (!prompt) {
                showNotice('warning', 'Nao foi possivel preparar o pedido.');
                return;
            }
            aiSuggestBtn.disabled = true;
            aiSuggestBtn.classList.add('disabled');
            showClassificationTableOverlay();
            if (useBankLoanFlow) {
                fetchBankLoanLines()
                    .then(function(lines) {
                        if (applyInsuranceDetectionFromLines(lines)) {
                            return requestAccountSuggestionsForCurrentRows({
                                session_id: 'ai_suggest_accounts'
                            }).then(function(applied) {
                                if (applied) {
                                    showSuccess('Sugestoes aplicadas (Seguradora).');
                                } else {
                                    showNotice('warning', 'Documento identificado como seguradora, mas nao existem sugestoes para aplicar.');
                                }
                            });
                        }
                        return applyBankLoanConversionFromLines(lines);
                    })
                    .catch(function() {
                        showError('Erro ao contactar o assistente.');
                    })
                    .finally(function() {
                        aiSuggestBtn.disabled = false;
                        aiSuggestBtn.classList.remove('disabled');
                        hideClassificationTableOverlay();
                    });
                return;
            }
            postAssistantRequest({
                action: 'suggest_accounts',
                payload: {
                    acquirer_nif: currentBtn.getAttribute('data-acquirer') || '',
                    acquirer_raw: currentBtn.getAttribute('data-acquirer') || '',
                    emitter: currentBtn.getAttribute('data-emitter-display') || currentBtn.getAttribute('data-emitter') || '',
                    emitter_raw: currentBtn.getAttribute('data-emitter') || '',
                    emitter_nif: currentBtn.getAttribute('data-emitter-nif') || '',
                    db: currentBtn.getAttribute('data-acquirer-db') || '',
                    doc_type: currentBtn.getAttribute('data-doctype') || '',
                    doc_date: currentBtn.getAttribute('data-docdate') || '',
                    doc_number: currentBtn.getAttribute('data-doc-number') || '',
                    emitter_type: emitterTypeSelect ? String(emitterTypeSelect.value || '').trim() : '',
                    document_fields: getCurrentDocumentFieldsPayload(),
                    document_lines: getCurrentDocumentLinesPayload(),
                    has_receipt_companion: currentBtn.getAttribute('data-has-receipt-companion') || '0',
                    rates: rateLines
                },
                message: prompt,
                session_id: 'ai_suggest_accounts'
            }).then(function(res) {
                var message = '';
                if (res) {
                    message = res.message || res.error || res.details || '';
                }
                debugJson('IA resposta', res);
                window.aiExpectedLines = (res && res.expected_lines && typeof res.expected_lines === 'object') ? res.expected_lines : null;
                var parsed = null;
                if (res && typeof res === 'object' && res.rates && typeof res.rates === 'object') {
                    parsed = {
                        rates: res.rates,
                        total_account: extractTotalAccountFromResponse(res)
                    };
                }
                if (!parsed) {
                    parsed = extractJsonFromText(message);
                }
                if (parsed && applyAiSuggestions(parsed, res)) {
                    if (res) {
                        window.aiSuggestionLogId = res.log_id || null;
                        window.aiSuggestedAccounts = parsed.rates || null;
                        window.aiSuggestionSources = [];
                        if (Array.isArray(res.actions)) {
                            res.actions.forEach(function(action) {
                                if (action && action.type === 'suggest_accounts') {
                                    if (action.user_correction_instructions && parseInt(action.user_correction_instructions, 10) > 0) {
                                        window.aiSuggestionSources.push('user_classification_corrections');
                                    }
                                    if (parseInt(action.bank_mode, 10) === 1) {
                                        window.aiSuggestionSources.push('bank_settings_erp');
                                    }
                                    if (action.history && parseInt(action.history, 10) > 0) {
                                        window.aiSuggestionSources.push('mysql_history');
                                    }
                                    if (action.plan_db) {
                                        window.aiSuggestionSources.push('erp_planocontas');
                                    }
                                    if (action.erp_ligacao && parseInt(action.erp_ligacao, 10) > 0) {
                                        window.aiSuggestionSources.push('erp_ligacao_cte_tipo_doc');
                                    }
                                    if (action.rules && parseInt(action.rules, 10) > 0) {
                                        window.aiSuggestionSources.push('mysql_classification_rules');
                                    }
                                    if (action.ai_instruction_rules && parseInt(action.ai_instruction_rules, 10) > 0) {
                                        window.aiSuggestionSources.push('ai_prompt_extra_classification_rules');
                                    }
                                    if (action.instruction_operations && parseInt(action.instruction_operations, 10) > 0) {
                                        window.aiSuggestionSources.push('entity_pair_ai_instructions');
                                    }
                                    if (action.erp_movimentos && parseInt(action.erp_movimentos, 10) > 0) {
                                        window.aiSuggestionSources.push('erp_movimentos');
                                    }
                                }
                            });
                        }
                    }
                    var sourceLabel = 'IA';
                    if (res && Array.isArray(res.actions)) {
                        res.actions.forEach(function(action) {
                            if (!action || action.type !== 'suggest_accounts') {
                                return;
                            }
                            if (parseInt(action.bank_mode, 10) === 1) {
                                sourceLabel = 'Banco: Settings + Ligação ERP';
                                return;
                            }
                            if (action.user_correction_instructions && parseInt(action.user_correction_instructions, 10) > 0 && sourceLabel === 'IA') {
                                sourceLabel = 'IA + Correções memorizadas';
                            }
                            var historyCount = parseInt(action.history, 10);
                            if (!isNaN(historyCount) && historyCount > 0) {
                                sourceLabel = 'Historico';
                            }
                            if (action.plan_db) {
                                sourceLabel = sourceLabel === 'Historico' ? 'Historico + ERP' : 'ERP';
                            }
                            if (action.erp_ligacao && parseInt(action.erp_ligacao, 10) > 0 && sourceLabel.indexOf('Ligacao ERP') === -1) {
                                sourceLabel = sourceLabel === 'IA' ? 'Ligacao ERP' : (sourceLabel + ' + Ligacao ERP');
                            }
                            if (action.rules && parseInt(action.rules, 10) > 0 && sourceLabel.indexOf('Regras') === -1) {
                                sourceLabel = sourceLabel === 'IA' ? 'Regras' : (sourceLabel + ' + Regras');
                            }
                            if (action.ai_instruction_rules && parseInt(action.ai_instruction_rules, 10) > 0 && sourceLabel.indexOf('Instruções') === -1) {
                                sourceLabel = sourceLabel === 'IA' ? 'Instruções AI' : (sourceLabel + ' + Instruções AI');
                            }
                            if (action.instruction_operations && parseInt(action.instruction_operations, 10) > 0 && sourceLabel.indexOf('Instruções') === -1) {
                                sourceLabel = sourceLabel === 'IA' ? 'Instruções AI' : (sourceLabel + ' + Instruções AI');
                            }
                            if (action.erp_movimentos && parseInt(action.erp_movimentos, 10) > 0 && sourceLabel.indexOf('Movimentos') === -1) {
                                sourceLabel = sourceLabel === 'IA' ? 'Movimentos ERP' : (sourceLabel + ' + Movimentos ERP');
                            }
                        });
                    }
                    showSuccess('Sugestoes aplicadas (' + sourceLabel + ').');
                } else if (parsed) {
                    if (message && !/^Sugestoes de contas geradas\.?$/i.test(String(message).trim())) {
                        showNotice('warning', message);
                    } else {
                        showNotice('warning', 'Nao existem sugestoes para aplicar.');
                    }
                } else if (message) {
                    showNotice('warning', message);
                } else {
                    showNotice('warning', 'Nao foi possivel obter sugestoes.');
                }
            }).catch(function() {
                showError('Erro ao contactar o assistente.');
            }).finally(function() {
                aiSuggestBtn.disabled = false;
                aiSuggestBtn.classList.remove('disabled');
                hideClassificationTableOverlay();
            });
        });
    }

    function buildSuggestionExplanationHtml(response) {
        if (!response || typeof response !== 'object' || !response.rates || typeof response.rates !== 'object') {
            return '<p>Sem detalhes de explicação disponíveis.</p>';
        }
        var html = '';
        if (response.summary && typeof response.summary === 'object') {
            var summary = response.summary;
            if (String(summary.bank_mode || '0') === '1') {
                html += '<div class="alert alert-info py-2 mb-3">'
                    + '<strong>' + escapeHtml(String(summary.bank_mode_label || 'Banco: Settings + Ligação ERP')) + '</strong><br>'
                    + 'Neste modo a sugestão prioriza as Instruções adicionais de Settings e a Ligação ERP, ignorando histórico, regras antigas e movimentos ERP.'
                    + '</div>';
            }
            if (String(summary.supplier_not_found || '0') === '1') {
                html += '<div class="alert alert-warning mb-3">'
                    + escapeHtml(String(summary.supplier_lookup_message || 'Fornecedor não encontrado no ERP para a ligação do documento.'))
                    + '</div>';
            }
            html += '<div class="mb-2"><small class="text-muted">'
                + 'Histórico: ' + escapeHtml(String(summary.history_samples || 0))
                + ' | Regras: ' + escapeHtml(String(summary.rule_samples || 0))
                + ' | Instruções BO: ' + escapeHtml(String(summary.backoffice_instruction_rules || 0))
                + ' | Regras especiais: ' + escapeHtml(String(summary.backoffice_instruction_operations || 0))
                + ' | Ligação ERP: ' + escapeHtml(String(summary.erp_ligacao_rows || 0))
                + ' | Movimentos ERP: ' + escapeHtml(String(summary.erp_movement_rows || 0))
                + ' | Plano ERP: ' + escapeHtml(String(summary.erp_plan_rows || 0))
                + '</small></div>';
            if (Array.isArray(summary.backoffice_instruction_source_order) && summary.backoffice_instruction_source_order.length) {
                html += '<div class="mb-2"><small class="text-muted">Ordem das instruções: '
                    + escapeHtml(summary.backoffice_instruction_source_order.join(' → '))
                    + '</small></div>';
            }
            if (String(summary.backoffice_instruction_source || '0') === '1') {
                html += '<div class="alert alert-info py-2 mb-3">'
                    + 'A sugestão aplicada inclui regras lidas nas Instruções adicionais do backoffice.'
                    + '</div>';
            }
            if (parseInt(summary.history_samples || 0, 10) > 0 || parseInt(summary.rule_samples || 0, 10) > 0) {
                html += '<div class="mb-3">'
                    + '<button type="button" class="btn btn-sm btn-outline-danger" id="clearWrongSuggestionHistoryBtn">'
                    + '<i class="fa fa-eraser"></i> Limpar histórico/regras errados deste contexto'
                    + '</button>'
                    + '<small class="text-muted d-block mt-2">Remove apenas sugestões históricas e regras do contexto atual com taxa textual inválida, como "Imposto do Selo".</small>'
                    + '</div>';
            }
        }

        if (Array.isArray(response.instruction_operations) && response.instruction_operations.length) {
            html += '<div class="alert alert-info py-2 mb-3">'
                + '<div><strong>Regras especiais consideradas</strong></div>'
                + '<ul class="mb-0 mt-2">';
            response.instruction_operations.forEach(function(note) {
                html += '<li>' + escapeHtml(String(note || '')) + '</li>';
            });
            html += '</ul></div>';
        }

        var ratesPayload = response.rates || {};
        var orderedKeys = [];
        var seen = {};
        buildRateExplanationPayload().forEach(function(line) {
            var key = line && line.key ? String(line.key) : '';
            if (!key || !Object.prototype.hasOwnProperty.call(ratesPayload, key) || seen[key]) {
                return;
            }
            orderedKeys.push(key);
            seen[key] = true;
        });
        Object.keys(ratesPayload).forEach(function(rateKey) {
            if (!seen[rateKey]) {
                orderedKeys.push(rateKey);
                seen[rateKey] = true;
            }
        });

        orderedKeys.forEach(function(rateKey) {
            var info = ratesPayload[rateKey] || {};
            var label = String(info.label || rateKey).trim();
            var suggested = info.suggested && typeof info.suggested === 'object' ? info.suggested : {};
            var reasons = Array.isArray(info.reasons) ? info.reasons : [];
            html += '<div class="mb-3 p-2 border rounded">'
                + '<div><strong>Taxa ' + escapeHtml(label) + '</strong></div>'
                + '<div><small>Conta geral: ' + escapeHtml(String(suggested.general_account || '-')) + ' | Conta IVA: ' + escapeHtml(String(suggested.iva_account || '-')) + '</small></div>';
            if (reasons.length > 0) {
                html += '<ul class="mb-0 mt-2">';
                reasons.forEach(function(reason) {
                    html += '<li>' + escapeHtml(String(reason || '')) + '</li>';
                });
                html += '</ul>';
            }
            html += '</div>';
        });

        if (response.total_account && typeof response.total_account === 'object') {
            var totalInfo = response.total_account;
            var totalReasons = Array.isArray(totalInfo.reasons) ? totalInfo.reasons : [];
            html += '<div class="mb-3 p-2 border rounded bg-light">'
                + '<div><strong>Valor Total</strong></div>'
                + '<div><small>Conta sugerida: ' + escapeHtml(String(totalInfo.suggested || '-')) + '</small></div>';
            if (totalReasons.length > 0) {
                html += '<ul class="mb-0 mt-2">';
                totalReasons.forEach(function(reason) {
                    html += '<li>' + escapeHtml(String(reason || '')) + '</li>';
                });
                html += '</ul>';
            }
            html += '</div>';
        }

        return html || '<p>Sem detalhes de explicação disponíveis.</p>';
    }

    var suggestionExplainModalEl = null;
    var suggestionExplainModalBody = null;
    var suggestionExplainModal = null;

    function ensureSuggestionExplainModal() {
        if (suggestionExplainModalEl) {
            return true;
        }
        if (!document.body || !window.bootstrap || typeof window.bootstrap.Modal !== 'function') {
            return false;
        }

        suggestionExplainModalEl = document.createElement('div');
        suggestionExplainModalEl.className = 'modal fade';
        suggestionExplainModalEl.id = 'suggestionExplainModal';
        suggestionExplainModalEl.tabIndex = -1;
        suggestionExplainModalEl.setAttribute('aria-hidden', 'true');
        suggestionExplainModalEl.innerHTML = ''
            + '<div class="modal-dialog modal-xl">'
            + '  <div class="modal-content">'
            + '    <div class="modal-header">'
            + '      <h5 class="modal-title">Explicação da sugestão</h5>'
            + '      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>'
            + '    </div>'
            + '    <div class="modal-body"></div>'
            + '    <div class="modal-footer">'
            + '      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';

        document.body.appendChild(suggestionExplainModalEl);
        suggestionExplainModalBody = suggestionExplainModalEl.querySelector('.modal-body');
        suggestionExplainModal = new window.bootstrap.Modal(suggestionExplainModalEl);
        return !!suggestionExplainModalBody;
    }

    function showSuggestionExplanationDialog(html) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            window.Swal.fire({
                title: 'Explicação da sugestão',
                html: html,
                width: 900,
                confirmButtonText: 'Fechar',
                didOpen: function() {
                    attachClearWrongSuggestionHistoryHandler(document);
                }
            });
            return;
        }

        if (ensureSuggestionExplainModal()) {
            suggestionExplainModalBody.innerHTML = html;
            attachClearWrongSuggestionHistoryHandler(suggestionExplainModalBody);
            suggestionExplainModal.show();
            return;
        }

        showNotice('info', 'Explicação obtida, mas sem componente visual para a mostrar.');
    }

    function clearWrongSuggestionHistoryFromExplanation(buttonEl) {
        if (!currentBtn) {
            showNotice('warning', 'Selecione um documento antes de limpar histórico.');
            return;
        }

        var rates = buildRateExplanationPayload();
        if (!rates.length) {
            showNotice('warning', 'Não existem taxas para limpar neste contexto.');
            return;
        }

        if (!window.confirm('Limpar o histórico e as regras erradas deste contexto? Esta ação remove sugestões antigas com taxa textual inválida.')) {
            return;
        }

        if (buttonEl) {
            buttonEl.disabled = true;
            buttonEl.classList.add('disabled');
        }

        fetchJson('contabilidade/classificacao-importacao/suggestion-explanation', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfInput ? csrfInput.value : '',
                mode: 'clear_wrong_history',
                payload: {
                    acquirer_nif: currentBtn.getAttribute('data-acquirer') || '',
                    acquirer_raw: currentBtn.getAttribute('data-acquirer') || '',
                    emitter: currentBtn.getAttribute('data-emitter-display') || currentBtn.getAttribute('data-emitter') || '',
                    emitter_nif: currentBtn.getAttribute('data-emitter-nif') || '',
                    db: currentBtn.getAttribute('data-acquirer-db') || '',
                    doc_type: currentBtn.getAttribute('data-doctype') || '',
                    doc_date: currentBtn.getAttribute('data-docdate') || '',
                    emitter_type: emitterTypeSelect ? String(emitterTypeSelect.value || '').trim() : '',
                    document_fields: getCurrentDocumentFieldsPayload(),
                    has_receipt_companion: currentBtn.getAttribute('data-has-receipt-companion') || '0',
                    total_account: totalAccountInput ? String(totalAccountInput.value || '').trim() : '',
                    suggestion_sources: window.aiSuggestionSources || [],
                    rates: rates
                }
            })
        }).then(function(res) {
            if (res && res.csrf_token && csrfInput) {
                csrfInput.value = res.csrf_token;
            }
            if (!res || !res.success) {
                showError((res && res.error) || 'Não foi possível limpar o histórico/regra errados.');
                return;
            }
            showSuccess((res && res.message) || 'Histórico/regra errados limpos. Peça nova sugestão.');
            if (window.Swal && typeof window.Swal.close === 'function') {
                window.Swal.close();
            }
            if (suggestionExplainModal && typeof suggestionExplainModal.hide === 'function') {
                suggestionExplainModal.hide();
            }
        }).catch(function(err) {
            showError((err && err.message) || 'Erro ao limpar histórico/regra errados.');
        }).finally(function() {
            if (buttonEl) {
                buttonEl.disabled = false;
                buttonEl.classList.remove('disabled');
            }
        });
    }

    function attachClearWrongSuggestionHistoryHandler(container) {
        if (!container || typeof container.querySelector !== 'function') {
            return;
        }
        var button = container.querySelector('#clearWrongSuggestionHistoryBtn');
        if (!button || button.__clearWrongSuggestionHistoryBound) {
            return;
        }
        button.__clearWrongSuggestionHistoryBound = true;
        button.addEventListener('click', function() {
            clearWrongSuggestionHistoryFromExplanation(button);
        });
    }

    if (aiSuggestionExplainBtn) {
        aiSuggestionExplainBtn.addEventListener('click', function() {
            if (!currentBtn) {
                showNotice('warning', 'Selecione um documento antes de pedir explicação.');
                return;
            }

            var rates = buildRateExplanationPayload();
            if (!rates.length) {
                showNotice('warning', 'Não existem taxas para explicar.');
                return;
            }

            aiSuggestionExplainBtn.disabled = true;
            aiSuggestionExplainBtn.classList.add('disabled');
            fetchJson('contabilidade/classificacao-importacao/suggestion-explanation', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfInput ? csrfInput.value : '',
                    payload: {
                        acquirer_nif: currentBtn.getAttribute('data-acquirer') || '',
                        acquirer_raw: currentBtn.getAttribute('data-acquirer') || '',
                        emitter: currentBtn.getAttribute('data-emitter-display') || currentBtn.getAttribute('data-emitter') || '',
                        emitter_nif: currentBtn.getAttribute('data-emitter-nif') || '',
                        db: currentBtn.getAttribute('data-acquirer-db') || '',
                        doc_type: currentBtn.getAttribute('data-doctype') || '',
                        doc_date: currentBtn.getAttribute('data-docdate') || '',
                        emitter_type: emitterTypeSelect ? String(emitterTypeSelect.value || '').trim() : '',
                        document_fields: getCurrentDocumentFieldsPayload(),
                        has_receipt_companion: currentBtn.getAttribute('data-has-receipt-companion') || '0',
                        total_account: totalAccountInput ? String(totalAccountInput.value || '').trim() : '',
                        suggestion_sources: window.aiSuggestionSources || [],
                        rates: rates
                    }
                })
            }).then(function(res) {
                if (res && res.csrf_token && csrfInput) {
                    csrfInput.value = res.csrf_token;
                }
                if (!res || !res.success) {
                    showError((res && res.error) || 'Não foi possível obter a explicação da sugestão.');
                    return;
                }
                showSuggestionExplanationDialog(buildSuggestionExplanationHtml(res));
            }).catch(function(err) {
                showError((err && err.message) || 'Erro ao obter explicação da sugestão.');
            }).finally(function() {
                aiSuggestionExplainBtn.disabled = false;
                aiSuggestionExplainBtn.classList.remove('disabled');
            });
        });
    }

    if (aiSuggestCorrectionBtn) {
        aiSuggestCorrectionBtn.addEventListener('click', function() {
            if (!currentBtn) {
                showNotice('warning', 'Selecione um documento antes de sugerir uma correção.');
                return;
            }
            openClassificationCorrectionDialog();
        });
    }

    if (entityPairAiInstructionsBtn) {
        entityPairAiInstructionsBtn.addEventListener('click', function() {
            if (!currentCanManageEntityPairAiInstructions) {
                showNotice('warning', 'Sem permissões para editar Instruções IA.');
                return;
            }
            openEntityPairAiInstructionsDialog();
        });
    }

    if (classifyModalEl) {
        classifyModalEl.addEventListener('shown.bs.modal', function() {
            refreshCostCenterFieldModes();
            var keys = getRateKeys();
            if (keys.length > 0) {
                focusRateInput(rateInputs[keys[0]]);
            }
        });
        classifyModalEl.addEventListener('hidden.bs.modal', function() {
            if (documentFieldClassificationRefreshTimer) {
                window.clearTimeout(documentFieldClassificationRefreshTimer);
                documentFieldClassificationRefreshTimer = null;
            }
            documentFieldClassificationRefreshRequestId += 1;
            if (modalTitleEl) {
                modalTitleEl.textContent = defaultModalTitle || 'Classificar';
            }
            updateClassifyModalCompanyBadge('');
            updateDocumentFieldsPanelVisibility(true);
            currentDocumentFieldValues = {};
            renderDocumentFieldInputs();
            resetClassifyDocumentPreview();
            invalidateReadyImportIdsCache();
            table.ajax.reload(null, false);
            currentBtn = null;
            updateCurrentPlanContextFromButton(null);
            currentOriginalRatesKey = null;
            currentTotalAccount = '';
            currentEntityPairAiInstructions = '';
            currentCanManageEntityPairAiInstructions = false;
            currentCostCenterDistributionRate = '';
            currentBankLoanConversionActive = false;
            if (totalAccountInput) {
                totalAccountInput.value = '';
            }
        });
    }

    if (costCenterDistributionAddRowBtn) {
        costCenterDistributionAddRowBtn.addEventListener('click', function() {
            addCostCenterDistributionRow(null);
        });
    }

    if (costCenterDistributionSaveBtn) {
        costCenterDistributionSaveBtn.addEventListener('click', function() {
            var rate = currentCostCenterDistributionRate;
            if (!rate || !costCenterDistributionTableBody) {
                return;
            }
            var rows = [];
            var totalPercentage = 0;
            var invalid = false;
            costCenterDistributionTableBody.querySelectorAll('tr').forEach(function(row) {
                var codeSelect = row.querySelector('.cc-distribution-code');
                var percentageInput = row.querySelector('.cc-distribution-percentage');
                var code = codeSelect ? String(codeSelect.value || '').trim() : '';
                var percentage = percentageInput ? parseDecimalValue(percentageInput.value) : null;
                if (!code && (percentage === null || percentage === 0)) {
                    return;
                }
                if (!code || percentage === null || percentage <= 0) {
                    invalid = true;
                    return;
                }
                totalPercentage += percentage;
                rows.push({
                    cost_center: code,
                    percentage: formatDecimalValue(percentage),
                    value: ''
                });
            });
            if (invalid || !rows.length) {
                showError('Preencha centros de custo e percentagens válidas.');
                return;
            }
            if (Math.abs(totalPercentage - 100) > 0.01) {
                showError('A percentagem atribuída tem de totalizar 100%.');
                return;
            }
            var info = rateInputs[rate] || null;
            var totalAmount = info && info.base ? (parseDecimalValue(info.base.value) || 0) : 0;
            rows = rows.map(function(row) {
                var percentage = parseDecimalValue(row.percentage) || 0;
                return {
                    cost_center: row.cost_center,
                    percentage: formatDecimalValue(percentage),
                    value: formatDecimalValue(totalAmount * (percentage / 100))
                };
            });
            currentCostCenterBreakdowns[rate] = rows;
            currentCostCenters[rate] = rows[0] ? rows[0].cost_center : '';
            if (rateInputs[rate] && rateInputs[rate].costCenter) {
                setCostCenterFieldOptions(rateInputs[rate].costCenter, currentCostCenters[rate]);
            }
            updateCostCenterFieldMode(rate);
            if (costCenterDistributionModal) {
                costCenterDistributionModal.hide();
            }
        });
    }

    var currentBtn = null;
    var linesModalEl = document.getElementById('linesModal');
    var linesModal = linesModalEl ? new bootstrap.Modal(linesModalEl) : null;
    var linesContainer = document.getElementById('linesContainer');
    var confirmLinesBtn = document.getElementById('confirmLinesBtn');
    var currentLinesId = null;
    var currentLinesEmitter = null;

    if (linesModalEl) {
        linesModalEl.addEventListener('hidden.bs.modal', function() {
            invalidateReadyImportIdsCache();
            table.ajax.reload(null, false);
        });
    }

    $('#classify-table').on('click', '.classify-row', function() {
        var btn = this;
        currentBtn = btn;
        updateCurrentPlanContextFromButton(btn);
        ensurePlanContextLoaded();
        currentOriginalRatesKey = buildOriginalRatesStorageKey(btn);
        var emitterRaw = btn.getAttribute('data-emitter') || '';
        var emitterDisplay = btn.getAttribute('data-emitter-display') || '';
        var emitterNif = btn.getAttribute('data-emitter-nif') || '';
        var acquirer = btn.getAttribute('data-acquirer') || '';
        var docType = btn.getAttribute('data-doctype') || '';
        var docDate = btn.getAttribute('data-docdate') || '';
        var documentDb = btn.getAttribute('data-acquirer-db') || erpDefaultDatabase || '';
        var docNumber = btn.getAttribute('data-doc-number') || '';
        var documentCompanyCode = extractCompanyCodeFromDatabase(documentDb);
        var showDocumentFields = String(btn.getAttribute('data-show-document-fields') || '').trim() === '1';

        if (modalTitleEl) {
            var baseTitle = defaultModalTitle || 'Classificar';
            var docPart = docNumber.trim();
            var emitterPart = emitterDisplay.trim();
            var emitterRawPart = emitterRaw.trim();
            var emitterNifPart = emitterNif.trim();
            if (!emitterPart && emitterRawPart) {
                emitterPart = emitterRawPart;
            }
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
        updateClassifyModalCompanyBadge(documentCompanyCode);
        updateDocumentFieldsPanelVisibility(showDocumentFields);

        resetRateRows();
        storedRowRates = {};
        storedDefaultRates = {};
        currentCostCenters = {};
        currentCostCenterBreakdowns = {};
        removedRates = {};
        currentTotalAccount = (btn.getAttribute('data-total-account') || '').trim();
        currentIgnoreDetectedRates = false;
        currentBankLoanConversionActive = false;
        currentClassificationModelName = '';
        classificationModels = [];
        currentEntityPairAiInstructions = '';
        currentCanManageEntityPairAiInstructions = false;
        currentDocumentFieldValues = normalizeDocumentFieldMap(parseJsonAttribute(btn, 'data-qr-fields') || {});
        currentBankLoanDocumentLines = [];
        renderDocumentFieldInputs();
        updateButtonDocumentFields(btn, currentDocumentFieldValues);
        var buttonRatesPayload = parseJsonAttribute(btn, 'data-rates') || {};
        currentBankLoanConversionActive = !!(
            buttonRatesPayload
            && hasBankLoanConversionRates(buttonRatesPayload)
        );
        if (totalAccountInput) {
            totalAccountInput.value = currentTotalAccount;
            updatePlanInputTitle(totalAccountInput);
        }
        if (classificationModelSelect) {
            renderClassificationModelOptions('');
        }
        if (saveClassificationModelSwitch) {
            saveClassificationModelSwitch.checked = false;
            toggleClassificationModelSaveFields();
        }
        if (emitterTypeSelect) {
            emitterTypeSelect.value = 'normal';
        }

        currentRateData = buttonRatesPayload;
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
            if (!rateInputs[rate] && (rateHasBaseValues(rate) || rateHasStoredAccounts(rate))) {
                addVatRowForRate(rate);
            }
        });
        ensureRowsForRates(currentRateData, { allowCreate: true });
        rebuildRequirementsForCurrentButton();
        getRateKeys().forEach(function(rate) {
            populateRateRow(rate);
        });
        rebuildRequirementsForCurrentButton();
        refreshCostCenterFieldModes();
        captureOriginalRateValues({ initialize: true, refresh: false, allowCreate: false });
        ensurePlanContextLoaded().then(function() {
            getRateKeys().forEach(function(rate) {
                populateRateRow(rate);
            });
        }).catch(function() {
            return null;
        });

        var btnCostCenters = parseJsonAttribute(btn, 'data-cost-centers');
        var btnCostCenterBreakdowns = parseJsonAttribute(btn, 'data-cost-center-breakdowns') || {};
        if (!btnCostCenters && btn.hasAttribute('data-cost-center')) {
            btnCostCenters = btn.getAttribute('data-cost-center') || '';
        }
        setClassifyDocumentPreview(btn.getAttribute('data-file-url') || '');
        applyCostCenterValues(btnCostCenters, { skipEnsure: true });
        applyCostCenterBreakdownValues(btnCostCenterBreakdowns);
        refreshCostCenterFieldModes();
        loadCostCenterCatalogForDocument(documentDb, docDate, { silent: true }).then(function() {
            applyCostCenterValues(currentCostCenters, { skipEnsure: true });
            applyCostCenterBreakdownValues(currentCostCenterBreakdowns);
            refreshCostCenterFieldModes();
        });

        var params = new URLSearchParams({
            action: 'get',
            id: btn.getAttribute('data-id') || '',
            A: emitterRaw,
            B: acquirer,
            D: docType,
            tenant_key: documentDb,
            csrf_token: csrfInput.value
        });
        fetchJson('contabilidade/save-analysis.php?' + params.toString())
            .then(function(res) {
                debugJson('resposta save-analysis', res);
                debugJson('dados de taxas após merge', currentRateData);
                applyClassificationContextResponse(res, { focusRestored: true });
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

            currentDocumentFieldValues = collectDocumentFieldInputs();

            getRateKeys().forEach(function(rate) {
                recalculateVatForRate(rate, { formatBase: true });
                if (isBankLoanConversionRate(rate, ensureRateData(rate))) {
                    clearBankLoanVatForRate(rate);
                }
                updateRowDirtyState(rate);
            });

            var totalAccountValue = totalAccountInput ? totalAccountInput.value.trim() : '';
            var currentRequirements = parseJsonAttribute(currentBtn, 'data-requirements') || {};
            var ratesPayload = {};
            getRateKeys().forEach(function(rate) {
                var info = rateInputs[rate];
                var baseValue = info.base ? String(info.base.value || '').trim() : '';
                var ivaValue = info.iva ? String(info.iva.value || '').trim() : '';
                var rateData = ensureRateData(rate);
                var costCenterRequired = false;
                if (currentRequirements[rate] && currentRequirements[rate].cost_center) {
                    costCenterRequired = true;
                }
                if (rateData.cost_center_required === '1') {
                    costCenterRequired = true;
                }
                ratesPayload[rate] = {
                    iva_account: info.ivaAccount ? info.ivaAccount.value.trim() : '',
                    general_account: info.generalAccount ? info.generalAccount.value.trim() : '',
                    label: getRateLabel(rate),
                    base: baseValue,
                    iva: ivaValue,
                    base_value: baseValue,
                    iva_value: ivaValue,
                    erp_rubric_code: String(rateData.erp_rubric_code || '').trim(),
                    vat_amounts_adjusted: isAdjustedVatRateEntry(rateData) ? '1' : '0',
                    bank_loan_conversion: String(rateData.bank_loan_conversion || '').trim() === '1' ? '1' : '0',
                    base_source_field: (function() {
                        var rateDataSource = ensureRateData(rate);
                        var existingSource = String(rateDataSource.base_source_field || '').trim();
                        if (existingSource) {
                            return existingSource;
                        }
                        return inferBaseSourceFieldForRate(rate, baseValue);
                    })(),
                    cost_center_required: costCenterRequired ? '1' : '0'
                };
            });

            if (currentBankLoanConversionActive) {
                Object.keys(ratesPayload).forEach(function(rate) {
                    if (rate !== '0' && rate !== 'bank_loan_commission' && rate !== 'bank_loan_capital') {
                        delete ratesPayload[rate];
                        removedRates[rate] = true;
                        if (rateInputs[rate]) {
                            removeRateRow(rate);
                        }
                    }
                });
                ['0', 'bank_loan_commission', 'bank_loan_capital'].forEach(function(rate) {
                    if (!ratesPayload[rate]) {
                        return;
                    }
                    ratesPayload[rate].iva = '';
                    ratesPayload[rate].iva_value = '';
                    ratesPayload[rate].iva_account = '';
                    ratesPayload[rate].bank_loan_conversion = '1';
                    if (Object.prototype.hasOwnProperty.call(removedRates, rate)) {
                        delete removedRates[rate];
                    }
                });
            }

            var removedPayload = Object.keys(removedRates).filter(function(rate) {
                return removedRates[rate];
            });

            var costCentersPayload = getCostCenterValues();
            var costCenterBreakdownsPayload = getCostCenterBreakdownValues();
            var missingCostCenterRates = [];
            Object.keys(currentRequirements).forEach(function(rate) {
                var req = currentRequirements[rate] || {};
                if (!req.cost_center) {
                    return;
                }
                var value = String(costCentersPayload[rate] || '').trim();
                var breakdownRows = Array.isArray(costCenterBreakdownsPayload[rate]) ? costCenterBreakdownsPayload[rate] : [];
                if (value === '' && !breakdownRows.length) {
                    missingCostCenterRates.push(String(rate));
                }
            });
            if (missingCostCenterRates.length) {
                showError('Preencha os centros de custo obrigatórios antes de guardar.');
                return;
            }
            var saveModelName = '';
            if (saveClassificationModelSwitch && saveClassificationModelSwitch.checked) {
                saveModelName = classificationModelNameInput ? classificationModelNameInput.value.trim() : '';
                if (!saveModelName) {
                    showError('Indique o nome do modelo antes de guardar.');
                    return;
                }
            }
            validateAccountsAgainstCurrentPlan(ratesPayload, totalAccountValue)
            .then(function(validation) {
                if (!validation || validation.ok === false) {
                    if (validation && validation.error) {
                        throw new Error('Não foi possível validar as contas no plano ERP: ' + validation.error);
                    }
                    var invalidLabels = (validation && Array.isArray(validation.invalid) ? validation.invalid : []).map(function(item) {
                        return item.label;
                    });
                    throw new Error('Existem contas que não pertencem ao plano de contas da base ERP atual: ' + invalidLabels.join('; '));
                }

                var body = new URLSearchParams({
                    id: currentBtn.getAttribute('data-id') || '',
                    A: currentBtn.getAttribute('data-emitter') || '',
                    B: currentBtn.getAttribute('data-acquirer') || '',
                    D: currentBtn.getAttribute('data-doctype') || '',
                    tenant_key: currentBtn.getAttribute('data-acquirer-db') || erpDefaultDatabase || '',
                    rates: JSON.stringify(ratesPayload),
                    removed_rates: JSON.stringify(removedPayload),
                    original_rates: JSON.stringify(originalRateValues),
                    cost_centers: JSON.stringify(costCentersPayload),
                    cost_center_breakdowns: JSON.stringify(costCenterBreakdownsPayload),
                    total_account: totalAccountValue,
                    ignore_detected_rates: (currentIgnoreDetectedRates || saveModelName !== '' ? '1' : '0'),
                    classification_model_name: currentClassificationModelName,
                    save_model_name: saveModelName,
                    emitter_type: emitterTypeSelect ? (emitterTypeSelect.value || 'normal') : 'normal',
                    manual_document_fields: (toggleDocumentFieldsSwitch && toggleDocumentFieldsSwitch.checked) ? '1' : '0',
                    document_fields: JSON.stringify(currentDocumentFieldValues),
                    csrf_token: csrfInput.value
                });
                return fetchJson('contabilidade/save-analysis.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
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
                if (res.cost_center_breakdowns && typeof res.cost_center_breakdowns === 'object') {
                    applyCostCenterBreakdownValues(res.cost_center_breakdowns);
                } else {
                    applyCostCenterBreakdownValues(costCenterBreakdownsPayload);
                }
                currentCostCenters = getCostCenterValues();
                var responseTotalAccount = '';
                if (typeof res.row_total_account === 'string') {
                    responseTotalAccount = res.row_total_account;
                } else if (typeof res.total_account === 'string') {
                    responseTotalAccount = res.total_account;
                }
                responseTotalAccount = (responseTotalAccount || totalAccountValue || '').trim();
                currentTotalAccount = responseTotalAccount;
                if (Object.prototype.hasOwnProperty.call(res, 'has_receipt_companion') && currentBtn) {
                    currentBtn.setAttribute('data-has-receipt-companion', String(res.has_receipt_companion || '').trim() === '1' ? '1' : '0');
                }
                if (totalAccountInput) {
                    totalAccountInput.value = currentTotalAccount;
                    updatePlanInputTitle(totalAccountInput);
                }
                if (Object.prototype.hasOwnProperty.call(res, 'manual_review_required')) {
                    var manualReviewValue = String(res.manual_review_required || '').trim();
                    currentBtn.setAttribute('data-manual-review', manualReviewValue === '1' ? '1' : '0');
                }
                currentIgnoreDetectedRates = String(res.ignore_detected_rates || '').trim() === '1';
                currentClassificationModelName = String(res.classification_model_name || '').trim();
                classificationModels = normalizeClassificationModelList(res.classification_models);
                if (emitterTypeSelect && Object.prototype.hasOwnProperty.call(res, 'emitter_type')) {
                    emitterTypeSelect.value = String(res.emitter_type || '').trim() || 'normal';
                }
                if (res.document_fields && typeof res.document_fields === 'object') {
                    currentDocumentFieldValues = normalizeDocumentFieldMap(res.document_fields);
                    renderDocumentFieldInputs();
                    updateButtonDocumentFields(currentBtn, currentDocumentFieldValues);
                }
                if (Object.prototype.hasOwnProperty.call(res, 'show_document_fields') && currentBtn) {
                    var responseShowFields = String(res.show_document_fields || '').trim() === '1';
                    currentBtn.setAttribute('data-show-document-fields', responseShowFields ? '1' : '0');
                    updateDocumentFieldsPanelVisibility(responseShowFields);
                }
                renderClassificationModelOptions(currentClassificationModelName);
                if (saveClassificationModelSwitch) {
                    saveClassificationModelSwitch.checked = false;
                    toggleClassificationModelSaveFields();
                }
                if (res.requirements && typeof res.requirements === 'object') {
                    currentBtn.setAttribute('data-requirements', JSON.stringify(res.requirements));
                    getRateKeys().forEach(function(rate) {
                        updateCostCenterFieldMode(rate);
                    });
                }

                if (res.row_rates && typeof res.row_rates === 'object') {
                    if (currentBankLoanConversionActive) {
                        Object.keys(res.row_rates).forEach(function(rate) {
                            if (rate !== '0' && rate !== 'bank_loan_commission' && rate !== 'bank_loan_capital') {
                                delete res.row_rates[rate];
                            }
                        });
                        ['0', 'bank_loan_commission', 'bank_loan_capital'].forEach(function(rate) {
                            if (!res.row_rates[rate]) {
                                return;
                            }
                            res.row_rates[rate].iva = '';
                            res.row_rates[rate].iva_value = '';
                            res.row_rates[rate].iva_account = '';
                            res.row_rates[rate].bank_loan_conversion = '1';
                        });
                        Object.keys(rateInputs).forEach(function(rate) {
                            if (rate === '0' || rate === 'bank_loan_commission' || rate === 'bank_loan_capital') {
                                return;
                            }
                            removeRateRow(rate);
                        });
                    }
                    storedRowRates = res.row_rates;
                    Object.keys(res.row_rates).forEach(function(rate) {
                        if (!currentRateData[rate]) {
                            currentRateData[rate] = {};
                        }
                        var rowData = res.row_rates[rate] || {};
                        currentRateData[rate].iva_account = rowData.iva_account || '';
                        currentRateData[rate].general_account = rowData.general_account || '';
                        if (rowData.label) {
                            currentRateData[rate].label = normalizeRateLabelForUi(rowData.label);
                        }
                        copyRateHiddenMetadata(currentRateData[rate], rowData);
                        if (rowData.base_source_field) {
                            currentRateData[rate].base_source_field = rowData.base_source_field;
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
                            if (isBankLoanConversionRate(rate, currentRateData[rate])) {
                                clearBankLoanVatForRate(rate);
                            }
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
                        currentRateData[rate].erp_rubric_code = ratesPayload[rate] ? ratesPayload[rate].erp_rubric_code : '';
                        currentRateData[rate].vat_amounts_adjusted = ratesPayload[rate] ? ratesPayload[rate].vat_amounts_adjusted : '0';
                        currentRateData[rate].base_source_field = ratesPayload[rate] ? ratesPayload[rate].base_source_field : '';
                        currentRateData[rate].label = getRateLabel(rate);
                    });
                }

                if (window.aiSuggestedAccounts && window.aiSuggestionLogId) {
                    var corrections = {};
                    var accepted = true;
                    Object.keys(window.aiSuggestedAccounts).forEach(function(rateKey) {
                        var suggested = window.aiSuggestedAccounts[rateKey] || {};
                        var current = currentRateData[rateKey] || {};
                        var sugIva = (suggested.iva_account || '').trim();
                        var sugGen = (suggested.general_account || '').trim();
                        var curIva = (current.iva_account || '').trim();
                        var curGen = (current.general_account || '').trim();
                        if (sugIva && sugIva !== curIva) {
                            corrections[rateKey] = corrections[rateKey] || {};
                            corrections[rateKey].iva_account = { suggested: sugIva, final: curIva };
                            accepted = false;
                        }
                        if (sugGen && sugGen !== curGen) {
                            corrections[rateKey] = corrections[rateKey] || {};
                            corrections[rateKey].general_account = { suggested: sugGen, final: curGen };
                            accepted = false;
                        }
                    });
                    postAssistantRequest({
                        action: 'log_feedback',
                        log_id: window.aiSuggestionLogId,
                        accepted: accepted ? 1 : 0,
                        corrected_after: accepted ? 0 : 1,
                        corrected_accounts: corrections,
                        suggested_accounts: window.aiSuggestedAccounts,
                        sources: window.aiSuggestionSources || [],
                        category: 'suggest_accounts',
                        session_id: 'ai_suggest_accounts'
                    }).catch(function() {});
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
                currentBtn.setAttribute('data-cost-center-breakdowns', JSON.stringify(getCostCenterBreakdownValues()));
                currentBtn.setAttribute('data-total-account', currentTotalAccount);
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
                invalidateReadyImportIdsCache();
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
        var emitterRaw = btn.getAttribute('data-emitter') || '';
        var emitterDisplay = btn.getAttribute('data-emitter-display') || emitterRaw || '';
        var emitterNif = btn.getAttribute('data-emitter-nif') || '';
        var acquirerValue = btn.getAttribute('data-acquirer') || '';
        var docTypeValue = btn.getAttribute('data-doctype') || '';
        var docDateValue = btn.getAttribute('data-docdate') || '';
        var documentDbValue = btn.getAttribute('data-acquirer-db') || erpDefaultDatabase || '';
        currentLinesEmitter = {
            raw: emitterRaw,
            display: emitterDisplay,
            identifier: emitterNif || emitterRaw,
            acquirer: acquirerValue,
            docType: docTypeValue
        };
        currentLinesId = id;
        linesContainer.innerHTML = '<div class="d-flex justify-content-center my-3"><div class="spinner-border" role="status"><span class="visually-hidden">A carregar...</span></div></div>';
        linesModal.show();
        var costCenterCatalogPromise = loadCostCenterCatalogForDocument(documentDbValue, docDateValue, { silent: true });
        var params = new URLSearchParams({
            action: 'lines',
            id: id
        });
        fetchJson('contabilidade/save-analysis.php?' + params.toString())
            .then(function(res) {
                if (!res) {
                    throw new Error('Resposta inválida');
                }
                if (res.csrf_token && csrfInput) {
                    csrfInput.value = res.csrf_token;
                }
                if (res.error) {
                    linesModal.hide();
                    showError(res.error);
                    return;
                }
                var responseLines = Array.isArray(res.lines) ? res.lines.slice() : Array.isArray(res) ? res.slice() : [];
                handleEmptyLineScenario(responseLines, {
                    raw: res.emitter || (currentLinesEmitter ? currentLinesEmitter.raw : ''),
                    display: res.emitter_display || (currentLinesEmitter ? currentLinesEmitter.display : ''),
                    acquirer: res.acquirer || (currentLinesEmitter ? currentLinesEmitter.acquirer : ''),
                    docType: res.doc_type || (currentLinesEmitter ? currentLinesEmitter.docType : '')
                }, !!res.skip_ocr).then(function(result) {
                    if (result.skipConfirmed) {
                        markAnalyzeLinesValidated();
                    }
                    costCenterCatalogPromise.finally(function() {
                        renderLines(result.lines);
                    });
                }).catch(function(err) {
                    costCenterCatalogPromise.finally(function() {
                        renderLines([]);
                    });
                    if (err && err.message) {
                        showError(err.message);
                    }
                });
            })
            .catch(function(err) {
                linesModal.hide();
                showError(err.message || 'Erro na análise');
            });
    });

    function lineHasContent(line) {
        if (!line || typeof line !== 'object') {
            return false;
        }
        var keys = ['ERP', 'IVA_TAXA', 'PRODUCT_CODE', 'ITEM', 'QUANTITY', 'UNIT_PRICE', 'PRICE', 'PRICE_VAT', 'COST_CENTER'];
        for (var i = 0; i < keys.length; i += 1) {
            var key = keys[i];
            if (Object.prototype.hasOwnProperty.call(line, key)) {
                var value = line[key];
                if (value !== null && value !== undefined && String(value).trim() !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    function areLinesCompletelyEmpty(lines) {
        if (!Array.isArray(lines) || lines.length === 0) {
            return true;
        }
        for (var i = 0; i < lines.length; i += 1) {
            if (lineHasContent(lines[i])) {
                return false;
            }
        }
        return true;
    }

    function markAnalyzeLinesValidated() {
        if (!currentLinesId) {
            return;
        }
        var analyzeBtn = document.querySelector('.analyze-lines[data-id="' + currentLinesId + '"]');
        if (analyzeBtn) {
            analyzeBtn.classList.remove('btn-info', 'btn-warning');
            analyzeBtn.classList.add('btn-success');
        }
        updateImportButtonState();
    }

    function confirmDisableOcr(emitterInfo) {
        var emitterLabel = emitterInfo && emitterInfo.display ? emitterInfo.display : 'este emitente';
        var message = 'Não foi possível ler as linhas desta fatura automaticamente. Deseja desativar a leitura automática (OCR) para ' + emitterLabel + ' no futuro?';
        if (window.Swal && typeof window.Swal.fire === 'function') {
            return window.Swal.fire({
                icon: 'warning',
                title: 'Desativar leitura automática?',
                text: message,
                showCancelButton: true,
                confirmButtonText: 'Sim',
                cancelButtonText: 'Não'
            }).then(function(result) {
                return !!result.isConfirmed;
            });
        }
        return Promise.resolve(window.confirm(message));
    }

    function persistSkipOcrPreference(emitterRaw, acquirerRaw, docTypeRaw) {
        if (!emitterRaw || !acquirerRaw || !docTypeRaw) {
            return Promise.resolve(false);
        }
        var body = new URLSearchParams({
            action: 'toggle_skip_ocr',
            emitter: emitterRaw,
            acquirer: acquirerRaw,
            doc_type: docTypeRaw,
            skip: '1',
            csrf_token: csrfInput ? csrfInput.value : ''
        });
        return fetchJson('contabilidade/save-analysis.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function(res) {
            if (res && res.csrf_token && csrfInput) {
                csrfInput.value = res.csrf_token;
            }
            if (!res || !res.success) {
                var message = res && res.error ? res.error : 'Falha ao guardar preferência';
                throw new Error(message);
            }
            return true;
        });
    }

    function handleEmptyLineScenario(lines, emitterInfo, skipAlreadyDisabled) {
        if (!areLinesCompletelyEmpty(lines)) {
            return Promise.resolve({
                lines: lines,
                skipConfirmed: false
            });
        }
        return confirmDisableOcr(emitterInfo).then(function(confirmed) {
            if (!confirmed) {
                return {
                    lines: [],
                    skipConfirmed: !!skipAlreadyDisabled
                };
            }
            var emitterRaw = emitterInfo && emitterInfo.raw ? emitterInfo.raw : '';
            var acquirerRaw = emitterInfo && emitterInfo.acquirer ? emitterInfo.acquirer : '';
            var docTypeRaw = emitterInfo && emitterInfo.docType ? emitterInfo.docType : '';
            return persistSkipOcrPreference(emitterRaw, acquirerRaw, docTypeRaw)
                .then(function() {
                    var label = emitterInfo && emitterInfo.display ? emitterInfo.display : emitterRaw || 'o emitente';
                    showSuccess('Leitura automática desativada para ' + label + '.');
                    return {
                        lines: [],
                        skipConfirmed: true
                    };
                })
                .catch(function(err) {
                    showError(err && err.message ? err.message : 'Não foi possível atualizar a preferência de OCR.');
                    return {
                        lines: [],
                        skipConfirmed: !!skipAlreadyDisabled
                    };
                });
        });
    }

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
                html += '<td>' + buildCostCenterSelectHtml(costCenter, 'form-control cost-center-input') + '</td>';
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
                updateImportButtonState();
            } else {
                showError(res.error || 'Erro ao guardar linhas');
            }
        })
        .catch(function(err) {
            showError(err.message || 'Erro ao guardar linhas');
        });
    });
});
