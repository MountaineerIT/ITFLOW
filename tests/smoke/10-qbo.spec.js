// QuickBooks Online, end to end against the test install's stand-in for Intuit (qbo-mock - never a real company): connect,
// map items, approve an invoice, batch it and send it (the invoice locks), take it out with a void, send it again, and
// payments made "in QuickBooks" coming back (Partial, then Paid), and "Already in QuickBooks" for one entered there by hand. Makes its own client and invoice and deletes them, and
// disconnects again (while connected ITFlow's own invoice emails stop), also when a step fails.
// Skipped when the stand-in is not installed (MIT_QBO_* in config.php - see README).

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean, csrf } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const MOCK = 'http://127.0.0.1/qbo-mock/index.php';
const made = { clientName: smokeName('QBO client'), clientId: 0, invoiceId: 0, manualId: 0, docNumber: '', connected: false, batchIds: [] };
const invoicePage = () => `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.invoiceId}`;
const bar = (page) => page.locator('[data-mit-qbo-bar]');

// Post one of ITFlow's own forms' fields straight to its handler, with the session's token
// (from inside the page - the test host name only resolves in the browser)
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

async function sendBatch(page, beforeSend = null) {
    await page.locator('select[name=batch_id]').selectOption('0');
    await page.fill('input[name=batch_name]', smokeName('QBO batch'));
    await page.locator('input[name="invoice_ids[]"]').evaluateAll((boxes, id) => boxes.forEach((b) => { b.checked = b.value === String(id); }), made.invoiceId);
    await submit(page.locator('#mitBatchAddForm'), 'mit_qbo_batch_add');
    expect(await flash(page)).toContain('1 invoice added');
    made.batchIds.push(new URL(page.url()).searchParams.get('batch_id'));
    await expect(page.locator(`[data-mit-batch-entries] a[href*="invoice_id=${made.invoiceId}"]`)).toHaveCount(1);
    if (beforeSend) {
        const batchPage = page.url();
        await beforeSend();
        await visit(page, batchPage);
    }
    await page.locator('button[name=mit_qbo_batch_send]').click();
    await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('load');
    await expectClean(page);
    expect(await flash(page), 'sent').toContain('1 invoice sent to QuickBooks Online');
}

test('connect to QuickBooks Online (stand-in)', async ({ page }) => {
    const errors = watch(page);
    test.skip(!(await mock(page, '/_reset')), 'the QuickBooks stand-in is not installed on this test install');
    await visit(page, 'admin/mit_qbo.php', errors);
    test.skip(await page.locator('input[name=qbo_client_id]').count() === 0, 'needs database update M031');
    await expect(page.locator('a.nav-link[href="/admin/mit_qbo.php"]'), 'Admin > Integrations menu entry').toHaveCount(1);
    if (await page.locator('button[name=mit_qbo_disconnect]').count()) {
        // Left connected by an earlier run that stopped part way
        await post(page, '/admin/post.php', { mit_qbo_disconnect: '1' });
        await visit(page, 'admin/mit_qbo.php', errors);
    }
    const form = page.locator('form:has(button[name=mit_qbo_save_settings])');
    await fillForm(form, { qbo_client_id: 'qbo-client', qbo_client_secret: 'qbo-secret', qbo_env: 'sandbox', qbo_tax_style: 'us' });
    await page.locator('select[name=qbo_payment_account]').selectOption({ index: 1 });
    await submit(form, 'mit_qbo_save_settings');
    expect(await flash(page)).toContain('settings saved');

    // The stand-in's sign-in sends the browser back to the redirect address (over http on the test install)
    await Promise.all([page.waitForURL(/mit_qbo\.php\?tab=customers/), page.locator('button[name=mit_qbo_connect]').click()]);
    await expectClean(page, errors);
    expect(await flash(page)).toContain('QuickBooks Online connected: Sandbox Company_US_1');
    made.connected = true;

    // Items by name; Services is the fallback for lines with no match
    await visit(page, 'admin/mit_qbo.php?tab=items', errors);
    await submit(page.locator('form:has(button[name=mit_qbo_auto_match])'), 'mit_qbo_auto_match');
    expect(await flash(page)).toMatch(/\d+ matched by name/);
    await visit(page, 'admin/mit_qbo.php', errors);
    await page.locator('select[name=qbo_fallback_item]').selectOption({ label: 'Services' });
    await submit(form, 'mit_qbo_save_settings');
    await expectClean(page, errors);
});

