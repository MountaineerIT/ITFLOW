<?php

/*
 * Mountaineer IT - database update M037
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * "Resolve - no response" (functions/mit_no_response.php): tickets resolved because the client never answered. One row per
 * ticket, holding the ticket_resolved_at of that resolution - a ticket reopened and resolved again later no longer matches it,
 * so the mark only ever covers the resolution it was made for. The message template is in mit_settings no_response_message.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_no_response` (
    `ticket_id` int(11) NOT NULL,
    `resolved_at` datetime NULL DEFAULT NULL,
    `user_id` int(11) NOT NULL DEFAULT 0,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`ticket_id`),
    CONSTRAINT `mit_ticket_no_response_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
