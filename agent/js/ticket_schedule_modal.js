// The ticket's Schedule dialog (agent/modals/ticket/ticket_edit_schedule.php):
// as a day is picked, what the assigned agent already has on it - their other
// scheduled tickets and the events they are on - so a visit is not booked on
// top of another. Wrapped in an IIFE because the modal can open more than once.
// Mountaineer IT: also used by our additional-agent modal (agent/modals/ticket/mit_ticket_tech.php);
// the Agent picker (#scheduleTech) switches whose day is shown, and "How long" sets the end
// (functions/mit_calendar.php).

(function () {

    const panel = document.getElementById('scheduleAgentDay');
    const field = document.getElementById('scheduleDateTime');
    if (!panel || !field) {
        return;
    }
    const title = panel.querySelector('.js-day-title');
    const list = panel.querySelector('.js-day-items');
    const open = panel.querySelector('.js-day-open');
    let shown = '';

    function load() {
        const date = (field.value || '').slice(0, 10);
        if (!Number(panel.dataset.user) || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            panel.hidden = true;
            return;
        }
        if (date === shown) {
            return;
        }
        shown = date;
        const day = new Date(date + 'T12:00:00');
        title.textContent = panel.dataset.name + "'s day - " + day.toLocaleDateString(undefined, {weekday: 'short', month: 'short', day: 'numeric'});
        open.href = 'calendar.php?date=' + date;
        const asked = date + '|' + panel.dataset.user;
        itflowGet('ajax.php', {calendar_day: 1, date: date, user_id: panel.dataset.user, ticket_id: panel.dataset.ticket}, function (text) {
            // Mountaineer IT: a slower answer for a day (or tech) picked before this one - leave the newer one showing
            if (asked !== shown + '|' + panel.dataset.user) {
                return;
            }
            let items = [];
            try {
                items = JSON.parse(text).items || [];
            } catch (e) {
                items = [];
            }
            list.innerHTML = '';
            if (!items.length) {
                const none = document.createElement('div');
                none.className = 'text-body-secondary';
                none.textContent = 'Nothing else booked';
                list.appendChild(none);
            }
            items.forEach(function (item) {
                const row = document.createElement('div');
                row.className = 'd-flex gap-2 py-1 border-top';
                const time = document.createElement('span');
                time.className = 'text-body-secondary text-nowrap';
                time.style.minWidth = '9.5rem';
                time.textContent = item.time;
                const what = document.createElement('span');
                what.className = 'text-truncate';
                what.textContent = item.title;
                row.appendChild(time);
                row.appendChild(what);
                list.appendChild(row);
            });
            panel.hidden = false;
        });
    }

    field.addEventListener('change', load);
    field.addEventListener('input', load);

    // Mountaineer IT: the day shown is the chosen tech's
    const tech = document.getElementById('scheduleTech');
    if (tech) {
        tech.addEventListener('change', function () {
            const option = tech.options[tech.selectedIndex];
            panel.dataset.user = tech.value;
            panel.dataset.name = option ? (option.dataset.name || option.textContent.replace(' (me)', '')) : '';
            shown = '';
            load();
        });
    }

    load();

})();
