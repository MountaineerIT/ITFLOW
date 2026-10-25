<?php

/*
 * Mountaineer IT - pick who an email reply goes to (agent/ticket.php reply box, agent/post/ticket.php add_ticket_reply).
 * ITFlow emails the contact and every watcher. Here "Email reply" has a "To" line: the contact and the watchers start in it,
 * the lead agent and helpers, the client's other contacts and any typed address can be added; whoever is in it gets
 * the email. The choice is remembered per ticket (mit_ticket_mail_choices, M021) where it differs from that default.
 * Without the database update, or from anywhere that does not post the list (the API, older forms), ITFlow's own
 * ticketMailContactAndWatchers() runs as before.
 */

function mitReplyRecipientsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_mail_choices'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Everyone the reply box offers: email => [name, group (contact | watcher | agent | client | added), default ticked, ticked now].
 * Saved choices for this ticket override the default; addresses typed in before (and ticked) come back as "added".
 */
function mitReplyRecipients($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id, ticket_contact_id, ticket_assigned_to FROM tickets WHERE ticket_id = $ticket_id"));
    if (!$ticket) {
        return [];
    }
    $list = [];
    $add = function ($email, $name, $group, $default) use (&$list) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($list[$email])) {
            return;
        }
        $list[$email] = ['name' => (string) $name, 'group' => $group, 'default' => $default, 'send' => $default];
    };
    $contact_id = intval($ticket['ticket_contact_id']);
    if ($contact_id) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT contact_name, contact_email FROM contacts WHERE contact_id = $contact_id"));
        if ($row) {
            $add($row['contact_email'], $row['contact_name'], 'contact', true);
        }
    }
    $result = mysqli_query($mysqli, "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id ORDER BY watcher_email");
    while ($row = mysqli_fetch_assoc($result)) {
        $add($row['watcher_email'], '', 'watcher', true);
    }
    // ITFlow 26.11: the client's primary / technical contacts who asked (in the portal) for every update on their company's tickets
    foreach (function_exists('ticketColleagueRecipients') ? ticketColleagueRecipients($ticket_id, 'update') : [] as $email => $colleague) {
        $add($email, $colleague['name'], 'colleague', true);
    }
    $lead = intval($ticket['ticket_assigned_to']);
    if ($lead) {
        $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_name, user_email FROM users WHERE user_id = $lead AND user_archived_at IS NULL"));
        if ($row) {
            $add($row['user_email'], $row['user_name'] . ' (assigned)', 'agent', false);
        }
    }
    foreach (function_exists('mitTicketTechs') ? mitTicketTechs($ticket_id) : [] as $helper) {
        $add($helper['user_email'], $helper['user_name'] . ' (helping)', 'agent', false);
    }
    $client_id = intval($ticket['ticket_client_id']);
    if ($client_id) {
        $result = mysqli_query($mysqli, "SELECT contact_name, contact_email FROM contacts WHERE contact_client_id = $client_id
            AND contact_archived_at IS NULL AND contact_email != '' ORDER BY contact_primary DESC, contact_name");
        while ($row = mysqli_fetch_assoc($result)) {
            $add($row['contact_email'], $row['contact_name'], 'client', false);
        }
    }
    if (mitReplyRecipientsReady()) {
        $result = mysqli_query($mysqli, "SELECT email, send FROM mit_ticket_mail_choices WHERE ticket_id = $ticket_id");
        while ($row = mysqli_fetch_assoc($result)) {
            $email = $row['email'];
            if (isset($list[$email])) {
                $list[$email]['send'] = (bool) intval($row['send']);
            } elseif (intval($row['send'])) {
                $add($email, '', 'added', false);
                $list[$email]['send'] = true;
            }
        }
    }
    return $list;
}

/**
 * The "To" line under the reply type picker (shown for "Email reply"): ITFlow's own picker (js/itflow_select.js) as a
 * multi-select with chips - the people it goes to by name, everyone else found by typing (name, email or group), and a
 * typed address added as it is (data-tags).
 */
