<?php

/*
 * Mountaineer IT - calendar entries (ITFlow's calendar_events) assigned to agents. ITFlow already keeps the agents on an
 * entry as attendees (calendar_event_attendees.attendee_user_id) and shows an entry on the calendar of every agent on it,
 * so this file only makes that plain and quick - no tables of its own:
 *  - New Event opened from an agent's calendar (Whose) starts with that agent instead of the one making it
 *    (mitEventDefaultAgents - one line in agent/modals/calendar/calendar_event_add.php; agent/js/calendar.js sends agent_id).
 *  - The form has "Assigned to" under the title (mitEventAssignField - one line in calendar_event_form.php, which then leaves
 *    its own Agents box out and calls the rest "Invite").
 *  - Entries are drawn in their agent's color with their names (mitEventItemsAgents - one line at the end of calendarEventItems).
 *  - The quick look changes who it is assigned to (mitEventQuickAssign - one line in modals/calendar/calendar_event.php,
 *    handler agent/post/mit_event_agents.php), and Dispatch's day view drags a one-off entry to another time or agent
 *    (mitDispatchEventMove in agent/mit_dispatch_ajax.php).
 * Changing agents keeps the entry's contacts and other people; someone who had been sent the invitation is sent the
 * cancellation when taken off, and a newcomer is sent the invitation when others on it already have it.
 */

/** The agents an entry can be assigned to: active agents, plus any already on it. user_id => row (user_name, user_avatar) */
function mitEventAgentChoices(array $keep = []) {
    global $mysqli;
    $keep = array_map('intval', $keep);
    $rows = [];
    $result = mysqli_query($mysqli, "SELECT user_id, user_name, user_avatar FROM users WHERE user_type = 1 AND user_archived_at IS NULL
        AND (user_status = 1 OR user_id IN (0" . ($keep ? ',' . implode(',', $keep) : '') . ")) ORDER BY user_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[intval($row['user_id'])] = $row;
    }
    return $rows;
}

/**
 * New Event's starting agents: the agent whose calendar it was opened from (?agent_id=, an active agent who can see the
 * client when there is one), else the one making it.
 */
function mitEventDefaultAgents(array $get, $client_id = 0) {
    global $session_user_id;
    $agent_id = intval($get['agent_id'] ?? 0);
    if ($agent_id && isset(mitEventAgentChoices()[$agent_id]) && mitEventAgentActive($agent_id)
        && (!function_exists('mitAgentCanSeeClient') || mitAgentCanSeeClient($agent_id, $client_id))) {
        return [$agent_id];
    }
    return [intval($session_user_id)];
}

/** Is this an active agent (not disabled)? */
function mitEventAgentActive($user_id) {
    global $mysqli;
    return (bool) mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM users WHERE user_id = " . intval($user_id) . " AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL"));
}

/** "Assigned to" for the New / Edit Event form - the agents[] field ITFlow's handler saves. */
function mitEventAssignField(array $form_attendees) {
    $chosen = array_map('intval', $form_attendees['agents'] ?? []);
    $options = '';
    foreach (mitEventAgentChoices($chosen) as $user_id => $row) {
        $options .= '<option value="' . $user_id . '"' . selectOptionUser($user_id, $row['user_name'], $row['user_avatar']) . (in_array($user_id, $chosen, true) ? ' selected' : '') . '>'
            . escapeHtml($row['user_name']) . '</option>';
    }
    return '<div class="mb-3" data-mit-event-assign>'
        . '<label class="form-label">Assigned to <span class="text-body-secondary small fw-normal">- it shows on their calendar and on Dispatch</span></label>'
        . '<div class="input-group"><span class="input-group-text"><i class="fas fa-fw fa-user-tie"></i></span>'
        . '<select class="form-select select2" name="agents[]" multiple data-placeholder="Agents">' . $options . '</select></div></div>';
}

