<?php

/*
 * Mountaineer IT - our additions on ITFlow's Dispatch (26.12: functions/dispatch.php, agent/dispatch.php, agent/js/dispatch.js - built from
 * ours; M067 moved the switch and each agent's view). What stays ours, as hooks on ITFlow's:
 *  - the Dispatch permission (M035, module_dispatch in Admin > Roles): View sees the board, Edit works it - one guarded condition each in
 *    dispatchCan / dispatchCanEdit (mitDispatchLevel). ITFlow ties both to Support.
 *  - service boards: a picker (All / My boards - the default for an agent on one - / each board) filtering the queue, Workload and This week
 *    (mitDispatchBoardWhere - one line each in dispatchQueue, dispatchWorkload, dispatchWeekStats, reading ?board=), "Only the board's
 *    members" for the agent columns, a board tag on queue cards, the menu count of unassigned tickets on the agent's boards;
 *  - the Schedule window puts the ticket right as it is booked - title, client, contact, assets, priority, status, category, SLA and board
 *    (mitDispatchDetails / mitDispatchSaveDetails, through agent/mit_dispatch_ajax.php) - and who is emailed (the booking-email ticks of
 *    functions/mit_notifications.php, replacing ITFlow's one "tell the client" tick). One guarded line before ITFlow's dispatchBook in
 *    agent/ajax.php runs mitDispatchBeforeBook: the booking is checked first (a refused one changes nothing), then the details are saved;
 *  - Outlook busy times on each agent's day (one line in dispatchItems);
 *  - an agent away from the company's time zone sees the working hours moved onto their clock (one line in dispatchBusinessHours).
 * The page's extras: one require in agent/dispatch.php (agent/includes/mit_dispatch_extras.php - the board picker, our fields in the Schedule
 * window and agent/js/mit_dispatch.js); dispatch.js has two lines - every request passes through window.itflowDispatchExtra, the agent
 * columns through window.itflowDispatchAgents - and tells us when the Schedule window opens (event "dispatch:schedule").
 */

/**
 * The signed-in agent's Dispatch level - the role's Dispatch permission (M035; Admin > Roles): 1 sees the board, 2 works it.
 * Before M035 has run it is Support edit, as it was. 0 when ticketing or our Dispatch switch is off.
 */
function mitDispatchLevel() {
    global $mysqli;
    static $has_row = null;
    if (function_exists('moduleOn') && !moduleOn('dispatch')) {
        return 0;
    }
    if ($has_row === null) {
        $has_row = mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM modules WHERE module_name = 'module_dispatch'")) === 1;
    }
    if (!$has_row) {
        return calendarCanScheduleTickets() ? 2 : 0;
    }
    return intval(lookupUserPermission('module_dispatch'));
}

/** A Dispatch board choice made safe: all, mine (only for an agent on a board), or a board's id; null = the default. */
function mitDispatchBoardChoice($choice) {
    global $session_user_id;
    if (!function_exists('mitBoards') || !mitBoards()) {
        return 'all';
    }
    $mine = (bool) array_intersect_key(mitBoardsOfUser($session_user_id), mitBoardsVisible());
    if ($choice === null || $choice === '') {
        return $mine ? 'mine' : 'all';
    }
    $choice = (string) $choice;
    if ($choice === 'mine') {
        return $mine ? 'mine' : 'all';
    }
    return isset(mitBoardsVisible()[intval($choice)]) ? (string) intval($choice) : 'all';
}

/** " AND <the ticket is on the chosen board(s)>" for Dispatch's lists - '' for all boards. */
function mitDispatchBoardWhere($choice, $alias = 'tickets') {
    global $session_user_id;
    return function_exists('mitBoardWhere') ? mitBoardWhere(mitDispatchBoardChoice($choice), $session_user_id, $alias) : '';
}

