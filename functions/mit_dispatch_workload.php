<?php

/*
 * Mountaineer IT - Dispatch's Workload view (required from functions/mit_dispatch.php; agent/mit_dispatch.php "Workload",
 * agent/js/mit_dispatch.js renderWorkload, ?workload= / POST assign= in agent/mit_dispatch_ajax.php): per tech, their open
 * tickets as lead or helper, ITFlow's SLA state for each (ticketSlaState), overdue due dates, unbooked and quiet tickets,
 * and booked hours today / this week against the working hours in Admin > SLA. "Falling behind" = an SLA breached or a
 * due date passed; "Overloaded" = well above the team average (mitDispatchWorkloadFlags). No tables of its own - who
 * assigned a ticket is in ITFlow's own ticket history.
 *
 * Also the "This week" dashboard under the board (mitDispatchWeekStats, ?week_stats=, renderStats in the script):
 * tickets in vs closed each day, closed against last week and the 4-week average, the week's SLA results, and per
 * tech the tickets assigned to them this week (created for them, or ITFlow's "assigned the ticket to" history line)
 * against those they closed.
 */

/** Days without a reply after which an open ticket (not waiting on the client) counts as quiet. */
const MIT_DISPATCH_QUIET_DAYS = 3;

/*
 * ---------- Workload ----------
 */

/** Working minutes a day and the working weekdays (ISO 1-7), from Admin > SLA. */
function mitDispatchWorkingDay() {
    $hours = mitDispatchBusinessHours();
    [$sh, $sm] = array_map('intval', explode(':', $hours['startTime']));
    [$eh, $em] = array_map('intval', explode(':', $hours['endTime']));
    $minutes = max(60, ($eh * 60 + $em) - ($sh * 60 + $sm));
    $days = array_map(function ($day) {
        return $day === 0 ? 7 : $day;
    }, $hours['daysOfWeek']);
    return [$minutes, $days];
}

/**
 * Each tech's load: [techs => [{id, name, color, open, lead, helping, sla: {breached, at_risk, running, paused, none},
 * overdue, unbooked, quiet, oldest, booked_today, booked_week, capacity_today, capacity_week, flags, tickets}], team =>
 * averages, week => [from, to]]. $first_day 0 = weeks start on Sunday, 1 = Monday (the agent's calendar setting).
 */
