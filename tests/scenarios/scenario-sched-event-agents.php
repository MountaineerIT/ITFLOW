<?php
/*
 * Calendar entries assigned to agents (functions/mit_event_agents.php): shown on the agents' calendars in their colour and
 * left off the maker's own calendar once handed out; Dispatch moving an entry in time and to another agent - stored times,
 * who is assigned after, and the invite / update / cancellation emails (.ics METHOD) each person gets; the quick look's
 * set-agents keeps contacts and guests; a repeating entry refuses a drag.
 */
require __DIR__ . '/bootstrap.php';

$tech = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'tech@test.test'"))[0] ?? 0);
$admin = $session_user_id;
$tech_email = 'tech@test.test';
$admin_email = mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_email FROM users WHERE user_id = $admin"))[0];
$tag = 'ZZev' . date('His');
$guest = strtolower("$tag-guest@example.invalid");
$day = date('Y-m-d', strtotime('next wednesday +7 days'));
$events = [];
$mails = function ($since) use ($mysqli, $tag) {
    $out = [];
    $r = mysqli_query($mysqli, "SELECT LOWER(email_recipient) r, email_subject s, email_cal_str c FROM email_queue WHERE email_id > $since AND email_subject LIKE '%$tag%' ORDER BY email_id");
    while ($row = mysqli_fetch_assoc($r)) {
        $out[] = $row;
    }
    return $out;
};
$last = function () use ($mysqli) {
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(email_id), 0) FROM email_queue"))[0]);
};
$by = function (array $mails) {
    $out = [];
    foreach ($mails as $m) {
        preg_match('/METHOD:(\w+)/', (string) $m['c'], $x);
        $out[$m['r']] = strtok($m['s'], ':') . '/' . ($x[1] ?? '');
    }
    ksort($out);
    return $out;
};
$ks = function (array $a) {
    ksort($a);
    return $a;
};
try {
    mysqli_query($mysqli, "INSERT INTO calendar_events SET event_title = '$tag visit', event_start = '$day 10:00:00', event_end = '$day 11:00:00', event_calendar_id = 1, event_created_by = $admin");
    $ev = $events[] = mysqli_insert_id($mysqli);
    calendarSetAttendees($ev, 0, [$tech], [], [$guest]);
    $since = $last();
    $sent = calendarSendInvites(calendarEventRow($ev), 'invite');
    scenarioCheck('invitations queued to the agent and the guest', $by($mails($since)), $ks([$guest => 'Invitation/REQUEST', $tech_email => 'Invitation/REQUEST']));

    // On the calendars
    $on = function ($user_id) use ($ev, $day) {
        $items = calendarEventItems([1], "$day 00:00:00", "$day 23:59:59", ['client_id' => 0, 'user_id' => $user_id]);
        return array_values(array_filter($items, function ($i) use ($ev) { return intval($i['extendedProps']['event_id'] ?? 0) === $ev; }));
    };
    $t = $on($tech);
    scenarioCheck("on the agent's calendar, in their colour, named", [count($t), $t[0]['color'] ?? '', $t[0]['extendedProps']['agent_name'] ?? ''],
        [1, mitAgentColor($tech), mitFirstName(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $tech"))[0])]);
    scenarioCheck("handed out: not on the maker's own calendar", count($on($admin)), 0);
    $all = calendarEventItems([1], "$day 00:00:00", "$day 23:59:59", ['client_id' => 0]);
    scenarioCheck('on Everyone', count(array_filter($all, function ($i) use ($ev) { return intval($i['extendedProps']['event_id'] ?? 0) === $ev; })), 1);

    // Dispatch: dragged to 14:00 and from the tech's column to the admin's
    $since = $last();
    [$ok, $msg] = mitDispatchEventMove($ev, "$day 14:00:00", '', false, $tech, $admin);
    $row = calendarEventRow($ev);
    scenarioCheck('Dispatch move: new time, length kept', [$ok, $row['event_start'], $row['event_end']], [true, "$day 14:00:00", "$day 15:00:00"]);
    scenarioCheck('Dispatch move: now assigned to the admin only', array_keys(mitEventAgents([$ev])[$ev] ?? []), [$admin]);
    $got = $by($mails($since));
    scenarioCheck('Dispatch move: guest updated, old agent cancelled, new agent invited', $got,
        $ks([$admin_email => 'Invitation/REQUEST', $guest => 'Updated/REQUEST', $tech_email => 'Cancelled/CANCEL']));
    $ics = '';
    foreach ($mails($since) as $m) {
        if ($m['r'] === $guest) {
            $ics = (string) $m['c'];
        }
    }
    scenarioCheck('the update carries the new time', strpos($ics, str_replace('-', '', $day) . 'T140000') !== false, true);
    preg_match('/SEQUENCE:(\d+)/', $ics, $seq);
    scenarioCheck('the update outranks the invitation (SEQUENCE > 0)', intval($seq[1] ?? 0) > 0, true);
    scenarioCheck('guest kept on the entry', in_array($guest, array_map('strtolower', array_column(calendarEventAttendees($ev), 'email')), true), true);

    // Quick look: both agents
    $since = $last();
    [$added, $removed, $sent] = mitEventSetAgents(calendarEventRow($ev), [$admin, $tech]);
    scenarioCheck('set agents: the tech added back and invited (others had it)', [count($added), count($removed), $by($mails($since))], [1, 0, [$tech_email => 'Invitation/REQUEST']]);
    $t = $on($tech);
    scenarioCheck('two agents: "+1" in the name', substr((string) ($t[0]['extendedProps']['agent_name'] ?? ''), -2), '+1');

    // Dispatch: a move in time only keeps both agents and updates everyone invited
    $since = $last();
    [$ok] = mitDispatchEventMove($ev, "$day 16:00:00", "$day 16:30:00", false, $admin, $admin);
    scenarioCheck('move in time only: agents kept', count(mitEventAgents([$ev])[$ev] ?? []), 2);
    scenarioCheck('move in time only: everyone invited gets the update', array_values(array_unique(array_values($by($mails($since))))), ['Updated/REQUEST']);
    scenarioCheck('move in time only: the given end kept', calendarEventRow($ev)['event_end'], "$day 16:30:00");

    // Moving to an agent who is not on Dispatch is refused
    [$ok] = mitDispatchEventMove($ev, "$day 16:00:00", '', false, $admin, 999999);
    scenarioCheck('move to an unknown agent refused', $ok, false);

    // A repeating entry is moved from Edit only
    mysqli_query($mysqli, "INSERT INTO calendar_events SET event_title = '$tag weekly', event_start = '$day 08:00:00', event_end = '$day 08:30:00', event_repeat = 'Week', event_repeat_interval = 1, event_calendar_id = 1, event_created_by = $admin");
    $ev2 = $events[] = mysqli_insert_id($mysqli);
    [$ok] = mitDispatchEventMove($ev2, "$day 09:00:00", '', false, $admin, $admin);
    scenarioCheck('a repeating entry refuses a drag', [$ok, calendarEventRow($ev2)['event_start']], [false, "$day 08:00:00"]);

    // All-day entry dragged onto the all-day row keeps its days
    mysqli_query($mysqli, "INSERT INTO calendar_events SET event_title = '$tag trip', event_start = '$day 00:00:00', event_end = '" . date('Y-m-d', strtotime("$day +2 days")) . " 00:00:00', event_all_day = 1, event_calendar_id = 1, event_created_by = $admin");
    $ev3 = $events[] = mysqli_insert_id($mysqli);
    $next = date('Y-m-d', strtotime("$day +7 days"));
    [$ok] = mitDispatchEventMove($ev3, "$next 00:00:00", '', true, $admin, $admin);
    $row = calendarEventRow($ev3);
    scenarioCheck('all-day entry moved a week keeps its three days', [$row['event_start'], $row['event_end'], intval($row['event_all_day'])], ["$next 00:00:00", date('Y-m-d', strtotime("$next +2 days")) . ' 00:00:00', 1]);

    // New Event from an agent's calendar starts with them; an unknown agent falls back to the maker
    scenarioCheck('New Event from the tech\'s calendar starts with the tech', mitEventDefaultAgents(['agent_id' => $tech]), [$tech]);
    scenarioCheck('New Event with an unknown agent starts with the maker', mitEventDefaultAgents(['agent_id' => 999999]), [$admin]);
} finally {
    foreach ($events as $id) {
        mysqli_query($mysqli, "DELETE FROM calendar_event_attendees WHERE attendee_event_id = $id");
        mysqli_query($mysqli, "DELETE FROM calendar_events WHERE event_id = $id");
    }
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
