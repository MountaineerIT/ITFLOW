<?php

/*
 * Mountaineer IT - "Left behind" on the calendar (agent/calendar.php): open tickets (not resolved, closed or archived)
 * whose booked time was on a day before today - the work was not done and nobody moved it on. Listed under To schedule,
 * one agent's or everyone's (the calendar's Whose picker), one client's inside a client; drag one onto the calendar to
 * book it again (calendar.js's own drop - the booking keeps its length), click for the quick look. Their old bookings are
 * marked on the calendar too (mitLeftBehindMark, from mitCalendarTicketItems).
 * No tables. Hooks: one line in agent/calendar.php (mitLeftBehindCard), one in agent/ajax.php (?mit_left_behind),
 * functions/calendar.php's tickets source (mitCalendarTicketItems).
 */

/** SQL: the ticket's booking ended before today and it is still open (tickets alias $t). */
function mitLeftBehindSql($t = 'tickets') {
    return "$t.ticket_schedule IS NOT NULL AND $t.ticket_resolved_at IS NULL AND $t.ticket_closed_at IS NULL AND $t.ticket_archived_at IS NULL
        AND COALESCE(IF($t.ticket_schedule_end > $t.ticket_schedule, $t.ticket_schedule_end, NULL),
            $t.ticket_schedule + INTERVAL " . intval(CALENDAR_TICKET_MINUTES) . " MINUTE) < CURDATE()";
}

/** The left-behind tickets: an agent's (0 = everyone's), in a client or all the agent can see - longest left first. */
function mitLeftBehindTickets($user_id = 0, $client_id = 0, $limit = 50) {
    global $mysqli;
    $user_id = intval($user_id);
    $client_id = intval($client_id);
    $sql = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_priority, ticket_onsite, ticket_schedule,
            ticket_client_id, client_name, ticket_assigned_to, user_name
        FROM tickets
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN users ON user_id = ticket_assigned_to
        WHERE " . mitLeftBehindSql() . "
        " . ($user_id ? "AND ticket_assigned_to = $user_id" : '') . "
        " . ($client_id ? "AND ticket_client_id = $client_id" : '') . "
        " . clientScopeSql('ticket_client_id') . mitBoardVisibleSql('tickets') . "
        ORDER BY ticket_schedule ASC
        LIMIT " . intval($limit));
    $tickets = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $days = intval((strtotime(date('Y-m-d')) - strtotime(substr($row['ticket_schedule'], 0, 10))) / 86400);
        $tickets[] = [
            'id' => intval($row['ticket_id']),
            'reference' => ticketReference($row),
            'subject' => $row['ticket_subject'],
            'client' => $row['client_name'] ?: 'Internal',
            'priority' => $row['ticket_priority'],
            'onsite' => intval($row['ticket_onsite']),
            'agent' => $row['user_name'] ?: 'Unassigned',
            'booked' => date('D M j', strtotime($row['ticket_schedule'])),
            'booked_date' => substr($row['ticket_schedule'], 0, 10),
            'days' => $days,
        ];
    }
    return $tickets;
}

/** agent/ajax.php?mit_left_behind&user_id=&client_id= - JSON for the list. */
function mitLeftBehindAjax() {
    header('Content-Type: application/json');
    $client_id = intval($_GET['client_id'] ?? 0);
    if (!calendarCanScheduleTickets() || ($client_id && !hasClientAccess($client_id))) {
        echo json_encode(['tickets' => []]);
        exit;
    }
    echo json_encode(['tickets' => mitLeftBehindTickets($_GET['user_id'] ?? 0, $client_id)]);
    exit;
}

/** The sidebar card (agent/calendar.php, under To schedule) and its script. */
function mitLeftBehindCard() {
    if (!calendarCanScheduleTickets()) {
        return '';
    }
    return '<style>
        .mit-left-behind-item .mit-left-behind-days { flex-shrink: 0; }
        #mitLeftBehind { max-height: 22rem; overflow-y: auto; }
        #itflowCalendar .mit-left-behind { outline: 2px dashed var(--bs-danger); outline-offset: -2px; }
    </style>
    <div class="card mb-3" id="mitLeftBehindCard">
        <div class="card-header py-2 d-flex align-items-center">
            <span class="fw-semibold text-nowrap"><i class="fas fa-fw fa-history text-danger me-1"></i>Left behind</span>
            <span class="badge rounded-pill text-bg-danger ms-auto" id="mitLeftBehindCount"></span>
        </div>
        <div class="card-body p-2">
            <div class="small text-body-secondary px-1 mb-2" id="mitLeftBehindHint">Open tickets booked on a day that has gone - drag one onto the calendar to book it again.</div>
            <div id="mitLeftBehind"></div>
        </div>
    </div>
    <script src="' . mitAsset('/agent/js/mit_left_behind.js') . '"></script>';
}

/** Mark the old bookings of left-behind tickets on the calendar (a dashed red outline, "Left behind" in the detail). */
function mitLeftBehindMark(array $items) {
    global $mysqli;
    $ids = [];
    foreach ($items as $item) {
        if (($item['extendedProps']['kind'] ?? '') === 'ticket' && !empty($item['end']) && $item['end'] < date('Y-m-d 00:00:00')) {
            $ids[] = intval($item['extendedProps']['ticket_id'] ?? 0);
        }
    }
    $ids = array_filter(array_unique($ids));
    if (!$ids) {
        return $items;
    }
    $left = [];
    $sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_id IN (" . implode(',', $ids) . ") AND " . mitLeftBehindSql());
    while ($row = mysqli_fetch_row($sql)) {
        $left[intval($row[0])] = true;
    }
    foreach ($items as &$item) {
        if (($item['extendedProps']['kind'] ?? '') === 'ticket' && isset($left[intval($item['extendedProps']['ticket_id'] ?? 0)])) {
            $item['className'] = trim(($item['className'] ?? '') . ' mit-left-behind');
            $item['extendedProps']['detail'] = trim('Left behind · ' . ($item['extendedProps']['detail'] ?? ''), ' ·');
        }
    }
    unset($item);
    return $items;
}

/** functions/calendar.php's tickets source: our changes to its items - left behind, then project bookings. */
function mitCalendarTicketItems(array $items, array $opts = []) {
    $items = function_exists('mitBoardVisibleCalendarItems') ? mitBoardVisibleCalendarItems($items) : $items;
    $items = mitLeftBehindMark($items);
    $items = function_exists('mitProjectCalendarItems') ? mitProjectCalendarItems($items, $opts) : $items;
    return mitServiceCalendarItems($items);
}
