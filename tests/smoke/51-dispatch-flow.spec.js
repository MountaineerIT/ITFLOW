// Dispatch is ITFlow's own since 26.12 (agent/dispatch.php, agent/js/dispatch.js - built from ours). What this checks is ours on top of it
// (agent/js/mit_dispatch.js, functions/mit_dispatch.php): a ticket dragged from the queue onto an agent's column at 10:00 opens ITFlow's
// Schedule window with that agent and time and our fields; the title changed there and the per-booking "who is emailed" ticks are saved
// with the booking (lead, start, end, onsite, subject) and only who was ticked is emailed. With our Dispatch View permission the Technician
// is refused every change (book, unbook, merge, event move) while the board still loads. Rows are made in the database and removed after;
// the admin's Dispatch view and the role's level are put back.

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean, getJson } = require('./helpers');
const { sql, one, deleteTickets, makeTicket } = require('./t2db');
const { dispatchState, dragToAgent } = require('./dispatch');

test.describe.configure({ mode: 'serial' });

const tag = `ZZdsp${Date.now() % 1000000}`;
const techEmail = process.env.ITFLOW_TECH_EMAIL || 'tech@test.test';
const techId = Number(one(`SELECT user_id FROM users WHERE user_email = '${techEmail}'`));
const roleId = Number(one(`SELECT user_role_id FROM users WHERE user_id = ${techId}`));
const made = { tickets: [], restore: null, level: undefined };

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
    made.restore = dispatchState(1);
});

