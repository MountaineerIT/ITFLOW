<?php
/*
 * Mountaineer IT - keeps the AI helper's library up to date (functions/mit_ai_helper.php mitAiIndexRun): resolved / closed tickets
 * and documents changed since the last run go in (cleaned of passwords first), reopened / archived ones come out, and passages get
 * their embeddings when a model is set. Does nothing while the AI helper is off (Admin > Integrations > AI helper).
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

if (!mitAiHelperReady()) {
    cronJobStop("The AI helper needs the database update");
}
if (!mitAiHelperSettings()['enabled']) {
    cronJobStop("The AI helper is switched off");
}

foreach (mitAiIndexRun(50) as $mit_ai_line) {
    echo "$mit_ai_line\n";
}
