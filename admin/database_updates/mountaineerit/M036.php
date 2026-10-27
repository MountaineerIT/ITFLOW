<?php

/*
 * Mountaineer IT - database update M036
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Customer Thermometer (functions/mit_ct.php): one survey per resolved ticket - who it went to, the one-person blast
 * Customer Thermometer made for it (how a response is matched back), and the response (temperature 1 Gold, 2 Green,
 * 3 Yellow, 4 Red, comment). A deleted ticket keeps its review (ticket_id goes NULL) so client scores keep their history.
 * Settings are in mit_settings ct_*.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ct_surveys` (
    `survey_id` int(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` int(11) NULL DEFAULT NULL,
    `ticket_ref` varchar(50) NOT NULL DEFAULT '',
    `client_id` int(11) NOT NULL DEFAULT 0,
    `contact_id` int(11) NOT NULL DEFAULT 0,
    `tech_user_id` int(11) NOT NULL DEFAULT 0,
    `tech_name` varchar(200) NOT NULL DEFAULT '',
    `email` varchar(255) NOT NULL DEFAULT '',
    `status` varchar(10) NOT NULL DEFAULT 'sent',
    `blast_id` varchar(64) NULL DEFAULT NULL,
    `error` varchar(500) NULL DEFAULT NULL,
    `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
    `temperature` tinyint(1) NULL DEFAULT NULL,
    `comment` text NULL DEFAULT NULL,
    `responded_at` datetime NULL DEFAULT NULL,
    `response_data` text NULL DEFAULT NULL,
    PRIMARY KEY (`survey_id`),
    UNIQUE KEY `mit_ct_surveys_ticket` (`ticket_id`),
    KEY `mit_ct_surveys_blast` (`blast_id`),
    KEY `mit_ct_surveys_client` (`client_id`, `responded_at`),
    KEY `mit_ct_surveys_responded` (`responded_at`),
    CONSTRAINT `mit_ct_surveys_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
