<?php

/*
 * Mountaineer IT - database update M043
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Timesheets (functions/mit_reply_times.php mitTsPublicNotesSql): moving a ticket to another client (ITFlow's Change client, and
 * Dispatch's) turns its public notes into internal ones, so the old client's contact doesn't see them. Their time was public ticket
 * time when it was logged - these rows remember which notes were public, so a timesheet that leaves internal notes out still counts
 * them. Goes with the note.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_reply_was_public` (
    `reply_id` int(11) NOT NULL,
    `moved_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`reply_id`),
    CONSTRAINT `mit_reply_was_public_reply` FOREIGN KEY (`reply_id`) REFERENCES `ticket_replies` (`ticket_reply_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
