<?php

/*
 * Mountaineer IT - database update M015
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * From the review of our additions:
 *  - purchase order numbers are unique (two people making a PO at the same moment can no longer get
 *    the same number - mitPoCreate() takes the next one when it loses the race); skipped, with a note
 *    in the PHP error log, if an install already has a duplicate to sort out first;
 *  - indexes for the lookups procurement does by project, ticket and invoice;
 *  - our two permission modules described the way ITFlow's are ("Access to ...");
 *  - "Keeps a timesheet" per person (Admin > Timesheets > People), on for everyone already there.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (!mitIndexExists($mysqli, 'mit_purchase_orders', 'mit_purchase_orders_number')) {
    $mit_duplicates = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM (SELECT po_number FROM mit_purchase_orders GROUP BY po_number HAVING COUNT(*) > 1) d"))[0];
    if (intval($mit_duplicates) === 0) {
        mysqli_query($mysqli, "ALTER TABLE `mit_purchase_orders` ADD UNIQUE KEY `mit_purchase_orders_number` (`po_number`)");
    } else {
        error_log("Mountaineer IT M015: $mit_duplicates purchase order number(s) are used twice - database update M025 renumbers them and makes PO numbers unique");
    }
}

$mit_m015_indexes = [
    ['mit_po_lines', 'mit_po_lines_project', 'project_id'],
    ['mit_procurement_items', 'mit_procurement_items_project', 'project_id'],
    ['mit_procurement_items', 'mit_procurement_items_ticket', 'ticket_id'],
    ['mit_ticket_parts', 'mit_ticket_parts_invoice', 'invoice_id'],
    ['mit_ticket_parts', 'mit_ticket_parts_item', 'proc_item_id'],
];
foreach ($mit_m015_indexes as [$mit_table, $mit_index, $mit_column]) {
    if (!mitIndexExists($mysqli, $mit_table, $mit_index)) {
        mysqli_query($mysqli, "ALTER TABLE `$mit_table` ADD INDEX `$mit_index` (`$mit_column`)");
    }
}

mysqli_query($mysqli, "UPDATE modules SET module_description = 'Access to procurement - items to order, purchase orders and receiving. Full approves purchase orders' WHERE module_name = 'module_procurement'");
mysqli_query($mysqli, "UPDATE modules SET module_description = 'Access to everyone''s timesheets and the payroll report (everyone keeps their own). Edit corrects them, Full approves' WHERE module_name = 'module_timesheets'");

if (!mitColumnExists($mysqli, 'mit_timesheet_people', 'keeps_timesheet')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_timesheet_people` ADD `keeps_timesheet` tinyint(1) NOT NULL DEFAULT 1");
}
