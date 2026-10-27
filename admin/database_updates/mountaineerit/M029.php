<?php

/*
 * Mountaineer IT - database update M029
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Indexes for timesheets (functions/mit_timesheets.php mitTsReplyWorkRows: a person's ticket replies over a date range) -
 * ITFlow's ticket_replies has indexes on its id and ticket only, so each timesheet pass read the whole table. Plain
 * (non-unique) indexes named mit_*: stock ITFlow ignores them, and they can be dropped at any time.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitIndexExists($mysqli, 'ticket_replies', 'mit_ticket_replies_by_created')) {
    mysqli_query($mysqli, "ALTER TABLE `ticket_replies` ADD INDEX `mit_ticket_replies_by_created` (`ticket_reply_by`, `ticket_reply_created_at`)");
}
if (!mitIndexExists($mysqli, 'ticket_replies', 'mit_ticket_replies_created')) {
    mysqli_query($mysqli, "ALTER TABLE `ticket_replies` ADD INDEX `mit_ticket_replies_created` (`ticket_reply_created_at`)");
}
