// The reply box's To line is ITFlow's own since 26.12 (functions/ticket_reply_recipients.php - who is emailed, what is remembered per
// ticket, a client move forgetting it - built from ours). Ours on top (functions/mit_ticket_techs.php mitReplyRecipientsHelpers): the agents
// helping on the ticket are offered in To, named "(helping)", not ticked; picked, the helper is emailed the reply. Internal notes tell
// agents with ITFlow's own @mentions (our Notify line is gone). A client of its own, removed at the end.

const { test, expect } = require('@playwright/test');
const { smokeName, visit, fillForm, submit, action, expectClean, watch } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const stamp = Date.now();
const made = { clientId: 0, ticketId: 0, number: '' };
const cara = `cara-${stamp}@example.invalid`;
const techEmail = db.value('SELECT user_email FROM users WHERE user_id = 2');
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;

test('reply recipients: setup - a client, its contact, a ticket led by the admin with the technician helping', async () => {
    const name = smokeName('t1 reply client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    db.run(`INSERT INTO contacts SET contact_name = 'Cara Contact', contact_email = '${cara}', contact_primary = 1, contact_client_id = ${made.clientId}`);
    const caraId = db.value(`SELECT contact_id FROM contacts WHERE contact_email = '${cara}'`);
    const n = 470000 + Math.floor(Math.random() * 9000);
    db.run(`INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = ${n}, ticket_subject = ${db.q(smokeName('t1 reply ticket'))}, ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 2, ticket_created_by = 1, ticket_assigned_to = 1, ticket_client_id = ${made.clientId}, ticket_contact_id = ${caraId}, ticket_url_key = 'zzr${n}'`);
    made.ticketId = Number(db.value(`SELECT ticket_id FROM tickets WHERE ticket_prefix = 'ZZ-' AND ticket_number = ${n}`));
    made.number = `ZZ-${n}`;
    db.run(`INSERT INTO ticket_helpers SET ticket_id = ${made.ticketId}, user_id = 2, follows_lead = 0, added_by = 1`);
    expect(made.ticketId).toBeGreaterThan(0);
});

test('reply recipients: the helper is offered in To, not ticked, and emailed when picked', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, ticketPage(), errors);
    test.skip(await page.locator('#replyToSelect').count() === 0, 'mail is not set up on this install');
    const option = page.locator(`#replyToSelect option[value="${techEmail}"]`);
    await expect(option, 'the helper in To').toHaveCount(1);
    await expect(option).toContainText('(helping)');
    expect(await option.evaluate((o) => o.selected), 'offered, not ticked').toBe(false);
    expect(await page.locator(`#replyToSelect option[value="${cara}"]`).evaluate((o) => o.selected), 'the contact starts in To').toBe(true);

    await page.click('label[for=public_reply_type_opt2]');
    await page.locator('#replyToSelect').evaluate((el, pick) => {
        for (const o of el.options) {
            o.selected = pick.includes(o.value);
        }
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }, [cara, techEmail]);
    const form = page.locator('form:has(#replyComposer)');
    await fillForm(form, { ticket_reply: `Smoke reply ${stamp}` });
    await submit(form, 'add_ticket_reply');
    const queued = db.column(`SELECT email_recipient FROM email_queue WHERE email_subject LIKE ${db.q(`%[${made.number}]%`)} ORDER BY email_recipient`);
    expect(queued, 'the contact and the helper').toEqual([cara, techEmail].sort());
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL });
    const page = await context.newPage();
    if (made.ticketId) {
        db.run(`DELETE FROM ticket_helpers WHERE ticket_id = ${made.ticketId}`);
        await action(page, `delete_ticket=${made.ticketId}`, ticketPage()).catch(() => {});
        db.run(`DELETE FROM email_queue WHERE email_subject LIKE ${db.q(`%[${made.number}]%`)}`);
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
