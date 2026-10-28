// Usage billing end to end: a recurring invoice with one line counted from contacts with a tag and one reported through the
// API (a temporary API key), Force -> the new invoice's quantities follow the counts on the snapshot day and it stays a Draft,
// Usage Review flags the big change, an override keeps its reason, and the invoice is approved; the client's Usage card takes a
// typed count. The snapshot day is set to today for the run and put back. Makes and deletes its own client, tag, API key,
// recurring invoice and invoice.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean, csrf } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = {
    clientName: smokeName('usage client'), clientId: 0, tagName: smokeName('Billable user'), tagId: 0, recurringId: 0, invoiceId: 0,
    apiKey: '', apiKeyName: smokeName('usage key'), snapshotDay: null, metric: 'smoke workstations',
};
const today = new Date();

// Post fields to a handler from inside the page (array values become name[] entries)
async function post(page, url, fields) {
    const token = await csrf(page);
    const { status, text } = await page.evaluate(async ({ url, fields }) => {
        const body = new URLSearchParams();
        for (const [name, value] of Object.entries(fields)) {
            (Array.isArray(value) ? value : [value]).forEach((v) => body.append(name, v));
        }
        const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
        return { status: response.status, text: await response.text() };
    }, { url: new URL(url, page.url()).href, fields: { csrf_token: token, ...fields } });
    expect(status, `POST ${url}`).toBeLessThan(400);
    expect(text, `PHP error after POST ${url}`).not.toMatch(/Fatal error|Warning:|Notice:|Deprecated:/);
    return text;
}