test('approve, batch and send an invoice - locked while in QuickBooks', async ({ page }) => {
    test.skip(!made.connected, 'not connected');
    const errors = watch(page);
    await visit(page, 'agent/clients.php', errors);
    const modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');
    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    made.clientId = Number(new URL(await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href'), page.url()).searchParams.get('client_id'));
    expect(made.clientId, 'client made').toBeGreaterThan(0);

    const add = await openModal(page, `modals/invoice/invoice_add.php?client_id=${made.clientId}`);
    await add.locator('select[name=category]').selectOption({ index: 1 });
    await fillForm(add, { scope: 'Smoke test QuickBooks' });
    await submit(add, 'add_invoice');
    made.invoiceId = Number(new URL(page.url()).searchParams.get('invoice_id'));
    expect(made.invoiceId, `invoice page (${await flash(page)})`).toBeGreaterThan(0);
    await post(page, '/agent/post.php', { billing_item_save: '1', type: 'invoice', doc_id: String(made.invoiceId), name: 'Smoke test labour', description: '', qty: '2', price: '50', tax_id: '0', product_id: '0' });

    // Awaiting approval; ITFlow's own email buttons are gone while QuickBooks emails invoices
    await visit(page, invoicePage(), errors);
    await expect(bar(page)).toContainText('Awaiting approval');
    await expect(page.locator('[data-modal-url*="invoice_email.php"]').first()).toBeHidden();
    await Promise.all([page.waitForNavigation(), bar(page).locator('a', { hasText: 'Approve' }).click()]);
    await expectClean(page, errors);
    await expect(bar(page)).toContainText('Approved');

    // Changing it after approval sends it back for approval
    await post(page, '/agent/post.php', { billing_item_save: '1', type: 'invoice', doc_id: String(made.invoiceId), name: 'Smoke test extra', description: '', qty: '1', price: '10', tax_id: '0', product_id: '0' });
    await visit(page, invoicePage(), errors);
    await expect(bar(page)).toContainText('Changed since approval');
    await visit(page, 'agent/mit_invoice_approvals.php?show=changed', errors);
    await page.locator(`input[name="invoice_ids[]"][value="${made.invoiceId}"]`).check();
    await submit(page.locator('#mitApproveForm'), 'mit_invoice_approve_bulk');
    expect(await flash(page)).toContain('1 invoice approved');

    // Batch, and while it waits to be sent a payment in ITFlow is refused (it would be billed again by QuickBooks); then send
    await visit(page, 'agent/mit_qbo_batches.php', errors);
    await sendBatch(page, async () => {
        await visit(page, invoicePage(), errors);
        await post(page, '/agent/post.php', { add_payment: '1', invoice_id: String(made.invoiceId), date: new Date().toISOString().slice(0, 10), amount: '110',
            account: '1', payment_method: 'Cash', reference: 'Smoke test refused', currency_code: 'USD', balance: '110', email_receipt: '0' });
        await visit(page, invoicePage(), errors);
        await expect(page.locator('body'), 'no payment while batched').not.toContainText('Smoke test refused');
    });
    const state = await mock(page, '/_state');
    const qbo = state.invoices.find((i) => i.PrivateNote && i.PrivateNote.includes('From ITFlow invoice'));
    expect(qbo, 'the invoice in QuickBooks').toBeTruthy();
    expect(qbo.TotalAmt, 'QuickBooks total').toBe(110);
    made.docNumber = qbo.DocNumber;

    // Locked: the change is refused, the lines are as sent, the status followed
    await visit(page, invoicePage(), errors);
    await expect(bar(page)).toContainText('In QuickBooks');
    // Every Add Payment button (ITFlow 26.11 has one in the header and one by the payments) and the line controls are hidden
    for (const hidden of ['[data-modal-url*="payment_add.php"]', '[data-modal-url*="billing_item.php"]', '#add-line', '.itflow-paper-actions']) {
        const shown = await page.locator(hidden).evaluateAll((els) => els.filter((el) => el.offsetParent !== null).length);
        expect(shown, `${hidden} hidden while locked`).toBe(0);
    }
    await post(page, '/agent/post.php', { billing_item_save: '1', type: 'invoice', doc_id: String(made.invoiceId), name: 'Smoke test refused', description: '', qty: '1', price: '1', tax_id: '0', product_id: '0' });
    await visit(page, invoicePage(), errors);
    await expect(page.locator('body')).not.toContainText('Smoke test refused');
    await expect(page.locator('body')).toContainText('Sent');
    // Taking a ticket off it is refused too (our guard kept through ITFlow 3.4.8's rewrite of remove_ticket_from_invoice)
    const ticketNumber = Number(db.value('SELECT IFNULL(MAX(ticket_number), 0) + 1000 FROM tickets'));
    db.run(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${ticketNumber}, ticket_subject = 'Smoke test QBO ticket', ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 5, ticket_created_by = 1, ticket_url_key = 'smokeqbo${Date.now()}', ticket_client_id = ${made.clientId}, ticket_invoice_id = ${made.invoiceId}`);
    const lockedTicket = db.value(`SELECT ticket_id FROM tickets WHERE ticket_number = ${ticketNumber}`);
    await page.goto(`/agent/post.php?remove_ticket_from_invoice&invoice_id=${made.invoiceId}&ticket_id=${lockedTicket}&csrf_token=${encodeURIComponent(await csrf(page))}`);
    const stillOn = db.value(`SELECT ticket_invoice_id FROM tickets WHERE ticket_id = ${lockedTicket}`);
    db.run(`DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${lockedTicket}; DELETE FROM tickets WHERE ticket_id = ${lockedTicket}`);
    expect(stillOn, 'the ticket stays on the locked invoice').toBe(String(made.invoiceId));
    await expectClean(page, errors);
});

test('void it in QuickBooks and take it out, then send it again', async ({ page }) => {
    test.skip(!made.invoiceId || !made.docNumber, 'nothing sent');
    const errors = watch(page);
    await visit(page, invoicePage(), errors);
    await Promise.all([page.waitForNavigation(), bar(page).locator('a[href*="mit_qbo_batch.php"]').click()]);
    const row = page.locator(`[data-mit-batch-entries] tr:has(a[href*="invoice_id=${made.invoiceId}"])`);
    await row.locator('button.dropdown-toggle').click();
    await row.locator('button[value=void]').click();
    await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('load');
    await expectClean(page, errors);
    expect(await flash(page)).toContain('Taken out of the batch');
    const state = await mock(page, '/_state');
    expect(state.invoices.find((i) => i.DocNumber === made.docNumber).PrivateNote, 'voided in QuickBooks').toBe('Voided');

    await visit(page, invoicePage(), errors);
    await expect(bar(page)).toContainText('Awaiting approval');
    await expect(page.locator('body')).toContainText('Draft');
    await Promise.all([page.waitForNavigation(), bar(page).locator('a', { hasText: 'Approve' }).click()]);
    await visit(page, 'agent/mit_qbo_batches.php', errors);
    await sendBatch(page);
});

test('payments made in QuickBooks come back: Partial, then Paid', async ({ page }) => {
    test.skip(!made.docNumber, 'nothing sent');
    const errors = watch(page);
    await mock(page, `/_pay?doc=${encodeURIComponent(made.docNumber)}&amount=40`);
    await visit(page, 'admin/mit_qbo.php', errors);
    await submit(page.locator('form:has(button[name=mit_qbo_sync_now])'), 'mit_qbo_sync_now');
    expect(await flash(page)).toMatch(/1 changed in ITFlow/);
    await visit(page, invoicePage(), errors);
    await expect(page.locator('body')).toContainText('Partial');
    await expect(page.locator('body')).toContainText('QuickBooks Online');

    await mock(page, `/_pay?doc=${encodeURIComponent(made.docNumber)}`);
    await visit(page, 'admin/mit_qbo.php', errors);
    await submit(page.locator('form:has(button[name=mit_qbo_sync_now])'), 'mit_qbo_sync_now');
    await visit(page, invoicePage(), errors);
    await expect(page.locator('body')).toContainText('Paid');
    await expect(bar(page)).toContainText('balance there 0.00');

    // The synced payment can't be deleted by hand
    const paymentLink = page.locator('a[href*="delete_payment="]').first();
    if (await paymentLink.count()) {
        const paymentId = new URL(await paymentLink.getAttribute('href'), page.url()).searchParams.get('delete_payment');
        await action(page, `delete_payment=${paymentId}`, invoicePage());
        await visit(page, invoicePage(), errors);
        await expect(page.locator(`a[href*="delete_payment=${paymentId}"]`), 'the synced payment is still there').toHaveCount(1);
    }
    await expectClean(page, errors);
});

test('already in QuickBooks: marked by hand it leaves the queues, is never batched, and Undo puts it back', async ({ page }) => {
    test.skip(!made.connected || !made.clientId, 'not connected');
    const errors = watch(page);
    await visit(page, `agent/invoices.php?client_id=${made.clientId}`, errors);
    const add = await openModal(page, `modals/invoice/invoice_add.php?client_id=${made.clientId}`);
    await add.locator('select[name=category]').selectOption({ index: 1 });
    await fillForm(add, { scope: 'Smoke test entered by hand' });
    await submit(add, 'add_invoice');
    made.manualId = Number(new URL(page.url()).searchParams.get('invoice_id'));
    expect(made.manualId, 'second invoice').toBeGreaterThan(0);
    const manualPage = `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.manualId}`;
    const row = (p) => p.locator(`[data-mit-approvals] input[name="invoice_ids[]"][value="${made.manualId}"]`);

    // From the invoice page (with ITFlow's confirm window)
    await visit(page, manualPage, errors);
    await bar(page).locator('[data-mit-qbo-mark-manual]').click();
    await Promise.all([page.waitForNavigation(), page.locator('.swal2-confirm').click()]);
    expect(await flash(page)).toContain('1 invoice marked as already in QuickBooks');
    await expect(bar(page).locator('[data-mit-qbo-manual]'), 'the strip says so').toHaveCount(1);
    await expect(bar(page).locator('a', { hasText: 'Approve' }), 'no Approve').toHaveCount(0);

    // Out of the approval lists, on its own list; approving or batching it is refused
    await visit(page, `agent/mit_invoice_approvals.php?show=all&client=${made.clientId}`, errors);
    await expect(row(page), 'not in All open').toHaveCount(0);
    await post(page, '/agent/post.php', { mit_invoice_approve_bulk: '1', 'invoice_ids[]': String(made.manualId) });
    await visit(page, `agent/mit_invoice_approvals.php?show=manual&client=${made.clientId}`, errors);
    await expect(row(page), 'on the Already in QuickBooks list').toHaveCount(1);
    await visit(page, manualPage, errors);
    await expect(bar(page).locator('[data-mit-qbo-manual]'), 'still marked, not approved').toHaveCount(1);

    // Undo from that list: back awaiting approval; approve it, then mark it from the batches page
    await visit(page, `agent/mit_invoice_approvals.php?show=manual&client=${made.clientId}`, errors);
    await row(page).check();
    await submit(page.locator('#mitApproveForm'), 'mit_qbo_unmark_manual_bulk');
    expect(await flash(page)).toContain('Back in the QuickBooks queue');
    await visit(page, `agent/mit_invoice_approvals.php?show=awaiting&client=${made.clientId}`, errors);
    await row(page).check();
    await submit(page.locator('#mitApproveForm'), 'mit_invoice_approve_bulk');
    await visit(page, 'agent/mit_qbo_batches.php', errors);
    const ready = page.locator(`[data-mit-ready] input[name="invoice_ids[]"][value="${made.manualId}"]`);
    await expect(ready, 'approved, waiting for a batch').toHaveCount(1);
    await page.locator('#mitBatchAddForm input[name="invoice_ids[]"]').evaluateAll((boxes, id) => boxes.forEach((b) => { b.checked = b.value === String(id); }), made.manualId);
    await page.locator('[data-mit-qbo-mark-bulk]').click();
    await Promise.all([page.waitForNavigation(), page.locator('.swal2-confirm').click()]);
    expect(await flash(page)).toContain('1 invoice marked');
    await expect(ready, 'gone from the batch list').toHaveCount(0);
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.invoiceId) {
        // Out of its batch (left in the stand-in), so ITFlow lets it be deleted
        await page.goto(invoicePage());
        const link = page.locator('[data-mit-qbo-bar] a[href*="mit_qbo_batch.php"]');
        if (await link.count()) {
            await page.goto(await link.getAttribute('href'));
            const entry = await page.locator(`[data-mit-batch-entries] tr:has(a[href*="invoice_id=${made.invoiceId}"])`).getAttribute('data-entry');
            await post(page, '/agent/post.php', { entry_id: entry, mit_qbo_entry_remove: 'unlink' });
        }
        await action(page, `delete_invoice=${made.invoiceId}`, invoicePage());
    }
    if (made.manualId) {
        await action(page, `delete_invoice=${made.manualId}`, `agent/invoice.php?client_id=${made.clientId}&invoice_id=${made.manualId}`).catch(() => {});
    }
    for (const batchId of made.batchIds) {
        await page.goto(`agent/mit_qbo_batch.php?batch_id=${batchId}`);
        await post(page, '/agent/post.php', { batch_id: batchId, mit_qbo_batch_delete: '1' });
    }
    if (made.clientId) {
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`);
    }
    if (made.connected) {
        await page.goto('admin/mit_qbo.php');
        await post(page, '/admin/post.php', { mit_qbo_disconnect: '1' });
        // Empty batches left from this run
        await page.request.get(MOCK + '/_reset');
    }
    await context.close();
});
