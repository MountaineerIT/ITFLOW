<?php
/*
 * California non-exempt, a pay period worked by hand (Oct 1-15 2026, week starts Sunday): typed work times incl. a split shift,
 * a 13 h day (late + missing meal, double time), a 5 h day with a missed rest break, a short meal the person chose, the 7th day
 * in a row (all overtime / double), the weekly 40, a closure day filled with Holiday, ticket time outside the typed times
 * ("not clocked"); then the problems that stop signing, the answers, signing, a change by someone else (sent back + to
 * acknowledge), approval and the stored figures. Every total checked against arithmetic done here.
 */
require __DIR__ . '/bootstrap.php';

$s = mitTsSettings();
if ($s['week_start'] !== 7 || mitTsCaStart() > '2026-10-01' || !$s['approval'] || mitTsUnallocatedCode()) {
    echo "  skip - settings differ from what this scenario expects\n";
    exit(0);
}
$code = function ($name) use ($mysqli) {
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT code_id FROM mit_charge_codes WHERE code_name = '" . escapeSql($name) . "' AND code_archived_at IS NULL"))[0] ?? 0);
};
$ADMIN = $code('Admin');
$HOLIDAY = $code('Holiday');
$closure = getBusinessHolidays(true)['2026-10-12'] ?? null;
if (!$ADMIN || !$HOLIDAY || !$closure) {
    echo "  skip - Admin / Holiday code or the Oct 12 closure day missing\n";
    exit(0);
}
$admin_id = $GLOBALS['session_user_id'];
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Scenario Cali', user_email = 'zz-cali@example.invalid', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$U = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $U, schedule = '480,480,480,480,480,0,0', pay_type = 'hourly', hire_date = '2026-07-01', ca_rules = 1, keeps_timesheet = 1");
$client = scenarioClient();
mysqli_query($mysqli, "INSERT INTO tickets SET ticket_number = 990002, ticket_prefix = 'ZZ-', ticket_subject = 'ZZ scenario cali', ticket_details = '', ticket_status = 1, ticket_created_by = $U, ticket_client_id = $client, ticket_billable = 1");
$T = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'zz', ticket_reply_type = 'Public', ticket_reply_by = $U, ticket_reply_ticket_id = $T, ticket_reply_time_worked = '05:00:00', ticket_reply_created_at = '2026-10-13 12:00:00'");

$period = ['start' => '2026-10-01', 'end' => '2026-10-15', 'label' => mitTsRangeText('2026-10-01', '2026-10-15')];
$h = function ($x) { return (int) round($x * 60); };
$as = function ($who) use ($U, $admin_id) {
    $GLOBALS['session_user_id'] = $who === 'person' ? $U : $admin_id;
    $GLOBALS['session_is_admin'] = $who !== 'person';
    $GLOBALS['session_user_role'] = $who === 'person' ? 2 : 3;
    $GLOBALS['session_name'] = $who === 'person' ? 'ZZ Scenario Cali' : 'Scenario check';
};
$b = function (...$pairs) {
    $blocks = [];
    foreach ($pairs as $p) {
        $blocks[] = ['start' => $p[0], 'end' => $p[1], 'gap' => $p[2] ?? ''];
    }
    return ['blocks' => $blocks];
};

