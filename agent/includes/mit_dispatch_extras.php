<?php
/*
 * Mountaineer IT - on ITFlow's Dispatch page (one require in agent/dispatch.php, in the toolbar): the service board picker - All boards,
 * My boards (the default for an agent on one) or one board - and "Only the board's members" for the agent columns. The page's data for
 * agent/js/mit_dispatch.js rides on the picker (data-mit-dispatch). functions/mit_dispatch.php.
 */
if (!function_exists('mitDispatchBoards')) {
    return;
}
$mit_boards = mitDispatchBoards();
$mit_board_state = mitDispatchBoardState();
$mit_data = [
    'boards' => $mit_boards,
    'board' => $mit_board_state['board'],
    'boardTechs' => $mit_board_state['board_techs'],
    'me' => intval($session_user_id),
    'notify' => function_exists('mitScheduleDefaults') ? ['0' => mitScheduleDefaults(false), '1' => mitScheduleDefaults(true)] : null,
    'contactDefault' => intval($config_ticket_client_general_notifications ?? 0) === 1,
    'csrf' => $_SESSION['csrf_token'] ?? '',
];
?>
<div class="d-flex align-items-center gap-2" data-mit-dispatch="<?= escapeHtml(json_encode($mit_data)) ?>">
    <?php if ($mit_boards) { ?>
        <div class="input-group input-group-sm w-auto">
            <span class="input-group-text"><i class="fas fa-fw fa-columns"></i></span>
            <select class="form-select form-select-sm" data-mit-dispatch-board aria-label="Service board">
                <option value="all" <?= $mit_board_state['board'] === 'all' ? 'selected' : '' ?>>All boards</option>
                <?php if (array_filter($mit_boards, function ($b) use ($session_user_id) { return in_array(intval($session_user_id), $b['members'], true); })) { ?>
                    <option value="mine" <?= $mit_board_state['board'] === 'mine' ? 'selected' : '' ?>>My boards</option>
                <?php } ?>
                <?php foreach ($mit_boards as $mit_board) { ?>
                    <option value="<?= intval($mit_board['id']) ?>" <?= $mit_board_state['board'] === (string) $mit_board['id'] ? 'selected' : '' ?>><?= escapeHtml($mit_board['name']) ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="form-check form-switch small mb-0" title="Agent columns for the members of the board(s) picked">
            <input class="form-check-input" type="checkbox" role="switch" id="mitDispatchBoardTechs" data-mit-dispatch-board-techs <?= $mit_board_state['board_techs'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="mitDispatchBoardTechs">Only its members</label>
        </div>
    <?php } ?>
</div>
<script src="<?= mitAsset('/agent/js/mit_dispatch.js') ?>"></script>
