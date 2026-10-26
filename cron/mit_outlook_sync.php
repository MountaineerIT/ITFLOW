<?php
/*
 * Mountaineer IT - keep each person's Outlook calendar in line with their booked tickets
 * (functions/mit_outlook.php). Every minute; only people whose bookings changed, or whose
 * 15-minute check of Outlook is due, cost a call to Microsoft.
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

mitSettingsCacheRef(true);  // settings as they are now - an earlier job in this cycle may have read them

if (!mitOutlookAvailable()) {
    cronJobStop();
}

try {
    mysqli_query($mysqli, "SELECT 1 FROM mit_outlook_links LIMIT 1");
} catch (Throwable $e) {
    cronJobStop("Outlook sync waits for the database update (M004)");
}

// The app's certificate is replaced 30 days before it expires; secrets under the old derived key move to config.php's key
mitM365MaybeRenewCertificate();
mitUpgradeSecrets();

// Someone archived keeps nothing in Outlook - take ITFlow's events out and forget the link
// (not in development mode - that would take the events out of their real calendar; mitOutlookSync only reads busy times there)
$mit_outlook_result = mitDevMode() ? false : mysqli_query($mysqli, "SELECT user_id FROM mit_outlook_links JOIN users USING (user_id) WHERE user_archived_at IS NOT NULL");
while ($mit_outlook_result && $mit_outlook_row = mysqli_fetch_assoc($mit_outlook_result)) {
    try {
        mitOutlookDisconnect($mit_outlook_row['user_id']);
        echo "Outlook disconnected for archived user {$mit_outlook_row['user_id']}\n";
    } catch (Throwable $e) {
        echo "Outlook disconnect for archived user {$mit_outlook_row['user_id']} failed: " . $e->getMessage() . "\n";
    }
}

$mit_outlook_users = [];
// A disabled user (Admin > Users) is paused, not removed - they may be back
$mit_outlook_result = mysqli_query($mysqli, "SELECT user_id FROM mit_outlook_links JOIN users USING (user_id) WHERE user_archived_at IS NULL AND user_status = 1");
while ($mit_outlook_result && $mit_outlook_row = mysqli_fetch_assoc($mit_outlook_result)) {
    $mit_outlook_users[] = intval($mit_outlook_row['user_id']);
}

foreach ($mit_outlook_users as $mit_outlook_user_id) {
    $mit_outlook_before = (string) (mitOutlookLink($mit_outlook_user_id)['last_error'] ?? '');
    try {
        $mit_outlook_summary = mitOutlookSync($mit_outlook_user_id);
        if (!in_array($mit_outlook_summary, ['Up to date', 'Waiting to retry', 'Already syncing'], true)) {
            echo "Outlook sync user $mit_outlook_user_id: $mit_outlook_summary\n";
        }
    } catch (Throwable $e) {
        // Kept on the person's link and shown on their Outlook calendar page; written to App Logs only when the
        // problem is new (a failing sync is retried every 15 minutes, which would otherwise fill the log). Compared as stored:
        // with several problems the message thrown lists up to three, the stored one the first and "(and n more)"
        $mit_outlook_after = (string) (mitOutlookLink($mit_outlook_user_id)['last_error'] ?? '');
        if (($mit_outlook_after !== '' ? $mit_outlook_after : $e->getMessage()) !== $mit_outlook_before) {
            logApp('Outlook', 'warning', "Outlook sync for user $mit_outlook_user_id failed: " . $e->getMessage());
        }
        echo "Outlook sync user $mit_outlook_user_id failed: " . $e->getMessage() . "\n";
    }
}
