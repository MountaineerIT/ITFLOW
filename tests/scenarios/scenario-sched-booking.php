<?php
/*
 * Who a booking's emails go to - ours on ITFlow's own scheduling (ticketSetSchedule / ticketCancelSchedule -> ticketMailSchedule, whose
 * bookings, invites and clashes are ITFlow's since 26.12): Admin > Notifications' Remote / Onsite defaults (tech, contact, watchers, the
 * site contact, the primary contact, typed addresses - mitScheduleNotify / mitScheduleExtraRecipients), the Schedule window's per-booking
 * ticks (mitScheduleNotifyFromPost), kept for the ticket so a later move or cancellation reaches the same people (mitScheduleChoice, M042),
 * a watchers tick left off, ITFlow's general client notifications off = the tech only, and an agent whose Outlook is synced gets no
 * .ics (mitOutlookSyncsEmail).
 */
require __DIR__ . '/bootstrap.php';

$tag = 'ZZsched' . date('His');
$tech = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'tech@test.test'"))[0] ?? 0);
$admin = $session_user_id;
if (!$tech) {
    echo "  skip - no tech@test.test\n";
    exit(0);
}
$q = function ($sql) use ($mysqli) {
    mysqli_query($mysqli, $sql);
    return mysqli_insert_id($mysqli);
};
$one = function ($sql) use ($mysqli) {
    $r = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $r ? $r[0] : null;
};
// Settings we change, put back at the end
$saved_settings = [];
foreach (['remote', 'onsite'] as $mode) {
    foreach (['tech', 'contact', 'watchers', 'site', 'primary', 'extra'] as $who) {
        $saved_settings["notify_schedule_{$mode}_$who"] = mitSetting("notify_schedule_{$mode}_$who", null);
    }
}
$general = $one("SELECT config_ticket_client_general_notifications FROM settings WHERE company_id = 1");
mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 1 WHERE company_id = 1");
$config_ticket_client_general_notifications = 1;

