(function(window, document) {
    'use strict';

    if (window.PNotify && (typeof window.PNotify === 'function' || typeof window.PNotify.alert === 'function')) {
        return;
    }

    function assign(target) {
        if (typeof Object.assign === 'function') {
            return Object.assign.apply(Object, arguments);
        }
        if (target == null) {
            throw new TypeError('Cannot convert undefined or null to object');
        }
        var to = Object(target);
        for (var index = 1; index < arguments.length; index += 1) {
            var nextSource = arguments[index];
            if (nextSource != null) {
                for (var nextKey in nextSource) {
                    if (Object.prototype.hasOwnProperty.call(nextSource, nextKey)) {
                        to[nextKey] = nextSource[nextKey];
                    }
                }
            }
        }
        return to;
    }

    function ensureContainer() {
        var container = document.querySelector('.custom-notifications');
        if (!container) {
            container = document.createElement('div');
            container.className = 'custom-notifications';
            document.body.appendChild(container);
        }
        return container;
    }

    function normalizeType(type) {
        var base = (type || 'info').toLowerCase();
        switch (base) {
            case 'error':
            case 'danger':
                return 'danger';
            case 'success':
                return 'success';
            case 'notice':
            case 'info':
                return 'info';
            case 'warning':
                return 'warning';
            default:
                return 'info';
        }
    }

    function toText(value) {
        if (value == null) {
            return '';
        }
        if (typeof value === 'string') {
            return value;
        }
        return String(value);
    }

    function removeNotice(root) {
        if (!root || !root.parentNode) {
            return;
        }
        root.classList.add('closing');
        setTimeout(function() {
            if (root.parentNode) {
                root.parentNode.removeChild(root);
            }
        }, 150);
    }

    function buildContent(options) {
        var container = ensureContainer();
        var wrapper = document.createElement('div');
        wrapper.className = 'ui-pnotify';

        var alertBox = document.createElement('div');
        var typeClass = normalizeType(options.type);
        var alertClasses = ['alert', 'alert-' + typeClass, 'alert-dismissible'];
        if (options.addclass) {
            alertClasses.push(options.addclass);
        }
        alertBox.className = alertClasses.join(' ');
        alertBox.setAttribute('role', 'alert');

        var closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'btn-close';
        closeButton.setAttribute('aria-label', 'Fechar notificação');
        closeButton.addEventListener('click', function() {
            removeNotice(wrapper);
        });
        alertBox.appendChild(closeButton);

        var title = toText(options.title).trim();
        if (title !== '') {
            var strong = document.createElement('strong');
            strong.textContent = title;
            alertBox.appendChild(strong);
            alertBox.appendChild(document.createTextNode(' '));
        }

        var text = toText(options.text).trim();
        if (text !== '') {
            var span = document.createElement('span');
            span.textContent = text;
            alertBox.appendChild(span);
        }

        wrapper.appendChild(alertBox);
        container.appendChild(wrapper);

        var hide = options.hide;
        if (typeof hide === 'undefined') {
            hide = true;
        }

        if (hide) {
            var delay = typeof options.delay === 'number' ? options.delay : 5000;
            if (delay > 0) {
                setTimeout(function() {
                    removeNotice(wrapper);
                }, delay);
            }
        }

        return {
            element: wrapper,
            update: function(newOptions) {
                var merged = assign({}, options, newOptions || {});
                alertBox.className = ['alert', 'alert-' + normalizeType(merged.type), 'alert-dismissible'].join(' ');
                var newTitle = toText(merged.title).trim();
                var newText = toText(merged.text).trim();
                alertBox.innerHTML = '';
                var newClose = closeButton.cloneNode(true);
                newClose.addEventListener('click', function() {
                    removeNotice(wrapper);
                });
                alertBox.appendChild(newClose);
                if (newTitle !== '') {
                    var newStrong = document.createElement('strong');
                    newStrong.textContent = newTitle;
                    alertBox.appendChild(newStrong);
                    alertBox.appendChild(document.createTextNode(' '));
                }
                if (newText !== '') {
                    var newSpan = document.createElement('span');
                    newSpan.textContent = newText;
                    alertBox.appendChild(newSpan);
                }
                options = merged;
            },
            remove: function() {
                removeNotice(wrapper);
            }
        };
    }

    function display(options) {
        var config = assign({
            type: 'info',
            hide: true,
            delay: 5000
        }, options || {});
        return buildContent(config);
    }

    function PNotifyFactory(options) {
        return display(options);
    }

    PNotifyFactory.alert = function(options) {
        return display(options);
    };

    PNotifyFactory.notice = function(options) {
        return display(assign({ type: 'info' }, options));
    };

    PNotifyFactory.info = function(options) {
        return display(assign({ type: 'info' }, options));
    };

    PNotifyFactory.success = function(options) {
        return display(assign({ type: 'success' }, options));
    };

    PNotifyFactory.error = function(options) {
        return display(assign({ type: 'danger' }, options));
    };

    window.PNotify = PNotifyFactory;
})(window, document);
