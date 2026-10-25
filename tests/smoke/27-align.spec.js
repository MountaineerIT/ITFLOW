// MSP Align, read only (functions/mit_align.php), against the test install's stand-in (align-mock - never a real MSP Align):
// connect with a key, Sync now, and what MSP Align knows shows in ITFlow - the client overview's health / alignment / devices /
// next meeting with its quick links, the client header link, the asset list's lifecycle badge, the asset page's MSP Align button,
// the license list's renewal line. Only GET requests reach MSP Align; a key without health:read just leaves the score out; a
// device MSP Align no longer has loses its badge. Makes its own client, asset and license, and switches the integration off again.
// Skipped when the stand-in is not installed (see README).

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean, csrf } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const MOCK = 'http://127.0.0.1/align-mock/index.php';
const made = { clientName: smokeName('Align client'), clientId: 0, assetId: 0, softwareId: 0, connected: false };

async function post(page, url, fields) {
    const token = await csrf(page);
    const status = await page.evaluate(async ({ url, fields }) => (await fetch(url, { method: 'POST', body: new URLSearchParams(fields), credentials: 'same-origin' })).status,
        { url: new URL(url, page.url()).href, fields: { csrf_token: token, ...fields } });
    expect(status, `POST ${url}`).toBeLessThan(400);
}
const mock = async (page, path, body) => {
    const response = body ? await page.request.post(MOCK + path, { data: body }) : await page.request.get(MOCK + path);
    return response.ok() ? response.json() : null;
};
const state = (scopes) => ({
    scopes: scopes || ['clients:read', 'devices:read', 'licenses:read', 'health:read', 'alignment:read', 'meetings:read'],
    clients: [
        { id: 71, name: made.clientName, psa_id: made.clientId, health: 82, band: 'Healthy', devices: 4, healthy: 3, alignment: 75, alignment_band: 'Needs attention',
            meeting: 'Quarterly business review', meeting_at: '2031-03-04T10:00:00+00:00' },
        { id: 72, name: 'Only in MSP Align', psa_id: 999999, health: 40, band: 'At risk' },
    ],
    devices: [{ id: 501, name: 'Smoke laptop', psa_asset_id: made.assetId, status: 'warranty_expired', label: 'Out of warranty', health: 'warn', warranty: '2025-01-31',
        replace_by: '2027-06-30', os_eos: '2025-10-14' }],
    licenses: [{ id: 601, client_id: 71, psa_id: made.softwareId, name: 'Smoke license', expire: '2027-03-01', annual: 1200 }],
});
async function syncNow(page) {
    await visit(page, 'admin/mit_align.php');
    await submit(page.locator('form:has(button[name=mit_align_sync])'), 'mit_align_sync');
    return flash(page);
}

