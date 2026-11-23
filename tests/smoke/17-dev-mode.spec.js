// Development mode is ITFlow's own since 26.12 (functions/dev_mode.php - its card on Maintenance > Backup, $config_dev_mode, the
// DEVELOPMENT badge - built from ours). Switched on there (a test inbox required), ours on top (functions/mit_dev_mode.php): our
// integrations only read: NinjaOne's organizations can still be read (sign-in token + GET pass mitDevAllows); writes are refused
// before they leave (checked by hand - there is no button here that writes to another system on the test install).
// QuickBooks Online works only with a sandbox company connected while development mode is on (production's is refused).
// Always switched off again at the end, even when a step fails - the other tests need the normal behaviour.

const { test, expect } = require('@playwright/test');
const { watch, visit, fillForm, submit, flash, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

async function setDevMode(page, on, mailTo = 'dev-inbox@mountaineerit.test') {
    await visit(page, 'admin/backup.php');
    const form = page.locator('form:has(button[name=edit_dev_mode])');
    await fillForm(form, { dev_mode: on, dev_mail_to: on ? mailTo : '' });
    await submit(form, 'edit_dev_mode');
    return flash(page);
}

test('development mode: ITFlow\'s switch, and NinjaOne still reads while it is on', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/backup.php', errors);
    test.skip(await page.locator('button[name=edit_dev_mode]').count() === 0, 'config.php can\'t be written here');

    expect(await setDevMode(page, true, ''), 'a test inbox is required').toContain('Give a test inbox');
    expect(await setDevMode(page, true), 'switched on').toContain('Development mode is on');
    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('[data-dev-mode]'), 'the badge in the top bar').toBeVisible();
    await expect(page.locator('[data-dev-mode]')).toHaveAttribute('title', /dev-inbox@mountaineerit\.test/);

    // Reads still work - only when NinjaOne is set up (the test install's stand-in)
    await visit(page, 'admin/mit_ninjaone.php?tab=mapping', errors);
    const read = page.locator('form:has(button[name=mit_ninja_refresh_orgs])');
    if (await read.count()) {
        await submit(read, 'mit_ninja_refresh_orgs');
        expect(await flash(page), 'NinjaOne still reads in development mode').toMatch(/organizations? read from NinjaOne/);
    }

    expect(await setDevMode(page, false), 'switched off').toContain('Development mode is off');
    await visit(page, 'agent/dashboard.php', errors);
    await expect(page.locator('[data-dev-mode]'), 'no badge').toHaveCount(0);
    await expectClean(page, errors);
});

// QuickBooks Online in development mode: a production company is refused, a sandbox company connected here works
// (against the test install's stand-in - skipped without it)
async function qboDisconnect(page) {
    await visit(page, 'admin/mit_qbo.php');
    if (await page.locator('button[name=mit_qbo_disconnect]').count()) {
        await page.locator('button[name=mit_qbo_disconnect]').evaluate((b) => b.form.requestSubmit(b));
        await page.waitForLoadState('load');
    }
}

test('development mode: QuickBooks Online only with a sandbox company connected here', async ({ page }) => {
    const errors = watch(page);
    const stand_in = await page.request.get('http://127.0.0.1/qbo-mock/index.php/_reset').then((r) => r.ok()).catch(() => false);
    test.skip(!stand_in, 'the QuickBooks stand-in is not installed on this test install');
    await visit(page, 'admin/mit_qbo.php', errors);
    await expect(page.locator('input[name=qbo_client_id]'), 'the QuickBooks settings form').toHaveCount(1);

    await qboDisconnect(page);
    expect(await setDevMode(page, true), 'switched on').toContain('Development mode is on');
    const settings = async (env) => {
        await visit(page, 'admin/mit_qbo.php', errors);
        const form = page.locator('form:has(button[name=mit_qbo_save_settings])');
        await fillForm(form, { qbo_client_id: 'qbo-client', qbo_client_secret: 'qbo-secret', qbo_env: env });
        await submit(form, 'mit_qbo_save_settings');
    };
    await settings('production');
    await expect(page.locator('[data-mit-qbo-dev]'), 'the development mode notice').toBeVisible();
    await submit(page.locator('form:has(button[name=mit_qbo_connect])'), 'mit_qbo_connect');
    expect(await flash(page), 'a production company is refused').toContain('only with an Intuit sandbox company');

    await settings('sandbox');
    await Promise.all([page.waitForURL(/mit_qbo\.php/), page.locator('button[name=mit_qbo_connect]').click()]);
    expect(await flash(page), 'a sandbox company connects').toContain('QuickBooks Online connected');
    await visit(page, 'admin/mit_qbo.php', errors);
    await submit(page.locator('form:has(button[name=mit_qbo_test])'), 'mit_qbo_test');
    expect(await flash(page), 'and answers through the read-only gate').toContain('QuickBooks Online answers');
    await qboDisconnect(page);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    await qboDisconnect(page).catch(() => {});
    await setDevMode(page, false).catch(() => {});
    await context.close();
});
