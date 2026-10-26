<?php
/*
 * The calendar's sources as the page asks for them (calendar_items: mit_split_projects): a project ticket's booking is
 * under Projects only, in the project colour with the agent's colour as the bar, and still on Dispatch; the holidays source
 * lists closure days as red all-day items; saved calendar state is cleaned (7 days view's old name mapped).
 */
require __DIR__ . '/bootstrap.php';

$tech = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'tech@test.test'"))[0] ?? 0);
$client = scenarioClient();
$tag = 'ZZsrc' . date('His');
$day = date('Y-m-d', strtotime('next friday +7 days'));
$tickets = [];
$project = 0;
$holiday = 0;
try {
    mysqli_query($mysqli, "INSERT INTO projects SET project_name = '$tag project', project_prefix = 'PRJ-', project_number = 9" . date('His') . ", project_client_id = $client, project_manager = $tech");
    $project = mysqli_insert_id($mysqli);
    foreach (['service' => 0, 'project' => $project] as $what => $pid) {
        mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = " . (500000 + count($tickets)) . ", ticket_subject = '$tag $what', ticket_details = 'x', ticket_priority = 'Low',
            ticket_status = 2, ticket_created_by = $session_user_id, ticket_assigned_to = $tech, ticket_client_id = $client, ticket_project_id = $pid, ticket_url_key = 'zs" . count($tickets) . "'");
        $tickets[$what] = mysqli_insert_id($mysqli);
        ticketSetSchedule(ticketRow($tickets[$what]), "$day 10:00:00", 0, "$day 11:00:00");
    }
    $opts = ['client_id' => 0, 'user_id' => 0, 'mit_split_projects' => true];
    $ids = function ($items) {
        return array_values(array_unique(array_map(function ($i) { return intval($i['extendedProps']['ticket_id'] ?? 0); }, array_filter($items, function ($i) {
            return in_array($i['extendedProps']['kind'] ?? '', ['ticket', 'helper'], true);
        }))));
    };
    $from = "$day 00:00:00";
    $to = "$day 23:59:59";
    $t = $ids(calendarSourceItems('tickets', $from, $to, $opts));
    $p = calendarSourceItems('projects', $from, $to, $opts);
    scenarioCheck('Scheduled tickets source: the service ticket, not the project one', [in_array($tickets['service'], $t, true), in_array($tickets['project'], $t, true)], [true, false]);
    scenarioCheck('Projects source: the project ticket\'s booking', in_array($tickets['project'], $ids($p), true), true);
    $pi = array_values(array_filter($p, function ($i) use ($tickets) { return intval($i['extendedProps']['ticket_id'] ?? 0) === $tickets['project']; }))[0] ?? [];
    scenarioCheck('project booking: project colour, agent colour as the bar', [$pi['color'] ?? '', $pi['extendedProps']['tech_color'] ?? ''], [mitProjectTicketColor(), mitAgentColor($tech)]);
    $p_tech = calendarSourceItems('projects', $from, $to, ['user_id' => $tech] + $opts);
    scenarioCheck("Projects source on the tech's calendar has it too", in_array($tickets['project'], $ids($p_tech), true), true);
    $d = $ids(array_filter(mitDispatchItems($day, $day, [$tech]), function ($i) { return true; }));
    scenarioCheck('Dispatch still shows both', [in_array($tickets['service'], $d, true), in_array($tickets['project'], $d, true)], [true, true]);
    // A project booking is not in Dispatch's queues (booked or not)
    mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = NULL, ticket_schedule_end = NULL WHERE ticket_id = " . $tickets['project']);
    scenarioCheck("an unbooked project ticket stays off Dispatch's Not booked queue", in_array($tickets['project'], array_column(mitDispatchQueue('unbooked', ['board' => 'all', 'q' => $tag])['tickets'], 'id'), true), false);

    // Dispatch's board picker: a ticket on Tier 2 (a board the admin is on) shows under that board and "My boards",
    // the service ticket on the default board does not; All shows both
    $boards = mitBoards();
    $mine = array_keys(array_intersect_key(mitBoardsOfUser($session_user_id), mitBoardsVisible()));
    if ($mine) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule = NULL, ticket_schedule_end = NULL, ticket_project_id = 0 WHERE ticket_id IN (" . implode(',', $tickets) . ")");
        mitBoardMove($tickets['project'], $mine[0]);
        $in = function ($board) use ($tag) {
            return array_column(mitDispatchQueue('unbooked', ['board' => $board, 'q' => $tag])['tickets'], 'id');
        };
        $both = array_values($tickets);
        sort($both);
        $all = $in('all');
        sort($all);
        scenarioCheck('Dispatch board picker: All shows both', $all, $both);
        scenarioCheck('Dispatch board picker: My boards shows the ticket on my board only', $in('mine'), [$tickets['project']]);
        scenarioCheck('Dispatch board picker: one board', $in((string) $mine[0]), [$tickets['project']]);
        scenarioCheck('Dispatch board picker: default when unsaved is My boards for a board member', mitDispatchBoardChoice(null), 'mine');
    }

    // Holidays
    mysqli_query($mysqli, "INSERT INTO business_holidays SET holiday_date = '$day', holiday_name = '$tag holiday'");
    $holiday = mysqli_insert_id($mysqli);
    $h = array_values(array_filter(calendarSourceItems('holidays', $from, $to, $opts), function ($i) use ($tag) { return $i['title'] === "$tag holiday"; }));
    scenarioCheck('holiday: red all-day item on its day', [count($h), $h[0]['start'] ?? '', $h[0]['allDay'] ?? null, $h[0]['color'] ?? ''], [1, $day, true, '#dc3545']);
    $sources = calendarSources();
    scenarioCheck('holidays source listed, on by default', !empty($sources['holidays']['default']), true);

    // Saved state cleaning
    scenarioCheck('saved state: old 7-days name mapped, bad values dropped',
        json_encode(mitCleanCalendarState(['view' => 'mitTimeGrid7', 'agent' => '-5', 'hidden' => ['3', 'x'], 'sources' => ['holidays' => 0, 'Bad Key!' => 1], 'evil' => 1])),
        json_encode(['view' => 'timeGridMit7', 'agent' => 0, 'hidden' => ['3', '0'], 'sources' => (object) ['holidays' => false]]));
    scenarioCheck('saved state: unknown view dropped', mitCleanCalendarState(['view' => 'resourceTimeline']), []);
} finally {
    foreach ($tickets as $id) {
        ticketDeleteForGood($id);
    }
    if ($project) {
        mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = $project");
    }
    if ($holiday) {
        mysqli_query($mysqli, "DELETE FROM business_holidays WHERE holiday_id = $holiday");
    }
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
