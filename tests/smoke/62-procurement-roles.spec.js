// Procurement by role on ITFlow 26.12's Purchasing (agent/post/purchase_order.php - ITFlow's handlers, with our hooks on them), the money
// checked in the database:
//  - a Technician (Procurement Edit, Sales Edit, Support Edit, no Financial) orders: PO from To Order, under the limit ordered straight
//    away / over it sent for approval, may NOT approve or cancel past a draft, marks ordered, receives with assets, may NOT record the
//    vendor bill, may NOT approve invoices for QuickBooks;
//  - an Accountant (Procurement View, Financial Edit, Sales View) sees the PO but can't create or receive;
//  - a client-limited agent (only Beta Co) doesn't see or reach Acme's items and POs, on any page;
//  - the admin approves (our hook: the total must match what was on screen), records the vendor bill, prints the PO.
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
const today = () => new Date().toISOString().slice(0, 10);

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
    const id = db.insert(`INSERT INTO order_items SET order_item_source = 'manual', order_item_client_id = ${client}, order_item_name = ${db.q(name)}, order_item_quantity = ${qty},
        order_item_cost = ${cost}, order_item_vendor_id = ${VENDOR}, order_item_status = 'waiting', order_item_created_by = 1`);
    made.items.push(id);
    return id;
}

function po(id) {
    // Not *: po_ship_to holds line breaks, which the mysql client's rows don't survive
    return db.one(`SELECT po_id, po_prefix, po_number, po_status, po_total, IFNULL(po_expense_id, 0) AS po_expense_id FROM purchase_orders WHERE po_id = ${id}`);
}

// po_create redirects to the new PO's page - its id from the address
async function create(page, token, itemId) {
    const r = await db.pagePost(page, 'post.php', { csrf_token: token, po_create: '1', vendor: String(VENDOR), 'order_item_ids[]': [String(itemId)] });
    const id = Number(new URL(r.url).searchParams.get('po_id')) || 0;
    if (id) {
        made.pos.push(id);
    }
    return id;
}

test.beforeAll(() => {
    for (const id of [TECH, ACCOUNTANT, LIMITED]) {
        made.users[id] = db.one(`SELECT user_password, user_status, user_archived_at FROM users WHERE user_id = ${id}`);
        const hash = db.php(`echo password_hash(${JSON.stringify(PASS)}, PASSWORD_DEFAULT);`).trim();
        db.exec(`UPDATE users SET user_password = ${db.q(hash)}, user_status = 1, user_archived_at = NULL WHERE user_id = ${id}`);
    }
    made.approval = db.one('SELECT config_po_approval, config_po_approval_over FROM settings WHERE company_id = 1');
    db.exec('UPDATE settings SET config_po_approval = 1, config_po_approval_over = 500 WHERE company_id = 1');
});

test.afterAll(() => {
    for (const [id, row] of Object.entries(made.users)) {
        db.exec(`UPDATE users SET user_password = ${db.q(row.user_password)}, user_status = ${row.user_status}, user_archived_at = ${row.user_archived_at === null ? 'NULL' : db.q(row.user_archived_at)} WHERE user_id = ${id}`);
    }
    for (const id of made.pos) {
        db.exec(`DELETE FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = ${id}; DELETE FROM notifications WHERE notification_type = 'Purchase Order' AND notification_entity_id = ${id};
            DELETE FROM purchase_orders WHERE po_id = ${id}`);
    }
    for (const id of made.items) {
        db.exec(`DELETE FROM order_items WHERE order_item_id = ${id}`);
    }
    for (const id of made.assets) {
        db.exec(`DELETE FROM asset_interfaces WHERE interface_asset_id = ${id}; DELETE FROM asset_history WHERE asset_history_asset_id = ${id}; DELETE FROM assets WHERE asset_id = ${id}`);
    }
    for (const id of made.expenses) {
        db.exec(`UPDATE product_stock SET stock_expense_id = NULL WHERE stock_expense_id = ${id}; DELETE FROM expenses WHERE expense_id = ${id}`);
    }
    if (made.invoice) {
        db.exec(`DELETE FROM mit_invoice_approvals WHERE invoice_id = ${made.invoice}; DELETE FROM invoice_items WHERE item_invoice_id = ${made.invoice}; DELETE FROM history WHERE history_invoice_id = ${made.invoice}; DELETE FROM invoices WHERE invoice_id = ${made.invoice}`);
    }
    if (made.approval) {
        db.exec(`UPDATE settings SET config_po_approval = ${Number(made.approval.config_po_approval)}, config_po_approval_over = ${Number(made.approval.config_po_approval_over)} WHERE company_id = 1`);
    }
});

