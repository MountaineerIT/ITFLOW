<?php
/*
 * Dispatch is ITFlow's own since 26.12 (functions/dispatch.php). Ours before its booking (agent/ajax.php dispatch_book ->
 * mitDispatchBeforeBook, functions/mit_dispatch.php): the booking is checked FIRST (mitDispatchBookProblem), so a refused one - the agent
 * can't see the client the Schedule window moves the ticket to - changes nothing (client, public replies); an accepted one saves the window's
 * ticket details (title, priority, client move with public replies made internal) and takes its email ticks, which ITFlow's dispatchBook
 * then follows (only the tech ticked = only the tech emailed).
 */
require __DIR__ . '/bootstrap.php';

$tech = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'tech@test.test'"))[0] ?? 0);
$tag = 'ZZgap' . date('His');
$q = function ($sql) use ($mysqli) {
    mysqli_query($mysqli, $sql);
    return mysqli_insert_id($mysqli);
};
$one = function ($sql) use ($mysqli) {
    $r = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $r ? $r[0] : null;
};
$general = $one("SELECT config_ticket_client_general_notifications FROM settings WHERE company_id = 1");
mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 1 WHERE company_id = 1");
$config_ticket_client_general_notifications = 1;
$client = $q("INSERT INTO clients SET client_name = '$tag client', client_currency_code = 'USD'");
$client2 = $q("INSERT INTO clients SET client_name = '$tag hidden', client_currency_code = 'USD'");
$contact = $q("INSERT INTO contacts SET contact_name = 'Cara Contact', contact_email = '" . strtolower($tag) . "-contact@example.invalid', contact_client_id = $client");
$tickets = [];
$new_ticket = function ($subject, $assigned) use (&$tickets, $q, $client, $contact, $session_user_id) {
    $n = 300000 + count($tickets) + intval(date('s')) * 100;
    return $tickets[] = $q("INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = $n, ticket_subject = '$subject', ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 2, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned, ticket_client_id = $client, ticket_contact_id = $contact, ticket_url_key = 'zg$n'");
};
$day = date('Y-m-d', strtotime('next monday +14 days'));
$remote_contact = mitSetting('notify_schedule_remote_contact', null);
try {
    mitSetSetting('notify_schedule_remote_contact', '1');

    // 1. Client change + booking for an agent who cannot see the new client
    mysqli_query($mysqli, "INSERT INTO user_client_permissions SET user_id = $tech, client_id = $client2, permission_type = 'deny'");
    $t = $new_ticket("$tag move", $tech);
    $q("INSERT INTO ticket_replies SET ticket_reply = 'to the client', ticket_reply_type = 'Public', ticket_reply_time_worked = '00:00:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $t");
    $post = ['dispatch_book' => 1, 'ticket_id' => $t, 'agent_id' => $tech, 'start' => "$day 10:00", 'minutes' => 60, 'detail_client_id' => $client2, 'detail_contact_id' => 0,
        'detail_subject' => "$tag renamed"];
    $why = mitDispatchBeforeBook($post);
    scenarioCheck('Dispatch dialog: the booking is refused (agent cannot see the new client)', strpos($why, 'does not have access') !== false, true);
    $row = ticketRow($t);
    scenarioCheck('a refused booking leaves the ticket on its client, its title as it was', [intval($row['ticket_client_id']), $row['ticket_subject']], [$client, "$tag move"]);
    scenarioCheck('a refused booking leaves its public replies public', intval($one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $t AND ticket_reply_type = 'Public'")), 1);
    scenarioCheck('... and not booked', $row['ticket_schedule'], null);
    mysqli_query($mysqli, "DELETE FROM user_client_permissions WHERE user_id = $tech AND client_id = $client2");

    // 2. The same, now allowed: details saved, ticks taken, then ITFlow's dispatchBook
    unset($GLOBALS['mit_schedule_notify']);
    $post = ['dispatch_book' => 1, 'ticket_id' => $t, 'agent_id' => $tech, 'start' => "$day 10:00", 'minutes' => 60, 'detail_client_id' => $client2, 'detail_contact_id' => 0,
        'detail_subject' => "$tag renamed", 'detail_priority' => 'High', 'mit_schedule_notify_present' => 1, 'mit_schedule_notify' => ['tech' => 1]];
    scenarioCheck('allowed: nothing stops it', mitDispatchBeforeBook($post), '');
    $row = ticketRow($t);
    scenarioCheck('details saved: client, title, priority', [intval($row['ticket_client_id']), $row['ticket_subject'], $row['ticket_priority']], [$client2, "$tag renamed", 'High']);
    scenarioCheck('the client move made its public replies internal', intval($one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $t AND ticket_reply_type = 'Public'")), 0);
    scenarioCheck('the email ticks taken for the booking', [$GLOBALS['mit_schedule_notify']['tech'] ?? null, $GLOBALS['mit_schedule_notify']['contact'] ?? null], [true, false]);
    $q("INSERT INTO ticket_watchers SET watcher_ticket_id = $t, watcher_email = '" . strtolower($tag) . "-watcher@example.invalid'"); // on by default - not ticked here
    $since = intval($one("SELECT COALESCE(MAX(email_id), 0) FROM email_queue"));
    [$ok] = dispatchBook($t, $tech, "$day 10:00", 60, false, true);
    unset($GLOBALS['mit_schedule_notify']);
    $number = $one("SELECT ticket_number FROM tickets WHERE ticket_id = $t");
    $to = array_map('strtolower', scenarioColumn("SELECT email_recipient FROM email_queue WHERE email_id > $since AND email_subject LIKE 'Ticket Scheduled%[ZZ-$number]%'"));
    scenarioCheck("ITFlow's dispatchBook books it, emailing the tech only", [$ok, ticketRow($t)['ticket_schedule'], $to], [true, "$day 10:00:00", ['tech@test.test']]);
} finally {
    mysqli_query($mysqli, "DELETE FROM user_client_permissions WHERE user_id = $tech AND client_id = $client2");
    foreach ($tickets as $id) {
        ticketDeleteForGood($id);
    }
    mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_client_id IN ($client, $client2)");
    mysqli_query($mysqli, "DELETE FROM clients WHERE client_id IN ($client, $client2)");
    if ($remote_contact === null) {
        mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name = 'notify_schedule_remote_contact'");
    } else {
        mitSetSetting('notify_schedule_remote_contact', $remote_contact);
    }
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = " . intval($general) . " WHERE company_id = 1");
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%' OR email_recipient LIKE '" . strtolower($tag) . "%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
