<?php
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$aiEnabled = getSetting('ai_enabled', '0') === '1';
if (!$aiEnabled || !userHasDepartmentPermission('ai_assistant')) {
    http_response_code(403);
    exit('Acesso negado.');
}

$user = currentUser();
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';
$csrfToken = generateCsrfToken();
$sessionId = bin2hex(random_bytes(8));
$readOnly = (int) ($user['ai_read_only'] ?? (int) getSetting('ai_default_read_only', '1'));
$hideOcrModal = true;

// Contexto de pagina (ex.: ficha de empresa em contabilidade/entidades),
// recebido via query string do iframe flutuante (ver footer.php). Apenas
// repassado ao JS tal-e-qual; a resolucao/validacao real e feita no servidor
// em assistant-handler.php a partir do nif/uuid.
$pageContextRaw = (string) ($_GET['page_context'] ?? '');
$pageContextData = null;
if ($pageContextRaw !== '') {
    $decodedPageContext = json_decode($pageContextRaw, true);
    if (is_array($decodedPageContext)) {
        $pageContextData = $decodedPageContext;
    }
}

if ($embed) {
    ?>
    <!DOCTYPE html>
    <html lang="pt">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Assistente AI</title>
        <link rel="stylesheet" href="vendors/bootstrap/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="vendors/font-awesome/css/font-awesome.min.css">
        <link rel="stylesheet" href="assets/css/custom.css">
    </head>
    <body class="nav-md" style="background:#f7f7f7;">
    <?php
}

