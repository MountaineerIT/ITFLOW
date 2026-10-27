<?php
/*
 * Our Microsoft 365 / Outlook hooks inside ITFlow's own functions: httpFormPost signs ITFlow's mail token requests with our app's
 * certificate (functions/request.php - the Microsoft stand-in refuses the placeholder secret), the calendar offers the Outlook busy
 * times source (functions/calendar.php calendarSources), and a booking email to an agent whose Outlook is synced comes without the
 * .ics, which the sync puts on their calendar instead (functions/ticket.php ticketMailSchedule). Needs the stand-in and our app.
 */
require __DIR__ . '/bootstrap.php';

$app = function_exists('mitM365App') ? mitM365App() : null;
if (!$app || strpos(MIT_M365_LOGIN, '127.0.0.1') === false) {
    echo "  skip - no Microsoft 365 app on the stand-in here\n";
    exit(0);
}

// 1. ITFlow's own token request with our app's id and the placeholder secret: signed with the certificate on the way out
$tenant = (string) mitSetting('m365_tenant_id', '');
$token = httpFormPost(MIT_M365_LOGIN . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token', [
    'client_id' => $app['client_id'], 'client_secret' => MIT_M365_CERT_SECRET, 'grant_type' => 'client_credentials', 'scope' => 'https://graph.microsoft.com/.default',
]);
scenarioCheck('httpFormPost: the certificate signs the token request (' . substr((string) ($token['body'] ?? $token['error'] ?? ''), 0, 120) . ')', $token['ok'], true);

// 2. The calendar's sources include Outlook busy times
scenarioCheck('calendarSources offers Outlook busy times', array_key_exists('outlook_busy', calendarSources()), true);

// 3. A booking email to an agent whose Outlook is synced carries no .ics
$synced = '';
foreach (scenarioColumn("SELECT user_email FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL") as $email) {
    if (mitOutlookSyncsEmail($email)) {
        $synced = $email;
        break;
    }
}
if ($synced === '') {
    echo "  note - no agent with Outlook synced here, the booking email check is left out\n";
} else {
    $agent = intval(scenarioValue("SELECT user_id FROM users WHERE user_email = '" . escapeSql($synced) . "'"));
    [$client_id, $contact_id] = scenarioMakeClient('ZZ m365 hooks ' . date('His'));
    $ticket_id = scenarioMakeTicket($client_id, $contact_id, ['ticket_assigned_to' => $agent, 'ticket_status' => 2, 'ticket_subject' => 'ZZ m365 hooks booking']);
    $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
    $day = date('Y-m-d', strtotime('+3 days'));
    $GLOBALS['mit_schedule_notify'] = ['tech' => true, 'contact' => false, 'watchers' => false, 'site' => false, 'primary' => false, 'extra' => ''];
    ticketSetSchedule(ticketRow($ticket_id), "$day 10:00:00", 0, "$day 11:00:00");
    unset($GLOBALS['mit_schedule_notify']);
    $mail = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT email_cal_str FROM email_queue WHERE email_recipient = '" . escapeSql($synced) . "'
        AND email_subject LIKE '%[TCK-$number]%' ORDER BY email_id DESC LIMIT 1"));
    scenarioCheck('the synced agent is emailed about the booking', (bool) $mail, true);
    scenarioCheck('without the .ics (Outlook sync puts it on their calendar)', trim((string) ($mail['email_cal_str'] ?? 'missing')), '');
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%[TCK-$number]%'");
    try {
        mysqli_query($mysqli, "DELETE FROM mit_outlook_events WHERE ticket_id = $ticket_id");
        mysqli_query($mysqli, "DELETE FROM mit_ticket_schedule_notify WHERE ticket_id = $ticket_id");
    } catch (Throwable $e) {
    }
}
scenarioCleanup();
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
