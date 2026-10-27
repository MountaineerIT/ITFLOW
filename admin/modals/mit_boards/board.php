<?php

/*
 * Mountaineer IT - add or change a board (admin/mit_boards.php, handler admin/post/mit_boards.php). ?board_id= to change.
 */

require_once '../../includes/modal_header.php';

$board_id = intval($_GET['board_id'] ?? 0);
$board = mitBoards(true)[$board_id] ?? null;
$board_id = $board ? $board_id : 0;
$part2 = mitBoardsPart2Ready();
$member_ids = [];
$notify_ids = [];
$off_rotation_ids = [];
if ($board_id) {
    $sql = mysqli_query($mysqli, "SELECT user_id, member_notify, " . ($part2 ? 'member_rotation' : '1') . " FROM mit_board_members WHERE board_id = $board_id");
    while ($row = mysqli_fetch_row($sql)) {
        $member_ids[] = intval($row[0]);
        if (intval($row[1])) {
            $notify_ids[] = intval($row[0]);
        }
        if (!intval($row[2])) {
            $off_rotation_ids[] = intval($row[0]);
        }
    }
}
$agents = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_archived_at IS NULL ORDER BY user_name");
$agent_list = [];
while ($row = mysqli_fetch_assoc($agents)) {
    $agent_list[intval($row['user_id'])] = $row['user_name'];
}

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-columns me-2"></i><?= $board ? 'Edit ' . escapeHtml($board['board_name']) : 'New board' ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="board_id" value="<?= $board_id ?>">

        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" maxlength="100" required value="<?= escapeHtml($board['board_name'] ?? '') ?>" placeholder="e.g. Tier 2">
            </div>
            <div class="col-md-4">
                <label class="form-label">Order</label>
                <input type="number" class="form-control" name="order" min="0" max="999" value="<?= intval($board['board_order'] ?? count(mitBoards(true))) ?>">
            </div>
            <div class="col-12">
                <label class="form-label">What it is for</label>
                <input type="text" class="form-control" name="description" maxlength="255" value="<?= escapeHtml($board['board_description'] ?? '') ?>" placeholder="e.g. Escalated, harder problems">
            </div>
            <div class="col-md-6">
                <label class="form-label">Colour</label>
                <input type="color" class="form-control form-control-color w-100" name="color" value="<?= escapeHtml($board['board_color'] ?? '#2f6fde') ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Icon</label>
                <select class="form-select" name="icon">
                    <?php foreach (MIT_BOARD_ICONS as $icon) { ?>
                        <option value="<?= $icon ?>" <?= ($board['board_icon'] ?? 'columns') === $icon ? 'selected' : '' ?>><?= escapeHtml(ucwords(str_replace('-', ' ', $icon))) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Members</label>
                <select class="form-select select2" name="members[]" multiple data-placeholder="Who works this board">
                    <?php foreach ($agent_list as $user_id => $name) { ?>
                        <option value="<?= $user_id ?>" <?= in_array($user_id, $member_ids, true) ? 'selected' : '' ?>><?= escapeHtml($name) ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Their "My boards" view on Tickets and Dispatch shows this board.</div>
            </div>
            <div class="col-12">
                <label class="form-label">Tell about new and moved tickets</label>
                <select class="form-select select2" name="notify[]" multiple data-placeholder="Members who get a bell notification and an email">
                    <?php foreach ($agent_list as $user_id => $name) { ?>
                        <option value="<?= $user_id ?>" <?= in_array($user_id, $notify_ids, true) ? 'selected' : '' ?>><?= escapeHtml($name) ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Anyone picked here is also made a member. Only tickets of clients they can see.</div>
            </div>
            <?php if ($part2) { $dealing = ($board['board_assign'] ?? 'off') === 'round_robin'; ?>
                <div class="col-12"><hr class="my-1"><h6 class="mb-0"><i class="fas fa-sync-alt me-2"></i>Auto-assign</h6></div>
                <div class="col-md-7">
                    <label class="form-label">New tickets on this board</label>
                    <select class="form-select" name="assign">
                        <option value="off" <?= $dealing ? '' : 'selected' ?>>Off - members pick them up</option>
                        <option value="round_robin" <?= $dealing ? 'selected' : '' ?>>Round-robin - hand out in turn</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Skip a member holding</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="assign_max" min="1" max="999" placeholder="Any" value="<?= $board && $board['board_assign_max'] !== null ? intval($board['board_assign_max']) : '' ?>">
                        <span class="input-group-text" title="open tickets on this board">open here</span>
                    </div>
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="assign_hours" value="1" id="mitBoardAssignHours" <?= !$board || intval($board['board_assign_hours']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mitBoardAssignHours">Only in working hours (Admin &gt; SLA) - tickets that come in after hours are handed out when the day starts</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Leave out of the turn</label>
                    <select class="form-select select2" name="no_rotation[]" multiple data-placeholder="Members who are never handed tickets">
                        <?php foreach ($agent_list as $user_id => $name) { ?>
                            <option value="<?= $user_id ?>" <?= in_array($user_id, $off_rotation_ids, true) ? 'selected' : '' ?>><?= escapeHtml($name) ?></option>
                        <?php } ?>
                    </select>
                    <div class="form-text">Only unassigned tickets placed on the board from when this is turned on (or moved here unassigned) are handed out - not the tickets already waiting.
                        Also skipped: anyone on leave today (timesheets), Out of office in Outlook right now, or who can't see the ticket's client. The agent gets ITFlow's usual "assigned to you" notification.</div>
                </div>
            <?php } ?>
        </div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_board_save" class="btn btn-primary"><i class="fa fa-check me-2"></i>Save</button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
