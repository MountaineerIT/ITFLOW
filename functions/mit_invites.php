<?php

/*
 * Mountaineer IT - our New User fields on ITFlow's agent invites (ITFlow 3.5.1, functions/user_invite.php). An invited agent's login is
 * made only when they accept the invite (join.php -> userInviteAccept), so Employment (mitTsPayTypeField) and Office (mitOfficeUserField)
 * picked in New User are kept with the invite (M049 `mit_invite_extras`) and put on the new login then:
 *  - mitInviteExtrasSave($invite_id, $_POST) - one line in admin/post/users.php add_user's invite branch (after userInviteSave);
 *  - mitInviteExtrasApply($invite_id, $user_id) - one line in userInviteAccept, before it returns the new agent.
 * Sending the invite again (the same address) replaces what was kept; cancelling or removing it takes ours too (CASCADE).
 */

/** The posted fields of ours an invite keeps. */
const MIT_INVITE_EXTRA_FIELDS = ['mit_pay_type', 'mit_office_id'];

/** Has database update M049 run (it needs ITFlow's user_invites)? */
function mitInvitesReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_invite_extras'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Keep what New User asked of ours with the invite (replacing what an earlier invite to them kept). */
function mitInviteExtrasSave($invite_id, array $post) {
    global $mysqli;
    $invite_id = intval($invite_id);
    if (!$invite_id || !mitInvitesReady()) {
        return;
    }
    $extras = [];
    foreach (MIT_INVITE_EXTRA_FIELDS as $field) {
        if (isset($post[$field]) && !is_array($post[$field])) {
            $extras[$field] = mb_substr((string) $post[$field], 0, 50);
        }
    }
    if (!$extras) {
        mysqli_query($mysqli, "DELETE FROM mit_invite_extras WHERE invite_id = $invite_id");
        return;
    }
    $json = mysqli_real_escape_string($mysqli, json_encode($extras));
    mysqli_query($mysqli, "INSERT INTO mit_invite_extras SET invite_id = $invite_id, extras = '$json' ON DUPLICATE KEY UPDATE extras = '$json'");
}

/** What an invite kept, as posted fields ([] when nothing). */
function mitInviteExtras($invite_id) {
    global $mysqli;
    if (!mitInvitesReady()) {
        return [];
    }
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT extras FROM mit_invite_extras WHERE invite_id = " . intval($invite_id)));
    $extras = $row ? json_decode((string) $row[0], true) : null;
    return is_array($extras) ? array_intersect_key($extras, array_flip(MIT_INVITE_EXTRA_FIELDS)) : [];
}

/** The invite was accepted: put what it kept on the new login, the way New User's own save does. */
function mitInviteExtrasApply($invite_id, $user_id) {
    global $mysqli;
    $user_id = intval($user_id);
    $extras = mitInviteExtras($invite_id);
    if (!$user_id || !$extras) {
        return;
    }
    if (function_exists('mitTsSavePayType')) {
        mitTsSavePayType($user_id, $extras);
    }
    if (function_exists('mitOfficeUserSave')) {
        mitOfficeUserSave($user_id, $extras);
    }
    mysqli_query($mysqli, "DELETE FROM mit_invite_extras WHERE invite_id = " . intval($invite_id));
}
