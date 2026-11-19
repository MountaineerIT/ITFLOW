<?php

/*
 * Mountaineer IT - database update M064
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Percentage discounts (functions/mit_discount.php - the user's request: a nonprofit discount on managed services, not on licensing):
 *   mit_billing_discounts  - an invoice / quote / recurring invoice whose ITFlow Discount is a percentage of its discountable lines
 *                            (doc_type + doc_id; percent; product_id = the product the discount posts as in QuickBooks, 0 = none). The
 *                            worked-out dollars stay in ITFlow's own *_discount_amount. No foreign key (three tables) - rows of deleted
 *                            documents are never read again (ids are not reused) and are tidied by the consistency check's report.
 *   mit_product_no_discount - products whose lines a percentage discount leaves out ("Never discounted"; FK products CASCADE).
 * Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_billing_discounts` (
    `doc_type` varchar(10) NOT NULL,
    `doc_id` int(11) NOT NULL,
    `percent` decimal(6,3) NOT NULL,
    `product_id` int(11) NOT NULL DEFAULT 0,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`doc_type`, `doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_product_no_discount` (
    `product_id` int(11) NOT NULL,
    PRIMARY KEY (`product_id`),
    CONSTRAINT `mit_product_no_discount_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
