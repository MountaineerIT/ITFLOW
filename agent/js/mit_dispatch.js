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

    // ---------- the Schedule window ----------

    const modal = document.getElementById('dispatchScheduleModal');
    const fields = modal && modal.querySelector('[data-mit-dispatch-fields]');
    const form = modal && modal.querySelector('[data-dispatch-schedule]');
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
    function head(icon, text) {
        return '<div class="col-12 fw-semibold border-top pt-2 mt-1"><i class="fas fa-fw ' + icon + ' me-1 text-body-secondary"></i>' + text + '</div>';
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
        const set = data.notify[onsite() ? '1' : '0'];
        return head('fa-envelope', 'Email about this booking') + '<div class="col-12" data-mit-notify><div class="d-flex flex-wrap gap-3">'
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
            + head('fa-ticket-alt', 'Ticket details')
            + select('mitDClient', 'Client', options(clients, t.client_id))
            + select('mitDContact', 'Contact', options([{id: 0, name: 'No contact'}].concat(d.contacts), t.contact_id))
            + '<div class="col-sm-6" data-mit-assets' + (t.client_id ? '' : ' hidden') + '><label class="form-label small mb-0" for="mitDAsset">Asset</label>'
            + '<select class="form-select form-select-sm" id="mitDAsset">' + assetOptions(d.assets, d.asset_id) + '</select></div>'
            + '<div class="col-sm-6" data-mit-assets' + (t.client_id ? '' : ' hidden') + '><label class="form-label small mb-0" for="mitDOtherAssets">Other assets</label>'
            + '<select class="form-select form-select-sm" id="mitDOtherAssets" multiple size="3">' + otherAssetOptions(d.assets, d.other_asset_ids, d.asset_id) + '</select></div>'
            + select('mitDPriority', 'Priority', options(d.priorities.map(function (p) { return {id: p, name: p}; }), t.priority), 'col-sm-4')
            + select('mitDStatus', 'Status', options(d.statuses, t.status_id), 'col-sm-4')
            + select('mitDCategory', 'Category', options([{id: 0, name: 'No category'}].concat(d.categories), t.category_id), 'col-sm-4')
            + (d.boards && d.boards.length ? select('mitDBoard', 'Board', options(d.boards, d.board)) : '')
            + select('mitDSla', 'SLA', '<option value="auto">Automatic - from the client\'s contract and priority</option>' + options([{id: 0, name: 'None'}].concat(d.slas), t.sla_id),
                d.boards && d.boards.length ? 'col-sm-6' : 'col-12')
            + '<div class="col-12"><div class="form-text mt-0" data-mit-client-note hidden>Moving it to another client makes its public replies internal, takes its assets off, and its SLA is worked out again unless you pick one.</div></div>'
            + notifyHtml()
            + '</div>';
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
        const notify = fields.querySelector('[data-mit-notify]');
        if (notify) {
            notify.addEventListener('input', function () {
                notifyTouched = true;
            });
        }
    }

    if (modal && fields && form) {
        // Our ticks say who is emailed - ITFlow's "Email the contact" tick goes
        const tell = form.elements.tell_client;
        if (tell && tell.closest('.form-check')) {
            tell.closest('.form-check').hidden = true;
        }
        modal.addEventListener('dispatch:schedule', function (e) {
            const ticket = e.detail.ticket;
            details = null;
            notifyTouched = false;
            fields.innerHTML = '<div class="small text-body-secondary"><i class="fas fa-spinner fa-spin me-1"></i>Loading the ticket\'s details...</div>';
            request({details: ticket.id}).then(function (d) {
                if (!d || d.error || !d.ticket) {
                    fields.innerHTML = data.notify ? '<div class="row g-2">' + notifyHtml() + '</div>' : '';
                    return;
                }
                details = d;
                details.ticketId = ticket.id;
                render(d);
            }).catch(function () {
                fields.innerHTML = '';
            });
        });
        // Remote / Onsite switches the ticks to that kind of visit's defaults, until they are touched
        if (form.elements.onsite) {
            const follow = function () {
                const notify = fields.querySelector('[data-mit-notify]');
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

    // Every request Dispatch makes: the board picked; a booking also takes the Schedule window's details and ticks
    window.itflowDispatchExtra = function (method, params) {
        params = Object.assign({}, params);
        if (board !== 'all' && (params.dispatch_queue !== undefined || params.dispatch_workload !== undefined || params.dispatch_week !== undefined)) {
            params.board = board;
        }
        if (params.dispatch_book !== undefined && fields) {
            const notify = fields.querySelector('[data-mit-notify]');
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
