<?php
/*
 * Undoing a receipt on one of ITFlow 26.12's purchase orders (its poUndoReceipt, with our hook mitProcUndoReceiptExtras - what our
 * receiving extras did is kept in mit_po_receipt_extras): received by mistake, it goes back to waiting - the line's received quantity
 * and the PO's status follow, the stock ITFlow put on the shelf comes off, the assets it made are deleted (and the ticket part no
 * longer points at one), the ticket gets a note, an invoice line's stock taken back again and its product restored; a receipt from
 * before M062 (tracked 0) is undone without our stock; a cancelled PO's receipts are refused.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitProcUndoReceiptExtras') || !mitProcReady()) {
    echo "  skip - not in this build\n";
    exit(0);
}
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ Undo receipt $stamp");
$vendor = intval(scenarioValue("SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1"));
$made = ['products' => [], 'pos' => [], 'items' => [], 'invoices' => [], 'assets' => []];
$old_approval = intval(scenarioValue("SELECT config_po_approval FROM settings WHERE company_id = 1"));
mysqli_query($mysqli, "UPDATE settings SET config_po_approval = 0 WHERE company_id = 1"); // before poSettings() reads it
$product = function ($name, $stock) use ($mysqli, &$made) {
    mysqli_query($mysqli, "INSERT INTO products SET product_name = '" . escapeSql($name) . "', product_price = 100, product_track_stock = 1, product_type = 'product', product_cost = 70,
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0");
    $id = $made['products'][] = mysqli_insert_id($mysqli);
    if ($stock) {
        productStockMove($id, $stock, 'received', ['note' => 'scenario']);
    }
    return $id;
};
// A PO already ordered, one line for one item on To Order
$po = function ($order_item_id) use ($vendor, &$made) {
    $po_id = $made['pos'][] = poCreate($vendor, [$order_item_id], $error);
    poMarkOrdered(poRow($po_id), date('Y-m-d'), '', $error);
    return [$po_id, intval(poItems($po_id)[0]['po_item_id'] ?? 0)];
};
$status = function ($po_id) {
    return (string) scenarioValue("SELECT po_status FROM purchase_orders WHERE po_id = $po_id");
};
$receipt = function ($po_id) {
    return intval(scenarioValue("SELECT MAX(po_receipt_id) FROM po_receipts WHERE po_receipt_po_id = $po_id"));
};
$undo = function ($receipt_id) {
    $ok = poUndoReceipt($receipt_id, $error);
    return [$ok, $error];
};
try {
    echo "into our stock\n";
    $pid = $product("ZZ undo switch $stamp", 0);
    $proc = $made['items'][] = mitProcAddItem(['source' => 'manual', 'client_id' => 0, 'product_id' => $pid, 'name' => "ZZ undo switch $stamp", 'quantity' => 3, 'unit_cost' => 70]);
    [$po_id, $line_id] = $po($proc);
    poReceive([$line_id => ['qty' => 2]]);
    scenarioCheck('received 2: on the shelf, PO partly received', [productOnHand($pid), $status($po_id)], [2.0, 'partial']);
    $r = $receipt($po_id);
    scenarioCheck('ITFlow\'s receipt has its stock move; ours is tracked', [intval(scenarioValue("SELECT po_receipt_stock_id > 0 FROM po_receipts WHERE po_receipt_id = $r")), intval(scenarioValue("SELECT tracked FROM mit_po_receipt_extras WHERE po_receipt_id = $r"))], [1, 1]);
    [$ok, $message] = $undo($r);
    scenarioCheck("undone ($message)", $ok, true);
    scenarioCheck('... stock back to 0, nothing received, PO ordered again', [productOnHand($pid), (float) scenarioValue("SELECT po_item_received FROM po_items WHERE po_item_id = $line_id"), $status($po_id)], [0.0, 0.0, 'ordered']);
    scenarioCheck('... the receipt and its extras are gone, the PO history says so', [intval(scenarioValue("SELECT COUNT(*) FROM po_receipts WHERE po_receipt_id = $r")), intval(scenarioValue("SELECT COUNT(*) FROM mit_po_receipt_extras WHERE po_receipt_id = $r")),
        intval(scenarioValue("SELECT COUNT(*) FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = $po_id AND log_description LIKE '%undid the receipt%'"))], [0, 0, 1]);
    poReceive([$line_id => ['qty' => 3]]);
    scenarioCheck('... it can be received again in full', [productOnHand($pid), $status($po_id)], [3.0, 'received']);
    [$ok] = $undo($receipt($po_id));
    scenarioCheck('a fully received PO goes back to ordered', [$ok, $status($po_id), (string) scenarioValue("SELECT order_item_status FROM order_items WHERE order_item_id = $proc")], [true, 'ordered', 'ordered']);

    echo "assets for a ticket\n";
    $ticket = scenarioMakeTicket($client_id, $contact_id);
    $proc = $made['items'][] = mitProcAddItem(['source' => 'ticket', 'ticket_id' => $ticket, 'client_id' => $client_id, 'name' => "ZZ undo laptop $stamp", 'quantity' => 2, 'unit_cost' => 700]);
    if (mitTicketPartsReady()) {
        mysqli_query($mysqli, "INSERT INTO mit_ticket_parts SET ticket_id = $ticket, client_id = $client_id, product_id = 0, asset_id = 0, name = 'ZZ undo laptop', description = '',
            quantity = 2, unit_cost = 700, unit_price = 900, tax_id = 0, source = 'order', proc_item_id = $proc, created_by = 1");
    }
    [$po_id, $line_id] = $po($proc);
    poReceive([$line_id => ['qty' => 2, 'serials' => "SN1\nSN2", 'asset_type' => 'Laptop']]);
    $r = $receipt($po_id);
    $asset_ids = array_filter(array_map('intval', explode(',', (string) scenarioValue("SELECT po_receipt_asset_ids FROM po_receipts WHERE po_receipt_id = $r"))));
    $made['assets'] = array_merge($made['assets'], $asset_ids);
    scenarioCheck('received: 2 assets, linked to the ticket, a note', [count($asset_ids), intval(scenarioValue("SELECT COUNT(*) FROM ticket_assets WHERE ticket_id = $ticket")),
        intval(scenarioValue("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket AND ticket_reply LIKE 'Received 2%'"))], [2, 2, 1]);
    if (mitTicketPartsReady()) {
        scenarioCheck('... the ticket part points at its first asset', intval(scenarioValue("SELECT asset_id FROM mit_ticket_parts WHERE proc_item_id = $proc")), intval(reset($asset_ids)));
    }
    [$ok, $message] = $undo($r);
    scenarioCheck("undone ($message)", $ok, true);
    scenarioCheck('... the assets and their ticket links are gone', [intval(scenarioValue("SELECT COUNT(*) FROM assets WHERE asset_id IN (" . implode(',', $asset_ids ?: [0]) . ")")), intval(scenarioValue("SELECT COUNT(*) FROM ticket_assets WHERE ticket_id = $ticket"))], [0, 0]);
    if (mitTicketPartsReady()) {
        scenarioCheck('... the ticket part no longer points at one', intval(scenarioValue("SELECT asset_id FROM mit_ticket_parts WHERE proc_item_id = $proc")), 0);
    }
    scenarioCheck('... the ticket gets a note', intval(scenarioValue("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket AND ticket_reply LIKE '%undone%'")), 1);

    echo "an invoice line's stock\n";
    $pid = $product("ZZ undo router $stamp", 5);
    $in = intval(scenarioValue("SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices"));
    mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = $in, invoice_scope = 'scenario', invoice_date = CURDATE(), invoice_due = CURDATE(), invoice_amount = 0,
        invoice_currency_code = 'USD', invoice_status = 'Draft', invoice_url_key = 'scenario', invoice_client_id = $client_id, invoice_category_id = 0");
    $iid = $made['invoices'][] = mysqli_insert_id($mysqli);
    billingCopyLines([['item_name' => "ZZ undo router $stamp", 'item_description' => '', 'item_quantity' => 2, 'item_price' => 100, 'item_subtotal' => 200, 'item_tax' => 0, 'item_total' => 200, 'item_tax_id' => 0, 'item_product_id' => $pid]], 'invoice', $iid);
    $item_id = intval(scenarioValue("SELECT MAX(item_id) FROM invoice_items WHERE item_invoice_id = $iid"));
    scenarioCheck('the invoice line took 2', productOnHand($pid), 3.0);
    $proc = $made['items'][] = mitProcAddItem(['source' => 'invoice', 'invoice_id' => $iid, 'invoice_item_id' => $item_id, 'client_id' => $client_id, 'product_id' => $pid, 'name' => "ZZ undo router $stamp", 'quantity' => 2, 'unit_cost' => 70]);
    [$po_id, $line_id] = $po($proc);
    poReceive([$line_id => ['qty' => 2]]);
    scenarioCheck('received: the 2 go back on the shelf, the line loses its product', [productOnHand($pid), intval(scenarioValue("SELECT item_product_id FROM invoice_items WHERE item_id = $item_id"))], [5.0, 0]);
    [$ok] = $undo($receipt($po_id));
    scenarioCheck('undone: taken for the invoice again, the line has its product back', [$ok, productOnHand($pid), intval(scenarioValue("SELECT item_product_id FROM invoice_items WHERE item_id = $item_id"))], [true, 3.0, $pid]);

    echo "older receipts and cancelled POs\n";
    poReceive([$line_id => ['qty' => 1]]);
    $r = $receipt($po_id);
    mysqli_query($mysqli, "UPDATE mit_po_receipt_extras SET tracked = 0, return_qty = 0, return_product_id = 0, cleared_item_id = 0 WHERE po_receipt_id = $r");
    [$ok, $message] = $undo($r);
    scenarioCheck("a receipt from before M062 is undone ($message)", $ok, true);
    poReceive([$line_id => ['qty' => 1]]);
    $r = $receipt($po_id);
    mysqli_query($mysqli, "UPDATE purchase_orders SET po_status = 'cancelled' WHERE po_id = $po_id");
    [$ok] = $undo($r);
    scenarioCheck('a cancelled PO: refused', $ok, false);
    scenarioCheck('an unknown receipt: refused', $undo(0)[0], false);
} finally {
    mysqli_query($mysqli, "UPDATE settings SET config_po_approval = $old_approval WHERE company_id = 1");
    foreach ($made['assets'] as $id) {
        mysqli_query($mysqli, "DELETE FROM assets WHERE asset_id = " . intval($id));
    }
    foreach (array_filter($made['pos']) as $id) {
        mysqli_query($mysqli, "DELETE FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM purchase_orders WHERE po_id = " . intval($id)); // lines, receipts, tracking go with it
    }
    foreach ($made['items'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE proc_item_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM order_items WHERE order_item_id = " . intval($id));
    }
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM product_stock WHERE stock_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM product_stock WHERE stock_product_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
