// Dispatch as a dispatcher uses it (agent/mit_dispatch.php + agent/js/mit_dispatch.js): a ticket dragged from the queue onto
// an agent's column at 10:00 opens the Schedule dialog with that agent and time; the title, length, onsite and the per-booking
// "who is emailed" ticks are changed there and "Schedule it" saves exactly that (lead, start, end, onsite, subject) and emails
// only who was ticked. The ticket then sits on the agent's column, and with Dispatch View the Technician is refused every change
// (book, unbook, merge, event move) while the board still loads. Rows are made in the database and removed after; the admin's
// Dispatch settings and the role's level are put back.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean, getJson } = require('./helpers');
const { sql, one, deleteTickets, makeTicket } = require('./t2db');

test.describe.configure({ mode: 'serial' });

const tag = `ZZdsp${Date.now() % 1000000}`;
const techEmail = process.env.ITFLOW_TECH_EMAIL || 'tech@test.test';
const techId = Number(one(`SELECT user_id FROM users WHERE user_email = '${techEmail}'`));
const roleId = Number(one(`SELECT user_role_id FROM users WHERE user_id = ${techId}`));
const made = { tickets: [], state: undefined, level: undefined };

function ymd(offset) {
    const d = new Date(Date.now() + offset * 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
// A weekday about a week and a half away
let offset = 10;
while ([0, 6].includes(new Date(Date.now() + offset * 86400000).getDay())) {
    offset++;
}
const day = ymd(offset);

test.beforeAll(() => {
    made.state = one(`SELECT value FROM mit_user_preferences WHERE user_id = 1 AND preference = 'dispatch.state'`);
    sql(`INSERT INTO mit_user_preferences SET user_id = 1, preference = 'dispatch.state', value = '{"view":"day","hidden":[],"weekends":true,"board":"all","board_techs":false}'
        ON DUPLICATE KEY UPDATE value = VALUES(value)`);
});

test('Dispatch: drag a queue ticket onto an agent at 10:00, change it in the dialog, Schedule it', async ({ page }) => {
    const errors = watch(page);
    const ticket = makeTicket({ subject: `${tag} drag me`, client: 1, assigned: 0, contact: 3 });
    made.tickets.push(ticket);
    const before = Number(one('SELECT COALESCE(MAX(email_id), 0) FROM email_queue'));
    await visit(page, 'agent/mit_dispatch.php', errors);
    await page.locator('[data-md-date]').fill(day);
    await page.locator('[data-md-date]').dispatchEvent('change');
    await page.locator('[data-md-filter="q"]').fill(tag);
    await page.locator('[data-md-filter="q"]').dispatchEvent('input');
    await page.locator('[data-md-tab="unassigned"]').click();
    const card = page.locator(`.mit-dq-item[data-ticket="${ticket}"]`);
    await expect(card, 'in the No agent queue').toHaveCount(1);
    await page.waitForTimeout(800);

    // The 10:00 slot of the tech's column
    const slot = await page.evaluate((tech) => {
        const col = document.querySelector(`.mit-dispatch-col[data-tech="${tech}"]`);
        col.scrollIntoView();
        const cells = [...col.querySelectorAll('[data-time="10:00:00"]')].map((el) => el.getBoundingClientRect()).sort((a, b) => b.width - a.width);
        const r = cells[0];
        return { x: r.x + r.width / 2, y: r.y + Math.min(4, r.height / 2) };
    }, techId);
    await card.scrollIntoViewIfNeeded();
    const from = await card.boundingBox();
    await page.mouse.move(from.x + 20, from.y + 10);
    await page.mouse.down();
    await page.mouse.move(from.x + 40, from.y + 30, { steps: 5 });
    const slot2 = await page.evaluate((tech) => {
        const col = document.querySelector(`.mit-dispatch-col[data-tech="${tech}"]`);
        const r = [...col.querySelectorAll('[data-time="10:00:00"]')].map((el) => el.getBoundingClientRect()).sort((a, b) => b.width - a.width)[0];
        return { x: r.x + r.width / 2, y: r.y + Math.min(4, r.height / 2) };
    }, techId);
    await page.mouse.move(slot2.x, slot2.y, { steps: 15 });
    await page.mouse.up();

    // The dialog: the agent and time it was dropped on
    const dialog = page.locator('.swal2-popup');
    await expect(dialog, 'the Schedule dialog').toBeVisible();
    expect(await page.locator('#mdTech').inputValue(), 'the agent dropped on').toBe(String(techId));
    expect(await page.locator('#mdDate').inputValue(), 'the day shown').toBe(day);
    expect(await page.locator('#mdStart').inputValue(), 'the slot dropped on').toBe('10:00');
    await page.locator('#mdSubject').fill(`${tag} renamed`);
    await page.locator('#mdLength').selectOption('120');
    await page.locator('#mdOnsite').check();
    // Who is emailed: only the tech
    const ticks = page.locator('#mdNotify input[type=checkbox][data-who]');
    for (let i = 0; i < await ticks.count(); i++) {
        const who = await ticks.nth(i).getAttribute('data-who');
        await ticks.nth(i).setChecked(who === 'tech');
    }
    if (await page.locator('#mdN_extra').count()) {
        await page.locator('#mdN_extra').fill('');
    }
    await page.locator('.swal2-confirm').click();
    await expect(dialog).toBeHidden({ timeout: 15000 });

    const row = sql(`SELECT ticket_subject, ticket_assigned_to, ticket_schedule, ticket_schedule_end, ticket_onsite FROM tickets WHERE ticket_id = ${ticket}`)[0];
    expect(row, 'saved as set in the dialog').toEqual({ ticket_subject: `${tag} renamed`, ticket_assigned_to: String(techId), ticket_schedule: `${day} 10:00:00`,
        ticket_schedule_end: `${day} 12:00:00`, ticket_onsite: '1' });
    const ref = one(`SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = ${ticket}`);
    const to = sql(`SELECT email_recipient FROM email_queue WHERE email_id > ${before} AND email_subject LIKE 'Ticket Scheduled - [${ref}]%'`).map((r) => r.email_recipient);
    expect(to, 'only the tech emailed (contact unticked)').toEqual([techEmail]);
    const items = await getJson(page, `mit_dispatch_ajax.php?items=1&from=${day}&to=${day}&techs=${techId}`);
    const item = items.find((i) => i.id === `ticket-${ticket}`);
    expect(item && [item.start, item.end, item.extendedProps.tech], 'on the agent\'s column').toEqual([`${day} 10:00:00`, `${day} 12:00:00`, techId]);
    await expectClean(page, errors);
});

test('Dispatch View: the Technician sees the board and is refused book, unbook, merge and event moves', async ({ browser }) => {
    const techState = path.join(__dirname, '.auth', 'tech.json');
    test.skip(!fs.existsSync(techState) || !roleId, 'no Technician login');
    made.level = one(`SELECT p.user_role_permission_level FROM user_role_permissions p JOIN modules m USING (module_id) WHERE p.user_role_id = ${roleId} AND m.module_name = 'module_dispatch'`);
    test.skip(made.level === null, 'no Dispatch permission row (M035)');
    sql(`UPDATE user_role_permissions p JOIN modules m USING (module_id) SET p.user_role_permission_level = 1 WHERE p.user_role_id = ${roleId} AND m.module_name = 'module_dispatch'`);
    const ticket = made.tickets[0];
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: techState, baseURL: use.baseURL });
    const tech = await context.newPage();
    await visit(tech, 'agent/mit_dispatch.php');
    const answers = await tech.evaluate(async ([ticket, techId, day]) => {
        const csrf = document.getElementById('mitDispatch').dataset.csrf;
        const post = async (fields) => (await fetch('/agent/mit_dispatch_ajax.php', { method: 'POST', body: new URLSearchParams({ csrf_token: csrf, ...fields }) })).status;
        return {
            items: (await fetch(`/agent/mit_dispatch_ajax.php?items=1&from=${day}&to=${day}&techs=${techId}`)).status,
            book: await post({ book: 1, ticket_id: ticket, tech_id: techId, start: `${day} 14:00:00`, minutes: 60, onsite: 0 }),
            unbook: await post({ unbook: 1, ticket_id: ticket }),
            merge: await post({ merge: 1, child_id: ticket, parent_id: ticket, comment: 'x' }),
            event: await post({ event_move: 1, event_id: 1, start: `${day} 14:00:00`, from: techId, to: techId }),
            state: await post({ state: 1, view: 'day', hidden: '', board: 'all' }),
        };
    }, [String(ticket), String(techId), day]);
    expect(answers, 'View: reads and own settings allowed, changes refused').toEqual({ items: 200, book: 403, unbook: 403, merge: 403, event: 403, state: 200 });
    const row = sql(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${ticket}`)[0];
    expect(row.ticket_schedule, 'the booking untouched').toBe(`${day} 10:00:00`);
    await context.close();
});

test.afterAll(() => {
    if (made.level !== undefined && made.level !== null) {
        sql(`UPDATE user_role_permissions p JOIN modules m USING (module_id) SET p.user_role_permission_level = ${Number(made.level)} WHERE p.user_role_id = ${roleId} AND m.module_name = 'module_dispatch'`);
    }
    deleteTickets(made.tickets);
    sql(`DELETE FROM mit_user_preferences WHERE user_id = 1 AND preference = 'dispatch.state'`);
    if (made.state) {
        sql(`INSERT INTO mit_user_preferences SET user_id = 1, preference = 'dispatch.state', value = '${made.state.replace(/'/g, "''")}'`);
    }
    sql(`DELETE FROM email_queue WHERE email_subject LIKE '%${tag}%'`);
});
