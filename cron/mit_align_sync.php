<?php
/*
 * Mountaineer IT - MSP Align, read only (functions/mit_align.php): copies what MSP Align knows about ITFlow's clients, assets and
 * licenses (health and alignment scores, lifecycle status, renewals) every hour. Only reads from MSP Align. Does nothing until it
 * is set up in Admin > Integrations > MSP Align.
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

if (!mitAlignReady()) {
    cronJobStop("MSP Align needs the database update");
}
if (!mitAlignOn()) {
    cronJobStop("MSP Align is not set up or is switched off");
}
echo 'MSP Align: ' . mitAlignSync() . "\n";
