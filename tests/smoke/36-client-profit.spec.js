// Client profitability's labor costs are ITFlow's own since 26.12 (functions/labor_cost.php, Admin > Labor Costs, the report's Labor /
// Parts / Profit / Margin and break-even columns - built from ours). Ours on top (functions/mit_profit.php): the report opens on Invoiced
// (ITFlow's opens on Paid), and an invoice line billed from ticket parts - it carries no product, so ITFlow's product-cost sum misses it -
// counts its parts' own cost in Parts cost. The sums in depth: tests/scenarios/scenario-client-profit.php.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./mitdb');

const ADMIN = Number(db.val("SELECT user_id FROM users WHERE user_email = 'admin@mountaineerit.test'") || 1);
const made = { client: 0, ticket: 0, invoice: 0, item: 0, name: smokeName('Profit client') };

test.beforeAll(() => {
    made.client = db.insert(`INSERT INTO clients SET client_name = ${db.q(made.name)}, client_currency_code = 'USD'`);
    const number = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    made.ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_subject = 'Smoke profit', ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 2, ticket_created_by = ${ADMIN}, ticket_assigned_to = ${ADMIN}, ticket_client_id = ${made.client}, ticket_url_key = 'smokeprofit'`);
    const n = Number(db.val('SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices'));
    made.invoice = db.insert(`INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = ${n}, invoice_scope = 'smoke', invoice_date = CURDATE(), invoice_due = CURDATE(),
        invoice_amount = 300, invoice_currency_code = 'USD', invoice_status = 'Sent', invoice_url_key = 'smokeprofit', invoice_client_id = ${made.client}, invoice_category_id = 0`);
    // A line billed from ticket parts: no product on it
    made.item = db.insert(`INSERT INTO invoice_items SET item_name = 'Smoke profit switch', item_description = '', item_quantity = 3, item_price = 100, item_subtotal = 300, item_tax = 0,
        item_total = 300, item_order = 1, item_tax_id = 0, item_product_id = 0, item_invoice_id = ${made.invoice}`);
    db.exec(`INSERT INTO mit_ticket_parts SET ticket_id = ${made.ticket}, client_id = ${made.client}, name = 'Smoke profit switch', quantity = 3, unit_cost = 70, unit_price = 100,
        source = 'stock', invoice_id = ${made.invoice}, invoice_item_id = ${made.item}, billed_at = NOW(), created_by = ${ADMIN}`);
});

test.afterAll(() => {
    if (!made.client) {
        return;
    }
    db.exec(`DELETE FROM mit_ticket_parts WHERE ticket_id = ${made.ticket}; DELETE FROM invoice_items WHERE item_invoice_id = ${made.invoice};
        DELETE FROM history WHERE history_invoice_id = ${made.invoice}; DELETE FROM invoices WHERE invoice_id = ${made.invoice};
        DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${made.ticket}; DELETE FROM tickets WHERE ticket_id = ${made.ticket};
        DELETE FROM logs WHERE log_client_id = ${made.client}; DELETE FROM clients WHERE client_id = ${made.client}`);
});

test('Client Profitability opens on Invoiced, and counts billed ticket parts in Parts cost', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/reports/client_profitability.php?period=last_30', errors);
    const basis = page.locator('[data-labor-basis] a.active');
    test.skip(await page.locator('[data-labor-basis]').count() === 0, 'ITFlow\'s labor costs not set up here');
    await expect(basis, 'Invoiced by default').toHaveText('Invoiced');
    const heads = (await page.locator('table th').allTextContents()).map((h) => h.trim().split('\n')[0].trim());
    const parts = heads.indexOf('Parts cost');
    expect(parts, 'the Parts cost column').toBeGreaterThan(0);
    const row = page.locator('tr', { hasText: made.name });
    await expect(row).toHaveCount(1);
    await expect(row.locator('td').nth(parts), '3 parts at 70 billed on a line with no product').toContainText('210.00');
    await expectClean(page, errors);
});
