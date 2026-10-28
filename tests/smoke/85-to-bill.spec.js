// To Bill (functions/mit_to_bill.php): ITFlow's Unbilled Time page, "To Bill" in the menu, also lists the parts put on tickets
// and not billed yet. One client: a "Parts to bill" card beside its tickets - ticked and priced, in the page's total as they
// change - and Make Invoice puts the ticked parts on the same invoice as the ticket's time (each with its ticket in the
// description; the ticket gets a note), leaving the unticked part for later. Every client: a Parts column, parts in each client's
// amount, and a client with only parts gets its own invoice. The old Unbilled Parts address sends people here. Makes its own
// clients, tickets, parts and category, and removes them and the invoices it made.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const made = { clients: [], tickets: [], invoices: [], category: 0 };
const N = { a: smokeName('to bill A'), b: smokeName('to bill B'), ssd: smokeName('SSD'), cable: smokeName('cable'), router: smokeName('router') };

function ticket(client, subject, resolved) {
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    const id = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = ${db.q(subject)}, ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = ${resolved ? 4 : 2}, ticket_billable = 1, ticket_resolved_at = ${resolved ? 'NOW() - INTERVAL 2 DAY' : 'NULL'}, ticket_client_id = ${client}, ticket_created_by = 1,
        ticket_url_key = MD5(RAND()), ticket_created_at = NOW() - INTERVAL 3 DAY`);
    made.tickets.push(id);
    return id;
}

function part(ticketId, client, name, qty, price) {
    return db.insert(`INSERT INTO mit_ticket_parts SET ticket_id = ${ticketId}, client_id = ${client}, product_id = 0, asset_id = 0, name = ${db.q(name)}, description = '',
        quantity = ${qty}, unit_cost = 0, unit_price = ${price}, tax_id = 0, source = 'other', proc_item_id = 0, invoice_id = 0, invoice_item_id = 0, created_by = 1, created_at = NOW() - INTERVAL 1 DAY`);
}

test.beforeAll(() => {
    test.skip(!db.val("SHOW TABLES LIKE 'mit_ticket_parts'"), 'no parts table (Procurement, M010)');
    made.category = db.insert(`INSERT INTO categories SET category_name = ${db.q(smokeName('income'))}, category_type = 'Income', category_color = '#2f6fde', category_created_at = NOW()`);
    // A client with a done ticket (an hour logged) and two parts on it
    made.a = db.insert(`INSERT INTO clients SET client_name = ${db.q(N.a)}, client_currency_code = 'USD', client_created_at = NOW()`);
    made.clients.push(made.a);
    made.ticketA = ticket(made.a, smokeName('fixed the server'), true);
    db.exec(`INSERT INTO ticket_replies SET ticket_reply = 'Done', ticket_reply_type = 'Internal', ticket_reply_time_worked = '01:00:00', ticket_reply_created_at = NOW() - INTERVAL 2 DAY,
        ticket_reply_by = 1, ticket_reply_ticket_id = ${made.ticketA}`);
    made.ssd = part(made.ticketA, made.a, N.ssd, 2, 120);
    made.cable = part(made.ticketA, made.a, N.cable, 1, 15);
    // A client with only a part, on a ticket still open
    made.b = db.insert(`INSERT INTO clients SET client_name = ${db.q(N.b)}, client_currency_code = 'USD', client_created_at = NOW()`);
    made.clients.push(made.b);
    made.ticketB = ticket(made.b, smokeName('new router'), false);
    made.router = part(made.ticketB, made.b, N.router, 1, 199.5);
});

test.afterAll(() => {
    for (const client of made.clients) {
        for (const row of db.rows(`SELECT invoice_id FROM invoices WHERE invoice_client_id = ${client}`)) {
            made.invoices.push(Number(row.invoice_id));
        }
    }
    for (const id of made.invoices) {
        db.exec(`DELETE FROM invoice_items WHERE item_invoice_id = ${id}; DELETE FROM history WHERE history_invoice_id = ${id}; DELETE FROM invoices WHERE invoice_id = ${id}`);
    }
    for (const id of made.tickets) {
        db.exec(`DELETE FROM mit_ticket_parts WHERE ticket_id = ${id}; DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${id};
            DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${id}; DELETE FROM tickets WHERE ticket_id = ${id}`);
    }
    for (const id of made.clients) {
        db.exec(`DELETE FROM logs WHERE log_client_id = ${id}; DELETE FROM clients WHERE client_id = ${id}`);
    }
    if (made.category) {
        db.exec(`DELETE FROM categories WHERE category_id = ${made.category}`);
    }
});

const money = (text) => Number(String(text).replace(/[^0-9.\-]/g, ''));

test('one client: parts beside the ticket, in the total, on the same invoice', async ({ page }) => {
    const errors = watch(page);
    await visit(page, `agent/unbilled_time.php?client_id=${made.a}`, errors);
    const card = page.locator('[data-mit-to-bill-card]');
    await expect(card, 'a Parts to bill card').toBeVisible();
    await expect(card.locator('[data-mit-part-row]')).toHaveCount(2);
    const total = page.locator('[data-unbilled-total]');
    // An hour at the client's rate (ITFlow 3.5.0: its contract's, else the default) + 2 x 120 + 15
    const hour = Number(db.php(`echo contractTicketRate(ticketRow(${made.ticketA}))['rate'];`).trim());
    await expect.poll(async () => money(await total.textContent()), 'the total counts the parts').toBeCloseTo(hour + 255, 2);
    await expect(page.locator('[data-unbilled-summary]')).toContainText('2 parts');
    await expect(page.locator('.itflow-billing-stat', { hasText: 'To bill' }), 'the To bill figure says what is parts').toContainText('$255.00 of parts');
    // The client's menu: To Bill's badge counts the ticket and its two parts
    await expect(page.locator('.app-sidebar a[href*="unbilled_time.php?client_id="] .badge'), "the client menu's badge").toHaveText('3');

    // Leave the cable for later, and bill the SSDs at 110
    const cable = card.locator('[data-mit-part-row]', { hasText: N.cable });
    await cable.locator('[data-mit-part-pick]').uncheck();
    const ssd = card.locator('[data-mit-part-row]', { hasText: N.ssd });
    await ssd.locator('[data-mit-part-price]').fill('110');
    await expect(ssd.locator('[data-mit-part-line]'), 'the line follows the price').toContainText('220.00');
    await expect.poll(async () => money(await total.textContent()), 'and the total').toBeCloseTo(hour + 220, 2);
    await expect(page.locator('[data-unbilled-summary]')).toContainText('1 part');

    await page.locator('select[name=category]').selectOption(String(made.category));
    await Promise.all([page.waitForURL(/invoice\.php\?invoice_id=\d+/), page.locator('[data-unbilled-submit]').click()]);
    const invoice = Number(new URL(page.url()).searchParams.get('invoice_id'));
    made.invoices.push(invoice);
    await expect(page.locator('.alert, [role=alert]').first(), 'the message names the parts').toContainText('1 part');

    expect(Number(db.val(`SELECT ticket_invoice_id FROM tickets WHERE ticket_id = ${made.ticketA}`)), 'the ticket on it').toBe(invoice);
    const line = db.one(`SELECT item_quantity, item_price, item_description FROM invoice_items WHERE item_invoice_id = ${invoice} AND item_name = ${db.q(N.ssd)}`);
    expect([Number(line.item_quantity), Number(line.item_price)], 'the SSDs at the price typed').toEqual([2, 110]);
    expect(line.item_description, 'with their ticket').toMatch(/^Ticket TCK-\d+/);
    expect(Number(db.val(`SELECT invoice_id FROM mit_ticket_parts WHERE part_id = ${made.ssd}`)), 'the part knows its invoice').toBe(invoice);
    expect(Number(db.val(`SELECT invoice_id FROM mit_ticket_parts WHERE part_id = ${made.cable}`)), 'the cable waits').toBe(0);
    expect(Number(db.val(`SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticketA} AND ticket_reply LIKE 'Billed 1 part%'`)), 'a note on the ticket').toBe(1);
    expect(Number(db.val(`SELECT invoice_amount FROM invoices WHERE invoice_id = ${invoice}`)), "the invoice's amount: the hour + 220").toBeCloseTo(hour + 220, 2);
    await expectClean(page, errors);
});

test('every client: a Parts column, and a client with only parts gets its invoice', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/unbilled_time.php', errors);
    await expect(page.locator('.app-sidebar a[href="/agent/unbilled_time.php"]'), 'the menu says To Bill').toContainText('To Bill');
    const rowA = page.locator('[data-unbilled-row]', { hasText: N.a });
    const rowB = page.locator('[data-unbilled-row]', { hasText: N.b });
    await expect(rowA.locator('[data-mit-to-bill-parts]'), "A's cable still waits").toContainText('1 part');
    await expect(rowB.locator('[data-mit-to-bill-parts]')).toContainText('199.50');
    await expect(page.locator('thead th', { hasText: /^Parts$/ }), 'a Parts column').toHaveCount(1);
    await expect(page.locator('.app-sidebar a[href="/agent/unbilled_time.php"] .badge'), "the menu badge's tip names the parts").toHaveAttribute('title', /\bparts?\b/);

    // Only B
    await page.locator('[data-unbilled-all]').uncheck();
    await rowB.locator('[data-unbilled-pick]').check();
    await expect.poll(async () => money(await page.locator('[data-unbilled-total]').textContent()), "B's router").toBe(199.5);
    await expect(page.locator('[data-unbilled-summary]')).toContainText('1 part');
    await page.locator('select[name=category]').selectOption(String(made.category));
    await Promise.all([page.waitForURL(/invoices\.php/), page.locator('[data-unbilled-submit]').click()]);
    await expect(page.locator('.alert, [role=alert]').first(), 'the message names the part').toContainText('1 part');
    const invoice = Number(db.val(`SELECT invoice_id FROM mit_ticket_parts WHERE part_id = ${made.router}`));
    expect(invoice, 'the router went on an invoice').toBeGreaterThan(0);
    expect(Number(db.val(`SELECT invoice_client_id FROM invoices WHERE invoice_id = ${invoice}`)), "B's own").toBe(made.b);
    expect(Number(db.val(`SELECT invoice_id FROM mit_ticket_parts WHERE part_id = ${made.cable}`)), "A's cable was not ticked").toBe(0);
    await expectClean(page, errors);
});

test('the old Unbilled Parts address opens To Bill', async ({ page }) => {
    await page.goto(`agent/mit_unbilled_parts.php?client=${made.a}`);
    await expect(page).toHaveURL(new RegExp(`unbilled_time\\.php\\?client_id=${made.a}$`));
});
