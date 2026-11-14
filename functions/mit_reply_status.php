<?php

/*
 * Mountaineer IT - where a ticket goes when its client replies (the user's request: a "Client responded" status to map replies to).
 *
 * ITFlow's Admin > Ticket Statuses has "When the client replies": back to Open, or (for your own statuses) leave it there. This adds
 * "Move it to <status>" for any active working status (Open, On Hold or one of your own), on every status a client can reply in - New,
 * Open, On Hold, Resolved and your own (not Closed: a closed ticket can't be replied to). Kept in M063 `mit_ticket_status_reply`
 * (ticket_status_id PK FK ticket_statuses CASCADE, reply_status_id FK ticket_statuses CASCADE - a deleted target falls back to ITFlow's
 * answer). ITFlow's own column is left at "back to Open" for such a status, so stock ITFlow (or this table gone) does what ITFlow does.
 *
 * Hooks: one guarded line at the start of ITFlow's ticketStatusAfterClientReply (functions/ticket_status.php - email parser, portal and
 * text replies all go through it); the picker in admin/modals/ticket_status/ticket_status_form.php (mitReplyStatusField, in place of
 * ITFlow's select; it posts ITFlow's client_reply as "1", "0" or "1:<id>", so ITFlow's own save reads 1 / 0 as before); the save in
 * admin/post/ticket_status.php (mitReplyStatusSave); the list's column in admin/ticket_statuses.php (mitReplyStatusListText).
 */

/** Has M063 run? */
function mitReplyStatusReady() {
    static $ready = null;
    if ($ready === null) {
        global $mysqli;
        $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_status_reply'")) > 0;
    }
    return $ready;
}

/** status id => the status a client's reply moves it to. */
function mitReplyStatusMap($fresh = false) {
    global $mysqli;
    static $map = null;
    if ($map === null || $fresh) {
        $map = [];
        if (mitReplyStatusReady()) {
            $result = mysqli_query($mysqli, "SELECT ticket_status_id, reply_status_id FROM mit_ticket_status_reply");
            while ($row = mysqli_fetch_assoc($result)) {
                $map[intval($row['ticket_status_id'])] = intval($row['reply_status_id']);
            }
        }
    }
    return $map;
}

/** Statuses a client's reply may move a ticket to: active, and not New / Resolved / Closed (the system sets those). id => row. */
function mitReplyStatusTargets() {
    $out = [];
    foreach (ticketStatusesAll() as $id => $row) {
        if (intval($row['ticket_status_active']) === 1 && !in_array(intval($id), [TICKET_STATUS_NEW, TICKET_STATUS_RESOLVED, TICKET_STATUS_CLOSED], true)) {
            $out[intval($id)] = $row;
        }
    }
    return $out;
}

/** Can a client reply in this status (everything but Closed)? */
function mitReplyStatusAllowed($status_id) {
    return intval($status_id) > 0 && intval($status_id) !== TICKET_STATUS_CLOSED;
}

/** The hook: the status this ticket's status sends a client's reply to, or 0 = ITFlow's own answer. */
function mitReplyStatusFor($current_status_id) {
    $current_status_id = intval($current_status_id);
    if (!mitReplyStatusAllowed($current_status_id)) {
        return 0;
    }
    $to = mitReplyStatusMap()[$current_status_id] ?? 0;
    return $to && isset(mitReplyStatusTargets()[$to]) ? $to : 0;
}

