/*
 * Mountaineer IT - the "Repeats on" choice for monthly / yearly repeats (functions/mit_recurring.php):
 * "On the 21st" or "On the third Wednesday" / "On the last Wednesday", worked out from the date picked.
 *
 * Markup: <select data-mit-repeat-on data-date="[name=start_date]" data-frequency="[name=frequency]"
 *          data-months="Monthly,Quarterly,..." data-current="3WE"> inside the form.
 * The choices follow the date and show only for month-based frequencies. Works in ajax-loaded modals.
 */
(function () {
    'use strict';

    var DAYS = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
    var NAMES = {SU: 'Sunday', MO: 'Monday', TU: 'Tuesday', WE: 'Wednesday', TH: 'Thursday', FR: 'Friday', SA: 'Saturday'};
    var ORDINALS = {1: 'first', 2: 'second', 3: 'third', 4: 'fourth', '-1': 'last'};
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    function suffix(n) {
        if (n % 100 >= 11 && n % 100 <= 13) {
            return 'th';
        }
        return ['th', 'st', 'nd', 'rd'][n % 10] || 'th';
    }

    /** [{value, text}] for a 'YYYY-MM-DD' date. */
    function options(dateText, yearly) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(dateText || '');
        if (!m) {
            return [];
        }
        var date = new Date(+m[1], +m[2] - 1, +m[3]);
        var day = date.getDate();
        var code = DAYS[date.getDay()];
        var daysInMonth = new Date(+m[1], +m[2], 0).getDate();
        var of = yearly ? ' of ' + MONTHS[date.getMonth()] : '';
        var out = [{value: '', text: yearly ? 'On ' + MONTHS[date.getMonth()] + ' ' + day : 'On the ' + day + suffix(day)}];
        var nth = Math.floor((day - 1) / 7) + 1;
        if (nth <= 4) {
            out.push({value: nth + code, text: 'On the ' + ORDINALS[nth] + ' ' + NAMES[code] + of});
        }
        if (day + 7 > daysInMonth) {
            out.push({value: '-1' + code, text: 'On the last ' + NAMES[code] + of});
        }
        return out;
    }

    function refresh(select) {
        var form = select.closest('form') || document;
        var dateInput = form.querySelector(select.dataset.date);
        var freqInput = form.querySelector(select.dataset.frequency);
        var wrap = select.closest('[data-mit-repeat-on-wrap]') || select.parentNode;
        var freq = freqInput ? freqInput.value : '';
        var months = (select.dataset.months || '').split(',');
        var yearly = (select.dataset.yearly || '').split(',').indexOf(freq) !== -1;
        var show = months.indexOf(freq) !== -1;
        wrap.hidden = !show;
        select.disabled = !show;
        if (!show) {
            return;
        }
        var wanted = select.value || select.dataset.current || '';
        var list = options(dateInput ? dateInput.value : '', yearly);
        select.textContent = '';
        list.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o.value;
            opt.textContent = o.text;
            select.appendChild(opt);
        });
        // Keep the choice when the new date still offers it; "last" stays "last" when it can
        var values = list.map(function (o) { return o.value; });
        var pick = values.indexOf(wanted) !== -1 ? wanted : '';
        if (!pick && wanted) {
            var lastNow = values.filter(function (v) { return v.indexOf('-1') === 0; })[0];
            var nthNow = values.filter(function (v) { return v && v.indexOf('-1') !== 0; })[0];
            pick = wanted.indexOf('-1') === 0 ? (lastNow || nthNow || '') : (nthNow || lastNow || '');
        }
        select.value = pick;
        select.dataset.current = pick;
    }

    function refreshAll(root) {
        (root || document).querySelectorAll('select[data-mit-repeat-on]').forEach(refresh);
    }

    window.mitRepeatOnRefresh = refreshAll;

    if (!window.mitRepeatOnBound) {
        window.mitRepeatOnBound = true;
        var onChange = function (e) {
            // A choice made in the list itself is the one to keep - "On the 15th" (no value) included
            if (e.target.matches && e.target.matches('select[data-mit-repeat-on]')) {
                e.target.dataset.current = e.target.value;
                return;
            }
            var form = e.target.closest ? e.target.closest('form') : null;
            if (form && form.querySelector('select[data-mit-repeat-on]')) {
                refreshAll(form);
            }
        };
        document.addEventListener('change', onChange);
        document.addEventListener('input', onChange);
        // select2 announces changes through jQuery only (a jQuery-triggered event has no originalEvent;
        // a real change is already handled by the listeners above)
        if (window.jQuery) {
            window.jQuery(document).on('change', 'select', function (e) {
                if (!e.originalEvent) {
                    onChange({target: this});
                }
            });
        }
        document.addEventListener('shown.bs.modal', function (e) {
            refreshAll(e.target);
        });
    }
    refreshAll();
})();
