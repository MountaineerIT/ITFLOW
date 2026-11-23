<?php
/*
 * "Resolve - no response" is ITFlow's own since 26.12 (functions/ticket.php ticketResolveNoResponse, tickets.ticket_no_response_at). Ours left
 * on it: the Customer Thermometer survey rule (functions/mit_ct.php mitCtDueTickets) - a no-response resolution is never surveyed, not even
 * once ITFlow closes it, but once reopened and resolved the normal way it is; and a duplicate merged into another ticket is not surveyed.
 * The survey boards / start date are set for the run and put back.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('ticketResolveNoResponse') || !function_exists('ticketNoResponseReady') || !ticketNoResponseReady()) {
    echo "  FAIL ITFlow's Resolve - no response (ticket_no_response_at) is not here\n";
    exit(1);
}
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ No response $stamp");
$saved = ['ct_boards' => mitSetting('ct_boards'), 'ct_start' => mitSetting('ct_start')];
try {
    $t1 = scenarioMakeTicket($client_id, $contact_id);
    mitSetSetting('ct_boards', (string) mitBoardOf($t1));
    mitSetSetting('ct_start', date('Y-m-d', strtotime('-1 day')));
    $due = function ($ticket_id) {
        return in_array((string) $ticket_id, array_map('strval', array_column(mitCtDueTickets(5000), 'ticket_id')), true);
    };

    // Control: a ticket resolved the normal way is surveyed
    $t0 = scenarioMakeTicket($client_id, $contact_id);
    ticketResolve(ticketRow($t0));
    scenarioCheck('control - resolved the normal way: surveyed', $due($t0), true);

    ticketResolveNoResponse(ticketRow($t1), 'No answer - closing.');
    scenarioCheck('ITFlow marked it no response', scenarioValue("SELECT ticket_no_response_at = ticket_resolved_at FROM tickets WHERE ticket_id = $t1"), '1');
    scenarioCheck('no survey for a no-response resolution', $due($t1), false);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_closed_at = NOW(), ticket_status = 5 WHERE ticket_id = $t1");
    scenarioCheck('nor once ITFlow closes it', $due($t1), false);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_closed_at = NULL, ticket_status = 4 WHERE ticket_id = $t1");
    ticketReopen(ticketRow($t1));
    sleep(1);
    ticketResolve(ticketRow($t1));
    scenarioCheck('reopened and resolved the normal way: surveyed', $due($t1), true);

    // A duplicate merged into another ticket (ITFlow's merge closes it) is not surveyed
    $parent = scenarioMakeTicket($client_id, $contact_id);
    $child = scenarioMakeTicket($client_id, $contact_id);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_resolved_at = NOW(), ticket_closed_at = NOW() WHERE ticket_id = $child");
    logTicketHistory($child, "Test merged this ticket into TCK-" . scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $parent") . " and closed it");
    scenarioCheck('a ticket merged into another is not surveyed', $due($child), false);
} finally {
    foreach ($saved as $name => $value) {
        $value === null ? mitDeleteSetting($name) : mitSetSetting($name, $value);
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
