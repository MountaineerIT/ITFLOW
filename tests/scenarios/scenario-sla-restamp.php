<?php
/*
 * Re-stamping SLA targets (ITFlow's applyTicketSla - saving an SLA, the SLA settings or a closure day runs it on every open
 * ticket, as does a priority change) must not send breach emails again: a ticket already breached and still past due keeps
 * alert stage 2 and "missed"; a warning keeps its stage while the deadline is unchanged; a deadline moved into the future
 * starts over; answered / resolved milestones are left as ITFlow judges them. (Before 26.11.5.3 every stage went back to 0
 * and cron/ticket_sla.php emailed each breach again a minute later.)
 */
require __DIR__ . '/bootstrap.php';
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ SLA restamp $stamp");
$sla_id = intval(scenarioValue("SELECT sla_id FROM slas WHERE sla_archived_at IS NULL AND sla_resolution_minutes > 0 ORDER BY sla_id LIMIT 1"));
if (!$sla_id) {
    echo "  skip - no SLA with a resolution target on this install\n";
    scenarioCleanup();
    exit(0);
}
$row = function ($t) {
    global $mysqli;
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_response_sla_alert_stage rs, ticket_resolution_sla_alert_stage zs, ticket_response_sla_met rm,
        ticket_resolution_sla_met zm, ticket_response_due_at rd FROM tickets WHERE ticket_id = $t"));
};
try {
    echo "a breached ticket, re-stamped\n";
    $old = scenarioMakeTicket($client_id, $contact_id, ['ticket_created_at' => date('Y-m-d H:i:s', time() - 60 * 86400)]);
    applyTicketSla($old, $sla_id);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_response_sla_alert_stage = 2, ticket_response_sla_met = 0, ticket_resolution_sla_alert_stage = 2, ticket_resolution_sla_met = 0 WHERE ticket_id = $old");
    applyTicketSla($old, $sla_id);
    $r = $row($old);
    scenarioCheck('still breached on both tracks - no second breach email', [$r['rs'], $r['zs']], ['2', '2']);
    scenarioCheck('... and still missed', [$r['rm'], $r['zm']], ['0', '0']);
    restampOpenSlaTickets();
    scenarioCheck('the same after restampOpenSlaTickets (SLA settings / closure days)', [$row($old)['rs'], $row($old)['zs']], ['2', '2']);

    echo "a warning, re-stamped with the same deadline\n";
    $new = scenarioMakeTicket($client_id, $contact_id);
    applyTicketSla($new, $sla_id);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_response_sla_alert_stage = 1 WHERE ticket_id = $new");
    applyTicketSla($new, $sla_id);
    scenarioCheck('the warning is not sent again', $row($new)['rs'], '1');

    echo "a deadline moved into the future\n";
    mysqli_query($mysqli, "UPDATE tickets SET ticket_response_sla_alert_stage = 2, ticket_response_due_at = '2000-01-01 00:00:00' WHERE ticket_id = $new");
    applyTicketSla($new, $sla_id);
    scenarioCheck('starts over (stage 0) - it is not breached any more', $row($new)['rs'], '0');

    echo "answered\n";
    setTicketFirstResponse($old);
    applyTicketSla($old, $sla_id);
    scenarioCheck('an answered response track has no alert stage', $row($old)['rs'], '0');
    scenarioCheck('... and is judged against its target (late = missed)', $row($old)['rm'], '0');
} finally {
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
