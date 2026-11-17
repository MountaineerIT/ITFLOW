// Onsite bookings stand out (26.11.8.7): an onsite ticket's booking gets an orange strip with a car across its top on the calendar and on
// Dispatch's day and week views ([data-mit-onsite]); a remote one does not. Two tickets booked today for the admin, removed afterwards.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');
const db = require('./db');

const pad = (n) => String(n).padStart(2, '0');
const now = new Date();
const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

test('onsite bookings carry the orange strip, remote ones do not', async ({ page }) => {
    const errors = watch(page);
    const ids = [];
    const book = (subject, hour, onsite) => {
        const n = Number(db.value('SELECT config_ticket_next_number FROM settings WHERE company_id = 1'));
        db.run('UPDATE settings SET config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1');
        db.run(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = ${db.q(subject)}, ticket_details = 'x', ticket_priority = 'Low',
            ticket_status = 2, ticket_created_by = 1, ticket_assigned_to = 1, ticket_client_id = 1, ticket_url_key = 'strip${Date.now()}${hour}',
            ticket_schedule = '${today} ${pad(hour)}:00:00', ticket_schedule_end = '${today} ${pad(hour + 1)}:00:00', ticket_onsite = ${onsite}`);
        ids.push(Number(db.value(`SELECT MAX(ticket_id) FROM tickets WHERE ticket_subject = ${db.q(subject)}`)));
    };
    try {
        book('Smoke strip onsite', 11, 1);
        book('Smoke strip remote', 9, 0);
        const strip = async (scope) => {
            const el = scope.locator('[data-mit-onsite]').filter({ hasText: 'Smoke strip onsite' }).first();
            await expect(el, 'the onsite booking is marked').toHaveCount(1);
            const before = await el.evaluate((e) => { const s = getComputedStyle(e, '::before'); return { content: s.content, bg: s.backgroundColor, h: s.height }; });
            expect(before.content, 'the car glyph').toBe('""');
            expect(before.bg, 'orange').toBe('rgb(255, 159, 26)');
            await expect(scope.locator('[data-mit-onsite]').filter({ hasText: 'Smoke strip remote' }), 'the remote booking has no strip').toHaveCount(0);
        };

        await visit(page, `agent/calendar.php?date=${today}`, errors);
        await page.locator('#itflowCalendar').getByText('Smoke strip remote').first().waitFor({ state: 'attached' });
        await strip(page.locator('#itflowCalendar'));

        await visit(page, `agent/mit_dispatch.php?date=${today}`, errors);
        await page.locator('[data-md-view="day"]').click();
        await page.getByText('Smoke strip remote').first().waitFor({ state: 'attached' });
        await strip(page.locator('.mit-dispatch'));

        await page.locator('[data-md-view="week"]').click();
        await expect(page.locator('.mit-dw-chip[data-mit-onsite]', { hasText: 'Smoke strip onsite' }), 'week chip').toHaveCount(1);
        await expect(page.locator('.mit-dw-chip[data-mit-onsite]', { hasText: 'Smoke strip remote' })).toHaveCount(0);
        await page.locator('[data-md-view="day"]').click();
        await expectClean(page, errors);
    } finally {
        if (ids.length) {
            db.run(`DELETE FROM ticket_history WHERE ticket_history_ticket_id IN (${ids.join(',')})`);
            db.run(`DELETE FROM mit_ticket_boards WHERE ticket_id IN (${ids.join(',')})`);
            db.run(`DELETE FROM tickets WHERE ticket_id IN (${ids.join(',')})`);
        }
    }
});
