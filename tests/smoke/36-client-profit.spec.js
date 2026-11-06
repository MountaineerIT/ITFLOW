// Client profitability costs (functions/mit_profit.php, 26.11.3.4, M057): Admin > Settings > Labor costs saves a company default and an
// agent's cost per hour from a date; ITFlow's Reports > Client Profitability then shows Labor cost, Parts cost, Profit and Margin
// after Net, our figures under its own, an Invoiced / Paid switch, and break-even hours for a flat monthly agreement. The sums themselves are in tests/scenarios/scenario-client-profit.php.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const ADMIN = Number(db.val("SELECT user_id FROM users WHERE user_email = 'admin@mountaineerit.test'") || 1);
const made = { client: 0, contact: 0, ticket: 0, invoice: 0, product: 0, name: smokeName('Profit client'), oldDefault: null, rates: [] };

test.beforeAll(() => {
    test.skip(!db.val("SHOW TABLES LIKE 'mit_agent_cost_rates'"), 'database update M057 not run');
    made.oldDefault = db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'profit_default_cost'");
    made.rates = db.rows(`SELECT rate_id FROM mit_agent_cost_rates WHERE user_id = ${ADMIN}`).map((r) => Number(r.rate_id));
    made.client = db.insert(`INSERT INTO clients SET client_name = ${db.q(made.name)}, client_currency_code = 'USD'`);
    const number = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    made.ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_subject = 'Smoke profit', ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 2, ticket_created_by = ${ADMIN}, ticket_assigned_to = ${ADMIN}, ticket_client_id = ${made.client}, ticket_url_key = 'smokeprofit'`);
    db.exec(`INSERT INTO ticket_replies SET ticket_reply = 'zz', ticket_reply_type = 'Internal', ticket_reply_by = ${ADMIN}, ticket_reply_ticket_id = ${made.ticket},
        ticket_reply_time_worked = '02:00:00', ticket_reply_created_at = NOW()`);
    made.product = db.insert(`INSERT INTO products SET product_name = 'Smoke profit switch', product_price = 100, product_cost = 70, product_type = 'product',
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0`);
    const n = Number(db.val('SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices'));
    made.invoice = db.insert(`INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = ${n}, invoice_scope = 'smoke', invoice_date = CURDATE(), invoice_due = CURDATE(),
        invoice_amount = 300, invoice_currency_code = 'USD', invoice_status = 'Sent', invoice_url_key = 'smokeprofit', invoice_client_id = ${made.client}, invoice_category_id = 0`);
    db.exec(`INSERT INTO invoice_items SET item_name = 'Smoke profit switch', item_description = '', item_quantity = 3, item_price = 100, item_subtotal = 300, item_tax = 0,
        item_total = 300, item_order = 1, item_tax_id = 0, item_product_id = ${made.product}, item_invoice_id = ${made.invoice}`);
    // A flat monthly agreement of $50 - far less than 2 h at 60 / h plus the parts: over break-even
    made.recurring = db.insert(`INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'ZZR-', recurring_invoice_number = ${900000 + Math.floor(Math.random() * 90000)},
        recurring_invoice_scope = 'smoke profit', recurring_invoice_frequency = 'month', recurring_invoice_next_date = DATE_ADD(CURDATE(), INTERVAL 10 DAY),
        recurring_invoice_anchor_day = 1, recurring_invoice_status = 1, recurring_invoice_discount_amount = 0, recurring_invoice_amount = 50,
        recurring_invoice_currency_code = 'USD', recurring_invoice_auto_send = 0, recurring_invoice_client_id = ${made.client}, recurring_invoice_category_id = 0`);
});

test.afterAll(() => {
    if (!made.client) {
        return;
    }
    const keep = made.rates.length ? ` AND rate_id NOT IN (${made.rates.join(',')})` : '';
    db.exec(`DELETE FROM mit_agent_cost_rates WHERE user_id = ${ADMIN}${keep}`);
    db.exec(made.oldDefault === null ? "DELETE FROM mit_settings WHERE setting_name = 'profit_default_cost'"
        : `UPDATE mit_settings SET setting_value = ${db.q(made.oldDefault)} WHERE setting_name = 'profit_default_cost'`);
    db.exec(`DELETE FROM recurring_invoices WHERE recurring_invoice_id = ${made.recurring || 0}`);
    db.exec(`DELETE FROM invoice_items WHERE item_invoice_id = ${made.invoice}; DELETE FROM history WHERE history_invoice_id = ${made.invoice}; DELETE FROM invoices WHERE invoice_id = ${made.invoice};
        DELETE FROM products WHERE product_id = ${made.product}; DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticket}; DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${made.ticket};
        DELETE FROM tickets WHERE ticket_id = ${made.ticket}; DELETE FROM logs WHERE log_client_id = ${made.client}; DELETE FROM clients WHERE client_id = ${made.client}`);
});

test('Labor costs: a company default and an agent\'s rate from a date', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/mit_labor_costs.php', errors);
    const form = page.locator('[data-mit-labor-default]');
    await form.locator('[name=mit_labor_default]').fill('40');
    await Promise.all([page.waitForNavigation(), form.locator('button[type=submit]').click()]);
    expect(db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'profit_default_cost'"), 'default saved').toBe('40');

    const row = page.locator(`[data-mit-labor-agent="${ADMIN}"]`);
    await row.locator('[name=mit_labor_from]').fill('2000-01-01');
    await row.locator('[name=mit_labor_cost]').fill('60');
    await Promise.all([page.waitForNavigation(), row.locator('[data-mit-labor-add] button[type=submit]').click()]);
    expect(db.val(`SELECT hourly_cost FROM mit_agent_cost_rates WHERE user_id = ${ADMIN} AND effective_from = '2000-01-01'`), 'rate saved').toBe('60.00');
    await expect(page.locator(`[data-mit-labor-agent="${ADMIN}"] [data-mit-labor-now]`), 'shown as the rate now').toContainText('60.00');
    const audit = db.val("SELECT log_description FROM logs WHERE log_type = 'Settings' AND log_description LIKE '%labor cost for%' ORDER BY log_id DESC LIMIT 1") || '';
    expect(audit, 'logged without the amount').not.toContain('60');
    await expectClean(page, errors);
});

test('Client Profitability: labor, parts, profit and margin after Net, and the basis switch', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/reports/client_profitability.php?period=last_30', errors);
    await expect(page.locator('[data-mit-profit-summary]'), 'our figures under ITFlow\'s').toContainText('Labor cost');
    await expect(page.locator('[data-mit-profit-basis] a.active'), 'invoiced by default').toHaveText('Invoiced');
    const heads = await page.locator('table th').allTextContents();
    const net = heads.findIndex((h) => /^Net/.test(h.trim()));
    expect(heads.slice(net + 1, net + 5).map((h) => h.trim().split('\n')[0]), 'our columns follow Net').toEqual(['Labor cost', 'Parts cost', 'Profit', 'Margin']);
    // 300 invoiced - 2 h x 60 - 3 x 70 = -30
    const row = page.locator('tr', { hasText: made.name });
    await expect(row.locator('[data-mit-profit]'), 'profit = 300 - 120 - 210').toHaveAttribute('data-mit-profit', '-30.00');
    expect(heads.map((h) => h.trim()), 'break-even column').toContain('Break-even h / month');
    await expect(row.locator('[data-mit-break-even]'), 'a $50 agreement does not pay for 2 h').toHaveAttribute('data-mit-break-even', 'over');
    await expect(page.locator('[data-mit-profit-summary]'), 'counted under our figures').toContainText('Over break-even');
    await page.locator('[data-mit-profit-basis] a', { hasText: 'Paid' }).click();
    await expect(page).toHaveURL(/basis=paid/);
    await expect(page.locator('tr', { hasText: made.name }).locator('[data-mit-profit]'), 'paid: nothing received yet').toHaveAttribute('data-mit-profit', '-330.00');
    await expectClean(page, errors);
});

test('Labor costs is on Admin > Settings', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings.php', errors);
    await expect(page.locator('a[href*="mit_labor_costs.php"]').first(), 'a tile / menu entry').toBeVisible();
    await expectClean(page, errors);
});
