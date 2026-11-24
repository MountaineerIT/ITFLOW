// Percentage discounts (functions/mit_discount.php, 26.11.8.9): a product ticked "Never discounted" in its window; a recurring invoice's Edit window
// switched from $ to % - ITFlow's dollar box hidden, 20% with the Nonprofit discount product - saves 20% of the discountable lines only, the page
// reads "Discount (20%)", the window opens again on % with what it comes to, and switching back to $ keeps a typed amount. Removes what it makes.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, submit, expectClean } = require('./helpers');
const db = require('./mitdb');

const CLIENT = Number(db.val("SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1"));
const made = { products: [], recurringId: 0, tag: smokeName('discount') };

test.afterAll(() => {
    if (made.recurringId) {
        db.exec(`DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = ${made.recurringId}`);
        db.exec(`DELETE FROM history WHERE history_recurring_invoice_id = ${made.recurringId}`);
        db.exec(`DELETE FROM recurring_invoices WHERE recurring_invoice_id = ${made.recurringId}`);
        db.exec(`DELETE FROM mit_billing_discounts WHERE doc_type = 'recurring' AND doc_id = ${made.recurringId}`);
    }
    for (const id of made.products) {
        db.exec(`DELETE FROM products WHERE product_id = ${id}`);
    }
});

test('discounts: a percentage of the lines, never-discounted products left out', async ({ page }) => {
    test.skip(!CLIENT, 'no client here');
    test.skip(!db.val("SHOW TABLES LIKE 'mit_product_no_discount'"), 'M064 not run');
    const errors = watch(page);
    // ITFlow's windows want a category on products and invoices
    const category = Number(db.val("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1") || 0)
        || db.insert("INSERT INTO categories SET category_name = 'Smoke income', category_type = 'Income', category_color = '#888888'");
    const product = (name, price) => {
        const id = db.insert(`INSERT INTO products SET product_name = ${db.q(name)}, product_price = ${price}, product_currency_code = 'USD', product_type = 'service', product_category_id = ${category}, product_tax_id = 0`);
        made.products.push(id);
        return id;
    };
    const managed = product(`${made.tag} Managed services`, 1000);
    const licence = product(`${made.tag} M365 licence`, 20);
    const nonprofit = product(`${made.tag} Nonprofit discount`, 0);

    // "Never discounted" in the product window
    await visit(page, 'agent/products.php', errors);
    let modal = await openModal(page, `modals/product/product_edit.php?id=${licence}`, 'lg');
    const never = modal.locator('[data-mit-no-discount] input[type=checkbox]');
    await expect(never).toBeVisible();
    await never.check();
    await submit(modal, 'edit_product');
    expect(db.val(`SELECT COUNT(*) FROM mit_product_no_discount WHERE product_id = ${licence}`), 'licence never discounted').toBe('1');

    // A recurring invoice with both
    made.recurringId = db.insert(`INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'REC-', recurring_invoice_number = (SELECT n FROM (SELECT COALESCE(MAX(recurring_invoice_number), 0) + 1 AS n FROM recurring_invoices) x),
        recurring_invoice_scope = ${db.q(made.tag)}, recurring_invoice_frequency = 'month', recurring_invoice_next_date = DATE_ADD(CURDATE(), INTERVAL 1 MONTH), recurring_invoice_anchor_day = 1,
        recurring_invoice_status = 1, recurring_invoice_discount_amount = 0, recurring_invoice_amount = 1200, recurring_invoice_currency_code = 'USD', recurring_invoice_auto_send = 0,
        recurring_invoice_category_id = ${category}, recurring_invoice_quote_id = 0, recurring_invoice_client_id = ${CLIENT}`);
    db.exec(`INSERT INTO recurring_invoice_items SET item_name = ${db.q(made.tag + ' Managed services')}, item_description = '', item_quantity = 1, item_price = 1000, item_subtotal = 1000, item_tax = 0, item_total = 1000, item_order = 1, item_tax_id = 0, item_product_id = ${managed}, item_recurring_invoice_id = ${made.recurringId};
        INSERT INTO recurring_invoice_items SET item_name = ${db.q(made.tag + ' M365 licence')}, item_description = '', item_quantity = 10, item_price = 20, item_subtotal = 200, item_tax = 0, item_total = 200, item_order = 2, item_tax_id = 0, item_product_id = ${licence}, item_recurring_invoice_id = ${made.recurringId}`);

    // The Edit window: $ -> %
    await visit(page, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`, errors);
    modal = await openModal(page, `modals/recurring_invoice/recurring_invoice_edit.php?id=${made.recurringId}`);
    const box = modal.locator('[data-mit-discount]');
    await expect(box).toBeVisible();
    const dollars = modal.locator('input[name=recurring_invoice_discount]');
    await expect(dollars).toBeVisible();
    await box.locator('label', { hasText: '% of lines' }).click();
    await expect(dollars, 'ITFlow\'s dollar box hidden').toBeHidden();
    await box.locator('[name=mit_discount_percent]').fill('20');
    await box.locator('[name=mit_discount_product]').selectOption(String(nonprofit));
    await submit(modal, 'edit_recurring_invoice');
    expect(db.val(`SELECT CONCAT(ROUND(percent), '/', product_id) FROM mit_billing_discounts WHERE doc_type = 'recurring' AND doc_id = ${made.recurringId}`), 'kept').toBe(`20/${nonprofit}`);
    expect(db.val(`SELECT CONCAT(recurring_invoice_discount_amount, '/', recurring_invoice_amount) FROM recurring_invoices WHERE recurring_invoice_id = ${made.recurringId}`), '20% of the 1,000 of managed services, not the licences')
        .toBe('200.00/1000.00');

    // The page and the window again
    await visit(page, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`, errors);
    await expect(page.locator('body')).toContainText('Discount (20%)');
    modal = await openModal(page, `modals/recurring_invoice/recurring_invoice_edit.php?id=${made.recurringId}`);
    await expect(modal.locator('[name=mit_discount_mode][value=percent]')).toBeChecked();
    await expect(modal.locator('[name=mit_discount_percent]')).toHaveValue('20');
    await expect(modal.locator('[data-mit-discount-now]')).toContainText('Comes to 200.00 now - 20% of 1,000.00 (1 line never discounted left out)');

    // Back to $: a typed amount
    await modal.locator('[data-mit-discount] label', { hasText: '$ amount' }).click();
    await expect(modal.locator('input[name=recurring_invoice_discount]')).toBeVisible();
    await modal.locator('input[name=recurring_invoice_discount]').fill('50');
    await submit(modal, 'edit_recurring_invoice');
    expect(db.val(`SELECT COUNT(*) FROM mit_billing_discounts WHERE doc_type = 'recurring' AND doc_id = ${made.recurringId}`), 'the percentage gone').toBe('0');
    expect(db.val(`SELECT recurring_invoice_discount_amount FROM recurring_invoices WHERE recurring_invoice_id = ${made.recurringId}`), 'the typed amount').toBe('50.00');
    await expectClean(page, errors);
});

