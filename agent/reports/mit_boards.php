<?php

/*
 * Mountaineer IT - Board Performance (service boards, functions/mit_boards.php): for each board over the period, the
 * tickets that came in (new) and were moved or escalated in, escalated on to another board, resolved, the typical first
 * reply and time to resolve, SLA targets met, and what is open on it now. Below: where escalations went (from -> to) and,
 * for one board, each agent on it.
 *
 * Arrivals and escalations come from the move log (mit_board_moves, database update M027 - it starts with where tickets
 * were when it ran); resolved tickets, replies, SLAs and open tickets count against the board a ticket is on now.
 */

require_once "includes/inc_report.php";
reportEnforce('mit_boards');

$period = reportPeriod('last_30');
$client_id = reportClientId();
$boards = mitBoards(true);
$board_pick = intval($_GET['board'] ?? 0);
$board_pick = isset($boards[$board_pick]) ? $board_pick : 0;
$board_sql = mitBoardSql();
$scope = reportTicketSql($client_id) . ($board_pick ? " AND $board_sql = $board_pick" : '');

$rows = [];
$blank = ['came_in' => 0, 'moved_in' => 0, 'escalated_out' => 0, 'resolved' => 0, 'response' => null, 'resolution' => null,
    'open_now' => 0, 'unassigned_now' => 0, 'breached_now' => 0, 'age' => null];
$row = function ($id) use (&$rows, $boards, $blank) {
    if (!isset($rows[$id])) {
        $rows[$id] = ['board_id' => $id, 'board' => $boards[$id]['board_name'] ?? 'Removed board', 'color' => $boards[$id]['board_color'] ?? '#999999'] + $blank
            + reportSlaFigures([]);
    }
    return $id;
};
foreach (array_keys($board_pick ? [$board_pick => 1] : mitBoards()) as $id) {
    $row($id);
}

