<?php

/*
 * Mountaineer IT - project tickets kept apart. Since 26.12 ITFlow does it itself (functions/project_tickets.php: off the service desk's
 * lists, no SLA while in a project, counted from when one leaves, the project colour on the calendar - built from ours; M067 copied our
 * settings and SLA start times). What stays ours: on the calendar page project bookings belong to the Projects tick box, not the tickets
 * one - agent/ajax.php calendar_items passes 'mit_split_projects' in $opts; the tickets source then leaves them out (mitProjectCalendarItems,
 * from mitCalendarTicketItems) and the projects source adds them (mitProjectBookingItems - one line in functions/calendar.php's projects
 * branch). Dispatch and the dashboard still get every booking.
 */

/** The service desk's tickets only (ITFlow's projectTicketsServiceSql) - for our lists and counts. */
function mitServiceTicketsAnd($column = 'ticket_project_id') {
    return function_exists('projectTicketsServiceSql') ? projectTicketsServiceSql($column) : '';
}

/** Project tickets are kept apart (ITFlow's setting). */
function mitProjectTicketsApart() {
    return function_exists('projectTicketsApart') && projectTicketsApart();
}

/** The tickets source's items: on the calendar page ($opts mit_split_projects) project bookings are left out - or, 'only', all that is kept. */
function mitProjectCalendarItems(array $items, array $opts = []) {
    $split = $opts['mit_split_projects'] ?? false;
    if (!$split || !mitProjectTicketsApart()) {
        return $items;
    }
    return array_values(array_filter($items, function ($item) use ($split) {
        return isset($item['extendedProps']['project']) === ($split === 'only');
    }));
}

/** The calendar page's Projects source: its tickets' bookings too (the tickets source leaves them out there). */
function mitProjectBookingItems($from, $to, array $opts) {
    if (empty($opts['mit_split_projects']) || !mitProjectTicketsApart()) {
        return [];
    }
    return calendarSourceItems('tickets', $from, $to, ['mit_split_projects' => 'only'] + $opts);
}
