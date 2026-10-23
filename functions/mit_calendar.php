<?php

/*
 * Mountaineer IT - calendar additions on top of ITFlow's calendar (functions/calendar.php).
 *
 *  - Every agent has a color of their own, worked out from their user id, so a new tech
 *    has one the moment their account exists and their booked work is told apart on the
 *    calendar at a glance.
 *  - Booked work on a ticket has an end as well as a start (tickets.ticket_schedule_end,
 *    database update M001). An empty end is ITFlow's default length, CALENDAR_TICKET_MINUTES.
 */

/** Twelve colors that read well on white and dark backgrounds and are easy to tell apart. */
const MIT_AGENT_COLORS = [
    '#2563eb', '#16a34a', '#d97706', '#9333ea', '#dc2626', '#0891b2',
    '#db2777', '#65a30d', '#7c3aed', '#ea580c', '#0d9488', '#4f46e5',
];

/**
 * An agent's calendar color: the one they picked (saved as the calendar.color preference),
 * or one worked out from their user id - the same every time, different for the first twelve.
 */
function mitAgentColor($user_id) {
    $user_id = intval($user_id);
    if ($user_id <= 0) {
        return '#6c757d';
    }
    $chosen = mitAgentChosenColors();
    return $chosen[$user_id] ?? mitAgentDefaultColor($user_id);
}

/** The color an agent has until they pick one. */
function mitAgentDefaultColor($user_id) {
    return MIT_AGENT_COLORS[(max(1, intval($user_id)) - 1) % count(MIT_AGENT_COLORS)];
}

/** Every agent's picked color, user_id => '#rrggbb' - read once per request. Pass true after saving one. */
function mitAgentChosenColors($reload = false) {
    global $mysqli;
    static $colors = null;
    if ($colors === null || $reload) {
        $colors = [];
        try {
            $result = mysqli_query($mysqli, "SELECT user_id, value FROM mit_user_preferences WHERE preference = 'calendar.color'");
        } catch (Throwable $e) {
            $result = false;  // database update pending - automatic colors
        }
        while ($result && $row = mysqli_fetch_assoc($result)) {
            if (mitIsHexColor($row['value'])) {
                $colors[intval($row['user_id'])] = strtolower($row['value']);
            }
        }
    }
    return $colors;
}

/** A '#rrggbb' color? */
function mitIsHexColor($color) {
    return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
}

/**
 * The Calendar color field - Account > Preferences (your own) and Admin > Users > Edit (anyone's).
 * Posts mit_calendar_color ('#rrggbb') and mit_calendar_color_auto; mitSaveAgentColor() reads them.
 */
