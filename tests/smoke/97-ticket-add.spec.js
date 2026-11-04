// New Ticket's Status and Schedule (functions/mit_ticket_add.php, 26.11.2.6 - the user's request): the window offers ITFlow's statuses and a
// booking (date / time, how long, remote / onsite); the ticket is made with them (ITFlow's ticketChangeStatus / ticketSetSchedule).
// Dispatch's day view: a drag over empty time on an agent's column opens New Ticket for that agent with the time filled in, and saving
// it comes back to Dispatch with the ticket booked there.
const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean, openModal, fillForm, submit } = require('./helpers');
const { sql, one, deleteTickets } = require('./t2db');

const tag = `ZZadd${Date.now() % 1000000}`;
const tomorrow = (() => {
    const d = new Date(Date.now() + 86400000);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
})();

test.describe.serial('new ticket status and schedule', () => {
    let state;
    test.beforeAll(() => {
        state = one(`SELECT value FROM mit_user_preferences WHERE user_id = 1 AND preference = 'dispatch.state'`);
        sql(`INSERT INTO mit_user_preferences SET user_id = 1, preference = 'dispatch.state', value = '{"view":"day","hidden":[],"weekends":true,"board":"all","board_techs":false}' ON DUPLICATE KEY UPDATE value = VALUES(value)`);
    });
    test.afterAll(() => {
        const ids = (one(`SELECT GROUP_CONCAT(ticket_id) FROM tickets WHERE ticket_subject LIKE '${tag}%'`) || '').split(',').filter(Boolean);
        deleteTickets(ids);
        if (state) {
            sql(`UPDATE mit_user_preferences SET value = '${state.replace(/'/g, "''")}' WHERE user_id = 1 AND preference = 'dispatch.state'`);
        }
    });

    test('the window sets the status and books the work', async ({ page }) => {
        const errors = watch(page);
        const status = Number(one(`SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_id NOT IN (1, 2, 4, 5) AND ticket_status_active = 1 ORDER BY ticket_status_order, ticket_status_id LIMIT 1`)
            || one(`SELECT ticket_status_id FROM ticket_statuses WHERE ticket_status_id = 2`));
        await visit(page, 'agent/tickets.php', errors);
        const modal = await openModal(page, 'modals/ticket/ticket_add.php?client_id=1', 'lg');
        await expect(modal.locator('[name="mit_status"]'), 'a Status picker').toHaveCount(1);
        await expect(modal.locator('[name="mit_schedule"]'), 'a Schedule date').toHaveCount(1);
        await fillForm(modal, {
            subject: `${tag} window`, mit_status: String(status), mit_schedule: `${tomorrow}T10:00`, mit_schedule_minutes: '90', mit_schedule_onsite: '1',
        });
        await submit(modal, 'add_ticket');
        const row = one(`SELECT CONCAT_WS('|', ticket_status, ticket_schedule, ticket_schedule_end, ticket_onsite) FROM tickets WHERE ticket_subject = '${tag} window'`);
        expect(row, 'status, booking, length and visit saved').toBe(`${status}|${tomorrow} 10:00:00|${tomorrow} 11:30:00|1`);
        await expect(page.locator('body'), 'the booking is in the message').toContainText('Scheduled for');
        await expectClean(page, errors);
    });

    test('left empty, it is an ordinary new ticket', async ({ page }) => {
        const errors = watch(page);
        await visit(page, 'agent/tickets.php', errors);
        const modal = await openModal(page, 'modals/ticket/ticket_add.php?client_id=1', 'lg');
        await fillForm(modal, { subject: `${tag} plain`, assigned_to: '0' });
        await submit(modal, 'add_ticket');
        expect(one(`SELECT CONCAT_WS('|', ticket_status, IFNULL(ticket_schedule, 'none')) FROM tickets WHERE ticket_subject = '${tag} plain'`)).toBe('1|none');
        await expectClean(page, errors);
    });

    test('a drag on Dispatch opens New Ticket for that agent and time, and books it', async ({ page }) => {
        const errors = watch(page);
        const techId = Number(one(`SELECT user_id FROM users WHERE user_email = 'tech@test.test'`));
        await visit(page, `agent/mit_dispatch.php?date=${tomorrow}`, errors);
        await page.locator(`.mit-dispatch-col[data-tech="${techId}"] [data-time="10:00:00"]`).first().waitFor();
        await page.evaluate((tech) => document.querySelector(`.mit-dispatch-col[data-tech="${tech}"]`).scrollIntoView(), techId);
        await page.waitForTimeout(400);
        // From just inside 10:00 to just above 11:00 (15-minute snaps): 10:00 - 11:00
        const slots = await page.evaluate((tech) => {
            const col = document.querySelector(`.mit-dispatch-col[data-tech="${tech}"]`);
            const at = (time, dy) => {
                const r = [...col.querySelectorAll(`[data-time="${time}"]`)].map((el) => el.getBoundingClientRect()).sort((a, b) => b.width - a.width)[0];
                return { x: r.x + r.width / 2, y: r.y + dy };
            };
            return { from: at('10:00:00', 3), to: at('11:00:00', -3) };
        }, techId);
        await page.mouse.move(slots.from.x, slots.from.y);
        await page.mouse.down();
        await page.mouse.move(slots.to.x, slots.to.y, { steps: 10 });
        await page.mouse.up();
        const modal = page.locator('.modal.show').last();
        await modal.locator('[name="mit_schedule"]').waitFor();
        await expect(modal.locator('[name="assigned_to"]'), 'assigned to the column\'s agent').toHaveValue(String(techId));
        await expect(modal.locator('[name="mit_schedule"]'), 'starts where the drag started').toHaveValue(`${tomorrow}T10:00`);
        await expect(modal.locator('[name="mit_schedule_minutes"]'), 'as long as the drag').toHaveValue('60');
        await fillForm(modal, { subject: `${tag} dispatch`, client_id: '1' });
        await submit(modal, 'add_ticket');
        expect(page.url(), 'back on Dispatch').toContain('mit_dispatch.php');
        expect(one(`SELECT CONCAT_WS('|', ticket_assigned_to, ticket_schedule, ticket_schedule_end) FROM tickets WHERE ticket_subject = '${tag} dispatch'`))
            .toBe(`${techId}|${tomorrow} 10:00:00|${tomorrow} 11:00:00`);
        await expect(page.locator('.itflow-cal-event', { hasText: `${tag} dispatch` }), 'on the board').toHaveCount(1, { timeout: 10000 });
        await expectClean(page, errors);
    });
});
