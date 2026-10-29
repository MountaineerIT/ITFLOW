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
        '[Agent]' => stripslashes((string) ($session_name ?? '')), // ITFlow keeps the name SQL-escaped
        '[Date]' => date('F j, Y'),
    ]);
}

/** The ticket page button, beside Resolve (open tickets, the caller has checked edit rights and open tasks). */
function mitNoResponseButton(array $ticket) {
    if (!mitNoResponseReady() || !ticketIsOpen($ticket)) {
        return '';
    }
    // A split arrow joined onto ITFlow's Resolve button (#ticket_close, just before it) - a button of its own made the action row
    // wrap the ⋮ menu onto a second line. ITFlow's action group may shrink and wrap, so a long subject squeezed it until the arrow
    // and ⋮ fell under Resolve: from md up (where the header keeps one row) it keeps its width and one line, the subject wraps instead
    return '<style>@media (min-width: 768px) { .ticket-actions:has(> .mit-no-response) { flex-shrink: 0; flex-wrap: nowrap !important; } }'
        . '.ticket-actions:has(> .mit-no-response) > .btn { white-space: nowrap; }'
        . '.ticket-actions #ticket_close:has(+ .mit-no-response) { border-top-right-radius: 0; border-bottom-right-radius: 0; }'
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
    global $mysqli, $session_user_id;
    $ticket_id = intval($row['ticket_id']);
    $html = nl2br(escapeHtml(trim((string) $message)));
    mysqli_query($mysqli, "INSERT INTO ticket_replies SET ticket_reply = '" . mitSqlHtml($html) . "', ticket_reply_type = 'Public',
        ticket_reply_time_worked = '00:00:00', ticket_reply_by = " . intval($session_user_id) . ", ticket_reply_ticket_id = $ticket_id");
    $reply_id = intval(mysqli_insert_id($mysqli));

    // Resolved first, so the email's ticket details say Resolved (ticketResolve sends nothing itself)
    ticketResolve($row, ' - no response from the client');

    // ITFlow's "resolved" email (26.11's emailHtml look: reply line, company details, the conversation), its words ours
    $mailed = 0;
    $ticket = ticketRow($ticket_id);
    // The contact when they have an address, and the watchers either way (as ITFlow's own resolved email)
    $watchers = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id"))[0] ?? 0);
    if ($ticket && (!empty($row['contact_email']) || $watchers)) {
        $content = ticketEmailContent($ticket, 'resolved', ['message' => '', 'by' => stripslashes((string) ($GLOBALS['session_name'] ?? ''))]);
        $content['intro'] = mitTextParagraphs($message);
        unset($content['message']);
        $content['history'] = [];
        $mailed = intval(ticketMailSend($ticket_id, "Ticket resolved - [" . ticketReference($ticket) . "] - " . $ticket['ticket_subject'], ticketEmailPreview($content),
            ['to_contact' => !empty($row['contact_email']), 'ticket_reply_id' => $reply_id]));
    }
    // ITFlow 3.4.8: the note shows who it was emailed to
    if (function_exists('ticketReplyEmailed')) {
        ticketReplyEmailed($reply_id, $mailed > 0);
    }

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

/**
 * Plain text in paragraphs for emailHtml's intro - blank lines split them, a single line break stays one ("Thanks,\nChris" -
 * emailHtml writes each paragraph with nl2br). A first paragraph that is only a greeting ("Hi Zed," / "Hello [First Name],") is
 * left out: ITFlow's email greets each person itself ("Hello Zed Contact," for the contact, "Hello," for a watcher), so it said
 * hello twice and greeted watchers with the contact's name.
 */
function mitTextParagraphs($text) {
    $paragraphs = preg_split('/\n\s*\n/', str_replace("\r", '', trim((string) $text)));
    $paragraphs = array_values(array_filter(array_map(function ($p) {
        return implode("\n", array_filter(array_map('trim', explode("\n", $p)), 'strlen'));
    }, $paragraphs), 'strlen'));
    if ($paragraphs && mitIsGreeting($paragraphs[0])) {
        array_shift($paragraphs);
    }
    return $paragraphs;
}

/** Is this line just a greeting - "Hi Zed,", "Hello there!", "Dear Ann Lee,", "Good morning,"? */
function mitIsGreeting($line) {
    $line = trim((string) $line);
    return strpos($line, "\n") === false && mb_strlen($line) <= 60
        && (bool) preg_match('/^(hi|hello|hey|dear|greetings|good (morning|afternoon|evening))\b[^.?\n]*[,!:]?$/iu', $line);
}
