<?php
/*
 * Outlook calendar per tech (functions/mit_outlook.php) against the Microsoft stand-in (MIT_M365_GRAPH): a booking becomes an
 * Outlook event at the right UTC time on both sides of the DST change, a move PATCHes it, an edit / delete in Outlook is put
 * back, a cancel or a resolve removes it, a helper's booking is added, a personal appointment comes back as a busy time
 * (with a clash) and ITFlow's own invites do not; the cron job runs clean.
 */
require __DIR__ . '/bootstrap.php';

$user = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM mit_outlook_links WHERE refresh_token IS NOT NULL AND refresh_token <> '' ORDER BY user_id LIMIT 1"))[0] ?? 0);
if (!$user || !defined('MIT_M365_GRAPH') || strpos(MIT_M365_GRAPH, '127.0.0.1') === false) {
    echo "  skip - no Outlook link on a stand-in\n";
    exit(0);
}
$graph = function ($method, $path, $body = null) {
    $ch = curl_init(MIT_M365_GRAPH . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer USERTOKEN', 'Content-Type: application/json']]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $text = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$status, json_decode((string) $text, true)];
};
$event_of = function ($ticket_id) use ($mysqli, $user) {
    return mysqli_fetch_row(mysqli_query($mysqli, "SELECT event_id FROM mit_outlook_events WHERE user_id = $user AND ticket_id = " . intval($ticket_id)))[0] ?? null;
};
$sync = function () use ($user) {
    try {
        return mitOutlookSync($user, true);
    } catch (Throwable $e) {
        return 'ERROR ' . $e->getMessage();
    }
};
$utc = function ($local) {
    return gmdate('Y-m-d\TH:i:s', strtotime($local));
};
$client = scenarioClient();
$tech = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'tech@test.test'"))[0] ?? 0);
$tag = 'ZZol' . date('His');
$tickets = [];
$made_events = [];
$new_ticket = function ($subject, $assigned) use (&$tickets, $mysqli, $client, $session_user_id) {
    $n = 800000 + count($tickets) + intval(date('s')) * 100;
    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = $n, ticket_subject = '$subject', ticket_details = 'scenario', ticket_priority = 'Low',
        ticket_status = 2, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned, ticket_client_id = $client, ticket_url_key = 'zo$n'");
    return $tickets[] = mysqli_insert_id($mysqli);
};
// A day before and a day after the US DST change in the company zone (first Sunday of November)
$year = intval(date('Y'));
$dst_end = date('Y-m-d', strtotime("first sunday of november $year"));
if ($dst_end < date('Y-m-d', strtotime('+2 days'))) {
    $dst_end = date('Y-m-d', strtotime('first sunday of november ' . ($year + 1)));
}
$before = date('Y-m-d', strtotime("$dst_end -3 days"));
$after = date('Y-m-d', strtotime("$dst_end +2 days"));

try {
    $res = $sync();
    scenarioCheck('a first sync runs clean', strpos($res, 'ERROR') === 0 ? $res : 'ok', 'ok');

    // 1. A booking before the DST change, 09:00 - 10:30 local
    $t1 = $new_ticket("$tag before", $user);
    ticketSetSchedule(ticketRow($t1), "$before 09:00:00", 0, "$before 10:30:00");
    $sync();
    $id1 = $event_of($t1);
    scenarioCheck('booking added to Outlook', $id1 !== null, true);
    [$st, $ev] = $graph('GET', '/me/events/' . rawurlencode((string) $id1));
    scenarioCheck('Outlook start in UTC (before the change)', substr((string) ($ev['start']['dateTime'] ?? ''), 0, 19), $utc("$before 09:00:00"));
    scenarioCheck('Outlook end is the booked end', substr((string) ($ev['end']['dateTime'] ?? ''), 0, 19), $utc("$before 10:30:00"));
    scenarioCheck('Outlook subject names the ticket', strpos((string) ($ev['subject'] ?? ''), ticketReference(ticketRow($t1)) . " - $tag before") === 0, true);
    scenarioCheck('Outlook event in the ITFlow category, busy, remote', [$ev['categories'] ?? [], $ev['showAs'] ?? '', $ev['location']['displayName'] ?? ''], [[MIT_OUTLOOK_CATEGORY], 'busy', 'Remote']);

    // 2. Moved after the change, onsite: the same event changed
    ticketSetSchedule(ticketRow($t1), "$after 09:00:00", 1, "$after 11:00:00");
    $res = $sync();
    [$st, $ev] = $graph('GET', '/me/events/' . rawurlencode((string) $id1));
    scenarioCheck('moved: the same Outlook event', $event_of($t1), $id1);
    scenarioCheck('moved: Outlook start in UTC (after the change - one hour more)', substr((string) ($ev['start']['dateTime'] ?? ''), 0, 19), $utc("$after 09:00:00"));
    scenarioCheck('UTC offsets differ across the change', intval(substr($utc("$after 09:00:00"), 11, 2)) - intval(substr($utc("$before 09:00:00"), 11, 2)), 1);
    scenarioCheck('moved: onsite shown in the location', strpos((string) ($ev['location']['displayName'] ?? ''), 'Onsite') === 0, true);

    // 3. Changed in Outlook: ITFlow puts it back
    $graph('PATCH', '/me/events/' . rawurlencode((string) $id1), ['subject' => 'Moved by hand', 'start' => ['dateTime' => $utc("$after 15:00:00"), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $utc("$after 16:00:00"), 'timeZone' => 'UTC']]);
    $res = $sync();
    [$st, $ev] = $graph('GET', '/me/events/' . rawurlencode((string) $id1));
    scenarioCheck('edited in Outlook: put back (subject)', strpos((string) ($ev['subject'] ?? ''), ticketReference(ticketRow($t1))) === 0, true);
    scenarioCheck('edited in Outlook: put back (time)', substr((string) ($ev['start']['dateTime'] ?? ''), 0, 19), $utc("$after 09:00:00"));
    scenarioCheck('sync summary says restored', strpos($res, 'restored') !== false, true);

    // 4. Deleted in Outlook: added again
    $graph('DELETE', '/me/events/' . rawurlencode((string) $id1));
    $sync();
    $id1b = $event_of($t1);
    [$st, $ev] = $graph('GET', '/me/events/' . rawurlencode((string) $id1b));
    scenarioCheck('deleted in Outlook: added again', [$id1b !== $id1, $st], [true, 200]);

    // 5. Cancelled in ITFlow: removed from Outlook
    ticketCancelSchedule(ticketRow($t1));
    $sync();
    [$st] = $graph('GET', '/me/events/' . rawurlencode((string) $id1b));
    scenarioCheck('cancelled: removed from Outlook and forgotten', [$st, $event_of($t1)], [404, null]);

    // 6. Resolved: removed
    $t2 = $new_ticket("$tag resolve", $user);
    ticketSetSchedule(ticketRow($t2), "$after 13:00:00", 0, "$after 14:00:00");
    $sync();
    $id2 = $event_of($t2);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $t2");
    $sync();
    [$st] = $graph('GET', '/me/events/' . rawurlencode((string) $id2));
    scenarioCheck('resolved: removed from Outlook', [$id2 !== null, $st], [true, 404]);

    // 7. Reassigned to someone else: off this person's Outlook
    $t3 = $new_ticket("$tag reassign", $user);
    ticketSetSchedule(ticketRow($t3), "$after 15:00:00", 0, "$after 16:00:00");
    $sync();
    $id3 = $event_of($t3);
    if ($tech) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = $tech WHERE ticket_id = $t3");
        $sync();
        [$st] = $graph('GET', '/me/events/' . rawurlencode((string) $id3));
        scenarioCheck('given to another agent: off the old lead\'s Outlook', $st, 404);

        // 8. Helping on the tech's ticket at the lead's time: on this person's Outlook
        mitTicketTechSave(ticketRow($t3), $user, 'lead');
        $sync();
        $id4 = $event_of($t3);
        [$st, $ev] = $graph('GET', '/me/events/' . rawurlencode((string) $id4));
        scenarioCheck('helper booking added to the helper\'s Outlook', [$st, substr((string) ($ev['start']['dateTime'] ?? ''), 0, 19)], [200, $utc("$after 15:00:00")]);
        mitTicketTechRemove(ticketRow($t3), $user);
        $sync();
        [$st] = $graph('GET', '/me/events/' . rawurlencode((string) $id4));
        scenarioCheck('helper taken off: removed from Outlook', $st, 404);
    }

    // 9. Personal appointments come back as busy times; ITFlow's own emailed invites do not
    [$st, $personal] = $graph('POST', '/me/events', ['subject' => "$tag dentist", 'showAs' => 'busy',
        'start' => ['dateTime' => $utc("$after 12:00:00"), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $utc("$after 12:45:00"), 'timeZone' => 'UTC']]);
    $made_events[] = $personal['id'] ?? '';
    [$st, $invite] = $graph('POST', '/me/events', ['subject' => "$tag invite copy", 'showAs' => 'busy', 'iCalUId' => 'ticket-999999@itflow.test',
        'start' => ['dateTime' => $utc("$after 17:00:00"), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $utc("$after 18:00:00"), 'timeZone' => 'UTC']]);
    $made_events[] = $invite['id'] ?? '';
    [$st, $free] = $graph('POST', '/me/events', ['subject' => "$tag free", 'showAs' => 'free',
        'start' => ['dateTime' => $utc("$after 19:00:00"), 'timeZone' => 'UTC'], 'end' => ['dateTime' => $utc("$after 20:00:00"), 'timeZone' => 'UTC']]);
    $made_events[] = $free['id'] ?? '';
    $sync();
    $busy = mysqli_fetch_all(mysqli_query($mysqli, "SELECT starts_at, ends_at, subject FROM mit_outlook_busy WHERE user_id = $user AND subject LIKE '$tag%'"), MYSQLI_ASSOC);
    scenarioCheck('personal appointment copied in local time; invite copy and free time left out', $busy, [['starts_at' => "$after 12:00:00", 'ends_at' => "$after 12:45:00", 'subject' => "$tag dentist"]]);
    $c = mitScheduleConflicts($user, "$after 12:30:00", "$after 13:30:00");
    scenarioCheck('busy time is a clash for a booking', count(array_filter($c, function ($x) { return strpos($x, 'Outlook:') === 0; })) >= 1, true);
    $items = mitOutlookBusyItems("$after 00:00:00", "$after 23:59:59", ['user_id' => $user]);
    $titles = array_column($items, 'title');
    scenarioCheck('owner sees the title on the calendar', in_array("$tag dentist", $titles, true), true);
    $GLOBALS['session_user_id'] = $tech ?: 999999;
    $titles = array_column(mitOutlookBusyItems("$after 00:00:00", "$after 23:59:59", ['user_id' => $user]), 'title');
    $GLOBALS['session_user_id'] = $session_user_id;
    scenarioCheck('others see only Busy', in_array("$tag dentist", $titles, true), false);
    foreach ($made_events as $id) {
        if ($id) {
            $graph('DELETE', '/me/events/' . rawurlencode($id));
        }
    }
    $made_events = [];
    $sync();
    scenarioCheck('deleted appointment drops off the busy copy', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_outlook_busy WHERE user_id = $user AND subject LIKE '$tag%'"))[0]), 0);

    // 10. The cron job runs clean as the web server user
    exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php mit_outlook_sync.php 2>&1', $out, $code);
    scenarioCheck('cron job mit_outlook_sync exits 0 without output errors', [$code, trim(implode("\n", $out))], [0, '']);
} finally {
    foreach ($made_events as $id) {
        if ($id) {
            $graph('DELETE', '/me/events/' . rawurlencode($id));
        }
    }
    foreach ($tickets as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_techs WHERE ticket_id = $id");
        mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = NULL, ticket_schedule_end = NULL WHERE ticket_id = $id");
    }
    $sync(); // takes their events out of Outlook
    foreach ($tickets as $id) {
        ticketDeleteForGood($id);
    }
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
