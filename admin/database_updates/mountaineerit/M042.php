<?php

/*
 * Mountaineer IT - database update M042
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Booking emails (functions/mit_notifications.php mitScheduleChoice): who was ticked to be emailed for a ticket's booking, kept so
 * a later move (calendar / Dispatch drag) or cancellation emails the same people - not the Remote / Onsite defaults. One row per
 * ticket, the choice as JSON; goes with the ticket.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_schedule_notify` (
    `ticket_id` int(11) NOT NULL,
    `choice` text NOT NULL,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`ticket_id`),
    CONSTRAINT `mit_ticket_schedule_notify_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
