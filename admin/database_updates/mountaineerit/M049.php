<?php

/*
 * Mountaineer IT - database update M049
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * ITFlow 3.5.1's agent invites (user_invites): an invited agent's login is made only when they accept, so what New User asked of ours
 * (Employment - the timesheet pay type -, Office) is kept here with the invite and put on the login when it is accepted
 * (functions/mit_invites.php). Goes with the invite (CASCADE).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

if (mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'user_invites'")) === 1) {
    mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_invite_extras` (
        `invite_id` int(11) NOT NULL,
        `extras` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`invite_id`),
        CONSTRAINT `mit_invite_extras_invite_fk` FOREIGN KEY (`invite_id`) REFERENCES `user_invites` (`invite_id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}
