<?php
/*
 * API keys are taken every way a caller sends them (api/v1/validate_api_key.php): ITFlow's own - ?api_key= in the address (what
 * tools written for stock ITFlow use for reads, MSP Align among them - refusing it in 26.10.73 broke them) and api_key in a POST
 * body (read.php files take GET only) - and ours, the X-API-Key / Authorization: Bearer headers. A wrong key is still refused, and no key at all is a 401.
 */
require __DIR__ . '/bootstrap.php';
$api_host = (string) $GLOBALS['config_base_url'];
$call = function ($method, $path, array $headers = [], $body = null) use ($api_host) {
    $ch = curl_init("http://$api_host/api/v1/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$api_host:80:127.0.0.1"], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => array_merge($headers, ['Content-Type: application/json']), CURLOPT_USERAGENT => 'scenario', CURLOPT_TIMEOUT => 20]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $text = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode($text, true)];
};
$key = 'zzscen' . bin2hex(random_bytes(12));
$admin = intval(scenarioValue("SELECT user_id FROM users JOIN user_roles ON role_id = user_role_id WHERE role_is_admin = 1 AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_id LIMIT 1"));
mysqli_query($mysqli, "INSERT INTO api_keys SET api_key_name = 'ZZ scen key ways', api_key_secret = '$key', api_key_decrypt_hash = '', api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = $admin");
$key_id = mysqli_insert_id($mysqli);
try {
    $read_ok = function ($label, $result) {
        scenarioCheck("$label: 200", $result[0], 200);
        scenarioCheck("$label: success", $result[1]['success'] ?? null, 'True');
    };
    echo "reads\n";
    $read_ok('key in the address (stock ITFlow, MSP Align)', $call('GET', "clients/read.php?api_key=$key&limit=1&offset=0"));
    $read_ok('X-API-Key header', $call('GET', 'clients/read.php?limit=1', ["X-API-Key: $key"]));
    $read_ok('Authorization: Bearer', $call('GET', 'clients/read.php?limit=1', ["Authorization: Bearer $key"]));

    echo "refusals\n";
    scenarioCheck('wrong key in the address: 401', $call('GET', 'clients/read.php?api_key=zz-not-a-key')[0], 401);
    scenarioCheck('wrong key in a header: 401', $call('GET', 'clients/read.php', ['X-API-Key: zz-not-a-key'])[0], 401);
    scenarioCheck('no key: 401', $call('GET', 'clients/read.php')[0], 401);
} finally {
    mysqli_query($mysqli, "DELETE FROM api_key_usage WHERE api_key_usage_key_id = $key_id");
    mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id = $key_id");
    mysqli_query($mysqli, "DELETE FROM logs WHERE log_type = 'API' AND log_user_agent = 'scenario' AND log_description LIKE 'Unknown API key%'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
