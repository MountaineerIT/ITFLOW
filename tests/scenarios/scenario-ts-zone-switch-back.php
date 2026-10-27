<?php
/*
 * Time zones: someone working from Paris switches back to the company's zone in the evening (company time). Ticket time they log
 * later that evening is dated where they are now - the same company day - not the next day (no timesheet of theirs is submitted,
 * so nothing needs protecting). Also: a reply logged while in Paris late in the evening (company time) is dated the Paris day.
 */
require __DIR__ . '/bootstrap.php';
if (!mitTsZoneChangesReady()) {
    echo "  skip - no time zone history (M024)\n";
    exit(0);
}
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Traveller', user_email = 'zz-traveller@example.invalid', user_password = 'x', user_type = 1, user_status = 1, user_role_id = 2");
$U = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $U, pay_type = 'hourly', ca_rules = 0, keeps_timesheet = 1, time_zone = NULL");
$company = date_default_timezone_get();
try {
    // In Paris from 1970, back in the company's zone from Aug 15 19:00 company time (Aug 16 01:00 in Paris, if the company is New York)
    mysqli_query($mysqli, "INSERT INTO mit_ts_zone_changes SET user_id = $U, time_zone = 'Europe/Paris', effective_at = '1970-01-01 00:00:00', changed_by = 0, changed_by_name = 'zz'");
    mysqli_query($mysqli, "INSERT INTO mit_ts_zone_changes SET user_id = $U, time_zone = NULL, effective_at = '2026-08-15 19:00:00', changed_by = 0, changed_by_name = 'zz'");
    $paris_offset = (new DateTime('2026-08-15 19:00:00', new DateTimeZone('Europe/Paris')))->getOffset() - (new DateTime('2026-08-15 19:00:00', new DateTimeZone($company)))->getOffset();
    if ($paris_offset < 5 * 3600) {
        echo "  skip - the company's zone ($company) is not far enough behind Paris for this story\n";
        exit(0);
    }
    scenarioCheck('in Paris: 2026-08-13 20:00 company time is Aug 14 there', mitTsLocalDate($U, '2026-08-13 20:00:00'), '2026-08-14');
    scenarioCheck('back home: 2026-08-15 21:00 company time stays on Aug 15 (the 16th is the next pay period)', mitTsLocalDate($U, '2026-08-15 21:00:00'), '2026-08-15');
} finally {
    mysqli_query($mysqli, "DELETE FROM mit_ts_zone_changes WHERE user_id = $U");
    mysqli_query($mysqli, "DELETE FROM mit_timesheet_people WHERE user_id = $U");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $U");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
