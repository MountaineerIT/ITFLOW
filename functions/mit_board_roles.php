<?php

/*
 * Mountaineer IT - service boards by role (required from functions/mit_boards.php). Each ITFlow role (Admin > Roles) sees
 * every board, or only the boards ticked for it (Admin > Roles > Edit > Service boards; M034 mit_role_board_access /
 * mit_role_boards). Admin roles always see every board. A board a role can't see is left out of that role's lists - the
 * Tickets list, kanban and client Tickets tab, the board switcher, the dashboard, Dispatch, the calendar, global search and
 * the client overview's open tickets. Still shown on a hidden board: tickets assigned to them, tickets they help on, and
 * tickets they escalated there. Hidden, not blocked: a link still opens the ticket. New Ticket offers only the role's
 * boards; Escalate offers every board. The API (api/v1) is not filtered by board - keys work by ITFlow's module and client
 * access only. Reports (Reporting access) show every board, so the Board Performance flows add up.
 */

/** Has M034 run? */
function mitBoardRolesReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mitBoardsReady() && mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_role_boards'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The boards a role sees: null = every board, else board ids (possibly none). */
function mitBoardRoleBoards($role_id) {
    global $mysqli;
    static $cache = [];
    $role_id = intval($role_id);
    if (!mitBoardRolesReady() || !$role_id) {
        return null;
    }
    if (!array_key_exists($role_id, $cache)) {
        $limited = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT limited FROM mit_role_board_access WHERE role_id = $role_id"))[0] ?? 0);
        if (!$limited) {
            $cache[$role_id] = null;
        } else {
            $cache[$role_id] = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT board_id FROM mit_role_boards
                WHERE role_id = $role_id"), MYSQLI_ASSOC), 'board_id'));
        }
    }
    return $cache[$role_id];
}

/** The boards the signed-in agent sees: null = every board (admins, roles not limited, cron with no role). */
function mitBoardVisibleIds() {
    global $session_is_admin, $session_user_role;
    if (!empty($session_is_admin) || empty($session_user_role)) {
        return null;
    }
    return mitBoardRoleBoards($session_user_role);
}

/** The active boards the signed-in agent sees (for pickers): id => row. */
function mitBoardsVisible() {
    $ids = mitBoardVisibleIds();
    return $ids === null ? mitBoards() : array_intersect_key(mitBoards(), array_flip($ids));
}

/**
 * " AND <the ticket is on a board the agent sees, or is theirs>" for ticket queries ($alias = the tickets table's name or
 * alias), or '' when they see every board. Theirs = assigned to them, they help on it, or they escalated it there.
 */
function mitBoardVisibleSql($alias = 'tickets') {
    global $session_user_id;
    $ids = mitBoardVisibleIds();
    if ($ids === null) {
        return '';
    }
    $user_id = intval($session_user_id);
    $in = $ids ? implode(',', array_map('intval', $ids)) : '-1';
    $helpers = function_exists('mitTicketTechsReady') && mitTicketTechsReady()
        ? " OR EXISTS (SELECT 1 FROM mit_ticket_techs mbr_tt WHERE mbr_tt.ticket_id = $alias.ticket_id AND mbr_tt.user_id = $user_id)" : '';
    $escalated = mitBoardsPart2Ready()
        ? " OR EXISTS (SELECT 1 FROM mit_board_moves mbr_mv WHERE mbr_mv.ticket_id = $alias.ticket_id AND mbr_mv.move_how = 'escalated' AND mbr_mv.moved_by = $user_id)" : '';
    return " AND (" . mitBoardSql($alias) . " IN ($in) OR $alias.ticket_assigned_to = $user_id$helpers$escalated)";
}

