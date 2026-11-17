<?php
/*
 * No mileage rate (26.11.8.6 - production ran this way unnoticed after 26.11: ITFlow hides Log a trip's "In their own vehicle" switch without
 * ITFlow's rate, so every trip was a company-vehicle trip and nothing reached a timesheet). With the rate at 0: mitReimbRateMissing, the trip
 * window's note where the switch would be (not when the switch shows), and the timesheet's Mileage box warns - with the period's
 * company-vehicle trips counted - even when nothing is owed. The company-vehicle count leaves out own-vehicle, archived and other people's trips.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitReimbRateMissing') || !mitReimbOn() || !mitReimbItflowPaysBack()) {
    echo "  skip - reimbursements / ITFlow pay-back not here\n";
    exit(0);
}
$saved_rate = scenarioValue("SELECT config_mileage_rate FROM settings WHERE company_id = 1");
// ITFlow's tripRules() keeps the settings for the request - set the rate before anything reads it
mysqli_query($mysqli, "UPDATE settings SET config_mileage_rate = 0 WHERE company_id = 1");
$user_id = intval($session_user_id);
$day = date('Y-m-d');
$period = function_exists('mitTsPeriodFor') ? mitTsPeriodFor($day) : ['start' => date('Y-m-01'), 'end' => date('Y-m-t')];
$made = [];
$trip = function ($own, $miles, $user = null, $archived = false) use ($mysqli, $user_id, $day, &$made) {
    mysqli_query($mysqli, "INSERT INTO trips SET trip_date = '$day', trip_source = 'ZZ from', trip_destination = 'ZZ to', trip_miles = $miles, round_trip = 0,
        trip_purpose = 'ZZ rate missing', trip_user_id = " . intval($user ?? $user_id) . ", trip_client_id = 0, trip_own_vehicle = $own, trip_created_by = $user_id"
        . ($archived ? ', trip_archived_at = NOW()' : ''));
    return $made[] = mysqli_insert_id($mysqli);
};
try {
    [$before] = mitReimbCompanyTrips($user_id, $period['start'], $period['end']);
    $trip(0, 19);
    $trip(0, 5.5);
    $trip(1, 7);                  // own vehicle - paid back, not counted
    $trip(0, 3, null, true);      // archived
    $other = intval(scenarioValue("SELECT user_id FROM users WHERE user_type = 1 AND user_id <> $user_id ORDER BY user_id LIMIT 1"));
    if ($other) {
        $trip(0, 9, $other);      // someone else's
    }
    [$count, $miles] = mitReimbCompanyTrips($user_id, $period['start'], $period['end']);
    scenarioCheck('company-vehicle trips this period: the two of theirs', $count - $before, 2);

    echo "no mileage rate\n";
    if (mitReimbItflowRate() > 0) {
        echo "  skip - ITFlow's trip settings were read before the scenario set the rate\n";
    } else {
        scenarioCheck('the rate is missing', mitReimbRateMissing(), true);
        $note = mitReimbTripRateNote(false);
        scenarioCheck('the trip window says why there is no own-vehicle switch', strpos($note, 'data-mit-trip-rate-missing') !== false && strpos($note, 'In their own vehicle') !== false, true);
        scenarioCheck('... with a link for an admin', strpos($note, 'href="/admin/mit_timesheets.php?tab=settings"') !== false, true);
        scenarioCheck('... and nothing while the switch shows', mitReimbTripRateNote(true), '');
        $box = mitReimbTimesheetBox($user_id, $period);
        scenarioCheck('the timesheet warns, counting the trips', strpos($box, 'data-mit-ts-rate-missing') !== false && strpos($box, "$count trip") !== false, true);
    }
} finally {
    if ($made) {
        mysqli_query($mysqli, "DELETE FROM trips WHERE trip_id IN (" . implode(',', array_map('intval', $made)) . ")");
    }
    mysqli_query($mysqli, "UPDATE settings SET config_mileage_rate = " . ($saved_rate === null ? 'NULL' : "'" . escapeSql($saved_rate) . "'") . " WHERE company_id = 1");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
