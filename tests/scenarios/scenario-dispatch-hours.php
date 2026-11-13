<?php
/*
 * Dispatch's working hours follow Admin > SLAs (ITFlow 26.11.8 says: no business days picked = the clocks run around the clock):
 * mitDispatchBusinessHours shades the whole week then, while a tech's Workload capacity stays an 8-hour weekday.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitDispatchBusinessHours')) {
    echo "  skip - no Dispatch\n";
    exit(0);
}
$saved = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_business_days, config_business_hours_start, config_business_hours_end FROM settings WHERE company_id = 1"));
$set = function ($days, $start, $end) use ($mysqli) {
    mysqli_query($mysqli, "UPDATE settings SET config_business_days = '" . escapeSql($days) . "', config_business_hours_start = '$start', config_business_hours_end = '$end' WHERE company_id = 1");
    getSlaSettings(true);
};
try {
    echo "business days set\n";
    $set('1,2,3,4,6', '09:00:00', '17:30:00');
    scenarioCheck('the picked days and hours', mitDispatchBusinessHours(), ['daysOfWeek' => [1, 2, 3, 4, 6], 'startTime' => '09:00', 'endTime' => '17:30']);
    scenarioCheck('capacity from them', mitDispatchWorkingDay(), [510, [1, 2, 3, 4, 6]]);

    echo "no business days - around the clock\n";
    $set('', '09:00:00', '17:30:00');
    scenarioCheck('around the clock', mitDispatchAroundTheClock(), true);
    scenarioCheck('every day, all day on the board', mitDispatchBusinessHours(), ['daysOfWeek' => [0, 1, 2, 3, 4, 5, 6], 'startTime' => '00:00', 'endTime' => '24:00']);
    scenarioCheck('a person still has an 8-hour weekday', mitDispatchWorkingDay(), [480, [1, 2, 3, 4, 5]]);

    echo "hours that make no day\n";
    $set('1,2,3,4,5', '17:00:00', '09:00:00');
    scenarioCheck('start after end = around the clock (as ITFlow)', mitDispatchAroundTheClock(), true);
} finally {
    $set((string) $saved['config_business_days'], (string) $saved['config_business_hours_start'], (string) $saved['config_business_hours_end']);
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
