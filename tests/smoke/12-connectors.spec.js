// Usage connectors against the test install's stand-ins (usage-mock - never real accounts): NinjaOne device counts, a Bitwarden
// client organization (a wrong key refused, the right one linked: 3 users, 2 confirmed), Ingram Micro (login, read customers,
// link one, its subscriptions counted per part number), and a recurring invoice line picking an Ingram product from the list and
// billing its quantity (12 + 3 on two subscriptions = 15). Skipped unless the stand-ins are installed (MIT_BITWARDEN_* /
// MIT_INGRAM_API_BASE in config.php - see README). Deletes its client, recurring invoice and invoice, puts settings back.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean, csrf } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const MOCK = 'http://127.0.0.1/usage-mock/index.php';
const BW_ORG = 'organization.11111111-2222-3333-4444-555555555555';
const MPN = 'CFQ7TTC0LH18:0001';
const made = { clientName: 'Smoke Ingram Customer', clientId: 0, recurringId: 0, invoiceId: 0, ninjaWasOn: null, snapshotDay: null, ingram: false };
const today = new Date();

async function post(page, url, fields) {
    const token = await csrf(page);
    const { status, text } = await page.evaluate(async ({ url, fields }) => {
        const response = await fetch(url, { method: 'POST', body: new URLSearchParams(fields), credentials: 'same-origin' });
        return { status: response.status, text: await response.text() };
    }, { url: new URL(url, page.url()).href, fields: { csrf_token: token, ...fields } });
    expect(status, `POST ${url}`).toBeLessThan(400);
    expect(text, `PHP error after POST ${url}`).not.toMatch(/Fatal error|Warning:|Notice:|Deprecated:/);
}

async function runNow(page, tab) {
    await visit(page, `admin/mit_usage_connectors.php?tab=${tab}`);
    await submit(page.locator('form:has(button[name=mit_usage_connector_run])'), 'mit_usage_connector_run');
    return flash(page);
}

test('NinjaOne device counts', async ({ page }) => {
    const errors = watch(page);
    const mock = await page.request.get(`${MOCK}/nothing`).catch(() => null);
    test.skip(!mock || mock.status() !== 404, 'the usage stand-ins are not installed on this test install');
    await visit(page, 'admin/mit_usage_connectors.php', errors);
    test.skip(await page.locator('input[name=usage_ninjaone_on]').count() === 0, 'needs database update M033');
    await expect(page.locator('a.nav-link[href="/admin/mit_usage_connectors.php"]'), 'Admin > Integrations menu entry').toHaveCount(1);
    made.ninjaWasOn = await page.locator('input[name=usage_ninjaone_on]').isChecked();
    await page.locator('input[name=usage_ninjaone_on]').check();
    await submit(page.locator('form:has(button[name=mit_usage_ninjaone_save])'), 'mit_usage_ninjaone_save');
    expect(await runNow(page, 'ninjaone')).toMatch(/NinjaOne: \d+ counted|set NinjaOne up first|NinjaOne is not set up/);
    await expectClean(page, errors);
});

