<?php
/*
 * API / service accounts are ITFlow's own since 26.12 (functions/service_account.php, users.user_service_account - built from ours; ITFlow
 * keeps them out of its own lists, sign-in and alerts). Ours: our own agent lists leave them out too, through mitServiceUserSql
 * (functions/mit_service_users.php, now reading ITFlow's column) - a board's members, timesheet people, timesheet approvers. An admin-role
 * login is in each list (the control), marked a service account it is in none, unmarked it is back.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('serviceAccountsReady') || !serviceAccountsReady()) {
    echo "  FAIL ITFlow's service accounts (users.user_service_account) are not here\n";
    exit(1);
}
$role = intval(scenarioValue("SELECT role_id FROM user_roles WHERE role_is_admin = 1 AND role_archived_at IS NULL ORDER BY role_id LIMIT 1"));
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Scen API User', user_email = 'zz-scen-api@example.test', user_password = '" . password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT) . "',
    user_type = 1, user_status = 1, user_role_id = $role");
$user = mysqli_insert_id($mysqli);
$board = intval(scenarioValue("SELECT board_id FROM mit_boards WHERE board_archived_at IS NULL ORDER BY board_id LIMIT 1"));
mysqli_query($mysqli, "INSERT INTO mit_board_members SET board_id = $board, user_id = $user, member_notify = 1");
$mark = function ($on) use ($mysqli, $user) {
    mysqli_query($mysqli, "UPDATE users SET user_service_account = " . ($on ? 1 : 0) . " WHERE user_id = $user");
};
try {
    scenarioCheck('control - an ordinary admin login: a board member', isset(mitBoardMembers($board)[$user]), true);
    scenarioCheck('control - ... in the timesheet people', isset(mitTsUsers([], true)[$user]), true);
    scenarioCheck('control - ... a timesheet approver', isset(mitTsApprovers()[$user]), true);

    $mark(true);
    scenarioCheck('marked (ITFlow\'s column)', mitIsServiceUser($user), true);
    scenarioCheck('off the board\'s members', isset(mitBoardMembers($board)[$user]), false);
    scenarioCheck('off the timesheet people', isset(mitTsUsers([$user], true)[$user]), false);
    scenarioCheck('off the timesheet approvers', isset(mitTsApprovers()[$user]), false);
    $team = mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_type = 1" . mitServiceUserSql('users.user_id') . " AND user_status = 1 AND user_archived_at IS NULL");
    scenarioCheck('off any list built with mitServiceUserSql', in_array((string) $user, array_column(mysqli_fetch_all($team), 0), true), false);

    $mark(false);
    scenarioCheck('unmarked: a board member again', isset(mitBoardMembers($board)[$user]), true);
    scenarioCheck('unmarked: an approver again', isset(mitTsApprovers()[$user]), true);
} finally {
    mysqli_query($mysqli, "DELETE FROM mit_board_members WHERE user_id = $user");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $user");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