function mitCalendarColorField($user_id, $col = 'col-md-6') {
    $user_id = intval($user_id);
    $chosen = mitAgentChosenColors()[$user_id] ?? '';
    $color = mitAgentColor($user_id);
    $id = 'mitCalendarColor' . $user_id;
    $html = '<div class="' . $col . '" data-mit-color-field><label class="form-label" for="' . $id . '">Calendar color</label>'
        . '<div class="d-flex flex-wrap align-items-center gap-2">'
        . '<input type="color" class="form-control form-control-color" id="' . $id . '" name="mit_calendar_color" value="' . $color . '" title="Pick any color">';
    foreach (MIT_AGENT_COLORS as $preset) {
        $html .= '<button type="button" class="btn p-0 rounded-circle border" style="width:1.5rem;height:1.5rem;background:' . $preset . '" data-mit-color="' . $preset . '" title="' . $preset . '"></button>';
    }
    $html .= '</div><div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="mit_calendar_color_auto" value="1" id="' . $id . 'Auto"' . ($chosen === '' ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="' . $id . 'Auto">Automatic (' . mitAgentDefaultColor($user_id) . ')</label></div>'
        . '<div class="form-text">Scheduled tickets are drawn in this color on the calendar.</div>'
        . "<script>(function (f) { var c = f.querySelector('input[type=color]'), a = f.querySelector('[name=mit_calendar_color_auto]');"
        . " f.querySelectorAll('[data-mit-color]').forEach(function (b) { b.addEventListener('click', function () { c.value = b.dataset.mitColor; a.checked = false; }); });"
        . " c.addEventListener('input', function () { a.checked = false; }); })(document.currentScript.closest('[data-mit-color-field]'));</script>"
        . '</div>';
    return $html;
}

/** Save the Calendar color field for an agent (a no-op when the form did not have it). */
function mitSaveAgentColor($user_id, array $post) {
    global $mysqli;
    $user_id = intval($user_id);
    if (!$user_id || !isset($post['mit_calendar_color'])) {
        return;
    }
    $color = strtolower(trim((string) $post['mit_calendar_color']));
    try {
        if (!empty($post['mit_calendar_color_auto'])) {
            mysqli_query($mysqli, "DELETE FROM mit_user_preferences WHERE user_id = $user_id AND preference = 'calendar.color'");
        } elseif (mitIsHexColor($color)) {
            mitSetUserPreference($user_id, 'calendar.color', $color);
        }
    } catch (Throwable $e) {
        // mit_user_preferences not there yet (database update pending) - automatic colors
    }
    mitAgentChosenColors(true);
}

/** When booked work ends: its stored end, or ITFlow's default length after the start. */
function mitTicketScheduleEnd($start, $end = null) {
    if (empty($start)) {
        return null;
    }
    if (!empty($end) && strtotime($end) > strtotime($start)) {
        return date('Y-m-d H:i:s', strtotime($end));
    }
    return date('Y-m-d H:i:s', strtotime($start) + CALENDAR_TICKET_MINUTES * 60);
}

/** Booked work's length in minutes. */
function mitTicketScheduleMinutes($start, $end = null) {
    if (empty($start)) {
        return CALENDAR_TICKET_MINUTES;
    }
    return intval(round((strtotime(mitTicketScheduleEnd($start, $end)) - strtotime($start)) / 60));
}

/** The lengths offered when booking work, in minutes => label. */
function mitScheduleLengths() {
    $lengths = [];
    foreach ([15, 30, 45, 60, 90, 120, 150, 180, 240, 300, 360, 420, 480] as $minutes) {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        $label = $hours ? $hours . ' hour' . ($hours > 1 ? 's' : '') : '';
        if ($rest) {
            $label .= ($label ? ' ' : '') . "$rest min";
        }
        $lengths[$minutes] = $label;
    }
    return $lengths;
}

/** "Tuesday, October 20, 2026, 1:00pm - 3:00pm" (or across days, both dates in full). */
function mitScheduleWhenText($start, $end = null) {
    $end = mitTicketScheduleEnd($start, $end);
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if (date('Y-m-d', $start_ts) === date('Y-m-d', $end_ts)) {
        return date('l, F j, Y, g:ia', $start_ts) . ' - ' . date('g:ia', $end_ts);
    }
    return date('l, F j, Y, g:ia', $start_ts) . ' - ' . date('l, F j, Y, g:ia', $end_ts);
}

/** Short form for the ticket page: "Oct 20, 2026 • 1:00 PM - 3:00 PM". */
function mitScheduleShortText($start, $end = null) {
    $end = mitTicketScheduleEnd($start, $end);
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if (date('Y-m-d', $start_ts) === date('Y-m-d', $end_ts)) {
        return date('M d, Y • g:i A', $start_ts) . ' - ' . date('g:i A', $end_ts);
    }
    return date('M d, Y • g:i A', $start_ts) . ' - ' . date('M d, g:i A', $end_ts);
}

/**
 * A clashing booking as the agent scheduling may see it: "TCK-12 - Subject", or only that there is one when it is for a
 * client they can't see (agents restricted to some clients must not learn other clients' tickets this way).
 */
function mitConflictWhat(array $row) {
    $client_id = intval($row['ticket_client_id'] ?? 0);
    if ($client_id && function_exists('hasClientAccess') && !hasClientAccess($client_id)) {
        return 'Another booking (a client you can\'t see)';
    }
    return ticketReference($row) . ' - ' . $row['ticket_subject'];
}

/**
 * The agent's other open booked work that overlaps $start - $end, as plain text lines - as the lead
 * or helping on a ticket.
 * Overlap uses each booking's real end (or the default length when it has none). Their
 * Outlook busy times count too.
 */
function mitScheduleConflicts($user_id, $start, $end, $ticket_id = 0) {
    global $mysqli;
    $user_id = intval($user_id);
    $ticket_id = intval($ticket_id);
    if (!$user_id || empty($start)) {
        return [];
    }
    $start = escapeSql(date('Y-m-d H:i:s', strtotime($start)));
    $end = escapeSql(mitTicketScheduleEnd($start, $end));
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $conflicts = [];
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_schedule, ticket_schedule_end, ticket_subject, ticket_client_id FROM tickets
        WHERE ticket_assigned_to = $user_id AND ticket_id <> $ticket_id
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND ticket_schedule IS NOT NULL
        AND ticket_schedule < '$end'
        AND COALESCE(ticket_schedule_end, DATE_ADD(ticket_schedule, INTERVAL $default_minutes MINUTE)) > '$start'
        ORDER BY ticket_schedule");
    while ($row = mysqli_fetch_assoc($sql)) {
        $row_end = mitTicketScheduleEnd($row['ticket_schedule'], $row['ticket_schedule_end']);
        $conflicts[] = mitConflictWhat($row) . ' @ ' . date('g:ia', strtotime($row['ticket_schedule'])) . '-' . date('g:ia', strtotime($row_end));
    }
    // Tickets they are helping on (functions/mit_ticket_techs.php)
    $conflicts = array_merge($conflicts, mitTicketTechConflicts($user_id, $start, $end, $ticket_id));
    // Their own Outlook appointments too, once they have connected Outlook (functions/mit_outlook.php)
    if (function_exists('mitOutlookBusyConflicts')) {
        $conflicts = array_merge($conflicts, mitOutlookBusyConflicts($user_id, $start, $end));
    }
    return array_merge($conflicts, mitEventConflicts($user_id, $start, $end));
}

/** A person's first name ("Chris" from "Chris Thompson") - what the calendar shows beside a booking's time. */
function mitFirstName($name) {
    $name = trim((string) $name);
    return $name === '' ? '' : preg_split('/\s+/', $name)[0];
}

/** The calendar color of booked service tickets (not in a project) - Admin > Settings > Ticket (admin/includes/mit_calendar_card.php). */
function mitServiceTicketColor() {
    return calendarColor(mitSetting('service_ticket_color', '#0d6efd'), '#0d6efd');
}

/**
 * Booked service tickets (and helpers' bookings) in the service color, with the agent's color as a bar (an unassigned
 * ticket has none) - like project bookings (mitProjectCalendarItems, which has already colored those and set 'project').
 * Every booking (service or project) also gets extendedProps.agent_name (first name; agent_full for the tooltip) - shown beside the time by agent/js/calendar.js, so
 * the lead's name comes out of the detail line underneath. From mitCalendarTicketItems, so the calendar page, Dispatch and
 * the dashboard all get it.
 */
function mitServiceCalendarItems(array $items) {
    global $mysqli;
    $color = mitServiceTicketColor();
    // Who each booking is for: the ticket's lead, or the helper
    $ticket_ids = [];
    $user_ids = [];
    foreach ($items as $item) {
        $kind = $item['extendedProps']['kind'] ?? '';
        if ($kind === 'ticket') {
            $ticket_ids[] = intval($item['extendedProps']['ticket_id'] ?? 0);
        } elseif ($kind === 'helper') {
            $user_ids[] = intval($item['extendedProps']['user_id'] ?? 0);
        }
    }
    $leads = [];
    $ticket_ids = array_filter(array_unique($ticket_ids));
    if ($ticket_ids) {
        $result = mysqli_query($mysqli, "SELECT ticket_id, user_name FROM tickets JOIN users ON user_id = ticket_assigned_to WHERE ticket_id IN (" . implode(',', $ticket_ids) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $leads[intval($row['ticket_id'])] = $row['user_name'];
        }
    }
    $names = [];
    $user_ids = array_filter(array_unique($user_ids));
    if ($user_ids) {
        $result = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_id IN (" . implode(',', $user_ids) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $names[intval($row['user_id'])] = $row['user_name'];
        }
    }
    foreach ($items as &$item) {
        $kind = $item['extendedProps']['kind'] ?? '';
        $name = $kind === 'ticket' ? ($leads[intval($item['extendedProps']['ticket_id'] ?? 0)] ?? '')
            : ($kind === 'helper' ? ($names[intval($item['extendedProps']['user_id'] ?? 0)] ?? '') : '');
        if ($name !== '') {
            $item['extendedProps']['agent_name'] = mitFirstName($name);
            $item['extendedProps']['agent_full'] = $name;
            if ($kind === 'ticket') {
                // ITFlow's detail starts with the lead's name ("Bob · Open"); it is beside the time now
                $item['extendedProps']['detail'] = implode(' · ', array_diff(explode(' · ', (string) ($item['extendedProps']['detail'] ?? '')), [$name]));
            }
        }
    }
    unset($item);
    foreach ($items as &$item) {
        if (!in_array($item['extendedProps']['kind'] ?? '', ['ticket', 'helper'], true) || isset($item['extendedProps']['project'])) {
            continue;
        }
        $unassigned = ($item['extendedProps']['kind'] ?? '') === 'ticket' && strpos((string) ($item['extendedProps']['detail'] ?? ''), 'Unassigned') === 0;
        if (!$unassigned && !empty($item['color'])) {
            $item['extendedProps']['tech_color'] = $item['color'];
        }
        $item['color'] = $color;
    }
    unset($item);
    return $items;
}

/** Calendar events the agent organises or is invited to that overlap $start - $end (repeats expanded; all-day ones left out). */
function mitEventConflicts($user_id, $start, $end) {
    if (!function_exists('calendarEventItems') || !function_exists('calendarList')) {
        return [];
    }
    $conflicts = [];
    foreach (calendarEventItems(array_keys(calendarList()), $start, $end, ['client_id' => 0, 'user_id' => intval($user_id)]) as $item) {
        if (!empty($item['allDay']) || empty($item['end']) || $item['end'] <= $start || $item['start'] >= $end) {
            continue;
        }
        $conflicts[] = $item['title'] . ' @ ' . date('g:ia', strtotime($item['start'])) . '-' . date('g:ia', strtotime($item['end']));
    }
    return $conflicts;
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

/** The calendar's settings as saved for this user ('all' or 'client' page), cleaned - [] when none. */
function mitCalendarState($user_id, $page) {
    $state = json_decode((string) mitUserPreference($user_id, "calendar.$page", ''), true);
    return is_array($state) ? mitCleanCalendarState($state) : [];
}

/** Only the keys the calendar page uses, in the shapes it expects. */
function mitCleanCalendarState(array $state) {
    $clean = [];
    if (isset($state['view']) && in_array($state['view'], ['dayGridMonth', 'timeGridWeek', 'timeGridDay', 'listWeek', 'listMonth', 'listDay'], true)) {
        $clean['view'] = $state['view'];
    }
    if (isset($state['agent'])) {
        $clean['agent'] = max(0, intval($state['agent']));
    }
    if (isset($state['hidden']) && is_array($state['hidden'])) {
        $clean['hidden'] = array_values(array_slice(array_map('strval', array_map('intval', $state['hidden'])), 0, 200));
    }
    if (isset($state['sources']) && is_array($state['sources'])) {
        // An object, even when empty - the page sets keys on it
        $clean['sources'] = new stdClass();
        foreach ($state['sources'] as $key => $on) {
            if (preg_match('/^[a-z_]{1,40}$/', (string) $key)) {
                $clean['sources']->$key = (bool) $on;
            }
        }
    }
    return $clean;
}

// Repeats by weekday (the third Wednesday, the last Friday) for recurring tickets and events
require_once __DIR__ . '/mit_recurring.php';
require_once __DIR__ . '/mit_ticket_techs.php';
require_once __DIR__ . '/mit_left_behind.php'; // tickets whose booked day has gone
require_once __DIR__ . '/mit_event_agents.php'; // calendar entries assigned to agents

/*
 * ---------- Holidays on the calendar ----------
 * ITFlow's closure days (business_holidays - Admin > SLA, and Admin > Timesheets > Holidays) as an all-day calendar
 * source: hooks in functions/calendar.php calendarSources (+=) and calendarSourceItems (early return).
 */

function mitHolidayCalendarSources() {
    return ['holidays' => ['label' => 'Holidays', 'icon' => 'fa-calendar-day', 'color' => '#dc3545', 'module' => 'module_support', 'default' => true,
        'title' => 'Days the office is closed (Admin > Timesheets > Holidays, the same as the SLA closure days)']];
}

/** The holidays between two dates, as all-day items. */
function mitHolidayCalendarItems($from, $to) {
    global $mysqli;
    $from = escapeSql(substr((string) $from, 0, 10));
    $to = escapeSql(substr((string) $to, 0, 10));
    $items = [];
    $result = mysqli_query($mysqli, "SELECT holiday_id, holiday_date, holiday_name FROM business_holidays WHERE holiday_date BETWEEN '$from' AND '$to' ORDER BY holiday_date");
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = ['id' => 'holiday-' . intval($row['holiday_id']), 'title' => $row['holiday_name'], 'start' => $row['holiday_date'], 'allDay' => true,
            'color' => '#dc3545', 'editable' => false,
            'extendedProps' => ['kind' => 'holiday', 'icon' => 'fa-calendar-day', 'detail' => 'Holiday - the office is closed']];
    }
    return $items;
}
