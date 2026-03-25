<?php
require_once __DIR__ . '/functions.php';

startSession();
requireLogin();

$user = currentUser();
$embed = isset($_GET['embed']) && $_GET['embed'] === '1';
$userId = (int) ($user['id'] ?? 0);
$canCreateGroups = ((int) ($user['role'] ?? 3)) <= 2;
$chatEnabled = isInternalChatEnabled();
$chatTablesReady = $chatEnabled && hasInternalChatTables();
$chatError = null;
$channels = [];
$members = [];
$presenceUsers = [];
$selectedChannelId = 0;
$initialMessages = [];

if (!$chatEnabled) {
    http_response_code(403);
    $chatError = 'O chat interno esta desativado nas definicoes da aplicacao.';
} elseif (!$chatTablesReady) {
    http_response_code(503);
    $chatError = 'O chat interno ainda nao esta disponivel nesta base de dados. Execute as migracoes pendentes.';
} else {
    ensureInternalChatPublicChannel();
    $channels = getInternalChatChannelsForUser($userId);
    $requestedChannelId = (int) ($_GET['channel'] ?? 0);

    if ($requestedChannelId > 0 && userCanAccessInternalChatChannel($userId, $requestedChannelId)) {
        $selectedChannelId = $requestedChannelId;
    } elseif (!empty($channels[0]['id'])) {
        $selectedChannelId = (int) $channels[0]['id'];
    }

    if ($selectedChannelId > 0) {
        $initialMessages = getInternalChatMessages($userId, $selectedChannelId);
    }

    $presenceUsers = getInternalChatPresenceUsers();

    if ($canCreateGroups) {
        $members = getInternalChatAvailableUsers();
    }
}

$useSelect2 = $canCreateGroups;
$disableAiFloating = true;
$disableInternalChatFloating = true;
$pageScripts = '';

