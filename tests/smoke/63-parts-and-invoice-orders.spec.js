// Ticket parts and invoice lines ordered, through the real windows and handlers, with the stock ledger checked underneath:
//  - a Technician adds a part from stock in the Parts window (stock 5 -> 3), changes it (-> 2), bills it with a changed price
//    (invoice total right), and a to-order part removed again cancels its item;
//  - an invoice line that took stock is sent to procurement from the invoice ("Send to procurement" window): the quantity is
//    capped at the line, a second send has nothing left; received on Receiving with "Fill in each asset's details" (ITFlow's
//    asset window opens for the new asset); the stock goes back exactly once - not again when the line is removed or the invoice
//    cancelled afterwards.
// Makes its own ticket / products / invoices and removes them.

const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, fillForm, submit, flash, expectClean, csrf } = require('./helpers');
const db = require('./mitdb');
const fs = require('fs');
const path = require('path');

test.describe.configure({ mode: 'serial' });

const CLIENT = 1;
const VENDOR = Number(db.val('SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1'));
const made = { ticket: 0, products: [], invoices: [], pos: [], assets: [] };
const onHand = (pid) => Number(db.val(`SELECT COALESCE(SUM(stock_qty), 0) FROM product_stock WHERE stock_product_id = ${pid}`));
const techState = path.join(__dirname, '.auth', 'tech.json');

function product(name, stock) {
    const id = db.insert(`INSERT INTO products SET product_name = ${db.q(name)}, product_price = 120, product_cost = 70, product_type = 'product', product_track_stock = 1,
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0`);
    made.products.push(id);
    if (stock) {
        db.php(`productStockMove(${id}, ${stock}, 'received', ['note' => 'Smoke 63']);`);
    }
    return id;
}

