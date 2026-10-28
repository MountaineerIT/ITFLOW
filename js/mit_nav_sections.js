/*
 * Mountaineer IT - fold the side menu's sections (SUPPORT, BILLING, FINANCE ...). Loaded for every look by mitThemeHead()
 * (functions/mit_theme.php) in the <head>, NOT deferred: a MutationObserver folds each item as the browser builds the menu, so a
 * folded section never shows for a moment on every page (26.10.188 - running after the page was drawn made it flicker open).
 * Each `li.nav-header` of the sidebar becomes a button that hides the items under it, up to the next heading. What is folded is
 * remembered in this browser (localStorage, per menu: agent / admin / a client's menu). The section holding the page you are on is
 * always shown open.
 */
(function () {
    'use strict';

    var KEY = 'mit.nav.folded';

    function load() {
        try {
            return JSON.parse(window.localStorage.getItem(KEY) || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function save(state) {
        try {
            window.localStorage.setItem(KEY, JSON.stringify(state));
        } catch (e) {
            // private window / storage off - folding still works for this page
        }
    }

    // Which menu this is: the admin pages, a client's menu (its pages carry client_id), or the main agent menu
    var path = window.location.pathname;
    var menu = path.indexOf('/admin/') === 0 ? 'admin' : (/[?&]client_id=\d/.test(window.location.search) ? 'client' : 'agent');
    var folded = load()[menu] || {};

    var style = document.createElement('style');
    style.textContent = '.app-sidebar li.nav-header.mit-nav-fold{cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between}'
        + '.app-sidebar li.nav-header.mit-nav-fold:hover{opacity:.85}'
        + '.app-sidebar li.nav-header .mit-nav-chevron{font-size:.7em;margin-left:.5rem;transition:transform .15s ease}'
        + '.app-sidebar li.nav-header[aria-expanded="false"] .mit-nav-chevron{transform:rotate(-90deg)}'
        + '.app-sidebar li.mit-nav-folded{display:none!important}';
    (document.head || document.documentElement).appendChild(style);

    function headingName(header) {
        return (header.textContent || '').trim().toUpperCase();
    }

    // The heading an item sits under (the nearest li.nav-header before it in the same list)
    function headingOf(item) {
        for (var node = item.previousElementSibling; node; node = node.previousElementSibling) {
            if (node.classList.contains('nav-header')) {
                return node;
            }
        }
        return null;
    }

    // While the page is built: hide each item of a folded section as it arrives
    function foldAsBuilt(item) {
        if (item.tagName !== 'LI' || item.classList.contains('nav-header') || !item.closest('.app-sidebar')) {
            return;
        }
        var header = headingOf(item);
        if (header && folded[headingName(header)]) {
            item.classList.add('mit-nav-folded');
        }
    }

    var observer = null;
    if (window.MutationObserver) {
        observer = new MutationObserver(function (records) {
            records.forEach(function (record) {
                Array.prototype.forEach.call(record.addedNodes, function (node) {
                    if (node.nodeType === 1) {
                        foldAsBuilt(node);
                        if (node.querySelectorAll) {
                            Array.prototype.forEach.call(node.querySelectorAll('.app-sidebar li'), foldAsBuilt);
                        }
                    }
                });
            });
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    // Once the menu is all there: the headings become buttons
    function init() {
        if (observer) {
            observer.disconnect();
        }
        Array.prototype.forEach.call(document.querySelectorAll('.app-sidebar li.nav-header'), function (header) {
            var name = headingName(header);
            if (!name) {
                return;
            }
            var items = [];
            for (var item = header.nextElementSibling; item && !item.classList.contains('nav-header'); item = item.nextElementSibling) {
                if (item.tagName === 'LI') {
                    items.push(item);
                }
            }
            if (!items.length) {
                return;
            }
            var holdsActive = items.some(function (item) {
                return item.querySelector('.nav-link.active');
            });

            header.classList.add('mit-nav-fold');
            header.setAttribute('role', 'button');
            header.setAttribute('tabindex', '0');
            header.dataset.mitNavSection = name;
            var chevron = document.createElement('i');
            chevron.className = 'fas fa-chevron-down mit-nav-chevron';
            chevron.setAttribute('aria-hidden', 'true');
            header.appendChild(chevron);

            function show(open) {
                header.setAttribute('aria-expanded', open ? 'true' : 'false');
                header.title = open ? 'Fold this section' : 'Show this section';
                items.forEach(function (item) {
                    item.classList.toggle('mit-nav-folded', !open);
                });
            }

            show(!folded[name] || holdsActive);

            function toggle() {
                var open = header.getAttribute('aria-expanded') !== 'true';
                show(open);
                var state = load();
                state[menu] = state[menu] || {};
                if (open) {
                    delete state[menu][name];
                } else {
                    state[menu][name] = 1;
                }
                save(state);
                folded = state[menu];
            }

            header.addEventListener('click', toggle);
            header.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    event.stopPropagation(); // ITFlow also turns Enter into a click further up - that would fold it straight back
                    toggle();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
