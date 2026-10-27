<?php
/*
 * The hand-worked Aug 1-15 2026 period of scenario-ts-period-hourly.php left in place for the browser tests (71-*.spec.js):
 *   php fixture-hourly.php <install> setup     -> prints the new user id
 *   php fixture-hourly.php <install> teardown  -> removes everything it made
 * Expected (see the scenario): billable 8, Admin 51, Training 2, PTO 8, Unpaid leave 8, Lunch 1, unaccounted 10, worked 71,
 * leave 16, unpaid 18, paid 69, overtime 5, regular 64.
 */
$mode = $argv[2] ?? 'setup';
$argv = [$argv[0], $argv[1] ?? '/var/www/itflow'];
ob_start();
require __DIR__ . '/bootstrap.php';
ob_end_clean();
$U = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'zz-fixture@example.invalid'"))[0] ?? 0);
if ($U) {
    $T = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_number = 990011"))[0] ?? 0);
    mysqli_query($mysqli, "DELETE FROM mit_reply_times WHERE reply_id IN (SELECT ticket_reply_id FROM ticket_replies WHERE ticket_reply_ticket_id = $T)");
    mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = $T");
    mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = $T");
    foreach (['mit_time_entries', 'mit_timesheets', 'mit_leave_ledger', 'mit_timesheet_people', 'mit_ts_days', 'mit_ts_work_periods'] as $table) {
        mysqli_query($mysqli, "DELETE FROM $table WHERE user_id = $U");
    }
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_user_id = $U");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $U");
}
if ($mode === 'teardown') {
    echo "removed\n";
    exit(0);
}
$code = function ($name) use ($mysqli) {
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT code_id FROM mit_charge_codes WHERE code_name = '" . escapeSql($name) . "' AND code_archived_at IS NULL"))[0] ?? 0);
};
$ADMIN = $code('Admin'); $TRAIN = $code('Training'); $PTO = $code('PTO'); $UNPAID = $code('Unpaid leave'); $LUNCH = $code('Lunch');
$bank = 0;
foreach (mitLeaveBanks() as $b) {
    if (in_array($PTO, $b['codes'], true)) {
        $bank = intval($b['bank_id']);
    }
}
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Fixture Hourly', user_email = 'zz-fixture@example.invalid', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$U = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $U, schedule = '480,480,480,480,480,0,0', pay_type = 'hourly', hire_date = '2026-07-01', ca_rules = 0, keeps_timesheet = 1, employee_id = 'ZZ-71'");
$client = scenarioClient();
mysqli_query($mysqli, "INSERT INTO tickets SET ticket_number = 990011, ticket_prefix = 'ZZ-', ticket_subject = 'ZZ fixture hourly', ticket_details = '', ticket_status = 1, ticket_created_by = $U, ticket_client_id = $client, ticket_billable = 1");
$T = mysqli_insert_id($mysqli);
$reply = function ($posted, $time, $type = 'Public') use ($mysqli, $U, $T) {
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = 'zz', ticket_reply_type = '$type', ticket_reply_by = $U, ticket_reply_ticket_id = $T,
        ticket_reply_time_worked = '$time', ticket_reply_created_at = '$posted'");
    return mysqli_insert_id($mysqli);
};
$reply('2026-08-03 15:00:00', '05:00:00');
$reply('2026-08-11 11:00:00', '03:00:00', 'Internal');
$reply('2026-08-11 16:00:00', '02:07:00');
$late = $reply('2026-08-13 10:00:00', '01:00:00');
mysqli_query($mysqli, "INSERT INTO mit_reply_times SET reply_id = $late, work_start = '2026-08-12 20:00:00', work_end = '2026-08-12 21:00:00'");
mitLeavePost($U, $bank, '2026-07-01', 600, 'adjust', null, 'ZZ fixture');
// "I could not take a rest break" on a day not under California rules: a record, no premium
mysqli_query($mysqli, "INSERT INTO mit_ts_days SET user_id = $U, work_date = '2026-08-05', rest_missed = 1, updated_by = $U");
$period = ['start' => '2026-08-01', 'end' => '2026-08-15', 'label' => mitTsRangeText('2026-08-01', '2026-08-15')];
[$ok, $msg] = mitTsSaveCells(mitTsSheetSaved($U, $period), $period, [
    '2026-08-03' => [$ADMIN => '4'], '2026-08-04' => [$ADMIN => '8'], '2026-08-05' => [$ADMIN => '8'], '2026-08-06' => [$ADMIN => '8', $TRAIN => '2'],
    '2026-08-07' => [$ADMIN => '6'], '2026-08-08' => [$ADMIN => '4'], '2026-08-10' => [$PTO => '8'], '2026-08-11' => [$ADMIN => '6'],
    '2026-08-12' => [$ADMIN => '7', $LUNCH => '1'], '2026-08-13' => [$UNPAID => '8'],
]);
if (!$ok) {
    fwrite(STDERR, "not saved: $msg\n");
    exit(1);
}
echo json_encode(['user_id' => $U, 'codes' => compact('ADMIN', 'TRAIN', 'PTO', 'UNPAID', 'LUNCH'), 'bank' => $bank]) . "\n";
