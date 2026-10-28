<?php

/*
 * Mountaineer IT - Quick ticket (Oct 28, 26.10.192): a button in the top bar that opens a small window - client, contact and what the
 * issue is - for a call or a walk-up. It shows the contact's tickets that are still open (not resolved or closed), so the same
 * problem isn't raised twice, with "Work on this one" to go to one of them instead.
 *
 * Make ticket posts ITFlow's own New Ticket form (agent/post/ticket.php add_ticket) - so the ticket gets ITFlow's numbering, SLA (from
 * the client's contract), board routing (mitBoardAfterCreate), emails, audit log and assignment exactly as from New Ticket - with
 * `mit_quick_ticket` set. Two lines in add_ticket: mitQuickTicketAfterCreate() after the board line (the starting status, a line in
 * the ticket's history, the timer) and mitQuickTicketMailsClient() on ITFlow's "Ticket created" email.
 *
 * The timer: ITFlow keeps a ticket's timer in the browser (agent/js/ticket_time_tracking.js reads `<id>-startTime` when the page loads
 * and runs from it). The window posts when the timer should count from (the moment Make ticket was pressed, or when the window was
 * opened - the start of the call); the hook keeps it in the session, and the ticket page's first load writes it there before ITFlow's
 * script reads it (mitQuickTicketTimerScript, from mitTicketLayoutHtml - no line of its own in agent/ticket.php).
 *
 * Defaults are on Admin > Settings > Tickets, section "Quick tickets" (mitQuickTicketSection - one line before the save bar of
 * admin/settings_ticket.php; saved by mitQuickTicketSettingsSave - one line in admin/post/settings_ticket.php, its changes counted in
 * ITFlow's "saved" message and audit log). mit_settings quick_ticket_*. No tables.
 * Who: Support edit (as New Ticket) with ticketing on. Requests: agent/mit_quick_ticket_ajax.php; script agent/js/mit_quick_ticket.js.
 */

const MIT_QUICK_TICKET_TIMER = [
    'make' => 'From when Make ticket is pressed',
    'open' => 'From when the window was opened (the start of the call)',
    'off' => 'No - leave the timer as ITFlow would',
];

/** The defaults, as saved (or the built-in ones). */
function mitQuickTicketSettings() {
    $priority = (string) mitSetting('quick_ticket_priority', 'Medium');
    $timer = (string) mitSetting('quick_ticket_timer', 'make');
    return [
        'on' => mitSetting('quick_ticket_on', '1') === '1',
        // '' = routed as any new ticket (board rules, else the default board); a board id = always that board
        'board' => intval(mitSetting('quick_ticket_board', '0')),
        // 'me' = the agent making it, 'nobody' (the board's round-robin may pick someone), or an agent's id
        'assign' => (string) mitSetting('quick_ticket_assign', 'me'),
        // 0 = as ITFlow sets it (Open once someone has it, else New); a status id = that status
        'status' => intval(mitSetting('quick_ticket_status', '0')),
        'priority' => in_array($priority, TICKET_PRIORITIES, true) ? $priority : 'Medium',
        'category' => intval(mitSetting('quick_ticket_category', '0')),
        // '' = ITFlow's "billable by default"; '1' / '0'
        'billable' => (string) mitSetting('quick_ticket_billable', ''),
        'timer' => isset(MIT_QUICK_TICKET_TIMER[$timer]) ? $timer : 'make',
        'email_client' => mitSetting('quick_ticket_email_client', '1') === '1',
        'primary' => mitSetting('quick_ticket_primary', '1') === '1',
        'open_tickets' => mitSetting('quick_ticket_open_tickets', '1') === '1',
    ];
}

/** The signed-in agent may make tickets here: ticketing on, Support edit, switched on in the settings. */
function mitQuickTicketCan() {
    return !empty($GLOBALS['session_user_id']) && empty($_SESSION['client_logged_in']) && !empty($GLOBALS['config_module_enable_ticketing'])
        && lookupUserPermission('module_support') >= 2 && mitQuickTicketSettings()['on'];
}