test('Technician: orders, but does not approve, cancel or record the bill', async ({ browser }) => {
    const { context, page } = await loginAs(browser, TECH);
    const errors = watch(page);
    const small = item(`${tag} mouse`, ACME, 2, 25);
    const big = item(`${tag} server`, ACME, 1, 2400);
    await visit(page, 'agent/to_order.php', errors);
    await expect(page.locator('body')).toContainText(`${tag} mouse`);
    let token = await csrf(page);
    // Small PO: no approval needed (50 < 500) - marked ordered straight from the draft
    const small_po = await create(page, token, small);
    expect(small_po, 'PO made').toBeGreaterThan(0);
    await db.pagePost(page, 'post.php', { csrf_token: token, po_mark_ordered: '1', po_id: String(small_po), order_date: today(), vendor_reference: 'SM62' });
    expect(po(small_po).po_status, 'under the limit: ordered without approval').toBe('ordered');
    // Big PO: pending; the technician can't approve it, nor cancel it
    const big_po = await create(page, token, big);
    await db.pagePost(page, 'post.php', { csrf_token: token, po_send_approval: '1', po_id: String(big_po) });
    expect(po(big_po).po_status, 'over the limit: pending').toBe('pending');
    await db.pagePost(page, 'post.php', { csrf_token: token, po_decide: '1', po_id: String(big_po), approve: '1', mit_seen_total: po(big_po).po_total, note: '' });
    expect(po(big_po).po_status, 'technician cannot approve').toBe('pending');
    await db.pageGet(page, `post.php?po_cancel=${big_po}&csrf_token=${encodeURIComponent(token)}`);
    expect(po(big_po).po_status, 'technician cannot cancel').toBe('pending');
    // Receive the small one, with assets (Support edit)
    const line = db.one(`SELECT po_item_id FROM po_items WHERE po_item_po_id = ${small_po}`);
    await visit(page, 'agent/receiving.php', errors);
    await expect(page.locator('body'), 'receiving lists the PO').toContainText(`${tag} mouse`);
    await expect(page.locator('[data-mit-review]'), 'our "Fill in each asset\'s details" tick').toHaveCount(1);
    token = await csrf(page);
    await db.pagePost(page, 'post.php', { csrf_token: token, po_receive: '1', [`received[${line.po_item_id}][qty]`]: '2', [`received[${line.po_item_id}][serials]`]: 'S62-1\nS62-2',
        [`received[${line.po_item_id}][asset_type]`]: 'Other' });
    expect(po(small_po).po_status, 'received in full').toBe('received');
    const assets = db.rows(`SELECT asset_id, asset_serial, asset_client_id FROM assets WHERE asset_purchase_reference = ${db.q(po(small_po).po_prefix + po(small_po).po_number)}`);
    made.assets.push(...assets.map((a) => a.asset_id));
    expect(assets.map((a) => a.asset_serial).sort(), 'an asset per unit, with serials').toEqual(['S62-1', 'S62-2']);
    // No Financial: the vendor bill is refused
    await db.pagePost(page, 'post.php', { csrf_token: token, po_bill: '1', po_id: String(small_po), amount: '50', date: today(), category: '0', account: '1' });
    expect(Number(po(small_po).po_expense_id), 'no vendor bill without Financial').toBe(0);
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
    await visit(page, `agent/purchase_order.php?po_id=${pid}`, errors);
    await expect(page.locator('body')).toContainText(`${tag} server`);
    const token = await csrf(page);
    const extra = item(`${tag} keyboard`, ACME, 1, 30);
    await create(page, token, extra);
    expect(db.val(`SELECT order_item_status FROM order_items WHERE order_item_id = ${extra}`), 'View only: no PO made').toBe('waiting');
    expect(Number(db.val(`SELECT COUNT(*) FROM po_items WHERE po_item_order_item_id = ${extra}`)), 'no PO line for the item').toBe(0);
    await db.pageGet(page, `post.php?mit_invoice_approve=${made.invoice}&csrf_token=${encodeURIComponent(token)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM mit_invoice_approvals WHERE invoice_id = ${made.invoice}`)), 'Financial edit approves for QuickBooks').toBe(1);
    await context.close();
});

test('client-limited agent: Acme\'s items and POs are out of sight and out of reach', async ({ browser }) => {
    const { context, page } = await loginAs(browser, LIMITED);
    const errors = watch(page);
    item(`${tag} beta printer`, BETA, 1, 300);
    await visit(page, 'agent/to_order.php', errors);
    const body = await page.locator('body').innerText();
    expect(body, 'own client\'s item listed').toContain(`${tag} beta printer`);
    expect(body, 'Acme\'s items hidden').not.toContain(`${tag} keyboard`);
    for (const url of ['agent/purchase_orders.php', 'agent/receiving.php', 'agent/unbilled_time.php']) {
        await visit(page, url, errors);
        expect(await page.locator('body').innerText(), `${url} hides Acme's`).not.toContain(`${tag} server`);
    }
    // The Acme PO itself: refused
    const r = await db.pageGet(page, `purchase_order.php?po_id=${made.pos[1]}`);
    expect(r.text, 'Acme PO not shown').not.toContain(`${tag} server`);
    // Not addable to a PO either
    await visit(page, 'agent/to_order.php', errors);
    const token = await csrf(page);
    const acme = item(`${tag} acme hidden`, ACME, 1, 10);
    await create(page, token, acme);
    expect(db.val(`SELECT order_item_status FROM order_items WHERE order_item_id = ${acme}`), 'Acme item not ordered by a Beta-only agent').toBe('waiting');
    await db.pagePost(page, 'post.php', { csrf_token: token, po_receive: '1', [`received[${db.val(`SELECT po_item_id FROM po_items WHERE po_item_po_id = ${made.pos[0]}`)}][qty]`]: '1' });
    expect(Number(db.val(`SELECT po_item_received FROM po_items WHERE po_item_po_id = ${made.pos[1]}`)), 'Acme line not received by a Beta-only agent').toBe(0);
    await context.close();
});

