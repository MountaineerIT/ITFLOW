<?php
/*
 * Additional agents (functions/mit_ticket_techs.php) against ITFlow's own scheduling: a helper who follows the lead is moved and
 * cancelled with the lead's booking (their own invite each time), the customer's
 * booking email is about the lead's booking only, the "copy of the client's reply" follows Admin > Notifications (helper_copy),
 * and a helper becoming the lead within minutes of their booking email still gets the ticket's own invite.
 */
require __DIR__ . '/bootstrap.php';
if (!mitTicketTechsReady()) {
    echo "  skip - M008 not run\n";
    exit(0);
}
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ Helpers $stamp");
$GLOBALS['session_user_id'] = 1;
$GLOBALS['session_name'] = 'Test Admin';
$GLOBALS['config_smtp_provider'] = $GLOBALS['config_smtp_provider'] ?: 'microsoft_oauth';
$saved_copy = mitSetting('notify_helper_copy');
$mail = function ($ticket_id, $to, $subject_start) {
    $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
    return scenarioColumn("SELECT email_cal_str FROM email_queue WHERE email_recipient = '" . escapeSql($to) . "' AND email_subject LIKE '" . escapeSql($subject_start) . " - [TCK-$number]%' ORDER BY email_id");
};
$tech = scenarioValue('SELECT user_email FROM users WHERE user_id = 2');
$admin = scenarioValue('SELECT user_email FROM users WHERE user_id = 1');
$contact = scenarioValue("SELECT contact_email FROM contacts WHERE contact_id = $contact_id");
try {
    $t = scenarioMakeTicket($client_id, $contact_id, ['ticket_assigned_to' => 1, 'ticket_status' => 2]);
    [$ok] = mitTicketTechSave(ticketRow($t), 2, 'lead');
    scenarioCheck('helper added, following the lead', $ok, true);
    // The lead booked: the helper too
    $start = date('Y-m-d 10:00:00', strtotime('+2 days'));
    ticketSetSchedule(ticketRow($t), $start, 0, date('Y-m-d 11:00:00', strtotime('+2 days')));
    $booked = $mail($t, $tech, 'Ticket Scheduled');
    scenarioCheck('the helper is sent a booking (their own invite)', count($booked) === 1 && strpos($booked[0], "ticket-$t-tech-2@") !== false, true);
    $lead = $mail($t, $admin, 'Ticket Scheduled');
    scenarioCheck("the lead is sent the ticket's invite", count($lead) === 1 && strpos($lead[0], "-tech-") === false, true);
    $customer = $mail($t, $contact, 'Ticket Scheduled');
    scenarioCheck('the contact gets one booking email (the lead\'s)', count($customer), 1);
    // Moved: the helper follows
    $moved = date('Y-m-d 14:00:00', strtotime('+2 days'));
    ticketSetSchedule(ticketRow($t), $moved, 0, date('Y-m-d 15:30:00', strtotime('+2 days')));
    $booked = $mail($t, $tech, 'Ticket Scheduled');
    scenarioCheck('moved: the helper is sent the new time', count($booked) === 2 && preg_match('/DTSTART[^\n]*T1400/', $booked[1]) === 1, true);
    scenarioCheck('helper booking end follows the lead (15:30)', preg_match('/DTEND[^\n]*T1530/', $booked[1]) === 1, true);
    // Cancelled: the following helper's booking is cancelled
    ticketCancelSchedule(ticketRow($t));
    scenarioCheck('cancelled: the helper is sent the cancellation', count($mail($t, $tech, 'Ticket Schedule Cancelled')), 1);

    echo "copy of the client's reply\n";
    $copies = function () use ($t, $tech) {
        $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $t");
        return intval(scenarioValue("SELECT COUNT(*) FROM email_queue WHERE email_recipient = '$tech' AND email_subject LIKE '%Ticket updated - [TCK-$number]%'"));
    };
    mitSetSetting('notify_helper_copy', '1');
    mitTicketTechsCopyReply($t, 'portal', '<p>Client says hi</p>', 'Zed Contact');
    scenarioCheck('helper_copy on: the helper gets the copy', $copies(), 1);
    mitSetSetting('notify_helper_copy', '0');
    mitTicketTechsCopyReply($t, 'portal', '<p>Client says hi again</p>', 'Zed Contact');
    scenarioCheck('helper_copy off: no copy', $copies(), 1);
    mitSetSetting('notify_helper_copy', '1');

    echo "helper becomes the lead soon after their booking\n";
    $t2 = scenarioMakeTicket($client_id, $contact_id, ['ticket_assigned_to' => 1, 'ticket_status' => 2]);
    mitTicketTechSave(ticketRow($t2), 2, 'lead');
    ticketSetSchedule(ticketRow($t2), $start, 0, date('Y-m-d 11:00:00', strtotime('+2 days')));
    ticketChangeAssignee($t2, 2);
    // mitTicketTechsTidy runs once per request - the next page view, here a fresh PHP process
    $code = 'chdir(' . var_export(getcwd(), true) . '); require "config.php"; require "functions.php"; require "includes/load_global_settings.php";'
        . ' $session_user_id = 1; $session_name = "Test Admin"; mitTicketTechsTidy();';
    shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    scenarioCheck('their helper row is cleared', intval(scenarioValue("SELECT COUNT(*) FROM mit_ticket_techs WHERE ticket_id = $t2")), 0);
    $invites = array_filter($mail($t2, $tech, 'Ticket Scheduled'), function ($cal) use ($t2) { return strpos($cal, '-tech-') === false; });
    scenarioCheck("as the lead they are sent the ticket's own invite (not deduplicated against their helper booking)", count($invites), 1);
} finally {
    $saved_copy === null ? mitDeleteSetting('notify_helper_copy') : mitSetSetting('notify_helper_copy', $saved_copy);
    $tickets = implode(',', array_map('intval', $GLOBALS['scenario_tickets'] ?? [])) ?: '0';
    foreach (scenarioColumn("SELECT ticket_number FROM tickets WHERE ticket_id IN ($tickets)") as $n) {
        mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%[TCK-$n]%'");
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
