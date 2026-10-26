<?php
/*
 * Mountaineer IT - service boards: tickets made by the portal, the API, recurring tickets and projects get their board
 * by the routing rules (mitBoardSweep in functions/mit_boards.php), and boards that hand tickets out round-robin deal out
 * the ones that waited for working hours (mitBoardAssignSweep).
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
require_once "../includes/load_global_settings.php"; // the assigned agent's email (ticketChangeAssignee)

$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron FROM settings WHERE company_id = 1"));
if (intval($row['config_enable_cron']) === 0) {
    cronJobStop("Cron: is not enabled -- Quitting..");
}

// Mountaineer IT: our tables are not there yet (just installed or restored, database updates still to run) - wait, don't fail
if (!mitDbReady()) {
    cronJobStop('Waiting for the Mountaineer IT database updates (after an install or a restore)');
}

echo mitBoardSweep() . " ticket(s) placed on a board\n";
echo mitBoardAssignSweep() . " ticket(s) handed out by auto-assign\n";