function mitReplyRecipientsField($ticket_id, $can_email = true) {
    $notify = mitInternalNotifyField($ticket_id);
    if (!$can_email || !mitReplyRecipientsReady()) {
        return $notify === '' ? '' : $notify . '<script src="' . mitAsset('/agent/js/mit_reply_recipients.js') . '"></script>';
    }
    $groups = ['contact' => 'Contact', 'watcher' => 'Watcher', 'colleague' => 'Asked for every update', 'agent' => 'Our team', 'added' => 'Added before', 'client' => 'Contact at the client'];
    $options = '';
    foreach (mitReplyRecipients($ticket_id) as $email => $r) {
        $name = $r['name'];
        $role = '';
        if (preg_match('/^(.*) \((assigned|helping)\)$/', $name, $m)) {
            [$name, $role] = [$m[1], ' - ' . $m[2]];
        }
        $label = $name !== '' ? $name : $email;
        $options .= '<option value="' . escapeHtml($email) . '"' . ($r['send'] ? ' selected' : '')
            . ' data-description="' . escapeHtml($groups[$r['group']] . $role) . '"'
            . ' data-keywords="' . escapeHtml($email) . '">' . escapeHtml($label) . '</option>';
    }
    return $notify . '<div class="input-group mb-3" id="mitSendTo" hidden><input type="hidden" name="mit_send_to_present" value="1">'
        . '<span class="input-group-text" title="Your choice is remembered for this ticket">To</span>'
        . '<select class="form-select select2" name="mit_send_to[]" id="mitSendToSelect" multiple data-tags="true" data-avatar="initials"'
        . ' data-placeholder="Type a name or an email address" aria-label="Send to">' . $options . '</select></div>'
        . '<script src="' . mitAsset('/agent/js/mit_reply_recipients.js') . '"></script>';
}

/**
 * From add_ticket_reply, instead of ticketMailContactAndWatchers() when the box was posted: emails whoever was ticked
 * (the contact gets ITFlow's own email, everyone else the same with ITFlow's collaborator line) and remembers the choice.
 * Returns the number of emails queued.
 */
function mitReplyRecipientsMail($ticket_id, array $post, $subject, $body, array $attachments) {
    global $mysqli, $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name, $session_user_id;
    $ticket_id = intval($ticket_id);
    $list = mitReplyRecipients($ticket_id);
    $clean = function ($emails) {
        $out = [];
        foreach ((array) $emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out[$email] = true;
            }
        }
        return $out;
    };
    $ticked = $clean($post['mit_send_to'] ?? []);
    $listed = array_fill_keys(array_keys($list), true) + $ticked;  // everyone offered, and anyone typed in

    // Remember it where it differs from the default; a typed address counts as not ticked by default
    foreach (array_slice(array_keys($listed), 0, 200) as $email) {
        $default = !empty($list[$email]['default']);
        $send = isset($ticked[$email]);
        $email_sql = escapeSql($email);
        if ($send === $default) {
            mysqli_query($mysqli, "DELETE FROM mit_ticket_mail_choices WHERE ticket_id = $ticket_id AND email = '$email_sql'");
        } else {
            mysqli_query($mysqli, "INSERT INTO mit_ticket_mail_choices SET ticket_id = $ticket_id, email = '$email_sql', send = " . ($send ? 1 : 0) . ", updated_by = " . intval($session_user_id) . "
                ON DUPLICATE KEY UPDATE send = VALUES(send), updated_by = VALUES(updated_by)");
        }
    }

    if (!$ticked) {
        return 0;
    }
    // ITFlow 26.11: its email builder - each one greeted by name, with why they get it (as ticketMailSend), $body its content
    if (is_array($body) && function_exists('emailQueue')) {
        $ref = (string) ($body['reference'] ?? '');
        $company_name = function_exists('billingCompany') ? (string) (billingCompany()['company_name'] ?? 'us') : 'us';
        $why = [
            'contact' => "You get this as the contact on this ticket with $company_name.",
            'watcher' => "You get this because you are watching this ticket - ask $company_name if you would like to stop.",
            'colleague' => "You get this because you asked for every update on your company's tickets - change that under Your account in the client portal.",
            'agent' => 'You get this because you work on this ticket.',
        ];
        $recipients = [];
        foreach (array_slice(array_keys($ticked), 0, 50) as $email) {
            $group = $list[$email]['group'] ?? 'added';
            $name = ($list[$email]['name'] ?? '') !== '' ? preg_replace('/ \((assigned|helping)\)$/', '', $list[$email]['name']) : '';
            $recipients[] = ['email' => $email, 'name' => $name, 'greet' => in_array($group, ['contact', 'colleague', 'client', 'agent'], true) ? $name : '',
                'footnote' => $why[$group] ?? "You get this because you were copied on this ticket with $company_name."];
        }
        $sent = emailQueue($recipients, $subject, $body, ['from' => function_exists('ticketEmailFrom') ? ticketEmailFrom() : null, 'attachments' => $attachments]);
        if ($sent) {
            logTicketHistory($ticket_id, escapeSql("Reply emailed to " . mb_substr(implode(', ', $sent), 0, 400)));
        }
        return count($sent);
    }
    if (empty($config_smtp_provider)) {
        return 0;
    }
    $from_email = escapeSql($config_ticket_from_email);
    $from_name = escapeSql($config_ticket_from_name);
    $copy_body = $body . "<br><br>----------------------------------------<br>YOU ARE A COLLABORATOR ON THIS TICKET";
    $data = [];
    foreach (array_slice(array_keys($ticked), 0, 50) as $email) {
        $is_contact = ($list[$email]['group'] ?? '') === 'contact';
        $name = ($list[$email]['name'] ?? '') !== '' ? preg_replace('/ \((assigned|helping)\)$/', '', $list[$email]['name']) : $email;
        $data[] = ['from' => $from_email, 'from_name' => $from_name, 'recipient' => escapeSql($email), 'recipient_name' => escapeSql($name),
            'subject' => $subject, 'body' => $is_contact ? $body : $copy_body, 'attachments' => $attachments];
    }
    addToMailQueue($data);
    $names = implode(', ', array_keys($ticked));
    logTicketHistory($ticket_id, escapeSql("Reply emailed to " . mb_substr($names, 0, 400)));
    return count($data);
}

