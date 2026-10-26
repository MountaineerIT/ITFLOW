// Rest breaks (26.10.151, functions/mit_timesheets.php mitTsDaysRead, mitTsRestMissedSave): short paid rest breaks are part of the hours
// people log - nothing is added on top (no "Paid breaks" chip or hours on open days, no setting to add them) - and every day asks
// "I could not take a rest break this day", not only California days. Uses the admin's own timesheet (not on California rules).

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');

test('Admin > Timesheets: rest breaks are inside the logged hours (no minutes to add)', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/mit_timesheets.php', errors);
    await expect(page.locator('[data-mit-rest-breaks]')).toContainText('nothing is added on top');
    await expect(page.locator('input[name="break_minutes"]')).toHaveCount(0);
    await expectClean(page, errors);
});

test('a day not on California rules: no breaks added, and "I could not take a rest break" is kept', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/mit_timesheet.php', errors);
    const data = JSON.parse(await page.locator('#mitTsData').textContent());
    test.skip(data.ca, 'the admin is on California rules - those days ask it under Work times');
    test.skip(data.locked !== '', 'this period is locked: ' + data.locked);
    const open = Object.keys(data.days).filter((d) => !data.days[d].future);
    test.skip(!open.length, 'no past day in this period yet');
    for (const d of open) {
        expect(data.days[d].break_paid, `${d}: nothing added on top of the logged hours`).toBe(0);
    }
    const date = open[open.length - 1];
    await page.click(`#mitTsWeeks [data-week="${data.weeks.findIndex((w) => w.includes(date))}"]`);
    await page.click(`.mit-ts-day[data-date="${date}"]`);
    const panel = page.locator('#mitTsDay');
    const box = panel.locator('#mitTsRestAny');
    await expect(box).toBeVisible();
    const was = await box.isChecked();
    try {
        await box.setChecked(!was);
        await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
        if (!was) {
            await expect(page.locator(`.mit-ts-day[data-date="${date}"]`)).toContainText('Rest break missed');
        }
        await visit(page, 'agent/mit_timesheet.php', errors);
        const again = JSON.parse(await page.locator('#mitTsData').textContent());
        expect(again.days[date].rest_missed, 'kept after a reload').toBe(was ? 0 : 1);
    } finally {
        await page.click(`#mitTsWeeks [data-week="${data.weeks.findIndex((w) => w.includes(date))}"]`);
        await page.click(`.mit-ts-day[data-date="${date}"]`);
        if (await panel.locator('#mitTsRestAny').isChecked() !== was) {
            await panel.locator('#mitTsRestAny').setChecked(was);
            await expect(panel.locator('[data-mit-day-status]')).toContainText('Saved', { timeout: 10000 });
        }
    }
    await expectClean(page, errors);
});
