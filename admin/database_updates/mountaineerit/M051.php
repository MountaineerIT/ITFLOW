<?php

/*
 * Mountaineer IT - database update M051
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * QuickBooks Online income accounts by category (functions/mit_qbo_accounts.php):
 *  - mit_qbo_category_accounts: which QuickBooks income account each ITFlow Income category's sales go to, and the item made in
 *    QuickBooks for that category's lines without a product ("Hardware Sales (ITFlow)").
 *  - mit_invoice_item_categories: the category picked for an invoice line with no product (Edit Line, or ticket time). Lines with a
 *    product take the product's category and get no row here.
 * Both our own tables, removed with the ITFlow category / invoice line (CASCADE).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_category_accounts` (
    `category_id` int(11) NOT NULL,
    `qbo_account_id` varchar(32) NOT NULL DEFAULT '',
    `qbo_account_name` varchar(255) NOT NULL DEFAULT '',
    `qbo_item_id` varchar(32) NOT NULL DEFAULT '',
    `qbo_item_name` varchar(255) NOT NULL DEFAULT '',
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`category_id`),
    CONSTRAINT `mit_qbo_category_accounts_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_invoice_item_categories` (
    `item_id` int(11) NOT NULL,
    `category_id` int(11) NOT NULL,
    `set_by` int(11) NOT NULL DEFAULT 0,
    `set_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`item_id`),
    KEY `mit_invoice_item_categories_category` (`category_id`),
    CONSTRAINT `mit_invoice_item_categories_item` FOREIGN KEY (`item_id`) REFERENCES `invoice_items` (`item_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_invoice_item_categories_cat` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
