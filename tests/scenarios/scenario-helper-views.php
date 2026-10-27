<?php
/*
 * A ticket an agent helps on (functions/mit_ticket_techs.php), on ITFlow's own views of "my work": the dashboard's My tickets and
 * schedule (functions/dashboard.php), and the agent's calendar feed (functions/calendar.php calendarUserFeedItems). Lead the admin,
 * the technician helping with the lead's booking; each view is read as the technician. Makes one ticket and removes it.
 */
require __DIR__ . '/bootstrap.php';

if (!function_exists('mitTicketTechsReady') || !mitTicketTechsReady()) {
    echo "  skip - additional techs not set up here\n";
    exit(0);
}
$tech = intval(scenarioValue("SELECT user_id FROM users WHERE user_email = 'tech@test.test'"));
[$client_id, $contact_id] = scenarioMakeClient('ZZ helper views ' . date('His'));
$day = date('Y-m-d', strtotime('+1 day'));
$ticket_id = scenarioMakeTicket($client_id, $contact_id, ['ticket_assigned_to' => 1, 'ticket_status' => 2, 'ticket_subject' => 'ZZ helper views',
    'ticket_schedule' => "$day 10:00:00"]);
mysqli_query($mysqli, "UPDATE tickets SET ticket_schedule_end = '$day 11:00:00' WHERE ticket_id = $ticket_id");
mysqli_query($mysqli, "INSERT INTO mit_ticket_techs SET ticket_id = $ticket_id, user_id = $tech, follows_lead = 1, added_by = 1");
$mine = function (array $items) use ($ticket_id) {
    return (bool) array_filter($items, function ($item) use ($ticket_id) {
        return strpos(json_encode($item), "ticket_id=$ticket_id") !== false || intval($item['ticket_id'] ?? $item['extendedProps']['ticket_id'] ?? 0) === $ticket_id;
    });
};
try {
    $GLOBALS['session_user_id'] = $tech;
    scenarioCheck("the helper's dashboard: in My tickets", $mine(dashboardMyTickets(50)['tickets']), true);
    scenarioCheck("the helper's dashboard: the booking on their schedule", $mine(dashboardSchedule(7, 200)), true);
    $feed = ['feed_user_id' => $tech, 'feed_scope' => 'mine', 'feed_busy_only' => 0, 'feed_tickets' => 1, 'feed_events' => 0];
    scenarioCheck("the helper's calendar feed: the booking is in it", $mine(calendarUserFeedItems($feed, "$day 00:00:00", "$day 23:59:59")), true);
} finally {
    $GLOBALS['session_user_id'] = 1;
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
