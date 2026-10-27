<?php

/*
 * Mountaineer IT - reimbursements on tickets (functions/mit_reimbursements.php): log or change mileage and
 * purchases, and remove them.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

mitModuleGuard('timesheets', ['mit_reimb_']);

if (isset($_POST['mit_reimb_save'])) {

    validateCSRFToken();

    if (!mitReimbReady()) {
        flashAlert('Reimbursements need the database update - Admin > Update.', 'error');
        redirect();
    }
    $reimb_id = intval($_POST['reimb_id'] ?? 0);
    $row = null;
    if ($reimb_id) {
        $row = mitReimbRow($reimb_id);
        if (!$row || (intval($row['client_id']) && !hasClientAccess($row['client_id']))) {
            flashAlert('That reimbursement no longer exists.', 'error');
            redirect();
        }
        // Changing one follows the timesheet's rules (mitReimbWhyLocked in mitReimbSave), as removing it does - the person, or
        // whoever corrects their timesheet; no Support access needed (the ticket is not shown)
        $ticket = ticketRow($row['ticket_id']);
        if (!$ticket) {
            flashAlert('Its ticket was deleted - it can only be removed.', 'error');
            redirect();
        }
    } else {
        // Logging a new one takes the right to edit the ticket
        $ticket = ticketForRequest($_POST['ticket_id'] ?? 0, 2);
        if (!empty($ticket['ticket_closed_at'])) {
            flashAlert('Ticket ' . escapeHtml(ticketReference($ticket)) . ' is closed.', 'error');
            redirect();
        }
    }
    [$ok, $message] = mitReimbSave($ticket, $row, $_POST, $_FILES);
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}

if (isset($_GET['mit_reimb_delete'])) {

    validateCSRFToken();

    $row = mitReimbRow($_GET['mit_reimb_delete']);
    if (!$row || (intval($row['client_id']) && !hasClientAccess($row['client_id']))) {
        flashAlert('That reimbursement no longer exists.', 'error');
        redirect();
    }
    [$ok, $message] = mitReimbDelete($row);
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}
