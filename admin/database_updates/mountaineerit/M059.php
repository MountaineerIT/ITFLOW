<?php

/*
 * Mountaineer IT - database update M059
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Statuses that meet the SLA response target (functions/mit_status_response.php): a ticket moved into one of them before anyone
 * replied counts as answered - optionally after the client is emailed the "ticket assigned" message. One row per such status
 * (goes with the status), and each ticket's last status seen, so only a move INTO the status counts (goes with the ticket).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_status_response` (
    `ticket_status_id` int(11) NOT NULL,
    `send_email` tinyint(1) NOT NULL DEFAULT 1,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`ticket_status_id`),
    CONSTRAINT `mit_ticket_status_response_status` FOREIGN KEY (`ticket_status_id`) REFERENCES `ticket_statuses` (`ticket_status_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_status_seen` (
    `ticket_id` int(11) NOT NULL,
    `status_id` int(11) NOT NULL,
    `seen_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`ticket_id`),
    CONSTRAINT `mit_ticket_status_seen_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
