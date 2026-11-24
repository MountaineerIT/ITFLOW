<?php
/*
 * One client's billing from start to finish, through every billing feature of ours together on ITFlow 26.12.1 (Nov 23 - the user asked
 * that all the invoice / QuickBooks / billing work still holds after the latest updates). Each piece has its own scenario; this one
 * checks they still fit together:
 *  - a recurring invoice with a line counted by an integration (usage), a "Never discounted" licence, a minimum-commit line and a 10%
 *    discount - the minimum tops it up, the discount leaves the licence and the commit line out;
 *  - the count goes up: ITFlow's seat refresh moves the line, the minimum drops to 0.00, the discount follows;
 *  - ITFlow's recurring run makes the invoice, carrying the count, the minimum and the percentage;
 *  - a ticket part billed onto that draft (To Bill's mitPartsBill) - the discount takes it in;
 *  - approved, batched and sent to QuickBooks (the stand-in): the QuickBooks invoice's lines less its discount = ITFlow's amount;
 *    once there, ITFlow's line edits and parts billing are refused;
 *  - ITFlow's API (invoices/read, as of 26.12.1.1) reports the percentage and the QuickBooks state;
 *  - paid in full in QuickBooks: the sync (cron/mit_qbo_sync.php, as cron runs it) books one payment for ITFlow's whole amount -> Paid,
 *    and a paid invoice keeps its discount and minimum when it is recalculated.
 * Leaves QuickBooks as it found it and resets the stand-in.
 */