// Copy Invoice keeps the percentage (mitDiscountCopy - one line in agent/post/invoice.php add_invoice_copy). Written after the hook proof
// (Nov 23) found the copy path untested.
test('discounts: Copy Invoice keeps the percentage', async ({ page }) => {
    const { csrf } = require('./helpers');
    const number = Number(db.val('SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices'));
    const invoice = db.insert(`INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = ${number}, invoice_scope = ${db.q(made.tag + ' copy')}, invoice_date = CURDATE(),
        invoice_due = CURDATE(), invoice_amount = 800, invoice_discount_amount = 200, invoice_currency_code = 'USD', invoice_status = 'Draft', invoice_url_key = 'smoke${Date.now()}',
        invoice_client_id = ${CLIENT}, invoice_category_id = 0`);
    db.exec(`INSERT INTO invoice_items SET item_name = ${db.q(made.tag + ' copy line')}, item_description = '', item_quantity = 1, item_price = 1000, item_subtotal = 1000, item_tax = 0,
        item_total = 1000, item_order = 1, item_tax_id = 0, item_product_id = 0, item_invoice_id = ${invoice};
        INSERT INTO mit_billing_discounts SET doc_type = 'invoice', doc_id = ${invoice}, percent = 20, product_id = 0`);
    let copy = 0;
    try {
        await visit(page, `agent/invoice.php?invoice_id=${invoice}`);
        await db.pagePost(page, '/agent/post.php', { csrf_token: await csrf(page), add_invoice_copy: '1', invoice_id: String(invoice), date: new Date().toISOString().slice(0, 10) });
        copy = Number(db.val(`SELECT MAX(invoice_id) FROM invoices WHERE invoice_client_id = ${CLIENT} AND invoice_scope = ${db.q(made.tag + ' copy')} AND invoice_id <> ${invoice}`) || 0);
        expect(copy, 'the copy was made').toBeGreaterThan(0);
        expect(db.val(`SELECT ROUND(percent) FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = ${copy}`), 'the copy keeps 20%').toBe('20');
    } finally {
        for (const id of [invoice, copy].filter(Boolean)) {
            db.exec(`DELETE FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = ${id}; DELETE FROM invoice_items WHERE item_invoice_id = ${id};
                DELETE FROM history WHERE history_invoice_id = ${id}; DELETE FROM invoices WHERE invoice_id = ${id}`);
        }
    }
});

