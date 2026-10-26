/*
 * Mountaineer IT - the calendar's "Left behind" list (functions/mit_left_behind.php): open tickets booked on a day that has
 * gone. Follows the calendar's Whose picker (Everyone = everyone's) and client. Each row carries the same data as a To
 * schedule row, so calendar.js's own drop books it again (keeping its length); a click opens the quick look.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const root = document.getElementById('itflowCalendar');
    const list = document.getElementById('mitLeftBehind');
    if (!root || !list) {
        return;
    }
    const clientId = Number(root.dataset.clientId) || 0;
    const agentSelect = document.getElementById('calendarAgent');
    const count = document.getElementById('mitLeftBehindCount');
    const hint = document.getElementById('mitLeftBehindHint');
    const tones = {Urgent: 'danger', High: 'warning', Medium: 'primary', Low: 'secondary'};

    function agentId() {
        return agentSelect ? Number(agentSelect.value) || 0 : 0;
    }

    const card = document.getElementById('mitLeftBehindCard');

    function recount() {
        const n = list.children.length;
        count.textContent = n ? String(n) : 'None';
        count.className = 'badge rounded-pill ms-auto ' + (n ? 'text-bg-danger' : 'bg-body-secondary text-body-secondary');
        // Nothing left behind: just the header line (functions/mit_left_behind.php gives the calendar its full height back)
        if (card) {
            card.classList.toggle('mit-empty', n === 0);
            window.dispatchEvent(new Event('resize'));
        }
        hint.textContent = n
            ? 'Open tickets booked on a day that has gone - drag one onto the calendar to book it again.'
            : 'Nothing left behind - every open ticket booked before today has been moved on.';
    }

    function openModal(url) {
        const link = document.createElement('a');
        link.href = '#';
        link.className = 'ajax-modal d-none';
        link.dataset.modalUrl = url;
        link.dataset.modalSize = 'lg';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    function load() {
        const params = new URLSearchParams({mit_left_behind: 1, user_id: agentId(), client_id: clientId});
        fetch('ajax.php?' + params.toString(), {credentials: 'same-origin'}).then(function (res) {
            return res.json();
        }).then(function (json) {
            list.innerHTML = '';
            (json.tickets || []).forEach(function (ticket) {
                const item = document.createElement('div');
                // itflow-cal-unscheduled: the look of a To schedule row
                item.className = 'itflow-cal-unscheduled mit-left-behind-item';
                item.dataset.ticket = ticket.id;
                item.dataset.reference = ticket.reference;
                item.dataset.title = ticket.reference + ' ' + ticket.subject;
                item.dataset.onsite = ticket.onsite;
                item.title = 'Booked ' + ticket.booked + ' - drag onto the calendar to book it again, click for a quick look';

                const dot = document.createElement('span');
                dot.className = 'itflow-cal-priority bg-' + (tones[ticket.priority] || 'secondary');
                dot.title = ticket.priority;
                item.appendChild(dot);

                const text = document.createElement('div');
                text.className = 'min-w-0 flex-grow-1';
                const line = document.createElement('div');
                line.className = 'text-truncate small fw-semibold';
                line.textContent = ticket.reference + ' ' + ticket.subject;
                const sub = document.createElement('div');
                sub.className = 'text-truncate small text-body-secondary';
                sub.textContent = [clientId ? '' : ticket.client, agentId() ? '' : ticket.agent, 'booked ' + ticket.booked].filter(Boolean).join(' · ');
                text.appendChild(line);
                text.appendChild(sub);
                item.appendChild(text);

                const days = document.createElement('span');
                days.className = 'badge text-bg-danger mit-left-behind-days';
                days.textContent = ticket.days + 'd';
                days.title = ticket.days + ' day' + (ticket.days === 1 ? '' : 's') + ' since it was booked';
                item.appendChild(days);

                item.addEventListener('click', function () {
                    openModal('modals/ticket/ticket.php?id=' + ticket.id);
                });
                list.appendChild(item);
            });
            recount();
        }).catch(function () {
            hint.textContent = 'The list could not be loaded - reload the page.';
        });
    }

    // calendar.js removes a row once it is booked again; refetch so the list stays true
    new MutationObserver(recount).observe(list, {childList: true});
    if (window.FullCalendar && FullCalendar.Interaction && FullCalendar.Interaction.Draggable && root.dataset.canSchedule === '1') {
        new FullCalendar.Interaction.Draggable(list, {
            itemSelector: '.mit-left-behind-item',
            eventData: function (item) {
                return {title: item.dataset.title, duration: '01:00', create: false, color: '#dc3545'};
            }
        });
    }
    if (agentSelect) {
        agentSelect.addEventListener('change', load);
    }
    // The ticket quick look can resolve or reschedule a ticket
    document.addEventListener('hidden.bs.modal', load);
    load();
});
