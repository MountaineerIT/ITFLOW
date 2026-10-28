// Mountaineer IT - Dispatch (agent/mit_dispatch.php, functions/mit_dispatch.php).
//
// The queue (no tech / not booked) beside a board of each tech's bookings: Day is one FullCalendar
// time grid per tech, side by side; Week is a table of techs by days, worked with SortableJS. Anything
// dropped on the board opens a quick confirm (tech, date, start, length, onsite/remote, email the
// contact) and is booked through mit_dispatch_ajax.php; a booking dropped on the queue is taken off.
// Workload: a row per tech with their open tickets, SLA and booked hours, flagged when falling behind or
// well above the team; open a row for the tickets, drop a queue ticket on a name to give it to them.
// The view, hidden techs and weekends are saved to the agent's account.

(function () {

    const root = document.getElementById('mitDispatch');
    if (!root || typeof FullCalendar === 'undefined') {
        return;
    }

    const csrf = root.dataset.csrf;
    // Dispatch View only (Admin > Roles): the board can be looked at, nothing on it moves (the server refuses anyway)
    const canEdit = root.dataset.canEdit !== '0';
    const techs = JSON.parse(root.dataset.techs || '[]');
    const saved = JSON.parse(root.dataset.state || '{}');
    // Service boards (functions/mit_boards.php): [{id, name, color, members}] - not the calendar "board" below
    const serviceBoards = JSON.parse(root.dataset.boards || '[]');
    const firstDay = Number(root.dataset.firstDay) || 0;
    const defaultMinutes = Number(root.dataset.defaultMinutes) || 60;
    const contactDefault = root.dataset.contactDefault === '1';
    // Who is emailed for a booking: the company's defaults for remote ('0') and onsite ('1') visits (functions/mit_notifications.php)
    let notifyDefaults = null;
    try {
        notifyDefaults = JSON.parse(root.dataset.scheduleNotify || 'null');
    } catch (e) {
        notifyDefaults = null;
    }
    const notifyWho = {tech: 'Tech', contact: 'Contact', watchers: 'Watchers', site: 'Site contact', primary: 'Primary contact'};
    function notifyHtml(onsite) {
        if (!notifyDefaults) {
            return '<div class="form-check ms-auto"><input class="form-check-input" type="checkbox" id="mdContact"' + (contactDefault ? ' checked' : '') + '><label class="form-check-label" for="mdContact">Email the contact</label></div>';
        }
        const set = notifyDefaults[onsite ? '1' : '0'];
        return '<div class="col-12 mt-2" id="mdNotify"><div class="form-label mb-1">Email</div><div class="d-flex flex-wrap gap-3">'
            + Object.keys(notifyWho).map(function (who) {
                return '<div class="form-check"><input class="form-check-input" type="checkbox" id="mdN_' + who + '" data-who="' + who + '"' + (set[who] ? ' checked' : '')
                    + '><label class="form-check-label" for="mdN_' + who + '">' + notifyWho[who] + '</label></div>';
            }).join('')
            + '</div><input type="text" class="form-control form-control-sm mt-1" id="mdN_extra" data-who="extra" placeholder="Also email (commas between)" value="' + escapeText((set.extra || []).join(', ')) + '">'
            + (contactDefault ? '' : '<div class="text-muted">Client notifications are off - only the tech is emailed.</div>') + '</div>';
    }
    const lengthOptions = JSON.parse(root.dataset.lengths || '[60]');
    const businessHours = JSON.parse(root.dataset.businessHours || 'true');
    const board = root.querySelector('[data-md-board]');
    const queueEl = root.querySelector('[data-md-queue]');
    const titleEl = root.querySelector('[data-md-title]');
    const dateInput = root.querySelector('[data-md-date]');

    const state = {
        view: ['week', 'workload'].indexOf(saved.view) !== -1 ? saved.view : 'day',
        hidden: Array.isArray(saved.hidden) ? saved.hidden.map(Number) : [],
        weekends: !!saved.weekends,
        serviceBoard: saved.board || 'all',
        boardTechs: !!saved.board_techs,
        date: parseYmd(root.dataset.initialDate) || new Date(),
        tab: 'unassigned',
        filters: {q: '', client_id: '', priority: ''}
    };
    let calendars = [];
    let sortables = [];
    let queueDraggable = null;
    let queueSortable = null;
    let queueTickets = [];
    const openTechs = new Set();

    // ---------- small helpers ----------

    function pad(n) {
        return String(n).padStart(2, '0');
    }
    function ymd(date) {
        return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
    }
    function hm(date) {
        return pad(date.getHours()) + ':' + pad(date.getMinutes());
    }
    function parseYmd(text) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text || '');
        return m ? new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : null;
    }
    function addDays(date, n) {
        const d = new Date(date.getTime());
        d.setDate(d.getDate() + n);
        return d;
    }
    function weekStart(date) {
        const offset = (date.getDay() - firstDay + 7) % 7;
        return addDays(date, -offset);
    }
    // Safe in text and in "..." / '...' attributes
    function escapeText(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    // The service boards picked: all of them (null), "My boards", or one
    function pickedBoards() {
        if (state.serviceBoard === 'all' || !serviceBoards.length) {
            return null;
        }
        const mine = serviceBoards.filter(function (b) {
            return b.members.indexOf(Number(root.dataset.me)) !== -1;
        });
        const picked = state.serviceBoard === 'mine' ? mine : serviceBoards.filter(function (b) {
            return String(b.id) === String(state.serviceBoard);
        });
        return picked.length ? picked : null;
    }
    function boardById(id) {
        return serviceBoards.find(function (b) {
            return b.id === Number(id);
        });
    }
    function shownTechs() {
        const picked = state.boardTechs ? pickedBoards() : null;
        let members = picked ? [].concat.apply([], picked.map(function (b) { return b.members; })) : null;
        // A board nobody is on yet would leave the board empty - show everyone instead
        if (members && !members.length) {
            members = null;
        }
        return techs.filter(function (t) {
            return state.hidden.indexOf(t.id) === -1 && (!members || members.indexOf(t.id) !== -1);
        });
    }
    function techById(id) {
        return techs.find(function (t) {
            return t.id === Number(id);
        });
    }
    function lengthText(minutes) {
        return minutes % 60 ? (minutes >= 60 ? Math.floor(minutes / 60) + 'h ' : '') + (minutes % 60) + 'm' : (minutes / 60) + 'h';
    }
    function openModal(url, size) {
        const link = document.createElement('a');
        link.href = '#';
        link.className = 'ajax-modal d-none';
        link.dataset.modalUrl = url;
        if (size) {
            link.dataset.modalSize = size;
        }
        document.body.appendChild(link);
        link.click();
        link.remove();
    }
    function toast(message, type) {
        if (typeof itflowToast === 'function') {
            itflowToast(message, type);
        }
    }

    function request(method, params) {
        const body = new URLSearchParams(params);
        const url = 'mit_dispatch_ajax.php' + (method === 'GET' ? '?' + body.toString() : '');
        return fetch(url, method === 'GET' ? {credentials: 'same-origin'} : {
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () {
                return {error: 'Something went wrong - reload and try again.'};
            });
        });
    }

    let saveTimer = null;
    function saveState() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            request('POST', {state: 1, csrf_token: csrf, view: state.view, hidden: state.hidden.join(','), weekends: state.weekends ? 1 : 0,
                board: state.serviceBoard, board_techs: state.boardTechs ? 1 : 0});
        }, 600);
    }

    // ---------- the quick confirm ----------

    // <option>s from [{id, name}], with the current one selected
    function options(list, current) {
        return list.map(function (o) {
            return '<option value="' + escapeText(String(o.id)) + '"' + (String(o.id) === String(current) ? ' selected' : '') + '>' + escapeText(o.name) + '</option>';
        }).join('');
    }

    // The ticket's client, contact, priority, status, category and SLA, to put right while scheduling it (null: not shown)
    function detailsHtml(d) {
        if (!d || d.error) {
            return '';
        }
        const t = d.ticket;
        const contacts = [{id: 0, name: 'No contact'}].concat(d.contacts);
        const clients = (t.client_id ? [] : [{id: 0, name: 'No client (internal)'}]).concat(d.clients);
        return '<div class="col-12 border-top mt-3 pt-2 fw-semibold">Details</div>'
            + '<div class="col-sm-6"><label class="form-label mb-0" for="mdClient">Client</label><select class="form-select form-select-sm" id="mdClient">' + options(clients, t.client_id) + '</select></div>'
            + '<div class="col-sm-6"><label class="form-label mb-0" for="mdContactPick">Contact</label><select class="form-select form-select-sm" id="mdContactPick">' + options(contacts, t.contact_id) + '</select></div>'
            + '<div class="col-12" id="mdOther" hidden></div>'
            + '<div class="col-sm-4"><label class="form-label mb-0" for="mdPriority">Priority</label><select class="form-select form-select-sm" id="mdPriority">'
            + options(d.priorities.map(function (p) { return {id: p, name: p}; }), t.priority) + '</select></div>'
            + '<div class="col-sm-4"><label class="form-label mb-0" for="mdStatus">Status</label><select class="form-select form-select-sm" id="mdStatus">'
            + options(d.statuses, t.status_id) + '</select></div>'
            + '<div class="col-sm-4"><label class="form-label mb-0" for="mdCategory">Category</label><select class="form-select form-select-sm" id="mdCategory">'
            + options([{id: 0, name: 'No category'}].concat(d.categories), t.category_id) + '</select></div>'
            + (d.boards && d.boards.length ? '<div class="col-12"><label class="form-label mb-0" for="mdBoard">Board</label><select class="form-select form-select-sm" id="mdBoard">'
                + options(d.boards, d.board) + '</select></div>' : '')
            + '<div class="col-12"><label class="form-label mb-0" for="mdSla">SLA</label><select class="form-select form-select-sm" id="mdSla">'
            + '<option value="auto">Automatic - from the client\'s contract and priority</option>'
            + options([{id: 0, name: 'None'}].concat(d.slas), t.sla_id) + '</select>'
            + '<div class="form-text mt-0" id="mdClientNote" hidden>Moving it to another client makes its public replies internal, and its SLA is worked out again unless you pick one.</div></div>';
    }

    // ticket: {id, reference, subject, tech, minutes, onsite}; at: Date (or null); tech: id
    function confirmBooking(ticket, at, techId) {
        // The ticket's details come with the dialog, so they can be checked as it is scheduled
        return request('GET', {details: ticket.id}).then(function (details) {
            return confirmBookingDialog(ticket, at, techId, details);
        });
    }

    // The contact's other open tickets under the Contact box, each with a way to merge it with this one
    function loadOtherTickets(ticket) {
        const box = document.getElementById('mdOther');
        const contact = document.getElementById('mdContactPick');
        if (!box || !contact) {
            return;
        }
        if (!Number(contact.value)) {
            box.hidden = true;
            return;
        }
        // Tickets merge only within a client - not while this one is being moved to another (saved when scheduled)
        const client = document.getElementById('mdClient');
        const moving = client && client.value !== client.dataset.original;
        const off = moving ? ' disabled title="Schedule it first - it is moving to another client"' : '';
        request('GET', {contact_tickets: contact.value, except: ticket.id}).then(function (list) {
            list = Array.isArray(list) ? list : [];
            box.hidden = !list.length;
            box.innerHTML = '<div class="border rounded p-2 bg-body-tertiary"><div class="fw-semibold mb-1"><i class="fas fa-fw fa-layer-group me-1"></i>'
                + list.length + ' other open ticket' + (list.length === 1 ? '' : 's') + ' for this contact</div>'
                + list.map(function (o) {
                    return '<div class="d-flex align-items-start gap-2 py-1 border-top" data-md-other="' + o.id + '" data-reference="' + escapeText(o.reference) + '">'
                        + '<div class="flex-grow-1" style="min-width:0"><a href="' + escapeText(o.url) + '" target="_blank" rel="noopener">' + escapeText(o.reference) + '</a> '
                        + escapeText(o.subject) + '<div class="text-muted">' + escapeText([o.status, o.agent, o.scheduled ? 'scheduled ' + o.scheduled : ''].filter(Boolean).join(' · ')) + '</div></div>'
                        + '<button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-md-merge="here"' + (off || ' title="Close ' + escapeText(o.reference) + ' into ' + escapeText(ticket.reference) + '"') + '>Merge into this</button>'
                        + '<button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" data-md-merge="there"' + (off || ' title="Close ' + escapeText(ticket.reference) + ' into ' + escapeText(o.reference) + ' (not scheduled)"') + '>Merge this into it</button>'
                        + '</div>';
                }).join('')
                + '<div class="border-top pt-2 mt-1" data-md-merge-form hidden><div class="mb-1" data-md-merge-what></div>'
                + '<input type="text" class="form-control form-control-sm mb-1" placeholder="Reason - noted on both tickets" data-md-merge-reason maxlength="500">'
                + '<div class="d-flex align-items-center gap-2"><div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="mdMergeReplies" data-md-merge-replies>'
                + '<label class="form-check-label" for="mdMergeReplies">Move its replies too</label></div>'
                + '<button type="button" class="btn btn-sm btn-light ms-auto" data-md-merge-cancel>Cancel</button>'
                + '<button type="button" class="btn btn-sm btn-danger" data-md-merge-go>Merge and close</button></div></div></div>';
        });
    }

    // Merge buttons in that list (one handler for the box, as the list is redrawn)
    function bindMerge(ticket) {
        const box = document.getElementById('mdOther');
        if (!box) {
            return;
        }
        let pending = null;
        box.addEventListener('click', function (e) {
            const pick = e.target.closest('[data-md-merge]');
            const form = box.querySelector('[data-md-merge-form]');
            if (pick) {
                const row = pick.closest('[data-md-other]');
                const other = {id: Number(row.dataset.mdOther), reference: row.dataset.reference};
                pending = pick.dataset.mdMerge === 'here' ? {child: other, parent: ticket} : {child: ticket, parent: other};
                box.querySelector('[data-md-merge-what]').textContent = 'Merge ' + pending.child.reference + ' into ' + pending.parent.reference
                    + ' - ' + pending.child.reference + ' is closed' + (pending.child.id === ticket.id ? ' and not scheduled.' : '.');
                form.hidden = false;
                box.querySelector('[data-md-merge-reason]').focus();
                return;
            }
            if (e.target.closest('[data-md-merge-cancel]')) {
                form.hidden = true;
                pending = null;
                return;
            }
            if (e.target.closest('[data-md-merge-go]') && pending) {
                const reason = box.querySelector('[data-md-merge-reason]').value.trim();
                if (!reason) {
                    Swal.showValidationMessage('Give a reason for the merge');
                    return;
                }
                Swal.resetValidationMessage();
                request('POST', {merge: 1, csrf_token: csrf, child_id: pending.child.id, parent_id: pending.parent.id, comment: reason,
                    move_replies: box.querySelector('[data-md-merge-replies]').checked ? 1 : 0}).then(function (json) {
                    if (json.error) {
                        Swal.showValidationMessage(json.error);
                        return;
                    }
                    toast(json.message, 'success');
                    if (pending.child.id === ticket.id) {
                        // This ticket is closed now - nothing left to schedule
                        Swal.close();
                        refresh();
                        return;
                    }
                    pending = null;
                    loadOtherTickets(ticket);
                });
            }
        });
    }

    function confirmBookingDialog(ticket, at, techId, details) {
        const start = at || (function () {
            const d = new Date(state.date.getTime());
            d.setHours(9, 0, 0, 0);
            return d;
        })();
        const minutes = ticket.minutes || defaultMinutes;
        const lengths = lengthOptions.slice();
        if (lengths.indexOf(minutes) === -1) {
            lengths.push(minutes);
            lengths.sort(function (a, b) {
                return a - b;
            });
        }
        const html = '<div class="text-start small">'
            + (details && details.ticket
                ? '<div class="mb-2"><label class="form-label mb-0" for="mdSubject">Title</label><input type="text" class="form-control form-control-sm" id="mdSubject" maxlength="500" value="' + escapeText(details.ticket.subject || '') + '"></div>'
                : '<div class="mb-2 text-muted">' + escapeText(ticket.subject || '') + '</div>')
            + '<div class="row g-2">'
            + '<div class="col-12"><label class="form-label mb-0">Agent</label><select class="form-select form-select-sm" id="mdTech">'
            + techs.map(function (t) {
                return '<option value="' + t.id + '"' + (t.id === Number(techId) ? ' selected' : '') + '>' + escapeText(t.name) + '</option>';
            }).join('') + '</select></div>'
            + '<div class="col-5"><label class="form-label mb-0">Date</label><input type="date" class="form-control form-control-sm" id="mdDate" value="' + ymd(start) + '"></div>'
            + '<div class="col-4"><label class="form-label mb-0">Start</label><input type="time" step="900" class="form-control form-control-sm" id="mdStart" value="' + hm(start) + '"></div>'
            + '<div class="col-3"><label class="form-label mb-0">Length</label><select class="form-select form-select-sm" id="mdLength">'
            + lengths.map(function (m) {
                return '<option value="' + m + '"' + (m === minutes ? ' selected' : '') + '>' + lengthText(m) + '</option>';
            }).join('') + '</select></div>'
            + '<div class="col-12 d-flex gap-3 mt-2">'
            + '<div class="form-check"><input class="form-check-input" type="radio" name="mdWhere" id="mdOnsite" value="1"' + (ticket.onsite ? ' checked' : '') + '><label class="form-check-label" for="mdOnsite">Onsite</label></div>'
            + '<div class="form-check"><input class="form-check-input" type="radio" name="mdWhere" id="mdRemote" value="0"' + (ticket.onsite ? '' : ' checked') + '><label class="form-check-label" for="mdRemote">Remote</label></div>'
            + (notifyDefaults ? '</div>' + notifyHtml(ticket.onsite) : notifyHtml(ticket.onsite) + '</div>') + detailsHtml(details) + '</div></div>';

        return Swal.fire({
            theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light',
            titleText: 'Schedule ' + ticket.reference,
            html: html,
            width: details && !details.error ? '40rem' : undefined,
            didOpen: function () {
                const notify = document.getElementById('mdNotify');
                if (notify) {
                    let touched = false;
                    notify.addEventListener('input', function () {
                        touched = true;
                    });
                    ['mdOnsite', 'mdRemote'].forEach(function (id) {
                        document.getElementById(id).addEventListener('change', function () {
                            const set = notifyDefaults[document.getElementById('mdOnsite').checked ? '1' : '0'];
                            if (touched || !set) {
                                return;
                            }
                            notify.querySelectorAll('input[type=checkbox][data-who]').forEach(function (tick) {
                                tick.checked = !!set[tick.dataset.who];
                            });
                            document.getElementById('mdN_extra').value = (set.extra || []).join(', ');
                        });
                    });
                }
                // Another client: its contacts, and a word on what moving it does
                const client = document.getElementById('mdClient');
                if (!client) {
                    return;
                }
                client.dataset.original = client.value;
                bindMerge(ticket);
                loadOtherTickets(ticket);
                document.getElementById('mdContactPick').addEventListener('change', function () {
                    loadOtherTickets(ticket);
                });
                const original = client.dataset.original;
                client.addEventListener('change', function () {
                    document.getElementById('mdClientNote').hidden = client.value === original;
                    const contact = document.getElementById('mdContactPick');
                    contact.disabled = true;
                    request('GET', {contacts: client.value}).then(function (list) {
                        contact.innerHTML = options([{id: 0, name: 'No contact'}].concat(Array.isArray(list) ? list : []), Array.isArray(list) && list.length ? list[0].id : 0);
                        contact.disabled = false;
                        loadOtherTickets(ticket);
                    });
                });
            },
            showCancelButton: true,
            confirmButtonText: 'Schedule it',
            reverseButtons: true,
            buttonsStyling: false,
            focusConfirm: true,
            customClass: {confirmButton: 'btn btn-primary mx-1', cancelButton: 'btn btn-secondary mx-1'},
            preConfirm: function () {
                const date = document.getElementById('mdDate').value;
                const time = document.getElementById('mdStart').value;
                if (!date || !time) {
                    Swal.showValidationMessage('Pick the date and start time');
                    return false;
                }
                const post = {
                    book: 1, csrf_token: csrf, ticket_id: ticket.id,
                    tech_id: document.getElementById('mdTech').value,
                    start: date + ' ' + time + ':00',
                    minutes: document.getElementById('mdLength').value,
                    onsite: document.getElementById('mdOnsite').checked ? 1 : 0,
                    contact: document.getElementById('mdContact') ? (document.getElementById('mdContact').checked ? 1 : 0) : 1
                };
                const notify = document.getElementById('mdNotify');
                if (notify) {
                    post.mit_schedule_notify_present = 1;
                    notify.querySelectorAll('input[type=checkbox][data-who]').forEach(function (tick) {
                        if (tick.checked) {
                            post['mit_schedule_notify[' + tick.dataset.who + ']'] = 1;
                        }
                    });
                    post.mit_schedule_notify_extra = document.getElementById('mdN_extra').value;
                }
                const subject = document.getElementById('mdSubject');
                if (subject && !subject.value.trim()) {
                    Swal.showValidationMessage('The ticket needs a title');
                    return false;
                }
                if (document.getElementById('mdClient')) {
                    if (subject) {
                        post.detail_subject = subject.value;
                    }
                    post.detail_client_id = document.getElementById('mdClient').value;
                    post.detail_contact_id = document.getElementById('mdContactPick').value;
                    post.detail_priority = document.getElementById('mdPriority').value;
                    post.detail_status = document.getElementById('mdStatus').value;
                    post.detail_category_id = document.getElementById('mdCategory').value;
                    post.detail_sla = document.getElementById('mdSla').value;
                    if (document.getElementById('mdBoard')) {
                        post.detail_board = document.getElementById('mdBoard').value;
                    }
                }
                return request('POST', post).then(function (json) {
                    if (json.error) {
                        Swal.showValidationMessage(json.error);
                        return false;
                    }
                    return json;
                });
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                toast(result.value.message, 'success');
                if (result.value.conflicts && result.value.conflicts.length) {
                    toast('Same agent, overlapping: ' + result.value.conflicts.join('; '), 'warning');
                }
            }
            refresh();
            return result.isConfirmed;
        });
    }

    // A calendar entry (not a ticket) dragged to another time, or to another agent's column - it goes to them
    // (functions/mit_event_agents.php mitDispatchEventMove); repeating entries are not movable here
    function moveEntry(info, from, to) {
        const event = info.event;
        const end = event.end || new Date(event.start.getTime() + 3600000);
        return request('POST', {
            event_move: 1, csrf_token: csrf, event_id: event.extendedProps.event_id, all_day: event.allDay ? 1 : 0,
            start: ymd(event.start) + ' ' + hm(event.start) + ':00', end: ymd(end) + ' ' + hm(end) + ':00', from: from, to: to
        }).then(function (json) {
            toast(json.error || json.message, json.error ? 'error' : 'success');
            if (json.error) {
                info.revert();
            }
            refresh();
        });
    }

    function confirmUnbook(ticket) {
        return Swal.fire({
            theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light',
            icon: 'question',
            titleText: 'Take ' + ticket.reference + ' off the schedule?',
            text: 'The scheduled time is cancelled and the agent and contact are told. It goes back in the queue.',
            showCancelButton: true,
            confirmButtonText: 'Take it off',
            reverseButtons: true,
            buttonsStyling: false,
            customClass: {confirmButton: 'btn btn-danger mx-1', cancelButton: 'btn btn-secondary mx-1'}
        }).then(function (result) {
            if (result.isConfirmed) {
                return request('POST', {unbook: 1, csrf_token: csrf, ticket_id: ticket.id}).then(function (json) {
                    toast(json.error || json.message, json.error ? 'error' : 'success');
                    refresh();
                });
            }
            refresh();
        });
    }

    // A ticket from a board item or a queue element
    function ticketFromEvent(event) {
        const props = event.extendedProps || {};
        const minutes = event.end && event.start ? Math.round((event.end - event.start) / 60000) : defaultMinutes;
        return {
            id: props.ticket_id, reference: props.reference || (event.title || '').split(' ')[0], subject: event.title,
            minutes: minutes, onsite: (props.icon || '') === 'fa-car' ? 1 : 0
        };
    }
    function ticketFromQueue(el) {
        const found = queueTickets.find(function (t) {
            return t.id === Number(el.dataset.ticket);
        });
        return found || {id: Number(el.dataset.ticket), reference: el.dataset.reference, subject: '', minutes: defaultMinutes, onsite: 0};
    }

    // ---------- the queue ----------

    function loadQueue() {
        const params = Object.assign({queue: state.tab, board: state.serviceBoard}, state.filters);
        request('GET', params).then(function (json) {
            if (json.error) {
                queueEl.innerHTML = '<div class="text-danger small p-2">' + escapeText(json.error) + '</div>';
                return;
            }
            queueTickets = json.tickets || [];
            Object.keys(json.counts || {}).forEach(function (key) {
                const badge = root.querySelector('[data-md-count="' + key + '"]');
                if (badge) {
                    badge.textContent = json.counts[key] || '';
                }
            });
            root.querySelectorAll('[data-md-tab]').forEach(function (tab) {
                tab.classList.toggle('active', tab.dataset.mdTab === state.tab);
            });
            if (!queueTickets.length) {
                queueEl.innerHTML = '<div class="text-center text-muted small py-4"><i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>'
                    + (state.tab === 'left_behind' ? 'Nothing left behind - every open ticket booked before today has been moved on.' : 'Nothing to dispatch here.') + '</div>';
            } else {
                queueEl.innerHTML = queueTickets.map(function (t) {
                    return '<div class="mit-dq-item border rounded p-2 mb-2 small" data-ticket="' + t.id + '" data-reference="' + escapeText(t.reference) + '" data-priority="' + escapeText(t.priority) + '">'
                        + '<div class="d-flex align-items-center gap-1"><strong>' + escapeText(t.reference) + '</strong>'
                        + (t.priority ? '<span class="badge text-bg-light border">' + escapeText(t.priority) + '</span>' : '')
                        + (t.onsite ? '<i class="fas fa-car text-muted" title="Onsite"></i>' : '')
                        + boardTag(t.board)
                        + (state.tab === 'left_behind' && t.booked
                            ? '<span class="ms-auto text-danger text-nowrap" title="When it was booked">' + escapeText(t.booked) + '</span></div>'
                            : '<span class="ms-auto text-muted">' + escapeText(t.age) + '</span></div>')
                        + '<div class="text-truncate" title="' + escapeText(t.subject) + '">' + escapeText(t.subject) + '</div>'
                        + '<div class="text-muted text-truncate">' + escapeText(t.client) + (t.tech_name ? ' · <i class="fas fa-user"></i> ' + escapeText(t.tech_name) : '') + (t.status ? ' · ' + escapeText(t.status) : '') + '</div>'
                        + '</div>';
                }).join('');
            }
        });
    }

    // A queue card's board, when the queue shows more than one board
    function boardTag(id) {
        const b = boardById(id);
        const picked = pickedBoards();
        if (!b || (picked && picked.length === 1)) {
            return '';
        }
        return '<span class="badge border fw-normal text-truncate" style="max-width:7rem;border-color:' + escapeText(b.color) + '!important;color:' + escapeText(b.color) + '" title="Board">'
            + escapeText(b.name) + '</span>';
    }
    queueEl.addEventListener('click', function (e) {
        const item = e.target.closest('.mit-dq-item');
        if (item) {
            openModal('modals/ticket/ticket.php?id=' + item.dataset.ticket, 'lg');
        }
    });
    root.querySelectorAll('[data-md-tab]').forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            state.tab = tab.dataset.mdTab;
            loadQueue();
        });
    });
    let filterTimer = null;
    root.querySelectorAll('[data-md-filter]').forEach(function (input) {
        input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', function () {
            state.filters[input.dataset.mdFilter] = input.value;
            clearTimeout(filterTimer);
            filterTimer = setTimeout(loadQueue, 250);
        });
    });

    // ---------- the board ----------

    function range() {
        if (state.view === 'day') {
            return [state.date, state.date];
        }
        // Weekends hidden and it is one: the week coming, not the one ending
        let date = state.date;
        if (!state.weekends && (date.getDay() === 0 || date.getDay() === 6)) {
            date = addDays(date, date.getDay() === 6 ? 2 : 1);
        }
        const start = weekStart(date);
        return [start, addDays(start, 6)];
    }

    function loadItems(callback) {
        const r = range();
        request('GET', {items: 1, from: ymd(r[0]), to: ymd(r[1]), techs: shownTechs().map(function (t) {
            return t.id;
        }).join(',')}).then(function (items) {
            callback(Array.isArray(items) ? items : []);
        });
    }

    function teardown() {
        calendars.forEach(function (cal) {
            cal.destroy();
        });
        calendars = [];
        sortables.forEach(function (s) {
            s.destroy();
        });
        sortables = [];
        if (queueDraggable) {
            queueDraggable.destroy();
            queueDraggable = null;
        }
        if (queueSortable) {
            queueSortable.destroy();
            queueSortable = null;
        }
    }

    function render() {
        teardown();
        renderStats();
        const r = range();
        titleEl.textContent = state.view === 'workload' ? 'Open tickets right now'
            : state.view === 'day'
            ? state.date.toLocaleDateString(undefined, {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'})
            : r[0].toLocaleDateString(undefined, {month: 'short', day: 'numeric'}) + ' - ' + r[1].toLocaleDateString(undefined, {month: 'short', day: 'numeric', year: 'numeric'});
        // Workload is about now - no dates to page through
        root.querySelectorAll('[data-md-nav], [data-md-date]').forEach(function (el) {
            el.hidden = state.view === 'workload';
        });
        dateInput.value = ymd(state.date);
        root.querySelectorAll('[data-md-view]').forEach(function (b) {
            b.classList.toggle('active', b.dataset.mdView === state.view);
        });
        const count = root.querySelector('[data-md-tech-count]');
        count.textContent = shownTechs().length + '/' + techs.length;
        if (!shownTechs().length) {
            board.innerHTML = '<div class="text-center text-muted py-5">Pick some agents to show.</div>';
            return;
        }
        if (state.view === 'day') {
            renderDay();
        } else if (state.view === 'workload') {
            renderWorkload();
        } else {
            renderWeek();
        }
    }

    function refresh() {
        loadQueue();
        renderStats();
        if (state.view === 'day') {
            loadItems(function (items) {
                calendars.forEach(function (cal) {
                    cal.removeAllEvents();
                    cal.addEventSource(items.filter(function (item) {
                        return Number(item.extendedProps.tech) === Number(cal.mdTech);
                    }));
                });
                dayTotals(items);
            });
        } else if (state.view === 'workload') {
            renderWorkload();
        } else {
            renderWeek();
        }
    }

    function overQueue(jsEvent) {
        const rect = queueEl.getBoundingClientRect();
        return jsEvent && jsEvent.clientX >= rect.left && jsEvent.clientX <= rect.right && jsEvent.clientY >= rect.top && jsEvent.clientY <= rect.bottom;
    }

    // Day: a time grid per tech
    function renderDay() {
        board.innerHTML = '<div class="mit-dispatch-cols">' + shownTechs().map(function (t) {
            return '<div class="mit-dispatch-col" data-tech="' + t.id + '"><div class="mit-dispatch-col-head small fw-semibold text-truncate">'
                + '<span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background: ' + escapeText(t.color) + '"></span>'
                + escapeText(t.name) + ' <span class="fw-normal text-muted ms-1" data-md-booked></span></div><div data-md-cal></div></div>';
        }).join('') + '</div>';

        board.querySelectorAll('.mit-dispatch-col').forEach(function (col, index) {
            const techId = Number(col.dataset.tech);
            const cal = new FullCalendar.Calendar(col.querySelector('[data-md-cal]'), {
                initialView: 'timeGridDay',
                initialDate: ymd(state.date),
                headerToolbar: false,
                dayHeaders: false,
                allDaySlot: true,
                allDayText: '',
                // Side by side it fills its column, the same height as the queue (CSS in agent/mit_dispatch.php)
                height: window.matchMedia('(min-width: 992px)').matches ? '100%' : 'calc(100vh - 16rem)',
                slotMinTime: '06:00:00',
                slotMaxTime: '21:00:00',
                scrollTime: '07:30:00',
                slotDuration: '00:30:00',
                snapDuration: '00:15:00',
                nowIndicator: true,
                editable: canEdit,
                droppable: canEdit,
                eventDurationEditable: canEdit,
                businessHours: businessHours,
                // The times down the side once, on the first column. The other columns keep the label but
                // hidden: an empty one collapses FullCalendar's rows, so their hours would be squashed
                slotHeaderContent: function (arg) {
                    if (index === 0) {
                        return arg.text;
                    }
                    const label = document.createElement('span');
                    label.style.visibility = 'hidden';
                    label.textContent = arg.text;
                    return {domNodes: [label]};
                },
                // The time, a project's tech dot, an icon for what it is (onsite / remote, a meeting...), the title and
                // where / who - drawn like the calendar page's (agent/js/calendar.js, itflow-cal-* styles)
                eventContent: function (arg) {
                    const props = arg.event.extendedProps || {};
                    const wrap = document.createElement('div');
                    wrap.className = 'itflow-cal-event ' + (arg.event.allDay ? 'is-daygrid' : 'is-timegrid');
                    if (arg.timeText && !arg.event.allDay) {
                        const time = document.createElement('span');
                        time.className = 'itflow-cal-time';
                        time.textContent = arg.timeText;
                        wrap.appendChild(time);
                    }

                    if (props.icon) {
                        const icon = document.createElement('i');
                        icon.className = 'fas fa-fw ' + props.icon + ' itflow-cal-icon';
                        icon.title = props.icon === 'fa-car' ? 'Onsite' : (props.icon === 'fa-headset' ? 'Remote' : '');
                        wrap.appendChild(icon);
                    }
                    const title = document.createElement('span');
                    title.className = 'itflow-cal-title';
                    title.textContent = arg.event.title;
                    wrap.appendChild(title);
                    if (props.detail && !arg.event.allDay) {
                        const detail = document.createElement('div');
                        detail.className = 'itflow-cal-detail';
                        detail.textContent = props.detail;
                        wrap.appendChild(detail);
                    }
                    return {domNodes: [wrap]};
                },
                eventDidMount: function (info) {
                    const props = info.event.extendedProps || {};
                    info.el.dataset.kind = props.kind || '';
                    if (props.tech_color) { // the tech's color as a bar down the left ([data-mit-agent] in agent/mit_dispatch.php)
                        info.el.dataset.mitAgent = '1';
                        info.el.style.setProperty('--mit-agent', props.tech_color);
                    }
                    info.el.setAttribute('title', info.event.title + (props.detail ? '\n' + props.detail : ''));
                },
                eventClick: function (info) {
                    info.jsEvent.preventDefault();
                    const props = info.event.extendedProps || {};
                    if (props.modal) {
                        openModal(props.modal, 'lg');
                    }
                },
                // From the queue
                drop: function (info) {
                    const start = new Date(info.date.getTime());
                    if (info.allDay) {
                        start.setHours(9, 0, 0, 0);
                    }
                    confirmBooking(ticketFromQueue(info.draggedEl), start, techId);
                },
                // To another time on this tech
                eventDrop: function (info) {
                    if (info.event.extendedProps.kind === 'event') {
                        moveEntry(info, techId, techId);
                        return;
                    }
                    const ticket = ticketFromEvent(info.event);
                    const start = new Date(info.event.start.getTime());
                    if (info.event.allDay) {
                        start.setHours(9, 0, 0, 0);
                    }
                    confirmBooking(ticket, start, techId).then(function (ok) {
                        if (!ok) {
                            info.revert();
                        }
                    });
                },
                eventResize: function (info) {
                    if (info.event.extendedProps.kind === 'event') {
                        moveEntry(info, techId, techId);
                        return;
                    }
                    confirmBooking(ticketFromEvent(info.event), info.event.start, techId).then(function (ok) {
                        if (!ok) {
                            info.revert();
                        }
                    });
                },
                // From another tech's column
                eventReceive: function (info) {
                    if (info.event.extendedProps.kind === 'event') {
                        moveEntry(info, Number(info.event.extendedProps.tech), techId);
                        return;
                    }
                    const ticket = ticketFromEvent(info.event);
                    const start = new Date(info.event.start.getTime());
                    if (info.event.allDay) {
                        start.setHours(9, 0, 0, 0);
                    }
                    confirmBooking(ticket, start, techId);
                },
                // Back onto the queue
                eventDragStop: function (info) {
                    queueEl.classList.remove('is-drop-target');
                    if (overQueue(info.jsEvent) && info.event.extendedProps.kind !== 'event') {
                        confirmUnbook(ticketFromEvent(info.event));
                    }
                },
                eventDragStart: function () {
                    queueEl.classList.add('is-drop-target');
                }
            });
            cal.mdTech = techId;
            cal.render();
            calendars.push(cal);
        });

        if (canEdit && FullCalendar.Interaction && FullCalendar.Interaction.Draggable) {
            queueDraggable = new FullCalendar.Interaction.Draggable(queueEl, {
                itemSelector: '.mit-dq-item',
                eventData: function (item) {
                    const ticket = ticketFromQueue(item);
                    const minutes = ticket.minutes || defaultMinutes;
                    return {title: ticket.reference, duration: pad(Math.floor(minutes / 60)) + ':' + pad(minutes % 60), create: false, color: '#6c757d'};
                }
            });
        }
        refresh();
    }

    function dayTotals(items) {
        board.querySelectorAll('.mit-dispatch-col').forEach(function (col) {
            let minutes = 0;
            items.forEach(function (item) {
                if (Number(item.extendedProps.tech) === Number(col.dataset.tech) && ['ticket', 'helper'].indexOf(item.extendedProps.kind) !== -1 && !item.allDay) {
                    minutes += Math.round((new Date(item.end.replace(' ', 'T')) - new Date(item.start.replace(' ', 'T'))) / 60000);
                }
            });
            col.querySelector('[data-md-booked]').textContent = minutes ? lengthText(minutes) + ' scheduled' : '';
        });
    }

    // Week: techs by days
    function renderWeek() {
        teardown();
        const start = range()[0];
        const days = [];
        for (let i = 0; i < 7; i++) {
            const d = addDays(start, i);
            if (state.weekends || (d.getDay() !== 0 && d.getDay() !== 6)) {
                days.push(d);
            }
        }
        loadItems(function (items) {
            const today = ymd(new Date());
            let html = '<div class="table-responsive mit-dispatch-week-wrap"><table class="table table-bordered table-sm mb-0 mit-dispatch-week"><thead><tr><th style="min-width: 150px">Agent</th>';
            days.forEach(function (d) {
                html += '<th class="small text-center' + (ymd(d) === today ? ' text-primary' : '') + '">' + d.toLocaleDateString(undefined, {weekday: 'short', month: 'short', day: 'numeric'}) + '</th>';
            });
            html += '</tr></thead><tbody>';
            shownTechs().forEach(function (t) {
                let week = 0;
                const cells = days.map(function (d) {
                    const day = ymd(d);
                    const chips = items.filter(function (item) {
                        return Number(item.extendedProps.tech) === t.id && String(item.start).substring(0, 10) === day;
                    }).sort(function (a, b) {
                        return String(a.start).localeCompare(String(b.start));
                    });
                    const off = d.getDay() === 0 || d.getDay() === 6;
                    return '<td class="mit-dw-cell' + (off ? ' is-off' : '') + '" data-tech="' + t.id + '" data-date="' + day + '">' + chips.map(function (item) {
                        const props = item.extendedProps || {};
                        const startText = item.allDay ? 'All day' : String(item.start).substring(11, 16);
                        let minutes = 0;
                        if (!item.allDay && item.end) {
                            minutes = Math.round((new Date(String(item.end).replace(' ', 'T')) - new Date(String(item.start).replace(' ', 'T'))) / 60000);
                            if (['ticket', 'helper'].indexOf(props.kind) !== -1) {
                                week += minutes;
                            }
                        }
                        return '<div class="mit-dw-chip" style="background-color: ' + escapeText(item.color || '#6c757d') + (props.tech_color ? '; --mit-agent: ' + escapeText(props.tech_color) : '') + '"'
                            + (props.tech_color ? ' data-mit-agent="1"' : '') + ' data-kind="' + escapeText(props.kind || '') + '"'
                            + (props.kind === 'ticket' && item.editable ? ' data-ticket="' + props.ticket_id + '" data-start="' + escapeText(String(item.start).substring(0, 16)) + '" data-minutes="' + minutes + '" data-onsite="' + (props.icon === 'fa-car' ? 1 : 0) + '"' : '')
                            + ' data-modal="' + escapeText(props.modal || '') + '" title="' + escapeText(item.title + (props.detail ? '\n' + props.detail : '')) + '">'
                            + (props.icon ? '<i class="fas fa-fw ' + escapeText(props.icon) + ' me-1" aria-hidden="true"></i>' : '')
                            + escapeText(startText + ' ' + item.title) + '</div>';
                    }).join('') + '</td>';
                }).join('');
                html += '<tr><th class="small"><span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background: ' + escapeText(t.color) + '"></span>'
                    + escapeText(t.name) + '<div class="fw-normal text-muted">' + (week ? lengthText(week) + ' scheduled' : '') + '</div></th>' + cells + '</tr>';
            });
            html += '</tbody></table></div>';
            board.innerHTML = html;

            board.querySelectorAll('.mit-dw-chip').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    if (chip.dataset.modal) {
                        openModal(chip.dataset.modal, 'lg');
                    }
                });
            });
            if (typeof Sortable === 'undefined' || !canEdit) {
                return;
            }
            board.querySelectorAll('.mit-dw-cell').forEach(function (cell) {
                sortables.push(Sortable.create(cell, {
                    group: {name: 'mit-dispatch', pull: true, put: true},
                    sort: false,
                    draggable: '.mit-dw-chip[data-ticket], .mit-dq-item',
                    forceFallback: true,
                    onStart: function () {
                        queueEl.classList.add('is-drop-target');
                    },
                    onEnd: function () {
                        queueEl.classList.remove('is-drop-target');
                    },
                    onAdd: function (evt) {
                        const item = evt.item;
                        const techId = Number(cell.dataset.tech);
                        const date = cell.dataset.date;
                        let ticket;
                        let time = '09:00';
                        if (item.classList.contains('mit-dq-item')) {
                            ticket = ticketFromQueue(item);
                        } else {
                            ticket = {id: Number(item.dataset.ticket), reference: item.textContent.split(' ')[1] || '', subject: item.getAttribute('title') || '',
                                minutes: Number(item.dataset.minutes) || defaultMinutes, onsite: Number(item.dataset.onsite)};
                            time = (item.dataset.start || '').substring(11, 16) || time;
                        }
                        const at = new Date(date + 'T' + time + ':00');
                        confirmBooking(ticket, at, techId);
                    }
                }));
            });
            // The queue takes bookings back (and gives tickets out)
            queueSortable = Sortable.create(queueEl, {
                group: {name: 'mit-dispatch', pull: 'clone', put: function (to, from, el) {
                    return el.classList.contains('mit-dw-chip');
                }},
                sort: false,
                draggable: '.mit-dq-item',
                forceFallback: true,
                onAdd: function (evt) {
                    const chip = evt.item;
                    chip.remove();
                    confirmUnbook({id: Number(chip.dataset.ticket), reference: chip.textContent.split(' ')[1] || 'the ticket'});
                }
            });
        });
    }

    // ---------- Workload: each tech's open tickets ----------

    const SLA_PARTS = [
        {key: 'breached', label: 'Breached', short: 'breached', cls: 'bg-danger'},
        {key: 'at_risk', label: 'Close to the SLA', short: 'close to SLA', cls: 'bg-warning'},
        {key: 'running', label: 'On track', short: 'on track', cls: 'bg-success'},
        {key: 'paused', label: 'Paused (waiting)', short: 'paused', cls: 'bg-secondary'},
        {key: 'none', label: 'No SLA', short: 'no SLA', cls: 'bg-body-secondary'}
    ];
    const FLAG_CLASS = {behind: 'text-bg-danger', watch: 'text-bg-warning', overloaded: 'text-bg-warning', room: 'text-bg-success'};
    const FLAG_ICON = {behind: 'fa-exclamation-triangle', watch: 'fa-stopwatch', overloaded: 'fa-weight-hanging', room: 'fa-feather'};

    // This week's booked time against their working week, with today's under it
    function loadBar(t) {
        const today = t.capacity_today ? (t.booked_today ? lengthText(t.booked_today) : '0h') + ' of ' + lengthText(t.capacity_today) + ' today'
            : (t.booked_today ? lengthText(t.booked_today) + ' today' : 'Not a working day');
        if (!t.capacity_week) {
            return '<div class="small">' + (t.booked_week ? lengthText(t.booked_week) : '0h') + ' this week</div><div class="small text-muted">' + today + '</div>';
        }
        const pct = Math.round(t.booked_week / t.capacity_week * 100);
        const cls = pct > 100 ? 'bg-danger' : (pct >= 85 ? 'bg-warning' : 'bg-primary');
        return '<div class="progress mit-wl-bar" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100" title="' + pct + '% of their working week">'
            + '<div class="progress-bar ' + cls + '" style="width: ' + Math.min(100, pct) + '%"></div></div>'
            + '<div class="small mt-1">' + (t.booked_week ? lengthText(t.booked_week) : '0h') + ' of ' + lengthText(t.capacity_week) + ' this week</div>'
            + '<div class="small text-muted">' + today + '</div>';
    }

    function slaBar(sla, open) {
        if (!open) {
            return '<span class="text-muted small">No open tickets</span>';
        }
        return '<div class="progress-stacked mit-wl-bar">' + SLA_PARTS.filter(function (p) {
            return sla[p.key];
        }).map(function (p) {
            return '<div class="progress" role="progressbar" style="width: ' + (sla[p.key] / open * 100) + '%" title="' + sla[p.key] + ' ' + p.label + '"><div class="progress-bar ' + p.cls + '"></div></div>';
        }).join('') + '</div><div class="small mt-1 d-flex flex-wrap gap-2">' + SLA_PARTS.filter(function (p) {
            return sla[p.key] && p.key !== 'none';
        }).map(function (p) {
            return '<span' + (p.key === 'breached' ? ' class="text-danger fw-semibold"' : (p.key === 'at_risk' ? ' class="text-warning-emphasis fw-semibold"' : ' class="text-muted"')) + '>'
                + sla[p.key] + ' ' + p.short + '</span>';
        }).join('') + '</div>';
    }

    function countCell(n, cls, title) {
        return '<td class="text-center" title="' + escapeText(title) + '">' + (n ? '<span class="fw-semibold ' + cls + '">' + n + '</span>' : '<span class="text-muted">0</span>') + '</td>';
    }

    function workloadTickets(t) {
        if (!t.tickets.length) {
            return '<div class="text-muted small p-3">No open tickets.</div>';
        }
        const others = techs.filter(function (o) {
            return o.id !== t.id;
        });
        return '<div class="list-group list-group-flush">' + t.tickets.map(function (k) {
            const move = k.role === 'lead' && canEdit ? '<div class="dropdown flex-shrink-0"><button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Move to</button>'
                + '<div class="dropdown-menu dropdown-menu-end" style="max-height: 50vh; overflow-y: auto">' + others.map(function (o) {
                    return '<a class="dropdown-item" href="#" data-wl-move="' + o.id + '" data-ticket="' + k.id + '"><span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background: ' + escapeText(o.color) + '"></span>' + escapeText(o.name) + '</a>';
                }).join('') + '</div></div>' : '<span class="badge text-bg-light border flex-shrink-0">Helping</span>';
            return '<div class="list-group-item d-flex align-items-center gap-2 py-2' + (k.sla === 'breached' || k.overdue ? ' mit-wl-late' : '') + '" data-wl-ticket="' + k.id + '">'
                + '<div class="d-flex flex-column gap-1 align-items-start flex-shrink-0" style="width: 8rem">' + (k.sla_html || '<span class="badge rounded-pill bg-body-secondary text-body-secondary fw-normal">No SLA</span>') + (k.due_html || '') + '</div>'
                + '<div style="flex: 1 1 0; min-width: 0">'
                + '<div class="text-truncate"><a href="#" data-wl-open="' + k.id + '" class="fw-semibold">' + escapeText(k.reference) + '</a> '
                + (k.priority ? '<span class="badge text-bg-light border">' + escapeText(k.priority) + '</span> ' : '') + escapeText(k.subject) + '</div>'
                + '<div class="small text-muted text-truncate">' + escapeText(k.client) + ' · ' + escapeText(k.status)
                + ' · ' + (k.scheduled ? '<i class="far fa-calendar-check"></i> ' + escapeText(k.scheduled) : '<span class="text-warning-emphasis">Not scheduled</span>')
                + ' · ' + (k.quiet ? '<span class="text-warning-emphasis" title="No reply from us in a while">Last reply ' + escapeText(k.last) + '</span>' : 'Last reply ' + escapeText(k.last))
                + '</div>'
                + '</div>' + move + '</div>';
        }).join('') + '</div>';
    }

    let workloadData = null;
    function renderWorkload() {
        teardown();
        request('GET', {workload: 1, first_day: firstDay, board: state.serviceBoard, techs: shownTechs().map(function (t) {
            return t.id;
        }).join(',')}).then(function (json) {
            if (json.error) {
                board.innerHTML = '<div class="text-danger p-3">' + escapeText(json.error) + '</div>';
                return;
            }
            workloadData = json;
            const team = json.team || {};
            let html = '<div class="d-flex flex-wrap align-items-center gap-3 px-3 py-2 border-bottom small text-muted">'
                + '<span>Team average: <strong class="text-body">' + team.open + '</strong> open · <strong class="text-body">' + lengthText(team.booked_week || 0) + '</strong> scheduled this week</span>'
                + '<span class="ms-auto">Drop a ticket from the queue on an agent\'s name to give it to them. Click a row for their tickets.</span></div>'
                + '<div class="table-responsive"><table class="table table-hover align-middle mb-0 mit-wl"><thead class="small text-muted"><tr>'
                + '<th style="min-width: 170px">Agent</th><th class="text-center">Open</th><th style="min-width: 150px">SLA</th>'
                + '<th class="text-center" title="Past their own due date">Overdue</th><th class="text-center" title="Leading, with no time booked">Not scheduled</th>'
                + '<th class="text-center" title="No reply from us in 3+ days (not waiting on the client)">Quiet</th>'
                + '<th style="min-width: 140px">Scheduled</th></tr></thead><tbody>';
            json.techs.forEach(function (t) {
                const flags = t.flags.map(function (f) {
                    return '<span class="badge ' + (FLAG_CLASS[f.key] || 'text-bg-secondary') + '" title="' + escapeText(f.why) + '" data-wl-flag="' + f.key + '"><i class="fas ' + (FLAG_ICON[f.key] || 'fa-flag') + ' me-1"></i>' + escapeText(f.text) + '</span>';
                }).join(' ');
                const open = openTechs.has(t.id);
                html += '<tr class="mit-wl-row" data-wl-tech="' + t.id + '" aria-expanded="' + open + '">'
                    + '<td><div class="mit-wl-drop" data-tech="' + t.id + '"><div class="d-flex align-items-center gap-2"><i class="fas fa-chevron-' + (open ? 'down' : 'right') + ' text-muted small"></i>'
                    + '<span class="d-inline-block rounded-circle flex-shrink-0" style="width: 12px; height: 12px; background: ' + escapeText(t.color) + '"></span>'
                    + '<span class="fw-semibold">' + escapeText(t.name) + '</span></div>' + (flags ? '<div class="mt-1 d-flex flex-wrap gap-1">' + flags + '</div>' : '') + '</div></td>'
                    + '<td class="text-center"><span class="fs-5 fw-semibold">' + t.open + '</span>' + (t.helping ? '<div class="small text-muted">' + t.helping + ' helping</div>' : '')
                    + (t.oldest ? '<div class="small text-muted text-nowrap" title="Their oldest open ticket">oldest ' + escapeText(t.oldest) + '</div>' : '') + '</td>'
                    + '<td>' + slaBar(t.sla, t.open) + '</td>'
                    + countCell(t.overdue, 'text-danger', 'Past their own due date')
                    + countCell(t.unbooked, 'text-warning-emphasis', 'Leading, with no time booked')
                    + countCell(t.quiet, 'text-warning-emphasis', 'No reply from us in 3+ days')
                    + '<td>' + loadBar(t) + '</td></tr>'
                    + '<tr class="mit-wl-tickets" data-wl-tickets="' + t.id + '"' + (open ? '' : ' hidden') + '><td colspan="7" class="p-0 bg-body-tertiary">' + workloadTickets(t) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            board.innerHTML = html;
            bindWorkload();
        });
    }

    function workloadTicket(id) {
        let found = null;
        (workloadData ? workloadData.techs : []).forEach(function (t) {
            t.tickets.forEach(function (k) {
                if (k.id === Number(id) && k.role === 'lead') {
                    found = k;
                }
            });
        });
        return found;
    }

    // Give a ticket to an agent: an unbooked one straight away, a booked one through the Schedule dialog (so the invite moves too)
    function giveTo(ticket, techId, scheduledAt) {
        const tech = techById(techId);
        if (scheduledAt) {
            return confirmBooking(ticket, scheduledAt, techId);
        }
        return Swal.fire({
            theme: document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light',
            icon: 'question',
            titleText: 'Give ' + ticket.reference + ' to ' + (tech ? tech.name : 'them') + '?',
            text: 'They are told it is theirs. Schedule it to book a time as well.',
            showCancelButton: true,
            showDenyButton: true,
            confirmButtonText: 'Give it to them',
            denyButtonText: 'Schedule…',
            reverseButtons: true,
            buttonsStyling: false,
            customClass: {confirmButton: 'btn btn-primary mx-1', denyButton: 'btn btn-outline-primary mx-1', cancelButton: 'btn btn-secondary mx-1'}
        }).then(function (result) {
            if (result.isConfirmed) {
                return request('POST', {assign: 1, csrf_token: csrf, ticket_id: ticket.id, tech_id: techId}).then(function (json) {
                    toast(json.error || json.message, json.error ? 'error' : 'success');
                    refresh();
                });
            }
            if (result.isDenied) {
                return confirmBooking(ticket, null, techId);
            }
            refresh();
        });
    }

    function bindWorkload() {
        board.querySelectorAll('.mit-wl-row').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('a, button, .dropdown-menu')) {
                    return;
                }
                const id = Number(row.dataset.wlTech);
                const list = board.querySelector('[data-wl-tickets="' + id + '"]');
                const open = list.hidden;
                list.hidden = !open;
                row.setAttribute('aria-expanded', open);
                const icon = row.querySelector('.fa-chevron-right, .fa-chevron-down');
                icon.classList.toggle('fa-chevron-right', !open);
                icon.classList.toggle('fa-chevron-down', open);
                if (open) {
                    openTechs.add(id);
                } else {
                    openTechs.delete(id);
                }
            });
        });
        if (typeof Sortable === 'undefined' || !canEdit) {
            return;
        }
        board.querySelectorAll('.mit-wl-drop').forEach(function (zone) {
            sortables.push(Sortable.create(zone, {
                group: {name: 'mit-dispatch', pull: false, put: true},
                sort: false,
                draggable: '.mit-dq-item',
                forceFallback: true,
                onAdd: function (evt) {
                    evt.item.remove();
                    giveTo(ticketFromQueue(evt.item), Number(zone.dataset.tech), null);
                }
            }));
        });
        queueSortable = Sortable.create(queueEl, {
            group: {name: 'mit-dispatch', pull: 'clone', put: false},
            sort: false,
            draggable: '.mit-dq-item',
            forceFallback: true,
            onStart: function () {
                board.classList.add('mit-wl-dragging');
            },
            onEnd: function () {
                board.classList.remove('mit-wl-dragging');
            }
        });
    }

    function onWorkloadClick(e) {
        if (state.view !== 'workload') {
            return;
        }
        const open = e.target.closest('[data-wl-open]');
        if (open) {
            e.preventDefault();
            openModal('modals/ticket/ticket.php?id=' + open.dataset.wlOpen, 'lg');
            return;
        }
        const move = e.target.closest('[data-wl-move]');
        if (move) {
            e.preventDefault();
            const k = workloadTicket(move.dataset.ticket);
            if (!k) {
                return;
            }
            const ticket = {id: k.id, reference: k.reference, subject: k.subject, minutes: k.minutes || defaultMinutes, onsite: k.onsite};
            giveTo(ticket, Number(move.dataset.wlMove), k.schedule_start ? new Date(k.schedule_start.replace(' ', 'T')) : null);
        }
    }

    board.addEventListener('click', onWorkloadClick);

    // ---------- This week: under the board ----------

    const statsEl = root.querySelector('[data-md-stats]');
    let statsChart = null;

    function cssVar(el, name) {
        return getComputedStyle(el).getPropertyValue(name).trim();
    }

    function signed(n) {
        return (n > 0 ? '+' : '') + n;
    }

    function tile(label, value, sub, valueClass) {
        return '<div class="mit-ms-tile"><div class="mit-ms-label">' + label + '</div>'
            + '<div class="mit-ms-value' + (valueClass ? ' ' + valueClass : '') + '">' + value + '</div>'
            + '<div class="mit-ms-sub">' + sub + '</div></div>';
    }

    // Met / missed as one stacked bar, with the share and counts in words
    function slaRow(label, met, missed) {
        const judged = met + missed;
        if (!judged) {
            return '<div class="mb-3"><div class="d-flex"><span class="fw-semibold">' + label + '</span><span class="ms-auto text-muted small">None due yet this week</span></div></div>';
        }
        const pct = Math.round(met / judged * 100);
        return '<div class="mb-3"><div class="d-flex align-items-baseline mb-1"><span class="fw-semibold">' + label + '</span>'
            + '<span class="ms-auto fw-semibold">' + pct + '% met</span></div>'
            + '<div class="progress-stacked" style="height: .6rem">'
            + (met ? '<div class="progress" role="progressbar" style="width: ' + (met / judged * 100) + '%" title="' + met + ' met"><div class="progress-bar bg-success"></div></div>' : '')
            + (missed ? '<div class="progress" role="progressbar" style="width: ' + (missed / judged * 100) + '%" title="' + missed + ' missed"><div class="progress-bar bg-danger"></div></div>' : '')
            + '</div><div class="small mt-1"><i class="fas fa-check text-success me-1"></i>' + met + ' met'
            + (missed ? ' <span class="ms-2 text-danger"><i class="fas fa-times me-1"></i>' + missed + ' missed</span>' : '') + '</div></div>';
    }

    // Asked for by render() and by refresh() (often both at once) - one request for the lot
    let statsTimer = null;
    function renderStats() {
        clearTimeout(statsTimer);
        statsTimer = setTimeout(loadStats, 50);
    }

    function loadStats() {
        if (!statsEl) {
            return;
        }
        request('GET', {week_stats: 1, first_day: firstDay, board: state.serviceBoard, techs: shownTechs().map(function (t) {
            return t.id;
        }).join(',')}).then(function (json) {
            if (json.error) {
                statsEl.innerHTML = '<div class="text-danger small">' + escapeText(json.error) + '</div>';
                return;
            }
            const t = json.totals;
            const s = json.sla;
            const from = parseYmd(json.week[0]);
            const to = parseYmd(json.week[1]);
            root.querySelector('[data-ms-week]').textContent = from.toLocaleDateString(undefined, {month: 'short', day: 'numeric'}) + ' - ' + to.toLocaleDateString(undefined, {month: 'short', day: 'numeric'});

            const vsLast = function (now, last) {
                return now === last ? 'same as last week' : signed(now - last) + ' on last week (' + last + ')';
            };
            let html = '<div class="mit-ms-tiles mb-4">'
                + tile('<span class="mit-ms-key" style="background: var(--ms-in)"></span>Inbound', t.inbound, vsLast(t.inbound, t.last_inbound))
                + tile('<span class="mit-ms-key" style="background: var(--ms-out)"></span>Closed (velocity)', t.closed, vsLast(t.closed, t.last_closed) + ' · 4-week average ' + t.avg_closed)
                + tile('Backlog', signed(t.net), t.net > 0 ? 'more came in than closed' : (t.net < 0 ? 'closed more than came in' : 'in and closed even'), t.net > 0 ? 'text-warning-emphasis' : (t.net < 0 ? 'text-success' : ''))
                + tile('Open now', t.open, (s.breached_now ? '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>' + s.breached_now + ' SLA breached</span>' : 'none breached')
                    + (s.at_risk_now ? ' · <span class="text-warning-emphasis"><i class="fas fa-stopwatch me-1"></i>' + s.at_risk_now + ' close</span>' : ''))
                + '</div>';

            html += '<div class="row g-4 mb-4"><div class="col-lg-7"><div class="fw-semibold mb-2">Inbound vs closed, by day</div>'
                + '<div class="mit-ms-chart"><canvas data-ms-chart aria-label="Tickets in and closed each day this week" role="img"></canvas></div>'
                + '<details class="small mt-2"><summary class="text-muted">As a table</summary><table class="table table-sm mt-2 mb-0"><thead><tr><th>Day</th><th class="text-end">Inbound</th><th class="text-end">Closed</th></tr></thead><tbody>'
                + json.days.map(function (d) {
                    return '<tr><td>' + escapeText(d.label) + '</td><td class="text-end">' + d.inbound + '</td><td class="text-end">' + d.closed + '</td></tr>';
                }).join('') + '</tbody></table></details></div>'
                + '<div class="col-lg-5"><div class="fw-semibold mb-2">SLA this week</div>'
                + slaRow('First response', s.response_met, s.response_missed)
                + slaRow('Resolution', s.resolution_met, s.resolution_missed)
                + '<div class="small text-muted">Targets due this week (resolutions also when closed this week). Ones still running are not counted until they are met or missed.</div></div></div>';

            // Per agent: assigned this week against closed this week
            const max = Math.max(1, ...json.techs.map(function (a) {
                return Math.max(a.assigned, a.closed);
            }));
            html += '<div class="fw-semibold mb-2">By agent</div><div class="table-responsive"><table class="table table-sm align-middle mb-0 mit-ms-table"><thead class="small text-muted"><tr>'
                + '<th>Agent</th><th class="text-end"><span class="mit-ms-key" style="background: var(--ms-in)"></span>Assigned</th><th class="text-end"><span class="mit-ms-key" style="background: var(--ms-out)"></span>Closed</th>'
                + '<th style="width: 30%"></th><th class="text-end">Open now</th><th class="text-end" title="First response or resolution targets missed this week">SLA missed</th></tr></thead><tbody>'
                + (json.techs.length ? json.techs.map(function (a) {
                    return '<tr data-ms-tech="' + a.id + '"><td><span class="d-inline-block rounded-circle me-2" style="width: 10px; height: 10px; background: ' + escapeText(a.color) + '"></span>' + escapeText(a.name) + '</td>'
                        + '<td class="text-end">' + a.assigned + '</td><td class="text-end">' + a.closed + '</td>'
                        + '<td title="' + a.assigned + ' assigned · ' + a.closed + ' closed"><div class="mit-ms-minibar" style="width: ' + (a.assigned / max * 100) + '%; background: var(--ms-in)"></div>'
                        + '<div class="mit-ms-minibar" style="width: ' + (a.closed / max * 100) + '%; background: var(--ms-out)"></div></td>'
                        + '<td class="text-end">' + a.open + '</td>'
                        + '<td class="text-end">' + (a.sla_missed ? '<span class="text-danger fw-semibold"><i class="fas fa-times me-1"></i>' + a.sla_missed + '</span>' : '<span class="text-muted">0</span>') + '</td></tr>';
                }).join('') : '<tr><td colspan="6" class="text-muted">Pick some agents to show.</td></tr>')
                + '</tbody></table></div>';
            statsEl.innerHTML = html;
            drawStatsChart(json.days);
        });
    }

    function drawStatsChart(days) {
        if (statsChart) {
            statsChart.destroy();
            statsChart = null;
        }
        const canvas = statsEl.querySelector('[data-ms-chart]');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        const card = root.querySelector('.mit-stats');
        const text = cssVar(document.body, '--bs-secondary-color') || '#6c757d';
        const grid = cssVar(document.body, '--bs-border-color-translucent') || 'rgba(0,0,0,.1)';
        const bar = {borderRadius: 4, borderSkipped: 'start', categoryPercentage: 0.7, barPercentage: 0.9, maxBarThickness: 28};
        statsChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels: days.map(function (d) {
                    return d.label;
                }),
                datasets: [
                    Object.assign({label: 'Inbound', data: days.map(function (d) { return d.inbound; }), backgroundColor: cssVar(card, '--ms-in')}, bar),
                    Object.assign({label: 'Closed', data: days.map(function (d) { return d.closed; }), backgroundColor: cssVar(card, '--ms-out')}, bar)
                ]
            },
            options: {
                maintainAspectRatio: false,
                animation: false,
                interaction: {mode: 'index', intersect: false},
                plugins: {
                    legend: {position: 'top', align: 'start', labels: {color: text, boxWidth: 12, boxHeight: 12, useBorderRadius: true, borderRadius: 2}},
                    tooltip: {callbacks: {footer: function (items) {
                        const net = (items[0] ? items[0].parsed.y : 0) - (items[1] ? items[1].parsed.y : 0);
                        return net ? 'Backlog ' + signed(net) : '';
                    }}}
                },
                scales: {
                    x: {grid: {display: false}, ticks: {color: text}, border: {color: grid}},
                    y: {beginAtZero: true, ticks: {color: text, precision: 0}, grid: {color: grid}, border: {display: false}}
                }
            }
        });
    }

    // ---------- the toolbar ----------

    root.querySelectorAll('[data-md-view]').forEach(function (b) {
        b.addEventListener('click', function () {
            state.view = b.dataset.mdView;
            saveState();
            render();
        });
    });
    root.querySelectorAll('[data-md-nav]').forEach(function (b) {
        b.addEventListener('click', function () {
            const step = Number(b.dataset.mdNav);
            state.date = step === 0 ? new Date() : addDays(state.date, step * (state.view === 'day' ? 1 : 7));
            render();
        });
    });
    dateInput.addEventListener('change', function () {
        const d = parseYmd(dateInput.value);
        if (d) {
            state.date = d;
            render();
        }
    });
    root.querySelectorAll('[data-md-tech]').forEach(function (box) {
        box.addEventListener('change', function () {
            const id = Number(box.value);
            state.hidden = state.hidden.filter(function (h) {
                return h !== id;
            });
            if (!box.checked) {
                state.hidden.push(id);
            }
            saveState();
            render();
        });
    });
    const serviceBoardPick = root.querySelector('[data-md-service-board]');
    if (serviceBoardPick) {
        serviceBoardPick.addEventListener('change', function () {
            state.serviceBoard = serviceBoardPick.value;
            saveState();
            render();
            if (state.view !== 'day') {
                loadQueue();
            }
            loadStats();
        });
    }
    const boardTechsBox = root.querySelector('[data-md-board-techs]');
    if (boardTechsBox) {
        boardTechsBox.addEventListener('change', function () {
            state.boardTechs = boardTechsBox.checked;
            saveState();
            render();
            loadStats();
        });
    }
    root.querySelector('[data-md-weekends]').addEventListener('change', function (e) {
        state.weekends = e.target.checked;
        saveState();
        if (state.view === 'week') {
            render();
        }
    });
    // A ticket changed in its quick look - the queue and board follow
    document.addEventListener('hidden.bs.modal', function () {
        refresh();
    });

    // render() loads the queue (through refresh) in the day view; the week and workload views need it here
    render();
    if (state.view !== 'day') {
        loadQueue();
    }
})();
