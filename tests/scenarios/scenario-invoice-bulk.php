<?php
/*
 * Bulk actions on the invoices list are ITFlow's own since 26.12 (functions/invoice_actions.php, taken from ours). Ours on top of them:
 * - an invoice in a QuickBooks Online batch is left alone by every action, named with why (the hook in invoiceBulkWhyNot) - while
 *   the same kind of invoice outside a batch is done (the control);
 * - Send by Email is not offered while QuickBooks Online is connected - QuickBooks sends the invoices (the hook in invoiceBulkCan),
 *   checked in a fresh process each way, since mitQboConnected() keeps its answer for the request.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('invoiceBulkRun') || !function_exists('mitQboReady') || !mitQboReady()) {
    echo "  FAIL ITFlow's bulk actions or our QuickBooks tables are not here\n";
    exit(1);
}
$stamp = date('His') . rand(10, 99);
[$client_id, $contact_id] = scenarioMakeClient("ZZ invoice bulk $stamp");
$made = [];
$invoice = function ($status, $amount) use ($mysqli, $client_id, &$made) {
    $n = 900000 + count($made) + intval(date('s')) * 50;
    mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'ZZB-', invoice_number = $n, invoice_scope = 'scenario', invoice_date = CURDATE(), invoice_due = CURDATE(),
        invoice_amount = $amount, invoice_currency_code = 'USD', invoice_status = '$status', invoice_url_key = 'scenario', invoice_client_id = $client_id, invoice_category_id = 0");
    $id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = 'ZZ line', item_quantity = 1, item_price = $amount, item_subtotal = $amount, item_total = $amount, item_invoice_id = $id");
    return $made[] = $id;
};
$status = function ($id) {
    return scenarioValue("SELECT invoice_status FROM invoices WHERE invoice_id = $id");
};
$saved = ['qbo_realm_id' => mitSetting('qbo_realm_id'), 'qbo_refresh_token' => mitSetting('qbo_refresh_token')];
$batch = 0;
try {
    $free = $invoice('Sent', 250);
    $batched = $invoice('Sent', 75);
    $batched_draft = $invoice('Draft', 60);
    mysqli_query($mysqli, "INSERT INTO mit_qbo_batches SET batch_name = 'ZZ bulk $stamp', batch_status = 'sent'");
    $batch = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO mit_qbo_batch_invoices SET batch_id = $batch, invoice_id = $batched, entry_status = 'sent'");
    mysqli_query($mysqli, "INSERT INTO mit_qbo_batch_invoices SET batch_id = $batch, invoice_id = $batched_draft, entry_status = 'pending'");

    foreach (['pay', 'cancel', 'delete'] as $action) {
        $plan = invoiceBulkPlan($action, [$free, $batched]);
        $why = [];
        foreach ($plan['skip'] as [$row, $reason]) {
            $why[intval($row['invoice_id'])] = $reason;
        }
        scenarioCheck("$action: offered for the invoice outside a batch only", array_map('intval', array_column($plan['do'], 'invoice_id')), [$free]);
        scenarioCheck("$action: the batched one left alone, with why", $why[$batched] ?? '', 'in a QuickBooks Online batch');
    }
    $plan = invoiceBulkPlan('sent', [$batched_draft]);
    scenarioCheck('Mark Sent: a draft waiting in a batch is left alone too', [count($plan['do']), $plan['skip'][0][1] ?? ''], [0, 'in a QuickBooks Online batch']);

    // Run: the batched invoices are untouched, the free one is done
    $result = invoiceBulkRun('pay', [$free, $batched], ['date' => date('Y-m-d'), 'method' => 'Check', 'reference' => 'ZZ bulk', 'account' => 1]);
    scenarioCheck('Mark Paid: the free one paid', [count($result['done']), $status($free)], [1, 'Paid']);
    scenarioCheck('... the batched one has no payment and is still Sent', [scenarioValue("SELECT COUNT(*) FROM payments WHERE payment_invoice_id = $batched"), $status($batched)], ['0', 'Sent']);
    $result = invoiceBulkRun('delete', [$batched, $batched_draft]);
    scenarioCheck('Delete: nothing in a batch is deleted', [count($result['done']), scenarioValue("SELECT COUNT(*) FROM invoices WHERE invoice_id IN ($batched, $batched_draft)")], [0, '2']);
    mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET entry_status = 'removed' WHERE batch_id = $batch AND invoice_id = $batched_draft");
    $result = invoiceBulkRun('sent', [$batched_draft], ['method' => 'Hand Delivered']);
    scenarioCheck('control - taken out of the batch: Mark Sent goes through', [count($result['done']), $status($batched_draft)], [1, 'Sent']);

    // Send by Email while QuickBooks is connected (each in its own process - the answer is kept per request)
    $email_can = function () {
        return trim((string) shell_exec('php -r ' . escapeshellarg('chdir(' . var_export(getcwd(), true) . '); $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; require "config.php"; require "functions.php"; require "includes/load_global_settings.php"; '
            . '$session_is_admin = $GLOBALS["session_is_admin"] = true; $session_user_id = $GLOBALS["session_user_id"] = 1; echo invoiceBulkCan("email") ? "yes" : "no";')));
    };
    mitSetSetting('qbo_realm_id', '');
    mitSetSetting('qbo_refresh_token', '');
    scenarioCheck('control - QuickBooks not connected: Send by Email offered', $email_can(), 'yes');
    mitSetSetting('qbo_realm_id', 'zz-realm');
    mitSetSetting('qbo_refresh_token', 'zz-token');
    scenarioCheck('QuickBooks connected: Send by Email not offered', $email_can(), 'no');
} finally {
    foreach ($saved as $name => $value) {
        $value === null ? mitDeleteSetting($name) : mitSetSetting($name, $value);
    }
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
