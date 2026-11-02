<?php

/*
 * Mountaineer IT - database update M053
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * QuickBooks Online terms picked for one invoice (functions/mit_qbo_terms.php - the Terms picker in the invoice's QuickBooks strip),
 * over the client's own (mit_qbo_client_terms) and the invoice's days. Our own table, removed with the invoice (CASCADE).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_invoice_terms` (
    `invoice_id` int(11) NOT NULL,
    `qbo_term_id` varchar(32) NOT NULL,
    `qbo_term_name` varchar(255) NOT NULL DEFAULT '',
    `set_by` int(11) NOT NULL DEFAULT 0,
    `set_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`invoice_id`),
    CONSTRAINT `mit_qbo_invoice_terms_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`invoice_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
