<?php

/*
 * Mountaineer IT - database update M055
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Recurring tickets and invoices made ahead (functions/mit_recurring_lead.php): per recurring ticket the time / length / visit its
 * tickets are booked for and how many days before the work date they are made; per recurring invoice how many days before its next
 * date it is made and whether it is due on that date or after the client's net terms. Our own tables, removed with their row (CASCADE).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_recurring_ticket_plan` (
    `recurring_ticket_id` int(11) NOT NULL,
    `schedule_time` time DEFAULT NULL,
    `schedule_minutes` int(11) NOT NULL DEFAULT 60,
    `schedule_onsite` tinyint(1) NOT NULL DEFAULT 0,
    `lead_days` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`recurring_ticket_id`),
    CONSTRAINT `mit_recurring_ticket_plan_ticket` FOREIGN KEY (`recurring_ticket_id`) REFERENCES `recurring_tickets` (`recurring_ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_recurring_invoice_plan` (
    `recurring_invoice_id` int(11) NOT NULL,
    `lead_days` int(11) NOT NULL DEFAULT 0,
    `due_mode` varchar(10) NOT NULL DEFAULT 'terms',
    PRIMARY KEY (`recurring_invoice_id`),
    CONSTRAINT `mit_recurring_invoice_plan_invoice` FOREIGN KEY (`recurring_invoice_id`) REFERENCES `recurring_invoices` (`recurring_invoice_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
