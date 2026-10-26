<?php
/*
 * Mountaineer IT - scenario checks: our code worked through against ITFlow's own functions on the TEST install (never production -
 * they write rows, then remove them). Each scenario-*.php file is one story; run them all with: php tests/scenarios/run.php [install]
 */
if (php_sapi_name() !== 'cli') {
    die("Run this from the command line.\n");
}
$root = $argv[1] ?? (getenv('ITFLOW_ROOT') ?: '/var/www/itflow');
chdir($root);
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/load_global_settings.php';
if (function_exists('mitDevMode') && !mitDevMode() && empty(getenv('MIT_SCENARIOS_ANYWAY')) && !preg_match('/\.test$/', (string) ($config_base_url ?? ''))) {
    die("Not a test install (no development mode, base URL not *.test) - scenarios write rows. Set MIT_SCENARIOS_ANYWAY=1 to run anyway.\n");
}
// An admin signed in, for functions that read the session
$session_user_id = $GLOBALS['session_user_id'] = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_id LIMIT 1"))[0]);
$session_is_admin = $GLOBALS['session_is_admin'] = true;
$session_name = $GLOBALS['session_name'] = 'Scenario check';
$GLOBALS['mit_scenario_failed'] = 0;

/** One expectation: prints ok / FAIL. */
function scenarioCheck($label, $got, $want) {
    $ok = is_float($want) || is_float($got) ? abs((float) $got - (float) $want) < 0.005 : $got === $want;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' - got ' . var_export($got, true) . ', want ' . var_export($want, true)) . "\n";
    if (!$ok) {
        $GLOBALS['mit_scenario_failed']++;
    }
}

/** The first active client. */
function scenarioClient() {
    global $mysqli;
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1"))[0] ?? 0);
}
