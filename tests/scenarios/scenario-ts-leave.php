<?php
/*
 * Leave banks worked by hand: a yearly grant on the hire anniversary with a carry-over cap (and a late correction to last year
 * re-working the carry-over), leave earned per hour worked up to a maximum, per period with waiting days, the timesheet refusing
 * leave beyond the balance, and the history list.
 */
require __DIR__ . '/bootstrap.php';
// Temporary codes and banks first (their lists are read once per request)
mysqli_query($mysqli, "INSERT INTO mit_charge_codes SET code_name = 'ZZ Leave X', code_kind = 'leave', code_paid = 1, code_color = '#000000', code_sort = 99");
$CX = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_leave_banks SET bank_name = 'ZZ Yearly', bank_codes = '$CX', bank_method = 'yearly', bank_rate = 4800, bank_per_worked = 0, bank_max = NULL,
    bank_carryover_max = 1200, bank_year_start = 'hire', bank_waiting_days = 0, bank_allow_negative = 0, bank_sort = 90, bank_created_at = '2025-01-01 00:00:00'");
$BX = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_leave_banks SET bank_name = 'ZZ Per hour', bank_codes = '', bank_method = 'worked', bank_rate = 60, bank_per_worked = 1800, bank_max = 600,
    bank_carryover_max = NULL, bank_year_start = 'jan1', bank_waiting_days = 0, bank_allow_negative = 0, bank_sort = 91, bank_created_at = '2025-01-01 00:00:00'");
$BY = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_leave_banks SET bank_name = 'ZZ Waiting', bank_codes = '', bank_method = 'period', bank_rate = 120, bank_per_worked = 0, bank_max = NULL,
    bank_carryover_max = NULL, bank_year_start = 'jan1', bank_waiting_days = 90, bank_allow_negative = 0, bank_sort = 92, bank_created_at = '2025-01-01 00:00:00'");
