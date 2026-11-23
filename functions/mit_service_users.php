<?php

/*
 * Mountaineer IT - API / service accounts. Since 26.12 ITFlow has them itself (functions/service_account.php: users.user_service_account,
 * the switch on a user's Sign in tab, no browser sign-in, left out of ITFlow's agent lists - built from ours; M067 copied our
 * mit_service_users into it). What stays here: our own agent lists leave them out too, through the one helper they always called.
 */

/** " AND <user id column> is not an API / service account" - for our queries that list agents. */
function mitServiceUserSql($column = 'user_id') {
    if (!function_exists('serviceAccountsReady') || !serviceAccountsReady() || !preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', (string) $column)) {
        return '';
    }
    return " AND $column NOT IN (SELECT service_users.user_id FROM users AS service_users WHERE service_users.user_service_account = 1)";
}

/** Is this login an API / service account? */
function mitIsServiceUser($user_id) {
    return function_exists('serviceAccountIs') && serviceAccountIs($user_id);
}