test('Bitwarden: a wrong key is refused, the right one links and counts', async ({ page }) => {
    test.skip(made.ninjaWasOn === null, 'not set up');
    const errors = watch(page);
    await visit(page, 'agent/clients.php', errors);
    const modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    made.clientName = `${made.clientName}`;
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    made.clientId = Number(new URL(await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href'), page.url()).searchParams.get('client_id'));
    expect(made.clientId).toBeGreaterThan(0);

    await visit(page, 'admin/mit_usage_connectors.php?tab=bitwarden', errors);
    const form = page.locator('[data-mit-bitwarden-add]');
    await fillForm(form, { client_id: String(made.clientId), external_name: 'Smoke vault', api_client_id: BW_ORG, api_secret: 'wrong' });
    await submit(form, 'mit_usage_bitwarden_link');
    expect(await flash(page)).toContain('Bitwarden refused the API key');
    await fillForm(page.locator('[data-mit-bitwarden-add]'), { client_id: String(made.clientId), external_name: 'Smoke vault', api_client_id: BW_ORG, api_secret: 'bw-secret' });
    await submit(page.locator('[data-mit-bitwarden-add]'), 'mit_usage_bitwarden_link');
    expect(await flash(page)).toContain('linked');
    await expect(page.locator('[data-mit-bitwarden-links] tr', { hasText: made.clientName })).toContainText('3 users (2 confirmed)');
    expect(await runNow(page, 'bitwarden')).toContain('Bitwarden: 1 counted');
    await expectClean(page, errors);
});

test('Ingram Micro: login, read customers, link, count subscriptions', async ({ page }) => {
    test.skip(!made.clientId, 'not set up');
    const errors = watch(page);
    await visit(page, 'admin/mit_usage_connectors.php?tab=ingram', errors);
    const form = page.locator('form:has(button[name=mit_usage_ingram_save])');
    await fillForm(form, { usage_ingram_marketplace: 'us', usage_ingram_login: 'im-user', usage_ingram_password: 'im-pass', usage_ingram_key: 'im-key' });
    await submit(form, 'mit_usage_ingram_save');
    made.ingram = true;
    await submit(page.locator('form:has(button[name=mit_usage_ingram_customers])'), 'mit_usage_ingram_customers');
    expect(await flash(page)).toContain('2 Ingram customers read');
    // Our client has the same name as the stand-in's customer C-1001
    await submit(page.locator('form:has(button[name=mit_usage_ingram_match])'), 'mit_usage_ingram_match');
    expect(await flash(page)).toMatch(/[1-9]\d* matched by name/);
    await expect(page.locator(`[data-mit-ingram-map] select[name="map[${made.clientId}]"]`)).toHaveValue('C-1001');
    expect(await runNow(page, 'ingram')).toContain('Ingram Micro: 1 counted');
    await expectClean(page, errors);
});

test('a recurring line picks the Ingram product and bills its quantity', async ({ page }) => {
    test.skip(!made.clientId || !made.ingram, 'not set up');
    test.skip(today.getDate() > 28, 'the snapshot day can only be 1-28 - run again tomorrow');
    const errors = watch(page);
    await visit(page, 'admin/settings_invoice.php', errors);
    made.snapshotDay = await page.locator('input[name=mit_usage_snapshot_day]').inputValue();
    await page.fill('input[name=mit_usage_snapshot_day]', String(today.getDate()));
    await submit(page.locator('form:has(button[name=mit_edit_usage_settings])'), 'mit_edit_usage_settings');

    await visit(page, 'agent/recurring_invoices.php', errors);
    const modal = await openModal(page, `modals/recurring_invoice/recurring_invoice_add.php?client_id=${made.clientId}`);
    await modal.locator('select[name=category]').selectOption({ index: 1 });
    await modal.locator('select[name=frequency]').selectOption('month');
    await fillForm(modal, { scope: 'Smoke test Microsoft 365' });
    await submit(modal, 'add_recurring_invoice');
    made.recurringId = Number(new URL(page.url()).searchParams.get('recurring_invoice_id'));
    expect(made.recurringId).toBeGreaterThan(0);
    await post(page, '/agent/post.php', { billing_item_save: '1', type: 'recurring', doc_id: String(made.recurringId), name: 'Smoke M365 Basic', description: '', qty: '1', price: '6', tax_id: '0' });

    await visit(page, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`, errors);
    await expect(page.locator(`#mitUsageMetrics option[value="ingram:${MPN}"]`), 'the Ingram product offered').toHaveAttribute('label', 'Microsoft 365 Business Basic');
    await expect(page.locator('#mitUsageMetrics option[value="bitwarden:users"]'), 'Bitwarden offered').toHaveCount(1);
    const row = page.locator('[data-mit-usage-card] tr', { hasText: 'Smoke M365 Basic' });
    await row.locator('[data-mit-usage-source]').selectOption('api');
    await row.locator('[data-mit-usage-metric]').fill(`ingram:${MPN}`);
    await submit(page.locator('[data-mit-usage-card] form'), 'mit_usage_save_lines');
    expect(await flash(page)).toContain('Usage billing saved');

    await action(page, `force_recurring=${made.recurringId}`, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`);
    await visit(page, `agent/invoices.php?client_id=${made.clientId}`, errors);
    const link = page.locator('a[href*="invoice.php?"][href*="invoice_id="]').filter({ hasText: /\d/ }).first();
    made.invoiceId = Number(new URL(await link.getAttribute('href'), page.url()).searchParams.get('invoice_id'));
    await visit(page, `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.invoiceId}`, errors);
    await expect(page.locator('body'), '15 x 6').toContainText('90.00');
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.invoiceId) {
        await action(page, `delete_invoice=${made.invoiceId}`, `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.invoiceId}`);
    }
    if (made.recurringId) {
        await action(page, `delete_recurring_invoice=${made.recurringId}`, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`);
    }
    if (made.clientId) {
        // Its Bitwarden / Ingram links and counts go with it
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    await page.goto('admin/mit_usage_connectors.php');
    if (made.ingram) {
        await post(page, '/admin/post.php', { mit_usage_ingram_save: '1', usage_ingram_marketplace: 'us', usage_ingram_login: '', usage_ingram_base: '' });
    }
    if (made.ninjaWasOn === false) {
        await post(page, '/admin/post.php', { mit_usage_ninjaone_save: '1' });
    }
    if (made.snapshotDay !== null) {
        await page.goto('admin/settings_invoice.php');
        await page.fill('input[name=mit_usage_snapshot_day]', made.snapshotDay);
        await submit(page.locator('form:has(button[name=mit_edit_usage_settings])'), 'mit_edit_usage_settings');
    }
    await context.close();
});
