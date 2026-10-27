<?php
/*
 * Mountaineer IT - daily tidy-up of procurement after ITFlow deletes tickets, clients or invoice lines
 * (mitProcTidy in functions/mit_procurement_parts.php). Safe to run any number of times.
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

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron FROM settings WHERE company_id = 1"));
if (intval($row['config_enable_cron']) === 0) {
    cronJobStop("Cron: is not enabled -- Quitting..");
}

// Mountaineer IT: our tables are not there yet (just installed or restored, database updates still to run) - wait, don't fail
if (!mitDbReady()) {
    cronJobStop('Waiting for the Mountaineer IT database updates (after an install or a restore)');
}

foreach (mitProcTidy() as $mit_tidy_line) {
    echo "$mit_tidy_line\n";
}
