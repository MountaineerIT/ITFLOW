// An admin sets their own employment (pay type) in Account > Preferences - Admin > Users does not edit your own account
// (functions/mit_timesheets.php mitTsPreferenceFields / mitTsSavePreferences). Put back as it was at the end. A technician does
// not get the field (04-permissions.spec.js).

const { test, expect } = require('@playwright/test');
const { watch, visit, submit, flash, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

let original = null;

async function setOwn(page, value) {
    await visit(page, 'agent/user/user_preferences.php');
    const form = page.locator('form:has(button[name=edit_your_user_preferences])');
    await form.locator('select[name=mit_pay_type]').selectOption(value);
    await submit(form, 'edit_your_user_preferences');
    return flash(page);
}

test('an admin changes their own employment in Account > Preferences', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/user/user_preferences.php', errors);
    const field = page.locator('select[name=mit_pay_type]');
    test.skip(await page.locator('input[name=mit_ts_prefs]').count() === 0, 'timesheets are switched off');
    await expect(field, 'the Employment field for an admin').toHaveCount(1);
    original = await field.inputValue();
    const other = original === 'contractor' ? 'salary' : 'contractor';
    await setOwn(page, other);
    await visit(page, 'agent/user/user_preferences.php', errors);
    await expect(field, 'saved').toHaveValue(other);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    if (original === null) {
        return;
    }
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    await setOwn(await context.newPage(), original).catch(() => {});
    await context.close();
});