$client = $q("INSERT INTO clients SET client_name = '$tag client', client_currency_code = 'USD'");
$client2 = $q("INSERT INTO clients SET client_name = '$tag other', client_currency_code = 'USD'");
$primary = $q("INSERT INTO contacts SET contact_name = 'Pat Primary', contact_email = '$tag-primary@example.invalid', contact_primary = 1, contact_client_id = $client");
$site = $q("INSERT INTO contacts SET contact_name = 'Sam Site', contact_email = '$tag-site@example.invalid', contact_client_id = $client");
$location = $q("INSERT INTO locations SET location_name = 'HQ', location_address = '1 Main St', location_contact_id = $site, location_client_id = $client");
$contact = $q("INSERT INTO contacts SET contact_name = 'Terry Ticket', contact_email = '$tag-contact@example.invalid', contact_location_id = $location, contact_client_id = $client");
$other_contact = $q("INSERT INTO contacts SET contact_name = 'Olly Other', contact_email = '$tag-other@example.invalid', contact_client_id = $client2");
$tickets = [];
$new_ticket = function ($subject, $assigned, $client_id = null, $contact_id = null) use (&$tickets, $q, $client, $contact, $session_user_id) {
    $client_id = $client_id ?? $client;
    $contact_id = $contact_id ?? $contact;
    $n = 900000 + count($tickets) + intval(date('s')) * 100;
    $id = $q("INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = $n, ticket_subject = '$subject', ticket_details = 'scenario', ticket_priority = 'Low',
        ticket_status = " . ($assigned ? 2 : 1) . ", ticket_created_by = $session_user_id, ticket_assigned_to = $assigned, ticket_client_id = $client_id, ticket_contact_id = $contact_id,
        ticket_url_key = 'zz$n'");
    $tickets[] = $id;
    return $id;
};
$mails = function ($ticket_id, $since) use ($mysqli) {
    $ref = mysqli_fetch_row(mysqli_query($mysqli, "SELECT CONCAT(ticket_prefix, ticket_number) FROM tickets WHERE ticket_id = $ticket_id"))[0];
    $out = [];
    $r = mysqli_query($mysqli, "SELECT email_recipient, email_subject, email_cal_str FROM email_queue WHERE email_id > $since AND email_subject LIKE '%[$ref]%' ORDER BY email_id");
    while ($row = mysqli_fetch_assoc($r)) {
        $out[] = $row;
    }
    return $out;
};
$last_mail = function () use ($one) {
    return intval($one("SELECT COALESCE(MAX(email_id), 0) FROM email_queue"));
};
$who = function (array $mails, $subject_start) {
    $list = [];
    foreach ($mails as $m) {
        if (strpos($m['email_subject'], $subject_start) === 0) {
            $list[] = strtolower($m['email_recipient']);
        }
    }
    sort($list);
    return $list;
};
$tech_email = $one("SELECT user_email FROM users WHERE user_id = $tech");
$admin_email = $one("SELECT user_email FROM users WHERE user_id = $admin");
$day = date('Y-m-d', strtotime('next tuesday +7 days'));
$event_ids = [];

try {
    // Defaults: remote = tech + contact + watchers; onsite = + site + primary + an extra address, no watchers
    foreach (['tech' => 1, 'contact' => 1, 'watchers' => 1, 'site' => 0, 'primary' => 0, 'extra' => ''] as $k => $v) {
        mitSetSetting("notify_schedule_remote_$k", (string) $v);
    }
    foreach (['tech' => 1, 'contact' => 1, 'watchers' => 0, 'site' => 1, 'primary' => 1, 'extra' => "$tag-desk@example.invalid"] as $k => $v) {
        mitSetSetting("notify_schedule_onsite_$k", (string) $v);
    }
    unset($GLOBALS['mit_schedule_notify']);

    // 1. A remote booking: tech, contact, watcher emailed (not the site / primary contacts)
    $t1 = $new_ticket("$tag remote", $tech);
    $q("INSERT INTO ticket_watchers SET watcher_ticket_id = $t1, watcher_email = '$tag-watcher@example.invalid'");
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t1), "$day 10:00:00", 0, "$day 11:30:00");
    $want = [strtolower("$tag-contact@example.invalid"), strtolower("$tag-watcher@example.invalid"), $tech_email];
    sort($want);
    scenarioCheck('remote defaults: exactly tech, contact, watcher', array_map('strtolower', $who($mails($t1, $since), 'Ticket Scheduled')), $want);
    $since = $last_mail();
    ticketCancelSchedule(ticketRow($t1));
    scenarioCheck('cancellation goes to the same people', array_map('strtolower', $who($mails($t1, $since), 'Ticket Schedule Cancelled')), $want);

    // 2. Onsite defaults: tech, contact, site contact, primary contact, extra address - watcher not
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t1), "$day 09:00:00", 1, "$day 10:00:00");
    $m = $mails($t1, $since);
    $want_onsite = array_map('strtolower', [$tech_email, "$tag-contact@example.invalid", "$tag-site@example.invalid", "$tag-primary@example.invalid", "$tag-desk@example.invalid"]);
    sort($want_onsite);
    scenarioCheck('onsite defaults: tech, contact, site, primary, extra', array_map('strtolower', $who($m, 'Ticket Scheduled')), $want_onsite);
    $cal = array_values(array_filter($m, function ($x) use ($tag) { return strtolower($x['email_recipient']) === strtolower("$tag-site@example.invalid"); }));
    scenarioCheck('the site contact gets the invite too', strpos((string) ($cal[0]['email_cal_str'] ?? ''), 'BEGIN:VCALENDAR') !== false, true);
    $since = $last_mail();
    ticketCancelSchedule(ticketRow($t1));
    scenarioCheck('onsite cancellation reaches the same people', array_map('strtolower', $who($mails($t1, $since), 'Ticket Schedule Cancelled')), $want_onsite);

    // 3. Per-booking ticks (the Schedule window): only the tech, plus one typed address - kept for the ticket
    mitScheduleNotifyFromPost(['mit_schedule_notify_present' => 1, 'mit_schedule_notify' => ['tech' => 1], 'mit_schedule_notify_extra' => "$tag-typed@example.invalid"]);
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t1), "$day 09:00:00", 1, "$day 10:00:00");
    unset($GLOBALS['mit_schedule_notify']);
    $want_ticks = [strtolower("$tag-typed@example.invalid"), strtolower($tech_email)];
    sort($want_ticks);
    scenarioCheck('per-booking ticks: tech + typed address only', array_map('strtolower', $who($mails($t1, $since), 'Ticket Scheduled')), $want_ticks);
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t1), "$day 13:00:00", 1, "$day 14:00:00");
    scenarioCheck('moved later with no ticks posted: the same people (kept for the ticket)', array_map('strtolower', $who($mails($t1, $since), 'Ticket Scheduled')), $want_ticks);
    $since = $last_mail();
    ticketCancelSchedule(ticketRow($t1));
    scenarioCheck('... and its cancellation too', array_map('strtolower', $who($mails($t1, $since), 'Ticket Schedule Cancelled')), $want_ticks);

    // 4. A watchers tick left off: no watcher email, the contact still emailed
    $t2 = $new_ticket("$tag watchers", $tech);
    $q("INSERT INTO ticket_watchers SET watcher_ticket_id = $t2, watcher_email = '$tag-watch2@example.invalid'");
    $GLOBALS['mit_schedule_notify'] = ['tech' => true, 'contact' => true, 'watchers' => false, 'site' => false, 'primary' => false, 'extra' => []];
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t2), "$day 15:00:00", 0, "$day 16:00:00");
    unset($GLOBALS['mit_schedule_notify']);
    $sent = array_map('strtolower', $who($mails($t2, $since), 'Ticket Scheduled'));
    scenarioCheck('watchers left off: the watcher gets nothing', in_array(strtolower("$tag-watch2@example.invalid"), $sent, true), false);
    scenarioCheck('... the contact still does', in_array(strtolower("$tag-contact@example.invalid"), $sent, true), true);
    ticketCancelSchedule(ticketRow($t2));

    // 5. ITFlow's general client notifications off: only the tech, whatever is ticked
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 0 WHERE company_id = 1");
    $config_ticket_client_general_notifications = 0;
    $t3 = $new_ticket("$tag quiet", $tech);
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t3), "$day 09:30:00", 1, "$day 10:00:00");
    scenarioCheck('client notifications off: only the tech is emailed', $who($mails($t3, $since), 'Ticket Scheduled'), [$tech_email]);
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 1 WHERE company_id = 1");
    $config_ticket_client_general_notifications = 1;

    // 6. An Outlook-synced lead (the admin, linked on the test install) gets the email without the .ics; the tech (not linked) with it
    if (function_exists('mitOutlookSyncsEmail') && mitOutlookSyncsEmail($admin_email)) {
        $t4 = $new_ticket("$tag outlook", $admin);
        $since = $last_mail();
        ticketSetSchedule(ticketRow($t4), "$day 15:00:00", 0, "$day 16:00:00");
        $tm = array_values(array_filter($mails($t4, $since), function ($x) use ($admin_email) { return $x['email_recipient'] === $admin_email; }));
        scenarioCheck('Outlook-synced lead: emailed once, no .ics', [count($tm), (string) ($tm[0]['email_cal_str'] ?? 'x')], [1, '']);
        $since = $last_mail();
        ticketCancelSchedule(ticketRow($t4));
        $tm = array_values(array_filter($mails($t4, $since), function ($x) use ($admin_email) { return $x['email_recipient'] === $admin_email; }));
        scenarioCheck('... and its cancellation without one', [count($tm), (string) ($tm[0]['email_cal_str'] ?? 'x')], [1, '']);
        scenarioCheck('control - a lead who is not synced gets the .ics', mitOutlookSyncsEmail($tech_email), false);
    } else {
        scenarioCheck('the admin\'s Outlook is linked on the test install (mit_outlook_links)', false, true);
    }
} finally {
    foreach ($event_ids as $ev) {
        mysqli_query($mysqli, "DELETE FROM calendar_event_attendees WHERE attendee_event_id = $ev");
        mysqli_query($mysqli, "DELETE FROM calendar_events WHERE event_id = $ev");
    }
    foreach ($tickets as $id) {
        ticketDeleteForGood($id);
    }
    mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_client_id IN ($client, $client2)");
    mysqli_query($mysqli, "DELETE FROM locations WHERE location_client_id IN ($client, $client2)");
    mysqli_query($mysqli, "DELETE FROM clients WHERE client_id IN ($client, $client2)");
    foreach ($saved_settings as $k => $v) {
        if ($v === null) {
            mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name = '$k'");
        } else {
            mitSetSetting($k, $v);
        }
    }
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = " . intval($general) . " WHERE company_id = 1");
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%' OR email_recipient LIKE '" . strtolower($tag) . "%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
