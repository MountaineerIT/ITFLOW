<?php
/*
 * "When the client replies" can move a ticket to any working status (functions/mit_reply_status.php, M063): a "Client responded" status
 * made like any other; On Hold and Open set to move there; ITFlow's own ticketStatusAfterClientReply then answers it (the email parser,
 * portal and text replies all use it); the picker saves "1:<id>" with ITFlow's column left at back to Open; New / Resolved / Closed can't
 * be targets; a deleted target falls back to ITFlow's answer. End to end: a client's email reply through the parser (Graph stand-in) moves
 * an On Hold ticket to Client responded.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitReplyStatusReady') || !mitReplyStatusReady()) {
    echo "  skip - M063 not run\n";
    exit(0);
}
$stamp = date('His') . rand(10, 99);
$saved = scenarioColumn("SELECT CONCAT(ticket_status_id, ':', reply_status_id) FROM mit_ticket_status_reply");
$order = intval(scenarioValue("SELECT MAX(ticket_status_order) FROM ticket_statuses")) + 1;
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'ZZ Client responded $stamp', ticket_status_color = '#0d6efd', ticket_status_active = 1, ticket_status_pauses_sla = 0, ticket_status_order = $order");
$responded = intval(mysqli_insert_id($mysqli));
mysqli_query($mysqli, "INSERT INTO ticket_statuses SET ticket_status_name = 'ZZ Scheduled $stamp', ticket_status_color = '#6f42c1', ticket_status_active = 1, ticket_status_pauses_sla = 0, ticket_status_client_reply = 0, ticket_status_order = " . ($order + 1));
$scheduled = intval(mysqli_insert_id($mysqli));
ticketStatusesAll(true);
$ticket_id = 0;
try {
    echo "before any mapping - ITFlow's own answers\n";
    scenarioCheck('On Hold goes back to Open, a "stay" status stays', [ticketStatusAfterClientReply(TICKET_STATUS_ON_HOLD), ticketStatusAfterClientReply($scheduled)], [TICKET_STATUS_OPEN, $scheduled]);

    echo "the picker\n";
    $field = mitReplyStatusField(TICKET_STATUS_ON_HOLD, 1);
    scenarioCheck('On Hold offers "Move it to ZZ Client responded"', strpos($field, 'value="1:' . $responded . '"') !== false && strpos($field, "Move it to ZZ Client responded $stamp") !== false, true);
    scenarioCheck('... never to New, Resolved or Closed', [strpos($field, 'value="1:' . TICKET_STATUS_NEW . '"'), strpos($field, 'value="1:' . TICKET_STATUS_RESOLVED . '"'), strpos($field, 'value="1:' . TICKET_STATUS_CLOSED . '"')], [false, false, false]);
    scenarioCheck('Closed keeps ITFlow\'s fixed text', mitReplyStatusField(TICKET_STATUS_CLOSED, 1), '');

    echo "mapped\n";
    scenarioCheck('On Hold saved to move to it', strpos(mitReplyStatusSave(TICKET_STATUS_ON_HOLD, ['mit_reply_present' => '1', 'client_reply' => '1:' . $responded]), 'moves the ticket to') !== false, true);
    mitReplyStatusSave(TICKET_STATUS_OPEN, ['mit_reply_present' => '1', 'client_reply' => '1:' . $responded]);
    mitReplyStatusSave(TICKET_STATUS_RESOLVED, ['mit_reply_present' => '1', 'client_reply' => '1:' . $responded]);
    scenarioCheck('ITFlow\'s answer now: On Hold, Open and Resolved go to Client responded', [ticketStatusAfterClientReply(TICKET_STATUS_ON_HOLD),
        ticketStatusAfterClientReply(TICKET_STATUS_OPEN), ticketStatusAfterClientReply(TICKET_STATUS_RESOLVED)], [$responded, $responded, $responded]);
    scenarioCheck('a status left alone keeps ITFlow\'s answer', ticketStatusAfterClientReply($scheduled), $scheduled);
    scenarioCheck('a target of New / Resolved / Closed is refused', [mitReplyStatusSave($scheduled, ['mit_reply_present' => '1', 'client_reply' => '1:' . TICKET_STATUS_RESOLVED]), ticketStatusAfterClientReply($scheduled)], ['', $scheduled]);
    scenarioCheck('a form without the picker leaves it alone', mitReplyStatusSave(TICKET_STATUS_ON_HOLD, ['client_reply' => '1']), '');
    scenarioCheck('the list says where it goes', strpos(mitReplyStatusListText(TICKET_STATUS_ON_HOLD), 'data-mit-reply-to="' . $responded . '"') !== false, true);

    echo "a client's email reply (the parser)\n";
    $mock = '/srv/mockms/state.json';
    if (is_writable($mock) && function_exists('mitM365GraphMailboxReady') && mitM365GraphMailboxReady()) {
        [$client_id, $contact_id] = scenarioMakeClient("ZZ Reply status $stamp");
        $contact = scenarioValue("SELECT contact_email FROM contacts WHERE contact_id = $contact_id");
        $ticket_id = scenarioMakeTicket($client_id, $contact_id, ['ticket_status' => TICKET_STATUS_ON_HOLD, 'ticket_subject' => "ZZ reply status $stamp"]);
        $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
        $edit = function (callable $change) use ($mock) {
            $lock = fopen("$mock.lock", 'c');
            flock($lock, LOCK_EX);
            $state = json_decode((string) file_get_contents($mock), true);
            if (is_array($state)) {
                file_put_contents("$mock.tmp", json_encode($change($state), JSON_PRETTY_PRINT));
                rename("$mock.tmp", $mock);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        };
        $mime = "From: Zed Contact <$contact>\r\nTo: service@mountaineerit.test\r\nSubject: RE: [TCK-$number] ZZ reply status $stamp\r\nDate: " . date('r')
            . "\r\nMessage-ID: <" . md5($stamp . microtime()) . "@zz>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHere are the details you asked for\r\n";
        $edit(function ($state) use ($stamp, $mime) {
            $state['mail'][] = ['id' => "M-ZZ-RS-$stamp", 'folder' => 'inbox', 'isRead' => false, 'flag' => 'notFlagged', 'received' => gmdate('Y-m-d\TH:i:s\Z'), 'mime' => $mime];
            return $state;
        });
        try {
            shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php ticket_email_parser.php 2>&1');
        } finally {
            $edit(function ($state) use ($stamp) {
                $state['mail'] = array_values(array_filter($state['mail'] ?? [], function ($m) use ($stamp) { return $m['id'] !== "M-ZZ-RS-$stamp"; }));
                return $state;
            });
        }
        scenarioCheck('the reply is on the ticket', intval(scenarioValue("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply LIKE '%details you asked for%'")), 1);
        scenarioCheck('On Hold -> Client responded', intval(scenarioValue("SELECT ticket_status FROM tickets WHERE ticket_id = $ticket_id")), $responded);
    } else {
        echo "  note - no Graph stand-in here, the email step is left out\n";
    }

    echo "the target deleted\n";
    mysqli_query($mysqli, "DELETE FROM ticket_statuses WHERE ticket_status_id = $responded");
    ticketStatusesAll(true);
    mitReplyStatusMap(true);
    scenarioCheck('its mapping goes with it (FK) - back to ITFlow\'s answer', [intval(scenarioValue("SELECT COUNT(*) FROM mit_ticket_status_reply WHERE reply_status_id = $responded")), ticketStatusAfterClientReply(TICKET_STATUS_ON_HOLD)], [0, TICKET_STATUS_OPEN]);
} finally {
    if ($ticket_id) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_status = 1 WHERE ticket_id = $ticket_id");
    }
    mysqli_query($mysqli, "DELETE FROM mit_ticket_status_reply");
    foreach ($saved as $row) {
        [$from, $to] = array_map('intval', explode(':', $row));
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_ticket_status_reply SET ticket_status_id = $from, reply_status_id = $to");
    }
    mysqli_query($mysqli, "DELETE FROM ticket_statuses WHERE ticket_status_id IN ($responded, $scheduled)");
    ticketStatusesAll(true);
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
