<?php

/*
 * Mountaineer IT - database update M024
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Time zone changes on timesheets (functions/mit_timesheets.php mitTsZoneAt): each change of a person's time zone, and
 * from when it counts, so ticket time logged before a change stays on the day (and pay period) it was on. Doubles as
 * the record of who changed it. People who already had a zone get one row counting from the start. No foreign keys:
 * like the other timesheet records, these outlive a deleted login.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ts_zone_changes` (
    `change_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `time_zone` varchar(64) NULL DEFAULT NULL,
    `effective_at` datetime NOT NULL,
    `changed_by` int(11) NOT NULL DEFAULT 0,
    `changed_by_name` varchar(200) NULL DEFAULT NULL,
    `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`change_id`),
    KEY `mit_ts_zone_changes_user` (`user_id`, `effective_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

if (mitColumnExists($mysqli, 'mit_timesheet_people', 'time_zone')) {
    mysqli_query($mysqli, "INSERT INTO mit_ts_zone_changes (user_id, time_zone, effective_at, changed_by_name)
        SELECT p.user_id, p.time_zone, '1970-01-01 00:00:00', 'Database update M024' FROM mit_timesheet_people p
        WHERE p.time_zone IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mit_ts_zone_changes c WHERE c.user_id = p.user_id)");
}
