// Admin > Settings > Notifications - "Ticket or recurring ticket assigned to a tech" (functions/mit_notifications.php, assign_app /
// assign_email): switched off, the technician gets no bell and no email however the ticket reaches them - New Ticket with an agent,
// Edit ticket, bulk Assign and the assignee picker; switched on, each path tells them. Checked in notifications / email_queue.
// The switches are put back at the end; the client and its tickets are removed.

const { test, expect } = require('@playwright/test');
const { smokeName, visit, openModal, fillForm, submit, flash, action } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const made = { clientId: 0, tickets: [], saved: {} };
const bells = (ref) => Number(db.value(`SELECT COUNT(*) FROM notifications WHERE notification_user_id = 2 AND (notification LIKE ${db.q(`%${ref} %assigned to you%`)} OR notification LIKE ${db.q(`%${ref}%has been assigned to you%`)})`));
const bulkBells = (since) => Number(db.value(`SELECT COUNT(*) FROM notifications WHERE notification_user_id = 2 AND notification LIKE '%been assigned to you by%' AND notification_action LIKE '%tickets.php?assigned=2%' AND notification_id > ${since}`));
const mails = (ref) => Number(db.value(`SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'tech@test.test' AND email_subject LIKE ${db.q(`%${ref}%assigned to you%`)}`));
const setSwitch = (key, on) => db.run(`INSERT INTO mit_settings SET setting_name = 'notify_${key}', setting_value = '${on ? 1 : 0}' ON DUPLICATE KEY UPDATE setting_value = '${on ? 1 : 0}'`);

async function newTicket(page, assigned) {
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`);
    const modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('t1 assign ticket'), details: 'Assign switch test', priority: 'Low', assigned_to: assigned });
    await submit(modal, 'add_ticket');
    const id = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(id, await flash(page)).toBeGreaterThan(0);
    made.tickets.push(id);
    return { id, ref: db.value(`SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = ${id}`) };
}

async function everyPath(page) {
    const result = {};
    // New Ticket with the technician
    let t = await newTicket(page, '2');
    result.newTicket = [bells(t.ref), mails(t.ref)];
    // Edit ticket: given to the technician
    t = await newTicket(page, '0');
    let modal = await openModal(page, `modals/ticket/ticket_edit.php?id=${t.id}`, 'lg');
    await fillForm(modal, { assigned_to: '2' });
    await submit(modal, 'edit_ticket');
    expect(db.value(`SELECT ticket_assigned_to FROM tickets WHERE ticket_id = ${t.id}`), await flash(page)).toBe('2');
    result.editTicket = [bells(t.ref), mails(t.ref)];
    // The assignee picker
    t = await newTicket(page, '0');
    const csrf = await page.locator('input[name=csrf_token]').first().inputValue();
    await page.evaluate(async (body) => {
        await fetch('/agent/ajax.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) });
    }, { quick_pick_set: '1', csrf_token: csrf, field: 'assignee', value: '2', 'ids[]': String(t.id) });
    expect(db.value(`SELECT ticket_assigned_to FROM tickets WHERE ticket_id = ${t.id}`)).toBe('2');
    result.picker = [bells(t.ref), mails(t.ref)];
    // Bulk assign from the Tickets list
    t = await newTicket(page, '0');
    const since = Number(db.value('SELECT IFNULL(MAX(notification_id), 0) FROM notifications'));
    await page.evaluate(async (body) => {
        await fetch('/agent/post.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) });
    }, { bulk_assign_ticket: '1', csrf_token: csrf, assign_to: '2', 'ticket_ids[]': String(t.id) });
    expect(db.value(`SELECT ticket_assigned_to FROM tickets WHERE ticket_id = ${t.id}`)).toBe('2');
    result.bulk = [bulkBells(since), mails(t.ref)];
    return result;
}

test('assign switches: on - every way of assigning tells the technician', async ({ page }) => {
    for (const key of ['assign_app', 'assign_email']) {
        made.saved[key] = db.value(`SELECT setting_value FROM mit_settings WHERE setting_name = 'notify_${key}'`);
        setSwitch(key, true);
    }
    const name = smokeName('t1 assign client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    const r = await everyPath(page);
    expect(r, 'a bell and an email each way').toEqual({ newTicket: [1, 1], editTicket: [1, 1], picker: [1, 1], bulk: [1, 1] });
});

test('assign switches: off - no bell and no email, whichever way', async ({ page }) => {
    test.skip(!made.clientId, 'no client');
    setSwitch('assign_app', false);
    setSwitch('assign_email', false);
    const r = await everyPath(page);
    expect.soft(r.picker, 'the assignee picker').toEqual([0, 0]);
    expect.soft(r.newTicket, 'New Ticket with an agent').toEqual([0, 0]);
    expect.soft(r.editTicket, 'Edit ticket').toEqual([0, 0]);
    expect.soft(r.bulk, 'bulk Assign').toEqual([0, 0]);
});

test.afterAll(async ({ browser }) => {
    for (const [key, value] of Object.entries(made.saved)) {
        if (value === null) {
            db.run(`DELETE FROM mit_settings WHERE setting_name = 'notify_${key}'`);
        } else {
            db.run(`UPDATE mit_settings SET setting_value = ${db.q(value)} WHERE setting_name = 'notify_${key}'`);
        }
    }
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL });
    const page = await context.newPage();
    const ids = made.tickets.join(',') || '0';
    const refs = db.column(`SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id IN (${ids})`);
    for (const ref of refs) {
        db.run(`DELETE FROM email_queue WHERE email_subject LIKE ${db.q(`%${ref}%`)}`);
        db.run(`DELETE FROM notifications WHERE notification LIKE ${db.q(`%${ref} %`)}`);
    }
    db.run("DELETE FROM notifications WHERE notification_user_id = 2 AND notification LIKE '1 ticket has been assigned to you by%'");
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