/** The agent a new quick ticket goes to (0 = nobody), from the setting. */
function mitQuickTicketAssignee() {
    $assign = mitQuickTicketSettings()['assign'];
    if ($assign === 'me') {
        return intval($GLOBALS['session_user_id'] ?? 0);
    }
    return $assign === 'nobody' ? 0 : ticketAgentId(intval($assign));
}

/** Billable to start with: the setting, else ITFlow's own default. */
function mitQuickTicketBillable() {
    $billable = mitQuickTicketSettings()['billable'];
    return $billable === '' ? intval($GLOBALS['config_ticket_default_billable'] ?? 0) === 1 : $billable === '1';
}

/*
 * ---------- the top bar ----------
 */

/** The top bar's button and its window (echoed from includes/mit_timers_nav.php). */
function mitQuickTicketNav() {
    if (!mitQuickTicketCan()) {
        return '';
    }
    $s = mitQuickTicketSettings();
    $priorities = '';
    foreach (TICKET_PRIORITIES as $priority) {
        $priorities .= '<option' . ($priority === $s['priority'] ? ' selected' : '') . '>' . escapeHtml($priority) . '</option>';
    }
    $assignee = mitQuickTicketAssignee();
    $me = intval($GLOBALS['session_user_id']);
    $assign_label = $assignee === $me ? 'Assign it to me' : 'Assign it to ' . ($assignee ? escapeHtml(stripslashes((string) getFieldById('users', $assignee, 'user_name'))) : 'me');
    return '<li class="nav-item" id="mitQuickTicketNav">'
        . '<a class="nav-link" href="#" role="button" title="Quick ticket" aria-label="Quick ticket" data-mit-quick-ticket-open>'
        . '<i class="fas fa-plus-circle" aria-hidden="true"></i></a>'
        // hidden until the script moves it out of the top bar to <body> (a .modal inside the navbar is drawn as part of it)
        . '<div class="modal fade" id="mitQuickTicket" tabindex="-1" aria-labelledby="mitQuickTicketTitle" aria-hidden="true" hidden'
        . ' data-endpoint="/agent/mit_quick_ticket_ajax.php" data-timer="' . escapeHtml($s['timer']) . '" data-primary="' . ($s['primary'] ? '1' : '0') . '"'
        . ' data-open-tickets="' . ($s['open_tickets'] ? '1' : '0') . '" data-assignee="' . $assignee . '" data-me="' . $me . '">'
        . '<div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">'
        . '<form action="/agent/post.php" method="post" autocomplete="off" data-mit-quick-ticket-form>'
        . '<input type="hidden" name="csrf_token" value="' . escapeHtml($_SESSION['csrf_token'] ?? '') . '">'
        . '<input type="hidden" name="mit_quick_ticket" value="1">'
        . '<input type="hidden" name="mit_quick_timer" value="" data-mit-quick-timer>'
        . '<input type="hidden" name="details" value="" data-mit-quick-details-html>'
        . '<input type="hidden" name="category_id" value="' . $s['category'] . '">'
        . '<input type="hidden" name="mit_board" value="' . ($s['board'] ?: '') . '">'
        . '<input type="hidden" name="billable" value="' . (mitQuickTicketBillable() ? '1' : '0') . '">'
        . '<div class="modal-header bg-dark text-white"><h5 class="modal-title" id="mitQuickTicketTitle"><i class="fas fa-plus-circle me-2"></i>Quick ticket</h5>'
        . '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">'
        . '<div class="mb-3"><label class="form-label" for="mitQuickClient">Client <strong class="text-danger">*</strong></label>'
        . '<select class="form-select select2" name="client_id" id="mitQuickClient" required data-mit-quick-client><option value="">Loading...</option></select></div>'
        . '<div class="mb-3"><label class="form-label" for="mitQuickContact">Contact</label>'
        . '<select class="form-select select2" name="contact_id" id="mitQuickContact" data-mit-quick-contact disabled><option value="0">Pick the client first</option></select></div>'
        . '<div data-mit-quick-open-tickets></div>'
        . '<div class="mb-3"><label class="form-label" for="mitQuickSubject">What is the issue? <strong class="text-danger">*</strong></label>'
        . '<input type="text" class="form-control" name="subject" id="mitQuickSubject" maxlength="500" required placeholder="e.g. Outlook keeps asking for a password" data-mit-quick-subject></div>'
        . '<div class="mb-3"><label class="form-label" for="mitQuickDetails">More detail</label>'
        . '<textarea class="form-control" id="mitQuickDetails" rows="3" maxlength="20000" placeholder="What they told you, error messages, what has been tried" data-mit-quick-details></textarea></div>'
        . '<div class="row g-2 align-items-end">'
        . '<div class="col-sm-5"><label class="form-label" for="mitQuickPriority">Priority</label><select class="form-select" name="priority" id="mitQuickPriority">' . $priorities . '</select></div>'
        . '<div class="col-sm-7"><div class="form-check form-switch mb-2">'
        . '<input type="checkbox" class="form-check-input" role="switch" id="mitQuickAssign" name="assigned_to" value="' . ($assignee ?: $me) . '"' . ($assignee ? ' checked' : '') . ' data-mit-quick-assign>'
        . '<label class="form-check-label" for="mitQuickAssign">' . $assign_label . '</label></div></div>'
        . '</div>'
        . ($s['timer'] !== 'off' ? '<div class="small text-body-secondary mt-3" data-mit-quick-timer-note><i class="fas fa-stopwatch me-1"></i>The ticket\'s timer starts '
            . ($s['timer'] === 'open' ? 'from when this window was opened' : 'when it is made') . '.</div>' : '')
        . '<div class="small text-danger mt-2" data-mit-quick-error></div>'
        . '</div>'
        . '<div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>'
        . '<button type="submit" name="add_ticket" value="1" class="btn btn-primary" data-mit-quick-make><i class="fas fa-check me-2"></i>Make ticket</button></div>'
        . '</form></div></div></div>'
        . '<script src="' . mitAsset('/agent/js/mit_quick_ticket.js') . '" defer></script>'
        . '</li>';
}

