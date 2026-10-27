// QuickBooks Online locks, every door: an invoice in a batch - waiting to send (still a Draft) and sent (in QuickBooks) - is tried
// through each of ITFlow's handlers that change, pay, email or delete an invoice (forms, GET links, the page's in-place ajax, the
// API), and must come out exactly as it was (fingerprint, status, payments, lines). Then the sync's own payment can't be edited or
// deleted by hand, the client can't be deleted, the approval can't be taken back, and parts can't be billed onto it.
// Uses the stand-in at http://127.0.0.1/qbo-mock; makes its own client / invoices and removes them, and disconnects again.

const { test, expect } = require('@playwright/test');
const { watch, visit, csrf } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const made = { client: 0, sent: 0, queued: 0, ticket: 0, key: 0, keySecret: 'smoke61' + Date.now(), wasConnected: false, batches: [] };

function state(id) {
    return JSON.parse(db.php(`echo json_encode(['fp' => mitInvoiceFingerprint(${id}), 'status' => getFieldById('invoices', ${id}, 'invoice_status'),
        'archived' => getFieldById('invoices', ${id}, 'invoice_archived_at'), 'exists' => (bool) getFieldById('invoices', ${id}, 'invoice_id'),
        'payments' => intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM payments WHERE payment_invoice_id = ${id}"))[0]),
        'lines' => intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM invoice_items WHERE item_invoice_id = ${id}"))[0]),
        'note' => getFieldById('invoices', ${id}, 'invoice_note')]);`));
}

test.beforeAll(async () => {
    made.wasConnected = db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'qbo_refresh_token'") ? true : false;
    const mock = await fetch('http://127.0.0.1/qbo-mock/index.php/_reset').catch(() => null);
    test.skip(!mock || !mock.ok, 'no QuickBooks stand-in');
    made.client = db.insert(`INSERT INTO clients SET client_name = 'Smoke 61 QBO client ${Date.now()}', client_net_terms = 30, client_currency_code = 'USD'`);
    db.exec(`INSERT INTO contacts SET contact_name = 'Smoke 61 billing', contact_email = 'smoke61@example.invalid', contact_billing = 1, contact_primary = 1, contact_client_id = ${made.client}`);
    const out = db.php(`
        if (mitDecryptSecret((string) mitSetting('qbo_client_secret', '')) !== 'qbo-secret') { mitSetSetting('qbo_client_id', 'qbo-client'); mitSetSetting('qbo_client_secret', mitEncryptSecret('qbo-secret')); }
        mitSetSetting('qbo_env', 'sandbox');
        mitQboConnect('code-smoke61', '9130355');
        mitSetSetting('qbo_fallback_item', '2');
        if (!mitSetting('qbo_payment_account', '')) { mitSetSetting('qbo_payment_account', mysqli_fetch_row(mysqli_query($mysqli, "SELECT account_id FROM accounts LIMIT 1"))[0]); }
        $ids = [];
        foreach (['sent', 'queued', 'control'] as $k) {
            $id = billingInvoiceCreate(${made.client}, date('Y-m-d'), 0, 'Smoke 61 ' . $k, 'Smoke');
            billingItemSave('invoice', $id, ['name' => 'Smoke 61 work', 'qty' => '2', 'price' => '150', 'tax_id' => 0], 0, $e);
            billingItemSave('invoice', $id, ['name' => 'Smoke 61 part', 'qty' => '1', 'price' => '40', 'tax_id' => 0], 0, $e);
            billingRecalc('invoice', $id);
            mitInvoiceApprove([$id]);
            $ids[$k] = $id;
        }
        $b1 = mitQboBatchCreate('Smoke 61 sent', false); mitQboBatchAdd($b1, [$ids['sent']]); mitQboBatchSend($b1);
        $b2 = mitQboBatchCreate('Smoke 61 queued', false); mitQboBatchAdd($b2, [$ids['queued']]);
        echo json_encode($ids + ['b1' => $b1, 'b2' => $b2]);`);
    const ids = JSON.parse(out.trim().split('\n').pop());
    made.sent = ids.sent;
    made.queued = ids.queued;
    made.control = ids.control;
    db.exec(`DELETE FROM mit_invoice_approvals WHERE invoice_id = ${made.control}`);
    made.batches = [ids.b1, ids.b2];
    made.key = db.insert(`INSERT INTO api_keys SET api_key_name = 'Smoke 61', api_key_secret = ${db.q(made.keySecret)}, api_key_decrypt_hash = '', api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = 1`);
});