// Arrivals: new tickets (routed / opened on it) and tickets moved or escalated in; escalations out
$moves_where = reportRangeSql('m.moved_at', $period) . ' AND ' . reportTicketSql($client_id);
$sql = mysqli_query($mysqli, "SELECT m.to_board_id, m.from_board_id, m.move_how, COUNT(*) AS n FROM mit_board_moves m JOIN tickets ON tickets.ticket_id = m.ticket_id
    WHERE $moves_where GROUP BY m.to_board_id, m.from_board_id, m.move_how");
$flows = [];
while ($move = mysqli_fetch_assoc($sql)) {
    $to = intval($move['to_board_id']);
    $from = $move['from_board_id'] === null ? null : intval($move['from_board_id']);
    $n = intval($move['n']);
    $new = in_array($move['move_how'], ['routed', 'created'], true) && $from === null;
    if ((!$board_pick || $to === $board_pick)) {
        $id = $row($to);
        $rows[$id][$new ? 'came_in' : 'moved_in'] += $n;
    }
    if ($move['move_how'] === 'escalated' && $from !== null && (!$board_pick || $from === $board_pick)) {
        $id = $row($from);
        $rows[$id]['escalated_out'] += $n;
    }
    if (!$new && in_array($move['move_how'], ['moved', 'escalated'], true) && (!$board_pick || $to === $board_pick || $from === $board_pick)) {
        $key = ($from ?? 0) . '-' . $to;
        $flows[$key] = $flows[$key] ?? ['from' => $from === null ? 'Before the move log' : ($boards[$from]['board_name'] ?? 'Removed board'),
            'to' => $boards[$to]['board_name'] ?? 'Removed board', 'moved' => 0, 'escalated' => 0];
        $flows[$key][$move['move_how']] += $n;
    }
}

// Resolved in the period, with how long they took; first replies of tickets raised in the period
$durations = ['resolution' => [], 'response' => []];
$sql = mysqli_query($mysqli, "SELECT $board_sql AS b, TIMESTAMPDIFF(SECOND, ticket_created_at, ticket_resolved_at) AS s FROM tickets
    WHERE $scope AND " . reportRangeSql('ticket_resolved_at', $period));
while ($t = mysqli_fetch_assoc($sql)) {
    $id = $row(intval($t['b']));
    $rows[$id]['resolved']++;
    $durations['resolution'][intval($t['b'])][] = floatval($t['s']);
}
$sql = mysqli_query($mysqli, "SELECT $board_sql AS b, TIMESTAMPDIFF(SECOND, ticket_created_at, ticket_first_response_at) AS s FROM tickets
    WHERE $scope AND ticket_first_response_at IS NOT NULL AND " . reportRangeSql('ticket_created_at', $period));
while ($t = mysqli_fetch_assoc($sql)) {
    $durations['response'][intval($t['b'])][] = floatval($t['s']);
}
foreach ($durations as $what => $by_board) {
    foreach ($by_board as $id => $values) {
        $id = $row($id);
        $rows[$id][$what] = reportMedian($values);
    }
}

// SLA targets on tickets raised in the period
$sql = mysqli_query($mysqli, "SELECT $board_sql AS k, " . REPORT_SLA_COLUMNS . " FROM tickets WHERE ticket_sla_id > 0 AND $scope AND " . reportRangeSql('ticket_created_at', $period) . " GROUP BY k");
while ($t = mysqli_fetch_assoc($sql)) {
    $id = $row(intval($t['k']));
    $rows[$id] = array_merge($rows[$id], reportSlaFigures($t));
}

// Open now (whenever raised)
$sql = mysqli_query($mysqli, "SELECT $board_sql AS b, COUNT(*) AS n, SUM(ticket_assigned_to = 0) AS unassigned, SUM(" . dashboardSlaBreachedSql() . ") AS breached,
    AVG(TIMESTAMPDIFF(SECOND, ticket_created_at, NOW())) AS age FROM tickets WHERE $scope AND " . TICKET_OPEN_SQL
    . (function_exists('mitServiceTicketsAnd') ? mitServiceTicketsAnd() : '') . " GROUP BY b");
while ($t = mysqli_fetch_assoc($sql)) {
    $id = $row(intval($t['b']));
    $rows[$id]['open_now'] = intval($t['n']);
    $rows[$id]['unassigned_now'] = intval($t['unassigned']);
    $rows[$id]['breached_now'] = intval($t['breached']);
    $rows[$id]['age'] = $t['age'] === null ? null : floatval($t['age']);
}
$rows = array_values($rows);

// One board: its agents
$agent_rows = [];
if ($board_pick) {
    $agents = [];
    $agent = function ($user_id) use (&$agents, $mysqli) {
        if (!isset($agents[$user_id])) {
            $user = $user_id ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name, user_avatar FROM users WHERE user_id = $user_id")) : null;
            $agents[$user_id] = ['user_id' => $user_id, 'agent' => $user['user_name'] ?? '', 'avatar' => $user['user_avatar'] ?? '', 'resolved' => 0, 'resolution' => [],
                'open_now' => 0, 'resolution_met' => 0, 'resolution_missed' => 0];
        }
        return $user_id;
    };
    foreach (mitBoardMembers($board_pick) as $user_id => $member) {
        $agent($user_id);
    }
    $sql = mysqli_query($mysqli, "SELECT ticket_assigned_to AS u, TIMESTAMPDIFF(SECOND, ticket_created_at, ticket_resolved_at) AS s, ticket_resolution_sla_met AS met
        FROM tickets WHERE $scope AND " . reportRangeSql('ticket_resolved_at', $period));
    while ($t = mysqli_fetch_assoc($sql)) {
        $u = $agent(intval($t['u']));
        $agents[$u]['resolved']++;
        $agents[$u]['resolution'][] = floatval($t['s']);
        if ($t['met'] !== null) {
            $agents[$u][intval($t['met']) ? 'resolution_met' : 'resolution_missed']++;
        }
    }
    $sql = mysqli_query($mysqli, "SELECT ticket_assigned_to AS u, COUNT(*) AS n FROM tickets WHERE $scope AND " . TICKET_OPEN_SQL . " GROUP BY u");
    while ($t = mysqli_fetch_assoc($sql)) {
        $u = $agent(intval($t['u']));
        $agents[$u]['open_now'] = intval($t['n']);
    }
    foreach ($agents as $a) {
        $a['resolution'] = reportMedian($a['resolution']);
        $a['resolution_percent'] = reportShare($a['resolution_met'], $a['resolution_met'] + $a['resolution_missed'], 1);
        $agent_rows[] = $a;
    }
    usort($agent_rows, function ($a, $b) { return [$b['resolved'], $b['open_now']] <=> [$a['resolved'], $a['open_now']]; });
}

$board_columns = [
    'board' => ['label' => 'Board', 'html' => function ($r) {
        return '<span class="mit-board-dot me-2" style="--mit-board:' . escapeHtml($r['color']) . '"></span>'
            . '<a href="' . escapeHtml(reportUrl(['board' => $r['board_id']])) . '">' . escapeHtml($r['board']) . '</a>';
    }],
    'came_in' => ['label' => 'New', 'type' => 'count', 'title' => 'New tickets that landed on the board'],
    'moved_in' => ['label' => 'Moved in', 'type' => 'count', 'title' => 'Moved or escalated here from another board'],
    'escalated_out' => ['label' => 'Escalated on', 'type' => 'count', 'title' => 'Escalated from here to another board'],
    'resolved' => ['label' => 'Resolved', 'type' => 'count'],
    'response' => ['label' => 'First reply', 'type' => 'duration', 'title' => 'Typical (median) time to the first reply, tickets raised in the period'],
    'resolution' => ['label' => 'Time to resolve', 'type' => 'duration', 'title' => 'Typical (median) time from opened to resolved'],
    'resolution_percent' => ['label' => 'SLA resolved in time', 'type' => 'percent', 'html' => function ($r) { return reportRateHtml($r['resolution_percent']) . reportLateHtml($r['resolution_missed']); }],
    'open_now' => ['label' => 'Open now', 'type' => 'count', 'html' => function ($r) {
        return '<a href="/agent/tickets.php?board=' . intval($r['board_id']) . '">' . reportCount($r['open_now']) . '</a>'
            . ($r['unassigned_now'] ? '<div class="small text-warning-emphasis">' . reportCount($r['unassigned_now']) . ' unassigned</div>' : '')
            . ($r['breached_now'] ? '<div class="small text-danger-emphasis">' . reportCount($r['breached_now']) . ' past SLA</div>' : '');
    }],
    'unassigned_now' => ['label' => 'Unassigned now', 'type' => 'count', 'screen' => false],
    'breached_now' => ['label' => 'Past SLA now', 'type' => 'count', 'screen' => false],
    'age' => ['label' => 'Average age open', 'type' => 'duration'],
];
$flow_columns = [
    'from' => ['label' => 'From'],
    'to' => ['label' => 'To'],
    'escalated' => ['label' => 'Escalated', 'type' => 'count'],
    'moved' => ['label' => 'Moved', 'type' => 'count'],
];
$flow_rows = array_values($flows);
usort($flow_rows, function ($a, $b) { return ($b['escalated'] + $b['moved']) <=> ($a['escalated'] + $a['moved']); });
$agent_columns = [
    'agent' => ['label' => 'Agent', 'csv' => function ($r) { return $r['user_id'] ? $r['agent'] : 'Unassigned'; }, 'html' => function ($r) {
        return formatAssignee($r['user_id'], $r['agent'], $r['avatar'], true);
    }],
    'resolved' => ['label' => 'Resolved', 'type' => 'count'],
    'resolution' => ['label' => 'Time to resolve', 'type' => 'duration'],
    'resolution_percent' => ['label' => 'SLA resolved in time', 'type' => 'percent', 'html' => function ($r) { return reportRateHtml($r['resolution_percent']) . reportLateHtml($r['resolution_missed']); }],
    'open_now' => ['label' => 'Open now', 'type' => 'count'],
];

if (reportExporting('flows')) {
    reportCsv('Board escalations', $flow_columns, $flow_rows);
}
if (reportExporting('agents') && $board_pick) {
    reportCsv('Board agents - ' . $boards[$board_pick]['board_name'], $agent_columns, $agent_rows);
}
if (reportExporting()) {
    reportCsv('Board Performance', $board_columns, $rows);
}

require_once "includes/inc_report_layout.php";

$sum = function ($key) use ($rows) { return array_sum(array_column($rows, $key)); };
$board_choices = ['0' => 'All boards'];
foreach (mitBoards() as $id => $b) {
    $board_choices[(string) $id] = $b['board_name'];
}
$export = ['boards' => 'By board', 'flows' => 'Escalations'] + ($board_pick ? ['agents' => 'By agent'] : []);

?>

<div class="itflow-report">

    <?= mitBoardStyle() ?>
    <?php reportHeader('mit_boards', [
        'period' => $period,
        'years' => reportPastYears("SELECT DISTINCT YEAR(moved_at) AS y FROM mit_board_moves"),
        'client' => $client_id,
        'controls' => reportSelectControl('board', (string) $board_pick, $board_choices, 'fa-columns'),
        'export' => $export,
    ]); ?>

    <?= reportStats([
        ['label' => 'New tickets', 'value' => reportCount($sum('came_in')), 'sub' => '<span class="text-body-tertiary">Landed on ' . ($board_pick ? 'the board' : 'a board') . '</span>'],
        ['label' => 'Escalated', 'value' => reportCount(array_sum(array_column($flow_rows, 'escalated'))), 'sub' => '<span class="text-body-tertiary">Between boards in the period</span>'],
        ['label' => 'Resolved', 'value' => reportCount($sum('resolved'))],
        ['label' => 'Open now', 'value' => reportCount($sum('open_now')), 'tone' => $sum('breached_now') ? 'danger' : '',
            'sub' => $sum('breached_now') ? reportCount($sum('breached_now')) . ' past SLA' : '<span class="text-body-tertiary">Whenever raised</span>',
            'url' => '/agent/tickets.php' . ($board_pick ? "?board=$board_pick" : '')],
    ]) ?>

    <?php if (!$rows) { ?>
        <div class="card"><div class="card-body"><?= reportEmpty('No boards yet - set them up under Admin > Settings > Boards.', 'fa-columns', 'secondary') ?></div></div>
    <?php } else { ?>

        <?php if (count($rows) > 1) { ?>
            <?= reportSectionOpen('In and out', 'New and moved-in tickets against those resolved') ?>
                <div class="card-body">
                    <?= reportChart([
                        'type' => 'bar',
                        'labels' => array_column($rows, 'board'),
                        'series' => [
                            ['label' => 'New', 'data' => array_column($rows, 'came_in'), 'tone' => 'accent', 'stack' => 'in'],
                            ['label' => 'Moved in', 'data' => array_column($rows, 'moved_in'), 'tone' => 'info', 'stack' => 'in'],
                            ['label' => 'Resolved', 'data' => array_column($rows, 'resolved'), 'tone' => 'success', 'stack' => 'out'],
                        ],
                        'format' => 'count',
                        'stacked' => true,
                    ], '', 'Tickets in and resolved by board') ?>
                </div>
            </div>
        <?php } ?>

        <?= reportSectionOpen($board_pick ? $boards[$board_pick]['board_name'] : 'By board', 'Resolved, replies and SLAs count against the board a ticket is on now') ?>
            <?= reportTable($board_columns, $rows) ?>
        </div>

        <?= reportSectionOpen('Escalations and moves', 'From one board to another, in the period') ?>
            <?php if ($flow_rows) { ?>
                <?= reportTable($flow_columns, $flow_rows) ?>
            <?php } else { ?>
                <div class="card-body"><?= reportEmpty('Nothing was escalated or moved between boards in this period.') ?></div>
            <?php } ?>
        </div>

        <?php if ($board_pick) { ?>
            <?= reportSectionOpen('Agents on ' . $boards[$board_pick]['board_name'], 'Who the ticket is assigned to') ?>
                <?php if ($agent_rows) { ?>
                    <?= reportTable($agent_columns, $agent_rows) ?>
                <?php } else { ?>
                    <div class="card-body"><?= reportEmpty('Nobody is on this board, and nothing on it was resolved in the period.', 'fa-users', 'secondary') ?></div>
                <?php } ?>
            </div>
        <?php } ?>

        <div class="small text-body-secondary px-1">
            New and moved-in tickets and escalations come from the board move log, which starts with database update M027 (tickets were then counted where they sat).
            Time to resolve and first reply are medians - half the tickets were quicker. Durations in the export are hours.
        </div>

    <?php } ?>

</div>

<?php require_once "../../includes/footer.php"; ?>
<script src="/js/reports.js"></script>