/*
 * ---------- the window's requests (agent/mit_quick_ticket_ajax.php) ----------
 */

/** The clients the agent may raise tickets for, as <option>s (not archived, not leads - as New Ticket lists them). */
function mitQuickTicketClientOptions() {
    global $mysqli;
    $html = '<option value="">- Pick a client -</option>';
    $result = mysqli_query($mysqli, "SELECT client_id, client_name, client_abbreviation FROM clients
        WHERE client_archived_at IS NULL AND client_lead = 0 " . clientScopeSql('clients.client_id') . " ORDER BY client_name ASC");
    while ($row = mysqli_fetch_assoc($result)) {
        $html .= '<option value="' . intval($row['client_id']) . '"' . selectOptionClient($row['client_id'], $row['client_name'], $row['client_abbreviation']) . '>'
            . escapeHtml($row['client_name']) . '</option>';
    }
    return $html;
}

/** A client's contacts as <option>s and the one to start on (its primary, when the setting says so). [html, selected id] */
function mitQuickTicketContactOptions($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    if (!$client_id || !hasClientAccess($client_id)) {
        return ['<option value="0">- Nobody -</option>', 0];
    }
    $html = '<option value="0">- Nobody -</option>';
    $selected = 0;
    $result = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_title, contact_photo, contact_primary, contact_email, contact_phone, contact_mobile FROM contacts
        WHERE contact_client_id = $client_id AND contact_archived_at IS NULL ORDER BY contact_primary DESC, contact_name ASC");
    while ($row = mysqli_fetch_assoc($result)) {
        $id = intval($row['contact_id']);
        if (!$selected && intval($row['contact_primary']) && mitQuickTicketSettings()['primary']) {
            $selected = $id;
        }
        // Search words: their email and numbers, so a caller can be found by the number on the phone
        $words = trim(implode(' ', array_filter([$row['contact_email'], $row['contact_phone'], $row['contact_mobile']])));
        $html .= '<option value="' . $id . '"' . selectOptionContact($client_id, $row['contact_name'], $row['contact_photo'], $row['contact_title'], $id)
            . ($words !== '' ? ' data-search="' . escapeHtml($words) . '"' : '') . '>' . escapeHtml($row['contact_name']) . (intval($row['contact_primary']) ? ' (primary)' : '') . '</option>';
    }
    return [$html, $selected];
}

/** The contact's tickets still open (not resolved or closed) - to work on one of those instead of raising another. */
function mitQuickTicketOpenTickets($client_id, $contact_id) {
    $client_id = intval($client_id);
    $contact_id = intval($contact_id);
    if (!$client_id || !$contact_id || !hasClientAccess($client_id) || !ticketClientOwnedId('contact', $contact_id, $client_id)) {
        return [];
    }
    $rows = ticketRows("tickets.ticket_contact_id = $contact_id AND tickets.ticket_client_id = $client_id AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL", 10);
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => intval($row['ticket_id']),
            'reference' => ticketReference($row),
            'subject' => html_entity_decode((string) $row['ticket_subject'], ENT_QUOTES, 'UTF-8'),
            'status_html' => ticketStatusHtml($row['ticket_status_name'], $row['ticket_status_color'], 'detail'),
            'assigned' => $row['user_name'] !== null ? stripslashes(html_entity_decode((string) $row['user_name'], ENT_QUOTES, 'UTF-8')) : '',
            'mine' => intval($row['ticket_assigned_to']) === intval($GLOBALS['session_user_id'] ?? 0),
            'updated' => timeAgo($row['ticket_updated_at'] ?: $row['ticket_created_at']),
            'url' => ticketUrl(intval($row['ticket_id']), $client_id),
        ];
    }
    return $out;
}

