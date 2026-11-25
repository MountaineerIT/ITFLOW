<?php

/*
 * Mountaineer IT - database update M071
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Quiet boards (functions/mit_boards.php mitBoardQuietSql - the user's request, Nov 25: an Alerts board whose tickets come and go by
 * themselves): mit_boards.board_quiet - 1 = its tickets stay out of Dispatch's queues and counts and the dashboard's ticket counts while
 * all boards / the agent's boards are shown (picking the board itself still shows them). Our table only - portable.
 * Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$m071_ours = mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_boards'");
if ($m071_ours && mysqli_num_rows($m071_ours) && !mitColumnExists($mysqli, 'mit_boards', 'board_quiet')) {
    mysqli_query($mysqli, "ALTER TABLE mit_boards ADD COLUMN board_quiet TINYINT(1) NOT NULL DEFAULT 0 AFTER board_is_default");
}
