/*
 * Mountaineer IT - the ticket page's customizable layout (functions/mit_ticket_layout.php).
 *
 * Finds ITFlow's boxes on the page, names them, and puts them where the saved layout says: how the
 * columns share the page (main wide, even, side wider, one column, or three columns - an extra one
 * on the left), which column each box is in and in what order, full or half width (two half boxes
 * sit side by side), collapsed or not. "Customize layout" (in the ticket's menu, or under the right
 * column) lets you drag them about, then save it for yourself or, as an admin, as the company default.
 * The page's markup is ITFlow's own; nothing here changes it server-side, so a box this script does
 * not know simply stays where ITFlow put it. Boxes are found by their title icon (ICONS below) -
 * ITFlow's, plus our Parts box (fa-microchip, functions/mit_procurement_parts.php) and Reimbursements box
 * (fa-receipt, functions/mit_reimbursements.php).
 */
(function () {
    'use strict';

    // The reply box starts open on "Public note" (ITFlow's starts shut with nothing picked). Done here,
    // before Bootstrap and TinyMCE load in the footer, so the editor is sized open and ITFlow's own
    // composer script finds it already shown - its Cancel still closes it.
    const replyComposer = document.getElementById('replyComposer');
    const publicNote = document.getElementById('public_reply_type_opt1');
    if (replyComposer && publicNote && !document.querySelector('input[name="public_reply_type"]:checked')) {
        publicNote.checked = true;
        replyComposer.classList.add('show');
        document.querySelectorAll('#replyAudience [data-audience]').forEach((line) => {
            line.hidden = line.dataset.audience !== '1';
        });
        const submitLabel = document.getElementById('replySubmitLabel');
        if (submitLabel) {
            submitLabel.textContent = 'Post note';
        }
    }

    // The ticket timer stops when the note is sent. ITFlow's agent/js/ticket_time_tracking.js clears it 100 ms after the
    // Add button is clicked - lost when the page moves on sooner (a quick server) or the form is sent another way, so the
    // timer kept running. Here it is cleared as the form really goes: the time inputs are already read into what is sent,
    // and a submit stopped by a check (nothing posted) leaves the timer alone. Keys as ITFlow's: <id>-startTime / -pausedTime.
    document.addEventListener('submit', function (e) {
        const form = e.target;
        const ticketId = new URLSearchParams(window.location.search).get('ticket_id');
        if (e.defaultPrevented || !ticketId || !form || !form.querySelector || !form.querySelector('#ticket_add_reply')) {
            return;
        }
        try {
            localStorage.removeItem(ticketId + '-startTime');
            localStorage.removeItem(ticketId + '-pausedTime');
            localStorage.removeItem('ticket-timer-running-' + ticketId);
            sessionStorage.setItem('mit-timer-posted-' + ticketId, '1');
        } catch (err) {
            // storage blocked - nothing to clear
        }
    });
    // Back on the ticket after sending it: the timer stays stopped at 0, even with Admin's timer auto-start on (that
    // starts it when a ticket is opened, not again after each note). ITFlow's timer script runs after this one and sees a
    // paused 0; the marker goes once it has, so the next visit auto-starts as usual.
    (function () {
        const ticketId = new URLSearchParams(window.location.search).get('ticket_id');
        try {
            if (ticketId && sessionStorage.getItem('mit-timer-posted-' + ticketId)) {
                sessionStorage.removeItem('mit-timer-posted-' + ticketId);
                if (!localStorage.getItem(ticketId + '-startTime') && !localStorage.getItem(ticketId + '-pausedTime')) {
                    localStorage.setItem(ticketId + '-pausedTime', '0');
                    document.addEventListener('DOMContentLoaded', function () {
                        setTimeout(function () {
                            if (localStorage.getItem(ticketId + '-pausedTime') === '0' && !localStorage.getItem(ticketId + '-startTime')) {
                                localStorage.removeItem(ticketId + '-pausedTime');
                            }
                        }, 0);
                    });
                }
            }
        } catch (err) {
            // storage blocked
        }
    })();

    const cfg = window.mitTicketLayout || {};
    const root = document.querySelector('.ticket-layout');
    const main = root && root.querySelector(':scope > .ticket-layout-main');
    const side = root && root.querySelector(':scope > .ticket-layout-side');
    if (!main || !side) {
        return;
    }

    // The extra (third) column, on the left - only shown in the three-column layout
    const left = document.createElement('div');
    left.className = 'mit-layout-left';
    root.insertBefore(left, main);
    const COLUMNS = {left: left, main: main, side: side};

    // How the columns share the page; '' is ITFlow's own (main wide, side 300-340px)
    const WIDTHS = {'': 'Main wide', even: 'Even 50/50', side: 'Side wider', single: 'One column', three: 'Three columns'};

    const style = document.createElement('style');
    style.textContent = '.ticket-layout .card.collapsed-card > .list-group { display: none; }'
        + '.ticket-layout:not(.mit-cols-three) > .mit-layout-left { display: none; }'
        + '.mit-layout-left { display: contents; } .mit-layout-left > * { order: 3; }'
        + '@media (min-width: 992px) {'
        + ' .ticket-layout.mit-cols-even { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }'
        + ' .ticket-layout.mit-cols-side { grid-template-columns: minmax(0, 2fr) minmax(0, 3fr); }'
        + ' .ticket-layout.mit-cols-single { grid-template-columns: minmax(0, 1fr); grid-template-rows: auto auto auto; grid-template-areas: "header" "main" "side"; }'
        + ' .ticket-layout.mit-cols-three { grid-template-columns: 300px minmax(0, 1fr) 300px; grid-template-areas: "left header side" "left main side"; }'
        + ' .mit-layout-left { display: block; grid-area: left; min-width: 0; }'
        + ' .ticket-layout.mit-layout-on > .ticket-layout-main, .ticket-layout.mit-layout-on > .ticket-layout-side,'
        + ' .ticket-layout.mit-layout-on.mit-cols-three > .mit-layout-left { display: flex; flex-wrap: wrap; align-content: flex-start; column-gap: 1rem; }'
        + ' .ticket-layout.mit-layout-on > :is(.ticket-layout-main, .ticket-layout-side, .mit-layout-left) > * { width: 100%; }'
        + ' .ticket-layout.mit-layout-on > :is(.ticket-layout-main, .ticket-layout-side, .mit-layout-left) > .mit-half { width: calc(50% - .5rem); }'
        + '}'
        + '@media (min-width: 1400px) { .ticket-layout.mit-cols-three { grid-template-columns: 340px minmax(0, 1fr) 340px; } }'
        + '.mit-layout-editing [data-mit-box] { outline: 2px dashed var(--bs-primary-border-subtle); outline-offset: 2px; border-radius: .375rem; margin-bottom: 1rem; }'
        + '.mit-layout-editing .ticket-layout-main, .mit-layout-editing .ticket-layout-side, .mit-layout-editing .mit-layout-left { min-height: 8rem; }'
        + '.mit-layout-editing.mit-cols-three .mit-layout-left:empty::before { content: "Drag boxes here"; display: block; width: 100%;'
        + ' padding: 2rem 1rem; text-align: center; color: var(--bs-secondary-color); border: 2px dashed var(--bs-border-color); border-radius: .375rem; }'
        + '.mit-box-bar { background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); border-radius: .375rem .375rem 0 0; }'
        + '.mit-box-bar { flex-wrap: wrap; row-gap: 0 !important; } .mit-box-bar .form-check { white-space: nowrap; }'
        + '.mit-box-handle { cursor: grab; font-weight: 600; white-space: nowrap; flex: 1 0 auto; }'
        + '.mit-layout-toolbar { position: sticky; top: .5rem; z-index: 1020; }';
    document.head.appendChild(style);

    // ITFlow's boxes, known by the icon in their title
    const ICONS = {
        'fa-align-left': 'description', 'fa-sliders-h': 'details', 'fa-user': 'contact', 'fa-layer-group': 'also_open',
        'fa-map-marker-alt': 'site', 'fa-tasks': 'tasks', 'fa-microchip': 'parts', 'fa-receipt': 'reimburse', 'fa-link': 'linked', 'fa-history': 'history'
    };
    const NAMES = {
        description: 'Description', reply: 'Reply box', conversation: 'Conversation', details: 'Details', contact: 'Contact',
        also_open: 'Also open', site: 'Site', tasks: 'Tasks', parts: 'Parts', reimburse: 'Reimbursements', linked: 'Linked', history: 'History'
    };
    // ITFlow's own layout - where a box goes back to, and where a saved layout slots a box it never saw
    const DEFAULT = {
        columns: '',
        left: [],
        main: ['description', 'reply', 'conversation'],
        side: ['details', 'contact', 'also_open', 'site', 'tasks', 'parts', 'reimburse', 'linked', 'history'],
        collapsed: ['history'],
        half: []
    };
    const NO_COLLAPSE = ['reply', 'conversation'];

    function boxes() {
        const found = {};
        root.querySelectorAll('[data-mit-box]').forEach(function (box) {
            found[box.dataset.mitBox] = box;
        });
        return found;
    }

    // ---------- naming the boxes ----------

    [main, side].forEach(function (column) {
        Array.prototype.forEach.call(column.children, function (el) {
            if (el.querySelector('#replyComposer')) {
                el.dataset.mitBox = 'reply';
                return;
            }
            const icon = el.classList.contains('card') && el.querySelector(':scope > .card-header .card-title i.fas');
            if (icon) {
                Object.keys(ICONS).some(function (name) {
                    if (icon.classList.contains(name)) {
                        el.dataset.mitBox = ICONS[name];
                        return true;
                    }
                    return false;
                });
            }
        });
    });
    // The conversation is a heading and a run of reply cards - wrap them so they move as one
    const loose = Array.prototype.filter.call(main.children, function (el) {
        return !el.dataset.mitBox && !['SCRIPT', 'STYLE', 'TEMPLATE'].includes(el.tagName);
    });
    if (loose.length) {
        const conversation = document.createElement('div');
        conversation.dataset.mitBox = 'conversation';
        main.insertBefore(conversation, loose[0]);
        loose.forEach(function (el) {
            conversation.appendChild(el);
        });
    }

    // ---------- applying a layout ----------

    function collapseButton(card) {
        let button = card.querySelector(':scope > .card-header [data-lte-toggle="card-collapse"]');
        if (!button) {
            const header = card.querySelector(':scope > .card-header');
            if (!header) {
                return null;
            }
            let tools = header.querySelector(':scope > .card-tools');
            if (!tools) {
                tools = document.createElement('div');
                tools.className = 'card-tools';
                header.appendChild(tools);
            }
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-tool';
            button.title = 'Show/hide';
            button.dataset.lteToggle = 'card-collapse';
            button.innerHTML = '<i class="fas fa-chevron-down"></i>';
            tools.appendChild(button);
        }
        return button;
    }

    function setCollapsed(box, collapsed) {
        if (!box.classList.contains('card') || NO_COLLAPSE.includes(box.dataset.mitBox)) {
            return;
        }
        if (collapsed === box.classList.contains('collapsed-card')) {
            return;
        }
        const button = collapseButton(box);
        if (!button) {
            return;
        }
        box.classList.toggle('collapsed-card', collapsed);
        box.querySelectorAll(':scope > .card-body, :scope > .card-footer').forEach(function (part) {
            part.style.display = collapsed ? 'none' : '';
        });
        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-chevron-up', collapsed);
            icon.classList.toggle('fa-chevron-down', !collapsed);
        }
    }

    /** The columns: '' (ITFlow's), even, side, single or three. Leaving three moves the left column's boxes to the top of the main one. */
    function setColumns(columns) {
        columns = Object.prototype.hasOwnProperty.call(WIDTHS, columns) ? columns : '';
        Object.keys(WIDTHS).forEach(function (name) {
            if (name) {
                root.classList.toggle('mit-cols-' + name, name === columns);
            }
        });
        if (columns !== 'three') {
            Array.prototype.slice.call(left.children).reverse().forEach(function (box) {
                main.insertBefore(box, main.firstChild);
            });
        }
        return columns;
    }

    function setHalf(box, half) {
        box.classList.toggle('mit-half', half);
    }

    function apply(layout) {
        if (!layout) {
            return;
        }
        root.classList.add('mit-layout-on');
        const found = boxes();
        ['left', 'main', 'side'].forEach(function (name) {
            (layout[name] || []).forEach(function (id) {
                if (found[id]) {
                    COLUMNS[name].appendChild(found[id]);
                }
            });
        });
        setColumns(layout.columns || '');
        const collapsed = layout.collapsed || [];
        const half = layout.half || [];
        Object.keys(found).forEach(function (id) {
            setCollapsed(found[id], collapsed.includes(id));
            setHalf(found[id], half.includes(id));
        });
    }

    apply(cfg.layout);

    // ---------- "Read" on the description ----------
    // Ticked once the tech has read it: folded away for them on this ticket from then on (until the description is edited)
    (function () {
        const box = boxes().description;
        const tools = box && box.querySelector(':scope > .card-header .card-tools');
        if (!tools || !cfg.ticketId) {
            return;
        }
        const wrap = document.createElement('div');
        wrap.className = 'form-check form-check-inline m-0 me-2 small align-middle';
        wrap.title = 'Read it? Tick to keep the description folded away for you on this ticket - it opens again if the description is changed';
        wrap.innerHTML = '<input class="form-check-input" type="checkbox" id="mitTicketRead"><label class="form-check-label" for="mitTicketRead">Read</label>';
        tools.insertBefore(wrap, tools.firstChild);
        const tick = wrap.querySelector('input');
        tick.checked = !!cfg.read;
        if (cfg.read) {
            box.dataset.mitRead = '1';
            setCollapsed(box, true);
        }
        tick.addEventListener('change', function () {
            const read = tick.checked;
            box.dataset.mitRead = read ? '1' : '';
            setCollapsed(box, read || (cfg.layout && (cfg.layout.collapsed || []).includes('description')));
            const body = new URLSearchParams({mit_ticket_layout: 1, csrf_token: cfg.csrf || '', scope: read ? 'read' : 'unread', ticket_id: cfg.ticketId});
            fetch('ajax.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, credentials: 'same-origin', body: body.toString()})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error();
                    }
                })
                .catch(function () {
                    tick.checked = !read;
                    box.dataset.mitRead = tick.checked ? '1' : '';
                    alert('Read could not be saved - please try again.');
                });
        });
    })();

    // ---------- customizing ----------

    /*
     * The layout as the page now shows it. Boxes this ticket does not have (Site, Also open...) keep
     * their place from the layout in use - after the box they followed - so a layout saved on one
     * ticket still suits the next.
     */
    function current() {
        const before = cfg.layout || DEFAULT;
        const names = ['left', 'main', 'side'];
        const shown = {left: [], main: [], side: []};
        names.forEach(function (name) {
            COLUMNS[name].querySelectorAll(':scope > [data-mit-box]').forEach(function (box) {
                shown[name].push(box.dataset.mitBox);
            });
        });
        const present = shown.left.concat(shown.main, shown.side);
        const placed = function (id) {
            return names.some(function (name) {
                return (before[name] || []).includes(id);
            });
        };
        names.forEach(function (name) {
            const list = (before[name] || []).concat((DEFAULT[name] || []).filter(function (id) {
                return !placed(id);
            }));
            list.forEach(function (id, index) {
                if (present.includes(id) || shown[name].includes(id)) {
                    return;
                }
                let at = 0;
                for (let i = index - 1; i >= 0; i--) {
                    const pos = shown[name].indexOf(list[i]);
                    if (pos !== -1) {
                        at = pos + 1;
                        break;
                    }
                }
                shown[name].splice(at, 0, id);
            });
        });
        // Boxes not on this ticket keep their collapsed / half setting; the rest are as ticked
        const kept = function (key) {
            return (before[key] || DEFAULT[key]).filter(function (id) {
                return !present.includes(id);
            });
        };
        const collapsed = kept('collapsed');
        const half = kept('half');
        root.querySelectorAll('[data-mit-box]').forEach(function (box) {
            // Folded because it was ticked Read is not "Start collapsed"
            if ((box.classList.contains('collapsed-card') && box.dataset.mitRead !== '1') || (box.querySelector(':scope > .mit-box-bar [data-mit-opt="collapsed"]') || {}).checked) {
                collapsed.push(box.dataset.mitBox);
            }
            if (box.classList.contains('mit-half')) {
                half.push(box.dataset.mitBox);
            }
        });
        const columns = Object.keys(WIDTHS).find(function (name) {
            return name && root.classList.contains('mit-cols-' + name);
        }) || '';
        return {columns: columns, left: shown.left, main: shown.main, side: shown.side, collapsed: collapsed, half: half};
    }

    function send(scope, layout) {
        const body = new URLSearchParams({mit_ticket_layout: 1, csrf_token: cfg.csrf, scope: scope, layout: layout ? JSON.stringify(layout) : ''});
        fetch('ajax.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, credentials: 'same-origin', body: body.toString()})
            .then(function (res) {
                return res.json();
            })
            .then(function (json) {
                if (!json || !json.ok) {
                    throw new Error(json && json.error ? json.error : 'The layout could not be saved.');
                }
                window.location.reload();
            })
            .catch(function (error) {
                if (typeof itflowToast === 'function') {
                    itflowToast(error.message, 'error');
                } else {
                    alert(error.message);
                }
            });
    }

    function button(label, css, onClick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + css;
        b.innerHTML = label;
        b.addEventListener('click', onClick);
        return b;
    }

    let editing = false;

    function customise() {
        if (editing || typeof Sortable === 'undefined') {
            return;
        }
        editing = true;
        document.body.classList.add('mit-layout-editing');
        root.classList.add('mit-layout-on');

        const bar = document.createElement('div');
        bar.className = 'mit-layout-toolbar card card-body py-2 mb-3 d-flex flex-row flex-wrap align-items-center gap-2';
        bar.innerHTML = '<span class="me-auto"><i class="fas fa-th-large me-2 text-primary"></i><strong>Customize layout</strong>'
            + '<span class="text-body-secondary small ms-2">Drag boxes by their bar - between the columns too. Half-width boxes sit side by side.</span></span>';
        const columnsLabel = document.createElement('label');
        columnsLabel.className = 'd-flex align-items-center gap-2 small mb-0';
        columnsLabel.innerHTML = '<span class="text-nowrap">Columns</span>';
        const columnsSelect = document.createElement('select');
        columnsSelect.className = 'form-select form-select-sm';
        Object.keys(WIDTHS).forEach(function (name) {
            const option = document.createElement('option');
            option.value = name;
            option.textContent = WIDTHS[name];
            columnsSelect.appendChild(option);
        });
        columnsSelect.value = current().columns;
        columnsSelect.addEventListener('change', function () {
            setColumns(columnsSelect.value);
        });
        columnsLabel.appendChild(columnsSelect);
        bar.appendChild(columnsLabel);
        bar.appendChild(button('<i class="fas fa-check me-1"></i>Save for me', 'btn-primary', function () {
            send('mine', current());
        }));
        if (cfg.isAdmin) {
            bar.appendChild(button('<i class="fas fa-users me-1"></i>Save as company default', 'btn-outline-primary', function () {
                send('default', current());
            }));
        }
        if (cfg.hasMine) {
            bar.appendChild(button('Use company default', 'btn-outline-secondary', function () {
                send('reset_mine');
            }));
        }
        if (cfg.isAdmin && cfg.default) {
            bar.appendChild(button('Company default back to ITFlow\'s', 'btn-outline-danger', function () {
                send('reset_default');
            }));
        }
        bar.appendChild(button('Cancel', 'btn-light', function () {
            window.location.reload();
        }));
        root.parentNode.insertBefore(bar, root);

        Object.values(boxes()).forEach(function (box) {
            const id = box.dataset.mitBox;
            const strip = document.createElement('div');
            strip.className = 'mit-box-bar d-flex align-items-center gap-2 px-2 py-1 small';
            strip.innerHTML = '<span class="mit-box-handle flex-grow-1" title="Drag to move"><i class="fas fa-grip-vertical me-2"></i>' + NAMES[id] + '</span>';
            const option = function (opt, text, checked, onChange) {
                const label = document.createElement('label');
                label.className = 'form-check mb-0';
                label.innerHTML = '<input type="checkbox" class="form-check-input" data-mit-opt="' + opt + '"> <span class="form-check-label">' + text + '</span>';
                const input = label.querySelector('input');
                input.checked = checked;
                input.addEventListener('change', function () {
                    onChange(input.checked);
                });
                strip.appendChild(label);
            };
            option('half', 'Half width', box.classList.contains('mit-half'), function (on) {
                setHalf(box, on);
            });
            if (box.classList.contains('card') && !NO_COLLAPSE.includes(id)) {
                option('collapsed', 'Start collapsed', box.classList.contains('collapsed-card') && box.dataset.mitRead !== '1', function (on) {
                    setCollapsed(box, on);
                });
            }
            box.insertBefore(strip, box.firstChild);
        });

        [left, main, side].forEach(function (column) {
            new Sortable(column, {group: 'mitTicketLayout', handle: '.mit-box-handle', draggable: '[data-mit-box]', animation: 150, forceFallback: true});
        });
        bar.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    // ---------- the way in ----------

    const link = document.createElement('a');
    link.href = '#';
    link.className = 'dropdown-item';
    link.innerHTML = '<i class="fas fa-fw fa-th-large me-2"></i>Customize layout';
    link.addEventListener('click', function (e) {
        e.preventDefault();
        customise();
    });
    // The ticket's ⋮ menu - not the Resolve arrow's menu (mit_no_response.php) that sits before it
    const menu = root.querySelector('.ticket-layout-header .dropdown:not(.mit-no-response) .dropdown-menu');
    if (menu) {
        const divider = document.createElement('div');
        divider.className = 'dropdown-divider';
        menu.appendChild(divider);
        menu.appendChild(link);
    } else {
        link.className = 'small text-body-secondary d-none d-lg-block text-end';
        side.appendChild(link);
    }
})();
