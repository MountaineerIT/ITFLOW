<?php

/*
 * Mountaineer IT - database update M031
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * QuickBooks Online (functions/mit_qbo.php): invoices are approved in ITFlow, gathered into batches and sent to QuickBooks
 * Online, which emails them and takes the payments; payments come back as ITFlow payments so balances stay right.
 * - mit_qbo_customers / mit_qbo_items / mit_qbo_tax_codes: ITFlow client / product / tax -> the QuickBooks customer / item /
 *   tax code (connection settings and tokens are in mit_settings, qbo_*).
 * - mit_invoice_approvals: an invoice approved for QuickBooks, with a fingerprint of what was approved - any change after
 *   that (lines, amounts, dates, client) makes the fingerprint differ, so it needs approving again.
 * - mit_qbo_batches / mit_qbo_batch_invoices: a batch and the invoices in it - queued, sent (with the QuickBooks invoice id
 *   and the ITFlow payment the sync keeps in step), failed, or removed (kept for the record). An invoice in a batch that is
 *   not removed is locked against changes in ITFlow.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_customers` (
    `client_id` int(11) NOT NULL,
    `qbo_customer_id` varchar(32) NOT NULL,
    `qbo_name` varchar(255) NOT NULL DEFAULT '',
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`client_id`),
    CONSTRAINT `mit_qbo_customers_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_items` (
    `product_id` int(11) NOT NULL,
    `qbo_item_id` varchar(32) NOT NULL,
    `qbo_name` varchar(255) NOT NULL DEFAULT '',
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`product_id`),
    CONSTRAINT `mit_qbo_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_tax_codes` (
    `tax_id` int(11) NOT NULL,
    `qbo_tax_code_id` varchar(32) NOT NULL,
    `qbo_name` varchar(255) NOT NULL DEFAULT '',
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`tax_id`),
    CONSTRAINT `mit_qbo_tax_codes_tax` FOREIGN KEY (`tax_id`) REFERENCES `taxes` (`tax_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_invoice_approvals` (
    `invoice_id` int(11) NOT NULL,
    `fingerprint` char(64) NOT NULL,
    `approved_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
    `approved_by` int(11) NOT NULL DEFAULT 0,
    `approved_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`invoice_id`),
    CONSTRAINT `mit_invoice_approvals_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_batches` (
    `batch_id` int(11) NOT NULL AUTO_INCREMENT,
    `batch_name` varchar(200) NOT NULL DEFAULT '',
    `batch_status` varchar(20) NOT NULL DEFAULT 'open',
    `batch_email` tinyint(1) NOT NULL DEFAULT 1,
    `batch_note` text NULL DEFAULT NULL,
    `created_by` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `sent_by` int(11) NOT NULL DEFAULT 0,
    `sent_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`batch_id`),
    KEY `mit_qbo_batches_status` (`batch_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_batch_invoices` (
    `entry_id` int(11) NOT NULL AUTO_INCREMENT,
    `batch_id` int(11) NOT NULL,
    `invoice_id` int(11) NOT NULL,
    `entry_status` varchar(20) NOT NULL DEFAULT 'queued',
    `fingerprint` char(64) NOT NULL DEFAULT '',
    `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
    `qbo_invoice_id` varchar(32) NULL DEFAULT NULL,
    `qbo_sync_token` varchar(32) NULL DEFAULT NULL,
    `qbo_doc_number` varchar(32) NULL DEFAULT NULL,
    `qbo_total` decimal(15,2) NULL DEFAULT NULL,
    `qbo_balance` decimal(15,2) NULL DEFAULT NULL,
    `emailed_at` datetime NULL DEFAULT NULL,
    `error` text NULL DEFAULT NULL,
    `warning` text NULL DEFAULT NULL,
    `payment_id` int(11) NULL DEFAULT NULL,
    `paid_synced_at` datetime NULL DEFAULT NULL,
    `added_by` int(11) NOT NULL DEFAULT 0,
    `added_at` datetime NOT NULL DEFAULT current_timestamp(),
    `sent_at` datetime NULL DEFAULT NULL,
    `removed_by` int(11) NOT NULL DEFAULT 0,
    `removed_at` datetime NULL DEFAULT NULL,
    `removed_how` varchar(20) NULL DEFAULT NULL,
    PRIMARY KEY (`entry_id`),
    KEY `mit_qbo_batch_invoices_batch` (`batch_id`),
    KEY `mit_qbo_batch_invoices_invoice` (`invoice_id`, `entry_status`),
    KEY `mit_qbo_batch_invoices_payment` (`payment_id`),
    CONSTRAINT `mit_qbo_batch_invoices_batch` FOREIGN KEY (`batch_id`) REFERENCES `mit_qbo_batches` (`batch_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_qbo_batch_invoices_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_qbo_batch_invoices_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
