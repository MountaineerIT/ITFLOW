/*
 * Mountaineer IT - the modern look (css/mit_theme.css, loaded by functions/mit_theme.php).
 * The top bar is marked data-bs-theme="dark" in ITFlow's markup (it sat on the accent colour); in light mode it is white
 * here, so its search box and menus switch to Bootstrap's light colours. Statuses, tags and categories carry their colour inline -
 * it is copied into --mit-tone so the stylesheet can show a soft tint of it, also for badges added later (modals, lists).
 */
(function () {
    'use strict';

    if (document.documentElement.getAttribute('data-bs-theme') !== 'dark') {
        document.querySelectorAll('.app-header[data-bs-theme="dark"]').forEach(function (bar) {
            bar.setAttribute('data-bs-theme', 'light');
        });
    }

    function tone(root) {
        // An inline background, or ITFlow 26.11's status colour (--itflow-status-bg)
        root.querySelectorAll('.badge[style*="background"], .badge[style*="--itflow-status-bg"]').forEach(function (badge) {
            var colour = badge.style.backgroundColor || badge.style.getPropertyValue('--itflow-status-bg').trim();
            if (colour && badge.style.getPropertyValue('--mit-tone') !== colour) {
                badge.style.setProperty('--mit-tone', colour);
                badge.classList.add('mit-tone');
            }
        });
    }

    tone(document);

    // ITFlow checks for the logo folder rather than the file, so an install without a logo shows a broken picture
    // ("Company logo") on quotes and invoices - hide one that failed to load
    document.querySelectorAll('img[alt="Company logo"]').forEach(function (img) {
        function check() {
            if (img.complete && !img.naturalWidth) {
                img.classList.add('mit-img-missing');
            }
        }
        img.addEventListener('error', check);
        check();
    });

    // Charts on the page were drawn before this ran (inline footer scripts) - redraw them in the same font
    if (window.Chart && Chart.defaults) {
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Object.values(Chart.instances || {}).forEach(function (chart) {
            chart.update('none');
        });
    }

    var queued = false;
    new MutationObserver(function () {
        if (!queued) {
            queued = true;
            requestAnimationFrame(function () {
                queued = false;
                tone(document);
            });
        }
    }).observe(document.body, { childList: true, subtree: true });
})();
