<?php
/*
 * The email parser end to end through the test install's Microsoft Graph stand-in (127.0.0.1:8765, /srv/mockms/state.json): a client's
 * email Cc'd to a routing mailbox (in another case) lands on that mailbox's board (cron/ticket_email_parser.php -> mitBoardRoute with
 * mitBoardMessageAddresses), the board's members are told; the client's reply is copied to the helping agent (mitTicketTechsCopyReply);
 * an agent's own email reply is filed as their internal note without reopening anything or mailing "ticket updated" (our parser
 * branch). Needs root (runs the parser as www-data); the stand-in is shared, so its messages are taken out again at the end.
 */
require __DIR__ . '/bootstrap.php';
$mock = '/srv/mockms/state.json';
if (!is_writable($mock) || !mitBoardsReady() || !function_exists('mitM365GraphMailboxReady') || !mitM365GraphMailboxReady()) {
    echo "  skip - no Graph stand-in / boards / Graph mailbox here\n";
    exit(0);
}
$stamp = date('His') . rand(10, 99);
[$client_id, $contact_id] = scenarioMakeClient("ZZ Parser $stamp");
$contact = scenarioValue("SELECT contact_email FROM contacts WHERE contact_id = $contact_id");
$mailbox = "zz-route-$stamp@mountaineerit.test";
$board = intval(scenarioValue("SELECT board_id FROM mit_boards WHERE board_archived_at IS NULL AND board_is_default = 0 ORDER BY board_id LIMIT 1"));
mysqli_query($mysqli, "INSERT INTO mit_board_rules SET rule_kind = 'mailbox', rule_match = '$mailbox', board_id = $board");
$tech = scenarioValue('SELECT user_email FROM users WHERE user_id = 2');
$subject = "ZZ parser $stamp";

$inject = function (array $messages) use ($mock) {
    $fh = fopen($mock, 'c+');
    flock($fh, LOCK_EX);
    $state = json_decode(stream_get_contents($fh), true);
    foreach ($messages as $id => $mime) {
        $state['mail'][] = ['id' => $id, 'folder' => 'inbox', 'isRead' => false, 'flag' => 'notFlagged', 'received' => gmdate('Y-m-d\TH:i:s\Z'), 'mime' => $mime];
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state, JSON_PRETTY_PRINT));
    flock($fh, LOCK_UN);
    fclose($fh);
};
$mime = function ($from, $to, $cc, $subj, $body) {
    return "From: $from\r\nTo: $to\r\n" . ($cc ? "Cc: $cc\r\n" : '') . "Subject: $subj\r\nDate: " . date('r') . "\r\nMessage-ID: <" . md5($subj . $from . microtime()) . "@zz>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$body\r\n";
};
$parse = function () {
    return (string) shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php ticket_email_parser.php 2>&1');
};
$ticket_id = 0;
try {
    $inject(["M-ZZ-$stamp-1" => $mime("Zed Contact <$contact>", 'service@mountaineerit.test', strtoupper($mailbox), $subject, 'Please help')]);
    $out = $parse();
    $ticket_id = intval(scenarioValue("SELECT ticket_id FROM tickets WHERE ticket_subject = '" . escapeSql($subject) . "' AND ticket_client_id = $client_id"));
    $GLOBALS['scenario_tickets'][] = $ticket_id;
    scenarioCheck("the email became the client's ticket ($out)", $ticket_id > 0, true);
    scenarioCheck('on the board of the mailbox it was Cc\'d to', intval(scenarioValue("SELECT board_id FROM mit_ticket_boards WHERE ticket_id = $ticket_id")), $board);
    $notify = array_map('intval', scenarioColumn("SELECT user_id FROM mit_board_members WHERE board_id = $board AND member_notify = 1"));
    if ($notify) {
        scenarioCheck("the board's members who asked are told", intval(scenarioValue("SELECT COUNT(DISTINCT notification_user_id) FROM notifications WHERE notification_entity_id = $ticket_id")) > 0, true);
    }
    $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");

    // Lead the admin, the technician helping; the client replies, then the technician answers by email
    mysqli_query($mysqli, "UPDATE tickets SET ticket_assigned_to = 1, ticket_status = 2 WHERE ticket_id = $ticket_id");
    mysqli_query($mysqli, "INSERT INTO mit_ticket_techs SET ticket_id = $ticket_id, user_id = 2, follows_lead = 1, added_by = 1");
    $inject([
        "M-ZZ-$stamp-2" => $mime("Zed Contact <$contact>", 'service@mountaineerit.test', '', "RE: [TCK-$number] $subject", 'Still broken'),
    ]);
    $parse();
    $copy = intval(scenarioValue("SELECT COUNT(*) FROM email_queue WHERE email_recipient = '$tech' AND email_subject LIKE '%Ticket updated - [TCK-$number]%'"));
    scenarioCheck('the client\'s reply is copied to the helper', $copy, 1);
    $inject([
        "M-ZZ-$stamp-3" => $mime("Restricted Tech <$tech>", 'service@mountaineerit.test', '', "RE: Internal note for you - [TCK-$number] - $subject", 'On it this afternoon'),
    ]);
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%Ticket updated - [TCK-$number]%'");
    $parse();
    $note = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_reply_type, ticket_reply_by FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply LIKE '%On it this afternoon%'"));
    scenarioCheck("the agent's email is their internal note", $note ? [$note['ticket_reply_type'], intval($note['ticket_reply_by'])] : null, ['Internal', 2]);
    scenarioCheck('no "ticket updated" mails for it', intval(scenarioValue("SELECT COUNT(*) FROM email_queue WHERE email_subject LIKE '%Ticket updated - [TCK-$number]%'")), 0);
    scenarioCheck('status untouched (Open)', intval(scenarioValue("SELECT ticket_status FROM tickets WHERE ticket_id = $ticket_id")), 2);
} finally {
    $fh = fopen($mock, 'c+');
    flock($fh, LOCK_EX);
    $state = json_decode(stream_get_contents($fh), true);
    $state['mail'] = array_values(array_filter($state['mail'] ?? [], function ($m) use ($stamp) { return strpos($m['id'], "M-ZZ-$stamp-") !== 0; }));
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state, JSON_PRETTY_PRINT));
    flock($fh, LOCK_UN);
    fclose($fh);
    mysqli_query($mysqli, "DELETE FROM mit_board_rules WHERE rule_match = '$mailbox'");
    if ($ticket_id) {
        $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
        mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%[TCK-$number]%'");
        mysqli_query($mysqli, "DELETE FROM ticket_attachments WHERE ticket_attachment_ticket_id = $ticket_id");
        shell_exec('rm -rf ' . escapeshellarg(getcwd() . "/uploads/tickets/$ticket_id"));
    }
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
