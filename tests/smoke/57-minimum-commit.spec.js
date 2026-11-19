// Minimum commits (functions/mit_minimum.php, 26.11.8.9): a recurring invoice of 2 workstations + 2 users (200) and a "Minimum commit" line;
// the Edit Line window's switch locks the quantity / price, 300 saves the line at 100 with "Minimum 300.00 - other lines 200.00, topped up 100.00"
// under it, more workstations take it to 0.00 ("met"), and the window opens again with the switch on. Removes what it makes.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, expectClean } = require('./helpers');
const db = require('./mitdb');

const CLIENT = Number(db.val("SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1"));
const made = { products: [], recurringId: 0, tag: smokeName('minimum') };

test.afterAll(() => {
    if (made.recurringId) {
        db.exec(`DELETE m FROM mit_billing_minimums m JOIN recurring_invoice_items i ON i.item_id = m.item_id WHERE m.doc_type = 'recurring' AND i.item_recurring_invoice_id = ${made.recurringId}`);
        db.exec(`DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = ${made.recurringId}`);
        db.exec(`DELETE FROM history WHERE history_recurring_invoice_id = ${made.recurringId}`);
        db.exec(`DELETE FROM recurring_invoices WHERE recurring_invoice_id = ${made.recurringId}`);
    }
    for (const id of made.products) {
        db.exec(`DELETE FROM products WHERE product_id = ${id}`);
    }
});

test('minimum commit: a line that tops the invoice up to a minimum', async ({ page }) => {
    test.skip(!CLIENT, 'no client here');
    test.skip(!db.val("SHOW TABLES LIKE 'mit_billing_minimums'"), 'M065 not run');
    const errors = watch(page);
    const category = Number(db.val("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL ORDER BY category_id LIMIT 1") || 0)
        || db.insert("INSERT INTO categories SET category_name = 'Smoke income', category_type = 'Income', category_color = '#888888'");
    const product = (name, price) => {
        const id = db.insert(`INSERT INTO products SET product_name = ${db.q(name)}, product_price = ${price}, product_currency_code = 'USD', product_type = 'service', product_category_id = ${category}, product_tax_id = 0`);
        made.products.push(id);
        return id;
    };
    const workstation = product(`${made.tag} Workstation`, 50);
    const user = product(`${made.tag} User`, 50);
    const commit = product(`${made.tag} Minimum commit`, 0);

    made.recurringId = db.insert(`INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'REC-', recurring_invoice_number = (SELECT n FROM (SELECT COALESCE(MAX(recurring_invoice_number), 0) + 1 AS n FROM recurring_invoices) x),
        recurring_invoice_scope = ${db.q(made.tag)}, recurring_invoice_frequency = 'month', recurring_invoice_next_date = DATE_ADD(CURDATE(), INTERVAL 1 MONTH), recurring_invoice_anchor_day = 1,
        recurring_invoice_status = 1, recurring_invoice_discount_amount = 0, recurring_invoice_amount = 200, recurring_invoice_currency_code = 'USD', recurring_invoice_auto_send = 0,
        recurring_invoice_category_id = ${category}, recurring_invoice_quote_id = 0, recurring_invoice_client_id = ${CLIENT}`);
    const line = (name, qty, price, productId, order) => db.insert(`INSERT INTO recurring_invoice_items SET item_name = ${db.q(made.tag + ' ' + name)}, item_description = '', item_quantity = ${qty}, item_price = ${price},
        item_subtotal = ${qty * price}, item_tax = 0, item_total = ${qty * price}, item_order = ${order}, item_tax_id = 0, item_product_id = ${productId}, item_recurring_invoice_id = ${made.recurringId}`);
    const wsLine = line('Workstation', 2, 50, workstation, 1);
    line('User', 2, 50, user, 2);
    const commitLine = line('Minimum commit', 1, 0, commit, 3);

    // The Edit Line window: the switch locks quantity and price
    await visit(page, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`, errors);
    let modal = await openModal(page, `modals/billing/billing_item.php?type=recurring&doc_id=${made.recurringId}&item_id=${commitLine}`);
    const box = modal.locator('[data-mit-minimum]');
    await expect(box).toBeVisible();
    await box.locator('[name=mit_minimum_on]').check();
    await expect(modal.locator('[name=price]')).toHaveJSProperty('readOnly', true);
    await expect(modal.locator('[name=qty]')).toHaveValue('1');
    await box.locator('[name=mit_minimum]').fill('300');
    await Promise.all([page.waitForNavigation(), modal.locator('button[type=submit]').click()]);
    expect(db.val(`SELECT minimum FROM mit_billing_minimums WHERE doc_type = 'recurring' AND item_id = ${commitLine}`), 'kept').toBe('300.00');
    expect(db.val(`SELECT CONCAT(item_price, '/', (SELECT recurring_invoice_amount FROM recurring_invoices WHERE recurring_invoice_id = ${made.recurringId})) FROM recurring_invoice_items WHERE item_id = ${commitLine}`),
        'topped up 100 - the invoice comes to 300').toBe('100.00/300.00');
    const row = page.locator(`tr[data-item-id="${commitLine}"]`);
    await expect(row.locator('[data-mit-minimum-note]')).toContainText('Minimum 300.00 - other lines 200.00, topped up 100.00');

    // They grow: 5 workstations - met
    modal = await openModal(page, `modals/billing/billing_item.php?type=recurring&doc_id=${made.recurringId}&item_id=${wsLine}`);
    await modal.locator('[name=qty]').fill('5');
    await Promise.all([page.waitForNavigation(), modal.locator('button[type=submit]').click()]);
    expect(db.val(`SELECT item_price FROM recurring_invoice_items WHERE item_id = ${commitLine}`), 'past the minimum - 0.00').toBe('0.00');
    await expect(page.locator(`tr[data-item-id="${commitLine}"] [data-mit-minimum-note]`)).toContainText('other lines 350.00, met');

    // The window opens with it on
    modal = await openModal(page, `modals/billing/billing_item.php?type=recurring&doc_id=${made.recurringId}&item_id=${commitLine}`);
    await expect(modal.locator('[name=mit_minimum_on]')).toBeChecked();
    await expect(modal.locator('[name=mit_minimum]')).toHaveValue('300.00');
    await expect(modal.locator('[data-mit-minimum-now]')).toContainText('this line is 0.00 now');
    await expectClean(page, errors);
});
