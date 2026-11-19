<?php
/*
 * Minimum commits (functions/mit_minimum.php, 26.11.8.9 - the user's request: a $300 minimum topped up by one line until the seats pass it).
 * Through ITFlow's own functions: a recurring invoice of 2 workstations + 2 users and a "Minimum commit" line at 300 - the line is 100; more
 * workstations (as ITFlow's seat refresh sets) take it to 0.00 and fewer bring it back; a 20% discount is measured after the minimum and leaves
 * the commit line out; ITFlow's recurring run makes an invoice carrying the minimum; a line added there shrinks it; a paid invoice keeps it; a
 * quote's monthly minimum ignores its one-time lines and is carried to the recurring invoice its conversion makes, and to lines added to an
 * existing one; the Edit Line save refuses a bad amount and a counted seat line, and switching it off keeps the price.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitMinimumReady') || !mitMinimumReady()) {
    echo "  skip - minimum commits (M065) not here\n";
    exit(0);
}
[$client_id] = scenarioMakeClient('ZZ Minimum ' . time());
$made = ['products' => [], 'recurring' => [], 'invoices' => [], 'quotes' => []];
$product = function ($name, $price) use ($mysqli, &$made) {
    mysqli_query($mysqli, "INSERT INTO products SET product_name = '" . escapeSql($name) . "', product_price = $price, product_currency_code = 'USD', product_type = 'service', product_category_id = 0, product_tax_id = 0");
    return $made['products'][] = intval(mysqli_insert_id($mysqli));
};
$line = function ($type, $item_id) use ($mysqli) {
    $t = billingType($type);
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_quantity, item_price FROM {$t['items']} WHERE item_id = " . intval($item_id)));
    return [round(floatval($row[0] ?? -1), 2), round(floatval($row[1] ?? -1), 2)];
};
$amount = function ($type, $id) {
    $c = billingType($type)['col'];
    return round(floatval(billingRow($type, $id, false)["{$c}_amount"]), 2);
};
$commit_of = function ($type, $doc_id) use ($mysqli) {
    $ids = array_keys(mitMinimumLines($type, $doc_id));
    return $ids ? $ids[0] : 0;
};
try {
    $tag = 'ZZ min ' . time();
    $workstation = $product("$tag Workstation", 50);
    $user = $product("$tag User", 50);
    $commit = $product("$tag Minimum commit", 0);
    $error = '';

    // The recurring invoice: 2 workstations, 2 users, the commit line
    $rn = intval(scenarioValue("SELECT COALESCE(MAX(recurring_invoice_number), 0) + 1 FROM recurring_invoices"));
    mysqli_query($mysqli, "INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'REC-', recurring_invoice_number = $rn, recurring_invoice_scope = '$tag',
        recurring_invoice_frequency = 'month', recurring_invoice_next_date = CURDATE(), recurring_invoice_anchor_day = 1, recurring_invoice_status = 1, recurring_invoice_discount_amount = 0,
        recurring_invoice_amount = 0, recurring_invoice_currency_code = 'USD', recurring_invoice_auto_send = 0, recurring_invoice_category_id = 0,
        recurring_invoice_quote_id = 0, recurring_invoice_client_id = $client_id");
    $rid = $made['recurring'][] = intval(mysqli_insert_id($mysqli));
    $ws_line = billingItemSave('recurring', $rid, ['name' => "$tag Workstation", 'description' => '', 'qty' => '2', 'price' => '50', 'tax_id' => 0, 'product_id' => $workstation], 0, $error);
    billingItemSave('recurring', $rid, ['name' => "$tag User", 'description' => '', 'qty' => '2', 'price' => '50', 'tax_id' => 0, 'product_id' => $user], 0, $error);
    $commit_line = billingItemSave('recurring', $rid, ['name' => "$tag Minimum commit", 'description' => '', 'qty' => '1', 'price' => '0', 'tax_id' => 0, 'product_id' => $commit], 0, $error);

    // The Edit Line window's switch
    scenarioCheck('a minimum of 0 is refused', mitMinimumSave('recurring', $commit_line, ['mit_minimum_present' => '1', 'mit_minimum_on' => '1', 'mit_minimum' => '0']) !== '', true);
    scenarioCheck('a counted seat line can\'t be one', mitMinimumSave('recurring', $commit_line, ['mit_minimum_present' => '1', 'mit_minimum_on' => '1', 'mit_minimum' => '300', 'seat_source' => 'contacts']) !== '', true);
    scenarioCheck('... nothing kept', mitMinimumOf('recurring', $commit_line), null);
    scenarioCheck('300 kept', mitMinimumSave('recurring', $commit_line, ['mit_minimum_present' => '1', 'mit_minimum_on' => '1', 'mit_minimum' => '$300.00']), '');
    scenarioCheck('the commit line tops 200 up to 300', [$line('recurring', $commit_line), $amount('recurring', $rid)], [[1.0, 100.0], 300.0]);
    scenarioCheck('a form without the switch changes nothing', mitMinimumSave('recurring', $commit_line, ['qty' => '1']), '');

    // More workstations (as ITFlow's seat refresh sets the quantity) - met; fewer - back
    billingItemSave('recurring', $rid, ['name' => "$tag Workstation", 'description' => '', 'qty' => '5', 'price' => '50', 'tax_id' => 0], $ws_line, $error);
    scenarioCheck('5 workstations - 350, the commit line 0.00', [$line('recurring', $commit_line), $amount('recurring', $rid)], [[1.0, 0.0], 350.0]);
    billingItemSave('recurring', $rid, ['name' => "$tag Workstation", 'description' => '', 'qty' => '3', 'price' => '50', 'tax_id' => 0], $ws_line, $error);
    scenarioCheck('3 workstations - 250, topped up 50', [$line('recurring', $commit_line), $amount('recurring', $rid)], [[1.0, 50.0], 300.0]);
    scenarioCheck('the page note', strip_tags(mitMinimumLineNote(['type' => 'recurring', 'edit' => true, 'row' => ['recurring_invoice_id' => $rid]], ['item_id' => $commit_line])), 'Minimum 300.00 - other lines 250.00, topped up 50.00');

    // A 20% discount: the minimum is measured first; the commit line is not discounted
    if (function_exists('mitDiscountSave') && mitDiscountReady()) {
        mitDiscountSave('recurring', $rid, ['mit_discount_present' => '1', 'mit_discount_mode' => 'percent', 'mit_discount_percent' => '20']);
        $c = billingRow('recurring', $rid, false);
        scenarioCheck('20% of the 250 of seats, not of the commit line', [round(floatval($c['recurring_invoice_discount_amount']), 2), $line('recurring', $commit_line)[1], $amount('recurring', $rid)], [50.0, 50.0, 250.0]);
        mitDiscountSave('recurring', $rid, ['mit_discount_present' => '1', 'mit_discount_mode' => 'amount']);
        mysqli_query($mysqli, "UPDATE recurring_invoices SET recurring_invoice_discount_amount = 0 WHERE recurring_invoice_id = $rid");
        billingRecalc('recurring', $rid);
    }

    // ITFlow's recurring run - the invoice carries the minimum
    $inv = $made['invoices'][] = intval(recurringInvoiceGenerate($rid, 'Scenario', true));
    scenarioCheck('the invoice was made', $inv > 0, true);
    $inv_commit = $commit_of('invoice', $inv);
    scenarioCheck('... its commit line carries 300', $inv_commit ? mitMinimumOf('invoice', $inv_commit) : null, 300.0);
    scenarioCheck('... at 50', [$line('invoice', $inv_commit), $amount('invoice', $inv)], [[1.0, 50.0], 300.0]);
    billingItemSave('invoice', $inv, ['name' => "$tag Server", 'description' => '', 'qty' => '1', 'price' => '120', 'tax_id' => 0], 0, $error);
    scenarioCheck('a server added on the invoice - met', [$line('invoice', $inv_commit), $amount('invoice', $inv)], [[1.0, 0.0], 370.0]);

    // Paid: what it had stays
    mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Paid' WHERE invoice_id = $inv");
    mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = $inv AND item_name = '" . escapeSql("$tag Server") . "'");
    billingRecalc('invoice', $inv);
    scenarioCheck('a paid invoice\'s commit line stays', $line('invoice', $inv_commit), [1.0, 0.0]);

    // Off: the minimum goes, the price it had stays
    mitMinimumSave('recurring', $commit_line, ['mit_minimum_present' => '1']);
    scenarioCheck('switched off - no minimum, the price kept', [mitMinimumOf('recurring', $commit_line), $line('recurring', $commit_line)], [null, [1.0, 50.0]]);

    // A quote: install once 500, each month 2 workstations + a 300 commit - the one-time line doesn't count
    $qn = intval(scenarioValue("SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes"));
    mysqli_query($mysqli, "INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = $qn, quote_scope = '$tag', quote_date = CURDATE(), quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        quote_amount = 0, quote_currency_code = 'USD', quote_status = 'Draft', quote_url_key = '" . bin2hex(random_bytes(8)) . "', quote_client_id = $client_id, quote_category_id = 0");
    $qid = $made['quotes'][] = intval(mysqli_insert_id($mysqli));
    billingItemSave('quote', $qid, ['name' => "$tag Install", 'description' => '', 'qty' => '1', 'price' => '500', 'tax_id' => 0, 'frequency' => ''], 0, $error);
    billingItemSave('quote', $qid, ['name' => "$tag Workstation", 'description' => '', 'qty' => '2', 'price' => '50', 'tax_id' => 0, 'product_id' => $workstation, 'frequency' => 'month'], 0, $error);
    $q_commit = billingItemSave('quote', $qid, ['name' => "$tag Minimum commit", 'description' => '', 'qty' => '1', 'price' => '0', 'tax_id' => 0, 'product_id' => $commit, 'frequency' => 'month'], 0, $error);
    mitMinimumSave('quote', $q_commit, ['mit_minimum_present' => '1', 'mit_minimum_on' => '1', 'mit_minimum' => '300']);
    scenarioCheck('the quote\'s monthly commit tops the monthly 100 up (the install left out)', $line('quote', $q_commit), [1.0, 200.0]);
    mysqli_query($mysqli, "UPDATE quotes SET quote_status = 'Accepted' WHERE quote_id = $qid");
    $converted = quoteConvert($qid, ['date' => date('Y-m-d'), 'by' => 'Scenario'], $error);
    $made['invoices'][] = intval($converted['invoice_id'] ?? 0);
    $new_rid = intval(array_key_first($converted['recurring'] ?? []) ?? 0);
    if ($new_rid) {
        $made['recurring'][] = $new_rid;
    }
    scenarioCheck("the quote made a recurring invoice ($error)", $new_rid > 0, true);
    $r_commit = $commit_of('recurring', $new_rid);
    scenarioCheck('... whose commit line carries 300 at 200', [$r_commit ? mitMinimumOf('recurring', $r_commit) : null, $line('recurring', $r_commit), $amount('recurring', $new_rid)], [300.0, [1.0, 200.0], 300.0]);

    // Lines of a second quote added to that recurring invoice - its commit line is matched to the new one, not the one already there
    $qn++;
    mysqli_query($mysqli, "INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = $qn, quote_scope = '$tag 2', quote_date = CURDATE(), quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        quote_amount = 0, quote_currency_code = 'USD', quote_status = 'Draft', quote_url_key = '" . bin2hex(random_bytes(8)) . "', quote_client_id = $client_id, quote_category_id = 0");
    $qid2 = $made['quotes'][] = intval(mysqli_insert_id($mysqli));
    $q2_commit = billingItemSave('quote', $qid2, ['name' => "$tag Minimum commit", 'description' => '', 'qty' => '1', 'price' => '0', 'tax_id' => 0, 'product_id' => $commit, 'frequency' => 'month'], 0, $error);
    mitMinimumSave('quote', $q2_commit, ['mit_minimum_present' => '1', 'mit_minimum_on' => '1', 'mit_minimum' => '400']);
    mysqli_query($mysqli, "UPDATE quotes SET quote_status = 'Accepted' WHERE quote_id = $qid2");
    quoteConvert($qid2, ['date' => date('Y-m-d'), 'by' => 'Scenario', 'recurring' => ['month' => ['to' => $new_rid]]], $error);
    $mins = array_values(mitMinimumLines('recurring', $new_rid));
    sort($mins);
    scenarioCheck("added to the existing recurring invoice ($error) - both minimums kept on their own lines", $mins, [300.0, 400.0]);
} finally {
    foreach ([['invoices', 'invoice', 'invoice_items', 'item_invoice_id', 'history_invoice_id', 'invoices', 'invoice_id'],
              ['recurring', 'recurring', 'recurring_invoice_items', 'item_recurring_invoice_id', 'history_recurring_invoice_id', 'recurring_invoices', 'recurring_invoice_id'],
              ['quotes', 'quote', 'quote_items', 'item_quote_id', 'history_quote_id', 'quotes', 'quote_id']] as [$key, $type, $items, $fk, $history, $table, $pk]) {
        foreach ($made[$key] as $id) {
            $id = intval($id);
            if (!$id) {
                continue;
            }
            mysqli_query($mysqli, "DELETE m FROM mit_billing_minimums m JOIN $items i ON i.item_id = m.item_id WHERE m.doc_type = '$type' AND i.$fk = $id");
            mysqli_query($mysqli, "DELETE FROM $items WHERE $fk = $id");
            mysqli_query($mysqli, "DELETE FROM history WHERE $history = $id");
            mysqli_query($mysqli, "DELETE FROM $table WHERE $pk = $id");
            if (function_exists('mitDiscountReady') && mitDiscountReady()) {
                mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = '$type' AND doc_id = $id");
            }
        }
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
