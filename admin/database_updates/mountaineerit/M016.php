<?php

/*
 * Mountaineer IT - database update M016
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Lunch and breaks on timesheets (functions/mit_timesheets.php):
 *  - a person's own paid-break rule (Admin > Timesheets > People): break_minutes for every break_per
 *    minutes worked; both NULL = the company's rule (mit_settings ts_break_minutes / ts_break_per);
 *  - an unpaid "Lunch" charge code of the new kind "break" (not worked), unless there is one of that kind already.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

foreach (['break_minutes', 'break_per'] as $mit_column) {
    if (!mitColumnExists($mysqli, 'mit_timesheet_people', $mit_column)) {
        mysqli_query($mysqli, "ALTER TABLE `mit_timesheet_people` ADD `$mit_column` int(11) NULL DEFAULT NULL");
    }
}

if (intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_charge_codes WHERE code_kind = 'break'"))[0]) === 0) {
    $mit_sort = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(code_sort), 0) + 1 FROM mit_charge_codes"))[0]);
    mysqli_query($mysqli, "INSERT INTO mit_charge_codes SET code_name = 'Lunch', code_kind = 'break', code_paid = 0, code_color = '#adb5bd', code_sort = $mit_sort");
}
