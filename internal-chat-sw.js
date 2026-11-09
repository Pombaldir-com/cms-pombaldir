// Service worker da aplicacao (scope = raiz da app). Sem cache.
// - message SHOW_NOTIFICATION: notificacoes pedidas pela pagina aberta
//   (chat interno em custom.js, avisos do calendario em calendario-avisos.js).
// - push: Web Push vindo do servidor (push/webpush.php), mesmo com o CRM
//   fechado: {title, body, tag, url, icon} (icon = logotipo da empresa). Ver docs/calendario.md.
// - notificationclick: abre o URL numa janela da app ja aberta ou numa nova.
// O nome do ficheiro mantem-se para nao perder as instalacoes existentes.

var APP_BASE = new URL('./', self.location.href).href;

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('message', function (event) {
    var data = event.data || {};
    if (data.type !== 'SHOW_NOTIFICATION') {
        return;
    }

    var title = data.title || 'Nova mensagem';
    var options = data.options || {};
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('push', function (event) {
    var data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(data.title || 'Aviso', {
        body: data.body || '',
        tag: data.tag || 'crm',
        renotify: true,
        icon: data.icon || (APP_BASE + 'assets/images/app-icon-192.png'),
        badge: APP_BASE + 'assets/images/app-badge-72.png',
        data: { url: data.url || (APP_BASE + 'dashboard') }
    }));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var target = (event.notification && event.notification.data && event.notification.data.url) || (APP_BASE + 'dashboard');
    var targetUrl = new URL(target, self.location.origin).href;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
            for (var i = 0; i < clients.length; i += 1) {
                var client = clients[i];
                if (client.url === targetUrl && 'focus' in client) {
                    return client.focus();
                }
            }
            // Uma janela da app ja aberta navega para o link (na app instalada
            // evita abrir uma segunda janela).
            for (var j = 0; j < clients.length; j += 1) {
                var other = clients[j];
                if (other.url.indexOf(APP_BASE) === 0 && 'navigate' in other) {
                    return other.navigate(targetUrl).then(function (c) { return c ? c.focus() : null; });
                }
            }
            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }
            return null;
        })
    );
});
