// The client's ticket tiles and header line (functions/mit_client_tiles.php, modern look) against known data: a client with two
// open service tickets (one unassigned, one past its due date), an open project ticket (kept apart - not counted), one resolved five
// days ago, one closed six weeks ago, and 30 minutes of note time this month (plus 20 last month, not counted). Each tile's number
// must match, and the tiles that link must open a list showing that many. The header shows the primary contact, phone, email and
// website. The client and everything under it are removed at the end.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean } = require('./helpers');
const db = require('./db');

const made = { clientId: 0, tickets: [], projectId: 0 };

function ticket(set) {
    const number = Number(db.value('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
    db.run('UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
    db.run(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_source = 'Agent', ticket_subject = ${db.q(smokeName('t1 tiles ticket'))}, ticket_details = 'x',
        ticket_priority = 'Low', ticket_created_by = 1, ticket_url_key = 'k${number}', ticket_client_id = ${made.clientId}, ${set}`);
    const id = Number(db.value(`SELECT ticket_id FROM tickets WHERE ticket_number = ${number} AND ticket_client_id = ${made.clientId}`));
    made.tickets.push(id);
    return id;
}

test('client tiles: the numbers match the client\'s tickets, and the header line its contact', async ({ page }) => {
    const errors = watch(page);
    test.skip(db.value("SELECT value FROM mit_user_preferences WHERE user_id = 1 AND preference = 'ui.look'") === 'classic', 'the admin uses the classic look');
    const name = smokeName('t1 tiles client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD', client_website = 'tiles.example.invalid'`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    db.run(`INSERT INTO contacts SET contact_name = 'Tia Tiles', contact_title = 'Office manager', contact_email = 'tia@example.invalid', contact_phone = '3045550123', contact_primary = 1, contact_client_id = ${made.clientId}`);
    db.run(`INSERT INTO projects SET project_prefix = 'PRJ-', project_number = 8${String(Date.now()).slice(-4)}, project_name = 'Tiles project', project_client_id = ${made.clientId}`);
    made.projectId = Number(db.value(`SELECT MAX(project_id) FROM projects WHERE project_client_id = ${made.clientId}`));

    ticket('ticket_status = 1, ticket_assigned_to = 0'); // open, unassigned
    const late = ticket('ticket_status = 2, ticket_assigned_to = 1, ticket_due_at = NOW() - INTERVAL 1 DAY'); // open, past due
    ticket(`ticket_status = 1, ticket_assigned_to = 0, ticket_project_id = ${made.projectId}`); // a project ticket - kept apart
    ticket('ticket_assigned_to = 1, ticket_status = 4, ticket_resolved_at = NOW() - INTERVAL 5 DAY');
    ticket('ticket_assigned_to = 1, ticket_status = 5, ticket_resolved_at = NOW() - INTERVAL 42 DAY, ticket_closed_at = NOW() - INTERVAL 40 DAY');
    db.run(`INSERT INTO ticket_replies SET ticket_reply = 'tiles time', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:30:00', ticket_reply_by = 1, ticket_reply_ticket_id = ${late}`);
    db.run(`INSERT INTO ticket_replies SET ticket_reply = 'tiles old time', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:20:00', ticket_reply_by = 1, ticket_reply_ticket_id = ${late},
        ticket_reply_created_at = DATE_FORMAT(CURDATE(), '%Y-%m-01') - INTERVAL 1 DAY`);

    await visit(page, `agent/tickets.php?client_id=${made.clientId}`, errors);
    const tiles = await page.locator('.mit-tiles .mit-tile').evaluateAll((t) => Object.fromEntries(t.map((x) => [x.querySelector('.mit-tile-label').textContent.trim(),
        { value: x.querySelector('.mit-tile-value').textContent.trim(), href: x.getAttribute('href') }])));
    expect(Object.fromEntries(Object.entries(tiles).map(([k, v]) => [k, v.value])), 'the tiles').toEqual({
        Open: '2', Overdue: '1', Unassigned: '1', 'Closed in 30 days': '1', 'Time this month': '30m',
    });
    // The header: primary contact (title), phone, email, website
    const meta = page.locator('.mit-client-meta');
    await expect(meta).toContainText('Tia Tiles (Office manager)');
    await expect(meta).toContainText('tia@example.invalid');
    await expect(meta.locator('a[href^="tel:"]')).toHaveCount(1);
    await expect(meta.locator('a[href="https://tiles.example.invalid"]')).toHaveCount(1);

    // A tile that links opens a list with as many tickets
    for (const label of ['Open', 'Overdue', 'Unassigned']) {
        await visit(page, `agent/${tiles[label].href}`, errors);
        const ids = new Set(await page.locator('a[href*="ticket_id="]').evaluateAll((a) => a.map((x) => new URL(x.href).searchParams.get('ticket_id'))));
        const ours = [...ids].filter((id) => made.tickets.includes(Number(id)));
        expect(ours.length, `${label}: the list behind the tile`).toBe(Number(tiles[label].value));
    }
    await expectClean(page, errors);
});

test.afterAll(async () => {
    const ids = made.tickets.join(',') || '0';
    db.run(`DELETE FROM ticket_replies WHERE ticket_reply_ticket_id IN (${ids})`);
    db.run(`DELETE FROM ticket_history WHERE ticket_history_ticket_id IN (${ids})`);
    db.run(`DELETE FROM mit_ticket_boards WHERE ticket_id IN (${ids})`);
    db.run(`DELETE FROM mit_project_ticket_sla WHERE ticket_id IN (${ids})`);
    db.run(`DELETE FROM tickets WHERE ticket_id IN (${ids})`);
    if (made.projectId) {
        db.run(`DELETE FROM projects WHERE project_id = ${made.projectId}`);
    }
    if (made.clientId) {
        db.run(`DELETE FROM contacts WHERE contact_client_id = ${made.clientId}`);
        db.run(`DELETE FROM clients WHERE client_id = ${made.clientId}`);
    }
});
