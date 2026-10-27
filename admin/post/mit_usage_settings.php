<?php

/*
 * Mountaineer IT - Usage billing settings (functions/mit_usage.php), from Admin > Settings > Invoices.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_edit_usage_settings'])) {

    validateCSRFToken();

    mitSetSetting('usage_snapshot_day', max(1, min(28, intval($_POST['mit_usage_snapshot_day'] ?? 1))));
    mitSetSetting('usage_change_pct', max(0, min(1000, intval($_POST['mit_usage_change_pct'] ?? 20))));
    mitSetSetting('usage_stale_days', max(0, min(90, intval($_POST['mit_usage_stale_days'] ?? 7))));
    logAudit('Settings', 'Edit', escapeSql(stripslashes($session_name) . " changed the usage billing settings"));
    flashAlert('Usage billing settings saved');
    redirect();
}
