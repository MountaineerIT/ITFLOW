<?php
/*
 * Dispatch's working hours are ITFlow's own since 26.12 (dispatchBusinessHours, from Admin > SLA). Ours on top: an agent who works in
 * another time zone (ITFlow's user_settings.user_config_timezone) sees them moved onto their own clock - the days moved with them, and
 * split in two where they run past midnight there (mitDispatchViewerHours, used by agent/dispatch.php). An agent on the company's clock
 * sees them as they are. Each case runs in its own process (ITFlow reads the zones once a request).
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitDispatchViewerHours') || !function_exists('timezoneUsersReady') || !timezoneUsersReady()) {
    echo "  FAIL ITFlow's Dispatch / agent time zones or our hook are not here\n";
    exit(1);
}
$tech = intval(scenarioValue("SELECT user_id FROM users WHERE user_email = 'tech@test.test'"));
$company = date_default_timezone_get();
$zone = $company === 'Asia/Tokyo' ? 'America/Los_Angeles' : 'Asia/Tokyo';
$now = new DateTime('now');
$offset = intdiv((new DateTimeZone($zone))->getOffset($now) - (new DateTimeZone($company))->getOffset($now), 60);
$saved_zone = scenarioValue("SELECT user_config_timezone FROM user_settings WHERE user_id = $tech");
$had_row = scenarioValue("SELECT COUNT(*) FROM user_settings WHERE user_id = $tech") === '1';
$saved = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_business_days, config_business_hours_start, config_business_hours_end FROM settings WHERE company_id = 1"));
$set_hours = function ($days, $start, $end) use ($mysqli) {
    mysqli_query($mysqli, "UPDATE settings SET config_business_days = '" . escapeSql($days) . "', config_business_hours_start = '$start', config_business_hours_end = '$end' WHERE company_id = 1");
};
$hours_for = function ($user_id) {
    $code = 'chdir(' . var_export(getcwd(), true) . '); $_SERVER["REMOTE_ADDR"] = "127.0.0.1"; require "config.php"; require "functions.php"; require "includes/load_global_settings.php"; '
        . '$session_user_id = $GLOBALS["session_user_id"] = ' . intval($user_id) . '; echo json_encode(mitDispatchViewerHours(dispatchBusinessHours()));';
    return json_decode((string) shell_exec('php -r ' . escapeshellarg($code)), true);
};
$minutes = function ($t) {
    return intval(substr($t, 0, 2)) * 60 + intval(substr($t, 3, 2));
};
try {
    $set_hours('1,2,3,4,5', '09:00:00', '17:30:00');
    mysqli_query($mysqli, $had_row ? "UPDATE user_settings SET user_config_timezone = NULL WHERE user_id = $tech" : "INSERT INTO user_settings SET user_id = $tech");
    scenarioCheck('control - an agent on the company\'s clock sees the hours as they are', $hours_for($tech), ['daysOfWeek' => [1, 2, 3, 4, 5], 'startTime' => '09:00', 'endTime' => '17:30']);

    mysqli_query($mysqli, "UPDATE user_settings SET user_config_timezone = '$zone' WHERE user_id = $tech");
    // 09:00 - 17:30 moved by the offset, the days with it
    $start = 9 * 60 + $offset;
    $shift = intdiv($start - ((($start % 1440) + 1440) % 1440), 1440);
    $start -= $shift * 1440;
    $days = array_map(function ($d) use ($shift) { return (($d + $shift) % 7 + 7) % 7; }, [1, 2, 3, 4, 5]);
    $got = $hours_for($tech);
    $length = 0;
    foreach ((array) $got as $piece) {
        $length += $minutes($piece['endTime']) - $minutes($piece['startTime']);
    }
    scenarioCheck("an agent on $zone: the hours keep their length (8.5 h)", $length, 510);
    scenarioCheck('... start at the moved time, on the moved days', [$got[0]['startTime'] ?? '', $got[0]['daysOfWeek'] ?? []], [sprintf('%02d:%02d', intdiv($start, 60), $start % 60), $days]);

    // Hours that cross midnight on the agent's clock come in two pieces, the second on the next days
    $cross = (24 * 60 - 60 - $offset) % 1440;                                  // starts 23:00 on their clock
    $cross = ($cross + 1440) % 1440;
    $set_hours('1,2,3,4,5', sprintf('%02d:%02d:00', intdiv($cross, 60), $cross % 60), sprintf('%02d:%02d:00', intdiv($cross + 180, 60) % 24, ($cross + 180) % 60));
    if ($cross + 180 <= 1440) {
        $got = $hours_for($tech);
        scenarioCheck('crossing their midnight: two pieces, 23:00 - 24:00 and 00:00 - 02:00', [count((array) $got), $got[0]['startTime'] ?? '', $got[0]['endTime'] ?? '', $got[1]['startTime'] ?? '', $got[1]['endTime'] ?? ''],
            [2, '23:00', '24:00', '00:00', '02:00']);
        scenarioCheck('... the second piece on the following days', $got[1]['daysOfWeek'] ?? [], array_map(function ($d) { return ($d + 1) % 7; }, $got[0]['daysOfWeek'] ?? []));
    } else {
        echo "  (the company's hours for that would cross the company's own midnight - left out)\n";
    }
} finally {
    $set_hours((string) $saved['config_business_days'], (string) $saved['config_business_hours_start'], (string) $saved['config_business_hours_end']);
    if ($had_row) {
        mysqli_query($mysqli, "UPDATE user_settings SET user_config_timezone = " . ($saved_zone === null ? 'NULL' : "'" . escapeSql($saved_zone) . "'") . " WHERE user_id = $tech");
    } else {
        mysqli_query($mysqli, "DELETE FROM user_settings WHERE user_id = $tech");
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
