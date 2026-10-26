<?php
/*
 * Mountaineer IT - daily consistency check (functions/mit_consistency.php): does our data still agree with ITFlow's? Read only.
 * A failed check stops this job with an error (cronJobStop code 1 - recorded as Failed) with what it found, so Cron Watch emails the admins and the top bar shows it;
 * warnings are only written to the job's output. Run it by hand with php scripts/mit_consistency_check.php.
 */

// Set working directory to the directory this cron script lives at.
chdir(dirname(__FILE__));

// Ensure we're running from command line
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

// Prevent overlapping runs of this script
$cron_lock_script = __FILE__;
require_once "includes/cron_lock.php";

require_once "../config.php";
require_once "../includes/inc_set_timezone.php";
require_once "../functions.php";
require_once "../includes/load_global_settings.php";
require_once "../includes/database_version.php";
require_once "../functions/mit_consistency.php";

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron FROM settings WHERE company_id = 1"));
if (intval($row['config_enable_cron']) === 0) {
    cronJobStop("Cron: is not enabled -- Quitting..");
}

$mit_fails = [];
foreach (mitConsistencyChecks() as $mit_finding) {
    $mit_line = strtoupper($mit_finding['level']) . " [{$mit_finding['area']}] {$mit_finding['text']}" . ($mit_finding['items'] ? ': ' . implode('; ', $mit_finding['items']) : '');
    echo "$mit_line\n";
    if ($mit_finding['level'] === 'fail') {
        $mit_fails[] = $mit_line;
    }
}
if ($mit_fails) {
    // A stop with an error code is recorded as Failed (cron/cron.php) - never exit(), which would end the whole cron cycle
    cronJobStop(count($mit_fails) . ' consistency check(s) failed - run php scripts/mit_consistency_check.php for details. ' . mb_substr(implode(' | ', $mit_fails), 0, 1500), 1);
}
