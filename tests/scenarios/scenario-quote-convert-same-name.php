<?php
/*
 * Quote -> invoice with two one-time lines of the same name and quantity, only the SECOND ticked "Send to procurement" (one unit
 * comes off the shelf, one is ordered for the client). The procurement item must point at the invoice line that was ordered (the
 * one quoteConvert put on without its product), so receiving it does not put "back" stock the shelf never got.
 */
require __DIR__ . '/bootstrap.php';
$client_id = scenarioClient();
$made = ['products' => [], 'invoices' => [], 'quotes' => []];
try {
    mysqli_query($mysqli, "INSERT INTO products SET product_name = 'ZZ scen twin laptop', product_price = 1000, product_track_stock = 1, product_type = 'product', product_cost = 800,
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0");
    $pid = $made['products'][] = mysqli_insert_id($mysqli);
    productStockMove($pid, 1, 'received', ['note' => 'scenario']);
    $qn = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes"))[0]);
    mysqli_query($mysqli, "INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = $qn, quote_scope = 'scenario', quote_date = CURDATE(), quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        quote_amount = 2000, quote_currency_code = 'USD', quote_status = 'Accepted', quote_url_key = 'scenario', quote_client_id = $client_id, quote_category_id = 0");
    $qid = $made['quotes'][] = mysqli_insert_id($mysqli);
    $lines = [];
    foreach (['Office A', 'Office B'] as $i => $desc) {
        mysqli_query($mysqli, "INSERT INTO quote_items SET item_name = 'ZZ scen twin laptop', item_description = '$desc', item_quantity = 1, item_price = 1000, item_subtotal = 1000, item_tax = 0,
            item_total = 1000, item_order = " . ($i + 1) . ", item_tax_id = 0, item_product_id = $pid, item_quote_id = $qid");
        $lines[] = mysqli_insert_id($mysqli);
    }
    // First line from the shelf (the one unit there), second ordered for the client
    $_POST = ['mit_proc_lines' => [$lines[1] => ['send' => 1, 'vendor_id' => 0]]];
    $result = quoteConvert($qid, ['date' => date('Y-m-d'), 'by' => 'Scenario'], $error);
    scenarioCheck("converted ($error)", (bool) $result, true);
    $iid = $made['invoices'][] = intval($result['invoice_id'] ?? 0);
    mitProcAfterQuoteConvert($qid, $iid, $client_id, $_POST);
    $inv_lines = mysqli_fetch_all(mysqli_query($mysqli, "SELECT item_id, item_description, item_product_id FROM invoice_items WHERE item_invoice_id = $iid ORDER BY item_id"), MYSQLI_ASSOC);
    scenarioCheck('line A keeps its product (took the shelf unit)', intval($inv_lines[0]['item_product_id']), $pid);
    scenarioCheck('line B ordered: no product', intval($inv_lines[1]['item_product_id']), 0);
    scenarioCheck('shelf 1 -> 0', productOnHand($pid), 0.0);
    $proc = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_id, invoice_item_id, description FROM mit_procurement_items WHERE invoice_id = $iid"));
    scenarioCheck('the item points at line B (the ordered one)', intval($proc['invoice_item_id'] ?? 0), intval($inv_lines[1]['item_id']));
    scenarioCheck('... and carries line B\'s description', $proc['description'] ?? '', 'Office B');
    // Receiving the ordered unit: it goes to the client - the shelf stays at 0
    mitProcAfterReceive(['proc_item_id' => $proc['item_id'], 'po_prefix' => 'PO-', 'po_number' => 9998, 'client_id' => $client_id, 'po_id' => 0, 'name' => 'ZZ scen twin laptop', 'unit_cost' => 800], 1, '', 0, [], []);
    scenarioCheck('received for the client: shelf still 0 (no phantom stock)', productOnHand($pid), 0.0);
    scenarioCheck('line A still moves stock as ITFlow\'s (product kept)', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_product_id FROM invoice_items WHERE item_id = " . intval($inv_lines[0]['item_id'])))[0]), $pid);
} finally {
    $_POST = [];
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_procurement_items WHERE invoice_id = " . intval($id));
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
