// Web Push deste browser/dispositivo (push/handler.php, push/webpush.php,
// service worker internal-chat-sw.js), em todas as paginas: os avisos do
// calendario chegam mesmo com o CRM fechado. A subscricao pertence ao
// utilizador. Liga-se no perfil (#push-switch) ou no botao "Ativar
// notificacoes" do dashboard; com a permissao ja dada e nao desligado aqui,
// a subscricao e renovada em silencio em cada pagina (chave nova, endpoint
// expirado). Porta de int.pombaldata.com/public/assets/js/push.js.
// API: window.AppPush. Ver docs/calendario.md, "Notificacoes push".
(function () {
    'use strict';

    var config = window.appPushConfig || {};
    var supported = !!(config.vapid && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window);
    // iPhone/iPad: o Safari so tem Web Push na app instalada no ecra principal.
    var ios = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var installed = window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    var active = false;
    var listeners = [];
    var OFF_KEY = 'push:off:' + String(config.userId || '');
    var appName = config.appName || 'a app';

    function vapidKey() {
        var b64 = config.vapid.replace(/-/g, '+').replace(/_/g, '/');
        var bin = window.atob(b64 + '='.repeat((4 - b64.length % 4) % 4));
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) { bytes[i] = bin.charCodeAt(i); }
        return bytes;
    }
    function b64(buffer) {
        var bin = String.fromCharCode.apply(null, new Uint8Array(buffer));
        return window.btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function sameKey(subscription) {
        var current = subscription.options && subscription.options.applicationServerKey;
        return !current || b64(current) === config.vapid;
    }
    function offHere() {
        try { return window.localStorage.getItem(OFF_KEY) === '1'; } catch (e) { return false; }
    }
    function setOffHere(off) {
        try {
            if (off) { window.localStorage.setItem(OFF_KEY, '1'); } else { window.localStorage.removeItem(OFF_KEY); }
        } catch (e) { /* sem storage: o interruptor funciona nesta pagina */ }
    }

    function post(action, data) {
        var body = new URLSearchParams(data || {});
        body.append('action', action);
        body.append('csrf_token', config.csrfToken || '');
        return window.fetch(config.handlerUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            body: body
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok || !payload || payload.ok !== true) {
                    throw new Error((payload && payload.error) || 'Falha no pedido.');
                }
                return payload;
            });
        });
    }

    function registration() {
        return navigator.serviceWorker.register(config.serviceWorkerUrl).then(function () {
            return navigator.serviceWorker.ready;
        });
    }

    // Subscreve este browser (permissao ja dada) e envia a subscricao ao servidor.
    function subscribe() {
        return registration().then(function (reg) {
            return reg.pushManager.getSubscription().then(function (current) {
                if (current && sameKey(current)) {
                    return current;
                }
                return (current ? current.unsubscribe() : Promise.resolve()).then(function () {
                    return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: vapidKey() });
                });
            });
        }).then(function (subscription) {
            var json = subscription.toJSON();
            return post('subscribe', { endpoint: json.endpoint, p256dh: json.keys.p256dh, auth: json.keys.auth });
        }).then(function () {
            active = true;
            notifyListeners();
        });
    }

    // Pede a permissao (precisa de um clique) e subscreve.
    function activate() {
        if (!supported) {
            return Promise.reject(new Error(ios && !installed
                ? 'No iPhone/iPad, adicione ' + appName + ' ao ecrã principal (Partilhar → Adicionar ao ecrã principal) e abra-o por esse ícone.'
                : 'Este browser não suporta notificações push.'));
        }
        return window.Notification.requestPermission().then(function (permission) {
            if (permission !== 'granted') {
                notifyListeners();
                throw new Error('As notificações foram recusadas no browser.');
            }
            setOffHere(false);
            return subscribe();
        });
    }

    // Remove a subscricao deste browser (servidor e browser).
    function deactivate() {
        setOffHere(true);
        if (!supported) {
            return Promise.resolve();
        }
        return navigator.serviceWorker.getRegistration(config.serviceWorkerUrl).then(function (reg) {
            return reg ? reg.pushManager.getSubscription() : null;
        }).then(function (subscription) {
            if (!subscription) {
                return null;
            }
            return post('unsubscribe', { endpoint: subscription.endpoint }).catch(function () { return null; }).then(function () {
                return subscription.unsubscribe();
            });
        }).then(function () {
            active = false;
            notifyListeners();
        });
    }

    // ------------------------------------------------ interruptor do perfil
    var toggle = document.getElementById('push-switch');
    var statusEl = document.getElementById('push-switch-status');
    var testButton = document.getElementById('push-test');
    var checking = supported && window.Notification.permission === 'granted' && !offHere();

    function renderToggle() {
        if (!toggle) {
            return;
        }
        var denied = supported && window.Notification.permission === 'denied';
        toggle.checked = active;
        toggle.disabled = !supported || denied || checking;
        if (testButton) {
            testButton.classList.toggle('d-none', !active);
        }
        if (!statusEl) {
            return;
        }
        var text = 'Recebe neste dispositivo os convites e lembretes do calendário, mesmo com ' + appName + ' fechado.';
        var cls = 'text-muted';
        if (!supported && ios && !installed) {
            text = 'No iPhone/iPad: no Safari, Partilhar → Adicionar ao ecrã principal, e abra ' + appName + ' por esse ícone para ligar as notificações (iOS 16.4 ou mais recente).';
            cls = 'text-warning';
        } else if (!supported) {
            text = 'Este browser não suporta notificações push.';
            cls = 'text-warning';
        } else if (denied) {
            text = 'As notificações estão bloqueadas nas definições do browser para este site: desbloqueie-as lá e volte a esta página.';
            cls = 'text-danger';
        } else if (active) {
            text = 'Ligadas neste dispositivo: recebe os convites e lembretes do calendário mesmo com ' + appName + ' fechado.';
            cls = 'text-success';
        }
        statusEl.textContent = text;
        statusEl.className = 'small mb-0 ' + cls;
    }

    function message(text, type) {
        if (typeof window.PNotify === 'function') {
            window.PNotify({ title: 'Notificações', text: text, type: type || 'danger', delay: 6000 });
        } else {
            window.alert(text);
        }
    }

    if (toggle) {
        toggle.addEventListener('change', function () {
            var on = toggle.checked;
            toggle.disabled = true;
            (on ? activate() : deactivate()).catch(function (e) {
                message('Não foi possível ' + (on ? 'ligar' : 'desligar') + ' as notificações: ' + e.message);
            }).then(notifyListeners);
        });
    }
    if (testButton) {
        testButton.addEventListener('click', function () {
            testButton.disabled = true;
            post('test').then(function (r) {
                message(r.sent > 0 ? 'Notificação de teste enviada para ' + r.sent + ' dispositivo(s).' : 'Nenhum dispositivo recebeu a notificação.', r.sent > 0 ? 'success' : 'warning');
            }).catch(function (e) {
                message(e.message);
            }).then(function () {
                testButton.disabled = false;
            });
        });
    }

    function notifyListeners() {
        renderToggle();
        listeners.forEach(function (fn) { fn(active); });
    }

    // Permissao ja dada (e nao desligado aqui): manter a subscricao em dia.
    if (checking) {
        subscribe().catch(function () { return null; }).then(function () {
            checking = false;
            notifyListeners();
        });
    }
    renderToggle();

    window.AppPush = {
        supported: supported,
        ios: ios,
        installed: installed,
        isActive: function () { return active; },
        isPending: function () { return checking; },
        activate: activate,
        deactivate: deactivate,
        onChange: function (fn) { listeners.push(fn); }
    };
})();
