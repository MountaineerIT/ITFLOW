<?php
/*
 * Service boards (functions/mit_boards.php): routing rules in their order (mailbox > client > category > default, the address
 * matched whatever its case), what members are told (bell + email, never the mover, Admin > Notifications switches), round-robin
 * auto-assign in turn with its skips (role can't work tickets, client kept out, on leave, Out of office, holding the max), the
 * cron sweep for tickets placed another way, and archiving a board. Two boards of its own, removed at the end.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitBoardsPart2Ready') || !mitBoardsPart2Ready()) {
    echo "  skip - boards not set up\n";
    exit(0);
}
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ Boards client $stamp");
[$client2_id, $contact2_id] = scenarioMakeClient("ZZ Boards other $stamp");
$mailbox = "zz-board-$stamp@example.invalid";
mysqli_query($mysqli, "INSERT INTO mit_boards SET board_name = 'ZZ Scen A $stamp', board_color = '#123456', board_icon = 'inbox', board_order = 90, board_is_default = 0, board_assign = 'off'");
$a = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_boards SET board_name = 'ZZ Scen B $stamp', board_color = '#654321', board_icon = 'inbox', board_order = 91, board_is_default = 0, board_assign = 'off'");
$b = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'ZZ Scen cat $stamp', category_type = 'Ticket', category_color = '#000000'");
$category = mysqli_insert_id($mysqli);
mysqli_query($mysqli, "INSERT INTO mit_board_rules (rule_kind, rule_match, board_id) VALUES ('mailbox', '$mailbox', $a), ('client', '$client_id', $b), ('category', '$category', $a)");
// Members of A: admin 1 and the technician 2 in the turn and told, the accountant 6 (Support view only) told, not in the turn
mysqli_query($mysqli, "INSERT INTO mit_board_members (board_id, user_id, member_notify, member_rotation) VALUES ($a, 1, 1, 1), ($a, 2, 1, 1), ($a, 6, 1, 0)");
$saved = ['notify_board_app' => mitSetting('notify_board_app'), 'notify_board_email' => mitSetting('notify_board_email')];
$default = mitBoardDefaultId();
$history = function ($ticket_id) {
    return implode(' | ', scenarioColumn("SELECT ticket_history_description FROM ticket_history WHERE ticket_history_ticket_id = $ticket_id ORDER BY ticket_history_id"));
};
$told = function ($ticket_id) {
    return array_map('intval', scenarioColumn("SELECT notification_user_id FROM notifications WHERE notification_entity_id = $ticket_id AND notification LIKE '%ZZ Scen%' ORDER BY notification_user_id"));
};
$mailed = function ($ticket_id) {
    global $mysqli;
    $number = scenarioValue("SELECT ticket_number FROM tickets WHERE ticket_id = $ticket_id");
    return scenarioColumn("SELECT email_recipient FROM email_queue WHERE email_subject LIKE '%TCK-$number]%ZZ Scen%' ORDER BY email_recipient");
};
$emails = array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT user_id, user_email FROM users WHERE user_id IN (1,2,6)"), MYSQLI_ASSOC), 'user_email', 'user_id');

try {
    mitDeleteSetting('notify_board_app');
    mitDeleteSetting('notify_board_email');

    echo "routing\n";
    // Mailbox beats the client rule, whatever the case of the address
    $t1 = scenarioMakeTicket($client_id, $contact_id, ['ticket_category' => $category]);
    $GLOBALS['session_user_id'] = 0; // the parser / cron: nobody signed in
    scenarioCheck('sent to the mailbox (upper case): its board', mitBoardRoute($t1, [strtoupper($mailbox), 'other@example.invalid']), $a);
    scenarioCheck('logged as routed in the move log', scenarioValue("SELECT CONCAT(IFNULL(from_board_id,'null'),'>',to_board_id,':',move_how) FROM mit_board_moves WHERE ticket_id = $t1"), "null>$a:routed");
    scenarioCheck('history says why', strpos($history($t1), "(sent to $mailbox)") !== false, true);
    scenarioCheck('routed once only (a second call keeps it)', mitBoardRoute($t1, []), $a);
    scenarioCheck('members told: everyone who asked and can at least read the client (bell)', $told($t1), [1, 2, 6]);
    $want = [$emails[1], $emails[2], $emails[6]];
    sort($want);
    scenarioCheck('members told: an email each', $mailed($t1), $want);
    $t2 = scenarioMakeTicket($client_id, $contact_id, ['ticket_category' => $category]);
    scenarioCheck('client rule beats the category rule', mitBoardRoute($t2), $b);
    $t3 = scenarioMakeTicket($client2_id, $contact2_id, ['ticket_category' => $category]);
    scenarioCheck('category rule', mitBoardRoute($t3), $a);
    $t4 = scenarioMakeTicket($client2_id, $contact2_id);
    scenarioCheck('no rule: the default board', mitBoardRoute($t4), $default);
    scenarioCheck('mitBoardMessageAddresses reads To / Cc / Delivered-To', mitBoardMessageAddresses(new class {
        public function to() { return [new class { public function email() { return 'Desk@Example.invalid'; } }]; }
        public function cc() { return ['"Bob" <bob@example.invalid>']; }
        public function header($name) { return $name === 'Delivered-To' ? new class { public function getValue() { return 'zz-route@example.invalid'; } } : null; }
    }), ['desk@example.invalid', 'bob@example.invalid', 'zz-route@example.invalid']);

    echo "moves and notification switches\n";
    $GLOBALS['session_user_id'] = 1;
    $GLOBALS['session_name'] = escapeSql("Test O'Admin");
    [$ok, $message] = mitBoardMove($t2, $a, 'Needs the A team', false, 'escalated');
    scenarioCheck('escalate: ok', $ok, true);
    scenarioCheck('escalate: the mover is not told, the others are', array_values(array_diff($told($t2), [])), [2, 6]);
    scenarioCheck('escalate: internal note with the reason', strpos((string) scenarioValue("SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_ticket_id = $t2 AND ticket_reply_type = 'Internal' ORDER BY ticket_reply_id DESC LIMIT 1"), 'Needs the A team') !== false, true);
    scenarioCheck("escalate: history names the agent without SQL escaping", strpos($history($t2), "Test O'Admin escalated the ticket") !== false, true);
    scenarioCheck('move log: escalated by 1', scenarioValue("SELECT CONCAT(from_board_id,'>',to_board_id,':',move_how,':',moved_by) FROM mit_board_moves WHERE ticket_id = $t2 ORDER BY move_id DESC LIMIT 1"), "$b>$a:escalated:1");
    $note = (string) scenarioValue("SELECT notification FROM notifications WHERE notification_entity_id = $t2 AND notification_user_id = 2");
    scenarioCheck('the bell names the agent as written', strpos($note, "Test O'Admin escalated") !== false, true);
    mitSetSetting('notify_board_app', '0');
    $t5 = scenarioMakeTicket($client_id, $contact_id);
    mitBoardMove($t5, $a, '', false, 'moved');
    scenarioCheck('board_app off: no bell', $told($t5), []);
    scenarioCheck('board_app off: emails still go', count($mailed($t5)), 2);
    mitSetSetting('notify_board_app', '1');
    mitSetSetting('notify_board_email', '0');
    $t6 = scenarioMakeTicket($client_id, $contact_id);
    mitBoardMove($t6, $a, '', false, 'moved');
    scenarioCheck('board_email off: no email', $mailed($t6), []);
    scenarioCheck('board_email off: the bell still rings', $told($t6), [2, 6]);
    mitDeleteSetting('notify_board_email');
    // A member kept out of the client is not told
    mysqli_query($mysqli, "INSERT INTO user_client_permissions SET user_id = 2, client_id = $client_id, permission_type = 'deny'");
    $t7 = scenarioMakeTicket($client_id, $contact_id);
    mitBoardMove($t7, $a, '', false, 'moved');
    scenarioCheck('member kept out of the client: not told', $told($t7), [6]);
    mysqli_query($mysqli, "DELETE FROM user_client_permissions WHERE user_id = 2 AND client_id = $client_id");

    echo "round-robin\n";
    mysqli_query($mysqli, "UPDATE mit_boards SET board_assign = 'round_robin', board_assign_hours = 0, board_assign_max = NULL, board_assign_last = 1, board_assign_since = NOW() - INTERVAL 1 MINUTE WHERE board_id = $a");
    $assigned = function ($ticket_id) {
        return intval(scenarioValue("SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id"));
    };
    $GLOBALS['session_user_id'] = 0;
    $r1 = scenarioMakeTicket($client2_id, $contact2_id, ['ticket_category' => $category]);
    mitBoardRoute($r1);
    scenarioCheck('in turn after the last one (1): the technician', $assigned($r1), 2);
    scenarioCheck('the reassign is signed by the board', strpos($history($r1), "Auto-assign (ZZ Scen A $stamp) assigned the ticket to") !== false, true);
    scenarioCheck('board remembers who got the last one', intval(scenarioValue("SELECT board_assign_last FROM mit_boards WHERE board_id = $a")), 2);
    $r2 = scenarioMakeTicket($client2_id, $contact2_id, ['ticket_category' => $category]);
    mitBoardRoute($r2);
    scenarioCheck('next in turn wraps to the admin', $assigned($r2), 1);
    // Skips: the technician is on leave today, so the admin gets it even out of turn
    $today = mitTsToday(2);
    mysqli_query($mysqli, "INSERT INTO mit_time_entries SET user_id = 2, entry_date = '$today', code_id = 5, minutes = 480, note = 'ZZ scenario leave', updated_by = 1");
    $leave_id = mysqli_insert_id($mysqli);
    [$next, $skipped] = mitBoardNextMember(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_boards WHERE board_id = $a")), $client2_id);
    scenarioCheck('on leave today: skipped', [$next, $skipped[2] ?? ''], [1, 'on leave today']);
    mysqli_query($mysqli, "INSERT INTO mit_outlook_busy SET user_id = 1, starts_at = NOW() - INTERVAL 1 HOUR, ends_at = NOW() + INTERVAL 1 HOUR, subject = 'ZZ OOF', show_as = 'oof'");
    $oof_id = mysqli_insert_id($mysqli);
    $r3 = scenarioMakeTicket($client2_id, $contact2_id, ['ticket_category' => $category]);
    mitBoardRoute($r3);
    scenarioCheck('everyone away: left unassigned', $assigned($r3), 0);
    mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE entry_id = $leave_id");
    mysqli_query($mysqli, "DELETE FROM mit_outlook_busy WHERE busy_id = $oof_id");
    scenarioCheck('the cron sweep deals out the one that waited', mitBoardAssignSweep() >= 1 && $assigned($r3) > 0, true);
    // Max open on the board: the technician already holds one (r1)
    mysqli_query($mysqli, "UPDATE mit_boards SET board_assign_max = 1, board_assign_last = 1 WHERE board_id = $a");
    $held2 = intval(scenarioValue("SELECT COUNT(*) FROM tickets WHERE ticket_assigned_to = 2 AND " . TICKET_OPEN_SQL . " AND " . mitBoardSql() . " = $a"));
    [$next, $skipped] = mitBoardNextMember(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_boards WHERE board_id = $a")), $client2_id);
    scenarioCheck("holding the max ($held2 open): skipped", $skipped[2] ?? '', 'holds 1 open tickets here');
    mysqli_query($mysqli, "UPDATE mit_boards SET board_assign_max = NULL WHERE board_id = $a");
    mysqli_query($mysqli, "INSERT INTO user_client_permissions SET user_id = 2, client_id = $client2_id, permission_type = 'deny'");
    [$next, $skipped] = mitBoardNextMember(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_boards WHERE board_id = $a")), $client2_id);
    scenarioCheck('kept out of the client: skipped', $skipped[2] ?? '', 'cannot see the client');
    mysqli_query($mysqli, "DELETE FROM user_client_permissions WHERE user_id = 2 AND client_id = $client2_id");
    // A project ticket is never dealt out
    $project = intval(scenarioValue("SELECT project_id FROM projects WHERE project_archived_at IS NULL LIMIT 1"));
    if ($project && mitProjectTicketsApart()) {
        $r4 = scenarioMakeTicket($client2_id, $contact2_id, ['ticket_category' => $category, 'ticket_project_id' => $project]);
        mitBoardRoute($r4);
        scenarioCheck('a project ticket is not dealt out', $assigned($r4), 0);
    }
    // Working hours only: outside them nothing is handed out (Admin > SLA hours made to exclude now)
    mysqli_query($mysqli, "UPDATE mit_boards SET board_assign_hours = 1 WHERE board_id = $a");
    if (!mitBoardWorkingNow()) {
        $r5 = scenarioMakeTicket($client2_id, $contact2_id, ['ticket_category' => $category]);
        mitBoardRoute($r5);
        scenarioCheck('working hours only, outside them: waits', $assigned($r5), 0);
    } else {
        echo "  (now is inside working hours - the after-hours wait is not checked)\n";
    }

    echo "sweep\n";
    $s1 = scenarioMakeTicket($client2_id, $contact2_id, ['ticket_category' => $category]);
    mysqli_query($mysqli, "UPDATE mit_boards SET board_assign = 'off' WHERE board_id = $a");
    mitBoardSweep();
    scenarioCheck('the cron sweep places a ticket made another way', mitBoardOf($s1), $a);
} finally {
    foreach ($saved as $name => $value) {
        $value === null ? mitDeleteSetting($name) : mitSetSetting($name, $value);
    }
    mysqli_query($mysqli, "DELETE FROM user_client_permissions WHERE client_id IN ($client_id, $client2_id)");
    mysqli_query($mysqli, "DELETE FROM mit_time_entries WHERE note = 'ZZ scenario leave'");
    mysqli_query($mysqli, "DELETE FROM mit_outlook_busy WHERE subject = 'ZZ OOF'");
    mysqli_query($mysqli, "DELETE FROM mit_board_rules WHERE board_id IN ($a, $b)");
    mysqli_query($mysqli, "DELETE FROM mit_board_members WHERE board_id IN ($a, $b)");
    $tickets = implode(',', array_map('intval', $GLOBALS['scenario_tickets'] ?? [])) ?: '0';
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%ZZ Scen%' OR email_subject LIKE '%ZZ scenario ticket%'");
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_entity_id IN ($tickets)");
    scenarioCleanup();
    mysqli_query($mysqli, "DELETE FROM mit_board_moves WHERE to_board_id IN ($a, $b) OR from_board_id IN ($a, $b)");
    mysqli_query($mysqli, "DELETE FROM mit_ticket_boards WHERE board_id IN ($a, $b)");
    mysqli_query($mysqli, "DELETE FROM mit_boards WHERE board_id IN ($a, $b)");
    mysqli_query($mysqli, "DELETE FROM categories WHERE category_id = $category");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
