<?php

/*
 * Mountaineer IT - database update M017
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Reimbursements on tickets (functions/mit_reimbursements.php): mileage (with the ITFlow trip it made and
 * the rate it was logged at) and purchases an agent paid for (with the receipt, the ticket part it added,
 * and the ITFlow expense recorded when the timesheet was approved). No foreign keys: an entry stays for
 * payroll even if its ticket is deleted.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

// kind: mileage | purchase. amount = miles x rate for mileage, the price paid for a purchase.
mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_reimbursements` (
    `reimb_id` int(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` int(11) NOT NULL DEFAULT 0,
    `client_id` int(11) NOT NULL DEFAULT 0,
    `user_id` int(11) NOT NULL,
    `entry_date` date NOT NULL,
    `kind` varchar(10) NOT NULL,
    `description` varchar(255) NOT NULL,
    `vendor` varchar(200) NULL DEFAULT NULL,
    `source` varchar(200) NULL DEFAULT NULL,
    `destination` varchar(200) NULL DEFAULT NULL,
    `miles` decimal(10,1) NULL DEFAULT NULL,
    `round_trip` tinyint(1) NOT NULL DEFAULT 0,
    `rate` decimal(10,4) NULL DEFAULT NULL,
    `amount` decimal(15,2) NOT NULL DEFAULT 0,
    `trip_id` int(11) NULL DEFAULT NULL,
    `part_id` int(11) NULL DEFAULT NULL,
    `receipt` varchar(255) NULL DEFAULT NULL,
    `receipt_name` varchar(255) NULL DEFAULT NULL,
    `expense_id` int(11) NULL DEFAULT NULL,
    `created_by` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NULL DEFAULT NULL ON UPDATE current_timestamp(),
    PRIMARY KEY (`reimb_id`),
    KEY `mit_reimbursements_user_date` (`user_id`, `entry_date`),
    KEY `mit_reimbursements_ticket` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
