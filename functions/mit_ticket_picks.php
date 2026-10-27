<?php

/*
 * Mountaineer IT - the ticket page's Board, SLA and Category in the Details box change in place, with ITFlow's own quick
 * picker (js/quick_pick.js, agent/ajax.php quick_pick_options / quick_pick_set) instead of opening a window - like Status,
 * Priority and Assigned to. Required from functions/mit_boards.php.
 *   mit_board     moves the ticket to another board (functions/mit_boards.php mitBoardMove - members told, round-robin)
 *   mit_sla       what ITFlow's SLA window does (agent/post/ticket.php edit_ticket_sla): pin the plan, work the targets out
 *   mit_category  what ITFlow's bulk category change does (agent/post/ticket.php bulk_edit_ticket_category) for one ticket
 * Same rights as the other pickers: Support edit and the ticket's client; an open ticket. Escalating (with a reason) stays
 * in the ⋮ menu. A change that shows elsewhere on the page (the SLA clock, a new agent) reloads it (`reload`).
 */

const MIT_TICKET_PICK_FIELDS = ['mit_board', 'mit_sla', 'mit_category'];

/** The picker button - the same markup as ITFlow's quickPick() 'detail' style. */
function mitTicketPickButton($field, $ticket_id, $current, $inner, $title) {
    return '<button type="button" class="itflow-quick-pick itflow-quick-pick-detail ticket-prop-edit" data-quick-pick="' . escapeHtml($field) . '"'
        . ' data-quick-id="' . intval($ticket_id) . '" data-quick-current="' . escapeHtml((string) $current) . '" data-quick-style="detail"'
        . ' data-csrf="' . escapeHtml($_SESSION['csrf_token'] ?? '') . '" title="' . escapeHtml($title) . '" aria-haspopup="listbox" aria-expanded="false">'
        . '<span class="itflow-quick-pick-value">' . $inner . '</span><i class="fas fa-chevron-down ticket-prop-edit-icon" aria-hidden="true"></i></button>';
}

/** How each field reads in the Details box. */
function mitTicketPickHtml($field, $ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    if ($field === 'mit_board') {
        return mitBoardBadge(mitBoardOf($ticket_id));
    }
    if ($field === 'mit_sla') {
        $name = mysqli_fetch_row(mysqli_query($mysqli, "SELECT sla_name FROM tickets JOIN slas ON sla_id = ticket_sla_id WHERE ticket_id = $ticket_id"))[0] ?? null;
        return $name === null ? "<span class='text-muted'>None</span>" : escapeHtml($name);
    }
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT category_name, category_color, category_icon FROM tickets JOIN categories ON category_id = ticket_category WHERE ticket_id = $ticket_id"));
    if (!$row) {
        return "<span class='text-muted'>None</span>";
    }
    // Drawn as agent/ticket.php does: the category's mark, then its name
    $mark = function_exists('categoryMarkHtml') ? categoryMarkHtml($row[1] ?? '', $row[2] ?? '', '<i class="fas fa-circle itflow-ticket-dot"%s aria-hidden="true"></i>') : '';
    return $mark . escapeHtml($row[0]);
}

/** agent/ajax.php quick_pick_options (Support edit already checked): the choices, or null when the field is not ours. */
function mitTicketPickOptions($field) {
    global $mysqli;
    if (!in_array($field, MIT_TICKET_PICK_FIELDS, true)) {
        return null;
    }
    $options = [];
    if ($field === 'mit_board') {
        foreach (mitBoards() as $id => $board) {
            $options[] = ['value' => (string) $id, 'label' => $board['board_name'], 'color' => $board['board_color'],
                'description' => (string) $board['board_description']];
        }
        return $options;
    }
    if ($field === 'mit_sla') {
        $options[] = ['value' => '0', 'label' => 'None', 'icon' => 'fas fa-minus', 'none' => true, 'description' => 'No response or resolution targets'];
        $result = mysqli_query($mysqli, "SELECT sla_id, sla_name FROM slas WHERE sla_archived_at IS NULL ORDER BY sla_name");
        while ($row = mysqli_fetch_assoc($result)) {
            $options[] = ['value' => (string) $row['sla_id'], 'label' => $row['sla_name'], 'icon' => 'fas fa-stopwatch'];
        }
        return $options;
    }
    $options[] = ['value' => '0', 'label' => 'None', 'icon' => 'fas fa-minus', 'none' => true];
    $result = mysqli_query($mysqli, "SELECT category_id, category_name, category_color FROM categories WHERE category_type = 'Ticket' AND category_archived_at IS NULL ORDER BY category_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $color = preg_match('/^#[0-9a-f]{3,8}$/i', (string) $row['category_color']) ? $row['category_color'] : '';
        $options[] = ['value' => (string) $row['category_id'], 'label' => $row['category_name']] + ($color ? ['color' => $color] : ['icon' => 'fas fa-tag']);
    }
    return $options;
}

