<?php
/*
 * The AI helper's library (functions/mit_ai_helper.php mitAiIndexRun / mitAiSearch) following ITFlow's records: an open ticket stays
 * out, resolved goes in, reopened or archived comes out, a client move follows, a deleted ticket's passages go with it; internal
 * notes and documents follow their switches; and a search only ever returns clients the agent can open, needs the role's Use AI
 * and Support permissions, and nothing while the helper is off.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitAiHelperReady') || !mitAiHelperReady()) {
    echo "  skip - M044 not run\n";
    exit(0);
}
$stamp = date('His');
$saved = [];
foreach (['ai_helper_enabled', 'ai_helper_internal', 'ai_helper_documents', 'ai_helper_embed_provider', 'ai_helper_embed_model'] as $name) {
    $saved[$name] = mitSetting($name);
}
$document_id = 0;
[$client_a] = $a = scenarioMakeClient("ZZ AI library A $stamp");
[$client_b] = $b = scenarioMakeClient("ZZ AI library B $stamp");
$catch_up = function () {
    mitSetSetting('ai_helper_since', ''); // look at everything again, as a fresh pass would
    mitSetSetting('ai_helper_pass_started', '');
    for ($i = 0; $i < 20; $i++) {
        mitAiIndexRun(50);
        if (mitSetting('ai_helper_pass_started', '') === '') {
            break;
        }
    }
};
$source = function ($ticket_id) {
    return scenarioValue("SELECT source_id FROM mit_ai_sources WHERE ticket_id = " . intval($ticket_id));
};
$text = function ($ticket_id) {
    return implode("\n", scenarioColumn("SELECT k.chunk_text FROM mit_ai_chunks k JOIN mit_ai_sources s USING (source_id) WHERE s.ticket_id = " . intval($ticket_id)));
};
$reply = function ($ticket_id, $type, $words) {
    global $mysqli;
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '<p>" . mysqli_real_escape_string($mysqli, $words) . "</p>', ticket_reply_type = '$type', ticket_reply_by = 1, ticket_reply_ticket_id = $ticket_id");
};
$as_agent = function ($access, $role = 2) {
    // A technician limited to $access clients (ITFlow's session arrays, as load_user_session.php fills them)
    $GLOBALS['session_is_admin'] = false;
    $GLOBALS['session_user_role'] = $role;
    $GLOBALS['client_access_array'] = $access;
    $GLOBALS['client_deny_array'] = [];
};
$as_admin = function () {
    $GLOBALS['session_is_admin'] = true;
    $GLOBALS['client_access_array'] = [];
    $GLOBALS['client_deny_array'] = [];
};
try {
    mitSetSetting('ai_helper_enabled', '1');
    mitSetSetting('ai_helper_internal', '1');
    mitSetSetting('ai_helper_documents', '1');
    mitSetSetting('ai_helper_embed_provider', '0');
    mitAiIndexReset();
    mitSetSetting('ai_helper_index_version', mitAiIndexVersion());

    echo "tickets come and go with their state\n";
    $t_open = scenarioMakeTicket($client_a, $a[1], ['ticket_subject' => "ZZ scenario ticket - zebrafish open $stamp", 'ticket_details' => 'zebrafish open issue']);
    $t_done = scenarioMakeTicket($client_a, $a[1], ['ticket_subject' => "ZZ scenario ticket - zebrafish fixed $stamp", 'ticket_details' => 'zebrafish scanner offline']);
    $reply($t_done, 'Internal', 'Internal: reseated the zebrafish scanner cable.');
    $reply($t_done, 'Public', 'Your zebrafish scanner works again.');
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $t_done");
    $t_b = scenarioMakeTicket($client_b, $b[1], ['ticket_subject' => "ZZ scenario ticket - zebrafish other client $stamp", 'ticket_details' => 'zebrafish at client B']);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_closed_at = NOW() WHERE ticket_id = $t_b");
    $catch_up();
    scenarioCheck('an open ticket stays out', $source($t_open), null);
    scenarioCheck('resolved and closed tickets go in', [$source($t_done) !== null, $source($t_b) !== null], [true, true]);
    scenarioCheck('with internal notes and replies', [strpos($text($t_done), 'reseated the zebrafish') !== false, strpos($text($t_done), 'works again') !== false], [true, true]);
    scenarioCheck('each passage starts with what it is from', strpos($text($t_done), "Ticket TCK-") === 0 && strpos($text($t_done), "ZZ AI library A $stamp") !== false, true);

    sleep(1); // updated_at moves on
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NULL WHERE ticket_id = $t_done");
    mitAiIndexRun(50);
    scenarioCheck('reopened: taken out on the next run', $source($t_done), null);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $t_done");
    mitAiIndexRun(50);
    scenarioCheck('resolved again: back in', $source($t_done) !== null, true);
    sleep(1);
    $reply($t_done, 'Internal', 'Later note: replaced the zebrafish cable for good.');
    mitAiIndexRun(50);
    scenarioCheck('a new note on a resolved ticket is picked up', strpos($text($t_done), 'replaced the zebrafish cable') !== false, true);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_client_id = $client_b WHERE ticket_id = $t_done");
    mitAiIndexRun(50);
    scenarioCheck('moved to another client: its passages follow', [scenarioValue("SELECT client_id FROM mit_ai_sources WHERE ticket_id = $t_done"),
        scenarioValue("SELECT MIN(k.client_id) FROM mit_ai_chunks k JOIN mit_ai_sources s USING (source_id) WHERE s.ticket_id = $t_done")], [(string) $client_b, (string) $client_b]);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_client_id = $client_a WHERE ticket_id = $t_done");
    mitAiIndexRun(50);

    echo "switches\n";
    mitSetSetting('ai_helper_internal', '0');
    $catch_up();
    scenarioCheck('internal notes off: the library is rebuilt without them', [strpos($text($t_done), 'reseated') === false, strpos($text($t_done), 'works again') !== false], [true, true]);
    mitSetSetting('ai_helper_internal', '1');
    $catch_up();
    mysqli_query($mysqli, "INSERT INTO documents SET document_name = 'ZZ zebrafish runbook $stamp', document_content = '<p>zebrafish scanner runbook: power cycle first.</p>', document_content_raw = 'x', document_client_id = $client_a");
    $document_id = mysqli_insert_id($mysqli);
    mitAiIndexRun(50);
    scenarioCheck('a new document goes in', scenarioValue("SELECT COUNT(*) FROM mit_ai_sources WHERE document_id = $document_id"), '1');
    mitSetSetting('ai_helper_documents', '0');
    $catch_up();
    scenarioCheck('documents off: taken out', scenarioValue("SELECT COUNT(*) FROM mit_ai_sources WHERE document_id = $document_id"), '0');
    mitSetSetting('ai_helper_documents', '1');
    $catch_up();
    mysqli_query($mysqli, "UPDATE documents SET document_archived_at = NOW() WHERE document_id = $document_id");
    mitAiIndexRun(50);
    scenarioCheck('an archived document comes out', scenarioValue("SELECT COUNT(*) FROM mit_ai_sources WHERE document_id = $document_id"), '0');

    echo "who may search what\n";
    $all = array_column(mitAiSearch("zebrafish", ['limit' => 30]), 'client_id');
    scenarioCheck('an admin finds both clients', [in_array($client_a, $all, true), in_array($client_b, $all, true)], [true, true]);
    $as_agent([$client_a]);
    $limited = array_column(mitAiSearch("zebrafish", ['limit' => 30]), 'client_id');
    scenarioCheck('a technician limited to client A never gets client B', [in_array($client_a, $limited, true), in_array($client_b, $limited, true)], [true, false]);
    $as_agent([$client_b]);
    $limited = array_column(mitAiSearch("zebrafish", ['limit' => 30, 'client_id' => $client_a]), 'client_id');
    scenarioCheck('... not even when asking about client A by id', in_array($client_a, $limited, true), false);
    $as_agent([], 4); // "Audit No Access": Use AI but no Support
    scenarioCheck('a role without Support gets nothing', mitAiSearch("zebrafish"), []);
    $as_admin();
    mitSetSetting('ai_helper_enabled', '0');
    scenarioCheck('helper off: nothing', mitAiSearch("zebrafish"), []);
    mitSetSetting('ai_helper_enabled', '1');

    echo "deleting\n";
    $sid = $source($t_b);
    mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_ticket_id = $t_b");
    mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id = $t_b");
    scenarioCheck('a deleted ticket takes its passages with it', scenarioValue("SELECT COUNT(*) FROM mit_ai_chunks WHERE source_id = " . intval($sid)), '0');
} finally {
    $as_admin();
    if ($document_id) {
        mysqli_query($mysqli, "DELETE FROM documents WHERE document_id = $document_id");
    }
    scenarioCleanup();
    foreach ($saved as $name => $value) {
        mitSetSetting($name, $value ?? '');
    }
    mysqli_query($mysqli, "DELETE FROM mit_ai_sources");
    mitSetSetting('ai_helper_index_version', '');
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