test('admin: approves what was on screen, records the bill, prints the PO', async ({ page }) => {
    const errors = watch(page);
    const pid = made.pos[1];
    await visit(page, `agent/purchase_order.php?po_id=${pid}`, errors);
    const token = await csrf(page);
    // The Approve window carries the total the approver sees (mitPoSeenTotalField), so a PO changed meanwhile is refused (the window comes as JSON)
    const decide = (await db.pageGet(page, `modals/purchasing/po_decide.php?po_id=${pid}&approve=1`)).text.replace(/\\"/g, '"');
    expect(decide, 'the Approve window carries the total shown').toContain(`name="mit_seen_total" value="${Number(po(pid).po_total).toFixed(2)}"`);
    await db.pagePost(page, 'post.php', { csrf_token: token, po_decide: '1', po_id: String(pid), approve: '1', mit_seen_total: '1.00', note: '' });
    expect(po(pid).po_status, 'a stale total is refused').toBe('pending');
    await db.pagePost(page, 'post.php', { csrf_token: token, po_decide: '1', po_id: String(pid), approve: '1', mit_seen_total: po(pid).po_total, note: 'ok' });
    expect(po(pid).po_status, 'approved').toBe('approved');
    await db.pagePost(page, 'post.php', { csrf_token: token, po_mark_ordered: '1', po_id: String(pid), order_date: today(), vendor_reference: '' });
    await db.pagePost(page, 'post.php', { csrf_token: token, po_bill: '1', po_id: String(pid), amount: '2,412.50', date: today(), category: String(db.val("SELECT category_id FROM categories WHERE category_type = 'Expense' AND category_archived_at IS NULL LIMIT 1")), account: '1', reference: 'SM62-BILL' });
    const expense = Number(po(pid).po_expense_id);
    expect(expense, 'vendor bill recorded').toBeGreaterThan(0);
    made.expenses.push(expense);
    const e = db.one(`SELECT expense_amount, expense_vendor_id, expense_client_id, expense_reference FROM expenses WHERE expense_id = ${expense}`);
    expect([Number(e.expense_amount), Number(e.expense_vendor_id), Number(e.expense_client_id), e.expense_reference]).toEqual([2412.5, VENDOR, ACME, 'SM62-BILL']);
    const pdf = await db.pageGet(page, `post.php?po_pdf=${pid}&csrf_token=${encodeURIComponent(token)}`, true);
    expect(pdf.status).toBe(200);
    expect(Buffer.from(pdf.b64, 'base64').slice(0, 5).toString(), 'PO PDF').toBe('%PDF-');
    // Cancel is allowed before anything arrived, refused after
    const extra = item(`${tag} cancel me`, ACME, 1, 10);
    const cpo = await create(page, token, extra);
    await db.pageGet(page, `post.php?po_cancel=${cpo}&csrf_token=${encodeURIComponent(token)}`);
    expect(po(cpo).po_status, 'cancelled').toBe('cancelled');
    expect(db.val(`SELECT order_item_status FROM order_items WHERE order_item_id = ${extra}`), 'its item waits again').toBe('waiting');
    await db.pageGet(page, `post.php?po_cancel=${made.pos[0]}&csrf_token=${encodeURIComponent(token)}`);
    expect(po(made.pos[0]).po_status, 'a received PO cannot be cancelled').toBe('received');
});

// One receive at a time (mitProcLock('receive') at the start of ITFlow's po_receive - a double click can't receive the same items twice): while
// another session holds the lock, a receive waits and is refused. Written after the hook proof (Nov 23) found nothing noticed the lock gone.
test('receiving: a second receive while one is running is refused', async ({ page }) => {
    test.setTimeout(90000);
    const { spawn } = require('child_process');
    const holder = spawn('mysql', [process.env.ITFLOW_DB || 'itflow', '-N', '-e', "SELECT GET_LOCK('mit_proc_receive', 5); SELECT SLEEP(40);"], { stdio: 'ignore' });
    try {
        await new Promise((r) => setTimeout(r, 1500));   // the other session has the lock
        await visit(page, 'agent/clients.php');   // a page with a form, for the token
        const res = await db.pagePost(page, 'post.php', { csrf_token: await csrf(page), po_receive: '1' });
        await visit(page, 'agent/purchase_orders.php');
        expect(res.text + (await page.content()), 'refused while another receive runs').toContain('Someone else is doing this right now');
    } finally {
        holder.kill();
    }
});