/** The boards for Dispatch's picker and agent columns: [{id, name, color, members: [user ids]}]. */
function mitDispatchBoards() {
    global $mysqli;
    if (!function_exists('mitBoards') || !mitBoards()) {
        return [];
    }
    $members = [];
    $result = mysqli_query($mysqli, "SELECT board_id, user_id FROM mit_board_members");
    while ($row = mysqli_fetch_row($result)) {
        $members[intval($row[0])][] = intval($row[1]);
    }
    $list = [];
    foreach (mitBoardsVisible() as $id => $board) {
        $list[] = ['id' => $id, 'name' => $board['board_name'], 'color' => $board['board_color'], 'members' => $members[$id] ?? []];
    }
    return $list;
}

/**
 * Would this booking be refused? Checked before the Schedule dialog's details are saved (agent/mit_dispatch_ajax.php book), so a
 * refused booking leaves the ticket as it was - client included ($client_id: the client the dialog moves it to, 0 = its own).
 * Returns '' or the reason.
 */
function mitDispatchBookProblem($ticket_id, $tech_id, $start, $client_id = 0) {
    $row = ticketRow($ticket_id);
    if (!$row || !empty($row['ticket_archived_at'])) {
        return 'That ticket no longer exists.';
    }
    if (!ticketIsOpen($row)) {
        return 'Ticket ' . ticketReference($row) . ' is resolved or closed - reopen it to schedule work.';
    }
    if (ticketScheduleValue($start) === '') {
        return 'Pick the date and time.';
    }
    $tech_id = intval($tech_id);
    if (!isset(dispatchAgents()[$tech_id])) {
        return 'Pick an agent.';
    }
    $client_id = intval($client_id) ?: intval($row['ticket_client_id']);
    if ($client_id && !mitAgentCanSeeClient($tech_id, $client_id)) {
        return dispatchAgents()[$tech_id]['name'] . ' does not have access to this client.';
    }
    return '';
}

/** A client's contacts for the dialog's Contact list: [{id, name}], primary first. [] for no client or one out of reach. */
function mitDispatchContacts($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    if (!$client_id || !hasClientAccess($client_id)) {
        return [];
    }
    $contacts = [];
    $result = mysqli_query($mysqli, "SELECT contact_id, contact_name, contact_title FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL
        ORDER BY contact_primary DESC, contact_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $contacts[] = ['id' => intval($row['contact_id']), 'name' => $row['contact_name'] . ($row['contact_title'] ? ' - ' . $row['contact_title'] : '')];
    }
    return $contacts;
}


