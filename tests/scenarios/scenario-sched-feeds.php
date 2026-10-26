<?php
/*
 * A tech's calendar feed (guest/guest_calendar_feed.php, fetched over HTTP like a calendar app does): their booking with
 * its real end, a ticket they help on at their own time, a "third Wednesday" meeting with its RRULE, and nothing of another
 * agent's. Needs the install's web address to answer on 127.0.0.1.
 */
require __DIR__ . '/bootstrap.php';

$tech = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'tech@test.test'"))[0] ?? 0);
$admin = $session_user_id;
$client = scenarioClient();
$tag = 'ZZfeed' . date('His');
$host = $config_base_url;
$key = bin2hex(random_bytes(16));
$day = date('Y-m-d', strtotime('next thursday +7 days'));
$tickets = [];
$events = [];
$had_feed = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM user_calendar_feeds WHERE feed_user_id = $tech"));
$new_ticket = function ($subject, $assigned) use (&$tickets, $mysqli, $client, $session_user_id) {
    $n = 600000 + count($tickets) + intval(date('s')) * 100;
    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = $n, ticket_subject = '$subject', ticket_details = 'scenario', ticket_priority = 'Low',
        ticket_status = 2, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned, ticket_client_id = $client, ticket_url_key = 'zf$n'");
    return $tickets[] = mysqli_insert_id($mysqli);
};
$fetch = function () use ($host, $key) {
    $ch = curl_init("http://$host/guest/guest_calendar_feed.php?key=$key");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$host:80:127.0.0.1"], CURLOPT_TIMEOUT => 20]);
    $body = (string) curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$status, str_replace("\r\n ", '', $body)];
};
$vevent = function ($ics, $needle) {
    foreach (explode('BEGIN:VEVENT', $ics) as $part) {
        if (strpos($part, $needle) !== false) {
            return $part;
        }
    }
    return '';
};
try {
    mysqli_query($mysqli, "DELETE FROM user_calendar_feeds WHERE feed_user_id = $tech");
    mysqli_query($mysqli, "INSERT INTO user_calendar_feeds SET feed_user_id = $tech, feed_key = '$key', feed_scope = 'mine', feed_tickets = 1, feed_events = 1, feed_projects = 1");
    $mine = $new_ticket("$tag mine", $tech);
    ticketSetSchedule(ticketRow($mine), "$day 09:00:00", 1, "$day 11:15:00");
    $theirs = $new_ticket("$tag theirs", $admin);
    ticketSetSchedule(ticketRow($theirs), "$day 13:00:00", 0, "$day 14:00:00");
    $helping = $new_ticket("$tag helping", $admin);
    ticketSetSchedule(ticketRow($helping), "$day 15:00:00", 0, "$day 16:00:00");
    mitTicketTechSave(ticketRow($helping), $tech, 'own', "$day 16:00:00", "$day 17:30:00");
    $start = date('Y-m-d', strtotime("third wednesday of " . date('F Y', strtotime("$day +1 month"))));
    mysqli_query($mysqli, "INSERT INTO calendar_events SET event_title = '$tag third wed', event_start = '$start 10:00:00', event_end = '$start 11:00:00', event_repeat = 'Month',
        event_repeat_interval = 1, event_repeat_by_day = '3WE', event_calendar_id = 1, event_created_by = $admin");
    $events[] = $ev = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO calendar_event_attendees SET attendee_event_id = $ev, attendee_user_id = $tech");

    [$status, $ics] = $fetch();
    scenarioCheck('feed answers 200 with a calendar', [$status, strpos($ics, 'BEGIN:VCALENDAR') === 0], [200, true]);
    $d = str_replace('-', '', $day);
    $v = $vevent($ics, "$tag mine");
    scenarioCheck('own booking: start and the booked end (11:15)', [(bool) preg_match("/DTSTART[^:]*:{$d}T090000/", $v), (bool) preg_match("/DTEND[^:]*:{$d}T111500/", $v)], [true, true]);
    scenarioCheck("another agent's booking is not in a 'mine' feed", $vevent($ics, "$tag theirs"), '');
    $v = $vevent($ics, "$tag helping");
    scenarioCheck('helping at their own time: 16:00 - 17:30', [(bool) preg_match("/DTSTART[^:]*:{$d}T160000/", $v), (bool) preg_match("/DTEND[^:]*:{$d}T173000/", $v)], [true, true]);
    $v = $vevent($ics, "$tag third wed");
    scenarioCheck('third-Wednesday meeting carries RRULE BYDAY=3WE', (bool) preg_match('/RRULE:FREQ=MONTHLY;BYDAY=3WE/', $v), true);
    // UIDs are unique across the feed
    preg_match_all('/^UID:(.+)$/m', $ics, $m);
    scenarioCheck('every UID in the feed is unique', count($m[1]) === count(array_unique($m[1])), true);
    // A disabled agent's link stops
    mysqli_query($mysqli, "UPDATE users SET user_status = 0 WHERE user_id = $tech");
    [$status] = $fetch();
    mysqli_query($mysqli, "UPDATE users SET user_status = 1 WHERE user_id = $tech");
    scenarioCheck("a disabled agent's feed stops answering", $status >= 400, true);
} finally {
    mysqli_query($mysqli, "UPDATE users SET user_status = 1 WHERE user_id = $tech");
    foreach ($events as $id) {
        mysqli_query($mysqli, "DELETE FROM calendar_event_attendees WHERE attendee_event_id = $id");
        mysqli_query($mysqli, "DELETE FROM calendar_events WHERE event_id = $id");
    }
    foreach ($tickets as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_techs WHERE ticket_id = $id");
        ticketDeleteForGood($id);
    }
    mysqli_query($mysqli, "DELETE FROM user_calendar_feeds WHERE feed_user_id = $tech");
    if ($had_feed) {
        $cols = [];
        foreach ($had_feed as $k => $v) {
            $cols[] = "$k = " . ($v === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $v) . "'");
        }
        mysqli_query($mysqli, "INSERT INTO user_calendar_feeds SET " . implode(', ', $cols));
    }
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
