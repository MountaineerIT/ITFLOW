<?php

/*
 * Mountaineer IT - database update M069
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Timesheets read ticket time by when it was worked (functions/mit_reply_times.php mitTsReplyWorkRowsRead) - since 26.12 from ITFlow's
 * ticket_replies.ticket_reply_work_start, which has no index; ours on mit_reply_times.work_start did that job. A plain index of ours
 * (mit_ name, nothing unique) - portable.
 * Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (mitColumnExists($mysqli, 'ticket_replies', 'ticket_reply_work_start') && !mitIndexExists($mysqli, 'ticket_replies', 'mit_ticket_replies_work_start')) {
    mysqli_query($mysqli, "ALTER TABLE ticket_replies ADD INDEX mit_ticket_replies_work_start (ticket_reply_by, ticket_reply_work_start)");
}
