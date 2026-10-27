/*
 * Mountaineer IT - To Bill's parts (functions/mit_to_bill.php): what the ticked parts come to, for ITFlow's Unbilled Time
 * script (js/unbilled_time.js calls window.mitToBillParts in its update and adds it to the total, the summary and the
 * button). One client: each part's line follows its price as it is typed; every client: the ticked clients' parts.
 * The parts' boxes carry ITFlow's data-unbilled-expense-pick / -price as well, so ITFlow's script runs its update when
 * they change; "all" and a click on a row are handled here.
 */
(function () {
    'use strict';

    function number(value) {
        var n = typeof itflowBillingNumber === 'function' ? itflowBillingNumber(value) : parseFloat(String(value).replace(/[^0-9.\-]/g, ''));
        return isNaN(n) ? 0 : n;
    }

    window.mitToBillParts = function (form, mode) {
        var out = { amount: 0, count: 0, picked: 0 };
        if (mode === 'client') {
            var rows = form.querySelectorAll('[data-mit-part-row]');
            var picked = 0;
            rows.forEach(function (row) {
                var pick = row.querySelector('[data-mit-part-pick]');
                var input = row.querySelector('[data-mit-part-price]');
                var on = !pick || pick.checked;
                var price = input ? number(input.value) : 0;
                var line = Math.round(price * Number(row.getAttribute('data-qty') || 0) * 100) / 100;
                var cell = row.querySelector('[data-mit-part-line]');
                row.classList.toggle('itflow-unbilled-off', !on);
                if (input) {
                    input.classList.toggle('is-invalid', input.value.trim() !== '' && (isNaN(itflowBillingNumber(input.value)) || price < 0));
                    if (cell && form.mitToBillMoney) {
                        cell.textContent = form.mitToBillMoney(line);
                    }
                }
                if (on && pick) {
                    picked++;
                    out.count++;
                    out.amount += line;
                }
            });
            out.picked = picked;
            var all = form.querySelector('[data-mit-part-all]');
            if (all) {
                all.checked = picked > 0 && picked === rows.length;
                all.indeterminate = picked > 0 && picked < rows.length;
            }
        } else {
            form.querySelectorAll('[data-unbilled-row]').forEach(function (row) {
                var pick = row.querySelector('[data-unbilled-pick]');
                if (!pick || pick.checked) {
                    out.count += Number(row.getAttribute('data-mit-parts') || 0);
                    out.amount += Number(row.getAttribute('data-mit-part-value') || 0);
                }
            });
        }
        out.amount = Math.round(out.amount * 100) / 100;
        return out;
    };

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-unbilled]');
        if (!form) {
            return;
        }
        var card = form.querySelector('[data-mit-to-bill-card]');
        if (card && card.getAttribute('data-currency')) {
            form.mitToBillMoney = function (amount) {
                return itflowBillingMoney(amount, card.getAttribute('data-currency'), card.getAttribute('data-locale'));
            };
        }
        // A pick's change runs ITFlow's update (it listens for its own attribute)
        function changed(pick) {
            pick.dispatchEvent(new Event('change', { bubbles: true }));
        }
        var all = form.querySelector('[data-mit-part-all]');
        if (all) {
            all.addEventListener('change', function () {
                var picks = form.querySelectorAll('[data-mit-part-pick]');
                picks.forEach(function (pick) {
                    pick.checked = all.checked;
                });
                if (picks.length) {
                    changed(picks[0]);
                }
            });
        }
        form.querySelectorAll('[data-mit-part-row]').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('a, input, button, select, label')) {
                    return;
                }
                var pick = row.querySelector('[data-mit-part-pick]');
                if (pick) {
                    pick.checked = !pick.checked;
                    changed(pick);
                }
            });
        });
    });
})();
