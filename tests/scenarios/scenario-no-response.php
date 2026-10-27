<?php
/*
 * "Resolve - no response" and the "Ticket resolved" wording (functions/mit_no_response.php), with the Customer Thermometer survey
 * rule (mitCtDueTickets): the [Agent] placeholder writes the agent's name as it is ($session_name is kept SQL-escaped by ITFlow),
 * the message reaches the contact - and the watchers when the contact has no address -, a no-response ticket is never surveyed,
 * but once reopened and resolved the normal way it is.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitNoResponseReady') || !mitNoResponseReady()) {
    echo "  skip - M037 not run\n";
    exit(0);
}
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ No response $stamp");
$saved = mitSetting('resolved_message');
$GLOBALS['session_user_id'] = 1;
$GLOBALS['session_name'] = $session_name = escapeSql("Pat O'Neil"); // as includes/load_user_session.php keeps it
$queued = function ($ticket_id) {
    $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
    return scenarioColumn("SELECT email_recipient FROM email_queue WHERE email_subject LIKE 'Ticket resolved - [TCK-$number]%' ORDER BY email_recipient");
};
$content = function ($ticket_id) {
    $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
    return (string) scenarioValue("SELECT email_content FROM email_queue WHERE email_subject LIKE 'Ticket resolved - [TCK-$number]%' ORDER BY email_id DESC LIMIT 1");
};
try {
    echo "placeholders\n";
    $t1 = scenarioMakeTicket($client_id, $contact_id);
    $filled = mitNoResponseFill('Thanks, [Agent] - [First Name] [Ticket]', ticketRow($t1));
    scenarioCheck('[Agent] is the name as written (no SQL backslash)', $filled, "Thanks, Pat O'Neil - Zed TCK-" . scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $t1"));

    echo "no response\n";
    $row = ticketRow($t1);
    $mailed = mitNoResponseResolve($row, "Hello Zed,\n\nNo answer - closing. [Agent]");
    scenarioCheck('emailed to the contact', [$mailed, $queued($t1)], [1, ['zz-contact-' . $client_id . '@example.invalid']]);
    scenarioCheck('resolved, marked with its resolved_at', scenarioValue("SELECT nr.resolved_at = t.ticket_resolved_at FROM tickets t JOIN mit_ticket_no_response nr USING (ticket_id) WHERE t.ticket_id = $t1"), '1');
    // ITFlow 3.4.8 keeps on the note who it was emailed to - our send records it too
    if (function_exists('ticketReplyEmailReady') && ticketReplyEmailReady()) {
        $note = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_id, ticket_reply_emailed, ticket_reply_emailed_to FROM ticket_replies WHERE ticket_reply_ticket_id = $t1 AND ticket_reply_type = 'Public' ORDER BY ticket_reply_id DESC LIMIT 1"));
        scenarioCheck('the note is marked emailed, to the contact', [$note['ticket_reply_emailed'], strpos((string) $note['ticket_reply_emailed_to'], 'zz-contact-' . $client_id . '@example.invalid') !== false], ['1', true]);
        scenarioCheck('... and the queued email carries the note', intval(scenarioValue("SELECT COUNT(*) FROM email_queue WHERE email_ticket_reply_id = " . intval($note['ticket_reply_id']))), 1);
    }
    scenarioCheck('public note with the message', strpos((string) scenarioValue("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id = $t1 AND ticket_reply_type = 'Public'"), 'No answer - closing.') !== false, true);
    // No survey for it - until it is reopened and resolved the normal way
    $due = function () use ($t1) {
        return in_array((string) $t1, array_column(mitCtDueTickets(500), 'ticket_id'), true);
    };
    $ct = mitCtSettings();
    $on_ct_board = in_array(mitBoardOf($t1), array_map('intval', $ct['boards']), true) && $ct['start'] <= date('Y-m-d');
    if ($on_ct_board) {
        scenarioCheck('no survey for a no-response resolution', $due(), false);
        mysqli_query($mysqli, "UPDATE tickets SET ticket_closed_at = NOW(), ticket_status = 5 WHERE ticket_id = $t1");
        scenarioCheck('nor once ITFlow closes it', $due(), false);
        mysqli_query($mysqli, "UPDATE tickets SET ticket_closed_at = NULL, ticket_status = 4 WHERE ticket_id = $t1");
        ticketReopen(ticketRow($t1));
        sleep(1);
        ticketResolve(ticketRow($t1));
        scenarioCheck('reopened and resolved the normal way: surveyed', $due(), true);
    } else {
        echo "  (the default board is not ticked for surveys here - survey checks left out)\n";
    }

    // A contact with no address: the watchers still hear (ITFlow's ticketMailSend sends watchers without a contact)
    $t2 = scenarioMakeTicket($client_id, 0);
    mysqli_query($mysqli, "INSERT INTO ticket_watchers SET watcher_email = 'zz-watch-$stamp@example.invalid', watcher_ticket_id = $t2");
    $mailed = mitNoResponseResolve(ticketRow($t2), 'Nobody answered.');
    scenarioCheck('no contact: the watcher is emailed', $queued($t2), ["zz-watch-$stamp@example.invalid"]);

    // A duplicate merged into another ticket is closed by ITFlow's merge (ticketMergeInto: resolved_at = NOW(), closed) - it was not
    // worked on its own, so no "how did we do" survey should go out for it before the ticket it went into is resolved
    if ($on_ct_board) {
        $parent = scenarioMakeTicket($client_id, $contact_id);
        $child = scenarioMakeTicket($client_id, $contact_id);
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 5, ticket_resolved_at = COALESCE(ticket_resolved_at, NOW()), ticket_closed_at = NOW() WHERE ticket_id = $child");
        logTicketHistory($child, "Test merged this ticket into TCK-" . scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $parent") . " and closed it");
        scenarioCheck('a ticket merged into another is not surveyed', in_array((string) $child, array_column(mitCtDueTickets(500), 'ticket_id'), true), false);
    }

    echo "resolved wording\n";
    mitSetSetting('resolved_message', "Hi [First Name], [Ticket] is [Status]. From [Agent].\n\n[Note]\n\nReopen: [Ticket Link]");
    $t3 = scenarioMakeTicket($client_id, $contact_id);
    ticketResolve(ticketRow($t3));
    ticketMailResolved($t3, '<p>Fixed the printer</p>');
    $body = $content($t3);
    scenarioCheck('our wording used', strpos($body, 'Reopen: the button below') !== false, true);
    scenarioCheck('[Status] filled in', strpos($body, 'is Resolved.') !== false, true);
    scenarioCheck('the note is kept', strpos($body, 'Fixed the printer') !== false, true);
    scenarioCheck('[Agent] as written in the email', strpos($body, 'From Pat O&#039;Neil.') !== false || strpos($body, "From Pat O'Neil.") !== false, true);
    scenarioCheck('no SQL backslash in the email', strpos($body, 'O\\') === false, true);
    // No note: nothing left where [Note] was
    $t4 = scenarioMakeTicket($client_id, $contact_id);
    ticketResolve(ticketRow($t4));
    ticketMailResolved($t4, '');
    scenarioCheck('empty note: no placeholder left', strpos($content($t4), '[Note]') === false, true);
    // Set back to ITFlow's: ITFlow's own email
    mitSetSetting('resolved_message', '');
    $t5 = scenarioMakeTicket($client_id, $contact_id);
    ticketResolve(ticketRow($t5));
    ticketMailResolved($t5, '');
    scenarioCheck("empty wording: ITFlow's own email", strpos($content($t5), 'From Pat') === false && $content($t5) !== '', true);
} finally {
    $saved === null ? mitDeleteSetting('resolved_message') : mitSetSetting('resolved_message', $saved);
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%ZZ scenario ticket%'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
