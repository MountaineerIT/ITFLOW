<?php
/*
 * The Ask AI panel (functions/mit_ai_ask.php mitAiAsk), against the test install's AI stand-in (ai-mock/index.php - skipped without
 * it): asking about an open ticket sends that ticket and the closest past fixes, cites them, and keeps the conversation; a follow-up
 * carries the earlier turns; nothing secret - a vault row, a password in the open ticket's note, one typed into the question -
 * reaches the AI or is stored; conversations are their owner's only; a technician limited to other clients gets neither the ticket
 * nor its client's passages; thumbs up / down; the monthly token limit.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitAiAskReady') || !mitAiAskReady()) {
    echo "  skip - M045 not run\n";
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
[$client_a, $contact_a] = scenarioMakeClient("ZZ Ask A $stamp");
[$client_b, $contact_b] = scenarioMakeClient("ZZ Ask B $stamp");
$credential_id = 0;
$canaries = ['CANARY-VAULT-ask', 'canaryopenzz', 'CanaryAskQ9', 'canaryfixzz'];
$calls = function () use ($mock) {
    return json_decode((string) @file_get_contents("$mock/_calls"), true) ?: [];
};
$chat = function () use ($calls) {
    $chats = array_values(array_filter($calls(), fn($c) => str_ends_with($c['path'], '/chat/completions')));
    return $chats ? end($chats)['body'] : null;
};
$leaks = function ($text) use ($canaries) {
    return array_values(array_filter($canaries, fn($c) => stripos((string) $text, $c) !== false));
};
$as = function ($user_id, $admin_flag, $access = [], $role = 1) {
    $GLOBALS['session_user_id'] = $user_id;
    $GLOBALS['session_is_admin'] = $admin_flag;
    $GLOBALS['session_user_role'] = $role;
    $GLOBALS['client_access_array'] = $access;
    $GLOBALS['client_deny_array'] = [];
};
try {
    mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_name LIKE 'ZZ %stand-in%'"); // left by a run that stopped half way
    mysqli_query($mysqli, "INSERT INTO ai_providers SET ai_provider_name = 'ZZ Ask stand-in $stamp', ai_provider_kind = 'custom', ai_provider_api_url = '$mock/v1/chat/completions', ai_provider_api_key = 'mock', ai_provider_redact = 1"); // as ITFlow saves it (aiProviderUrl)
    $provider_id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO ai_models SET ai_model_name = 'mock-chat', ai_model_use_case = 'Tickets', ai_model_ai_provider_id = $provider_id");
    mitSetSetting('ai_helper_enabled', '1');
    mitSetSetting('ai_helper_internal', '1');
    mitSetSetting('ai_helper_documents', '1');
    mitSetSetting('ai_helper_embed_provider', $provider_id);
    mitSetSetting('ai_helper_embed_model', 'mock-embed');
    mitSetSetting('ai_helper_monthly_tokens', '0');

    $reply = function ($ticket_id, $type, $words) {
        global $mysqli;
        mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '<p>" . mysqli_real_escape_string($mysqli, $words) . "</p>', ticket_reply_type = '$type', ticket_reply_by = 1, ticket_reply_ticket_id = $ticket_id");
    };
    mysqli_query($mysqli, "INSERT INTO credentials SET credential_name = 'ZZ Ask vault', credential_username = 'u', credential_password = 'CANARY-VAULT-ask', credential_client_id = $client_a");
    $credential_id = mysqli_insert_id($mysqli);
    $fixed = scenarioMakeTicket($client_a, $contact_a, ['ticket_subject' => "ZZ scenario ticket - Authenticator prompt loop $stamp", 'ticket_details' => 'The MFA prompt keeps looping on the phone.']);
    $reply($fixed, 'Internal', 'Fixed: re-registered the Authenticator app and cleared the token cache, prompt loop gone. The tenant admin pw is canaryfixzz');
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $fixed");
    $fixed_b = scenarioMakeTicket($client_b, $contact_b, ['ticket_subject' => "ZZ scenario ticket - Authenticator prompt loop at B $stamp", 'ticket_details' => 'MFA prompt looping for client B.']);
    $reply($fixed_b, 'Internal', 'Client B fix: re-registered the Authenticator app.');
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $fixed_b");
    $open = scenarioMakeTicket($client_a, $contact_a, ['ticket_subject' => "ZZ scenario ticket - MFA prompts keep looping $stamp", 'ticket_details' => 'Sales laptop: Outlook MFA prompt loops.']);
    $reply($open, 'Internal', 'Tried a reboot. Local admin pw is canaryopenzz');

    mitAiIndexReset();
    mitSetSetting('ai_helper_index_version', mitAiIndexVersion());
    for ($i = 0; $i < 20 && mitSetting('ai_helper_since', '') === ''; $i++) {
        mitAiIndexRun(50);
    }
    mitAiEmbedPending(30);
    @file_get_contents("$mock/_clear");

    echo "ask about the open ticket\n";
    [$ok, $conversation_id, $out] = mitAiAsk(0, 'How do I fix this? The password is CanaryAskQ9!', $open);
    scenarioCheck('answered', [$ok, count((array) $out)], [true, 2]);
    $answer = $ok ? $out[1] : [];
    scenarioCheck('the conversation is about the ticket', scenarioValue("SELECT ticket_id FROM mit_ai_conversations WHERE conversation_id = " . intval($conversation_id)), (string) $open);
    $sent = $chat();
    $sent_text = json_encode($sent);
    scenarioCheck('the AI got the open ticket', strpos($sent_text, 'Outlook MFA prompt loops') !== false, true);
    scenarioCheck('... and the past fix', strpos($sent_text, 're-registered the Authenticator app') !== false, true);
    scenarioCheck('... with the rules about passwords', strpos($sent_text, 'password vault') !== false, true);
    scenarioCheck('... and no secret: not the vault, not the notes, not the question\'s', $leaks($sent_text), []);
    scenarioCheck('the answer cites the fix, linked', count(array_filter($answer['sources'] ?? [], fn($s) => strpos($s['url'], "ticket_id=$fixed") !== false)) === 1
        && strpos($answer['html'] ?? '', 'class="mit-ai-cite"') !== false, true);
    scenarioCheck('the open ticket itself is not one of its own sources', count(array_filter($answer['sources'] ?? [], fn($s) => strpos($s['url'], "ticket_id=$open") !== false)), 0);
    scenarioCheck('the question was stored cleaned', $leaks(implode("\n", scenarioColumn("SELECT content FROM mit_ai_messages WHERE conversation_id = " . intval($conversation_id)))), []);
    scenarioCheck('the answer is safe HTML (no tags from the model)', strpos($answer['html'] ?? '', '<script') === false, true);

    echo "follow-up\n";
    [$ok2] = mitAiAsk($conversation_id, 'And if that does not work?');
    $sent2 = $chat();
    scenarioCheck('the earlier turns go with it', [$ok2, count($sent2['messages'] ?? []) >= 4, strpos(json_encode($sent2), 'How do I fix this') !== false], [true, true, true]);
    scenarioCheck('four messages kept', scenarioValue("SELECT COUNT(*) FROM mit_ai_messages WHERE conversation_id = " . intval($conversation_id)), '4');

    echo "feedback\n";
    scenarioCheck('thumbs up kept', [mitAiFeedback($answer['id'], 1), scenarioValue("SELECT feedback FROM mit_ai_messages WHERE message_id = " . intval($answer['id']))], [true, '1']);
    mitAiFeedback($answer['id'], 0);
    scenarioCheck('and taken back', scenarioValue("SELECT feedback FROM mit_ai_messages WHERE message_id = " . intval($answer['id'])), null);

    echo "someone else\n";
    if ($tech) {
        $as($tech, false, [$client_b], 2);
        scenarioCheck('another agent can\'t open it', mitAiConversation($conversation_id), null);
        scenarioCheck('... nor add to it', mitAiAsk($conversation_id, 'hello')[0], false);
        scenarioCheck('... nor rate or delete it', [mitAiFeedback($answer['id'], -1), mitAiDeleteConversation($conversation_id)], [false, false]);
        scenarioCheck('their list does not have it', in_array($conversation_id, array_column(mitAiConversations(), 'id'), true), false);
        @file_get_contents("$mock/_clear");
        [$ok3, $tech_conversation, $out3] = mitAiAsk(0, 'Authenticator prompt loop - how to fix?', $open);
        $sent3 = json_encode($chat());
        scenarioCheck('limited to client B: answered, but not about client A\'s ticket', [$ok3, scenarioValue("SELECT ticket_id FROM mit_ai_conversations WHERE conversation_id = " . intval($tech_conversation))], [true, null]);
        scenarioCheck('... nothing of client A was sent', [strpos($sent3, 'Outlook MFA prompt loops') === false, strpos($sent3, "Ask A $stamp") === false], [true, true]);
        scenarioCheck('... client B\'s fix was', strpos($sent3, 'Client B fix') !== false, true);
        $as($admin, true);
    }

    echo "monthly limit\n";
    mitSetSetting('ai_helper_monthly_tokens', '1');
    [$ok4, , $error] = mitAiAsk(0, 'Anything?');
    scenarioCheck('over the limit: refused, says why', [$ok4, strpos((string) $error, 'token limit') !== false], [false, true]);
    mitSetSetting('ai_helper_monthly_tokens', '0');

    echo "off\n";
    mitSetSetting('ai_helper_enabled', '0');
    scenarioCheck('helper off: refused', mitAiAsk(0, 'Anything?')[0], false);
    mitSetSetting('ai_helper_enabled', '1');

    echo "delete\n";
    scenarioCheck('the owner deletes it, messages go with it', [mitAiDeleteConversation($conversation_id),
        scenarioValue("SELECT COUNT(*) FROM mit_ai_messages WHERE conversation_id = " . intval($conversation_id))], [true, '0']);
} finally {
    $as($admin, true);
    mysqli_query($mysqli, "DELETE FROM mit_ai_conversations WHERE title LIKE '%Authenticator prompt loop%' OR title LIKE 'How do I fix this%' OR title IN ('Anything?', 'hello')");
    if ($credential_id) {
        mysqli_query($mysqli, "DELETE FROM credentials WHERE credential_id = $credential_id");
    }
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
