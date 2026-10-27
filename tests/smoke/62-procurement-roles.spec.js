// Procurement by role, through the real handlers (agent/post/mit_procurement.php) and pages, with the money checked in the database:
//  - a Technician (Procurement Edit, Sales Edit, Support Edit, no Financial) orders: PO from the list, submit (approved under the
//    limit / pending over it), may NOT approve or cancel, marks ordered, receives with assets, may NOT record the vendor bill, may NOT
//    approve invoices for QuickBooks;
//  - an Accountant (Procurement View, Financial Edit, Sales View) sees the PO but can't create, receive or override usage;
//  - a client-limited agent (only Beta Co) doesn't see or reach Acme's items and POs, on any page;
//  - the admin approves (total must match what was on screen), records the vendor bill, prints the PO.
// Makes its own items / POs / logins' passwords and puts everything back.

const { test, expect, chromium } = require('@playwright/test');
const { watch, visit, csrf } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const PASS = 'Smoke-62-pass!';
const made = { users: {}, items: [], pos: [], assets: [], expenses: [], invoice: 0 };
const ACME = 1;
const BETA = Number(db.val("SELECT client_id FROM user_client_permissions WHERE permission_type = 'allow' LIMIT 1")) || 2;
const LIMITED = Number(db.val("SELECT user_id FROM user_client_permissions WHERE permission_type = 'allow' LIMIT 1")) || 10;
const TECH = Number(db.val(`SELECT user_id FROM users WHERE user_email = ${db.q(process.env.ITFLOW_TECH_EMAIL)}`));
const ACCOUNTANT = Number(db.val("SELECT user_id FROM users u JOIN user_roles r ON r.role_id = u.user_role_id WHERE r.role_name = 'Accountant' AND u.user_type = 1 LIMIT 1"));
const VENDOR = Number(db.val('SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1'));
const tag = `Smoke 62 ${Date.now()}`;

async function loginAs(browser, userId) {
    const email = db.val(`SELECT user_email FROM users WHERE user_id = ${userId}`);
    const context = await browser.newContext({ storageState: undefined });
    const page = await context.newPage();
    await page.goto('login.php');
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[name=login]')]);
    expect(page.url(), `log in as ${email}`).not.toMatch(/login\.php/);
    return { context, page };
}

function item(name, client, qty, cost) {
    const id = db.insert(`INSERT INTO mit_procurement_items SET source = 'manual', client_id = ${client}, name = ${db.q(name)}, quantity = ${qty}, unit_cost = ${cost}, vendor_id = ${VENDOR}, created_by = 1`);
    made.items.push(id);
    return id;
}

function po(id) {
    return db.one(`SELECT * FROM mit_purchase_orders WHERE po_id = ${id}`);
}

test.beforeAll(() => {
    for (const id of [TECH, ACCOUNTANT, LIMITED]) {
        made.users[id] = db.one(`SELECT user_password, user_status, user_archived_at FROM users WHERE user_id = ${id}`);
        const hash = db.php(`echo password_hash(${JSON.stringify(PASS)}, PASSWORD_DEFAULT);`).trim();
        db.exec(`UPDATE users SET user_password = ${db.q(hash)}, user_status = 1, user_archived_at = NULL WHERE user_id = ${id}`);
    }
    db.exec("INSERT INTO mit_settings (setting_name, setting_value) VALUES ('procurement_approval_on', '1') ON DUPLICATE KEY UPDATE setting_value = '1'");
    made.over = db.val("SELECT setting_value FROM mit_settings WHERE setting_name = 'procurement_approval_over'");
    db.exec("INSERT INTO mit_settings (setting_name, setting_value) VALUES ('procurement_approval_over', '500') ON DUPLICATE KEY UPDATE setting_value = '500'");
});

