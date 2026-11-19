<?php
/*
 * Mountaineer IT - set NinjaOne up on a TEST install against the stand-in (run by start.sh from tests/mocks/install.php):
 *   php tests/mocks/ninja/setup.php /var/www/itflow
 * Only fills what is missing, so a second run changes nothing a test set: the API keys (ninja-client / ninja-secret) when none are
 * saved, a client "Acme Ninja Co" for the stand-in's organization 1 "Acme Ninja", organization 2 not synced and 3 not synced
 * (22-ninjaone-sync sets 3 to "not mapped" for its run and puts it back). The first sync (that test, or Run sync now) makes the
 * assets. Refuses an install that is not *.test.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$root = rtrim($argv[1] ?? '/var/www/itflow', '/');
chdir($root);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'ninja stand-in setup';
require_once 'config.php';
if (!preg_match('/\.test$/', (string) ($config_base_url ?? ''))) {
    fwrite(STDERR, "Not a test install - NinjaOne left alone.\n");
    exit(1);
}
require_once 'functions.php';
require_once 'includes/load_global_settings.php';
$session_user_id = 1;
$session_name = 'NinjaOne stand-in setup';
if (!function_exists('mitNinjaReady') || !mitNinjaReady()) {
    fwrite(STDERR, "NinjaOne's database update has not run here - nothing set up.\n");
    exit(1);
}
if ((string) mitSetting('ninja_client_id', '') === '') {
    mitSetSetting('ninja_client_id', 'ninja-client');
    mitSetSetting('ninja_client_secret', mitEncryptSecret('ninja-secret'));
    mitSetSetting('ninja_token', '');
    mitSetSetting('ninja_token_expires', 0);
    mitSetSetting('ninja_brake', 100);
    echo "    NinjaOne API keys saved\n";
}
$client_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_name = 'Acme Ninja Co' AND client_archived_at IS NULL LIMIT 1"))[0] ?? 0);
if (!$client_id) {
    mysqli_query($mysqli, "INSERT INTO clients SET client_name = 'Acme Ninja Co', client_currency_code = 'USD'");
    $client_id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO locations SET location_name = 'Main', location_primary = 1, location_client_id = $client_id");
    echo "    client Acme Ninja Co (#$client_id)\n";
}
foreach ([1 => ['Acme Ninja', "client_id = $client_id, status = 'mapped'"], 2 => ['Beta Ninja', "client_id = NULL, status = 'ignored'"],
    3 => ['Gamma Ninja', "client_id = NULL, status = 'ignored'"]] as $org_id => [$name, $set]) {
    mysqli_query($mysqli, "INSERT IGNORE INTO mit_ninja_orgs SET org_id = $org_id, name = '$name', $set, updated_by = 1, updated_at = NOW()");
}
// Organization 1 always on the stand-in client (another test may have moved it)
mysqli_query($mysqli, "UPDATE mit_ninja_orgs SET client_id = $client_id, status = 'mapped' WHERE org_id = 1 AND (status <> 'mapped' OR client_id IS NULL
    OR client_id NOT IN (SELECT client_id FROM clients WHERE client_archived_at IS NULL))");
