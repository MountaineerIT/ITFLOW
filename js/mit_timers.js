/*
 * Mountaineer IT - the ticket timers in this browser, in the top bar next to the bell
 * (includes/mit_timers_nav.php), so a running timer is never lost on a ticket you left.
 *
 * ITFlow keeps each ticket's timer in localStorage (agent/js/ticket_time_tracking.js):
 *   "<ticket id>-startTime"   when it last started counting (ms), only while running
 *   "<ticket id>-pausedTime"  seconds counted before that
 *   "ticket-timer-running-<ticket id>"
 * This reads and changes those same keys. On the ticket's own page it presses that page's
 * buttons instead, so the page and the list never disagree.
 */
(function () {
    'use strict';

    var root = document.getElementById('mitTimers');
    if (!root) {
        return;
    }
    // Styles here rather than in the nav markup, where only list items may sit
    var style = document.createElement('style');
    style.textContent = '#mitTimers.is-running > .nav-link { color: #ffc107; }'
        + '#mitTimers.is-running .fa-stopwatch { animation: mit-timer-pulse 1.6s ease-in-out infinite; }'
        + '@keyframes mit-timer-pulse { 50% { opacity: .45; } }'
        + '.mit-timers-panel .mit-timer-row { padding: .6rem 1rem; border-bottom: 1px solid var(--bs-border-color); }'
        + '.mit-timers-panel .mit-timer-row:last-child { border-bottom: 0; }'
        + '.mit-timers-panel .mit-timer-time { font-variant-numeric: tabular-nums; font-weight: 600; }'
        + '@media (prefers-reduced-motion: reduce) { #mitTimers.is-running .fa-stopwatch { animation: none; } }';
    document.head.appendChild(style);
    var badge = root.querySelector('[data-timers-badge]');
    var list = root.querySelector('[data-timers-list]');
    var tickets = {};       // ticket id -> {reference, subject, client, client_id, open}, or null when not found
    var fetchedAt = 0;
    var ticking = null;

    function store() {
        try {
            return window.localStorage;
        } catch (e) {
            return null;
        }
    }

    /** Every timer with time on it (or running): [{id, running, seconds}], running first, then most time. */
    function timers() {
        var ls = store();
        if (!ls) {
            return [];
        }
        var ids = {};
        for (var i = 0; i < ls.length; i++) {
            var m = /^(\d+)-(startTime|pausedTime)$/.exec(ls.key(i) || '');
            if (m) {
                ids[m[1]] = true;
            }
        }
        var out = [];
        Object.keys(ids).forEach(function (id) {
            var start = parseInt(ls.getItem(id + '-startTime') || '0', 10);
            var paused = parseInt(ls.getItem(id + '-pausedTime') || '0', 10);
            var seconds = paused + (start ? Math.max(0, Math.floor((Date.now() - start) / 1000)) : 0);
            if (start || seconds > 0) {
                out.push({id: id, running: !!start, seconds: seconds});
            }
        });
        out.sort(function (a, b) {
            return (b.running - a.running) || (b.seconds - a.seconds);
        });
        return out;
    }

    function hms(total) {
        var h = Math.floor(total / 3600);
        var m = Math.floor((total % 3600) / 60);
        var s = total % 60;
        return h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    /** The ticket page this is, if any. */
    function pageTicketId() {
        if (!/\/agent\/ticket\.php$/.test(window.location.pathname)) {
            return null;
        }
        return new URLSearchParams(window.location.search).get('ticket_id');
    }

    // On the ticket's own page its Start/Stop button does it, so the page's fields follow - but only when the
    // timer is in the state we expect (another tab may have changed it), as the button toggles
    function pause(id) {
        var ls = store();
        if (pageTicketId() === id && document.getElementById('startStopTimer')) {
            if (ls.getItem(id + '-startTime')) {
                document.getElementById('startStopTimer').click();
            }
            return;
        }
        var t = timers().filter(function (x) { return x.id === id; })[0];
        ls.setItem(id + '-pausedTime', String(t ? t.seconds : 0));
        ls.removeItem(id + '-startTime');
        ls.setItem('ticket-timer-running-' + id, 'false');
    }

    function resume(id) {
        var ls = store();
        if (pageTicketId() === id && document.getElementById('startStopTimer')) {
            if (!ls.getItem(id + '-startTime')) {
                document.getElementById('startStopTimer').click();
            }
            return;
        }
        ls.setItem(id + '-startTime', String(Date.now()));
        ls.setItem('ticket-timer-running-' + id, 'true');
    }

    function discard(id) {
        var ls = store();
        ls.removeItem(id + '-startTime');
        ls.removeItem(id + '-pausedTime');
        ls.removeItem('ticket-timer-running-' + id);
        if (pageTicketId() === id) {
            // The page's own timer fields still show the time - start it clean
            window.location.reload();
        }
    }

    /** The badge and icon: how many timers, and whether any is counting now. */
    function refreshBadge() {
        var all = timers();
        root.hidden = all.length === 0;
        var running = all.filter(function (t) { return t.running; }).length;
        root.classList.toggle('is-running', running > 0);
        badge.textContent = all.length;
        badge.className = 'badge rounded-pill navbar-badge ' + (running ? 'bg-warning text-dark' : 'bg-light text-dark');
        root.querySelector('.nav-link').title = running
            ? running + ' ticket timer' + (running === 1 ? '' : 's') + ' running' + (all.length > running ? ', ' + (all.length - running) + ' paused' : '')
            : all.length + ' paused ticket timer' + (all.length === 1 ? '' : 's');
        if (!all.length && ticking) {
            stopTicking();
        }
        return all;
    }

    function button(icon, title, cls, onClick) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + cls;
        b.title = title;
        b.setAttribute('aria-label', title);
        b.innerHTML = '<i class="fas fa-fw ' + icon + '"></i>';
        b.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            onClick();
            render();
        });
        return b;
    }

    function render() {
        var all = refreshBadge();
        list.textContent = '';
        if (!all.length) {
            var none = document.createElement('div');
            none.className = 'p-3 text-body-secondary small';
            none.textContent = 'No timers running.';
            list.appendChild(none);
            return;
        }
        all.forEach(function (t) {
            var info = tickets[t.id];
            var row = document.createElement('div');
            row.className = 'mit-timer-row d-flex align-items-center gap-2';
            row.dataset.timer = t.id;

            var text = document.createElement('div');
            text.className = 'flex-grow-1 min-w-0';
            var link = document.createElement('a');
            // Inside its client when it has one, as ITFlow's ticketUrl() does
            link.href = '/agent/ticket.php?' + (info && info.client_id ? 'client_id=' + encodeURIComponent(info.client_id) + '&' : '') + 'ticket_id=' + encodeURIComponent(t.id);
            link.className = 'd-block text-truncate fw-semibold';
            link.textContent = info ? info.reference + ' - ' + info.subject : 'Ticket #' + t.id;
            text.appendChild(link);
            var sub = document.createElement('div');
            sub.className = 'small text-body-secondary text-truncate';
            if (info === null) {
                sub.textContent = 'Deleted, or not one you can see';
            } else if (info) {
                sub.textContent = info.client || 'No client';
                if (!info.open) {
                    var closed = document.createElement('span');
                    closed.className = 'badge text-bg-secondary ms-1';
                    closed.textContent = 'Closed';
                    sub.appendChild(closed);
                }
            }
            text.appendChild(sub);
            row.appendChild(text);

            var time = document.createElement('span');
            time.className = 'mit-timer-time ' + (t.running ? 'text-warning-emphasis' : 'text-body-secondary');
            time.dataset.timerTime = t.id;
            time.textContent = hms(t.seconds);
            row.appendChild(time);

            row.appendChild(t.running
                ? button('fa-pause', 'Pause', 'btn-outline-secondary', function () { pause(t.id); })
                : button('fa-play', 'Resume', 'btn-outline-secondary', function () { resume(t.id); }));
            row.appendChild(button('fa-trash-alt', 'Discard this timer', 'btn-outline-danger', function () {
                if (window.confirm('Throw away the ' + hms(timers().filter(function (x) { return x.id === t.id; })[0]?.seconds || 0)
                    + ' on ' + (info ? info.reference : 'ticket #' + t.id) + '? It has not been saved to the ticket.')) {
                    discard(t.id);
                }
            }));
            list.appendChild(row);
        });
    }

    /** Look up the tickets the timers belong to (only those this agent may see come back). */
    function lookup(callback) {
        var ids = timers().map(function (t) { return t.id; });
        var missing = ids.filter(function (id) { return !(id in tickets); });
        if (!ids.length || (!missing.length && Date.now() - fetchedAt < 60000)) {
            callback();
            return;
        }
        itflowGet('/agent/ajax.php', {mit_timer_tickets: 1, ids: ids.join(',')}, function (text) {
            var found = {};
            try {
                found = JSON.parse(text) || {};
            } catch (e) {
                found = {};
            }
            ids.forEach(function (id) {
                tickets[id] = found[id] || null;
            });
            fetchedAt = Date.now();
            callback();
        }, function () {
            callback();
        });
    }

    function tick() {
        timers().forEach(function (t) {
            var el = list.querySelector('[data-timer-time="' + t.id + '"]');
            if (el) {
                el.textContent = hms(t.seconds);
            }
        });
    }

    function stopTicking() {
        clearInterval(ticking);
        ticking = null;
    }

    root.addEventListener('show.bs.dropdown', function () {
        render();
        lookup(render);
        ticking = ticking || setInterval(tick, 1000);
    });
    root.addEventListener('hidden.bs.dropdown', stopTicking);

    // Timers started, paused or reset in another tab, or on this page
    window.addEventListener('storage', function (e) {
        if (!e.key || /(-startTime|-pausedTime)$|^ticket-timer-running-/.test(e.key)) {
            list.closest('.dropdown-menu').classList.contains('show') ? render() : refreshBadge();
        }
    });
    setInterval(refreshBadge, 5000);
    refreshBadge();
})();
