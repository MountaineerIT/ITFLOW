// Additional agents on a ticket are ITFlow's own since 26.12 (functions/ticket_helpers.php - the window, bookings, invites, badges - built
// from ours). Ours on top (functions/mit_ticket_techs.php): a helper must be able to WORK the ticket - Support edit and the client
// (ITFlow lets anyone who can read it help) - and a ticket moved to another client takes off a helper who can't see the new one.
// Clients of its own, removed at the end.

const { test, expect } = require('@playwright/test');
const { smokeName, visit, openModal, fillForm, submit, flash, action } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const stamp = Date.now();
const made = { clientId: 0, client2Id: 0, ticketId: 0, ref: '' };
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const helpers = () => db.column(`SELECT user_id FROM ticket_helpers WHERE ticket_id = ${made.ticketId} ORDER BY user_id`);
async function addHelper(page, userId) {
    await visit(page, ticketPage());
    const csrf = await page.locator('input[name=csrf_token]').first().inputValue();
    await page.evaluate(async (body) => {
        await fetch('/agent/post.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) });
    }, { save_ticket_helper: '1', csrf_token: csrf, ticket_id: String(made.ticketId), user_id: String(userId), helper_mode: 'none' });
}

test('helpers: setup - a ticket led by the admin', async ({ page }) => {
    for (const [key, name] of [['clientId', smokeName('t1 helper client')], ['client2Id', smokeName('t1 helper other')]]) {
        db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
        made[key] = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
        db.run(`INSERT INTO contacts SET contact_name = 'Hal Helper', contact_email = 'hal-${key}-${stamp}@example.invalid', contact_primary = 1, contact_client_id = ${made[key]}`);
    }
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`);
    const modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('t1 helper ticket'), details: 'Helpers test', priority: 'Low', assigned_to: '1' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, await flash(page)).toBeGreaterThan(0);
    made.ref = db.value(`SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = ${made.ticketId}`);
});

test('helpers: an agent whose role can\'t work tickets, or who can\'t see the client, is refused; the technician is taken', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await addHelper(page, 6);
    expect(helpers(), 'the accountant (Support view) not added').not.toContain('6');
    db.run(`INSERT INTO user_client_permissions SET user_id = 2, client_id = ${made.clientId}, permission_type = 'deny'`);
    try {
        await addHelper(page, 2);
        expect(helpers(), 'the technician kept out of this client not added').not.toContain('2');
    } finally {
        db.run(`DELETE FROM user_client_permissions WHERE user_id = 2 AND client_id = ${made.clientId}`);
    }
    // ITFlow's own window offers them and adds them
    await visit(page, ticketPage());
    const modal = await openModal(page, `modals/ticket/ticket_helper.php?ticket_id=${made.ticketId}`);
    // Only agents who can work the ticket are offered (the user's choice, Nov 22) - not the accountant, whose role can only view tickets
    await expect(modal.locator('select[name=user_id] option[value="6"]'), 'a view-only agent is not offered').toHaveCount(0);
    await expect(modal.locator('select[name=user_id] option[value="2"]'), 'the technician is').toHaveCount(1);
    await fillForm(modal, { user_id: '2', helper_mode: 'none' });
    await submit(modal, 'save_ticket_helper');
    expect(helpers(), `the technician added (${await flash(page)})`).toEqual(['2']);
});

test('helpers: a client move takes off a helper who cannot see the new client', async ({ page }) => {
    test.skip(!made.ticketId || !helpers().includes('2'), 'no helper');
    db.run(`INSERT INTO user_client_permissions SET user_id = 2, client_id = ${made.client2Id}, permission_type = 'deny'`);
    try {
        await visit(page, ticketPage());
        const modal = await openModal(page, `modals/ticket/ticket_change_client.php?ticket_id=${made.ticketId}`);
        await fillForm(modal, { new_client_id: String(made.client2Id) });
        await submit(modal, 'change_client_ticket');
        expect(db.value(`SELECT ticket_client_id FROM tickets WHERE ticket_id = ${made.ticketId}`)).toBe(String(made.client2Id));
        expect(helpers(), 'helper taken off').toEqual([]);
        made.clientId = made.client2Id;
    } finally {
        db.run(`DELETE FROM user_client_permissions WHERE user_id = 2 AND client_id = ${made.client2Id}`);
    }
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL });
    const page = await context.newPage();
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, ticketPage()).catch(() => {});
        db.run(`DELETE FROM email_queue WHERE email_subject LIKE ${db.q(`%[${made.ref}]%`)}`);
        db.run(`DELETE FROM notifications WHERE notification LIKE ${db.q(`%${made.ref} %`)}`);
    }
    for (const name of ['t1 helper client', 't1 helper other']) {
        for (const id of db.column(`SELECT client_id FROM clients WHERE client_name LIKE ${db.q(`Smoke test ${name}%`)}`)) {
            await action(page, `delete_client=${id}`, `agent/client_overview.php?client_id=${id}`).catch(() => {});
        }
    }
    await context.close();
});
