<?php
/*
 * Additional agents on a ticket are ITFlow's own since 26.12 (functions/ticket_helpers.php, ticket_helpers - built from ours). Ours on top
 * (functions/mit_ticket_techs.php):
 * - a helper must be able to WORK the ticket: Support edit and the client (the guard in ticketHelperSave) - the Accountant (Support view)
 *   is refused, a technician is taken (the control);
 * - a helper who was on the ticket in our old table (mit_ticket_techs) keeps the invite UID their calendar already has
 *   ("ticket-<id>-tech-<user>@", ticketHelperUid -> mitTicketHelperUid), a new one gets ITFlow's "-helper-";
 * - a ticket moved to another client takes off the helpers who can't see it (mitTicketClientMoved -> ticketHelperRemove), others stay;
 * - helpers are offered (not ticked) in an email reply's To line (ticketReplyRecipients -> mitReplyRecipientsHelpers).
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('ticketHelperSave') || !ticketHelpersReady()) {
    echo "  FAIL ITFlow's helpers (ticket_helpers) are not here\n";
    exit(1);
}
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ Helpers $stamp");
[$other_client, $other_contact] = scenarioMakeClient("ZZ Helpers moved $stamp");
$GLOBALS['session_user_id'] = 1;
$GLOBALS['session_name'] = 'Test Admin';
$GLOBALS['config_smtp_provider'] = $GLOBALS['config_smtp_provider'] ?: 'microsoft_oauth';
$id_of = function ($email) {
    return intval(scenarioValue("SELECT user_id FROM users WHERE user_email = '$email'"));
};
$tech = $id_of('tech@test.test');
$lim = $id_of('limited@test.test'); // a technician allowed only some clients (Zack Limited)
$acct = $id_of('acct@test.test');
$old_table = (bool) scenarioValue("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mit_ticket_techs'");
$cals = function ($ticket_id, $user_id) {
    $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
    $to = scenarioValue("SELECT user_email FROM users WHERE user_id = $user_id");
    return implode("\n", scenarioColumn("SELECT email_cal_str FROM email_queue WHERE email_recipient = '" . escapeSql($to) . "' AND email_subject LIKE '%[TCK-$number]%'"));
};
try {
    $t = scenarioMakeTicket($client_id, $contact_id, ['ticket_assigned_to' => 1, 'ticket_status' => 2]);
    mysqli_query($mysqli, "INSERT INTO user_client_permissions SET user_id = $lim, client_id = $client_id, permission_type = 'allow'");

    echo "who can help\n";
    if ($acct && mitAgentSupportLevel($acct) < 2) {
        [$ok, $message] = ticketHelperSave(ticketRow($t), $acct, 'lead');
        scenarioCheck('an agent whose role can only read tickets is refused', [$ok, strpos($message, "can't work this client's tickets") !== false], [false, true]);
        scenarioCheck('... and is not on the ticket', isset(ticketHelpers($t)[$acct]), false);
    } else {
        scenarioCheck('the Accountant (acct@test.test, Support view) is on the test install', false, true);
    }
    [$ok] = ticketHelperSave(ticketRow($t), $tech, 'lead');
    scenarioCheck('control - a technician is added', [$ok, isset(ticketHelpers($t)[$tech])], [true, true]);
    [$ok] = ticketHelperSave(ticketRow($t), $lim, 'lead');
    scenarioCheck('control - a second technician is added', $ok, true);

    echo "invite UIDs\n";
    if ($old_table) {
        // The technician was helping before 26.12 (our old table still has them); lim was added after
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_ticket_techs SET ticket_id = $t, user_id = $tech, follows_lead = 1, added_by = 1");
        $day = date('Y-m-d', strtotime('+3 days'));
        ticketSetSchedule(ticketRow($t), "$day 10:00:00", 0, "$day 11:00:00");
        scenarioCheck('a helper from before 26.12 keeps "ticket-<id>-tech-<user>@"', strpos($cals($t, $tech), "UID:ticket-$t-tech-$tech@") !== false, true);
        scenarioCheck("a helper added since gets ITFlow's \"-helper-\"", [strpos($cals($t, $lim), "ticket-$t-helper-$lim@") !== false, strpos($cals($t, $lim), '-tech-') === false], [true, true]);
        ticketCancelSchedule(ticketRow($t));
        scenarioCheck('... and the cancellation finds the same UID', substr_count($cals($t, $tech), "ticket-$t-tech-$tech@") >= 2, true);
    } else {
        echo "  (no mit_ticket_techs table - nothing kept from before 26.12)\n";
    }

    echo "the email reply's To line\n";
    $to = ticketReplyRecipients($t);
    $tech_email = 'tech@test.test';
    scenarioCheck('a helper is offered', isset($to[$tech_email]) ? [$to[$tech_email]['group'], $to[$tech_email]['default'], strpos($to[$tech_email]['name'], '(helping)') !== false] : null, ['agent', false, true]);

    echo "moved to another client\n";
    scenarioCheck('the limited technician can\'t see the other client', mitAgentCanSeeClient($lim, $other_client), false);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_client_id = $other_client, ticket_contact_id = $other_contact WHERE ticket_id = $t");
    mitTicketClientMoved($t, $other_client);
    $helpers = ticketHelpers($t);
    scenarioCheck('the helper who can\'t see the new client is taken off', isset($helpers[$lim]), false);
    scenarioCheck('control - the one who can stays', isset($helpers[$tech]), true);
} finally {
    mysqli_query($mysqli, "DELETE FROM user_client_permissions WHERE client_id IN ($client_id, $other_client)");
    $tickets = implode(',', array_map('intval', $GLOBALS['scenario_tickets'] ?? [])) ?: '0';
    foreach (scenarioColumn("SELECT ticket_number FROM tickets WHERE ticket_id IN ($tickets)") as $n) {
        mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%[TCK-$n]%'");
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
