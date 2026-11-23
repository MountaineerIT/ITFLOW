<?php
/*
 * Procurement and ITFlow's stock (functions/mit_procurement_parts.php, mit_procurement_extras.php - receiving through ITFlow 26.12's
 * poReceive, our hook mitProcAfterReceive):
 *  - a quote line for a tracked product with none on the shelf, ticked "Send to procurement", converts (ITFlow alone refuses) and takes
 *    no stock; receiving it puts none back; cancelling the invoice cancels the waiting item (mitProcTidy);
 *  - a line sent from an invoice that took stock: receipts put back only what is still taken - partly, in full, never more, and nothing
 *    after the invoice was cancelled (ITFlow gave it back already).
 */
require __DIR__ . '/bootstrap.php';
$client_id = scenarioClient();
$product = function ($name, $stock) use ($mysqli) {
    mysqli_query($mysqli, "INSERT INTO products SET product_name = '" . escapeSql($name) . "', product_price = 100, product_track_stock = 1, product_type = 'product', product_cost = 70,
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0");
    $id = mysqli_insert_id($mysqli);
    if ($stock) {
        productStockMove($id, $stock, 'received', ['note' => 'scenario']);
    }
    return $id;
};
$vendor = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1"))[0] ?? 0);
$made = ['products' => [], 'invoices' => [], 'quotes' => [], 'items' => [], 'pos' => []];
$old_approval = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_po_approval FROM settings WHERE company_id = 1"))[0] ?? 0);
mysqli_query($mysqli, "UPDATE settings SET config_po_approval = 0 WHERE company_id = 1"); // before poSettings() reads it
// An item on an ordered PO of its own - its line id
$ordered = function ($order_item_id) use ($vendor, &$made) {
    $po_id = $made['pos'][] = poCreate($vendor, [$order_item_id], $error);
    poMarkOrdered(poRow($po_id), date('Y-m-d'), '', $error);
    return intval(poItems($po_id)[0]['po_item_id'] ?? 0);
};
try {
    // 1. Ordered for the client from a quote, nothing on the shelf
    $pid = $made['products'][] = $product('ZZ scenario laptop', 0);
    $qn = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes"))[0]);
    mysqli_query($mysqli, "INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = $qn, quote_scope = 'scenario', quote_date = CURDATE(), quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        quote_amount = 300, quote_currency_code = 'USD', quote_status = 'Accepted', quote_url_key = 'scenario', quote_client_id = $client_id, quote_category_id = 0");
    $qid = $made['quotes'][] = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO quote_items SET item_name = 'ZZ scenario laptop', item_description = '', item_quantity = 3, item_price = 100, item_subtotal = 300, item_tax = 0,
        item_total = 300, item_order = 1, item_tax_id = 0, item_product_id = $pid, item_quote_id = $qid");
    $qitem = mysqli_insert_id($mysqli);
    $_POST = [];
    scenarioCheck('without the tick ITFlow refuses (not on the shelf)', quoteConvert($qid, ['date' => date('Y-m-d'), 'by' => 'Scenario'], $error), null);
    $_POST = ['mit_proc_lines' => [$qitem => ['send' => 1, 'vendor_id' => 0]]];
    $result = quoteConvert($qid, ['date' => date('Y-m-d'), 'by' => 'Scenario'], $error);
    scenarioCheck('ticked to order: converts', (bool) $result, true);
    if ($result) {
        $iid = $made['invoices'][] = intval($result['invoice_id']);
        mitProcAfterQuoteConvert($qid, $iid, $client_id, $_POST);
        scenarioCheck('the invoice line has no product (nothing taken)', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_product_id FROM invoice_items WHERE item_invoice_id = $iid"))[0]), 0);
        scenarioCheck('shelf still 0', productOnHand($pid), 0.0);
        $proc = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT o.order_item_id AS item_id, o.order_item_product_id AS product_id FROM order_items o
            JOIN mit_order_item_links links ON links.order_item_id = o.order_item_id WHERE links.invoice_id = $iid"));
        $made['items'][] = intval($proc['item_id'] ?? 0);
        scenarioCheck('the item on To Order keeps the product', intval($proc['product_id'] ?? 0), $pid);
        // A second one waits while the first is ordered and received
        $waiting = $made['items'][] = mitProcAddItem(['source' => 'quote', 'invoice_id' => $iid, 'invoice_item_id' => intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_id FROM invoice_items WHERE item_invoice_id = $iid"))[0]),
            'client_id' => $client_id, 'product_id' => $pid, 'name' => 'ZZ scenario laptop', 'quantity' => 1, 'unit_cost' => 70]);
        poReceive([$ordered(intval($proc['item_id'])) => ['qty' => 3]]);
        scenarioCheck('received: shelf still 0 (it went to the client)', productOnHand($pid), 0.0);
        mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Cancelled' WHERE invoice_id = $iid");
        mitProcTidy();
        scenarioCheck('invoice cancelled: the waiting item is cancelled', (string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT order_item_status FROM order_items WHERE order_item_id = $waiting"))[0], 'cancelled');
    }
    $_POST = [];

    // 2. Sent from an invoice whose line took stock
    $pid = $made['products'][] = $product('ZZ scenario switch', 5);
    $in = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices"))[0]);
    mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = $in, invoice_scope = 'scenario', invoice_date = CURDATE(), invoice_due = CURDATE(), invoice_amount = 0,
        invoice_currency_code = 'USD', invoice_status = 'Draft', invoice_url_key = 'scenario', invoice_client_id = $client_id, invoice_category_id = 0");
    $iid = $made['invoices'][] = mysqli_insert_id($mysqli);
    $line = ['item_name' => 'ZZ scenario switch', 'item_description' => '', 'item_quantity' => 2, 'item_price' => 100, 'item_subtotal' => 200, 'item_tax' => 0, 'item_total' => 200, 'item_tax_id' => 0, 'item_product_id' => $pid];
    billingCopyLines([$line], 'invoice', $iid);
    $line_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT MAX(item_id) FROM invoice_items WHERE item_invoice_id = $iid"))[0]);
    scenarioCheck('invoice line took 2', productOnHand($pid), 3.0);
    $item = ['source' => 'invoice', 'invoice_id' => $iid, 'client_id' => $client_id, 'project_id' => 0, 'product_id' => $pid, 'vendor_id' => 0, 'name' => 'ZZ scenario switch', 'description' => '', 'quantity' => 2, 'unit_cost' => 70];
    $proc_id = $made['items'][] = mitProcAddItem($item + ['invoice_item_id' => $line_id]);
    $extra = $made['items'][] = mitProcAddItem(['quantity' => 1] + $item + ['invoice_item_id' => $line_id]);
    $l1 = $ordered($proc_id);
    poReceive([$l1 => ['qty' => 1]]);
    scenarioCheck('1 of 2 received: 1 back', productOnHand($pid), 4.0);
    scenarioCheck('... kept for undoing it (mit_po_receipt_extras)', (float) mysqli_fetch_row(mysqli_query($mysqli, "SELECT SUM(return_qty) FROM mit_po_receipt_extras x JOIN po_receipts r ON r.po_receipt_id = x.po_receipt_id WHERE r.po_receipt_item_id = $l1"))[0], 1.0);
    poReceive([$l1 => ['qty' => 1]]);
    scenarioCheck('2 of 2 received: all back', productOnHand($pid), 5.0);
    poReceive([$ordered($extra) => ['qty' => 1]]);
    scenarioCheck('one more received: nothing more back', productOnHand($pid), 5.0);
    billingCopyLines([$line], 'invoice', $iid);
    $line2 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT MAX(item_id) FROM invoice_items WHERE item_invoice_id = $iid"))[0]);
    productStockReturnInvoice($iid, 'scenario cancelled');
    scenarioCheck('invoice cancelled: ITFlow put its stock back', productOnHand($pid), 5.0);
    $proc2 = $made['items'][] = mitProcAddItem($item + ['invoice_item_id' => $line2]);
    poReceive([$ordered($proc2) => ['qty' => 2]]);
    scenarioCheck('received after the cancel: not put back twice', productOnHand($pid), 5.0);
} finally {
    $_POST = [];
    mysqli_query($mysqli, "UPDATE settings SET config_po_approval = $old_approval WHERE company_id = 1");
    foreach (array_filter($made['pos']) as $id) {
        mysqli_query($mysqli, "DELETE FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM purchase_orders WHERE po_id = " . intval($id));
    }
    foreach (array_filter($made['items']) as $id) {
        mysqli_query($mysqli, "DELETE FROM order_items WHERE order_item_id = " . intval($id));
    }
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['quotes'] as $id) {
        mysqli_query($mysqli, "DELETE FROM quote_items WHERE item_quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM quotes WHERE quote_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM product_stock WHERE stock_product_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
