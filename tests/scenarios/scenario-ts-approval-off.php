<?php
/*
 * Timesheet approval switched off (Admin > Timesheets): the hourly job earns leave for a settled period and pays its mileage with
 * payroll once; then an own-vehicle trip logged afterwards in ITFlow's Trips on a day of that settled period - is it locked, or
 * paid by a later run? (Puts ts_approval back as it was.)
 */
$GLOBALS['zz_root'] = $argv[1] ?? '/var/www/itflow';
// Switch approval off before anything reads the settings
$was = trim(shell_exec("mysql -N -B " . escapeshellarg(basename(preg_replace('/^.*itflow_?/', 'itflow_', $GLOBALS['zz_root']))) . " -e \"SELECT setting_value FROM mit_settings WHERE setting_name = 'ts_approval'\" 2>/dev/null") ?? '');
require __DIR__ . '/bootstrap.php';
$was = mysqli_fetch_row(mysqli_query($mysqli, "SELECT setting_value FROM mit_settings WHERE setting_name = 'ts_approval'"))[0] ?? null;
mitSetSetting('ts_approval', '0');
$restore = function () use ($mysqli, $was) {
    if ($was !== null) {
        mysqli_query($mysqli, "UPDATE mit_settings SET setting_value = '" . escapeSql($was) . "' WHERE setting_name = 'ts_approval'");
    }
};
try {
    if (mitTsSettings()['approval'] || !mitReimbViaPayroll()) {
        echo "  skip - approval could not be switched off here (settings read already) or pay back is not payroll\n";
        exit(0);
    }
    mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ No Approval', user_email = 'zz-noappr@example.invalid', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
    $U = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $U, schedule = '0,0,0,0,0,0,0', pay_type = 'hourly', hire_date = '2026-01-01', ca_rules = 0, keeps_timesheet = 1");
    $client = scenarioClient();
    $last = mitTsPeriodShift(mitTsPeriodFor(), -1);
    $wait = mitTsSettings()['autolock_days'] ?: 7;
    if (date('Y-m-d') <= date('Y-m-d', strtotime($last['end'] . " +$wait days"))) {
        echo "  skip - the last period has not settled yet\n";
        exit(0);
    }
    $trip = function ($date, $miles) use ($mysqli, $U, $client) {
        mysqli_query($mysqli, "INSERT INTO trips SET trip_date = '$date', trip_purpose = 'ZZ', trip_source = 'A', trip_destination = 'B', trip_miles = $miles, round_trip = 0,
            trip_user_id = $U, trip_client_id = $client, trip_own_vehicle = 1");
        return mysqli_insert_id($mysqli);
    };
    $t1 = $trip($last['start'], 20);
    mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $U, entry_date = '{$last['start']}', code_id = 1, source = 'manual', minutes = 60");
    $log = mitReimbRecordEnded();
    $paid = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM trips t JOIN expenses e ON e.expense_id = t.trip_expense_id WHERE t.trip_id = $t1 AND e.expense_reimbursement_id > 0"))[0]);
    scenarioCheck('settled period: its trip paid with payroll by the hourly job', $paid, 1);
    $log = mitLeaveAccrueEnded();
    scenarioCheck('settled period: leave earned without approval', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_leave_ledger WHERE user_id = $U AND kind = 'accrual' AND ref = '{$last['start']}'"))[0]) > 0, true);

    // A trip logged afterwards in ITFlow's Trips on a day of that settled (closed) period
    $locked = mitReimbTripWhyLocked(0, ['date' => $last['start'], 'driver' => $U, 'own_vehicle' => 1]);
    $t2 = $trip(date('Y-m-d', strtotime($last['start'] . ' +1 day')), 30);
    mitReimbRecordEnded();
    $paid2 = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM trips t JOIN expenses e ON e.expense_id = t.trip_expense_id WHERE t.trip_id = $t2 AND e.expense_reimbursement_id > 0"))[0]);
    $later = mitReimbTotals([$U], mitTsPeriodFor()['start'], mitTsPeriodFor()['end'])[$U]['total'];
    echo "  info: ITFlow Trips lock on that day: " . json_encode($locked) . "; paid by a later run: $paid2; in the current period's payroll: $later\n";
    scenarioCheck('a late trip in a settled period is either refused in Trips or paid later', $locked !== '' || $paid2 === 1 || $later > 0, true);
} finally {
    if (!empty($U)) {
        $GLOBALS['mit_reimb_undoing'] = true;
        mysqli_query($mysqli, "DELETE FROM expenses WHERE expense_id IN (SELECT reimbursement_expense_id FROM reimbursements WHERE reimbursement_user_id = $U)");
        mysqli_query($mysqli, "DELETE FROM reimbursements WHERE reimbursement_user_id = $U");
        mysqli_query($mysqli, "DELETE FROM expenses WHERE expense_paid_by = $U");
        mysqli_query($mysqli, "DELETE FROM trips WHERE trip_user_id = $U");
        foreach (['mit_time_entries', 'mit_timesheets', 'mit_leave_ledger', 'mit_timesheet_people'] as $table) {
            mysqli_query($mysqli, "DELETE FROM $table WHERE user_id = $U");
        }
        mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name LIKE 'ts_payroll_closed_{$U}_%'");
        mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $U");
    }
    $restore();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
