// QuickBooks Online income accounts by category (functions/mit_qbo_accounts.php) against the test install's stand-in: the Income
// accounts tab saves a category's account; an invoice line typed by hand gets that category in its Edit window (and shows it under
// the line); the batch lists the QuickBooks item it will make ("<category> (ITFlow)", on the category's account) before the send,
// and sending makes it there. Makes its own category, client and invoice and deletes them; disconnects again.
// Skipped when the stand-in is not installed (MIT_QBO_* in config.php - see README) or M051 has not run.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean, csrf } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const MOCK = 'http://127.0.0.1/qbo-mock/index.php';
const made = { category: smokeName('QBO Hardware'), categoryId: 0, clientName: smokeName('QBO accounts client'), clientId: 0, invoiceId: 0, lineId: 0, connected: false, batchIds: [] };
// Our QuickBooks settings this test changes - put back afterwards (rows as they were, or none)
const SETTINGS = ['qbo_default_account', 'qbo_default_account_name', 'qbo_default_item', 'qbo_time_category', 'qbo_create_items', 'qbo_fallback_item'];
const savedSettings = db.rows(`SELECT setting_name, setting_value FROM mit_settings WHERE setting_name IN ('${SETTINGS.join("','")}')`);
const invoicePage = () => `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.invoiceId}`;

async function post(page, url, fields) {
    const token = await csrf(page);
    const { status, text } = await page.evaluate(async ({ url, fields }) => {
        const response = await fetch(url, { method: 'POST', body: new URLSearchParams(fields), credentials: 'same-origin' });
        return { status: response.status, text: await response.text() };
    }, { url: new URL(url, page.url()).href, fields: { csrf_token: token, ...fields } });
    expect(status, `POST ${url}`).toBeLessThan(400);
    expect(text, `PHP error after POST ${url}`).not.toMatch(/Fatal error|Warning:|Notice:|Deprecated:/);
}

async function mock(page, path) {
    const response = await page.request.get(MOCK + path);
    return response.ok() ? response.json() : null;
}

test('the Income accounts tab maps a category to a QuickBooks account', async ({ page }) => {
    const errors = watch(page);
    test.skip(!(await mock(page, '/_reset')), 'the QuickBooks stand-in is not installed on this test install');
    test.skip(!db.value("SHOW TABLES LIKE 'mit_qbo_category_accounts'"), 'needs database update M051');
    await visit(page, 'admin/mit_qbo.php', errors);
    if (await page.locator('button[name=mit_qbo_disconnect]').count()) {
        await post(page, '/admin/post.php', { mit_qbo_disconnect: '1' });
        await visit(page, 'admin/mit_qbo.php', errors);
    }
    const form = page.locator('form:has(button[name=mit_qbo_save_settings])');
    await fillForm(form, { qbo_client_id: 'qbo-client', qbo_client_secret: 'qbo-secret', qbo_env: 'sandbox', qbo_tax_style: 'us' });
    await page.locator('select[name=qbo_payment_account]').selectOption({ index: 1 });
    await submit(form, 'mit_qbo_save_settings');
    await Promise.all([page.waitForURL(/mit_qbo\.php\?tab=customers/), page.locator('button[name=mit_qbo_connect]').click()]);
    made.connected = true;
    // No fallback item: lines must find their item through their category
    await visit(page, 'admin/mit_qbo.php', errors);
    await page.locator('select[name=qbo_fallback_item]').selectOption('');
    await submit(form, 'mit_qbo_save_settings');

    db.run(`INSERT INTO categories SET category_name = '${made.category}', category_type = 'Income', category_color = '#000'`);
    made.categoryId = Number(db.value(`SELECT category_id FROM categories WHERE category_name = '${made.category}'`));

    await visit(page, 'admin/mit_qbo.php?tab=accounts', errors);
    const accounts = page.locator('[data-mit-qbo-accounts]');
    await expect(accounts).toBeVisible();
    await accounts.locator('select[name=qbo_default_account]').selectOption({ label: 'Sales of Product Income' });
    const row = accounts.locator(`[data-mit-qbo-category="${made.categoryId}"]`);
    await expect(row, 'the category is listed').toHaveCount(1);
    await expect(row.locator('select option', { hasText: 'Checking' }), 'only income accounts offered').toHaveCount(0);
    await row.locator('select').selectOption({ label: 'Hardware Sales' });
    await submit(accounts, 'mit_qbo_save_accounts');
    expect(await flash(page)).toContain('Income accounts saved');
    await expect(page.locator(`[data-mit-qbo-category="${made.categoryId}"] select`)).toHaveValue('80');
    await expectClean(page, errors);
});

