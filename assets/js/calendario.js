// Calendario do dashboard (FullCalendar 6 + calendario/handler.php).
// Clique num dia/horario cria um evento; o criador (ou um administrador)
// altera, arrasta e elimina; os convidados so veem. Ver docs/calendario.md.
(function () {
    'use strict';

    var config = {};
    try {
        config = JSON.parse((document.getElementById('dash-calendar-config') || {}).textContent || '{}') || {};
    } catch (e) {
        config = {};
    }
    if (!config.handlerUrl) {
        return;
    }

    var $ = window.jQuery;
    var calendar = null;
    var modal = null;
    var modalEl = document.getElementById('calendar-event-modal');
    var form = document.getElementById('calendar-event-form');
    var byId = function (id) { return document.getElementById(id); };
    var pad = function (n) { return String(n).padStart(2, '0'); };
    var day = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
    var hm = function (d) { return pad(d.getHours()) + ':' + pad(d.getMinutes()); };
    var dayTime = function (d) { return day(d) + 'T' + hm(d); };
    var scopeKey = 'calendar_scope:' + String(config.userId || '');
    var viewKey = 'calendar_view:' + String(config.userId || '');

    function storageGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function storageSet(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* ignorar */ }
    }

    function notify(type, text) {
        if (typeof window.PNotify === 'function') {
            window.PNotify({ title: type === 'success' ? 'Sucesso' : 'Erro', text: text, type: type === 'success' ? 'success' : 'danger', delay: type === 'success' ? 3000 : 6000 });
        } else if (type !== 'success') {
            window.alert(text);
        }
    }

    function request(action, method, data) {
        var url = config.handlerUrl + '?action=' + encodeURIComponent(action);
        var options = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (method === 'GET') {
            url += '&' + new URLSearchParams(data || {}).toString();
        } else {
            data.append('action', action);
            data.append('csrf_token', config.csrfToken || '');
            options.body = data;
        }
        return window.fetch(url, options).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'Resposta inválida do servidor.' };
            });
        }).then(function (payload) {
            if (!payload || payload.ok !== true) {
                throw new Error(payload && payload.error ? String(payload.error) : 'Não foi possível concluir o pedido.');
            }
            return payload;
        });
    }

    // ------------------------------------------------------------- modal
    var clientSelect = byId('cal-client');
    var membersSelect = byId('cal-members');

    function prepareModal() {
        if (modal || !modalEl) {
            return;
        }
        modal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
        (config.users || []).forEach(function (u) {
            var option = document.createElement('option');
            option.value = String(u.id);
            option.textContent = u.name + (u.username && u.username !== u.name ? ' (' + u.username + ')' : '');
            membersSelect.appendChild(option);
        });
        var reminder = byId('cal-reminder');
        (config.reminders || []).forEach(function (r) {
            var option = document.createElement('option');
            option.value = String(r.value);
            option.textContent = r.label;
            reminder.appendChild(option);
        });
        if ($ && $.fn && $.fn.select2) {
            $(membersSelect).select2({
                width: '100%',
                dropdownParent: $(modalEl),
                placeholder: 'Escolher utilizadores',
                language: { noResults: function () { return 'Sem resultados'; } }
            });
            if (clientSelect) {
                $(clientSelect).select2({
                    width: '100%',
                    dropdownParent: $(modalEl),
                    allowClear: true,
                    placeholder: 'Pesquisar por nome, NIF ou código',
                    minimumInputLength: 0,
                    language: {
                        noResults: function () { return 'Nenhum cliente encontrado'; },
                        searching: function () { return 'A pesquisar…'; },
                        inputTooShort: function () { return 'Escreva para pesquisar'; }
                    },
                    ajax: {
                        url: config.handlerUrl,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) { return { action: 'clients', q: params.term || '' }; },
                        processResults: function (data) { return { results: (data && data.results) || [] }; }
                    }
                });
                $(clientSelect).on('select2:select select2:clear', function () {
                    byId('cal-client-link').classList.add('d-none');
                });
            }
        }
        byId('cal-all-day').addEventListener('change', function () {
            setAllDay(this.checked);
        });
        form.addEventListener('submit', save);
        byId('cal-delete').addEventListener('click', remove);
    }

    // Troca os campos de data entre date (dia inteiro) e datetime-local.
    function setAllDay(allDay) {
        [['cal-start', '09:00'], ['cal-end', '10:00']].forEach(function (pair) {
            var input = byId(pair[0]);
            var value = input.value;
            input.type = allDay ? 'date' : 'datetime-local';
            input.value = value === '' ? '' : (allDay ? value.slice(0, 10) : value.slice(0, 10) + 'T' + (value.length > 10 ? value.slice(11, 16) : pair[1]));
        });
    }

    function setClient(client) {
        if (!clientSelect) {
            return;
        }
        var link = byId('cal-client-link');
        $(clientSelect).empty();
        if (client && (client.id || client.name)) {
            var label = client.name + (client.nif ? ' (NIF ' + client.nif + ')' : '');
            // Sem id (entidade apagada) mostra-se o nome guardado, sem valor.
            var option = new Option(label, client.id ? String(client.id) : '', true, true);
            clientSelect.appendChild(option);
        }
        $(clientSelect).trigger('change');
        if (client && client.url) {
            link.href = client.url;
            link.classList.remove('d-none');
        } else {
            link.classList.add('d-none');
        }
    }

    function openModal(event) {
        prepareModal();
        if (!modal) {
            return;
        }
        var canEdit = event.can_edit !== false;
        byId('calendar-event-modal-title').textContent = event.uuid ? (canEdit ? 'Editar evento' : 'Evento') : 'Novo evento';
        byId('cal-uuid').value = event.uuid || '';
        byId('cal-title').value = event.title || '';
        byId('cal-all-day').checked = !!event.all_day;
        byId('cal-start').type = event.all_day ? 'date' : 'datetime-local';
        byId('cal-end').type = event.all_day ? 'date' : 'datetime-local';
        byId('cal-start').value = event.start || '';
        byId('cal-end').value = event.end || '';
        byId('cal-location').value = event.location || '';
        byId('cal-description').value = event.description || '';
        byId('cal-reminder').value = String(event.reminder_minutes !== undefined ? event.reminder_minutes : (config.reminderDefault || 60));
        var chosen = (event.members || []).map(String);
        Array.prototype.forEach.call(membersSelect.options, function (option) {
            option.selected = chosen.indexOf(option.value) !== -1;
        });
        if ($ && $.fn && $.fn.select2) {
            $(membersSelect).trigger('change');
        }
        setClient(event.client || null);

        Array.prototype.forEach.call(form.querySelectorAll('input, select, textarea'), function (el) {
            if (el.type !== 'hidden') {
                el.disabled = !canEdit;
            }
        });
        byId('cal-save').classList.toggle('d-none', !canEdit);
        byId('cal-delete').classList.toggle('d-none', !canEdit || !event.uuid);

        var info = [];
        if (event.uuid && event.creator_name && String(event.creator) !== String(config.userId)) {
            info.push('Criado por ' + event.creator_name + '.');
        }
        if (!canEdit && chosen.length > 0) {
            var names = chosen.map(function (id) {
                var u = (config.users || []).filter(function (x) { return String(x.id) === id; })[0];
                return u ? u.name : '';
            }).filter(Boolean);
            if (names.length > 0) {
                info.push('Convidados: ' + names.join(', ') + '.');
            }
        }
        var infoEl = byId('cal-info');
        infoEl.textContent = info.join(' ');
        infoEl.classList.toggle('d-none', info.length === 0);

        modal.show();
        if (canEdit && !event.uuid) {
            modalEl.addEventListener('shown.bs.modal', function focus() {
                modalEl.removeEventListener('shown.bs.modal', focus);
                byId('cal-title').focus();
            });
        }
    }

    function openEvent(uuid, goTo) {
        request('view', 'GET', { uuid: uuid }).then(function (payload) {
            if (goTo && calendar && payload.event.start) {
                calendar.gotoDate(payload.event.start.slice(0, 10));
            }
            openModal(payload.event);
        }).catch(function (error) {
            notify('error', error.message);
        });
    }

    // Novo evento: um dia na vista Mes fica 09:00-10:00; varios dias, dia
    // inteiro; um horario da vista Semana/Dia, 1 hora.
    function newEvent(info) {
        var event = { reminder_minutes: config.reminderDefault };
        if (!info) {
            var now = new Date();
            now.setMinutes(0, 0, 0);
            now.setHours(now.getHours() + 1);
            event.start = dayTime(now);
            event.end = dayTime(new Date(now.getTime() + 3600000));
        } else if (info.allDay) {
            var last = new Date(info.end.getTime() - 86400000);
            if (info.view.type === 'dayGridMonth' && day(last) === day(info.start)) {
                event.start = day(info.start) + 'T09:00';
                event.end = day(info.start) + 'T10:00';
            } else {
                event.all_day = true;
                event.start = day(info.start);
                event.end = day(last) !== day(info.start) ? day(last) : '';
            }
        } else {
            var end = info.end.getTime() - info.start.getTime() <= 1800000 ? new Date(info.start.getTime() + 3600000) : info.end;
            event.start = dayTime(info.start);
            event.end = dayTime(end);
        }
        openModal(event);
    }

    function save(e) {
        e.preventDefault();
        if (byId('cal-title').value.trim() === '' || byId('cal-start').value === '') {
            notify('error', byId('cal-title').value.trim() === '' ? 'Indique o título do evento.' : 'Indique a data de início.');
            return;
        }
        var button = byId('cal-save');
        button.disabled = true;
        request('save', 'POST', new FormData(form)).then(function (payload) {
            modal.hide();
            notify('success', payload.message || 'Evento gravado.');
            refetch();
        }).catch(function (error) {
            notify('error', error.message);
        }).then(function () {
            button.disabled = false;
        });
    }

    function remove() {
        var uuid = byId('cal-uuid').value;
        if (!uuid || !window.confirm('Eliminar o evento? Sai também do calendário dos convidados.')) {
            return;
        }
        var data = new FormData();
        data.append('uuid', uuid);
        request('delete', 'POST', data).then(function (payload) {
            modal.hide();
            notify('success', payload.message || 'Evento eliminado.');
            refetch();
        }).catch(function (error) {
            notify('error', error.message);
        });
    }

    // Arrastar / redimensionar: so mudam as datas.
    function move(info) {
        var ev = info.event;
        var data = new FormData();
        data.append('uuid', ev.extendedProps.uuid);
        data.append('all_day', ev.allDay ? '1' : '0');
        if (ev.allDay) {
            data.append('start', day(ev.start));
            data.append('end', ev.end ? day(new Date(ev.end.getTime() - 86400000)) : '');
        } else {
            data.append('start', dayTime(ev.start));
            data.append('end', ev.end ? dayTime(ev.end) : dayTime(new Date(ev.start.getTime() + 3600000)));
        }
        request('move', 'POST', data).then(function (payload) {
            notify('success', payload.message || 'Evento movido.');
        }).catch(function (error) {
            info.revert();
            notify('error', error.message);
        });
    }

    // A lista "Proximos eventos" e gerada no servidor: recarregar a pagina
    // seria pesado, por isso so o calendario e atualizado.
    function refetch() {
        if (calendar) {
            calendar.refetchEvents();
        }
    }
    window.calendarRefetch = refetch;

    function currentScope() {
        var select = byId('dash-cal-scope');
        return select ? select.value : 'mine';
    }

    // ------------------------------------------------------------- FullCalendar
    function boot() {
        var el = byId('dash-calendar');
        if (!el || typeof window.FullCalendar === 'undefined') {
            return;
        }
        var isPhone = window.matchMedia('(max-width: 767.98px)').matches;
        var views = ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek'];
        var savedView = storageGet(viewKey);
        var scopeSelect = byId('dash-cal-scope');
        if (scopeSelect && storageGet(scopeKey) === 'all') {
            scopeSelect.value = 'all';
        }

        calendar = new window.FullCalendar.Calendar(el, {
            locale: 'pt',
            timeZone: 'local',
            initialView: isPhone ? 'listWeek' : (views.indexOf(savedView) !== -1 ? savedView : 'dayGridMonth'),
            firstDay: 1,
            height: isPhone ? 'auto' : 720,
            nowIndicator: true,
            navLinks: true,
            dayMaxEvents: true,
            eventDisplay: 'block',
            slotMinTime: '07:00:00',
            slotMaxTime: '21:00:00',
            scrollTime: '08:00:00',
            allDayText: 'Dia',
            headerToolbar: isPhone
                ? { left: 'prev,next', center: 'title', right: 'today' }
                : { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
            footerToolbar: isPhone ? { center: 'listWeek,dayGridMonth,timeGridDay' } : false,
            buttonText: { today: 'Hoje', month: 'Mês', week: 'Semana', day: 'Dia', list: 'Lista' },
            noEventsText: 'Sem eventos.',
            eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            events: function (fetchInfo, success, failure) {
                request('feed', 'GET', { start: fetchInfo.startStr.slice(0, 10), end: fetchInfo.endStr.slice(0, 10), scope: currentScope() })
                    .then(function (payload) { success(payload.events || []); })
                    .catch(function (error) {
                        notify('error', error.message);
                        failure(error);
                    });
            },
            selectable: true,
            selectMirror: true,
            selectLongPressDelay: 400,
            select: function (info) {
                calendar.unselect();
                newEvent(info);
            },
            eventDrop: move,
            eventResize: move,
            eventClick: function (info) {
                info.jsEvent.preventDefault();
                openEvent(info.event.extendedProps.uuid, false);
            },
            eventDidMount: function (info) {
                var p = info.event.extendedProps || {};
                var hint = [];
                if (p.client) { hint.push('Cliente: ' + p.client); }
                if (p.location) { hint.push('Local: ' + p.location); }
                if (p.kind !== 'own' && p.creator) { hint.push('Criado por: ' + p.creator); }
                if (p.members > 0) { hint.push(p.members + (p.members === 1 ? ' convidado' : ' convidados')); }
                if (p.detail) { hint.push(p.detail); }
                if (hint.length > 0) {
                    info.el.setAttribute('title', hint.join(' | '));
                }
            },
            datesSet: function (arg) {
                if (!isPhone) {
                    storageSet(viewKey, arg.view.type);
                }
            }
        });
        calendar.render();

        if (scopeSelect) {
            scopeSelect.addEventListener('change', function () {
                storageSet(scopeKey, scopeSelect.value);
                refetch();
            });
        }
        if (config.open) {
            openEvent(config.open, true);
        }
    }

    var newButton = byId('dash-cal-new');
    if (newButton) {
        newButton.addEventListener('click', function () { newEvent(null); });
    }

    // "Proximos eventos": abre no modal em vez de recarregar a pagina.
    Array.prototype.forEach.call(document.querySelectorAll('[data-cal-open]'), function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            openEvent(link.getAttribute('data-cal-open'), true);
        });
    });

    // Notificacoes: com Web Push (assets/js/push.js) chegam mesmo com a app
    // fechada; sem ele, so a permissao do browser (calendario-avisos.js).
    var notifyButton = byId('dash-cal-notify');
    var push = window.AppPush || null;
    function refreshNotifyButton() {
        if (!notifyButton) {
            return;
        }
        var show;
        if (push && (push.supported || (push.ios && !push.installed))) {
            show = !push.isActive() && !push.isPending() && !(push.supported && window.Notification.permission === 'denied');
        } else {
            show = !!window.calendarNotices && window.calendarNotices.permission() === 'default';
        }
        notifyButton.classList.toggle('d-none', !show);
    }
    if (push) {
        push.onChange(refreshNotifyButton);
    }
    if (notifyButton) {
        notifyButton.addEventListener('click', function () {
            if (push && (push.supported || push.ios)) {
                push.activate().then(function () {
                    notify('success', 'Notificações ativas neste dispositivo: os convites e lembretes chegam mesmo com a app fechada.');
                }).catch(function (error) {
                    notify('error', error.message);
                }).then(refreshNotifyButton);
                return;
            }
            window.calendarNotices.requestPermission().then(function (permission) {
                refreshNotifyButton();
                if (permission === 'granted') {
                    notify('success', 'Notificações ativas: os lembretes aparecem mesmo com o separador em segundo plano.');
                }
            });
        });
    }

    function start() {
        boot();
        refreshNotifyButton();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
