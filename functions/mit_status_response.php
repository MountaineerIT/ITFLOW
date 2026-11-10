<?php

/*
 * Mountaineer IT - statuses that meet the SLA response target. On Admin > Ticket Statuses a status (Open, On Hold or one of your
 * own - an "Assigned" status, say) can be set to count as the first response: a ticket moved INTO it before anyone replied gets
 * ticket_first_response_at (ITFlow's setTicketFirstResponse - met or missed against its response due time). With "and email the
 * client", the client (contact, watchers, colleagues who asked for every update - ITFlow's ticketMailSend) is first sent the
 * "ticket assigned" message, also kept as a public note; when nobody could be emailed (no address, no mail set up, client
 * notifications off in Admin > Ticket) it does not count. The message is edited beside "Resolve - no response" on Admin > Settings >
 * Ticket (admin/includes/mit_no_response_card.php, mit_settings status_response_message).
 *
 * Only a move counts: mit_ticket_status_seen keeps each ticket's last status seen (written only while some status has the setting;
 * set for every open ticket when a status is switched on, so tickets already in it are not emailed). Hooks: one guarded line at the
 * start of ITFlow's syncTicketSlaClock() in functions/sla.php - which ITFlow calls after a status change -, the field on the status
 * form (mitStatusResponseField - one line in admin/modals/ticket_status/ticket_status_form.php) and its save (mitStatusResponseSave
 * - one line in admin/post/ticket_status.php). A few of ITFlow's paths change the status without the sync (API replies, texts, the
 * parser's reopen); cron mit_boards runs mitStatusResponseSweep every minute for them. Tables M059.
 */

const MIT_STATUS_RESPONSE_DEFAULT = "Hello [First Name],\n\n"
    . "Thank you for reaching out. Your ticket [Ticket] - [Subject] has been assigned to [Tech], who will be in touch with you shortly.\n\n"
    . "If you have anything to add in the meantime, just reply to this email.\n\n"
    . "Thank you,\n[Company]";

/** Is M059 in? (one cached query) */
function mitStatusResponseReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_status_seen'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The statuses that may carry the setting: Open, On Hold and your own - never New, Resolved or Closed. */
function mitStatusResponseAllowed($ticket_status_id) {
    $ticket_status_id = intval($ticket_status_id);
    return $ticket_status_id > 0 && !in_array($ticket_status_id, [TICKET_STATUS_NEW, TICKET_STATUS_RESOLVED, TICKET_STATUS_CLOSED], true);
}

/** status id => send_email (1 / 0), for the statuses that meet the response target. $fresh after a change. */
function mitStatusResponseStatuses($fresh = false) {
    global $mysqli;
    static $statuses = null;
    if ($statuses === null || $fresh) {
        $statuses = [];
        if (mitStatusResponseReady()) {
            $sql = mysqli_query($mysqli, "SELECT ticket_status_id, send_email FROM mit_ticket_status_response");
            while ($row = mysqli_fetch_assoc($sql)) {
                if (mitStatusResponseAllowed($row['ticket_status_id'])) {
                    $statuses[intval($row['ticket_status_id'])] = intval($row['send_email']) ? 1 : 0;
                }
            }
        }
    }
    return $statuses;
}

/** A status's setting: 0 off, 1 counts without an email, 2 counts after emailing the client. */
function mitStatusResponseMode($ticket_status_id) {
    $statuses = mitStatusResponseStatuses();
    $ticket_status_id = intval($ticket_status_id);
    return isset($statuses[$ticket_status_id]) ? ($statuses[$ticket_status_id] ? 2 : 1) : 0;
}

/** The status form's field (ticket_status_form.php sets $s_id - 0 for a new status). */
function mitStatusResponseField($ticket_status_id) {
    $ticket_status_id = intval($ticket_status_id);
    if (!mitStatusResponseReady() || ($ticket_status_id && !mitStatusResponseAllowed($ticket_status_id))) {
        return '';
    }
    $mode = $ticket_status_id ? mitStatusResponseMode($ticket_status_id) : 0;
    $options = [0 => 'No - only a reply to the client counts', 2 => 'Yes - after emailing the client the "ticket assigned" message', 1 => 'Yes - without an email'];
    $html = '<div class="mb-3" data-mit-status-response><label class="form-label">SLA response target</label><div class="input-group">'
        . '<span class="input-group-text"><i class="fa fa-fw fa-reply"></i></span><select class="form-select" name="mit_status_response">';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($value === $mode ? ' selected' : '') . '>' . escapeHtml($label) . '</option>';
    }
    return $html . '</select><input type="hidden" name="mit_status_response_present" value="1"></div>'
        . '<small class="text-secondary">A ticket moved into this status before anyone has replied counts as answered - the response target is met (or'
        . ' missed) then. Only a move into it counts, once per ticket. With the email, the client and watchers get the message set in Admin > Settings >'
        . ' Ticket (Messages to clients), kept as a public note; if nobody could be emailed, it does not count. Assigning a New ticket moves it to Open.</small></div>';
}

