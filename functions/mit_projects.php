<?php

/*
 * Mountaineer IT - project tickets kept apart from service tickets (Admin > Settings > Project, card
 * admin/includes/mit_project_card.php). A ticket in a project (ITFlow's own ticket_project_id):
 *   - is off the Tickets board (its own "Projects" pill there; still found with the Project filter),
 *     the client's Tickets tab, the Dispatch queue and the dashboard's unassigned count - but stays in a
 *     tech's own "My tickets", the Dispatch workload, the calendar and Dispatch bookings;
 *   - has no SLA - the project's due dates are what count. When it leaves the project it gets the SLA
 *     back, counted from that moment (mit_project_ticket_sla, M020) so it is not breached at once;
 *   - is drawn on the calendar and Dispatch in the project color, with a dot in the tech's color.
 * Switch off = ITFlow's own behaviour (and tickets in projects get their SLA back from that moment).
 */

/** Whether project tickets are kept apart (default on). */
function mitProjectTicketsApart() {
    return mitSetting('project_tickets_apart', '1') !== '0';
}

/** The color project bookings are drawn in. */
function mitProjectTicketColor() {
    return calendarColor(mitSetting('project_ticket_color', '#8e44ad'), '#8e44ad');
}

/** " AND <column> = 0" when kept apart - for a WHERE that should only see service tickets. */
function mitServiceTicketsAnd($column = 'ticket_project_id') {
    return mitProjectTicketsApart() ? " AND $column = 0" : '';
}

function mitProjectTicketsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_project_ticket_sla'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * The Tickets page's views (agent/tickets.php $shows): service tickets only, plus a "Projects" view of the open
 * project ones - unless a project was picked in the filters, which shows that project's tickets as before. Mine,
 * Closed and All keep them.
 */
function mitProjectTicketsShows(array &$shows, $base_sql, $project_filter) {
    if (!mitProjectTicketsApart() || intval($project_filter)) {
        return;
    }
    $out = [];
    foreach ($shows as $key => $show) {
        if ($key === 'closed') {
            $out['projects'] = mitProjectTicketsShow($base_sql);
        }
        // A tech's own tickets, the closed ones and All keep project tickets - otherwise a finished project ticket could only be
        // found with the Project filter
        if (!in_array($key, ['mine', 'closed', 'all'], true)) {
            $show['where'] = '(' . $show['where'] . ') AND tickets.ticket_project_id = 0';
        }
        $out[$key] = $show;
    }
    if (!isset($out['projects'])) {
        $out['projects'] = mitProjectTicketsShow($base_sql);
    }
    $shows = $out;
}

function mitProjectTicketsShow($base_sql) {
    return ['label' => 'Projects', 'icon' => 'fa-project-diagram', 'where' => "($base_sql) AND tickets.ticket_project_id > 0",
        'title' => 'Tickets that are part of a project - kept off the other views, with no SLA', 'divider' => true];
}

/**
 * From applyTicketSla(): true when this ticket must have no SLA (it is in a project). Also notes when it
 * joins or leaves one, for mitProjectSlaStart(). Only an open ticket changes: a resolved or closed project
 * ticket with no SLA keeps none, one that has an SLA is left to ITFlow, and nothing is noted.
 */
