<?php

/*
 * Mountaineer IT - database update M027
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * Service boards, part 2 (functions/mit_boards.php):
 * - mit_board_moves: every time a ticket lands on or moves to a board (routed, opened on, moved, escalated), for the
 *   Board Performance report - mit_ticket_boards only holds where a ticket is now. Seeded from mit_ticket_boards.
 * - Auto-assign per board (round-robin, off by default): mit_boards.board_assign ('off' | 'round_robin'),
 *   board_assign_max (most open tickets a member may hold on the board before being skipped, NULL = no limit),
 *   board_assign_hours (1 = wait for Admin > SLA working hours), board_assign_last (who got the last one),
 *   board_assign_since (only tickets placed from then on are handed out, so turning it on does not deal out the backlog);
 *   mit_board_members.member_rotation (0 = a member who is not handed tickets).
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

mysqli_query($mysqli, "CREATE TABLE IF NOT EXISTS `mit_board_moves` (
    `move_id` int(11) NOT NULL AUTO_INCREMENT,
    `ticket_id` int(11) NOT NULL,
    `from_board_id` int(11) NULL DEFAULT NULL,
    `to_board_id` int(11) NOT NULL,
    `move_how` varchar(20) NOT NULL DEFAULT 'moved',
    `moved_by` int(11) NOT NULL DEFAULT 0,
    `moved_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`move_id`),
    KEY `mit_board_moves_when` (`moved_at`),
    KEY `mit_board_moves_ticket` (`ticket_id`),
    CONSTRAINT `mit_board_moves_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`ticket_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// Where tickets are now, as their first move (only on the first run - the table is empty then)
if (intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_board_moves"))[0]) === 0) {
    mysqli_query($mysqli, "INSERT INTO mit_board_moves (ticket_id, from_board_id, to_board_id, move_how, moved_by, moved_at)
        SELECT ticket_id, NULL, board_id, board_set_how, board_set_by, board_set_at FROM mit_ticket_boards");
}

$mit_columns = [
    'board_assign' => "varchar(20) NOT NULL DEFAULT 'off'",
    'board_assign_max' => "int(11) NULL DEFAULT NULL",
    'board_assign_hours' => "tinyint(1) NOT NULL DEFAULT 1",
    'board_assign_last' => "int(11) NOT NULL DEFAULT 0",
    'board_assign_since' => "datetime NULL DEFAULT NULL",
];
foreach ($mit_columns as $mit_column => $mit_definition) {
    if (!mitColumnExists($mysqli, 'mit_boards', $mit_column)) {
        mysqli_query($mysqli, "ALTER TABLE `mit_boards` ADD `$mit_column` $mit_definition");
    }
}

if (!mitColumnExists($mysqli, 'mit_board_members', 'member_rotation')) {
    mysqli_query($mysqli, "ALTER TABLE `mit_board_members` ADD `member_rotation` tinyint(1) NOT NULL DEFAULT 1");
}