// New Invoice saves a percentage typed in it (mitDiscountSave - one line in agent/post/invoice.php add_invoice). Written after the hook
// proof (Nov 23) found the add path untested (the tests set a percentage only on an existing recurring invoice).
test('discounts: New Invoice keeps a percentage typed in it', async ({ page }) => {
    const { csrf } = require('./helpers');
    const scope = made.tag + ' new';
    const category = db.val("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1") || '0';
    await visit(page, 'agent/invoices.php');
    await db.pagePost(page, '/agent/post.php', { csrf_token: await csrf(page), add_invoice: '1', client_id: String(CLIENT), date: new Date().toISOString().slice(0, 10),
        category, scope, invoice_discount: '', mit_discount_present: '1', mit_discount_mode: 'percent', mit_discount_percent: '15', mit_discount_product: '0' });
    const invoice = Number(db.val(`SELECT MAX(invoice_id) FROM invoices WHERE invoice_client_id = ${CLIENT} AND invoice_scope = ${db.q(scope)}`) || 0);
    try {
        expect(invoice, 'the invoice was made').toBeGreaterThan(0);
        expect(db.val(`SELECT ROUND(percent) FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = ${invoice}`), 'its 15% kept').toBe('15');
        // ... and Edit Invoice changes it (the same save, in edit_invoice)
        const today = new Date().toISOString().slice(0, 10);
        await db.pagePost(page, '/agent/post.php', { csrf_token: await csrf(page), edit_invoice: '1', invoice_id: String(invoice), date: today, due: today,
            category, scope, invoice_discount: '', mit_discount_present: '1', mit_discount_mode: 'percent', mit_discount_percent: '25', mit_discount_product: '0' });
        expect(db.val(`SELECT ROUND(percent) FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = ${invoice}`), 'Edit Invoice: 25% now').toBe('25');
    } finally {
        if (invoice) {
            db.exec(`DELETE FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = ${invoice}; DELETE FROM history WHERE history_invoice_id = ${invoice}; DELETE FROM invoices WHERE invoice_id = ${invoice}`);
        }
    }
});

