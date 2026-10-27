<?php

/*
 * Mountaineer IT - database update M047
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Bug reports and feature requests (functions/mit_feedback.php - the top bar's Feedback panel): what, who sent it, the page and
 * build it came from, an optional screenshot (uploads/mit_feedback/, closed to the web), and whether it has been dealt with. Kept
 * when the sender or the admin who closed it is deleted (SET NULL).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_feedback` (
    `feedback_id` int(11) NOT NULL AUTO_INCREMENT,
    `kind` varchar(10) NOT NULL DEFAULT 'bug',
    `title` varchar(200) NOT NULL,
    `details` text DEFAULT NULL,
    `page` varchar(500) NOT NULL DEFAULT '',
    `version` varchar(60) NOT NULL DEFAULT '',
    `browser` varchar(255) NOT NULL DEFAULT '',
    `image` varchar(100) DEFAULT NULL,
    `status` varchar(10) NOT NULL DEFAULT 'open',
    `created_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `done_by` int(11) DEFAULT NULL,
    `done_at` datetime DEFAULT NULL,
    `done_note` text DEFAULT NULL,
    PRIMARY KEY (`feedback_id`),
    KEY `mit_feedback_status` (`status`, `created_at`),
    CONSTRAINT `mit_feedback_created_by_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
    CONSTRAINT `mit_feedback_done_by_fk` FOREIGN KEY (`done_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