function mitDispatchWorkload(array $tech_ids, $first_day = 0, $board = '') {
    global $mysqli;
    $techs = mitDispatchTechs();
    $tech_ids = array_values(array_filter(array_unique(array_map('intval', $tech_ids)), function ($id) use ($techs) {
        return isset($techs[$id]);
    }));
    $today = date('Y-m-d');
    $offset = (intval(date('w')) - intval($first_day) + 7) % 7;
    $week_from = date('Y-m-d', strtotime("-$offset days"));
    $week_to = date('Y-m-d', strtotime("$week_from +6 days"));
    [$day_minutes, $work_days] = mitDispatchWorkingDay();
    $week_days = 0;
    for ($i = 0; $i < 7; $i++) {
        $week_days += in_array(intval(date('N', strtotime("$week_from +$i days"))), $work_days, true) ? 1 : 0;
    }
    $works_today = in_array(intval(date('N')), $work_days, true);

    $rows = [];
    foreach ($tech_ids as $id) {
        $rows[$id] = [
            'id' => $id, 'name' => $techs[$id]['name'], 'color' => $techs[$id]['color'],
            'open' => 0, 'lead' => 0, 'helping' => 0,
            'sla' => ['breached' => 0, 'at_risk' => 0, 'running' => 0, 'paused' => 0, 'none' => 0],
            'overdue' => 0, 'unbooked' => 0, 'quiet' => 0, 'oldest' => '',
            'booked_today' => 0, 'booked_week' => 0,
            'capacity_today' => $works_today ? $day_minutes : 0, 'capacity_week' => $week_days * $day_minutes,
            'flags' => [], 'tickets' => [],
        ];
    }
    if (!$tech_ids) {
        return ['techs' => [], 'team' => ['open' => 0, 'booked_week' => 0], 'week' => [$week_from, $week_to]];
    }
    $in = implode(',', $tech_ids);

    // Open tickets they lead or help on, with what the SLA pill and due pill need
    $helper_join = mitTicketTechsReady() ? "LEFT JOIN mit_ticket_techs tt ON tt.ticket_id = tickets.ticket_id AND tt.user_id IN ($in)" : '';
    $helper_col = mitTicketTechsReady() ? 'tt.user_id AS helper_id' : '0 AS helper_id';
    $helper_where = mitTicketTechsReady() ? " OR tt.user_id IS NOT NULL" : '';
    $result = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_priority, ticket_status,
            ticket_sla_id, ticket_response_sla_alert_stage, ticket_resolution_sla_alert_stage, ticket_response_sla_met, ticket_resolution_sla_met,
            ticket_first_response_at, ticket_response_due_at, ticket_resolution_due_at, ticket_due_at, ticket_resolved_at, ticket_closed_at,
            ticket_schedule, ticket_schedule_end, ticket_onsite, ticket_created_at, ticket_assigned_to, ticket_client_id,
            client_name, ticket_status_name, ticket_status_pauses_sla, $helper_col,
            (SELECT MAX(ticket_reply_created_at) FROM ticket_replies WHERE ticket_reply_ticket_id = tickets.ticket_id AND ticket_reply_type != 'Client') AS last_reply_at
        FROM tickets
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN ticket_statuses ON ticket_status_id = ticket_status
        $helper_join
        WHERE " . TICKET_OPEN_SQL . " AND ticket_archived_at IS NULL" . clientScopeSql('ticket_client_id') . mitDispatchBoardWhere($board) . "
            AND (ticket_assigned_to IN ($in)$helper_where)");
    $tickets = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $tickets[] = $row;
    }
    $order = ['breached' => 0, 'at_risk' => 1, 'running' => 2, 'paused' => 3, 'none' => 4];
    $quiet_before = time() - MIT_DISPATCH_QUIET_DAYS * 86400;
    $seen = [];

    foreach ($tickets as $row) {
        $lead = intval($row['ticket_assigned_to']);
        $helper = intval($row['helper_id']);
        // The lead's row, and the helper's (a ticket can be on two rows)
        $owners = [];
        if (isset($rows[$lead])) {
            $owners[$lead] = 'lead';
        }
        if ($helper && isset($rows[$helper]) && $helper !== $lead) {
            $owners[$helper] = 'helper';
        }
        $sla = ticketSlaState($row);
        $due = ticketDueState($row);
        $last = max(strtotime($row['ticket_created_at']), $row['last_reply_at'] ? strtotime($row['last_reply_at']) : 0);
        $quiet = $last < $quiet_before && $sla['key'] !== 'paused';
        foreach ($owners as $tech_id => $role) {
            // A ticket with two of these techs helping comes back twice - count it once per tech
            if (isset($seen[$row['ticket_id'] . '-' . $tech_id])) {
                continue;
            }
            $seen[$row['ticket_id'] . '-' . $tech_id] = true;
            $r = &$rows[$tech_id];
            $r['open']++;
            $r[$role === 'lead' ? 'lead' : 'helping']++;
            $r['sla'][$sla['key'] === 'met' || $sla['key'] === 'missed' ? 'none' : $sla['key']]++;
            $r['overdue'] += ($due['key'] ?? '') === 'overdue' ? 1 : 0;
            $r['unbooked'] += $role === 'lead' && empty($row['ticket_schedule']) ? 1 : 0;
            $r['quiet'] += $quiet ? 1 : 0;
            if ($r['oldest'] === '' || $row['ticket_created_at'] < $r['oldest']) {
                $r['oldest'] = $row['ticket_created_at'];
            }
            $r['tickets'][] = [
                'id' => intval($row['ticket_id']),
                'reference' => ticketReference($row),
                'subject' => (string) $row['ticket_subject'],
                'client' => $row['client_name'] ?: 'Internal',
                'client_id' => intval($row['ticket_client_id']),
                'priority' => (string) $row['ticket_priority'],
                'status' => (string) $row['ticket_status_name'],
                'role' => $role,
                'sla' => $sla['key'],
                'sla_due' => $sla['due'] ?? '',
                'sla_html' => ticketSlaPill($sla),
                'due_html' => ($due['key'] ?? '') === 'overdue' || ($due['key'] ?? '') === 'soon' ? ticketDuePill($due) : '',
                'overdue' => ($due['key'] ?? '') === 'overdue',
                'quiet' => $quiet,
                'last' => timeAgo(date('Y-m-d H:i:s', $last)),
                'scheduled' => $row['ticket_schedule'] ? date('D M j, g:i A', strtotime($row['ticket_schedule'])) : '',
                'schedule_start' => (string) $row['ticket_schedule'],
                'minutes' => mitTicketScheduleMinutes($row['ticket_schedule'], $row['ticket_schedule_end']),
                'onsite' => intval($row['ticket_onsite']),
                'age' => timeAgo($row['ticket_created_at']),
                'url' => ticketUrl($row['ticket_id'], $row['ticket_client_id']),
            ];
            unset($r);
        }
    }

    // Booked time this week (their tickets and helper bookings - not Outlook busy times)
    foreach (mitDispatchItems($week_from, $week_to, $tech_ids) as $item) {
        $kind = $item['extendedProps']['kind'] ?? '';
        $tech_id = intval($item['extendedProps']['tech'] ?? 0);
        if (!isset($rows[$tech_id]) || ($kind !== 'ticket' && $kind !== 'helper') || empty($item['start']) || empty($item['end'])) {
            continue;
        }
        $start = strtotime($item['start']);
        $minutes = max(0, intval((strtotime($item['end']) - $start) / 60));
        $rows[$tech_id]['booked_week'] += $minutes;
        if (date('Y-m-d', $start) === $today) {
            $rows[$tech_id]['booked_today'] += $minutes;
        }
    }

    // Most urgent first: SLA state, then the clock that runs out first, then the oldest
    foreach ($rows as &$r) {
        usort($r['tickets'], function ($a, $b) use ($order) {
            return [$order[$a['sla']] ?? 9, $a['overdue'] ? 0 : 1, $a['sla_due'] ?: '9999', $a['id']] <=> [$order[$b['sla']] ?? 9, $b['overdue'] ? 0 : 1, $b['sla_due'] ?: '9999', $b['id']];
        });
        $r['oldest'] = $r['oldest'] !== '' ? timeAgo($r['oldest']) : '';
    }
    unset($r);

    $team = mitDispatchWorkloadFlags($rows);
    return ['techs' => array_values($rows), 'team' => $team, 'week' => [$week_from, $week_to]];
}

