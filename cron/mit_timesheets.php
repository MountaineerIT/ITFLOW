<?php
/*
 * Mountaineer IT - the timesheet job (functions/mit_timesheets.php, functions/mit_leave.php), hourly:
 * reminders on a pay period's last day and when the last one is late (each sent once; people can turn
 * them off in Account > Preferences), leave earned for ended periods when timesheets are not approved,
 * and the start of each leave year (carry-over limit, yearly grant).
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

if (!mitTsOn()) {
    cronJobStop();
}

foreach (array_merge(mitTsFillEndedClosures(), mitTsSendReminders(), mitLeaveAccrueEnded(), mitReimbRecordEnded(), mitLeaveReady() ? mitLeaveYearEnds() : []) as $mit_ts_line) {
    echo "$mit_ts_line\n";
}
