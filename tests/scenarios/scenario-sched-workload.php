<?php
/*
 * Dispatch's queue, Workload and "This week" are ITFlow's own since 26.12 (functions/dispatch.php). Ours on them: the service-board
 * picker (?board= - mitDispatchBoardSql, one line each in dispatchQueue / dispatchWorkload / dispatchWeekStats): All shows every board,
 * one board only its tickets, "My boards" the boards the agent is on - and each queue card carries its board's tag (mitDispatchQueueBoards).
 * Two of the tech's tickets: one on the admin's board, one on the default board.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('dispatchWorkload') || !function_exists('mitBoardsReady') || !mitBoardsReady()) {
    echo "  FAIL ITFlow's Dispatch or our boards are not here\n";
    exit(1);
}
$tech = intval(scenarioValue("SELECT user_id FROM users WHERE user_email = 'tech@test.test'"));
$admin = $session_user_id;
$client = scenarioClient();
$tag = 'ZZwl' . date('His');
$mine = array_keys(array_intersect_key(mitBoardsOfUser($admin), mitBoardsVisible()));
$default = mitBoardDefaultId();
if (!$mine || in_array($default, $mine, true)) {
    echo "  FAIL the admin should be on a board other than the default (the test install's Sales)\n";
    exit(1);
}
$board = intval($mine[0]);
$tickets = [];
$new_ticket = function ($subject, $assigned) use (&$tickets, $mysqli, $client, $session_user_id) {
    $n = 700000 + count($tickets) + intval(date('s')) * 100;
    mysqli_query($mysqli, "INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = $n, ticket_subject = '$subject', ticket_details = 'scenario', ticket_priority = 'Low',
        ticket_status = 2, ticket_created_by = $session_user_id, ticket_assigned_to = $assigned, ticket_client_id = $client, ticket_url_key = 'zw$n'");
    return $tickets[] = mysqli_insert_id($mysqli);
};
$with_board = function ($choice, callable $read) {
    $_GET['board'] = $choice;
    try {
        return $read();
    } finally {
        unset($_GET['board']);
    }
};
$open_for = function ($choice) use ($with_board, $tech, $admin) {
    return $with_board($choice, function () use ($tech, $admin) {
        foreach (dispatchWorkload([$admin, $tech])['agents'] as $r) {
            if ($r['id'] === $tech) {
                return $r['open'];
            }
        }
        return null;
    });
};
$inbound_for = function ($choice) use ($with_board, $tech, $admin) {
    return $with_board($choice, function () use ($tech, $admin) {
        $today = date('Y-m-d');
        foreach (dispatchWeekStats([$admin, $tech])['days'] as $d) {
            if ($d['date'] === $today) {
                return $d['inbound'];
            }
        }
        return null;
    });
};
$queue = function ($choice) use ($with_board, $tag) {
    return $with_board($choice, function () use ($tag) {
        return dispatchQueue('unbooked', ['q' => $tag])['tickets'];
    });
};
try {
    $before = ['all' => $open_for('all'), 'one' => $open_for((string) $board), 'mine' => $open_for('mine')];
    $before_in = ['all' => $inbound_for('all'), 'one' => $inbound_for((string) $board)];
    $on_board = $new_ticket("$tag on board", $tech);
    $on_default = $new_ticket("$tag on default", $tech);
    mitBoardMove($on_board, $board);

    $ids = function ($rows) {
        $ids = array_column($rows, 'id');
        sort($ids);
        return $ids;
    };
    $both = [$on_board, $on_default];
    sort($both);
    scenarioCheck('queue: All shows both', $ids($queue('all')), $both);
    scenarioCheck('queue: one board shows its ticket only', $ids($queue((string) $board)), [$on_board]);
    scenarioCheck('queue: My boards shows the ticket on my board only', $ids($queue('mine')), [$on_board]);
    $card = array_values(array_filter($queue('all'), function ($t) use ($on_board) { return $t['id'] === $on_board; }))[0] ?? [];
    scenarioCheck("queue card carries its board's tag", $card['mit_board']['name'] ?? '', mitBoards()[$board]['board_name'] ?? 'x');
    scenarioCheck('with nothing picked, a board member gets My boards', mitDispatchBoardChoice(null), 'mine');

    scenarioCheck('Workload: All counts both of the tech\'s tickets', $open_for('all') - $before['all'], 2);
    scenarioCheck('Workload: one board counts its ticket only', $open_for((string) $board) - $before['one'], 1);
    scenarioCheck('Workload: My boards likewise', $open_for('mine') - $before['mine'], 1);
    scenarioCheck('This week: inbound today, All +2', $inbound_for('all') - $before_in['all'], 2);
    scenarioCheck('This week: inbound today, one board +1', $inbound_for((string) $board) - $before_in['one'], 1);
} finally {
    unset($_GET['board']);
    foreach ($tickets as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_boards WHERE ticket_id = $id");
        mysqli_query($mysqli, "DELETE FROM mit_board_moves WHERE ticket_id = $id");
        ticketDeleteForGood($id);
    }
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%$tag%'");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
