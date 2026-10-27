<?php
/*
 * Dispatch's Workload view and "This week" numbers (functions/mit_dispatch_workload.php) move by exactly what was done:
 * open / lead / helping / not booked / quiet / booked today and this week per tech, Move to (unbooked = assign, booked =
 * refused), and the week's inbound / closed per day and per agent (made for them, or reassigned to them this week).
 */
require __DIR__ . '/bootstrap.php';

$tech = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'tech@test.test'"))[0] ?? 0);
$admin = $session_user_id;
$client = scenarioClient();
$tag = 'ZZwl' . date('His');
$tickets = [];
$new_ticket = function ($subject, $assigned, $created = null) use (&$tickets, $mysqli, $client, $session_user_id) {
    $n = 700000 + count($tickets) + intval(date('s')) * 100;
    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = $n, ticket_subject = '$subject', ticket_details = 'scenario', ticket_priority = 'Low',
        ticket_status = 2, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned, ticket_client_id = $client, ticket_url_key = 'zw$n'"
        . ($created ? ", ticket_created_at = '$created'" : ''));
    return $tickets[] = mysqli_insert_id($mysqli);
};
$row_for = function ($user_id) {
    foreach (mitDispatchWorkload([$GLOBALS['admin_id'], $GLOBALS['tech_id']], 1, 'all')['techs'] as $r) {
        if ($r['id'] === $user_id) {
            return $r;
        }
    }
    return null;
};
$GLOBALS['admin_id'] = $admin;
$GLOBALS['tech_id'] = $tech;
$pick = function ($r) {
    return ['open' => $r['open'], 'lead' => $r['lead'], 'helping' => $r['helping'], 'unbooked' => $r['unbooked'], 'quiet' => $r['quiet'], 'booked_today' => $r['booked_today'], 'booked_week' => $r['booked_week']];
};
$diff = function ($a, $b) {
    $out = [];
    foreach ($a as $k => $v) {
        $out[$k] = $b[$k] - $v;
    }
    return $out;
};
$stats_for = function ($user_id) {
    $s = mitDispatchWeekStats([$GLOBALS['admin_id'], $GLOBALS['tech_id']], 1, 'all');
    $today = date('Y-m-d');
    $day = array_values(array_filter($s['days'], function ($d) use ($today) { return $d['date'] === $today; }))[0];
    $t = array_values(array_filter($s['techs'], function ($t) use ($user_id) { return $t['id'] === $user_id; }))[0];
    return ['inbound_today' => $day['inbound'], 'closed_today' => $day['closed'], 'assigned' => $t['assigned'], 'closed' => $t['closed'], 'open' => $t['open']];
};

try {
    $before = $pick($row_for($tech));
    $stats_before = $stats_for($tech);
    $old_day = date('Y-m-d H:i:s', strtotime('-10 days'));
    $quiet = $new_ticket("$tag quiet", $tech, $old_day);                 // open, not booked, nothing said for 10 days
    $booked = $new_ticket("$tag booked", $tech);                         // booked today for 90 minutes
    $start = date('Y-m-d H:i:00', strtotime('today 23:00'));
    ticketSetSchedule(ticketRow($booked), date('Y-m-d') . ' 06:00:00', 0, date('Y-m-d') . ' 07:30:00');
    $helped = $new_ticket("$tag helped", $admin);                        // the tech helps, at their own time today
    mitTicketTechSave(ticketRow($helped), $tech, 'own', date('Y-m-d') . ' 05:00:00', date('Y-m-d') . ' 05:30:00');
    $after = $pick($row_for($tech));
    $d = $diff($before, $after);
    scenarioCheck('workload moves by what was added: 3 open (2 lead, 1 helping), 1 not booked, 1 quiet, 120 min today',
        $d, ['open' => 3, 'lead' => 2, 'helping' => 1, 'unbooked' => 1, 'quiet' => 1, 'booked_today' => 120,
            'booked_week' => 120]);
    // Move to: unbooked goes; booked is refused
    [$ok] = mitDispatchAssign($quiet, $admin);
    scenarioCheck('Move to: an unbooked ticket is given to the other agent', [$ok, intval(ticketRow($quiet)['ticket_assigned_to'])], [true, $admin]);
    [$ok] = mitDispatchAssign($booked, $admin);
    scenarioCheck('Move to: a booked ticket is refused (goes through Schedule)', [$ok, intval(ticketRow($booked)['ticket_assigned_to'])], [false, $tech]);
    // Back to the tech: counted as assigned to them this week
    [$ok] = mitDispatchAssign($quiet, $tech);
    // Resolve the booked one today
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $booked");
    $stats_after = $stats_for($tech);
    scenarioCheck('this week: inbound today +2 (created today counts, the old one not)', $stats_after['inbound_today'] - $stats_before['inbound_today'], 2);
    scenarioCheck('this week: closed today +1', $stats_after['closed_today'] - $stats_before['closed_today'], 1);
    scenarioCheck("this week: the tech's assigned +2 (made for them, and handed back to them)", $stats_after['assigned'] - $stats_before['assigned'], 2);
    scenarioCheck("this week: the tech's closed +1", $stats_after['closed'] - $stats_before['closed'], 1);
    $after2 = $pick($row_for($tech));
    scenarioCheck('resolved: off the open count', $after2['open'] - $before['open'], 2);
} finally {
    foreach ($tickets as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_techs WHERE ticket_id = $id");
        ticketDeleteForGood($id);
    }
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
