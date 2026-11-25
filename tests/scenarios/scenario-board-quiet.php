<?php
/*
 * Quiet boards (M071, functions/mit_boards.php mitBoardQuietSql - the user's request, Nov 25: an Alerts board whose tickets come and go by
 * themselves). A board switched to "Keep off Dispatch and the dashboard counts":
 *  - its unassigned ticket is not in Dispatch's Not assigned queue or its menu count while all boards / the agent's boards are shown, but is
 *    when the board itself is picked;
 *  - a ticket on it assigned to an agent stays in Workload; its new tickets are not in This week;
 *  - the dashboard's Unassigned count leaves it out (team workload's too), unless the dashboard shows that board;
 *  - the default board is never quiet, and with the switch off everything is back.
 * Through ITFlow's own dispatchQueue / dispatchWorkload / dispatchWeekStats / dashboardAttention.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitBoardQuietSql') || !mitColumnReady('mit_boards', 'board_quiet') || !function_exists('dispatchQueue')) {
    echo "  skip - quiet boards (M071) or Dispatch not here\n";
    exit(0);
}
$stamp = date('His');
$default = mitBoardDefaultId();
mysqli_query($mysqli, "INSERT INTO mit_boards SET board_name = 'ZZ Alerts $stamp', board_color = '#888888', board_icon = 'bolt', board_order = 99, board_is_default = 0");
$alerts = intval(mysqli_insert_id($mysqli));
$agent = intval($session_user_id);
[$client, $contact] = scenarioMakeClient("ZZ Quiet $stamp");
$noise = scenarioMakeTicket($client, $contact, ['ticket_subject' => "ZZ quiet alert $stamp"]);
$held = scenarioMakeTicket($client, $contact, ['ticket_subject' => "ZZ quiet held $stamp", 'ticket_assigned_to' => $agent]);
$real = scenarioMakeTicket($client, $contact, ['ticket_subject' => "ZZ quiet real $stamp"]);
foreach ([$noise, $held] as $t) {
    mysqli_query($mysqli, "INSERT INTO mit_ticket_boards SET ticket_id = $t, board_id = $alerts, board_set_by = 0, board_set_how = 'routed'");
}
$was_mine = mitUserPreference($agent, 'dispatch.board', '');
$was_dash = mitUserPreference($agent, 'dashboard.board', '');
// Each look is a fresh PHP process (the board list and choices are kept per request), asked through ITFlow's own functions
$ask = function ($code, array $get = []) use ($agent) {
    $php = '$_SERVER["REMOTE_ADDR"]="127.0.0.1"; $_GET = ' . var_export($get, true) . '; require "' . __DIR__ . '/bootstrap.php"; ' . $code;
    $out = shell_exec('cd /var/www/itflow && php -r ' . escapeshellarg($php) . ' 2>&1');
    $json = json_decode(trim((string) $out), true);
    if ($json === null) {
        echo "    (not JSON: " . substr((string) $out, 0, 300) . ")\n";
    }
    return $json;
};
$queue = function (array $get = []) use ($ask, $client) {
    return $ask('echo json_encode(array_column(dispatchQueue("unassigned", ["client_id" => ' . $client . '])["tickets"], "id"));', $get + ["board" => "all"]) ?? [];
};
$navCount = function () use ($ask, $client) {
    return $ask('unset($_SESSION); echo json_encode(intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_client_id = ' . $client . ' AND ticket_assigned_to = 0 AND ticket_resolved_at IS NULL" . mitBoardWhere("all", $session_user_id) . mitBoardQuietSql("all")))[0]));');
};
$workload = function () use ($ask, $agent, $client) {
    return $ask('$r = dispatchWorkload([' . $agent . ']); $ids = []; foreach ($r as $row) { foreach (($row["tickets"] ?? []) as $t) { $ids[] = intval($t["id"] ?? $t["ticket_id"] ?? 0); } } echo json_encode(["ids" => $ids, "raw" => mitDispatchBoardSql("tickets", false)]);');
};
$sqlNoise = function ($fragment) use ($noise) {
    return intval(scenarioValue("SELECT COUNT(*) FROM tickets WHERE ticket_id = $noise $fragment"));
};
try {
    mitSetUserPreference($agent, 'dispatch.board', json_encode(['board' => 'all', 'board_techs' => false]));
    mitSetUserPreference($agent, 'dashboard.board', '');

    echo "switch off - nothing changes\n";
    scenarioCheck('Not assigned holds the alert and the real ticket', array_values(array_intersect([$noise, $real], $queue())), [$noise, $real]);

    mysqli_query($mysqli, "UPDATE mit_boards SET board_quiet = 1 WHERE board_id = $alerts");
    echo "switch on\n";
    scenarioCheck('Not assigned (all boards): the alert is gone, the real ticket stays', array_values(array_intersect([$noise, $real], $queue())), [$real]);
    scenarioCheck('... the Alerts board picked itself: the alert is there', in_array($noise, $queue(['board' => (string) $alerts]), true), true);
    scenarioCheck('Dispatch\'s menu count leaves it out', $navCount(), 1);
    $w = $workload();
    scenarioCheck('Workload: the alert assigned to the agent is still counted (no quiet filter there)', strpos((string) ($w['raw'] ?? 'x'), 'NOT IN') === false, true);
    $week_sql = (string) $ask('echo json_encode(mitDispatchBoardSql());', ['board' => 'all']);
    scenarioCheck('This week (and the queues) filter quiet boards out', strpos($week_sql, 'NOT IN') !== false, true);
    scenarioCheck('... the SQL really drops the alert', $sqlNoise($week_sql), 0);
    scenarioCheck('... and keeps it for the Alerts board itself', $sqlNoise((string) $ask('echo json_encode(mitDispatchBoardSql());', ['board' => (string) $alerts])), 1);
    scenarioCheck('Dashboard counts (all boards) leave it out', $sqlNoise($ask('echo json_encode(mitBoardDashAnd());')), 0);
    scenarioCheck('... team workload keeps assigned work (only its Unassigned leaves quiet boards out)', strpos((string) $ask('echo json_encode(mitBoardDashAnd("tickets", false));'), 'NOT IN') === false, true);
    $att = $ask('$a = dashboardAttention(["tickets" => true, "sales" => false, "support" => true, "financial" => false]); foreach ($a as $i) { if (($i["icon"] ?? "") === "user-slash") { echo json_encode($i["count"]); exit; } } echo json_encode(0);');
    mysqli_query($mysqli, "UPDATE mit_boards SET board_quiet = 0 WHERE board_id = $alerts");
    $att_off = $ask('$a = dashboardAttention(["tickets" => true, "sales" => false, "support" => true, "financial" => false]); foreach ($a as $i) { if (($i["icon"] ?? "") === "user-slash") { echo json_encode($i["count"]); exit; } } echo json_encode(0);');
    scenarioCheck('Needs attention\'s Unassigned: one fewer with the switch on', intval($att_off) - intval($att), 1);
    mitSetUserPreference($agent, 'dashboard.board', (string) $alerts);
    mysqli_query($mysqli, "UPDATE mit_boards SET board_quiet = 1 WHERE board_id = $alerts");
    scenarioCheck('... the dashboard showing the Alerts board counts it', $sqlNoise($ask('echo json_encode(mitBoardDashAnd());')), 1);

    echo "the default board is never quiet\n";
    mysqli_query($mysqli, "UPDATE mit_boards SET board_quiet = 1 WHERE board_id = $default");
    scenarioCheck('a quiet flag on the default board is ignored', in_array($default, $ask('echo json_encode(mitBoardQuietIds());') ?? [], true), false);
} finally {
    mysqli_query($mysqli, "UPDATE mit_boards SET board_quiet = 0 WHERE board_id = $default");
    mitSetUserPreference($agent, 'dispatch.board', $was_mine);
    mitSetUserPreference($agent, 'dashboard.board', $was_dash);
    mysqli_query($mysqli, "DELETE FROM mit_ticket_boards WHERE ticket_id IN ($noise, $held, $real)");
    mysqli_query($mysqli, "DELETE FROM mit_board_moves WHERE ticket_id IN ($noise, $held, $real)");
    scenarioCleanup();
    mysqli_query($mysqli, "DELETE FROM mit_boards WHERE board_id = $alerts");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