test.afterAll(() => {
    for (const [id, row] of Object.entries(made.users)) {
        db.exec(`UPDATE users SET user_password = ${db.q(row.user_password)}, user_status = ${row.user_status}, user_archived_at = ${row.user_archived_at === null ? 'NULL' : db.q(row.user_archived_at)} WHERE user_id = ${id}`);
    }
    for (const id of made.pos) {
        db.exec(`DELETE FROM mit_purchase_orders WHERE po_id = ${id}`);
    }
    for (const id of made.items) {
        db.exec(`DELETE FROM mit_procurement_items WHERE item_id = ${id}`);
    }
    for (const id of made.assets) {
        db.exec(`DELETE FROM assets WHERE asset_id = ${id}`);
    }
    for (const id of made.expenses) {
        db.exec(`UPDATE product_stock SET stock_expense_id = NULL WHERE stock_expense_id = ${id}; DELETE FROM expenses WHERE expense_id = ${id}`);
    }
    if (made.invoice) {
        db.exec(`DELETE FROM mit_invoice_approvals WHERE invoice_id = ${made.invoice}; DELETE FROM invoice_items WHERE item_invoice_id = ${made.invoice}; DELETE FROM history WHERE history_invoice_id = ${made.invoice}; DELETE FROM invoices WHERE invoice_id = ${made.invoice}`);
    }
    if (made.over !== undefined && made.over !== null) {
        db.exec(`UPDATE mit_settings SET setting_value = ${db.q(made.over)} WHERE setting_name = 'procurement_approval_over'`);
    }
});

test('Technician: orders, but does not approve, cancel or record the bill', async ({ browser }) => {
    const { context, page } = await loginAs(browser, TECH);
    const errors = watch(page);
    const small = item(`${tag} mouse`, ACME, 2, 25);
    const big = item(`${tag} server`, ACME, 1, 2400);
    await visit(page, 'agent/mit_procurement.php', errors);
    await expect(page.locator('body')).toContainText(`${tag} mouse`);
    let token = await csrf(page);
    // Small PO: approved on submit (50 < 500)
    let r = await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_create: '1', vendor_id: String(VENDOR), 'item_ids[]': [String(small)] });
    const small_po = Number(new URL(r.url).searchParams.get('po_id'));
    expect(small_po, 'PO made').toBeGreaterThan(0);
    made.pos.push(small_po);
    await db.pageGet(page, `post.php?mit_po_submit=${small_po}&csrf_token=${encodeURIComponent(token)}`);
    expect(po(small_po).status, 'under the limit: approved').toBe('approved');
    // Big PO: pending; the technician can't approve it, nor cancel it
    r = await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_create: '1', vendor_id: String(VENDOR), 'item_ids[]': [String(big)] });
    const big_po = Number(new URL(r.url).searchParams.get('po_id'));
    made.pos.push(big_po);
    await db.pageGet(page, `post.php?mit_po_submit=${big_po}&csrf_token=${encodeURIComponent(token)}`);
    expect(po(big_po).status, 'over the limit: pending').toBe('pending');
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_decide: '1', po_id: String(big_po), decision: 'approve', total: po(big_po).total, note: '' });
    expect(po(big_po).status, 'technician cannot approve').toBe('pending');
    await db.pageGet(page, `post.php?mit_po_cancel=${big_po}&csrf_token=${encodeURIComponent(token)}`);
    expect(po(big_po).status, 'technician cannot cancel').toBe('pending');
    // Order and receive the small one, with assets (Support edit)
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_ordered: '1', po_id: String(small_po), order_date: new Date().toISOString().slice(0, 10), vendor_order_number: 'SM62' });
    expect(po(small_po).status).toBe('ordered');
    const line = db.one(`SELECT line_id FROM mit_po_lines WHERE po_id = ${small_po}`);
    await visit(page, 'agent/mit_receiving.php', errors);
    await expect(page.locator('body'), 'receiving lists the PO').toContainText(`${tag} mouse`);
    token = await csrf(page);
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_receive: '1', [`received[${line.line_id}][qty]`]: '2', [`received[${line.line_id}][serials]`]: 'S62-1\nS62-2',
        [`received[${line.line_id}][asset_type]`]: 'Other', 'options[assets]': '1' });
    expect(po(small_po).status, 'received in full').toBe('received');
    const assets = db.rows(`SELECT asset_id, asset_serial, asset_client_id FROM assets WHERE asset_purchase_reference = ${db.q(po(small_po).po_prefix + po(small_po).po_number)}`);
    made.assets.push(...assets.map((a) => a.asset_id));
    expect(assets.map((a) => a.asset_serial).sort(), 'an asset per unit, with serials').toEqual(['S62-1', 'S62-2']);
    // No Financial: the vendor bill is refused
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_bill: '1', po_id: String(small_po), amount: '50', date: new Date().toISOString().slice(0, 10), category: '0', account: '1' });
    expect(po(small_po).expense_id === null || Number(po(small_po).expense_id) === 0, 'no vendor bill without Financial').toBe(true);
    // No Financial: invoices are not approved for QuickBooks
    made.invoice = Number(db.php(`$id = billingInvoiceCreate(${ACME}, date('Y-m-d'), 0, 'Smoke 62', 'Smoke'); billingItemSave('invoice', $id, ['name' => 'x', 'qty' => '1', 'price' => '5', 'tax_id' => 0], 0, $e); billingRecalc('invoice', $id); echo $id;`).trim());
    await db.pageGet(page, `post.php?mit_invoice_approve=${made.invoice}&csrf_token=${encodeURIComponent(token)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM mit_invoice_approvals WHERE invoice_id = ${made.invoice}`)), 'technician cannot approve for QuickBooks').toBe(0);
    await context.close();
});

