<?php

/*
 * Mountaineer IT - database update M021
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Email reply recipients picked per ticket (functions/mit_reply_recipients.php): the address and whether it is
 * ticked, kept only where it differs from the default (the contact and watchers ticked, anyone else not), so
 * the next email reply on the ticket starts from the same choice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_mail_choices` (
    `ticket_id` int(11) NOT NULL,
    `email` varchar(255) NOT NULL,
    `send` tinyint(1) NOT NULL DEFAULT 0,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`ticket_id`, `email`),
    CONSTRAINT `mit_ticket_mail_choices_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
