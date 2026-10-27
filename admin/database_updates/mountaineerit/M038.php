<?php

/*
 * Mountaineer IT - database update M038
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * QuickBooks Online, "Already in QuickBooks" (functions/mit_qbo_billing.php): invoices someone entered in QuickBooks by hand.
 * They leave the approval and batch lists and can't be batched; nothing links them to a QuickBooks invoice, so their payments
 * are recorded in ITFlow as usual. One row per invoice; deleting the row (Undo) puts the invoice back in the queue.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_manual` (
    `invoice_id` int(11) NOT NULL,
    `marked_by` int(11) NOT NULL DEFAULT 0,
    `marked_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`invoice_id`),
    CONSTRAINT `mit_qbo_manual_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