/** Save the field after ITFlow saved the status (only when the form carried it). Audit-logged when it changed. */
function mitStatusResponseSave($ticket_status_id, array $post) {
    global $mysqli, $session_name;
    $ticket_status_id = intval($ticket_status_id);
    if (!mitStatusResponseReady() || empty($post['mit_status_response_present']) || !mitStatusResponseAllowed($ticket_status_id)) {
        return '';
    }
    $mode = intval($post['mit_status_response'] ?? 0);
    $mode = in_array($mode, [0, 1, 2], true) ? $mode : 0;
    $was = mitStatusResponseMode($ticket_status_id);
    if ($mode === $was) {
        return '';
    }
    if ($mode === 0) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_status_response WHERE ticket_status_id = $ticket_status_id");
    } else {
        // Switched on with nothing on before: what every open ticket is in now is "seen", so only later moves count
        if (!mitStatusResponseStatuses()) {
            mitStatusResponseSeedSeen();
        }
        mysqli_query($mysqli, "INSERT INTO mit_ticket_status_response SET ticket_status_id = $ticket_status_id, send_email = " . ($mode === 2 ? 1 : 0)
            . " ON DUPLICATE KEY UPDATE send_email = VALUES(send_email)");
        if (!mitSetting('status_response_since', '')) {
            mitSetSetting('status_response_since', date('Y-m-d H:i:s'));
        }
    }
    mitStatusResponseStatuses(true);
    $said = ['off', 'on, without an email', 'on, emailing the client'][$mode];
    $name = escapeSql((string) (ticketStatusRow($ticket_status_id)['ticket_status_name'] ?? $ticket_status_id));
    logAudit("Ticket Status", "Edit", "$session_name set ticket status $name meeting the SLA response target $said", 0, $ticket_status_id);
    return $said;
}

