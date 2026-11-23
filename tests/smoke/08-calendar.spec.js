// The calendar is ITFlow's own since 26.12 (To schedule, Left behind, 7 days, side by side, Dispatch's meetings and clashes - built from
// ours). Ours on top (functions/mit_calendar.php mitServiceCalendarItems, agent/js/calendar.js eventDidMount, agent/calendar.php's style):
// a booked service ticket is drawn in the one service colour set on Admin > Settings > Ticket (admin/includes/mit_calendar_card.php), its
// agent's colour kept as tech_color and drawn as a bar down the left of the block ([data-mit-agent], an inset box-shadow); an unassigned
// booking has no bar. Rows are made in the database and removed after; the service colour and Whose are put back.

const { test, expect } = require('@playwright/test');
const { watch, visit, submit, getJson, expectClean } = require('./helpers');
const { sql, one, deleteTickets, makeTicket } = require('./t2db');

const tag = `ZZcol${Date.now() % 1000000}`;
const made = { tickets: [], color: null, state: undefined };

function ymd(offset) {
    const d = new Date(Date.now() + offset * 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

async function serviceColor(page, color) {
    await visit(page, 'admin/settings_ticket.php');
    const field = page.locator('#mitServiceTicketColor');
    const was = await field.inputValue();
    if (color) {
        await field.fill(color);
        await submit(page.locator('form:has(button[name=mit_edit_calendar_colors])'), 'mit_edit_calendar_colors');
    }
    return was;
}

test.afterAll(async ({ browser }) => {
    deleteTickets(made.tickets);
    if (made.state !== undefined) {
        sql(`UPDATE user_settings SET user_config_calendar_state = ${made.state === null ? 'NULL' : `'${made.state.replace(/'/g, "''")}'`} WHERE user_id = 1`);
    }
    if (made.color) {
        const use = test.info().project.use;
        const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
        await serviceColor(await context.newPage(), made.color).catch(() => {});
        await context.close();
    }
});

test('calendar: service bookings in the service colour, the agent\'s colour as a bar', async ({ page }) => {
    const errors = watch(page);
    const date = ymd(1);
    const mine = makeTicket({ subject: `${tag} mine`, client: 1, assigned: 1 });
    const nobody = makeTicket({ subject: `${tag} nobody`, client: 1, assigned: 0 });
    made.tickets.push(mine, nobody);
    sql(`UPDATE tickets SET ticket_schedule = '${date} 10:00:00', ticket_schedule_end = '${date} 11:00:00' WHERE ticket_id = ${mine}`);
    sql(`UPDATE tickets SET ticket_schedule = '${date} 13:00:00', ticket_schedule_end = '${date} 14:00:00' WHERE ticket_id = ${nobody}`);

    made.color = await serviceColor(page, '#123456');
    await visit(page, 'agent/calendar.php', errors);
    const items = await getJson(page, `ajax.php?calendar_items=1&from=${date}&to=${date}&sources=tickets`);
    const a = items.find((i) => i.id === `ticket-${mine}`);
    const b = items.find((i) => i.id === `ticket-${nobody}`);
    expect(a && a.color, 'the service colour').toBe('#123456');
    expect(a.extendedProps.tech_color, 'the agent\'s colour kept for the bar').toMatch(/^#[0-9a-f]{6}$/i);
    expect(b && b.color, 'unassigned: the service colour too').toBe('#123456');
    expect(b.extendedProps.tech_color, 'and no bar').toBeUndefined();

    // Drawn: the week with everyone's bookings - the assigned one has the bar
    made.state = one('SELECT user_config_calendar_state FROM user_settings WHERE user_id = 1');
    await visit(page, `agent/calendar.php?date=${date}`, errors);
    await page.evaluate(() => {
        const select = document.getElementById('calendarAgent');
        select.value = '0';
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await page.locator('#itflowCalendar button', { hasText: /^Week$/ }).first().click();
    const block = page.locator('[data-kind="ticket"]', { hasText: `${tag} mine` }).first();
    await expect(block).toBeVisible();
    expect(await block.getAttribute('data-mit-agent'), 'marked for the bar').toBe('1');
    expect(await block.evaluate((el) => getComputedStyle(el).boxShadow), 'the bar').toContain('inset');
    const plain = page.locator('[data-kind="ticket"]', { hasText: `${tag} nobody` }).first();
    await expect(plain).toBeVisible();
    expect(await plain.getAttribute('data-mit-agent'), 'no bar when nobody is on it').toBeNull();
    await expectClean(page, errors);
});
