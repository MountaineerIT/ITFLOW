<?php

/*
 * Mountaineer IT - API / service accounts (26.11.2.6, the user's request).
 *
 * ITFlow's API keys act as an agent (api_keys.api_key_user_id - an active agent with a role decides what a key may do), so a key
 * needs a login of its own - which then showed up in the calendar's Whose, Dispatch, every assign picker, reports... An admin
 * marks such a login "API / service account" in Admin > Users (mitServiceUserField / mitServiceUserSave, table M054
 * mit_service_users). It stays an active agent with its role, so its keys keep working, but:
 *   - every agent list leaves it out - one guarded `mitServiceUserSql('<user id column>')` appended to each query (ITFlow's and ours);
 *   - it can't sign in to the web pages (login.php skips it; includes/load_user_session.php ends a session it already had);
 *   - Admin > Users lists it in its own section at the bottom (mitServiceUsersSection), Roles and API Keys show it as before.
 */

/** Has database update M054 run? */
function mitServiceUsersReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_service_users'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The marked users' ids. $forget = true reads them again (after a change). */
function mitServiceUserIds($forget = false) {
    global $mysqli;
    static $ids = null;
    if ($forget) {
        $ids = null;
    }
    if ($ids === null) {
        $ids = [];
        if (mitServiceUsersReady()) {
            $result = mysqli_query($mysqli, "SELECT user_id FROM mit_service_users");
            while ($row = mysqli_fetch_row($result)) {
                $ids[] = intval($row[0]);
            }
        }
    }
    return $ids;
}

/** Is this user an API / service account? */
function mitIsServiceUser($user_id) {
    return in_array(intval($user_id), mitServiceUserIds(), true);
}

/**
 * " AND <column> NOT IN (...)" leaving the service accounts out of an agent list - '' when there are none. $column is the
 * query's user id column ('user_id', 'users.user_id', 'u.user_id' ...).
 */
function mitServiceUserSql($column = 'user_id') {
    $ids = mitServiceUserIds();
    if (!$ids || !preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $column)) {
        return '';
    }
    return " AND $column NOT IN (" . implode(',', $ids) . ")";
}

/** An array of agents keyed by user id with the service accounts taken out. */
function mitServiceUsersOut(array $agents) {
    foreach (mitServiceUserIds() as $user_id) {
        unset($agents[$user_id]);
    }
    return $agents;
}

/** The switch on Admin > Users > New / Edit (one line in admin/modals/user/user_form.php after Office). */
function mitServiceUserField($user_id) {
    if (!mitServiceUsersReady()) {
        return '';
    }
    $on = $user_id && mitIsServiceUser($user_id);
    return '<div class="col-12" data-mit-service-user><input type="hidden" name="mit_service_present" value="1">'
        . '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="mit_service_user" value="1" id="mitServiceUser' . intval($user_id) . '"'
        . ($on ? ' checked' : '') . '><label class="form-check-label" for="mitServiceUser' . intval($user_id) . '"><i class="fas fa-fw fa-plug me-1"></i>API / service account</label></div>'
        . '<div class="form-text">For a login an API key acts as. It keeps its role, so its keys work, but it is left out of the calendar, Dispatch, '
        . 'assign pickers, reports and every other agent list, and it can\'t sign in to ITFlow in a browser. It shows only here, in Roles and in API Keys.</div></div>';
}

/** Save the switch (one line each in admin/post/users.php add_user / edit_user); a no-op when the form did not have it. Returns a change line or ''. */
function mitServiceUserSave($user_id, array $post) {
    global $mysqli;
    $user_id = intval($user_id);
    if (!$user_id || empty($post['mit_service_present']) || !mitServiceUsersReady()) {
        return '';
    }
    $want = !empty($post['mit_service_user']);
    $was = mitIsServiceUser($user_id);
    if ($want === $was) {
        return '';
    }
    if ($want) {
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_service_users SET user_id = $user_id, marked_by = " . intval($GLOBALS['session_user_id'] ?? 0));
    } else {
        mysqli_query($mysqli, "DELETE FROM mit_service_users WHERE user_id = $user_id");
    }
    mitServiceUserIds(true);
    $name = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $user_id"))[0] ?? '');
    $line = $want ? "marked $name as an API / service account" : "made $name an ordinary agent again (no longer an API / service account)";
    if (function_exists('logAudit')) {
        logAudit("User", "Edit", escapeSql(stripslashes((string) ($GLOBALS['session_name'] ?? 'Someone')) . " $line"), 0, $user_id);
    }
    return $line;
}

/** Admin > Users: the service accounts in a section of their own (one line in admin/users.php under the agents list). */
function mitServiceUsersSection() {
    global $mysqli;
    $ids = mitServiceUserIds();
    if (!$ids) {
        return '';
    }
    $result = mysqli_query($mysqli, "SELECT u.user_id, u.user_name, u.user_email, u.user_status, u.user_archived_at, r.role_name,
            (SELECT COUNT(*) FROM api_keys k WHERE k.api_key_user_id = u.user_id) AS key_count
        FROM users u LEFT JOIN user_roles r ON r.role_id = u.user_role_id
        WHERE u.user_id IN (" . implode(',', $ids) . ") AND u.user_archived_at IS NULL ORDER BY u.user_name");
    $rows = '';
    while ($row = mysqli_fetch_assoc($result)) {
        $rows .= '<tr data-mit-service-row="' . intval($row['user_id']) . '"><td><span class="badge text-bg-secondary me-2"><i class="fas fa-plug me-1"></i>API</span>'
            . '<a href="#" class="fw-semibold ajax-modal" data-modal-size="lg" data-modal-url="modals/user/user_edit.php?id=' . intval($row['user_id']) . '">' . escapeHtml($row['user_name']) . '</a>'
            . '<div class="small text-body-secondary">' . escapeHtml($row['user_email']) . '</div></td>'
            . '<td>' . escapeHtml((string) ($row['role_name'] ?? 'No role')) . '</td>'
            . '<td>' . intval($row['key_count']) . ' API key' . (intval($row['key_count']) === 1 ? '' : 's') . '</td>'
            . '<td>' . (intval($row['user_status']) === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Disabled</span>') . '</td></tr>';
    }
    if ($rows === '') {
        return '';
    }
    return '<div class="card mt-3" data-mit-service-users><div class="card-header py-2"><h3 class="card-title h6 mb-0"><i class="fas fa-fw fa-plug me-2"></i>API / service accounts</h3>'
        . '<div class="small text-body-secondary">Logins API keys act as - left out of every agent list and unable to sign in to ITFlow. Open one to change it.</div></div>'
        . '<div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Account</th><th>Role</th><th>Keys</th><th>Status</th></tr></thead><tbody>'
        . $rows . '</tbody></table></div></div></div>';
}
