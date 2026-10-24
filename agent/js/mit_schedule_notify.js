/*
 * Mountaineer IT - the Schedule window's "Email" ticks (functions/mit_notifications.php mitScheduleNotifyField): when the visit is switched
 * between Remote and Onsite, the ticks and the extra addresses follow the company's defaults for that kind of visit - until someone
 * changes a tick by hand. Run each time the window opens (ajax_modal re-runs its scripts).
 */
(function () {
    document.querySelectorAll('[data-mit-schedule-notify]:not([data-mit-ready])').forEach(function (box) {
        box.setAttribute('data-mit-ready', '1');
        const form = box.closest('form');
        const visit = form && form.querySelector('select[name=onsite]');
        let defaults = {};
        try {
            defaults = JSON.parse(box.dataset.defaults || '{}');
        } catch (e) {
            return;
        }
        let touched = false;
        box.addEventListener('input', function () {
            touched = true;
        });
        if (!visit) {
            return;
        }
        visit.addEventListener('change', function () {
            const set = defaults[visit.value === '1' ? '1' : '0'];
            if (touched || !set) {
                return;
            }
            box.querySelectorAll('input[type=checkbox][data-who]').forEach(function (tick) {
                tick.checked = !!set[tick.dataset.who];
            });
            const extra = box.querySelector('input[data-who=extra]');
            if (extra) {
                extra.value = (set.extra || []).join(', ');
            }
        });
    });
})();
