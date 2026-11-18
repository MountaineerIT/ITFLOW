<?php
/*
 * Bulk actions on the invoices list (functions/mit_invoice_bulk.php, 26.11.8.5): each action goes only to the invoices ITFlow's own button
 * would be offered for, and the rest are named with why - a QuickBooks batch, a draft that can't be paid, nothing owed, already sent /
 * cancelled. Mark Paid pays exactly what is owed (a part-paid invoice gets the rest), ITFlow's status follows; Mark Sent / Cancel / Delete
 * do what ITFlow's handlers do (history, delete takes lines, payments and history, tickets billed on it unbilled again); a client out of
 * the agent's reach is left alone.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitInvoiceBulkRun')) {
    echo "  skip - bulk actions not loaded\n";
    exit(0);
}
$stamp = date('His') . rand(10, 99);
[$client_id, $contact_id] = scenarioMakeClient("ZZ invoice bulk $stamp");
$made = [];
$invoice = function ($status, $amount, $paid = 0) use ($mysqli, $client_id, &$made) {
    $n = 900000 + count($made) + intval(date('s')) * 50;
    mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'ZZB-', invoice_number = $n, invoice_scope = 'scenario', invoice_date = CURDATE(), invoice_due = CURDATE(),
        invoice_amount = $amount, invoice_currency_code = 'USD', invoice_status = '$status', invoice_url_key = 'scenario', invoice_client_id = $client_id, invoice_category_id = 0");
    $id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = 'ZZ line', item_quantity = 1, item_price = $amount, item_subtotal = $amount, item_total = $amount, item_invoice_id = $id");
    if ($paid) {
        mysqli_query($mysqli, "INSERT INTO payments SET payment_date = CURDATE(), payment_amount = $paid, payment_currency_code = 'USD', payment_account_id = 1, payment_method = 'Check', payment_invoice_id = $id");
    }
    return $made[] = $id;
};
$status = function ($id) {
    return scenarioValue("SELECT invoice_status FROM invoices WHERE invoice_id = $id");
};
$batch = 0;
try {
    $draft = $invoice('Draft', 100);
    $sent = $invoice('Sent', 250);
    $partial = $invoice('Partial', 100, 40);
    $paid = $invoice('Paid', 50, 50);
    $zero = $invoice('Sent', 0);
    $batched = $invoice('Sent', 75);
    if (mitQboReady()) {
        mysqli_query($mysqli, "INSERT INTO mit_qbo_batches SET batch_name = 'ZZ bulk $stamp', batch_status = 'sent'");
        $batch = mysqli_insert_id($mysqli);
        mysqli_query($mysqli, "INSERT INTO mit_qbo_batch_invoices SET batch_id = $batch, invoice_id = $batched, entry_status = 'sent'");
    }
    $all = [$draft, $sent, $partial, $paid, $zero, $batched];

    // The window's plan
    $plan = mitInvoiceBulkPlan('pay', $all);
    scenarioCheck('Mark Paid offered for the open invoices that owe something', array_map('intval', array_column($plan['do'], 'invoice_id')), [$sent, $partial]);
    $why = [];
    foreach ($plan['skip'] as [$row, $reason]) {
        $why[intval($row['invoice_id'])] = $reason;
    }
    scenarioCheck('... a draft is left alone, with why', $why[$draft] ?? '', 'a draft - mark it sent first');
    scenarioCheck('... a paid one', $why[$paid] ?? '', 'Paid');
    scenarioCheck('... one with nothing owed', $why[$zero] ?? '', 'nothing owed');
    if ($batch) {
        scenarioCheck('... one in a QuickBooks batch', $why[$batched] ?? '', 'in a QuickBooks batch');
    }

    // Mark Paid
    $result = mitInvoiceBulkRun('pay', $all, ['date' => date('Y-m-d'), 'method' => 'Check', 'reference' => 'ZZ bulk', 'account' => 1, 'email_receipt' => false]);
    scenarioCheck('two marked paid', count($result['done']), 2);
    scenarioCheck('total paid = what was owed (250 + 60)', $result['total']['USD'] ?? 0, 31000);
    scenarioCheck('the sent one is Paid', $status($sent), 'Paid');
    scenarioCheck('the part-paid one got the rest and is Paid', [$status($partial), (float) scenarioValue("SELECT SUM(payment_amount) FROM payments WHERE payment_invoice_id = $partial")], ['Paid', 100.0]);
    scenarioCheck('the payment carries the details', scenarioValue("SELECT CONCAT(payment_method, '|', payment_reference, '|', payment_account_id) FROM payments WHERE payment_invoice_id = $sent"), 'Check|ZZ bulk|1');
    scenarioCheck('the draft has no payment', scenarioValue("SELECT COUNT(*) FROM payments WHERE payment_invoice_id = $draft"), '0');
    if ($batch) {
        scenarioCheck('the batched one has no payment', scenarioValue("SELECT COUNT(*) FROM payments WHERE payment_invoice_id = $batched"), '0');
    }
    $again = mitInvoiceBulkRun('pay', [$sent], ['date' => date('Y-m-d'), 'method' => 'Check', 'reference' => '', 'account' => 1]);
    scenarioCheck('paying again does nothing (Paid)', [count($again['done']), $again['skipped']['ZZB-' . scenarioValue("SELECT invoice_number FROM invoices WHERE invoice_id = $sent")] ?? ''], [0, 'Paid']);
    scenarioCheck('the flash names what was left alone', strpos(mitInvoiceBulkMessage('pay', $result), 'Left alone:') !== false, true);

    // Mark Sent - drafts only
    $result = mitInvoiceBulkRun('sent', [$draft, $sent], ['sent_method' => 'Hand Delivered', 'note' => 'ZZ note']);
    scenarioCheck('Mark Sent: the draft only', [count($result['done']), $status($draft)], [1, 'Sent']);
    scenarioCheck('... its history says how', strpos((string) scenarioValue("SELECT history_description FROM history WHERE history_invoice_id = $draft ORDER BY history_id DESC LIMIT 1"), 'Hand Delivered') !== false, true);

    // Cancel - not paid ones
    $result = mitInvoiceBulkRun('cancel', [$draft, $sent, $batched]);
    scenarioCheck('Cancel: the open one only (paid left, batched left)', [$result['done'], $status($draft), $status($sent)], [['ZZB-' . scenarioValue("SELECT invoice_number FROM invoices WHERE invoice_id = $draft")], 'Cancelled', 'Paid']);
    if ($batch) {
        scenarioCheck('... the batched one still Sent', $status($batched), 'Sent');
    }

    // Delete - everything with it; tickets billed on it unbilled
    $ticket = scenarioMakeTicket($client_id, $contact_id, ['ticket_invoice_id' => $sent, 'ticket_billable' => 1]);
    $result = mitInvoiceBulkRun('delete', [$sent, $draft]);
    scenarioCheck('Delete: both gone', [count($result['done']), scenarioValue("SELECT COUNT(*) FROM invoices WHERE invoice_id IN ($sent, $draft)")], [2, '0']);
    scenarioCheck('... their lines, payments and history too', scenarioValue("SELECT (SELECT COUNT(*) FROM invoice_items WHERE item_invoice_id IN ($sent, $draft)) + (SELECT COUNT(*) FROM payments WHERE payment_invoice_id IN ($sent, $draft)) + (SELECT COUNT(*) FROM history WHERE history_invoice_id IN ($sent, $draft))"), '0');
    scenarioCheck('... the ticket billed on it is unbilled again', scenarioValue("SELECT ticket_invoice_id FROM tickets WHERE ticket_id = $ticket"), '0');

    // A client the agent can't reach
    $GLOBALS['session_is_admin'] = false;
    $GLOBALS['client_access_array'] = [999999999];
    $GLOBALS['client_deny_array'] = [];
    $result = mitInvoiceBulkRun('cancel', [$partial, $zero]);
    scenarioCheck('a client out of reach: nothing done', [count($result['done']), $status($zero)], [0, 'Sent']);
    scenarioCheck('... and said why', in_array('you can\'t reach its client', $result['skipped'], true), true);
    $GLOBALS['session_is_admin'] = true;
    $GLOBALS['client_access_array'] = [];
} finally {
    $GLOBALS['session_is_admin'] = true;
    $ids = implode(',', $made);
    if ($batch) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batch_invoices WHERE batch_id = $batch");
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = $batch");
    }
    mysqli_query($mysqli, "DELETE FROM payments WHERE payment_invoice_id IN ($ids)");
    mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id IN ($ids)");
    mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id IN ($ids)");
    mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id IN ($ids)");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