/*
 * ---------- ITFlow's add_ticket (agent/post/ticket.php) ----------
 */

/** After ITFlow has made the ticket (and placed it on a board): the starting status, a history line and the timer. Only for quick tickets. */
function mitQuickTicketAfterCreate($ticket_id, array $post) {
    global $session_name;
    if (empty($post['mit_quick_ticket'])) {
        return;
    }
    $ticket_id = intval($ticket_id);
    $s = mitQuickTicketSettings();
    if ($s['status'] && isset(ticketPickableStatuses()[$s['status']])) {
        $row = ticketRow($ticket_id);
        if ($row && intval($row['ticket_status']) !== $s['status']) {
            ticketChangeStatus($ticket_id, $s['status']);
        }
    }
    logTicketHistory($ticket_id, escapeSql(stripslashes((string) $session_name) . ' raised it with Quick ticket'));
    // When the timer counts from - a time in ms by the agent's own browser clock (it is that browser's timer), checked to be a number
    $start = preg_match('/^\d{12,14}$/', (string) ($post['mit_quick_timer'] ?? '')) ? (string) $post['mit_quick_timer'] : '';
    if ($start !== '' && $s['timer'] !== 'off') {
        $_SESSION['mit_quick_timer'][$ticket_id] = $start;
    }
}

/** ITFlow's "Ticket created" email to the client: a quick ticket sends it only when the setting says so. */
function mitQuickTicketMailsClient(array $post) {
    return empty($post['mit_quick_ticket']) || mitQuickTicketSettings()['email_client'];
}

