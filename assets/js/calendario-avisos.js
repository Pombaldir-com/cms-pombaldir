// Avisos do calendario (calendario/handler.php?action=notices): convites,
// alteracoes de hora e lembretes antes do inicio, em todas as paginas.
// Cada aviso e entregue uma vez (o servidor marca-o ao devolve-lo), por isso
// so um separador o mostra. Aparece como notificacao no ecra (PNotify) e,
// com permissao do browser, como notificacao do sistema (service worker do
// chat interno, que abre o link ao clicar). Ver docs/calendario.md.
(function () {
    'use strict';

    var config = window.calendarNoticesConfig || null;
    if (!config || !config.url || !window.fetch) {
        return;
    }

    var intervalMs = 60000;
    var registration = null;
    var busy = false;

    function registerWorker() {
        if (!('serviceWorker' in navigator) || !config.serviceWorkerUrl) {
            return Promise.resolve(null);
        }
        return navigator.serviceWorker.register(config.serviceWorkerUrl)
            .then(function (reg) { registration = reg; return reg; })
            .catch(function () { return null; });
    }

    function systemNotification(notice) {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }
        // Com Web Push ligado neste dispositivo a notificacao do sistema chega
        // pelo push (assets/js/push.js): aqui fica so o aviso na pagina.
        if (window.AppPush && window.AppPush.isActive()) {
            return;
        }
        var options = {
            body: notice.body || '',
            tag: notice.tag || undefined,
            data: { url: notice.url || '' },
            requireInteraction: notice.kind === 'reminder'
        };
        if (registration && registration.showNotification) {
            registration.showNotification(notice.title, options).catch(function () {});
            return;
        }
        try {
            var n = new Notification(notice.title, options);
            n.onclick = function () {
                window.focus();
                if (notice.url) {
                    window.location.href = notice.url;
                }
                n.close();
            };
        } catch (e) {
            // Alguns browsers so aceitam notificacoes via service worker.
        }
    }

    function screenNotification(notice) {
        if (typeof window.PNotify === 'undefined') {
            return;
        }
        window.PNotify({
            title: notice.title,
            text: notice.body || '',
            type: notice.kind === 'reminder' ? 'warning' : 'info',
            hide: notice.kind !== 'reminder',
            delay: 10000
        });
    }

    function poll() {
        if (busy) {
            return;
        }
        busy = true;
        window.fetch(config.url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (payload) {
                if (!payload || payload.ok !== true || !Array.isArray(payload.notices)) {
                    return;
                }
                payload.notices.forEach(function (notice) {
                    screenNotification(notice);
                    systemNotification(notice);
                });
                if (payload.notices.length > 0 && window.calendarRefetch) {
                    window.calendarRefetch();
                }
            })
            .catch(function () {
                // Sem rede ou sessao terminada: tenta no proximo ciclo.
            })
            .then(function () { busy = false; });
    }

    // Pedido de permissao so depois de um gesto do utilizador (os browsers
    // bloqueiam pedidos automaticos): o botao "Ativar notificacoes" do dashboard.
    window.calendarNotices = {
        requestPermission: function () {
            if (!('Notification' in window)) {
                return Promise.resolve('unsupported');
            }
            if (Notification.permission !== 'default') {
                return Promise.resolve(Notification.permission);
            }
            return Notification.requestPermission();
        },
        permission: function () {
            return ('Notification' in window) ? Notification.permission : 'unsupported';
        },
        poll: poll
    };

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            poll();
        }
    });

    registerWorker().then(function () {
        poll();
        window.setInterval(poll, intervalMs);
    });
})();