require __DIR__ . '/bootstrap.php';
$root = getcwd();
const MOCK = 'http://127.0.0.1/qbo-mock/index.php';
$mock = function ($path) {
    return json_decode((string) @file_get_contents(MOCK . $path), true);
};
foreach (['mitDiscountReady', 'mitMinimumReady', 'mitUsageReady', 'mitQboReady', 'mitPartsBill'] as $fn) {
    if (!function_exists($fn) || ($fn !== 'mitPartsBill' && !$fn())) {
        echo "  skip - $fn is not ready here\n";
        exit(0);
    }
}
if (!defined('MIT_QBO_API_BASE') || !$mock('/_state')) {
    echo "  skip - no QuickBooks stand-in on this install\n";
    exit(0);
}
$q1 = function ($sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
$was = ['refresh' => mitSetting('qbo_refresh_token', ''), 'fallback' => mitSetting('qbo_fallback_item', ''), 'tax_style' => mitSetting('qbo_tax_style', 'us'),
    'account' => mitSetting('qbo_payment_account', ''), 'income' => mitSetting('qbo_default_account', '')];
$made = ['products' => [], 'recurring' => [], 'invoices' => [], 'batches' => [], 'keys' => []];
$stamp = date('His');
$tag = "ZZ chain $stamp";
[$client_id, $contact_id] = scenarioMakeClient("ZZ Billing chain $stamp");
$metric = "zz chain ws $stamp";
$amount = function ($type, $id) {
    $c = billingType($type)['col'];
    return round(floatval(billingRow($type, $id, false)["{$c}_amount"]), 2);
};
$discount = function ($type, $id) {
    $c = billingType($type)['col'];
    return round(floatval(billingRow($type, $id, false)["{$c}_discount_amount"]), 2);
};
$price = function ($type, $item_id) use ($q1) {
    return round(floatval($q1("SELECT item_price FROM " . billingType($type)['items'] . " WHERE item_id = " . intval($item_id))), 2);
};
$product = function ($name, $price) use ($mysqli, &$made) {
    mysqli_query($mysqli, "INSERT INTO products SET product_name = '" . escapeSql($name) . "', product_price = $price, product_currency_code = 'USD', product_type = 'service',
        product_category_id = 0, product_tax_id = 0");
    return $made['products'][] = intval(mysqli_insert_id($mysqli));
};
try {
    // QuickBooks: the stand-in, connected as the QuickBooks scenarios do
    $mock('/_reset');
    if (mitSetting('qbo_client_id', '') === '' || mitDecryptSecret((string) mitSetting('qbo_client_secret', '')) !== 'qbo-secret') {
        mitSetSetting('qbo_client_id', 'qbo-client');
        mitSetSetting('qbo_client_secret', mitEncryptSecret('qbo-secret'));
    }
    mitSetSetting('qbo_env', 'sandbox');
    scenarioCheck('connected to the QuickBooks stand-in', mitQboConnect('code-scenario', '9130355'), 'Sandbox Company_US_1');
    mitSetSetting('qbo_payment_account', intval($q1("SELECT account_id FROM accounts ORDER BY account_id LIMIT 1")));
    mitSetSetting('qbo_tax_style', 'us');
    mitSetSetting('qbo_fallback_item', '2');
    mitSetSetting('qbo_default_account', '79'); // a default income account, as any real set-up has (the stand-in's Services income)

    $ws = $product("$tag Managed workstation", 50);
    $licence = $product("$tag Licence", 20);
    $commit = $product("$tag Minimum commit", 0);
    mitDiscountProductSave($licence, ['mit_no_discount_present' => '1', 'mit_no_discount' => '1']);
    scenarioCheck('the licence is "Never discounted"', mitDiscountProductExcluded($licence), true);

    echo "the recurring invoice\n";
    mitUsageRecordCount($client_id, $metric, 3, date('Y-m-d'), 'api', 'scenario');
    $rn = intval($q1("SELECT COALESCE(MAX(recurring_invoice_number), 0) + 1 FROM recurring_invoices"));
    mysqli_query($mysqli, "INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'REC-', recurring_invoice_number = $rn, recurring_invoice_scope = '$tag',
        recurring_invoice_frequency = 'month', recurring_invoice_next_date = CURDATE(), recurring_invoice_anchor_day = 1, recurring_invoice_status = 1, recurring_invoice_discount_amount = 0,
        recurring_invoice_amount = 0, recurring_invoice_currency_code = 'USD', recurring_invoice_auto_send = 0, recurring_invoice_category_id = 0,
        recurring_invoice_quote_id = 0, recurring_invoice_client_id = $client_id");
    $rid = $made['recurring'][] = intval(mysqli_insert_id($mysqli));
    $error = '';
    $l_ws = billingItemSave('recurring', $rid, ['name' => "$tag Managed workstation", 'description' => '', 'qty' => '1', 'price' => '50', 'tax_id' => 0, 'product_id' => $ws,
        'seat_source' => 'usage', 'seat_metric' => $metric, 'seat_minimum' => '0'], 0, $error);
    scenarioCheck("counted line saved ($error)", $l_ws > 0, true);
    billingItemSave('recurring', $rid, ['name' => "$tag Licence", 'description' => '', 'qty' => '2', 'price' => '20', 'tax_id' => 0, 'product_id' => $licence], 0, $error);
    $l_commit = billingItemSave('recurring', $rid, ['name' => "$tag Minimum commit", 'description' => '', 'qty' => '1', 'price' => '0', 'tax_id' => 0, 'product_id' => $commit], 0, $error);
    scenarioCheck('minimum 300 kept', mitMinimumSave('recurring', $l_commit, ['mit_minimum_present' => '1', 'mit_minimum_on' => '1', 'mit_minimum' => '300']), '');
    mitDiscountSave('recurring', $rid, ['mit_discount_present' => '1', 'mit_discount_mode' => 'percent', 'mit_discount_percent' => '10']);
    // 3 x 50 + 2 x 20 = 190, topped up 110 to 300; 10% of the 150 of workstations (not the licence, not the commit line) = 15
    scenarioCheck('3 counted: topped up 110, 10% of the workstations off -> 285', [floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_ws")),
        $price('recurring', $l_commit), $discount('recurring', $rid), $amount('recurring', $rid)], [3.0, 110.0, 15.0, 285.0]);

    mitUsageRecordCount($client_id, $metric, 8, date('Y-m-d'), 'api', 'scenario');
    recurringInvoiceRefresh($rid, 'Scenario');
    // 8 x 50 + 40 = 440 > 300: commit 0.00; 10% of 400 = 40
    scenarioCheck('8 counted after ITFlow\'s refresh: commit 0.00, discount 40 -> 400', [floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_ws")),
        $price('recurring', $l_commit), $discount('recurring', $rid), $amount('recurring', $rid)], [8.0, 0.0, 40.0, 400.0]);

    echo "ITFlow's recurring run\n";
    $inv = $made['invoices'][] = intval(recurringInvoiceGenerate($rid, 'Scenario', true));
    scenarioCheck('the invoice was made, a Draft', [$inv > 0, (string) $q1("SELECT invoice_status FROM invoices WHERE invoice_id = $inv")], [true, 'Draft']);
    scenarioCheck('... carrying the 10% and the minimum', [mitDiscountRule('invoice', $inv)['percent'] ?? null, array_values(mitMinimumLines('invoice', $inv))], [10.0, [300.0]]);
    scenarioCheck('... at 400', [$discount('invoice', $inv), $amount('invoice', $inv)], [40.0, 400.0]);

    echo "a ticket part billed onto it (To Bill)\n";
    $ticket = scenarioMakeTicket($client_id, $contact_id);
    $t_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket"));
    [$ok, $msg, $part_id] = array_pad(mitTicketPartSave($t_row, ['name' => "$tag Docking station", 'quantity' => '1', 'unit_price' => '100', 'source' => 'other']), 3, 0);
    scenarioCheck("part added ($msg)", $ok, true);
    $billed = mitPartsBill($client_id, mitTicketParts($ticket), [], $inv, ['date' => date('Y-m-d'), 'by' => 'Scenario']);
    scenarioCheck('billed onto the same draft (' . ($billed['error'] ?? '') . ')', intval($billed['invoice_id']), $inv);
    // 400 + 40 + 100 = 540; 10% of 500 = 50 -> 490
    scenarioCheck('the discount takes the part in: 50 off -> 490', [$discount('invoice', $inv), $amount('invoice', $inv)], [50.0, 490.0]);

    echo "QuickBooks\n";
    [$approved] = mitInvoiceApprove([$inv]);
    scenarioCheck('approved', $approved, 1);
    $batch = $made['batches'][] = mitQboBatchCreate('ZZ chain batch', false);
    [$added] = mitQboBatchAdd($batch, [$inv]);
    [$sent, $failed] = $added ? mitQboBatchSend($batch) : [0, []];
    scenarioCheck('sent (' . json_encode($failed) . ')', $sent, 1);
    $state = $mock('/_state');
    $qbo = end($state['invoices']) ?: ['Line' => [], 'Id' => 0];
    $sales = array_values(array_filter($qbo['Line'], function ($l) { return $l['DetailType'] === 'SalesItemLineDetail'; }));
    $disc = array_sum(array_map(function ($l) { return (float) $l['Amount']; }, array_filter($qbo['Line'], function ($l) { return $l['DetailType'] === 'DiscountLineDetail'; })));
    $lines = array_sum(array_map(function ($l) { return (float) $l['Amount']; }, $sales));
    scenarioCheck('QuickBooks gets the lines (540) and the discount (50)', [round($lines, 2), round($disc, 2)], [540.0, 50.0]);
    scenarioCheck('... so its total is ITFlow\'s 490', round($lines - $disc, 2), $amount('invoice', $inv));
    scenarioCheck('ITFlow: Sent, its entry sent', [(string) $q1("SELECT invoice_status FROM invoices WHERE invoice_id = $inv"),
        (string) $q1("SELECT entry_status FROM mit_qbo_batch_invoices WHERE invoice_id = $inv ORDER BY entry_id DESC LIMIT 1")], ['Sent', 'sent']);
    scenarioCheck('locked in ITFlow while in QuickBooks', mitQboInvoiceLock($inv) !== '', true);
    [$ok2, , $part2] = array_pad(mitTicketPartSave($t_row, ['name' => "$tag Cable", 'quantity' => '1', 'unit_price' => '10', 'source' => 'other']), 3, 0);
    $refused = mitPartsBill($client_id, array_intersect_key(mitTicketParts($ticket), [$part2 => 1]), [], $inv, ['date' => date('Y-m-d'), 'by' => 'Scenario']);
    scenarioCheck('more parts onto it refused', intval($refused['invoice_id']), 0);

    echo "ITFlow's API reports it\n";
    $key = 'zzscen' . bin2hex(random_bytes(12));
    $admin = intval($q1("SELECT user_id FROM users JOIN user_roles ON role_id = user_role_id WHERE role_is_admin = 1 AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_id LIMIT 1"));
    mysqli_query($mysqli, "INSERT INTO api_keys SET api_key_name = 'ZZ scen chain', api_key_secret = '$key', api_key_decrypt_hash = '', api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = $admin");
    $made['keys'][] = mysqli_insert_id($mysqli);
    $host = (string) $GLOBALS['config_base_url'];
    $ch = curl_init("http://$host/api/v1/invoices/read.php?invoice_id=$inv");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$host:80:127.0.0.1"], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ["X-API-Key: $key"], CURLOPT_USERAGENT => 'scenario', CURLOPT_TIMEOUT => 20]);
    $api = json_decode((string) curl_exec($ch), true)['data'][0] ?? [];
    curl_close($ch);
    scenarioCheck('invoices/read: 10%, sent to QuickBooks, approved', [floatval($api['mit_discount_percent'] ?? 0), $api['mit_quickbooks']['batch_entry'] ?? null,
        $api['mit_quickbooks']['approval'] ?? null, floatval($api['invoice_amount'] ?? 0)], [10.0, 'sent', 'approved', 490.0]);

    echo "paid in QuickBooks\n";
    $mock('/_pay?id=' . $qbo['Id']);
    exec('cd ' . escapeshellarg($root) . ' && ' . (function_exists('posix_getuid') && posix_getuid() === 0 ? 'sudo -u www-data ' : '') . 'php cron/mit_qbo_sync.php 2>&1', $out, $code);
    scenarioCheck("cron mit_qbo_sync ran (exit $code)", $code, 0);
    scenarioCheck('one payment of ITFlow\'s 490 -> Paid', [round(floatval($q1("SELECT COALESCE(SUM(payment_amount), 0) FROM payments WHERE payment_invoice_id = $inv")), 2),
        (string) $q1("SELECT invoice_status FROM invoices WHERE invoice_id = $inv")], [490.0, 'Paid']);
    billingRecalc('invoice', $inv);
    scenarioCheck('a paid invoice keeps its discount and amount when recalculated', [$discount('invoice', $inv), $amount('invoice', $inv)], [50.0, 490.0]);
} finally {
    foreach ($made['invoices'] as $id) {
        foreach (['payments' => 'payment_invoice_id', 'mit_qbo_batch_invoices' => 'invoice_id', 'mit_invoice_approvals' => 'invoice_id', 'history' => 'history_invoice_id'] as $t => $c) {
            mysqli_query($mysqli, "DELETE FROM $t WHERE $c = " . intval($id));
        }
        mysqli_query($mysqli, "DELETE FROM mit_billing_minimums WHERE doc_type = 'invoice' AND item_id IN (SELECT item_id FROM invoice_items WHERE item_invoice_id = " . intval($id) . ")");
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['recurring'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_billing_minimums WHERE doc_type = 'recurring' AND item_id IN (SELECT item_id FROM recurring_invoice_items WHERE item_recurring_invoice_id = " . intval($id) . ")");
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = 'recurring' AND doc_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM recurring_invoices WHERE recurring_invoice_id = " . intval($id));
    }
    foreach ($made['batches'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_items WHERE product_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
    foreach ($made['keys'] as $id) {
        mysqli_query($mysqli, "DELETE FROM api_key_usage WHERE api_key_usage_key_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id = " . intval($id));
    }
    mysqli_query($mysqli, "DELETE FROM mit_usage_counts WHERE client_id = " . intval($client_id));
    mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE client_id = " . intval($client_id));
    mysqli_query($mysqli, "DELETE FROM mit_qbo_customers WHERE client_id = " . intval($client_id));
    if ($was['refresh'] === '') {
        mitQboDisconnect();
    }
    mitSetSetting('qbo_fallback_item', $was['fallback']);
    mitSetSetting('qbo_tax_style', $was['tax_style']);
    mitSetSetting('qbo_payment_account', $was['account']);
    mitSetSetting('qbo_default_account', $was['income']);
    $mock('/_reset');
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
