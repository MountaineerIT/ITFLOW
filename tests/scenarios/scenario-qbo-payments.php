<?php
/*
 * QuickBooks payments back into ITFlow (functions/mit_qbo_billing.php mitQboApplyPaid): more paid there = a new payment for the
 * difference (the earlier one - deposited - untouched); less paid = the newest of ours goes, a deposited / reconciled one is left with
 * a warning; an entry taken out while the sync ran gets nothing; QuickBooks sends the reminders for batched and "Already in
 * QuickBooks" invoices. No QuickBooks call is made.
 */
require __DIR__ . '/bootstrap.php';
$client_id = scenarioClient();
$account = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT account_id FROM accounts ORDER BY account_id LIMIT 1"))[0] ?? 0);
$in = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices"))[0]);
mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = $in, invoice_scope = 'scenario', invoice_date = CURDATE(), invoice_due = CURDATE(), invoice_amount = 1000,
    invoice_currency_code = 'USD', invoice_status = 'Sent', invoice_url_key = 'scenario', invoice_client_id = $client_id, invoice_category_id = 0");
$iid = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_qbo_batches SET batch_name = 'ZZ scenario', batch_status = 'sent', created_at = NOW()");
$bid = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_qbo_batch_invoices SET batch_id = $bid, invoice_id = $iid, entry_status = 'sent', fingerprint = 'scenario', amount = 1000, qbo_invoice_id = 'scenario-$iid',
    qbo_doc_number = 'INV-$in', added_at = NOW(), sent_at = NOW()");
$eid = mysqli_insert_id($mysqli);
$entry = function () use ($mysqli, $eid) {
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT e.*, invoice_amount, invoice_currency_code FROM mit_qbo_batch_invoices e JOIN invoices ON invoices.invoice_id = e.invoice_id WHERE entry_id = $eid"));
};
$payments = function () use ($mysqli, $iid) {
    return array_map('floatval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT payment_amount FROM payments WHERE payment_invoice_id = $iid ORDER BY payment_id"), MYSQLI_ASSOC), 'payment_amount'));
};
try {
    mitQboApplyPaid($entry(), 1000, 500, $account);
    scenarioCheck('half paid there: one payment of 500', $payments(), [500.0]);
    mysqli_query($mysqli, "UPDATE payments SET payment_deposit_transfer_id = 999999 WHERE payment_invoice_id = $iid"); // deposited in ITFlow
    mitQboApplyPaid($entry(), 1000, 0, $account);
    scenarioCheck('all paid: a second payment, the deposited one untouched', $payments(), [500.0, 500.0]);
    scenarioCheck('invoice Paid', (string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT invoice_status FROM invoices WHERE invoice_id = $iid"))[0], 'Paid');
    mitQboApplyPaid($entry(), 1000, 0, $account);
    scenarioCheck('run again: nothing changes', $payments(), [500.0, 500.0]);
    mitQboApplyPaid($entry(), 1000, 1000, $account);
    scenarioCheck('refunded there: the newest goes, the deposited one stays', $payments(), [500.0]);
    scenarioCheck('... with a warning on the entry', strpos((string) $entry()['warning'], 'deposited') !== false, true);
    mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET entry_status = 'removed' WHERE entry_id = $eid");
    $stale = $entry();
    $stale['entry_status'] = 'sent';
    scenarioCheck('taken out during a sync run: nothing added', mitQboApplyPaid($stale, 1000, 0, $account), false);
    mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET entry_status = 'sent' WHERE entry_id = $eid");
    scenarioCheck('in a batch: QuickBooks sends its reminders', mitQboSendsReminders($iid), true);
    if (mitQboManualReady()) {
        mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET entry_status = 'removed' WHERE entry_id = $eid");
        mysqli_query($mysqli, "INSERT INTO mit_qbo_manual SET invoice_id = $iid, marked_by = 0");
        scenarioCheck('"Already in QuickBooks": QuickBooks sends its reminders', mitQboSendsReminders($iid), true);
    }
} finally {
    mysqli_query($mysqli, "DELETE FROM payments WHERE payment_invoice_id = $iid");
    mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = $iid");
    mysqli_query($mysqli, "DELETE FROM mit_qbo_batch_invoices WHERE entry_id = $eid");
    mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = $bid");
    mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = $iid"); // mit_qbo_manual goes with it (CASCADE)
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
