// Dispatch's Schedule window is ITFlow's own since 26.12 (agent/dispatch.php #dispatchScheduleModal - its merge of the contact's other
// open tickets too). Ours on top (agent/js/mit_dispatch.js, functions/mit_dispatch.php mitDispatchSaveDetails): the ticket's details in
// the window - title, client, contact, the asset and other assets, priority, status, category, board, SLA - in that order with the
// booking-email ticks after them, and what is picked saved as it is booked (ITFlow's ticket_asset_id + ticket_assets, a history line).
const { test, expect } = require('@playwright/test');
const { watch } = require('./helpers');
const { one, deleteTickets, makeTicket } = require('./t2db');
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

    test('our details in the window, and assets picked as it is scheduled', async ({ page }) => {
        const errors = watch(page);
        const a = makeTicket({ subject: `${tag} E`, client: 1, assigned: 0, contact: 3 });
        made.push(a);
        const modal = await dragToAgent(page, { ticketId: a, agentId: userId('tech@test.test'), search: tag });
        // Our fields in order, the email ticks after them
        const order = await modal.evaluate(() => ['mitDTitle', 'mitDClient', 'mitDContact', 'mitDAsset', 'mitDOtherAssets', 'mitDPriority', 'mitDSla']
            .map((id) => { const el = document.getElementById(id); return el ? el.getBoundingClientRect().top : -1; })
            .concat([(document.querySelector('[data-mit-notify]') || { getBoundingClientRect: () => ({ top: -1 }) }).getBoundingClientRect().top]));
        expect(order.every((t) => t >= 0), `every field there: ${order}`).toBe(true);
        for (let i = 1; i < order.length; i++) {
            expect(order[i], `field ${i} not above the one before`).toBeGreaterThanOrEqual(order[i - 1]);
        }
        expect(await modal.locator('#mitDClient').inputValue()).toBe('1');
        expect(await modal.locator('#mitDContact').inputValue()).toBe('3');
        // The main asset, then two others
        await modal.locator('#mitDAsset').selectOption('8');
        await expect(modal.locator('#mitDOtherAssets option[value="8"]'), 'the main one is not offered again').toHaveCount(0);
        await modal.locator('#mitDOtherAssets').selectOption(['9', '10']);
        await modal.locator('button[type=submit]').click();
        await expect(modal).toBeHidden({ timeout: 10000 });
        expect(one(`SELECT ticket_asset_id FROM tickets WHERE ticket_id = ${a}`)).toBe('8');
        expect(one(`SELECT GROUP_CONCAT(asset_id ORDER BY asset_id) FROM ticket_assets WHERE ticket_id = ${a}`)).toBe('9,10');
        expect(one(`SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = ${a} AND ticket_history_description LIKE '%asset to ACME-PC1%'`)).toBe('1');
        expect(one(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${a}`)).not.toBeNull();
        expect(errors).toEqual([]);
    });
});
