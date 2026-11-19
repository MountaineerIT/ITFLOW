<?php

/*
 * Mountaineer IT - database update M065
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Minimum commits (functions/mit_minimum.php - the user's request: a client's $300 minimum, topped up by one line until their seats pass it):
 *   mit_billing_minimums - a line of an invoice / quote / recurring invoice whose price tops the document up to a minimum (doc_type + item_id;
 *                          minimum). The price it comes to stays in ITFlow's own item columns. No foreign key (three line tables) - rows of
 *                          deleted lines are never read again (ids are not reused).
 * Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_billing_minimums` (
    `doc_type` varchar(10) NOT NULL,
    `item_id` int(11) NOT NULL,
    `minimum` decimal(15,2) NOT NULL,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`doc_type`, `item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
