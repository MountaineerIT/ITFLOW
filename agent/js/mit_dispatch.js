/*
 * Mountaineer IT - our additions on ITFlow's Dispatch (agent/js/dispatch.js; functions/mit_dispatch.php):
 *  - the service board picker: every request Dispatch makes carries ?board= (window.itflowDispatchExtra, called from ITFlow's request()),
 *    "Only its members" narrows the agent columns (window.itflowDispatchAgents, from shownAgents()), each queue card shows its board
 *    (window.itflowDispatchCardExtra);
 *  - the Schedule window (event "dispatch:schedule" from ITFlow's schedule()): the ticket's title, client, contact, assets, priority,
 *    status, category, SLA and board, put right as it is booked, and who is emailed (the visit kind's defaults, changed per booking) -
 *    posted with ITFlow's dispatch_book and saved first by mitDispatchBeforeBook. ITFlow's own "Email the contact" tick is replaced by ours.
 */
(function () {
    'use strict';

    const holder = document.querySelector('[data-mit-dispatch]');
    if (!holder) {
        return;
    }
    let data = {};
    try {
        data = JSON.parse(holder.dataset.mitDispatch || '{}');
    } catch (e) {
        data = {};
    }
    const boards = data.boards || [];
    const esc = function (text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    };

    // ---------- service boards ----------

    let board = data.board || 'all';
    let boardTechs = !!data.boardTechs;
    function pickedBoards() {
        if (board === 'all') {
            return null;
        }
        return boards.filter(function (b) {
            return board === 'mine' ? b.members.indexOf(Number(data.me)) !== -1 : String(b.id) === String(board);
        });
    }
    function saveBoard() {
        const body = new URLSearchParams({board_state: 1, csrf_token: data.csrf || '', board: board, board_techs: boardTechs ? 1 : 0});
        return fetch('mit_dispatch_ajax.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()});
    }
    const boardPick = holder.querySelector('[data-mit-dispatch-board]');
    const techsPick = holder.querySelector('[data-mit-dispatch-board-techs]');
    if (boardPick) {
        boardPick.addEventListener('change', function () {
            board = boardPick.value;
            saveBoard().finally(function () {
                window.location.reload(); // the queue, the board, Workload and This week all follow it
            });
        });
    }
    if (techsPick) {
        techsPick.addEventListener('change', function () {
            boardTechs = techsPick.checked;
            saveBoard().finally(function () {
                window.location.reload();
            });
        });
    }

    window.itflowDispatchAgents = function (list) {
        const picked = pickedBoards();
        if (!boardTechs || !picked) {
            return list;
        }
        const members = [].concat.apply([], picked.map(function (b) { return b.members; }));
        // A board with nobody on it shows everyone
        return members.length ? list.filter(function (a) { return members.indexOf(Number(a.id)) !== -1; }) : list;
    };

    window.itflowDispatchCardExtra = function (ticket) {
        const b = ticket.mit_board;
        return b ? '<span class="ms-auto small text-nowrap text-body-secondary" title="Board"><span class="itflow-dispatch-dot me-1" style="background: '
            + esc(b.color || '#6c757d') + '"></span>' + esc(b.name) + '</span>' : '';
    };

    // ---------- the Schedule window (further down the page than this script - found once the page has loaded) ----------

    let modal = null;
    let fields = null;
    let form = null;
    const notifyWho = {tech: 'Agent', contact: 'Contact', watchers: 'Watchers', site: 'Site contact', primary: 'Primary contact'};
    let details = null;
    let notifyTouched = false;

    function request(params) {
        return fetch('mit_dispatch_ajax.php?' + new URLSearchParams(params).toString(), {credentials: 'same-origin'}).then(function (res) {
            return res.json();
        });
    }
    function options(list, current) {
        return list.map(function (o) {
            return '<option value="' + esc(o.id) + '"' + (String(o.id) === String(current) ? ' selected' : '') + '>' + esc(o.name) + '</option>';
        }).join('');
    }
    function select(id, label, html, col, extra) {
        return '<div class="' + (col || 'col-sm-6') + '"><label class="form-label small mb-0" for="' + id + '">' + label + '</label>'
            + '<select class="form-select form-select-sm" id="' + id + '"' + (extra || '') + '>' + html + '</select></div>';
    }
    function onsite() {
        return form && form.elements.onsite && String(form.elements.onsite.value) === '1';
    }
    function notifyHtml() {
        if (!data.notify) {
            return '';
        }
        // A booked ticket being moved: the ticks kept for it (mitDispatchKeptNotify), else the visit kind's defaults
        const set = details && details.notify_kept ? Object.assign({extra: []}, details.notify_kept) : data.notify[onsite() ? '1' : '0'];
        return '<div class="col-12 fw-semibold small"><i class="fas fa-fw fa-envelope me-1 text-body-secondary"></i>Email about this booking</div><div class="col-12" data-mit-notify><div class="d-flex flex-wrap gap-3">'
            + Object.keys(notifyWho).map(function (who) {
                return '<div class="form-check"><input class="form-check-input" type="checkbox" id="mitN_' + who + '" data-who="' + who + '"' + (set[who] ? ' checked' : '')
                    + '><label class="form-check-label small" for="mitN_' + who + '">' + notifyWho[who] + '</label></div>';
            }).join('')
            + '</div><input type="text" class="form-control form-control-sm mt-1" data-who="extra" placeholder="Also email (commas between)" value="' + esc((set.extra || []).join(', ')) + '">'
            + (data.contactDefault ? '' : '<div class="form-text">Client notifications are off - only the agent is emailed.</div>') + '</div>';
    }
    function assetOptions(list, current) {
        return options([{id: 0, name: 'None'}].concat(list || []), current || 0);
    }
    function otherAssetOptions(list, picked, main) {
        picked = (picked || []).map(Number);
        return (list || []).filter(function (a) { return a.id !== Number(main); }).map(function (a) {
            return '<option value="' + a.id + '"' + (picked.indexOf(a.id) !== -1 ? ' selected' : '') + '>' + esc(a.name) + '</option>';
        }).join('');
    }
    function render(d) {
        const t = d.ticket;
        const clients = (t.client_id ? [] : [{id: 0, name: 'No client (internal)'}]).concat(d.clients);
        fields.innerHTML = '<div class="row g-2">'
            + '<div class="col-12"><label class="form-label small mb-0" for="mitDTitle">Title</label><input type="text" class="form-control form-control-sm" id="mitDTitle" maxlength="500" value="' + esc(t.subject) + '"></div>'
            + select('mitDClient', 'Client', options(clients, t.client_id))
            + select('mitDContact', 'Contact', options([{id: 0, name: 'No contact'}].concat(d.contacts), t.contact_id))
            + '<div class="col-sm-6" data-mit-assets' + (t.client_id ? '' : ' hidden') + '><label class="form-label small mb-0" for="mitDAsset">Asset</label>'
            + '<select class="form-select form-select-sm" id="mitDAsset">' + assetOptions(d.assets, d.asset_id) + '</select></div>'
            + '<div class="col-sm-6" data-mit-assets' + (t.client_id ? '' : ' hidden') + '><label class="form-label small mb-0" for="mitDOtherAssets">Other assets</label>'
            + '<select class="form-select form-select-sm select2" id="mitDOtherAssets" multiple data-placeholder="None">' + otherAssetOptions(d.assets, d.other_asset_ids, d.asset_id) + '</select></div>'
            + select('mitDPriority', 'Priority', options(d.priorities.map(function (p) { return {id: p, name: p}; }), t.priority), 'col-sm-4')
            + select('mitDStatus', 'Status', options(d.statuses, t.status_id), 'col-sm-4')
            + select('mitDCategory', 'Category', options([{id: 0, name: 'No category'}].concat(d.categories), t.category_id), 'col-sm-4')
            + (d.boards && d.boards.length ? select('mitDBoard', 'Board', options(d.boards, d.board)) : '')
            + select('mitDSla', 'SLA', '<option value="auto">Automatic - from the client\'s contract and priority</option>' + options([{id: 0, name: 'None'}].concat(d.slas), t.sla_id),
                d.boards && d.boards.length ? 'col-sm-6' : 'col-12')
            + '<div class="col-12"><div class="form-text mt-0" data-mit-client-note hidden>Moving it to another client makes its public replies internal, takes its assets off, and its SLA is worked out again unless you pick one.</div></div>'
            + '</div>';
        notifyBox.innerHTML = notifyHtml() ? '<div class="row g-2">' + notifyHtml() + '</div>' : '';
        bind(d);
    }
    function loadAssets(keep) {
        const client = document.getElementById('mitDClient');
        const contact = document.getElementById('mitDContact');
        const main = document.getElementById('mitDAsset');
        const others = document.getElementById('mitDOtherAssets');
        const wanted = keep ? Number(main.value) : 0;
        const picked = keep ? Array.prototype.map.call(others.selectedOptions, function (o) { return Number(o.value); }) : [];
        fields.querySelectorAll('[data-mit-assets]').forEach(function (el) {
            el.hidden = !Number(client.value);
        });
        if (!Number(client.value)) {
            main.innerHTML = assetOptions([], 0);
            others.innerHTML = '';
            return;
        }
        request({assets: client.value, contact: contact.value}).then(function (list) {
            list = Array.isArray(list) ? list : [];
            details.assets = list;
            main.innerHTML = assetOptions(list, list.some(function (a) { return a.id === wanted; }) ? wanted : 0);
            others.innerHTML = otherAssetOptions(list, picked, main.value);
        });
    }
    function bind(d) {
        const client = document.getElementById('mitDClient');
        const contact = document.getElementById('mitDContact');
        const main = document.getElementById('mitDAsset');
        const others = document.getElementById('mitDOtherAssets');
        const original = client.value;
        main.addEventListener('change', function () {
            const picked = Array.prototype.map.call(others.selectedOptions, function (o) { return Number(o.value); });
            others.innerHTML = otherAssetOptions(details.assets, picked, main.value);
        });
        contact.addEventListener('change', function () {
            loadAssets(true);
        });
        client.addEventListener('change', function () {
            fields.querySelector('[data-mit-client-note]').hidden = client.value === original;
            contact.disabled = true;
            request({contacts: client.value}).then(function (list) {
                list = Array.isArray(list) ? list : [];
                contact.innerHTML = options([{id: 0, name: 'No contact'}].concat(list), list.length ? list[0].id : 0);
                contact.disabled = false;
                loadAssets(client.value === original);
            });
        });
        const notify = modal.querySelector('[data-mit-notify]');
        if (notify) {
            notify.addEventListener('input', function () {
                notifyTouched = true;
            });
        }
    }

    /*
     * The window laid out to fit a screen (the user's choice, Nov 22 - a hybrid of ITFlow's window and ours from before 26.12): wide, two
     * columns - Booking (ITFlow's agent, date and time, how long, visit, then who is emailed) and Ticket (our title and details) -, the
     * ticket's line in the title bar, the contact's other open tickets as a slim strip (a count that opens the list when there are
     * several), and Schedule It always in view (the middle scrolls on a short screen). ITFlow's own elements are moved, not copied, so
     * its script keeps working on them.
     */
    let notifyBox = null;
    function layout() {
        modal.setAttribute('data-mit-ds', '');
        const dialog = modal.querySelector('.modal-dialog');
        dialog.classList.add('modal-xl', 'modal-dialog-scrollable');
        const header = modal.querySelector('.modal-header');
        const title = header.querySelector('.modal-title');
        const ticketEl = modal.querySelector('[data-dispatch-schedule-ticket]');
        const top = document.createElement('div');
        top.className = 'me-auto min-w-0';
        header.insertBefore(top, title);
        top.appendChild(title);
        if (ticketEl) {
            ticketEl.className = 'mit-ds-ticket small text-truncate';
            top.appendChild(ticketEl);
        }
        const body = modal.querySelector('.modal-body');
        const others = body.querySelector('[data-dispatch-schedule-others]');
        const error = body.querySelector('[data-dispatch-schedule-error]');
        const row = document.createElement('div');
        row.className = 'row g-4';
        const left = document.createElement('div');
        left.className = 'col-lg-5';
        left.innerHTML = '<div class="mit-ds-head"><i class="fas fa-fw fa-calendar-check me-1"></i>Booking</div>';
        const right = document.createElement('div');
        right.className = 'col-lg-7 mit-ds-right';
        right.innerHTML = '<div class="mit-ds-head"><i class="fas fa-fw fa-ticket-alt me-1"></i>Ticket</div>';
        Array.prototype.slice.call(body.children).forEach(function (el) {
            if (el === others || el === error || el === ticketEl) {
                return;
            }
            (el === fields ? right : left).appendChild(el);
        });
        notifyBox = document.createElement('div');
        notifyBox.className = 'mt-3';
        notifyBox.setAttribute('data-mit-dispatch-notify', '');
        const hint = left.querySelector('.form-text:last-child');
        left.insertBefore(notifyBox, hint && hint.parentNode === left ? hint : null);
        row.appendChild(left);
        row.appendChild(right);
        body.insertBefore(row, error || null);
        // The other open tickets: one line for one, a count that opens the list for several
        if (others) {
            const shape = function () {
                const n = others.querySelectorAll('[data-other]').length;
                const many = n > 1;
                others.classList.toggle('mit-many', many);
                if (!many) {
                    others.classList.remove('mit-open');
                }
                // ITFlow's count, kept right as merges take rows away
                const head = others.querySelector(':scope > .fw-semibold');
                const text = head ? Array.prototype.find.call(head.childNodes, function (node) { return node.nodeType === 3 && /\d+ other open ticket/.test(node.textContent); }) : null;
                if (text && n) {
                    text.textContent = text.textContent.replace(/\d+ other open tickets?/, n + ' other open ticket' + (n === 1 ? '' : 's'));
                }
            };
            new MutationObserver(shape).observe(others, {childList: true});
            // Enter in a merge's reason merges (as our window before 26.12 did) - it is inside the Schedule form, which would book the ticket
            others.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && e.target.closest('[data-merge-reason]')) {
                    e.preventDefault();
                    const go = e.target.closest('[data-merge-form]') && e.target.closest('[data-merge-form]').querySelector('[data-merge-go]');
                    if (go && !go.disabled) {
                        go.click();
                    }
                }
            });
            others.addEventListener('click', function (e) {
                if (others.classList.contains('mit-many') && e.target.closest('.fw-semibold') && !e.target.closest('[data-other]')) {
                    others.classList.toggle('mit-open');
                }
            });
        }
        const style = document.createElement('style');
        style.textContent = ''
            + '[data-mit-ds] .modal-header { align-items: flex-start; }'
            + '[data-mit-ds] .mit-ds-ticket { color: rgba(255,255,255,.7); max-width: 60rem; }'
            + '[data-mit-ds] .mit-ds-ticket a { color: #fff; }'
            + '[data-mit-ds] .mit-ds-ticket > div { display: inline; margin-left: .5rem; color: rgba(255,255,255,.7) !important; }'
            + '[data-mit-ds] .mit-ds-head { font-size: .75rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color);'
            + ' border-bottom: 1px solid var(--bs-border-color); padding-bottom: .25rem; margin-bottom: .75rem; }'
            + '[data-mit-ds] .col-lg-5 .mb-3 { margin-bottom: .75rem !important; }'
            + '[data-mit-ds] [data-dispatch-schedule-others] { max-height: none !important; display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .75rem;'
            + ' background: var(--bs-warning-bg-subtle); border-color: var(--bs-warning-border-subtle) !important; padding: .35rem .6rem !important; }'
            + '[data-mit-ds] [data-dispatch-schedule-others][hidden] { display: none; }'
            + '[data-mit-ds] [data-dispatch-schedule-others] > .text-body-secondary { display: none; }'
            + '[data-mit-ds] [data-dispatch-schedule-others] > [data-other] { border-top: 0 !important; padding: 0 !important; flex: 1 1 24rem; min-width: 0; }'
            + '[data-mit-ds] [data-dispatch-schedule-others].mit-many > [data-other] { flex-basis: 100%; border-top: 1px solid var(--bs-warning-border-subtle) !important; padding-top: .25rem !important; }'
            + '[data-mit-ds] [data-dispatch-schedule-others].mit-many:not(.mit-open) > [data-other] { display: none; }'
            + '[data-mit-ds] [data-dispatch-schedule-others].mit-many > .fw-semibold { cursor: pointer; }'
            + '[data-mit-ds] [data-dispatch-schedule-others].mit-many > .fw-semibold::after { content: "Show"; margin-left: .5rem; font-weight: 400; text-decoration: underline; color: var(--bs-link-color); }'
            + '[data-mit-ds] [data-dispatch-schedule-others].mit-many.mit-open > .fw-semibold::after { content: "Hide"; }';
        document.head.appendChild(style);
    }

    function bindModal() {
        modal = document.getElementById('dispatchScheduleModal');
        fields = modal && modal.querySelector('[data-mit-dispatch-fields]');
        form = modal && modal.querySelector('[data-dispatch-schedule]');
        if (!modal || !fields || !form) {
            return;
        }
        layout();
        // Our ticks say who is emailed - ITFlow's "Email the contact" tick goes
        const tell = form.elements.tell_client;
        if (tell && tell.closest('.form-check')) {
            tell.closest('.form-check').hidden = true;
        }
        modal.addEventListener('dispatch:schedule', function (e) {
            const ticket = e.detail.ticket;
            // The title already says which ticket - its line in the title bar keeps the subject only
            const line = modal.querySelector('.mit-ds-ticket a');
            if (line && ticket.reference && line.textContent.indexOf(ticket.reference + ' ') === 0) {
                line.textContent = line.textContent.slice(ticket.reference.length + 1);
            }
            details = null;
            notifyTouched = false;
            fields.innerHTML = '<div class="small text-body-secondary"><i class="fas fa-spinner fa-spin me-1"></i>Loading the ticket\'s details...</div>';
            notifyBox.innerHTML = '';
            request({details: ticket.id}).then(function (d) {
                if (!d || d.error || !d.ticket) {
                    fields.innerHTML = '';
                    notifyBox.innerHTML = data.notify ? '<div class="row g-2">' + notifyHtml() + '</div>' : '';
                    return;
                }
                details = d;
                details.ticketId = ticket.id;
                notifyTouched = !!d.notify_kept; // kept ticks are a choice already made - the visit kind doesn't switch them
                render(d);
            }).catch(function () {
                fields.innerHTML = '';
            });
        });
        // Remote / Onsite switches the ticks to that kind of visit's defaults, until they are touched
        if (form.elements.onsite) {
            const follow = function () {
                const notify = modal.querySelector('[data-mit-notify]');
                if (!notify || notifyTouched || !data.notify) {
                    return;
                }
                const set = data.notify[onsite() ? '1' : '0'];
                notify.querySelectorAll('input[type=checkbox][data-who]').forEach(function (tick) {
                    tick.checked = !!set[tick.dataset.who];
                });
                notify.querySelector('[data-who="extra"]').value = (set.extra || []).join(', ');
            };
            form.elements.onsite.addEventListener('change', follow);
            if (window.jQuery) {
                window.jQuery(form.elements.onsite).on('change', follow); // select2
            }
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindModal);
    } else {
        bindModal();
    }

    // Every request Dispatch makes: the board picked; a booking also takes the Schedule window's details and ticks
    window.itflowDispatchExtra = function (method, params) {
        params = Object.assign({}, params);
        if (params.dispatch_queue !== undefined || params.dispatch_workload !== undefined || params.dispatch_week !== undefined) {
            params.board = board;
        }
        if (params.dispatch_book !== undefined && fields) {
            const notify = modal.querySelector('[data-mit-notify]');
            if (notify) {
                params.tell_client = 1; // ours decide
                params.mit_schedule_notify_present = 1;
                notify.querySelectorAll('input[type=checkbox][data-who]').forEach(function (tick) {
                    if (tick.checked) {
                        params['mit_schedule_notify[' + tick.dataset.who + ']'] = 1;
                    }
                });
                params.mit_schedule_notify_extra = notify.querySelector('[data-who="extra"]').value;
            }
            if (details && Number(params.ticket_id) === Number(details.ticketId) && document.getElementById('mitDClient')) {
                const title = document.getElementById('mitDTitle');
                if (title) {
                    params.detail_subject = title.value;
                }
                params.detail_client_id = document.getElementById('mitDClient').value;
                params.detail_contact_id = document.getElementById('mitDContact').value;
                params.detail_priority = document.getElementById('mitDPriority').value;
                params.detail_status = document.getElementById('mitDStatus').value;
                params.detail_category_id = document.getElementById('mitDCategory').value;
                params.detail_sla = document.getElementById('mitDSla').value;
                if (document.getElementById('mitDBoard')) {
                    params.detail_board = document.getElementById('mitDBoard').value;
                }
                params.detail_assets_present = 1;
                params.detail_asset_id = document.getElementById('mitDAsset').value;
                params.detail_other_assets = Array.prototype.map.call(document.getElementById('mitDOtherAssets').selectedOptions, function (o) {
                    return o.value;
                }).join(',');
            }
        }
        return params;
    };
})();
