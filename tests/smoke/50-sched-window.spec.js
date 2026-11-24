// The ticket's Schedule window (tech, How long, invite end, clash, cancel) and each agent's calendar colour field are ITFlow's own since
// 26.12. Ours on top (functions/mit_calendar.php mitServiceCalendarItems): a booked service ticket is drawn in the one service colour
// (mit_settings service_ticket_color) with its agent's colour - picked in Account > Preferences or by an admin in Admin > Users > Edit,
// ITFlow's fields - as the bar down the left (extendedProps.tech_color). Rows are made in the database and removed after.

const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, fillForm, submit, expectClean, getJson } = require('./helpers');
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

test('calendar colour: own pick in Preferences, an admin\'s pick in Users, Automatic again', async ({ page }) => {
    const errors = watch(page);
    made.color = one('SELECT user_config_calendar_color FROM user_settings WHERE user_id = 1');
    made.techColor = one(`SELECT user_config_calendar_color FROM user_settings WHERE user_id = ${techId}`);
    const service = one("SELECT setting_value FROM mit_settings WHERE setting_name = 'service_ticket_color'") || '#0d6efd';
    // Own colour (ITFlow's field)
    await visit(page, 'agent/user/user_preferences.php', errors);
    const form = page.locator('form', { has: page.locator('[name=edit_your_user_preferences]') });
    await fillForm(form, { calendar_color: '#db2777', calendar_color_auto: false });
    await submit(form, 'edit_your_user_preferences');
    expect(one('SELECT user_config_calendar_color FROM user_settings WHERE user_id = 1'), 'own colour saved').toBe('#db2777');

    // An admin sets the tech's in Admin > Users > Edit
    await visit(page, 'admin/users.php', errors);
    const modal = await openModal(page, `modals/user/user_edit.php?id=${techId}`, 'lg');
    await fillForm(modal, { calendar_color: '#0d9488', calendar_color_auto: false });
    await submit(modal, 'edit_user');
    expect(one(`SELECT user_config_calendar_color FROM user_settings WHERE user_id = ${techId}`), 'tech colour saved by the admin').toBe('#0d9488');

    // On the calendar: the tech's booking is in the service colour, the tech's colour as its bar
    const t = makeTicket({ subject: `${tag} colour`, client: 1, assigned: techId });
    made.tickets.push(t);
    sql(`UPDATE tickets SET ticket_schedule = '${day} 15:00:00', ticket_schedule_end = '${day} 16:00:00' WHERE ticket_id = ${t}`);
    await visit(page, 'agent/calendar.php', errors);
    const items = await getJson(page, `ajax.php?calendar_items=1&from=${day}&to=${day}&sources=tickets`);
    const item = items.find((i) => i.id === `ticket-${t}`);
    expect(item, 'the booking on the calendar').toBeTruthy();
    expect(item.color.toLowerCase(), 'in the service colour').toBe(service.toLowerCase());
    expect(item.extendedProps.tech_color, 'the agent bar in the picked colour').toBe('#0d9488');
    await expectClean(page, errors);
});

test.afterAll(() => {
    deleteTickets(made.tickets);
    for (const [user, value] of [[1, made.color], [techId, made.techColor]]) {
        if (value === undefined) {
            continue;
        }
        sql(`UPDATE user_settings SET user_config_calendar_color = ${value === null ? 'NULL' : `'${value}'`} WHERE user_id = ${user}`);
    }
    sql(`DELETE FROM email_queue WHERE email_subject LIKE '%${tag}%'`);
});
