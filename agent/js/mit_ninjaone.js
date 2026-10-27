// Mountaineer IT - NinjaOne beside assets (functions/mit_ninjaone.php).
//
// Finds the asset links on the page (ITFlow's asset windows: modals/asset/asset.php?...id=N, as in a ticket's Linked
// box) and the server-drawn NinjaOne buttons ([data-mit-ninja-asset]), asks mit_ninjaone_ajax.php which of those assets
// are NinjaOne devices, and puts a NinjaOne button with the device's status (online / offline, last seen) beside each.

(function () {
    if (window.mitNinjaLoaded) {
        return;
    }
    window.mitNinjaLoaded = true;

    function assetIdOf(link) {
        const m = /modals\/asset\/asset\.php\?[^"']*?\bid=(\d+)/.exec(link.getAttribute('data-modal-url') || '');
        return m ? Number(m[1]) : 0;
    }

    function statusDot(device) {
        const dot = document.createElement('span');
        dot.className = 'd-inline-block rounded-circle me-1';
        dot.style.cssText = 'width:.55rem;height:.55rem;vertical-align:middle';
        dot.style.background = device.offline === null ? 'var(--bs-secondary-color)' : (device.offline ? 'var(--bs-danger)' : 'var(--bs-success)');
        return dot;
    }

    function statusText(device) {
        const state = device.offline === null ? 'Status unknown' : (device.offline ? 'Offline' : 'Online');
        return state + (device.last_contact ? ' - last seen ' + device.last_contact : '') + (device.checked ? ' (checked ' + device.checked + ')' : '');
    }

    function button(device) {
        const a = document.createElement('a');
        a.href = device.url;
        a.target = '_blank';
        a.rel = 'noopener';
        a.className = 'btn btn-sm btn-outline-primary py-0 px-1 ms-2 text-nowrap mit-ninja-button';
        a.title = 'Open in NinjaOne - ' + statusText(device);
        a.appendChild(statusDot(device));
        a.appendChild(document.createTextNode('NinjaOne'));
        return a;
    }

    function run() {
        const links = Array.from(document.querySelectorAll('a[data-modal-url*="modals/asset/asset.php"]')).filter(function (link) {
            return assetIdOf(link) && !link.parentNode.querySelector('.mit-ninja-button');
        });
        const servers = Array.from(document.querySelectorAll('[data-mit-ninja-asset]:not([data-mit-ninja-done])'));
        const ids = Array.from(new Set(links.map(assetIdOf).concat(servers.map(function (el) {
            return Number(el.getAttribute('data-mit-ninja-asset'));
        })))).slice(0, 50);
        if (!ids.length) {
            return;
        }
        fetch('/agent/mit_ninjaone_ajax.php?status=' + ids.join(','), {credentials: 'same-origin'}).then(function (res) {
            return res.ok ? res.json() : {};
        }).then(function (devices) {
            links.forEach(function (link) {
                const device = devices[assetIdOf(link)];
                if (device && !link.parentNode.querySelector('.mit-ninja-button')) {
                    link.insertAdjacentElement('afterend', button(device));
                }
            });
            servers.forEach(function (el) {
                const device = devices[el.getAttribute('data-mit-ninja-asset')];
                el.setAttribute('data-mit-ninja-done', '1');
                if (device) {
                    el.title = 'Open in NinjaOne - ' + statusText(device);
                    el.insertBefore(statusDot(device), el.firstChild);
                }
            });
        }).catch(function () {
            // NinjaOne status is extra - the page works without it
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
    // Asset windows load their content later
    document.addEventListener('shown.bs.modal', run);
})();
