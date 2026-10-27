<?php

/*
 * Mountaineer IT - Admin > Settings > Boards (admin/mit_boards.php, functions/mit_boards.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_board_save'])) {

    validateCSRFToken();

    $board_id = intval($_POST['board_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 100) {
        flashAlert('A board needs a name (up to 100 characters).', 'error');
        redirect();
    }
    $name_sql = escapeSql($name);
    $description = escapeSql(mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255));
    $color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($_POST['color'] ?? '')) ? strtolower($_POST['color']) : '#2f6fde';
    $icon = in_array($_POST['icon'] ?? '', MIT_BOARD_ICONS, true) ? $_POST['icon'] : 'columns';
    $order = max(0, min(999, intval($_POST['order'] ?? 0)));

    if ($board_id && !isset(mitBoards(true)[$board_id])) {
        redirect();
    }
    $was_dealing = $board_id && (mitBoards(true)[$board_id]['board_assign'] ?? 'off') === 'round_robin';
    if ($board_id) {
        mysqli_query($mysqli, "UPDATE mit_boards SET board_name = '$name_sql', board_description = '$description', board_color = '$color', board_icon = '$icon',
            board_order = $order WHERE board_id = $board_id");
    } else {
        mysqli_query($mysqli, "INSERT INTO mit_boards SET board_name = '$name_sql', board_description = '$description', board_color = '$color', board_icon = '$icon',
            board_order = $order, board_is_default = 0");
        $board_id = mysqli_insert_id($mysqli);
    }

    // Auto-assign (M027): handing out starts with tickets placed from now on, never the backlog
    $part2 = mitBoardsPart2Ready();
    if ($part2) {
        $assign = ($_POST['assign'] ?? '') === 'round_robin' ? 'round_robin' : 'off';
        $assign_max = intval($_POST['assign_max'] ?? 0);
        $assign_max = $assign_max >= 1 ? min($assign_max, 999) : 'NULL';
        $assign_hours = empty($_POST['assign_hours']) ? 0 : 1;
        $since = $assign === 'round_robin' && !$was_dealing ? ', board_assign_since = NOW()' : '';
        mysqli_query($mysqli, "UPDATE mit_boards SET board_assign = '$assign', board_assign_max = $assign_max, board_assign_hours = $assign_hours$since WHERE board_id = $board_id");
    }

    // Members (anyone picked to be told is a member too); only agents
    $notify = array_map('intval', (array) ($_POST['notify'] ?? []));
    $no_rotation = array_map('intval', (array) ($_POST['no_rotation'] ?? []));
    $wanted = array_unique(array_merge(array_map('intval', (array) ($_POST['members'] ?? [])), $notify, $no_rotation));
    mysqli_query($mysqli, "DELETE FROM mit_board_members WHERE board_id = $board_id");
    foreach ($wanted as $user_id) {
        if ($user_id && mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM users WHERE user_id = $user_id AND user_type = 1 AND user_archived_at IS NULL"))) {
            mysqli_query($mysqli, "INSERT INTO mit_board_members SET board_id = $board_id, user_id = $user_id, member_notify = " . (in_array($user_id, $notify, true) ? 1 : 0)
                . ($part2 ? ', member_rotation = ' . (in_array($user_id, $no_rotation, true) ? 0 : 1) : ''));
        }
    }

    logAudit('Settings', 'Edit', "$session_name saved the $name_sql board");
    flashAlert('Board <strong>' . escapeHtml($name) . '</strong> saved.');
    redirect();
}

if (isset($_GET['mit_board_default'])) {

    validateCSRFToken();

    $board_id = intval($_GET['mit_board_default']);
    $board = mitBoards()[$board_id] ?? null;
    if ($board) {
        // Tickets with no row sat on the old default - pin them there before the default moves, so none jump boards
        $old = mitBoardDefaultId();
        if ($old && $old !== $board_id) {
            mysqli_query($mysqli, "INSERT IGNORE INTO mit_ticket_boards (ticket_id, board_id, board_set_by, board_set_how)
                SELECT ticket_id, $old, 0, 'routed' FROM tickets");
        }
        mysqli_query($mysqli, "UPDATE mit_boards SET board_is_default = (board_id = $board_id)");
        logAudit('Settings', 'Edit', "$session_name made " . escapeSql($board['board_name']) . " the default board");
        flashAlert('New tickets that match no rule now land on <strong>' . escapeHtml($board['board_name']) . '</strong>.');
    }
    redirect();
}

if (isset($_GET['mit_board_archive'])) {

    validateCSRFToken();

    $board_id = intval($_GET['mit_board_archive']);
    $board = mitBoards()[$board_id] ?? null;
    if ($board && !intval($board['board_is_default'])) {
        // Its tickets go to the default board (their rows now point at it, logged as moves), its rules go
        $default = mitBoardDefaultId();
        $moved = 0;
        $sql = mysqli_query($mysqli, "SELECT ticket_id FROM mit_ticket_boards WHERE board_id = $board_id");
        while ($row = mysqli_fetch_row($sql)) {
            logTicketHistory(intval($row[0]), escapeSql("Moved to the " . (mitBoards()[$default]['board_name'] ?? 'default') . " board - the " . $board['board_name'] . " board was archived"));
            $moved++;
        }
        if (mitBoardsPart2Ready()) {
            mysqli_query($mysqli, "INSERT INTO mit_board_moves (ticket_id, from_board_id, to_board_id, move_how, moved_by)
                SELECT ticket_id, $board_id, $default, 'archived', " . intval($session_user_id) . " FROM mit_ticket_boards WHERE board_id = $board_id");
        }
        mysqli_query($mysqli, "UPDATE mit_ticket_boards SET board_id = $default, board_set_at = NOW(), board_set_by = " . intval($session_user_id) . ", board_set_how = 'moved' WHERE board_id = $board_id");
        mysqli_query($mysqli, "DELETE FROM mit_board_rules WHERE board_id = $board_id");
        mysqli_query($mysqli, "UPDATE mit_boards SET board_archived_at = NOW() WHERE board_id = $board_id");
        logAudit('Settings', 'Archive', "$session_name archived the " . escapeSql($board['board_name']) . " board ($moved tickets moved)");
        flashAlert('Board <strong>' . escapeHtml($board['board_name']) . "</strong> archived - $moved ticket(s) moved to the default board.");
    }
    redirect();
}

if (isset($_GET['mit_board_restore'])) {

    validateCSRFToken();

    $board_id = intval($_GET['mit_board_restore']);
    mysqli_query($mysqli, "UPDATE mit_boards SET board_archived_at = NULL WHERE board_id = $board_id");
    flashAlert('Board restored.');
    redirect();
}

if (isset($_POST['mit_board_rule_add'])) {

    validateCSRFToken();

    $kind = (string) ($_POST['rule_kind'] ?? '');
    $board_id = intval($_POST['board_id'] ?? 0);
    $match = trim((string) ($_POST['rule_match'] ?? ''));
    if (!isset(MIT_BOARD_RULE_KINDS[$kind]) || !isset(mitBoards()[$board_id])) {
        redirect();
    }
    if ($kind === 'mailbox') {
        $match = strtolower($match);
        if (!filter_var($match, FILTER_VALIDATE_EMAIL)) {
            flashAlert('That is not an email address.', 'error');
            redirect();
        }
    } elseif ($kind === 'client') {
        $match = (string) intval($match);
        if (!mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM clients WHERE client_id = " . intval($match)))) {
            redirect();
        }
    } else {
        $match = (string) intval($match);
        if (!mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM categories WHERE category_type = 'Ticket' AND category_id = " . intval($match)))) {
            redirect();
        }
    }
    $match_sql = mysqli_real_escape_string($mysqli, $match);
    mysqli_query($mysqli, "INSERT INTO mit_board_rules SET rule_kind = '$kind', rule_match = '$match_sql', board_id = $board_id
        ON DUPLICATE KEY UPDATE board_id = $board_id");
    logAudit('Settings', 'Edit', "$session_name added a board routing rule ($kind $match_sql)");
    flashAlert('Rule saved - it applies to new tickets.');
    redirect('mit_boards.php?tab=routing');
}

if (isset($_GET['mit_board_rule_delete'])) {

    validateCSRFToken();

    mysqli_query($mysqli, "DELETE FROM mit_board_rules WHERE rule_id = " . intval($_GET['mit_board_rule_delete']));
    flashAlert('Rule removed.');
    redirect('mit_boards.php?tab=routing');
}

if (isset($_GET['mit_board_place_category'])) {

    validateCSRFToken();

    $moved = mitBoardPlaceByCategory();
    logAudit('Settings', 'Edit', "$session_name placed $moved open ticket(s) on boards by their category");
    flashAlert("$moved open ticket(s) placed on their category's board.");
    redirect('mit_boards.php?tab=routing');
}
