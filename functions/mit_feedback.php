<?php
/*
 * Mountaineer IT - bug reports and feature requests (the top bar's Feedback button, agent/js/mit_feedback.js, requests to
 * agent/mit_feedback_ajax.php). Any agent sends one - bug or feature, a title, details, a screenshot pasted or picked - and the page
 * it was sent from, our build, the browser, who and when are kept with it. Every agent sees every report (so nobody reports the same
 * thing twice); admins mark them done (with a note), reopen or delete them, and the sender may delete their own while it is open.
 * A new report rings the bell for the admins, a closed one for its sender. Table mit_feedback (M047); screenshots are re-drawn by
 * GD (mitImageStore - only the pixels are kept) into uploads/mit_feedback/, which is closed to the web, and shown through the
 * request handler to signed-in agents only. Required from functions/mit_common.php.
 */

const MIT_FEEDBACK_DIR = 'uploads/mit_feedback';
const MIT_FEEDBACK_IMAGE_SIDE = 2400;
const MIT_FEEDBACK_KINDS = ['bug' => 'Bug', 'feature' => 'Feature request'];

/** Has database update M047 run? One cached query per request. */
function mitFeedbackReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_feedback'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** A signed-in agent (not a portal contact) on an install that has the table. */
function mitFeedbackCan() {
    return mitFeedbackReady() && mitFeedbackOn() && !empty($GLOBALS['session_user_id']) && empty($_SESSION['client_logged_in']);
}

/** Switched on in Admin > Debug (mit_settings feedback_enabled) - off until an admin turns it on. */
function mitFeedbackOn() {
    return mitSetting('feedback_enabled', '0') === '1';
}

function mitFeedbackIsAdmin() {
    return !empty($GLOBALS['session_is_admin']);
}

/** Text for SQL kept exactly as typed (escapeSql would strip anything that looks like a tag) - shown escaped. */
function mitFeedbackSql($text) {
    global $mysqli;
    return mysqli_real_escape_string($mysqli, (string) $text);
}

/** The screenshot folder, closed to the web. */
function mitFeedbackFolder() {
    $dir = dirname(__DIR__) . '/' . MIT_FEEDBACK_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException('Could not create the screenshot folder in uploads.');
    }
    if (!is_file("$dir/.htaccess")) {
        @file_put_contents("$dir/.htaccess", "Require all denied\n");
    }
    return $dir;
}

/** Is this one of our screenshot files (and nothing else)? */
function mitFeedbackImageIsOurs($relative) {
    return is_string($relative) && preg_match('#^' . preg_quote(MIT_FEEDBACK_DIR, '#') . '/[a-f0-9]{32}\.(jpg|png)$#', $relative) === 1;
}

/** A page of this site as path + query (what the sender was looking at), or ''. */
function mitFeedbackPage($page) {
    $page = trim((string) $page);
    $parts = parse_url($page);
    if ($parts === false) {
        return '';
    }
    $path = (string) ($parts['path'] ?? '');
    if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0) {
        return '';
    }
    $page = $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    return mb_substr($page, 0, 500);
}

/**
 * Save a report. $post: kind, title, details, page; $file: an uploaded image ($_FILES entry) or null.
 * Returns [id, ''] or [0, why not].
 */
