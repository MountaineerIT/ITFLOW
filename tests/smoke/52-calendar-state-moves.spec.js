// The calendar page's own settings and moves (agent/calendar.php + agent/js/calendar.js + ajax.php):
//  - "7 days", Whose = the tech and a source switched off are saved to the agent's account (mit_user_preferences calendar.all)
//    and come back after a reload; the tech's booking is drawn with their first name beside the time and their colour bar;
//  - a booking dragged to another time keeps its length, a resize changes only its end, dropped on a whole day keeps its time
//    of day, a resolved ticket refuses a move.
// The agent's saved calendar settings are put back; rows made in the database are removed.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');
const { sql, one, deleteTickets, makeTicket } = require('./t2db');

test.describe.configure({ mode: 'serial' });

const tag = `ZZcal${Date.now() % 1000000}`;
const techId = Number(one(`SELECT user_id FROM users WHERE user_email = '${process.env.ITFLOW_TECH_EMAIL || 'tech@test.test'}'`));
const techFirst = String(one(`SELECT user_name FROM users WHERE user_id = ${techId}`)).split(/\s+/)[0];
const made = { tickets: [], state: undefined };

function ymd(offset) {
    const d = new Date(Date.now() + offset * 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

async function move(page, fields) {
    return page.evaluate(async (fields) => {
        const root = document.getElementById('itflowCalendar');
        const body = new URLSearchParams({ calendar_move: 1, csrf_token: root.dataset.csrf, kind: 'ticket', ...fields });
        const res = await fetch('ajax.php', { method: 'POST', credentials: 'same-origin', body });
        return { status: res.status, json: await res.json() };
    }, fields);
}
const booking = (id) => sql(`SELECT ticket_schedule s, ticket_schedule_end e FROM tickets WHERE ticket_id = ${id}`)[0];

test.beforeAll(() => {
    made.state = one(`SELECT value FROM mit_user_preferences WHERE user_id = 1 AND preference = 'calendar.all'`);
    sql(`DELETE FROM mit_user_preferences WHERE user_id = 1 AND preference = 'calendar.all'`);
});

test('calendar: 7 days, Whose and a source are saved to the account and come back', async ({ page }) => {
    const errors = watch(page);
    const t = makeTicket({ subject: `${tag} drawn`, client: 1, assigned: techId });
    made.tickets.push(t);
    const today = ymd(0);
    sql(`UPDATE tickets SET ticket_schedule = '${ymd(1)} 10:00:00', ticket_schedule_end = '${ymd(1)} 11:00:00' WHERE ticket_id = ${t}`);

    await visit(page, 'agent/calendar.php', errors);
    await page.locator('button', { hasText: /^7 days$/ }).click();
    await page.evaluate((v) => {
        const select = document.getElementById('calendarAgent');
        select.value = v;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }, String(techId));
    const holidays = page.locator('#calendarSourceholidays');
    if (await holidays.isChecked()) {
        await holidays.click();
    }
    await page.waitForTimeout(1500);
    const saved = JSON.parse(one(`SELECT value FROM mit_user_preferences WHERE user_id = 1 AND preference = 'calendar.all'`) || '{}');
    expect([saved.view, Number(saved.agent), saved.sources && saved.sources.holidays], 'saved to the account').toEqual(['timeGridMit7', techId, false]);

    await page.evaluate(() => { try { localStorage.clear(); } catch (e) { /* none */ } }); // the account copy, not this browser's
    await visit(page, 'agent/calendar.php', errors);
    await page.waitForTimeout(1000);
    expect(await page.locator('#calendarAgent').inputValue(), 'Whose came back').toBe(String(techId));
    await expect(holidays, 'the source stays off').not.toBeChecked();
    await expect(page.locator('button', { hasText: /^7 days$/ }), '7 days came back').toHaveAttribute('aria-selected', 'true');
    // The tech's booking tomorrow: first name beside the time, the agent bar
    const ev = page.locator('[data-kind="ticket"]', { hasText: `${tag} drawn` });
    await expect(ev).toHaveCount(1);
    await expect(ev.locator('.mit-cal-agent')).toHaveText(techFirst);
    expect(await ev.getAttribute('data-mit-agent'), 'agent colour bar').not.toBeNull();
    await expect(ev.locator('.mit-tech-dot'), 'a block has the bar, not a dot as well').toHaveCount(0);
    // Whose: each agent with their calendar colour (functions/mit_calendar.php mitAgentColor)
    expect(await page.locator(`#calendarAgent option[value="${techId}"]`).getAttribute('data-color'), 'the tech\'s colour in Whose').toMatch(/^#[0-9a-f]{3,8}$/i);
    expect(today).toBeTruthy();
    await expectClean(page, errors);
});

test('calendar moves: length kept, resize changes the end, a whole-day drop keeps the time, resolved refused', async ({ page }) => {
    const errors = watch(page);
    const t = makeTicket({ subject: `${tag} moved`, client: 1, assigned: techId });
    made.tickets.push(t);
    const d1 = ymd(12);
    const d2 = ymd(13);
    sql(`UPDATE tickets SET ticket_schedule = '${d1} 09:00:00', ticket_schedule_end = '${d1} 10:45:00' WHERE ticket_id = ${t}`);
    await visit(page, 'agent/calendar.php', errors);

    let r = await move(page, { id: String(t), start: `${d1} 13:00` });
    expect(r.status).toBe(200);
    expect(booking(t), 'dragged: 1h45 kept').toEqual({ s: `${d1} 13:00:00`, e: `${d1} 14:45:00` });
    r = await move(page, { id: String(t), start: `${d1} 13:00`, end: `${d1} 16:00` });
    expect(booking(t), 'resized: only the end moves').toEqual({ s: `${d1} 13:00:00`, e: `${d1} 16:00:00` });
    r = await move(page, { id: String(t), start: `${d2} 00:00`, all_day: '1' });
    expect(booking(t), 'dropped on a whole day: same time, same length').toEqual({ s: `${d2} 13:00:00`, e: `${d2} 16:00:00` });
    sql(`UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = ${t}`);
    r = await move(page, { id: String(t), start: `${d1} 08:00` });
    expect(r.status, 'a resolved ticket refuses').toBe(400);
    expect(booking(t).s, 'left where it was').toBe(`${d2} 13:00:00`);
    await expectClean(page, errors);
});

test.afterAll(() => {
    deleteTickets(made.tickets);
    sql(`DELETE FROM mit_user_preferences WHERE user_id = 1 AND preference = 'calendar.all'`);
    if (made.state) {
        sql(`INSERT INTO mit_user_preferences SET user_id = 1, preference = 'calendar.all', value = '${made.state.replace(/'/g, "''")}'`);
    }
    sql(`DELETE FROM email_queue WHERE email_subject LIKE '%${tag}%'`);
});