test.afterAll(async () => {
    for (const id of [made.sent, made.queued, made.control].filter(Boolean)) {
        db.exec(`DELETE FROM payments WHERE payment_invoice_id = ${id}; DELETE FROM mit_qbo_batch_invoices WHERE invoice_id = ${id}; DELETE FROM mit_invoice_approvals WHERE invoice_id = ${id};
            DELETE FROM mit_qbo_manual WHERE invoice_id = ${id}; DELETE FROM invoice_items WHERE item_invoice_id = ${id}; DELETE FROM history WHERE history_invoice_id = ${id}; DELETE FROM invoices WHERE invoice_id = ${id}`);
    }
    for (const id of made.batches) {
        db.exec(`DELETE FROM mit_qbo_batches WHERE batch_id = ${id}`);
    }
    if (made.ticket) {
        db.exec(`DELETE FROM mit_ticket_parts WHERE ticket_id = ${made.ticket}; DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticket}; DELETE FROM tickets WHERE ticket_id = ${made.ticket}`);
    }
    if (made.client) {
        db.exec(`DELETE FROM mit_qbo_customers WHERE client_id = ${made.client}; DELETE FROM contacts WHERE contact_client_id = ${made.client}; DELETE FROM clients WHERE client_id = ${made.client}`);
    }
    if (made.key) {
        db.exec(`DELETE FROM api_keys WHERE api_key_id = ${made.key}`);
    }
    if (!made.wasConnected) {
        db.php('mitQboDisconnect();');
    }
    await fetch('http://127.0.0.1/qbo-mock/index.php/_reset').catch(() => null);
});

test('both invoices are locked: one sent to QuickBooks, one waiting in a batch', async () => {
    expect(state(made.sent).status, 'sent one followed to Sent').toBe('Sent');
    expect(state(made.queued).status, 'queued one still a Draft').toBe('Draft');
    expect(db.val(`SELECT entry_status FROM mit_qbo_batch_invoices WHERE invoice_id = ${made.sent}`)).toBe('sent');
    expect(db.val(`SELECT entry_status FROM mit_qbo_batch_invoices WHERE invoice_id = ${made.queued}`)).toBe('queued');
});