/**
 * agent/ajax.php quick_pick_set: change it on each ticket - [http code, response] - or null when the field is not ours.
 * The response is what js/quick_pick.js redraws from (records id => current + html), plus `reload` when more of the page
 * changed.
 */
function mitTicketPickSet($field, $value, array $ticket_ids) {
    global $mysqli, $session_name;
    if (!in_array($field, MIT_TICKET_PICK_FIELDS, true)) {
        return null;
    }
    $rows = [];
    foreach ($ticket_ids as $ticket_id) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_prefix, ticket_number, ticket_client_id, ticket_sla_id, ticket_category,
            ticket_assigned_to, ticket_project_id, ticket_resolved_at, ticket_closed_at FROM tickets WHERE ticket_id = " . intval($ticket_id)));
        if (!$row || lookupUserPermission('module_support') < 2 || (intval($row['ticket_client_id']) && !hasClientAccess($row['ticket_client_id']))
            || !empty($row['ticket_closed_at']) || ($field === 'mit_board' && !empty($row['ticket_resolved_at']))) {
            return [403, ['error' => 'You cannot change that on these tickets.']];
        }
        $rows[intval($row['ticket_id'])] = $row;
    }
    if (!$rows) {
        return [403, ['error' => 'You cannot change that on these tickets.']];
    }

    $value = intval($value);
    $message = '';
    $reload = false;
    $records = [];
    foreach ($rows as $ticket_id => $row) {
        $reference = escapeSql(ticketReference($row));
        $client_id = intval($row['ticket_client_id']);
        if ($field === 'mit_board') {
            [$ok, $message] = mitBoardMove($ticket_id, $value);
            if (!$ok) {
                return [422, ['error' => strip_tags($message)]];
            }
            // Round-robin may have given it to someone - the Assigned to line shows that
            $now = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id"))[0] ?? 0);
            $reload = $reload || $now !== intval($row['ticket_assigned_to']);
        } elseif ($field === 'mit_sla') {
            // A project ticket has no SLA (functions/mit_projects.php) - say so rather than "set" one that does not stick
            if ($value && intval($row['ticket_project_id'] ?? 0) && function_exists('mitProjectTicketsApart') && mitProjectTicketsApart()) {
                return [422, ['error' => 'This ticket is in a project - project tickets have no SLA. Take it out of the project first.']];
            }
            $sla_name = 'None';
            if ($value) {
                $sla = mysqli_fetch_row(mysqli_query($mysqli, "SELECT sla_name FROM slas WHERE sla_id = $value AND (sla_archived_at IS NULL OR sla_id = " . intval($row['ticket_sla_id']) . ")"));
                if (!$sla) {
                    return [422, ['error' => 'That SLA plan is not available.']];
                }
                $sla_name = $sla[0];
            }
            $old = intval($row['ticket_sla_id']) ? (string) getFieldById('slas', $row['ticket_sla_id'], 'sla_name') : 'None';
            applyTicketSla($ticket_id, $value);
            logTicketHistory($ticket_id, "$session_name changed SLA from " . escapeSql($old) . " to " . escapeSql($sla_name));
            logAudit("Ticket", "Edit", "$session_name changed SLA from " . escapeSql($old) . " to " . escapeSql($sla_name) . " for ticket $reference", $client_id, $ticket_id);
            triggerCustomAction('ticket_update', $ticket_id);
            $message = "SLA set to $sla_name";
            $reload = true; // the clock and targets show with it
        } else {
            $category_id = ticketCategoryId($value);
            $name = $category_id ? (string) getFieldById('categories', $category_id, 'category_name') : 'Not categorized';
            $old = intval($row['ticket_category']) ? (string) getFieldById('categories', intval($row['ticket_category']), 'category_name') : 'Not categorized';
            if ($category_id !== intval($row['ticket_category'])) {
                mysqli_query($mysqli, "UPDATE tickets SET ticket_category = $category_id WHERE ticket_id = $ticket_id");
                logTicketHistory($ticket_id, "$session_name changed the category from " . escapeSql($old) . " to " . escapeSql($name));
                logAudit("Ticket", "Edit", "$session_name updated the category on ticket $reference from " . escapeSql($old) . " to " . escapeSql($name), $client_id, $ticket_id);
                triggerCustomAction('ticket_update', $ticket_id);
            }
            $message = "Category set to $name";
        }
        $html = mitTicketPickHtml($field, $ticket_id);
        $current = $field === 'mit_board' ? mitBoardOf($ticket_id) : ($field === 'mit_sla'
            ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_sla_id FROM tickets WHERE ticket_id = $ticket_id"))[0] ?? 0)
            : intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_category FROM tickets WHERE ticket_id = $ticket_id"))[0] ?? 0));
        $records[$ticket_id] = ['current' => (string) $current, 'html' => ['list' => $html, 'detail' => $html]];
    }
    return [200, ['records' => $records, 'message' => count($rows) === 1 ? strip_tags($message) : 'Updated ' . count($rows) . ' tickets', 'reload' => $reload]];
}
