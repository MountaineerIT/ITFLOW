// The ticket's Schedule window as a person uses it (agent/modals/ticket/ticket_edit_schedule.php -> edit_ticket_schedule):
// another tech and "How long" are saved as the lead and the booking's end, the tech's emailed invite ends there, the ticket
// page says "start - end", the window reopens on the same length, a clash with the tech's other booking is warned about, and
// Cancel takes it off with a cancellation. Then the calendar colour: picked in Account > Preferences and by an admin in
// Admin > Users > Edit, used on the calendar, and Automatic again. Rows are made in the database and removed after.

const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, fillForm, submit, flash, expectClean, getJson, csrf } = require('./helpers');
const { sql, one, deleteTickets, makeTicket } = require('./t2db');

test.describe.configure({ mode: 'serial' });

const tag = `ZZwin${Date.now() % 1000000}`;
const made = { tickets: [], color: undefined };
const techId = Number(one(`SELECT user_id FROM users WHERE user_email = '${process.env.ITFLOW_TECH_EMAIL || 'tech@test.test'}'`));
const techEmail = process.env.ITFLOW_TECH_EMAIL || 'tech@test.test';

function ymd(offset) {
    const d = new Date(Date.now() + offset * 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
const day = ymd(9);

test('Schedule window: tech + How long saved, invite ends there, clash warned, cancel', async ({ page }) => {
    const errors = watch(page);
    const t1 = makeTicket({ subject: `${tag} one`, client: 1, assigned: 1 });
    const t2 = makeTicket({ subject: `${tag} two`, client: 1, assigned: techId });
    made.tickets.push(t1, t2);
    const before = Number(one('SELECT COALESCE(MAX(email_id), 0) FROM email_queue'));

    await visit(page, `agent/ticket.php?ticket_id=${t1}&client_id=1`, errors);
    let modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${t1}`);
    await fillForm(modal, { scheduled_date_time: `${day}T10:00`, schedule_tech: String(techId), schedule_minutes: '150', onsite: '1' });
    await submit(modal, 'edit_ticket_schedule');
    expect(await flash(page)).toMatch(/Ticket scheduled for/);
    let row = sql(`SELECT ticket_assigned_to, ticket_schedule, ticket_schedule_end, ticket_onsite FROM tickets WHERE ticket_id = ${t1}`)[0];
    expect(row, 'lead, start, end and onsite saved').toEqual({ ticket_assigned_to: String(techId), ticket_schedule: `${day} 10:00:00`, ticket_schedule_end: `${day} 12:30:00`, ticket_onsite: '1' });
    const ref = one(`SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = ${t1}`);
    const invite = sql(`SELECT email_cal_str FROM email_queue WHERE email_id > ${before} AND email_recipient = '${techEmail}' AND email_subject LIKE 'Ticket Scheduled - [${ref}]%'`);
    expect(invite.length, 'the new lead is emailed the booking').toBe(1);
    expect(invite[0].email_cal_str, 'the invite ends at the end of How long').toContain(`${day.replace(/-/g, '')}T123000`);

    // The ticket page says start - end; the window reopens on the same tech and length
    await visit(page, `agent/ticket.php?ticket_id=${t1}&client_id=1`, errors);
    await expect(page.locator('body')).toContainText(/10:00 AM - 12:30 PM/);
    modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${t1}`);
    expect(await modal.locator('[name=schedule_minutes]').inputValue(), 'How long remembered').toBe('150');
    expect(await modal.locator('[name=schedule_tech]').inputValue(), 'the tech remembered').toBe(String(techId));
    await page.keyboard.press('Escape');

    // The tech's other ticket booked at 11:00: a clash with the first is warned about
    await visit(page, `agent/ticket.php?ticket_id=${t2}&client_id=1`, errors);
    modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${t2}`);
    await fillForm(modal, { scheduled_date_time: `${day}T11:00`, schedule_minutes: '60', onsite: '0' });
    await submit(modal, 'edit_ticket_schedule');
    const message = await flash(page);
    expect(message, 'clash warned').toContain('already has work booked');
    expect(message, 'naming the other ticket').toContain(ref);

    // At 12:30 (as the first ends) there is no clash
    modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${t2}`);
    await fillForm(modal, { scheduled_date_time: `${day}T12:30`, schedule_minutes: '60', onsite: '0' });
    await submit(modal, 'edit_ticket_schedule');
    expect(await flash(page), 'touching is no clash').not.toContain('already has work booked');

    // Cancel: the booking goes and the tech is sent the cancellation
    const beforeCancel = Number(one('SELECT COALESCE(MAX(email_id), 0) FROM email_queue'));
    await visit(page, `agent/ticket.php?ticket_id=${t1}&client_id=1`, errors);
    const token = await csrf(page);
    await page.goto(`agent/post.php?cancel_ticket_schedule=${t1}&csrf_token=${encodeURIComponent(token)}`);
    row = sql(`SELECT ticket_schedule, ticket_schedule_end FROM tickets WHERE ticket_id = ${t1}`)[0];
    expect(row, 'start and end cleared').toEqual({ ticket_schedule: null, ticket_schedule_end: null });
    const cancel = sql(`SELECT email_cal_str FROM email_queue WHERE email_id > ${beforeCancel} AND email_recipient = '${techEmail}' AND email_subject LIKE 'Ticket Schedule Cancelled - [${ref}]%'`);
    expect(cancel.length, 'the tech is sent the cancellation').toBe(1);
    expect(cancel[0].email_cal_str).toContain('METHOD:CANCEL');
    await expectClean(page, errors);
});