/**
 * Sets each row's flags: behind (an SLA breached or a due date passed), watch (an SLA close to breaching),
 * overloaded (well above the team average in open tickets or booked hours, or booked past their working week),
 * room (well below it). Returns the team averages: [open, booked_week].
 */
function mitDispatchWorkloadFlags(array &$rows) {
    $count = count($rows);
    $avg_open = $count ? array_sum(array_column($rows, 'open')) / $count : 0;
    $avg_booked = $count ? array_sum(array_column($rows, 'booked_week')) / $count : 0;
    foreach ($rows as &$r) {
        $flags = [];
        if ($r['sla']['breached'] || $r['overdue']) {
            $parts = [];
            if ($r['sla']['breached']) {
                $parts[] = $r['sla']['breached'] . ' SLA breached';
            }
            if ($r['overdue']) {
                $parts[] = $r['overdue'] . ' past due';
            }
            $flags[] = ['key' => 'behind', 'text' => 'Falling behind', 'why' => implode(', ', $parts)];
        } elseif ($r['sla']['at_risk']) {
            $flags[] = ['key' => 'watch', 'text' => 'Watch', 'why' => $r['sla']['at_risk'] . ' close to the SLA'];
        }
        $why = [];
        // Compared with the team only when there is a team to compare with
        if ($count > 1 && $r['open'] >= 1.5 * $avg_open && $r['open'] - $avg_open >= 3) {
            $why[] = $r['open'] . ' open (team average ' . round($avg_open, 1) . ')';
        }
        if ($count > 1 && $r['booked_week'] >= 1.5 * $avg_booked && $r['booked_week'] - $avg_booked >= 240) {
            $why[] = round($r['booked_week'] / 60, 1) . ' h booked this week (team average ' . round($avg_booked / 60, 1) . ' h)';
        }
        if ($r['capacity_week'] && $r['booked_week'] > $r['capacity_week']) {
            $why[] = 'booked past their working week';
        }
        if ($why) {
            $flags[] = ['key' => 'overloaded', 'text' => 'Overloaded', 'why' => implode('; ', $why)];
        } elseif ($count > 1 && $avg_open >= 2 && $r['open'] <= 0.5 * $avg_open && $r['booked_week'] <= 0.5 * max($avg_booked, 1) && !$r['sla']['breached']) {
            $flags[] = ['key' => 'room', 'text' => 'Has room', 'why' => $r['open'] . ' open (team average ' . round($avg_open, 1) . ')'];
        }
        $r['flags'] = $flags;
    }
    unset($r);
    return ['open' => round($avg_open, 1), 'booked_week' => intval(round($avg_booked))];
}

