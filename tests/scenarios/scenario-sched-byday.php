<?php
/*
 * Repeats by weekday ("the third Wednesday", "the last Friday" - functions/mit_recurring.php), checked against an
 * independent brute-force oracle: the date maths, recurring tickets' next runs (monthly / quarterly / yearly, missed
 * runs stepped over), the nightly job raising a weekday-rule ticket and moving it on, calendar events expanded over
 * DST with interval / until / skipped dates, the RRULE in the .ics, and the event form saving the rule.
 */
require __DIR__ . '/bootstrap.php';

/** Oracle: the nth (1-4, -1 = last) weekday (ISO 1-7) of a month, by walking the days. */
function oracleByDay($year, $month, $nth, $iso) {
    $hits = [];
    for ($d = 1; checkdate($month, $d, $year); $d++) {
        if (intval(date('N', mktime(12, 0, 0, $month, $d, $year))) === $iso) {
            $hits[] = sprintf('%04d-%02d-%02d', $year, $month, $d);
        }
    }
    return $nth === -1 ? end($hits) : $hits[$nth - 1];
}
$codes = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

// 1. mitByDayDate for every rule over three years
$bad = [];
foreach ([2026, 2027, 2028] as $y) {
    for ($m = 1; $m <= 12; $m++) {
        foreach ($codes as $code => $iso) {
            foreach ([1, 2, 3, 4, -1] as $nth) {
                $want = oracleByDay($y, $m, $nth, $iso);
                $got = mitByDayDate($y, $m, $nth . $code);
                if ($got !== $want) {
                    $bad[] = "$y-$m $nth$code: $got != $want";
                }
            }
        }
    }
}
scenarioCheck('mitByDayDate matches the calendar for 1st-4th / last of every weekday, 36 months', implode('; ', array_slice($bad, 0, 5)), '');

// 2. Options offered for a date
scenarioCheck('options for Mon 2026-10-26 (4th and last Monday)', array_keys(mitByDayOptions('2026-10-26')), ['4MO', '-1MO']);
scenarioCheck('options for Thu 2026-10-29 (5th Thursday: last only)', array_keys(mitByDayOptions('2026-10-29')), ['-1TH']);
scenarioCheck('options for Wed 2026-10-21 (3rd Wednesday only)', array_keys(mitByDayOptions('2026-10-21')), ['3WE']);
scenarioCheck('a rule that does not fit the date is dropped', mitByDayForDate('3WE', '2026-10-22'), '');
scenarioCheck('follow: last stays last on a new date', mitByDayFollow('-1FR', '2026-11-26'), '-1TH');
scenarioCheck('follow: 3WE moved to a 2nd Tuesday becomes 2TU', mitByDayFollow('3WE', '2026-11-10'), '2TU');

