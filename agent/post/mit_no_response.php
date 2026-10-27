<?php

/*
 * Mountaineer IT - "Resolve - no response" (functions/mit_no_response.php, agent/modals/mit_no_response/no_response.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_no_response_resolve'])) {

    validateCSRFToken();

    $row = ticketForRequest($_POST['ticket_id'] ?? 0, 2);
    $ticket_id = intval($row['ticket_id']);
    if (!mitNoResponseReady()) {
        flashAlert('The database update for this is still to run - Admin > Update.', 'error');
        redirect();
    }
    if (!ticketIsOpen($row)) {
        flashAlert("Ticket <strong>" . escapeHtml(ticketReference($row)) . "</strong> is resolved or closed already.", 'error');
        redirect();
    }
    $open_tasks = ticketOpenTaskCount($ticket_id);
    if ($open_tasks) {
        flashAlert("Finish the <strong>$open_tasks</strong> open task" . ($open_tasks === 1 ? '' : 's') . " before resolving this ticket.", 'error');
        redirect();
    }
    $message = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? ''))), 0, 5000);
    if ($message === '') {
        flashAlert('Write the message to the client first.', 'error');
        redirect();
    }

    $mailed = mitNoResponseResolve($row, $message);
    flashAlert("Ticket <strong>" . escapeHtml(ticketReference($row)) . "</strong> resolved - no response" . ($mailed ? ', message emailed' : ', message added to the ticket'));
    redirect();
}