/** Give a ticket to another tech from the Workload view (not booked - a booked one goes through the Schedule dialog). */
function mitDispatchAssign($ticket_id, $tech_id) {
    $row = ticketRow($ticket_id);
    if (!$row || !empty($row['ticket_archived_at'])) {
        return [false, 'That ticket no longer exists.'];
    }
    if (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id'])) {
        return [false, 'You do not have access to that client.'];
    }
    if (!ticketIsOpen($row)) {
        return [false, 'Ticket ' . ticketReference($row) . ' is resolved or closed.'];
    }
    $tech_id = intval($tech_id);
    if (!isset(mitDispatchTechs()[$tech_id])) {
        return [false, 'Pick an agent.'];
    }
    if (intval($row['ticket_client_id']) && !mitAgentCanSeeClient($tech_id, $row['ticket_client_id'])) {
        return [false, mitDispatchTechs()[$tech_id]['name'] . ' does not have access to this client.'];
    }
    if ($tech_id === intval($row['ticket_assigned_to'])) {
        return [true, ticketReference($row) . ' is already theirs.'];
    }
    if (!empty($row['ticket_schedule'])) {
        return [false, ticketReference($row) . ' is booked - move the booking instead, so the new agent gets the invite.'];
    }
    [$ok, $message] = ticketChangeAssignee($row['ticket_id'], $tech_id);
    return [$ok, $ok ? ticketReference($row) . ' given to ' . mitDispatchTechs()[$tech_id]['name'] . '.' : strip_tags($message)];
}

/*
 * ---------- This week ----------
 */

/** Tickets closed (resolved, or closed without resolving) - the moment it was done. */
const MIT_DISPATCH_DONE_AT = "COALESCE(tickets.ticket_resolved_at, tickets.ticket_closed_at)";

/** Done between two SQL datetimes ($to inclusive with '<=', exclusive with '<') - the same as MIT_DISPATCH_DONE_AT in the
 * range, written so the resolved / closed indexes (M030) can be used. */
function mitDispatchDoneIn($from, $to, $to_op = '<=') {
    return "(tickets.ticket_resolved_at >= $from AND tickets.ticket_resolved_at $to_op $to
        OR (tickets.ticket_resolved_at IS NULL AND tickets.ticket_closed_at >= $from AND tickets.ticket_closed_at $to_op $to))";
}

