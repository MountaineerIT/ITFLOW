<?php

/*
 * Mountaineer IT - database update M054
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * API / service accounts (functions/mit_service_users.php): agents that exist only so an API key can act as them. They stay
 * ordinary active agents with a role (ITFlow's API keys need that) but are left out of every agent list and can't sign in to the
 * web pages. Our own table, removed with the user (CASCADE).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_service_users` (
    `user_id` int(11) NOT NULL,
    `marked_by` int(11) NOT NULL DEFAULT 0,
    `marked_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`user_id`),
    CONSTRAINT `mit_service_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
