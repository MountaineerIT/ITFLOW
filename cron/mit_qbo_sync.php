<?php
/*
 * Mountaineer IT - payments made in QuickBooks Online back into ITFlow, hourly (mitQboPaymentSync in
 * functions/mit_qbo_billing.php); it also keeps the QuickBooks sign-in fresh. Does nothing until QuickBooks Online is
 * connected (Admin > Integrations > QuickBooks Online).
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

if (mitQboConnected() && mitQboDevProblem() !== '') {
    cronJobStop(mitQboDevProblem()); // development mode without a sandbox company connected here
}
if (!mitQboConnected()) {
    cronJobStop("QuickBooks Online is not connected");
} else {
    $session_name = 'QuickBooks Online sync';
    try {
        // Intuit's sign-in lapses after about 100 days unused - renew it in good time even with nothing to check
        if (intval(mitSetting('qbo_refresh_expires', 0)) < time() + 30 * 86400) {
            mitQboAccessToken(true);
        }
        $mit_qbo_report = mitQboPaymentSync();
    } catch (Throwable $e) {
        mitSetSetting('qbo_last_error', mb_substr($e->getMessage(), 0, 500));
        // A failed job shows in Maintenance > Cron and the cron alerts - thrown, as a job must never exit
        throw new RuntimeException('QuickBooks Online payment sync failed: ' . $e->getMessage());
    }
    echo "QuickBooks Online: {$mit_qbo_report['checked']} invoices checked, {$mit_qbo_report['changed']} changed\n";
    foreach ($mit_qbo_report['errors'] as $mit_qbo_line) {
        echo "$mit_qbo_line\n";
    }
}
