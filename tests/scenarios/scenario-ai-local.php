<?php
/*
 * Local embeddings (functions/mit_ai_local.php) against the test install's Ollama stand-in (ollama-mock/index.php, MIT_OLLAMA_BASE in its
 * config.php - skipped without it): not running = refused with the install command and nothing changed; running without the model =
 * the model is pulled, ITFlow's Ollama provider made, meaning search switched over (what was set before kept) and the library re-done
 * with the new model; on again changes nothing; off goes back to the provider / model from before.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitAiLocalTurnOn') || !defined('MIT_OLLAMA_BASE')) {
    echo "  skip - no local embeddings code or no Ollama stand-in on this test install\n";
    exit(0);
}
$mock = MIT_OLLAMA_BASE;
$stamp = date('His');
$names = ['ai_helper_enabled', 'ai_helper_embed_provider', 'ai_helper_embed_model', 'ai_local_on', 'ai_local_provider', 'ai_local_prev_provider', 'ai_local_prev_model', 'ai_helper_index_version'];
$saved = [];
foreach ($names as $name) {
    $saved[$name] = mitSetting($name);
}
$calls = function () use ($mock) {
    return json_decode((string) @file_get_contents("$mock/_calls"), true) ?: [];
};
$made = [];
[$client_id, $contact_id] = scenarioMakeClient("ZZ AI local $stamp");
try {
    mysqli_query($mysqli, "INSERT INTO ai_providers SET ai_provider_name = 'ZZ Cloud stand-in $stamp', ai_provider_kind = 'custom', ai_provider_api_url = 'http://127.0.0.1/ai-mock/index.php/v1/chat/completions', ai_provider_api_key = 'mock'");
    $cloud = mysqli_insert_id($mysqli);
    $made[] = $cloud;
    foreach (['ai_helper_enabled' => '1', 'ai_helper_embed_provider' => $cloud, 'ai_helper_embed_model' => 'mock-embed', 'ai_local_on' => '0', 'ai_local_provider' => '0'] as $name => $value) {
        mitSetSetting($name, $value);
    }
    $t = scenarioMakeTicket($client_id, $contact_id, ['ticket_subject' => "ZZ local embeddings $stamp", 'ticket_details' => 'Scanner will not send to email.']);
    mysqli_query($mysqli, "UPDATE tickets SET ticket_resolved_at = NOW() WHERE ticket_id = $t");
    mitAiIndexReset();
    mitSetSetting('ai_helper_index_version', mitAiIndexVersion());
    for ($i = 0; $i < 20 && mitSetting('ai_helper_since', '') === ''; $i++) {
        mitAiIndexRun(50);
    }

    echo "not running\n";
    @file_get_contents("$mock/_state?reset=1&running=0");
    $status = mitAiLocalStatus();
    scenarioCheck('seen as not running', [$status['running'], $status['model'], $status['on']], [false, false, false]);
    [$ok, $message] = mitAiLocalTurnOn();
    scenarioCheck('switching on is refused, with the install command', [$ok, strpos($message, MIT_AI_LOCAL_INSTALL) !== false], [false, true]);
    scenarioCheck('... and nothing changed', [intval(mitSetting('ai_helper_embed_provider')), mitSetting('ai_helper_embed_model'), mitSetting('ai_local_on')], [$cloud, 'mock-embed', '0']);

    echo "running, no model yet\n";
    @file_get_contents("$mock/_state?reset=1");
    @file_get_contents("$mock/_clear");
    $status = mitAiLocalStatus();
    scenarioCheck('running, model missing', [$status['running'], $status['model']], [true, false]);
    [$ok, $message] = mitAiLocalTurnOn();
    scenarioCheck('switched on', $ok, true);
    $pulls = array_values(array_filter($calls(), fn($c) => str_ends_with($c['path'], '/api/pull')));
    scenarioCheck('the model was pulled through Ollama', $pulls[0]['body']['model'] ?? '', MIT_AI_LOCAL_MODEL);
    $local = intval(mitSetting('ai_local_provider'));
    $made[] = $local;
    $row = aiProviderRow($local) ?: [];
    scenarioCheck("ITFlow's Ollama provider made", [$row['ai_provider_kind'] ?? '', $row['ai_provider_name'] ?? '', $row['ai_provider_api_url'] ?? ''],
        ['ollama', MIT_AI_LOCAL_PROVIDER_NAME, $mock . '/v1/chat/completions']);
    scenarioCheck('meaning search points at it', [intval(mitSetting('ai_helper_embed_provider')), mitSetting('ai_helper_embed_model'), mitAiLocalOn()], [$local, MIT_AI_LOCAL_MODEL, true]);
    scenarioCheck('what was set before is kept', [intval(mitSetting('ai_local_prev_provider')), mitSetting('ai_local_prev_model')], [$cloud, 'mock-embed']);

    echo "the library follows\n";
    for ($i = 0; $i < 10 && mitAiEmbedPending(30) !== ''; $i++) {
    }
    $stats = mitAiIndexStats();
    scenarioCheck('every passage re-done with the local model', [$stats['embedded_current'], $stats['chunks'] > 0], [$stats['chunks'], true]);
    $titles = array_map(fn($h) => $h['title'], mitAiSearch('scanner will not send to email'));
    scenarioCheck('search finds it', count(array_filter($titles, fn($title) => strpos($title, "ZZ local embeddings $stamp") !== false)), 1);
    scenarioCheck('the embeddings came from the local stand-in', count(array_filter($calls(), fn($c) => str_ends_with($c['path'], '/v1/embeddings'))) > 0, true);

    echo "on again\n";
    [$ok] = mitAiLocalTurnOn();
    scenarioCheck('still on, same provider, before-settings untouched', [$ok, intval(mitSetting('ai_local_provider')), intval(mitSetting('ai_local_prev_provider'))], [true, $local, $cloud]);
    scenarioCheck('no second provider', intval(scenarioValue("SELECT COUNT(*) FROM ai_providers WHERE ai_provider_name = '" . MIT_AI_LOCAL_PROVIDER_NAME . "'")), 1);

    echo "off\n";
    [$ok] = mitAiLocalTurnOff();
    scenarioCheck('back to the provider / model from before', [$ok, intval(mitSetting('ai_helper_embed_provider')), mitSetting('ai_helper_embed_model'), mitAiLocalOn()], [true, $cloud, 'mock-embed', false]);
    mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_id = $cloud");
    mitAiLocalTurnOn();
    mitAiLocalTurnOff();
    scenarioCheck('the one before gone: keyword search only', intval(mitSetting('ai_helper_embed_provider')), 0);
} finally {
    scenarioCleanup();
    foreach ($made as $id) {
        mysqli_query($mysqli, "DELETE FROM ai_usage WHERE ai_usage_provider_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM ai_providers WHERE ai_provider_id = " . intval($id));
    }
    foreach ($saved as $name => $value) {
        mitSetSetting($name, $value ?? '');
    }
    mysqli_query($mysqli, "DELETE FROM mit_ai_sources");
    @file_get_contents("$mock/_state?reset=1");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
