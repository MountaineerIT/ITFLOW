// QuickBooks Online locks on the rest of ITFlow's doors into an invoice (61-qbo-guards tries the main ones): for each door, the same
// request is made against an invoice in no batch - it must change it, so the request is a real one - and against one waiting in a
// QuickBooks batch, which must come out exactly as it was. (Apply credit is left out: ITFlow's handler can't apply one yet - see
// scripts/mit_hook_proof_accepted.txt.) Each run gets its own client, invoice and whatever the door bills (a
// resolved billable ticket, an expense, a trip), so the doors can't see each other's. All of it is removed at the end.
// Uses the stand-in at http://127.0.0.1/qbo-mock (connected for the test, disconnected again if it wasn't).

const { test, expect } = require('@playwright/test');
const { visit, csrf } = require('./helpers');
const db = require('./mitdb');

const stamp = Date.now();
const made = { clients: [], taxId: 0, wasConnected: false, batches: [] };
const today = new Date().toISOString().slice(0, 10);
const VENDOR = Number(db.val('SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1') || 0);
const SENT_METHOD = db.php('echo getSentMethods()[0];').trim();

/** Everything a door could change on an invoice. */
function state(id) {
    return JSON.parse(db.php(`echo json_encode(['fp' => mitInvoiceFingerprint(${id}), 'status' => getFieldById('invoices', ${id}, 'invoice_status'),
        'amount' => getFieldById('invoices', ${id}, 'invoice_amount'), 'credit' => getFieldById('invoices', ${id}, 'invoice_credit_amount'),
        'payments' => intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM payments WHERE payment_invoice_id = ${id}"))[0]),
        'lines' => mysqli_fetch_all(mysqli_query($mysqli, "SELECT item_id, item_name, item_quantity, item_price, item_tax_id, item_order FROM invoice_items WHERE item_invoice_id = ${id} ORDER BY item_id")),
        'billed' => mysqli_fetch_row(mysqli_query($mysqli, "SELECT (SELECT COUNT(*) FROM tickets WHERE ticket_invoice_id = ${id}) + (SELECT COUNT(*) FROM expenses WHERE expense_invoice_id = ${id})
            + (SELECT COUNT(*) FROM trips WHERE trip_invoice_id = ${id})"))[0],
        'emails' => mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM email_queue JOIN contacts ON contact_email = email_recipient JOIN invoices ON invoice_client_id = contact_client_id
            WHERE invoice_id = ${id}"))[0]]);`));
}

/** A client with a billing contact, and a draft invoice with one taxed line - locked (approved, in a batch waiting to send) or not. */
function invoice(locked) {
    const client = db.insert(`INSERT INTO clients SET client_name = 'Smoke 66 ${stamp} ${made.clients.length + 1}', client_net_terms = 30, client_currency_code = 'USD'`);
    made.clients.push(client);
    db.exec(`INSERT INTO contacts SET contact_name = 'Smoke 66 billing', contact_email = 'smoke66-${client}@example.invalid', contact_billing = 1, contact_primary = 1, contact_client_id = ${client}`);
    const out = db.php(`
        $id = billingInvoiceCreate(${client}, date('Y-m-d'), 0, 'Smoke 66', 'Smoke');
        billingItemSave('invoice', $id, ['name' => 'Smoke 66 work', 'qty' => '2', 'price' => '100', 'tax_id' => ${made.taxId}], 0, $e);
        billingItemSave('invoice', $id, ['name' => 'Smoke 66 part', 'qty' => '1', 'price' => '40', 'tax_id' => ${made.taxId}], 0, $e);
        billingRecalc('invoice', $id);
        if (${locked ? 1 : 0}) { mitInvoiceApprove([$id]); $b = mitQboBatchCreate('Smoke 66 ' . $id, false); mitQboBatchAdd($b, [$id]); echo "B$b\\n"; }
        echo $id;`).trim().split('\n');
    const batch = out.find((l) => l.startsWith('B'));
    if (batch) {
        made.batches.push(Number(batch.slice(1)));
    }
    return { client, id: Number(out.pop()) };
}

