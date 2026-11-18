<?php
/*
 * The end of paging is not an error (api/v1/read_output.php, 26.11.8.6). Callers that read a list page by page (MSP Align: limit + offset
 * + api_key, every hour) stop at a short or empty page; ITFlow logged every empty page as "Read query failed" in App Logs (thousands of
 * Errors on production). An empty page past the first is now answered as before (success False) but not logged; an empty first page or a
 * record asked for by id that isn't there is still logged.
 */
require __DIR__ . '/bootstrap.php';
$api_host = (string) $GLOBALS['config_base_url'];
$call = function ($path) use ($api_host) {
    $ch = curl_init("http://$api_host/api/v1/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$api_host:80:127.0.0.1"], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_USERAGENT => 'scenario-paging', CURLOPT_TIMEOUT => 20]);
    $text = (string) curl_exec($ch);
    curl_close($ch);
    return json_decode($text, true) ?: [];
};
$logged = function () {
    return intval(scenarioValue("SELECT COUNT(*) FROM app_logs WHERE app_log_category = 'API' AND app_log_type = 'Error' AND app_log_details LIKE '%with agent scenario-paging'"));
};
$key = 'zzscen' . bin2hex(random_bytes(12));
$admin = intval(scenarioValue("SELECT user_id FROM users JOIN user_roles ON role_id = user_role_id WHERE role_is_admin = 1 AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_id LIMIT 1"));
mysqli_query($mysqli, "INSERT INTO api_keys SET api_key_name = 'ZZ scen paging', api_key_secret = '$key', api_key_decrypt_hash = '', api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = $admin");
$key_id = mysqli_insert_id($mysqli);
try {
    $tickets = intval(scenarioValue("SELECT COUNT(*) FROM tickets"));
    $first = $call("tickets/read.php?api_key=$key&limit=5&offset=0");
    scenarioCheck('first page answered', $first['success'] ?? null, $tickets ? 'True' : 'False');

    $before = $logged();
    foreach (['tickets', 'assets', 'clients'] as $resource) {
        $end = $call("$resource/read.php?api_key=$key&limit=50&offset=1000000");
        scenarioCheck("$resource: a page past the end still says no rows", $end['success'] ?? null, 'False');
    }
    scenarioCheck('... and is not logged as an error', $logged() - $before, 0);

    $before = $logged();
    $call("tickets/read.php?api_key=$key&ticket_id=999999999");
    scenarioCheck('a ticket id that is not there is still logged', $logged() - $before, 1);
} finally {
    mysqli_query($mysqli, "DELETE FROM api_key_usage WHERE api_key_usage_key_id = $key_id");
    mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id = $key_id");
    mysqli_query($mysqli, "DELETE FROM app_logs WHERE app_log_details LIKE '%with agent scenario-paging'");
    mysqli_query($mysqli, "DELETE FROM logs WHERE log_user_agent = 'scenario-paging'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
