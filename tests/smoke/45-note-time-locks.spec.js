// Times on ticket notes and the timesheet locks (functions/mit_reply_times.php): a note's times on a day of a submitted timesheet
// are not kept (the time counts as worked now, with a warning), a day still to come is refused the same way, a note can't be
// edited onto a submitted / approved day, and with today on an approved timesheet the time is not counted at all. Checked in ticket_replies /
// mit_reply_times. The admin's own timesheets are used: one submitted sheet is added for the first half of this month (removed
// again; an approved one already there is used as it is) and the current one is marked approved for one step (put back).

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, expectClean } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const now = new Date();
const today = ymd(now);
const tomorrow = ymd(new Date(Date.now() + 86400000));
const made = { clientId: 0, ticketId: 0, sheetId: 0, current: null, lockedDay: '', lockedStatus: '' };
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const reply = (text) => db.rows(`SELECT r.ticket_reply_id AS id, r.ticket_reply_time_worked AS worked, t.work_start, t.work_end FROM ticket_replies r
    LEFT JOIN mit_reply_times t ON t.reply_id = r.ticket_reply_id WHERE r.ticket_reply_ticket_id = ${made.ticketId} AND r.ticket_reply LIKE ${db.q(`%${text}%`)}`)[0];

async function note(page, text, date, start, end) {
    await visit(page, ticketPage());
    await page.click('label[for=public_reply_type_opt1]');
    await page.locator('#replyComposer.show').waitFor();
    await page.evaluate((text) => window.tinymce.get(document.querySelector('#replyComposer textarea').id).setContent(text), text);
    await page.fill('#mitWorkDate', date);
    await page.fill('#mitWorkStart', start);
    await page.fill('#mitWorkEnd', end);
    await Promise.all([page.waitForNavigation(), page.click('#ticket_add_reply')]);
    await expectClean(page);
    return flash(page);
}

test('note time locks: setup - a ticket, and a submitted timesheet for an earlier period', async () => {
    test.skip(db.value("SELECT setting_value FROM mit_settings WHERE setting_name = 'module_timesheets'") === '0', 'timesheets switched off');
    const name = smokeName('t1 locks client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD', client_net_terms = 30`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    const number = Number(db.value('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
    db.run('UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
    db.run(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_source = 'Agent', ticket_subject = ${db.q(smokeName('t1 locks ticket'))}, ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 2, ticket_created_by = 1, ticket_assigned_to = 1, ticket_url_key = 'k${Date.now()}', ticket_client_id = ${made.clientId}`);
    made.ticketId = Number(db.value(`SELECT MAX(ticket_id) FROM tickets WHERE ticket_client_id = ${made.clientId}`));
    // The period before the current one, submitted (the admin has none there)
    made.current = db.rows(`SELECT sheet_id, period_start, status FROM mit_timesheets WHERE user_id = 1 AND period_start <= '${today}' AND period_end >= '${today}'`)[0] || null;
    const start = made.current ? made.current.period_start : today;
    const prevEnd = ymd(new Date(new Date(`${start}T12:00:00`).getTime() - 86400000));
    const prevStart = prevEnd.slice(0, 8) + (Number(prevEnd.slice(8)) > 15 ? '16' : '01');
    const there = db.rows(`SELECT sheet_id, status FROM mit_timesheets WHERE user_id = 1 AND period_start = '${prevStart}'`)[0];
    if (there) {
        test.skip(!['submitted', 'approved'].includes(there.status), 'the admin has an open sheet there');
        made.lockedStatus = there.status; // use it as it is
    } else {
        db.run(`INSERT INTO mit_timesheets SET user_id = 1, period_start = '${prevStart}', period_end = '${prevEnd}', status = 'submitted', submitted_at = NOW()`);
        made.sheetId = Number(db.value(`SELECT sheet_id FROM mit_timesheets WHERE user_id = 1 AND period_start = '${prevStart}'`));
        made.lockedStatus = 'submitted';
    }
    made.lockedDay = ymd(new Date(new Date(`${prevStart}T12:00:00`).getTime() + 2 * 86400000));
});

test('note time locks: submitted / approved days and days to come', async ({ page }) => {
    test.skip(!made.lockedDay, 'no locked sheet');
    const errors = watch(page);
    let message = await note(page, 'Smoke lock submitted', made.lockedDay, '09:00', '09:30');
    let r = reply('Smoke lock submitted');
    expect(r.worked, 'the length kept').toBe('00:30:00');
    expect(r.work_start, 'no times stored - it counts as worked now').toBeNull();
    expect.soft(message, 'the person is told their times were not kept').toMatch(new RegExp(`times were not kept: .* is on an? ${made.lockedStatus} timesheet`));

    message = await note(page, 'Smoke lock future', tomorrow, '09:00', '10:00');
    expect(reply('Smoke lock future').work_start, 'a day to come: times not kept').toBeNull();
    expect.soft(message, 'and the person is told').toMatch(/times were not kept: .* has not come yet/);

    message = await note(page, 'Smoke lock today', today, '00:05', '00:50');
    r = reply('Smoke lock today');
    expect(r.worked, `typed times set the length (${message})`).toBe('00:45:00');
    expect(r.work_start).toBe(`${today} 00:05:00`);

    // Edit it onto the submitted day: refused, nothing changes
    const modal = await openModal(page, `modals/ticket/ticket_reply_edit.php?id=${r.id}`, 'lg');
    await fillForm(modal, { mit_work_date: made.lockedDay });
    await submit(modal, 'edit_ticket_reply');
    expect(await flash(page), 'refused').toMatch(new RegExp(`can't move onto .* is on an? ${made.lockedStatus} timesheet`));
    expect(reply('Smoke lock today').work_start, 'unchanged').toBe(`${today} 00:05:00`);

    // Today on an approved timesheet: the time is not counted at all
    if (made.current) {
        db.run(`UPDATE mit_timesheets SET status = 'approved' WHERE sheet_id = ${made.current.sheet_id}`);
        try {
            const said = await note(page, 'Smoke lock approved', today, '00:10', '00:20');
            const a = reply('Smoke lock approved');
            expect(a.worked, 'no time on the note').toBe('00:00:00');
            expect(a.work_start).toBeNull();
            expect.soft(said, 'and the person is told why their time went').toContain('The time was not counted: today is on your approved timesheet');
        } finally {
            db.run(`UPDATE mit_timesheets SET status = ${db.q(made.current.status)} WHERE sheet_id = ${made.current.sheet_id}`);
        }
    }
    await expectClean(page, errors);
});

test.afterAll(async () => {
    if (made.current) {
        db.run(`UPDATE mit_timesheets SET status = ${db.q(made.current.status)} WHERE sheet_id = ${made.current.sheet_id}`);
    }
    if (made.sheetId) {
        db.run(`DELETE FROM mit_timesheets WHERE sheet_id = ${made.sheetId}`);
    }
    if (made.ticketId) {
        db.run(`DELETE t FROM mit_reply_times t JOIN ticket_replies r ON r.ticket_reply_id = t.reply_id WHERE r.ticket_reply_ticket_id = ${made.ticketId}`);
        db.run(`DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticketId}`);
        db.run(`DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${made.ticketId}`);
        db.run(`DELETE FROM mit_ticket_boards WHERE ticket_id = ${made.ticketId}`);
        db.run(`DELETE FROM tickets WHERE ticket_id = ${made.ticketId}`);
    }
    if (made.clientId) {
        db.run(`DELETE FROM clients WHERE client_id = ${made.clientId}`);
    }
});
