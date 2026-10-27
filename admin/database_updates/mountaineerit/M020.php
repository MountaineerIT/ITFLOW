<?php

/*
 * Mountaineer IT - database update M020
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Project tickets kept apart (functions/mit_projects.php): a ticket in a project has no SLA. This remembers
 * which tickets are in a project and when one last left, so the SLA it gets back is counted from then
 * rather than from when the ticket was created. Today's project tickets are marked as in one; the
 * mit_project_tickets cron job takes their SLA off (it needs the business-hours maths of a timezone-set process).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_project_ticket_sla` (
    `ticket_id` int(11) NOT NULL,
    `in_project` tinyint(1) NOT NULL DEFAULT 1,
    `left_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`ticket_id`),
    CONSTRAINT `mit_project_ticket_sla_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "INSERT IGNORE INTO mit_project_ticket_sla (ticket_id, in_project) SELECT ticket_id, 1 FROM tickets WHERE ticket_project_id > 0");
