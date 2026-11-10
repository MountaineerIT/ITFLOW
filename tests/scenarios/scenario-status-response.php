<?php
/*
 * Statuses that meet the SLA response target (functions/mit_status_response.php): a ticket moved INTO such a status before anyone
 * replied gets its first response - after the client is emailed the "ticket assigned" message when the status says so; tickets
 * already in the status when it was switched on are left alone; a move away and back counts once; a status change that skips
 * ITFlow's SLA sync is caught by the sweep; nobody to email = not counted; the no-email setting counts without mail; New / Resolved /
 * Closed can't carry it. Runs through ITFlow's own ticketChangeAssignee / ticketChangeStatus (the hook in syncTicketSlaClock).
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitStatusResponseReady') || !mitStatusResponseReady()) {
    echo "  skip - M059 not run\n";
    exit(0);
}
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ Status response $stamp");
$GLOBALS['session_user_id'] = 1;
$GLOBALS['session_name'] = $session_name = 'Scenario';
$saved_since = mitSetting('status_response_since');
$saved_message = mitSetting('status_response_message');
$saved_notify = scenarioValue("SELECT config_ticket_client_general_notifications FROM settings WHERE company_id = 1");
$saved_rows = scenarioColumn("SELECT CONCAT(ticket_status_id, ':', send_email) FROM mit_ticket_status_response");
mysqli_query($mysqli, "DELETE FROM mit_ticket_status_response");
mitStatusResponseStatuses(true);
mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 1 WHERE company_id = 1");
$agent = intval(scenarioValue("SELECT user_id FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL AND user_id <> 1 ORDER BY user_id LIMIT 1")) ?: 1;
$agent_name = (string) scenarioValue("SELECT user_name FROM users WHERE user_id = $agent");
$order = intval(scenarioValue("SELECT MAX(ticket_status_order) FROM ticket_statuses")) + 1;
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'ZZ Assigned $stamp', ticket_status_color = '#123456', ticket_status_active = 1, ticket_status_pauses_sla = 0, ticket_status_order = $order");
$assigned = intval(mysqli_insert_id($mysqli));
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'ZZ Triaged $stamp', ticket_status_color = '#654321', ticket_status_active = 1, ticket_status_pauses_sla = 0, ticket_status_order = " . ($order + 1));
$triaged = intval(mysqli_insert_id($mysqli));
ticketStatusesAll(true);

$number = function ($t) {
    return scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $t");
};
$mails = function ($t) use ($number) {
    return scenarioColumn("SELECT email_recipient FROM email_queue WHERE email_subject LIKE 'Ticket assigned - [TCK-" . $number($t) . "]%' OR email_subject LIKE 'Ticket update - [TCK-" . $number($t) . "]%' ORDER BY email_recipient");
};
$responded = function ($t) {
    return scenarioValue("SELECT ticket_first_response_at IS NOT NULL FROM tickets WHERE ticket_id = $t");
};
try {
    echo "which statuses\n";
    scenarioCheck('New, Resolved and Closed cannot carry it; Open and our own can', [mitStatusResponseAllowed(1), mitStatusResponseAllowed(4), mitStatusResponseAllowed(5),
        mitStatusResponseAllowed(2), mitStatusResponseAllowed($assigned)], [false, false, false, true, true]);
    scenarioCheck('the field is offered on our own status', strpos(mitStatusResponseField($assigned), 'name="mit_status_response"') !== false, true);
    scenarioCheck('... not on New', mitStatusResponseField(1), '');

    // A ticket already sitting in the status before it is switched on
    $before = scenarioMakeTicket($client_id, $contact_id, ['ticket_status' => $assigned, 'ticket_assigned_to' => $agent]);

    echo "switched on (with the email)\n";
    mitDeleteSetting('status_response_since');
    scenarioCheck('saved', mitStatusResponseSave($assigned, ['mit_status_response_present' => '1', 'mit_status_response' => '2']), 'on, emailing the client');
    scenarioCheck('mode 2', mitStatusResponseMode($assigned), 2);
    scenarioCheck('the ticket already in it is seen as it is', scenarioValue("SELECT status_id FROM mit_ticket_status_seen WHERE ticket_id = $before"), (string) $assigned);
    syncTicketSlaClock($before);
    scenarioCheck('... and not emailed or counted', [$mails($before), $responded($before)], [[], '0']);

    echo "a new ticket assigned, then moved into the status\n";
    mitSetSetting('status_response_message', "Hi [First Name],\n\n[Ticket] is with [Tech] ([Status]) for [Client].\n\nThanks,\n[Company]");
    $t = scenarioMakeTicket($client_id, $contact_id);
    applyTicketSla($t);
    ticketChangeAssignee($t, $agent);
    scenarioCheck('assigned (New -> Open) - Open is not set, nothing yet', [$mails($t), $responded($t)], [[], '0']);
    ticketChangeStatus($t, $assigned);
    scenarioCheck('moved into it: the contact is emailed', $mails($t), ['zz-contact-' . $client_id . '@example.invalid']);
    scenarioCheck('... and the first response is recorded', $responded($t), '1');
    $mail = (string) scenarioValue("SELECT email_content FROM email_queue WHERE email_subject LIKE 'Ticket assigned - [TCK-" . $number($t) . "]%' ORDER BY email_id DESC LIMIT 1");
    scenarioCheck('the message filled in ([Tech], [Status], [Client])', strpos($mail, "TCK-" . $number($t) . " is with " . escapeHtml($agent_name) . " (ZZ Assigned $stamp) for ZZ Status response $stamp") !== false, true);
    scenarioCheck('one greeting (ITFlow\'s)', substr_count($mail, 'Hi Zed'), 0);
    $note = (string) scenarioValue("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id = $t AND ticket_reply_type = 'Public' ORDER BY ticket_reply_id DESC LIMIT 1");
    scenarioCheck('kept as a public note', strpos($note, 'is with') !== false, true);
    scenarioCheck('ticket history says so', intval(scenarioValue("SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = $t AND ticket_history_description LIKE '%counts as the first response%'")), 1);
    $first = scenarioValue("SELECT ticket_first_response_at FROM tickets WHERE ticket_id = $t");

    ticketChangeStatus($t, $triaged);
    ticketChangeStatus($t, $assigned);
    scenarioCheck('away and back: no second email, first response unchanged', [count($mails($t)), scenarioValue("SELECT ticket_first_response_at FROM tickets WHERE ticket_id = $t")], [1, $first]);

    echo "a change that skips the SLA sync (API, texts, parser)\n";
    $t2 = scenarioMakeTicket($client_id, $contact_id, ['ticket_assigned_to' => $agent]);
    applyTicketSla($t2);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_status = $assigned WHERE ticket_id = $t2");
    scenarioCheck('not yet', $responded($t2), '0');
    scenarioCheck('the sweep counts it', mitStatusResponseSweep() >= 1 && $responded($t2) === '1', true);
    scenarioCheck('... emailed once', count($mails($t2)), 1);
    scenarioCheck('a second sweep does nothing more', [mitStatusResponseSweep(), count($mails($t2))], [0, 1]);

    echo "already answered\n";
    $t3 = scenarioMakeTicket($client_id, $contact_id);
    applyTicketSla($t3);
    setTicketFirstResponse($t3);
    ticketChangeStatus($t3, $assigned);
    scenarioCheck('a ticket someone already replied on gets no email', $mails($t3), []);

    echo "nobody to email\n";
    $t4 = scenarioMakeTicket($client_id, 0);
    applyTicketSla($t4);
    ticketChangeStatus($t4, $assigned);
    scenarioCheck('no contact, no watchers: not counted', [$responded($t4), intval(scenarioValue("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $t4 AND ticket_reply_type = 'Public'"))], ['0', 0]);
    scenarioCheck('... and the history says why', intval(scenarioValue("SELECT COUNT(*) FROM ticket_history WHERE ticket_history_ticket_id = $t4 AND ticket_history_description LIKE '%nobody could be emailed%'")), 1);
    $t4b = scenarioMakeTicket($client_id, 0);
    mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = 'zz-watch-$stamp@example.invalid', watcher_ticket_id = $t4b");
    ticketChangeStatus($t4b, $assigned);
    scenarioCheck('no contact but a watcher: the watcher is emailed and it counts', [$mails($t4b), $responded($t4b)], [["zz-watch-$stamp@example.invalid"], '1']);
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 0 WHERE company_id = 1");
    $t4c = scenarioMakeTicket($client_id, $contact_id);
    ticketChangeStatus($t4c, $assigned);
    scenarioCheck('client notifications off (Admin > Ticket): no email, not counted', [$mails($t4c), $responded($t4c)], [[], '0']);
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = 1 WHERE company_id = 1");

    echo "without an email\n";
    scenarioCheck('saved', mitStatusResponseSave($triaged, ['mit_status_response_present' => '1', 'mit_status_response' => '1']), 'on, without an email');
    $t5 = scenarioMakeTicket($client_id, $contact_id);
    applyTicketSla($t5);
    ticketChangeStatus($t5, $triaged);
    scenarioCheck('counted, nothing emailed', [$responded($t5), $mails($t5)], ['1', []]);
    scenarioCheck('response met against its due time', scenarioValue("SELECT ticket_response_sla_met FROM tickets WHERE ticket_id = $t5") !== '0', true);

    echo "switched off\n";
    scenarioCheck('saved', mitStatusResponseSave($triaged, ['mit_status_response_present' => '1', 'mit_status_response' => '0']), 'off');
    scenarioCheck('a form without the field leaves it alone', mitStatusResponseSave($assigned, []), '');
    $t6 = scenarioMakeTicket($client_id, $contact_id);
    ticketChangeStatus($t6, $triaged);
    scenarioCheck('off: nothing', $responded($t6), '0');
} finally {
    mysqli_query($mysqli, "DELETE FROM ticket_statuses WHERE ticket_status_id IN ($assigned, $triaged)");
    mysqli_query($mysqli, "DELETE FROM mit_ticket_status_response");
    foreach ($saved_rows as $saved_row) {
        [$id, $email] = array_map('intval', explode(':', $saved_row));
        mysqli_query($mysqli, "INSERT INTO mit_ticket_status_response SET ticket_status_id = $id, send_email = $email");
    }
    $saved_since === null ? mitDeleteSetting('status_response_since') : mitSetSetting('status_response_since', $saved_since);
    $saved_message === null ? mitDeleteSetting('status_response_message') : mitSetSetting('status_response_message', $saved_message);
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_client_general_notifications = " . intval($saved_notify) . " WHERE company_id = 1");
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%ZZ scenario ticket%'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
