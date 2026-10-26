<?php
/*
 * Mountaineer IT - NinjaOne devices to ITFlow assets, hourly (mitNinjaSync in functions/mit_ninjaone.php).
 * Does nothing until NinjaOne is set up and its sync switched on (Admin > Integrations > NinjaOne).
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

if (!mitNinjaConfigured() || !mitNinjaSettings()['sync_on']) {
    cronJobStop("NinjaOne sync is off");
} else {
    $mit_ninja_report = mitNinjaSync('cron');
    $mit_ninja_totals = $mit_ninja_report['totals'] ?? [];
    echo 'NinjaOne sync: ' . $mit_ninja_report['outcome'] . ' - ' . intval($mit_ninja_totals['created'] ?? 0) . ' new, ' . intval($mit_ninja_totals['updated'] ?? 0) . ' updated, '
        . intval($mit_ninja_totals['unchanged'] ?? 0) . " unchanged\n";
    foreach ($mit_ninja_report['errors'] ?? [] as $mit_ninja_line) {
        echo "$mit_ninja_line\n";
    }
    // A run that could not reach NinjaOne counts as a failed job (Maintenance > Cron, and the cron alerts) - thrown, as a job
    // must never exit (cron/cron.php runs the jobs in one process)
    if ($mit_ninja_report['outcome'] === 'failed') {
        throw new RuntimeException('NinjaOne sync failed: ' . implode(' ', $mit_ninja_report['errors'] ?? []));
    }
}
