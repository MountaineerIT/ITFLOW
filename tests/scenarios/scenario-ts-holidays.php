<?php
/*
 * Closure days changed in ITFlow's own Admin > SLA (not our Holidays tab): the hourly timesheet job (mitTsHolidaySweep) fills a new
 * one in on open timesheets and takes a deleted one off again.
 */
require __DIR__ . '/bootstrap.php';
if (!mitTsHolidayCodes()) {
    echo "  skip - no charge code fills closure days\n";
    exit(0);
}
// A past weekday of the current period with no holiday yet
$period = mitTsPeriodFor();
$date = '';
for ($d = $period['start']; $d < date('Y-m-d'); $d = date('Y-m-d', strtotime("$d +1 day"))) {
    if (date('N', strtotime($d)) < 6 && !isset(getBusinessHolidays(true)[$d])) {
        $date = $d;
        break;
    }
}
if ($date === '') {
    echo "  skip - no past weekday in this period yet\n";
    exit(0);
}
$codes = implode(',', array_keys(mitTsHolidayCodes()));
$count = function () use ($mysqli, $date, $codes) {
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_time_entries WHERE entry_date = '$date' AND code_id IN ($codes) AND note = 'ZZ Scenario Day'"))[0]);
};
mitTsHolidaySweep(); // remember the list as it is
try {
    mysqli_query($mysqli, "INSERT INTO business_holidays SET holiday_date = '$date', holiday_name = 'ZZ Scenario Day'");
    mitTsHolidaySweep();
    scenarioCheck('added in Admin > SLA: filled in on open timesheets', $count() > 0, true);
} finally {
    mysqli_query($mysqli, "DELETE FROM business_holidays WHERE holiday_date = '$date' AND holiday_name = 'ZZ Scenario Day'");
    mitTsHolidaySweep();
}
scenarioCheck('deleted in Admin > SLA: taken off again', $count(), 0);
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