if ($chatTablesReady) {
    $pageScripts = <<<JS
const internalChatConfig = {
    csrfToken: __CSRF_TOKEN__,
    currentUserId: __CURRENT_USER_ID__,
    canCreateGroups: __CAN_CREATE_GROUPS__,
    channelsEndpoint: __CHANNELS_ENDPOINT__,
    presenceEndpoint: __PRESENCE_ENDPOINT__,
    initialChannels: __INITIAL_CHANNELS__,
    initialPresenceUsers: __INITIAL_PRESENCE_USERS__,
    initialMessages: __INITIAL_MESSAGES__,
    initialChannelId: __INITIAL_CHANNEL_ID__
};

(function ($) {
    var channels = Array.isArray(internalChatConfig.initialChannels) ? internalChatConfig.initialChannels : [];
    var presenceUsers = Array.isArray(internalChatConfig.initialPresenceUsers) ? internalChatConfig.initialPresenceUsers : [];
    var currentChannelId = Number(internalChatConfig.initialChannelId || 0);
    var pollingTimer = null;
    var presenceTimer = null;
    var sending = false;
    var loadingMessages = false;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatTimestamp(value) {
        if (!value) {
            return '';
        }
        var normalized = String(value).replace(' ', 'T');
        var date = new Date(normalized);
        if (isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString('pt-PT', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function initialsFromName(name) {
        var clean = String(name || '').trim();
        if (!clean) {
            return '?';
        }
        var parts = clean.split(/\\s+/).slice(0, 2);
        return parts.map(function (part) {
            return part.charAt(0).toUpperCase();
        }).join('');
    }

    function getCurrentChannel() {
        for (var i = 0; i < channels.length; i += 1) {
            if (Number(channels[i].id) === Number(currentChannelId)) {
                return channels[i];
            }
        }
        return null;
    }

    function getPresenceStateLabel(state) {
        switch (String(state || 'offline')) {
            case 'online':
                return 'Online';
            case 'away':
                return 'Ausente';
            default:
                return 'Offline';
        }
    }

    function setFeedback(message, type) {
        var box = $('#internalChatFeedback');
        if (!message) {
            box.hide().removeClass('alert-success alert-danger alert-warning').text('');
            return;
        }
        box
            .show()
            .removeClass('alert-success alert-danger alert-warning')
            .addClass('alert-' + (type || 'warning'))
            .text(message);
    }

    function renderChannels() {
        var list = $('#chatChannelList');
        list.empty();

        if (!channels.length) {
            list.append('<div class="chat-empty-state">Sem canais disponiveis.</div>');
            return;
        }

        channels.forEach(function (channel) {
            var isActive = Number(channel.id) === Number(currentChannelId);
            var badgeClass = channel.channel_type === 'public' ? 'badge bg-info' : 'badge bg-secondary';
            var badgeLabel = channel.channel_type === 'public' ? 'Publico' : 'Grupo';
            var preview = channel.last_message ? escapeHtml(channel.last_message) : 'Sem mensagens';
            var countLabel = Number(channel.member_count || 0) > 0 && channel.channel_type !== 'public'
                ? '<span class="chat-channel-meta">' + Number(channel.member_count) + ' membro(s)</span>'
                : '';

            list.append(
                '<button type="button" class="chat-channel-item ' + (isActive ? 'is-active' : '') + '" data-channel-id="' + Number(channel.id) + '">' +
                    '<div class="chat-channel-top">' +
                        '<strong>' + escapeHtml(channel.name) + '</strong>' +
                        '<span class="' + badgeClass + '">' + badgeLabel + '</span>' +
                    '</div>' +
                    '<div class="chat-channel-preview">' + preview + '</div>' +
                    '<div class="chat-channel-bottom">' +
                        '<span class="chat-channel-meta">' + escapeHtml(formatTimestamp(channel.last_message_at || channel.updated_at || '')) + '</span>' +
                        countLabel +
                    '</div>' +
                '</button>'
            );
        });
    }

    function renderCurrentChannelHeader() {
        var channel = getCurrentChannel();
        if (!channel) {
            $('#chatCurrentChannelTitle').text('Sem canal');
            $('#chatCurrentChannelMeta').text('');
            return;
        }

        $('#chatCurrentChannelTitle').text(channel.name);
        $('#chatCurrentChannelMeta').text(
            channel.channel_type === 'public'
                ? 'Canal publico partilhado por todos os colaboradores.'
                : 'Grupo privado visivel apenas para os membros.'
        );
    }

    function renderPresence(users) {
        var list = $('#chatPresenceList');
        var counts = {
            online: 0,
            away: 0,
            offline: 0
        };

        presenceUsers = Array.isArray(users) ? users : [];
        list.empty();

        if (!presenceUsers.length) {
            list.append('<div class="chat-empty-state">Sem utilizadores disponiveis.</div>');
        } else {
            presenceUsers.forEach(function (presenceUser) {
                var state = String(presenceUser.presence_state || 'offline');
                var lastSeen = presenceUser.last_seen ? formatTimestamp(presenceUser.last_seen) : 'Sem atividade';
                var photoHtml = presenceUser.photo
                    ? '<img src="' + escapeHtml(presenceUser.photo) + '" alt="">'
                    : '<span>' + escapeHtml(initialsFromName(presenceUser.display_name)) + '</span>';

                if (!counts[state]) {
                    counts[state] = 0;
                }
                counts[state] += 1;

                list.append(
                    '<div class="chat-presence-item">' +
                        '<div class="chat-avatar chat-avatar-small">' + photoHtml + '</div>' +
                        '<div class="chat-presence-body">' +
                            '<div class="chat-presence-top">' +
                                '<strong>' + escapeHtml(presenceUser.display_name) + '</strong>' +
                                '<span class="chat-state-dot ' + escapeHtml(state) + '"></span>' +
                            '</div>' +
                            '<div class="chat-presence-meta">' + escapeHtml(getPresenceStateLabel(state)) + ' · ' + escapeHtml(lastSeen) + '</div>' +
                        '</div>' +
                    '</div>'
                );
            });
        }

        $('#chatPresenceCounts').text(
            String(counts.online || 0) + ' online · ' +
            String(counts.away || 0) + ' ausente(s)'
        );
    }

    function renderMessages(messages, scrollToBottom) {
        var container = $('#chatMessages');
        container.empty();

        if (!Array.isArray(messages) || !messages.length) {
            container.append('<div class="chat-empty-state">Ainda nao existem mensagens neste canal.</div>');
            return;
        }

        messages.forEach(function (message) {
            var isMine = Number(message.user_id) === Number(internalChatConfig.currentUserId);
            var photoHtml = message.photo
                ? '<img src="' + escapeHtml(message.photo) + '" alt="">'
                : '<span>' + escapeHtml(initialsFromName(message.display_name)) + '</span>';

            container.append(
                '<div class="chat-message-row ' + (isMine ? 'is-own' : '') + '">' +
                    '<div class="chat-avatar">' + photoHtml + '</div>' +
                    '<div class="chat-message-bubble">' +
                        '<div class="chat-message-meta">' +
                            '<strong>' + escapeHtml(message.display_name) + '</strong>' +
                            '<span>' + escapeHtml(formatTimestamp(message.created_at)) + '</span>' +
                        '</div>' +
                        '<div class="chat-message-text">' + escapeHtml(message.message) + '</div>' +
                    '</div>' +
                '</div>'
            );
        });

        if (scrollToBottom) {
            container.scrollTop(container[0].scrollHeight);
        }
    }

    function loadChannels(options) {
        options = options || {};
        return $.getJSON(internalChatConfig.channelsEndpoint, { action: 'channels' })
            .done(function (response) {
                if (!response.ok) {
                    setFeedback(response.message || 'Nao foi possivel carregar os canais.', 'danger');
                    return;
                }

                channels = Array.isArray(response.channels) ? response.channels : [];
                if (!channels.length) {
                    currentChannelId = 0;
                } else if (!getCurrentChannel()) {
                    currentChannelId = Number(channels[0].id);
                }

                renderChannels();
                renderCurrentChannelHeader();

                if (options.reloadMessages !== false && currentChannelId > 0) {
                    loadMessages(!!options.scrollToBottom);
                }
            })
            .fail(function (xhr) {
                var message = (xhr.responseJSON && xhr.responseJSON.message) || 'Falha ao atualizar os canais.';
                setFeedback(message, 'danger');
            });
    }

    function loadMessages(scrollToBottom) {
        if (!currentChannelId || loadingMessages) {
            return;
        }

        loadingMessages = true;
        $.getJSON(internalChatConfig.channelsEndpoint, {
            action: 'messages',
            channel_id: currentChannelId
        })
            .done(function (response) {
                if (!response.ok) {
                    setFeedback(response.message || 'Nao foi possivel carregar as mensagens.', 'danger');
                    return;
                }
                renderMessages(response.messages || [], !!scrollToBottom);
                renderCurrentChannelHeader();
            })
            .fail(function (xhr) {
                var message = (xhr.responseJSON && xhr.responseJSON.message) || 'Falha ao carregar as mensagens.';
                setFeedback(message, 'danger');
            })
            .always(function () {
                loadingMessages = false;
            });
    }

    function loadPresence() {
        return $.getJSON(internalChatConfig.presenceEndpoint, { action: 'presence' })
            .done(function (response) {
                if (!response.ok) {
                    return;
                }
                renderPresence(response.users || []);
            });
    }

    function selectChannel(channelId, scrollToBottom) {
        currentChannelId = Number(channelId || 0);
        renderChannels();
        renderCurrentChannelHeader();
        loadMessages(scrollToBottom !== false);

        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.set('channel', currentChannelId);
            window.history.replaceState({}, '', url.toString());
        }
    }

    function startPolling() {
        if (pollingTimer) {
            window.clearInterval(pollingTimer);
        }
        pollingTimer = window.setInterval(function () {
            loadChannels({ reloadMessages: false });
            loadMessages(false);
        }, 5000);

        if (presenceTimer) {
            window.clearInterval(presenceTimer);
        }
        presenceTimer = window.setInterval(loadPresence, 15000);
    }

    $(document).on('click', '.chat-channel-item', function () {
        var channelId = Number($(this).data('channel-id') || 0);
        if (!channelId || channelId === currentChannelId) {
            return;
        }
        setFeedback('', 'warning');
        selectChannel(channelId, true);
    });

    $('#chatMessageForm').on('submit', function (event) {
        event.preventDefault();
        if (!currentChannelId || sending) {
            return;
        }

        var messageField = $('#chatMessageInput');
        var message = messageField.val();
        if (!String(message || '').trim()) {
            return;
        }

        sending = true;
        $('#chatSendButton').prop('disabled', true);

        $.post(internalChatConfig.channelsEndpoint, {
            action: 'send',
            csrf_token: internalChatConfig.csrfToken,
            channel_id: currentChannelId,
            message: message
        })
            .done(function (response) {
                if (!response.ok) {
                    setFeedback(response.message || 'Nao foi possivel enviar a mensagem.', 'danger');
                    return;
                }

                channels = Array.isArray(response.channels) ? response.channels : channels;
                renderChannels();
                renderCurrentChannelHeader();
                renderMessages(response.messages || [], true);
                messageField.val('').trigger('focus');
                setFeedback('', 'warning');
            })
            .fail(function (xhr) {
                var message = (xhr.responseJSON && xhr.responseJSON.message) || 'Falha ao enviar a mensagem.';
                setFeedback(message, 'danger');
            })
            .always(function () {
                sending = false;
                $('#chatSendButton').prop('disabled', false);
            });
    });

    $('#chatMessageInput').on('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            $('#chatMessageForm').trigger('submit');
        }
    });

    $('#chatEnableAlerts').on('click', function () {
        if (!window.internalChatAlerts || typeof window.internalChatAlerts.requestPermission !== 'function') {
            setFeedback('O browser nao suporta alertas de chat.', 'warning');
            return;
        }

        window.internalChatAlerts.requestPermission().then(function (permission) {
            if (permission === 'granted') {
                setFeedback('Alertas de mensagens ativados neste browser.', 'success');
                return;
            }

            if (permission === 'denied') {
                setFeedback('Os alertas foram bloqueados pelo browser.', 'warning');
                return;
            }

            setFeedback('Os alertas continuam desativados.', 'warning');
        });
    });

    if (internalChatConfig.canCreateGroups) {
        var groupModalElement = document.getElementById('chatGroupModal');
        var groupModal = groupModalElement ? bootstrap.Modal.getOrCreateInstance(groupModalElement) : null;

        $('.js-chat-members').select2({
            width: '100%',
            dropdownParent: $('#chatGroupModal')
        });

        $('#chatGroupForm').on('submit', function (event) {
            event.preventDefault();

            $.post(internalChatConfig.channelsEndpoint, $(this).serialize())
                .done(function (response) {
                    if (!response.ok) {
                        setFeedback(response.message || 'Nao foi possivel criar o grupo.', 'danger');
                        return;
                    }

                    channels = Array.isArray(response.channels) ? response.channels : channels;
                    renderChannels();
                    setFeedback('Grupo criado com sucesso.', 'success');
                    $('#chatGroupForm')[0].reset();
                    $('.js-chat-members').val(null).trigger('change');

                    if (response.channel_id) {
                        selectChannel(Number(response.channel_id), true);
                    }

                    if (groupModal) {
                        groupModal.hide();
                    }
                })
                .fail(function (xhr) {
                    var message = (xhr.responseJSON && xhr.responseJSON.message) || 'Falha ao criar o grupo.';
                    setFeedback(message, 'danger');
                });
        });
    }

    renderChannels();
    renderCurrentChannelHeader();
    renderPresence(internalChatConfig.initialPresenceUsers || []);
    renderMessages(internalChatConfig.initialMessages || [], true);
    loadPresence();
    startPolling();
})(jQuery);
JS;

    $pageScripts = strtr($pageScripts, [
        '__CSRF_TOKEN__' => json_encode(generateCsrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '__CURRENT_USER_ID__' => (string) $userId,
        '__CAN_CREATE_GROUPS__' => $canCreateGroups ? 'true' : 'false',
        '__CHANNELS_ENDPOINT__' => json_encode(BASE_URL . 'chat-interno-handler', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '__PRESENCE_ENDPOINT__' => json_encode(BASE_URL . 'chat-interno-handler', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '__INITIAL_CHANNELS__' => json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '__INITIAL_PRESENCE_USERS__' => json_encode($presenceUsers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '__INITIAL_MESSAGES__' => json_encode($initialMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '__INITIAL_CHANNEL_ID__' => (string) $selectedChannelId,
    ]);
}

if ($embed) {
    ?>
    <!DOCTYPE html>
    <html lang="pt">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Chat Interno</title>
        <link rel="stylesheet" href="vendors/bootstrap/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="vendors/font-awesome/css/font-awesome.min.css">
        <?php if ($useSelect2): ?>
        <link rel="stylesheet" href="vendors/select2/dist/css/select2.min.css">
        <?php endif; ?>
        <link rel="stylesheet" href="assets/css/custom.css">
    </head>
    <body class="nav-md" style="background:#f7f7f7;">
    <?php
} else {
    require_once __DIR__ . '/header.php';
}
?>
<div class="internal-chat-page">
    <?php if ($embed && $chatError === null): ?>
    <div class="internal-chat-embed-toolbar">
        <div class="internal-chat-embed-title">Janela de conversa</div>
        <div class="internal-chat-embed-actions">
            <button type="button" class="btn btn-default btn-sm" id="chatEnableAlerts">
                <i class="fa fa-bell"></i> Alertas
            </button>
            <?php if ($canCreateGroups && $chatTablesReady): ?>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#chatGroupModal">
                <i class="fa fa-users"></i> Novo grupo
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!$embed): ?>
    <div class="page-title">
        <div class="title_left">
            <h3><i class="fa fa-comments-o"></i> Chat Interno</h3>
        </div>
        <div class="title_right text-end">
            <?php if ($chatTablesReady): ?>
            <button type="button" class="btn btn-default" id="chatEnableAlerts">
                <i class="fa fa-bell"></i> Ativar alertas
            </button>
            <?php endif; ?>
            <?php if ($canCreateGroups && $chatTablesReady): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#chatGroupModal">
                <i class="fa fa-users"></i> Novo grupo
            </button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="clearfix"></div>

    <div id="internalChatFeedback" class="alert" style="display:none;"></div>

    <?php if ($chatError !== null): ?>
    <div class="x_panel">
        <div class="x_title">
            <h2><i class="fa fa-exclamation-triangle"></i> Chat indisponivel</h2>
            <div class="clearfix"></div>
        </div>
        <div class="x_content">
            <div class="alert alert-warning" role="alert" style="margin-bottom:0;"><?= htmlspecialchars($chatError); ?></div>
        </div>
    </div>
    <?php else: ?>
    <div class="row">
        <div class="col-md-4 col-lg-3">
            <div class="x_panel chat-sidebar-panel">
                <div class="x_title">
                    <h2><i class="fa fa-comments"></i> Canais</h2>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div id="chatChannelList" class="chat-channel-list"></div>
                </div>
            </div>
            <div class="x_panel chat-sidebar-panel">
                <div class="x_title">
                    <h2><i class="fa fa-circle"></i> Presenca</h2>
                    <ul class="nav navbar-right panel_toolbox" style="min-width:auto;">
                        <li><span id="chatPresenceCounts" class="chat-channel-header-meta"></span></li>
                    </ul>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div id="chatPresenceList" class="chat-presence-list"></div>
                </div>
            </div>
        </div>
        <div class="col-md-8 col-lg-9">
            <div class="x_panel chat-main-panel">
                <div class="x_title">
                    <h2 id="chatCurrentChannelTitle">Canal</h2>
                    <ul class="nav navbar-right panel_toolbox" style="min-width:auto;">
                        <li><span id="chatCurrentChannelMeta" class="chat-channel-header-meta"></span></li>
                    </ul>
                    <div class="clearfix"></div>
                </div>
                <div class="x_content">
                    <div id="chatMessages" class="chat-messages"></div>
                    <form id="chatMessageForm" class="chat-composer">
                        <div class="form-group mb-2">
                            <label for="chatMessageInput" class="sr-only">Mensagem</label>
                            <textarea id="chatMessageInput" class="form-control" rows="3" placeholder="Escreva uma mensagem para o canal atual..."></textarea>
                        </div>
                        <div class="text-end">
                            <button type="submit" id="chatSendButton" class="btn btn-success">
                                <i class="fa fa-paper-plane"></i> Enviar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($canCreateGroups && $chatTablesReady): ?>
<div class="modal fade" id="chatGroupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="chatGroupForm">
                <div class="modal-header">
                    <h4 class="modal-title"><i class="fa fa-users"></i> Criar grupo</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_group">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()); ?>">
                    <div class="form-group mb-3">
                        <label for="chatGroupName">Nome do grupo</label>
                        <input type="text" class="form-control" id="chatGroupName" name="name" maxlength="150" required>
                    </div>
                    <div class="form-group mb-0">
                        <label for="chatGroupMembers">Membros</label>
                        <select id="chatGroupMembers" class="form-control js-chat-members" name="member_ids[]" multiple>
                            <?php foreach ($members as $member): ?>
                            <option value="<?= (int) $member['id']; ?>"><?= htmlspecialchars((string) $member['display_name']); ?><?php if (!empty($member['username'])): ?> (<?= htmlspecialchars((string) $member['username']); ?>)<?php endif; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">O utilizador que cria o grupo e incluido automaticamente.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Criar grupo</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
.internal-chat-page {
    padding: <?= $embed ? '12px' : '0'; ?>;
}
.internal-chat-embed-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;
}
.internal-chat-embed-title {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #70849a;
}
.internal-chat-embed-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.internal-chat-page .page-title {
    margin-bottom: 16px;
}
.chat-sidebar-panel .x_content,
.chat-main-panel .x_content {
    padding: 0;
}
.chat-channel-list {
    display: flex;
    flex-direction: column;
}
.chat-presence-list {
    display: flex;
    flex-direction: column;
    padding: 8px 0;
}
.chat-channel-item {
    border: 0;
    border-bottom: 1px solid #e6e9ed;
    background: #fff;
    text-align: left;
    padding: 14px 16px;
    width: 100%;
    transition: background-color 0.2s ease, border-left-color 0.2s ease;
    border-left: 4px solid transparent;
}
.chat-channel-item:hover,
.chat-channel-item:focus {
    background: #f6f9fc;
    outline: none;
}
.chat-channel-item.is-active {
    background: #eef6ff;
    border-left-color: #1f78d1;
}
.chat-channel-top,
.chat-channel-bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.chat-channel-preview {
    margin: 8px 0 10px;
    color: #6c7a89;
    font-size: 12px;
    line-height: 1.5;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.chat-channel-meta,
.chat-channel-header-meta {
    color: #7b8a9a;
    font-size: 12px;
}
.chat-messages {
    height: 60vh;
    overflow-y: auto;
    padding: 18px;
    background: linear-gradient(180deg, #f9fbfd 0%, #ffffff 100%);
}
.chat-message-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 16px;
}
.chat-message-row.is-own {
    flex-direction: row-reverse;
}
.chat-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    overflow: hidden;
    background: #1f78d1;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex: 0 0 42px;
}
.chat-avatar-small {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    font-size: 12px;
}
.chat-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.chat-presence-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 18px;
    border-bottom: 1px solid #eef2f6;
}
.chat-presence-item:last-child {
    border-bottom: 0;
}
.chat-presence-body {
    min-width: 0;
    flex: 1 1 auto;
}
.chat-presence-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
}
.chat-presence-meta {
    color: #7b8a9a;
    font-size: 12px;
    margin-top: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.chat-state-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #c1cad4;
    flex: 0 0 10px;
}
.chat-state-dot.online {
    background: #1abb9c;
}
.chat-state-dot.away {
    background: #f0ad4e;
}
.chat-state-dot.offline {
    background: #c1cad4;
}
.chat-message-bubble {
    max-width: min(720px, calc(100% - 60px));
    background: #fff;
    border: 1px solid #dce4ec;
    border-radius: 12px;
    padding: 12px 14px;
    box-shadow: 0 8px 18px rgba(31, 47, 70, 0.06);
}
.chat-message-row.is-own .chat-message-bubble {
    background: #eef6ff;
    border-color: #bfd8f3;
}
.chat-message-meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 6px;
    color: #6c7a89;
    font-size: 12px;
}
.chat-message-text {
    white-space: pre-wrap;
    word-break: break-word;
    color: #2a3f54;
}
.chat-composer {
    border-top: 1px solid #e6e9ed;
    padding: 16px 18px 18px;
    background: #fff;
}
.chat-empty-state {
    padding: 24px 18px;
    color: #7b8a9a;
    text-align: center;
}
@media (max-width: 991px) {
    .internal-chat-embed-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    .internal-chat-embed-actions {
        justify-content: flex-end;
    }
    .chat-messages {
        height: 50vh;
    }
}
</style>
<?php
if ($embed) {
    if (isInternalChatEnabled() && hasInternalChatTables()) {
        ?>
        <script>
        window.internalChatGlobalConfig = {
            enabled: true,
            userId: <?= (int) $userId; ?>,
            summaryUrl: <?= json_encode(BASE_URL . 'chat-interno-handler?action=summary', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            heartbeatUrl: <?= json_encode(BASE_URL . 'chat-interno-handler', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            chatUrl: <?= json_encode(BASE_URL . 'chat-interno', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            serviceWorkerUrl: <?= json_encode(BASE_URL . 'internal-chat-sw.js', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            csrfToken: <?= json_encode(generateCsrfToken(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            appName: <?= json_encode((string) getSetting('app_name', 'CMS'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        };
        </script>
        <?php
    }
    ?>
    <script src="vendors/jquery/dist/jquery.min.js"></script>
    <script src="vendors/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
    <?php if ($useSelect2): ?>
    <script src="vendors/select2/dist/js/select2.full.min.js"></script>
    <?php endif; ?>
    <script src="assets/js/pnotify_theme_adapter.js"></script>
    <script src="assets/js/custom.js"></script>
    <?php if (!empty($pageScripts)): ?>
    <script>
    <?= $pageScripts ?>
    </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
    return;
}

require_once __DIR__ . '/footer.php';
