/**
 * Resize function without multiple trigger
 * 
 * Usage:
 * $(window).smartresize(function(){  
 *     // code here
 * });
 */
(function($, sr) {
    // debouncing function from John Hann
    // http://unscriptable.com/index.php/2009/03/20/debouncing-javascript-methods/
    var debounce = function(func, threshold, execAsap) {
        var timeout;

        return function debounced() {
            var obj = this,
                args = arguments;

            function delayed() {
                if (!execAsap)
                    func.apply(obj, args);
                timeout = null;
            }

            if (timeout)
                clearTimeout(timeout);
            else if (execAsap)
                func.apply(obj, args);

            timeout = setTimeout(delayed, threshold || 100);
        };
    };

    // smartresize 
    jQuery.fn[sr] = function(fn) { return fn ? this.bind('resize', debounce(fn)) : this.trigger(sr); };

})(jQuery, 'smartresize');
/**
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

var CURRENT_URL = window.location.href.split('#')[0].split('?')[0],
    $BODY = $('body'),
    $MENU_TOGGLE = $('#menu_toggle'),
    $SIDEBAR_MENU = $('#sidebar-menu'),
    $SIDEBAR_FOOTER = $('.sidebar-footer'),
    $LEFT_COL = $('.left_col'),
    $RIGHT_COL = $('.right_col'),
    $NAV_MENU = $('.nav_menu'),
    $FOOTER = $('footer');

function normalizeMenuUrl(url) {
    var cleanUrl = (url || '').split('#')[0].split('?')[0].replace(/\/+$/, '');
    if (cleanUrl !== '' && cleanUrl.indexOf('/contabilidade/entidades/') !== -1) {
        return cleanUrl.replace(/\/contabilidade\/entidades\/[^\/]+\/\d+$/i, '/contabilidade/entidades/empresas');
    }
    return cleanUrl;
}

// Sidebar
$(document).ready(function() {
    // TODO: This is some kind of easy fix, maybe we can improve this
    var setContentHeight = function() {
        // reset height
        $RIGHT_COL.css('min-height', $(window).height());

        var bodyHeight = $BODY.outerHeight(),
            footerHeight = $BODY.hasClass('footer_fixed') ? -10 : $FOOTER.height(),
            leftColHeight = $LEFT_COL.eq(1).height() + $SIDEBAR_FOOTER.height(),
            contentHeight = bodyHeight < leftColHeight ? leftColHeight : bodyHeight;

        // normalize content
        contentHeight -= $NAV_MENU.height() + footerHeight;

        $RIGHT_COL.css('min-height', contentHeight);
    };

    $SIDEBAR_MENU.find('a').on('click', function(ev) {
        var $li = $(this).parent();

        if ($li.is('.active')) {
            $li.removeClass('active active-sm');
            $('ul:first', $li).slideUp(function() {
                setContentHeight();
            });
        } else {
            // prevent closing menu if we are on child menu
            if (!$li.parent().is('.child_menu')) {
                $SIDEBAR_MENU.find('li').removeClass('active active-sm');
                $SIDEBAR_MENU.find('li ul').slideUp();
            }

            $li.addClass('active');

            $('ul:first', $li).slideDown(function() {
                setContentHeight();
            });
        }
    });

    // toggle small or large menu
    $MENU_TOGGLE.on('click', function() {
        if ($BODY.hasClass('nav-md')) {
            $SIDEBAR_MENU.find('li.active ul').hide();
            $SIDEBAR_MENU.find('li.active').addClass('active-sm').removeClass('active');
        } else {
            $SIDEBAR_MENU.find('li.active-sm ul').show();
            $SIDEBAR_MENU.find('li.active-sm').addClass('active').removeClass('active-sm');
        }

        $BODY.toggleClass('nav-md nav-sm');

        if (window.localStorage) {
            localStorage.setItem('sidebar_state', $BODY.hasClass('nav-sm') ? 'collapsed' : 'expanded');
        }

        setContentHeight();
    });

    // restore sidebar state
    if (window.localStorage) {
        var sidebarState = localStorage.getItem('sidebar_state');
        if (sidebarState === 'collapsed' && $BODY.hasClass('nav-md')) {
            $SIDEBAR_MENU.find('li.active ul').hide();
            $SIDEBAR_MENU.find('li.active').addClass('active-sm').removeClass('active');
            $BODY.removeClass('nav-md').addClass('nav-sm');
        } else if (sidebarState === 'expanded' && $BODY.hasClass('nav-sm')) {
            $BODY.removeClass('nav-sm').addClass('nav-md');
        }
    }

    // check active menu
    var normalizedCurrentUrl = normalizeMenuUrl(CURRENT_URL);
    $SIDEBAR_MENU.find('a').filter(function() {
        var href = normalizeMenuUrl(this.href);
        return href !== '' && href === normalizedCurrentUrl;
    }).parent('li').addClass('current-page');

    $SIDEBAR_MENU.find('a').filter(function() {
        var href = normalizeMenuUrl(this.href);
        return href !== '' && href === normalizedCurrentUrl;
    }).parent('li').addClass('current-page').parents('ul').slideDown(function() {
        setContentHeight();
    }).parent().addClass('active');

    // recompute content when resizing
    $(window).smartresize(function() {
        setContentHeight();
    });

    setContentHeight();

    // fixed sidebar
    if ($.fn.mCustomScrollbar) {
        $('.menu_fixed').mCustomScrollbar({
            autoHideScrollbar: true,
            theme: 'minimal',
            mouseWheel: { preventDefault: true }
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    var selectors = ['#songInfoModal', '#songinfoModal', '.songinfo-modal'];
    var modalElements = [];

    selectors.forEach(function(selector) {
        var matches = document.querySelectorAll(selector);
        if (!matches || !matches.length) {
            return;
        }
        matches.forEach(function(match) {
            if (modalElements.indexOf(match) === -1) {
                modalElements.push(match);
            }
        });
    });

    if (!modalElements.length) {
        return;
    }

    function applyCentered(modalEl) {
        if (!modalEl) {
            return;
        }

        if (!modalEl.classList.contains('songinfo-modal')) {
            modalEl.classList.add('songinfo-modal');
        }

        var dialog = modalEl.querySelector('.modal-dialog');
        if (!dialog) {
            return;
        }

        if (!dialog.classList.contains('songinfo-dialog')) {
            dialog.classList.add('songinfo-dialog');
        }
    }

    function moveModalToBody(modalEl) {
        if (!modalEl || modalEl.parentElement === document.body) {
            return;
        }

        modalEl.__songinfoOriginalParent = modalEl.parentElement;
        modalEl.__songinfoOriginalNextSibling = modalEl.nextSibling;
        document.body.appendChild(modalEl);
    }

    function restoreModalParent(modalEl) {
        if (!modalEl || !modalEl.__songinfoOriginalParent) {
            return;
        }

        var parent = modalEl.__songinfoOriginalParent;
        var sibling = modalEl.__songinfoOriginalNextSibling;

        if (!parent.isConnected) {
            document.body.appendChild(modalEl);
        } else if (sibling && parent.contains(sibling)) {
            parent.insertBefore(modalEl, sibling);
        } else {
            parent.appendChild(modalEl);
        }

        modalEl.__songinfoOriginalParent = null;
        modalEl.__songinfoOriginalNextSibling = null;
    }

    function getViewportHeight() {
        if (window.visualViewport && typeof window.visualViewport.height === 'number') {
            return window.visualViewport.height;
        }

        return window.innerHeight;
    }

    function updateViewportHeight(modalEl) {
        if (!modalEl) {
            return;
        }

        var height = getViewportHeight();

        if (height && height > 0) {
            modalEl.style.setProperty('--songinfo-viewport-height', height + 'px');
        } else {
            modalEl.style.removeProperty('--songinfo-viewport-height');
        }
    }

    function registerViewportUpdates(modalEl) {
        if (!modalEl || modalEl.__songinfoViewportHandler) {
            return;
        }

        var handler = function() {
            updateViewportHeight(modalEl);
        };

        if (window.visualViewport && typeof window.visualViewport.addEventListener === 'function') {
            window.visualViewport.addEventListener('resize', handler);
            window.visualViewport.addEventListener('scroll', handler);
            modalEl.__songinfoViewportHandlerTarget = 'visualViewport';
        } else {
            window.addEventListener('resize', handler);
            window.addEventListener('orientationchange', handler);
            modalEl.__songinfoViewportHandlerTarget = 'window';
        }

        modalEl.__songinfoViewportHandler = handler;
    }

    function unregisterViewportUpdates(modalEl) {
        if (!modalEl || !modalEl.__songinfoViewportHandler) {
            return;
        }

        var handler = modalEl.__songinfoViewportHandler;

        if (modalEl.__songinfoViewportHandlerTarget === 'visualViewport' && window.visualViewport && typeof window.visualViewport.removeEventListener === 'function') {
            window.visualViewport.removeEventListener('resize', handler);
            window.visualViewport.removeEventListener('scroll', handler);
        } else {
            window.removeEventListener('resize', handler);
            window.removeEventListener('orientationchange', handler);
        }

        modalEl.__songinfoViewportHandler = null;
        modalEl.__songinfoViewportHandlerTarget = null;
    }

    modalElements.forEach(function(modalEl) {
        modalEl.addEventListener('show.bs.modal', function() {
            moveModalToBody(modalEl);
            applyCentered(modalEl);
            updateViewportHeight(modalEl);
            registerViewportUpdates(modalEl);
            modalEl.scrollTop = 0;
        });

        modalEl.addEventListener('shown.bs.modal', function() {
            updateViewportHeight(modalEl);
            modalEl.scrollTop = 0;
        });

        modalEl.addEventListener('hidden.bs.modal', function() {
            unregisterViewportUpdates(modalEl);
            modalEl.style.removeProperty('--songinfo-viewport-height');
            restoreModalParent(modalEl);
        });

        applyCentered(modalEl);
    });
});
// /Sidebar

// Panel toolbox
$(document).ready(function() {
    $('.collapse-link').on('click', function() {
        var $BOX_PANEL = $(this).closest('.x_panel'),
            $ICON = $(this).find('i'),
            $BOX_CONTENT = $BOX_PANEL.find('.x_content');

        // fix for some div with hardcoded fix class
        if ($BOX_PANEL.attr('style')) {
            $BOX_CONTENT.slideToggle(200, function() {
                $BOX_PANEL.removeAttr('style');
            });
        } else {
            $BOX_CONTENT.slideToggle(200);
            $BOX_PANEL.css('height', 'auto');
        }

        $ICON.toggleClass('fa-chevron-up fa-chevron-down');
    });

    $('.close-link').click(function() {
        var $BOX_PANEL = $(this).closest('.x_panel');

        $BOX_PANEL.remove();
    });
});

$(document).ready(function() {

    function toggleApiSettings() {
        var enabled = $('#api_enabled').is(':checked');
        $('#api-settings').toggle(enabled);
    }
    toggleApiSettings();
    $('#api_enabled').on('change', toggleApiSettings);
    $('#generate_token').on('click', function () {
        if (window.crypto && window.crypto.getRandomValues) {
            var array = new Uint8Array(20);
            window.crypto.getRandomValues(array);
            var token = Array.from(array, function (b) {
                return ('00' + b.toString(16)).slice(-2);
            }).join('');
            $('#api_token').val(token);
        } else {
            $('#api_token').val(Math.random().toString(36).substring(2));
        }
    });
});
// /Panel toolbox

// Tooltip
$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip({
        container: 'body'
    });
});
// /Tooltip

// Progressbar
if ($(".progress .progress-bar")[0]) {
    $('.progress .progress-bar').progressbar();
}
// /Progressbar

// Switchery
$(document).ready(function() {
    if ($(".js-switch")[0]) {
        var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
        elems.forEach(function(html) {
            var switchery = new Switchery(html, {
                color: '#26B99A'
            });
        });
    }
});
// /Switchery

// iCheck
$(document).ready(function() {
    if ($("input.flat")[0]) {
        $(document).ready(function() {
            $('input.flat').iCheck({
                checkboxClass: 'icheckbox_flat-green',
                radioClass: 'iradio_flat-green'
            });
        });
    }
});
// /iCheck

// Table
$('table input').on('ifChecked', function() {
    checkState = '';
    $(this).parent().parent().parent().addClass('selected');
    countChecked();
});
$('table input').on('ifUnchecked', function() {
    checkState = '';
    $(this).parent().parent().parent().removeClass('selected');
    countChecked();
});

var checkState = '';

$('.bulk_action input').on('ifChecked', function() {
    checkState = '';
    $(this).parent().parent().parent().addClass('selected');
    countChecked();
});
$('.bulk_action input').on('ifUnchecked', function() {
    checkState = '';
    $(this).parent().parent().parent().removeClass('selected');
    countChecked();
});
$('.bulk_action input#check-all').on('ifChecked', function() {
    checkState = 'all';
    countChecked();
});
$('.bulk_action input#check-all').on('ifUnchecked', function() {
    checkState = 'none';
    countChecked();
});

function countChecked() {
    if (checkState === 'all') {
        $(".bulk_action input[name='table_records']").iCheck('check');
    }
    if (checkState === 'none') {
        $(".bulk_action input[name='table_records']").iCheck('uncheck');
    }

    var checkCount = $(".bulk_action input[name='table_records']:checked").length;

    if (checkCount) {
        $('.column-title').hide();
        $('.bulk-actions').show();
        $('.action-cnt').html(checkCount + ' Records Selected');
    } else {
        $('.column-title').show();
        $('.bulk-actions').hide();
    }
}

// Accordion
$(document).ready(function() {
    $(".expand").on("click", function() {
        $(this).next().slideToggle(200);
        $expand = $(this).find(">:first-child");

        if ($expand.text() == "+") {
            $expand.text("-");
        } else {
            $expand.text("+");
        }
    });
});

// NProgress
if (typeof NProgress != 'undefined') {
    $(document).ready(function() {
        NProgress.start();
    });

    $(window).load(function() {
        NProgress.done();
    });
}



$('.changeBD').click(function() {
    bootbox.prompt({
        title: "Escolha a empresa",
        inputType: 'select',
        inputOptions: ERPBdList,
        callback: function(result) {

            $.ajax({
                type: "POST",
                url: URLBASE + "/data/settings.php",
                data: { "bd": result, "accao": "changeDB" },
                dataType: "json",
                success: function(data) {
                    console.log(data.message);
                    location.reload();
                },
                error: function(request, status, error) {
                    console.log('Erro a atualizar BD');
                }
            });

            console.log(result);
        }
    });
});



function updtCliParam(cliente, params) {
    $.ajax({
        type: "POST",
        url: URLBASE + "/data/utils.php",
        data: { "accao": "updtCliParam", "cliente": cliente, "params": params },
        dataType: "json",
        success: function(data) {
            console.log(data);

        },
        error: function(request, status, error) {
            console.log(request.responseText);
        }
    });
}

$(document).ready(function() {
    if ($.fn.DataTable) {
        $('table.datatable').each(function() {
            var $table = $(this);
            var source = $table.data('source');
            var options = { responsive: true };
            options.language = { url: 'vendors/datatables.net/i18n/pt-PT.json' };
            var nonSortable = [];
            $table.find('thead th').each(function(i) {
                if ($(this).data('orderable') === false) {
                    nonSortable.push(i);
                }
            });
            if (nonSortable.length) {
                options.columnDefs = options.columnDefs || [];
                options.columnDefs.push({ targets: nonSortable, orderable: false });
            }
            if ($table.data('no-sort-last')) {
                options.columnDefs = options.columnDefs || [];
                options.columnDefs.push({ targets: -1, orderable: false });
            }
            var orderColumn = $table.data('order-column');
            if (typeof orderColumn !== 'undefined' && orderColumn !== '') {
                var orderDir = $table.data('order-dir') || 'asc';
                var orderIndex = parseInt(orderColumn, 10);
                if (!isNaN(orderIndex)) {
                    options.order = [[orderIndex, orderDir]];
                }
            }
            if (source) {
                var typeId = $table.data('type-id');
                options.ajax = {
                    url: source,
                    type: 'POST',
                    data: { type_id: typeId }
                };
            }
            var table = $table.DataTable(options);
            var $toggles = $table.prev('.column-toggler');
            if ($toggles.length) {
                $toggles.find('input[type="checkbox"]').on('change', function() {
                    var column = table.column($(this).data('column'));
                    column.visible(this.checked);
                });
            }
        });
    }
});

$(document).ready(function() {
    document.querySelectorAll('select.content-select').forEach(function(sel) {
        var targetType = sel.dataset.targetType;
        var filterField = sel.dataset.filterField || '';
        var staticFilterValue = sel.dataset.filterValue || '';
        var selected = sel.dataset.selected ? sel.dataset.selected.split(',').filter(Boolean) : [];

        function loadOptions(filterValue) {
            var fv = filterValue;
            if (filterField) {
                if (fv === undefined || fv === '') {
                    fv = staticFilterValue;
                    if (fv === '') {
                        sel.innerHTML = sel.multiple ? '' : '<option value="">-- Select --</option>';
                        return;
                    }
                }
            }

            var params = new URLSearchParams();
            params.append('type_id', targetType);
            if (filterField) {
                params.append('filter_field', filterField);
                params.append('filter_value', fv);
            }

            fetch('data/content_options.php?' + params.toString())
                .then(function(resp) { return resp.json(); })
                .then(function(data) {
                    sel.innerHTML = sel.multiple ? '' : '<option value="">-- Select --</option>';
                    data.entries.forEach(function(entry) {
                        var opt = document.createElement('option');
                        opt.value = entry.id;
                        opt.textContent = entry.title;
                        if (selected.includes(String(entry.id))) {
                            opt.selected = true;
                        }
                        sel.appendChild(opt);
                    });
                });
        }

        function getFilterInput() {
            if (!filterField) return null;
            if (filterField.startsWith('tax_')) {
                var id = filterField.substring(4);
                return document.querySelector('[name="taxonomy_' + id + '"],[name="taxonomy_' + id + '[]"]');
            }
            return document.querySelector('[name="field_' + filterField + '"],[name="field_' + filterField + '[]"]');
        }

        var filterInput = getFilterInput();
        if (filterInput) {
            var update = function() { loadOptions(filterInput.value); };
            filterInput.addEventListener('change', update);
            if (filterInput.value !== '') {
                update();
            } else {
                loadOptions(staticFilterValue);
            }
        } else {
            loadOptions(staticFilterValue);
        }
    });
});