/** The agents on these entries: event_id => [user_id => name], in name order. */
function mitEventAgents(array $event_ids) {
    global $mysqli;
    $event_ids = array_values(array_unique(array_filter(array_map('intval', $event_ids))));
    $agents = [];
    if ($event_ids) {
        $result = mysqli_query($mysqli, "SELECT attendee_event_id, user_id, user_name FROM calendar_event_attendees JOIN users ON user_id = attendee_user_id
            WHERE attendee_event_id IN (" . implode(',', $event_ids) . ") AND attendee_archived_at IS NULL AND attendee_user_id > 0 ORDER BY user_name");
        while ($row = mysqli_fetch_assoc($result)) {
            $agents[intval($row['attendee_event_id'])][intval($row['user_id'])] = $row['user_name'];
        }
    }
    return $agents;
}

/**
 * Hook at the end of calendarEventItems(): each entry gets its agents - extendedProps.agents (ids), the color of the agent
 * whose calendar is shown (or the first), and their names ahead of the detail ("Bob", "Bob +1"). On one agent's
 * calendar ($opts user_id) an entry assigned to others is left out even when that agent made it.
 */
function mitEventItemsAgents(array $items, array $opts = []) {
    $agents = mitEventAgents(array_map(function ($item) {
        return $item['extendedProps']['event_id'] ?? 0;
    }, $items));
    $shown = intval($opts['user_id'] ?? 0);
    foreach ($items as $key => &$item) {
        $on = $agents[intval($item['extendedProps']['event_id'] ?? 0)] ?? [];
        $item['extendedProps']['agents'] = array_keys($on);
        if (!$on) {
            continue;
        }
        // ITFlow shows an entry on its maker's calendar too; once it is assigned, it is the agents' (a dispatcher's
        // own calendar is not filled with what they handed out)
        if ($shown && !isset($on[$shown])) {
            unset($items[$key]);
            continue;
        }
        $first = isset($on[$shown]) ? $shown : array_key_first($on);
        // Filled with the agent's color (ITFlow's calendar color stays for entries with nobody on them)
        $item['color'] = mitAgentColor($first);
        $names = $on[$first] . (count($on) > 1 ? ' +' . (count($on) - 1) : '');
        $item['extendedProps']['detail'] = trim($names . ' · ' . ($item['extendedProps']['detail'] ?? ''), ' ·');
    }
    unset($item);
    return array_values($items);
}

/**
 * Put these agents on an entry in place of the ones on it (its contacts and other people stay). With $notify, the agents
 * taken off who had the invitation are sent the cancellation, and the new ones the invitation when anyone on the entry
 * has been sent it. Returns [added names, removed names, emails sent].
 */
function mitEventSetAgents(array $event, array $agent_ids, $notify = true) {
    $event_id = intval($event['event_id']);
    $contacts = [];
    $emails = [];
    $had_invites = false;
    foreach (calendarEventAttendees($event_id) as $attendee) {
        if ($attendee['kind'] === 'contact') {
            $contacts[] = intval($attendee['attendee_contact_id']);
        } elseif ($attendee['kind'] === 'guest') {
            $emails[] = $attendee['email'];
        }
        $had_invites = $had_invites || !empty($attendee['attendee_invited_at']);
    }
    $before = mitEventAgents([$event_id])[$event_id] ?? [];
    $result = calendarSetAttendees($event_id, intval($event['event_client_id']), $agent_ids, $contacts, $emails);
    $after = mitEventAgents([$event_id])[$event_id] ?? [];
    $sent = 0;
    if ($notify) {
        $sent += calendarSendInvites($event, 'cancel', array_values(array_filter($result['removed'], function ($attendee) {
            return $attendee['kind'] === 'agent' && !empty($attendee['attendee_invited_at']);
        })));
        if ($had_invites && $result['new']) {
            $sent += calendarSendInvites($event, 'invite', array_values(array_filter(calendarEventAttendees($event_id), function ($attendee) use ($result) {
                return in_array(intval($attendee['attendee_id']), $result['new'], true);
            })));
        }
    }
    return [array_values(array_diff_key($after, $before)), array_values(array_diff_key($before, $after)), $sent];
}

/** "Assigned to Bob, Ann; taken off Sam" for messages and the audit log. */
function mitEventAgentsText(array $added, array $removed) {
    return implode('; ', array_filter([$added ? 'assigned to ' . implode(', ', $added) : '', $removed ? 'taken off ' . implode(', ', $removed) : '']));
}

/** The quick look's "Assigned to" - change the agents without opening the whole form. */
function mitEventQuickAssign(array $event) {
    $event_id = intval($event['event_id']);
    $on = array_keys(mitEventAgents([$event_id])[$event_id] ?? []);
    $options = '';
    foreach (mitEventAgentChoices($on) as $user_id => $row) {
        $options .= '<option value="' . $user_id . '"' . selectOptionUser($user_id, $row['user_name'], $row['user_avatar']) . (in_array($user_id, $on, true) ? ' selected' : '') . '>'
            . escapeHtml($row['user_name']) . '</option>';
    }
    return '<dt>Assigned to</dt><dd class="min-w-0"><form action="post.php" method="post" class="d-flex flex-wrap gap-2 align-items-center" data-mit-event-quick-assign>'
        . '<input type="hidden" name="csrf_token" value="' . escapeHtml($_SESSION['csrf_token']) . '">'
        . '<input type="hidden" name="event_id" value="' . $event_id . '">'
        . '<div class="flex-grow-1" style="min-width: 14rem"><select class="form-select form-select-sm select2" name="agents[]" multiple data-placeholder="Nobody - pick agents">' . $options . '</select></div>'
        . '<button type="submit" name="mit_event_assign" class="btn btn-sm btn-outline-primary"><i class="fas fa-user-check me-1"></i>Save</button>'
        . '</form></dd>';
}

/**
 * Dispatch's day view: an entry dragged to another time and / or from one agent's column to another's. $from / $to are the
 * columns' agents (the same for a move in time). Repeating entries are moved from Edit. Those who have the invitation are
 * sent the update. Returns [ok, message].
 */
function mitDispatchEventMove($event_id, $start, $end, $all_day, $from, $to) {
    global $mysqli, $session_name;
    $event = calendarEventRow($event_id);
    if (!$event || !empty($event['event_archived_at'])) {
        return [false, 'That entry no longer exists.'];
    }
    if (intval($event['event_client_id']) && !hasClientAccess($event['event_client_id'])) {
        return [false, 'You do not have access to that client.'];
    }
    if (calendarEventRepeats($event)) {
        return [false, 'A repeating entry is moved from Edit, so the whole series moves together.'];
    }
    $event_id = intval($event['event_id']);
    $from = intval($from);
    $to = intval($to);
    if ($from !== $to) {
        if (!isset(mitDispatchTechs()[$to])) {
            return [false, 'Pick an agent.'];
        }
        if (function_exists('mitAgentCanSeeClient') && !mitAgentCanSeeClient($to, $event['event_client_id'])) {
            return [false, 'That agent cannot see this client.'];
        }
    }
    $start = ticketScheduleValue($start);
    if ($start === '') {
        return [false, 'That is not a date and time.'];
    }
    if ($all_day) {
        // One day on the board's all-day row - event_end is the last day covered
        $days = $event['event_all_day'] ? max(0, intval((strtotime(substr($event['event_end'] ?: $event['event_start'], 0, 10)) - strtotime(substr($event['event_start'], 0, 10))) / 86400)) : 0;
        $start = substr($start, 0, 10) . ' 00:00:00';
        $end = date('Y-m-d 00:00:00', strtotime(substr($start, 0, 10) . " +$days day"));
    } else {
        $end = ticketScheduleValue($end);
        if ($end === '' || $end <= $start) {
            $length = $event['event_all_day'] ? 3600 : max(900, strtotime($event['event_end'] ?: $event['event_start']) - strtotime($event['event_start']));
            $end = date('Y-m-d H:i:s', strtotime($start) + $length);
        }
    }
    $all_day = $all_day ? 1 : 0;
    $moved = $start !== $event['event_start'] || $end !== (string) $event['event_end'] || $all_day !== intval($event['event_all_day']);
    if ($moved) {
        mysqli_query($mysqli, "UPDATE calendar_events SET event_start = '" . escapeSql($start) . "', event_end = '" . escapeSql($end) . "', event_all_day = $all_day,
            event_sequence = event_sequence + 1 WHERE event_id = $event_id");
    }
    $event = calendarEventRow($event_id);
    $sent = 0;
    if ($moved) {
        // Those who already have it get the new time - not an agent being taken off (they get the cancellation below)
        $sent += calendarSendInvites($event, 'update', array_values(array_filter(calendarInvitedAttendees($event_id), function ($attendee) use ($from, $to) {
            return $from === $to || intval($attendee['attendee_user_id']) !== $from;
        })));
    }
    $words = '';
    if ($from !== $to) {
        $agents = array_keys(mitEventAgents([$event_id])[$event_id] ?? []);
        $agents = array_values(array_unique(array_merge(array_diff($agents, [$from]), [$to])));
        [$added, $removed, $mailed] = mitEventSetAgents($event, $agents);
        $sent += $mailed;
        $words = mitEventAgentsText($added, $removed);
    }
    $what = trim(($moved ? 'moved to ' . calendarWhenText($event) : '') . ($moved && $words ? ', ' : '') . $words);
    logAudit('Calendar Event', 'Edit', escapeSql("$session_name (Dispatch) " . ($what ?: 'left') . ' the event ' . $event['event_title']), intval($event['event_client_id']), $event_id);
    return [true, $event['event_title'] . ($what ? ' ' . $what : '') . ($sent ? " - emailed to $sent " . ($sent === 1 ? 'person' : 'people') : '')];
}