/** The email ticks kept for a booked ticket ({tech, contact, ..., extra: []}), or null - not booked, or nothing kept (functions/mit_notifications.php). */
function mitDispatchKeptNotify(array $row) {
    global $mysqli;
    if (empty($row['ticket_schedule']) || !function_exists('mitScheduleKeptReady') || !mitScheduleKeptReady()) {
        return null;
    }
    $kept = json_decode((string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT choice FROM mit_ticket_schedule_notify WHERE ticket_id = " . intval($row['ticket_id'])))[0] ?? ''), true);
    if (!is_array($kept)) {
        return null;
    }
    $kept['extra'] = array_values(array_filter((array) ($kept['extra'] ?? []), 'is_string'));
    return $kept;
}

/**
 * What the Schedule dialog shows of a ticket, so the client, contact, priority, status, category and SLA can be put right as it is
 * scheduled: the current values and the choices for each (clients this agent can see, the client's contacts, ticket
 * categories and SLAs still in use - plus the ticket's own if it was archived). Null when the ticket is out of reach.
 */
function mitDispatchDetails($ticket_id) {
    global $mysqli;
    $row = ticketRow($ticket_id);
    if (!$row || (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id']))) {
        return null;
    }
    $category_id = intval($row['ticket_category']);
    $sla_id = intval($row['ticket_sla_id']);
    $pick = function ($sql) use ($mysqli) {
        $list = [];
        $result = mysqli_query($mysqli, $sql);
        while ($r = mysqli_fetch_row($result)) {
            $list[] = ['id' => intval($r[0]), 'name' => $r[1]];
        }
        return $list;
    };
    return [
        'ticket' => ['subject' => $row['ticket_subject'], 'client_id' => intval($row['ticket_client_id']), 'contact_id' => intval($row['ticket_contact_id']), 'priority' => $row['ticket_priority'],
            'status_id' => intval($row['ticket_status']), 'category_id' => $category_id, 'sla_id' => $sla_id],
        // The statuses an agent may set by hand (ITFlow's own list), plus the one it has now when that is not one of them (New)
        'statuses' => array_values(array_map(function ($status) {
            return ['id' => intval($status['ticket_status_id']), 'name' => $status['ticket_status_name']];
        }, ticketPickableStatuses() + [intval($row['ticket_status']) => ['ticket_status_id' => $row['ticket_status'], 'ticket_status_name' => $row['ticket_status_name']]])),
        'clients' => $pick("SELECT client_id, client_name FROM clients WHERE (client_archived_at IS NULL OR client_id = " . intval($row['ticket_client_id']) . ")"
            . clientScopeSql('client_id') . " ORDER BY client_name"),
        'contacts' => mitDispatchContacts($row['ticket_client_id']),
        'priorities' => TICKET_PRIORITIES,
        'categories' => $pick("SELECT category_id, category_name FROM categories WHERE category_type = 'Ticket' AND (category_archived_at IS NULL OR category_id = $category_id) ORDER BY category_name"),
        'slas' => $pick("SELECT sla_id, sla_name FROM slas WHERE sla_archived_at IS NULL OR sla_id = $sla_id ORDER BY sla_name"),
        'board' => $board_id = (function_exists('mitBoardOf') ? mitBoardOf($row['ticket_id']) : 0),
        // The role's boards, plus the one the ticket is on (it may be one they don't see - it is theirs, or left behind)
        'boards' => array_values(array_map(function ($b) { return ['id' => $b['id'], 'name' => $b['name']]; }, mitDispatchBoards())
            + ($board_id && !in_array($board_id, array_column(mitDispatchBoards(), 'id'), true) && isset(mitBoards()[$board_id])
                ? [-1 => ['id' => $board_id, 'name' => mitBoards()[$board_id]['board_name']]] : [])),
        // ITFlow's ticket assets: the main one (ticket_asset_id) and the others (ticket_assets)
        'asset_id' => intval($row['ticket_asset_id']),
        'other_asset_ids' => $other_assets = mitDispatchTicketOtherAssets($row['ticket_id']),
        'assets' => mitDispatchAssets($row['ticket_client_id'], $row['ticket_contact_id'], array_merge([intval($row['ticket_asset_id'])], $other_assets)),
        // A booked ticket's email ticks as they were chosen (mit_ticket_schedule_notify) - a move starts from them, not the visit's defaults
        'notify_kept' => mitDispatchKeptNotify($row),
    ];
}

/** A ticket's other assets (ITFlow's ticket_assets), as ids. */
function mitDispatchTicketOtherAssets($ticket_id) {
    global $mysqli;
    $ids = [];
    $result = mysqli_query($mysqli, "SELECT asset_id FROM ticket_assets WHERE ticket_id = " . intval($ticket_id) . " ORDER BY asset_id");
    while ($row = mysqli_fetch_row($result)) {
        $ids[] = intval($row[0]);
    }
    return $ids;
}

/**
 * A client's assets for the dialog's Asset pickers: [{id, name}] - the contact's own first, then the rest by name; archived ones only when
 * the ticket already has them ($keep). [] for no client or one out of reach.
 */
function mitDispatchAssets($client_id, $contact_id = 0, array $keep = []) {
    global $mysqli;
    $client_id = intval($client_id);
    if (!$client_id || !hasClientAccess($client_id)) {
        return [];
    }
    $contact_id = intval($contact_id);
    $keep = array_filter(array_map('intval', $keep));
    $result = mysqli_query($mysqli, "SELECT asset_id, asset_name, asset_type, asset_contact_id, asset_archived_at FROM assets WHERE asset_client_id = $client_id
        AND (asset_archived_at IS NULL" . ($keep ? " OR asset_id IN (" . implode(',', $keep) . ")" : '') . ")
        ORDER BY (asset_contact_id = $contact_id AND $contact_id > 0) DESC, asset_name LIMIT 2000");
    $assets = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $name = $row['asset_name'] . ($row['asset_type'] ? ' - ' . $row['asset_type'] : '');
        if ($contact_id && intval($row['asset_contact_id']) === $contact_id) {
            $name .= ' (contact\'s)';
        }
        if ($row['asset_archived_at']) {
            $name .= ' (archived)';
        }
        $assets[] = ['id' => intval($row['asset_id']), 'name' => $name];
    }
    return $assets;
}

/**
 * Save the details posted with a Dispatch booking (detail_subject, detail_client_id, detail_contact_id, detail_priority, detail_category_id,
 * detail_status, detail_sla - "auto", or an SLA id, 0 for none), only what changed, the way ITFlow's own forms do it: a new client as
 * "Change client" does (agent/post/ticket.php change_client_ticket - its public replies become internal, the old
 * client's asset, site and project links go); the contact only one of the client's; the priority through
 * ticketChangePriority(); the status through ticketChangeStatus() (only the ones an agent may set by hand); the SLA chosen pins it (edit_ticket_sla), otherwise a new client or priority works it out again.
 * Every change goes in the ticket's history and the audit log. Returns [ok, message or ''].
 */
function mitDispatchSaveDetails($ticket_id, array $post) {
    global $mysqli, $session_name;
    if (!isset($post['detail_client_id'])) {
        return [true, ''];  // the dialog did not show them
    }
    $row = ticketRow($ticket_id);
    if (!$row || (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id']))) {
        return [false, 'That ticket no longer exists.'];
    }
    if (!empty($row['ticket_closed_at'])) {
        return [false, 'Ticket ' . ticketReference($row) . ' is closed.'];
    }
    $ticket_id = intval($row['ticket_id']);
    $reference = escapeSql(ticketReference($row));
    $old_client_id = intval($row['ticket_client_id']);
    $client_id = intval($post['detail_client_id']);
    $changed = [];

    // What can refuse is checked before anything is saved, so a refusal leaves the ticket as it was: the subject, the
    // client and the SLA plan
    if (isset($post['detail_subject']) && trim((string) $post['detail_subject']) === '') {
        return [false, 'A ticket needs a subject.'];
    }
    $client_name = '';
    if ($client_id && $client_id !== $old_client_id) {
        $client_name = (string) getFieldById('clients', $client_id, 'client_name');
        if ($client_name === '' || !hasClientAccess($client_id)) {
            return [false, 'You do not have access to that client.'];
        }
    }
    $old_sla_id = intval($row['ticket_sla_id']);
    $sla = (string) ($post['detail_sla'] ?? $old_sla_id);
    $sla_name = 'None';
    if ($sla !== 'auto' && intval($sla) && intval($sla) !== $old_sla_id) {
        // A project ticket has no SLA (functions/mit_projects.php) - refused, as the ticket's SLA picker does, rather than "set" one that does not stick
        if (intval($row['ticket_project_id'] ?? 0) && function_exists('mitProjectTicketsApart') && mitProjectTicketsApart()) {
            return [false, 'This ticket is in a project - project tickets have no SLA. Take it out of the project first.'];
        }
        $sla_name = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT sla_name FROM slas WHERE sla_id = " . intval($sla) . " AND sla_archived_at IS NULL"))[0] ?? '');
        if ($sla_name === '') {
            return [false, 'That SLA plan is not available.'];
        }
    }

    // Subject - as ITFlow's Edit ticket keeps it (500 characters, no tags)
    if (isset($post['detail_subject'])) {
        $subject = escapeSql(mb_substr(trim((string) $post['detail_subject']), 0, 500));
        if ($subject !== escapeSql($row['ticket_subject'])) {
            mysqli_query($mysqli, "UPDATE tickets SET ticket_subject = '$subject' WHERE ticket_id = $ticket_id");
            logTicketHistory($ticket_id, "$session_name changed the subject to $subject");
            $changed[] = 'subject';
        }
    }

    // Client
    if ($client_id && $client_id !== $old_client_id) {
        $vendor_id = ticketClientOwnedId('vendor', $row['ticket_vendor_id'], 0) ?: ticketClientOwnedId('vendor', $row['ticket_vendor_id'], $client_id);
        $project_id = ticketClientOwnedId('project', $row['ticket_project_id'], $client_id);
        mitTsKeepPublicNotes($ticket_id); // their time stays on timesheets
        mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_type = 'Internal' WHERE ticket_reply_ticket_id = $ticket_id AND ticket_reply_type = 'Public'");
        mysqli_query($mysqli, "UPDATE tickets SET ticket_client_id = $client_id, ticket_contact_id = 0, ticket_asset_id = 0, ticket_location_id = 0,
            ticket_vendor_id = $vendor_id, ticket_project_id = $project_id WHERE ticket_id = $ticket_id LIMIT 1");
        mysqli_query($mysqli, "DELETE FROM ticket_assets WHERE ticket_id = $ticket_id");
        mitTicketClientMoved($ticket_id, $client_id);
        // As ITFlow's change_client_ticket: the driving for it goes with it - not to the old client's site
        mysqli_query($mysqli, "UPDATE trips SET trip_client_id = $client_id, trip_location_id = 0 WHERE trip_ticket_id = $ticket_id");
        $old_name = $old_client_id ? (string) $row['client_name'] : 'no client';
        logTicketHistory($ticket_id, "$session_name moved the ticket from " . escapeSql($old_name) . " to " . escapeSql($client_name));
        logAudit("Ticket", "Change", "$session_name moved ticket $reference from " . escapeSql($old_name) . " to " . escapeSql($client_name), $client_id, $ticket_id);
        $changed[] = "client $client_name";
        $row['ticket_contact_id'] = 0; // contact_name kept, so the contact's history line names who it was
    } else {
        $client_id = $old_client_id;
    }

    // Contact - one of the client's, or none
    $contact_id = ticketClientOwnedId('contact', $post['detail_contact_id'] ?? 0, $client_id);
    if ($contact_id !== intval($row['ticket_contact_id'])) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_contact_id = $contact_id WHERE ticket_id = $ticket_id");
        $contact_name = $contact_id ? (string) getFieldById('contacts', $contact_id, 'contact_name') : 'No one';
        logTicketHistory($ticket_id, "$session_name changed the contact from " . escapeSql($row['contact_name'] ?: 'No one') . " to " . escapeSql($contact_name));
        $changed[] = "contact $contact_name";
    }

    // Assets - as ITFlow's ticket Asset form: the main one and the others, the client's own only (a new client's start empty)
    if (isset($post['detail_assets_present'])) {
        $asset_id = ticketClientOwnedId('asset', $post['detail_asset_id'] ?? 0, $client_id);
        $old_asset_id = $client_id === $old_client_id ? intval($row['ticket_asset_id']) : 0;
        $others = ticketClientOwnedAssetIds(explode(',', (string) ($post['detail_other_assets'] ?? '')), $client_id, $asset_id);
        $old_others = $client_id === $old_client_id ? mitDispatchTicketOtherAssets($ticket_id) : [];
        if ($asset_id !== $old_asset_id) {
            mysqli_query($mysqli, "UPDATE tickets SET ticket_asset_id = $asset_id WHERE ticket_id = $ticket_id");
            $asset_name = $asset_id ? (string) getFieldById('assets', $asset_id, 'asset_name') : '';
            logTicketHistory($ticket_id, $asset_id ? "$session_name set the asset to " . escapeSql($asset_name) : "$session_name removed the asset");
            $changed[] = $asset_id ? "asset $asset_name" : 'asset removed';
        }
        sort($others);
        if ($others !== $old_others) {
            ticketSetOtherAssets($ticket_id, $others);
            $added = array_diff($others, $old_others);
            $removed = array_diff($old_others, $others);
            foreach ([[$added, 'added additional asset'], [$removed, 'removed additional asset']] as [$ids, $verb]) {
                foreach ($ids as $id) {
                    logTicketHistory($ticket_id, "$session_name $verb " . escapeSql((string) getFieldById('assets', $id, 'asset_name')));
                }
            }
            $changed[] = count($others) . ' other asset' . (count($others) === 1 ? '' : 's');
        }
    }

    // Category
    $category_id = ticketCategoryId($post['detail_category_id'] ?? 0);
    if ($category_id !== intval($row['ticket_category'])) {
        mysqli_query($mysqli, "UPDATE tickets SET ticket_category = $category_id WHERE ticket_id = $ticket_id");
        $category_name = $category_id ? (string) getFieldById('categories', $category_id, 'category_name') : 'none';
        logTicketHistory($ticket_id, "$session_name changed the category to " . escapeSql($category_name));
        $changed[] = "category $category_name";
    }

    // Priority (ticketChangePriority logs it and works the SLA out again)
    $priority = (string) ($post['detail_priority'] ?? $row['ticket_priority']);
    $priority_changed = in_array($priority, TICKET_PRIORITIES, true) && $priority !== $row['ticket_priority'];
    if ($priority_changed) {
        ticketChangePriority($ticket_id, $priority);
        $changed[] = "priority $priority";
    }

    // Status - ticketChangeStatus() keeps to the statuses an agent may set, logs it and keeps the SLA clock right
    $status_id = intval($post['detail_status'] ?? $row['ticket_status']);
    if ($status_id !== intval($row['ticket_status']) && isset(ticketPickableStatuses()[$status_id])) {
        [$status_ok] = ticketChangeStatus($ticket_id, $status_id);
        if ($status_ok) {
            $changed[] = 'status ' . ticketPickableStatuses()[$status_id]['ticket_status_name'];
        }
    }

    // SLA: the dialog starts on the ticket's own. "Automatic" works it out from the client's contract (ITFlow 3.5.0) and priority; a different
    // one pins it; left as it was, a new client works it out again (a new priority already did, above)
    if ($sla === 'auto') {
        applyTicketSla($ticket_id);
        $new_sla_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_sla_id FROM tickets WHERE ticket_id = $ticket_id"))[0] ?? 0);
        if ($new_sla_id !== $old_sla_id) {
            $changed[] = 'SLA ' . ($new_sla_id ? (string) getFieldById('slas', $new_sla_id, 'sla_name') : 'None') . ' (automatic)';
        }
    } elseif (intval($sla) !== $old_sla_id) {
        $sla_id = intval($sla);
        applyTicketSla($ticket_id, $sla_id);
        $old_sla_name = $old_sla_id ? (string) getFieldById('slas', $old_sla_id, 'sla_name') : 'None';
        logTicketHistory($ticket_id, "$session_name changed SLA from " . escapeSql($old_sla_name) . " to " . escapeSql($sla_name));
        $changed[] = "SLA $sla_name";
    } elseif ($client_id !== $old_client_id) {
        applyTicketSla($ticket_id);
    }

    // The board - moved as the ticket's Board line does, its members told
    $board_id = intval($post['detail_board'] ?? 0);
    if ($board_id && function_exists('mitBoardMove') && isset(mitBoards()[$board_id]) && $board_id !== mitBoardOf($ticket_id)) {
        mitBoardMove($ticket_id, $board_id);
        $changed[] = 'board ' . mitBoards()[$board_id]['board_name'];
    }

    if ($changed) {
        logAudit("Ticket", "Edit", "$session_name changed ticket $reference from Dispatch: " . escapeSql(implode(', ', $changed)), $client_id, $ticket_id);
        triggerCustomAction('ticket_update', $ticket_id);
    }
    return [true, $changed ? 'Updated ' . implode(', ', $changed) : ''];
}

/** The ?board= of a Dispatch request (all | mine | a board id), made safe. */
function mitDispatchBoardRequest() {
    return mitDispatchBoardChoice(isset($_GET['board']) || isset($_POST['board']) ? (string) ($_GET['board'] ?? $_POST['board']) : null);
}

/**
 * " AND <the ticket is on the board(s) asked for>" - one line each in ITFlow's dispatchQueue, dispatchWorkload and dispatchWeekStats.
 * $quiet: quiet boards (Alerts - mitBoardQuietSql) left out unless one is picked itself - the queues and This week; Workload passes false
 * (it only counts tickets an agent holds, which stay theirs to see).
 */
function mitDispatchBoardSql($alias = 'tickets', $quiet = true) {
    $choice = mitDispatchBoardRequest();
    return mitDispatchBoardWhere($choice, $alias) . ($quiet && function_exists('mitBoardQuietSql') ? mitBoardQuietSql($choice, $alias) : '');
}

/** What this agent last picked in the board picker (mit_user_preferences dispatch.board): board, board_techs. */
function mitDispatchBoardState() {
    global $session_user_id;
    $state = json_decode((string) mitUserPreference($session_user_id, 'dispatch.board', ''), true);
    $state = is_array($state) ? $state : [];
    return ['board' => mitDispatchBoardChoice($state['board'] ?? null), 'board_techs' => !empty($state['board_techs'])];
}

/** Keep the board picker's choice. */
function mitDispatchBoardStateSave(array $post) {
    global $session_user_id;
    mitSetUserPreference($session_user_id, 'dispatch.board', json_encode(['board' => mitDispatchBoardChoice((string) ($post['board'] ?? '')),
        'board_techs' => !empty($post['board_techs'])]));
}

/**
 * agent/ajax.php dispatch_book, before ITFlow's dispatchBook (one line): the booking checked first, then the Schedule window's ticket details
 * saved and its email ticks taken. Returns '' to go on, else why not (nothing was changed).
 */
function mitDispatchBeforeBook(array $post) {
    $ticket_id = intval($post['ticket_id'] ?? 0);
    $why = mitDispatchBookProblem($ticket_id, $post['agent_id'] ?? 0, str_replace('T', ' ', (string) ($post['start'] ?? '')), intval($post['detail_client_id'] ?? 0));
    if ($why !== '') {
        return $why;
    }
    [$ok, $message] = mitDispatchSaveDetails($ticket_id, $post);
    if (!$ok) {
        return $message;
    }
    $GLOBALS['mit_dispatch_details_message'] = $message;
    if (function_exists('mitScheduleNotifyFromPost')) {
        mitScheduleNotifyFromPost($post); // who is emailed for this booking
    }
    return '';
}


/** What booking on Dispatch takes, for ITFlow's View only badge and refusal (agent/dispatch.php, agent/ajax.php - one line each). */
function mitDispatchEditNeeds() {
    return 'Booking and moving tickets takes Dispatch at Edit and Support at Modify in your role (Admin > Roles)';
}

/** ITFlow's dispatchItems (one line): each shown agent's Outlook busy times too. */
function mitDispatchOutlookItems($from, $to, array $agent_ids) {
    if (!function_exists('mitOutlookBusyItems') || !isset(calendarSources()['outlook_busy'])) {
        return [];
    }
    $items = [];
    foreach ($agent_ids as $agent_id) {
        foreach (mitOutlookBusyItems($from, $to, ['user_id' => intval($agent_id), 'client_id' => 0]) as $item) {
            $item['resourceId'] = (string) intval($agent_id);
            $item['extendedProps']['agent'] = intval($agent_id);
            $item['editable'] = false;
            $items[] = $item;
        }
    }
    return $items;
}

/**
 * ITFlow's dispatchBusinessHours (one line): an agent away from the company's time zone (ITFlow's timezoneShifted) sees the working hours
 * moved onto their own clock - as two pieces when they run past midnight there.
 */
function mitDispatchViewerHours($hours) {
    if (!function_exists('timezoneShifted') || !timezoneShifted() || !is_array($hours) || !isset($hours['startTime'], $hours['endTime'], $hours['daysOfWeek'])) {
        return $hours;
    }
    $company = new DateTimeZone(date_default_timezone_get());
    $viewer = new DateTimeZone(timezoneView());
    $now = new DateTime('now');
    $offset = intdiv($viewer->getOffset($now) - $company->getOffset($now), 60);
    if (!$offset) {
        return $hours;
    }
    $minutes = function ($time) {
        return intval(substr($time, 0, 2)) * 60 + intval(substr($time, 3, 2));
    };
    $start = $minutes($hours['startTime']) + $offset;
    $end = $minutes($hours['endTime']) + $offset;
    $day_shift = intdiv($start - ((($start % 1440) + 1440) % 1440), 1440);
    $start -= $day_shift * 1440;
    $end -= $day_shift * 1440;
    $days = array_values(array_map(function ($day) use ($day_shift) {
        return ((intval($day) + $day_shift) % 7 + 7) % 7;
    }, (array) $hours['daysOfWeek']));
    $clock = function ($m) {
        return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    };
    if ($end <= 1440) {
        return [['daysOfWeek' => $days, 'startTime' => $clock($start), 'endTime' => $clock($end)]];
    }
    return [
        ['daysOfWeek' => $days, 'startTime' => $clock($start), 'endTime' => '24:00'],
        ['daysOfWeek' => array_map(function ($day) {
            return ($day + 1) % 7;
        }, $days), 'startTime' => '00:00', 'endTime' => $clock($end - 1440)],
    ];
}

/** Queue rows (ITFlow's dispatchQueue, one line): each ticket's board, for the tag on its card. */
function mitDispatchQueueBoards(array $tickets) {
    if (!function_exists('mitBoardsOfTickets') || !$tickets) {
        return $tickets;
    }
    $boards = mitBoardsOfTickets(array_column($tickets, 'id'));
    foreach ($tickets as &$ticket) {
        $board = $boards[intval($ticket['id'])] ?? null;
        if ($board) {
            $ticket['mit_board'] = ['name' => $board['board_name'], 'color' => $board['board_color']];
        }
    }
    unset($ticket);
    return $tickets;
}

/** The side menu's count beside Dispatch: unassigned open service tickets on the agent's own boards (or all, when on none) - quiet boards left out. Cached a minute. */
function mitDispatchNavCount() {
    global $mysqli, $session_user_id;
    if (!function_exists('mitNavCachedCount') || !dispatchCan()) {
        return 0;
    }
    return mitNavCachedCount('dispatch', function () use ($mysqli, $session_user_id) {
        $choice = mitDispatchBoardChoice(null);
        $board = function_exists('mitBoardWhere') ? mitBoardWhere($choice, $session_user_id, 'tickets') . mitBoardQuietSql($choice, 'tickets') : '';
        $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM tickets WHERE ticket_assigned_to = 0 AND " . TICKET_OPEN_SQL . "
            AND ticket_archived_at IS NULL" . clientScopeSql('ticket_client_id') . (function_exists('projectTicketsServiceSql') ? projectTicketsServiceSql('tickets.ticket_project_id') : '') . $board));
        return intval($row[0] ?? 0);
    }, basename($_SERVER['PHP_SELF'] ?? '') === 'dispatch.php');
}

/** In ITFlow's Schedule window (agent/dispatch.php, one line): where our script puts the ticket's details and the booking-email ticks. */
function mitDispatchScheduleFields() {
    return '<div class="mb-3" data-mit-dispatch-fields></div>';
}