// The same percentage through quotes and recurring invoices: New / Edit / Copy Quote, New Recurring Invoice and Make Recurring from an
// invoice (mitDiscountSave / mitDiscountCopy - one line each in agent/post/quote.php and recurring_invoice.php). Written after the hook
// proof (Nov 23) found the invoice ones untested - these went the same way.
test('discounts: quotes and recurring invoices keep the percentage - new, edit, copy, make recurring', async ({ page }) => {
    const { csrf } = require('./helpers');
    const today = new Date().toISOString().slice(0, 10);
    const later = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    const category = db.val("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1") || '0';
    const pct = (type, id) => db.val(`SELECT ROUND(percent) FROM mit_billing_discounts WHERE doc_type = '${type}' AND doc_id = ${Number(id) || 0}`);
    const percent = (n) => ({ mit_discount_present: '1', mit_discount_mode: 'percent', mit_discount_percent: String(n), mit_discount_product: '0' });
    const made2 = { quotes: [], recurring: [], invoices: [] };
    await visit(page, 'agent/quotes.php');
    const post = async (fields) => db.pagePost(page, '/agent/post.php', { csrf_token: await csrf(page), ...fields });
    try {
        // Quotes
        const qscope = made.tag + ' quote';
        await post({ add_quote: '1', client_id: String(CLIENT), date: today, expire: later, category, scope: qscope, quote_discount: '', ...percent(10) });
        const quote = Number(db.val(`SELECT MAX(quote_id) FROM quotes WHERE quote_client_id = ${CLIENT} AND quote_scope = ${db.q(qscope)}`) || 0);
        made2.quotes.push(quote);
        expect(pct('quote', quote), 'New Quote keeps 10%').toBe('10');
        await post({ edit_quote: '1', quote_id: String(quote), date: today, expire: later, category, scope: qscope, quote_discount: '', ...percent(12) });
        expect(pct('quote', quote), 'Edit Quote: 12%').toBe('12');
        // The quote page, its totals by how often they are billed, says "Discount (12%)" (mitDiscountLabel - includes/billing_paper.php)
        db.exec(`INSERT INTO quote_items SET item_name = ${db.q(made.tag + ' monthly')}, item_description = '', item_quantity = 1, item_price = 100, item_subtotal = 100, item_tax = 0,
            item_total = 100, item_order = 1, item_tax_id = 0, item_product_id = 0, item_frequency = 'month', item_quote_id = ${quote}`);
        db.php(`billingRecalc('quote', ${quote});`);
        await visit(page, `agent/quote.php?quote_id=${quote}`);
        await expect(page.locator('body'), 'the quote page names the percentage').toContainText('Discount (12%)');
        await post({ add_quote_copy: '1', quote_id: String(quote), client_id: String(CLIENT), date: today, expire: later });
        const qcopy = Number(db.val(`SELECT MAX(quote_id) FROM quotes WHERE quote_client_id = ${CLIENT} AND quote_scope = ${db.q(qscope)} AND quote_id <> ${quote}`) || 0);
        made2.quotes.push(qcopy);
        expect(pct('quote', qcopy), 'Copy Quote keeps 12%').toBe('12');

        // Recurring invoices
        const rscope = made.tag + ' recurring';
        await post({ add_recurring_invoice: '1', client_id: String(CLIENT), start_date: later, frequency: 'month', category, scope: rscope, recurring_invoice_discount: '',
            auto_send: '0', lead_days: '0', due_mode: 'terms', ...percent(5) });
        const recurring = Number(db.val(`SELECT MAX(recurring_invoice_id) FROM recurring_invoices WHERE recurring_invoice_client_id = ${CLIENT} AND recurring_invoice_scope = ${db.q(rscope)}`) || 0);
        made2.recurring.push(recurring);
        expect(pct('recurring', recurring), 'New Recurring Invoice keeps 5%').toBe('5');

        // Make Recurring from an invoice with 8%
        const number = Number(db.val('SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices'));
        const iscope = made.tag + ' make recurring';
        const invoice = db.insert(`INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = ${number}, invoice_scope = ${db.q(iscope)}, invoice_date = CURDATE(), invoice_due = CURDATE(),
            invoice_amount = 0, invoice_currency_code = 'USD', invoice_status = 'Draft', invoice_url_key = 'smoke${Date.now()}', invoice_client_id = ${CLIENT}, invoice_category_id = ${Number(category)}`);
        made2.invoices.push(invoice);
        db.exec(`INSERT INTO mit_billing_discounts SET doc_type = 'invoice', doc_id = ${invoice}, percent = 8, product_id = 0`);
        await post({ add_invoice_recurring: '1', invoice_id: String(invoice), frequency: 'month' });
        const made_recurring = Number(db.val(`SELECT MAX(recurring_invoice_id) FROM recurring_invoices WHERE recurring_invoice_client_id = ${CLIENT} AND recurring_invoice_scope = ${db.q(iscope)}`) || 0);
        made2.recurring.push(made_recurring);
        expect(pct('recurring', made_recurring), 'Make Recurring keeps the invoice\'s 8%').toBe('8');
    } finally {
        for (const id of made2.quotes.filter(Boolean)) {
            db.exec(`DELETE FROM mit_billing_discounts WHERE doc_type = 'quote' AND doc_id = ${id}; DELETE FROM quote_items WHERE item_quote_id = ${id}; DELETE FROM history WHERE history_quote_id = ${id}; DELETE FROM quotes WHERE quote_id = ${id}`);
        }
        for (const id of made2.recurring.filter(Boolean)) {
            db.exec(`DELETE FROM mit_billing_discounts WHERE doc_type = 'recurring' AND doc_id = ${id}; DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = ${id};
                DELETE FROM history WHERE history_recurring_invoice_id = ${id}; DELETE FROM recurring_invoices WHERE recurring_invoice_id = ${id}`);
        }
        for (const id of made2.invoices.filter(Boolean)) {
            db.exec(`DELETE FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = ${id}; DELETE FROM history WHERE history_invoice_id = ${id}; DELETE FROM invoices WHERE invoice_id = ${id}`);
        }
    }
});

// "Never discounted" ticked in New Product (mitDiscountProductSave - one line in agent/post/product.php add_product; the first test ticks it in Edit
// Product). Written after the hook proof (Nov 23) found the add path untested.
test('discounts: New Product keeps "Never discounted"', async ({ page }) => {
    const name = `${made.tag} new never`;
    await visit(page, 'agent/products.php');
    const modal = await openModal(page, 'modals/product/product_add.php', 'lg');
    await modal.locator('input[name=name]').fill(name);
    await modal.locator('input[name=price]').fill('10');
    await modal.locator('select[name=category]').selectOption({ index: 1 }, { force: true });
    await modal.locator('[data-mit-no-discount] input[type=checkbox]').check();
    await submit(modal, 'add_product');
    const id = Number(db.val(`SELECT product_id FROM products WHERE product_name = ${db.q(name)} ORDER BY product_id DESC LIMIT 1`) || 0);
    if (id) {
        made.products.push(id);
    }
    expect(id, 'the product was made').toBeGreaterThan(0);
    expect(db.val(`SELECT COUNT(*) FROM mit_product_no_discount WHERE product_id = ${id}`), 'never discounted, from New Product').toBe('1');
});
