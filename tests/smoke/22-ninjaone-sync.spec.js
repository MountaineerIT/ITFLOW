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

// Preview plans without writing; the safety brake keeps the report - each asset it would have made, and why it matched none
// (here two stand-in devices with no asset, one named like an existing asset). The limit is set to 1 for the run and put back.
async function brake(page, value) {
    await visit(page, 'admin/mit_ninjaone.php?tab=settings');
    const form = page.locator('form:has(button[name=mit_ninja_save_settings])');
    const input = form.locator('input[name=ninja_brake]');
    const was = await input.inputValue();
    await input.fill(String(value));
    await submit(form, 'mit_ninja_save_settings');
    return was;
}

test('ninjaone: Preview writes nothing, and the safety brake reports what it would have made and why', async ({ page }) => {
    test.setTimeout(120000);
    const errors = watch(page);
    test.skip(!(await mock(page, '/_calls')), 'the NinjaOne stand-in is not installed on this test install');
    await visit(page, 'admin/mit_ninjaone.php?tab=runs', errors);
    test.skip(!(await page.locator('button[name=mit_ninja_preview]').count()), 'NinjaOne is not set up on this test install');
    let was = null;
    try {
        await mock(page, '/_extra?on=1');
        const preview = async () => {
            await visit(page, 'admin/mit_ninjaone.php?tab=runs');
            await submit(page.locator('form:has(button[name=mit_ninja_preview])'), 'mit_ninja_preview');
        };
        await preview();
        expect(await flash(page)).toMatch(/Preview done - nothing was written: 2 would be new/);
        await expect(page.locator('[data-mit-ninja-plan]')).toContainText('would make 2 new assets');
        await expect(page.locator('[data-mit-ninja-orgs]')).toContainText('Acme Ninja');
        await expect(page.locator('li', { hasText: 'ACME-NEW' })).toContainText('serial SER-909');
        await expect(page.locator('li', { hasText: 'device 108' }), 'the same-named asset is shown with what it carries').toContainText(/Same name: .*Front Desk #\d+ has serial SER-303 and NinjaOne Device ID 103 \(another device\)/);

        // Over the limit: nothing written, the report kept
        was = await brake(page, 1);
        await visit(page, 'admin/mit_ninjaone.php?tab=runs');
        await submit(page.locator('form:has(button[name=mit_ninja_run])'), 'mit_ninja_run');
        expect(await flash(page)).toMatch(/Safety brake: nothing was written - the report lists the 2 assets/);
        await expect(page.locator('[data-mit-ninja-plan]')).toContainText('Stopped by the safety brake');
        await expect(page.locator('li', { hasText: 'ACME-NEW' })).toHaveCount(1);

        // Still nothing made: a second preview finds the same two
        await preview();
        expect(await flash(page)).toMatch(/2 would be new/);
        await expectClean(page, errors);
    } finally {
        await mock(page, '/_extra?on=0');
        if (was !== null) {
            await brake(page, was).catch(() => {});
        }
    }
});

test('ninjaone: an asset linked to a device has its "Open in NinjaOne" button on its page and in its window', async ({ page }) => {
    const db = require('./mitdb');
    const row = db.one(`SELECT d.asset_id, a.asset_client_id FROM mit_ninja_devices d JOIN assets a ON a.asset_id = d.asset_id
        WHERE a.asset_archived_at IS NULL ORDER BY d.asset_id LIMIT 1`);
    test.skip(!row, 'no asset linked to a NinjaOne device here (run a sync first)');
    const errors = watch(page);
    await visit(page, `agent/asset.php?client_id=${row.asset_client_id}&asset_id=${row.asset_id}`, errors);
    await expect(page.locator(`a[data-mit-ninja-asset="${row.asset_id}"]`).first(), 'on the asset page').toHaveAttribute('title', /^Open in NinjaOne/);
    await page.evaluate((id) => {
        const b = document.createElement('button');
        b.className = 'ajax-modal d-none';
        b.dataset.modalUrl = `modals/asset/asset.php?id=${id}`;
        document.body.appendChild(b);
        b.click();
    }, row.asset_id);
    await expect(page.locator(`.modal.show a[data-mit-ninja-asset="${row.asset_id}"]`).first(), 'in the asset window').toHaveCount(1);
    await expectClean(page, errors);
});
