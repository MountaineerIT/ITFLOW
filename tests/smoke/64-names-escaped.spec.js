// Names people type (items, parts, PO lines, vendor order numbers, tracking, usage names, batch names) are shown escaped on every
// procurement / billing page of ours - an element smuggled in a name must not appear in the page. Makes its rows and removes them.

const { test, expect } = require('@playwright/test');
const { watch, visit } = require('./helpers');
const db = require('./mitdb');

const EVIL = 'Smoke64 <b id="xss64">x</b><img src=x id="xss64img">';
const made = { item: 0, po: 0, ticket: 0, batch: 0, invoice: 0 };

test.beforeAll(() => {
    const vendor = Number(db.val('SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1'));
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    made.ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = 'Smoke 64', ticket_details = 'x', ticket_priority = 'Low', ticket_status = 1, ticket_client_id = 1, ticket_created_by = 1`);
    db.insert(`INSERT INTO mit_ticket_parts SET ticket_id = ${made.ticket}, client_id = 1, name = ${db.q(EVIL)}, description = ${db.q(EVIL)}, quantity = 1, unit_price = 5, source = 'other'`);
    made.item = db.insert(`INSERT INTO mit_procurement_items SET source = 'manual', client_id = 1, name = ${db.q(EVIL)}, description = ${db.q(EVIL)}, note = ${db.q(EVIL)}, quantity = 1, unit_cost = 5, vendor_id = ${vendor}, created_by = 1`);
    const out = db.php(`$id = mitPoCreate([${made.item}], ${vendor}); mysqli_query($mysqli, "UPDATE mit_purchase_orders SET status = 'ordered', vendor_order_number = '" . escapeSql(${JSON.stringify(EVIL)}) . "', note = '" . escapeSql(${JSON.stringify(EVIL)}) . "' WHERE po_id = $id");
        mysqli_query($mysqli, "INSERT INTO mit_po_tracking SET po_id = $id, carrier = 'other', tracking_number = '" . escapeSql(${JSON.stringify(EVIL)}) . "', note = '" . escapeSql(${JSON.stringify(EVIL)}) . "'"); echo $id;`);
    made.po = Number(out.trim());
    made.batch = db.insert(`INSERT INTO mit_qbo_batches SET batch_name = ${db.q(EVIL)}, batch_status = 'open', created_by = 1`);
});

test.afterAll(() => {
    db.exec(`DELETE FROM mit_qbo_batches WHERE batch_id = ${made.batch}; DELETE FROM mit_purchase_orders WHERE po_id = ${made.po}; DELETE FROM mit_procurement_items WHERE item_id = ${made.item};
        DELETE FROM mit_ticket_parts WHERE ticket_id = ${made.ticket}; DELETE FROM tickets WHERE ticket_id = ${made.ticket}`);
});

for (const url of ['agent/mit_procurement.php', 'agent/mit_purchase_orders.php', 'agent/mit_purchase_order.php?po_id=PO', 'agent/mit_receiving.php',
    'agent/unbilled_time.php?client_id=1', 'agent/ticket.php?ticket_id=TICKET', 'agent/mit_qbo_batches.php', 'agent/mit_qbo_batch.php?batch_id=BATCH', 'agent/client_overview.php?client_id=1']) {
    test(`escaped on ${url.replace(/\?.*/, '')}`, async ({ page }) => {
        const errors = watch(page);
        await visit(page, url.replace('PO', made.po).replace('TICKET', made.ticket).replace('BATCH', made.batch), errors);
        await page.waitForTimeout(500);
        expect(await page.locator('#xss64, #xss64img').count(), 'no element from a typed name').toBe(0);
    });
}
