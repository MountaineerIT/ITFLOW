<?php

/*
 * Mountaineer IT - database update M041
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Indexes for ITFlow 26.11's busy queries (post-merge performance pass, Oct 2026, on the 50,000-ticket copy): the "Resolved, not
 * invoiced" / Unbilled Time count ITFlow runs on every page (ticket_invoice_id + ticket_billable, 16 ms -> under 1 ms), the Tickets
 * list's category filter list (ticket_category) and Admin > Audit logs' type and action pickers (about 130 ms each).
 * Plain (non-unique) indexes named mit_*: stock ITFlow ignores them, and they can be dropped at any time.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$mit_indexes = [
    ['tickets', 'mit_tickets_unbilled', '`ticket_invoice_id`, `ticket_billable`, `ticket_archived_at`'],
    ['tickets', 'mit_tickets_category', '`ticket_category`'],
    ['logs', 'mit_logs_type', '`log_type`'],
    ['logs', 'mit_logs_action', '`log_action`'],
];
foreach ($mit_indexes as [$mit_table, $mit_index, $mit_columns]) {
    if (!mitIndexExists($mysqli, $mit_table, $mit_index)) {
        mysqli_query($mysqli, "ALTER TABLE `$mit_table` ADD INDEX `$mit_index` ($mit_columns)");
    }
}