test.beforeAll(() => {
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    made.ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = 'Smoke 63 parts', ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 1, ticket_client_id = ${CLIENT}, ticket_created_by = 1`);
});

test.afterAll(() => {
    db.exec(`DELETE FROM mit_procurement_items WHERE ticket_id = ${made.ticket} OR name LIKE 'Smoke 63%'`);
    for (const id of made.pos) {
        db.exec(`DELETE FROM mit_purchase_orders WHERE po_id = ${id}`);
    }
    for (const id of made.assets) {
        db.exec(`DELETE FROM ticket_assets WHERE asset_id = ${id}; DELETE FROM assets WHERE asset_id = ${id}`);
    }
    for (const id of made.invoices) {
        db.exec(`DELETE FROM invoice_items WHERE item_invoice_id = ${id}; DELETE FROM history WHERE history_invoice_id = ${id}; DELETE FROM invoices WHERE invoice_id = ${id}`);
    }
    db.exec(`DELETE FROM mit_ticket_parts WHERE ticket_id = ${made.ticket}; DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticket};
        DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${made.ticket}; DELETE FROM tickets WHERE ticket_id = ${made.ticket}`);
    for (const id of made.products) {
        db.exec(`DELETE FROM product_stock WHERE stock_product_id = ${id}; DELETE FROM products WHERE product_id = ${id}`);
    }
});

test('Technician: part from stock, changed, billed; a to-order part removed', async ({ browser }) => {
    test.skip(!fs.existsSync(techState), 'no technician login');
    const context = await browser.newContext({ storageState: techState });
    const page = await context.newPage();
    const errors = watch(page);
    const ssd = product('Smoke 63 SSD', 5);
    await visit(page, `agent/ticket.php?ticket_id=${made.ticket}`, errors);
    await expect(page.locator('.card:has(.card-title i.fa-microchip)'), 'the ticket page has its Parts box').not.toHaveCount(0);
    let modal = await openModal(page, `modals/mit_procurement/part.php?ticket_id=${made.ticket}`, 'lg');
    await fillForm(modal, { product_id: String(ssd), name: 'Smoke 63 SSD', quantity: '2', unit_price: '120', source: 'stock' });
    await submit(modal, 'mit_part_save');
    expect(await flash(page)).toContain('Part added');
    expect(onHand(ssd), 'stock 5 -> 3').toBe(3);
    const part = db.one(`SELECT part_id, unit_cost FROM mit_ticket_parts WHERE ticket_id = ${made.ticket} AND name = 'Smoke 63 SSD'`);
    expect(Number(part.unit_cost), "the product's cost on the part").toBe(70);
    modal = await openModal(page, `modals/mit_procurement/part.php?ticket_id=${made.ticket}&part_id=${part.part_id}`, 'lg');
    await fillForm(modal, { quantity: '3' });
    await submit(modal, 'mit_part_save');
    expect(onHand(ssd), 'qty 2 -> 3: stock 2').toBe(2);
    expect(Number(db.val(`SELECT COUNT(*) FROM product_stock WHERE stock_product_id = ${ssd}`)), 'one move per change (received, used, changed)').toBe(3);

    // Bill it at a changed price
    modal = await openModal(page, `modals/mit_procurement/parts_bill.php?ticket_id=${made.ticket}`, 'lg');
    await fillForm(modal, { [`prices[${part.part_id}]`]: '110.50', invoice_id: '0' });
    await submit(modal, 'mit_parts_bill');
    const invoiceId = Number(new URL(page.url()).searchParams.get('invoice_id'));
    expect(invoiceId, `billed (${await flash(page)})`).toBeGreaterThan(0);
    made.invoices.push(invoiceId);
    const line = db.one(`SELECT item_quantity, item_price, item_total, item_product_id FROM invoice_items WHERE item_invoice_id = ${invoiceId}`);
    expect([Number(line.item_quantity), Number(line.item_price), Number(line.item_total), Number(line.item_product_id)], 'one line, no product (stock already taken)').toEqual([3, 110.5, 331.5, 0]);
    expect(Number(db.val(`SELECT invoice_amount FROM invoices WHERE invoice_id = ${invoiceId}`))).toBe(331.5);
    expect(onHand(ssd), 'billing takes no more stock').toBe(2);

    // A part to order, then removed: its item is cancelled
    modal = await openModal(page, `modals/mit_procurement/part.php?ticket_id=${made.ticket}`, 'lg');
    await fillForm(modal, { product_id: '0', name: 'Smoke 63 dock', quantity: '1', unit_price: '200', unit_cost: '150', source: 'order', vendor_id: String(VENDOR) });
    await submit(modal, 'mit_part_save');
    const order = db.one(`SELECT part_id, proc_item_id FROM mit_ticket_parts WHERE ticket_id = ${made.ticket} AND name = 'Smoke 63 dock'`);
    expect(db.val(`SELECT status FROM mit_procurement_items WHERE item_id = ${order.proc_item_id}`), 'on the list to order').toBe('waiting');
    const token = await csrf(page);
    await db.pageGet(page, `post.php?mit_part_remove=${order.part_id}&ticket_id=${made.ticket}&csrf_token=${encodeURIComponent(token)}`);
    expect(db.val(`SELECT status FROM mit_procurement_items WHERE item_id = ${order.proc_item_id}`), 'removed part: item cancelled').toBe('cancelled');
    await expectClean(page, errors);
    await context.close();
});

test('invoice line to procurement: capped, received with the asset window, stock back exactly once', async ({ page }) => {
    const errors = watch(page);
    const nas = product('Smoke 63 NAS', 5);
    const invoiceId = Number(db.php(`$id = billingInvoiceCreate(${CLIENT}, date('Y-m-d'), 0, 'Smoke 63', 'Smoke');
        billingItemSave('invoice', $id, ['name' => 'Smoke 63 NAS', 'qty' => '2', 'price' => '120', 'tax_id' => 0, 'product_id' => ${nas}], 0, $e); billingRecalc('invoice', $id); echo $id;`).trim());
    made.invoices.push(invoiceId);
    expect(onHand(nas), 'the invoice line took 2').toBe(3);
    const lineId = db.val(`SELECT item_id FROM invoice_items WHERE item_invoice_id = ${invoiceId}`);
    await visit(page, `agent/invoice.php?invoice_id=${invoiceId}`, errors);
    await expect(page.locator('a[data-modal-url*="mit_procurement/from_invoice.php"]'), "the invoice's menu has Send to procurement").not.toHaveCount(0);
    let modal = await openModal(page, `modals/mit_procurement/from_invoice.php?invoice_id=${invoiceId}`, 'lg');
    await fillForm(modal, { [`lines[${lineId}][send]`]: true, [`lines[${lineId}][qty]`]: '5', [`lines[${lineId}][cost]`]: '80', [`lines[${lineId}][vendor_id]`]: String(VENDOR) });
    await submit(modal, 'mit_proc_from_invoice');
    const item = db.one(`SELECT item_id, quantity, unit_cost, product_id, status FROM mit_procurement_items WHERE invoice_id = ${invoiceId}`);
    expect([Number(item.quantity), Number(item.unit_cost), Number(item.product_id), item.status], 'capped at the line (2)').toEqual([2, 80, nas, 'waiting']);
    const token = await csrf(page);
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_proc_from_invoice: '1', invoice_id: String(invoiceId), [`lines[${lineId}][send]`]: '1', [`lines[${lineId}][qty]`]: '1', [`lines[${lineId}][cost]`]: '80' });
    expect(Number(db.val(`SELECT COUNT(*) FROM mit_procurement_items WHERE invoice_id = ${invoiceId}`)), 'nothing left to send').toBe(1);

    // PO, ordered, received on Receiving with the asset window
    let r = await db.pagePost(page, 'post.php', { csrf_token: token, mit_po_create: '1', vendor_id: String(VENDOR), 'item_ids[]': [item.item_id] });
    const po = Number(new URL(r.url).searchParams.get('po_id'));
    made.pos.push(po);
    db.exec(`UPDATE mit_purchase_orders SET status = 'ordered', order_date = CURDATE() WHERE po_id = ${po}`);
    await visit(page, `agent/mit_receiving.php?po_id=${po}`, errors);
    const row = page.locator('#mitReceiveForm tr', { hasText: 'Smoke 63 NAS' });
    await row.locator('input[name$="[qty]"]').fill('2');
    await row.locator('textarea[name$="[serials]"], input[name$="[serials]"]').first().fill('NAS-1\nNAS-2');
    const form = page.locator('#mitReceiveForm');
    await form.locator('#mitOptAssets').check();
    await form.locator('#mitOptReview').check();
    await submit(form, 'mit_po_receive');
    expect(page.url(), 'back on Receiving with the assets to review').toMatch(/mit_review_assets=\d+(,\d+)*/);
    const assets = db.rows(`SELECT asset_id, asset_serial FROM assets WHERE asset_purchase_reference = (SELECT CONCAT(po_prefix, po_number) FROM mit_purchase_orders WHERE po_id = ${po})`);
    made.assets.push(...assets.map((a) => a.asset_id));
    expect(assets.map((a) => a.asset_serial).sort(), 'two assets with serials').toEqual(['NAS-1', 'NAS-2']);
    await expect(page.locator('.modal.show form'), "ITFlow's asset window opens for the first one").toHaveCount(1, { timeout: 10000 });
    await expect(page.locator('.modal.show')).toContainText('Smoke 63 NAS');
    expect(Number(db.val(`SELECT asset_invoice_id FROM assets WHERE asset_id = ${assets[0].asset_id}`)), 'asset tied to the invoice it was sold on').toBe(invoiceId);

    expect(onHand(nas), 'received for the invoice: the 2 it took go back').toBe(5);
    await visit(page, `agent/invoice.php?invoice_id=${invoiceId}`, errors);
    await expect(page.locator('.alert:has(i.fa-truck-loading)'), 'the invoice says how much of what was ordered came in').toContainText('2 of 2 received');
    expect(Number(db.val(`SELECT item_product_id FROM invoice_items WHERE item_id = ${lineId}`)), 'line no longer moves stock').toBe(0);
    // Removing the line, then cancelling the invoice: nothing comes back a second time
    await visit(page, `agent/invoice.php?invoice_id=${invoiceId}`, errors);
    const t2 = await csrf(page);
    await db.pageGet(page, `post.php?billing_item_delete=${lineId}&type=invoice&doc_id=${invoiceId}&csrf_token=${encodeURIComponent(t2)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM invoice_items WHERE item_id = ${lineId}`)), 'line removed').toBe(0);
    expect(onHand(nas), 'line removed: stock not returned again').toBe(5);
    await db.pageGet(page, `post.php?cancel_invoice=${invoiceId}&csrf_token=${encodeURIComponent(t2)}`);
    expect(onHand(nas), 'invoice cancelled: stock not returned again').toBe(5);
});

test('deleting a ticket puts its unbilled stock parts back on the shelf', async ({ page }) => {
    const errors = watch(page);
    const ram = product('Smoke 63 RAM', 4);
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    const ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = 'Smoke 63 delete', ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 1, ticket_client_id = ${CLIENT}, ticket_created_by = 1`);
    await visit(page, `agent/ticket.php?ticket_id=${ticket}`, errors);
    const token = await csrf(page);
    await db.pagePost(page, 'post.php', { csrf_token: token, mit_part_save: '1', ticket_id: String(ticket), product_id: String(ram), name: 'Smoke 63 RAM',
        quantity: '3', unit_price: '50', source: 'stock' });
    expect(onHand(ram), 'the part took 3 from the shelf').toBe(1);
    await db.pageGet(page, `post.php?delete_ticket=${ticket}&csrf_token=${encodeURIComponent(token)}`);
    const gone = db.val(`SELECT COUNT(*) FROM tickets WHERE ticket_id = ${ticket}`) === '0';
    if (!gone) {
        db.exec(`DELETE FROM mit_ticket_parts WHERE ticket_id = ${ticket}; DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${ticket}; DELETE FROM tickets WHERE ticket_id = ${ticket}`);
    }
    expect(gone, 'ticket deleted').toBe(true);
    expect(onHand(ram), 'the 3 parts went back when the ticket was deleted').toBe(4);
});


