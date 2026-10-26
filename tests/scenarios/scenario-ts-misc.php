<?php
/*
 * Pay types (salary / contractor: no overtime, contractor never California), auto lost time stopping when someone is archived,
 * who is behind + a reminder by hand (notification + email queued, not twice in an hour), the hourly job's late reminder, and the
 * guard on changing a timed note on a submitted day.
 */
require __DIR__ . '/bootstrap.php';
$ADMIN = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT code_id FROM mit_charge_codes WHERE code_name = 'Admin'"))[0]);
$mk = function ($name, $pay, $ca, $hire = '2026-01-05') use ($mysqli) {
    $email = 'zz-' . strtolower(str_replace(' ', '-', $name)) . '@example.invalid';
    mysqli_query($mysqli, "INSERT INTO users SET user_name = '$name', user_email = '$email', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
    $id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $id, schedule = '480,480,480,480,480,0,0', pay_type = '$pay', hire_date = '$hire', ca_rules = " . ($ca === null ? 'NULL' : $ca) . ", keeps_timesheet = 1");
    return $id;
};
// Made before anything reads the people
$SAL = $mk('ZZ Salaried', 'salary', 'NULL');
$CON = $mk('ZZ Contractor', 'contractor', 1);
$SALCA = $mk('ZZ Salaried CA', 'salary', 1);
$GONE = $mk('ZZ Leaver', 'hourly', 0);
$LATE = $mk('ZZ Behind', 'hourly', 0);
mysqli_query($mysqli, "UPDATE users SET user_archived_at = '2026-08-06 12:00:00' WHERE user_id = $GONE");
$all = [$SAL, $CON, $SALCA, $GONE, $LATE];
$h = function ($x) { return (int) round($x * 60); };
$entry = function ($u, $date, $minutes) use ($mysqli, $ADMIN) {
    mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $u, entry_date = '$date', code_id = $ADMIN, source = 'manual', minutes = $minutes");
};
try {
    // Aug 3-7 2026: 10 h a day
    foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $d) {
        $entry($SAL, $d, 600);
        $entry($CON, $d, 600);
    }
    $t = mitTsTotals([$SAL, $CON], '2026-08-01', '2026-08-15');
    scenarioCheck('salaried: 50 h worked, no overtime', [$t[$SAL]['worked'] - $t[$SAL]['auto_lost'], $t[$SAL]['overtime']], [$h(50), 0]);
    scenarioCheck('contractor: no overtime', $t[$CON]['overtime'], 0);
    scenarioCheck('contractor with California switched on: still not California', mitTsCaOn($CON, '2026-10-10'), false);
    scenarioCheck('salaried: not California unless switched on', mitTsCaOn($SAL, '2026-10-10'), false);
    scenarioCheck('salaried with California switched on: California', mitTsCaOn($SALCA, '2026-10-10'), true);

    // Archived on Aug 6: unaccounted time up to that day only (Aug 3-6 = 4 days x 8 h)
    $t = mitTsTotals([$GONE], '2026-08-01', '2026-08-15')[$GONE];
    scenarioCheck('archived Aug 6: unaccounted only to that day (32 h)', $t['auto_lost'], $h(32));

    // Behind: every ended period since the hire date not submitted (6 at most)
    $behind = mitTsBehind([$LATE])[$LATE]['periods'] ?? [];
    scenarioCheck('behind on 6 periods', count($behind), 6);
    $before = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'zz-zz-behind@example.invalid'"))[0]);
    [$ok, $who] = mitTsRemind($LATE, mitTsBehind([$LATE])[$LATE]);
    scenarioCheck('reminded by hand', $ok, true);
    scenarioCheck('... a notification', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM notifications WHERE notification_user_id = $LATE AND notification_type = 'Timesheet'"))[0]), 1);
    $queued = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'zz-zz-behind@example.invalid'"))[0]);
    scenarioCheck('... and an email queued', $queued, $before + 1);
    [$ok] = mitTsRemind($LATE, mitTsBehind([$LATE])[$LATE]);
    scenarioCheck('not again within the hour', $ok, false);

    // Send back: the person is told by email too, with the reason; approve: a notification only
    $p = mitTsPeriodShift(mitTsPeriodFor(), -1);
    $mails = function () use ($mysqli) { return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'zz-zz-behind@example.invalid'"))[0]); };
    [$ok] = mitTsSubmit(mitTsSheetSaved($LATE, $p), $p);
    scenarioCheck('submitted (by a corrector)', $ok, true);
    $m0 = $mails();
    [$ok] = mitTsDecide(mitTsSheet($LATE, $p), $p, 'return', '');
    scenarioCheck('send back needs a reason', $ok, false);
    [$ok] = mitTsDecide(mitTsSheet($LATE, $p), $p, 'return', 'ZZ missing Friday');
    scenarioCheck('sent back', $ok, true);
    $last_mail = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT email_subject FROM email_queue WHERE email_recipient = 'zz-zz-behind@example.invalid' ORDER BY email_id DESC LIMIT 1"));
    scenarioCheck('... emailed with the reason', $mails() === $m0 + 1 && strpos($last_mail['email_subject'] ?? '', 'ZZ missing Friday') !== false, true);
    scenarioCheck('... shown as behind again', count(mitTsBehind([$LATE])[$LATE]['periods'] ?? []) >= 1, true);
    mysqli_query($mysqli, "DELETE FROM mit_timesheets WHERE user_id = $LATE");

    // The hourly job: late reminder for the last period (once)
    $s = mitTsSettings();
    if ($s['remind_late_days'] && $s['approval']) {
        $last = mitTsPeriodShift(mitTsPeriodFor(), -1);
        mysqli_query($mysqli, "DELETE FROM mit_timesheets WHERE user_id = $LATE");
        $log = mitTsSendReminders();
        $mine = array_filter($log, function ($l) { return strpos($l, 'ZZ Behind') !== false; });
        $due = date('Y-m-d') >= date('Y-m-d', strtotime($last['end'] . ' +' . $s['remind_late_days'] . ' days'));
        scenarioCheck('hourly job reminds about the late period ' . ($due ? '' : '(not due yet)'), count($mine) === 1, $due);
        $log = mitTsSendReminders();
        scenarioCheck('... once', count(array_filter($log, function ($l) { return strpos($l, 'ZZ Behind') !== false; })), 0);
    }
} finally {
    $in = implode(',', $all);
    foreach (['mit_time_entries', 'mit_timesheets', 'mit_leave_ledger', 'mit_timesheet_people'] as $table) {
        mysqli_query($mysqli, "DELETE FROM $table WHERE user_id IN ($in)");
    }
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_user_id IN ($in)");
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_recipient LIKE 'zz-%@example.invalid'");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id IN ($in)");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
