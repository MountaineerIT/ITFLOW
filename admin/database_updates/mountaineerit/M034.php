<?php

/*
 * Mountaineer IT - database update M034
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Service boards by role (functions/mit_board_roles.php): which boards each ITFlow role sees.
 * - mit_role_board_access: a role limited to chosen boards (no row = every board, so nothing changes until a role is limited).
 * - mit_role_boards: the boards a limited role sees.
 * Both follow ITFlow's roles (deleted with the role) and our boards.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_role_board_access` (
    `role_id` int(11) NOT NULL,
    `limited` tinyint(1) NOT NULL DEFAULT 0,
    `updated_by` int(11) NOT NULL DEFAULT 0,
    `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`role_id`),
    CONSTRAINT `mit_role_board_access_role` FOREIGN KEY (`role_id`) REFERENCES `user_roles` (`role_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_role_boards` (
    `role_id` int(11) NOT NULL,
    `board_id` int(11) NOT NULL,
    PRIMARY KEY (`role_id`, `board_id`),
    KEY `mit_role_boards_board` (`board_id`),
    CONSTRAINT `mit_role_boards_role` FOREIGN KEY (`role_id`) REFERENCES `user_roles` (`role_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_role_boards_board` FOREIGN KEY (`board_id`) REFERENCES `mit_boards` (`board_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
