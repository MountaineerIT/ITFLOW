<?php

/*
 * API - Service boards - Move a ticket (Mountaineer IT, functions/mit_boards.php mitBoardMove - the same as the ticket page's
 * Board picker and "Escalate to another board")
 * POST /api/v1/mit_boards/move.php
 *
 * The ticket must be open. The move is in the ticket's history (and an internal note), the board's members who asked to be told
 * get a bell notification and an email, and a board with auto-assign hands an unassigned ticket to its next member. Needs Support
 * edit and access to the ticket's client.
 *
 * Parameters (POST, JSON body):
 *   ticket_id  required - The ticket
 *   board_id   required - The board to move it to
 *   escalate   optional - 1 = escalate (a reason is then required), 0 = a plain move (default)
 *   reason     optional - Why (kept as an internal note; required when escalating), up to 2,000 characters
 *   unassign   optional - 1 = hand the ticket back to nobody (so the new board picks it up)
 *   client_id  optional - The ticket's client (needed when the key's user is limited to certain clients)
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

if (!function_exists('mitBoardsReady') || !mitBoardsReady()) {
    mitApiFail(503, 'Service boards need the database update.');
}

$ticket = mitApiTicket($_POST['ticket_id'] ?? 0);
if (!ticketIsOpen($ticket)) {
    mitApiFail(409, 'The ticket is resolved or closed - reopen it to move it.');
}
$board_id = intval($_POST['board_id'] ?? 0);
if (!isset(mitBoards()[$board_id])) {
    mitApiFail(400, 'board_id is not an active board - GET mit_boards/read.php lists them.');
}
$escalate = !empty($_POST['escalate']);
$reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 2000);
if ($escalate && $reason === '') {
    mitApiFail(400, 'Say why it is being escalated (reason).');
}

[$ok, $message] = mitBoardMove(intval($ticket['ticket_id']), $board_id, $reason, !empty($_POST['unassign']), $escalate ? 'escalated' : 'moved');
if (!$ok) {
    mitApiFail(400, $message);
}
$assigned = intval(getFieldById('tickets', intval($ticket['ticket_id']), 'ticket_assigned_to'));
mitApiDone($message, ['ticket_id' => intval($ticket['ticket_id']), 'board_id' => $board_id, 'board_name' => mitBoards()[$board_id]['board_name'],
    'ticket_assigned_to' => $assigned ?: null]);
