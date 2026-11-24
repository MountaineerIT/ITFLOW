<?php
/*
 * Percentage discounts (functions/mit_discount.php, 26.11.8.9 - the user's request: a nonprofit discount on managed services, not on licensing,
 * that keeps recurring invoices up to date by itself). Through ITFlow's own functions: a recurring invoice at 20% of its discountable lines
 * (a "Never discounted" licence left out, the discount's own product left out), the dollars following a quantity change, an invoice made from
 * it by ITFlow's recurring run carrying the percentage, a typed line named like a never-discounted product left out, a paid invoice keeping its
 * discount, the "Discount (20%)" label, the $ / % save (a bad percentage refused, $ drops the rule and keeps ITFlow's typed dollars), "Never
 * discounted" saved through our product save, and QuickBooks posting the discount to the discount product's category account.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitDiscountReady') || !mitDiscountReady()) {
    echo "  skip - percentage discounts (M064) not here\n";
    exit(0);
}
[$client_id] = scenarioMakeClient('ZZ Nonprofit ' . time());
$made = ['products' => [], 'recurring' => [], 'invoices' => [], 'quotes' => [], 'category' => 0];
$product = function ($name, $price) use ($mysqli, &$made) {
    mysqli_query($mysqli, "INSERT INTO products SET product_name = '" . escapeSql($name) . "', product_price = $price, product_currency_code = 'USD', product_type = 'service', product_category_id = 0, product_tax_id = 0");
    return $made['products'][] = intval(mysqli_insert_id($mysqli));
};
$money = function ($type, $id) {
    $c = billingType($type)['col'];
    $row = billingRow($type, $id, false);
    return [round(floatval($row["{$c}_discount_amount"]), 2), round(floatval($row["{$c}_amount"]), 2)];
};
try {
    $tag = 'ZZ disc ' . time();
    $managed = $product("$tag Managed services", 1000);
    $licence = $product("$tag M365 licence", 20);
    $nonprofit = $product("$tag Nonprofit discount", 0);
    // "Never discounted" through our product save (the product window posts these)
    mitDiscountProductSave($licence, ['mit_no_discount_present' => '1', 'mit_no_discount' => '1']);
    scenarioCheck('"Never discounted" saved', mitDiscountProductExcluded($licence), true);
    mitDiscountProductSave($managed, ['mit_no_discount_present' => '1']);
    scenarioCheck('... and not on a product left unticked', mitDiscountProductExcluded($managed), false);

    // A recurring invoice: managed services 1 x 1,000, licences 10 x 20
    $rn = intval(scenarioValue("SELECT COALESCE(MAX(recurring_invoice_number), 0) + 1 FROM recurring_invoices"));
    mysqli_query($mysqli, "INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'REC-', recurring_invoice_number = $rn, recurring_invoice_scope = '$tag',
        recurring_invoice_frequency = 'month', recurring_invoice_next_date = CURDATE(), recurring_invoice_anchor_day = 1, recurring_invoice_status = 1, recurring_invoice_discount_amount = 0,
        recurring_invoice_amount = 0, recurring_invoice_currency_code = 'USD', recurring_invoice_auto_send = 0, recurring_invoice_category_id = 0,
        recurring_invoice_quote_id = 0, recurring_invoice_client_id = $client_id");
    $rid = $made['recurring'][] = intval(mysqli_insert_id($mysqli));
    $error = '';
    $line_managed = billingItemSave('recurring', $rid, ['name' => "$tag Managed services", 'description' => '', 'qty' => '1', 'price' => '1000', 'tax_id' => 0, 'product_id' => $managed], 0, $error);
    billingItemSave('recurring', $rid, ['name' => "$tag M365 licence", 'description' => '', 'qty' => '10', 'price' => '20', 'tax_id' => 0, 'product_id' => $licence], 0, $error);

    // The Edit window's choice: a bad percentage refused, then 20% posting as the Nonprofit discount product
    $problem = mitDiscountSave('recurring', $rid, ['mit_discount_present' => '1', 'mit_discount_mode' => 'percent', 'mit_discount_percent' => '150']);
    scenarioCheck('150% is refused', $problem !== '', true);
    scenarioCheck('... nothing kept', mitDiscountRule('recurring', $rid), null);
    mitDiscountSave('recurring', $rid, ['mit_discount_present' => '1', 'mit_discount_mode' => 'percent', 'mit_discount_percent' => '20%', 'mit_discount_product' => $nonprofit]);
    scenarioCheck('20% kept with its product', mitDiscountRule('recurring', $rid), ['percent' => 20.0, 'product_id' => $nonprofit]);
    scenarioCheck('20% of managed services only - the licences left out', $money('recurring', $rid), [200.0, 1000.0]);
    scenarioCheck('the totals read "Discount (20%)"', mitDiscountLabel('recurring', $rid), 'Discount (20%)');

    // A quantity change (as a seat refresh makes) - the dollars follow
    billingItemSave('recurring', $rid, ['name' => "$tag Managed services", 'description' => '', 'qty' => '2', 'price' => '1000', 'tax_id' => 0, 'product_id' => $managed], $line_managed, $error);
    scenarioCheck('two of managed services - the discount follows', $money('recurring', $rid), [400.0, 1800.0]);

    // ITFlow's recurring run makes the invoice - with the percentage
    $inv = $made['invoices'][] = intval(recurringInvoiceGenerate($rid, 'Scenario', true));
    scenarioCheck('the invoice was made', $inv > 0, true);
    scenarioCheck('... carrying 20% and its product', mitDiscountRule('invoice', $inv), ['percent' => 20.0, 'product_id' => $nonprofit]);
    scenarioCheck('... worked out on its own lines', $money('invoice', $inv), [400.0, 1800.0]);

    // A typed line named like the never-discounted licence (no product on it) is left out too; a typed line of no product counts
    billingItemSave('invoice', $inv, ['name' => "$tag M365 licence", 'description' => '', 'qty' => '5', 'price' => '20', 'tax_id' => 0], 0, $error);
    scenarioCheck('a typed licence line is left out by its name', $money('invoice', $inv), [400.0, 1900.0]);
    billingItemSave('invoice', $inv, ['name' => "$tag On-site visit", 'description' => '', 'qty' => '1', 'price' => '150', 'tax_id' => 0], 0, $error);
    scenarioCheck('a typed line of no product counts', $money('invoice', $inv), [430.0, 2020.0]);
    // A line of the discount's own product is never part of what it is taken from
    billingItemSave('invoice', $inv, ['name' => "$tag Nonprofit discount", 'description' => '', 'qty' => '1', 'price' => '-10', 'tax_id' => 0, 'product_id' => $nonprofit], 0, $error);
    scenarioCheck('the discount product\'s own line is left out', $money('invoice', $inv)[0], 430.0);

    // QuickBooks posts it to the discount product's category account
    mysqli_query($mysqli, "INSERT INTO categories SET category_name = '$tag Discounts', category_type = 'Income', category_color = '#999999'");
    $made['category'] = intval(mysqli_insert_id($mysqli));
    mysqli_query($mysqli, "UPDATE products SET product_category_id = {$made['category']} WHERE product_id = $nonprofit");
    if (function_exists('mitQboAccountsReady') && mitQboAccountsReady()) {
        mysqli_query($mysqli, "INSERT INTO mit_qbo_category_accounts SET category_id = {$made['category']}, qbo_account_id = '83', qbo_account_name = 'Discounts given'");
        mitQboCategoryAccounts(true);
        scenarioCheck('QuickBooks: the discount product\'s category account', mitDiscountQboAccount($inv), '83');
        scenarioCheck('... on the invoice\'s discount line', mitQboDiscountLine(430, $inv)['DiscountLineDetail']['DiscountAccountRef']['value'] ?? '', '83');
        scenarioCheck('... another invoice keeps the "Discounts go to" setting', isset(mitQboDiscountLine(5, 0)['DiscountLineDetail']['DiscountAccountRef']) === (mitQboAccountSettings()['discount_account'] !== ''), true);
    }

    // Paid: the lines are closed and the discount stays as it was, whatever changes
    mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Paid' WHERE invoice_id = $inv");
    mysqli_query($mysqli, "DELETE FROM mit_product_no_discount WHERE product_id = $licence");
    billingRecalc('invoice', $inv);
    scenarioCheck('a paid invoice keeps its discount', $money('invoice', $inv)[0], 430.0);
    mysqli_query($mysqli, "INSERT IGNORE INTO mit_product_no_discount SET product_id = $licence");

    // A quote at 20%, invoiced - the invoice carries it, worked out on its own lines
    $qn = intval(scenarioValue("SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes"));
    mysqli_query($mysqli, "INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = $qn, quote_scope = '$tag', quote_date = CURDATE(), quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        quote_amount = 0, quote_currency_code = 'USD', quote_status = 'Draft', quote_url_key = '" . bin2hex(random_bytes(8)) . "', quote_client_id = $client_id, quote_category_id = 0");
    $qid = $made['quotes'][] = intval(mysqli_insert_id($mysqli));
    billingItemSave('quote', $qid, ['name' => "$tag Managed services", 'description' => '', 'qty' => '1', 'price' => '1000', 'tax_id' => 0, 'product_id' => $managed], 0, $error);
    billingItemSave('quote', $qid, ['name' => "$tag M365 licence", 'description' => '', 'qty' => '10', 'price' => '20', 'tax_id' => 0, 'product_id' => $licence], 0, $error);
    mitDiscountSave('quote', $qid, ['mit_discount_present' => '1', 'mit_discount_mode' => 'percent', 'mit_discount_percent' => '20', 'mit_discount_product' => $nonprofit]);
    scenarioCheck('a quote at 20%', $money('quote', $qid)[0], 200.0);
    mysqli_query($mysqli, "UPDATE quotes SET quote_status = 'Accepted' WHERE quote_id = $qid");
    $converted = quoteConvert($qid, ['date' => date('Y-m-d'), 'by' => 'Scenario'], $error);
    $qinv = $made['invoices'][] = intval($converted['invoice_id'] ?? 0);
    scenarioCheck("the quote was invoiced ($error)", $qinv > 0, true);
    scenarioCheck('... the invoice at 20% of its discountable lines', [mitDiscountRule('invoice', $qinv)['percent'] ?? null, $money('invoice', $qinv)], [20.0, [200.0, 1000.0]]);

    // Back to a dollar amount: the rule goes, ITFlow's typed dollars stay
    mysqli_query($mysqli, "UPDATE recurring_invoices SET recurring_invoice_discount_amount = 75 WHERE recurring_invoice_id = $rid");
    mitDiscountSave('recurring', $rid, ['mit_discount_present' => '1', 'mit_discount_mode' => 'amount']);
    scenarioCheck('$ again: no percentage, the typed 75 kept', [mitDiscountRule('recurring', $rid), $money('recurring', $rid)[0]], [null, 75.0]);
    scenarioCheck('... the label is ITFlow\'s', mitDiscountLabel('recurring', $rid), 'Discount');
    scenarioCheck('a form without the switch changes nothing', mitDiscountSave('recurring', $rid, ['recurring_invoice_discount' => '9']), '');
} finally {
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = " . intval($id));
    }
    foreach ($made['recurring'] as $id) {
        mysqli_query($mysqli, "DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM recurring_invoices WHERE recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = 'recurring' AND doc_id = " . intval($id));
    }
    foreach ($made['quotes'] as $id) {
        mysqli_query($mysqli, "DELETE FROM quote_items WHERE item_quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM quotes WHERE quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = 'quote' AND doc_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
    if ($made['category']) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_category_accounts WHERE category_id = {$made['category']}");
        mysqli_query($mysqli, "DELETE FROM categories WHERE category_id = {$made['category']}");
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
