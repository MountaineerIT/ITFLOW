// Additional agents on a ticket (functions/mit_ticket_techs.php), end to end with the data underneath: added as a helper who follows the
// lead (row, bell, "added" email), the lead booked in ITFlow's Schedule window (the helper's own booking email + calendar item, its own
// invite UID), the helper's lists (Mine + "Helping" badge, as the technician), refusing an agent whose role can't work tickets, the
// helper made lead with ITFlow's own assignee picker (row cleared, helper booking cancelled, lead invite), and a client move taking
// off a helper who can't see the new client. Clients of its own, removed at the end.

const { test, expect } = require('@playwright/test');
const path = require('path');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, getJson, expectClean } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const stamp = Date.now();
const made = { clientId: 0, client2Id: 0, ticketId: 0, ref: '' };
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const mails = (to, like) => db.column(`SELECT email_subject FROM email_queue WHERE email_recipient = ${db.q(to)} AND email_subject LIKE ${db.q(like)} ORDER BY email_id`);
function tomorrow() {
    const d = new Date(Date.now() + 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

test('helpers: setup - a ticket led by the admin', async ({ page }) => {
    for (const [key, name] of [['clientId', smokeName('t1 helper client')], ['client2Id', smokeName('t1 helper other')]]) {
        db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD', client_net_terms = 30`);
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

test('helpers: add the technician with the lead, then book the lead', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, ticketPage(), errors);
    let modal = await openModal(page, `modals/ticket/mit_ticket_tech.php?ticket_id=${made.ticketId}`);
    const offered = await modal.locator('select[name=user_id] option').evaluateAll((o) => o.map((x) => x.value));
    expect(offered, 'the lead is not offered as a helper').not.toContain('1');
    await fillForm(modal, { user_id: '2', tech_mode: 'lead' });
    await submit(modal, 'mit_save_ticket_tech');
    expect(await flash(page)).toContain('added to the ticket');
    expect(db.rows(`SELECT follows_lead, schedule_start FROM mit_ticket_techs WHERE ticket_id = ${made.ticketId} AND user_id = 2`), 'the helper row')
        .toEqual([{ follows_lead: '1', schedule_start: null }]);
    expect(db.value(`SELECT COUNT(*) FROM notifications WHERE notification_user_id = 2 AND notification LIKE ${db.q(`%${made.ref} %added you to help%`)}`), 'their bell').toBe('1');
    expect(mails('tech@test.test', `Ticket - [${made.ref}]%`).length, 'the "added" email').toBe(1);
    await expect(page.locator('.ticket-prop:has(dt:text-is("Additional agents"))'), 'shown under Assigned to').toContainText('Restricted Tech');

    // The lead booked in ITFlow's Schedule window: the helper is booked at the same time, with their own invite
    const day = tomorrow();
    modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { scheduled_date_time: `${day}T09:00`, schedule_minutes: '90', onsite: '0' });
    await submit(modal, 'edit_ticket_schedule');
    const booked = db.rows(`SELECT email_cal_str FROM email_queue WHERE email_recipient = 'tech@test.test' AND email_subject LIKE ${db.q(`Ticket Scheduled - [${made.ref}]%`)}`);
    expect(booked.length, 'the helper\'s booking email').toBe(1);
    expect(booked[0].email_cal_str, 'its own invite UID, not the lead\'s').toContain(`ticket-${made.ticketId}-tech-2@`);
    expect(booked[0].email_cal_str).toMatch(/DTSTART[^\n]*0900/);
    expect(booked[0].email_cal_str, 'the 90 minutes').toMatch(/DTEND[^\n]*1030/);
    const items = await getJson(page, `/agent/ajax.php?calendar_items&from=${day}&to=${day}&sources=tickets`);
    const helper = items.find((i) => i.extendedProps && i.extendedProps.kind === 'helper' && Number(i.extendedProps.ticket_id) === made.ticketId);
    expect(helper, 'the helper booking on the calendar').toBeTruthy();
    expect(helper.start).toContain(`${day} 09:00`);
    await expectClean(page, errors);
});

test('helpers: the technician sees it as theirs ("Mine", Helping badge)', async ({ browser }) => {
    test.skip(!made.ticketId, 'no ticket');
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: path.join(__dirname, '.auth', 'tech.json'), baseURL: use.baseURL });
    const page = await context.newPage();
    try {
        await visit(page, `agent/tickets.php?show=mine&q=${encodeURIComponent(made.ref.replace(/^\D+/, ''))}`);
        const row = page.locator('tr', { has: page.locator(`a[href*="ticket_id=${made.ticketId}"]`) }).first();
        await expect(row, 'in the helper\'s own tickets').toBeVisible();
        await expect(row.locator('.badge', { hasText: 'Helping' }), 'with the Helping badge').toHaveCount(1);
    } finally {
        await context.close();
    }
});

