<?php

/*
 * Mountaineer IT - our additions on top of ITFlow's calendar (functions/calendar.php).
 *
 * Since 26.12 ITFlow has, built from ours: agent colours, bookings with an end and their clashes, Left behind, the 7 days view, own time
 * zones, weekday repeats, events assigned to agents, Holidays and the saved calendar state (M067 moved our preferences into
 * user_settings). What stays ours:
 *  - service bookings in one colour (mit_settings service_ticket_color - Admin > Settings > Tickets, admin/includes/mit_calendar_card.php)
 *    with the agent's colour as a bar down the left (extendedProps.tech_color -> data-mit-agent, agent/js/calendar.js eventDidMount and
 *    agent/calendar.php's style) - the user's choice; ITFlow fills a booking in the agent's colour;
 *  - the boards a role sees (functions/mit_board_roles.php) and project bookings under the Projects tick box (functions/mit_projects.php);
 *  - Outlook busy times (functions/mit_outlook.php) as a source and in clashes (one line in ticketScheduleConflicts).
 * All through mitCalendarTicketItems - one guarded line at the end of ITFlow's tickets source.
 * Also here: per-agent preferences of ours (mit_user_preferences, M002), used across our features.
 */

/** An agent's calendar colour (ITFlow's, since 26.12). */
function mitAgentColor($user_id) {
    return function_exists('calendarAgentColor') ? calendarAgentColor($user_id) : '#6c757d';
}

/** When booked work ends (ITFlow's ticketScheduleEnd). */
function mitTicketScheduleEnd($start, $end = null) {
    return ticketScheduleEnd($start, $end);
}

/** The agent's other work at that time, as text lines (ITFlow's clashes - with Outlook busy times through our hook). */
function mitScheduleConflicts($user_id, $start, $end, $ticket_id = 0) {
    return ticketScheduleConflicts($user_id, $start, $end, $ticket_id);
}

/** What an overlapping booking is, without its subject when it is a client this agent can't see. */
function mitConflictWhat(array $row) {
    $client_id = intval($row['ticket_client_id'] ?? 0);
    if ($client_id && function_exists('hasClientAccess') && !hasClientAccess($client_id)) {
        return 'Another booking (a client you can\'t see)';
    }
    return ticketReference($row) . ' - ' . $row['ticket_subject'];
}

/** The calendar colour of booked service tickets (not in a project) - Admin > Settings > Tickets (admin/includes/mit_calendar_card.php). */
function mitServiceTicketColor() {
    return calendarColor(mitSetting('service_ticket_color', '#0d6efd'), '#0d6efd');
}

/**
 * Booked service tickets and helpers' bookings in the service colour, with the agent's colour kept as tech_color for the bar down the
 * left (an unassigned ticket has none). Project bookings (ITFlow marks them extendedProps.project) keep the project colour.
 */
function mitServiceCalendarItems(array $items) {
    $color = mitServiceTicketColor();
    foreach ($items as &$item) {
        $kind = $item['extendedProps']['kind'] ?? '';
        if (!in_array($kind, ['ticket', 'helper'], true) || isset($item['extendedProps']['project'])) {
            continue;
        }
        $has_agent = $kind === 'helper' || (string) ($item['extendedProps']['agent_full'] ?? '') !== '';
        if ($has_agent && !empty($item['color'])) {
            $item['extendedProps']['tech_color'] = $item['color'];
        }
        $item['color'] = $color;
    }
    unset($item);
    return $items;
}

/** ITFlow's tickets source (functions/calendar.php, one line): boards this role sees, project bookings under Projects, service colour. */
function mitCalendarTicketItems(array $items, array $opts = []) {
    $items = function_exists('mitBoardVisibleCalendarItems') ? mitBoardVisibleCalendarItems($items) : $items;
    $items = function_exists('mitProjectCalendarItems') ? mitProjectCalendarItems($items, $opts) : $items;
    return mitServiceCalendarItems($items);
}

/*
 * ---------- per-user preferences (mit_user_preferences, database update M002) ----------
 */

/** A saved preference for a user, or $default. */
function mitUserPreference($user_id, $preference, $default = null) {
    global $mysqli;
    $user_id = intval($user_id);
    $preference = escapeSql($preference);
    if (!$user_id) {
        return $default;
    }
    try {
        $result = mysqli_query($mysqli, "SELECT value FROM mit_user_preferences WHERE user_id = $user_id AND preference = '$preference' LIMIT 1");
    } catch (Throwable $e) {
        return $default;  // database update pending
    }
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return $row ? $row['value'] : $default;
}

/** Save a preference for a user. */
function mitSetUserPreference($user_id, $preference, $value) {
    global $mysqli;
    $user_id = intval($user_id);
    if (!$user_id) {
        return;
    }
    // Not escapeSql(): it strips tags and trims, and values here are JSON and settings that must come back exactly
    $preference = mysqli_real_escape_string($mysqli, (string) $preference);
    $value = mysqli_real_escape_string($mysqli, (string) $value);
    mysqli_query($mysqli, "INSERT INTO mit_user_preferences SET user_id = $user_id, preference = '$preference', value = '$value'
        ON DUPLICATE KEY UPDATE value = '$value'");
}

require_once __DIR__ . '/mit_ticket_techs.php';
