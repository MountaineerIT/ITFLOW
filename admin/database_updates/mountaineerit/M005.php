<?php

/*
 * Mountaineer IT - database update M005
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Outlook busy times on the ITFlow calendar (functions/mit_outlook.php): a copy of each connected
 * person's own Outlook appointments, last week to eight weeks ahead, replaced every few minutes
 * by the Outlook Calendar Sync job - so the calendar and the booking overlap check read them from
 * here instead of asking Microsoft each time.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_outlook_busy` (
    `busy_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `starts_at` datetime NOT NULL,
    `ends_at` datetime NOT NULL,
    `is_all_day` tinyint(1) NOT NULL DEFAULT 0,
    `subject` varchar(255) NOT NULL DEFAULT '',
    `is_private` tinyint(1) NOT NULL DEFAULT 0,
    `show_as` varchar(20) NOT NULL DEFAULT 'busy',
    PRIMARY KEY (`busy_id`),
    KEY `mit_outlook_busy_when` (`user_id`, `starts_at`),
    CONSTRAINT `mit_outlook_busy_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

if (!mitColumnExists($mysqli, 'mit_outlook_links', 'busy_refreshed_at')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_outlook_links` ADD `busy_refreshed_at` datetime NULL AFTER `last_check_at`");
}
