<?php
/*
 * Mountaineer IT - Customer Thermometer (functions/mit_ct.php): sends a satisfaction survey for each newly resolved ticket on the
 * chosen service boards, and every 15 minutes reads the responses back. Does nothing until it is set up and switched on in
 * Admin > Integrations > Customer Thermometer.
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

if (!mitCtReady()) {
    cronJobStop("Customer Thermometer needs the database update");
}
if (!mitCtSettings()['enabled']) {
    cronJobStop("Customer Thermometer is switched off");
}
if (mitCtMissing() !== '') {
    cronJobStop("Customer Thermometer needs " . mitCtMissing());
}
echo 'Customer Thermometer: ' . mitCtRun() . "\n";