async function api(page, path, body) {
    return page.evaluate(async ({ path, body, key }) => {
        const response = await fetch(path, { method: 'POST', headers: { 'X-API-Key': key, 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        return { status: response.status, json: await response.json().catch(() => null) };
    }, { path, body, key: made.apiKey });
}

test('set up: client, tagged contacts, API key, recurring invoice with usage lines', async ({ page }) => {
    test.skip(today.getDate() > 28, 'the snapshot day can only be 1-28 - run again tomorrow');
    const errors = watch(page);
    await visit(page, 'admin/settings_invoice.php', errors);
    test.skip(await page.locator('[data-mit-usage-settings]').count() === 0, 'needs database update M032');
    made.snapshotDay = await page.locator('input[name=mit_usage_snapshot_day]').inputValue();
    await page.fill('input[name=mit_usage_snapshot_day]', String(today.getDate()));
    await submit(page.locator('form:has(button[name=mit_edit_usage_settings])'), 'mit_edit_usage_settings');
    expect(await flash(page)).toContain('Usage billing settings saved');

    // Client, a contact tag, two tagged contacts and one without
    await visit(page, 'agent/clients.php', errors);
    let modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    made.clientId = Number(new URL(await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href'), page.url()).searchParams.get('client_id'));
    expect(made.clientId).toBeGreaterThan(0);
    await post(page, '/admin/post.php', { add_tag: '1', name: made.tagName, type: '3', color: '#2f6fde', icon: 'user' });
    await visit(page, 'agent/contacts.php', errors);
    modal = await openModal(page, `modals/contact/contact_add.php?client_id=${made.clientId}`, 'lg');
    made.tagId = Number(await modal.locator('select[name="tags[]"] option', { hasText: made.tagName }).getAttribute('value'));
    expect(made.tagId, 'the tag in the contact form').toBeGreaterThan(0);
    for (const name of ['Smoke usage one', 'Smoke usage two']) {
        await post(page, '/agent/post.php', { add_contact: '1', client_id: String(made.clientId), name, 'tags[]': [String(made.tagId)] });
    }

    // A temporary API key through Admin's own form (it runs as this admin)
    await visit(page, 'agent/user/user_details.php', errors);
    const me = await page.locator('input[name=name]').inputValue();
    await visit(page, 'admin/api_keys.php', errors);
    modal = await openModal(page, 'modals/api/api_key_add.php');
    made.apiKey = await modal.locator('input[name=key]').inputValue();
    const expire = new Date(Date.now() + 2 * 86400000).toISOString().slice(0, 10);
    const userId = await modal.locator('select[name=run_as_user] option', { hasText: me }).first().getAttribute('value');
    await fillForm(modal, { name: made.apiKeyName, expire, run_as_user: userId, ack: true });
    await submit(modal, 'add_api_key');

    // Recurring invoice: Billable users (from the tag) and Workstations (from the API), one each to start
    await visit(page, 'agent/recurring_invoices.php', errors);
    modal = await openModal(page, `modals/recurring_invoice/recurring_invoice_add.php?client_id=${made.clientId}`);
    await modal.locator('select[name=category]').selectOption({ index: 1 });
    await modal.locator('select[name=frequency]').selectOption('month');
    await fillForm(modal, { scope: 'Smoke test managed services' });
    await submit(modal, 'add_recurring_invoice');
    made.recurringId = Number(new URL(page.url()).searchParams.get('recurring_invoice_id'));
    expect(made.recurringId, `recurring invoice (${await flash(page)})`).toBeGreaterThan(0);
    for (const [name, price] of [['Smoke billable users', '10'], ['Smoke workstations', '50']]) {
        await post(page, '/agent/post.php', { billing_item_save: '1', type: 'recurring', doc_id: String(made.recurringId), name, description: '', qty: '1', price, tax_id: '0' });
    }

    await visit(page, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`, errors);
    const card = page.locator('[data-mit-usage-card]');
    await expect(card).toHaveCount(1);
    const users = card.locator('tr', { hasText: 'Smoke billable users' });
    await users.locator('[data-mit-usage-source]').selectOption('tag');
    await users.locator('[data-mit-usage-tag]').selectOption(String(made.tagId));
    const stations = card.locator('tr', { hasText: 'Smoke workstations' });
    await stations.locator('[data-mit-usage-source]').selectOption('api');
    await stations.locator('[data-mit-usage-metric]').fill(made.metric);
    await submit(card.locator('form'), 'mit_usage_save_lines');
    expect(await flash(page)).toContain('Usage billing saved');
    await expect(page.locator('[data-mit-usage-card] tr', { hasText: 'Smoke workstations' }).locator('[data-mit-usage-metric]')).toHaveValue(made.metric);
    await expectClean(page, errors);
});

test('the API reports a count; a bad one is refused', async ({ page }) => {
    test.skip(!made.recurringId || !made.apiKey, 'not set up');
    await visit(page, 'agent/dashboard.php');
    let answer = await api(page, '/api/v1/mit_usage/create.php', { client_id: made.clientId, metric: made.metric, quantity: 7 });
    expect(answer.status, JSON.stringify(answer.json)).toBe(200);
    expect(answer.json.success).toBe('True');
    answer = await api(page, '/api/v1/mit_usage/create.php', { client_id: made.clientId, metric: made.metric, quantity: -3 });
    expect(answer.status).toBe(400);
    expect(answer.json.message).toContain('quantity');
    // Tag lines' names are kept for the daily tag count - the API can't write over them
    answer = await api(page, '/api/v1/mit_usage/create.php', { client_id: made.clientId, metric: `tag:${made.tagId}`, quantity: 99 });
    expect(answer.status, 'tag: name refused').toBe(400);
    // The read endpoint lists the line to report for, with its latest count
    const read = await page.evaluate(async ({ key, client }) => {
        const response = await fetch(`/api/v1/mit_usage/read.php?client_id=${client}`, { headers: { 'X-API-Key': key } });
        return response.json();
    }, { key: made.apiKey, client: made.clientId });
    const line = (read.data || []).find((l) => l.metric === made.metric);
    expect(line, 'the API line in read.php').toBeTruthy();
    expect(Number(line.latest_quantity)).toBe(7);
    // A body that is not JSON (a form post) is answered with ITFlow's JSON refusal - it was an empty 500
    // (count(null) in api/v1/*_output.php once the key came from the header)
    const form = await page.evaluate(async ({ key }) => {
        const response = await fetch('/api/v1/clients/delete.php', { method: 'POST', headers: { 'X-API-Key': key, 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'client_id=0' });
        return { status: response.status, text: await response.text() };
    }, { key: made.apiKey });
    expect(form.status, 'a form post').toBeLessThan(500);
    expect(() => JSON.parse(form.text), 'answered in JSON').not.toThrow();
});

test('Force: quantities follow the counts, the invoice stays a Draft, Usage Review flags it, override and approve', async ({ page }) => {
    test.skip(!made.recurringId, 'not set up');
    const errors = watch(page);
    await action(page, `force_recurring=${made.recurringId}`, `agent/recurring_invoice.php?recurring_invoice_id=${made.recurringId}`);
    await visit(page, `agent/invoices.php?client_id=${made.clientId}`, errors);
    const link = page.locator('a[href*="invoice.php?"][href*="invoice_id="]').filter({ hasText: /\d/ }).first();
    made.invoiceId = Number(new URL(await link.getAttribute('href'), page.url()).searchParams.get('invoice_id'));
    expect(made.invoiceId, 'the invoice made').toBeGreaterThan(0);

    await visit(page, `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.invoiceId}`, errors);
    await expect(page.locator('body')).toContainText('Draft');
    await expect(page.locator('#items tr', { hasText: 'Smoke billable users' })).toContainText('2');
    await expect(page.locator('#items tr', { hasText: 'Smoke workstations' })).toContainText('7');
    await expect(page.locator('body'), '2 x 10 + 7 x 50').toContainText('370.00');

    await visit(page, 'agent/mit_usage_review.php', errors);
    const box = page.locator(`[data-mit-usage-invoice="${made.invoiceId}"]`);
    await expect(box, 'flagged for review').toHaveCount(1);
    const stations = box.locator('tr', { hasText: 'Smoke workstations' });
    await expect(stations.locator('[data-flag="big_change"]')).toHaveCount(1);
    await stations.locator('input[name=quantity]').fill('6');
    await stations.locator('input[name=reason]').fill('One workstation retired on the 1st');
    await submit(stations.locator('form'), 'mit_usage_override');
    expect(await flash(page)).toContain('Quantity changed');
    await visit(page, 'agent/mit_usage_review.php?show=all', errors);
    const after = page.locator(`[data-mit-usage-invoice="${made.invoiceId}"] tr`, { hasText: 'Smoke workstations' });
    await expect(after.locator('[data-qty]')).toContainText('6');
    await expect(after).toContainText('One workstation retired');

    const approve = page.locator(`[data-mit-usage-invoice="${made.invoiceId}"] a`, { hasText: 'Approve' });
    if (await approve.count()) {
        await Promise.all([page.waitForNavigation(), approve.click()]);
        await expect(page.locator(`[data-mit-usage-invoice="${made.invoiceId}"] .badge`, { hasText: 'Approved' })).toHaveCount(1);
    }
    await visit(page, `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.invoiceId}`, errors);
    await expect(page.locator('body'), '2 x 10 + 6 x 50').toContainText('320.00');
    await expectClean(page, errors);
});

test('the client\'s Usage card shows the lines and takes a typed count', async ({ page }) => {
    test.skip(!made.recurringId, 'not set up');
    const errors = watch(page);
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`, errors);
    const card = page.locator('[data-mit-usage-client]');
    await expect(card.locator('tr', { hasText: 'Smoke billable users' })).toContainText('now 2');
    const row = card.locator(`tr[data-metric="${made.metric}"]`);
    await expect(row).toContainText('7');
    await row.locator('input[name=quantity]').fill('9');
    await submit(row.locator('form'), 'mit_usage_count');
    expect(await flash(page)).toContain('Count saved');
    await expect(page.locator(`[data-mit-usage-client] tr[data-metric="${made.metric}"]`)).toContainText('9');
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    if (process.env.SMOKE_KEEP) { return; }
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
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    if (made.apiKey) {
        // ITFlow offers Delete only for an expired key - revoke it first
        for (const kind of ['revoke_api_key', 'delete_api_key']) {
            await page.goto(`admin/api_keys.php?q=${encodeURIComponent(made.apiKeyName)}`);
            const link = page.locator('tr', { hasText: made.apiKeyName }).locator(`a[href*="${kind}="]`);
            if (await link.count()) {
                await page.goto(new URL(await link.first().getAttribute('href'), page.url()).href);
            }
        }
    }
    if (made.tagId) {
        await page.goto('admin/settings_invoice.php');
        const token = await csrf(page);
        await page.goto(`admin/post.php?delete_tag=${made.tagId}&csrf_token=${encodeURIComponent(token)}`);
    }
    if (made.snapshotDay !== null) {
        await page.goto('admin/settings_invoice.php');
        await page.fill('input[name=mit_usage_snapshot_day]', made.snapshotDay);
        await submit(page.locator('form:has(button[name=mit_edit_usage_settings])'), 'mit_edit_usage_settings');
    }
    await context.close();
});
