<?php

/*
 * Mountaineer IT - AI helper: local embeddings on this server (required from functions/mit_ai_helper.php).
 *
 * Ollama, put on the server once by scripts/install_local_ai.sh (as root - the web server can't and shouldn't install software),
 * listening on 127.0.0.1 only. The switch on Admin > Integrations > AI helper checks it is there, pulls the embeddings model through
 * Ollama's own API if it is missing, adds ITFlow's Ollama provider ("This server (Ollama)") and points the helper's meaning search
 * at it - the library re-does its passages with the new model on the next runs (mitAiEmbedPending). Off goes back to what was set
 * before. Nothing leaves the server, no tokens are paid for. The address is fixed (MIT_OLLAMA_BASE only on a test install's
 * config.php, for its stand-in), never typed in, so nothing here can be pointed elsewhere. Settings: mit_settings ai_local_on,
 * ai_local_provider (the provider row it made), ai_local_prev_provider / ai_local_prev_model (what to go back to).
 */

const MIT_AI_LOCAL_MODEL = 'nomic-embed-text';
const MIT_AI_LOCAL_PROVIDER_NAME = 'This server (Ollama)';
const MIT_AI_LOCAL_INSTALL = 'sudo bash /var/www/itflow/scripts/install_local_ai.sh';

/** Ollama's address on this server. */
function mitAiLocalBase() {
    return rtrim(defined('MIT_OLLAMA_BASE') ? (string) MIT_OLLAMA_BASE : 'http://127.0.0.1:11434', '/');
}

/** One call to the local Ollama - [http status, decoded JSON | null]. Never leaves this server. */
function mitAiLocalCall($method, $path, $body = null, $timeout = 5) {
    $ch = curl_init(mitAiLocalBase() . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = $response === false ? 0 : intval(curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
    curl_close($ch);
    return [$status, is_string($response) ? json_decode($response, true) : null];
}

/** Where it stands: running (and its version), model there, switched on, and the provider it uses. */
function mitAiLocalStatus() {
    [$status, $version] = mitAiLocalCall('GET', '/api/version');
    $running = $status === 200 && is_array($version);
    $model = false;
    if ($running) {
        [$status, $tags] = mitAiLocalCall('GET', '/api/tags');
        foreach ((array) ($tags['models'] ?? []) as $row) {
            $name = (string) ($row['name'] ?? $row['model'] ?? '');
            if ($name === MIT_AI_LOCAL_MODEL || $name === MIT_AI_LOCAL_MODEL . ':latest') {
                $model = true;
            }
        }
    }
    return [
        'running' => $running,
        'version' => $running ? (string) ($version['version'] ?? '') : '',
        'model' => $model,
        'on' => mitAiLocalOn(),
    ];
}

/** Is the helper's meaning search on this server's Ollama? (The switch was turned on and the settings still point at it.) */
function mitAiLocalOn() {
    $provider = intval(mitSetting('ai_local_provider', '0'));
    $settings = mitAiHelperSettings();
    return mitSetting('ai_local_on', '0') === '1' && $provider && $settings['embed_provider'] === $provider && $settings['embed_model'] === MIT_AI_LOCAL_MODEL;
}

/** The provider row for this server's Ollama - the one made before if it is still there, else a new one. */
function mitAiLocalProvider() {
    global $mysqli;
    $url = mitAiLocalBase() . '/v1/chat/completions';
    $id = intval(mitSetting('ai_local_provider', '0'));
    if ($id && mysqli_fetch_row(mysqli_query($mysqli, "SELECT ai_provider_id FROM ai_providers WHERE ai_provider_id = $id"))) {
        mysqli_query($mysqli, "UPDATE ai_providers SET ai_provider_kind = 'ollama', ai_provider_api_url = '" . mysqli_real_escape_string($mysqli, $url) . "' WHERE ai_provider_id = $id");
        return $id;
    }
    mysqli_query($mysqli, "INSERT INTO ai_providers SET ai_provider_name = '" . MIT_AI_LOCAL_PROVIDER_NAME . "', ai_provider_kind = 'ollama',
        ai_provider_api_url = '" . mysqli_real_escape_string($mysqli, $url) . "', ai_provider_api_key = ''");
    $id = intval(mysqli_insert_id($mysqli));
    mitSetSetting('ai_local_provider', $id);
    return $id;
}

/**
 * Switch meaning search onto this server's Ollama - [ok, message]. Pulls the model when it is missing (a few minutes the first
 * time - about 270 MB), tests it, and only then changes the settings; what was set before is kept to go back to.
 */
function mitAiLocalTurnOn() {
    $status = mitAiLocalStatus();
    if (!$status['running']) {
        return [false, 'Ollama is not running on this server. Install it once from a root shell: ' . MIT_AI_LOCAL_INSTALL];
    }
    if (!$status['model']) {
        [$code, $pulled] = mitAiLocalCall('POST', '/api/pull', ['model' => MIT_AI_LOCAL_MODEL, 'stream' => false], 900);
        if ($code !== 200 || ($pulled['status'] ?? '') !== 'success') {
            return [false, 'Ollama could not download ' . MIT_AI_LOCAL_MODEL . (is_scalar($pulled['error'] ?? null) ? ': ' . $pulled['error'] : ' (HTTP ' . $code . ')') . '.'];
        }
    }
    $settings = mitAiHelperSettings();
    $provider = mitAiLocalProvider();
    $config = ['provider' => aiProviderRow($provider), 'kind' => 'ollama', 'url' => mitAiLocalBase() . '/v1/embeddings', 'model' => MIT_AI_LOCAL_MODEL];
    [$ok, $vectors] = mitAiEmbed(['Outlook keeps asking the user to sign in again'], $config);
    if (!$ok) {
        return [false, 'Ollama answered, but the embeddings test failed: ' . $vectors];
    }
    if (!mitAiLocalOn()) {
        mitSetSetting('ai_local_prev_provider', $settings['embed_provider']);
        mitSetSetting('ai_local_prev_model', $settings['embed_model']);
    }
    mitSetSetting('ai_helper_embed_provider', $provider);
    mitSetSetting('ai_helper_embed_model', MIT_AI_LOCAL_MODEL);
    mitSetSetting('ai_local_on', '1');
    return [true, 'Meaning search now runs on this server (' . MIT_AI_LOCAL_MODEL . ', ' . count($vectors[0] ?? []) . ' numbers a passage). The library re-does its passages over the next runs - keyword search works meanwhile.'];
}

/** Switch it off: back to the provider / model set before (when that provider is still there), else meaning search off. */
function mitAiLocalTurnOff() {
    $prev = intval(mitSetting('ai_local_prev_provider', '0'));
    $model = (string) mitSetting('ai_local_prev_model', '');
    if ($prev && $prev !== intval(mitSetting('ai_local_provider', '0')) && $model !== '' && aiProviderRow($prev)) {
        mitSetSetting('ai_helper_embed_provider', $prev);
        mitSetSetting('ai_helper_embed_model', $model);
        $back = aiProviderRow($prev)['ai_provider_name'] . ' (' . $model . ')';
    } else {
        mitSetSetting('ai_helper_embed_provider', '0');
        $back = 'keyword search only';
    }
    mitSetSetting('ai_local_on', '0');
    return [true, "Meaning search is back on $back. Ollama stays installed - remove the model with 'ollama rm " . MIT_AI_LOCAL_MODEL . "' if you like."];
}