/** Every open ticket's current status as seen (when the first status is switched on - nothing in it now is emailed). */
function mitStatusResponseSeedSeen() {
    global $mysqli;
    mysqli_query($mysqli, "INSERT INTO mit_ticket_status_seen (ticket_id, status_id)
        SELECT ticket_id, ticket_status FROM tickets WHERE ticket_closed_at IS NULL AND ticket_resolved_at IS NULL
        ON DUPLICATE KEY UPDATE status_id = VALUES(status_id)");
}

/**
 * After a ticket's status may have changed (the hook at the start of syncTicketSlaClock, and the sweep): when it moved into a status
 * that meets the response target and nobody has replied yet, email the client (if set) and record the first response. Claims the
 * move first (seen row changed by this call), so two requests at once can't email twice. Returns true when it counted.
 */
function mitStatusResponseCheck($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $statuses = mitStatusResponseStatuses();
    if (!$ticket_id || !$statuses) {
        return false;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_status, ticket_created_at, ticket_first_response_at, ticket_resolved_at, ticket_closed_at,
            ticket_archived_at, s.status_id AS seen_status FROM tickets LEFT JOIN mit_ticket_status_seen s ON s.ticket_id = tickets.ticket_id
        WHERE tickets.ticket_id = $ticket_id LIMIT 1"));
    if (!$row) {
        return false;
    }
    $status = intval($row['ticket_status']);
    if ($row['seen_status'] !== null && intval($row['seen_status']) === $status) {
        return false;
    }
    // Claim the move: only the request that changes the seen row goes on
    if ($row['seen_status'] === null) {
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_ticket_status_seen SET ticket_id = $ticket_id, status_id = $status");
    } else {
        mysqli_query($mysqli, "UPDATE mit_ticket_status_seen SET status_id = $status WHERE ticket_id = $ticket_id AND status_id = " . intval($row['seen_status']));
    }
    if (mysqli_affected_rows($mysqli) !== 1) {
        return false;
    }
    if (!isset($statuses[$status]) || !empty($row['ticket_first_response_at']) || !empty($row['ticket_resolved_at'])
        || !empty($row['ticket_closed_at']) || !empty($row['ticket_archived_at'])) {
        return false;
    }
    // Never seen before: a ticket from before the setting was switched on (it was seeded then) is not one that just moved
    $since = (string) mitSetting('status_response_since', '');
    if ($row['seen_status'] === null && $since !== '' && strtotime((string) $row['ticket_created_at']) < strtotime($since)) {
        return false;
    }
    $status_name = escapeSql((string) (ticketStatusRow($status)['ticket_status_name'] ?? 'this status'));

    if ($statuses[$status]) {
        $mailed = mitStatusResponseMail($ticket_id);
        if ($mailed < 1) {
            logTicketHistory($ticket_id, "Moved to $status_name, but nobody could be emailed - the response target is still open");
            return false;
        }
        setTicketFirstResponse($ticket_id);
        logTicketHistory($ticket_id, "The client was emailed that the ticket is assigned ($mailed) - moving to $status_name counts as the first response");
    } else {
        setTicketFirstResponse($ticket_id);
        logTicketHistory($ticket_id, "Moving to $status_name counts as the first response");
    }
    return true;
}

/** The message template (plain text with [placeholders]). */
function mitStatusResponseTemplate() {
    $text = (string) mitSetting('status_response_message', '');
    return trim($text) !== '' ? $text : MIT_STATUS_RESPONSE_DEFAULT;
}

/** The placeholders the message may use. */
function mitStatusResponsePlaceholders() {
    return ['[First Name]' => "the contact's first name", '[Contact]' => "the contact's full name", '[Client]' => 'the client',
        '[Ticket]' => 'the ticket number', '[Subject]' => 'the subject', '[Tech]' => 'the assigned tech (or "our team")',
        '[Status]' => 'the status', '[Company]' => 'your company', '[Date]' => 'today'];
}

/** The template filled in for a ticket (ticketRow). */
function mitStatusResponseFill($text, array $row) {
    $contact = trim((string) ($row['contact_name'] ?? ''));
    $tech = trim((string) ($row['user_name'] ?? ''));
    return strtr((string) $text, [
        '[First Name]' => $contact !== '' ? preg_split('/\s+/', $contact)[0] : 'there',
        '[Contact]' => $contact !== '' ? $contact : 'there',
        '[Client]' => (string) ($row['client_name'] ?? ''),
        '[Ticket]' => ticketReference($row),
        '[Subject]' => (string) ($row['ticket_subject'] ?? ''),
        '[Tech]' => $tech !== '' && intval($row['ticket_assigned_to'] ?? 0) ? $tech : 'our team',
        '[Status]' => (string) ($row['ticket_status_name'] ?? ''),
        '[Company]' => (string) (billingCompany()['company_name'] ?? ''),
        '[Date]' => date('F j, Y'),
    ]);
}

/**
 * Email the client the message - a public note first (by whoever made the change, else the assigned tech), then ITFlow's ticket
 * email to the contact and watchers, recorded on the note. Client notifications (Admin > Ticket) must be on. How many were emailed.
 */
function mitStatusResponseMail($ticket_id) {
    global $mysqli, $session_user_id;
    $ticket_id = intval($ticket_id);
    $notify = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_ticket_client_general_notifications FROM settings WHERE company_id = 1"))[0] ?? 0);
    $ticket = ticketRow($ticket_id);
    if (!$ticket || $notify !== 1 || !emailReady()) {
        return 0;
    }
    $message = trim(mitStatusResponseFill(mitStatusResponseTemplate(), $ticket));
    $by = intval($session_user_id ?? 0) ?: intval($ticket['ticket_assigned_to']);
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . mitSqlHtml(nl2br(escapeHtml($message))) . "', ticket_reply_type = 'Public',
        ticket_reply_time_worked = '00:00:00', ticket_reply_by = $by, ticket_reply_ticket_id = $ticket_id");
    $reply_id = intval(mysqli_insert_id($mysqli));

    $tech = intval($ticket['ticket_assigned_to']) ? trim((string) $ticket['user_name']) : '';
    $content = ticketEmailContent($ticket, 'reply', ['message' => '']);
    $content['kind'] = $tech !== '' ? 'Ticket assigned' : 'Ticket update';
    $content['title'] = $tech !== '' ? 'Your ticket is with ' . $tech : 'Your ticket has been picked up';
    $content['intro'] = mitTextParagraphs($message);
    unset($content['message']);
    $content['history'] = [];
    if ($tech !== '') {
        $content['summary'] = ($content['summary'] ?? []) + ['Assigned to' => $tech];
    }
    $mailed = intval(ticketMailSend($ticket_id, ($tech !== '' ? 'Ticket assigned' : 'Ticket update') . " - [" . ticketReference($ticket) . "] - " . $ticket['ticket_subject'],
        ticketEmailPreview($content), ['to_contact' => !empty($ticket['contact_email']), 'ticket_reply_id' => $reply_id]));
    if (function_exists('ticketReplyEmailed')) {
        ticketReplyEmailed($reply_id, $mailed > 0);
    }
    if ($mailed < 1) {
        // Nobody got it - the note would claim the client was told
        mysqli_query($mysqli, "DELETE FROM ticket_replies WHERE ticket_reply_id = $reply_id");
    }
    return $mailed;
}

/** Cron (mit_boards, every minute): tickets that moved into such a status by a path that skips the SLA sync. How many counted. */
function mitStatusResponseSweep() {
    global $mysqli;
    $statuses = mitStatusResponseStatuses();
    if (!$statuses) {
        return 0;
    }
    $in = implode(',', array_map('intval', array_keys($statuses)));
    $since = escapeSql((string) mitSetting('status_response_since', date('Y-m-d H:i:s')));
    $sql = mysqli_query($mysqli, "SELECT t.ticket_id FROM tickets t LEFT JOIN mit_ticket_status_seen s ON s.ticket_id = t.ticket_id
        WHERE t.ticket_status IN ($in) AND t.ticket_first_response_at IS NULL AND t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL
          AND t.ticket_archived_at IS NULL AND ((s.ticket_id IS NULL AND t.ticket_created_at >= '$since') OR s.status_id <> t.ticket_status)
        ORDER BY t.ticket_id LIMIT 200");
    $counted = 0;
    while ($row = mysqli_fetch_assoc($sql)) {
        $counted += mitStatusResponseCheck($row['ticket_id']) ? 1 : 0;
    }
    return $counted;
}
