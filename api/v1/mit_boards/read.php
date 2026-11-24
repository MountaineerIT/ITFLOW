<?php

/*
 * API - Service boards - Read (Mountaineer IT, functions/mit_boards.php, functions/mit_api.php)
 * GET /api/v1/mit_boards/read.php
 *
 * The service boards (Service Desk, Tier 1 ...), each with its members and how many open tickets it holds among the clients the key
 * can reach. With ticket_id: that ticket's board and every move it has made between boards. Needs Support view.
 *
 * Parameters (GET):
 *   board_id   optional - Only this board
 *   ticket_id  optional - This ticket's board and its moves (routed, moved, escalated ...)
 *   archived   optional - 1 = archived boards too
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

if (!function_exists('mitBoardsReady') || !mitBoardsReady()) {
    mitApiFail(503, 'Service boards need the database update.');
}

if (isset($_GET['ticket_id'])) {
    $ticket = mitApiTicket($_GET['ticket_id']);
    $board = mitBoards(true)[mitBoardOf($ticket['ticket_id'])] ?? null;
    $moves = [];
    if (mitBoardsPart2Ready()) {
        $names = array_map(function ($b) { return $b['board_name']; }, mitBoards(true));
        $result = mysqli_query($mysqli, "SELECT m.from_board_id, m.to_board_id, m.move_how, m.moved_by, user_name, m.moved_at FROM mit_board_moves m
            LEFT JOIN users ON user_id = m.moved_by WHERE m.ticket_id = " . intval($ticket['ticket_id']) . " ORDER BY m.moved_at, m.move_id");
        while ($row = mysqli_fetch_assoc($result)) {
            $moves[] = ['from_board_id' => $row['from_board_id'] === null ? null : intval($row['from_board_id']), 'from_board_name' => $names[intval($row['from_board_id'])] ?? null,
                'to_board_id' => intval($row['to_board_id']), 'to_board_name' => $names[intval($row['to_board_id'])] ?? null, 'how' => $row['move_how'],
                'by_user_id' => intval($row['moved_by']) ?: null, 'by_user_name' => $row['user_name'], 'at' => $row['moved_at']];
        }
    }
    mitApiList([[
        'ticket_id' => intval($ticket['ticket_id']),
        'board_id' => $board ? intval($board['board_id']) : null,
        'board_name' => $board['board_name'] ?? null,
        'moves' => $moves,
    ]], false);
}

$open = mitApiBoardOpenCounts();
$rows = [];
foreach (mitBoards(!empty($_GET['archived'])) as $board_id => $board) {
    if (isset($_GET['board_id']) && intval($_GET['board_id']) !== $board_id) {
        continue;
    }
    $row = mitApiBoard($board, $open);
    $row['board_archived_at'] = $board['board_archived_at'];
    $rows[] = $row;
}
mitApiList($rows);
