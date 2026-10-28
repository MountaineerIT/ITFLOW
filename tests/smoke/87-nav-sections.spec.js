// The side menu's sections fold (js/mit_nav_sections.js): clicking BILLING hides its items and the browser remembers it on the next
// page; the section holding the page you are on is shown anyway; clicking again opens it. The admin menu folds on its own. The
// browser's memory of it is cleared afterwards.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');

test('side menu sections fold, are remembered, and the current one stays open', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/dashboard.php', errors);
    const billing = page.locator('.app-sidebar li.nav-header[data-mit-nav-section="BILLING"]');
    test.skip(!(await billing.count()), 'no Billing section for this login');
    const quotes = page.locator('.app-sidebar a[href="/agent/quotes.php"]');
    await expect(billing).toHaveAttribute('aria-expanded', 'true');
    await expect(quotes).toBeVisible();

    await billing.click();
    await expect(billing, 'folded').toHaveAttribute('aria-expanded', 'false');
    await expect(quotes, 'its items are hidden').toBeHidden();
    await expect(page.locator('.app-sidebar a[href="/agent/tickets.php"]'), 'other sections stay as they were').toBeVisible();

    // The next page: remembered - and never drawn open first (checked on every frame while the page is built)
    await page.addInitScript(() => {
        window.__mitNavFlash = 0;
        const look = () => {
            const link = document.querySelector('.app-sidebar a[href="/agent/quotes.php"]');
            if (link && link.offsetParent !== null) {
                window.__mitNavFlash++;
            }
            if (document.readyState !== 'complete') {
                requestAnimationFrame(look);
            }
        };
        requestAnimationFrame(look);
    });
    await visit(page, 'agent/dashboard.php', errors);
    await expect(quotes, 'remembered on the next page').toBeHidden();
    expect(await page.evaluate(() => window.__mitNavFlash), 'a folded section never shows for a moment').toBe(0);

    await visit(page, 'agent/invoices.php', errors);
    await expect(page.locator('.app-sidebar a[href="/agent/invoices.php"]'), 'the section of the page you are on shows anyway').toBeVisible();

    await visit(page, 'admin/users.php', errors);
    const access = page.locator('.app-sidebar li.nav-header[data-mit-nav-section="ACCESS"]');
    await expect(access, 'the admin menu folds too').toHaveAttribute('role', 'button');
    await expect(access, 'and does not take the agent menu\'s folds').toHaveAttribute('aria-expanded', 'true');

    await visit(page, 'agent/dashboard.php', errors);
    await billing.press('Enter');
    await expect(billing, 'opened again from the keyboard').toHaveAttribute('aria-expanded', 'true');
    await expect(quotes).toBeVisible();
    await page.evaluate(() => window.localStorage.removeItem('mit.nav.folded'));
    await expectClean(page, errors);
});