/** The ticket page's first load after a quick ticket: start ITFlow's timer from when the window said (before its script reads it). */
function mitQuickTicketTimerScript($ticket_id) {
    $ticket_id = intval($ticket_id);
    $start = $_SESSION['mit_quick_timer'][$ticket_id] ?? '';
    if ($start === '') {
        return '';
    }
    unset($_SESSION['mit_quick_timer'][$ticket_id]);
    // Only a timer not already there - ITFlow's keys: <id>-startTime (running from), <id>-pausedTime, ticket-timer-running-<id>
    return '<script>(function(){try{var id=' . json_encode((string) $ticket_id) . ';if(!localStorage.getItem(id+"-startTime")&&!localStorage.getItem(id+"-pausedTime")){'
        . 'localStorage.setItem(id+"-startTime",' . json_encode($start) . ');localStorage.setItem("ticket-timer-running-"+id,"true");}}catch(e){}})();</script>';
}

/*
 * ---------- Admin > Settings > Tickets ----------
 */

/** The "Quick tickets" section (inside ITFlow's form on admin/settings_ticket.php). */
function mitQuickTicketSection() {
    global $mysqli;
    if (!function_exists('settingsSectionStart')) {
        return '';
    }
    $s = mitQuickTicketSettings();
    $boards = ['0' => 'As any new ticket (the board rules, else the default board)'];
    if (function_exists('mitBoards')) {
        foreach (mitBoards() as $id => $board) {
            $boards[(string) $id] = $board['board_name'];
        }
    }
    $assign = ['me' => 'The agent making it', 'nobody' => 'Nobody (the board\'s round-robin may pick someone)'];
    $result = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_name ASC");
    while ($row = mysqli_fetch_assoc($result)) {
        $assign[(string) intval($row['user_id'])] = stripslashes($row['user_name']);
    }
    $statuses = ['0' => 'As ITFlow sets it (Open once someone has it, else New)'];
    foreach (ticketPickableStatuses() as $id => $status) {
        $statuses[(string) $id] = $status['ticket_status_name'];
    }
    $categories = '<option value="0"' . ($s['category'] ? '' : ' selected') . '>- Not Categorized -</option>' . categoryOptionsHtml('Ticket', $s['category'] ?: null);
    $billable = ['' => 'As "A new ticket starts billable" above', '1' => 'Billable', '0' => 'Not billable'];
    $email_note = intval($GLOBALS['config_ticket_client_general_notifications'] ?? 0) === 1 ? '' : ' (ITFlow\'s client emails are off in Notifications, so none go either way.)';
    return '<input type="hidden" name="mit_quick_ticket_present" value="1">'
        . settingsSectionStart('mit-quick-ticket', 'Quick tickets', 'The <i class="fas fa-plus-circle"></i> button in the top bar - client, contact and the issue, for a call or a walk-up. It shows the contact\'s tickets still open first, so the same problem isn\'t raised twice. These are what a quick ticket starts with.')
        . settingsSwitch('mit_quick_ticket_on', $s['on'] ? 1 : 0, ['label' => 'Show the Quick ticket button', 'help' => 'For everyone who can make tickets (Support edit).'])
        . settingsSelect('mit_quick_ticket_board', (string) $s['board'], $boards, ['icon' => 'fa-columns', 'label' => 'Board it lands on'])
        . settingsSelect('mit_quick_ticket_assign', $s['assign'], $assign, ['icon' => 'fa-user-check', 'label' => 'Assigned to', 'search' => true,
            'help' => 'The window has an "Assign it to ..." switch to change this for one ticket.'])
        . settingsSelect('mit_quick_ticket_status', (string) $s['status'], $statuses, ['icon' => 'fa-flag', 'label' => 'Status it starts in'])
        . settingsSelect('mit_quick_ticket_priority', $s['priority'], array_combine(TICKET_PRIORITIES, TICKET_PRIORITIES), ['icon' => 'fa-thermometer-half', 'label' => 'Priority',
            'help' => 'The window can change it for one ticket. The SLA follows the priority and the client\'s contract, as for any ticket.'])
        . settingsSelect('mit_quick_ticket_category', (string) $s['category'], [], ['icon' => 'fa-layer-group', 'label' => 'Category', 'options_html' => $categories])
        . settingsSelect('mit_quick_ticket_billable', $s['billable'], $billable, ['icon' => 'fa-dollar-sign', 'label' => 'Billable'])
        . settingsSelect('mit_quick_ticket_timer', $s['timer'], MIT_QUICK_TICKET_TIMER, ['icon' => 'fa-stopwatch', 'label' => 'Start the ticket\'s timer',
            'help' => 'It runs on the ticket page, as ITFlow\'s timer does, until the time goes on a note.'])
        . settingsSwitch('mit_quick_ticket_primary', $s['primary'] ? 1 : 0, ['label' => 'Start on the client\'s primary contact', 'help' => 'Otherwise the contact starts empty, to be picked.'])
        . settingsSwitch('mit_quick_ticket_open_tickets', $s['open_tickets'] ? 1 : 0, ['label' => 'Show the contact\'s open tickets', 'help' => 'Not resolved or closed - with "Work on this one" to go to one instead.'])
        . settingsSwitch('mit_quick_ticket_email_client', $s['email_client'] ? 1 : 0, ['label' => 'Email the contact that a ticket was opened',
            'help' => 'ITFlow\'s "Ticket created" email, as New Ticket sends it.' . $email_note])
        . settingsSectionEnd();
}

