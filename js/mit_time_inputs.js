/*
 * Mountaineer IT - time fields on every agent / admin page (loaded by mitThemeHead, functions/mit_theme.php). The clock icon
 * of a time field puts in the time now instead of opening the browser's scroll list. Typing a time by hand into an empty time
 * or date-and-time field fills in AM / PM by itself once the minutes are in - the same as the clock now - so "9 15" in the
 * evening is 9:15 PM (typing A or P first still picks it). Nothing is filled on just clicking in. The keys are followed segment
 * by segment the way the browser takes them (an hour of 2-9 is done at once, 1 waits for a second digit, ":" or Tab moves on),
 * in the order of the browser's own date format; with a 24-hour clock there is no AM / PM and nothing to do. Works by event
 * delegation, so fields in modals and drawn by scripts are covered too.
 */
(function () {
    'use strict';

    const pad = (n) => String(n).padStart(2, '0');
    const twelveHour = new Intl.DateTimeFormat(undefined, { hour: 'numeric' }).resolvedOptions().hour12;
    // The date segments in the order the browser shows them (en-US: month / day / year)
    const dateOrder = new Intl.DateTimeFormat(undefined, { year: 'numeric', month: '2-digit', day: '2-digit' })
        .formatToParts(new Date(2026, 0, 2)).map((p) => p.type).filter((t) => ['month', 'day', 'year'].includes(t));
    const LIMITS = { month: 12, day: 31, hour: 12, minute: 59 };
    const typing = new WeakMap(); // field -> { segs, idx, cur, got } while a time is being typed into an empty field

    function usable(el) {
        return el instanceof HTMLInputElement && (el.type === 'time' || el.type === 'datetime-local') && !el.disabled && !el.readOnly;
    }
    function changed(el) {
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    // Now, to the minute or the field's step
    function nowTime(el) {
        const d = new Date();
        d.setSeconds(0, 0);
        const step = parseInt(el.step, 10) / 60;
        if (step > 1) {
            d.setHours(0, Math.round((d.getHours() * 60 + d.getMinutes()) / step) * step);
        }
        return pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    // The icon does nothing itself, so the click lands on the field and is ours
    const style = document.createElement('style');
    style.textContent = 'input[type="time"]::-webkit-calendar-picker-indicator{pointer-events:none}';
    document.head.appendChild(style);

    document.addEventListener('click', function (e) {
        const el = e.target;
        if (!usable(el) || el.type !== 'time' || e.clientX < el.getBoundingClientRect().right - 32) {
            return; // not a time field's clock icon
        }
        e.preventDefault();
        typing.delete(el);
        el.value = nowTime(el);
        changed(el);
    });

    document.addEventListener('focusin', function (e) {
        const el = e.target;
        if (twelveHour && usable(el) && el.value === '') {
            typing.set(el, { segs: (el.type === 'time' ? [] : dateOrder).concat(['hour', 'minute']), idx: 0, cur: '', got: {} });
        }
    });
    document.addEventListener('focusout', function (e) {
        typing.delete(e.target);
    });

    // One segment done: on to the next, and once the minutes are in, the whole value with AM / PM from now
    function finish(el, state, value) {
        state.got[state.segs[state.idx]] = value;
        state.idx++;
        state.cur = '';
        if (state.idx < state.segs.length) {
            return;
        }
        typing.delete(el);
        const g = state.got;
        if (g.hour < 1 || g.hour > 12) {
            return;
        }
        const hour = (g.hour % 12) + (new Date().getHours() >= 12 ? 12 : 0);
        let value24 = pad(hour) + ':' + pad(g.minute);
        if (el.type === 'datetime-local') {
            const date = new Date(g.year, g.month - 1, g.day);
            if (!g.year || date.getMonth() !== g.month - 1 || date.getDate() !== g.day) {
                return;
            }
            value24 = g.year + '-' + pad(g.month) + '-' + pad(g.day) + 'T' + value24;
        }
        // After the browser has taken the key itself
        setTimeout(function () {
            if (el.value === '') {
                el.value = value24;
                changed(el);
            }
        }, 0);
    }
    document.addEventListener('keydown', function (e) {
        const el = e.target;
        const state = usable(el) ? typing.get(el) : null;
        if (!state || e.ctrlKey || e.metaKey || e.altKey) {
            return;
        }
        const seg = state.segs[state.idx];
        if (/^[0-9]$/.test(e.key)) {
            state.cur += e.key;
            if (seg === 'year') {
                if (state.cur.length === 4) {
                    finish(el, state, parseInt(state.cur, 10));
                }
            } else if (state.cur.length === 1 && parseInt(e.key, 10) * 10 > LIMITS[seg]) {
                finish(el, state, parseInt(e.key, 10)); // 2-9 for an hour, 6-9 for minutes ...
            } else if (state.cur.length === 2) {
                const two = parseInt(state.cur, 10);
                if (two <= LIMITS[seg]) {
                    finish(el, state, two);
                } else {
                    // "15" as an hour: 1, and the 5 starts the next segment
                    finish(el, state, parseInt(state.cur[0], 10));
                    if (state.idx < state.segs.length) {
                        state.cur = e.key;
                    }
                }
            }
        } else if ([':', '/', '-', '.', ' ', 'Tab'].includes(e.key) && !e.shiftKey && state.cur !== '') {
            finish(el, state, parseInt(state.cur, 10));
        } else if (e.key !== 'Shift') {
            typing.delete(el); // A / P, arrows, deleting ... - the person takes it from here
        }
    });
})();
