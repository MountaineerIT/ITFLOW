<?php

/*
 * Mountaineer IT - database update M001
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * When booked work on a ticket ends. ITFlow stores only the start (ticket_schedule) and
 * treats every booking as an hour; NULL here still means that hour.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitColumnExists($mysqli, 'tickets', 'ticket_schedule_end')) {
    mysqli_query($mysqli, "ALTER TABLE `tickets` ADD COLUMN `ticket_schedule_end` datetime DEFAULT NULL AFTER `ticket_schedule`");
}
