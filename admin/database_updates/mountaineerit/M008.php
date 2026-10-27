<?php

/*
 * Mountaineer IT - database update M008
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Additional techs on a ticket (functions/mit_ticket_techs.php): techs helping the assigned (lead)
 * tech, each booked at the same time as the lead (follows_lead), at their own time
 * (schedule_start / schedule_end), or not booked. The lead stays tickets.ticket_assigned_to.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_techs` (
    `ticket_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `follows_lead` tinyint(1) NOT NULL DEFAULT 1,
    `schedule_start` datetime NULL,
    `schedule_end` datetime NULL,
    `added_by` int(11) NOT NULL DEFAULT 0,
    `added_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`ticket_id`, `user_id`),
    KEY `mit_ticket_techs_user` (`user_id`),
    CONSTRAINT `mit_ticket_techs_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_ticket_techs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