test('the Procurement menu, and a project page shows what is ordered for it', async ({ page }) => {
    const errors = watch(page);
    const project = db.insert(`INSERT INTO projects SET project_name = 'Smoke 63 project', project_client_id = ${CLIENT}, project_due = DATE_ADD(CURDATE(), INTERVAL 30 DAY)`);
    const item = db.insert(`INSERT INTO mit_procurement_items SET source = 'manual', client_id = ${CLIENT}, project_id = ${project}, name = 'Smoke 63 project switch',
        quantity = 2, unit_cost = 150, vendor_id = ${VENDOR}, created_by = 1`);
    try {
        await visit(page, `agent/project.php?project_id=${project}`, errors);
        for (const file of ['mit_procurement.php', 'mit_purchase_orders.php', 'mit_receiving.php']) {
            await expect(page.locator(`.app-sidebar a[href="/agent/${file}"]`), `side menu: ${file}`).toHaveCount(1);
        }
        const card = page.locator('.card:has(.card-title i.fa-file-signature)');
        await expect(card, "the project's Purchase orders card").toHaveCount(1);
        await expect(card).toContainText('300.00');
        await expectClean(page, errors);
    } finally {
        db.exec(`DELETE FROM mit_procurement_items WHERE item_id = ${item}; DELETE FROM projects WHERE project_id = ${project}`);
    }
});

