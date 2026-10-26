<?php
/*
 * QuickBooks Online against the test install's stand-in (http://127.0.0.1/qbo-mock - MIT_QBO_* in config.php), on real rows:
 *  - the invoice payload: lines (qty x price), item mapping (product, name, fallback), US tax (TAX / NON + one TxnTaxCodeRef),
 *    discount line, ApplyTaxAfterDiscount, DocNumber, dates; two taxes refused; another currency refused;
 *  - approval fingerprint: approved -> changed by an ITFlow path with no hook (Admin tax rate change on drafts) -> batch refuses;
 *  - locks while queued: our parts billing, ITFlow's product reprice;
 *  - send: Draft -> Sent, entry sent; the payment sync (cron/mit_qbo_sync.php as cron runs it) brings part, then all, back;
 *  - take out: void (refused while paid there; voided -> Draft, unapproved, no sync payment) and unlink (stays, "Already in
 *    QuickBooks", payment kept and editable); sent again after a void makes a new QuickBooks invoice;
 *  - while connected the nightly job leaves invoice mail to QuickBooks.
 * Leaves QuickBooks as it found it (disconnected if it was) and resets the stand-in.
 */
require __DIR__ . '/bootstrap.php';
$root = getcwd();
const MOCK = 'http://127.0.0.1/qbo-mock/index.php';
$mock = function ($path) {
    return json_decode((string) @file_get_contents(MOCK . $path), true);
};
if (!defined('MIT_QBO_API_BASE') || !$mock('/_state')) {
    echo "  skip - no QuickBooks stand-in on this install\n";
    exit(0);
}
$client_id = scenarioClient();
$q1 = function ($sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
$was = ['realm' => mitSetting('qbo_realm_id', ''), 'refresh' => mitSetting('qbo_refresh_token', ''), 'access' => mitSetting('qbo_access_token', ''),
    'fallback' => mitSetting('qbo_fallback_item', ''), 'tax_style' => mitSetting('qbo_tax_style', 'us'), 'account' => mitSetting('qbo_payment_account', '')];
$made = ['invoices' => [], 'batches' => [], 'taxes' => [], 'products' => [], 'tickets' => []];
$invoice = function (array $lines, $discount = 0, $currency = 'USD') use ($mysqli, $client_id, &$made) {
    $id = billingInvoiceCreate($client_id, date('Y-m-d'), 0, 'ZZ scenario QBO', 'Scenario');
    $made['invoices'][] = $id;
    foreach ($lines as $line) {
        $error = '';
        if (!billingItemSave('invoice', $id, $line, 0, $error)) {
            throw new RuntimeException("line: $error");
        }
    }
    mysqli_query($mysqli, "UPDATE invoices SET invoice_discount_amount = " . floatval($discount) . ", invoice_currency_code = '" . escapeSql($currency) . "' WHERE invoice_id = $id");
    billingRecalc('invoice', $id);
    return $id;
};
$amount = function ($id) use ($q1) {
    return (float) $q1("SELECT invoice_amount FROM invoices WHERE invoice_id = " . intval($id));
};
$status = function ($id) use ($q1) {
    return (string) $q1("SELECT invoice_status FROM invoices WHERE invoice_id = " . intval($id));
};
$paid = function ($id) use ($q1) {
    return (float) $q1("SELECT COALESCE(SUM(payment_amount), 0) FROM payments WHERE payment_invoice_id = " . intval($id));
};
$entry = function ($id) use ($mysqli) {
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_qbo_batch_invoices WHERE invoice_id = " . intval($id) . " ORDER BY entry_id DESC LIMIT 1"));
};
$send = function (array $ids) use (&$made) {
    $batch = $made['batches'][] = mitQboBatchCreate('ZZ scenario batch', false);
    [$added, $refused] = mitQboBatchAdd($batch, $ids);
    [$sent, $failed] = $added ? mitQboBatchSend($batch) : [0, []];
    return [$batch, $added, $refused, $sent, $failed];
};
$cron_sync = function () use ($root) {
    exec('cd ' . escapeshellarg($root) . ' && ' . (function_exists('posix_getuid') && posix_getuid() === 0 ? 'sudo -u www-data ' : '') . 'php cron/mit_qbo_sync.php 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
};
try {
    $mock('/_reset');
    if (mitSetting('qbo_client_id', '') === '' || mitDecryptSecret((string) mitSetting('qbo_client_secret', '')) !== 'qbo-secret') {
        mitSetSetting('qbo_client_id', 'qbo-client');
        mitSetSetting('qbo_client_secret', mitEncryptSecret('qbo-secret'));
    }
    mitSetSetting('qbo_env', 'sandbox');
    $company = mitQboConnect('code-scenario', '9130355');
    scenarioCheck('connected to the stand-in', $company, 'Sandbox Company_US_1');
    $account = intval($q1("SELECT account_id FROM accounts ORDER BY account_id LIMIT 1"));
    mitSetSetting('qbo_payment_account', $account);
    mitSetSetting('qbo_tax_style', 'us');
    mitSetSetting('qbo_fallback_item', '2');

    // Fixtures: a tax mapped to QuickBooks' tax code 5, a second unmapped tax, a product mapped to item 1
    mysqli_query($mysqli, "INSERT INTO taxes SET tax_name = 'ZZ scen state 8.25', tax_percent = 8.25");
    $tax = $made['taxes'][] = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO taxes SET tax_name = 'ZZ scen other 5', tax_percent = 5");
    $tax2 = $made['taxes'][] = mysqli_insert_id($mysqli);
    taxForget();
    mitQboSetMap('tax_codes', $tax, '5', 'State tax 7%');
    mysqli_query($mysqli, "INSERT INTO products SET product_name = 'ZZ scen gadget', product_price = 66.67, product_track_stock = 0, product_type = 'product', product_cost = 40,
        product_currency_code = 'USD', product_tax_id = $tax, product_category_id = 0");
    $pid = $made['products'][] = mysqli_insert_id($mysqli);
    mitQboSetMap('items', $pid, '1', 'Widget');

    // 1. The payload
    $a = $invoice([
        ['name' => 'ZZ scen gadget', 'description' => 'Blue <b>one</b>', 'qty' => '2', 'price' => '66.67', 'tax_id' => $tax, 'product_id' => $pid],
        ['name' => 'Services', 'description' => '', 'qty' => '3', 'price' => '150', 'tax_id' => 0],
        ['name' => 'ZZ scen typed line', 'description' => '', 'qty' => '1.5', 'price' => '33.33', 'tax_id' => 0],
    ], 50);
    $itflow_amount = $amount($a);
    [$approved] = mitInvoiceApprove([$a]);
    scenarioCheck('approved', $approved, 1);
    [$batch, $added, $refused, $sent, $failed] = $send([$a]);
    scenarioCheck('sent (' . json_encode($failed + $refused) . ')', $sent, 1);
    $state = $mock('/_state');
    $qbo = end($state['invoices']);
    $sales = array_values(array_filter($qbo['Line'], function ($l) { return $l['DetailType'] === 'SalesItemLineDetail'; }));
    $disc = array_values(array_filter($qbo['Line'], function ($l) { return $l['DetailType'] === 'DiscountLineDetail'; }));
    scenarioCheck('three sales lines', count($sales), 3);
    scenarioCheck('line amounts = qty x price', array_map('floatval', array_column($sales, 'Amount')), [133.34, 450.0, 50.0]);
    scenarioCheck('items: mapped product 1, name match "Services" 2, fallback 2', array_map(function ($l) { return $l['SalesItemLineDetail']['ItemRef']['value']; }, $sales), ['1', '2', '2']);
    scenarioCheck('tax codes: TAX, NON, NON', array_map(function ($l) { return $l['SalesItemLineDetail']['TaxCodeRef']['value'] ?? ''; }, $sales), ['TAX', 'NON', 'NON']);
    scenarioCheck('one invoice tax code (5)', $qbo['TxnTaxDetail']['TxnTaxCodeRef']['value'] ?? '', '5');
    scenarioCheck('discount line 50', (float) ($disc[0]['Amount'] ?? 0), 50.0);
    scenarioCheck('tax after the discount, as ITFlow did it', $qbo['ApplyTaxAfterDiscount'] ?? null, true);
    scenarioCheck('description: tags stripped', strpos($sales[0]['Description'], '<b>') === false && strpos($sales[0]['Description'], 'Blue one') !== false, true);
    $inv_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_id = $a"));
    scenarioCheck('DocNumber, dates', [$qbo['DocNumber'], $qbo['TxnDate'], $qbo['DueDate']], [$inv_row['invoice_prefix'] . $inv_row['invoice_number'], $inv_row['invoice_date'], $inv_row['invoice_due']]);
    // ITFlow: subtotal 633.34, discount 50, tax on the gadget's share after discount
    $sub = 133.34 + 450 + 50;
    scenarioCheck('ITFlow amount = lines - discount + tax', $itflow_amount, round($sub - 50 + (float) $q1("SELECT SUM(item_tax) FROM invoice_items WHERE item_invoice_id = $a"), 2));
    scenarioCheck('ITFlow Draft -> Sent', $status($a), 'Sent');
    scenarioCheck('entry sent with the doc number', [$entry($a)['entry_status'], $entry($a)['qbo_doc_number']], ['sent', $qbo['DocNumber']]);

    // 2. Payments back - through the cron job as cron runs it
    $mock('/_pay?id=' . $qbo['Id'] . '&amount=100');
    [$code, $out] = $cron_sync();
    scenarioCheck("cron mit_qbo_sync ran (exit $code)", $code, 0);
    scenarioCheck('100 paid there -> one payment of 100', $paid($a), 100.0);
    scenarioCheck('-> Partial', $status($a), 'Partial');
    $pay = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM payments WHERE payment_invoice_id = $a"));
    scenarioCheck('payment: method, account, reference', [$pay['payment_method'], intval($pay['payment_account_id']), $pay['payment_reference']], ['QuickBooks Online', $account, 'QuickBooks invoice ' . $qbo['DocNumber']]);
    $mock('/_pay?id=' . $qbo['Id']);
    $cron_sync();
    scenarioCheck('all paid there -> ITFlow\'s whole amount paid (QuickBooks\' tax rounding aside)', $paid($a), $itflow_amount);
    scenarioCheck('-> Paid', $status($a), 'Paid');
    [$code] = $cron_sync();
    scenarioCheck('run again: nothing added', $paid($a), $itflow_amount);
    $why = mitQboRemoveEntry($entry($a)['entry_id'], 'void');
    scenarioCheck('void refused while paid there', $why !== '', true);

    // 3. Void: back to Draft, unapproved, the sync's payment gone; sent again = a new QuickBooks invoice
    $b = $invoice([['name' => 'Services', 'description' => '', 'qty' => '1', 'price' => '200', 'tax_id' => 0]]);
    mitInvoiceApprove([$b]);
    $send([$b]);
    $b_qbo = $entry($b)['qbo_invoice_id'];
    scenarioCheck('B in QuickBooks', $b_qbo !== '' && $b_qbo !== null, true);
    scenarioCheck('bank-statement import does not offer B to pay', isset(incomeImportOpenInvoices()[$b]), false);
    scenarioCheck('the nightly reminders / late fees skip B', mitQboSendsReminders($b), true);
    scenarioCheck('void: no problem', mitQboRemoveEntry($entry($b)['entry_id'], 'void'), '');
    $voided = array_values(array_filter($mock('/_state')['invoices'], function ($i) use ($b_qbo) { return $i['Id'] === $b_qbo; }))[0] ?? [];
    scenarioCheck('voided in QuickBooks', [(float) ($voided['TotalAmt'] ?? -1), $voided['PrivateNote'] ?? ''], [0.0, 'Voided']);
    scenarioCheck('ITFlow: Draft, unapproved, unlocked', [$status($b), mitInvoiceApprovalState($b), mitQboInvoiceLock($b)], ['Draft', 'none', '']);
    mitInvoiceApprove([$b]);
    [, , , $sent] = $send([$b]);
    scenarioCheck('sent again after a void', $sent, 1);
    scenarioCheck('... as a new QuickBooks invoice', $entry($b)['qbo_invoice_id'] !== $b_qbo, true);

    // 4. Unlink with a part payment: stays, marked "Already in QuickBooks", payment kept and no longer the sync's
    $mock('/_pay?id=' . $entry($b)['qbo_invoice_id'] . '&amount=50');
    mitQboPaymentSync();
    scenarioCheck('B part paid 50', $paid($b), 50.0);
    scenarioCheck('unlink: no problem', mitQboRemoveEntry($entry($b)['entry_id'], 'unlink'), '');
    scenarioCheck('unlinked: Partial, marked, unlocked', [$status($b), (bool) mitQboManualRows([$b]), mitQboInvoiceLock($b)], ['Partial', true, '']);
    scenarioCheck('payment kept', $paid($b), 50.0);
    scenarioCheck('unlinked: the sync does not touch it', mitQboTakesPayments($b), false);
    [$add] = mitQboBatchAdd(mitQboBatchCreate('ZZ scenario batch', false), [$b]);
    $made['batches'][] = intval($q1("SELECT MAX(batch_id) FROM mit_qbo_batches"));
    scenarioCheck('marked invoice cannot be batched again', $add, 0);

    // 5. Fingerprint: an ITFlow path with no hook of ours changes an approved draft - the batch refuses it
    $c = $invoice([['name' => 'Services', 'description' => '', 'qty' => '1', 'price' => '100', 'tax_id' => $tax]]);
    mitInvoiceApprove([$c]);
    scenarioCheck('C approved', mitInvoiceApprovalState($c), 'approved');
    taxUpdate($tax, ['name' => 'ZZ scen state 8.25', 'percent' => '9.5', 'description' => '', 'registration' => '', 'components' => []], true);
    taxForget();
    scenarioCheck('Admin > Taxes rate change re-taxed the draft', $amount($c), 109.5);
    scenarioCheck('-> "changed since approval"', mitInvoiceApprovalState($c), 'changed');
    [$batch, $added, $refused] = $send([$c]);
    scenarioCheck('changed invoice refused by the batch', $added, 0);

    // 6. Locks while queued (still a Draft): parts billed onto it, ITFlow's product reprice
    mitInvoiceApprove([$c]);
    $batch = $made['batches'][] = mitQboBatchCreate('ZZ scenario queued', false);
    mitQboBatchAdd($batch, [$c]);
    scenarioCheck('queued: locked', mitQboInvoiceLock($c) !== '', true);
    $tn = intval($q1("SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets"));
    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = $tn, ticket_subject = 'ZZ scenario QBO parts', ticket_details = 'x',
        ticket_priority = 'Low', ticket_status = 1, ticket_client_id = $client_id, ticket_created_by = " . intval($session_user_id));
    $tid = $made['tickets'][] = mysqli_insert_id($mysqli);
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $tid"));
    [, , $part] = mitTicketPartSave($ticket, ['name' => 'ZZ scen part', 'quantity' => '1', 'unit_price' => '10', 'source' => 'other']);
    [$billed] = mitTicketPartsBill($ticket, [$part], [], $c, '', 0);
    scenarioCheck('parts not billed onto a queued invoice', $billed, 0);
    $line_c = intval($q1("SELECT item_id FROM invoice_items WHERE item_invoice_id = $c LIMIT 1"));
    mysqli_query($mysqli, "UPDATE invoice_items SET item_product_id = $pid WHERE item_id = $line_c");
    $error = '';
    $done = productRepriceApply(["invoice:$line_c" => '999'], $error);
    scenarioCheck('ITFlow product reprice refused on a queued invoice', $done === null && $error !== '', true);
    scenarioCheck('... line unchanged', (float) $q1("SELECT item_price FROM invoice_items WHERE item_id = $line_c"), 100.0);
    scenarioCheck('take it out of the queue', mitQboRemoveEntry($entry($c)['entry_id']), '');
    scenarioCheck('unlocked again', mitQboInvoiceLock($c), '');

    // 7. Refused payloads: two taxes; another currency
    $d = $invoice([['name' => 'Services', 'qty' => '1', 'price' => '10', 'tax_id' => $tax], ['name' => 'Services', 'qty' => '1', 'price' => '10', 'tax_id' => $tax2]]);
    [, $problems] = mitQboInvoicePayload($d, mitQboList('items'));
    scenarioCheck('two taxes on one invoice refused (US)', (bool) array_filter($problems, function ($p) { return strpos($p, 'different taxes') !== false; }), true);
    $e = $invoice([['name' => 'Services', 'qty' => '1', 'price' => '10', 'tax_id' => 0]], 0, 'EUR');
    [, $problems] = mitQboInvoicePayload($e, mitQboList('items'));
    scenarioCheck('another currency refused', (bool) array_filter($problems, function ($p) { return strpos($p, 'EUR') !== false; }), true);

    // 7b. Tax per line (non-US companies): each line its own mapped code, untaxed lines the "no tax" code, no invoice tax code
    mitSetSetting('qbo_tax_style', 'line');
    mitSetSetting('qbo_no_tax_code', '');
    $f = $invoice([['name' => 'Services', 'qty' => '1', 'price' => '10', 'tax_id' => $tax2], ['name' => 'Services', 'qty' => '2', 'price' => '5', 'tax_id' => 0]]);
    [, $problems] = mitQboInvoicePayload($f, mitQboList('items'));
    scenarioCheck('line style: unmapped tax and no "untaxed" code refused', count(array_filter($problems, function ($p) { return strpos($p, 'tax code') !== false; })), 2);
    mitQboSetMap('tax_codes', $tax2, 'TAX', 'TAX');
    mitSetSetting('qbo_no_tax_code', 'NON');
    [$payload, $problems] = mitQboInvoicePayload($f, mitQboList('items'));
    scenarioCheck('line style: payload made', $problems, []);
    scenarioCheck('line style: per-line codes TAX / NON', array_map(function ($l) { return $l['SalesItemLineDetail']['TaxCodeRef']['value'] ?? ''; }, $payload['Line']), ['TAX', 'NON']);
    scenarioCheck('line style: no invoice tax code', isset($payload['TxnTaxDetail']), false);
    mitSetSetting('qbo_tax_style', 'us');
    mitSetSetting('qbo_no_tax_code', '');

    // 7c. An expired access token is refreshed (refresh token rotated) and the call goes through
    $old_refresh = mitDecryptSecret((string) mitSetting('qbo_refresh_token', ''));
    mitSetSetting('qbo_access_expires', time() - 60);
    $info = mitQboApi('GET', '/companyinfo/9130355');
    scenarioCheck('expired token: refreshed, call answered', $info['CompanyInfo']['CompanyName'] ?? '', 'Sandbox Company_US_1');
    scenarioCheck('... new expiry ahead', intval(mitSetting('qbo_access_expires', 0)) > time() + 600, true);
    scenarioCheck('... refresh token rotated and kept encrypted', mitDecryptSecret((string) mitSetting('qbo_refresh_token', '')) !== $old_refresh
        && strpos((string) mitSetting('qbo_refresh_token', ''), 'rt-') === false, true);

    // 8. While connected QuickBooks emails invoices (nightly: no ITFlow reminders / auto-send)
    scenarioCheck('QuickBooks handles invoice mail while connected', mitQboHandlesInvoiceMail(), true);
} finally {
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM payments WHERE payment_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batch_invoices WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM mit_invoice_approvals WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['batches'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = " . intval($id));
    }
    foreach ($made['tickets'] as $id) {
        mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_items WHERE product_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
    foreach (array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT tax_id FROM taxes WHERE tax_name LIKE 'ZZ scen%'")), 0) as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_tax_codes WHERE tax_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM tax_components WHERE component_tax_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM taxes WHERE tax_id = " . intval($id));
    }
    if ($was['refresh'] === '') {
        mitQboDisconnect();
    }
    mitSetSetting('qbo_fallback_item', $was['fallback']);
    mitSetSetting('qbo_tax_style', $was['tax_style']);
    mitSetSetting('qbo_payment_account', $was['account']);
    $mock('/_reset');
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
