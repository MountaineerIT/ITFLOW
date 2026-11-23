// Names people type (items, parts, PO lines, vendor order numbers, tracking, usage names, batch names) are shown escaped on every
// procurement / billing page of ours, and ITFlow 26.12's purchasing pages with our hooks on them - an element smuggled in a name must not
// appear in the page. Makes its rows and removes them.

const { test, expect } = require('@playwright/test');
const { watch, visit, openModal } = require('./helpers');
const db = require('./mitdb');

const EVIL = 'Smoke64 <b id="xss64">x</b><img src=x id="xss64img">';
const made = { item: 0, po: 0, ticket: 0, batch: 0, invoice: 0 };

test.beforeAll(() => {
    const vendor = Number(db.val('SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1'));
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    made.ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = 'Smoke 64', ticket_details = 'x', ticket_priority = 'Low', ticket_status = 1, ticket_client_id = 1, ticket_created_by = 1`);
    db.insert(`INSERT INTO mit_ticket_parts SET ticket_id = ${made.ticket}, client_id = 1, name = ${db.q(EVIL)}, description = ${db.q(EVIL)}, quantity = 1, unit_price = 5, source = 'other'`);
    // On ITFlow 26.12's To Order (from a ticket part - our link row) and on one of its POs, ordered, with tracking
    made.item = Number(db.php(`echo mitProcAddItem(['source' => 'ticket', 'ticket_id' => ${made.ticket}, 'client_id' => 1, 'name' => ${JSON.stringify(EVIL)}, 'description' => ${JSON.stringify(EVIL)},
        'note' => ${JSON.stringify(EVIL)}, 'quantity' => 1, 'unit_cost' => 5, 'vendor_id' => ${vendor}]);`).trim());
    db.exec(`INSERT INTO order_items SET order_item_name = ${db.q(EVIL)}, order_item_description = ${db.q(EVIL)}, order_item_note = ${db.q(EVIL)}, order_item_quantity = 1,
        order_item_cost = 5, order_item_status = 'waiting', order_item_client_id = 1, order_item_vendor_id = ${vendor}, order_item_created_by = 1`);
    const out = db.php(`$id = poCreate(${vendor}, [${made.item}], $e); mysqli_query($mysqli, "UPDATE purchase_orders SET po_status = 'ordered', po_order_date = CURDATE(), po_vendor_reference = '" . escapeSql(${JSON.stringify(EVIL)}) . "', po_note = '" . escapeSql(${JSON.stringify(EVIL)}) . "' WHERE po_id = $id");
        mysqli_query($mysqli, "INSERT INTO po_tracking SET po_tracking_po_id = $id, po_tracking_carrier = 'other', po_tracking_number = '" . escapeSql(${JSON.stringify(EVIL)}) . "'"); echo $id;`);
    made.po = Number(out.trim());
    made.batch = db.insert(`INSERT INTO mit_qbo_batches SET batch_name = ${db.q(EVIL)}, batch_status = 'open', created_by = 1`);
});

test.afterAll(() => {
    db.exec(`DELETE FROM mit_qbo_batches WHERE batch_id = ${made.batch}; DELETE FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = ${made.po}; DELETE FROM purchase_orders WHERE po_id = ${made.po};
        DELETE FROM order_items WHERE order_item_id = ${made.item} OR order_item_name LIKE 'Smoke64 %';
        DELETE FROM mit_ticket_parts WHERE ticket_id = ${made.ticket}; DELETE FROM tickets WHERE ticket_id = ${made.ticket}`);
});

for (const url of ['agent/to_order.php', 'agent/purchase_orders.php', 'agent/purchase_order.php?po_id=PO', 'agent/receiving.php',
    'agent/unbilled_time.php?client_id=1', 'agent/ticket.php?ticket_id=TICKET', 'agent/mit_qbo_batches.php', 'agent/mit_qbo_batch.php?batch_id=BATCH', 'agent/client_overview.php?client_id=1']) {
    test(`escaped on ${url.replace(/\?.*/, '')}`, async ({ page }) => {
        const errors = watch(page);
        await visit(page, url.replace('PO', made.po).replace('TICKET', made.ticket).replace('BATCH', made.batch), errors);
        await page.waitForTimeout(500);
        expect(await page.locator('#xss64, #xss64img').count(), 'no element from a typed name').toBe(0);
    });
}

test('escaped in our "Link to a project" window', async ({ page }) => {
    const errors = watch(page);
    await visit(page, `agent/purchase_order.php?po_id=${made.po}`, errors);
    await openModal(page, `modals/mit_procurement/po_project.php?po_id=${made.po}`);
    expect(await page.locator('#xss64, #xss64img').count(), 'no element from a typed name').toBe(0);
});
