<?php

/*
 * Mountaineer IT - database update M002
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Per-user preferences that should follow the person to any device or browser - first
 * the calendar's view, whose calendar, and which calendars and sources are ticked.
 * Kept in our own table rather than as columns on ITFlow's user_settings.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_user_preferences` (
    `user_id` int(11) NOT NULL,
    `preference` varchar(100) NOT NULL,
    `value` text NOT NULL,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`user_id`, `preference`),
    CONSTRAINT `mit_user_preferences_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
