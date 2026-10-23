// The company logo in the side menu (functions/mit_theme.php mitBrandLogoOnly): Admin > Settings > Company Details has
// "The logo shows the company name" - on, the side menu shows the logo on its own; off, the logo with the name beside it.
// Uploads a small logo (a company that already has one keeps it - then only the switch is checked) and puts things back.

const { test, expect } = require('@playwright/test');
const { watch, visit, submit, flash, expectClean } = require('./helpers');

// A 120 x 30 PNG
const LOGO = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAHgAAAAeCAIAAABoq03CAAAAQklEQVR4nO3PMQ0AIAzAMPybhkPQeGg24QNcWm9MVjzZ1dWVdwOAEAgEAoFAIBAIBAKBQCAQCAQCgUAgEAgEAoFAIBAIBAKBQCAQCAQCgUAg8LnxBgM2SPwAWl0hAAAAAElFTkSuQmCC', 'base64');

test('company logo: on its own in the side menu when it shows the company name', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings_company.php', errors);
    const field = page.locator('[data-mit-brand-logo-only] input[type=checkbox]');
    await expect(field, 'the switch under the logo').toHaveCount(1);
    const was = await field.isChecked();
    const hadLogo = await page.locator('a[href*="remove_company_logo"]').count() > 0;
    const save = async (on, upload) => {
        await visit(page, 'admin/settings_company.php');
        const form = page.locator('form:has(button[name=edit_company])');
        if (upload) {
            await form.locator('input[name=file]').setInputFiles({ name: 'smoke-logo.png', mimeType: 'image/png', buffer: LOGO });
        }
        await form.locator('[data-mit-brand-logo-only] input[type=checkbox]').setChecked(on);
        await submit(form, 'edit_company');
        await flash(page);
    };
    try {
        await save(true, !hadLogo);
        await visit(page, 'agent/dashboard.php', errors);
        await expect(page.locator('.itflow-brand-mark-logo[data-mit-logo-only]'), 'the logo on its own').toHaveCount(1);
        await expect(page.locator('.itflow-brand-name'), 'no name beside it').toHaveCount(0);

        await save(false, false);
        await visit(page, 'agent/dashboard.php', errors);
        await expect(page.locator('.itflow-brand-mark-logo[data-mit-logo-only]')).toHaveCount(0);
        await expect(page.locator('.itflow-brand-name'), 'the name beside the logo again').toHaveCount(1);
        await expectClean(page, errors);
    } finally {
        await save(was, false).catch(() => {});
        if (!hadLogo) {
            await visit(page, 'admin/settings_company.php');
            const remove = page.locator('a[href*="remove_company_logo"]');
            if (await remove.count()) {
                await page.goto('admin/' + (await remove.getAttribute('href')));
            }
        }
    }
});
