<?php
/*
 * Service boards by role on ITFlow's shared pages (functions/mit_board_roles.php mitBoardVisibleSql, hooks in
 * functions/client_overview.php, functions/dashboard.php and functions/search.php): for an agent whose role sees only one board, a
 * ticket on another board (not theirs) is left out of the client overview's Needs attention counts (open and resolved-not-invoiced)
 * and open tickets list, the dashboard's unassigned count and ticket trend, and the conversation search; an admin still counts it.
 * Makes a role, a client and two tickets of its own and removes them.
 */
require __DIR__ . '/bootstrap.php';

if (!function_exists('mitBoardRolesReady') || !mitBoardRolesReady()) {
    echo "  skip - boards by role not set up here\n";
    exit(0);
}
$boards = array_keys(mitBoards());
$default = intval(scenarioValue("SELECT board_id FROM mit_boards WHERE board_is_default = 1 AND board_archived_at IS NULL"));
$other = 0;
foreach ($boards as $id) {
    if (intval($id) !== $default) {
        $other = intval($id);
        break;
    }
}
if (!$other) {
    echo "  skip - needs two boards\n";
    exit(0);
}
$tag = 'ZZscope' . date('His');
$agent = intval(scenarioValue("SELECT user_id FROM users WHERE user_email = 'tech@test.test'"));
mysqli_query($mysqli, "INSERT INTO user_roles SET role_name = '$tag role', role_description = 'scenario', role_is_admin = 0");
$role = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_role_board_access SET role_id = $role, limited = 1");
mysqli_query($mysqli, "INSERT INTO mit_role_boards SET role_id = $role, board_id = $default");

[$client_id, $contact_id] = scenarioMakeClient("$tag client");
// Open and unassigned on the board the role doesn't see; resolved and billable there too
$hidden = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => "$tag hidden open"]);
$resolved = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => "$tag hidden resolved", 'ticket_status' => 4, 'ticket_billable' => 1]);
mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $resolved");
foreach ([$hidden, $resolved] as $t) {
    mysqli_query($mysqli, "INSERT INTO mit_ticket_boards SET ticket_id = $t, board_id = $other");
}
mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '$tag findme', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:10:00', ticket_reply_by = 1, ticket_reply_ticket_id = $hidden");

$access = ['tickets' => true, 'docs' => true, 'financial' => true, 'sales' => true, 'support' => true, 'credentials' => false];
$look = function () use ($client_id, $access, $tag, $hidden) {
    $counts = [];
    foreach (clientOverviewAttention($client_id, $access, null) as $item) {
        $counts[$item['label']] = intval($item['count']);
    }
    $open = array_map(function ($r) { return intval($r['ticket_id']); }, clientOverviewOpenTickets($client_id, 50)['tickets']);
    $team = dashboardTeamWorkload(50);
    $trend = array_sum(array_map(function ($w) { return intval($w['created'] ?? $w['opened'] ?? $w['new'] ?? 0); }, dashboardTicketTrend()));
    $replies = null;
    foreach (globalSearchGroups(['eq' => $tag, 'like' => "$tag findme", 'phone' => '', 'raw' => "$tag findme"], true) as $group) {
        if ($group['key'] === 'ticket_replies') {
            global $mysqli;
            $replies = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM {$group['from']} WHERE {$group['where']}"))[0]);
        }
    }
    $dash = 0;
    foreach (dashboardAttention($access) as $item) {
        if ($item['label'] === 'Unassigned tickets') {
            $dash = intval($item['count']);
        }
    }
    return ['dash_unassigned' => $dash, 'unassigned' => $counts['Unassigned tickets'] ?? 0, 'unbilled' => $counts['Resolved, not invoiced'] ?? 0, // items with nothing in them are left out
        'listed' => in_array($hidden, $open, true), 'team_unassigned' => intval($team['unassigned'] ?? 0), 'trend' => $trend, 'replies' => $replies];
};
try {
    $session_is_admin = true;
    $session_user_role = 0;
    $admin = $look();
    $session_is_admin = false;
    $session_user_role = $role;
    $session_user_id = $agent;
    $limited = $look();

    scenarioCheck('admin: the hidden open ticket counts as unassigned', $admin['unassigned'], 1);
    scenarioCheck('limited role: not in the unassigned count', $limited['unassigned'], 0);
    scenarioCheck('admin: resolved, not invoiced counts it', $admin['unbilled'], 1);
    scenarioCheck('limited role: resolved, not invoiced leaves it out', $limited['unbilled'], 0);
    scenarioCheck('admin: on the open tickets list', $admin['listed'], true);
    scenarioCheck('limited role: not on the open tickets list', $limited['listed'], false);
    scenarioCheck("dashboard's Needs attention: fewer unassigned for the limited role", $admin['dash_unassigned'] - $limited['dash_unassigned'] >= 1, true);
    if (function_exists('mitProjectTicketsApart') && mitProjectTicketsApart()) {
        // A project ticket is kept off the service counts (project tickets kept apart)
        $session_is_admin = true;
        $session_user_role = 0;
        $session_user_id = 1;
        mysqli_query($mysqli, "INSERT INTO projects SET project_prefix = 'PRJ-', project_number = 7" . date('His') . ", project_name = '$tag project', project_client_id = $client_id");
        $project = mysqli_insert_id($mysqli);
        $before = $look()['dash_unassigned'];
        $in_project = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => "$tag project ticket", 'ticket_project_id' => $project]);
        scenarioCheck("dashboard's Needs attention: an unassigned project ticket is not counted", $look()['dash_unassigned'], $before);
        mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = $project");
    }
    scenarioCheck("dashboard's unassigned count: less for the limited role", $admin['team_unassigned'] - $limited['team_unassigned'] >= 1, true);
    scenarioCheck('ticket trend: the hidden tickets left out for the limited role', $admin['trend'] - $limited['trend'] >= 2, true);
    scenarioCheck('conversation search: admin finds the note', $admin['replies'], 1);
    scenarioCheck('conversation search: limited role does not', $limited['replies'], 0);
} finally {
    $session_is_admin = true;
    $session_user_id = 1;
    mysqli_query($mysqli, "DELETE FROM user_roles WHERE role_id = $role");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