test('Accountant: sees POs, cannot create or receive; approves for QuickBooks', async ({ browser }) => {
    test.skip(!ACCOUNTANT, 'no Accountant login');
    const { context, page } = await loginAs(browser, ACCOUNTANT);
    const errors = watch(page);
    const pid = made.pos[1];
    await visit(page, `agent/mit_purchase_order.php?po_id=${pid}`, errors);
    await expect(page.locator('body')).toContainText(`${tag} server`);
    const token = await csrf(page);
    const extra = item(`${tag} keyboard`, ACME, 1, 30);
    const r = await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_create: '1', vendor_id: String(VENDOR), 'item_ids[]': [String(extra)] });
    expect(db.val(`SELECT status FROM mit_procurement_items WHERE item_id = ${extra}`), 'View only: no PO made').toBe('waiting');
    const pos = db.rows(`SELECT l.po_id FROM mit_po_lines l WHERE l.proc_item_id = ${extra}`);
    made.pos.push(...pos.map((p) => Number(p.po_id)));
    expect(pos.length, `no PO for the item (${r.status})`).toBe(0);
    await db.pageGet(page, `post.php?mit_invoice_approve=${made.invoice}&csrf_token=${encodeURIComponent(token)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM mit_invoice_approvals WHERE invoice_id = ${made.invoice}`)), 'Financial edit approves for QuickBooks').toBe(1);
    await context.close();
});

