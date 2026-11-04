<?php

/*
 * Mountaineer IT - New Ticket: Status and Schedule (26.11.2.6, the user's request).
 *
 * ITFlow's New Ticket window starts every ticket as New (Open when assigned) and unbooked. This adds a Status picker (ITFlow's
 * pickable statuses) and a Schedule section (date / time on the viewer's own clock, how long, remote / onsite) under its
 * Assign to / Due row - mitTicketAddFields(), one line in agent/modals/ticket/ticket_add.php. After ITFlow's add_ticket has made
 * the ticket, mitTicketAddAfterCreate() (one line in agent/post/ticket.php) sets the status with ITFlow's ticketChangeStatus and
 * books it with ticketSetSchedule - the same emails / note / history as the ticket's own Schedule window.
 *
 * Dispatch's day view opens the window from a drag on empty time (agent/js/mit_dispatch.js select): ?assigned_to= picks the agent
 * (mitTicketAddAssignee - one condition in ticket_add.php's Assign to list), ?mit_schedule_start= / _end= fill the booking, and
 * mit_return=dispatch brings the agent back to Dispatch on that day (mitTicketAddRedirect).
 */

/** Who the Assign to list starts on: ?assigned_to= (Dispatch's agent column; 0 = nobody), else the agent opening it. */
function mitTicketAddAssignee() {
    global $session_user_id;
    if (isset($_GET['assigned_to']) && preg_match('/^\d+$/', (string) $_GET['assigned_to'])) {
        return intval($_GET['assigned_to']);
    }
    return intval($session_user_id);
}

/** "2026-11-04T09:30" from a GET value, or ''. */
function mitTicketAddTime($value) {
    $value = trim((string) $value);
    return preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/', $value, $m) ? $m[1] . 'T' . $m[2] : '';
}

/** Status and Schedule fields for New Ticket (after the Assign to / Due row). */
function mitTicketAddFields() {
    $start = mitTicketAddTime($_GET['mit_schedule_start'] ?? '');
    $end = mitTicketAddTime($_GET['mit_schedule_end'] ?? '');
    $minutes = CALENDAR_TICKET_MINUTES;
    if ($start !== '' && $end !== '') {
        $minutes = max(15, min(24 * 60, intval((strtotime($end) - strtotime($start)) / 60)));
    }
    $lengths = mitScheduleLengths();
    if (!isset($lengths[$minutes])) {
        $lengths[$minutes] = $minutes . ' min';
        ksort($lengths);
    }
    $onsite = intval($_GET['mit_schedule_onsite'] ?? 0) ? 1 : 0;

    $status_options = '<option value="0">New / Open (automatic)</option>';
    foreach (ticketPickableStatuses() as $id => $status) {
        $status_options .= '<option value="' . intval($id) . '">' . escapeHtml($status['ticket_status_name']) . '</option>';
    }
    $length_options = '';
    foreach ($lengths as $value => $label) {
        $length_options .= '<option value="' . intval($value) . '"' . (intval($value) === $minutes ? ' selected' : '') . '>' . escapeHtml($label) . '</option>';
    }
    $return = ($_GET['mit_return'] ?? '') === 'dispatch' ? '<input type="hidden" name="mit_return" value="dispatch">' : '';
    $hint = function_exists('mitZoneFormHint') ? mitZoneFormHint() : '';

    return '<div data-mit-ticket-add><input type="hidden" name="mit_ticket_add_present" value="1">' . $return
        . '<div class="mb-3"><label>Status</label><div class="input-group"><span class="input-group-text"><i class="fa fa-fw fa-info-circle"></i></span>'
        . '<select class="form-select" name="mit_status">' . $status_options . '</select></div>'
        . '<div class="form-text">Automatic: New, or Open once it has an agent.</div></div>'
        . '<fieldset class="border rounded p-2 pt-1 mb-3"><legend class="float-none w-auto px-1 mb-0 fs-6"><i class="fa fa-fw fa-calendar-check me-1"></i>Schedule</legend>'
        . '<div class="row g-2">'
        . '<div class="col-md-5"><label class="small">Date and time</label><input type="datetime-local" class="form-control" name="mit_schedule" value="' . escapeHtml($start) . '"></div>'
        . '<div class="col-md-4"><label class="small">How long</label><select class="form-select" name="mit_schedule_minutes">' . $length_options . '</select></div>'
        . '<div class="col-md-3"><label class="small">Visit</label><select class="form-select" name="mit_schedule_onsite">'
        . '<option value="0"' . ($onsite ? '' : ' selected') . '>Remote</option><option value="1"' . ($onsite ? ' selected' : '') . '>Onsite</option></select></div>'
        . $hint . '</div>'
        . '<div class="form-text">Leave the date empty to book it later. The agent and contact are emailed as when it is scheduled on the ticket.</div>'
        . '</fieldset></div>';
}

