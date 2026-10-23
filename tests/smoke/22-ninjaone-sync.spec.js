// NinjaOne "Run sync now" (functions/mit_ninjaone.php, admin/post/mit_ninjaone.php) against the test install's stand-in (ninja-mock -
// never a real account): while a slow sync runs, the person's other pages still open (the session is let go during the run); an
// organization not mapped yet is a note on the report, not "Needs attention"; and warranties are looked up only for devices of
// mapped organizations. The stand-in's organization 3 is set to "not mapped" for the run and put back afterwards.

const { test, expect } = require('@playwright/test');
const { watch, visit, submit, flash, expectClean } = require('./helpers');

const MOCK = 'http://127.0.0.1/ninja-mock/index.php';
const mock = (page, path) => page.request.get(MOCK + path).then((r) => (r.ok() ? r.json() : null)).catch(() => null);

async function mapping(page, org, value) {
    await visit(page, 'admin/mit_ninjaone.php?tab=mapping');
    const form = page.locator('form:has(button[name=mit_ninja_save_mapping])');
    const select = form.locator(`select[name="mapping[${org}]"]`);
    if (!(await select.count())) {
        return null;
    }
    const was = await select.inputValue();
    await select.selectOption(value);
    await submit(form, 'mit_ninja_save_mapping');
    return was;
}

test('ninjaone: a sync leaves the rest of ITFlow usable, unmapped organizations are a note, warranties only for mapped ones', async ({ browser, page }) => {
    test.setTimeout(120000);
    const errors = watch(page);
    test.skip(!(await mock(page, '/_calls')), 'the NinjaOne stand-in is not installed on this test install');
    await visit(page, 'admin/mit_ninjaone.php?tab=runs', errors);
    test.skip(!(await page.locator('button[name=mit_ninja_run]').count()), 'NinjaOne is not set up on this test install');
    const was = await mapping(page, 3, '');
    test.skip(was === null, 'the stand-in organization 3 is not in the mapping');
    try {
        await mock(page, '/_clear');
        await mock(page, '/_slow?seconds=8');
        // Run sync now in one tab; the same person opens another page while it runs
        await visit(page, 'admin/mit_ninjaone.php?tab=runs');
        const running = Promise.all([page.waitForNavigation({ timeout: 90000 }), page.click('button[name=mit_ninja_run]')]);
        await page.waitForTimeout(1500);
        const other = await page.context().newPage();
        const started = Date.now();
        await other.goto('agent/dashboard.php');
        const waited = Date.now() - started;
        await other.close();
        expect(waited, 'another page opened while the sync ran').toBeLessThan(5000);
        await running;
        expect(await flash(page)).toMatch(/Sync done/);

        // The report: the unmapped organization is a note, not an error
        await expect(page.locator('[data-mit-ninja-note]'), 'a note about the organization not mapped yet').toContainText('not mapped yet');
        await expect(page.locator('.alert-danger', { hasText: 'not mapped' }), 'not an error').toHaveCount(0);

        // No warranty lookup for the unmapped organization's device (107)
        const calls = (await mock(page, '/_calls')).calls;
        expect(calls.some((c) => / \/v2\/devices-detailed/.test(c)), 'the sync asked for the devices').toBe(true);
        expect(calls.filter((c) => /\/v2\/device\/107\b/.test(c)), 'no warranty lookup for a device not synced').toEqual([]);
        await expectClean(page, errors);
    } finally {
        await mock(page, '/_slow?seconds=0');
        await mapping(page, 3, was || 'ignore').catch(() => {});
    }
});
