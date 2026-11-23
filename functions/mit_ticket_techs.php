<?php

/*
 * Mountaineer IT - agents helping on a ticket. Since 26.12 ITFlow has them itself (functions/ticket_helpers.php: ticket_helpers,
 * Additional agents on the ticket, their bookings, invites and the +N badge - built from ours; M067 copied mit_ticket_techs into it).
 * What stays ours, as hooks on ITFlow's:
 *  - a helper must be able to WORK the ticket - Support edit and the client (the user's rule; ITFlow lets anyone who can read it
 *    help): one guarded block in ticketHelperSave (mitAgentCanWorkTicket);
 *  - a helper booked before the move keeps the calendar UID their calendar already has ("ticket-<id>-tech-<user>@"), so a move or
 *    cancel still finds it: one line in ticketHelperUid (mitTicketHelperUid);
 *  - an agent whose Outlook is synced gets no invite file (one line in ticketHelperMail - functions/mit_outlook.php);
 *  - helpers can be added in an email reply's To line (one line in ticketReplyRecipients - mitReplyRecipientsHelpers);
 *  - a ticket moved to another client takes off helpers who can't see it and takes its waiting parts along
 *    (mitTicketClientMoved - one line in change_client_ticket);
 *  - their bookings in Outlook (mitTicketTechOutlookBookings, from functions/mit_outlook.php).
 * Also here, used across our features: mitAgentCanSeeClient / mitAgentSupportLevel / mitAgentCanWorkTicket - what another agent's
 * role and client access let them do.
 */

/** ITFlow's helpers table is there (database 3.8.5). */
function mitTicketTechsReady() {
    return function_exists('ticketHelpersReady') && ticketHelpersReady();
}

/** The agents helping on a ticket: user_id => row (ITFlow's ticketHelpers()). */
function mitTicketTechs($ticket_id) {
    return mitTicketTechsReady() ? ticketHelpers($ticket_id) : [];
}

/** " AND (the lead is $user_id, or they are helping)" - for "mine" lists of ours. */
function mitAssignedSql($user_id, $id_column = 'tickets.ticket_id') {
    return function_exists('ticketAssignedSql') ? ticketAssignedSql($user_id) : "ticket_assigned_to = " . intval($user_id);
}

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

/** The UID a helper's calendar already has from before 26.12 (ours: ticket-<id>-tech-<user>@), or '' for ITFlow's own. */
function mitTicketHelperUid($ticket_id, $user_id) {
    global $mysqli;
    static $ours = null;
    if ($ours === null) {
        $ours = false;
        $result = mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_techs'");
        if ($result && mysqli_num_rows($result)) {
            $ours = true;
        }
    }
    if (!$ours) {
        return '';
    }
    $found = mysqli_fetch_row(mysqli_query($mysqli, "SELECT 1 FROM mit_ticket_techs WHERE ticket_id = " . intval($ticket_id) . " AND user_id = " . intval($user_id)));
    return $found ? 'ticket-' . intval($ticket_id) . '-tech-' . intval($user_id) . '@' . ($_SERVER['SERVER_NAME'] ?? 'itflow') : '';
}

/** ticketReplyRecipients' $add for each agent helping on the ticket - offered, not ticked. */
function mitReplyRecipientsHelpers($ticket_id, callable $add) {
    foreach (mitTicketTechs($ticket_id) as $user_id => $helper) {
        if (!empty($helper['user_email'])) {
            $add($helper['user_email'], $helper['user_name'] . ' (helping)', 'agent', false,
                function_exists('selectOptionUser') ? selectOptionUser($user_id, $helper['user_name'], $helper['user_avatar'] ?? '') : '');
        }
    }
}

/**
 * A ticket moved to another client (agent/post/ticket.php change_client_ticket - ITFlow there takes off the old client's watchers and
 * the To line's choices): its parts still to order / to receive go with it (functions/mit_procurement_extras.php), and helpers who
 * can't see the new client come off (their booking cancelled - ITFlow's ticketHelperRemove).
 */
function mitTicketClientMoved($ticket_id, $client_id) {
    $ticket_id = intval($ticket_id);
    $client_id = intval($client_id);
    if (function_exists('mitProcTicketClientMoved')) {
        mitProcTicketClientMoved($ticket_id, $client_id);
    }
    $helpers = mitTicketTechs($ticket_id);
    $ticket = $helpers ? ticketRow($ticket_id) : null;
    foreach ($ticket ? $helpers : [] as $user_id => $helper) {
        if (!mitAgentCanSeeClient($user_id, $client_id)) {
            ticketHelperRemove($ticket, $user_id);
        }
    }
}

/** An agent's open helper bookings for their Outlook (functions/mit_outlook.php), ticket_id => row like the lead's. */
function mitTicketTechOutlookBookings($user_id, $past_days) {
    global $mysqli;
    if (!mitTicketTechsReady()) {
        return [];
    }
    $user_id = intval($user_id);
    $default_minutes = intval(CALENDAR_TICKET_MINUTES);
    $past_days = intval($past_days);
    $s = TICKET_HELPER_START_SQL;
    $e = TICKET_HELPER_END_SQL;
    $result = mysqli_query($mysqli, "SELECT tickets.ticket_id, ticket_prefix, ticket_number, ticket_subject, $s AS ticket_schedule, $e AS ticket_schedule_end, ticket_onsite,
            client_name, contact_name, contact_phone, contact_mobile, location_name, location_address, location_city, location_state, location_zip
        FROM ticket_helpers th
        JOIN tickets ON tickets.ticket_id = th.ticket_id
        LEFT JOIN clients ON client_id = ticket_client_id
        LEFT JOIN contacts ON contact_id = ticket_contact_id
        LEFT JOIN locations ON location_id = IF(ticket_location_id > 0, ticket_location_id, contact_location_id)
        WHERE th.user_id = $user_id AND th.user_id <> ticket_assigned_to AND $s IS NOT NULL
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL
        AND COALESCE($e, DATE_ADD($s, INTERVAL $default_minutes MINUTE)) >= DATE_SUB(NOW(), INTERVAL $past_days DAY)
        ORDER BY ticket_schedule");
    $bookings = [];
    while ($result && $row = mysqli_fetch_assoc($result)) {
        $bookings[intval($row['ticket_id'])] = $row;
    }
    return $bookings;
}