try {
    $as('person');
    $sheet = mitTsSheetSaved($U, $period);
    $times = [
        '2026-10-04' => $b(['08:00', '12:00'], ['12:30', '16:30']),
        '2026-10-05' => $b(['07:00', '13:00'], ['13:30', '20:30']),
        '2026-10-06' => $b(['08:00', '12:00'], ['15:00', '19:00', 'off']),
        '2026-10-07' => $b(['09:00', '14:00']),
        '2026-10-08' => $b(['08:00', '11:00'], ['11:20', '16:20']),
        '2026-10-09' => $b(['08:00', '12:00'], ['12:30', '16:30']),
        '2026-10-10' => $b(['08:00', '12:00'], ['12:30', '18:30']),
        '2026-10-13' => $b(['08:00', '12:00']),
        '2026-10-15' => $b(['08:00', '16:00']),
    ];
    $answers = ['2026-10-07' => ['rest_missed' => 1], '2026-10-08' => ['meal1' => 'chose']];
    [$ok, $msg] = mitTsCaSave($sheet, $period, $times, $answers, '');
    scenarioCheck("times saved ($msg)", $ok, true);
    [$ok, $msg] = mitTsSaveCells(mitTsSheet($U, $period), $period, ['2026-10-04' => [$ADMIN => '3']]);
    scenarioCheck("hours on Admin saved ($msg)", $ok, true);
    mitTsForget();

    $ca = mitTsCaDays([$U], '2026-10-01', '2026-10-15')[$U];
    scenarioCheck('Oct 5: 1st meal late', $ca['2026-10-05']['meals'][1], 'late');
    scenarioCheck('Oct 5: 2nd meal missing', $ca['2026-10-05']['meals'][2], 'missing');
    scenarioCheck('Oct 6 split shift: 3 h off counts as the meal', $ca['2026-10-06']['meals'][1], 'ok');
    scenarioCheck('Oct 7: 5 h exactly - no meal due', $ca['2026-10-07']['meals'][1], null);
    scenarioCheck('Oct 8: 20 min break is short', $ca['2026-10-08']['meals'][1], 'short');
    scenarioCheck('Oct 8: chose - no premium', $ca['2026-10-08']['meal_premium'], 0);
    scenarioCheck('Oct 5: unanswered - premium owed (one per day)', $ca['2026-10-05']['meal_premium'], 60);
    scenarioCheck('Oct 7: rest break missed - premium', $ca['2026-10-07']['rest_premium'], 60);
    scenarioCheck('Oct 15: 8 h, no meal - missing', $ca['2026-10-15']['meals'][1], 'missing');

    $days = mitTsDays([$U], '2026-10-01', '2026-10-15')[$U];
    scenarioCheck('Oct 12 closure day filled with Holiday 8h', $days['2026-10-12']['codes'][$HOLIDAY] ?? 0, 480);
    scenarioCheck('Oct 14 (no times) - no auto lost on a California day', $days['2026-10-14']['auto_lost'], 0);
    scenarioCheck('Oct 4 not on a code 5h', $days['2026-10-04']['unallocated'], 300);

    $t = mitTsTotals([$U], '2026-10-01', '2026-10-15')[$U];
    scenarioCheck('clocked 72h', $t['clocked'], $h(72));
    scenarioCheck('worked 72h', $t['worked'], $h(72));
    scenarioCheck('not clocked 1h', $t['not_clocked'], $h(1));
    scenarioCheck('not on a code 65h', $t['unallocated'], $h(65));
    scenarioCheck('paid 80h (72 + 8 holiday)', $t['paid'], $h(80));
    scenarioCheck('overtime 17h (Mon 4, Fri 5 past 40, 7th day 8)', $t['overtime'], $h(17));
    scenarioCheck('double 3h (Mon 1 past 12, 7th day 2 past 8)', $t['double'], $h(3));
    scenarioCheck('regular 60h', $t['regular'], $h(60));
    scenarioCheck('meal premium 2h (Oct 5, Oct 15)', $t['meal_premium'], 120);
    scenarioCheck('rest premium 1h', $t['rest_premium'], 60);

    // Signing: refused while meals are unanswered
    $problems = mitTsCaProblems($U, $period);
    scenarioCheck('3 unanswered meal questions stop signing (Oct 5 x2, Oct 15)', count($problems), 3);
    $sign = ['mit_ca_accurate' => 1, 'mit_ca_breaks' => 'exceptions', 'mit_ca_exceptions' => 'Oct 5 and 15', 'mit_ca_signature' => 'ZZ Scenario Cali'];
    [$ok] = mitTsCaAttest(mitTsSheet($U, $period), $period, $sign);
    scenarioCheck('attest refused with problems', $ok, false);
    [$ok] = mitTsCaSave(mitTsSheet($U, $period), $period, [], ['2026-10-05' => ['meal1' => 'chose', 'meal2' => 'not_provided'], '2026-10-15' => ['meal1' => 'chose'],
        '2026-10-07' => ['rest_missed' => 1], '2026-10-08' => ['meal1' => 'chose']], '');
    mitTsForget();
    $t = mitTsTotals([$U], '2026-10-01', '2026-10-15')[$U];
    scenarioCheck('answered: meal premium 1h (Oct 5 not provided, Oct 15 chose)', $t['meal_premium'], 60);
    [$ok] = mitTsSubmit(mitTsSheet($U, $period), $period);
    scenarioCheck('submit refused before signing', $ok, false);
    [$ok, $msg] = mitTsCaAttest(mitTsSheet($U, $period), $period, ['mit_ca_signature' => 'Someone Else'] + $sign);
    scenarioCheck('attest refused with a wrong name', $ok, false);
    [$ok, $msg] = mitTsCaAttest(mitTsSheet($U, $period), $period, $sign);
    scenarioCheck("signed ($msg)", $ok, true);
    [$ok, $msg] = mitTsSubmit(mitTsSheet($U, $period), $period);
    scenarioCheck("submitted ($msg)", $ok, true);

    // Someone else changes the times: needs a reason, goes back to the person, waits for acknowledgement
    $as('admin');
    [$ok, $msg] = mitTsCaSave(mitTsSheet($U, $period), $period, ['2026-10-09' => $b(['08:00', '12:00'], ['12:30', '17:00'])], [], '');
    scenarioCheck('change by someone else without a reason refused', $ok, false);
    [$ok, $msg] = mitTsCaSave(mitTsSheet($U, $period), $period, ['2026-10-09' => $b(['08:00', '12:00'], ['12:30', '17:00'])], [], 'left at 5');
    scenarioCheck("change with a reason saved ($msg)", $ok, true);
    scenarioCheck('signed sheet changed: back with the person', mitTsSheet($U, $period)['status'], 'returned');
    scenarioCheck('signature set aside', mitTsCaAttestation(mitTsSheet($U, $period)['sheet_id']), null);
    scenarioCheck('one change to acknowledge', count(mitTsCaUnacknowledged($U, $period)), 1);
    [$ok, $msg] = mitTsDecide(mitTsSheet($U, $period), $period, 'approve');
    scenarioCheck('approve refused without a signature', $ok, false);

    $as('person');
    [$ok, $msg] = mitTsCaAttest(mitTsSheet($U, $period), $period, $sign);
    scenarioCheck('signed again (acknowledges the change)', $ok, true);
    scenarioCheck('nothing left to acknowledge', count(mitTsCaUnacknowledged($U, $period)), 0);
    mitTsSubmit(mitTsSheet($U, $period), $period);
    $as('admin');
    mitTsForget();
    // Oct 9 now 8.5 h: Fri regular past 40 -> 5.5 OT
    [$ok, $msg] = mitTsDecide(mitTsSheet($U, $period), $period, 'approve');
    scenarioCheck("approved ($msg)", $ok, true);
    $stored = function ($source) use ($mysqli, $U) {
        return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(minutes), 0) FROM mit_time_entries WHERE user_id = $U AND source = '$source'"))[0]);
    };
    scenarioCheck('stored overtime 17.5h', $stored('overtime'), $h(17.5));
    scenarioCheck('stored double 3h', $stored('double'), $h(3));
    scenarioCheck('stored meal premium 1h', $stored('meal_premium'), 60);
    scenarioCheck('stored rest premium 1h', $stored('rest_premium'), 60);
    scenarioCheck('stored pay_rules: 15 California days', $stored('pay_rules'), 15);
    // Later the person is taken off California rules: the approved period keeps its figures
    mysqli_query($mysqli, "UPDATE mit_timesheet_people SET ca_rules = 0 WHERE user_id = $U");
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('$argv=[0,' . var_export(getcwd(), true) . ']; require ' . var_export(__DIR__ . '/bootstrap.php', true) . '; echo json_encode(mitTsTotals([' . $U . '], "2026-10-01", "2026-10-15")[' . $U . ']);'));
    $later = json_decode(substr($out, strpos($out, '{')), true);
    scenarioCheck('after leaving California rules: overtime kept 17.5h', $later['overtime'] ?? null, $h(17.5));
    scenarioCheck('... double kept 3h', $later['double'] ?? null, $h(3));
    scenarioCheck('... meal premium kept', $later['meal_premium'] ?? null, 60);
    scenarioCheck('... worked still the clocked 72.5h', $later['worked'] ?? null, $h(72.5));
} finally {
    mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = $T");
    mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = $T");
    foreach (['mit_time_entries', 'mit_timesheets', 'mit_leave_ledger', 'mit_timesheet_people', 'mit_ts_days', 'mit_ts_work_periods', 'mit_ts_changes', 'mit_ts_attestations'] as $table) {
        mysqli_query($mysqli, "DELETE FROM $table WHERE user_id = $U");
    }
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_user_id = $U");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $U");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
