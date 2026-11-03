// Dispatch's Schedule dialog: its order (booking, ticket details with the assets, email, then the contact's other open tickets),
// assets picked as it is scheduled (ITFlow's ticket_asset_id + ticket_assets, functions/mit_dispatch.php mitDispatchSaveDetails), and merging the contact's other open ticket (agent/js/mit_dispatch.js loadOtherTickets / bindMerge,
// functions/mit_dispatch.php mitDispatchMerge). The buttons say which ticket is kept; the form opens under the row picked and
// shows its problems there (a missing reason used to show at the foot of the tall dialog, out of sight); Enter in the reason
// merges. Both ways round: keep this one (the other closed, the list redrawn) and keep the other (this one closed, the window shut).
const { test, expect } = require('@playwright/test');
const { watch, visit } = require('./helpers');
const { sql, one, deleteTickets, makeTicket } = require('./t2db');

const tag = `ZZmrg${Date.now() % 1000000}`;

async function openDialog(page, ticketId) {
    await visit(page, 'agent/mit_dispatch.php');
    await page.locator('[data-md-filter="q"]').fill(tag);
    await page.locator('[data-md-filter="q"]').dispatchEvent('input');
    await page.locator('[data-md-tab="unassigned"]').click();
    const card = page.locator(`.mit-dq-item[data-ticket="${ticketId}"]`);
    await expect(card).toHaveCount(1, { timeout: 8000 });
    // The queue redraws as the filter and tab reload it - let it settle so the card isn't replaced mid-drag
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
    await expect(card).toHaveCount(1);
    const techId = Number(one(`SELECT user_id FROM users WHERE user_email = 'tech@test.test'`));
    await card.scrollIntoViewIfNeeded();
    const from = await card.boundingBox();
    await page.mouse.move(from.x + 20, from.y + 10);
    await page.mouse.down();
    await page.mouse.move(from.x + 40, from.y + 30, { steps: 5 });
    const slot = await page.evaluate((tech) => {
        const col = document.querySelector(`.mit-dispatch-col[data-tech="${tech}"]`);
        col.scrollIntoView();
        const r = [...col.querySelectorAll('[data-time="10:00:00"]')].map((el) => el.getBoundingClientRect()).sort((a, b) => b.width - a.width)[0];
        return { x: r.x + r.width / 2, y: r.y + Math.min(4, r.height / 2) };
    }, techId);
    await page.mouse.move(slot.x, slot.y, { steps: 15 });
    await page.mouse.up();
    await expect(page.locator('.swal2-popup')).toBeVisible();
}