$BZ = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Scenario Leave', user_email = 'zz-leave@example.invalid', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$U = mysqli_insert_id($mysqli);
// Hired Oct 20 last year: the leave year (hire anniversary) started 6 days ago when today is Oct 26
$hire = date('Y-m-d', strtotime('-1 year -6 days'));
mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $U, schedule = '480,480,480,480,480,0,0', pay_type = 'hourly', hire_date = '$hire', ca_rules = 0, keeps_timesheet = 1");
$ADMIN = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT code_id FROM mit_charge_codes WHERE code_name = 'Admin'"))[0]);
$year = date('Y-m-d', strtotime('-6 days'));
$before = date('Y-m-d', strtotime("$year -1 day"));
$h = function ($x) { return (int) round($x * 60); };
$entry = function ($date, $code, $minutes) use ($mysqli, $U) {
    mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = $U, entry_date = '$date', code_id = $code, source = 'manual', minutes = $minutes
        ON DUPLICATE KEY UPDATE minutes = $minutes");
    mitTsForget();
};
$ledger = function ($bank, $kind) use ($mysqli, $U) {
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(minutes), 0) FROM mit_leave_ledger WHERE user_id = $U AND bank_id = $bank AND kind = '$kind'"))[0]);
};
try {
    $banks = mitLeaveBanks();
    // --- yearly grant + carry-over at the hire anniversary ---
    mitLeavePost($U, $BX, $hire, 3000, 'adjust', null, 'ZZ start');
    $entry(date('Y-m-d', strtotime("$year -5 months")), $CX, 600);
    mitLeaveYearEnds();
    scenarioCheck('over the 20h carry-over: 20h taken off', $ledger($BX, 'carryover'), -$h(20));
    scenarioCheck('carry-over dated the day before the leave year', intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_leave_ledger WHERE user_id = $U AND bank_id = $BX AND kind = 'carryover' AND entry_date = '$before'"))[0]), 1);
    scenarioCheck('yearly grant 80h on the anniversary', $ledger($BX, 'grant'), $h(80));
    mitLeaveYearEnds();
    scenarioCheck('run again: nothing more', [$ledger($BX, 'carryover'), $ledger($BX, 'grant')], [-$h(20), $h(80)]);
    scenarioCheck('balance now 100h', mitLeaveBalance($U, $banks[$BX])['now'], $h(100));
    // A late correction to last year: 5 h more leave used -> 15 h over the cap
    $entry(date('Y-m-d', strtotime("$year -2 months")), $CX, 300);
    mitLeaveYearEnds();
    scenarioCheck('late correction: carry-over worked out again (-15h)', $ledger($BX, 'carryover'), -$h(15));
    scenarioCheck('balance still 100h', mitLeaveBalance($U, $banks[$BX])['now'], $h(100));
    $hist = mitLeaveHistory($U, $banks[$BX]);
    scenarioCheck('history lists the ledger rows (adjust, carry-over, grant)', count(array_filter($hist, function ($r) { return isset($r['kind']); })) >= 3, true);

    // --- the timesheet refuses leave beyond the balance ---
    $p = mitTsPeriodFor(date('Y-m-d', strtotime('-1 day')));
    $sheet = mitTsSheetSaved($U, $p);
    $day = $p['start'];
    [$ok, $msg] = mitTsSaveCells($sheet, $p, [$day => [$CX => '101']]);
    scenarioCheck('101h of a 100h bank refused', $ok, false);

    // --- per hour worked: 80 h worked in Sep 1-15 -> 80/30 h, at most 10 h in the bank ---
    $sep = ['start' => '2026-09-01', 'end' => '2026-09-15', 'label' => 'Sep 1 - 15'];
    foreach (mitTsDates($sep) as $d) {
        if (date('N', strtotime($d)) < 6) {
            $entry($d, $ADMIN, 480); // 11 weekdays -> 88 h
        }
    }
    mitLeaveAccruePeriod($U, $sep);
    scenarioCheck('per hour: 88h worked -> floor(88*60/30) = 176 min', $ledger($BY, 'accrual'), 176);
    mitLeavePost($U, $BY, '2026-08-01', 500, 'adjust');
    mitLeaveAccruePeriod($U, $sep);
    scenarioCheck('per hour: re-accrued up to the 10h max (100 min room)', $ledger($BY, 'accrual'), 100);
    // --- waiting days: 90 days from hire ---
    $first = mitTsPeriodFor(date('Y-m-d', strtotime("$hire +30 days")));
    $later = mitTsPeriodFor(date('Y-m-d', strtotime("$hire +120 days")));
    mitLeaveAccruePeriod($U, $first);
    mitLeaveAccruePeriod($U, $later);
    $ref = function ($start) use ($mysqli, $U, $BZ) {
        return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(SUM(minutes), 0) FROM mit_leave_ledger WHERE user_id = $U AND bank_id = $BZ AND kind = 'accrual' AND ref = '$start'"))[0]);
    };
    scenarioCheck('waiting days: nothing in the first month', $ref($first['start']), 0);
    scenarioCheck('waiting days: 2h once they are over', $ref($later['start']), 120);
} finally {
    mysqli_query($mysqli, "DELETE FROM mit_leave_ledger WHERE user_id = $U OR bank_id IN ($BX, $BY, $BZ)");
    mysqli_query($mysqli, "DELETE FROM mit_leave_rates WHERE bank_id IN ($BX, $BY, $BZ)");
    mysqli_query($mysqli, "DELETE FROM mit_leave_banks WHERE bank_id IN ($BX, $BY, $BZ)");
    foreach (['mit_time_entries', 'mit_timesheets', 'mit_timesheet_people'] as $table) {
        mysqli_query($mysqli, "DELETE FROM $table WHERE user_id = $U");
    }
    mysqli_query($mysqli, "DELETE FROM mit_charge_codes WHERE code_id = $CX");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $U");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
