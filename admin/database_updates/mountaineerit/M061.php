<?php

/*
 * Mountaineer IT - database update M061
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * ITFlow 26.11.8 (database 3.6.6) adds its own audit-log indexes. Two of ours (M030 / M041) do the same job, and a second copy of an
 * index only slows every log write, so ours go where ITFlow's is there (the user's choice, as M060 for the ticket ones):
 * mit_logs_user_type (= ITFlow's log_user_type - user, type, action) and mit_logs_type (covered by log_type - type, action, user, ip,
 * created). mit_logs_action and mit_logs_client stay. Safe to run twice; on an install without ITFlow's index ours is kept.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

foreach (['mit_logs_user_type' => 'log_user_type', 'mit_logs_type' => 'log_type'] as $mit_ours => $mit_theirs) {
    if (mitIndexExists($mysqli, 'logs', $mit_ours) && mitIndexExists($mysqli, 'logs', $mit_theirs)) {
        mysqli_query($mysqli, "ALTER TABLE `logs` DROP INDEX `$mit_ours`");
    }
}
unset($mit_ours, $mit_theirs);