test('calendar colour: own pick in Preferences, an admin\'s pick in Users, Automatic again', async ({ page }) => {
    const errors = watch(page);
    made.color = one(`SELECT value FROM mit_user_preferences WHERE user_id = 1 AND preference = 'calendar.color'`);
    made.techColor = one(`SELECT value FROM mit_user_preferences WHERE user_id = ${techId} AND preference = 'calendar.color'`);
    // Own colour
    await visit(page, 'agent/user/user_preferences.php', errors);
    const form = page.locator('form', { has: page.locator('[name=edit_your_user_preferences]') });
    await form.locator('[data-mit-color="#db2777"]').click();
    await expect(form.locator('[name=mit_calendar_color_auto]'), 'picking a preset unticks Automatic').not.toBeChecked();
    await submit(form, 'edit_your_user_preferences');
    expect(one(`SELECT value FROM mit_user_preferences WHERE user_id = 1 AND preference = 'calendar.color'`), 'own colour saved').toBe('#db2777');

    // An admin sets the tech's in Admin > Users > Edit
    await visit(page, 'admin/users.php', errors);
    const modal = await openModal(page, `modals/user/user_edit.php?id=${techId}`, 'lg');
    await fillForm(modal, { mit_calendar_color: '#0d9488', mit_calendar_color_auto: false });
    await submit(modal, 'edit_user');
    expect(one(`SELECT value FROM mit_user_preferences WHERE user_id = ${techId} AND preference = 'calendar.color'`), 'tech colour saved by the admin').toBe('#0d9488');

    // On the calendar: the tech's booking carries the tech's colour as its bar
    const t = makeTicket({ subject: `${tag} colour`, client: 1, assigned: techId });
    made.tickets.push(t);
    sql(`UPDATE tickets SET ticket_schedule = '${day} 15:00:00', ticket_schedule_end = '${day} 16:00:00' WHERE ticket_id = ${t}`);
    await visit(page, 'agent/calendar.php', errors);
    const items = await getJson(page, `ajax.php?calendar_items=1&from=${day}&to=${day}&sources=tickets`);
    const item = items.find((i) => i.id === `ticket-${t}`);
    expect(item && item.extendedProps.tech_color, 'the agent bar in the picked colour').toBe('#0d9488');

    // Automatic: the preference goes
    await visit(page, 'agent/user/user_preferences.php', errors);
    await fillForm(form, { mit_calendar_color_auto: true });
    await submit(form, 'edit_your_user_preferences');
    expect(one(`SELECT COUNT(*) FROM mit_user_preferences WHERE user_id = 1 AND preference = 'calendar.color'`), 'Automatic removes the pick').toBe('0');
    await expectClean(page, errors);
});

test.afterAll(() => {
    deleteTickets(made.tickets);
    for (const [user, value] of [[1, made.color], [techId, made.techColor]]) {
        if (value === undefined) {
            continue;
        }
        sql(`DELETE FROM mit_user_preferences WHERE user_id = ${user} AND preference = 'calendar.color'`);
        if (value !== null) {
            sql(`INSERT INTO mit_user_preferences SET user_id = ${user}, preference = 'calendar.color', value = '${value}'`);
        }
    }
    sql(`DELETE FROM email_queue WHERE email_subject LIKE '%${tag}%'`);
});
