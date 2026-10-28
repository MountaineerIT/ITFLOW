<?php

/*
 * Mountaineer IT - a customizable ticket page layout: which column each box sits in, their order,
 * and which start collapsed. An admin can save a company default; each agent can save their own on
 * top of it. Stored as JSON - the default in mit_settings (ticket_layout), an agent's own in
 * mit_user_preferences (ticket.layout) - and applied in the browser by agent/js/mit_ticket_layout.js,
 * which moves ITFlow's own boxes, so the page's markup stays upstream's.
 *
 * Layout: {"columns": '' | even | side | single | three, "left": [box ids] (the third column),
 * "main": [...], "side": [...], "collapsed": [...], "half": [...] (half width - two sit side by side)}.
 * Box ids are the script's names for the boxes (description, reply, conversation, details, contact,
 * also_open, site, tasks, parts - our Parts box, procurement -, reimburse - our Reimbursements box -, linked, history; ITFlow 26.11 added fill_ins, trips and the ai_triage banner). A box a layout does
 * not mention stays where ITFlow puts it.
 */

/** A layout as posted or stored, made safe - or null when it is not one. */
function mitTicketLayoutClean($layout) {
    if (is_string($layout)) {
        $layout = json_decode($layout, true);
    }
    if (!is_array($layout)) {
        return null;
    }
    $clean = ['columns' => in_array($layout['columns'] ?? '', ['', 'even', 'side', 'single', 'three'], true) ? ($layout['columns'] ?? '') : ''];
    foreach (['left', 'main', 'side', 'collapsed', 'half'] as $key) {
        $ids = [];
        foreach ((array) ($layout[$key] ?? []) as $id) {
            if (is_string($id) && preg_match('/^[a-z_]{1,24}$/', $id) && count($ids) < 30) {
                $ids[$id] = $id;
            }
        }
        $clean[$key] = array_values($ids);
    }
    // A box is in one column only
    $clean['left'] = array_values(array_diff($clean['left'], $clean['main']));
    $clean['side'] = array_values(array_diff($clean['side'], $clean['main'], $clean['left']));
    return $clean['left'] || $clean['main'] || $clean['side'] ? $clean : null;
}

/** The company default layout, or null for ITFlow's own. */
function mitTicketLayoutDefault() {
    return mitTicketLayoutClean((string) mitSetting('ticket_layout', ''));
}

/** A tech's own layout, or null when they use the company default. */
function mitTicketLayoutMine($user_id) {
    return mitTicketLayoutClean((string) mitUserPreference($user_id, 'ticket.layout', ''));
}

/** The ticket page's hook (agent/ticket.php, after the layout): what the script needs, and the script. */
function mitTicketLayoutHtml() {
    global $session_user_id, $session_is_admin, $ticket_id, $ticket;
    $mine = mitTicketLayoutMine($session_user_id);
    $default = mitTicketLayoutDefault();
    $config = [
        'layout' => $mine ?? $default,
        'default' => $default,
        'hasMine' => $mine !== null,
        'isAdmin' => !empty($session_is_admin),
        'csrf' => $_SESSION['csrf_token'] ?? '',
        // The tech ticked "Read" on this description (and it has not changed since) - it starts folded away
        'ticketId' => intval($ticket_id ?? 0),
        'read' => mitTicketIsRead($session_user_id, intval($ticket_id ?? 0), (string) ($ticket['ticket_details'] ?? '')),
    ];
    // A quick ticket's timer - written before ITFlow's timer script reads it (functions/mit_quick_ticket.php)
    $quick_timer = function_exists('mitQuickTicketTimerScript') ? mitQuickTicketTimerScript(intval($ticket_id ?? 0)) : '';
    return $quick_timer . '<script>window.mitTicketLayout = ' . json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
        . '<script src="' . mitAsset('/agent/js/mit_ticket_layout.js') . '"></script>'
        // NinjaOne buttons beside the ticket's assets (functions/mit_ninjaone.php)
        . (function_exists('mitNinjaPageScript') ? mitNinjaPageScript() : '');
}