// 3. recurringTicketNextRun - every month for two years against the oracle
$bad = [];
foreach (['3WE' => [3, 3], '-1FR' => [-1, 5], '1MO' => [1, 1], '4SU' => [4, 7]] as $rule => [$nth, $iso]) {
    $date = oracleByDay(2026, 10, $nth, $iso);
    for ($i = 1; $i <= 24; $i++) {
        $date = recurringTicketNextRun($date, 'Monthly', '2026-10-01', $date, $rule);
        $month = 10 + $i;
        $want = oracleByDay(2026 + intdiv($month - 1, 12), ($month - 1) % 12 + 1, $nth, $iso);
        if ($date !== $want) {
            $bad[] = "$rule step $i: $date != $want";
            break;
        }
    }
}
scenarioCheck('monthly weekday rules step month by month for 24 months', implode('; ', $bad), '');
scenarioCheck('quarterly 3WE from 2026-10-21', recurringTicketNextRun('2026-10-21', 'Quarterly', '2026-10-21', '2026-10-21', '3WE'), oracleByDay(2027, 1, 3, 3));
scenarioCheck('biannually -1FR from 2026-10-30', recurringTicketNextRun('2026-10-30', 'Biannually', '2026-10-30', '2026-10-30', '-1FR'), oracleByDay(2027, 4, -1, 5));
scenarioCheck('annually -1FR from 2026-10-30', recurringTicketNextRun('2026-10-30', 'Annually', '2026-10-30', '2026-10-30', '-1FR'), oracleByDay(2027, 10, -1, 5));
scenarioCheck('missed runs stepped over: due 2026-06-17 (3WE), after 2026-10-26', recurringTicketNextRun('2026-06-17', 'Monthly', '2026-06-17', '2026-10-26', '3WE'), oracleByDay(2026, 11, 3, 3));
scenarioCheck('missed run whose next month day is still ahead this month', recurringTicketNextRun('2026-09-28', 'Monthly', '2026-09-28', '2026-10-25', '-1MO'), '2026-10-26');
scenarioCheck('weekly frequency ignores a stray rule', recurringTicketNextRun('2026-10-26', 'Weekly', '2026-10-26', '2026-10-26', '4MO'), '2026-11-02');
scenarioCheck('cadence text', recurringTicketCadenceText(['recurring_ticket_frequency' => 'Monthly', 'recurring_ticket_next_run' => '2026-11-18', 'recurring_ticket_by_day' => '3WE']), 'on the third Wednesday');
scenarioCheck('upcoming list follows the rule', recurringTicketUpcoming(['recurring_ticket_next_run' => '2026-10-30', 'recurring_ticket_frequency' => 'Monthly', 'recurring_ticket_start_date' => '2026-10-30', 'recurring_ticket_by_day' => '-1FR']),
    ['2026-10-30', oracleByDay(2026, 11, -1, 5), oracleByDay(2026, 12, -1, 5)]);