test('Dispatch: drag a queue ticket onto an agent at 10:00, change it in the window, Schedule it', async ({ page }) => {
    const errors = watch(page);
    const ticket = makeTicket({ subject: `${tag} drag me`, client: 1, assigned: 0, contact: 3 });
    made.tickets.push(ticket);
    const before = Number(one('SELECT COALESCE(MAX(email_id), 0) FROM email_queue'));
    const modal = await dragToAgent(page, { ticketId: ticket, agentId: techId, day, search: tag });
    expect(await modal.locator('[name=agent_id]').inputValue(), 'the agent dropped on').toBe(String(techId));
    expect(await modal.locator('[name=start]').inputValue(), 'the slot dropped on').toBe(`${day}T10:00`);
    await modal.locator('#mitDTitle').fill(`${tag} renamed`);
    await modal.evaluate((root) => {
        const set = (name, value) => {
            const el = root.querySelector(`[name="${name}"]`);
            el.value = value;
            el.dispatchEvent(new Event('change', { bubbles: true }));
            if (window.jQuery) { window.jQuery(el).trigger('change'); }
        };
        set('minutes', '120');
        set('onsite', '1');
    });
    // Who is emailed: only the agent
    const ticks = modal.locator('[data-mit-notify] input[type=checkbox][data-who]');
    await expect(ticks.first()).toBeVisible();
    for (let i = 0; i < await ticks.count(); i++) {
        const who = await ticks.nth(i).getAttribute('data-who');
        await ticks.nth(i).setChecked(who === 'tech');
    }
    await modal.locator('[data-mit-notify] [data-who="extra"]').fill('');
    await expect(modal.locator('[name=tell_client]').locator('xpath=ancestor::div[contains(@class,"form-check")]'), 'ITFlow\'s one tick gives way to ours').toBeHidden();
    await modal.locator('button[type=submit]').click();
    await expect(modal).toBeHidden({ timeout: 15000 });

    const row = sql(`SELECT ticket_subject, ticket_assigned_to, ticket_schedule, ticket_schedule_end, ticket_onsite FROM tickets WHERE ticket_id = ${ticket}`)[0];
    expect(row, 'saved as set in the window').toEqual({ ticket_subject: `${tag} renamed`, ticket_assigned_to: String(techId), ticket_schedule: `${day} 10:00:00`,
        ticket_schedule_end: `${day} 12:00:00`, ticket_onsite: '1' });
    const ref = one(`SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = ${ticket}`);
    const to = sql(`SELECT email_recipient FROM email_queue WHERE email_id > ${before} AND email_subject LIKE '%Scheduled%${ref}%'`).map((r) => r.email_recipient);
    expect(to, 'only the agent emailed (contact unticked)').toEqual([techEmail]);
    const items = await getJson(page, `ajax.php?dispatch_items=1&from=${day}&to=${day}&agents=${techId}`);
    const item = items.find((i) => String(i.id) === `ticket-${ticket}`);
    expect(item, 'on the agent\'s column').toBeTruthy();
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
    await visit(tech, 'agent/dispatch.php');
    await expect(tech.locator('#itflowDispatch')).toHaveAttribute('data-can-edit', '0');
    const answers = await tech.evaluate(async ([ticket, techId, day]) => {
        const csrf = document.getElementById('itflowDispatch').dataset.csrf;
        const post = async (fields) => (await fetch('/agent/ajax.php', { method: 'POST', body: new URLSearchParams({ csrf_token: csrf, ...fields }) })).status;
        return {
            items: (await fetch(`/agent/ajax.php?dispatch_items=1&from=${day}&to=${day}&agents=${techId}`)).status,
            book: await post({ dispatch_book: 1, ticket_id: ticket, agent_id: techId, start: `${day} 14:00:00`, minutes: 60, onsite: 0 }),
            unbook: await post({ dispatch_unbook: 1, ticket_id: ticket }),
            merge: await post({ dispatch_merge: 1, child_id: ticket, parent_id: ticket, comment: 'x' }),
            event: await post({ dispatch_event_move: 1, event_id: 1, start: `${day} 14:00:00`, from: techId, to: techId }),
            state: await post({ dispatch_state: 1, view: 'day', hidden: '' }),
        };
    }, [String(ticket), String(techId), day]);
    expect(answers, 'View: reads and own settings allowed, changes refused').toEqual({ items: 200, book: 403, unbook: 403, merge: 403, event: 403, state: 200 });
    const row = sql(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${ticket}`)[0];
    expect(row.ticket_schedule, 'the booking untouched').toBe(`${day} 10:00:00`);
    await context.close();
});

// The side menu's Dispatch count (mitDispatchNavCount - unassigned tickets on the agent's own boards): one more unassigned ticket on one of
// the admin's boards, one more in the count. Written after the hook proof (Nov 23) found nothing noticed it gone.
test('the Dispatch menu count: unassigned tickets on your boards', async ({ page }) => {
    const errors = watch(page);
    const badge = async () => {
        await visit(page, 'agent/dispatch.php', errors);   // the count is worked out fresh on Dispatch itself
        const b = page.locator('.app-sidebar a[href="/agent/dispatch.php"] .badge');
        return (await b.count()) ? Number(await b.first().innerText()) : 0;
    };
    const myBoard = one("SELECT m.board_id FROM mit_board_members m JOIN mit_boards b ON b.board_id = m.board_id AND b.board_archived_at IS NULL WHERE m.user_id = 1 ORDER BY m.board_id LIMIT 1");
    const before = await badge();
    const ticket = makeTicket({ subject: `${tag} menu count`, client: Number(one('SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1')) });
    if (myBoard) {
        sql(`INSERT INTO mit_ticket_boards SET ticket_id = ${ticket}, board_id = ${myBoard}`);
    }
    try {
        expect(await badge(), 'one more unassigned ticket on your boards').toBe(before + 1);
    } finally {
        sql(`DELETE FROM mit_ticket_boards WHERE ticket_id = ${ticket}`);
        deleteTickets([ticket]);
    }
    await expectClean(page, errors);
});

test.afterAll(() => {
    if (made.level !== undefined && made.level !== null) {
        sql(`UPDATE user_role_permissions p JOIN modules m USING (module_id) SET p.user_role_permission_level = ${Number(made.level)} WHERE p.user_role_id = ${roleId} AND m.module_name = 'module_dispatch'`);
    }
    deleteTickets(made.tickets);
    if (made.restore) {
        made.restore();
    }
    sql(`DELETE FROM email_queue WHERE email_subject LIKE '%${tag}%'`);
});
