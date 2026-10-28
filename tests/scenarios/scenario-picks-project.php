<?php
/*
 * Details box pickers (functions/mit_ticket_picks.php) on a project ticket (functions/mit_projects.php): picking an SLA there
 * must not claim it was set while the project keeps the ticket without one - nor may Dispatch's Schedule dialog. Category and board picks still work on it.
 */
require __DIR__ . '/bootstrap.php';
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ Picks $stamp");
mysqli_query($mysqli, "INSERT INTO projects SET project_prefix = 'PRJ-', project_number = 77$stamp, project_name = 'ZZ picks project', project_client_id = $client_id");
$project = mysqli_insert_id($mysqli);
$GLOBALS['session_user_id'] = 1;
$GLOBALS['session_name'] = 'Test Admin';
$_SESSION['user_id'] = 1;
try {
    $t = scenarioMakeTicket($client_id, $contact_id, ['ticket_project_id' => $project]);
    applyTicketSla($t);
    if (!mitProjectTicketsApart()) {
        echo "  skip - project tickets not kept apart\n";
        exit(0);
    }
    $sla = intval(scenarioValue("SELECT sla_id FROM slas WHERE sla_archived_at IS NULL LIMIT 1"));
    // lookupUserPermission / hasClientAccess read the session: an admin
    [$code, $out] = mitTicketPickSet('mit_sla', (string) $sla, [$t]);
    $now = intval(scenarioValue("SELECT ticket_sla_id FROM tickets WHERE ticket_id = $t"));
    $history = (string) scenarioValue("SELECT ticket_history_description FROM ticket_history WHERE ticket_history_ticket_id = $t ORDER BY ticket_history_id DESC LIMIT 1");
    echo "  (code $code, message: " . ($out['message'] ?? $out['error'] ?? '') . ", ticket_sla_id now $now, history: $history)\n";
    scenarioCheck('SLA picked on a project ticket: what it says matches what it did', $now === $sla || ($code !== 200) || strpos($history, 'changed SLA') === false, true);

    // Dispatch's Schedule dialog (mitDispatchSaveDetails) pinning an SLA: refused the same way, nothing written
    $history_count = intval(scenarioValue("SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = $t"));
    [$ok, $message] = mitDispatchSaveDetails($t, ['detail_client_id' => $client_id, 'detail_sla' => (string) $sla]);
    scenarioCheck('Dispatch: an SLA on a project ticket is refused', [$ok, (bool) preg_match('/project/i', $message)], [false, true]);
    scenarioCheck('Dispatch: nothing written', [intval(scenarioValue("SELECT ticket_sla_id FROM tickets WHERE ticket_id = $t")), intval(scenarioValue("SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = $t"))], [0, $history_count]);
} finally {
    scenarioCleanup();
    mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = $project");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
