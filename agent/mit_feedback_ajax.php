<?php

/*
 * Mountaineer IT - the Bugs & ideas panel's requests (functions/mit_feedback.php, agent/js/mit_feedback.js):
 *   GET  ?list=open|done                 the reports (JSON) and the open count
 *   GET  ?image=<id>                     a report's screenshot
 *   POST add=1 kind title details page   a new report, with an optional image file (multipart)
 *   POST done=1 id note / reopen=1 id    admins only
 *   POST delete=1 id                     admins, or the sender while it is open
 * Signed-in agents only; POSTs need the CSRF token.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

$reply = function ($code, array $data) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
};

if (!function_exists('mitFeedbackCan') || !mitFeedbackCan()) {
    $reply(403, ['error' => 'Bug reports are not available here.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRFToken();
    if (isset($_POST['add'])) {
        [$id, $error] = mitFeedbackAdd($_POST, $_FILES['image'] ?? null);
        $reply($id ? 200 : 422, $id ? ['id' => $id] : ['error' => $error]);
    }
    if (isset($_POST['done']) || isset($_POST['reopen'])) {
        $error = mitFeedbackSetStatus(intval($_POST['id'] ?? 0), isset($_POST['done']), (string) ($_POST['note'] ?? ''));
        $reply($error === '' ? 200 : 403, $error === '' ? ['ok' => true] : ['error' => $error]);
    }
    if (isset($_POST['delete'])) {
        $error = mitFeedbackDelete(intval($_POST['id'] ?? 0));
        $reply($error === '' ? 200 : 403, $error === '' ? ['ok' => true] : ['error' => $error]);
    }
    $reply(400, ['error' => 'Unknown request.']);
}

if (isset($_GET['image'])) {
    session_write_close();
    if (!mitFeedbackImageServe(intval($_GET['image']))) {
        http_response_code(404);
    }
    exit;
}

if (isset($_GET['list'])) {
    $reply(200, ['items' => mitFeedbackList((string) $_GET['list']), 'open' => mitFeedbackOpenCount(), 'admin' => mitFeedbackIsAdmin()]);
}

$reply(400, ['error' => 'Unknown request.']);
