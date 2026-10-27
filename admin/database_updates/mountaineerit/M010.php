<?php

/*
 * Mountaineer IT - database update M010
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Procurement part 2 (functions/mit_procurement_parts.php): parts used on a ticket - from stock,
 * to order (they go on the procurement list) or on hand - and billed to an invoice; and a preferred
 * vendor on products, so a product's items know who to order from.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_parts` (
    `part_id` int(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` int(11) NOT NULL,
    `client_id` int(11) NOT NULL DEFAULT 0,
    `product_id` int(11) NOT NULL DEFAULT 0,
    `asset_id` int(11) NOT NULL DEFAULT 0,
    `name` varchar(255) NOT NULL,
    `description` text NULL,
    `quantity` decimal(15,2) NOT NULL DEFAULT 1.00,
    `unit_cost` decimal(15,2) NULL DEFAULT NULL,
    `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
    `tax_id` int(11) NOT NULL DEFAULT 0,
    `source` varchar(10) NOT NULL DEFAULT 'stock',
    `proc_item_id` int(11) NOT NULL DEFAULT 0,
    `invoice_id` int(11) NOT NULL DEFAULT 0,
    `invoice_item_id` int(11) NOT NULL DEFAULT 0,
    `billed_at` datetime NULL DEFAULT NULL,
    `created_by` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`part_id`),
    KEY `mit_ticket_parts_ticket` (`ticket_id`),
    CONSTRAINT `mit_ticket_parts_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

if (!mitColumnExists($mysqli, 'products', 'product_vendor_id')) {
    mysqli_query($mysqli, "ALTER TABLE `products` ADD `product_vendor_id` int(11) NULL DEFAULT NULL");
}
