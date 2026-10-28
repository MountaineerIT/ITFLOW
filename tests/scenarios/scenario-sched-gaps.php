<?php
/*
 * Gaps found in the scheduling test (Oct 26, 26.10.151) - these checks state the behaviour a user would expect and FAIL
 * until fixed:
 *  1. Dispatch's Schedule dialog saves the ticket's details (client move, public replies made internal) before it checks the
 *     booking; when the agent cannot see the new client the booking is refused but the client move has already happened.
 *  2. The "who is emailed" ticks of a booking are not remembered: cancelling (or later dragging) that booking emails the people
 *     who were deliberately left out of it.
 *  3. A booking that runs past midnight is not on the calendar / Dispatch for the day it runs into (the tickets source selects
 *     bookings by their start only).
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

    // 1. Client change + booking for an agent who cannot see the new client (as agent/mit_dispatch_ajax.php book does)
    mysqli_query($mysqli, "INSERT INTO user_client_permissions SET user_id = $tech, client_id = $client2, permission_type = 'deny'");
    $t = $new_ticket("$tag move", $tech);
    $q("INSERT INTO ticket_replies SET ticket_reply = 'to the client', ticket_reply_type = 'Public', ticket_reply_time_worked = '00:00:00', ticket_reply_by = $session_user_id, ticket_reply_ticket_id = $t");
    $post = ['book' => 1, 'ticket_id' => $t, 'tech_id' => $tech, 'start' => "$day 10:00", 'minutes' => 60, 'detail_client_id' => $client2, 'detail_contact_id' => 0];
    [$ok_book, $message] = mitDispatchBookFromDialog($post); // what agent/mit_dispatch_ajax.php book runs
    scenarioCheck('Dispatch dialog: the booking is refused (agent cannot see the new client)', $ok_book, false);
    $row = ticketRow($t);
    scenarioCheck('Dispatch dialog: a refused booking leaves the ticket on its client', intval($row['ticket_client_id']), $client);
    scenarioCheck('Dispatch dialog: a refused booking leaves its public replies public', intval($one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $t AND ticket_reply_type = 'Public'")), 1);

    // 2. Booked with the contact unticked; then cancelled - the contact should not get a cancellation for a visit they never heard of
    $t2 = $new_ticket("$tag ticks", $tech);
    mitScheduleNotifyFromPost(['mit_schedule_notify_present' => 1, 'mit_schedule_notify' => ['tech' => 1]]);
    $since = intval($one("SELECT COALESCE(MAX(email_id), 0) FROM email_queue"));
    ticketSetSchedule(ticketRow($t2), "$day 13:00:00", 0, "$day 14:00:00");
    unset($GLOBALS['mit_schedule_notify']);
    $contact_mails = function ($since, $subject) use ($one, $tag) {
        return intval($one("SELECT COUNT(*) FROM email_queue WHERE email_id > $since AND email_recipient LIKE '" . strtolower($tag) . "-contact@%' AND email_subject LIKE '$subject%'"));
    };
    scenarioCheck('booked with the contact unticked: the contact is not emailed', $contact_mails($since, 'Ticket Scheduled'), 0);
    // later dragged on the calendar (calendar_move -> ticketSetSchedule, no ticks posted)
    $since = intval($one("SELECT COALESCE(MAX(email_id), 0) FROM email_queue"));
    ticketSetSchedule(ticketRow($t2), "$day 15:00:00", 0, "$day 16:00:00");
    scenarioCheck('the same booking dragged later: the contact left out is still not emailed', $contact_mails($since, 'Ticket Scheduled'), 0);
    $since = intval($one("SELECT COALESCE(MAX(email_id), 0) FROM email_queue"));
    ticketCancelSchedule(ticketRow($t2));
    scenarioCheck('the same booking cancelled: the contact left out gets no cancellation', $contact_mails($since, 'Ticket Schedule Cancelled'), 0);

    // 3. Overnight booking on the next day's calendar and Dispatch day
    $t3 = $new_ticket("$tag overnight", $tech);
    $next = date('Y-m-d', strtotime("$day +1 day"));
    ticketSetSchedule(ticketRow($t3), "$day 22:00:00", 0, "$next 03:00:00");
    $on_next = array_filter(calendarSourceItems('tickets', "$next 00:00:00", "$next 23:59:59", ['client_id' => 0]), function ($i) use ($t3) { return intval($i['extendedProps']['ticket_id'] ?? 0) === $t3; });
    scenarioCheck('a 22:00-03:00 booking shows on the calendar of the day it runs into', count($on_next), 1);
    $on_board = array_filter(mitDispatchItems($next, $next, [$tech]), function ($i) use ($t3) { return intval($i['extendedProps']['ticket_id'] ?? 0) === $t3; });
    scenarioCheck("...and on that day's Dispatch board", count($on_board), 1);
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