/** Save the section (from admin/post/settings_ticket.php). Lines for ITFlow's "saved" message and audit log. */
function mitQuickTicketSettingsSave(array $post) {
    if (empty($post['mit_quick_ticket_present'])) {
        return [];
    }
    $now = mitQuickTicketSettings();
    $assign = (string) ($post['mit_quick_ticket_assign'] ?? 'me');
    if (!in_array($assign, ['me', 'nobody'], true)) {
        $assign = ticketAgentId(intval($assign)) ? (string) intval($assign) : 'me';
    }
    $board = intval($post['mit_quick_ticket_board'] ?? 0);
    if ($board && (!function_exists('mitBoards') || !isset(mitBoards()[$board]))) {
        $board = 0;
    }
    $status = intval($post['mit_quick_ticket_status'] ?? 0);
    if ($status && !isset(ticketPickableStatuses()[$status])) {
        $status = 0;
    }
    $priority = (string) ($post['mit_quick_ticket_priority'] ?? 'Medium');
    $billable = (string) ($post['mit_quick_ticket_billable'] ?? '');
    $timer = (string) ($post['mit_quick_ticket_timer'] ?? 'make');
    $after = [
        'on' => !empty($post['mit_quick_ticket_on']),
        'board' => $board,
        'assign' => $assign,
        'status' => $status,
        'priority' => in_array($priority, TICKET_PRIORITIES, true) ? $priority : 'Medium',
        'category' => ticketCategoryId($post['mit_quick_ticket_category'] ?? 0),
        'billable' => in_array($billable, ['', '0', '1'], true) ? $billable : '',
        'timer' => isset(MIT_QUICK_TICKET_TIMER[$timer]) ? $timer : 'make',
        'email_client' => !empty($post['mit_quick_ticket_email_client']),
        'primary' => !empty($post['mit_quick_ticket_primary']),
        'open_tickets' => !empty($post['mit_quick_ticket_open_tickets']),
    ];
    $labels = ['on' => 'Quick ticket button', 'board' => 'Quick ticket board', 'assign' => 'Quick ticket assigned to', 'status' => 'Quick ticket status',
        'priority' => 'Quick ticket priority', 'category' => 'Quick ticket category', 'billable' => 'Quick ticket billable', 'timer' => 'Quick ticket timer',
        'email_client' => 'Quick ticket email to the contact', 'primary' => 'Quick ticket starts on the primary contact', 'open_tickets' => 'Quick ticket shows open tickets'];
    $lines = [];
    foreach ($after as $key => $value) {
        if ($value !== $now[$key]) {
            $lines[] = $labels[$key] . (is_bool($value) ? ($value ? ' on' : ' off') : ' changed');
        }
        mitSetSetting("quick_ticket_$key", is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }
    return $lines;
}