test.describe.serial('dispatch schedule dialog', () => {
    let state;
    const made = [];
    test.beforeAll(() => {
        state = one(`SELECT value FROM mit_user_preferences WHERE user_id = 1 AND preference = 'dispatch.state'`);
        sql(`INSERT INTO mit_user_preferences SET user_id = 1, preference = 'dispatch.state', value = '{"view":"day","hidden":[],"weekends":true,"board":"all","board_techs":false}' ON DUPLICATE KEY UPDATE value = VALUES(value)`);
    });
    test.afterAll(() => {
        deleteTickets(made);
        if (state) {
            sql(`UPDATE mit_user_preferences SET value = '${state.replace(/'/g, "''")}' WHERE user_id = 1 AND preference = 'dispatch.state'`);
        }
    });

    test('keep this one: the other is closed, its problems shown by the form', async ({ page }) => {
        const errors = watch(page);
        const a = makeTicket({ subject: `${tag} A`, client: 1, assigned: 0, contact: 3 });
        const b = makeTicket({ subject: `${tag} B`, client: 1, assigned: 1, contact: 3 });
        made.push(a, b);
        const bRef = one(`SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = ${b}`);
        await openDialog(page, a);
        const row = page.locator(`[data-md-other="${b}"]`);
        await expect(row).toHaveCount(1, { timeout: 8000 });
        await expect(row.locator('[data-md-merge="here"]')).toHaveText(`Keep this one, close ${bRef}`);
        await expect(row.locator('[data-md-merge="there"]')).toHaveText(`Keep ${bRef}, close this one`);
        await row.locator('[data-md-merge="here"]').click();
        const form = row.locator('[data-md-merge-form]');
        await expect(form).toBeVisible();
        await expect(form.locator('[data-md-merge-what]')).toContainText(`${bRef} is closed`);
        // No reason: said beside the button, nothing merged
        await form.locator('[data-md-merge-go]').click();
        await expect(form.locator('[data-md-merge-error]')).toBeVisible();
        await expect(form.locator('[data-md-merge-error]')).toContainText('reason');
        expect(one(`SELECT ticket_closed_at FROM tickets WHERE ticket_id = ${b}`)).toBeNull();
        // Enter in the reason merges (and does not schedule this one)
        await form.locator('[data-md-merge-reason]').fill('same printer');
        await form.locator('[data-md-merge-reason]').press('Enter');
        await expect(row).toHaveCount(0, { timeout: 8000 });
        await expect(page.locator('.swal2-popup')).toBeVisible();
        expect(one(`SELECT ticket_closed_at FROM tickets WHERE ticket_id = ${b}`)).not.toBeNull();
        expect(one(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${a}`)).toBeNull();
        expect(one(`SELECT ticket_closed_at FROM tickets WHERE ticket_id = ${a}`)).toBeNull();
        expect(errors).toEqual([]);
    });

    test('keep the other: this one is closed and the window shuts', async ({ page }) => {
        const errors = watch(page);
        const a = makeTicket({ subject: `${tag} C`, client: 1, assigned: 0, contact: 3 });
        const b = makeTicket({ subject: `${tag} D`, client: 1, assigned: 1, contact: 3 });
        made.push(a, b);
        sql(`UPDATE tickets SET ticket_status = 5, ticket_schedule = CONCAT(CURDATE(), ' 14:00:00'), ticket_schedule_end = CONCAT(CURDATE(), ' 15:00:00') WHERE ticket_id = ${b}`);
        await openDialog(page, a);
        const row = page.locator(`[data-md-other="${b}"]`);
        await expect(row).toHaveCount(1, { timeout: 8000 });
        await row.locator('[data-md-merge="there"]').click();
        await expect(row.locator('[data-md-merge-what]')).toContainText('this window closes');
        await row.locator('[data-md-merge-reason]').fill('same issue');
        await row.locator('[data-md-merge-go]').click();
        await expect(page.locator('.swal2-popup')).toHaveCount(0, { timeout: 8000 });
        expect(one(`SELECT ticket_closed_at FROM tickets WHERE ticket_id = ${a}`)).not.toBeNull();
        expect(one(`SELECT ticket_closed_at FROM tickets WHERE ticket_id = ${b}`)).toBeNull();
        expect(one(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${b}`)).not.toBeNull();
        expect(errors).toEqual([]);
    });

    test('order of the dialog, and assets picked as it is scheduled', async ({ page }, info) => {
        const errors = watch(page);
        const a = makeTicket({ subject: `${tag} E`, client: 1, assigned: 0, contact: 3 });
        const b = makeTicket({ subject: `${tag} F`, client: 1, assigned: 1, contact: 3 });
        made.push(a, b);
        sql(`UPDATE tickets SET ticket_asset_id = 0 WHERE ticket_id = ${a}`);
        await openDialog(page, a);
        await expect(page.locator(`[data-md-other="${b}"]`)).toHaveCount(1, { timeout: 8000 });
        // Booking first, then details, then email, the other tickets last
        const order = await page.evaluate(() => {
            const at = (el) => (el ? el.getBoundingClientRect().top + document.querySelector('.swal2-html-container').scrollTop : -1);
            return ['mdTech', 'mdClient', 'mdAsset', 'mdOtherAssets', 'mdSla', 'mdNotify', 'mdOther'].map((id) => at(document.getElementById(id)));
        });
        for (let i = 1; i < order.length; i++) {
            expect(order[i], `field ${i} below the one before`).toBeGreaterThanOrEqual(order[i - 1]);
        }
        // One size that fits the window: the fields scroll inside it, the buttons stay on screen
        await page.setViewportSize({ width: 1280, height: 720 });
        await page.waitForTimeout(400);
        const fit = await page.evaluate(() => {
            const popup = document.querySelector('.swal2-popup').getBoundingClientRect();
            const body = document.querySelector('.swal2-html-container');
            const buttons = document.querySelector('.swal2-actions').getBoundingClientRect();
            return { top: popup.top, bottom: popup.bottom, scrolls: body.scrollHeight > body.clientHeight + 10, buttons: buttons.bottom <= window.innerHeight };
        });
        expect(fit.top).toBeGreaterThanOrEqual(0);
        expect(fit.bottom).toBeLessThanOrEqual(720);
        expect(fit.scrolls).toBe(true);
        expect(fit.buttons).toBe(true);
        await page.screenshot({ path: info.outputPath('dialog.png') });
        // The main asset, and two others through ITFlow's picker
        await page.locator('#mdAsset').selectOption('8');
        await expect(page.locator('#mdOtherAssets option[value="8"]')).toHaveCount(0);
        // ITFlow's own picker, clicked inside the dialog (its panel must sit above the dialog)
        await page.locator('#mdOtherAssets').locator('xpath=..').click();
        await page.locator('.itflow-select-option', { hasText: 'ACME-PC2' }).click();
        await page.locator('.itflow-select-option', { hasText: 'Front Desk - Printer' }).click();
        await page.keyboard.press('Escape');
        await expect(page.locator('.swal2-popup')).toBeVisible();
        await page.locator('.swal2-confirm').click();
        await expect(page.locator('.swal2-popup')).toHaveCount(0, { timeout: 8000 });
        expect(one(`SELECT ticket_asset_id FROM tickets WHERE ticket_id = ${a}`)).toBe('8');
        expect(one(`SELECT GROUP_CONCAT(asset_id ORDER BY asset_id) FROM ticket_assets WHERE ticket_id = ${a}`)).toBe('9,10');
        expect(one(`SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = ${a} AND ticket_history_description LIKE '%set the asset to ACME-PC1%'`)).toBe('1');
        expect(one(`SELECT ticket_schedule FROM tickets WHERE ticket_id = ${a}`)).not.toBeNull();
        expect(errors).toEqual([]);
    });
});