/*
 * ---------- Internal notes: notify a tech ----------
 * The Internal note box has a "Notify" line (empty to start): the agents picked get a bell notification and an email
 * with the note and a link to the ticket. A tech who answers the email from their own address lands on the ticket as
 * an internal reply (ITFlow's parser files mail from anyone but the client's contacts as internal).
 */

/** Agents who may be notified about this ticket: active, and able to see its client. user_id => row. */
function mitInternalNotifyAgents($ticket_id) {
    global $mysqli, $session_user_id;
    $ticket = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_client_id, ticket_assigned_to FROM tickets WHERE ticket_id = " . intval($ticket_id)));
    if (!$ticket) {
        return [];
    }
    $roles = [intval($ticket['ticket_assigned_to']) => 'assigned'];
    foreach (function_exists('mitTicketTechs') ? mitTicketTechs($ticket_id) : [] as $helper) {
        $roles[intval($helper['user_id'])] = 'helping';
    }
    $agents = [];
    $rows = mysqli_fetch_all(mysqli_query($mysqli, "SELECT user_id, user_name, user_email, IFNULL(role_is_admin, 0) AS role_is_admin FROM users
        LEFT JOIN user_roles ON role_id = user_role_id
        WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL AND user_id <> " . intval($session_user_id) . " ORDER BY user_name"), MYSQLI_ASSOC);
    // Who can see the client - mitAgentCanSeeClient's rule (admins see all; else the allow / deny lists), in one query for everyone
    $client_id = intval($ticket['ticket_client_id']);
    $access = $client_id ? userClientAccess(array_map(function ($r) {
        return intval($r['user_id']);
    }, array_filter($rows, function ($r) {
        return intval($r['role_is_admin']) !== 1;
    }))) : [];
    foreach ($rows as $row) {
        $user_id = intval($row['user_id']);
        if (mitAgentSupportLevel($user_id) < 1) {
            continue; // their role has no Support access - they could not open the ticket
        }
        if ($client_id && intval($row['role_is_admin']) !== 1) {
            $a = $access[$user_id] ?? ['allow' => [], 'deny' => []];
            if (isset($a['deny'][$client_id]) || ($a['allow'] && !isset($a['allow'][$client_id]))) {
                continue;
            }
        }
        unset($row['role_is_admin']);
        $row['role'] = $roles[$user_id] ?? '';
        $agents[$user_id] = $row;
    }
    // The ticket's own people first
    uasort($agents, function ($a, $b) {
        return [$a['role'] === '', $a['user_name']] <=> [$b['role'] === '', $b['user_name']];
    });
    return $agents;
}

/** The "Notify" line, shown for "Internal note". */
function mitInternalNotifyField($ticket_id) {
    $options = '';
    foreach (mitInternalNotifyAgents($ticket_id) as $user_id => $agent) {
        $options .= '<option value="' . $user_id . '" data-description="' . escapeHtml($agent['role'] !== '' ? ucfirst($agent['role']) : 'Our team') . '"'
            . ' data-keywords="' . escapeHtml($agent['user_email']) . '">' . escapeHtml($agent['user_name']) . '</option>';
    }
    if ($options === '') {
        return '';
    }
    return '<div class="input-group mb-3" id="mitNotify" hidden>'
        . '<span class="input-group-text" title="They get a notification and an email with this note">Notify</span>'
        . '<select class="form-select select2" name="mit_notify[]" id="mitNotifySelect" multiple data-avatar="initials"'
        . ' data-placeholder="Nobody - type a name to ping a tech about this note" aria-label="Notify">' . $options . '</select></div>';
}

/**
 * From add_ticket_reply, after an internal note is saved: tell each agent picked - a bell notification and an email
 * with the note. Returns the names told.
 */
function mitInternalNoteNotify(array $ticket, $reply_id, array $post) {
    global $mysqli, $session_name, $config_smtp_provider, $config_ticket_from_email, $config_ticket_from_name, $config_base_url;
    $ticket_id = intval($ticket['ticket_id']);
    $wanted = array_unique(array_map('intval', (array) ($post['mit_notify'] ?? [])));
    if (!$wanted || !intval($reply_id)) {
        return [];
    }
    $agents = mitInternalNotifyAgents($ticket_id);
    $note = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_reply FROM ticket_replies WHERE ticket_reply_id = " . intval($reply_id)))[0] ?? '');
    $reference = ticketReference($ticket);
    $client_id = intval($ticket['ticket_client_id']);
    $url = '/agent/' . ticketUrl($ticket_id, $client_id);
    $link = 'https://' . $config_base_url . $url;
    $subject = "Internal note for you - [$reference] - " . $ticket['ticket_subject'];
    $body = escapeHtml($session_name) . ' added an internal note on ticket <b>' . escapeHtml($reference) . '</b> - ' . escapeHtml($ticket['ticket_subject'])
        . ' and asked for you.<br><br>--------------------------------<br>' . $note . '<br>--------------------------------<br><br>'
        . '<a href="' . escapeHtml($link) . '">Open the ticket</a><br><br><i style="color: #808080">Only our team can see this note. A reply to this email from your own address is added to the ticket as an internal note.</i>';
    $told = [];
    $mail = [];
    foreach (array_slice($wanted, 0, 25) as $user_id) {
        if (!isset($agents[$user_id])) {
            continue;
        }
        $agent = $agents[$user_id];
        mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Ticket', notification = '" . escapeSql("$session_name asked for you in an internal note on ticket $reference - " . $ticket['ticket_subject']) . "',
            notification_action = '" . escapeSql($url) . "', notification_client_id = $client_id, notification_user_id = $user_id");
        if (!empty($config_smtp_provider) && filter_var($agent['user_email'], FILTER_VALIDATE_EMAIL)) {
            $mail[] = ['from' => escapeSql($config_ticket_from_email), 'from_name' => escapeSql($config_ticket_from_name), 'recipient' => escapeSql($agent['user_email']),
                'recipient_name' => escapeSql($agent['user_name']), 'subject' => escapeSql($subject), 'body' => mitMailBody($body)];
        }
        $told[] = $agent['user_name'];
    }
    if ($mail) {
        addToMailQueue($mail);
    }
    if ($told) {
        logTicketHistory($ticket_id, escapeSql("$session_name notified " . implode(', ', $told) . " about an internal note"));
    }
    return $told;
}

/**
 * A ticket moved to another client (agent/post/ticket.php change_client_ticket, Dispatch's details save): its remembered
 * "To" choices go, and so do watchers who are neither our agents nor contacts of the new client - otherwise the next
 * email reply (with the guest link) could go to the old client's people. Helpers who cannot see the new client are taken
 * off (mitTicketTechRemove - their booking cancelled). Returns how many watchers were taken off.
 */
function mitTicketClientMoved($ticket_id, $client_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $client_id = intval($client_id);
    if (mitReplyRecipientsReady()) {
        mysqli_query($mysqli, "DELETE FROM mit_ticket_mail_choices WHERE ticket_id = $ticket_id");
    }
    if (function_exists('mitProcTicketClientMoved')) {
        mitProcTicketClientMoved($ticket_id, $client_id); // its parts still to order / to receive go with it
    }
    mysqli_query($mysqli, "DELETE FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id
        AND watcher_email NOT IN (SELECT user_email FROM users WHERE user_type = 1 AND user_archived_at IS NULL AND user_email IS NOT NULL)
        AND watcher_email NOT IN (SELECT contact_email FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL AND contact_email != '')");
    $removed = mysqli_affected_rows($mysqli);
    if ($removed > 0) {
        logTicketHistory($ticket_id, "$removed watcher(s) of the old client were taken off with the move");
    }
    // Helpers (functions/mit_ticket_techs.php) who cannot see the new client come off too - their booking is cancelled
    if (function_exists('mitTicketTechs') && function_exists('mitTicketTechRemove')) {
        $techs = mitTicketTechs($ticket_id);
        $ticket = $techs ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id")) : null;
        foreach ($ticket ? $techs : [] as $user_id => $tech) {
            if (!mitAgentCanSeeClient($user_id, $client_id)) {
                mitTicketTechRemove($ticket, $user_id);
            }
        }
    }
    return $removed;
}
