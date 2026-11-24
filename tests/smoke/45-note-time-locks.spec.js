// Times on ticket notes are ITFlow's own since 26.12 (ticket_replies.ticket_reply_work_start / _end, the reply box's When it was worked).
// Our timesheet locks on them (functions/mit_reply_times.php, two guarded lines in agent/post/ticket.php): a note's times on a day of a submitted timesheet
// are not kept (the time counts as worked now, with a warning), a day still to come is refused the same way, a note can't be
// edited onto a submitted / approved day, and with today on an approved timesheet the time is not counted at all. Checked in ticket_replies. The admin's own timesheets are used: one submitted sheet is added for the first half of this month (removed
// again; an approved one already there is used as it is) and the current one is marked approved for one step (put back).

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, expectClean, csrf } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const pad = (n) => String(n).padStart(2, '0');
const ymd = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const now = new Date();
const today = ymd(now);
const tomorrow = ymd(new Date(Date.now() + 86400000));
const made = { clientId: 0, ticketId: 0, sheetId: 0, current: null, lockedDay: '', lockedStatus: '' };
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const reply = (text) => db.rows(`SELECT r.ticket_reply_id AS id, r.ticket_reply_time_worked AS worked, r.ticket_reply_work_start AS work_start, r.ticket_reply_work_end AS work_end
    FROM ticket_replies r WHERE r.ticket_reply_ticket_id = ${made.ticketId} AND r.ticket_reply LIKE ${db.q(`%${text}%`)}`)[0];

async function note(page, text, date, start, end) {
    await visit(page, ticketPage());
    await page.click('label[for=public_reply_type_opt1]');
    await page.locator('#replyComposer.show').waitFor();
    await page.evaluate((text) => window.tinymce.get(document.querySelector('#replyComposer textarea').id).setContent(text), text);
    await page.fill('#work_date', date);
    await page.fill('#work_start', start);
    await page.fill('#work_end', end);
    await Promise.all([page.waitForNavigation(), page.click('#ticket_add_reply')]);
    await expectClean(page);
    return flash(page);
}

