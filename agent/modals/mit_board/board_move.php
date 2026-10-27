<?php

/*
 * Mountaineer IT - move a ticket to another board, or escalate it (functions/mit_boards.php, handler
 * agent/post/mit_boards.php). ?ticket_id= ; &escalate=1 asks for a reason and hands the ticket back to nobody by default.
 */

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$row = ticketForModal($_GET['ticket_id'] ?? 0, 2);
$ticket_id = intval($row['ticket_id']);
$escalate = !empty($_GET['escalate']);
$current = mitBoardOf($ticket_id);
$boards = mitBoards();

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fa fa-fw fa-<?= $escalate ? 'level-up-alt' : 'columns' ?> me-2"></i><?= $escalate ? 'Escalate' : 'Move' ?> <small class="ms-1 opacity-75"><?= escapeHtml(ticketReference($row)) ?></small>
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">
        <input type="hidden" name="escalate" value="<?= $escalate ? 1 : 0 ?>">

        <p class="mb-3">Now on <?= mitBoardStyle() . mitBoardBadge($current) ?></p>

        <div class="mb-3">
            <label><?= $escalate ? 'Escalate to' : 'Move to' ?></label>
            <div class="input-group">
                <span class="input-group-text"><i class="fa fa-fw fa-columns"></i></span>
                <select class="form-select" name="board_id" required>
                    <?php foreach ($boards as $id => $board) { if ($id === $current) { continue; } ?>
                        <option value="<?= $id ?>"><?= escapeHtml($board['board_name']) ?><?= $board['board_description'] !== '' ? ' - ' . escapeHtml($board['board_description']) : '' ?></option>
                    <?php } ?>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label><?= $escalate ? 'Why' : 'Note' ?><?= $escalate ? ' <span class="text-danger">*</span>' : ' <span class="text-body-secondary small">(optional)</span>' ?></label>
            <textarea class="form-control" name="reason" rows="3" maxlength="2000" <?= $escalate ? 'required' : '' ?>
                placeholder="<?= $escalate ? 'What was tried, and what the next board should look at' : 'Why it belongs there' ?>"></textarea>
            <div class="form-text">Added to the ticket as an internal note and sent to the board's members.</div>
        </div>

        <?php if (intval($row['ticket_assigned_to'])) { ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="unassign" value="1" id="mitBoardUnassign" <?= $escalate ? 'checked' : '' ?>>
                <label class="form-check-label" for="mitBoardUnassign">Hand it back to nobody (now <?= escapeHtml($row['user_name'] ?? 'assigned') ?>), so the new board picks it up</label>
            </div>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_board_move" class="btn btn-primary"><i class="fa fa-check me-2"></i><?= $escalate ? 'Escalate' : 'Move' ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
