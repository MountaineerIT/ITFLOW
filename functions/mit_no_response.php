<?php

/*
 * Mountaineer IT - "Resolve - no response": for the rare ticket whose client never answers after several tries. Beside
 * Resolve on the ticket page (mitNoResponseButton - one line in agent/ticket.php) a window (agent/modals/mit_no_response/
 * no_response.php, handler agent/post/mit_no_response.php) shows the company's message filled in for the ticket, editable;
 * sending posts it as a public note, emails it to the contact and watchers instead of ITFlow's "Ticket resolved" mail, and
 * resolves the ticket - ITFlow's nightly job closes it later as usual (that sends no mail), and a client reply before then
 * reopens it as usual. No Customer Thermometer survey goes out for it (mitNoResponseNotSql in mitCtDueTickets).
 * Table M037 mit_ticket_no_response (one row per ticket with the ticket_resolved_at it was made for - a later resolution
 * after a reopen is a normal one). Template: mit_settings no_response_message, edited on Admin > Settings > Ticket
 * (admin/includes/mit_no_response_card.php, handler admin/post/mit_no_response_settings.php).
 */

const MIT_NO_RESPONSE_DEFAULT = "Hello [First Name],\n\n"
    . "We have reached out a few times about this ticket ([Ticket] - [Subject]) but have not heard back, so we are marking it as resolved for now.\n\n"
    . "If you still need help, just reply to this email and we will pick it up again.\n\n"
    . "Thank you,\n[Agent]";

/** Is M037 in? (one cached query) */
function mitNoResponseReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_no_response'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The message template (plain text with [placeholders]). */
function mitNoResponseTemplate() {
    $text = (string) mitSetting('no_response_message', '');
    return trim($text) !== '' ? $text : MIT_NO_RESPONSE_DEFAULT;
}

/** The placeholders the template may use, and what each is for. */
function mitNoResponsePlaceholders() {
    return ['[First Name]' => "the contact's first name", '[Contact]' => "the contact's full name", '[Client]' => 'the client',
        '[Ticket]' => 'the ticket number', '[Subject]' => 'the subject', '[Agent]' => 'you', '[Date]' => 'today'];
}

/** The template filled in for a ticket (from ticketRow / ticketForModal). */
function mitNoResponseFill($text, array $row) {
    global $session_name;
    $contact = trim((string) ($row['contact_name'] ?? ''));
    $first = $contact !== '' ? preg_split('/\s+/', $contact)[0] : 'there';
    return strtr((string) $text, [
        '[First Name]' => $first,
        '[Contact]' => $contact !== '' ? $contact : 'there',
        '[Client]' => (string) ($row['client_name'] ?? ''),
        '[Ticket]' => ticketReference($row),
        '[Subject]' => (string) ($row['ticket_subject'] ?? ''),
        '[Agent]' => (string) ($session_name ?? ''),
        '[Date]' => date('F j, Y'),
    ]);
}

/** The ticket page button, beside Resolve (open tickets, the caller has checked edit rights and open tasks). */
function mitNoResponseButton(array $ticket) {
    if (!mitNoResponseReady() || !ticketIsOpen($ticket)) {
        return '';
    }
    // A split arrow joined onto ITFlow's Resolve button (#ticket_close, just before it) - a button of its own made the action row
    // wrap the ⋮ menu onto a second line
    return '<style>.ticket-actions #ticket_close:has(+ .mit-no-response) { border-top-right-radius: 0; border-bottom-right-radius: 0; }'
        . '.ticket-actions .mit-no-response { margin-left: calc(-.5rem - 1px); }'
        . '.ticket-actions .mit-no-response > .btn { border-top-left-radius: 0; border-bottom-left-radius: 0; border-left: 1px solid rgba(255,255,255,.35); }</style>'
        . '<div class="dropdown mit-no-response"><button type="button" class="btn btn-success dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"'
        . ' aria-expanded="false" title="More ways to resolve"><span class="visually-hidden">More ways to resolve</span></button>'
        . '<div class="dropdown-menu dropdown-menu-end"><a href="#" class="dropdown-item ajax-modal" data-mit-no-response data-modal-url="modals/mit_no_response/no_response.php?ticket_id='
        . intval($ticket['ticket_id']) . '"><i class="fas fa-fw fa-comment-slash me-2"></i>Resolve - no response...'
        . '<div class="small text-body-secondary ms-4">Message a client who never replied; no survey</div></a></div></div>';
}

/**
 * Resolve a ticket as "no response": the message as a public note, emailed to the contact and watchers, then ITFlow's own
 * resolve (status, SLA, history, audit, custom action) and our mark. $row from ticketForRequest; the caller has checked the
 * ticket is open and its tasks are done. Returns how many were emailed.
 */
