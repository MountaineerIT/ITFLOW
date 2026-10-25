<?php

/*
 * Mountaineer IT - additional techs on a ticket (database update M008, table mit_ticket_techs).
 *
 * The ticket's assigned tech stays the lead (tickets.ticket_assigned_to - everything ITFlow does
 * with it is untouched). Other techs can be added to help. Each one is booked at the same time as
 * the lead (follows_lead - moves and cancels with the lead's booking), at their own time, or not
 * booked at all. A helper sees the ticket in their own lists, on their calendar and in their
 * Outlook, is told when a client replies, and gets their own booking emails. The customer's
 * emails stay about the lead's booking only.
 *
 * A helper row for whoever is now the lead is ignored everywhere (they are the lead), and cleared
 * by mitTicketTechsTidy().
 */

/** Is the table there yet? (The code can be updated before the database update runs.) */
function mitTicketTechsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_techs'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * A helper who has since become the ticket's lead is no longer a helper. ITFlow changes the lead in
 * several places (the assignee picker, Edit ticket, bulk assign, the API), so instead of a hook in
 * each, those rows are cleared whenever helpers are read - once per request.
 */
function mitTicketTechsTidy() {
    global $mysqli;
    static $done = false;
    if (!$done && mitTicketTechsReady()) {
        $done = true;
        // A helper booking they were sent an invite for is cancelled first, and as the lead they are sent the ticket's own
        // (ITFlow's plain reassign sends none - the schedule modal and Dispatch did already, so it is not sent twice)
        $booked = mysqli_query($mysqli, "SELECT tt.ticket_id, tt.user_id, " . MIT_HELPER_START_SQL . " AS start FROM mit_ticket_techs tt
            JOIN tickets ON tickets.ticket_id = tt.ticket_id WHERE tt.user_id = tickets.ticket_assigned_to AND " . MIT_HELPER_START_SQL . " IS NOT NULL");
        while ($row = mysqli_fetch_assoc($booked)) {
            mitTicketTechMail($row['ticket_id'], $row['user_id'], $row['start'], null, 'cancelled');
            mitTicketLeadInvite($row['ticket_id']);
        }
        mysqli_query($mysqli, "DELETE tt FROM mit_ticket_techs tt JOIN tickets ON tickets.ticket_id = tt.ticket_id WHERE tt.user_id = tickets.ticket_assigned_to");
    }
}

/**
 * Hook after a booked ticket got a new lead (agent/post/ticket.php edit_ticket_schedule, mitDispatchBook - once the
 * reassign succeeded; $row is the ticket as it was before it): the old lead's calendar invite is cancelled - the new lead
 * is then sent the booking by ticketSetSchedule(). Nothing for an unbooked ticket, or a lead whose Outlook is synced (the
 * sync takes it off their calendar).
 */
function mitScheduleLeadChanging(array $row, $new_user_id) {
    global $mysqli, $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name, $config_base_url, $session_company_name;
    $old_user_id = intval($row['ticket_assigned_to'] ?? 0);
    if (empty($row['ticket_schedule']) || !$old_user_id || $old_user_id === intval($new_user_id) || empty($config_smtp_provider) || !mitScheduleNotify($row, 'tech')) {
        return;
    }
    $old = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE user_id = $old_user_id"));
    if (!$old || !filter_var($old['user_email'], FILTER_VALIDATE_EMAIL) || (function_exists('mitOutlookSyncsEmail') && mitOutlookSyncsEmail($old['user_email']))) {
        return;
    }
    $ticket_id = intval($row['ticket_id']);
    $client = mysqli_fetch_row(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = " . intval($row['ticket_client_id'])))[0] ?? '';
    $reference = ticketReference($row);
    $cal_str = createiCalStrCancel($row['ticket_schedule'], intval($row['ticket_number']) . ": $client - " . $row['ticket_subject'], getTicketCalendarUid($ticket_id));
    $link = "https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id";
    $body = 'Hello, ' . escapeHtml($old['user_name']) . '<br><br>The ticket regarding ' . escapeHtml($row['ticket_subject']) . ' has been given to someone else - its scheduled time is off your calendar.'
        . '<br><br><a href="' . $link . '">' . escapeHtml($reference) . '</a><br><br>Please do not reply to this email.<br><br>~<br>' . escapeHtml($session_company_name) . '<br>Support Department';
    addToMailQueue([[
        'from' => escapeSql($config_ticket_from_email), 'from_name' => escapeSql($config_ticket_from_name),
        'recipient' => escapeSql($old['user_email']), 'recipient_name' => escapeSql($old['user_name']),
        'subject' => escapeSql("Ticket Schedule Cancelled - [$reference] - " . $row['ticket_subject']), 'body' => mitMailBody($body), 'cal_str' => $cal_str,
    ]]);
}

/**
 * A scheduled open ticket's own booking invite, for its lead only (ticketMailSchedule with the contact and watchers left
 * out) - unless the same "Ticket Scheduled" email to them was queued in the last 10 minutes.
 */
function mitTicketLeadInvite($ticket_id) {
    global $mysqli, $config_smtp_provider, $config_base_url;
    $ticket_id = intval($ticket_id);
    if (empty($config_smtp_provider)) {
        return;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_details, ticket_client_id, client_name,
            ticket_schedule, ticket_schedule_end, ticket_resolved_at, ticket_closed_at, user_name, user_email, location_address
        FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN locations ON location_id = IF(ticket_location_id > 0, ticket_location_id, contact_location_id)
        LEFT JOIN users ON ticket_assigned_to = user_id
        WHERE tickets.ticket_id = $ticket_id"));
    if (!$row || empty($row['ticket_schedule']) || !ticketIsOpen($row) || !filter_var($row['user_email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }
    // The same subject ticketMailSchedule() gives it
    $subject = mysqli_real_escape_string($mysqli, 'Ticket Scheduled - [' . ticketReference($row) . '] - ' . $row['ticket_subject']);
    $email = mysqli_real_escape_string($mysqli, $row['user_email']);
    if (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM email_queue WHERE email_recipient = '$email' AND email_subject = '$subject'
        AND email_queued_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1"))) {
        return;
    }
    $end = mitTicketScheduleEnd($row['ticket_schedule'], $row['ticket_schedule_end']);
    $cal_str = createiCalStr($row['ticket_schedule'], intval($row['ticket_number']) . ": " . $row['client_name'] . " - " . $row['ticket_subject'],
        mb_substr(strip_tags((string) $row['ticket_details']), 0, 100) . " - https://$config_base_url/client/ticket.php?id=$ticket_id",
        (string) $row['location_address'], getTicketCalendarUid($ticket_id), $end);
    $skip = $GLOBALS['mit_schedule_skip_contact'] ?? false;
    $GLOBALS['mit_schedule_skip_contact'] = true;
    ticketMailSchedule($row, $cal_str, false, mitScheduleWhenText($row['ticket_schedule'], $end));
    $GLOBALS['mit_schedule_skip_contact'] = $skip;
}

/** Helpers' bookings for the dashboard's "My schedule" (functions/dashboard.php dashboardSchedule), in its item shape. */
function mitTicketTechDashboardItems($from, $to) {
    global $session_user_id;
    $items = [];
    foreach (mitTicketTechCalendarItems($from, $to, 0, $session_user_id, false) as $item) {
        $props = $item['extendedProps'];
        $items[] = [
            'when' => $item['start'], 'all_day' => false, 'ongoing' => false,
            'title' => $item['title'], 'ref' => '', 'detail' => $props['detail'],
            'onsite' => $props['icon'] === 'fa-car', 'color' => null,
            'url' => '/agent/' . ticketUrl($props['ticket_id'], $props['client_id']),
        ];
    }
    return $items;
}

// A helper's booking: the lead's when they follow the lead, else their own. Alias tt = mit_ticket_techs.
const MIT_HELPER_START_SQL = "IF(tt.follows_lead = 1, tickets.ticket_schedule, tt.schedule_start)";
const MIT_HELPER_END_SQL = "IF(tt.follows_lead = 1, tickets.ticket_schedule_end, tt.schedule_end)";

/**
 * "Assigned to this tech" for the ticket lists: the lead, or one of the helpers.
 * $id_column is how the query names the ticket's id.
 */
function mitAssignedSql($user_id, $id_column = 'tickets.ticket_id') {
    $user_id = intval($user_id);
    if (!mitTicketTechsReady()) {
        return "ticket_assigned_to = $user_id";
    }
    return "(ticket_assigned_to = $user_id OR $id_column IN (SELECT tt.ticket_id FROM mit_ticket_techs tt WHERE tt.user_id = $user_id))";
}

/** A ticket's helpers (not the lead), with their booking: start / end ('' when not booked). */
function mitTicketTechs($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    if (!$ticket_id || !mitTicketTechsReady()) {
        return [];
    }
    mitTicketTechsTidy();
    $result = mysqli_query($mysqli, "SELECT tt.ticket_id, tt.user_id, tt.follows_lead, tt.schedule_start, tt.schedule_end, tt.added_by, tt.added_at, user_name, user_email, user_avatar,
            " . MIT_HELPER_START_SQL . " AS start, " . MIT_HELPER_END_SQL . " AS end
        FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        JOIN users ON users.user_id = tt.user_id
        WHERE tt.ticket_id = $ticket_id AND tt.user_id <> tickets.ticket_assigned_to AND users.user_archived_at IS NULL
        ORDER BY user_name");
    $techs = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['end'] = $row['start'] ? mitTicketScheduleEnd($row['start'], $row['end']) : '';
        $row['start'] = (string) $row['start'];
        $techs[intval($row['user_id'])] = $row;
    }
    return $techs;
}

/**
 * The helpers of every ticket, ticket_id => [user_id => user_name] - for the ticket list and board. Read in one
 * query per page rather than one per row: the table holds only tickets that have helpers, so it stays small.
 */
function mitTicketTechNames() {
    global $mysqli;
    static $names = null;
    if ($names === null) {
        $names = [];
        if (mitTicketTechsReady()) {
            mitTicketTechsTidy();
            $result = mysqli_query($mysqli, "SELECT tt.ticket_id, tt.user_id, user_name FROM mit_ticket_techs tt
                JOIN tickets ON tickets.ticket_id = tt.ticket_id
                JOIN users ON users.user_id = tt.user_id
                WHERE tt.user_id <> tickets.ticket_assigned_to AND users.user_archived_at IS NULL
                ORDER BY user_name");
            while ($row = mysqli_fetch_assoc($result)) {
                $names[intval($row['ticket_id'])][intval($row['user_id'])] = $row['user_name'];
            }
        }
    }
    return $names;
}

/** What a helper's booking says: "Same time as the lead", a time, or "Not booked". */
function mitTicketTechWhenText(array $tech) {
    if (intval($tech['follows_lead']) === 1) {
        return $tech['start'] !== '' ? 'Same time as the lead' : 'With the lead - not scheduled yet';
    }
    return $tech['start'] !== '' ? mitScheduleShortText($tech['start'], $tech['end']) : 'Not scheduled';
}

/*
 * ---------- the ticket page ----------
 */

/** The ticket page's "Additional techs" line (agent/ticket.php, under Assigned to). */
function mitTicketTechsProp(array $ticket, $can_change) {
    if (!mitTicketTechsReady()) {
        return '';
    }
    $ticket_id = intval($ticket['ticket_id']);
    $techs = mitTicketTechs($ticket_id);
    if (!$techs && !$can_change) {
        return '';
    }
    $html = '<div class="ticket-prop"><dt>Additional agents</dt><dd>';
    foreach ($techs as $user_id => $tech) {
        $value = '<span><span class="d-inline-flex align-items-center gap-2"><span class="rounded-circle flex-shrink-0" style="width:.7rem;height:.7rem;background:' . mitAgentColor($user_id) . '"></span>'
            . escapeHtml($tech['user_name']) . '</span><span class="ticket-prop-sub">' . escapeHtml(mitTicketTechWhenText($tech)) . '</span></span>';
        if ($can_change) {
            $html .= '<a href="#" class="ticket-prop-edit ajax-modal" data-modal-url="modals/ticket/mit_ticket_tech.php?ticket_id=' . $ticket_id . '&user_id=' . $user_id . '" title="Change ' . escapeHtml($tech['user_name']) . '\'s schedule">'
                . $value . '<i class="fas fa-pen ticket-prop-edit-icon"></i></a>';
        } else {
            $html .= '<span class="ticket-prop-static">' . $value . '</span>';
        }
    }
    if ($can_change && ticketIsOpen($ticket)) {
        $html .= '<a href="#" class="ticket-prop-edit ajax-modal small" data-modal-url="modals/ticket/mit_ticket_tech.php?ticket_id=' . $ticket_id . '" title="Add an agent to help">'
            . '<span class="' . ($techs ? 'text-body-secondary' : 'text-muted') . '"><i class="fas fa-user-plus me-1"></i>' . ($techs ? 'Add another' : 'Add an agent') . '</span><i class="fas fa-plus ticket-prop-edit-icon"></i></a>';
    } elseif (!$techs) {
        $html .= '<span class="ticket-prop-static text-muted">None</span>';
    }
    return $html . '</dd></div>';
}

/** The ticket list's assignee cell: "+2" after the lead, highlighted when you are one of them. */
function mitTicketTechsListBadge($ticket_id) {
    global $session_user_id;
    $names = mitTicketTechNames()[intval($ticket_id)] ?? [];
    if (!$names) {
        return '';
    }
    $mine = isset($names[intval($session_user_id)]);
    return ' <span class="badge rounded-pill ' . ($mine ? 'text-bg-primary' : 'text-bg-light border') . ' align-middle" title="' . escapeHtml(($mine ? 'You are helping. ' : '') . 'Also on it: ' . implode(', ', $names)) . '">'
        . ($mine ? '<i class="fas fa-user-friends me-1"></i>Helping' : '+' . count($names)) . '</span>';
}

/*
 * ---------- saving ----------
 */

/**
 * Can this agent see this client (Admin > Users > client access - allowed list, kept-out list, admins see all)?
 * The same rule hasClientAccess() applies to the signed-in agent, for anyone. A ticket with no client: yes.
 */
function mitAgentCanSeeClient($user_id, $client_id) {
    global $mysqli;
    $user_id = intval($user_id);
    $client_id = intval($client_id);
    if (!$client_id) {
        return true;
    }
    $admin = mysqli_fetch_row(mysqli_query($mysqli, "SELECT role_is_admin FROM users JOIN user_roles ON role_id = user_role_id WHERE user_id = $user_id"));
    if ($admin && intval($admin[0]) === 1) {
        return true;
    }
    $access = userClientAccess([$user_id])[$user_id];
    if (isset($access['deny'][$client_id])) {
        return false;
    }
    return !$access['allow'] || isset($access['allow'][$client_id]);
}

/**
 * Another agent's Support level from their ITFlow role (admins 3, no role / no Support row 0) - for picking, handing out
 * or telling people about tickets: 1 can read a ticket, 2 can work it (reply, schedule).
 */
function mitAgentSupportLevel($user_id) {
    global $mysqli;
    static $cache = [];
    $user_id = intval($user_id);
    if (!isset($cache[$user_id])) {
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT IFNULL(role_is_admin, 0), (SELECT p.user_role_permission_level FROM user_role_permissions p
                JOIN modules m ON m.module_id = p.module_id WHERE p.user_role_id = users.user_role_id AND m.module_name = 'module_support')
            FROM users LEFT JOIN user_roles ON role_id = user_role_id AND role_archived_at IS NULL WHERE user_id = $user_id"));
        $cache[$user_id] = !$row ? 0 : (intval($row[0]) === 1 ? 3 : intval($row[1]));
    }
    return $cache[$user_id];
}

/** Can this agent work (level 2) - or read (level 1) - a ticket of this client: their role's Support level and client access. */
function mitAgentCanWorkTicket($user_id, $client_id, $level = 2) {
    return mitAgentSupportLevel($user_id) >= $level && mitAgentCanSeeClient($user_id, $client_id);
}

/**
 * Add a helper, or change their booking. $mode: 'lead' (same time as the lead), 'own' ($start - $end)
 * or 'none'. Emails the helper their booking (or its cancellation), notes it on the ticket.
 * Returns [ok, message (plain text - the caller escapes it), conflicts].
 */
function mitTicketTechSave(array $ticket, $user_id, $mode, $start = '', $end = '') {
    global $mysqli, $session_user_id, $session_name;
    $who = stripslashes((string) $session_name); // it is kept SQL-escaped
    $ticket_id = intval($ticket['ticket_id']);
    $user_id = intval($user_id);
    if (!mitTicketTechsReady()) {
        return [false, 'The database update for additional agents has not run yet.', []];
    }
    $tech = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_id = $user_id AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL"));
    if (!$tech) {
        return [false, 'Pick an agent.', []];
    }
    if ($user_id === intval($ticket['ticket_assigned_to'])) {
        return [false, $tech['user_name'] . ' is the lead agent on this ticket.', []];
    }
    if (!mitAgentCanWorkTicket($user_id, $ticket['ticket_client_id'])) {
        return [false, $tech['user_name'] . ' does not have access to this client.', []];
    }
    $mode = in_array($mode, ['lead', 'own', 'none'], true) ? $mode : 'lead';
    if ($mode === 'own' && ($start === '' || $end === '' || $end <= $start)) {
        return [false, 'Pick the date and time for ' . $tech['user_name'] . '.', []];
    }

    $before = mitTicketTechs($ticket_id)[$user_id] ?? null;
    $follows = $mode === 'lead' ? 1 : 0;
    $start_sql = $mode === 'own' ? "'" . escapeSql($start) . "'" : 'NULL';
    $end_sql = $mode === 'own' ? "'" . escapeSql($end) . "'" : 'NULL';
    mysqli_query($mysqli, "INSERT INTO mit_ticket_techs SET ticket_id = $ticket_id, user_id = $user_id, follows_lead = $follows,
            schedule_start = $start_sql, schedule_end = $end_sql, added_by = " . intval($session_user_id) . "
        ON DUPLICATE KEY UPDATE follows_lead = $follows, schedule_start = $start_sql, schedule_end = $end_sql");
    $after = mitTicketTechs($ticket_id)[$user_id] ?? null;

    $name = $tech['user_name'];
    $when = $after && $after['start'] !== '' ? mitScheduleWhenText($after['start'], $after['end']) : '';
    $conflicts = $when !== '' ? mitScheduleConflicts($user_id, $after['start'], $after['end'], $ticket_id) : [];

    // Tell them: their booking (new or moved), or its cancellation, or just that they are on it
    $was_booked = $before && $before['start'] !== '';
    $changed = !$before || $before['start'] !== ($after['start'] ?? '') || $before['end'] !== ($after['end'] ?? '');
    if ($when !== '' && $changed) {
        mitTicketTechMail($ticket_id, $user_id, $after['start'], $after['end'], 'booked');
    } elseif ($when === '' && $was_booked) {
        mitTicketTechMail($ticket_id, $user_id, $before['start'], $before['end'], 'cancelled');
    } elseif (!$before) {
        mitTicketTechMail($ticket_id, $user_id, '', '', 'added');
    }

    $what = $mode === 'lead' ? 'at the same time as the lead' . ($when !== '' ? " ($when)" : '') : ($mode === 'own' ? "for $when" : 'not scheduled');
    $note = $before ? "$name's schedule changed - $what." : "$name added as an additional agent - $what.";
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql($note) . "', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");
    logTicketHistory($ticket_id, escapeSql("$who " . ($before ? "changed $name's schedule - $what" : "added $name as an additional agent - $what")));
    logAudit("Ticket", "Edit", escapeSql("$who " . ($before ? "changed $name's schedule on " : "added $name to ") . ticketReference($ticket)), intval($ticket['ticket_client_id']), $ticket_id);

    // Their app notification, when someone else added them
    if (!$before && $user_id !== intval($session_user_id)) {
        $client_uri = intval($ticket['ticket_client_id']) ? '&client_id=' . intval($ticket['ticket_client_id']) : '';
        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '" . escapeSql('Ticket ' . ticketReference($ticket) . ' - ' . $ticket['ticket_subject'] . " - $who added you to help") . "',
            notification_action = '/agent/ticket.php?ticket_id=$ticket_id$client_uri', notification_client_id = " . intval($ticket['ticket_client_id']) . ", notification_user_id = $user_id");
    }

    return [true, $before ? "$name's schedule is saved" : "$name added to the ticket", $conflicts];
}

/** Take a helper off a ticket - their booking is cancelled. */
function mitTicketTechRemove(array $ticket, $user_id) {
    global $mysqli, $session_user_id, $session_name;
    $who = stripslashes((string) $session_name); // it is kept SQL-escaped
    $ticket_id = intval($ticket['ticket_id']);
    $user_id = intval($user_id);
    $tech = mitTicketTechs($ticket_id)[$user_id] ?? null;
    if (!$tech) {
        return false;
    }
    mysqli_query($mysqli, "DELETE FROM mit_ticket_techs WHERE ticket_id = $ticket_id AND user_id = $user_id");
    if ($tech['start'] !== '') {
        mitTicketTechMail($ticket_id, $user_id, $tech['start'], $tech['end'], 'cancelled');
    }
    $name = $tech['user_name'];
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . escapeSql("$name removed from the ticket.") . "', ticket_reply_type = 'Internal', ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");
    logTicketHistory($ticket_id, escapeSql("$who removed $name from the ticket"));
    logAudit("Ticket", "Edit", escapeSql("$who removed $name from " . ticketReference($ticket)), intval($ticket['ticket_client_id']), $ticket_id);
    return $name;
}

/*
 * ---------- the lead's booking moved or cancelled (hooks in functions/ticket.php) ----------
 */

/** After ticketSetSchedule(): helpers who follow the lead get the new time. Returns their clashes. */
function mitTicketTechsLeadMoved($ticket_id) {
    $conflicts = [];
    foreach (mitTicketTechs($ticket_id) as $user_id => $tech) {
        if (intval($tech['follows_lead']) === 1 && $tech['start'] !== '') {
            mitTicketTechMail($ticket_id, $user_id, $tech['start'], $tech['end'], 'booked');
            foreach (mitScheduleConflicts($user_id, $tech['start'], $tech['end'], $ticket_id) as $line) {
                $conflicts[] = $tech['user_name'] . ': ' . $line;
            }
        }
    }
    return $conflicts;
}

/** After ticketCancelSchedule(): helpers who follow the lead get the cancellation. */
function mitTicketTechsLeadCancelled($ticket_id, $old_start) {
    foreach (mitTicketTechs($ticket_id) as $user_id => $tech) {
        if (intval($tech['follows_lead']) === 1 && $old_start) {
            mitTicketTechMail($ticket_id, $user_id, $old_start, '', 'cancelled');
        }
    }
}

/*
 * ---------- emails ----------
 */

/** The calendar-invite UID of a helper's booking - its own, so it never replaces the lead's. */
function mitTicketTechUid($ticket_id, $user_id) {
    return 'ticket-' . intval($ticket_id) . '-tech-' . intval($user_id) . '@' . ($_SERVER['SERVER_NAME'] ?? 'itflow');
}

/** Email a helper: $kind 'booked' (with the invite), 'cancelled' (its cancellation) or 'added'. */
function mitTicketTechMail($ticket_id, $user_id, $start, $end, $kind) {
    global $mysqli, $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name, $config_base_url, $session_company_name;
    if (empty($config_smtp_provider) || !mitScheduleNotify($ticket_id, 'tech')) {
        return;
    }
    $ticket_id = intval($ticket_id);
    $user_id = intval($user_id);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_details, ticket_client_id, ticket_onsite,
            client_name, location_address, lead.user_name AS lead_name
        FROM tickets
        LEFT JOIN clients ON ticket_client_id = client_id
        LEFT JOIN contacts ON ticket_contact_id = contact_id
        LEFT JOIN locations ON location_id = IF(ticket_location_id > 0, ticket_location_id, contact_location_id)
        LEFT JOIN users lead ON lead.user_id = ticket_assigned_to
        WHERE tickets.ticket_id = $ticket_id"));
    $tech = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE user_id = $user_id"));
    if (!$row || !$tech || !filter_var($tech['user_email'], FILTER_VALIDATE_EMAIL)) {
        return;
    }
    // Nothing new about a ticket of a client they can no longer see (a cancellation still goes, to clear their calendar)
    if ($kind !== 'cancelled' && !mitAgentCanSeeClient($user_id, $row['ticket_client_id'])) {
        return;
    }
    $html = function ($value) {
        return escapeHtml((string) $value);
    };
    $reference = ticketReference($row);
    $client_id = intval($row['ticket_client_id']);
    $link = "https://$config_base_url/agent/ticket.php?ticket_id=$ticket_id" . ($client_id ? "&client_id=$client_id" : '');
    $about = 'the ticket regarding ' . $html($row['ticket_subject']) . ($row['client_name'] ? ' (' . $html($row['client_name']) . ')' : '');
    $lead = $row['lead_name'] ? ' The lead agent is ' . $html($row['lead_name']) . '.' : '';

    $cal_str = '';
    if ($kind === 'booked') {
        $when = mitScheduleWhenText($start, $end);
        $what = "You are scheduled to help on $about for " . $html($when) . " (" . (intval($row['ticket_onsite']) ? 'onsite' : 'remote') . ").$lead";
        $subject = "Ticket Scheduled - [$reference] - " . $row['ticket_subject'];
        $cal_subject = intval($row['ticket_number']) . ": " . $row['client_name'] . " - " . $row['ticket_subject'];
        $cal_str = createiCalStr($start, $cal_subject, mb_substr(strip_tags((string) $row['ticket_details']), 0, 100) . " - $link", (string) $row['location_address'], mitTicketTechUid($ticket_id, $user_id), $end);
    } elseif ($kind === 'cancelled') {
        $what = "Your scheduled time to help on $about has been cancelled.";
        $subject = "Ticket Schedule Cancelled - [$reference] - " . $row['ticket_subject'];
        $cal_str = createiCalStrCancel($start, intval($row['ticket_number']) . ": " . $row['client_name'] . " - " . $row['ticket_subject'], mitTicketTechUid($ticket_id, $user_id));
    } else {
        $what = "You have been added to help on $about. It is not on your calendar.$lead";
        $subject = "Ticket - [$reference] - " . $row['ticket_subject'];
    }
    // A tech whose Outlook is synced gets the booking there - no second copy from an invite
    if ($cal_str !== '' && function_exists('mitOutlookSyncsEmail') && mitOutlookSyncsEmail($tech['user_email'])) {
        $cal_str = '';
    }
    $body = 'Hello, ' . $html($tech['user_name']) . "<br><br>$what<br><br>--------------------------------<br><a href=\"$link\">" . $html($reference) . "</a><br>--------------------------------<br><br>Please do not reply to this email.<br><br>~<br>"
        . $html($session_company_name) . "<br>Support Department<br>" . $html($config_ticket_from_email);
    $esc = function ($value) use ($mysqli) {
        return mysqli_real_escape_string($mysqli, (string) $value);
    };
    addToMailQueue([[
        'from' => $esc($config_ticket_from_email), 'from_name' => $esc($config_ticket_from_name),
        'recipient' => $esc($tech['user_email']), 'recipient_name' => $esc($tech['user_name']),
        'subject' => $esc($subject), 'body' => $esc($body), 'cal_str' => $cal_str,
    ]]);
}

/**
 * A client replied (cron/ticket_email_parser.php, client/post.php - after ITFlow's ticketMailAssigneeReplied): the same "client
 * replied" email ITFlow sends the lead (ticketAgentEmailContent 'replied'), for each helper who can see the client. $how portal |
 * email, $reply the reply's HTML, $by who wrote it. As ITFlow, a ticket with no lead mails nobody.
 */
function mitTicketTechsCopyReply($ticket_id, $how, $reply, $by) {
    global $config_app_name;
    if (!mitNotifyOn('helper_copy') || !mitTicketTechsReady()) {
        return 0;
    }
    $row = ticketRow($ticket_id);
    $lead = $row ? ticketMailAgent($row['ticket_assigned_to']) : null;
    if (!$lead) {
        return 0;
    }
    $client_id = intval($row['ticket_client_id']);
    $recipients = [];
    foreach (mitTicketTechs($ticket_id) as $user_id => $tech) {
        if (!filter_var($tech['user_email'], FILTER_VALIDATE_EMAIL) || strcasecmp($tech['user_email'], $lead['email']) === 0 || !mitAgentCanSeeClient($user_id, $client_id)) {
            continue;
        }
        $recipients[] = ['email' => $tech['user_email'], 'name' => $tech['user_name'], 'footnote' => 'You get this as a tech helping on ' . ticketReference($row) . '.'];
    }
    if (!$recipients) {
        return 0;
    }
    return ticketMailAgents($recipients, ($config_app_name ?: 'ITFlow') . " - Ticket updated - [" . ticketReference($row) . "] " . $row['ticket_subject'],
        ticketAgentEmailContent($row, 'replied', ['how' => $how, 'message' => (string) $reply, 'by' => (string) $by]));
}

/*
 * ---------- calendar, clashes, Outlook ----------
 */

/** Helpers' bookings for the ITFlow calendar (functions/calendar.php, the tickets source). */
function mitTicketTechCalendarItems($from, $to, $client_id, $user_id, $can_move) {
    global $mysqli;
    if (!mitTicketTechsReady()) {
        return [];
    }
    $from = escapeSql($from);
    $to = escapeSql($to);
    $client_id = intval($client_id);
    $user_id = intval($user_id);
    $start = MIT_HELPER_START_SQL;
    $sql = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_onsite, ticket_client_id,
            ticket_resolved_at, ticket_closed_at, client_name, ticket_status_name, tt.user_id, tt.follows_lead, helper.user_name, lead.user_name AS lead_name,
            $start AS start, " . MIT_HELPER_END_SQL . " AS end
        FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        JOIN users helper ON helper.user_id = tt.user_id
        LEFT JOIN users lead ON lead.user_id = ticket_assigned_to
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN ticket_statuses ON ticket_status_id = ticket_status
        WHERE $start BETWEEN '$from' AND '$to' AND ticket_archived_at IS NULL AND tt.user_id <> ticket_assigned_to
        AND helper.user_archived_at IS NULL
        " . ($client_id ? "AND ticket_client_id = $client_id" : '') . "
        " . ($user_id ? "AND tt.user_id = $user_id" : '') . "
        " . clientScopeSql('ticket_client_id'));
    $items = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $open = ticketIsOpen($row);
        $ticket_id = intval($row['ticket_id']);
        $items[] = [
            'id' => "helper-$ticket_id-" . intval($row['user_id']),
            'title' => calendarTicketTitle($row, !$client_id) . ' (helping)',
            'start' => $row['start'],
            'end' => mitTicketScheduleEnd($row['start'], $row['end']),
            'allDay' => false,
            'editable' => $can_move && $open,
            'durationEditable' => $can_move && $open,
            'color' => mitAgentColor($row['user_id']),
            'className' => $open ? '' : 'itflow-cal-done',
            'extendedProps' => ['kind' => 'helper', 'ticket_id' => $ticket_id, 'user_id' => intval($row['user_id']), 'follows_lead' => intval($row['follows_lead']),
                'client_id' => intval($row['ticket_client_id']),
                'icon' => intval($row['ticket_onsite']) ? 'fa-car' : 'fa-headset',
                'modal' => "modals/ticket/ticket.php?id=$ticket_id",
                'detail' => implode(' · ', array_filter([$row['user_name'] . ' helping' . ($row['lead_name'] ? ' ' . $row['lead_name'] : ''), $row['ticket_status_name']]))],
        ];
    }
    return $items;
}

/** Helpers' bookings for a person's calendar feed (functions/calendar.php calendarUserFeedItems). */
function mitTicketTechFeedItems(array $feed, $from, $to) {
    global $mysqli;
    if (!mitTicketTechsReady() || empty($feed['feed_tickets']) || lookupUserPermission('module_support') < 1) {
        return [];
    }
    $user_id = intval($feed['feed_user_id']);
    $everyone = ($feed['feed_scope'] ?? 'mine') === 'everyone';
    $host = calendarHost();
    $from = escapeSql($from);
    $to = escapeSql($to);
    $start = MIT_HELPER_START_SQL;
    $sql = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, ticket_onsite, ticket_client_id,
            ticket_created_at, ticket_updated_at, ticket_resolved_at, ticket_closed_at, client_name, tt.user_id, helper.user_name,
            location_name, location_address, location_city, location_state, location_zip,
            $start AS start, " . MIT_HELPER_END_SQL . " AS end
        FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        JOIN users helper ON helper.user_id = tt.user_id
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN contacts ON contact_id = ticket_contact_id AND ticket_contact_id > 0
        LEFT JOIN locations ON location_id = IF(ticket_location_id > 0, ticket_location_id, contacts.contact_location_id)
        WHERE $start BETWEEN '$from' AND '$to' AND ticket_archived_at IS NULL AND tt.user_id <> ticket_assigned_to
        " . ($everyone ? '' : "AND tt.user_id = $user_id") . "
        " . clientScopeSql('ticket_client_id'));
    $items = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $ticket_id = intval($row['ticket_id']);
        $link = "https://$host/agent/" . ticketUrl($ticket_id, $row['ticket_client_id']);
        $items[] = [
            'uid' => "itflow-ticket-$ticket_id-tech-" . intval($row['user_id']) . "@$host",
            'start' => $row['start'],
            'end' => mitTicketScheduleEnd($row['start'], $row['end']),
            'summary' => calendarTicketTitle($row) . ' (' . ($everyone ? $row['user_name'] . ' ' : '') . 'helping)' . (ticketIsOpen($row) ? '' : ' [done]'),
            // Where the work is, as the lead's feed item gives it (functions/calendar.php calendarUserFeedItems)
            'location' => implode(', ', array_filter([$row['location_address'], $row['location_city'], trim($row['location_state'] . ' ' . $row['location_zip'])])),
            'description' => implode("\n", array_filter([$row['client_name'] ? 'Client: ' . $row['client_name'] : '', $row['location_name'] ? 'Site: ' . $row['location_name'] : '', 'Helping: ' . $row['user_name'], $link])),
            'url' => $link,
            'stamp' => $row['ticket_updated_at'] ?: $row['ticket_created_at'],
            'categories' => intval($row['ticket_onsite']) ? 'Onsite' : 'Remote',
            'busy' => !empty($feed['feed_busy_only']),
        ];
    }
    return $items;
}

/** An agent's open helper bookings that overlap $start - $end (for mitScheduleConflicts), as text lines. */
function mitTicketTechConflicts($user_id, $start, $end, $ticket_id = 0) {
    global $mysqli;
    if (!mitTicketTechsReady()) {
        return [];
    }
    $user_id = intval($user_id);
    $ticket_id = intval($ticket_id);
    $start = escapeSql($start);
    $end = escapeSql($end);
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $s = MIT_HELPER_START_SQL;
    $e = MIT_HELPER_END_SQL;
    $sql = mysqli_query($mysqli, "SELECT ticket_prefix, ticket_number, ticket_subject, ticket_client_id, $s AS start, $e AS end FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        WHERE tt.user_id = $user_id AND tt.ticket_id <> $ticket_id AND tt.user_id <> ticket_assigned_to
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND $s IS NOT NULL AND $s < '$end'
        AND COALESCE($e, DATE_ADD($s, INTERVAL $default_minutes MINUTE)) > '$start'
        ORDER BY start");
    $lines = [];
    while ($row = mysqli_fetch_assoc($sql)) {
        $row_end = mitTicketScheduleEnd($row['start'], $row['end']);
        $lines[] = mitConflictWhat($row) . ' (helping) @ ' . date('g:ia', strtotime($row['start'])) . '-' . date('g:ia', strtotime($row_end));
    }
    return $lines;
}

/** A tech's open helper bookings for their Outlook (functions/mit_outlook.php), ticket_id => row like the lead's. */
function mitTicketTechOutlookBookings($user_id, $past_days) {
    global $mysqli;
    if (!mitTicketTechsReady()) {
        return [];
    }
    mitTicketTechsTidy();
    $user_id = intval($user_id);
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $past_days = intval($past_days);
    $s = MIT_HELPER_START_SQL;
    $e = MIT_HELPER_END_SQL;
    $result = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, $s AS ticket_schedule, $e AS ticket_schedule_end, ticket_onsite,
            client_name, contact_name, contact_phone, contact_mobile, location_name, location_address, location_city, location_state, location_zip
        FROM mit_ticket_techs tt
        JOIN tickets ON tickets.ticket_id = tt.ticket_id
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN contacts ON contact_id = ticket_contact_id
        LEFT JOIN locations ON location_id = ticket_location_id
        WHERE tt.user_id = $user_id AND tt.user_id <> ticket_assigned_to AND $s IS NOT NULL
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND COALESCE($e, DATE_ADD($s, INTERVAL $default_minutes MINUTE)) >= DATE_SUB(NOW(), INTERVAL $past_days DAY)
        ORDER BY ticket_schedule");
    $bookings = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $bookings[intval($row['ticket_id'])] = $row;
    }
    return $bookings;
}

/**
 * A helper's booking dragged on the calendar (agent/ajax.php calendar_move, kind 'helper'):
 * it becomes their own time. Answers in JSON and stops.
 */
function mitTicketTechCalendarMove(array $post) {
    $fail = function ($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    };
    if (!calendarCanScheduleTickets()) {
        $fail('Your role cannot schedule tickets.', 403);
    }
    $row = ticketRow($post['id'] ?? 0);
    if (!$row || !empty($row['ticket_archived_at'])) {
        $fail('That ticket no longer exists.', 404);
    }
    if (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id'])) {
        $fail('You do not have access to that client.', 403);
    }
    if (!ticketIsOpen($row)) {
        $fail('Ticket ' . ticketReference($row) . ' is resolved or closed - reopen it to schedule work.');
    }
    $user_id = intval($post['user_id'] ?? 0);
    $tech = mitTicketTechs($row['ticket_id'])[$user_id] ?? null;
    if (!$tech) {
        $fail('That agent is no longer on the ticket.', 404);
    }
    $start = ticketScheduleValue($post['start'] ?? '');
    if ($start === '') {
        $fail('That is not a date and time.');
    }
    if (!empty($post['all_day'])) {
        $start = substr($start, 0, 10) . ' ' . ($tech['start'] !== '' ? date('H:i:s', strtotime($tech['start'])) : '09:00:00');
    }
    $end = ticketScheduleValue($post['end'] ?? '');
    if ($end === '' || $end <= $start || !empty($post['all_day'])) {
        $length = $tech['start'] !== '' ? mitTicketScheduleMinutes($tech['start'], $tech['end']) : CALENDAR_TICKET_MINUTES;
        $end = date('Y-m-d H:i:s', strtotime($start) + $length * 60);
    }
    [$ok, $message, $conflicts] = mitTicketTechSave($row, $user_id, 'own', $start, $end);
    if (!$ok) {
        $fail(strip_tags($message));
    }
    echo json_encode(['ok' => true, 'message' => $tech['user_name'] . ' scheduled on ' . ticketReference($row) . ' for ' . mitScheduleWhenText($start, $end), 'conflicts' => $conflicts]);
    exit;
}