test('deleting a client (in ITFlow or through the API) puts its tickets\' unbilled stock parts back', async ({ page }) => {
    const errors = watch(page);
    const keys = [];
    try {
        for (const via of ['page', 'api']) {
            const cpu = product(`Smoke 63 CPU ${via}`, 5);
            const client = db.insert(`INSERT INTO clients SET client_name = 'Smoke 63 client ${via} ${Date.now()}', client_currency_code = 'USD'`);
            const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
            const ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = 'Smoke 63 client delete', ticket_details = 'x',
                ticket_priority = 'Low', ticket_status = 1, ticket_client_id = ${client}, ticket_created_by = 1`);
            await visit(page, `agent/ticket.php?ticket_id=${ticket}`, errors);
            const token = await csrf(page);
            await db.pagePost(page, 'post.php', { csrf_token: token, mit_part_save: '1', ticket_id: String(ticket), product_id: String(cpu), name: `Smoke 63 CPU ${via}`,
                quantity: '2', unit_price: '300', source: 'stock' });
            expect(onHand(cpu), 'the part took 2 from the shelf').toBe(3);
            db.exec(`UPDATE clients SET client_archived_at = NOW() WHERE client_id = ${client}`);
            if (via === 'page') {
                await db.pageGet(page, `post.php?delete_client=${client}&csrf_token=${encodeURIComponent(token)}`);
            } else {
                const secret = require('crypto').randomBytes(16).toString('hex');
                keys.push(db.insert(`INSERT INTO api_keys SET api_key_name = 'Smoke 63', api_key_secret = '${secret}', api_key_decrypt_hash = '',
                    api_key_expire = DATE_ADD(CURDATE(), INTERVAL 1 DAY), api_key_user_id = 1`));
                await page.evaluate(async ({ secret, client }) => {
                    await fetch('/api/v1/clients/delete.php', { method: 'POST', headers: { 'X-API-Key': secret, 'Content-Type': 'application/json' }, body: JSON.stringify({ client_id: client }) });
                }, { secret, client });
            }
            const gone = db.val(`SELECT COUNT(*) FROM clients WHERE client_id = ${client}`) === '0';
            if (!gone) {
                db.exec(`DELETE FROM mit_ticket_parts WHERE ticket_id = ${ticket}; DELETE FROM tickets WHERE ticket_id = ${ticket}; DELETE FROM clients WHERE client_id = ${client}`);
            }
            expect(gone, `client deleted (${via})`).toBe(true);
            expect(onHand(cpu), `the 2 parts went back (${via})`).toBe(5);
        }
    } finally {
        for (const id of keys) {
            db.exec(`DELETE FROM api_keys WHERE api_key_id = ${id}`);
        }
    }
});
