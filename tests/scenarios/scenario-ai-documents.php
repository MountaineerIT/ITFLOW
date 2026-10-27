<?php
/*
 * Documents in the AI helper's answers (functions/mit_ai_helper.php mitAiSearch): a library of many tickets about the same thing still
 * hands the AI the matching document - kept places (MIT_AI_DOCUMENT_SLOTS) and a weight - by keyword, and by meaning when the test
 * install's AI stand-in is there; a document archived in ITFlow (which keeps its updated time) leaves the library on the next run.
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
$provider_id = 0;
[$client_id, $contact_id] = scenarioMakeClient("ZZ AI docs $stamp");
$run = function () {
    mitSetSetting('ai_helper_since', '');
    mitSetSetting('ai_helper_pass_started', '');
    for ($i = 0; $i < 20; $i++) {
        mitAiIndexRun(50);
        if (mitSetting('ai_helper_pass_started', '') === '') {
            break;
        }
    }
};
$kinds = function ($query) {
    return array_map(fn($hit) => $hit['kind'] . ':' . $hit['title'], mitAiSearch($query, ['limit' => 8]));
};
$has_document = function ($hits) use (&$document_name) {
    return in_array('document:' . $document_name, $hits, true);
};
try {
    foreach (['ai_helper_enabled' => '1', 'ai_helper_internal' => '1', 'ai_helper_documents' => '1', 'ai_helper_embed_provider' => '0'] as $name => $value) {
        mitSetSetting($name, $value);
    }
    mitAiIndexReset();
    mitSetSetting('ai_helper_index_version', mitAiIndexVersion());
    // Twenty tickets that say "plotter queue stuck" over and over, one document that says it once
    for ($i = 1; $i <= 20; $i++) {
        $t = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => "ZZ plotter queue stuck $i $stamp",
            'ticket_details' => str_repeat('Plotter queue stuck again, plotter queue stuck, jobs sit in the plotter queue. ', 3)]);
        mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $t");
    }
    $document_name = "ZZ Plotter runbook $stamp";
    $body = '<h2>Clearing the plotter</h2><p>When the plotter queue is stuck: stop the Print Spooler, delete C:\\Windows\\System32\\spool\\PRINTERS\\*, start the spooler, '
        . 'then power-cycle the plotter. DOCMARKER</p>' . str_repeat('<p>Other notes about paper rolls and ink cartridges for the wide format device.</p>', 6);
    mysqli_query($mysqli, "INSERT INTO documents SET document_name = '" . escapeSql($document_name) . "', document_content = '" . mysqli_real_escape_string($mysqli, $body) . "',
        document_content_raw = '', document_client_id = $client_id");
    $document_id = mysqli_insert_id($mysqli);
    $run();

    echo "keyword search\n";
    scenarioCheck('the keyword index finds what is in the library (hourly self-check)', mitAiKeywordIndexOk(), true);
    $hits = $kinds('plotter queue stuck');
    scenarioCheck('the document is among the passages, though 20 tickets match better', $has_document($hits), true);
    scenarioCheck('... and tickets too', count(array_filter($hits, fn($h) => str_starts_with($h, 'ticket:'))) >= 4, true);
    scenarioCheck('a question it has nothing on brings no document', $has_document($kinds('exchange mailbox quota')), false);

    $mock = 'http://127.0.0.1/ai-mock/index.php';
    if (@file_get_contents("$mock/_clear") !== false) {
        echo "by meaning\n";
        mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_name LIKE 'ZZ %stand-in%'");
        mysqli_query($mysqli, "INSERT INTO ai_providers SET ai_provider_name = 'ZZ Docs stand-in $stamp', ai_provider_kind = 'custom', ai_provider_api_url = '$mock/v1/chat/completions', ai_provider_api_key = 'mock', ai_provider_redact = 1");
        $provider_id = mysqli_insert_id($mysqli);
        mitSetSetting('ai_helper_embed_provider', $provider_id);
        mitSetSetting('ai_helper_embed_model', 'mock-embed');
        for ($i = 0; $i < 10 && mitAiEmbedPending(30) !== ''; $i++) {
        }
        $hits = $kinds('plotter queue stuck');
        scenarioCheck('the document is still there with meaning search on', $has_document($hits), true);
    } else {
        echo "  (no AI stand-in - meaning search not checked)\n";
    }

    echo "archived\n";
    mysqli_query($mysqli, "UPDATE documents SET document_archived_at = NOW(), document_updated_at = document_updated_at WHERE document_id = $document_id");
    mitSetSetting('ai_helper_pass_started', '');
    mitAiIndexRun(50); // a normal run, not a full pass: ITFlow's archive leaves the updated time alone
    scenarioCheck('an archived document leaves the library', intval(scenarioValue("SELECT COUNT(*) FROM mit_ai_sources WHERE document_id = $document_id")), 0);
    scenarioCheck('... and the answers', $has_document($kinds('plotter queue stuck')), false);
} finally {
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
