<?php
/*
 * The calendar's ticket sources as the page asks for them (agent/ajax.php calendar_items passes mit_split_projects) - ours on ITFlow's
 * calendar (functions/mit_calendar.php mitCalendarTicketItems, functions/mit_projects.php): a project ticket's booking is under Projects only
 * (the tickets source leaves it out, the projects source adds it - mitProjectCalendarItems / mitProjectBookingItems), keeping ITFlow's
 * project colour; a service booking is filled with our service colour with the agent's colour as the bar (tech_color); without the split
 * (Dispatch, the dashboard) the tickets source has both, and Dispatch's board shows both.
 */
require __DIR__ . '/bootstrap.php';

$tech = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_email = 'tech@test.test'"))[0] ?? 0);
$client = scenarioClient();
$tag = 'ZZsrc' . date('His');
$day = date('Y-m-d', strtotime('next friday +7 days'));
$tickets = [];
$project = 0;
if (!function_exists('mitProjectTicketsApart') || !mitProjectTicketsApart()) {
    echo "  FAIL project tickets are not kept apart here (ITFlow's setting, on by default for us)\n";
    exit(1);
}
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
    scenarioCheck("project booking: ITFlow's project colour, not the service colour", [isset($pi['extendedProps']['project']), ($pi['color'] ?? '') !== mitServiceTicketColor()], [true, true]);
    $p_tech = calendarSourceItems('projects', $from, $to, ['user_id' => $tech] + $opts);
    scenarioCheck("Projects source on the tech's calendar has it too", in_array($tickets['project'], $ids($p_tech), true), true);
    $si = array_values(array_filter(calendarSourceItems('tickets', $from, $to, $opts), function ($i) use ($tickets) { return intval($i['extendedProps']['ticket_id'] ?? 0) === $tickets['service']; }))[0] ?? [];
    scenarioCheck('service booking: filled with the service colour, the agent\'s colour as the bar', [$si['color'] ?? '', $si['extendedProps']['tech_color'] ?? ''], [mitServiceTicketColor(), mitAgentColor($tech)]);
    $saved_color = mitSetting('service_ticket_color');
    mitSetSetting('service_ticket_color', '#123abc');
    $si = array_values(array_filter(calendarSourceItems('tickets', $from, $to, $opts), function ($i) use ($tickets) { return intval($i['extendedProps']['ticket_id'] ?? 0) === $tickets['service']; }))[0] ?? [];
    $saved_color === null ? mitDeleteSetting('service_ticket_color') : mitSetSetting('service_ticket_color', $saved_color);
    scenarioCheck('... the colour set in Admin > Settings > Tickets', $si['color'] ?? '', '#123abc');
    $all = $ids(calendarSourceItems('tickets', $from, $to, ['client_id' => 0, 'user_id' => 0]));
    scenarioCheck('without the split (Dispatch, dashboard) the tickets source has both', [in_array($tickets['service'], $all, true), in_array($tickets['project'], $all, true)], [true, true]);
    $d = $ids(dispatchItems($day, $day, [$tech]));
    scenarioCheck('Dispatch still shows both', [in_array($tickets['service'], $d, true), in_array($tickets['project'], $d, true)], [true, true]);
    // Outlook busy times on Dispatch: a one-day range (the day view) and a week ending that day both carry the busy block (ITFlow's
    // dispatchItems passes our hook its company-clock range - with bare dates the last day was cut off)
    mysqli_query($mysqli, "INSERT INTO mit_outlook_busy SET user_id = $tech, starts_at = '$day 14:00:00', ends_at = '$day 15:00:00', is_all_day = 0, subject = '$tag busy', is_private = 0, show_as = 'busy'");
    $busy_id = mysqli_insert_id($mysqli);
    $busy = function ($items) {
        return count(array_filter($items, function ($i) { return ($i['extendedProps']['kind'] ?? '') === 'outlook_busy' && preg_match('/[ T]14:00/', (string) ($i['start'] ?? '')); }));
    };
    scenarioCheck("Dispatch's day view: the agent's Outlook busy time", $busy(dispatchItems($day, $day, [$tech])), 1);
    scenarioCheck('... and a week that ends on that day', $busy(dispatchItems(date('Y-m-d', strtotime("$day -6 days")), $day, [$tech])), 1);
} finally {
    mysqli_query($mysqli, "DELETE FROM mit_outlook_busy WHERE subject LIKE '$tag%'");
    foreach ($tickets as $id) {
        ticketDeleteForGood($id);
    }
    if ($project) {
        mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = $project");
    }
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
