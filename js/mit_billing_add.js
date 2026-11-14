/*
 * Mountaineer IT - the full add row on an invoice, quote or recurring invoice (functions/mit_quotes.php mitPaperAddRow, in place of
 * ITFlow 26.11.8's one "type a product" field). The name box searches the products like ITFlow's field did (js/autocomplete.js, the
 * list drawn by js/billing_items.js's itflowBillingProductRow); a pick fills the description, price and tax in - on a quote
 * js/mit_quote_pricing.js then fills our cost, pricing and picture - and nothing is added until Add (or Enter). The line goes to
 * ITFlow's own ajax.php billing_item_add as multipart (a picture goes with it); the page is then redrawn through js/billing_items.js
 * (window.mitBillingRefresh), or loaded again when that is not there.
 */
(function () {
    'use strict';

    function start() {
        var form = document.querySelector('form[data-mit-add-row]');
        if (!form || form.dataset.mitBound) {
            return;
        }
        form.dataset.mitBound = '1';
        var type = form.dataset.type;
        var name = form.elements.name;
        var go = form.querySelector('[data-mit-add-go]');
        var description = form.elements.description;
        var imageBox = form.querySelector('[data-mit-add-image-box]');
        var defaults = {};
        Array.prototype.forEach.call(form.elements, function (el) {
            if (el.name && el.type !== 'file' && el.type !== 'hidden') {
                defaults[el.name] = el.value;
            }
        });
        var pickedName = null;

        function toast(message, kind) {
            if (typeof itflowToast === 'function') {
                itflowToast(message, kind || 'success');
            } else if (kind === 'error') {
                alert(message);
            }
        }

        function products() {
            var holder = document.querySelector('[data-billing-products]');
            try {
                return holder ? JSON.parse(holder.textContent) : [];
            } catch (e) {
                return [];
            }
        }

        function money(value) {
            var n = parseFloat(value);
            return isNaN(n) ? '' : n.toFixed(2);
        }

        function setTax(id) {
            var select = form.elements.tax_id;
            if (select && select.querySelector('option[value="' + String(id) + '"]')) {
                select.value = String(id);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function clearImage() {
            var preview = form.querySelector('[data-mit-image-preview]');
            var empty = form.querySelector('[data-mit-image-empty]');
            if (preview) {
                preview.removeAttribute('src');
                preview.style.display = 'none';
                delete preview.dataset.mitOriginal;
            }
            if (empty) {
                empty.style.display = '';
                empty.hidden = false;
            }
            if (form.elements.item_image) {
                form.elements.item_image.value = '';
            }
            if (form.elements.item_image_url) {
                form.elements.item_image_url.value = '';
            }
        }

        function reset() {
            Object.keys(defaults).forEach(function (key) {
                if (form.elements[key]) {
                    form.elements[key].value = defaults[key];
                }
            });
            form.elements.product_id.value = '0';
            form.elements.frequency.value = '';
            form.elements.seat_source.value = '';
            setTax(defaults.tax_id);
            clearImage();
            if (imageBox) {
                imageBox.hidden = true;
            }
            var hint = form.querySelector('[data-mit-hint]');
            if (hint) {
                hint.textContent = '';
            }
            pickedName = null;
            description.rows = 1;
        }

        // A product picked: what it is billed at fills the row - change any of it before Add
        function pick(item) {
            name.value = item.product_name || item.label || '';
            pickedName = name.value;
            description.value = item.description || '';
            description.rows = description.value.indexOf('\n') !== -1 ? 3 : 1;
            if (form.elements.price) {
                form.elements.price.value = money(item.price);
            }
            setTax(item.tax || 0);
            form.elements.product_id.value = item.prod_id || 0;
            form.elements.frequency.value = type === 'quote' ? (item.frequency || '') : '';
            form.elements.seat_source.value = type === 'recurring' && (item.seat_source === 'contacts' || item.seat_source === 'assets') ? item.seat_source : '';
            if (form.elements.item_cost) {
                // js/mit_quote_pricing.js fills the product's cost, pricing and picture just after this
                form.elements.item_cost.value = '';
                clearImage();
                if (form.elements.item_price_method) {
                    form.elements.item_price_method.value = 'price';
                }
            }
            setTimeout(function () {
                if (form.elements.price) {
                    form.elements.price.dispatchEvent(new Event('input', { bubbles: true }));
                }
                (form.elements.qty || form.elements.price).focus();
                (form.elements.qty || form.elements.price).select();
            }, 10);
        }

        if (typeof itflowAutocomplete === 'function') {
            var list = products();
            itflowAutocomplete(name, {
                minLength: 1,
                source: function (term) {
                    var t = String(term || '').toLowerCase();
                    return list.filter(function (item) {
                        return typeof itflowBillingProductMatch === 'function' ? itflowBillingProductMatch(item, t) : String(item.label || '').toLowerCase().indexOf(t) !== -1;
                    });
                },
                row: function (item) {
                    return typeof itflowBillingProductRow === 'function' ? itflowBillingProductRow(item, {}) : { label: item.label };
                },
                onSelect: pick
            });
        }

        // Typed over after a pick: a line of its own, not the product
        name.addEventListener('input', function () {
            if (pickedName !== null && name.value !== pickedName) {
                form.elements.product_id.value = '0';
                form.elements.frequency.value = '';
                form.elements.seat_source.value = '';
                pickedName = null;
            }
        });

        // A longer description grows as it is typed; Enter there is a new line, Ctrl / Cmd + Enter adds
        description.addEventListener('input', function () {
            description.rows = Math.min(8, Math.max(1, description.value.split('\n').length));
        });
        description.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });

        // The picture: a file or a link, under the row
        var toggle = form.querySelector('[data-mit-add-image-toggle]');
        if (toggle && imageBox) {
            toggle.addEventListener('click', function () {
                imageBox.hidden = !imageBox.hidden;
                if (!imageBox.hidden) {
                    form.elements.item_image.focus();
                }
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (name.getAttribute('aria-expanded') === 'true') {
                return;   // Enter in the open list picks a product - js/autocomplete.js has it
            }
            if (name.value.trim() === '') {
                name.focus();
                toast('Type what the line is for, or pick a product.', 'error');
                return;
            }
            if (go.disabled) {
                return;
            }
            var data = new FormData(form);
            data.append('billing_item_add', '1');
            ['price', 'qty', 'item_cost', 'item_price_percent'].forEach(function (key) {
                if (data.has(key) && String(data.get(key)).trim() === '') {
                    data.delete(key);
                }
            });
            if (!data.has('frequency') || data.get('frequency') === '') {
                data.delete('frequency');
            }
            if (!data.has('seat_source') || data.get('seat_source') === '') {
                data.delete('seat_source');
            }
            if (form.elements.item_image && !form.elements.item_image.files.length) {
                data.delete('item_image');
            }
            go.disabled = true;
            var icon = go.querySelector('i');
            if (icon) {
                icon.className = 'fas fa-circle-notch fa-spin me-1';
            }
            fetch('ajax.php', { method: 'POST', credentials: 'same-origin', body: data }).then(function (res) {
                return res.json();
            }).then(function (json) {
                if (!json || json.status !== 'success') {
                    var refused = new Error((json && (json.message || json.error)) || "That line didn't save.");
                    refused.reload = !!(json && json.reload);
                    throw refused;
                }
                reset();
                return typeof window.mitBillingRefresh === 'function' ? window.mitBillingRefresh() : window.location.reload();
            }).then(function () {
                go.disabled = false;
                if (icon) {
                    icon.className = 'fas fa-plus me-1';
                }
                name.focus();
            }).catch(function (error) {
                go.disabled = false;
                if (icon) {
                    icon.className = 'fas fa-plus me-1';
                }
                toast(error instanceof SyntaxError ? "That line didn't save - loading the page again" : error.message, 'error');
                if (error instanceof SyntaxError || error.reload) {
                    setTimeout(function () { window.location.reload(); }, 1500);
                }
            });
        });

        if (window.location.hash === '#add-line') {
            name.scrollIntoView({ block: 'center' });
            name.focus();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
