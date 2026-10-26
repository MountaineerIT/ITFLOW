<?php
/*
 * The AI helper's password wall (functions/mit_ai_helper.php, CLAUDE.md "AI helper"): canary secrets planted in the password vault, a
 * resolved ticket's details and notes, and a document never reach the library, a search, or the AI provider - while the useful
 * troubleshooting around them stays and is found. With the test install's AI stand-in (ai-mock/index.php) meaning-based search is
 * checked too, and every request it received is searched for the canaries.
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
$mock = @file_get_contents('http://127.0.0.1/ai-mock/index.php/_clear') !== false;
$provider_id = 0;
$credential_id = 0;
$document_id = 0;
[$client_id, $contact_id] = scenarioMakeClient("ZZ AI wall $stamp");
$canaries = ['CANARY-VAULT-9f3Xq', 'canary-vault-user', 'vault-note-canary', 'CANARY-NOTE-1a', 'canarymonkeyzz', 'CanaryZz2024', 'canarywifi-77',
    'CANARYabcdefghijklmnop', 'CanaryDocPwd9', 'canaryhunter', 'CanaryQuoted'];
$leaks = function ($text) use ($canaries) {
    $found = [];
    foreach ($canaries as $canary) {
        if (stripos((string) $text, $canary) !== false) {
            $found[] = $canary;
        }
    }
    return $found;
};
try {
    mitSetSetting('ai_helper_enabled', '1');
    mitSetSetting('ai_helper_internal', '1');
    mitSetSetting('ai_helper_documents', '1');
    if ($mock) {
        mysqli_query($mysqli, "INSERT INTO ai_providers SET ai_provider_name = 'ZZ AI stand-in $stamp', ai_provider_kind = 'custom', ai_provider_api_url = 'http://127.0.0.1/ai-mock/index.php/v1', ai_provider_api_key = 'mock', ai_provider_redact = 1");
        $provider_id = mysqli_insert_id($mysqli);
        mitSetSetting('ai_helper_embed_provider', $provider_id);
        mitSetSetting('ai_helper_embed_model', 'mock-embed');
    } else {
        mitSetSetting('ai_helper_embed_provider', '0');
        echo "  (no AI stand-in - keyword search only)\n";
    }

    echo "plant the canaries\n";
    // The vault itself - a full row of the client's
    mysqli_query($mysqli, "INSERT INTO credentials SET credential_name = 'ZZ Router', credential_username = 'canary-vault-user', credential_password = 'CANARY-VAULT-9f3Xq!',
        credential_note = 'vault-note-canary', credential_client_id = $client_id");
    $credential_id = mysqli_insert_id($mysqli);
    // A resolved ticket with secrets written into it every way techs write them, around a real fix
    $t1 = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => 'ZZ scenario ticket - printing stuck in the queue',
        'ticket_details' => '<p>Nothing prints from the front desk. Router admin password: CANARY-NOTE-1a!</p>']);
    $reply = function ($ticket_id, $type, $html) {
        global $mysqli;
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . mysqli_real_escape_string($mysqli, $html) . "', ticket_reply_type = '$type', ticket_reply_by = 1, ticket_reply_ticket_id = $ticket_id");
    };
    $reply($t1, 'Internal', '<p>Logged in to the NAS, the NAS password canarymonkeyzz. The login for the switch is CanaryZz2024!</p><p>Guest wifi key: canarywifi-77</p>');
    $reply($t1, 'Internal', '<p>Fixed it: the Print Spooler service had hung. Restarted the Print Spooler and cleared C:\\Windows\\System32\\spool\\PRINTERS, printing works.</p>');
    $reply($t1, 'Public', '<p>API key = sk-proj-CANARYabcdefghijklmnopqrstuv. Password is "CanaryQuoted phrase". All printing again - thanks!</p>');
    $reply($t1, 'Internal', '<p>pw is canaryhunter</p>');
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW(), ticket_status = 4 WHERE ticket_id = $t1");
    // A document with a password in it
    mysqli_query($mysqli, "INSERT INTO documents SET document_name = 'ZZ Front desk printer', document_content = '<p>Model HP M404. pwd=CanaryDocPwd9!</p><p>If jobs stick, restart the spooler.</p>',
        document_content_raw = 'x', document_client_id = $client_id");
    $document_id = mysqli_insert_id($mysqli);

    echo "build the library\n";
    mitAiIndexReset();
    mitSetSetting('ai_helper_index_version', mitAiIndexVersion());
    for ($i = 0; $i < 20 && mitSetting('ai_helper_since', '') === ''; $i++) {
        mitAiIndexRun(50);
    }
    // the stand-in's embeddings run inside mitAiIndexRun - make sure every passage of ours has one
    mitAiEmbedPending(30);
    $ticket_source = scenarioValue("SELECT source_id FROM mit_ai_sources WHERE ticket_id = $t1");
    $doc_source = scenarioValue("SELECT source_id FROM mit_ai_sources WHERE document_id = $document_id");
    scenarioCheck('the resolved ticket and the document are in the library', [$ticket_source !== null, $doc_source !== null], [true, true]);
    $library = implode("\n", scenarioColumn("SELECT chunk_text FROM mit_ai_chunks WHERE source_id IN (" . intval($ticket_source) . ", " . intval($doc_source) . ")"))
        . "\n" . implode("\n", scenarioColumn("SELECT title FROM mit_ai_sources WHERE source_id IN (" . intval($ticket_source) . ", " . intval($doc_source) . ")"));
    scenarioCheck('no canary anywhere in the library', $leaks($library), []);
    scenarioCheck('... nor in any passage at all', $leaks(implode("\n", scenarioColumn("SELECT chunk_text FROM mit_ai_chunks WHERE chunk_text LIKE '%anary%'"))), []);
    scenarioCheck('the fix is kept (Print Spooler, the spool folder)', [strpos($library, 'Restarted the Print Spooler') !== false, strpos($library, 'spool\\PRINTERS') !== false], [true, true]);
    scenarioCheck('the document\'s useful line is kept', strpos($library, 'restart the spooler') !== false, true);
    scenarioCheck('both are counted as having had something taken out', [intval(scenarioValue("SELECT scrubbed FROM mit_ai_sources WHERE source_id = " . intval($ticket_source))) >= 5,
        intval(scenarioValue("SELECT scrubbed FROM mit_ai_sources WHERE source_id = " . intval($doc_source))) >= 1], [true, true]);
    scenarioCheck('listed under "Passwords taken out"', count(array_filter(mitAiScrubbedSources(500), fn($s) => in_array(intval($s['source_id']), [intval($ticket_source), intval($doc_source)], true))), 2);

    echo "search\n";
    $hits = mitAiSearch('print spooler stuck', ['client_id' => $client_id]);
    scenarioCheck('a search finds the ticket', in_array(intval($ticket_source), array_column($hits, 'source_id'), true), true);
    scenarioCheck('what a search hands back has no canary', $leaks(json_encode($hits)), []);
    $hits = mitAiSearch('what is the router admin password CANARY-NOTE-1a canarymonkeyzz', ['client_id' => $client_id]);
    scenarioCheck('asking for the password by name finds no secret', $leaks(implode("\n", array_column($hits, 'text'))), []);
    if ($mock) {
        $hits = mitAiSearch('documents will not come out of the printer', ['client_id' => $client_id]);
        $found = array_values(array_filter($hits, fn($h) => $h['source_id'] === intval($ticket_source)));
        scenarioCheck('meaning-based search finds it from other words', $found ? in_array($found[0]['how'], ['meaning', 'both'], true) : false, true);
        $sent = (string) @file_get_contents('http://127.0.0.1/ai-mock/index.php/_calls');
        scenarioCheck('the AI provider received passages', strpos($sent, 'Print Spooler') !== false, true);
        scenarioCheck('the AI provider never received a canary', $leaks($sent), []);
        scenarioCheck('... and never the question\'s canary either', strpos($sent, 'CANARY-NOTE-1a') === false, true);
    }

    echo "the vault row itself\n";
    scenarioCheck('the vault row is in the database (the test planted it)', scenarioValue("SELECT credential_username FROM credentials WHERE credential_id = $credential_id"), 'canary-vault-user');
    scenarioCheck('but the library never has it', $leaks(implode("\n", scenarioColumn("SELECT chunk_text FROM mit_ai_chunks"))), []);
} finally {
    if ($credential_id) {
        mysqli_query($mysqli, "DELETE FROM credentials WHERE credential_id = $credential_id");
    }
    if ($document_id) {
        mysqli_query($mysqli, "DELETE FROM documents WHERE document_id = $document_id");
    }
    scenarioCleanup();
    foreach ($saved as $name => $value) {
        mitSetSetting($name, $value ?? '');
    }
    if ($provider_id) {
        mysqli_query($mysqli, "DELETE FROM ai_usage WHERE ai_usage_provider_id = $provider_id");
        mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_id = $provider_id");
    }
    mysqli_query($mysqli, "DELETE FROM mit_ai_sources");
    mitSetSetting('ai_helper_index_version', '');
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
