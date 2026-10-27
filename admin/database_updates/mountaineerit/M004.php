<?php

/*
 * Mountaineer IT - database update M004
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Each person's own Outlook calendar (functions/mit_outlook.php):
 *  - mit_outlook_links: one row per person who connected Outlook - the Microsoft account,
 *    their sign-in (refresh token, encrypted with this server's key) and how the sync went.
 *  - mit_outlook_events: the Outlook event ITFlow made for each booked ticket, so it can be
 *    changed or removed when the booking is.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_outlook_links` (
    `user_id` int(11) NOT NULL,
    `account_email` varchar(255) NOT NULL DEFAULT '',
    `account_name` varchar(255) NOT NULL DEFAULT '',
    `refresh_token` text NOT NULL,
    `access_token` text NULL,
    `access_expires_at` datetime NULL,
    `connected_at` datetime NOT NULL DEFAULT current_timestamp(),
    `last_sync_at` datetime NULL,
    `last_check_at` datetime NULL,
    `sync_signature` varchar(40) NOT NULL DEFAULT '',
    `last_error` text NULL,
    PRIMARY KEY (`user_id`),
    CONSTRAINT `mit_outlook_links_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_outlook_events` (
    `user_id` int(11) NOT NULL,
    `ticket_id` int(11) NOT NULL,
    `event_id` varchar(512) NOT NULL,
    `event_hash` varchar(40) NOT NULL DEFAULT '',
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`user_id`, `ticket_id`),
    CONSTRAINT `mit_outlook_events_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