function mitProjectSlaOff($ticket_id) {
    global $mysqli;
    if (!mitProjectTicketsReady()) {
        return false;
    }
    $ticket_id = intval($ticket_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_project_id, ticket_sla_id, in_project, " . TICKET_OPEN_SQL . " AS is_open FROM tickets
        LEFT JOIN mit_project_ticket_sla s ON s.ticket_id = tickets.ticket_id WHERE tickets.ticket_id = $ticket_id"));
    if (!$row) {
        return false;
    }
    if (!intval($row['is_open'])) {
        return intval($row['ticket_project_id']) && !intval($row['ticket_sla_id']) && mitProjectTicketsApart();
    }
    if (intval($row['ticket_project_id']) && mitProjectTicketsApart()) {
        if ($row['in_project'] === null || !intval($row['in_project'])) {
            mysqli_query($mysqli, "INSERT INTO mit_project_ticket_sla (ticket_id, in_project) VALUES ($ticket_id, 1)
                ON DUPLICATE KEY UPDATE in_project = 1");
        }
        return true;
    }
    if (intval($row['in_project'] ?? 0)) {
        mysqli_query($mysqli, "UPDATE mit_project_ticket_sla SET in_project = 0, left_at = NOW() WHERE ticket_id = $ticket_id");
    }
    return false;
}

/** From applyTicketSla(): when its SLA counts from - when it last left a project, else when it was created. */
function mitProjectSlaStart($ticket_id, $created_at) {
    global $mysqli;
    if (!mitProjectTicketsReady()) {
        return $created_at;
    }
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT left_at FROM mit_project_ticket_sla WHERE ticket_id = " . intval($ticket_id) . " AND in_project = 0"));
    return ($row && $row[0] && strtotime($row[0]) > strtotime($created_at)) ? $row[0] : $created_at;
}

/** A ticket was put in or taken out of a project: its SLA goes or comes back now. */
function mitProjectTicketMoved($ticket_id) {
    if (mitProjectTicketsReady()) {
        applyTicketSla(intval($ticket_id));
    }
}

/**
 * The cron job (cron/mit_project_tickets.php, every minute): catches the ways in and out that do not call
 * applyTicketSla (the API, deleting a project, switching the setting) - project tickets still carrying an
 * SLA, and tickets that left a project without it being noticed. Open tickets only - a resolved or closed
 * ticket's SLA data is never touched.
 */
function mitProjectTicketsSweep() {
    global $mysqli;
    if (!mitProjectTicketsReady()) {
        return 0;
    }
    $ids = [];
    if (mitProjectTicketsApart()) {
        $sql = mysqli_query($mysqli, "SELECT ticket_id FROM tickets WHERE ticket_project_id > 0 AND ticket_sla_id > 0 AND " . TICKET_OPEN_SQL . " LIMIT 200");
        while ($row = mysqli_fetch_row($sql)) {
            $ids[] = intval($row[0]);
        }
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_project_ticket_sla (ticket_id, in_project) SELECT ticket_id, 1 FROM tickets WHERE ticket_project_id > 0 AND " . TICKET_OPEN_SQL);
    }
    $sql = mysqli_query($mysqli, "SELECT s.ticket_id FROM mit_project_ticket_sla s JOIN tickets ON tickets.ticket_id = s.ticket_id
        WHERE s.in_project = 1 AND " . TICKET_OPEN_SQL . (mitProjectTicketsApart() ? ' AND tickets.ticket_project_id = 0' : '') . " LIMIT 200");
    while ($row = mysqli_fetch_row($sql)) {
        $ids[] = intval($row[0]);
    }
    foreach (array_unique($ids) as $ticket_id) {
        applyTicketSla($ticket_id);
    }
    return count(array_unique($ids));
}

/**
 * Calendar items (calendarSourceItems 'tickets', so Dispatch too): project bookings in the project color,
 * with the tech's color kept for a dot (extendedProps.tech_color) and the project named in the detail.
 * On the calendar page ($opts mit_split_projects, from agent/ajax.php calendar_items) project bookings belong
 * to the Projects source instead (mitProjectBookingItems): left out here, or - 'only' - all that is kept.
 */
function mitProjectCalendarItems(array $items, array $opts = []) {
    global $mysqli;
    if (!mitProjectTicketsApart()) {
        return $items;
    }
    $split = $opts['mit_split_projects'] ?? false;
    $ids = [];
    foreach ($items as $item) {
        if (in_array($item['extendedProps']['kind'] ?? '', ['ticket', 'helper'], true)) {
            $ids[] = intval($item['extendedProps']['ticket_id'] ?? 0);
        }
    }
    $ids = array_filter(array_unique($ids));
    $projects = [];
    if ($ids) {
        $sql = mysqli_query($mysqli, "SELECT ticket_id, project_prefix, project_number, project_name FROM tickets
            JOIN projects ON project_id = ticket_project_id WHERE ticket_id IN (" . implode(',', $ids) . ")");
        while ($row = mysqli_fetch_assoc($sql)) {
            $projects[intval($row['ticket_id'])] = projectReference($row) . ' ' . $row['project_name'];
        }
    }
    $color = mitProjectTicketColor();
    foreach ($items as &$item) {
        $project = $projects[intval($item['extendedProps']['ticket_id'] ?? 0)] ?? null;
        if ($project === null || !in_array($item['extendedProps']['kind'] ?? '', ['ticket', 'helper'], true)) {
            continue;
        }
        $item['extendedProps']['tech_color'] = $item['color'] ?? '';
        $item['extendedProps']['project'] = $project;
        $item['extendedProps']['detail'] = trim('Project ' . $project . ' · ' . ($item['extendedProps']['detail'] ?? ''), ' ·');
        $item['color'] = $color;
    }
    unset($item);
    if ($split) {
        $items = array_values(array_filter($items, function ($item) use ($split) {
            return isset($item['extendedProps']['project']) === ($split === 'only');
        }));
    }
    return $items;
}

/** The calendar page's Projects source: its tickets' bookings too (the tickets source leaves them out there). */
function mitProjectBookingItems($from, $to, array $opts) {
    if (empty($opts['mit_split_projects']) || !mitProjectTicketsApart()) {
        return [];
    }
    return calendarSourceItems('tickets', $from, $to, ['mit_split_projects' => 'only'] + $opts);
}
