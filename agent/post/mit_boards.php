<?php

/*
 * Mountaineer IT - service boards: move or escalate a ticket (functions/mit_boards.php, agent/modals/mit_board/board_move.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_board_move'])) {

    validateCSRFToken();

    $row = ticketForRequest($_POST['ticket_id'] ?? 0, 2);
    if (!ticketIsOpen($row)) {
        flashAlert("Ticket <strong>" . escapeHtml(ticketReference($row)) . "</strong> is resolved or closed - reopen it to move it.", 'error');
        redirect();
    }
    $escalate = !empty($_POST['escalate']);
    $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 2000);
    if ($escalate && $reason === '') {
        flashAlert('Say why it is being escalated.', 'error');
        redirect();
    }

    [$ok, $message] = mitBoardMove(intval($row['ticket_id']), intval($_POST['board_id'] ?? 0), $reason, !empty($_POST['unassign']), $escalate ? 'escalated' : 'moved');
    flashAlert(escapeHtml($message), $ok ? 'success' : 'error');
    redirect();
}
