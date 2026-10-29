/*
 * Mountaineer IT - the reply box's "To" line (functions/mit_reply_recipients.php): "To" is shown for "Email reply"; the picker is
 * ITFlow's own (js/itflow_select.js). An email reply with nobody in it, or with something typed that is not an email
 * address, is not sent.
 */
(function () {
    'use strict';

    // A click in the note editor (TinyMCE's own frame) never reaches the page, so ITFlow's picker does not see it as a
    // click outside and stays open - close our list when focus moves into a frame
    window.addEventListener('blur', function () {
        setTimeout(function () {
            const active = document.activeElement;
            if (!active || active.tagName !== 'IFRAME') {
                return;
            }
            ['mitSendToSelect'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el && el.itflowSelect) {
                    el.itflowSelect.close(true, false);
                }
            });
        }, 0);
    });

    const box = document.getElementById('mitSendTo');
    const select = document.getElementById('mitSendToSelect');
    if (!box || !select) {
        return;
    }
    const form = box.closest('form');
    const audience = document.querySelector('#replyAudience [data-audience="2"]');
    if (audience) {
        audience.innerHTML = '<i class="fas fa-fw fa-paper-plane me-1"></i>Emails the people in To (remembered for this ticket), and shows in the portal.';
    }

    function emailPicked() {
        const picked = document.querySelector('input[name="public_reply_type"]:checked');
        return !!picked && picked.value === '2';
    }
    function update() {
        box.hidden = !emailPicked();
    }
    document.querySelectorAll('input[name="public_reply_type"]').forEach(function (radio) {
        radio.addEventListener('change', update);
    });
    update();

    // Checked when Send is clicked, before ITFlow's own click handler (agent/js/ticket_time_tracking.js), which resets the
    // ticket timer - a reply that is not sent must keep its time
    function problem() {
        if (!emailPicked()) {
            return '';
        }
        const picked = Array.prototype.filter.call(select.options, function (o) {
            return o.selected;
        }).map(function (o) {
            return o.value.trim();
        });
        const wrong = picked.filter(function (v) {
            return !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
        });
        if (!picked.length) {
            return 'Add at least one person to To, or pick "Public note" to post it without an email.';
        }
        if (wrong.length) {
            return 'Not an email address: ' + wrong.join(', ') + ' - take it out of To or type the full address.';
        }
        return '';
    }
    function stop(e) {
        const message = problem();
        if (message) {
            e.preventDefault();
            e.stopImmediatePropagation();
            alert(message);
        }
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest && e.target.closest('#ticket_add_reply')) {
            stop(e);
        }
    }, true);
    if (form) {
        form.addEventListener('submit', stop, true);
    }
})();