for (const which of ['control', 'sent', 'queued']) {
    test(`every ITFlow door ${which === 'control' ? 'works on an invoice in no batch (control)' : 'refuses a ' + which + ' invoice'}`, async ({ page }) => {
        const errors = watch(page);
        const id = made[which];
        const before = state(id);
        await visit(page, `agent/invoice.php?invoice_id=${id}`, errors);
        const token = await csrf(page);
        const line = db.val(`SELECT item_id FROM invoice_items WHERE item_invoice_id = ${id} ORDER BY item_id LIMIT 1`);
        const lineIds = db.rows(`SELECT item_id FROM invoice_items WHERE item_invoice_id = ${id} ORDER BY item_id DESC`).map((r) => r.item_id);
        const today = new Date().toISOString().slice(0, 10);
        const tries = [
            ['add a line', 'POST', 'post.php', { billing_item_save: '1', type: 'invoice', doc_id: id, name: 'Smoke 61 sneaked in', description: '', qty: '1', price: '9', tax_id: '0', product_id: '0' }],
            ['edit a line', 'POST', 'post.php', { billing_item_save: '1', type: 'invoice', doc_id: id, item_id: line, name: 'Smoke 61 work', description: '', qty: '5', price: '150', tax_id: '0' }],
            ['AI lines', 'POST', 'post.php', { billing_ai_lines: '1', type: 'invoice', doc_id: id, 'lines[0][include]': '1', 'lines[0][name]': 'Smoke 61 ai', 'lines[0][price]': '5', 'lines[0][qty]': '1' }],
            ['remove a line', 'GET', `post.php?billing_item_delete=${line}&type=invoice&doc_id=${id}`],
            ['remove tax', 'GET', `post.php?billing_remove_tax=${id}&type=invoice`],
            ['edit invoice (discount, dates)', 'POST', 'post.php', { edit_invoice: '1', invoice_id: id, date: today, due: today, category: '0', scope: 'changed', invoice_discount: '25' }],
            ['mark sent', 'POST', 'post.php', { mark_invoice_sent: '1', invoice_id: id, sent_method: 'Hand Delivered' }],
            ['non-billable', 'GET', `post.php?mark_invoice_non-billable=${id}`],
            ['cancel', 'GET', `post.php?cancel_invoice=${id}`],
            ['delete', 'GET', `post.php?delete_invoice=${id}`],
            ['add payment', 'POST', 'post.php', { add_payment: '1', invoice_id: id, date: today, amount: '10', account: '1', payment_method: 'Cash', reference: 'Smoke 61 refused', currency_code: 'USD', balance: '340', email_receipt: '0' }],
            ['bulk payment', 'POST', 'post.php', { add_bulk_payment: '1', client_id: made.client, date: today, amount: '340', balance: '680', account: '1', currency_code: 'USD', payment_method: 'Cash', reference: 'Smoke 61 bulk', email_receipt: '0' }],
            ['email', 'POST', 'post.php', { email_invoice: '1', invoice_id: id, quick_send: '1' }],
            ['unbilled time onto it', 'POST', 'post.php', { unbilled_invoice: '1', client_id: made.client, invoice_id: id, date: today }],
            ['take approval back', 'GET', `post.php?mit_invoice_unapprove=${id}`],
            ['notes in place (ajax)', 'POST', 'ajax.php', { invoice_set_notes: '1', invoice_id: id, note: 'Smoke 61 changed note' }],
            ['line order (ajax)', 'POST', 'ajax.php', { billing_items_order: '1', type: 'invoice', doc_id: id, items: lineIds.join(',') }],
        ];
        const statuses = {};
        if (which === 'control') {
            // The same requests do change an invoice that is in no batch - so the locked ones staying put means the locks work
            for (const label of ['edit a line', 'notes in place (ajax)', 'add a line', 'edit invoice (discount, dates)']) {
                const [, method, url, fields] = tries.find((t) => t[0] === label);
                const was = state(id);
                await db.pagePost(page, url, { csrf_token: token, ...Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, String(v)])) });
                expect(state(id), `control: "${label}" changes an unlocked invoice`).not.toEqual(was);
            }
            return;
        }
        for (const [label, method, url, fields] of tries) {
            const r = method === 'GET' ? await db.pageGet(page, `${url}${url.includes('?') ? '&' : '?'}csrf_token=${encodeURIComponent(token)}`)
                : await db.pagePost(page, url, { csrf_token: token, ...Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, Array.isArray(v) ? v : String(v)])) });
            statuses[label] = r.status;
            const now = state(id);
            expect(now, `after "${label}" (${method} ${url}) the ${which} invoice is unchanged`).toEqual(before);
        }
        expect(statuses['notes in place (ajax)'], 'ajax notes refused with 409').toBe(409);
        expect(Number(db.val(`SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'smoke61@example.invalid'`)), 'nothing emailed').toBe(0);
        // The API
        const api = await page.evaluate(async ({ key, id, client }) => {
            const r = await fetch('/api/v1/invoice_items/create.php', { method: 'POST', headers: { 'X-API-Key': key, 'Content-Type': 'application/json' },
                body: JSON.stringify({ client_id: client, invoice_id: id, name: 'Smoke 61 via API', qty: 1, price: 3 }) });
            return { status: r.status, text: await r.text() };
        }, { key: made.keySecret, id, client: made.client });
        expect(state(id), `API invoice_items/create (${api.text.slice(0, 120)}) changed nothing`).toEqual(before);
    });
}

