self.addEventListener('install', function (event) {
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

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var targetUrl = '/';
    if (event.notification && event.notification.data && event.notification.data.url) {
        targetUrl = event.notification.data.url;
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clients) {
            for (var i = 0; i < clients.length; i += 1) {
                var client = clients[i];
                if ('focus' in client) {
                    if (client.url === targetUrl || client.url.indexOf(targetUrl) === 0) {
                        return client.focus();
                    }
                }
            }

            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl);
            }

            return null;
        })
    );
});