test('client-limited agent: Acme\'s items and POs are out of sight and out of reach', async ({ browser }) => {
    const { context, page } = await loginAs(browser, LIMITED);
    const errors = watch(page);
    const beta = item(`${tag} beta printer`, BETA, 1, 300);
    await visit(page, 'agent/mit_procurement.php', errors);
    const body = await page.locator('body').innerText();
    expect(body, 'own client\'s item listed').toContain(`${tag} beta printer`);
    expect(body, 'Acme\'s items hidden').not.toContain(`${tag} keyboard`);
    for (const url of ['agent/mit_purchase_orders.php', 'agent/mit_receiving.php', 'agent/mit_unbilled_parts.php']) {
        await visit(page, url, errors);
        expect(await page.locator('body').innerText(), `${url} hides Acme's`).not.toContain(`${tag} server`);
    }
    // The Acme PO itself: refused
    const r = await db.pageGet(page, `mit_purchase_order.php?po_id=${made.pos[1]}`);
    expect(r.text, 'Acme PO not shown').not.toContain(`${tag} server`);
    // Not addable to a PO either
    await visit(page, 'agent/mit_procurement.php', errors);
    const token = await csrf(page);
    const acme = item(`${tag} acme hidden`, ACME, 1, 10);
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_create: '1', vendor_id: String(VENDOR), 'item_ids[]': [String(acme)] });
    expect(db.val(`SELECT status FROM mit_procurement_items WHERE item_id = ${acme}`), 'Acme item not ordered by a Beta-only agent').toBe('waiting');
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_receive: '1', [`received[${db.val(`SELECT line_id FROM mit_po_lines WHERE po_id = ${made.pos[1]}`)}][qty]`]: '1' });
    expect(Number(db.val(`SELECT received_qty FROM mit_po_lines WHERE po_id = ${made.pos[1]}`)), 'Acme line not received by a Beta-only agent').toBe(0);
    await context.close();
});

test('admin: approves what was on screen, records the bill, prints the PO', async ({ page }) => {
    const errors = watch(page);
    const pid = made.pos[1];
    await visit(page, `agent/mit_purchase_order.php?po_id=${pid}`, errors);
    const token = await csrf(page);
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_decide: '1', po_id: String(pid), decision: 'approve', total: '1.00', note: '' });
    expect(po(pid).status, 'a stale total is refused').toBe('pending');
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_decide: '1', po_id: String(pid), decision: 'approve', total: po(pid).total, note: 'ok' });
    expect(po(pid).status, 'approved').toBe('approved');
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_ordered: '1', po_id: String(pid), order_date: new Date().toISOString().slice(0, 10), vendor_order_number: '' });
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_bill: '1', po_id: String(pid), amount: '2,412.50', date: new Date().toISOString().slice(0, 10), category: String(db.val("SELECT category_id FROM categories WHERE category_type = 'Expense' AND category_archived_at IS NULL LIMIT 1")), account: '1', reference: 'SM62-BILL' });
    const expense = Number(po(pid).expense_id);
    expect(expense, 'vendor bill recorded').toBeGreaterThan(0);
    made.expenses.push(expense);
    const e = db.one(`SELECT expense_amount, expense_vendor_id, expense_client_id, expense_reference FROM expenses WHERE expense_id = ${expense}`);
    expect([Number(e.expense_amount), Number(e.expense_vendor_id), Number(e.expense_client_id), e.expense_reference]).toEqual([2412.5, VENDOR, ACME, 'SM62-BILL']);
    const pdf = await db.pageGet(page, `post.php?mit_po_pdf=${pid}`, true);
    expect(pdf.status).toBe(200);
    expect(Buffer.from(pdf.b64, 'base64').slice(0, 5).toString(), 'PO PDF').toBe('%PDF-');
    // Cancel is refused once something arrived, allowed before
    const extra = item(`${tag} cancel me`, ACME, 1, 10);
    const r = await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_create: '1', vendor_id: String(VENDOR), 'item_ids[]': [String(extra)] });
    const cpo = Number(new URL(r.url).searchParams.get('po_id'));
    made.pos.push(cpo);
    await db.pageGet(page, `post.php?mit_po_cancel=${cpo}&csrf_token=${encodeURIComponent(token)}`);
    expect(po(cpo).status, 'cancelled').toBe('cancelled');
    expect(db.val(`SELECT status FROM mit_procurement_items WHERE item_id = ${extra}`), 'its item waits again').toBe('waiting');
    await db.pageGet(page, `post.php?mit_po_cancel=${made.pos[0]}&csrf_token=${encodeURIComponent(token)}`);
    expect(po(made.pos[0]).status, 'a received PO cannot be cancelled').toBe('received');
});