test('the sync payment follows QuickBooks: no hand edits, no deleting; client and parts blocked', async ({ page }) => {
    const errors = watch(page);
    const docNumber = db.val(`SELECT qbo_doc_number FROM mit_qbo_batch_invoices WHERE invoice_id = ${made.sent}`);
    await fetch(`http://127.0.0.1/qbo-mock/index.php/_pay?doc=${encodeURIComponent(docNumber)}&amount=40`);
    db.php('mitQboPaymentSync();');
    const pay = db.one(`SELECT payment_id, payment_amount FROM payments WHERE payment_invoice_id = ${made.sent}`);
    expect(Number(pay.payment_amount), 'payment back from QuickBooks').toBe(40);
    await visit(page, `agent/invoice.php?invoice_id=${made.sent}`, errors);
    const token = await csrf(page);
    await db.pagePost(page, 'post.php', { csrf_token: token, edit_payment: '1', payment_id: pay.payment_id, date: new Date().toISOString().slice(0, 10), amount: '400', account: '1', payment_method: 'Cash', reference: 'hand edit' });
    await db.pageGet(page, `post.php?delete_payment=${pay.payment_id}&csrf_token=${encodeURIComponent(token)}`);
    const after = db.one(`SELECT payment_amount, payment_method FROM payments WHERE payment_id = ${pay.payment_id}`);
    expect(after, 'sync payment still there').toBeTruthy();
    expect([Number(after.payment_amount), after.payment_method], 'sync payment unchanged').toEqual([40, 'QuickBooks Online']);

    // The client can't be deleted while its invoices are in batches
    await db.pageGet(page, `post.php?delete_client=${made.client}&csrf_token=${encodeURIComponent(token)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM clients WHERE client_id = ${made.client}`)), 'client kept').toBe(1);
    // ... nor through the API (which deletes archived clients only)
    db.exec(`UPDATE clients SET client_archived_at = NOW() WHERE client_id = ${made.client}`);
    await page.evaluate(async ({ key, client }) => {
        await fetch('/api/v1/clients/delete.php', { method: 'POST', headers: { 'X-API-Key': key, 'Content-Type': 'application/json' }, body: JSON.stringify({ client_id: client }) });
    }, { key: made.keySecret, client: made.client });
    expect(Number(db.val(`SELECT COUNT(*) FROM clients WHERE client_id = ${made.client}`)), 'client kept (API)').toBe(1);
    db.exec(`UPDATE clients SET client_archived_at = NULL WHERE client_id = ${made.client}`);

    // Ticket parts can't be billed onto the queued draft
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    made.ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = 'Smoke 61 parts', ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 1, ticket_client_id = ${made.client}, ticket_created_by = 1`);
    const part = db.insert(`INSERT INTO mit_ticket_parts SET ticket_id = ${made.ticket}, client_id = ${made.client}, name = 'Smoke 61 cable', quantity = 1, unit_price = 12, source = 'other'`);
    const before = state(made.queued);
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_parts_bill: '1', ticket_id: String(made.ticket), 'part_ids[]': [String(part)], invoice_id: String(made.queued), date: '', category: '0' });
    expect(state(made.queued), 'queued draft unchanged').toEqual(before);
    expect(Number(db.val(`SELECT invoice_id FROM mit_ticket_parts WHERE part_id = ${part}`)), 'part not billed').toBe(0);
});
