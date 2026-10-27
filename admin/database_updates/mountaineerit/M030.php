<?php

/*
 * Mountaineer IT - database update M030
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Indexes for the busy pages (performance review, Oct 2026 - measured on a copy grown to 50,000 tickets, 300,000 replies and
 * 300,000 log rows): the open-ticket counts in the side menus and lists (closed / resolved), date ranges on the dashboard,
 * reports and calendar (created / resolved / schedule), ticket numbers for the email parser, a ticket's attachments, a
 * client's recent activity and each user's last sign-in (logs), the open-invoice counts and the week's ticket history.
 * Plain (non-unique) indexes named mit_*: stock ITFlow ignores them, and they can be dropped at any time.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$mit_indexes = [
    ['tickets', 'mit_tickets_open', '`ticket_closed_at`, `ticket_resolved_at`, `ticket_archived_at`'],
    ['tickets', 'mit_tickets_created', '`ticket_created_at`'],
    ['tickets', 'mit_tickets_resolved', '`ticket_resolved_at`'],
    ['tickets', 'mit_tickets_schedule', '`ticket_schedule`'],
    ['tickets', 'mit_tickets_number', '`ticket_number`'],
    ['ticket_attachments', 'mit_ticket_attachments_ticket', '`ticket_attachment_ticket_id`'],
    ['ticket_history', 'mit_ticket_history_created', '`ticket_history_created_at`'],
    ['logs', 'mit_logs_client', '`log_client_id`, `log_id`'],
    ['logs', 'mit_logs_user_type', '`log_user_id`, `log_type`, `log_action`, `log_id`'],
    ['invoices', 'mit_invoices_status', '`invoice_status`, `invoice_archived_at`'],
];
foreach ($mit_indexes as [$mit_table, $mit_index, $mit_columns]) {
    if (!mitIndexExists($mysqli, $mit_table, $mit_index)) {
        mysqli_query($mysqli, "ALTER TABLE `$mit_table` ADD INDEX `$mit_index` ($mit_columns)");
    }
}
