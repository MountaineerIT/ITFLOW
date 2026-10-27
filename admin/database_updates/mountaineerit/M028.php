<?php

/*
 * Mountaineer IT - database update M028
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Times on ticket notes (functions/mit_reply_times.php): when the work on a note with time worked was done - a start and
 * an end, as the person's clock read them - so ticket time lines up with the time clock and counts on the day it was
 * worked. One row per note (ITFlow's ticket_replies); a note without a row ended when it was posted.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_reply_times` (
    `reply_id` int(11) NOT NULL,
    `work_start` datetime NOT NULL,
    `work_end` datetime NOT NULL,
    `set_by` int(11) NOT NULL DEFAULT 0,
    `set_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`reply_id`),
    KEY `mit_reply_times_start` (`work_start`),
    CONSTRAINT `mit_reply_times_reply` FOREIGN KEY (`reply_id`) REFERENCES `ticket_replies` (`ticket_reply_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
