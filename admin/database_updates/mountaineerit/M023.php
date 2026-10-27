<?php

/*
 * Mountaineer IT - database update M023
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Time zones on timesheets (functions/mit_timesheets.php mitTsZone): a remote person's timesheet days follow their own
 * clock - "today", future days, and which day their ticket time falls on. NULL = the company's time zone.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitColumnExists($mysqli, 'mit_timesheet_people', 'time_zone')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_timesheet_people` ADD `time_zone` varchar(64) NULL DEFAULT NULL");
}
