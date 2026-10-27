<?php
/*
 * The nightly job and invoices in QuickBooks Online (cron/nightly_tasks.php hooks): no ITFlow payment reminder for an invoice sent
 * to QuickBooks or marked as entered there by hand (QuickBooks reminds), and no autopay attempt for one (QuickBooks takes its
 * payments). Each beside a control invoice in no batch that does get its reminder / its autopay attempt. Runs ITFlow's nightly job
 * once (as cron would); reminder settings put back, its invoices removed.
 */
require __DIR__ . '/bootstrap.php';

if (!function_exists('mitQboReady') || !mitQboReady()) {
    echo "  skip - QuickBooks tables not here\n";
    exit(0);
}
$tag = 'ZZqbonight' . date('His');
$saved = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_send_invoice_reminders, config_invoice_reminder_days FROM settings WHERE company_id = 1"));
[$client_id, $contact_id] = scenarioMakeClient("$tag client");
mysqli_query($mysqli, "UPDATE contacts SET contact_billing = 1 WHERE contact_id = $contact_id");
mysqli_query($mysqli, "UPDATE clients SET client_reminders = 1 WHERE client_id = $client_id");

$invoice = function ($label, $due, $recurring = 0) use ($client_id, $tag) {
    $id = billingInvoiceCreate($client_id, date('Y-m-d', strtotime('-20 days')), 0, "$tag $label", 'Scenario');
    billingItemSave('invoice', $id, ['name' => "$tag $label", 'qty' => '1', 'price' => '100', 'tax_id' => 0], 0, $e);
    billingRecalc('invoice', $id);
    global $mysqli;
    mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Sent', invoice_due = '$due', invoice_recurring_invoice_id = $recurring WHERE invoice_id = $id");
    return $id;
};
$history = function ($invoice_id, $like) {
    return intval(scenarioValue("SELECT COUNT(*) FROM history WHERE history_invoice_id = $invoice_id AND history_description LIKE '" . escapeSql($like) . "'"));
};
$batches = [];
$in_quickbooks = function ($invoice_id) use ($mysqli, $tag, &$batches) {
    mysqli_query($mysqli, "INSERT INTO mit_qbo_batches SET batch_name = '$tag', batch_status = 'sent'");
    $b = mysqli_insert_id($mysqli);
    $batches[] = $b;
    mysqli_query($mysqli, "INSERT INTO mit_qbo_batch_invoices SET batch_id = $b, invoice_id = $invoice_id, entry_status = 'sent'");
};
$yesterday = date('Y-m-d', strtotime('-1 day'));
$ids = [];
try {
    mysqli_query($mysqli, "UPDATE settings SET config_send_invoice_reminders = 1, config_invoice_reminder_days = '1' WHERE company_id = 1");
    // Reminders: one late a day, three ways
    $ids['control'] = $invoice('reminder control', $yesterday);
    $ids['sent'] = $invoice('reminder in QuickBooks', $yesterday);
    $in_quickbooks($ids['sent']);
    $ids['manual'] = $invoice('reminder entered by hand', $yesterday);
    mitQboMarkManual([$ids['manual']]);
    // Autopay: due today on a recurring invoice with a card on file (a missing one - the attempt is what counts)
    mysqli_query($mysqli, "INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'R-', recurring_invoice_number = 9" . date('His') . ", recurring_invoice_scope = '$tag',
        recurring_invoice_frequency = 'month', recurring_invoice_next_date = CURDATE() + INTERVAL 1 MONTH, recurring_invoice_status = 1, recurring_invoice_amount = 100,
        recurring_invoice_currency_code = 'USD', recurring_invoice_client_id = $client_id, recurring_invoice_category_id = 0");
    $recurring = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO recurring_payments SET recurring_payment_currency_code = 'USD', recurring_payment_method = 'Stripe', recurring_payment_account_id = 1,
        recurring_payment_recurring_invoice_id = $recurring, recurring_payment_saved_payment_id = NULL");
    $ids['auto_control'] = $invoice('autopay control', date('Y-m-d'), $recurring);
    $ids['auto_sent'] = $invoice('autopay in QuickBooks', date('Y-m-d'), $recurring);
    $in_quickbooks($ids['auto_sent']);
    $before_log = intval(scenarioValue("SELECT COALESCE(MAX(log_id), 0) FROM logs"));

    $out = (string) shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php nightly_tasks.php 2>&1');
    $attempts = function ($invoice_id) use ($before_log) {
        return intval(scenarioValue("SELECT COUNT(*) FROM logs WHERE log_id > $before_log AND log_entity_id = $invoice_id AND log_description LIKE '%auto Payment%'"));
    };
    scenarioCheck('control: the late invoice is reminded (' . substr(trim($out), 0, 120) . ')', $history($ids['control'], 'Reminder%'), 1);
    scenarioCheck('sent to QuickBooks: no ITFlow reminder', $history($ids['sent'], 'Reminder%'), 0);
    scenarioCheck('entered in QuickBooks by hand: no ITFlow reminder', $history($ids['manual'], 'Reminder%'), 0);
    scenarioCheck('control: autopay tries the card on file', $attempts($ids['auto_control']) >= 1, true);
    scenarioCheck('in QuickBooks: autopay leaves it alone', $attempts($ids['auto_sent']), 0);
} finally {
    mysqli_query($mysqli, "UPDATE settings SET config_send_invoice_reminders = " . intval($saved['config_send_invoice_reminders']) . ",
        config_invoice_reminder_days = '" . escapeSql((string) $saved['config_invoice_reminder_days']) . "' WHERE company_id = 1");
    foreach ($ids as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batch_invoices WHERE invoice_id = $id");
        mysqli_query($mysqli, "DELETE FROM mit_qbo_manual WHERE invoice_id = $id");
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = $id");
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = $id");
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = $id");
    }
    foreach ($batches as $b) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = $b");
    }
    if (!empty($recurring)) {
        mysqli_query($mysqli, "DELETE FROM recurring_payments WHERE recurring_payment_recurring_invoice_id = $recurring");
        mysqli_query($mysqli, "DELETE FROM recurring_invoices WHERE recurring_invoice_id = $recurring");
    }
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%' OR email_recipient = 'zz-contact-$client_id@example.invalid'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
