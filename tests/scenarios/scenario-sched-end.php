<?php
/*
 * A booking's end inside ITFlow's own functions (functions/mit_calendar.php mitTicketScheduleEnd / mitScheduleWhenText): an end
 * before the start is not stored as given - the booking gets the usual length (functions/ticket.php ticketSetSchedule); the "when"
 * ticketSetSchedule hands back (emails, history) names the end; ITFlow's invite builder (functions/app.php createiCalStr) never
 * makes an invite that ends where it starts. Makes one ticket and removes it.
 */
require __DIR__ . '/bootstrap.php';

[$client_id, $contact_id] = scenarioMakeClient('ZZ sched end ' . date('His'));
$ticket_id = scenarioMakeTicket($client_id, $contact_id, ['ticket_assigned_to' => 1, 'ticket_status' => 2, 'ticket_subject' => 'ZZ sched end']);
$number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
$day = date('Y-m-d', strtotime('+4 days'));
$GLOBALS['mit_schedule_notify'] = ['tech' => false, 'contact' => false, 'watchers' => false, 'site' => false, 'primary' => false, 'extra' => ''];
try {
    // An end before the start: the usual length instead
    ticketSetSchedule(ticketRow($ticket_id), "$day 10:00:00", 0, "$day 09:00:00");
    scenarioCheck('an end before the start is not stored', scenarioValue("SELECT ticket_schedule_end FROM tickets WHERE ticket_id = $ticket_id"),
        date('Y-m-d H:i:s', strtotime("$day 10:00:00") + CALENDAR_TICKET_MINUTES * 60));

    // The when text names the end
    $result = ticketSetSchedule(ticketRow($ticket_id), "$day 10:00:00", 0, "$day 11:30:00");
    scenarioCheck('the when text names the end (' . ($result['when_text'] ?? '') . ')', strpos((string) ($result['when_text'] ?? ''), '- 11:30am') !== false, true);

    // ITFlow's invite: an end at the start is not kept
    $ics = createiCalStr("$day 10:00:00", 'ZZ sched end', 'test', '', 'zz-sched-end@test', "$day 10:00:00");
    scenarioCheck('the invite does not end where it starts', strpos($ics, 'DTEND') !== false && preg_match('/DTEND[^:\r\n]*:' . date('Ymd', strtotime($day)) . 'T100000/', $ics) === 0, true);
} finally {
    unset($GLOBALS['mit_schedule_notify']);
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%[TCK-$number]%'");
    try {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_schedule_notify WHERE ticket_id = $ticket_id");
        mysqli_query($mysqli, "DELETE FROM mit_outlook_events WHERE ticket_id = $ticket_id");
    } catch (Throwable $e) {
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