test('helpers: an agent whose role cannot work tickets is refused', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await visit(page, ticketPage());
    const csrf = await page.locator('input[name=csrf_token]').first().inputValue();
    await page.evaluate(async (body) => {
        await fetch('/agent/post.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) });
    }, { mit_save_ticket_tech: '1', csrf_token: csrf, ticket_id: String(made.ticketId), user_id: '6', tech_mode: 'none' });
    expect(db.value(`SELECT COUNT(*) FROM mit_ticket_techs WHERE ticket_id = ${made.ticketId} AND user_id = 6`), 'the accountant (Support view) not added').toBe('0');
});

test('helpers: the helper made lead with ITFlow\'s assignee picker', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await visit(page, ticketPage());
    const csrf = await page.locator('input[name=csrf_token]').first().inputValue();
    const result = await page.evaluate(async (body) => {
        const r = await fetch('/agent/ajax.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) });
        return r.status;
    }, { quick_pick_set: '1', csrf_token: csrf, field: 'assignee', value: '2', 'ids[]': String(made.ticketId) });
    expect(result).toBe(200);
    expect(db.value(`SELECT ticket_assigned_to FROM tickets WHERE ticket_id = ${made.ticketId}`)).toBe('2');
    await visit(page, ticketPage()); // helpers are tidied when read
    expect(db.value(`SELECT COUNT(*) FROM mit_ticket_techs WHERE ticket_id = ${made.ticketId}`), 'no longer a helper row').toBe('0');
    const cancelled = db.rows(`SELECT email_cal_str FROM email_queue WHERE email_recipient = 'tech@test.test' AND email_subject LIKE ${db.q(`Ticket Schedule Cancelled - [${made.ref}]%`)}`);
    expect(cancelled.length, 'their helper booking cancelled').toBe(1);
    expect(cancelled[0].email_cal_str).toContain(`ticket-${made.ticketId}-tech-2@`);
    const lead = db.rows(`SELECT email_cal_str FROM email_queue WHERE email_recipient = 'tech@test.test' AND email_subject LIKE ${db.q(`Ticket Scheduled - [${made.ref}]%`)} ORDER BY email_id DESC LIMIT 1`);
    expect(lead.length && lead[0].email_cal_str, 'and sent the ticket\'s own invite as the lead').not.toContain('-tech-2@');
});

test('helpers: a client move takes off a helper who cannot see the new client', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    // The admin back as lead, the technician helping on their own time
    db.run(`UPDATE tickets SET ticket_assigned_to = 1 WHERE ticket_id = ${made.ticketId}`);
    await visit(page, ticketPage());
    let modal = await openModal(page, `modals/ticket/mit_ticket_tech.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { user_id: '2', tech_mode: 'own', scheduled_date_time: `${tomorrow()}T14:00`, schedule_minutes: '60' });
    await submit(modal, 'mit_save_ticket_tech');
    expect(db.value(`SELECT follows_lead FROM mit_ticket_techs WHERE ticket_id = ${made.ticketId} AND user_id = 2`), await flash(page)).toBe('0');
    db.run(`INSERT INTO user_client_permissions SET user_id = 2, client_id = ${made.client2Id}, permission_type = 'deny'`);
    try {
        modal = await openModal(page, `modals/ticket/ticket_change_client.php?ticket_id=${made.ticketId}`);
        await fillForm(modal, { new_client_id: String(made.client2Id) });
        await submit(modal, 'change_client_ticket');
        expect(db.value(`SELECT ticket_client_id FROM tickets WHERE ticket_id = ${made.ticketId}`)).toBe(String(made.client2Id));
        expect(db.value(`SELECT COUNT(*) FROM mit_ticket_techs WHERE ticket_id = ${made.ticketId}`), 'helper taken off').toBe('0');
        expect(db.value(`SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'tech@test.test' AND email_cal_str LIKE '%CANCEL%' AND email_cal_str LIKE ${db.q(`%ticket-${made.ticketId}-tech-2@%`)}`) >= '1', 'their own booking cancelled').toBe(true);
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
