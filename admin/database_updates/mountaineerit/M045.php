<?php

/*
 * Mountaineer IT - database update M045
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * AI helper, round 2 (functions/mit_ai_ask.php): the Ask AI panel's conversations - each belongs to one agent (it goes with them),
 * may be about a ticket (kept when the ticket goes, without it) - and their questions and answers, with the library passages each
 * answer was given (`sources`, JSON) and the agent's thumbs up / down. Questions are stored as cleaned before they were sent.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ai_conversations` (
    `conversation_id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `ticket_id` int(11) DEFAULT NULL,
    `title` varchar(255) NOT NULL DEFAULT '',
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`conversation_id`),
    KEY `mit_ai_conversations_user` (`user_id`, `updated_at`),
    CONSTRAINT `mit_ai_conversations_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_ai_conversations_ticket_fk` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ai_messages` (
    `message_id` int(11) NOT NULL AUTO_INCREMENT,
    `conversation_id` int(11) NOT NULL,
    `role` varchar(10) NOT NULL,
    `content` mediumtext NOT NULL,
    `sources` text DEFAULT NULL,
    `feedback` tinyint(4) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`message_id`),
    KEY `mit_ai_messages_conversation` (`conversation_id`, `message_id`),
    CONSTRAINT `mit_ai_messages_conversation_fk` FOREIGN KEY (`conversation_id`) REFERENCES `mit_ai_conversations` (`conversation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
