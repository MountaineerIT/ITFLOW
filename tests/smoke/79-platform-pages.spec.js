// Our pieces on ITFlow's shared pages (one-line hooks): the admin menus list our settings and integration pages, Billing lists our
// pages, Admin > Update and the admin footer show our build's version (with ITFlow's under it) and our release notes, Admin > Roles
// explains our modules' levels, and the calendar loads its script with a version in the address (so browsers fetch our changes).

const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, expectClean } = require('./helpers');

const read = (file) => fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');
const MIT_VERSION = read('includes/mit_version.php').match(/'MIT_VERSION',\s*'([^']+)'/)[1];
const APP_VERSION = read('includes/app_version.php').match(/APP_VERSION["'],\s*["']([^"']+)/i)[1];

test('admin menus: our settings and integration pages', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings_company.php', errors);
    for (const file of ['mit_boards.php', 'mit_timesheets.php', 'mit_ninjaone.php', 'mit_qbo.php', 'mit_align.php', 'mit_ai_helper.php']) {
        await expect(page.locator(`.app-sidebar a[href="/admin/${file}"]`), `admin menu: ${file}`).toHaveCount(1);
    }
    await expectClean(page, errors);
});

test('Billing menu: Unbilled Parts', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/invoices.php', errors);
    await expect(page.locator('.app-sidebar a[href="/agent/mit_unbilled_parts.php"]'), 'Billing > Unbilled Parts').toHaveCount(1);
});

test('Admin > Update and the admin footer: our version, ITFlow\'s under it, our release notes', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/update.php', errors);
    const release = page.locator('.col-md-3:has(small:text-is("Release"))');
    await expect(release.locator('.h5'), 'our build\'s version').toHaveText(MIT_VERSION);
    await expect(release, 'ITFlow\'s version under it').toContainText(`ITFlow ${APP_VERSION}`);
    await expect(page.locator(`#mit-release-${MIT_VERSION.replace(/\./g, '\\.')}`), 'our release notes, this version first').toHaveCount(1);
    await expect(page.locator('footer.app-footer'), 'the admin footer').toContainText(`ITFlow ${MIT_VERSION}`);
    await expectClean(page, errors);
});

test('Admin > Roles: our modules say what each level allows', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/roles.php', errors);
    const modal = await openModal(page, 'modals/role/role_add.php', 'lg');
    for (const text of ['Approve and cancel purchase orders', 'See everyone\'s and the payroll report', 'Book, move, assign and merge tickets']) {
        await expect(modal, `level text: ${text}`).toContainText(text);
    }
});

test('the calendar loads its script with a version in the address', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/calendar.php', errors);
    const src = await page.locator('script[src*="/agent/js/calendar.js"]').getAttribute('src');
    expect(src, 'calendar.js?v=<file time>').toMatch(/calendar\.js\?v=\d+/);
});
