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
    require_once __DIR__ . '/header.php';
}
?>
<div class="container-fluid">
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-robot"></i> Assistente AI</h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div id="ai-chat" class="ai-chat">
                <div id="ai-messages" class="ai-messages"></div>
                <div class="ai-input">
                    <textarea id="ai-input" class="form-control" rows="2" placeholder="Escreva a sua mensagem..."></textarea>
                    <div class="mt-2">
                        <input id="ai-file" type="file" class="form-control" multiple>
                        <div id="ai-upload-status" class="small text-muted mt-1"></div>
                    </div>
                    <button id="ai-send" class="btn btn-primary mt-2"><i class="fa fa-paper-plane"></i> Enviar</button>
                    <?php if ($readOnly): ?>
                        <span class="badge bg-warning text-dark ms-2">Modo seguro</span>
                    <?php endif; ?>
                    <div class="ai-feedback mt-3">
                        <div class="text-muted small mb-1">Feedback rápido</div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
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
    height: 70vh;
}
.ai-messages {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
}
.ai-message {
    padding: 10px 12px;
    border-radius: 10px;
    margin-bottom: 10px;
    max-width: 80%;
    white-space: pre-wrap;
}
.ai-message.user {
    background: #e8f0fe;
    margin-left: auto;
}
.ai-message.assistant {
    background: #fff;
    border: 1px solid #e2e8f0;
}
.ai-input {
    margin-top: 12px;
}
</style>

<?php
$pageScripts = "window.aiSessionId = " . json_encode($sessionId) . ";\n"
    . "window.aiCsrfToken = " . json_encode($csrfToken) . ";\n"
    . "window.aiReadOnly = " . json_encode((int) $readOnly) . ";\n"
    . <<<'JS'
(function() {
    var messagesEl = document.getElementById('ai-messages');
    var inputEl = document.getElementById('ai-input');
    var sendBtn = document.getElementById('ai-send');
    var fileInput = document.getElementById('ai-file');
    var uploadStatusEl = document.getElementById('ai-upload-status');
    var feedbackPositiveBtn = document.getElementById('aiFeedbackPositive');
    var feedbackNegativeBtn = document.getElementById('aiFeedbackNegative');
    var feedbackInput = document.getElementById('aiFeedbackText');
    var pendingAttachmentIds = [];
    var activeUploads = 0;

    function appendMessage(role, text) {
        var bubble = document.createElement('div');
        bubble.className = 'ai-message ' + role;
        bubble.textContent = text;
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
                body: JSON.stringify(payload),
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
                var shouldRetry = tryIndex < retries && (err.name === 'AbortError' || status >= 500 || status === 0);
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
            return Promise.resolve();
        }
        activeUploads += 1;
        setUploadStatus('A carregar anexos...', false);
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
            pendingAttachmentIds.push(payload.attachment.id);
            appendMessage('assistant', 'Anexo carregado: ' + (payload.attachment.filename || file.name));
        });
    }

    function handleFileSelection() {
        if (!fileInput || !fileInput.files || !fileInput.files.length) {
            return;
        }
        var files = Array.prototype.slice.call(fileInput.files);
        var sequence = Promise.resolve();
        files.forEach(function(file) {
            sequence = sequence.then(function() {
                return uploadAttachment(file);
            });
        });
        sequence.catch(function(err) {
            setUploadStatus(err && err.message ? err.message : 'Falha no upload de anexos.', true);
        }).finally(function() {
            activeUploads = 0;
            fileInput.value = '';
            setUploadStatus('Anexos prontos para usar na próxima mensagem.', false);
        });
    }

    function sendMessage() {
        var text = inputEl.value.trim();
        if (!text || activeUploads > 0) {
            if (activeUploads > 0) {
                setUploadStatus('Aguarde o fim do upload antes de enviar.', true);
            }
            return;
        }
        var attachmentsToSend = pendingAttachmentIds.slice();
        pendingAttachmentIds = [];
        appendMessage('user', text);
        inputEl.value = '';
        sendBtn.disabled = true;
        requestAssistant({
            csrf_token: window.aiCsrfToken,
            message: text,
            session_id: window.aiSessionId,
            attachments: attachmentsToSend
        }).then(function(payload) {
            if (payload && payload.message) {
                appendMessage('assistant', payload.message);
            } else {
                appendMessage('assistant', 'Nao foi possivel obter resposta.');
            }
            if (payload && payload.csrf_token) {
                window.aiCsrfToken = payload.csrf_token;
            }
        }).catch(function(err) {
            appendMessage('assistant', buildAssistantErrorMessage(err, 'Erro ao comunicar com o assistente.'));
        }).finally(function() {
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
        }, { retries: 0, timeoutMs: 20000 })
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
