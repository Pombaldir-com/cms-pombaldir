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
    var feedbackPositiveBtn = document.getElementById('aiFeedbackPositive');
    var feedbackNegativeBtn = document.getElementById('aiFeedbackNegative');
    var feedbackInput = document.getElementById('aiFeedbackText');

    function appendMessage(role, text) {
        var bubble = document.createElement('div');
        bubble.className = 'ai-message ' + role;
        bubble.textContent = text;
        messagesEl.appendChild(bubble);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function sendMessage() {
        var text = inputEl.value.trim();
        if (!text) {
            return;
        }
        appendMessage('user', text);
        inputEl.value = '';
        sendBtn.disabled = true;
        fetch('assistant-handler.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                csrf_token: window.aiCsrfToken,
                message: text,
                session_id: window.aiSessionId
            })
        }).then(function(res) {
            return res.json();
        }).then(function(payload) {
            if (payload && payload.message) {
                appendMessage('assistant', payload.message);
            } else {
                appendMessage('assistant', 'Nao foi possivel obter resposta.');
            }
            if (payload && payload.csrf_token) {
                window.aiCsrfToken = payload.csrf_token;
            }
        }).catch(function() {
            appendMessage('assistant', 'Erro ao comunicar com o assistente.');
        }).finally(function() {
            sendBtn.disabled = false;
        });
    }

    function sendFeedback(rating) {
        var feedbackText = feedbackInput ? feedbackInput.value.trim() : '';
        fetch('assistant-handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: window.aiCsrfToken,
                action: 'log_feedback',
                rating: rating,
                feedback: feedbackText,
                session_id: window.aiSessionId,
                category: 'chat'
            })
        }).then(function(res) { return res.json(); })
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
