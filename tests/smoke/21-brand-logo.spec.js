// The company logo in the side menu (functions/mit_theme.php mitBrandLogoOnly): Admin > Settings > Company Details has
// "The logo shows the company name" - on, the side menu shows the logo on its own; off, the logo with the name beside it.
// "No light tile behind the logo" (mitBrandLogoPlain) takes the white tile away.
// A second logo for ITFlow's screens (mitBrandAppLogo) shows in the side menu and on the sign-in page, the company logo staying
// for quotes and invoices; removing it brings the company logo back.
// Uploads a small logo (a company that already has one keeps it - then only the switch is checked) and puts things back.

const { test, expect } = require('@playwright/test');
const { watch, visit, submit, flash, expectClean } = require('./helpers');

// A 120 x 30 PNG
const LOGO = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAHgAAAAeCAIAAABoq03CAAAAQklEQVR4nO3PMQ0AIAzAMPybhkPQeGg24QNcWm9MVjzZ1dWVdwOAEAgEAoFAIBAIBAKBQCAQCAQCgUAgEAgEAoFAIBAIBAKBQCAQCAQCgUAg8LnxBgM2SPwAWl0hAAAAAElFTkSuQmCC', 'base64');

test('company logo: on its own in the side menu when it shows the company name, with or without its tile', async ({ page }) => {
    test.setTimeout(90000);
    const errors = watch(page);
    await visit(page, 'admin/settings_company.php', errors);
    const field = page.locator('[data-mit-brand-logo-only] input[type=checkbox]');
    await expect(field, 'the switch under the logo').toHaveCount(1);
    const was = await field.isChecked();
    const plainField = page.locator('[data-mit-brand-logo-plain] input[type=checkbox]');
    await expect(plainField, 'the no-tile switch').toHaveCount(1);
    const wasPlain = await plainField.isChecked();
    const hadLogo = await page.locator('a[href*="remove_company_logo"]').count() > 0;
    const save = async (on, upload, plain = false) => {
        await visit(page, 'admin/settings_company.php');
        const form = page.locator('form:has(button[name=edit_company])');
        if (upload) {
            await form.locator('input[name=file]').setInputFiles({ name: 'smoke-logo.png', mimeType: 'image/png', buffer: LOGO });
        }
        await form.locator('[data-mit-brand-logo-only] input[type=checkbox]').setChecked(on);
        await form.locator('[data-mit-brand-logo-plain] input[type=checkbox]').setChecked(plain);
        await submit(form, 'edit_company');
        await flash(page);
    };
    try {
        await save(true, !hadLogo);
        await visit(page, 'agent/dashboard.php', errors);
        await expect(page.locator('.itflow-brand-mark-logo[data-mit-logo-only]'), 'the logo on its own').toHaveCount(1);
        await expect(page.locator('.itflow-brand-name'), 'no name beside it').toHaveCount(0);
        const tile = page.locator('.itflow-brand-mark-logo');
        expect(await tile.evaluate((el) => getComputedStyle(el).backgroundColor), 'the light tile').toBe('rgb(255, 255, 255)');

        await save(true, false, true);
        await visit(page, 'agent/dashboard.php', errors);
        await expect(page.locator('.itflow-brand-mark-logo[data-mit-logo-plain]')).toHaveCount(1);
        expect(await tile.evaluate((el) => getComputedStyle(el).backgroundColor), 'no tile').toBe('rgba(0, 0, 0, 0)');

        // The logo for ITFlow's screens: the side menu and the sign-in page show it, Company Details keeps the company logo
        await visit(page, 'admin/settings_company.php');
        const companyLogo = new URL(await page.locator('img.img-thumbnail').first().getAttribute('src'), page.url()).pathname.split('/').pop();
        let form = page.locator('form:has(button[name=edit_company])');
        await form.locator('input[name=mit_app_logo]').setInputFiles({ name: 'smoke-app-logo.png', mimeType: 'image/png', buffer: LOGO });
        await submit(form, 'edit_company');
        await flash(page);
        const appSrc = await page.locator('[data-mit-brand-app-logo] img').getAttribute('src');
        const appLogo = appSrc.split('/').pop();
        expect(appLogo, 'a new file').not.toBe(companyLogo);
        await visit(page, 'agent/dashboard.php', errors);
        expect((await page.locator('.itflow-brand-mark-logo img').getAttribute('src')).split('/').pop(), 'side menu').toBe(appLogo);
        const use = test.info().project.use;
        const context = await page.context().browser().newContext({ baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors, storageState: { cookies: [], origins: [] } });
        const signIn = await context.newPage();
        await signIn.goto('login.php');
        expect((await signIn.locator('.login-logo img').getAttribute('src')).split('/').pop(), 'sign-in page').toBe(appLogo);
        expect(await signIn.locator('.login-logo img').evaluate((el) => getComputedStyle(el).backgroundColor), 'no white plate (no-tile switch)').toBe('rgba(0, 0, 0, 0)');
        await context.close();
        await visit(page, 'admin/settings_company.php');
        expect(new URL(await page.locator('img.img-thumbnail').first().getAttribute('src'), page.url()).pathname.split('/').pop(), 'company logo kept').toBe(companyLogo);
        form = page.locator('form:has(button[name=edit_company])');
        await form.locator('input[name=mit_app_logo_remove]').check();
        await submit(form, 'edit_company');
        await flash(page);
        await visit(page, 'agent/dashboard.php', errors);
        expect((await page.locator('.itflow-brand-mark-logo img').getAttribute('src')).split('/').pop(), 'company logo again').toBe(companyLogo);

        await save(false, false);
        await visit(page, 'agent/dashboard.php', errors);
        await expect(page.locator('.itflow-brand-mark-logo[data-mit-logo-only]')).toHaveCount(0);
        await expect(page.locator('.itflow-brand-name'), 'the name beside the logo again').toHaveCount(1);
        await expectClean(page, errors);
    } finally {
        await save(was, false, wasPlain).catch(() => {});
        if (!hadLogo) {
            await visit(page, 'admin/settings_company.php');
            const remove = page.locator('a[href*="remove_company_logo"]');
            if (await remove.count()) {
                await page.goto('admin/' + (await remove.getAttribute('href')));
            }
        }
    }
});
