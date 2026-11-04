<?php
/*
 * The calendar / Dispatch in a person's own time zone (functions/mit_zones.php, 26.11.2.5): times out are moved onto the viewer's
 * clock, times in back onto the company's, working hours that cross midnight split, events' day + time fields, and the tech's
 * booking email in their zone. Uses Asia/Tokyo against the company's zone (the test install's is America/New_York).
 */
require __DIR__ . '/bootstrap.php';
$company = mitCompanyZone();
$zone = $company === 'Asia/Tokyo' ? 'America/Los_Angeles' : 'Asia/Tokyo';
$at = function ($value, $from, $to) {
    return (new DateTime($value, new DateTimeZone($from)))->setTimezone(new DateTimeZone($to))->format('Y-m-d H:i:s');
};

$stored = '2026-11-10 13:00:00';
$seen = $at($stored, $company, $zone);
scenarioCheck('a stored time shown on the viewer\'s clock', mitToView($stored, $zone), $seen);
scenarioCheck('and back again', mitFromView($seen, $zone), $stored);
scenarioCheck('the T form keeps its shape', mitToView('2026-11-10T13:00', $zone), str_replace(' ', 'T', substr($seen, 0, 16)));
scenarioCheck('a date on its own is not moved', mitToView('2026-11-10', $zone), '2026-11-10');

$items = mitZoneItems([
    ['start' => $stored, 'end' => '2026-11-10 14:00:00'],
    ['start' => '2026-11-10', 'allDay' => true],
], $zone);
scenarioCheck('calendar items moved', $items[0]['start'], $seen);
scenarioCheck('whole-day items stay on their date', $items[1]['start'], '2026-11-10');

$post = mitZoneMovePost(['start' => $seen, 'end' => $at('2026-11-10 14:00:00', $company, $zone)], $zone);
scenarioCheck('a dropped time stored on the company\'s clock', $post['start'], $stored);
$post = mitZoneMovePost(['start' => '2026-11-10', 'all_day' => 1], $zone);
scenarioCheck('a whole-day drop keeps its date', $post['start'], '2026-11-10');

$event = mitZoneEventPost(['start_date' => substr($seen, 0, 10), 'start_time' => substr($seen, 11, 5), 'end_date' => substr($seen, 0, 10), 'end_time' => substr($at('2026-11-10 14:00:00', $company, $zone), 11, 5)], $zone);
scenarioCheck('an event typed on the viewer\'s clock - start', $event['start_date'] . ' ' . $event['start_time'], '2026-11-10 13:00');
scenarioCheck('an event typed on the viewer\'s clock - end', $event['end_date'] . ' ' . $event['end_time'], '2026-11-10 14:00');

// Working hours: 8:00 - 17:00 Mon - Fri on the company's clock, moved by the offset now
$offset = mitZoneOffsetMinutes($zone);
$hours = mitZoneBusinessHours(['daysOfWeek' => [1, 2, 3, 4, 5], 'startTime' => '08:00', 'endTime' => '17:00'], $zone);
$covered = 0;
foreach ($hours as $piece) {
    [$h1, $m1] = array_map('intval', explode(':', $piece['startTime']));
    [$h2, $m2] = array_map('intval', explode(':', $piece['endTime']));
    $covered += ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
}
scenarioCheck('working hours keep their length (9 h)', $covered, 540);
$start_minutes = ((8 * 60 + $offset) % 1440 + 1440) % 1440;
scenarioCheck('and start at the moved time', $hours[0]['startTime'], sprintf('%02d:%02d', intdiv($start_minutes, 60), $start_minutes % 60));

// The tech's booking email: their own clock with the company's after it
$who = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_archived_at IS NULL AND user_type = 1 ORDER BY user_id LIMIT 1"))[0] ?? 0);
if ($who && mitTsZoneColumn()) {
    $before = mitTsPeople()[$who]['time_zone'] ?? null;
    mysqli_query($mysqli, "INSERT INTO mit_timesheet_people SET user_id = $who, time_zone = '$zone' ON DUPLICATE KEY UPDATE time_zone = '$zone'");
    try {
        // mitTsPeople() keeps what it read - check through a fresh process
        $text = mitScheduleWhenText($stored, '2026-11-10 14:00:00');
        $out = shell_exec('php -r ' . escapeshellarg('chdir(' . var_export(getcwd(), true) . '); require "config.php"; require "functions.php"; require "includes/load_global_settings.php"; '
            . '$t = mitScheduleWhenText(' . var_export($stored, true) . ', "2026-11-10 14:00:00"); echo mitZoneWhenFor($t, ' . $who . ');'));
        scenarioCheck('the tech\'s email names their zone', strpos((string) $out, mitZoneAbbr($zone)) !== false, true);
        scenarioCheck('and the company\'s time after it', strpos((string) $out, $text) !== false, true);
    } finally {
        $restore = $before === null ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $before) . "'";
        mysqli_query($mysqli, "UPDATE mit_timesheet_people SET time_zone = $restore WHERE user_id = $who");
    }
}
scenarioCheck('a when-text it never made is left alone', mitZoneWhenFor('Someday', $who), 'Someday');
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
