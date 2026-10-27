<?php

/*
 * Mountaineer IT - database update M009
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Procurement (functions/mit_procurement.php): items waiting to be ordered, purchase orders with
 * their lines, tracking, receipts and activity - and a "Procurement" permission module, so roles
 * can be given View / Edit / Full (Full approves POs). ITFlow's own tables are not changed; the
 * module is one more row in its modules list.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_procurement_items` (
    `item_id` int(11) NOT NULL AUTO_INCREMENT,
    `source` varchar(20) NOT NULL DEFAULT 'manual',
    `client_id` int(11) NOT NULL DEFAULT 0,
    `project_id` int(11) NOT NULL DEFAULT 0,
    `invoice_id` int(11) NOT NULL DEFAULT 0,
    `invoice_item_id` int(11) NOT NULL DEFAULT 0,
    `ticket_id` int(11) NOT NULL DEFAULT 0,
    `product_id` int(11) NOT NULL DEFAULT 0,
    `vendor_id` int(11) NOT NULL DEFAULT 0,
    `name` varchar(255) NOT NULL,
    `description` text NULL,
    `quantity` decimal(15,2) NOT NULL DEFAULT 1.00,
    `unit_cost` decimal(15,2) NULL DEFAULT NULL,
    `status` varchar(20) NOT NULL DEFAULT 'waiting',
    `note` varchar(255) NULL DEFAULT NULL,
    `created_by` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NULL DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`item_id`),
    KEY `mit_procurement_items_status` (`status`, `vendor_id`),
    KEY `mit_procurement_items_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_purchase_orders` (
    `po_id` int(11) NOT NULL AUTO_INCREMENT,
    `po_prefix` varchar(20) NOT NULL DEFAULT 'PO-',
    `po_number` int(11) NOT NULL,
    `vendor_id` int(11) NOT NULL DEFAULT 0,
    `status` varchar(20) NOT NULL DEFAULT 'draft',
    `order_date` date NULL DEFAULT NULL,
    `vendor_order_number` varchar(100) NULL DEFAULT NULL,
    `ship_to` varchar(20) NOT NULL DEFAULT 'office',
    `ship_to_address` text NULL,
    `note` text NULL,
    `shipping` decimal(15,2) NOT NULL DEFAULT 0.00,
    `total` decimal(15,2) NOT NULL DEFAULT 0.00,
    `created_by` int(11) NOT NULL DEFAULT 0,
    `submitted_at` datetime NULL DEFAULT NULL,
    `approved_by` int(11) NOT NULL DEFAULT 0,
    `approved_at` datetime NULL DEFAULT NULL,
    `approval_note` varchar(255) NULL DEFAULT NULL,
    `ordered_by` int(11) NOT NULL DEFAULT 0,
    `ordered_at` datetime NULL DEFAULT NULL,
    `received_at` datetime NULL DEFAULT NULL,
    `cancelled_at` datetime NULL DEFAULT NULL,
    `expense_id` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NULL DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`po_id`),
    KEY `mit_purchase_orders_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_po_lines` (
    `line_id` int(11) NOT NULL AUTO_INCREMENT,
    `po_id` int(11) NOT NULL,
    `proc_item_id` int(11) NOT NULL DEFAULT 0,
    `client_id` int(11) NOT NULL DEFAULT 0,
    `project_id` int(11) NOT NULL DEFAULT 0,
    `name` varchar(255) NOT NULL,
    `description` text NULL,
    `quantity` decimal(15,2) NOT NULL DEFAULT 1.00,
    `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
    `received_qty` decimal(15,2) NOT NULL DEFAULT 0.00,
    `sort` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`line_id`),
    KEY `mit_po_lines_po` (`po_id`),
    KEY `mit_po_lines_item` (`proc_item_id`),
    CONSTRAINT `mit_po_lines_po` FOREIGN KEY (`po_id`) REFERENCES `mit_purchase_orders` (`po_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_po_tracking` (
    `tracking_id` int(11) NOT NULL AUTO_INCREMENT,
    `po_id` int(11) NOT NULL,
    `carrier` varchar(30) NOT NULL DEFAULT '',
    `tracking_number` varchar(100) NOT NULL DEFAULT '',
    `note` varchar(255) NULL DEFAULT NULL,
    `eta` date NULL DEFAULT NULL,
    `delivered` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`tracking_id`),
    KEY `mit_po_tracking_po` (`po_id`),
    CONSTRAINT `mit_po_tracking_po` FOREIGN KEY (`po_id`) REFERENCES `mit_purchase_orders` (`po_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_po_receipts` (
    `receipt_id` int(11) NOT NULL AUTO_INCREMENT,
    `po_id` int(11) NOT NULL,
    `line_id` int(11) NOT NULL,
    `quantity` decimal(15,2) NOT NULL,
    `serials` text NULL,
    `received_by` int(11) NOT NULL DEFAULT 0,
    `received_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`receipt_id`),
    KEY `mit_po_receipts_line` (`line_id`),
    CONSTRAINT `mit_po_receipts_po` FOREIGN KEY (`po_id`) REFERENCES `mit_purchase_orders` (`po_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_po_history` (
    `history_id` int(11) NOT NULL AUTO_INCREMENT,
    `po_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL DEFAULT 0,
    `description` varchar(500) NOT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`history_id`),
    KEY `mit_po_history_po` (`po_id`),
    CONSTRAINT `mit_po_history_po` FOREIGN KEY (`po_id`) REFERENCES `mit_purchase_orders` (`po_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// The Procurement permission - a row in ITFlow's modules list, so Admin > Roles offers it
$mit_m009_module = mysqli_query($mysqli, "SELECT module_id FROM modules WHERE module_name = 'module_procurement'");
if (mysqli_num_rows($mit_m009_module) === 0) {
    mysqli_query($mysqli, "INSERT INTO modules SET module_name = 'module_procurement', module_description = 'Access to procurement - items to order, purchase orders and receiving (Full approves POs)'");
}
