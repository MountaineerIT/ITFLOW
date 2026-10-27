<?php

/*
 * Mountaineer IT - database update M032
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Usage billing (functions/mit_usage.php): a recurring invoice's line can take its quantity from a count each month.
 * - mit_usage_lines: a recurring invoice line billed by usage - where its count comes from (manual / api / tag) and the
 *   usage name (metric) the counts are kept under for the client.
 * - mit_usage_counts: every count with its date and source (typed, reported through the API, or the daily tag count).
 * - mit_usage_applied: what each generated invoice line got - the count used, last month's quantity, the flags, and any
 *   override with its reason - for Billing > Usage Review and the trail behind each billed quantity.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_usage_lines` (
    `line_id` int(11) NOT NULL AUTO_INCREMENT,
    `recurring_item_id` int(11) NOT NULL,
    `recurring_invoice_id` int(11) NOT NULL,
    `line_source` varchar(20) NOT NULL DEFAULT 'manual',
    `line_metric` varchar(100) NOT NULL DEFAULT '',
    `line_tag_id` int(11) NOT NULL DEFAULT 0,
    `line_min_qty` decimal(15,2) NULL DEFAULT NULL,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`line_id`),
    UNIQUE KEY `mit_usage_lines_item` (`recurring_item_id`),
    KEY `mit_usage_lines_recurring` (`recurring_invoice_id`),
    CONSTRAINT `mit_usage_lines_item` FOREIGN KEY (`recurring_item_id`) REFERENCES `recurring_invoice_items` (`item_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_usage_lines_recurring` FOREIGN KEY (`recurring_invoice_id`) REFERENCES `recurring_invoices` (`recurring_invoice_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_usage_counts` (
    `count_id` int(11) NOT NULL AUTO_INCREMENT,
    `client_id` int(11) NOT NULL,
    `metric` varchar(100) NOT NULL,
    `quantity` decimal(15,2) NOT NULL DEFAULT 0.00,
    `as_of` date NOT NULL,
    `count_source` varchar(20) NOT NULL DEFAULT 'manual',
    `count_note` varchar(255) NOT NULL DEFAULT '',
    `reported_by` int(11) NOT NULL DEFAULT 0,
    `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`count_id`),
    KEY `mit_usage_counts_lookup` (`client_id`, `metric`, `as_of`),
    CONSTRAINT `mit_usage_counts_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_usage_applied` (
    `applied_id` int(11) NOT NULL AUTO_INCREMENT,
    `invoice_id` int(11) NOT NULL,
    `invoice_item_id` int(11) NOT NULL,
    `line_id` int(11) NULL DEFAULT NULL,
    `metric` varchar(100) NOT NULL DEFAULT '',
    `snapshot_date` date NOT NULL,
    `count_id` int(11) NULL DEFAULT NULL,
    `count_as_of` date NULL DEFAULT NULL,
    `count_source` varchar(20) NULL DEFAULT NULL,
    `base_qty` decimal(15,2) NOT NULL DEFAULT 0.00,
    `previous_qty` decimal(15,2) NULL DEFAULT NULL,
    `applied_qty` decimal(15,2) NOT NULL DEFAULT 0.00,
    `flags` varchar(100) NOT NULL DEFAULT '',
    `override_qty` decimal(15,2) NULL DEFAULT NULL,
    `override_reason` varchar(255) NULL DEFAULT NULL,
    `override_by` int(11) NOT NULL DEFAULT 0,
    `override_at` datetime NULL DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`applied_id`),
    UNIQUE KEY `mit_usage_applied_item` (`invoice_item_id`),
    KEY `mit_usage_applied_invoice` (`invoice_id`),
    KEY `mit_usage_applied_line` (`line_id`, `applied_id`),
    KEY `mit_usage_applied_snapshot` (`snapshot_date`),
    CONSTRAINT `mit_usage_applied_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_usage_applied_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`item_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_usage_applied_line` FOREIGN KEY (`line_id`) REFERENCES `mit_usage_lines` (`line_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
