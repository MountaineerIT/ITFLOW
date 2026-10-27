/*
 * Mountaineer IT - quote pricing (functions/mit_quotes.php): works a line's customer price out
 * from our cost and a markup / margin %, shows what that means, previews a chosen image, and on
 * the quote's add-item row fills cost, pricing and picture in when a product is picked.
 *
 * Fields are found through their form (input.form - the add-item row's form sits inside a table
 * row, so its fields are not its DOM children): [data-mit-cost], [data-mit-method], [data-mit-pct],
 * the price field (name from data-price on [data-mit-pricing], or data-mit-price-field, default
 * "price"), [data-mit-hint], [data-mit-preset] buttons. Safe to load more than once.
 */
(function () {
    'use strict';

    function num(value) {
        var n = parseFloat(String(value || '').replace(/[$,%\s]/g, ''));
        return isNaN(n) ? null : n;
    }
    /** n as money in the page's currency (data-mit-currency on the pricing block or cell, else USD). */
    function money(n, el) {
        var holder = el && el.closest('[data-mit-currency]');
        var currency = (holder && holder.dataset.mitCurrency) || 'USD';
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(n);
        } catch (e) {
            return n.toFixed(2);
        }
    }

    /** The pieces of one pricing group, keyed off its cost field. */
    function group(costInput) {
        var form = costInput.form;
        if (!form) {
            return null;
        }
        var all = Array.prototype.slice.call(form.elements);
        var block = costInput.closest('[data-mit-pricing]');
        var priceName = (block && block.dataset.price) || costInput.dataset.mitPriceField || 'price';
        var root = block || document;
        return {
            form: form,
            cost: costInput,
            method: all.filter(function (el) { return el.hasAttribute('data-mit-method'); })[0],
            pct: all.filter(function (el) { return el.hasAttribute('data-mit-pct'); })[0],
            price: form.elements[priceName] || all.filter(function (el) { return el.name === priceName; })[0],
            hint: (block || costInput.closest('tr') || document).querySelector('[data-mit-hint]') || root.querySelector('[data-mit-hint]'),
            pctWrap: block ? block.querySelector('[data-mit-pct-wrap]') : null,
            presets: block ? block.querySelector('[data-mit-presets]') : null
        };
    }

    function update(g, fromPrice) {
        if (!g || !g.method || !g.price) {
            return;
        }
        var method = g.method.value;
        var cost = num(g.cost.value);
        var pct = g.pct ? num(g.pct.value) : null;
        var setPrice = method === 'price';
        if (g.pctWrap) {
            g.pctWrap.hidden = setPrice;
        }
        if (g.presets) {
            g.presets.hidden = setPrice;
        }
        if (g.pct && !g.pctWrap) {
            g.pct.hidden = setPrice;
        }
        if (!fromPrice && !setPrice && cost !== null && cost > 0 && pct !== null) {
            var price = method === 'markup' ? cost * (1 + pct / 100) : (pct < 100 ? cost / (1 - pct / 100) : null);
            if (price !== null) {
                g.price.value = price.toFixed(2);
            }
        }
        if (g.presets) {
            g.presets.querySelectorAll('[data-mit-preset]').forEach(function (b) {
                var on = pct !== null && num(b.dataset.mitPreset) === pct;
                b.classList.toggle('btn-primary', on);
                b.classList.toggle('btn-outline-secondary', !on);
            });
        }
        if (!g.hint) {
            return;
        }
        var p = num(g.price.value);
        if (cost === null || cost <= 0 || p === null || p <= 0) {
            g.hint.textContent = cost === null && !setPrice && !g.hint.hasAttribute('data-mit-quiet') ? 'Enter our cost to work the price out.' : '';
            return;
        }
        var profit = p - cost;
        var markup = profit / cost * 100;
        var margin = profit / p * 100;
        g.hint.textContent = 'Profit ' + money(profit, g.hint) + ' each - ' + (method === 'margin' ? markup.toFixed(1) + '% markup' : (method === 'markup' ? margin.toFixed(1) + '% margin' : markup.toFixed(1) + '% markup, ' + margin.toFixed(1) + '% margin'));
        g.hint.classList.toggle('text-danger', profit < 0);
    }

    function bind(costInput) {
        if (costInput.dataset.mitBound) {
            return;
        }
        var g = group(costInput);
        if (!g || !g.price) {
            return;
        }
        costInput.dataset.mitBound = '1';
        [g.cost, g.pct].forEach(function (el) {
            if (el) {
                el.addEventListener('input', function () { update(g); });
            }
        });
        if (g.method) {
            g.method.addEventListener('change', function () { update(g); });
        }
        // Typing a price yourself makes it a set price - the hint still shows what it comes to
        g.price.addEventListener('input', function (e) {
            if (e.isTrusted && g.method && g.method.value !== 'price') {
                g.method.value = 'price';
            }
            update(g, true);
        });
        if (g.presets) {
            g.presets.addEventListener('click', function (e) {
                var b = e.target.closest('[data-mit-preset]');
                if (b && g.pct) {
                    g.pct.value = b.dataset.mitPreset;
                    update(g);
                }
            });
        }
        update(g, true);

        // The quote's add-item row: a product picked from the list brings its cost, pricing and picture
        // js/product_autocomplete.js sets the hidden product_id without firing an event, so look at
        // it just after each mouse or key press (js/autocomplete.js picks on mousedown or Enter)
        var productId = g.form.elements.product_id;
        if (productId && window.mitProductPricing && !costInput.closest('[data-mit-pricing]')) {
            var last = productId.value;
            var picked = function () {
                if (productId.value === last) {
                    return;
                }
                last = productId.value;
                var p = window.mitProductPricing[last];
                if (!p) {
                    return;
                }
                g.cost.value = p.cost || '';
                if (p.method && g.method) {
                    g.method.value = p.method;
                }
                if (g.pct) {
                    g.pct.value = p.percent || g.pct.value;
                }
                update(g, true);
                if (p.image) {
                    var row = costInput.closest('tr');
                    var img = row && row.querySelector('[data-mit-image-preview]');
                    if (img) {
                        img.src = p.image;
                        img.style.display = '';
                        var empty = row.querySelector('[data-mit-image-empty]');
                        if (empty) {
                            empty.style.display = 'none';
                        }
                    }
                }
            };
            ['mousedown', 'keydown'].forEach(function (type) {
                document.addEventListener(type, function () { setTimeout(picked, 0); }, true);
            });
        }
    }

    /** The picture box around an image field: preview, "no image" placeholder, file and link inputs. */
    function imageBox(el) {
        var box = el.closest('[data-mit-image-box]') || el.closest('tr, label, .d-flex') || el.parentNode;
        return {
            img: box.querySelector('[data-mit-image-preview]'),
            empty: box.querySelector('[data-mit-image-empty]'),
            file: box.querySelector('[data-mit-image-input]'),
            url: box.querySelector('[data-mit-image-url]')
        };
    }

    function showImage(b, src, label) {
        if (!b.img) {
            return;
        }
        if (!b.img.dataset.mitOriginal) {
            b.img.dataset.mitOriginal = b.img.getAttribute('src') || '-';
        }
        var original = b.img.dataset.mitOriginal === '-' ? '' : b.img.dataset.mitOriginal;
        src = src || original;
        b.img.onerror = null;
        if (b.empty && b.empty.dataset.mitText === undefined) {
            b.empty.dataset.mitText = b.empty.textContent;
        }
        var placeholder = function (text) {
            b.img.style.display = 'none';
            if (b.empty) {
                if (b.empty.dataset.mitText) {
                    b.empty.textContent = text || b.empty.dataset.mitText;
                }
                b.empty.hidden = false;
                b.empty.style.display = 'block';
            }
        };
        if (!src) {
            placeholder();
            return;
        }
        // A product page is not an image itself - say a link is waiting instead
        b.img.onerror = function () { placeholder(label); };
        b.img.src = src;
        b.img.style.display = '';
        if (b.empty) {
            b.empty.hidden = true;
            b.empty.style.display = 'none';
        }
    }

    function bindImage(input) {
        if (input.dataset.mitBound) {
            return;
        }
        input.dataset.mitBound = '1';
        input.addEventListener('change', function () {
            var b = imageBox(input);
            if (input.files && input.files[0]) {
                if (b.url) {
                    b.url.value = '';
                }
                showImage(b, URL.createObjectURL(input.files[0]));
            }
        });
    }

    function bindImageUrl(input) {
        if (input.dataset.mitBound) {
            return;
        }
        input.dataset.mitBound = '1';
        input.addEventListener('input', function () {
            var b = imageBox(input);
            var url = input.value.trim();
            if (/^https?:\/\/[^\s]+\.[^\s]+/i.test(url)) {
                if (b.file) {
                    b.file.value = '';
                }
                showImage(b, url, 'Link added');
            } else if (!url && !(b.file && b.file.files && b.file.files.length)) {
                showImage(b, '');
            }
        });
    }

    function scan(root) {
        (root || document).querySelectorAll('[data-mit-cost]').forEach(bind);
        (root || document).querySelectorAll('[data-mit-image-input]').forEach(bindImage);
        (root || document).querySelectorAll('[data-mit-image-url]').forEach(bindImageUrl);
    }

    // The add-item row's script sits before the row's price field: wait for the whole page
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { scan(); });
    } else {
        scan();
    }
    if (!window.mitQuotePricingBound) {
        window.mitQuotePricingBound = true;
        document.addEventListener('shown.bs.modal', function (e) { scan(e.target); });
    }
})();
