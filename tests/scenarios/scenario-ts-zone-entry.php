<?php
/*
 * Time zones on one ticket entry (26.12.1, from the functional pass): a note's start and end are read with ONE zone - the one in force
 * when its work started - so an entry that spans the moment someone changes zone keeps its end after its start; and the timesheet's
 * quick fix stores an older entry with the zone it is listed with, not the person's zone now (it used to move to another day).
 */
require __DIR__ . '/bootstrap.php';
if (!mitTsZoneChangesReady() || !function_exists('mitReplyPersonClock')) {
    echo "  skip - no time zone history (M024)\n";
    exit(0);
}
$company = date_default_timezone_get();
if ($company === 'Asia/Tokyo') {
    echo "  skip - the company is on Tokyo time already\n";
    exit(0);
}
$tokyo = function ($company_time) use ($company) {
    return (new DateTime($company_time, new DateTimeZone($company)))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s');
};
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Zone Entry', user_email = 'zz-zone-entry@example.invalid', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$U = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $U, pay_type = 'hourly', ca_rules = 0, keeps_timesheet = 1, time_zone = 'Asia/Tokyo'");
try {
    // On the company's clock until Aug 20 18:00 (company time), then in Tokyo
    mysqli_query($mysqli, "INSERT INTO mit_ts_zone_changes SET user_id = $U, time_zone = NULL, effective_at = '1970-01-01 00:00:00', changed_by = 0, changed_by_name = 'zz'");
    mysqli_query($mysqli, "INSERT INTO mit_ts_zone_changes SET user_id = $U, time_zone = 'Asia/Tokyo', effective_at = '2026-08-20 18:00:00', changed_by = 0, changed_by_name = 'zz'");

    echo "an entry across the change\n";
    $start = mitReplyPersonClock($U, '2026-08-20 17:00:00');
    $end = mitReplyPersonClock($U, '2026-08-20 18:30:00', '2026-08-20 17:00:00');
    scenarioCheck('both ends on the zone it started in', [$start, $end], ['2026-08-20 17:00:00', '2026-08-20 18:30:00']);
    scenarioCheck('... so it ends after it starts', strtotime($end) > strtotime($start), true);
    scenarioCheck('an entry wholly after the change is on Tokyo time', mitReplyPersonClock($U, '2026-08-21 18:30:00', '2026-08-21 17:00:00'), $tokyo('2026-08-21 18:30:00'));

    echo "the quick fix stores an entry with the zone it is listed with\n";
    scenarioCheck('an entry from before the change: typed times are company times', mitReplyCompanyClock($U, '2026-08-18 09:00:00', '2026-08-18 09:00:00'), '2026-08-18 09:00:00');
    $after = '2026-08-21 17:00:00';
    scenarioCheck('an entry from after it: typed times are Tokyo times', mitReplyCompanyClock($U, $tokyo($after), $after), $after);
    scenarioCheck('a new entry (nothing stored yet) takes their zone now', mitReplyCompanyClock($U, $tokyo($after)), $after);
} finally {
    mysqli_query($mysqli, "DELETE FROM mit_ts_zone_changes WHERE user_id = $U");
    mysqli_query($mysqli, "DELETE FROM mit_timesheet_people WHERE user_id = $U");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $U");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