// 4. The nightly job raises a weekday-rule ticket due today and moves it to the rule's next date
$client_id = scenarioClient();
$today = date('Y-m-d');
$opts = mitByDayOptions($today);
$rule = (string) array_key_first($opts);
[$nth, $code] = mitByDayParts($rule);
$subject = 'ZZ Scenario byday ' . date('His');
mysqli_query($mysqli, "INSERT INTO recurring_tickets SET recurring_ticket_subject = '$subject', recurring_ticket_details = 'x', recurring_ticket_priority = 'Low',
    recurring_ticket_frequency = 'Monthly', recurring_ticket_by_day = '$rule', recurring_ticket_start_date = '$today', recurring_ticket_next_run = '$today',
    recurring_ticket_client_id = $client_id, recurring_ticket_created_by = $session_user_id");
$rid = mysqli_insert_id($mysqli);
// another one, missed four months ago on the same kind of rule
$past = date('Y-m-d', strtotime('-4 months', strtotime($today)));
$past_rule = (string) array_key_first(mitByDayOptions($past));
[$pnth, $pcode] = mitByDayParts($past_rule);
mysqli_query($mysqli, "INSERT INTO recurring_tickets SET recurring_ticket_subject = '$subject missed', recurring_ticket_details = 'x', recurring_ticket_priority = 'Low',
    recurring_ticket_frequency = 'Monthly', recurring_ticket_by_day = '$past_rule', recurring_ticket_start_date = '$past', recurring_ticket_next_run = '$past',
    recurring_ticket_client_id = $client_id, recurring_ticket_created_by = $session_user_id");
$rid2 = mysqli_insert_id($mysqli);
try {
    exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php nightly_tasks.php 2>&1', $out, $code_run);
    scenarioCheck('nightly job ran', $code_run, 0);
    $raised = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_recurring_ticket_id = $rid"))[0]);
    scenarioCheck('nightly job raised exactly one ticket for the rule due today', $raised, 1);
    $next = mysqli_fetch_row(mysqli_query($mysqli, "SELECT recurring_ticket_next_run, recurring_ticket_by_day FROM recurring_tickets WHERE recurring_ticket_id = $rid"));
    $ny = intval(date('Y', strtotime("$today first day of next month")));
    $nm = intval(date('n', strtotime("$today first day of next month")));
    scenarioCheck("next run moved to $rule of next month", $next[0], oracleByDay($ny, $nm, $nth, $codes[$code]));
    scenarioCheck('rule kept', $next[1], $rule);
    $raised2 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_recurring_ticket_id = $rid2"))[0]);
    scenarioCheck('a missed one raises once, not once per missed month', $raised2, 1);
    $next2 = mysqli_fetch_row(mysqli_query($mysqli, "SELECT recurring_ticket_next_run FROM recurring_tickets WHERE recurring_ticket_id = $rid2"))[0];
    $want2 = oracleByDay(intval(date('Y')), intval(date('n')), $pnth, $codes[$pcode]);
    if ($want2 <= $today) {
        $want2 = oracleByDay($ny, $nm, $pnth, $codes[$pcode]);
    }
    scenarioCheck("missed one's next run is the first $past_rule after today", $next2, $want2);
    // Run again: nothing more raised
    exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php nightly_tasks.php 2>&1');
    $raised = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_recurring_ticket_id IN ($rid, $rid2)"))[0]);
    scenarioCheck('a second run the same day raises nothing more', $raised, 2);
} finally {
    $ids = [];
    $r = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_recurring_ticket_id IN ($rid, $rid2)");
    while ($row = mysqli_fetch_row($r)) {
        $ids[] = intval($row[0]);
    }
    foreach ($ids as $id) {
        ticketDeleteForGood($id);
    }
    mysqli_query($mysqli, "DELETE FROM recurring_tickets WHERE recurring_ticket_id IN ($rid, $rid2)");
}

// 5. Calendar events: expansion over DST, interval, until, skip; RRULE
$event = ['event_start' => '2026-10-21 10:00:00', 'event_end' => '2026-10-21 11:30:00', 'event_repeat' => 'Month', 'event_repeat_interval' => 1,
    'event_repeat_until' => null, 'event_repeat_skip' => null, 'event_repeat_by_day' => '3WE'];
$got = array_map(function ($o) { return $o['start'] . '/' . substr($o['end'], 11); }, expandRecurringEvent($event, '2026-10-01 00:00:00', '2027-03-31 23:59:59'));
$want = [];
for ($i = 0; $i < 6; $i++) {
    $m = 10 + $i;
    $want[] = oracleByDay(2026 + intdiv($m - 1, 12), ($m - 1) % 12 + 1, 3, 3) . ' 10:00:00/11:30:00';
}
scenarioCheck('monthly 3WE event expands to each third Wednesday at 10:00-11:30 across the DST change', $got, $want);
$event['event_repeat_interval'] = 2;
$event['event_repeat_until'] = '2027-02-28';
$got = array_column(expandRecurringEvent($event, '2026-10-01 00:00:00', '2027-12-31 23:59:59'), 'start');
scenarioCheck('every 2 months until 2027-02-28', $got, ['2026-10-21 10:00:00', oracleByDay(2026, 12, 3, 3) . ' 10:00:00', oracleByDay(2027, 2, 3, 3) . ' 10:00:00']);
$event['event_repeat_skip'] = oracleByDay(2026, 12, 3, 3);
$got = array_column(expandRecurringEvent($event, '2026-10-01 00:00:00', '2027-12-31 23:59:59'), 'start');
scenarioCheck('a skipped date is left out', in_array(oracleByDay(2026, 12, 3, 3) . ' 10:00:00', $got, true), false);
// window starting mid-series
$event = ['event_start' => '2026-10-30 09:00:00', 'event_end' => '2026-10-30 09:30:00', 'event_repeat' => 'Year', 'event_repeat_interval' => 1,
    'event_repeat_until' => null, 'event_repeat_skip' => null, 'event_repeat_by_day' => '-1FR'];
$got = array_column(expandRecurringEvent($event, '2028-01-01 00:00:00', '2029-12-31 23:59:59'), 'start');
scenarioCheck('yearly last Friday of October, window two years on', $got, [oracleByDay(2028, 10, -1, 5) . ' 09:00:00', oracleByDay(2029, 10, -1, 5) . ' 09:00:00']);
scenarioCheck('RRULE monthly', icsRepeatToRrule('Month', 1, null, false, 'America/New_York', '3WE', '2026-10-21 10:00:00'), 'FREQ=MONTHLY;BYDAY=3WE');
scenarioCheck('RRULE yearly with month', icsRepeatToRrule('Year', 1, null, false, 'America/New_York', '-1FR', '2026-10-30 09:00:00'), 'FREQ=YEARLY;BYMONTH=10;BYDAY=-1FR');
scenarioCheck('RRULE weekly ignores the rule', icsRepeatToRrule('Week', 1, null, false, 'America/New_York', '3WE', '2026-10-21 10:00:00'), 'FREQ=WEEKLY');

// 6. The event form saves the rule, the event's .ics and invitation carry it, the calendar lists the right days
$post = ['title' => 'ZZ Scenario 3rd Wed', 'calendar' => 1, 'start_date' => '2026-10-21', 'start_time' => '10:00', 'end_date' => '2026-10-21', 'end_time' => '11:00',
    'repeat' => 'Month', 'repeat_interval' => 1, 'repeat_on' => '3WE', 'location' => '', 'details' => '', 'all_day' => 0];
$err = '';
$set = calendarEventFormSet($post, 0, $err);
if ($set === false || $set === null || $set === '') {
    scenarioCheck('calendarEventFormSet accepted the post', $err, '');
} else {
    mysqli_query($mysqli, "INSERT INTO calendar_events SET $set, event_created_by = $session_user_id");
    $eid = mysqli_insert_id($mysqli);
    try {
        $row = calendarEventRow($eid);
        scenarioCheck('event saved with its weekday rule', $row['event_repeat_by_day'] ?? null, '3WE');
        scenarioCheck('repeat text mentions the third Wednesday', strpos((string) calendarRepeatText($row), 'on the third Wednesday') !== false, true);
        $ics = icsCalendar(['name' => 'x'], [calendarEventIcsItem($row)], 'America/New_York');
        scenarioCheck('event .ics has RRULE BYDAY=3WE', (bool) preg_match('/RRULE:FREQ=MONTHLY;BYDAY=3WE/', $ics), true);
        $items = calendarEventItems([1], '2026-11-01 00:00:00', '2026-11-30 23:59:59', ['client_id' => 0]);
        $mine = array_values(array_filter($items, function ($i) use ($eid) { return ($i['extendedProps']['event_id'] ?? 0) === $eid; }));
        scenarioCheck('calendar shows it on the third Wednesday of November only', array_column($mine, 'start'), [oracleByDay(2026, 11, 3, 3) . ' 10:00:00']);
        // a weekly repeat drops the rule
        $post['repeat'] = 'Week';
        $set2 = calendarEventFormSet($post, 0, $err);
        mysqli_query($mysqli, "UPDATE calendar_events SET $set2 WHERE event_id = $eid");
        scenarioCheck('changed to weekly: the rule is cleared', calendarEventRow($eid)['event_repeat_by_day'] ?? null, null);
        // a rule that doesn't fit the start date is not saved
        $post['repeat'] = 'Month';
        $post['repeat_on'] = '2WE';
        $set3 = calendarEventFormSet($post, 0, $err);
        mysqli_query($mysqli, "UPDATE calendar_events SET $set3 WHERE event_id = $eid");
        scenarioCheck('a rule that does not fit the start date is not saved', calendarEventRow($eid)['event_repeat_by_day'] ?? null, null);
    } finally {
        mysqli_query($mysqli, "DELETE FROM calendar_event_attendees WHERE attendee_event_id = $eid");
        mysqli_query($mysqli, "DELETE FROM calendar_events WHERE event_id = $eid");
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
