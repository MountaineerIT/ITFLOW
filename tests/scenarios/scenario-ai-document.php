<?php
/*
 * Write up (functions/mit_ai_docs.php mitAiDocumentDraft), against the test install's AI stand-in (ai-mock/index.php - skipped without
 * it; for a document it answers HTML with a <script> and a password in it on purpose): a conversation about a ticket is drafted for
 * the ticket's client with the conversation and the full text of the tickets it cited - and of no client the agent can't open -, no
 * secret is sent, the draft comes back purified and cleaned (no script, no password), split into title / summary / body; only the
 * owner can draft it; the company's own client writes it as a general article; the monthly limit applies.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitAiDocumentDraft') || !mitAiAskReady()) {
    echo "  skip - no AI helper round 3\n";
    exit(0);
}
$mock = 'http://127.0.0.1/ai-mock/index.php';
if (@file_get_contents("$mock/_clear") === false) {
    echo "  skip - no AI stand-in on this test install\n";
    exit(0);
}
$stamp = date('His');
$saved = [];
foreach (['ai_helper_enabled', 'ai_helper_internal', 'ai_helper_documents', 'ai_helper_embed_provider', 'ai_helper_embed_model', 'ai_helper_monthly_tokens'] as $name) {
    $saved[$name] = mitSetting($name);
}
$admin = $GLOBALS['session_user_id'];
$tech = intval(scenarioValue("SELECT user_id FROM users WHERE user_role_id = 2 AND user_archived_at IS NULL ORDER BY user_id LIMIT 1"));
[$client_a, $contact_a] = scenarioMakeClient("ZZ Doc A $stamp");
[$client_b, $contact_b] = scenarioMakeClient("ZZ Doc B $stamp");
$own_was = $config_internal_client_id ?? 0;
$sent = function () use ($mock) {
    $calls = json_decode((string) @file_get_contents("$mock/_calls"), true) ?: [];
    $chats = array_values(array_filter($calls, fn($c) => str_ends_with($c['path'], '/chat/completions')));
    return $chats ? json_encode(end($chats)['body']) : '';
};
try {
    mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_name LIKE 'ZZ %stand-in%'");
    mysqli_query($mysqli, "INSERT INTO ai_providers SET ai_provider_name = 'ZZ Doc stand-in $stamp', ai_provider_kind = 'custom', ai_provider_api_url = '$mock/v1/chat/completions', ai_provider_api_key = 'mock', ai_provider_redact = 1");
    $provider_id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO ai_models SET ai_model_name = 'mock-chat', ai_model_use_case = 'General', ai_model_ai_provider_id = $provider_id");
    foreach (['ai_helper_enabled' => '1', 'ai_helper_internal' => '1', 'ai_helper_documents' => '1', 'ai_helper_embed_provider' => $provider_id,
        'ai_helper_embed_model' => 'mock-embed', 'ai_helper_monthly_tokens' => '0'] as $name => $value) {
        mitSetSetting($name, $value);
    }
    $reply = function ($ticket_id, $words) {
        global $mysqli;
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '<p>" . mysqli_real_escape_string($mysqli, $words) . "</p>', ticket_reply_type = 'Internal', ticket_reply_by = 1, ticket_reply_ticket_id = $ticket_id");
    };
    $fixed = scenarioMakeTicket($client_a, $contact_a, ['ticket_subject' => "ZZ scenario ticket - plotter offline $stamp", 'ticket_details' => 'Plotter shows offline for everyone.']);
    $reply($fixed, 'Fixed: the plotter had a new DHCP lease; set a reservation and re-added the port. FULLTEXTMARKER plotter port re-added. Plotter web login password is canaryplotzz');
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $fixed");
    $fixed_b = scenarioMakeTicket($client_b, $contact_b, ['ticket_subject' => "ZZ scenario ticket - plotter offline at B $stamp", 'ticket_details' => 'Plotter offline at client B.']);
    $reply($fixed_b, 'Client B plotter fix: BMARKER replaced the network cable.');
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $fixed_b");
    $open = scenarioMakeTicket($client_a, $contact_a, ['ticket_subject' => "ZZ scenario ticket - plotter offline again $stamp", 'ticket_details' => 'Plotter offline again in the drawing office.']);
    mitAiIndexReset();
    mitSetSetting('ai_helper_index_version', mitAiIndexVersion());
    for ($i = 0; $i < 20 && mitSetting('ai_helper_since', '') === ''; $i++) {
        mitAiIndexRun(50);
    }
    mitAiEmbedPending(30);

    [$ok, $conversation_id] = mitAiAsk(0, 'Plotter offline - how did we fix it before?', $open);
    scenarioCheck('a conversation to write up', $ok, true);
    $conversation = mitAiConversation($conversation_id);
    scenarioCheck('its default client is the ticket\'s', mitAiDocumentDefaultClient($conversation), $client_a);

    echo "draft for the ticket's client\n";
    @file_get_contents("$mock/_clear");
    $draft = mitAiDocumentDraft($conversation_id, $client_a);
    $body = $sent();
    scenarioCheck('drafted', $draft['ok'] ?? false, true);
    scenarioCheck('the AI got the conversation and the cited ticket in full', [strpos($body, 'Plotter offline - how did we fix it before?') !== false, strpos($body, 'FULLTEXTMARKER') !== false], [true, true]);
    scenarioCheck('... written for that client', strpos($body, "ZZ Doc A $stamp") !== false, true);
    scenarioCheck('... and no password', stripos($body, 'canaryplotzz') === false, true);
    scenarioCheck('title and summary come off the top', [$draft['title'] ?? '', $draft['description'] ?? ''], ['Mock article', 'Mock summary of the fix.']);
    scenarioCheck('the script is gone', stripos($draft['html'] ?? '<script', '<script') === false, true);
    scenarioCheck('the password the AI wrote is gone', strpos($draft['html'] ?? 'MockDocLeak9', 'MockDocLeak9') === false, true);
    scenarioCheck('the rest of the steps stay', strpos($draft['html'] ?? '', 'Do the first step from the material.') !== false, true);
    scenarioCheck('counted as taken out', ($draft['removed'] ?? 0) >= 1, true);

    if ($own_was || true) {
        echo "the company's own\n";
        $GLOBALS['config_internal_client_id'] = $client_b; // stands in for the company's own client record
        @file_get_contents("$mock/_clear");
        $draft2 = mitAiDocumentDraft($conversation_id, $client_b);
        scenarioCheck('written as a general article', [$draft2['ok'] ?? false, strpos($sent(), 'general, so any technician can follow it') !== false], [true, true]);
        $GLOBALS['config_internal_client_id'] = $own_was;
    }

    if ($tech) {
        echo "someone else\n";
        $GLOBALS['session_user_id'] = $tech;
        $GLOBALS['session_is_admin'] = false;
        $GLOBALS['session_user_role'] = 2;
        $GLOBALS['client_access_array'] = [$client_b];
        $GLOBALS['client_deny_array'] = [];
        scenarioCheck('another agent can\'t draft it', mitAiDocumentDraft($conversation_id, $client_b)['ok'] ?? true, false);
        [$ok_t, $tech_conv] = mitAiAsk(0, 'Plotter offline - how did we fix it before?');
        @file_get_contents("$mock/_clear");
        $draft_t = mitAiDocumentDraft($tech_conv, $client_b);
        $body_t = $sent();
        scenarioCheck('limited to client B: drafted from B only', [$draft_t['ok'] ?? false, strpos($body_t, 'FULLTEXTMARKER') === false, strpos($body_t, 'BMARKER') !== false], [true, true, true]);
        scenarioCheck('... and can\'t keep it with client A', mitAiDocumentDraft($tech_conv, $client_a)['ok'] ?? true, false);
        $GLOBALS['session_user_id'] = $admin;
        $GLOBALS['session_is_admin'] = true;
        $GLOBALS['client_access_array'] = [];
    }

    echo "monthly limit\n";
    mitSetSetting('ai_helper_monthly_tokens', '1');
    scenarioCheck('over the limit: refused', mitAiDocumentDraft($conversation_id, $client_a)['ok'] ?? true, false);
    mitSetSetting('ai_helper_monthly_tokens', '0');
} finally {
    $GLOBALS['session_user_id'] = $admin;
    $GLOBALS['session_is_admin'] = true;
    $GLOBALS['client_access_array'] = [];
    $GLOBALS['config_internal_client_id'] = $own_was;
    mysqli_query($mysqli, "DELETE FROM mit_ai_conversations WHERE title LIKE 'Plotter offline%'");
    scenarioCleanup();
    foreach ($saved as $name => $value) {
        mitSetSetting($name, $value ?? '');
    }
    if (!empty($provider_id)) {
        mysqli_query($mysqli, "DELETE FROM ai_usage WHERE ai_usage_provider_id = $provider_id");
        mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_id = $provider_id");
    }
    mysqli_query($mysqli, "DELETE FROM mit_ai_sources");
    mitSetSetting('ai_helper_index_version', '');
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
