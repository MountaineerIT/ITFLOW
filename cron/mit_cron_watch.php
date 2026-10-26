<?php
/*
 * Mountaineer IT - emails the admins when a cron job fails, gets stuck or falls behind, once a day
 * while it lasts, and when it is fixed (mitCronWatch in functions/mit_cron_health.php).
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

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron FROM settings WHERE company_id = 1"));
if (intval($row['config_enable_cron']) === 0) {
    cronJobStop("Cron: is not enabled -- Quitting..");
}

// Mountaineer IT: our tables are not there yet (just installed or restored, database updates still to run) - wait, don't fail
if (!mitDbReady()) {
    cronJobStop('Waiting for the Mountaineer IT database updates (after an install or a restore)');
}

// Secrets still under the old derived key move to config.php's key (mitUpgradeSecrets) - this job always runs, the
// Outlook one only with Microsoft 365 set up; once a day is plenty
try {
    if (mitSetting('secrets_upgraded_on', '') !== date('Y-m-d')) {
        mitSetSetting('secrets_upgraded_on', date('Y-m-d'));
        $mit_upgraded = mitUpgradeSecrets();
        if ($mit_upgraded) {
            echo "Re-encrypted $mit_upgraded secret(s) with config.php's key\n";
        }
    }
} catch (Throwable $e) {
    echo "Secret upgrade failed: " . $e->getMessage() . "\n";
}

foreach (mitCronWatch() as $mit_watch_line) {
    echo "$mit_watch_line\n";
}
