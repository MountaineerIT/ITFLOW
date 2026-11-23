<?php

/*
 * Mountaineer IT - the requests behind our additions on ITFlow's Dispatch (functions/mit_dispatch.php, agent/js/mit_dispatch.js), JSON:
 *   GET  ?details=TICKET_ID                    the ticket's title, client, contact, assets, priority, status, category, SLA and board + choices
 *   GET  ?contacts=CLIENT_ID                   a client's contacts
 *   GET  ?assets=CLIENT_ID&contact=CONTACT_ID  a client's assets (the contact's first)
 *   POST board_state=1 board board_techs       the board picker's choice for this agent
 * ITFlow's dispatchCan() for all (it carries our Dispatch permission); the details are saved with ITFlow's dispatch_book (mitDispatchBeforeBook).
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

header('Content-Type: application/json');

$mit_fail = function ($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
};

if (!function_exists('dispatchCan') || !dispatchCan()) {
    $mit_fail('The Dispatch board needs the Dispatch permission (Admin > Roles).', 403);
}

if (isset($_GET['details'])) {
    $details = mitDispatchDetails($_GET['details']);
    if (!$details) {
        $mit_fail('That ticket no longer exists.', 404);
    }
    echo json_encode($details);
    exit;
}

if (isset($_GET['contacts'])) {
    echo json_encode(mitDispatchContacts($_GET['contacts']));
    exit;
}

if (isset($_GET['assets'])) {
    echo json_encode(mitDispatchAssets($_GET['assets'], intval($_GET['contact'] ?? 0)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['board_state'])) {
    validateCSRFToken();
    mitDispatchBoardStateSave($_POST);
    echo json_encode(['ok' => true]);
    exit;
}

$mit_fail('Unknown request.');