function ticket(client, hours = '01:30:00') {
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    const id = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = 'Smoke 66 billable', ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 4, ticket_resolved_at = NOW(), ticket_billable = 1, ticket_client_id = ${client}, ticket_created_by = 1`);
    db.exec(`INSERT INTO ticket_replies SET ticket_reply = 'Smoke 66 work', ticket_reply_type = 'Internal', ticket_reply_time_worked = '${hours}', ticket_reply_by = 1, ticket_reply_ticket_id = ${id}`);
    return id;
}
function expense(client) {
    return db.insert(`INSERT INTO expenses SET expense_description = 'Smoke 66 cable', expense_amount = 30, expense_currency_code = 'USD', expense_date = CURDATE(),
        expense_client_id = ${client}, expense_billable = 1, expense_vendor_id = ${VENDOR}, expense_category_id = 0, expense_account_id = 0`);
}
function trip(client) {
    return db.insert(`INSERT INTO trips SET trip_date = CURDATE(), trip_purpose = 'Smoke 66 visit', trip_source = 'Office', trip_destination = 'Client', trip_miles = 20, round_trip = 0,
        trip_billable = 1, trip_client_id = ${client}, trip_user_id = 1, trip_created_by = 1`);
}

// [door, what it bills (made before the invoice is locked when it must already be on it), the request]
const doors = [
    ['remove tax', null, (inv) => ['GET', `post.php?billing_remove_tax=${inv.id}&type=invoice`]],
    ['mark sent', null, (inv) => ['POST', 'post.php', { mark_invoice_sent: '1', invoice_id: inv.id, sent_method: SENT_METHOD }]],
    // While connected QuickBooks emails every invoice; after a disconnect ITFlow does again, except those still in a batch
    ['email (after a disconnect)', null, (inv) => ['POST', 'post.php', { email_invoice: '1', invoice_id: inv.id, quick_send: '1' }], { disconnected: true }],
    ['line order (ajax)', null, (inv) => ['POST', 'ajax.php', { billing_items_order: '1', type: 'invoice', doc_id: inv.id,
        items: db.rows(`SELECT item_id FROM invoice_items WHERE item_invoice_id = ${inv.id} ORDER BY item_id DESC`).map((r) => r.item_id).join(',') }]],
    ['unbilled time onto it', (inv) => { inv.ticket = ticket(inv.client); },
        (inv) => ['POST', 'post.php', { unbilled_invoice: '1', client_id: inv.client, invoice_id: inv.id, 'ticket_ids[]': [String(inv.ticket)], date: today }]],
    ['unbilled time, by client, onto the draft', (inv) => { inv.ticket = ticket(inv.client); },
        (inv) => ['POST', 'post.php', { unbilled_invoice_clients: '1', target: 'draft', 'client_ids[]': [String(inv.client)], date: today }]],
    ['a ticket onto it (invoice page)', (inv) => { inv.ticket = ticket(inv.client); },
        (inv) => ['POST', 'post.php', { add_ticket_to_invoice: '1', invoice_id: inv.id, 'ticket_ids[]': [String(inv.ticket)], bill_time: '1' }]],
    ['a ticket onto it (ticket page)', (inv) => { inv.ticket = ticket(inv.client); },
        (inv) => ['POST', 'post.php', { add_invoice_from_ticket: '1', ticket_id: inv.ticket, invoice_id: inv.id, qty: '1.5', date: today, item_name: 'Smoke 66 support' }]],
    ['expenses onto it', (inv) => { inv.expense = expense(inv.client); },
        (inv) => ['POST', 'post.php', { invoice_add_expenses: '1', invoice_id: inv.id, 'expense_ids[]': [String(inv.expense)], [`expense_price[${inv.expense}]`]: '35' }]],
    ['an expense taken off', (inv) => { inv.expense = expense(inv.client); db.php(`expenseBillInvoice(${inv.client}, [${inv.expense} => '35'], ['invoice_id' => ${inv.id}], $e); billingRecalc('invoice', ${inv.id});`); },
        (inv) => ['GET', `post.php?expense_unbill=${inv.expense}`]],
    ['trips onto it', (inv) => { inv.trip = trip(inv.client); },
        (inv) => ['POST', 'post.php', { invoice_add_trips: '1', invoice_id: inv.id, 'trip_ids[]': [String(inv.trip)], [`trip_price[${inv.trip}]`]: '25' }]],
    ['a trip taken off', (inv) => { inv.trip = trip(inv.client); db.php(`tripBillInvoice(${inv.client}, [${inv.trip} => '25'], ['invoice_id' => ${inv.id}], $e); billingRecalc('invoice', ${inv.id});`); },
        (inv) => ['GET', `post.php?trip_unbill=${inv.trip}`]],
];

test.beforeAll(async () => {
    made.wasConnected = db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'qbo_refresh_token'") ? true : false;
    const mock = await fetch('http://127.0.0.1/qbo-mock/index.php/_reset').catch(() => null);
    test.skip(!mock || !mock.ok, 'no QuickBooks stand-in');
    db.php(`
        if (mitDecryptSecret((string) mitSetting('qbo_client_secret', '')) !== 'qbo-secret') { mitSetSetting('qbo_client_id', 'qbo-client'); mitSetSetting('qbo_client_secret', mitEncryptSecret('qbo-secret')); }
        mitSetSetting('qbo_env', 'sandbox');
        mitQboConnect('code-smoke66', '9130355');`);
    made.taxId = db.insert(`INSERT INTO taxes SET tax_name = 'Smoke 66 tax', tax_percent = 6`);
});

test.afterAll(async () => {
    for (const client of made.clients) {
        db.exec(`DELETE FROM mit_qbo_batch_invoices WHERE invoice_id IN (SELECT invoice_id FROM invoices WHERE invoice_client_id = ${client});
            DELETE FROM mit_invoice_approvals WHERE invoice_id IN (SELECT invoice_id FROM invoices WHERE invoice_client_id = ${client});
            DELETE FROM invoice_items WHERE item_invoice_id IN (SELECT invoice_id FROM invoices WHERE invoice_client_id = ${client});
            DELETE FROM history WHERE history_invoice_id IN (SELECT invoice_id FROM invoices WHERE invoice_client_id = ${client});
            DELETE FROM payments WHERE payment_invoice_id IN (SELECT invoice_id FROM invoices WHERE invoice_client_id = ${client});
            DELETE FROM invoices WHERE invoice_client_id = ${client}; DELETE FROM credits WHERE credit_client_id = ${client};
            DELETE FROM ticket_replies WHERE ticket_reply_ticket_id IN (SELECT ticket_id FROM tickets WHERE ticket_client_id = ${client});
            DELETE FROM ticket_history WHERE ticket_history_ticket_id IN (SELECT ticket_id FROM tickets WHERE ticket_client_id = ${client});
            DELETE FROM tickets WHERE ticket_client_id = ${client}; DELETE FROM expenses WHERE expense_client_id = ${client}; DELETE FROM trips WHERE trip_client_id = ${client};
            DELETE FROM mit_qbo_customers WHERE client_id = ${client}; DELETE FROM contacts WHERE contact_client_id = ${client}; DELETE FROM clients WHERE client_id = ${client}`);
    }
    for (const id of made.batches) {
        db.exec(`DELETE FROM mit_qbo_batches WHERE batch_id = ${id}`);
    }
    if (made.taxId) {
        db.exec(`DELETE FROM taxes WHERE tax_id = ${made.taxId}`);
    }
    if (!made.wasConnected) {
        db.php('mitQboDisconnect();');
    }
    await fetch('http://127.0.0.1/qbo-mock/index.php/_reset').catch(() => null);
});

for (const [label, material, request, opts = {}] of doors) {
    test(`"${label}": changes an invoice in no batch, not one waiting in a QuickBooks batch`, async ({ page }) => {
        await visit(page, 'agent/invoices.php');
        const token = await csrf(page);
        const send = async (inv) => {
            const [method, url, fields] = request(inv);
            const base = '/agent/';
            return method === 'GET' ? db.pageGet(page, `${base}${url}${url.includes('?') ? '&' : '?'}csrf_token=${encodeURIComponent(token)}`)
                : db.pagePost(page, `${base}${url}`, { csrf_token: token, ...Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, Array.isArray(v) ? v : String(v)])) });
        };
        for (const locked of [false, true]) {
            // Material that must already be on the invoice is put on before it is locked (approved and put in a batch)
            const target = invoice(false);
            if (material) {
                material(target);
            }
            if (locked) {
                db.php(`billingRecalc('invoice', ${target.id}); mitInvoiceApprove([${target.id}]); $b = mitQboBatchCreate('Smoke 66 ${label}', false); mitQboBatchAdd($b, [${target.id}]); echo $b;`)
                    .trim().split('\n').forEach((l) => /^\d+$/.test(l) && made.batches.push(Number(l)));
                expect(db.val(`SELECT entry_status FROM mit_qbo_batch_invoices WHERE invoice_id = ${target.id}`), 'locked: waiting in a batch').toBe('queued');
            }
            if (opts.disconnected) {
                db.php('mitQboDisconnect();');
            }
            const before = state(target.id);
            const r = await send(target);
            const after = state(target.id);
            if (opts.disconnected) {
                db.php("mitQboConnect('code-smoke66', '9130355');");
            }
            if (locked) {
                expect(after, `"${label}" left the invoice in the batch alone (${r.status})`).toEqual(before);
            } else {
                expect(after, `"${label}" changes an invoice in no batch - the request is a real one`).not.toEqual(before);
            }
        }
    });
}

test('the invoices list marks an invoice in QuickBooks', async ({ page }) => {
    const inv = invoice(false);
    db.php(`mitInvoiceApprove([${inv.id}]); $b = mitQboBatchCreate('Smoke 66 list', false); mitQboBatchAdd($b, [${inv.id}]); echo $b;`)
        .trim().split('\n').forEach((l) => /^\d+$/.test(l) && made.batches.push(Number(l)));
    await visit(page, `agent/invoices.php?client_id=${inv.client}`);
    const row = page.locator(`tr:has(a[href*="invoice_id=${inv.id}"])`).first();
    await expect(row.locator('[data-mit-qbo-badge], .badge', { hasText: /QBO|Batched/ }), 'the QuickBooks badge by the number').toHaveCount(1);
});