/** The picker on the status window, in place of ITFlow's. '' = leave ITFlow's (Closed, or before M063). */
function mitReplyStatusField($s_id, $s_reply) {
    $s_id = intval($s_id);
    if (!mitReplyStatusReady() || $s_id === TICKET_STATUS_CLOSED) {
        return '';
    }
    $builtin = ticketStatusIsBuiltIn($s_id);
    $current = $s_id ? (mitReplyStatusMap()[$s_id] ?? 0) : 0;
    $options = [];
    $options['1'] = [TICKET_STATUS_NEW => 'Open it (ITFlow\'s usual)', TICKET_STATUS_OPEN => 'It stays Open', TICKET_STATUS_RESOLVED => 'Reopen it - back to Open'][$s_id] ?? 'Move it back to Open';
    if (!$builtin) {
        $options['0'] = 'Leave it in this status';
    }
    foreach (mitReplyStatusTargets() as $id => $row) {
        if ($id === TICKET_STATUS_OPEN || $id === $s_id) {
            continue; // Open is the first choice; moving to itself is "leave it"
        }
        $options['1:' . $id] = 'Move it to ' . $row['ticket_status_name'];
    }
    $selected = $current && isset($options['1:' . $current]) ? '1:' . $current : (string) ($builtin ? 1 : $s_reply);
    $html = '<div class="mb-1" data-mit-reply-status><input type="hidden" name="mit_reply_present" value="1">'
        . '<label class="form-label">When the client replies</label><div class="input-group">'
        . '<span class="input-group-text"><i class="fa fa-fw fa-reply"></i></span><select class="form-select" name="client_reply">';
    foreach ($options as $value => $label) {
        $html .= '<option value="' . escapeHtml($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . escapeHtml($label) . '</option>';
    }
    return $html . '</select></div><small class="text-secondary">By email, in the portal or by text. Pick a status such as "Client responded" to see at a glance which '
        . 'tickets have a new answer from the client; back to Open suits a status that waits on the client; leave it for one like Scheduled, where a "thanks, '
        . 'see you then" shouldn\'t put it back in the queue. Whoever it is assigned to is told either way.</small></div>';
}

/** Saves the picker (only when it was on the form). Returns what changed, '' for nothing. */
function mitReplyStatusSave($status_id, array $post) {
    global $mysqli;
    $status_id = intval($status_id);
    if (!$status_id || empty($post['mit_reply_present']) || !mitReplyStatusReady() || !mitReplyStatusAllowed($status_id)) {
        return '';
    }
    $before = mitReplyStatusMap(true)[$status_id] ?? 0;
    $to = preg_match('/^1:(\d+)$/', (string) ($post['client_reply'] ?? ''), $m) ? intval($m[1]) : 0;
    if ($to && ($to === $status_id || !isset(mitReplyStatusTargets()[$to]))) {
        $to = 0;
    }
    if ($to === $before) {
        return '';
    }
    if ($to) {
        mysqli_query($mysqli, "INSERT INTO mit_ticket_status_reply SET ticket_status_id = $status_id, reply_status_id = $to ON DUPLICATE KEY UPDATE reply_status_id = $to");
    } else {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_status_reply WHERE ticket_status_id = $status_id");
    }
    mitReplyStatusMap(true);
    $statuses = ticketStatusesAll();
    $name = (string) ($statuses[$status_id]['ticket_status_name'] ?? $status_id);
    $what = $to ? 'a client\'s reply now moves the ticket to ' . ($statuses[$to]['ticket_status_name'] ?? $to) : 'a client\'s reply no longer moves the ticket to another status of ours';
    logAudit('Ticket Status', 'Edit', escapeSql(stripslashes((string) ($GLOBALS['session_name'] ?? '')) . " changed ticket status $name: $what"), 0, $status_id);
    return $what;
}

/** The list's "When the client replies" cell for a status moved elsewhere, or '' (ITFlow's own text). */
function mitReplyStatusListText($status_id) {
    $to = mitReplyStatusFor($status_id);
    if (!$to) {
        return '';
    }
    $row = ticketStatusesAll()[$to] ?? null;
    return $row ? '<span class="text-body-secondary" data-mit-reply-to="' . $to . '"><i class="fas fa-fw fa-share me-1"></i>To ' . ticketStatusBadgeHtml($row['ticket_status_name'], $row['ticket_status_color'], 'list') . '</span>' : '';
}
