<?php
/*
 * "Repeats on the 2nd Tuesday" (functions/mit_recurring.php) on ITFlow's own lists, not just the calendar: the client overview's
 * Upcoming and the dashboard's schedule read the rule (mitEventByDayColumn in their SELECTs) and show the entry on that weekday,
 * not on the start's day of the month. Makes one calendar entry of its own and removes it.
 */
require __DIR__ . '/bootstrap.php';

if (!mitEventHasByDay()) {
    echo "  skip - database update M006 not run\n";
    exit(0);
}
$calendar = intval(scenarioValue("SELECT calendar_id FROM calendars WHERE calendar_archived_at IS NULL ORDER BY calendar_id LIMIT 1"));
// A day in the next 6 whose weekday rule (the nth such weekday) falls on another day of the month a month earlier
$target = $start = null;
for ($i = 1; $i <= 6 && !$target; $i++) {
    $day = new DateTime("+$i days");
    $nth = intdiv(intval($day->format('j')) - 1, 7) + 1;
    if ($nth > 4) {
        continue;
    }
    $before = new DateTime($day->format('Y-m-01'));
    $before->modify('-1 month');
    $before->modify(['first', 'second', 'third', 'fourth'][$nth - 1] . ' ' . $day->format('l') . ' of ' . $before->format('F Y'));
    if ($before->format('j') !== $day->format('j')) {
        [$target, $start] = [$day, $before];
    }
}
if (!$target) {
    echo "  skip - no fitting day this week\n";
    exit(0);
}
$rule = (intdiv(intval($target->format('j')) - 1, 7) + 1) . strtoupper(substr($target->format('D'), 0, 2));
[$client_id] = scenarioMakeClient('ZZ byday ' . date('His'));
$title = 'ZZ byday ' . bin2hex(random_bytes(3));
mysqli_query($mysqli, "INSERT INTO calendar_events SET event_title = '$title', event_start = '" . $start->format('Y-m-d') . " 09:00:00', event_end = '" . $start->format('Y-m-d') . " 10:00:00',
    event_all_day = 0, event_repeat = 'Month', event_repeat_interval = 1, event_repeat_by_day = '$rule', event_created_by = 1, event_client_id = $client_id, event_calendar_id = $calendar");
$event = mysqli_insert_id($mysqli);
try {
    $want = $target->format('Y-m-d');
    $dates = function (array $items) use ($title) {
        $out = [];
        array_walk_recursive($items, function ($v, $k) use (&$out) {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                $out[] = substr($v, 0, 10);
            }
        });
        return $out;
    };
    $mine = function (array $items) use ($title) {
        return array_values(array_filter($items, function ($item) use ($title) { return strpos(json_encode($item), $title) !== false; }));
    };
    $access = ['tickets' => true, 'docs' => true, 'financial' => true, 'sales' => true, 'credentials' => false];
    $overview = $dates($mine(clientOverviewUpcoming($client_id, $access, 14)));
    scenarioCheck("client overview Upcoming: on the $rule ($want)", in_array($want, $overview, true), true);
    $dashboard = $dates($mine(dashboardSchedule(7, 200)));
    scenarioCheck("dashboard schedule: on the $rule ($want)", in_array($want, $dashboard, true), true);
} finally {
    mysqli_query($mysqli, "DELETE FROM calendar_events WHERE event_id = $event");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