if (!$embed) {
    $disableAiFloating = true;
    require_once __DIR__ . '/header.php';
}
?>
<div class="container-fluid">
    <div class="x_panel ai-shell">
        <div class="x_title">
            <h2><i class="fa fa-robot"></i> Assistente AI</h2>
            <ul class="nav navbar-right panel_toolbox">
                <?php if ($readOnly): ?>
                    <li><span class="badge bg-warning text-dark"><i class="fa fa-shield"></i> Modo seguro</span></li>
                <?php else: ?>
                    <li><span class="badge bg-success"><i class="fa fa-check-circle"></i> Modo assistido</span></li>
                <?php endif; ?>
            </ul>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div id="ai-chat" class="ai-chat">
                <div class="ai-toolbar">
                    <div class="ai-toolbar-title">
                        <strong>Conversa</strong>
                        <small>Respostas em tempo real com suporte a anexos</small>
                    </div>
                    <div class="ai-toolbar-actions">
                        <span class="badge bg-info"><i class="fa fa-bolt"></i> Contexto ativo</span>
                    </div>
                </div>
                <div id="ai-messages" class="ai-messages"></div>
                <div class="ai-input">
                    <div class="ai-compose">
                        <input id="ai-file" type="file" class="form-control ai-file-input" multiple>
                        <textarea id="ai-input" class="form-control ai-compose-text" rows="3" placeholder="Escreva a sua mensagem..."></textarea>
                        <div class="ai-compose-actions">
                            <button type="button" class="btn btn-default ai-attach-btn" id="ai-attach-btn" title="Anexar ficheiros">
                                <i class="fa fa-paperclip"></i>
                            </button>
                            <button id="ai-send" class="btn btn-primary ai-send-btn" type="button" title="Enviar">
                                <i class="fa fa-paper-plane"></i>
                            </button>
                        </div>
                    </div>
                    <div class="ai-compose-meta">
                        <div id="ai-upload-status" class="small text-muted"></div>
                        <span class="text-muted small ai-hint"><i class="fa fa-keyboard-o"></i> Enter envia, Shift+Enter cria nova linha</span>
                    </div>
                    <div class="ai-feedback mt-3">
                        <div class="text-muted small mb-1">Feedback rápido</div>
                        <div class="ai-feedback-row">
                            <button type="button" class="btn btn-sm btn-outline-success" id="aiFeedbackPositive">
                                <i class="fa fa-thumbs-up"></i> Útil
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" id="aiFeedbackNegative">
                                <i class="fa fa-thumbs-down"></i> Não útil
                            </button>
                            <input type="text" class="form-control form-control-sm flex-grow-1" id="aiFeedbackText" placeholder="Comentário (opcional)" style="min-width: 220px;">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ai-chat {
    display: flex;
    flex-direction: column;
    min-height: 74vh;
}
.ai-shell {
    border: 1px solid #dce6f0;
    border-radius: 12px;
    box-shadow: 0 16px 42px rgba(23, 36, 50, 0.08);
}
.ai-shell .x_title {
    border-bottom: 1px solid #dfe8f1;
    background: linear-gradient(90deg, #f8fbff 0%, #f3f8ff 100%);
}
.ai-shell .x_title h2 {
    font-weight: 600;
}
.ai-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    border: 1px solid #dce6f0;
    border-radius: 10px;
    background: #f7fbff;
    padding: 10px 12px;
}
.ai-toolbar-title strong {
    display: block;
    color: #2a3f54;
    font-size: 14px;
}
.ai-toolbar-title small {
    display: block;
    color: #6b7f92;
    font-size: 12px;
}
.ai-messages {
    flex: 1;
    overflow-y: auto;
    padding: 14px;
    background: linear-gradient(180deg, #f8fbff 0%, #f4f8fc 100%);
    border: 1px solid #dce6f0;
    border-radius: 10px;
}
.ai-message {
    position: relative;
    padding: 11px 13px;
    border-radius: 12px;
    margin-bottom: 11px;
    max-width: 82%;
    white-space: pre-wrap;
    box-shadow: 0 5px 14px rgba(25, 42, 61, 0.08);
}
.ai-message.user {
    background: linear-gradient(135deg, #3b87f9 0%, #2e6fd0 100%);
    color: #fff;
    margin-left: auto;
}
.ai-message.assistant {
    background: #fff;
    border: 1px solid #dfe8f1;
}
.ai-message.attachment {
    max-width: 92%;
    background: #f3f8ff;
    border: 1px dashed #b9c9d8;
    color: #2a3f54;
    margin-left: auto;
    margin-right: 0;
}
.ai-message.ai-downloads {
    background: #f0f9f4;
    border: 1px solid #c6e7d4;
}
.ai-downloads-label {
    font-size: 12px;
    color: #2a3f54;
    margin-bottom: 6px;
}
.ai-download-link {
    display: inline-block;
    margin: 3px 6px 3px 0;
    padding: 6px 12px;
    background: #26b99a;
    color: #fff;
    border-radius: 6px;
    text-decoration: none;
    font-size: 13px;
}
.ai-download-link:hover {
    background: #1f9c83;
    color: #fff;
    text-decoration: none;
}
.ai-input {
    margin-top: 12px;
    border: 1px solid #dce6f0;
    border-radius: 10px;
    background: #fff;
    padding: 12px;
}
.ai-compose {
    display: flex;
    align-items: stretch;
    gap: 10px;
}
.ai-compose-text {
    flex: 1;
    min-height: 96px;
    resize: vertical;
}
.ai-compose-actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: 56px;
}
.ai-compose-meta {
    margin-top: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.ai-hint {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.ai-feedback {
    padding-top: 8px;
    border-top: 1px dashed #dce6f0;
}
.ai-feedback-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.ai-feedback-row .form-control {
    min-width: 220px;
    flex: 1 1 220px;
}
.ai-file-input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}
.ai-attach-btn {
    width: 56px;
    height: 44px;
    font-size: 20px;
    border: 1px dashed #b9c9d8;
    background: #f8fbff;
}
.ai-send-btn {
    width: 56px;
    height: 44px;
    padding: 0;
}
.ai-attach-btn:hover,
.ai-attach-btn:focus {
    background: #eef5fc;
    border-color: #8eabc3;
}
@media (max-width: 768px) {
    .ai-chat {
        min-height: 68vh;
    }
    .ai-toolbar {
        flex-direction: column;
        align-items: flex-start;
    }
    .ai-message {
        max-width: 94%;
    }
    .ai-compose {
        flex-direction: column;
    }
    .ai-compose-actions {
        flex-direction: row;
        width: 100%;
    }
    .ai-attach-btn,
    .ai-send-btn {
        width: 48px;
        height: 40px;
    }
}
</style>

<?php
$pageScripts = "window.aiSessionId = " . json_encode($sessionId) . ";\n"
    . "window.aiCsrfToken = " . json_encode($csrfToken) . ";\n"
    . "window.aiReadOnly = " . json_encode((int) $readOnly) . ";\n"
    . "window.aiPageContext = " . json_encode($pageContextData, JSON_UNESCAPED_UNICODE) . ";\n"
    . <<<'JS'
(function() {
    var messagesEl = document.getElementById('ai-messages');
    var inputEl = document.getElementById('ai-input');
    var sendBtn = document.getElementById('ai-send');
    var fileInput = document.getElementById('ai-file');
    var attachBtn = document.getElementById('ai-attach-btn');
    var uploadStatusEl = document.getElementById('ai-upload-status');
    var feedbackPositiveBtn = document.getElementById('aiFeedbackPositive');
    var feedbackNegativeBtn = document.getElementById('aiFeedbackNegative');
    var feedbackInput = document.getElementById('aiFeedbackText');
    var selectedFiles = [];
    var activeUploads = 0;

    function appendMessage(role, text) {
        var bubble = document.createElement('div');
        bubble.className = 'ai-message ' + role;
        bubble.textContent = text;
        messagesEl.appendChild(bubble);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function appendAttachmentMessage(filenames) {
        if (!Array.isArray(filenames) || !filenames.length) {
            return;
        }
        var label = filenames.length === 1 ? 'Anexo enviado' : 'Anexos enviados';
        appendMessage('attachment', label + ': ' + filenames.join(', '));
    }

    function appendDownloads(downloads) {
        if (!Array.isArray(downloads) || !downloads.length) {
            return;
        }
        var bubble = document.createElement('div');
        bubble.className = 'ai-message assistant ai-downloads';
        var label = document.createElement('div');
        label.className = 'ai-downloads-label';
        label.textContent = downloads.length === 1 ? 'Ficheiro disponivel para download:' : 'Ficheiros disponiveis para download:';
        bubble.appendChild(label);
        downloads.forEach(function (dl) {
            if (!dl || !dl.url) {
                return;
            }
            var link = document.createElement('a');
            link.className = 'ai-download-link';
            link.href = dl.url;
            link.setAttribute('download', dl.filename || '');
            link.setAttribute('rel', 'noopener');
            link.target = '_blank';
            var name = dl.filename || 'ficheiro';
            var size = dl.size ? ' (' + Math.max(1, Math.round(dl.size / 1024)) + ' KB)' : '';
            link.textContent = '⬇️ ' + name + size;
            bubble.appendChild(link);
        });
        messagesEl.appendChild(bubble);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function readFileAsDataUrl(file) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() { resolve(String(reader.result || '')); };
            reader.onerror = function() { reject(new Error('Falha ao ler ficheiro.')); };
            reader.readAsDataURL(file);
        });
    }

    function setUploadStatus(message, isError) {
        if (!uploadStatusEl) {
            return;
        }
        uploadStatusEl.textContent = message || '';
        uploadStatusEl.className = isError ? 'small text-danger mt-1' : 'small text-muted mt-1';
    }

    function buildAssistantErrorMessage(err, fallback) {
        var defaultMsg = fallback || 'Erro ao comunicar com o assistente.';
        if (!err) {
            return defaultMsg;
        }
        if (err.name === 'AbortError') {
            return 'O pedido demorou demasiado tempo. Tente novamente.';
        }
        var message = (err && err.message) ? String(err.message).trim() : '';
        if (!message) {
            return defaultMsg;
        }
        return message;
    }

    function requestAssistant(payload, options) {
        options = options || {};
        var retries = typeof options.retries === 'number' ? options.retries : 1;
        var timeoutMs = typeof options.timeoutMs === 'number' ? options.timeoutMs : 45000;
        var payloadRef = payload || {};

        function attempt(tryIndex) {
            var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var timeoutId = null;
            if (controller) {
                timeoutId = window.setTimeout(function() {
                    controller.abort();
                }, timeoutMs);
            }

            return fetch('assistant-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payloadRef),
                signal: controller ? controller.signal : undefined
            }).then(function(res) {
                return res.text().then(function(rawText) {
                    if (timeoutId) {
                        window.clearTimeout(timeoutId);
                    }
                    var data = null;
                    if (rawText) {
                        try {
                            data = JSON.parse(rawText);
                        } catch (parseError) {
                            var invalidErr = new Error('Resposta invalida do servidor do assistente.');
                            invalidErr.status = res.status;
                            invalidErr.raw = rawText;
                            throw invalidErr;
                        }
                    }

                    if (data && data.csrf_token) {
                        window.aiCsrfToken = data.csrf_token;
                        payloadRef.csrf_token = data.csrf_token;
                    }

                    if (!res.ok) {
                        var serverMsg = (data && data.message) ? String(data.message) : ('Erro HTTP ' + res.status + ' no assistente.');
                        var httpErr = new Error(serverMsg);
                        httpErr.status = res.status;
                        httpErr.payload = data;
                        throw httpErr;
                    }

                    return data || {};
                });
            }).catch(function(err) {
                if (timeoutId) {
                    window.clearTimeout(timeoutId);
                }
                var status = (err && typeof err.status === 'number') ? err.status : 0;
                var errorMessage = (err && err.message) ? String(err.message).toLowerCase() : '';
                var csrfInvalid = status === 400 && (errorMessage.indexOf('token invalido') !== -1 || errorMessage.indexOf('csrf') !== -1);
                var shouldRetry = tryIndex < retries && (err.name === 'AbortError' || status >= 500 || status === 0 || csrfInvalid);
                if (shouldRetry) {
                    return attempt(tryIndex + 1);
                }
                throw err;
            });
        }

        return attempt(0);
    }

    function uploadAttachment(file) {
        if (!file) {
            return Promise.resolve('');
        }
        return readFileAsDataUrl(file).then(function(dataUrl) {
            return requestAssistant({
                csrf_token: window.aiCsrfToken,
                action: 'upload_attachment',
                session_id: window.aiSessionId,
                filename: file.name || 'anexo.bin',
                mime_type: file.type || 'application/octet-stream',
                content_base64: dataUrl
            });
        }).then(function(payload) {
            if (!payload || payload.success !== true || !payload.attachment || !payload.attachment.id) {
                throw new Error((payload && payload.message) ? payload.message : 'Falha no upload do anexo.');
            }
            if (payload.csrf_token) {
                window.aiCsrfToken = payload.csrf_token;
            }
            return String(payload.attachment.id || '');
        });
    }

    function handleFileSelection() {
        if (!fileInput) {
            return;
        }
        selectedFiles = fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
        if (!selectedFiles.length) {
            setUploadStatus('', false);
            return;
        }
        if (selectedFiles.length === 1) {
            setUploadStatus('1 anexo selecionado. Sera enviado com a mensagem.', false);
            return;
        }
        setUploadStatus(selectedFiles.length + ' anexos selecionados. Serao enviados com a mensagem.', false);
    }

    function uploadSelectedFilesWithMessage() {
        if (!selectedFiles.length) {
            return Promise.resolve([]);
        }
        activeUploads += 1;
        setUploadStatus('A enviar anexos com a mensagem...', false);
        var attachmentIds = [];
        var sequence = Promise.resolve();
        selectedFiles.forEach(function(file) {
            sequence = sequence.then(function() {
                return uploadAttachment(file).then(function(attachmentId) {
                    if (attachmentId) {
                        attachmentIds.push(attachmentId);
                    }
                });
            });
        });
        return sequence.then(function() {
            return attachmentIds;
        }).finally(function() {
            activeUploads = 0;
            selectedFiles = [];
            fileInput.value = '';
        });
    }

    function sendMessage() {
        var text = inputEl.value.trim();
        var selectedFilenames = selectedFiles.map(function(file) {
            return (file && file.name) ? String(file.name) : 'anexo.bin';
        });
        if (activeUploads > 0) {
            setUploadStatus('Aguarde o envio dos anexos antes de continuar.', true);
            return;
        }
        if (!text) {
            setUploadStatus('Escreva uma mensagem para enviar com os anexos.', true);
            return;
        }
        sendBtn.disabled = true;

        uploadSelectedFilesWithMessage().then(function(attachmentsToSend) {
            appendMessage('user', text);
            if (attachmentsToSend.length > 0) {
                appendAttachmentMessage(selectedFilenames);
            }
            inputEl.value = '';
            if (attachmentsToSend.length > 0) {
                setUploadStatus('Anexos enviados com a mensagem.', false);
            } else {
                setUploadStatus('', false);
            }
            return requestAssistant({
                csrf_token: window.aiCsrfToken,
                message: text,
                session_id: window.aiSessionId,
                attachments: attachmentsToSend,
                page_context: window.aiPageContext
            });
        }).then(function(payload) {
            if (payload && payload.message) {
                appendMessage('assistant', payload.message);
            } else {
                appendMessage('assistant', 'Nao foi possivel obter resposta.');
            }
            if (payload && payload.downloads) {
                appendDownloads(payload.downloads);
            }
            if (payload && payload.csrf_token) {
                window.aiCsrfToken = payload.csrf_token;
            }
        }).catch(function(err) {
            setUploadStatus(err && err.message ? err.message : 'Falha no envio de anexos.', true);
            appendMessage('assistant', buildAssistantErrorMessage(err, 'Erro ao comunicar com o assistente.'));
        }).finally(function() {
            activeUploads = 0;
            sendBtn.disabled = false;
        });
    }

    function sendFeedback(rating) {
        var feedbackText = feedbackInput ? feedbackInput.value.trim() : '';
        requestAssistant({
            csrf_token: window.aiCsrfToken,
            action: 'log_feedback',
            rating: rating,
            feedback: feedbackText,
            session_id: window.aiSessionId,
            category: 'chat'
        }, { retries: 1, timeoutMs: 20000 })
        .then(function(payload) {
            if (payload && payload.csrf_token) {
                window.aiCsrfToken = payload.csrf_token;
            }
            if (feedbackInput) {
                feedbackInput.value = '';
            }
        }).catch(function() {});
    }

    sendBtn.addEventListener('click', sendMessage);
    inputEl.addEventListener('keydown', function(ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) {
            ev.preventDefault();
            sendMessage();
        }
    });

    if (feedbackPositiveBtn) {
        feedbackPositiveBtn.addEventListener('click', function() {
            sendFeedback(5);
        });
    }
    if (feedbackNegativeBtn) {
        feedbackNegativeBtn.addEventListener('click', function() {
            sendFeedback(1);
        });
    }
    if (fileInput) {
        fileInput.addEventListener('change', handleFileSelection);
    }
    if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function() {
            fileInput.click();
        });
    }

    appendMessage('assistant', 'Ola! Como posso ajudar?');
})();
JS;

if ($embed) {
    ?>
    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/pnotify_theme_adapter.js"></script>
    <script src="assets/js/custom.js"></script>
    <script>
    <?= $pageScripts ?>
    </script>
    </body>
    </html>
    <?php
    exit;
}

require_once __DIR__ . '/footer.php';
