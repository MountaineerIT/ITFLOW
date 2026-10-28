// Our settings on ITFlow's Admin > Settings (functions/mit_settings_index.php): Procurement, Timesheets and Boards are tiles in "Work" with
// how they are set now, and entries in the Settings menu (once each); Find a setting finds our pages and the settings on our cards inside
// ITFlow's pages, and a result opens the page at the field. Admin > Settings > Notifications shows our switches as sections of ITFlow's page.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');

test('Settings overview: our pages as tiles and in the menu, found by Find a setting', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings.php', errors);
    const work = page.locator('[data-settings-group="work"]');
    for (const [key, title] of [['mit_timesheets', 'Timesheets'], ['mit_boards', 'Boards'], ['mit_procurement', 'Procurement']]) {
        const tile = work.locator(`[data-settings-tile="${key}"]`);
        if (!(await tile.count())) {
            continue; // its module is off here
        }
        await expect(tile, `${title} is a tile`).toContainText(title);
        await expect(tile.locator('[data-settings-now]'), `with how it is set now`).not.toBeEmpty();
    }
    await expect(work.locator('[data-settings-tile="mit_timesheets"] [data-settings-now]')).toContainText('periods');
    // The Settings menu lists each once
    await expect(page.locator('.app-sidebar a[href="/admin/mit_timesheets.php"]'), 'Timesheets once in the menu').toHaveCount(1);
    await expect(page.locator('.app-sidebar a[href="/admin/mit_boards.php"]')).toHaveCount(1);

    // Find a setting: a page of ours, and a setting on our card in ITFlow's Quotes page
    const find = page.locator('[data-settings-find-input]');
    await find.fill('round-robin');
    await expect(page.locator('[data-settings-results]'), 'our Boards page is found').toContainText('Boards');
    await find.fill('price from cost');
    const result = page.locator('[data-settings-results] a', { hasText: 'Price from cost' }).first();
    await expect(result, 'a setting on our Quote pricing card').toHaveAttribute('href', /settings_quote\.php#setting=mit_quote_price_method/);
    await Promise.all([page.waitForURL(/settings_quote\.php/), result.click()]);
    await expect(page.locator('[name="mit_quote_price_method"]').first(), 'it opens the page at the field').toBeVisible();
    await expectClean(page, errors);
});

test('Notifications: our switches are sections of ITFlow\'s page', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings_notification.php', errors);
    await expect(page.locator('[data-mit-notifications]'), 'no separate table any more').toHaveCount(0);
    const scheduling = page.locator('section#section-mit-scheduling');
    await expect(scheduling, 'scheduling defaults are a section').toBeVisible();
    await expect(scheduling.locator('[data-mit-schedule-defaults]')).toHaveCount(1);
    const assigning = page.locator('section#section-mit-assigning');
    await expect(assigning, 'Assigning is a section').toBeVisible();
    await expect(assigning.locator('input[data-mit-notify="assign_app"]'), 'with its bell switch').toHaveAttribute('role', 'switch');
    await expect(assigning.locator('label[for="mitNotify_assign_email"]')).toHaveText('By email');
    // Touching one shows ITFlow's save bar, as its own fields do
    await assigning.locator('label[for="mitNotify_assign_email"]').click();
    await expect(page.locator('[data-settings-dirty-note]'), 'ITFlow\'s save bar notices').toBeVisible();
    await assigning.locator('label[for="mitNotify_assign_email"]').click();
    await expectClean(page, errors);
});
