<?php
/*
 * Booking work on a ticket end to end against ITFlow's own functions (ticketSetSchedule / ticketCancelSchedule, Dispatch's
 * mitDispatchBook / mitDispatchUnbook / mitDispatchSaveDetails / mitDispatchMerge): the stored start / end, the emails queued
 * (who, per the Remote / Onsite defaults and per-booking ticks, the .ics they carry - times, TZID, UID, METHOD), cancellations,
 * a lead change cancelling the old lead's invite, helpers booked with the lead, clashes (tickets, helpers, calendar events,
 * Outlook busy times, edges that only touch), left behind, the Dispatch queue tabs and details changes.
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

$client = $q("INSERT INTO clients SET client_name = '$tag client', client_currency_code = 'USD', client_net_terms = 30");
$client2 = $q("INSERT INTO clients SET client_name = '$tag other', client_currency_code = 'USD', client_net_terms = 30");
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
    // Defaults: remote = tech + contact + watchers; onsite = + site + primary + an extra address
    foreach (['tech' => 1, 'contact' => 1, 'watchers' => 1, 'site' => 0, 'primary' => 0, 'extra' => ''] as $k => $v) {
        mitSetSetting("notify_schedule_remote_$k", (string) $v);
    }
    foreach (['tech' => 1, 'contact' => 1, 'watchers' => 0, 'site' => 1, 'primary' => 1, 'extra' => "$tag-desk@example.invalid"] as $k => $v) {
        mitSetSetting("notify_schedule_onsite_$k", (string) $v);
    }
    unset($GLOBALS['mit_schedule_notify']);

    // 1. A remote booking: 10:00 - 11:30, stored with its end; tech, contact, watcher emailed with the invite
    $t1 = $new_ticket("$tag remote", $tech);
    $q("INSERT INTO ticket_watchers SET watcher_ticket_id = $t1, watcher_email = '$tag-watcher@example.invalid'");
    $since = $last_mail();
    $result = ticketSetSchedule(ticketRow($t1), "$day 10:00:00", 0, "$day 11:30:00");
    $row = ticketRow($t1);
    scenarioCheck('booking stored with its start', $row['ticket_schedule'], "$day 10:00:00");
    scenarioCheck('booking stored with its end', $row['ticket_schedule_end'], "$day 11:30:00");
    $m = $mails($t1, $since);
    $want = [strtolower("$tag-contact@example.invalid"), strtolower("$tag-watcher@example.invalid"), $tech_email];
    sort($want);
    scenarioCheck('remote defaults: exactly tech, contact, watcher', array_map('strtolower', $who($m, 'Ticket Scheduled')), $want);
    $ics = '';
    foreach ($m as $mail) {
        if ($mail['email_recipient'] === $tech_email) {
            $ics = (string) $mail['email_cal_str'];
        }
    }
    $tz = $config_timezone;
    scenarioCheck('tech invite: DTSTART in the company zone', (bool) preg_match('/DTSTART;TZID=' . preg_quote($tz, '/') . ':' . str_replace('-', '', $day) . 'T100000/', $ics), true);
    scenarioCheck('tech invite: DTEND is the booked end (11:30)', (bool) preg_match('/DTEND;TZID=' . preg_quote($tz, '/') . ':' . str_replace('-', '', $day) . 'T113000/', $ics), true);
    scenarioCheck('tech invite: UID is the ticket\'s', (bool) preg_match('/UID:ticket-' . $t1 . '@/', $ics), true);
    $contact_ics = '';
    foreach ($m as $mail) {
        if (strtolower($mail['email_recipient']) === strtolower("$tag-contact@example.invalid")) {
            $contact_ics = (string) $mail['email_cal_str'];
        }
    }
    scenarioCheck('contact invite carries the same UID and end', (bool) preg_match('/UID:ticket-' . $t1 . '@/', $contact_ics) && strpos($contact_ics, 'T113000') !== false, true);
    scenarioCheck('ticket note says when', strpos((string) $one("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id = $t1 ORDER BY ticket_reply_id DESC LIMIT 1"), 'Ticket scheduled for') === 0, true);

    // 2. The same booking moved (re-scheduled): a new SEQUENCE that outranks the first, same UID
    preg_match('/SEQUENCE:(\d+)/', $ics, $s1);
    sleep(61); // icsSequenceNow() counts minutes
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t1), "$day 13:00:00", 0, "$day 14:00:00");
    $m = $mails($t1, $since);
    $ics2 = '';
    foreach ($m as $mail) {
        if ($mail['email_recipient'] === $tech_email) {
            $ics2 = (string) $mail['email_cal_str'];
        }
    }
    preg_match('/SEQUENCE:(\d+)/', $ics2, $s2);
    scenarioCheck('moved booking: the new invite outranks the first (SEQUENCE)', intval($s2[1] ?? 0) > intval($s1[1] ?? 0), true);

    // 3. Cancel: METHOD:CANCEL with the same UID to the same people
    $since = $last_mail();
    ticketCancelSchedule(ticketRow($t1));
    $row = ticketRow($t1);
    scenarioCheck('cancelled: start and end cleared', [$row['ticket_schedule'], $row['ticket_schedule_end']], [null, null]);
    $m = $mails($t1, $since);
    scenarioCheck('cancellation goes to tech, contact, watcher', array_map('strtolower', $who($m, 'Ticket Schedule Cancelled')), $want);
    $cancel_ics = (string) ($m[0]['email_cal_str'] ?? '');
    scenarioCheck('cancellation: METHOD:CANCEL, same UID', strpos($cancel_ics, 'METHOD:CANCEL') !== false && (bool) preg_match('/UID:ticket-' . $t1 . '@/', $cancel_ics), true);

    // 4. Onsite defaults: tech, contact, site contact, primary contact, extra address - watcher not
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t1), "$day 09:00:00", 1, "$day 10:00:00");
    $m = $mails($t1, $since);
    $want_onsite = array_map('strtolower', [$tech_email, "$tag-contact@example.invalid", "$tag-site@example.invalid", "$tag-primary@example.invalid", "$tag-desk@example.invalid"]);
    sort($want_onsite);
    scenarioCheck('onsite defaults: tech, contact, site, primary, extra', array_map('strtolower', $who($m, 'Ticket Scheduled')), $want_onsite);
    $loc_ok = true;
    foreach ($m as $mail) {
        if (strpos((string) $mail['email_cal_str'], 'LOCATION:1 Main St') === false) {
            $loc_ok = false;
        }
    }
    scenarioCheck('onsite invites carry the site address (the contact\'s location)', $loc_ok, true);
    // cancelling an onsite booking reaches the same people (ticket_onsite defaults)
    $since = $last_mail();
    ticketCancelSchedule(ticketRow($t1));
    scenarioCheck('onsite cancellation reaches the same people', array_map('strtolower', $who($mails($t1, $since), 'Ticket Schedule Cancelled')), $want_onsite);

    // 5. Per-booking ticks (the Schedule window): only the tech, plus one typed address
    mitScheduleNotifyFromPost(['mit_schedule_notify_present' => 1, 'mit_schedule_notify' => ['tech' => 1], 'mit_schedule_notify_extra' => "$tag-typed@example.invalid"]);
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t1), "$day 09:00:00", 1, "$day 10:00:00");
    unset($GLOBALS['mit_schedule_notify']);
    $want_ticks = [strtolower("$tag-typed@example.invalid"), strtolower($tech_email)];
    sort($want_ticks);
    scenarioCheck('per-booking ticks: tech + typed address only', array_map('strtolower', $who($mails($t1, $since), 'Ticket Scheduled')), $want_ticks);

    // 6. General client notifications off: only the tech
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 0 WHERE company_id = 1");
    $config_ticket_client_general_notifications = 0;
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t1), "$day 09:30:00", 0, "$day 10:00:00");
    scenarioCheck('client notifications off: only the tech is emailed', $who($mails($t1, $since), 'Ticket Scheduled'), [$tech_email]);
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 1 WHERE company_id = 1");
    $config_ticket_client_general_notifications = 1;

    // 7. Outlook-synced tech (the admin, when linked) gets the email without the .ics
    if (function_exists('mitOutlookSyncsEmail') && mitOutlookSyncsEmail($admin_email)) {
        $t2 = $new_ticket("$tag outlook", $admin);
        $since = $last_mail();
        ticketSetSchedule(ticketRow($t2), "$day 15:00:00", 0, "$day 16:00:00");
        $tm = array_values(array_filter($mails($t2, $since), function ($x) use ($admin_email) { return $x['email_recipient'] === $admin_email; }));
        scenarioCheck('Outlook-synced lead: emailed once, no .ics', [count($tm), (string) ($tm[0]['email_cal_str'] ?? 'x')], [1, '']);
    }

    // 8. Lead change (as the Schedule window / Dispatch do): the old lead's invite cancelled, the new lead invited
    $t3 = $new_ticket("$tag handover", $tech);
    ticketSetSchedule(ticketRow($t3), "$day 11:00:00", 0, "$day 12:00:00");
    $since = $last_mail();
    $before = ticketRow($t3);
    [$ok] = ticketChangeAssignee($t3, $admin);
    mitScheduleLeadChanging($before, $admin);
    ticketSetSchedule(ticketRow($t3), "$day 11:00:00", 0, "$day 12:00:00");
    $m = $mails($t3, $since);
    scenarioCheck('lead change: the old lead gets a cancellation', in_array($tech_email, $who($m, 'Ticket Schedule Cancelled'), true), true);
    $old_cancel = array_values(array_filter($m, function ($x) use ($tech_email) { return $x['email_recipient'] === $tech_email && strpos($x['email_subject'], 'Cancelled') !== false; }));
    scenarioCheck('lead change: the cancellation is METHOD:CANCEL for the ticket UID', strpos((string) ($old_cancel[0]['email_cal_str'] ?? ''), 'METHOD:CANCEL') !== false, true);
    scenarioCheck('lead change: the old lead is not sent the new booking', in_array($tech_email, $who($m, 'Ticket Scheduled'), true), false);
    scenarioCheck('lead change: the new lead is sent the booking', in_array($admin_email, $who($m, 'Ticket Scheduled'), true), true);

    // 9. Helpers booked with the lead: own invite UID, moved with the lead, cancelled with the lead
    $t4 = $new_ticket("$tag helper", $admin);
    ticketSetSchedule(ticketRow($t4), "$day 08:00:00", 0, "$day 09:00:00");
    $since = $last_mail();
    [$ok, $msg] = mitTicketTechSave(ticketRow($t4), $tech, 'lead');
    scenarioCheck('helper added with the lead', $ok, true);
    $m = $mails($t4, $since);
    $hm = array_values(array_filter($m, function ($x) use ($tech_email) { return $x['email_recipient'] === $tech_email; }));
    scenarioCheck('helper emailed their booking with their own invite UID', (bool) preg_match('/UID:ticket-' . $t4 . '-tech-' . $tech . '@/', (string) ($hm[0]['email_cal_str'] ?? '')), true);
    scenarioCheck('helper invite ends with the lead (09:00)', strpos((string) ($hm[0]['email_cal_str'] ?? ''), 'T090000') !== false, true);
    $since = $last_mail();
    ticketSetSchedule(ticketRow($t4), "$day 14:00:00", 0, "$day 16:00:00");
    $hm = array_values(array_filter($mails($t4, $since), function ($x) use ($tech_email) { return $x['email_recipient'] === $tech_email; }));
    scenarioCheck('lead moved: helper sent the new time (14:00-16:00)', count($hm) === 1 && strpos((string) $hm[0]['email_cal_str'], 'T140000') !== false && strpos((string) $hm[0]['email_cal_str'], 'T160000') !== false, true);
    // helper bookings on the calendar
    $items = calendarSourceItems('tickets', "$day 00:00:00", "$day 23:59:59", ['client_id' => 0, 'user_id' => $tech]);
    $helper_item = array_values(array_filter($items, function ($i) use ($t4) { return ($i['extendedProps']['kind'] ?? '') === 'helper' && intval($i['extendedProps']['ticket_id'] ?? 0) === $t4; }));
    scenarioCheck("helper's booking on the helper's calendar at the lead's time", [$helper_item[0]['start'] ?? '', $helper_item[0]['end'] ?? ''], ["$day 14:00:00", "$day 16:00:00"]);
    scenarioCheck("helper's booking named for the helper", $helper_item[0]['extendedProps']['agent_name'] ?? '', mitFirstName($one("SELECT user_name FROM users WHERE user_id = $tech")));
    // a clash for the helper at that time
    $t5 = $new_ticket("$tag clash", $tech);
    $r = ticketSetSchedule(ticketRow($t5), "$day 15:00:00", 0, "$day 15:30:00");
    scenarioCheck('helper booking counts as a clash for the helper', count(array_filter($r['conflicts'], function ($c) use ($tag) { return strpos($c, "$tag helper") !== false; })), 1);
    $since = $last_mail();
    ticketCancelSchedule(ticketRow($t4));
    $hm = array_values(array_filter($mails($t4, $since), function ($x) use ($tech_email) { return $x['email_recipient'] === $tech_email; }));
    scenarioCheck('lead cancelled: helper gets a cancellation of their own UID', count($hm) === 1 && strpos((string) $hm[0]['email_cal_str'], 'METHOD:CANCEL') !== false && (bool) preg_match('/UID:ticket-' . $t4 . '-tech-' . $tech . '@/', (string) $hm[0]['email_cal_str']), true);
    ticketCancelSchedule(ticketRow($t5));

    // 10. Clashes: overlap, touching edges, default length, closed tickets, calendar events (timed / all-day)
    $day_main = $day;
    $day = date('Y-m-d', strtotime("$day +2 days"));
    $a = $new_ticket("$tag A", $tech);
    ticketSetSchedule(ticketRow($a), "$day 10:00:00", 0, "$day 11:00:00");
    scenarioCheck('clash: overlapping booking', count(mitScheduleConflicts($tech, "$day 10:30:00", "$day 11:30:00")), 1);
    scenarioCheck('no clash: starts as the other ends', count(mitScheduleConflicts($tech, "$day 11:00:00", "$day 12:00:00")), 0);
    scenarioCheck('no clash: ends as the other starts', count(mitScheduleConflicts($tech, "$day 09:00:00", "$day 10:00:00")), 0);
    scenarioCheck('no clash with itself', count(mitScheduleConflicts($tech, "$day 10:00:00", "$day 11:00:00", $a)), 0);
    scenarioCheck('another agent has no clash', count(mitScheduleConflicts($admin, "$day 10:30:00", "$day 11:30:00")), 0);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $a");
    scenarioCheck('a resolved ticket\'s booking is no clash', count(mitScheduleConflicts($tech, "$day 10:30:00", "$day 11:30:00")), 0);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NULL WHERE ticket_id = $a");
    // an event the tech is invited to
    $ev = $q("INSERT INTO calendar_events SET event_title = '$tag meeting', event_start = '$day 12:00:00', event_end = '$day 13:00:00', event_calendar_id = 1, event_created_by = $admin");
    $event_ids[] = $ev;
    $q("INSERT INTO calendar_event_attendees SET attendee_event_id = $ev, attendee_user_id = $tech");
    $c = mitScheduleConflicts($tech, "$day 12:30:00", "$day 13:30:00");
    scenarioCheck('clash: a meeting the tech is on', count(array_filter($c, function ($x) use ($tag) { return strpos($x, "$tag meeting") === 0; })), 1);
    scenarioCheck('no clash: meeting ends as the booking starts', count(mitScheduleConflicts($tech, "$day 13:00:00", "$day 14:00:00")), 0);
    $ev2 = $q("INSERT INTO calendar_events SET event_title = '$tag allday', event_start = '$day 00:00:00', event_end = '$day 00:00:00', event_all_day = 1, event_calendar_id = 1, event_created_by = $tech");
    $event_ids[] = $ev2;
    scenarioCheck('an all-day event is no clash', count(array_filter(mitScheduleConflicts($tech, "$day 16:00:00", "$day 17:00:00"), function ($x) use ($tag) { return strpos($x, "$tag allday") === 0; })), 0);
    // weekly repeating meeting, an occurrence a week later
    $ev3 = $q("INSERT INTO calendar_events SET event_title = '$tag weekly', event_start = '$day 07:00:00', event_end = '$day 07:30:00', event_repeat = 'Week', event_repeat_interval = 1, event_calendar_id = 1, event_created_by = $tech");
    $event_ids[] = $ev3;
    $next_week = date('Y-m-d', strtotime("$day +7 days"));
    scenarioCheck('clash: a repeating meeting\'s later occurrence', count(array_filter(mitScheduleConflicts($tech, "$next_week 07:15:00", "$next_week 08:00:00"), function ($x) use ($tag) { return strpos($x, "$tag weekly") === 0; })), 1);
    // Outlook busy time
    $busy = $q("INSERT INTO mit_outlook_busy SET user_id = $tech, starts_at = '$day 17:00:00', ends_at = '$day 18:00:00', is_all_day = 0, subject = '$tag dentist', is_private = 0, show_as = 'busy'");
    $c = mitScheduleConflicts($tech, "$day 17:30:00", "$day 18:30:00");
    scenarioCheck('clash: Outlook busy time', count(array_filter($c, function ($x) { return strpos($x, 'Outlook:') === 0; })), 1);
    mysqli_query($mysqli, "DELETE FROM mit_outlook_busy WHERE busy_id = $busy");
    ticketCancelSchedule(ticketRow($a));
    $day = $day_main;

    // 11. Overnight booking: 22:00 - 02:00 is stored across midnight and clashes on the next morning
    $o = $new_ticket("$tag overnight", $tech);
    $next = date('Y-m-d', strtotime("$day +1 day"));
    ticketSetSchedule(ticketRow($o), "$day 22:00:00", 0, "$next 02:00:00");
    scenarioCheck('overnight end stored on the next day', ticketRow($o)['ticket_schedule_end'], "$next 02:00:00");
    scenarioCheck('overnight booking clashes at 01:00 the next day', count(mitScheduleConflicts($tech, "$next 01:00:00", "$next 01:30:00")), 1);
    // (shown on the next day's calendar: scenario-sched-gaps.php)
    ticketCancelSchedule(ticketRow($o));

    // 12. Left behind: booked yesterday, still open; resolved = not; booked earlier today = not
    $lb = $new_ticket("$tag left", $tech);
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = '$yesterday 10:00:00', ticket_schedule_end = '$yesterday 11:00:00' WHERE ticket_id = $lb");
    $ids = array_column(mitLeftBehindTickets($tech, 0, 500), 'id');
    scenarioCheck('left behind: yesterday\'s open booking listed for the tech', in_array($lb, $ids, true), true);
    scenarioCheck('left behind: listed under Everyone too', in_array($lb, array_column(mitLeftBehindTickets(0, 0, 500), 'id'), true), true);
    scenarioCheck('left behind: not for another agent', in_array($lb, array_column(mitLeftBehindTickets($admin, 0, 500), 'id'), true), false);
    $q_lb = mitDispatchQueue('left_behind', ['board' => 'all']);
    scenarioCheck('Dispatch Left behind tab lists it', in_array($lb, array_column($q_lb['tickets'], 'id'), true), true);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = '" . date('Y-m-d') . " 00:30:00', ticket_schedule_end = '" . date('Y-m-d') . " 01:00:00' WHERE ticket_id = $lb");
    scenarioCheck('left behind: earlier today is not left behind yet', in_array($lb, array_column(mitLeftBehindTickets($tech, 0, 500), 'id'), true), false);
    // an overnight booking from yesterday into today is not left behind
    mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = '$yesterday 23:00:00', ticket_schedule_end = '" . date('Y-m-d') . " 01:00:00' WHERE ticket_id = $lb");
    scenarioCheck('left behind: an overnight booking ending today is not left behind', in_array($lb, array_column(mitLeftBehindTickets($tech, 0, 500), 'id'), true), false);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = '$yesterday 10:00:00', ticket_schedule_end = '$yesterday 11:00:00' WHERE ticket_id = $lb");
    $items = calendarSourceItems('tickets', "$yesterday 00:00:00", "$yesterday 23:59:59", ['client_id' => 0, 'user_id' => $tech]);
    $mark = array_values(array_filter($items, function ($i) use ($lb) { return intval($i['extendedProps']['ticket_id'] ?? 0) === $lb; }));
    scenarioCheck('left behind: its old booking marked on the calendar', strpos((string) ($mark[0]['className'] ?? ''), 'mit-left-behind') !== false, true);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $lb");
    scenarioCheck('left behind: resolved ones drop off', in_array($lb, array_column(mitLeftBehindTickets($tech, 0, 500), 'id'), true), false);

    // 13. Service colour / agent colour / agent name on bookings
    $sc = $new_ticket("$tag colour", $tech);
    ticketSetSchedule(ticketRow($sc), "$day 18:00:00", 0, "$day 19:00:00");
    $items = calendarSourceItems('tickets', "$day 00:00:00", "$day 23:59:59", ['client_id' => 0]);
    $it = array_values(array_filter($items, function ($i) use ($sc) { return intval($i['extendedProps']['ticket_id'] ?? 0) === $sc; }))[0] ?? [];
    scenarioCheck('service booking filled with the service colour', $it['color'] ?? '', mitServiceTicketColor());
    scenarioCheck('agent colour as the bar', $it['extendedProps']['tech_color'] ?? '', mitAgentColor($tech));
    scenarioCheck('agent name beside the time, not in the detail', [$it['extendedProps']['agent_name'] ?? '', strpos((string) ($it['extendedProps']['detail'] ?? ''), (string) $one("SELECT user_name FROM users WHERE user_id = $tech")) === false],
        [mitFirstName($one("SELECT user_name FROM users WHERE user_id = $tech")), true]);
    scenarioCheck('booking end on the calendar', $it['end'] ?? '', "$day 19:00:00");
    // a picked colour wins
    $saved_color = mitUserPreference($tech, 'calendar.color', null);
    mitSaveAgentColor($tech, ['mit_calendar_color' => '#ABCDEF']);
    scenarioCheck('picked colour saved lower-case', mitAgentColor($tech), '#abcdef');
    mitSaveAgentColor($tech, ['mit_calendar_color' => 'red; x']);
    scenarioCheck('a bad colour is refused, the old one kept', mitAgentColor($tech), '#abcdef');
    mitSaveAgentColor($tech, ['mit_calendar_color' => '#000000', 'mit_calendar_color_auto' => 1]);
    scenarioCheck('Automatic goes back to the worked-out colour', mitAgentColor($tech), mitAgentDefaultColor($tech));
    if ($saved_color !== null) {
        mitSetUserPreference($tech, 'calendar.color', $saved_color);
        mitAgentChosenColors(true);
    }
    ticketCancelSchedule(ticketRow($sc));

    // 14. Dispatch: queue tabs, book (assign + time + length), details (client change, contact), unbook, merge
    $d1 = $new_ticket("$tag dispatch", 0);
    $qu = mitDispatchQueue('unassigned', ['q' => "$tag dispatch", 'board' => 'all']);
    scenarioCheck('Dispatch: an unassigned ticket is in the Unassigned tab', array_column($qu['tickets'], 'id'), [$d1]);
    $since = $last_mail();
    [$ok, $msg] = mitDispatchBook($d1, $tech, "$day 08:00", 90, true, true);
    $row = ticketRow($d1);
    scenarioCheck('Dispatch book: assigned, booked 90 min, onsite', [$ok, intval($row['ticket_assigned_to']), $row['ticket_schedule'], $row['ticket_schedule_end'], intval($row['ticket_onsite'])],
        [true, $tech, "$day 08:00:00", "$day 09:30:00", 1]);
    scenarioCheck('Dispatch book: the tech is emailed the booking', in_array($tech_email, $who($mails($d1, $since), 'Ticket Scheduled'), true), true);
    // tech only ("Email the contact" unticked)
    $since = $last_mail();
    mitDispatchBook($d1, $tech, "$day 09:00", 30, false, false);
    scenarioCheck('Dispatch book without the contact: only the tech emailed', $who($mails($d1, $since), 'Ticket Scheduled'), [$tech_email]);
    // queue: booked tickets are not in Not booked
    $qn = mitDispatchQueue('unbooked', ['q' => "$tag dispatch", 'board' => 'all']);
    scenarioCheck('Dispatch: a booked ticket is not in Not booked', array_column($qn['tickets'], 'id'), []);
    // Details: move to the other client with its contact - booking for the tech stays, public replies go internal
    $q("INSERT INTO ticket_replies SET ticket_reply = 'public one', ticket_reply_type = 'Public', ticket_reply_time_worked = '00:00:00', ticket_reply_by = $admin, ticket_reply_ticket_id = $d1");
    [$ok, $msg] = mitDispatchSaveDetails($d1, ['detail_client_id' => $client2, 'detail_contact_id' => $other_contact, 'detail_subject' => "$tag dispatch moved", 'detail_priority' => 'High', 'detail_sla' => 'auto']);
    $row = ticketRow($d1);
    scenarioCheck('Dispatch details: client, contact, subject, priority changed', [$ok, intval($row['ticket_client_id']), intval($row['ticket_contact_id']), $row['ticket_subject'], $row['ticket_priority']],
        [true, $client2, $other_contact, "$tag dispatch moved", 'High']);
    scenarioCheck('Dispatch details: public replies made internal on the client move', intval($one("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $d1 AND ticket_reply_type = 'Public'")), 0);
    [$ok] = mitDispatchSaveDetails($d1, ['detail_client_id' => $client2, 'detail_contact_id' => $contact]);
    scenarioCheck('Dispatch details: another client\'s contact is refused (set to none)', intval(ticketRow($d1)['ticket_contact_id']), 0);
    [$ok, $msg] = mitDispatchSaveDetails($d1, ['detail_client_id' => $client2, 'detail_subject' => '   ']);
    scenarioCheck('Dispatch details: an empty subject is refused', $ok, false);
    // unbook
    $since = $last_mail();
    [$ok] = mitDispatchUnbook($d1);
    scenarioCheck('Dispatch unbook: booking off, cancellation sent', [$ok, ticketRow($d1)['ticket_schedule'], in_array($tech_email, $who($mails($d1, $since), 'Ticket Schedule Cancelled'), true)], [true, null, true]);
    scenarioCheck('Dispatch: unbooked ticket back in Not booked', in_array($d1, array_column(mitDispatchQueue('unbooked', ['q' => "$tag dispatch", 'board' => 'all'])['tickets'], 'id'), true), true);
    // contact's other tickets + merge (a scheduled child is unscheduled first)
    $m1 = $new_ticket("$tag dup one", $tech, $client2, $other_contact);
    $m2 = $new_ticket("$tag dup two", $tech, $client2, $other_contact);
    ticketSetSchedule(ticketRow($m2), "$day 07:00:00", 0, "$day 07:30:00");
    $others = array_column(mitDispatchContactTickets($other_contact, $m1), 'id');
    scenarioCheck("Dispatch: the contact's other open tickets", in_array($m2, $others, true) && !in_array($m1, $others, true), true);
    [$ok, $msg] = mitDispatchMerge($m2, $m1, '', true);
    scenarioCheck('Dispatch merge needs a reason', $ok, false);
    [$ok, $msg] = mitDispatchMerge($m2, $t1, 'dup', true);
    scenarioCheck('Dispatch merge across clients refused', $ok, false);
    $since = $last_mail();
    [$ok, $msg] = mitDispatchMerge($m2, $m1, 'duplicate', true);
    $child = ticketRow($m2);
    scenarioCheck('Dispatch merge: child closed and unscheduled', [$ok, !empty($child['ticket_closed_at']), $child['ticket_schedule']], [true, true, null]);
    scenarioCheck('Dispatch merge: the merged ticket\'s invite cancelled', in_array($tech_email, $who($mails($m2, $since), 'Ticket Schedule Cancelled'), true), true);

    // 15. Dispatch items for the board: the tech's bookings, their meeting, holidays, editable only for tickets/events
    $bt = $new_ticket("$tag board", $tech);
    $clash_day = date('Y-m-d', strtotime("$day +2 days"));
    ticketSetSchedule(ticketRow($bt), "$clash_day 10:00:00", 0, "$clash_day 11:00:00");
    $items = mitDispatchItems($clash_day, $clash_day, [$tech]);
    $kinds = [];
    foreach ($items as $i) {
        if (intval($i['extendedProps']['ticket_id'] ?? 0) === $bt || ($i['title'] ?? '') === "$tag meeting") {
            $kinds[] = ($i['extendedProps']['kind'] ?? '') . ':' . intval($i['extendedProps']['tech'] ?? 0);
        }
    }
    sort($kinds);
    scenarioCheck('Dispatch board: the booking and the meeting on the tech\'s column', $kinds, ["event:$tech", "ticket:$tech"]);
    ticketCancelSchedule(ticketRow($bt));
} finally {
    foreach ($event_ids as $ev) {
        mysqli_query($mysqli, "DELETE FROM calendar_event_attendees WHERE attendee_event_id = $ev");
        mysqli_query($mysqli, "DELETE FROM calendar_events WHERE event_id = $ev");
    }
    foreach ($tickets as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_techs WHERE ticket_id = $id");
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
