// Mountaineer IT - Quick ticket (functions/mit_quick_ticket.php mitQuickTicketNav; requests to agent/mit_quick_ticket_ajax.php).
// The top bar's + opens a window: client, contact (the client's primary to start with), the issue. Picking the contact lists their
// tickets still open, with "Work on this one" to go there instead. Make ticket posts ITFlow's own New Ticket form (add_ticket) with
// mit_quick_ticket, and with when the timer should count from - this browser's clock, as ITFlow's timer is this browser's.
// What people typed is drawn as text; the status badges come from ITFlow's ticketStatusHtml on the server.
(function () {
    'use strict';
    const modal = document.getElementById('mitQuickTicket');
    const opener = document.querySelector('[data-mit-quick-ticket-open]');
    if (!modal || !opener || modal.dataset.ready) {
        return;
    }
    modal.dataset.ready = '1';
    document.body.appendChild(modal); // out of the navbar, so the window sits over the page
    modal.hidden = false;

    const endpoint = modal.dataset.endpoint;
    const timerMode = modal.dataset.timer;
    const showOpen = modal.dataset.openTickets === '1';
    const form = modal.querySelector('[data-mit-quick-ticket-form]');
    const client = form.querySelector('[data-mit-quick-client]');
    const contact = form.querySelector('[data-mit-quick-contact]');
    const openBox = form.querySelector('[data-mit-quick-open-tickets]');
    const subject = form.querySelector('[data-mit-quick-subject]');
    const details = form.querySelector('[data-mit-quick-details]');
    const detailsHtml = form.querySelector('[data-mit-quick-details-html]');
    const timer = form.querySelector('[data-mit-quick-timer]');
    const error = form.querySelector('[data-mit-quick-error]');
    const make = form.querySelector('[data-mit-quick-make]');
    let openedAt = 0;
    let clientsLoaded = false;
    let contactRequest = 0;
    let openRequest = 0;

    const escape = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const get = (params) => fetch(endpoint + '?' + new URLSearchParams(params), { credentials: 'same-origin' }).then((r) => r.json());

    // The client of the page we are on (a client's pages carry client_id), to start on
    function pageClient() {
        const id = new URLSearchParams(location.search).get('client_id');
        return id && /^\d+$/.test(id) ? id : '';
    }

    function loadClients() {
        if (clientsLoaded) {
            return Promise.resolve();
        }
        return get({ clients: 1 }).then((data) => {
            client.innerHTML = data.html || '<option value="">No clients</option>';
            clientsLoaded = true;
        });
    }

    function loadContacts() {
        const id = client.value;
        const mine = ++contactRequest;
        openBox.innerHTML = '';
        if (!id) {
            contact.innerHTML = '<option value="0">Pick the client first</option>';
            contact.disabled = true;
            return;
        }
        contact.disabled = true;
        get({ contacts: 1, client_id: id }).then((data) => {
            if (mine !== contactRequest) {
                return;
            }
            contact.innerHTML = data.html || '<option value="0">- Nobody -</option>';
            contact.disabled = false;
            contact.value = String(data.selected || 0);
            loadOpen();
        });
    }

    function loadOpen() {
        const mine = ++openRequest;
        openBox.innerHTML = '';
        if (!showOpen || !client.value || !contact.value || contact.value === '0') {
            return;
        }
        get({ open: 1, client_id: client.value, contact_id: contact.value }).then((data) => {
            if (mine !== openRequest) {
                return;
            }
            const tickets = data.tickets || [];
            if (!tickets.length) {
                openBox.innerHTML = '<div class="small text-body-secondary mb-3" data-mit-quick-none><i class="fas fa-check-circle text-success me-1"></i>No open tickets for this contact.</div>';
                return;
            }
            openBox.innerHTML = '<div class="alert alert-warning py-2 small mb-3" data-mit-quick-open-list>'
                + '<div class="fw-semibold mb-1"><i class="fas fa-layer-group me-1"></i>Already open for this contact (' + tickets.length + ')</div>'
                + tickets.map((t) => '<div class="d-flex align-items-center gap-2 py-1 border-top" data-mit-quick-open-ticket="' + t.id + '">'
                    + '<div class="text-truncate me-auto"><a href="' + escape(t.url) + '" class="fw-semibold">' + escape(t.reference) + '</a> ' + escape(t.subject)
                    + '<div class="text-body-secondary">' + (t.assigned ? (t.mine ? 'Yours' : escape(t.assigned)) : 'Nobody has it') + ' · ' + escape(t.updated) + '</div></div>'
                    + '<span class="flex-shrink-0">' + t.status_html + '</span>'
                    + '<button type="button" class="btn btn-sm btn-outline-dark flex-shrink-0" data-mit-quick-work="' + t.id + '" data-url="' + escape(t.url) + '">Work on this one</button>'
                    + '</div>').join('')
                + '<div class="text-body-secondary mt-1">If it is the same problem, add to that ticket instead.</div></div>';
        });
    }

    // Start the ticket's timer the way ITFlow keeps it (agent/js/ticket_time_tracking.js) - only one not already there
    function startTimer(ticketId, from) {
        try {
            if (!localStorage.getItem(ticketId + '-startTime') && !localStorage.getItem(ticketId + '-pausedTime')) {
                localStorage.setItem(ticketId + '-startTime', String(from));
                localStorage.setItem('ticket-timer-running-' + ticketId, 'true');
            }
        } catch (e) {
            // storage off - the ticket opens without a running timer
        }
    }

    function timerStart() {
        return timerMode === 'open' && openedAt ? openedAt : Date.now();
    }

    function open() {
        form.reset();
        error.textContent = '';
        make.disabled = false;
        openBox.innerHTML = '';
        openedAt = Date.now();
        bootstrap.Modal.getOrCreateInstance(modal).show();
        loadClients().then(() => {
            const preset = pageClient();
            if (preset && client.querySelector('option[value="' + preset + '"]')) {
                client.value = preset;
            } else {
                client.value = '';
            }
            loadContacts();
        }).catch(() => {
            error.textContent = 'The client list could not be loaded - try again.';
        });
    }

    opener.addEventListener('click', (event) => {
        event.preventDefault();
        open();
    });
    modal.addEventListener('shown.bs.modal', () => {
        (client.value ? subject : client).focus();
    });
    client.addEventListener('change', loadContacts);
    contact.addEventListener('change', loadOpen);

    openBox.addEventListener('click', (event) => {
        const button = event.target.closest('[data-mit-quick-work]');
        if (!button) {
            return;
        }
        if (timerMode !== 'off') {
            startTimer(button.dataset.mitQuickWork, timerStart());
        }
        location.href = button.dataset.url;
    });

    form.addEventListener('submit', (event) => {
        error.textContent = '';
        if (!client.value) {
            event.preventDefault();
            error.textContent = 'Pick the client.';
            return;
        }
        if (!subject.value.trim()) {
            event.preventDefault();
            error.textContent = 'Say what the issue is.';
            subject.focus();
            return;
        }
        // The detail as ITFlow keeps a ticket's details - HTML, made from the text typed (escaped, line breaks kept)
        const text = details.value.trim();
        detailsHtml.value = text ? '<p>' + escape(text).replace(/\r?\n/g, '<br>') + '</p>' : '';
        timer.value = timerMode === 'off' ? '' : String(timerStart());
        // A disabled button's name is not sent - ITFlow's handler needs add_ticket, so send it as a field
        if (!form.querySelector('input[name="add_ticket"]')) {
            const field = document.createElement('input');
            field.type = 'hidden';
            field.name = 'add_ticket';
            field.value = '1';
            form.appendChild(field);
        }
        make.disabled = true;
    });
})();
