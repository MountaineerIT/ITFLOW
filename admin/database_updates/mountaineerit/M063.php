<?php

/*
 * Mountaineer IT - database update M063
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Where a ticket goes when its client replies, per status (functions/mit_reply_status.php - the user's request for a "Client
 * responded" status): mit_ticket_status_reply. Both columns point at ITFlow's ticket_statuses with ON DELETE CASCADE, so deleting
 * either status drops the row and ITFlow's own answer applies again. Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_status_reply` (
    `ticket_status_id` int(11) NOT NULL,
    `reply_status_id` int(11) NOT NULL,
    PRIMARY KEY (`ticket_status_id`),
    KEY `mit_ticket_status_reply_to` (`reply_status_id`),
    CONSTRAINT `mit_ticket_status_reply_status` FOREIGN KEY (`ticket_status_id`) REFERENCES `ticket_statuses` (`ticket_status_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_ticket_status_reply_target` FOREIGN KEY (`reply_status_id`) REFERENCES `ticket_statuses` (`ticket_status_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