test('note time locks: setup - a ticket, and a submitted timesheet for an earlier period', async () => {
    test.skip(db.value("SELECT setting_value FROM mit_settings WHERE setting_name = 'module_timesheets'") === '0', 'timesheets switched off');
    const name = smokeName('t1 locks client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    const number = Number(db.value('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
    db.run('UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
    db.run(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_source = 'Agent', ticket_subject = ${db.q(smokeName('t1 locks ticket'))}, ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 2, ticket_created_by = 1, ticket_assigned_to = 1, ticket_url_key = 'k${Date.now()}', ticket_client_id = ${made.clientId}`);
    made.ticketId = Number(db.value(`SELECT MAX(ticket_id) FROM tickets WHERE ticket_client_id = ${made.clientId}`));
    // The period before the current one, submitted (the admin has none there)
    made.current = db.rows(`SELECT sheet_id, period_start, status FROM mit_timesheets WHERE user_id = 1 AND period_start <= '${today}' AND period_end >= '${today}'`)[0] || null;
    // No sheet yet for this period (a fresh install): its start is the 1st or the 16th (semi-monthly) - not today
    const start = made.current ? made.current.period_start : today.slice(0, 8) + (Number(today.slice(8)) > 15 ? '16' : '01');
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
    const future = reply('Smoke lock future');
    expect(!future || future.work_start === null || future.work_start.slice(0, 10) !== tomorrow, `a day to come: no times kept on it (${message})`).toBe(true);

    message = await note(page, 'Smoke lock today', today, '00:05', '00:50');
    r = reply('Smoke lock today');
    expect(r.worked, `typed times set the length (${message})`).toBe('00:45:00');
    expect(r.work_start).toBe(`${today} 00:05:00`);

    // Edit it onto the submitted day: refused, nothing changes
    const modal = await openModal(page, `modals/ticket/ticket_reply_edit.php?id=${r.id}`, 'lg');
    await fillForm(modal, { work_date: made.lockedDay });
    await submit(modal, 'edit_ticket_reply');
    expect(await flash(page), 'refused').toMatch(new RegExp(`The note was not changed: .* ${made.lockedStatus}`));
    expect(reply('Smoke lock today').work_start, 'unchanged').toBe(`${today} 00:05:00`);

    // A note already on the submitted day: its time can't be changed in Edit Note (mitTsReplyTimeGuard)
    db.run(`INSERT INTO ticket_replies SET ticket_reply = 'Smoke lock already there', ticket_reply_type = 'Public', ticket_reply_time_worked = '00:30:00',
        ticket_reply_work_start = '${made.lockedDay} 10:00:00', ticket_reply_work_end = '${made.lockedDay} 10:30:00', ticket_reply_by = 1,
        ticket_reply_created_at = '${made.lockedDay} 10:30:00', ticket_reply_ticket_id = ${made.ticketId}`);
    const there = reply('Smoke lock already there');
    const lockedEdit = await openModal(page, `modals/ticket/ticket_reply_edit.php?id=${there.id}`, 'lg');
    await fillForm(lockedEdit, { time: '01:00:00' });
    await submit(lockedEdit, 'edit_ticket_reply');
    expect(await flash(page), 'refused').toContain('The time on this note stays 00:30:00');
    expect(reply('Smoke lock already there').worked, 'its time unchanged').toBe('00:30:00');
    // ... nor archived (that would take its time off the sheet too)
    await visit(page, ticketPage());
    const token = await csrf(page);
    await page.goto(`/agent/post.php?archive_ticket_reply=${there.id}&csrf_token=${encodeURIComponent(token)}`, { referer: page.url() });
    await page.waitForLoadState('load');
    expect(await flash(page), 'archiving refused').toContain('The time on this note stays 00:30:00');
    expect(db.value(`SELECT ticket_reply_archived_at FROM ticket_replies WHERE ticket_reply_id = ${there.id}`), 'not archived').toBeNull();

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

// Deleting a client whose ticket time is on a submitted timesheet is refused before anything is deleted (mitTsDeleteGuard in delete_client).
// Each ticket's delete checks too, but only when it gets to that ticket - by then the client's earlier tickets were gone. Written after
// the hook proof (Nov 23) found nothing noticed the client-level check gone.
test('note time locks: a client with time on a submitted timesheet is not deleted - nothing of it', async ({ page }) => {
    test.skip(!made.lockedDay, 'no locked sheet');
    const name = smokeName('t1 locks delete client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
    const client = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    const ticket = (subject) => {
        const number = Number(db.value('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
        db.run('UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
        db.run(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${number}, ticket_source = 'Agent', ticket_subject = ${db.q(smokeName(subject))}, ticket_details = 'x',
            ticket_priority = 'Low', ticket_status = 2, ticket_created_by = 1, ticket_assigned_to = 1, ticket_url_key = 'k${Date.now()}${number}', ticket_client_id = ${client}`);
        return Number(db.value(`SELECT MAX(ticket_id) FROM tickets WHERE ticket_client_id = ${client}`));
    };
    const plain = ticket('locks delete plain');      // first, so a delete going ticket by ticket reaches it before the locked one
    const timed = ticket('locks delete timed');
    db.run(`INSERT INTO ticket_replies SET ticket_reply = 'Smoke lock delete', ticket_reply_type = 'Public', ticket_reply_time_worked = '00:30:00',
        ticket_reply_work_start = '${made.lockedDay} 11:00:00', ticket_reply_work_end = '${made.lockedDay} 11:30:00', ticket_reply_by = 1,
        ticket_reply_created_at = '${made.lockedDay} 11:30:00', ticket_reply_ticket_id = ${timed}`);
    try {
        await visit(page, `agent/client_overview.php?client_id=${client}`);
        const token = await csrf(page);
        await page.goto(`/agent/post.php?delete_client=${client}&csrf_token=${encodeURIComponent(token)}`, { referer: page.url() });
        await page.waitForLoadState('load');
        expect(Number(db.value(`SELECT COUNT(*) FROM tickets WHERE ticket_id IN (${plain}, ${timed})`)), 'both tickets still there').toBe(2);
        expect(Number(db.value(`SELECT COUNT(*) FROM clients WHERE client_id = ${client}`)), 'the client still there').toBe(1);
    } finally {
        db.run(`DELETE FROM ticket_replies WHERE ticket_reply_ticket_id IN (${plain}, ${timed})`);
        db.run(`DELETE FROM ticket_history WHERE ticket_history_ticket_id IN (${plain}, ${timed})`);
        db.run(`DELETE FROM mit_ticket_boards WHERE ticket_id IN (${plain}, ${timed})`);
        db.run(`DELETE FROM tickets WHERE ticket_client_id = ${client}`);
        db.run(`DELETE FROM clients WHERE client_id = ${client}`);
    }
});

test.afterAll(async () => {
    if (made.current) {
        db.run(`UPDATE mit_timesheets SET status = ${db.q(made.current.status)} WHERE sheet_id = ${made.current.sheet_id}`);
    }
    if (made.sheetId) {
        db.run(`DELETE FROM mit_timesheets WHERE sheet_id = ${made.sheetId}`);
    }
    if (made.ticketId) {
        db.run(`DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticketId}`);
        db.run(`DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${made.ticketId}`);
        db.run(`DELETE FROM mit_ticket_boards WHERE ticket_id = ${made.ticketId}`);
        db.run(`DELETE FROM tickets WHERE ticket_id = ${made.ticketId}`);
    }
    if (made.clientId) {
        db.run(`DELETE FROM clients WHERE client_id = ${made.clientId}`);
    }
});