function mitNoResponseResolve(array $row, $message) {
    global $mysqli, $session_user_id, $config_smtp_provider, $config_base_url, $config_ticket_from_email;
    $ticket_id = intval($row['ticket_id']);
    $html = nl2br(escapeHtml(trim((string) $message)));
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . mitSqlHtml($html) . "', ticket_reply_type = 'Public',
        ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");

    $mailed = 0;
    if (!empty($config_smtp_provider) && !empty($row['contact_email'])) {
        // ITFlow's ticketMailCompany() hands these back SQL-escaped; the body here is escaped once, at the end
        $company = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1")) ?: [];
        $company_name = (string) ($company['company_name'] ?? '');
        $company_phone = formatPhoneNumber($company['company_phone'] ?? '', $company['company_phone_country_code'] ?? '');
        $reference = escapeHtml(ticketReference($row));
        $link = 'https://' . escapeHtml($config_base_url) . '/guest/guest_view_ticket.php?ticket_id=' . $ticket_id . '&url_key=' . escapeHtml($row['ticket_url_key']);
        $body = "<i style='color: #808080'>##- Please type your reply above this line -##</i><br><br>$html<br><br>Ticket: $reference<br>Subject: "
            . escapeHtml($row['ticket_subject']) . "<br>Portal: <a href='$link'>View ticket</a><br><br>--<br>" . escapeHtml($company_name) . ' - Support<br>'
            . escapeHtml($config_ticket_from_email) . '<br>' . escapeHtml($company_phone);
        // ticketMailContactAndWatchers takes SQL-escaped text (as ITFlow's own mails build it)
        $mailed = intval(ticketMailContactAndWatchers($ticket_id, escapeSql("Ticket resolved - [" . ticketReference($row) . "] - " . $row['ticket_subject']), mitSqlHtml($body)));
    }

    ticketResolve($row, ' - no response from the client');
    $resolved_at = mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_resolved_at FROM tickets WHERE ticket_id = $ticket_id"))[0] ?? null;
    mysqli_query($mysqli, "INSERT INTO mit_ticket_no_response SET ticket_id = $ticket_id, resolved_at = " . ($resolved_at ? "'" . escapeSql($resolved_at) . "'" : 'NULL')
        . ", user_id = " . intval($session_user_id) . " ON DUPLICATE KEY UPDATE resolved_at = VALUES(resolved_at), user_id = VALUES(user_id), created_at = NOW()");
    return $mailed;
}

/** SQL: the ticket (alias $alias) was not resolved as "no response" in its current resolution - for surveys. */
function mitNoResponseNotSql($alias = 't') {
    if (!mitNoResponseReady()) {
        return '1';
    }
    $alias = preg_replace('/[^a-z_]/i', '', $alias);
    return "NOT EXISTS (SELECT 1 FROM mit_ticket_no_response nr WHERE nr.ticket_id = $alias.ticket_id AND nr.resolved_at <=> $alias.ticket_resolved_at)";
}

/*
 * The "Ticket resolved" email (ITFlow's ticketMailResolved) in the company's own words: mit_settings resolved_message, edited on the
 * same Admin > Settings > Ticket card. Empty = ITFlow's own email, unchanged. One guarded line in functions/ticket.php
 * ticketMailResolved swaps the body for mitResolvedMailBody's. The "type your reply above this line" marker and the company signature
 * are added around it as ITFlow does; the subject stays ITFlow's.
 */

const MIT_RESOLVED_DEFAULT = "Hello [Contact],\n\n"
    . "Your ticket regarding [Subject] has been marked as solved and is pending closure.\n\n"
    . "[Note]\n\n"
    . "If your request/issue is resolved, you can simply ignore this email. If you need further assistance, please reply or re-open it here: [Ticket Link]\n\n"
    . "Ticket: [Ticket]\nSubject: [Subject]\nStatus: [Status]";

/** The saved wording for the "Ticket resolved" email, or '' for ITFlow's own. */
function mitResolvedTemplateSaved() {
    return trim((string) mitSetting('resolved_message', ''));
}

/** What the card shows: the saved wording, else ITFlow's own written as a template. */
function mitResolvedTemplate() {
    $saved = mitResolvedTemplateSaved();
    return $saved !== '' ? $saved : MIT_RESOLVED_DEFAULT;
}

/** The placeholders the resolved email may use, and what each is for. */
function mitResolvedPlaceholders() {
    return mitNoResponsePlaceholders() + ['[Status]' => 'its status', '[Ticket Link]' => 'a link to view or re-open it',
        '[Note]' => 'what the agent wrote when resolving (left out when nothing was)'];
}

/**
 * The resolved email's body in the company's words, SQL-escaped as ticketMailContactAndWatchers takes it - or null when none is
 * saved (ITFlow's own body is used). $note is ITFlow's: the agent's words, already SQL-escaped HTML, or ''.
 */
function mitResolvedMailBody(array $row, $note = '') {
    global $mysqli, $config_base_url, $config_ticket_from_email;
    $template = mitResolvedTemplateSaved();
    if ($template === '') {
        return null;
    }
    $ticket_id = intval($row['ticket_id']);
    $link = 'https://' . $config_base_url . '/guest/guest_view_ticket.php?ticket_id=' . $ticket_id . '&url_key=' . $row['ticket_url_key'];
    $text = mitNoResponseFill(strtr($template, ['[Status]' => (string) ($row['ticket_status_name'] ?? '')]), $row);
    // An empty note takes its line with it
    if ($note === '') {
        $text = preg_replace('/[ \t]*\[Note\][ \t]*(\n\n|\n)?/', '', $text);
    }
    $html = nl2br(escapeHtml(trim($text)));
    $html = str_replace('[Ticket Link]', "<a href='" . escapeHtml($link) . "'>View ticket</a>", $html);
    $company = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT company_name, company_phone, company_phone_country_code FROM companies WHERE company_id = 1")) ?: [];
    $html = "<i style='color: #808080'>##- Please type your reply above this line -##</i><br><br>$html<br><br>--<br>" . escapeHtml($company['company_name'] ?? '')
        . ' - Support<br>' . escapeHtml($config_ticket_from_email) . '<br>' . escapeHtml(formatPhoneNumber($company['company_phone'] ?? '', $company['company_phone_country_code'] ?? ''));
    // The note is already escaped for SQL - put it in after escaping the rest
    $body = mitSqlHtml(str_replace('[Note]', '%%MIT_RESOLVED_NOTE%%', $html));
    return str_replace('%%MIT_RESOLVED_NOTE%%', $note !== '' ? "--------------------------------<br>$note<br>--------------------------------" : '', $body);
}
