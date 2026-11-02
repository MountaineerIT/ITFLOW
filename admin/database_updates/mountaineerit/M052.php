<?php

/*
 * Mountaineer IT - database update M052
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * QuickBooks Online invoice terms (functions/mit_qbo_terms.php): a client's own QuickBooks term (e.g. "Due on the 1st"), picked on
 * Admin > Integrations > QuickBooks Online > Customers. Clients without a row get the QuickBooks term matching their invoice's days.
 * Our own table, removed with the client (CASCADE).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_qbo_client_terms` (
    `client_id` int(11) NOT NULL,
    `qbo_term_id` varchar(32) NOT NULL,
    `qbo_term_name` varchar(255) NOT NULL DEFAULT '',
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`client_id`),
    CONSTRAINT `mit_qbo_client_terms_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
