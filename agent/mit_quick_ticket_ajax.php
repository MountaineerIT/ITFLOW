<?php

/*
 * Mountaineer IT - Quick ticket's requests (functions/mit_quick_ticket.php, agent/js/mit_quick_ticket.js):
 *   GET ?clients=1                              the clients the agent may raise tickets for (<option>s)
 *   GET ?contacts=1&client_id=                  the client's contacts (<option>s) and the one to start on
 *   GET ?open=1&client_id=&contact_id=          the contact's tickets still open (JSON rows)
 * Signed-in agents with Support edit and Quick ticket switched on. Making the ticket itself is ITFlow's own add_ticket (agent/post.php).
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!function_exists('mitQuickTicketCan') || !mitQuickTicketCan()) {
    http_response_code(403);
    echo json_encode(['error' => 'Quick ticket is not available here.']);
    exit;
}
session_write_close();

if (isset($_GET['clients'])) {
    echo json_encode(['html' => mitQuickTicketClientOptions()]);
    exit;
}

if (isset($_GET['contacts'])) {
    [$html, $selected] = mitQuickTicketContactOptions(intval($_GET['client_id'] ?? 0));
    echo json_encode(['html' => $html, 'selected' => $selected]);
    exit;
}

if (isset($_GET['open'])) {
    echo json_encode(['tickets' => mitQuickTicketOpenTickets(intval($_GET['client_id'] ?? 0), intval($_GET['contact_id'] ?? 0))]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Unknown request.']);
