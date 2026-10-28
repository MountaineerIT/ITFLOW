<?php

/*
 * Mountaineer IT - database update M050
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Index for the timesheets' "who had ticket time in this period" lookup (mitTsUsersWithActivity - the timesheet page, the team list,
 * the payroll report; performance review Oct 28, 26.10.191, on the 50,000-ticket copy): reply date + time worked + author, so the
 * lookup is answered from the index (10 ms -> 1.4 ms) instead of reading every reply of the period. ITFlow's Ticket Summary keeps
 * its own plan (checked).
 * A plain (non-unique) index named mit_*: stock ITFlow ignores it, and it can be dropped at any time.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitIndexExists($mysqli, 'ticket_replies', 'mit_ticket_replies_worked')) {
    mysqli_query($mysqli, "ALTER TABLE `ticket_replies` ADD INDEX `mit_ticket_replies_worked` (`ticket_reply_created_at`, `ticket_reply_time_worked`, `ticket_reply_by`)");
}
