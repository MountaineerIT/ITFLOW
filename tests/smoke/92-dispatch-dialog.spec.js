// Dispatch's Schedule window is ITFlow's own since 26.12 (agent/dispatch.php #dispatchScheduleModal - its merge of the contact's other
// open tickets too). Ours on top (agent/js/mit_dispatch.js, functions/mit_dispatch.php mitDispatchSaveDetails): the ticket's details in
// the window - title, client, contact, the asset and other assets, priority, status, category, board, SLA - saved as it is booked
// (ITFlow's ticket_asset_id + ticket_assets, a history line). Laid out to fit the screen (the user's choice, Nov 22): wide, Booking on the
// left (agent, time, visit, who is emailed), Ticket on the right, the other open tickets as a slim strip, nothing to scroll on a laptop.
const { test, expect } = require('@playwright/test');
const { watch } = require('./helpers');
const { one, sql, deleteTickets, makeTicket } = require('./t2db');
const { dispatchState, dragToAgent, userId } = require('./dispatch');

const tag = `ZZmrg${Date.now() % 1000000}`;

test.describe.serial('dispatch schedule window', () => {
    let restore;
    const made = [];
    test.beforeAll(() => {
        restore = dispatchState(1);
    });
    test.afterAll(() => {
        deleteTickets(made);
        if (restore) {
            restore();
        }
    });

    test('our details in the window, laid out to fit, and assets picked as it is scheduled', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        const errors = watch(page);
        const a = makeTicket({ subject: `${tag} E`, client: 1, assigned: 0, contact: 3 });
        made.push(a);
        const modal = await dragToAgent(page, { ticketId: a, agentId: userId('tech@test.test'), search: tag });
        // Our fields in order down the Ticket column (on the right of the Booking column, which holds the agent and the email ticks)
        const order = await modal.evaluate(() => ['mitDTitle', 'mitDClient', 'mitDAsset', 'mitDPriority', 'mitDSla']
            .map((id) => { const el = document.getElementById(id); return el ? el.getBoundingClientRect().top : -1; }));
        expect(order.every((t) => t >= 0), `every field there: ${order}`).toBe(true);
        for (let i = 1; i < order.length; i++) {
            expect(order[i], `field ${i} not above the one before`).toBeGreaterThanOrEqual(order[i - 1]);
        }
        const cols = await modal.evaluate(() => {
            const m = document.getElementById('dispatchScheduleModal');
            const booking = m.querySelector('.col-lg-5');
            const ticket = m.querySelector('.mit-ds-right');
            return { agent: booking.contains(m.querySelector('[name=agent_id]')), notify: booking.contains(m.querySelector('[data-mit-notify]')),
                title: ticket.contains(m.querySelector('#mitDTitle')), side: ticket.getBoundingClientRect().left > booking.getBoundingClientRect().right - 1 };
        });
        expect(cols, 'Booking: agent and email ticks; Ticket: our fields, beside it').toEqual({ agent: true, notify: true, title: true, side: true });
        await expect(modal.locator('.mit-ds-ticket'), "the ticket's line in the title bar").toContainText(`${tag} E`);
        await expect(modal.locator('[data-dispatch-schedule-others]'), 'the other open tickets as a strip').toBeVisible();
        await expect(modal.locator('[data-dispatch-schedule-others] > .text-body-secondary'), 'without its explanation').toBeHidden();
        await expect(modal.locator('button[type=submit]'), 'Schedule It in view').toBeInViewport();
        const fits = await modal.evaluate(() => { const d = document.querySelector('#dispatchScheduleModal .modal-dialog'); return d.getBoundingClientRect().bottom <= window.innerHeight; });
        expect(fits, 'the window fits the screen').toBe(true);
        await expect(modal.locator('.itflow-select.is-multiple > #mitDOtherAssets.itflow-select-native'), 'Other assets is the chip picker').toHaveCount(1);
        expect(await modal.locator('#mitDClient').inputValue()).toBe('1');
        expect(await modal.locator('#mitDContact').inputValue()).toBe('3');
        // The main asset, then two others
        await modal.locator('#mitDAsset').selectOption('8');
        await expect(modal.locator('#mitDOtherAssets option[value="8"]'), 'the main one is not offered again').toHaveCount(0);
        await modal.locator('#mitDOtherAssets').evaluate((select) => { // behind ITFlow's picker
            Array.from(select.options).forEach((o) => { o.selected = ['9', '10'].includes(o.value); });
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        await modal.locator('button[type=submit]').click();
        await expect(modal).toBeHidden({ timeout: 10000 });
        expect(one(`SELECT ticket_asset_id FROM tickets WHERE ticket_id = ${a}`)).toBe('8');
        expect(one(`SELECT GROUP_CONCAT(asset_id ORDER BY asset_id) FROM ticket_assets WHERE ticket_id = ${a}`)).toBe('9,10');
        expect(one(`SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = ${a} AND ticket_history_description LIKE '%asset to ACME-PC1%'`)).toBe('1');
        expect(one(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${a}`)).not.toBeNull();
        expect(errors).toEqual([]);
    });

    // Found in the full test (Nov 22): Enter in a merge's reason booked the ticket (the Schedule form); the ticket number showed twice; the
    // strip's count stayed as it was after a merge; a move started from the visit's default email ticks, not the ones kept for the booking.
    test('the strip merges on Enter, counts down, and a move keeps the email ticks', async ({ page }) => {
        await page.setViewportSize({ width: 1366, height: 768 });
        const errors = watch(page);
        sql(`INSERT INTO contacts SET contact_name = '${tag} Contact', contact_email = '${tag.toLowerCase()}@example.invalid', contact_client_id = 1`);
        const contact = Number(one(`SELECT contact_id FROM contacts WHERE contact_name = '${tag} Contact' ORDER BY contact_id DESC LIMIT 1`));
        try {
            const a = makeTicket({ subject: `${tag} Strip A`, client: 1, assigned: 0, contact });
            const b = makeTicket({ subject: `${tag} Strip B`, client: 1, assigned: 1, contact });
            const c = makeTicket({ subject: `${tag} Strip C`, client: 1, assigned: 1, contact });
            made.push(a, b, c);
            const modal = await dragToAgent(page, { ticketId: a, agentId: userId('tech@test.test'), search: `${tag} Strip A` });
            await expect(modal.locator('.mit-ds-ticket a'), 'the subject only - the number is in the title').toHaveText(`${tag} Strip A`);
            const strip = modal.locator('[data-dispatch-schedule-others]');
            await expect(strip, 'two others').toContainText('2 other open tickets');
            await strip.locator('> .fw-semibold').click();
            const rowB = strip.locator(`[data-other="${b}"]`);
            await rowB.locator('.dropdown-toggle').click();
            await rowB.locator('[data-merge="keep-this"]').click();
            await rowB.locator('[data-merge-reason]').fill('Same problem - smoke test');
            await rowB.locator('[data-merge-reason]').press('Enter');
            await expect(rowB, 'merged - its row goes').toHaveCount(0, { timeout: 10000 });
            await expect(modal, 'the Schedule window stays open (nothing booked)').toBeVisible();
            expect(one(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${a}`), 'Enter did not book this ticket').toBeNull();
            expect(one(`SELECT ticket_closed_at IS NOT NULL FROM tickets WHERE ticket_id = ${b}`), 'the other was merged and closed').toBe('1');
            await expect(strip, 'the count follows').toContainText('1 other open ticket for');

            // Book it with the agent's email unticked, then the kept ticks come back for a move
            await modal.locator('#mitN_tech').uncheck();
            await modal.locator('button[type=submit]').click();
            await expect(modal).toBeHidden({ timeout: 10000 });
            const details = await page.evaluate(async (id) => (await fetch(`mit_dispatch_ajax.php?details=${id}`)).json(), a);
            expect(details.notify_kept && details.notify_kept.tech, 'the booking\'s ticks kept - the agent unticked').toBe(false);
            expect(errors).toEqual([]);
        } finally {
            sql(`DELETE FROM contacts WHERE contact_id = ${contact}`);
        }
    });
});
