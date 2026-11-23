<?php
/*
 * Cron health is ITFlow's own since 26.12 (functions/cron_health.php, /health.php, the Cron Watch job). Ours on top of it
 * (functions/mit_cron_health.php): the Microsoft 365 certificate our app signs in with is one of ITFlow's problems when its renewal
 * failed (mitCronExtraProblems, hooked at the end of cronHealthProblems) - so /health.php reports it -, /mit_health.php (the address our
 * monitors use) answers exactly as /health.php, and the top bar's warning (mitCronNavHtml) lists the problems for admins.
 * Puts the renewal error back as it was.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitM365App') || !mitM365App()) {
    echo "  skip - no Microsoft 365 app on this install (tests/mocks ms stand-in sets one up)\n";
    exit(0);
}
$saved = mitSetting('m365_renew_error');
$key = (string) scenarioValue("SELECT config_cron_health_key FROM settings WHERE company_id = 1");
$get = function ($path, $with_key = true) use ($key, $config_base_url) {
    $out = shell_exec('curl -s -w "\n%{http_code}" -H ' . escapeshellarg("Host: $config_base_url") . ' ' . escapeshellarg("http://127.0.0.1/$path" . ($with_key ? "?key=$key" : '')));
    $code = substr(trim((string) $out), -3);
    return [$code, trim(substr(trim((string) $out), 0, -3))];
};
try {
    mitDeleteSetting('m365_renew_error');
    $keys = array_column(cronHealthProblems(true), 'key');
    scenarioCheck('control - a healthy certificate is no problem', in_array('m365_certificate', $keys, true), false);
    [$code, $body] = $get('mit_health.php');
    scenarioCheck('control - /mit_health.php does not name Microsoft 365', stripos($body, 'Microsoft 365') === false, true);

    mitSetSetting('m365_renew_error', 'ZZ scenario renewal refused');
    $problems = cronHealthProblems(true);
    $mine = array_values(array_filter($problems, function ($p) { return $p['key'] === 'm365_certificate'; }));
    scenarioCheck('a failed renewal is one of ITFlow\'s cron problems', count($mine), 1);
    scenarioCheck('... with the error as its detail', ($mine[0]['detail'] ?? ''), 'ZZ scenario renewal refused');

    [$code, $body] = $get('health.php');
    [$mcode, $mbody] = $get('mit_health.php');
    scenarioCheck('/health.php answers 503 naming Microsoft 365', [$code, stripos($body, 'Microsoft 365') !== false], ['503', true]);
    scenarioCheck('/mit_health.php answers the same', [$mcode, $mbody], [$code, $body]);
    [$code] = $get('mit_health.php', false);
    scenarioCheck('/mit_health.php without the key is refused like /health.php', $code, $get('health.php', false)[0]);

    scenarioCheck('... and still there when the request asks again (ITFlow keeps its answer)', in_array('m365_certificate', array_column(cronHealthProblems(), 'key'), true), true);
    $GLOBALS['session_is_admin'] = true;
    $html = mitCronNavHtml();
    scenarioCheck('top bar: admins see the warning with the certificate', strpos($html, 'id=\'mitCronHealth\'') !== false && strpos($html, 'Microsoft 365 certificate') !== false, true);
    scenarioCheck('top bar: its count is ITFlow\'s problems', (bool) preg_match('/navbar-badge\'>' . count($problems) . '</', $html), true);
    $GLOBALS['session_is_admin'] = false;
    scenarioCheck('top bar: nothing for an agent who is not an admin', mitCronNavHtml(), '');
    $GLOBALS['session_is_admin'] = true;
} finally {
    $saved === null ? mitDeleteSetting('m365_renew_error') : mitSetSetting('m365_renew_error', $saved);
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
