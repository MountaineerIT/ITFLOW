/*
 * Mountaineer IT - Date worked / Start / End on the reply box (functions/mit_reply_times.php). Until they are typed they
 * follow ITFlow's timer (agent/js/ticket_time_tracking.js, which writes #hours / #minutes / #seconds): end = now,
 * start = end less the time worked. Typing a time pauses the timer and sets the length from start - end; changing the
 * length after that moves the start. A note with time worked must carry valid times to be sent - checked on the send
 * button's click in the capture phase, before ITFlow resets its timer.
 */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const field = document.querySelector('[data-mit-reply-times-field]');
    const send = document.getElementById('ticket_add_reply');
    const h = document.getElementById('hours');
    const m = document.getElementById('minutes');
    const s = document.getElementById('seconds');
    if (!field || !send || !h || !m) {
        return;
    }
    // The layout: ITFlow's timer moves into the time strip, its old column goes, and the row below is Status | send buttons
    const row = field.parentElement;
    const timer = h.closest('.input-group');
    const timeCol = timer && timer.parentElement && timer.parentElement.closest('[class*="col-"]');
    const statusSelect = document.getElementById('ticket_reply_status');
    if (row && timer && timeCol && timeCol !== field) {
        row.classList.add('mit-reply-foot');
        field.querySelector('[data-mit-time-slot]').appendChild(timer);
        timeCol.remove();
        if (statusSelect && statusSelect.closest('[class*="col-"]')) {
            statusSelect.closest('[class*="col-"]').classList.add('mit-status-col');
        }
        if (send.closest('[class*="col-"]')) {
            send.closest('[class*="col-"]').classList.add('mit-send-col');
        }
    }

    const date = document.getElementById('mitWorkDate');
    const start = document.getElementById('mitWorkStart');
    const end = document.getElementById('mitWorkEnd');
    const mode = field.querySelector('[data-mit-times-mode]');
    const error = field.querySelector('[data-mit-times-error]');
    let typed = false;  // the person set the times themselves

    const pad = (n) => String(n).padStart(2, '0');
    const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
    const hm = (d) => pad(d.getHours()) + ':' + pad(d.getMinutes());

    function worked() {
        return (parseInt(h.value, 10) || 0) * 3600 + (parseInt(m.value, 10) || 0) * 60 + (s ? parseInt(s.value, 10) || 0 : 0);
    }
    // The typed range in seconds (an end before the start runs past midnight), or null
    function typedLength() {
        if (!date.value || !start.value || !end.value) {
            return null;
        }
        const from = new Date(date.value + 'T' + start.value);
        let to = new Date(date.value + 'T' + end.value);
        if (to <= from) {
            // The next calendar day - adding 24 hours is an hour off on the nights the clocks change
            to.setDate(to.getDate() + 1);
        }
        const secs = (to - from) / 1000;
        return secs > 0 && secs < 86400 ? secs : null;
    }
    function setWorked(secs) {
        h.value = Math.floor(secs / 3600) || '';
        m.value = pad(Math.floor((secs % 3600) / 60));
        if (s) {
            s.value = '00';
        }
        // ITFlow's timer takes the new length from its inputs
        [h, m].forEach((el) => el.dispatchEvent(new Event('change', { bubbles: true })));
    }
    function pauseTimer() {
        const button = document.getElementById('startStopTimer');
        if (button && button.querySelector('.fa-pause')) {
            button.click();
        }
    }
    // Following the timer: ends now, started the time worked ago
    function follow() {
        if (typed) {
            return;
        }
        const secs = worked();
        if (!secs) {
            date.value = date.value || ymd(new Date());
            start.value = '';
            end.value = '';
            return;
        }
        const now = new Date();
        const from = new Date(now.getTime() - Math.round(secs / 60) * 60000);
        date.value = ymd(from);
        start.value = hm(from);
        end.value = hm(now);
    }
    function showMode() {
        mode.innerHTML = typed ? 'Typed by you · <a href="#" data-mit-times-follow>use the timer</a>' : '<i class="fas fa-link me-1"></i>From the timer';
    }
    function problem() {
        if (!worked()) {
            return '';
        }
        if (!date.value || !start.value || !end.value) {
            return 'Add the date and the start and end times of this work.';
        }
        if (typedLength() === null) {
            return 'The end has to be after the start, within a day.';
        }
        return '';
    }
    function showProblem(text) {
        error.textContent = text;
        error.hidden = !text;
        [date, start, end].forEach((el) => el.classList.toggle('is-invalid', !!text && !el.value));
    }

    [date, start, end].forEach(function (el) {
        el.addEventListener('input', function () {
            if (!typed) {
                typed = true;
                pauseTimer();
                showMode();
            }
            const secs = typedLength();
            if (secs !== null) {
                setWorked(secs);
            }
            showProblem('');
        });
    });
    // A new length once the times are typed: the end stays, the start moves
    [h, m].forEach(function (el) {
        el.addEventListener('input', function () {
            if (typed && end.value && date.value) {
                const to = new Date(date.value + 'T' + end.value);
                const from = new Date(to.getTime() - Math.round(worked() / 60) * 60000);
                date.value = ymd(from);
                start.value = hm(from);
            } else {
                follow();
            }
        });
    });
    mode.addEventListener('click', function (e) {
        if (e.target.matches('[data-mit-times-follow]')) {
            e.preventDefault();
            typed = false;
            showMode();
            follow();
        }
    });

    // Before ITFlow's click handler resets the timer, and before the form goes
    send.addEventListener('click', function (e) {
        follow();
        const why = problem();
        if (why) {
            e.preventDefault();
            e.stopImmediatePropagation();
            showProblem(why);
            start.focus();
        }
    }, true);
    send.form.addEventListener('submit', function (e) {
        if (e.submitter === send || !e.submitter) {
            follow(); // sent without a click on the button (Enter, a script): times from the timer now
            const why = problem();
            if (why) {
                e.preventDefault();
                showProblem(why);
            }
        }
    });

    showMode();
    follow();
    setInterval(follow, 15000);
    // The timer writes the inputs without events - follow it while it runs
    setInterval(function () {
        if (!typed && document.querySelector('#startStopTimer .fa-pause')) {
            follow();
        }
    }, 5000);
});
