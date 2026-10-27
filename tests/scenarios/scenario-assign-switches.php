<?php
/*
 * Admin > Settings > Notifications switches inside ITFlow's own functions (functions/mit_notifications.php mitNotifyOn): with
 * "assignment - in the app" off a recurring ticket handed to an agent rings no bell (recurringTicketChangeAssignee), with
 * "assignment - email" off ITFlow's recurring-ticket assignment email is not queued (recurringTicketMailAssigned), and a booking's
 * "watchers" tick left off emails no watcher (ticketMailSchedule). Each is tried off, then on (the control). Settings put back.
 */
require __DIR__ . '/bootstrap.php';

if (!function_exists('mitNotifyOn')) {
    echo "  skip - notification switches not here\n";
    exit(0);
}
$tech = intval(scenarioValue("SELECT user_id FROM users WHERE user_email = 'tech@test.test'"));
$tech_email = 'tech@test.test';
$tag = 'ZZassign' . date('His');
$saved = ['assign_app' => mitSetting('notify_assign_app', null), 'assign_email' => mitSetting('notify_assign_email', null)];
$general = scenarioValue("SELECT config_ticket_client_general_notifications FROM settings WHERE company_id = 1");
$switch = function ($key, $on) {
    mitSetSetting("notify_$key", $on ? '1' : '0');
    if (function_exists('mitSettingsForget')) {
        mitSettingsForget();
    }
};
[$client_id, $contact_id] = scenarioMakeClient("$tag client");
mysqli_query($mysqli, "INSERT INTO recurring_tickets SET recurring_ticket_subject = '$tag recurring', recurring_ticket_details = 'x', recurring_ticket_frequency = 'Monthly',
    recurring_ticket_start_date = CURDATE() + INTERVAL 30 DAY, recurring_ticket_next_run = CURDATE() + INTERVAL 30 DAY, recurring_ticket_priority = 'Low',
    recurring_ticket_assigned_to = 0, recurring_ticket_client_id = $client_id");
$recurring = mysqli_insert_id($mysqli);
$bells = function () use ($mysqli, $tech, $tag) {
    return intval(scenarioValue("SELECT COUNT(*) FROM notifications WHERE notification_user_id = $tech AND notification LIKE '%$tag recurring%'"));
};
$mails = function ($to, $like) {
    return intval(scenarioValue("SELECT COUNT(*) FROM email_queue WHERE email_recipient = '" . escapeSql($to) . "' AND email_subject LIKE '" . escapeSql($like) . "'"));
};
try {
    // In the app
    $switch('assign_app', false);
    $switch('assign_email', false);
    recurringTicketChangeAssignee($recurring, $tech);
    scenarioCheck('assignment bell off: no bell for the recurring ticket', $bells(), 0);
    recurringTicketChangeAssignee($recurring, 0);
    $switch('assign_app', true);
    recurringTicketChangeAssignee($recurring, $tech);
    scenarioCheck('control - on: the bell rings', $bells(), 1);

    // By email
    $before = $mails($tech_email, "%$tag recurring%");
    scenarioCheck('assignment email off: nothing queued', recurringTicketMailAssigned($tech, ["$tag recurring"], 'Scenario'), 0);
    scenarioCheck('... nothing in the queue', $mails($tech_email, "%$tag recurring%"), $before);
    $switch('assign_email', true);
    scenarioCheck('control - on: the email is queued', recurringTicketMailAssigned($tech, ["$tag recurring"], 'Scenario') > 0, true);

    // A booking whose watchers tick is off: no watcher email (the contact left out too, so nothing goes to the client at all)
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 1 WHERE company_id = 1");
    $GLOBALS['config_ticket_client_general_notifications'] = 1;
    $ticket_id = scenarioMakeTicket($client_id, $contact_id, ['ticket_assigned_to' => $tech, 'ticket_status' => 2, 'ticket_subject' => "$tag booking"]);
    $watcher = "zz-watch-$ticket_id@example.invalid";
    mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_name = 'Wat Cher', watcher_email = '$watcher', watcher_ticket_id = $ticket_id");
    $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
    $day = date('Y-m-d', strtotime('+4 days'));
    $GLOBALS['mit_schedule_notify'] = ['tech' => false, 'contact' => false, 'watchers' => false, 'site' => false, 'primary' => false, 'extra' => ''];
    ticketSetSchedule(ticketRow($ticket_id), "$day 10:00:00", 0, "$day 11:00:00");
    scenarioCheck('watchers left off: the watcher gets nothing', $mails($watcher, "%[TCK-$number]%"), 0);
    $GLOBALS['mit_schedule_notify']['watchers'] = true;
    ticketSetSchedule(ticketRow($ticket_id), "$day 13:00:00", 0, "$day 14:00:00");
    scenarioCheck('control - ticked: the watcher is emailed', $mails($watcher, "%[TCK-$number]%") >= 1, true);
    unset($GLOBALS['mit_schedule_notify']);
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%[TCK-$number]%'");
    try {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_schedule_notify WHERE ticket_id = $ticket_id");
    } catch (Throwable $e) {
    }
} finally {
    foreach ($saved as $key => $value) {
        if ($value === null) {
            mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name = 'notify_$key'");
        } else {
            mitSetSetting("notify_$key", $value);
        }
    }
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = " . intval($general) . " WHERE company_id = 1");
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification LIKE '%$tag recurring%'");
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag recurring%'");
    mysqli_query($mysqli, "DELETE FROM recurring_tickets WHERE recurring_ticket_id = $recurring");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
