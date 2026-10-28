<?php

/*
 * Mountaineer IT - database update M048
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Offices (functions/mit_offices.php): more places the company works from, beside the main one in Admin > Settings > Company
 * Details (which stays ITFlow's companies row - office 0). Contact details only: name, address, phone, email. Each agent can
 * belong to one (mit_user_offices - none = the main office); their trips start there, ticket emails show its contact line, and
 * purchase orders can be shipped to any office. Removing an office or a login removes its links (CASCADE).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_offices` (
    `office_id` int(11) NOT NULL AUTO_INCREMENT,
    `office_name` varchar(100) NOT NULL,
    `office_address` varchar(200) NOT NULL DEFAULT '',
    `office_city` varchar(200) NOT NULL DEFAULT '',
    `office_state` varchar(200) NOT NULL DEFAULT '',
    `office_zip` varchar(200) NOT NULL DEFAULT '',
    `office_country` varchar(200) NOT NULL DEFAULT '',
    `office_phone_country_code` varchar(10) NOT NULL DEFAULT '',
    `office_phone` varchar(200) NOT NULL DEFAULT '',
    `office_email` varchar(200) NOT NULL DEFAULT '',
    `office_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `office_updated_at` datetime NULL DEFAULT NULL ON UPDATE current_timestamp(),
    `office_archived_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`office_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_user_offices` (
    `user_id` int(11) NOT NULL,
    `office_id` int(11) NOT NULL,
    PRIMARY KEY (`user_id`),
    KEY `mit_user_offices_office` (`office_id`),
    CONSTRAINT `mit_user_offices_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_user_offices_office_fk` FOREIGN KEY (`office_id`) REFERENCES `mit_offices` (`office_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
