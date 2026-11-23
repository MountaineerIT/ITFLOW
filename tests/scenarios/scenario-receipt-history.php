<?php
/*
 * A payment receipt in an invoice's history (26.12.1): the line records the invoice's status after the payment - Paid or Partial -
 * not "Sent" (ITFlow wrote "Sent" in billingPaymentReceiptSend, Add Payment's bulk receipt and the invoices' bulk Mark Paid).
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('billingPaymentSend')) {
    echo "  skip - no billingPaymentSend here\n";
    exit(0);
}
$stamp = date('His');
[$client, $contact] = scenarioMakeClient("ZZ Receipt $stamp");
$invoices = [];
try {
    foreach ([[100, 100, 'Paid'], [100, 40, 'Partial']] as [$amount, $paid, $want]) {
        mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = " . (800000 + count($invoices) + intval($stamp)) . ", invoice_scope = 'scenario',
            invoice_date = CURDATE(), invoice_due = CURDATE(), invoice_amount = $amount, invoice_currency_code = 'USD', invoice_status = 'Sent', invoice_url_key = 'scenario',
            invoice_client_id = $client, invoice_category_id = 0");
        $invoice = mysqli_insert_id($mysqli);
        $invoices[] = $invoice;
        mysqli_query($mysqli, "INSERT INTO payments SET payment_date = CURDATE(), payment_amount = $paid, payment_currency_code = 'USD', payment_account_id = 0,
            payment_method = 'Cash', payment_reference = 'zz', payment_invoice_id = $invoice");
        updateInvoiceStatusFromPayments($invoice);
        $sent = billingPaymentSend($invoice, $paid, ['date' => date('Y-m-d'), 'method' => 'Cash', 'reference' => 'zz']);
        scenarioCheck("the receipt went ($want)", count($sent) > 0, true);
        scenarioCheck("... and its history line says $want, not Sent", scenarioValue("SELECT history_status FROM history WHERE history_invoice_id = $invoice
            AND history_description LIKE 'Payment receipt emailed%' ORDER BY history_id DESC LIMIT 1"), $want);
    }
} finally {
    foreach ($invoices as $invoice) {
        mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_recipient = 'zz-contact-$client@example.invalid'");
        mysqli_query($mysqli, "DELETE FROM payments WHERE payment_invoice_id = $invoice");
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = $invoice");
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = $invoice");
    }
    clientDeleteForGood($client);
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
