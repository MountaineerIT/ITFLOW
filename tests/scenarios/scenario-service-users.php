<?php
/*
 * API / service accounts (functions/mit_service_users.php, 26.11.2.6): a login marked "API / service account" keeps its role, so its
 * API key works, but it is out of the agent lists (Dispatch, assign pickers, @mentions, procurement approvers ...) and ITFlow's API key
 * page still offers it. Unmarked, it is back everywhere.
 */
require __DIR__ . '/bootstrap.php';
if (!mitServiceUsersReady()) {
    echo "  skip - database update M054 not run\n";
    exit(0);
}
$role = intval(scenarioValue("SELECT role_id FROM user_roles WHERE role_is_admin = 1 AND role_archived_at IS NULL ORDER BY role_id LIMIT 1"));
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ Scen API User', user_email = 'zz-scen-api@example.test', user_password = '" . password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT) . "',
    user_type = 1, user_status = 1, user_role_id = $role");
$user = mysqli_insert_id($mysqli);
$key = 'zzsvc' . bin2hex(random_bytes(12));
mysqli_query($mysqli, "INSERT INTO api_keys SET api_key_name = 'ZZ scen service key', api_key_secret = '$key', api_key_decrypt_hash = '', api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = $user");
$key_id = mysqli_insert_id($mysqli);
$api_host = (string) $GLOBALS['config_base_url'];
$read = function () use ($api_host, $key) {
    $ch = curl_init("http://$api_host/api/v1/clients/read.php?limit=1&offset=0");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$api_host:80:127.0.0.1"], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ["X-API-Key: $key"], CURLOPT_TIMEOUT => 20]);
    $text = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode($text, true)];
};
$in = function ($list) use ($user) {
    return isset($list[$user]);
};
try {
    scenarioCheck('an ordinary agent: on Dispatch', $in(mitDispatchTechs()), true);

    $GLOBALS['session_user_id'] = 1;
    $GLOBALS['session_name'] = 'Scenario';
    mitServiceUserSave($user, ['mit_service_present' => 1, 'mit_service_user' => 1]);
    scenarioCheck('marked', mitIsServiceUser($user), true);
    scenarioCheck('off Dispatch', $in(mitDispatchTechs()), false);
    $quick = array_map(function ($o) { return $o['value']; }, quickPickAgents());
    scenarioCheck('off the assign quick picker', in_array((string) $user, $quick, true), false);
    $mention_ids = array_map('intval', array_column(ticketMentionAgents(0), 'id'));
    scenarioCheck('off @mentions', in_array($user, $mention_ids, true), false);
    scenarioCheck('off the admins told about sign-ins', isset(signInAdmins()[$user]), false);
    scenarioCheck('still offered on API Keys', $in(apiKeyAgents()), true);
    $call = $read();
    scenarioCheck('its API key still works: 200', $call[0], 200);
    scenarioCheck('its API key still works: success', $call[1]['success'] ?? null, 'True');
    scenarioCheck('Admin > Users lists it in its own section', strpos(mitServiceUsersSection(), 'ZZ Scen API User') !== false, true);

    mitServiceUserSave($user, ['mit_service_present' => 1]);
    scenarioCheck('unmarked: back on Dispatch', $in(mitDispatchTechs()), true);
    mitServiceUserSave($user, ['mit_service_user' => 1]);
    scenarioCheck('a form without the switch changes nothing', mitIsServiceUser($user), false);
} finally {
    mysqli_query($mysqli, "DELETE FROM api_key_usage WHERE api_key_usage_key_id = $key_id");
    mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id = $key_id");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $user");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
