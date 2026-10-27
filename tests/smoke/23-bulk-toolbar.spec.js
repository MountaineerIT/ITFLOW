// The Tickets list toolbar while tickets are ticked (functions/mit_list_views.php mitListViewResetButton's style): ITFlow's
// Bulk Action button joins Reset / List / Kanban - each stays one line high, and none of them runs over the filters beside them
// (they used to squash into two-line buttons and cover the client filter). Checked at a wide and a narrower window.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');

const overlaps = (a, b) => a.x < b.x + b.width && b.x < a.x + a.width && a.y < b.y + b.height && b.y < a.y + a.height;

for (const width of [1600, 1100]) {
    test(`tickets: the toolbar stays tidy with tickets ticked (${width}px)`, async ({ page }) => {
        const errors = watch(page);
        await page.setViewportSize({ width, height: 900 });
        await visit(page, 'agent/tickets.php?status=All&view=list', errors);
        const boxes = page.locator('table.itflow-ticket-list tbody input[type=checkbox]');
        test.skip((await boxes.count()) < 2 || !(await page.locator('#bulkActionButton').count()), 'needs two tickets and ticket edit rights');
        await boxes.nth(0).check();
        await boxes.nth(1).check();
        const bulk = page.locator('#bulkActionButton');
        await expect(bulk).toBeVisible();
        const bar = bulk.locator('xpath=..');
        const buttons = bar.locator(':scope > .btn, :scope > .btn-group > .btn, :scope > .dropdown > .btn');
        const n = await buttons.count();
        expect(n, 'Bulk Action, Reset, List and Kanban').toBeGreaterThanOrEqual(3);
        const boxesOf = [];
        for (let i = 0; i < n; i++) {
            const box = await buttons.nth(i).boundingBox();
            expect(box.height, `button ${i + 1} stays one line high`).toBeLessThan(50);
            boxesOf.push(box);
        }
        // Nothing in the toolbar sits over a filter of the same form
        const column = bar.locator('xpath=..');
        const others = column.locator('xpath=preceding-sibling::*').locator('input:visible, select:visible, .form-select:visible, button:visible');
        for (let i = 0; i < (await others.count()); i++) {
            const other = await others.nth(i).boundingBox();
            if (!other) {
                continue;
            }
            for (const box of boxesOf) {
                expect(overlaps(box, other), 'a toolbar button covers a filter').toBe(false);
            }
        }
        await expectClean(page, errors);
    });
}
