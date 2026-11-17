/*
 * Registo de ponto (assiduidade): botao "Registar ..." da barra de topo e do
 * painel "Hoje" em assiduidade/registo.php, e o modal de confirmacao.
 * Porta do #relogioPonto da intranet legacy (header.php + data/utils.php
 * accaop=relogioponto). Configuracao em window.attendanceConfig (footer.php).
 * Ver docs/assiduidade.md.
 */
(function () {
    'use strict';

    var cfg = window.attendanceConfig || null;
    if (!cfg) {
        return;
    }

    var modalEl = document.getElementById('attendancePunchModal');
    var modal = null;
    var lastStatus = null;

    function notify(text, type) {
        if (typeof window.PNotify === 'function') {
            window.PNotify({ title: type === 'success' ? 'Registo de ponto' : 'Erro', text: text, type: type === 'success' ? 'success' : 'danger', delay: type === 'success' ? 3500 : 6000 });
        } else {
            window.alert(text);
        }
    }

    function getJson(url) {
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
    }

    function applyButton(btn, status) {
        if (!btn) {
            return;
        }
        if (!status || !status.tracked || !status.next) {
            btn.style.display = 'none';
            return;
        }
        btn.style.display = '';
        btn.classList.remove('btn-danger', 'btn-success');
        btn.classList.add(status.is_entry ? 'btn-danger' : 'btn-success');
        var label = btn.querySelector('.js-attendance-label');
        if (label) {
            label.textContent = 'Registar ' + status.next_label;
        }
        btn.setAttribute('title', 'Registar ' + status.next_label);
    }

    function renderToday(status) {
        var panel = document.getElementById('attendanceTodayPanel');
        if (!panel) {
            return;
        }
        applyButton(document.getElementById('attendanceTodayBtn'), status);
        var done = document.getElementById('attendanceTodayDone');
        if (done) {
            done.style.display = status && status.tracked && !status.next ? '' : 'none';
        }
        var list = document.getElementById('attendanceTodayPunches');
        if (list && status) {
            var parts = [];
            var labels = cfg.periods || {};
            Object.keys(status.punches || {}).forEach(function (p) {
                parts.push('<span class="badge bg-light text-dark border">' + (labels[p] || p) + ': <strong>' + status.punches[p] + '</strong></span>');
            });
            list.innerHTML = parts.length ? parts.join(' ') : '<span class="text-muted">Ainda sem registos hoje.</span>';
        }
    }

    function refresh() {
        return getJson(cfg.handlerUrl + '?action=status').then(function (status) {
            lastStatus = status;
            document.querySelectorAll('.js-attendance-topbar').forEach(function (btn) { applyButton(btn, status); });
            renderToday(status);
            return status;
        });
    }

    function openModal() {
        if (!modalEl) {
            return;
        }
        refresh().then(function (status) {
            if (!status.ok || !status.tracked) {
                notify(status.error || 'O seu utilizador não regista ponto.', 'error');
                return;
            }
            if (!status.next) {
                notify('Os registos de hoje já estão completos.', 'error');
                return;
            }
            var select = document.getElementById('attendancePunchPeriod');
            select.innerHTML = '';
            (status.available || []).forEach(function (p) {
                if (p < status.next) {
                    return;
                }
                var opt = document.createElement('option');
                opt.value = p;
                opt.textContent = status.available_labels[p];
                if (p === status.next) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
            if (!select.options.length) {
                var only = document.createElement('option');
                only.value = status.next;
                only.textContent = status.next_label;
                select.appendChild(only);
            }
            document.getElementById('attendancePunchTime').textContent = status.now;
            document.getElementById('attendancePunchSchedule').textContent = status.schedule_name ? 'Horário: ' + status.schedule_name + (status.reference ? ' · previsto ' + status.reference : '') : '';
            document.getElementById('attendancePunchError').style.display = 'none';
            document.getElementById('attendancePunchJustification').value = '';
            syncLate();
            if (!modal) {
                modal = new window.bootstrap.Modal(modalEl);
            }
            modal.show();
        }).catch(function () {
            notify('Não foi possível obter o estado do registo de ponto.', 'error');
        });
    }

    function syncLate() {
        var select = document.getElementById('attendancePunchPeriod');
        var lateBox = document.getElementById('attendancePunchLate');
        var text = document.getElementById('attendancePunchLateText');
        var field = document.getElementById('attendancePunchJustification');
        var isNext = lastStatus && parseInt(select.value, 10) === lastStatus.next;
        var late = isNext ? (lastStatus.late_minutes || 0) : 0;
        lateBox.style.display = late > 0 ? '' : 'none';
        field.required = !!(late > 0 && lastStatus.require_justification);
        if (late > 0) {
            var h = Math.floor(late / 60);
            var m = late % 60;
            text.textContent = 'Está a registar com ' + (h ? h + 'h' + ('0' + m).slice(-2) : m + ' min') + ' de atraso (previsto ' + lastStatus.reference + ').' + (lastStatus.require_justification ? ' Indique o motivo.' : '');
        }
    }

    function submitPunch(ev) {
        ev.preventDefault();
        var form = document.getElementById('attendancePunchForm');
        var errorBox = document.getElementById('attendancePunchError');
        var submit = form.querySelector('button[type="submit"]');
        if (!form.reportValidity()) {
            return;
        }
        submit.disabled = true;
        var body = new FormData(form);
        body.append('action', 'punch');
        body.append('csrf_token', cfg.csrfToken);
        fetch(cfg.handlerUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                submit.disabled = false;
                if (!data.ok) {
                    errorBox.textContent = data.error || 'Não foi possível registar.';
                    errorBox.style.display = '';
                    return;
                }
                if (modal) {
                    modal.hide();
                }
                notify(data.message, 'success');
                // No mapa mensal recarrega para atualizar a tabela do dia.
                if (document.getElementById('attendanceTodayPanel')) {
                    window.setTimeout(function () { window.location.reload(); }, 800);
                } else {
                    refresh();
                }
            })
            .catch(function () {
                submit.disabled = false;
                errorBox.textContent = 'Erro de comunicação. Tente novamente.';
                errorBox.style.display = '';
            });
    }

    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.js-attendance-punch');
        if (btn) {
            ev.preventDefault();
            openModal();
        }
    });

    if (modalEl) {
        document.getElementById('attendancePunchForm').addEventListener('submit', submitPunch);
        document.getElementById('attendancePunchPeriod').addEventListener('change', syncLate);
    }

    // Relogio do painel "Hoje" (registo.php).
    var clock = document.getElementById('attendanceClock');
    if (clock) {
        window.setInterval(function () {
            var d = new Date();
            clock.textContent = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
        }, 10000);
    }

    if (document.getElementById('attendanceTodayPanel')) {
        refresh();
    }
})();