function mitFeedbackAdd(array $post, $file = null) {
    global $mysqli, $session_user_id, $session_name;
    if (!mitFeedbackCan()) {
        return [0, 'Bug reports are not available here.'];
    }
    $kind = isset(MIT_FEEDBACK_KINDS[$post['kind'] ?? '']) ? $post['kind'] : 'bug';
    $title = trim(preg_replace('/\s+/u', ' ', (string) ($post['title'] ?? '')));
    $details = trim((string) ($post['details'] ?? ''));
    if ($title === '') {
        return [0, 'Give it a short title.'];
    }
    $title = mb_substr($title, 0, 200);
    $details = mb_substr($details, 0, 10000);
    $page = mitFeedbackPage($post['page'] ?? '');
    if (!defined('APP_VERSION') && is_file(dirname(__DIR__) . '/includes/app_version.php')) {
        require_once dirname(__DIR__) . '/includes/app_version.php'; // ITFlow's release - only some pages load it
    }
    $version = mb_substr((function_exists('mitVersion') ? mitVersion() : '') . (defined('APP_VERSION') ? ' / ITFlow ' . APP_VERSION : ''), 0, 60);
    $browser = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $image = null;
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            return [0, 'The screenshot did not come through - try again (up to 8 MB).'];
        }
        try {
            mitFeedbackFolder();
            $image = mitImageStore((string) file_get_contents($file['tmp_name']), MIT_FEEDBACK_DIR, MIT_FEEDBACK_IMAGE_SIDE);
        } catch (Throwable $e) {
            return [0, $e->getMessage()];
        }
    }

    $user_id = intval($session_user_id);
    mysqli_query($mysqli, "INSERT INTO mit_feedback SET kind = '$kind', title = '" . mitFeedbackSql($title) . "', details = '" . mitFeedbackSql($details) . "',
        page = '" . mitFeedbackSql($page) . "', version = '" . mitFeedbackSql($version) . "', browser = '" . mitFeedbackSql($browser) . "',
        image = " . ($image ? "'" . mitFeedbackSql($image) . "'" : 'NULL') . ", created_by = $user_id");
    $id = intval(mysqli_insert_id($mysqli));

    // The admins hear about it (not the sender)
    $line = MIT_FEEDBACK_KINDS[$kind] . ' from ' . stripslashes((string) $session_name) . ': ' . $title;
    $admins = mysqli_query($mysqli, "SELECT u.user_id FROM users u JOIN user_roles r ON r.role_id = u.user_role_id
        WHERE r.role_is_admin = 1 AND u.user_type = 1 AND u.user_status = 1 AND u.user_archived_at IS NULL AND u.user_id != $user_id");
    while ($row = mysqli_fetch_assoc($admins)) {
        mitFeedbackBell(intval($row['user_id']), $line, $id);
    }
    logAudit('Feedback', 'Create', "$session_name sent " . strtolower(MIT_FEEDBACK_KINDS[$kind]) . ' #' . $id . ': ' . mitFeedbackSql(mb_substr($title, 0, 150)));
    return [$id, ''];
}

/** A bell notification that opens the Feedback panel on that report. */
function mitFeedbackBell($user_id, $text, $feedback_id) {
    global $mysqli;
    mysqli_query($mysqli, "INSERT INTO notifications SET notification_type = 'Feedback', notification = '" . mitFeedbackSql(mb_substr($text, 0, 1000)) . "',
        notification_action = '/agent/dashboard.php?mit_feedback=" . intval($feedback_id) . "', notification_user_id = " . intval($user_id)
        . ", notification_entity_id = " . intval($feedback_id));
}

function mitFeedbackRow($id) {
    global $mysqli;
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_feedback WHERE feedback_id = " . intval($id))) ?: null;
}

/** Reports with this status ('open' | 'done'), newest first - for the panel. Text is raw: the script escapes it. */
function mitFeedbackList($status) {
    global $mysqli, $session_user_id;
    $status = $status === 'done' ? 'done' : 'open';
    $order = $status === 'done' ? 'f.done_at DESC' : 'f.created_at DESC';
    $result = mysqli_query($mysqli, "SELECT f.*, c.user_name AS created_name, d.user_name AS done_name FROM mit_feedback f
        LEFT JOIN users c ON c.user_id = f.created_by LEFT JOIN users d ON d.user_id = f.done_by
        WHERE f.status = '$status' ORDER BY $order, f.feedback_id DESC LIMIT 500");
    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = [
            'id' => intval($row['feedback_id']),
            'kind' => $row['kind'],
            'kind_label' => MIT_FEEDBACK_KINDS[$row['kind']] ?? 'Bug',
            'title' => $row['title'],
            'details' => (string) $row['details'],
            'page' => $row['page'],
            'version' => $row['version'],
            'browser' => $row['browser'],
            'image' => mitFeedbackImageIsOurs($row['image']) ? '/agent/mit_feedback_ajax.php?image=' . intval($row['feedback_id']) : '',
            'by' => $row['created_name'] ?? 'Someone no longer here',
            'at' => $row['created_at'],
            'done_by' => $row['done_name'] ?? '',
            'done_at' => $row['done_at'],
            'done_note' => (string) $row['done_note'],
            'mine' => intval($row['created_by']) === intval($session_user_id),
        ];
    }
    return $items;
}

function mitFeedbackOpenCount() {
    global $mysqli;
    if (!mitFeedbackReady()) {
        return 0;
    }
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_feedback WHERE status = 'open'"))[0] ?? 0);
}

/** An admin marks a report done (with a note) or open again. Returns '' or why not. */
function mitFeedbackSetStatus($id, $done, $note = '') {
    global $mysqli, $session_user_id, $session_name;
    if (!mitFeedbackIsAdmin()) {
        return 'Only an admin can mark reports done.';
    }
    $row = mitFeedbackRow($id);
    if (!$row) {
        return 'That report is gone.';
    }
    $id = intval($row['feedback_id']);
    if ($done) {
        $note = mb_substr(trim((string) $note), 0, 5000);
        mysqli_query($mysqli, "UPDATE mit_feedback SET status = 'done', done_by = " . intval($session_user_id) . ", done_at = NOW(),
            done_note = '" . mitFeedbackSql($note) . "' WHERE feedback_id = $id");
        if (intval($row['created_by']) && intval($row['created_by']) !== intval($session_user_id)) {
            mitFeedbackBell(intval($row['created_by']), 'Done: ' . $row['title'] . ($note !== '' ? ' - ' . mb_substr($note, 0, 200) : ''), $id);
        }
        logAudit('Feedback', 'Edit', "$session_name marked report #$id done");
    } else {
        mysqli_query($mysqli, "UPDATE mit_feedback SET status = 'open', done_by = NULL, done_at = NULL, done_note = NULL WHERE feedback_id = $id");
        logAudit('Feedback', 'Edit', "$session_name reopened report #$id");
    }
    return '';
}

/** Admins delete any report; the sender their own while it is open. Returns '' or why not. */
function mitFeedbackDelete($id) {
    global $mysqli, $session_user_id, $session_name;
    $row = mitFeedbackRow($id);
    if (!$row) {
        return 'That report is gone.';
    }
    $own_open = intval($row['created_by']) === intval($session_user_id) && $row['status'] === 'open';
    if (!mitFeedbackIsAdmin() && !$own_open) {
        return 'Only an admin can delete this report.';
    }
    if (mitFeedbackImageIsOurs($row['image'])) {
        @unlink(dirname(__DIR__) . '/' . $row['image']);
    }
    mysqli_query($mysqli, "DELETE FROM mit_feedback WHERE feedback_id = " . intval($row['feedback_id']));
    logAudit('Feedback', 'Delete', "$session_name deleted report #" . intval($row['feedback_id']));
    return '';
}

/** The report's screenshot to a signed-in agent; false when there is none. */
function mitFeedbackImageServe($id) {
    $row = mitFeedbackRow($id);
    if (!$row || !mitFeedbackImageIsOurs($row['image'])) {
        return false;
    }
    $file = dirname(__DIR__) . '/' . $row['image'];
    if (!is_file($file)) {
        return false;
    }
    header('Content-Type: ' . (substr($file, -4) === '.png' ? 'image/png' : 'image/jpeg'));
    header('Content-Length: ' . filesize($file));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    readfile($file);
    return true;
}

/** The top bar's button and panel (echoed from includes/mit_timers_nav.php). */
function mitFeedbackNav() {
    if (!mitFeedbackCan()) {
        return '';
    }
    $open = mitFeedbackIsAdmin() ? mitFeedbackOpenCount() : 0;
    return '<li class="nav-item" id="mitFeedbackNav">'
        . '<a class="nav-link" href="#" role="button" data-bs-toggle="offcanvas" data-bs-target="#mitFeedbackPanel" aria-controls="mitFeedbackPanel" title="Bugs & ideas" data-mit-feedback-open>'
        . '<i class="fas fa-bug" aria-hidden="true"></i>'
        . '<span class="badge rounded-pill text-bg-danger navbar-badge" data-mit-feedback-badge' . ($open ? '' : ' hidden') . '>' . $open . '</span></a>'
        // hidden until the script moves it out of the top bar (as the Ask AI panel)
        . '<div class="offcanvas offcanvas-end mit-feedback-panel" tabindex="-1" id="mitFeedbackPanel" aria-labelledby="mitFeedbackTitle" hidden'
        . ' data-endpoint="/agent/mit_feedback_ajax.php" data-csrf="' . escapeHtml($_SESSION['csrf_token'] ?? '') . '" data-admin="' . (mitFeedbackIsAdmin() ? '1' : '0') . '">'
        . '<div class="offcanvas-header border-bottom py-2"><h5 class="offcanvas-title me-auto" id="mitFeedbackTitle"><i class="fas fa-bug me-2"></i>Bugs & ideas</h5>'
        . '<div class="d-flex align-items-center ms-auto"><button type="button" class="btn btn-sm btn-primary me-3" data-mit-feedback-new><i class="fas fa-plus me-1"></i>New</button>'
        . '<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div></div>'
        . '<div class="offcanvas-body p-0 d-flex flex-column">'
        . '<form class="border-bottom p-3 bg-body-tertiary" data-mit-feedback-form hidden>'
        . '<div class="btn-group btn-group-sm w-100 mb-2" role="group">'
        . '<input type="radio" class="btn-check" name="kind" id="mitFeedbackBug" value="bug" checked><label class="btn btn-outline-danger" for="mitFeedbackBug"><i class="fas fa-bug me-1"></i>Bug</label>'
        . '<input type="radio" class="btn-check" name="kind" id="mitFeedbackIdea" value="feature"><label class="btn btn-outline-primary" for="mitFeedbackIdea"><i class="fas fa-lightbulb me-1"></i>Feature request</label></div>'
        . '<input type="text" class="form-control form-control-sm mb-2" name="title" maxlength="200" placeholder="Short title" required>'
        . '<textarea class="form-control form-control-sm mb-2" name="details" rows="4" maxlength="10000" placeholder="What happened, or what would you like? Steps to see it help."></textarea>'
        . '<div class="small text-muted mb-2" data-mit-feedback-shot>Paste a screenshot here (Ctrl+V) or <label class="link-primary mb-0" role="button" for="mitFeedbackFile">pick an image</label>.'
        . '<input type="file" class="d-none" id="mitFeedbackFile" name="image" accept="image/png,image/jpeg,image/gif,image/webp"></div>'
        . '<div class="mb-2" data-mit-feedback-preview hidden></div>'
        . '<div class="small text-muted mb-2" data-mit-feedback-where></div>'
        . '<div class="d-flex gap-2"><button type="submit" class="btn btn-primary btn-sm" data-mit-feedback-send>Send</button>'
        . '<button type="button" class="btn btn-light btn-sm" data-mit-feedback-cancel>Cancel</button>'
        . '<span class="small text-danger align-self-center" data-mit-feedback-error></span></div></form>'
        . '<ul class="nav nav-tabs px-2 pt-2" role="tablist">'
        . '<li class="nav-item"><button type="button" class="nav-link active" data-mit-feedback-tab="open">Open <span class="badge text-bg-secondary" data-mit-feedback-count="open"></span></button></li>'
        . '<li class="nav-item"><button type="button" class="nav-link" data-mit-feedback-tab="done">Done</button></li></ul>'
        . '<div class="flex-grow-1 overflow-auto p-2" data-mit-feedback-list><div class="text-muted small p-2">Loading...</div></div>'
        . '</div></div>'
        . '<script src="' . mitAsset('/agent/js/mit_feedback.js') . '" defer></script>'
        . '</li>';
}
