<?php
/*
 * Mountaineer IT - bulk actions on the invoices list (functions/mit_invoice_bulk.php; window agent/modals/mit_invoice/bulk.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_invoice_bulk'])) {

    validateCSRFToken();

    $action = (string) ($_POST['mit_bulk_action'] ?? '');
    if (!isset(mitInvoiceBulkActions()[$action])) {
        flashAlert('Unknown bulk action.', 'error');
        redirect();
    }
    enforceUserPermission('module_sales', $action === 'delete' ? 3 : 2);
    if ($action === 'pay') {
        enforceBillingMoney('take');
    }
    if (!mitInvoiceBulkCan($action)) {
        flashAlert($action === 'email' ? 'Email is not set up, or QuickBooks Online emails the invoices.' : 'That bulk action is not available.', 'error');
        redirect();
    }

    $opts = [];
    if ($action === 'sent') {
        $opts['sent_method'] = (string) ($_POST['sent_method'] ?? '');
        if (!in_array($opts['sent_method'], getSentMethods(), true)) {
            flashAlert('Invalid delivery method', 'error');
            redirect();
        }
        $opts['note'] = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
    }
    if ($action === 'pay') {
        $opts['date'] = (string) ($_POST['date'] ?? '');
        $opts['method'] = trim((string) ($_POST['payment_method'] ?? ''));
        $opts['reference'] = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 200);
        $opts['account'] = intval($_POST['account'] ?? 0);
        $opts['email_receipt'] = !empty($_POST['email_receipt']);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $opts['date']) || $opts['method'] === '' || !isset(paymentAccounts()[$opts['account']])) {
            flashAlert('Pick the date, the method and the account the money went into.', 'error');
            redirect();
        }
    }

    // A long run (hundreds of invoices, PDFs for email) keeps going past PHP's usual limit
    @set_time_limit(0);
    $ids = isset($_POST['mit_bulk_set']) ? mitInvoiceBulkRecall($_POST['mit_bulk_set']) : (array) ($_POST['invoice_ids'] ?? []);
    if (!$ids) {
        flashAlert('The invoices picked are no longer remembered - tick them again.', 'error');
        redirect();
    }
    $result = mitInvoiceBulkRun($action, $ids, $opts);
    flashAlert(mitInvoiceBulkMessage($action, $result), $result['done'] ? 'success' : 'error');

    redirect();

}