/** After ITFlow's add_ticket made the ticket: the status picked, then the booking. Messages go on after "Ticket created" (mitTicketAddFlash). */
function mitTicketAddAfterCreate($ticket_id, array $post) {
    if (empty($post['mit_ticket_add_present']) || !empty($post['mit_quick_ticket'])) {
        return;
    }
    $ticket_id = intval($ticket_id);
    $notes = [];
    $warning = false;

    $status = intval($post['mit_status'] ?? 0);
    if ($status && isset(ticketPickableStatuses()[$status])) {
        $row = ticketRow($ticket_id);
        if ($row && intval($row['ticket_status']) !== $status) {
            ticketChangeStatus($ticket_id, $status);
        }
    }

    $typed = trim((string) ($post['mit_schedule'] ?? ''));
    if ($typed !== '') {
        if (function_exists('mitFromView')) { // typed on the viewer's own clock (functions/mit_zones.php)
            $typed = mitFromView($typed);
        }
        $schedule = ticketScheduleValue($typed);
        if ($schedule === '') {
            $notes[] = 'The schedule time could not be read - book it from the ticket.';
            $warning = true;
        } else {
            $minutes = max(15, min(24 * 60, intval($post['mit_schedule_minutes'] ?? CALENDAR_TICKET_MINUTES)));
            $end = date('Y-m-d H:i:s', strtotime($schedule) + $minutes * 60);
            $row = ticketForRequest($ticket_id, 2);
            $result = ticketSetSchedule($row, $schedule, intval($post['mit_schedule_onsite'] ?? 0) ? 1 : 0, $end);
            $notes[] = 'Scheduled for ' . escapeHtml($result['when_text']) . (!empty($row['user_name']) ? ' with ' . escapeHtml($row['user_name']) : '');
            if (!empty($result['conflicts'])) {
                $notes[] = 'The same agent already has work booked at that time: ' . implode(', ', array_map('escapeHtml', $result['conflicts']));
                $warning = true;
            }
        }
    }
    $GLOBALS['mit_ticket_add_notes'] = ['lines' => $notes, 'warning' => $warning];
}

/** One line after ITFlow's "Ticket created" flash: the booking (and any clash) added to it - flashAlert keeps one message. */
function mitTicketAddFlash() {
    $notes = $GLOBALS['mit_ticket_add_notes'] ?? null;
    if (!$notes || !$notes['lines']) {
        return;
    }
    flashAlert(($_SESSION['alert_message'] ?? '') . '<br>' . implode('<br>', $notes['lines']), $notes['warning'] ? 'warning' : 'success');
    unset($GLOBALS['mit_ticket_add_notes']);
}

/** Opened from Dispatch: back to Dispatch on the booked day (or today) instead of the new ticket. */
function mitTicketAddRedirect($ticket_id, array $post) {
    if (($post['mit_return'] ?? '') !== 'dispatch' || !function_exists('mitDispatchCan') || !mitDispatchCan()) {
        return;
    }
    $row = ticketRow(intval($ticket_id));
    $day = '';
    if ($row && !empty($row['ticket_schedule'])) {
        $day = substr(function_exists('mitToView') ? mitToView($row['ticket_schedule']) : $row['ticket_schedule'], 0, 10);
    }
    redirect('mit_dispatch.php' . ($day !== '' ? '?date=' . urlencode($day) : ''));
}
