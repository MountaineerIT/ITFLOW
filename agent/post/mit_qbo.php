<?php

/*
 * Mountaineer IT - invoice approvals and QuickBooks batches (agent/mit_invoice_approvals.php, mit_qbo_batches.php,
 * mit_qbo_batch.php, the strip on agent/invoice.php; functions/mit_qbo_billing.php). Financial edit for all of it.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_GET['mit_invoice_approve']) || isset($_GET['mit_invoice_unapprove']) || isset($_POST['mit_invoice_approve_bulk'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales');
    enforceUserPermission('module_financial', 2);

    if (isset($_GET['mit_invoice_unapprove'])) {
        $why = mitInvoiceUnapprove(intval($_GET['mit_invoice_unapprove']));
        flashAlert($why !== '' ? escapeHtml($why) : 'Approval taken back.', $why !== '' ? 'error' : 'success');
        redirect();
    }
    $ids = isset($_GET['mit_invoice_approve']) ? [intval($_GET['mit_invoice_approve'])] : array_map('intval', (array) ($_POST['invoice_ids'] ?? []));
    if (!$ids) {
        flashAlert('Tick the invoices to approve.', 'error');
        redirect();
    }
    [$approved, $refused] = mitInvoiceApprove($ids);
    $message = "$approved invoice" . ($approved === 1 ? '' : 's') . ' approved for QuickBooks.';
    if ($refused) {
        $message .= ' Not approved: ' . escapeHtml(implode('; ', array_unique($refused)));
    }
    flashAlert($message, $refused ? ($approved ? 'warning' : 'error') : 'success');
    redirect();
}

if (isset($_POST['mit_qbo_batch_add'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales');
    enforceUserPermission('module_financial', 2);

    $ids = array_map('intval', (array) ($_POST['invoice_ids'] ?? []));
    if (!$ids) {
        flashAlert('Tick the invoices to add.', 'error');
        redirect('mit_qbo_batches.php');
    }
    $batch_id = intval($_POST['batch_id'] ?? 0);
    if ($batch_id) {
        $open = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT batch_id FROM mit_qbo_batches WHERE batch_id = $batch_id AND batch_status != 'sent'"));
        if (!$open) {
            flashAlert('That batch is already sent - pick another or make a new one.', 'error');
            redirect('mit_qbo_batches.php');
        }
    } else {
        $batch_id = mitQboBatchCreate((string) ($_POST['batch_name'] ?? ''), !empty($_POST['batch_email']));
    }
    [$added, $refused] = mitQboBatchAdd($batch_id, $ids);
    if (!$added && !mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_qbo_batch_invoices WHERE batch_id = $batch_id"))) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = $batch_id AND batch_status = 'open'");
        flashAlert('Nothing added: ' . escapeHtml(implode('; ', array_unique($refused))), 'error');
        redirect('mit_qbo_batches.php');
    }
    logAudit('Invoice', 'Edit', escapeSql(stripslashes($session_name) . " added $added invoice" . ($added === 1 ? '' : 's') . " to QuickBooks batch $batch_id"));
    flashAlert("$added invoice" . ($added === 1 ? '' : 's') . ' added - they are locked until sent or taken out.'
        . ($refused ? ' Not added: ' . escapeHtml(implode('; ', array_unique($refused))) : ''), $refused ? 'warning' : 'success');
    redirect("mit_qbo_batch.php?batch_id=$batch_id");
}

if (isset($_POST['mit_qbo_batch_send'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales');
    enforceUserPermission('module_financial', 2);

    $batch_id = intval($_POST['batch_id'] ?? 0);
    try {
        [$sent, $failed] = mitQboBatchSend($batch_id);
        $message = "$sent invoice" . ($sent === 1 ? '' : 's') . ' sent to QuickBooks Online.';
        if ($failed) {
            $message .= ' ' . count($failed) . ' failed - see the reasons below and send again once fixed.';
        }
        flashAlert($message, $failed ? ($sent ? 'warning' : 'error') : 'success');
    } catch (Throwable $e) {
        flashAlert(escapeHtml($e->getMessage()), 'error');
    }
    redirect("mit_qbo_batch.php?batch_id=$batch_id");
}

if (isset($_POST['mit_qbo_entry_remove'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales');
    enforceUserPermission('module_financial', 2);

    $entry_id = intval($_POST['entry_id'] ?? 0);
    $batch_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT batch_id FROM mit_qbo_batch_invoices WHERE entry_id = $entry_id"))[0] ?? 0);
    $how = $_POST['mit_qbo_entry_remove'] === 'unlink' ? 'unlink' : 'void';
    $why = mitQboRemoveEntry($entry_id, $how);
    if ($why !== '') {
        flashAlert(escapeHtml($why), 'error');
    } else {
        logAudit('Invoice', 'Edit', escapeSql(stripslashes($session_name) . " took an invoice out of QuickBooks batch $batch_id ($how)"));
        flashAlert('Taken out of the batch - the invoice is unlocked.');
    }
    redirect($batch_id ? "mit_qbo_batch.php?batch_id=$batch_id" : 'mit_qbo_batches.php');
}

if (isset($_POST['mit_qbo_batch_edit'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales');
    enforceUserPermission('module_financial', 2);

    $batch_id = intval($_POST['batch_id'] ?? 0);
    $name = mb_substr(trim((string) ($_POST['batch_name'] ?? '')), 0, 200);
    if (mitQboBatchHiddenCount($batch_id)) {
        flashAlert('This batch also holds invoices of clients you can\'t see.', 'error');
    } elseif ($name !== '') {
        mysqli_query($mysqli, "UPDATE mit_qbo_batches SET batch_name = '" . escapeSql($name) . "', batch_email = " . (empty($_POST['batch_email']) ? 0 : 1) . "
            WHERE batch_id = $batch_id AND batch_status = 'open'");
        flashAlert('Batch saved');
    }
    redirect("mit_qbo_batch.php?batch_id=$batch_id");
}

if (isset($_POST['mit_qbo_batch_delete'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales');
    enforceUserPermission('module_financial', 2);

    $batch_id = intval($_POST['batch_id'] ?? 0);
    $why = mitQboBatchDelete($batch_id);
    if ($why !== '') {
        flashAlert(escapeHtml($why), 'error');
        redirect("mit_qbo_batch.php?batch_id=$batch_id");
    }
    logAudit('Invoice', 'Delete', escapeSql(stripslashes($session_name) . " deleted QuickBooks batch $batch_id"));
    flashAlert('Batch deleted - its invoices are unlocked (and still approved).');
    redirect('mit_qbo_batches.php');
}

if (isset($_GET['mit_qbo_mark_manual']) || isset($_POST['mit_qbo_mark_manual']) || isset($_GET['mit_qbo_unmark_manual']) || isset($_POST['mit_qbo_unmark_manual_bulk'])) {

    validateCSRFToken();
    enforceUserPermission('module_sales');
    enforceUserPermission('module_financial', 2);

    // Undo: one from the invoice page, or ticked on Invoice Approvals' "Already in QuickBooks" list
    if (isset($_GET['mit_qbo_unmark_manual']) || isset($_POST['mit_qbo_unmark_manual_bulk'])) {
        $ids = isset($_GET['mit_qbo_unmark_manual']) ? [intval($_GET['mit_qbo_unmark_manual'])] : array_map('intval', (array) ($_POST['invoice_ids'] ?? []));
        if (!$ids) {
            flashAlert('Tick the invoices to put back.', 'error');
            redirect();
        }
        $problems = [];
        foreach ($ids as $id) {
            if (($why = mitQboUnmarkManual($id)) !== '') {
                $problems[] = $why;
            }
        }
        flashAlert($problems ? escapeHtml(implode(' ', array_unique($problems))) : (count($ids) === 1 ? 'Back in the QuickBooks queue.' : count($ids) . ' invoices are back in the QuickBooks queue.'),
            $problems ? 'error' : 'success');
        redirect();
    }

    $ids = isset($_GET['mit_qbo_mark_manual']) ? [intval($_GET['mit_qbo_mark_manual'])] : array_map('intval', (array) ($_POST['invoice_ids'] ?? []));
    if (!$ids) {
        flashAlert('Tick the invoices that are already in QuickBooks.', 'error');
        redirect();
    }
    [$marked, $refused] = mitQboMarkManual($ids);
    $message = "$marked invoice" . ($marked === 1 ? '' : 's') . ' marked as already in QuickBooks - out of the approval and batch lists.';
    if ($refused) {
        $message .= ' Not marked: ' . escapeHtml(implode('; ', array_unique($refused)));
    }
    flashAlert($message, $refused ? ($marked ? 'warning' : 'error') : 'success');
    redirect();
}