test('a typed line gets the category in its Edit window, the batch lists the item it makes, and sending makes it', async ({ page }) => {
    test.skip(!made.connected, 'not connected');
    const errors = watch(page);
    await visit(page, 'agent/clients.php', errors);
    const modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = '${made.clientName}'`));
    expect(made.clientId, 'client made').toBeGreaterThan(0);
    const add = await openModal(page, `modals/invoice/invoice_add.php?client_id=${made.clientId}`);
    await add.locator('select[name=category]').selectOption({ index: 1 });
    await fillForm(add, { scope: 'Smoke test QuickBooks accounts' });
    await submit(add, 'add_invoice');
    made.invoiceId = Number(new URL(page.url()).searchParams.get('invoice_id'));
    expect(made.invoiceId, 'invoice made').toBeGreaterThan(0);
    await post(page, '/agent/post.php', { billing_item_save: '1', type: 'invoice', doc_id: String(made.invoiceId), name: 'Smoke typed switch', description: '', qty: '1', price: '120', tax_id: '0', product_id: '0' });
    made.lineId = Number(db.value(`SELECT item_id FROM invoice_items WHERE item_invoice_id = ${made.invoiceId} AND item_name = 'Smoke typed switch'`));

    // Edit Line: pick the category
    await visit(page, invoicePage(), errors);
    const edit = await openModal(page, `modals/billing/billing_item.php?type=invoice&doc_id=${made.invoiceId}&item_id=${made.lineId}`);
    const picker = edit.locator('[data-mit-line-category] select[name=mit_line_category]');
    await expect(picker).toBeVisible();
    await picker.selectOption(String(made.categoryId));
    await Promise.all([page.waitForNavigation(), edit.locator('button[type=submit]', { hasText: 'Save' }).click()]);
    expect(db.value(`SELECT category_id FROM mit_invoice_item_categories WHERE item_id = ${made.lineId}`), 'saved').toBe(String(made.categoryId));
    await expect(page.locator(`[data-mit-line-category-tag="${made.categoryId}"]`), 'shown under the line').toContainText(made.category);

    // Approve, batch: the item it will make is listed first
    await page.locator('[data-mit-qbo-bar] a', { hasText: 'Approve' }).click();
    await page.waitForLoadState('load');
    await visit(page, 'agent/mit_qbo_batches.php', errors);
    await page.locator('select[name=batch_id]').selectOption('0');
    await page.fill('input[name=batch_name]', smokeName('QBO accounts batch'));
    await page.locator('input[name="invoice_ids[]"]').evaluateAll((boxes, id) => boxes.forEach((b) => { b.checked = b.value === String(id); }), made.invoiceId);
    await submit(page.locator('#mitBatchAddForm'), 'mit_qbo_batch_add');
    made.batchIds.push(new URL(page.url()).searchParams.get('batch_id'));
    const plan = page.locator('[data-mit-qbo-item-plan] [data-mit-qbo-plan-item]', { hasText: `${made.category} (ITFlow)` });
    await expect(plan, 'listed before the send').toHaveCount(1);
    await expect(plan).toContainText('Hardware Sales');

    await page.locator('button[name=mit_qbo_batch_send]').click();
    await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('load');
    await expectClean(page, errors);
    expect(await flash(page), 'sent').toContain('1 invoice sent to QuickBooks Online');
    const state = await mock(page, '/_state');
    const item = (state.items || []).find((i) => i.Name === `${made.category} (ITFlow)`);
    expect(item, 'the item made in QuickBooks').toBeTruthy();
    expect(item.IncomeAccountRef.value, 'on the category\'s account').toBe('80');
    const invoice = state.invoices.find((i) => i.PrivateNote && i.PrivateNote.includes('From ITFlow invoice'));
    expect(invoice.Line[0].SalesItemLineDetail.ItemRef.value, 'the line uses it').toBe(item.Id);
    await expect(page.locator('[data-mit-qbo-item-plan]'), 'nothing left to make').toHaveCount(0);
});

test('the Customers tab sets a client\'s own QuickBooks terms', async ({ page }) => {
    test.skip(!made.connected || !made.clientId, 'not connected');
    test.skip(!db.value("SHOW TABLES LIKE 'mit_qbo_client_terms'"), 'needs database update M052');
    const errors = watch(page);
    await visit(page, 'admin/mit_qbo.php?tab=customers', errors);
    const pick = page.locator(`select[name="term[${made.clientId}]"][data-mit-qbo-client-term]`);
    await expect(pick, 'a Terms picker per client').toHaveCount(1);
    await pick.selectOption({ label: 'Due on the 1st' });
    await submit(page.locator('#mitQboMapForm'), 'mit_qbo_save_map');
    expect(await flash(page)).toMatch(/\d+ mappings? saved/);
    expect(db.value(`SELECT qbo_term_name FROM mit_qbo_client_terms WHERE client_id = ${made.clientId}`), 'saved').toBe('Due on the 1st');
    await expect(page.locator(`select[name="term[${made.clientId}]"]`)).toHaveValue('4');
    await expectClean(page, errors);
});

test('an invoice\'s own terms: picked in its QuickBooks strip over the client\'s default', async ({ page }) => {
    test.skip(!made.connected || !made.clientId, 'not connected');
    test.skip(!db.value("SHOW TABLES LIKE 'mit_qbo_invoice_terms'"), 'needs database update M053');
    const errors = watch(page);
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`, errors);
    const add = await openModal(page, `modals/invoice/invoice_add.php?client_id=${made.clientId}`);
    await add.locator('select[name=category]').selectOption({ index: 1 });
    await fillForm(add, { scope: 'Smoke test QuickBooks terms' });
    await submit(add, 'add_invoice');
    made.invoice2Id = Number(new URL(page.url()).searchParams.get('invoice_id'));
    expect(made.invoice2Id, 'invoice made').toBeGreaterThan(0);
    const picker = page.locator('[data-mit-qbo-bar] [data-mit-qbo-invoice-term] select[name=term]');
    await expect(picker, 'the Terms picker in the QuickBooks strip').toHaveCount(1);
    await expect(picker.locator('option[value=""]'), 'the client\'s terms are the default').toHaveText("Client's: Due on the 1st");
    await Promise.all([page.waitForNavigation(), picker.selectOption({ label: 'Net 15' })]);
    expect(await flash(page)).toContain('QuickBooks terms: Net 15');
    expect(db.value(`SELECT qbo_term_name FROM mit_qbo_invoice_terms WHERE invoice_id = ${made.invoice2Id}`), 'saved').toBe('Net 15');
    expect(db.value(`SELECT DATEDIFF(invoice_due, invoice_date) FROM invoices WHERE invoice_id = ${made.invoice2Id}`), 'due date follows').toBe('15');
    await expect(page.locator('[data-mit-qbo-invoice-term] select[name=term]')).toHaveValue('2');
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.invoiceId) {
        await page.goto(invoicePage());
        const link = page.locator('[data-mit-qbo-bar] a[href*="mit_qbo_batch.php"]');
        if (await link.count()) {
            await page.goto(await link.getAttribute('href'));
            const entry = await page.locator(`[data-mit-batch-entries] tr:has(a[href*="invoice_id=${made.invoiceId}"])`).getAttribute('data-entry');
            await post(page, '/agent/post.php', { entry_id: entry, mit_qbo_entry_remove: 'unlink' });
        }
        await action(page, `delete_invoice=${made.invoiceId}`, invoicePage());
    }
    if (made.invoice2Id) {
        await action(page, `delete_invoice=${made.invoice2Id}`, `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.invoice2Id}`);
    }
    for (const batchId of made.batchIds) {
        await page.goto(`agent/mit_qbo_batch.php?batch_id=${batchId}`);
        await post(page, '/agent/post.php', { batch_id: batchId, mit_qbo_batch_delete: '1' });
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    if (made.categoryId) {
        db.run(`DELETE FROM categories WHERE category_id = ${made.categoryId}`);
    }
    db.run(`DELETE FROM mit_settings WHERE setting_name IN ('${SETTINGS.join("','")}')`);
    for (const row of savedSettings) {
        db.run(`INSERT INTO mit_settings SET setting_name = '${row.setting_name}', setting_value = '${String(row.setting_value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`);
    }
    if (made.connected) {
        await page.goto('admin/mit_qbo.php');
        await post(page, '/admin/post.php', { mit_qbo_disconnect: '1' });
        await page.request.get(MOCK + '/_reset');
    }
    await context.close();
});