/**
 * The week's numbers for the dashboard under the board: [week => [from, to], days => [{date, label, inbound, closed}],
 * totals => [inbound, closed, net, open, last_inbound, last_closed, avg_closed], sla => [response_met, response_missed,
 * resolution_met, resolution_missed, breached_now, at_risk_now], techs => [{id, name, color, assigned, closed, open,
 * sla_missed}]]. $first_day 0 = weeks start on Sunday, 1 = Monday.
 */
function mitDispatchWeekStats(array $tech_ids, $first_day = 0, $board = '') {
    global $mysqli;
    $offset = (intval(date('w')) - intval($first_day) + 7) % 7;
    $from = date('Y-m-d', strtotime("-$offset days"));
    $to = date('Y-m-d', strtotime("$from +6 days"));
    $start = "'$from 00:00:00'";
    $end = "'$to 23:59:59'";
    $scope = "tickets.ticket_archived_at IS NULL" . clientScopeSql('tickets.ticket_client_id') . mitDispatchBoardWhere($board);
    $one = function ($sql) use ($mysqli) {
        return intval(mysqli_fetch_row(mysqli_query($mysqli, $sql))[0] ?? 0);
    };
    $done = MIT_DISPATCH_DONE_AT;

    // Each day: in and closed
    $days = [];
    for ($i = 0; $i < 7; $i++) {
        $date = date('Y-m-d', strtotime("$from +$i days"));
        $days[$date] = ['date' => $date, 'label' => date('D j', strtotime($date)), 'inbound' => 0, 'closed' => 0, 'future' => $date > date('Y-m-d')];
    }
    $result = mysqli_query($mysqli, "SELECT DATE(ticket_created_at) AS d, COUNT(*) AS n FROM tickets WHERE $scope AND ticket_created_at BETWEEN $start AND $end GROUP BY d");
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($days[$row['d']])) {
            $days[$row['d']]['inbound'] = intval($row['n']);
        }
    }
    $result = mysqli_query($mysqli, "SELECT DATE($done) AS d, COUNT(*) AS n FROM tickets WHERE $scope AND " . mitDispatchDoneIn($start, $end) . " GROUP BY d");
    while ($row = mysqli_fetch_assoc($result)) {
        if (isset($days[$row['d']])) {
            $days[$row['d']]['closed'] = intval($row['n']);
        }
    }
    $inbound = array_sum(array_column($days, 'inbound'));
    $closed = array_sum(array_column($days, 'closed'));

    // Last week, and the four weeks before this one
    $last_from = date('Y-m-d', strtotime("$from -7 days"));
    $four_from = date('Y-m-d', strtotime("$from -28 days"));
    $totals = [
        'inbound' => $inbound,
        'closed' => $closed,
        'net' => $inbound - $closed,
        'open' => $one("SELECT COUNT(*) FROM tickets WHERE $scope AND " . TICKET_OPEN_SQL),
        'last_inbound' => $one("SELECT COUNT(*) FROM tickets WHERE $scope AND ticket_created_at >= '$last_from' AND ticket_created_at < '$from'"),
        'last_closed' => $one("SELECT COUNT(*) FROM tickets WHERE $scope AND " . mitDispatchDoneIn("'$last_from'", "'$from'", '<')),
        'avg_closed' => round($one("SELECT COUNT(*) FROM tickets WHERE $scope AND " . mitDispatchDoneIn("'$four_from'", "'$from'", '<')) / 4, 1),
    ];

    // SLA results this week: responses due this week, resolutions due or done this week - judged ones only
    $response = "tickets.ticket_sla_id > 0 AND tickets.ticket_response_due_at BETWEEN $start AND $end";
    $done_week = mitDispatchDoneIn($start, $end);
    $resolution = "tickets.ticket_sla_id > 0 AND (tickets.ticket_resolution_due_at BETWEEN $start AND $end OR $done_week)";
    $sla = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT
            SUM($response AND ticket_response_sla_met = 1) AS response_met,
            SUM($response AND ticket_response_sla_met = 0) AS response_missed,
            SUM($resolution AND ticket_resolution_sla_met = 1) AS resolution_met,
            SUM($resolution AND ticket_resolution_sla_met = 0) AS resolution_missed,
            SUM(" . TICKET_OPEN_SQL . " AND ticket_sla_id > 0 AND GREATEST(ticket_response_sla_alert_stage, ticket_resolution_sla_alert_stage) = 2) AS breached_now,
            SUM(" . TICKET_OPEN_SQL . " AND ticket_sla_id > 0 AND GREATEST(ticket_response_sla_alert_stage, ticket_resolution_sla_alert_stage) = 1) AS at_risk_now
        FROM tickets WHERE $scope AND (tickets.ticket_response_due_at BETWEEN $start AND $end
            OR tickets.ticket_resolution_due_at BETWEEN $start AND $end OR $done_week OR " . TICKET_OPEN_SQL . ")"));
    $sla = array_map('intval', $sla ?: []);

    // Per tech: assigned to them this week (made for them, or ITFlow's history line) against closed by them this week.
    // The week's "assigned the ticket to <name>" lines are read once and shared out by name here - the longest name that
    // fits, so "Bob Smith" is not counted for "Bob" too
    $techs = mitDispatchTechs();
    $ids = array_values(array_filter(array_unique(array_map('intval', $tech_ids)), function ($id) use ($techs) { return isset($techs[$id]); }));
    $assigned_to = array_fill_keys($ids, []);
    if ($ids) {
        $result = mysqli_query($mysqli, "SELECT ticket_id, ticket_assigned_to FROM tickets WHERE $scope AND ticket_created_at BETWEEN $start AND $end
            AND ticket_assigned_to IN (" . implode(',', $ids) . ")");
        while ($row = mysqli_fetch_row($result)) {
            $assigned_to[intval($row[1])][intval($row[0])] = true;
        }
        $by_name = [];
        foreach ($ids as $id) {
            $by_name[$id] = (string) $techs[$id]['name'];
        }
        uasort($by_name, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
        $result = mysqli_query($mysqli, "SELECT ticket_history_ticket_id, ticket_history_description FROM ticket_history
            JOIN tickets ON tickets.ticket_id = ticket_history_ticket_id
            WHERE $scope AND ticket_history_created_at BETWEEN $start AND $end AND ticket_history_description LIKE '%assigned the ticket to %'");
        while ($row = mysqli_fetch_row($result)) {
            foreach ($by_name as $id => $name) {
                if ($name !== '' && preg_match('/assigned the ticket to ' . preg_quote($name, '/') . '( |$)/iu', (string) $row[1])) {
                    $assigned_to[$id][intval($row[0])] = true;
                    break;
                }
            }
        }
    }
    $rows = [];
    foreach ($ids as $id) {
        $assigned = count($assigned_to[$id]);
        $rows[] = [
            'id' => $id,
            'name' => $techs[$id]['name'],
            'color' => $techs[$id]['color'],
            'assigned' => $assigned,
            'closed' => $one("SELECT COUNT(*) FROM tickets WHERE $scope AND ticket_assigned_to = $id AND " . mitDispatchDoneIn($start, $end)),
            'open' => $one("SELECT COUNT(*) FROM tickets WHERE $scope AND ticket_assigned_to = $id AND " . TICKET_OPEN_SQL),
            'sla_missed' => $one("SELECT COUNT(*) FROM tickets WHERE $scope AND ticket_assigned_to = $id
                AND (($response AND ticket_response_sla_met = 0) OR ($resolution AND ticket_resolution_sla_met = 0))"),
        ];
    }
    return ['week' => [$from, $to], 'days' => array_values($days), 'totals' => $totals, 'sla' => $sla, 'techs' => $rows];
}