/**
 * Save or reset a layout (agent/ajax.php?mit_ticket_layout). $post['scope']: 'mine', 'default'
 * (admins - also clears their own, so they see what they saved), 'reset_mine', 'reset_default'.
 * Answers in JSON and stops.
 */
function mitTicketLayoutAjax(array $post) {
    global $mysqli, $session_user_id, $session_is_admin;
    header('Content-Type: application/json');
    $fail = function ($message, $code = 400) {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    };
    $scope = (string) ($post['scope'] ?? '');
    $user_id = intval($session_user_id);
    if (in_array($scope, ['read', 'unread'], true)) {
        if (!mitTicketReadSet($user_id, intval($post['ticket_id'] ?? 0), $scope === 'read')) {
            $fail('That ticket could not be marked.', 403);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if (in_array($scope, ['default', 'reset_default'], true) && empty($session_is_admin)) {
        $fail('Only an admin can change the company layout.', 403);
    }
    $layout = mitTicketLayoutClean($post['layout'] ?? '');
    if (in_array($scope, ['mine', 'default'], true) && !$layout) {
        $fail('That layout could not be read.');
    }
    try {
        if ($scope === 'mine') {
            mitSetUserPreference($user_id, 'ticket.layout', json_encode($layout));
            $message = 'Your ticket layout is saved';
        } elseif ($scope === 'default') {
            mitSetSetting('ticket_layout', json_encode($layout));
            mysqli_query($mysqli, "DELETE FROM mit_user_preferences WHERE user_id = $user_id AND preference = 'ticket.layout'");
            $message = 'Company default layout saved';
        } elseif ($scope === 'reset_mine') {
            mysqli_query($mysqli, "DELETE FROM mit_user_preferences WHERE user_id = $user_id AND preference = 'ticket.layout'");
            $message = 'You are back on the company default layout';
        } elseif ($scope === 'reset_default') {
            mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name = 'ticket_layout'");
            $message = 'Company default is back to ITFlow\'s layout';
        } else {
            $fail('Unknown request.');
        }
    } catch (Throwable $e) {
        $fail('The layout could not be saved - has the database update run?', 500);
    }
    echo json_encode(['ok' => true, 'message' => $message]);
    exit;
}

/*
 * ---------- "Read" on the description ----------
 * A tech ticks Read once they have read a ticket's description, and it then starts folded away for them on that
 * ticket. Kept per tech in mit_user_preferences ticket.read: {"t<ticket id>": a short hash of the description},
 * the newest MIT_TICKET_READ_KEEP - an edited description shows again, as unread.
 */

const MIT_TICKET_READ_KEEP = 500;

function mitTicketReadHash($details) {
    return substr(md5((string) $details), 0, 12);
}

function mitTicketReadMap($user_id) {
    $map = json_decode((string) mitUserPreference($user_id, 'ticket.read', ''), true);
    return is_array($map) ? $map : [];
}

function mitTicketIsRead($user_id, $ticket_id, $details) {
    if (!$ticket_id) {
        return false;
    }
    return (mitTicketReadMap($user_id)['t' . intval($ticket_id)] ?? '') === mitTicketReadHash($details);
}

/** Tick or untick Read for this agent - false when the ticket is not one they can see. */
function mitTicketReadSet($user_id, $ticket_id, $read) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    if (!$ticket_id || lookupUserPermission('module_support') < 1) {
        return false;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_details, ticket_client_id FROM tickets WHERE ticket_id = $ticket_id LIMIT 1"));
    if (!$row || (intval($row['ticket_client_id']) && !hasClientAccess(intval($row['ticket_client_id'])))) {
        return false;
    }
    $map = mitTicketReadMap($user_id);
    unset($map["t$ticket_id"]);
    if ($read) {
        $map["t$ticket_id"] = mitTicketReadHash($row['ticket_details']);
        $map = array_slice($map, -MIT_TICKET_READ_KEEP, null, true);
    }
    mitSetUserPreference($user_id, 'ticket.read', json_encode($map));
    return true;
}