/** Calendar items: ticket bookings on boards the agent doesn't see are left out (same rules as the lists). */
function mitBoardVisibleCalendarItems(array $items) {
    global $mysqli;
    if (mitBoardVisibleIds() === null) {
        return $items;
    }
    $ids = [];
    foreach ($items as $item) {
        if (!empty($item['extendedProps']['ticket_id'])) {
            $ids[] = intval($item['extendedProps']['ticket_id']);
        }
    }
    $ids = array_unique($ids);
    if (!$ids) {
        return $items;
    }
    $seen = array_flip(array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT ticket_id FROM tickets
        WHERE ticket_id IN (" . implode(',', $ids) . ")" . mitBoardVisibleSql('tickets')), MYSQLI_ASSOC), 'ticket_id')));
    return array_values(array_filter($items, function ($item) use ($seen) {
        $ticket_id = intval($item['extendedProps']['ticket_id'] ?? 0);
        return !$ticket_id || isset($seen[$ticket_id]);
    }));
}

/*
 * ---------- Admin > Roles ----------
 */

/** Admin > Roles > New / Edit: the Service boards section (one line in each role modal). */
function mitBoardRoleField($role_id = 0, $is_admin = false) {
    if (!mitBoardRolesReady() || !mitBoards()) {
        return '';
    }
    $boards_seen = mitBoardRoleBoards($role_id);
    $all = $boards_seen === null;
    $html = '<div class="mt-3" data-mit-role-boards><input type="hidden" name="mit_boards_present" value="1"><h6 class="mb-1"><i class="fas fa-fw fa-columns me-2"></i>Service boards</h6>'
        . '<p class="small text-muted mb-2">Which boards this role\'s agents see in their lists, counts, Dispatch, the calendar and search. '
        . 'They still see tickets assigned to them, ones they help on and ones they escalated. Admin roles always see every board.</p>'
        . '<div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="mit_boards_all" value="1" id="mitRoleBoardsAll"'
        . ($all ? ' checked' : '') . '><label class="form-check-label" for="mitRoleBoardsAll">All boards</label></div>'
        . '<div class="row g-1 ps-1' . ($all ? ' d-none' : '') . '" data-mit-role-board-list>';
    foreach (mitBoards() as $id => $board) {
        $checked = !$all && in_array($id, $boards_seen, true);
        $html .= '<div class="col-sm-6"><div class="form-check"><input class="form-check-input" type="checkbox" name="mit_boards[]" value="' . $id . '" id="mitRoleBoard' . $id . '"'
            . ($checked ? ' checked' : '') . '><label class="form-check-label" for="mitRoleBoard' . $id . '"><span class="d-inline-block rounded-circle me-1" style="width:.6rem;height:.6rem;background:'
            . escapeHtml($board['board_color']) . '"></span>' . escapeHtml($board['board_name']) . '</label></div></div>';
    }
    $html .= '</div>' . ($is_admin ? '<div class="small text-warning-emphasis mt-1">This is an admin role - it sees every board whatever is ticked.</div>' : '')
        . '<script>(function () { var s = document.getElementById("mitRoleBoardsAll"); if (!s) { return; } s.addEventListener("change", function () {'
        . ' document.querySelector("[data-mit-role-board-list]").classList.toggle("d-none", s.checked); }); })();</script></div>';
    return $html;
}

/** admin/post/roles.php add / edit (one line each): save the role's boards from the form. Returns what changed, in words ('' = nothing). */
function mitBoardRoleSave($role_id, array $post) {
    global $mysqli, $session_user_id;
    $role_id = intval($role_id);
    if (!mitBoardRolesReady() || !$role_id || !array_key_exists('mit_boards_present', $post)) {
        return '';
    }
    // Before, to say what changed (for ITFlow's "saved" / "unchanged" and its audit line)
    $was_limited = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT limited FROM mit_role_board_access WHERE role_id = $role_id"))[0] ?? 0);
    $was_boards = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT board_id FROM mit_role_boards WHERE role_id = $role_id ORDER BY board_id")), 0));
    $limited = empty($post['mit_boards_all']) ? 1 : 0;
    mysqli_query($mysqli, "INSERT INTO mit_role_board_access SET role_id = $role_id, limited = $limited, updated_by = " . intval($session_user_id) . ", updated_at = NOW()
        ON DUPLICATE KEY UPDATE limited = $limited, updated_by = " . intval($session_user_id) . ", updated_at = NOW()");
    mysqli_query($mysqli, "DELETE FROM mit_role_boards WHERE role_id = $role_id");
    if ($limited) {
        foreach (array_unique(array_map('intval', (array) ($post['mit_boards'] ?? []))) as $board_id) {
            if (isset(mitBoards(true)[$board_id])) {
                mysqli_query($mysqli, "INSERT INTO mit_role_boards SET role_id = $role_id, board_id = $board_id");
            }
        }
    }
    $now_boards = $limited ? array_map('intval', array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT board_id FROM mit_role_boards WHERE role_id = $role_id ORDER BY board_id")), 0)) : [];
    if ($limited === $was_limited && (!$limited || $now_boards === $was_boards)) {
        return '';
    }
    if (!$limited) {
        return 'sees every service board';
    }
    $names = array_map(function ($board_id) {
        return mitBoards(true)[$board_id]['board_name'] ?? '';
    }, $now_boards);
    return 'service boards: ' . ($names ? implode(', ', $names) : 'none');
}

/** Admin > Settings > Boards: the roles that see a board ("Everyone" when no role is limited). */
function mitBoardSeenBy($board_id) {
    global $mysqli;
    if (!mitBoardRolesReady()) {
        return '';
    }
    $board_id = intval($board_id);
    $names = [];
    $result = mysqli_query($mysqli, "SELECT role_name, role_is_admin, COALESCE(a.limited, 0) AS limited,
            EXISTS (SELECT 1 FROM mit_role_boards rb WHERE rb.role_id = user_roles.role_id AND rb.board_id = $board_id) AS ticked
        FROM user_roles LEFT JOIN mit_role_board_access a ON a.role_id = user_roles.role_id
        WHERE role_archived_at IS NULL ORDER BY role_name");
    $everyone = true;
    while ($row = mysqli_fetch_assoc($result)) {
        if (intval($row['role_is_admin']) || !intval($row['limited']) || intval($row['ticked'])) {
            $names[] = $row['role_name'];
        } else {
            $everyone = false;
        }
    }
    return $everyone ? 'Every role' : implode(', ', $names);
}