test('MSP Align: connect, sync, and its data on the client, asset and license pages', async ({ page }) => {
    test.setTimeout(120000);
    const errors = watch(page);
    test.skip(!(await mock(page, '/_reset')), 'the MSP Align stand-in is not installed on this test install');

    // A client with an asset and a license
    await visit(page, 'agent/clients.php', errors);
    const modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    made.clientId = Number(new URL(await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href'), page.url()).searchParams.get('client_id'));
    expect(made.clientId, 'client made').toBeGreaterThan(0);
    await post(page, '/agent/post.php', { add_asset: '1', client_id: String(made.clientId), name: 'Smoke laptop', type: 'Laptop' });
    await visit(page, `agent/assets.php?client_id=${made.clientId}`, errors);
    made.assetId = Number(new URL(await page.locator('a[href*="asset.php?"]', { hasText: 'Smoke laptop' }).first().getAttribute('href'), page.url()).searchParams.get('asset_id'));
    await post(page, '/agent/post.php', { add_software: '1', client_id: String(made.clientId), name: 'Smoke license' });
    await visit(page, `agent/software.php?client_id=${made.clientId}`, errors);
    const licenseHref = await page.locator('a.stretched-link', { hasText: 'Smoke license' }).first().getAttribute('href');
    made.softwareId = Number((licenseHref.match(/software_id=(\d+)/) || [])[1] || 0);
    expect(made.assetId && made.softwareId, 'asset and license made').toBeTruthy();
    await mock(page, '/_set', state());

    // Connect: the key is tested and its scopes shown
    await visit(page, 'admin/mit_align.php', errors);
    await expect(page.locator('a.nav-link[href="/admin/mit_align.php"]'), 'Admin > Integrations menu entry').toHaveCount(1);
    const form = page.locator('form:has(button[name=mit_align_save])');
    await fillForm(form, { align_url: MOCK, align_key: 'msa_test', align_enabled: true });
    await submit(form, 'mit_align_save');
    expect(await flash(page)).toContain('MSP Align connected');
    made.connected = true;
    await expect(page.locator('[data-mit-align-scopes] .text-bg-success'), 'six read scopes').toHaveCount(6);

    // Sync: the client, the asset and the license are matched by their ITFlow ids; the client only in MSP Align is not
    expect(await syncNow(page)).toContain('Matched 1 client, 1 asset, 1 license');

    // Client overview and header
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`, errors);
    const card = page.locator('[data-mit-align-client]');
    await expect(card.locator('[data-mit-align-health="82"]'), 'the health score').toContainText('Healthy');
    await expect(card.locator('[data-mit-align-devices="1"]'), 'devices to act on').toContainText('of 4 devices');
    await expect(card.locator('[data-mit-align-meeting]')).toContainText('Quarterly business review');
    await expect(card.locator('[data-mit-align-links] a'), 'quick links').toHaveCount(10);
    await expect(card.locator('[data-mit-align-links] a', { hasText: 'Backups' })).toHaveAttribute('href', `${MOCK}/clients/71/backups`);
    await expect(card.locator('[data-mit-align-links] a', { hasText: 'Roadmap' })).toHaveAttribute('href', `${MOCK}/clients/71/roadmap`);

    // Asset list badge (and the client header's link, on the client's other pages) and the asset page's button
    await visit(page, `agent/assets.php?client_id=${made.clientId}`, errors);
    await expect(page.locator('[data-mit-align-head]'), 'the header link').toHaveAttribute('href', `${MOCK}/clients/71`);
    await expect(page.locator(`[data-mit-align-asset="${made.assetId}"]`)).toContainText('Out of warranty');
    await visit(page, `agent/asset.php?client_id=${made.clientId}&asset_id=${made.assetId}`, errors);
    await expect(page.locator(`[data-mit-align-asset-panel="${made.assetId}"]`)).toHaveAttribute('href', `${MOCK}/devices/501`);

    // License list
    await visit(page, `agent/software.php?client_id=${made.clientId}`, errors);
    const line = page.locator(`[data-mit-align-license="${made.softwareId}"]`);
    await expect(line).toContainText('renews 2027-03-01');
    await expect(line).toContainText('/yr');
    await expect(line.locator('a')).toHaveAttribute('href', `${MOCK}/clients/71/licenses`);

    // Only ever read
    const calls = await mock(page, '/_calls');
    expect(calls.length, 'calls made').toBeGreaterThan(3);
    expect(calls.filter((c) => !c.startsWith('GET ')), 'nothing but GET').toEqual([]);
    await expectClean(page, errors);
});

test('MSP Align: a key without health:read leaves the score out; a device MSP Align dropped loses its badge', async ({ page }) => {
    test.skip(!made.connected, 'not connected');
    const errors = watch(page);
    const next = state(['clients:read', 'devices:read', 'licenses:read']);
    next.devices = [];
    await mock(page, '/_set', next);
    expect(await syncNow(page)).toContain('Matched 1 client, 0 assets, 1 license');
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`, errors);
    await expect(page.locator('[data-mit-align-client] [data-mit-align-health=""]'), 'no score without health:read').toHaveCount(1);
    await visit(page, `agent/assets.php?client_id=${made.clientId}`, errors);
    await expect(page.locator(`[data-mit-align-asset="${made.assetId}"]`)).toHaveCount(0);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.connected) {
        // Switched off again (the stand-in's address and key stay, unused)
        await page.goto('admin/mit_align.php');
        await post(page, '/admin/post.php', { mit_align_save: '1', align_url: MOCK, align_key: '' }).catch(() => {});
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await page.request.get(MOCK + '/_reset').catch(() => {});
    await context.close();
});
