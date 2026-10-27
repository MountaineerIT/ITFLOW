<?php

/*
 * Mountaineer IT - database update M022
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Split shifts on California timesheets (functions/mit_ts_california.php): a day can have any number of work blocks,
 * and the gap before each block (after the first) is a meal break or time off between shifts. NULL = meal break, as
 * every gap was before this.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitColumnExists($mysqli, 'mit_ts_work_periods', 'gap_before')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_ts_work_periods` ADD `gap_before` varchar(10) NULL DEFAULT NULL AFTER `end_at`");
}
