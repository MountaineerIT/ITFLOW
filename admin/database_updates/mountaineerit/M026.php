<?php

/*
 * Mountaineer IT - database update M026
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Service boards (functions/mit_boards.php): who works a ticket (Service Desk, Tier 1, Tier 2, Sysadmin, Sales ...),
 * apart from its category (what it is about). A ticket with no row in mit_ticket_boards is on the default board, so
 * every ticket before this update starts there and stock ITFlow simply ignores these tables. Routing rules put new
 * tickets on a board by the address an email was sent to, the client or the category.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_boards` (
    `board_id` int(11) NOT NULL AUTO_INCREMENT,
    `board_name` varchar(100) NOT NULL,
    `board_description` varchar(255) NOT NULL DEFAULT '',
    `board_color` varchar(7) NOT NULL DEFAULT '#2f6fde',
    `board_icon` varchar(50) NOT NULL DEFAULT 'columns',
    `board_order` int(11) NOT NULL DEFAULT 0,
    `board_is_default` tinyint(1) NOT NULL DEFAULT 0,
    `board_created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `board_archived_at` datetime NULL DEFAULT NULL,
    PRIMARY KEY (`board_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_board_members` (
    `board_id` int(11) NOT NULL,
    `user_id` int(11) NOT NULL,
    `member_notify` tinyint(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`board_id`, `user_id`),
    KEY `mit_board_members_user` (`user_id`),
    CONSTRAINT `mit_board_members_board` FOREIGN KEY (`board_id`) REFERENCES `mit_boards` (`board_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_board_members_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_ticket_boards` (
    `ticket_id` int(11) NOT NULL,
    `board_id` int(11) NOT NULL,
    `board_set_at` datetime NOT NULL DEFAULT current_timestamp(),
    `board_set_by` int(11) NOT NULL DEFAULT 0,
    `board_set_how` varchar(20) NOT NULL DEFAULT 'moved',
    PRIMARY KEY (`ticket_id`),
    KEY `mit_ticket_boards_board` (`board_id`),
    CONSTRAINT `mit_ticket_boards_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE,
    CONSTRAINT `mit_ticket_boards_board` FOREIGN KEY (`board_id`) REFERENCES `mit_boards` (`board_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_board_rules` (
    `rule_id` int(11) NOT NULL AUTO_INCREMENT,
    `rule_kind` varchar(10) NOT NULL,
    `rule_match` varchar(255) NOT NULL,
    `board_id` int(11) NOT NULL,
    PRIMARY KEY (`rule_id`),
    UNIQUE KEY `mit_board_rules_match` (`rule_kind`, `rule_match`),
    CONSTRAINT `mit_board_rules_board` FOREIGN KEY (`board_id`) REFERENCES `mit_boards` (`board_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// The starting boards - only on a first run
if (intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_boards"))[0]) === 0) {
    $mit_boards = [
        ['Service Desk', 'Where new tickets land - triage, then work or move them', '#2f6fde', 'inbox', 1],
        ['Tier 1', 'Everyday support', '#1f9d61', 'headset', 0],
        ['Tier 2', 'Escalated, harder problems', '#d9930c', 'layer-group', 0],
        ['Sysadmin', 'Servers, networks and infrastructure', '#7c5cd6', 'server', 0],
        ['Sales', 'Quotes, orders and new business', '#e0484d', 'handshake', 0],
    ];
    foreach ($mit_boards as $mit_order => $mit_board) {
        mysqli_query($mysqli, "INSERT INTO mit_boards SET board_name = '" . mysqli_real_escape_string($mysqli, $mit_board[0]) . "',
            board_description = '" . mysqli_real_escape_string($mysqli, $mit_board[1]) . "', board_color = '$mit_board[2]',
            board_icon = '$mit_board[3]', board_is_default = $mit_board[4], board_order = $mit_order");
    }
}

// Tickets made from here on are routed (the cron job places the ones made by ways that do not route at once);
// older ones stay on the default board without anyone being notified
if (!mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_settings WHERE setting_name = 'boards_since'"))) {
    mysqli_query($mysqli, "INSERT INTO mit_settings SET setting_name = 'boards_since', setting_value = '" . date('Y-m-d H:i:s') . "'");
}
