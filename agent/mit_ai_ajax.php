<?php

/*
 * Mountaineer IT - the Ask AI panel's requests (functions/mit_ai_ask.php, agent/js/mit_ai_helper.js), JSON:
 *   GET  ?list                                         the agent's conversations
 *   GET  ?conversation=<id>                            one of them with its messages
 *   POST ask=1 conversation_id question ticket_id      ask (a new conversation when conversation_id is 0)
 *   POST feedback=1 message_id value (1 | -1 | 0)      thumbs up / down on an answer
 *   POST delete=1 conversation_id                      delete one of the agent's conversations
 *   POST draft=1 conversation_id client_id             a document drafted from it (functions/mit_ai_docs.php) - for the Write up window
 * Every request needs the helper to be on and the agent's Use AI + Support view (mitAiAskCan); POSTs the CSRF token.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');

$reply = function ($code, array $data) {
    http_response_code($code);
    echo json_encode($data);
    exit;
};

if (!function_exists('mitAiAskCan') || !mitAiAskCan()) {
    $reply(403, ['error' => 'The AI helper is not available to you.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken();
    if (isset($_POST['ask'])) {
        // An answer can take a while - let the agent's other pages through meanwhile
        session_write_close();
        [$ok, $conversation_id, $out] = mitAiAsk(intval($_POST['conversation_id'] ?? 0), (string) ($_POST['question'] ?? ''), intval($_POST['ticket_id'] ?? 0));
        $reply($ok ? 200 : 422, $ok ? ['conversation_id' => $conversation_id, 'messages' => $out] : ['error' => $out, 'conversation_id' => $conversation_id]);
    }
    if (isset($_POST['draft'])) {
        session_write_close();
        $draft = mitAiDocumentDraft(intval($_POST['conversation_id'] ?? 0), intval($_POST['client_id'] ?? 0));
        $reply($draft['ok'] ? 200 : 422, $draft);
    }
    if (isset($_POST['feedback'])) {
        $reply(mitAiFeedback(intval($_POST['message_id'] ?? 0), intval($_POST['value'] ?? 0)) ? 200 : 404, ['ok' => true]);
    }
    if (isset($_POST['delete'])) {
        $reply(mitAiDeleteConversation(intval($_POST['conversation_id'] ?? 0)) ? 200 : 404, ['ok' => true]);
    }
    $reply(400, ['error' => 'Unknown request.']);
}

if (isset($_GET['list'])) {
    $reply(200, ['conversations' => mitAiConversations()]);
}
if (isset($_GET['conversation'])) {
    $conversation = mitAiConversation(intval($_GET['conversation']));
    $reply($conversation ? 200 : 404, $conversation ?: ['error' => 'That conversation is not yours, or no longer there.']);
}
$reply(400, ['error' => 'Unknown request.']);
