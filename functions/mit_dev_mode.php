<?php

/*
 * Mountaineer IT - development mode, for a copy of production used to try out changes (the beta server). Required from
 * functions/mit_common.php.
 *
 * Since 26.12 ITFlow has development mode of its own (functions/dev_mode.php, $config_dev_mode / $config_dev_mail_to in
 * config.php, switched in Admin > Backup): every email to the test inbox, texts, the ticket mailbox, AI Triage, Stripe,
 * log forwarding and managed check-ins off, and the DEVELOPMENT badge. This file adds what is ours on top of it:
 *   - every integration of ours is read-only: mitHttp (Microsoft 365 / Outlook / QuickBooks / Bitwarden / Ingram / MSP
 *     Align / Customer Thermometer) and mitNinjaHttp (NinjaOne) refuse anything but GET / HEAD and sign-in token requests
 *     (mitDevAllows) - nothing is created, changed or deleted in another system, also from a button pressed by hand
 *   - Outlook: busy times are still read, nothing is written to the techs' calendars (mitOutlookSyncLocked); the Microsoft
 *     365 certificate is not renewed (it would change production's app)
 *   - QuickBooks Online works only with an Intuit sandbox company connected on this server (mitQboDevProblem)
 *   - ITFlow's custom actions do not run (functions/logging.php triggerCustomAction hook)
 *   - Customer Thermometer surveys go to the test inbox (functions/mit_ct.php)
 *
 * Bridge (26.12): before ITFlow had it, ours was $config_mit_dev_mode / $config_mit_dev_mail_to. A server switched on with
 * those names only is on in ITFlow's too (below, at load) - else a copy restored from production would email real clients,
 * charge cards and read production's mailbox under ITFlow's code. Database update M067 writes ITFlow's names into
 * config.php once. When ITFlow's own setting is there, it decides.
 */

if (!isset($GLOBALS['config_dev_mode']) && !empty($GLOBALS['config_mit_dev_mode'])) {
    $GLOBALS['config_dev_mode'] = 1;
    $GLOBALS['config_dev_mail_to'] = (string) ($GLOBALS['config_mit_dev_mail_to'] ?? '');
}

/** Is this install in development mode? ITFlow's setting (functions/dev_mode.php), or ours from before 26.12. */
function mitDevMode() {
    if (function_exists('devModeOn')) {
        return devModeOn();
    }
    return !empty($GLOBALS['config_dev_mode']) || !empty($GLOBALS['config_mit_dev_mode']);
}

/** Where development mode sends every email - '' when no good address is set. */
function mitDevMailTo() {
    if (function_exists('devModeMailTo')) {
        return devModeMailTo();
    }
    $to = (string) ($GLOBALS['config_dev_mail_to'] ?? ($GLOBALS['config_mit_dev_mail_to'] ?? ''));
    return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
}

/** Sign-in token addresses: Microsoft, NinjaOne, Bitwarden, Ingram (a token request changes nothing there). */
const MIT_DEV_TOKEN_URL = '#/(oauth2/v2\.0/token|connect/token|ws/oauth/token|token)/?(\?.*)?$#i';

/**
 * In development mode only reads go out: GET / HEAD, and POSTs for a sign-in token - or QuickBooks once mitQboDevProblem
 * allows it (a sandbox company). Everything else is refused.
 */
function mitDevAllows($method, $url) {
    if (!mitDevMode()) {
        return true;
    }
    $method = strtoupper((string) $method);
    if (in_array($method, ['GET', 'HEAD'], true) || !empty($GLOBALS['mit_dev_qbo_ok'])) {
        return true;
    }
    return $method === 'POST' && preg_match(MIT_DEV_TOKEN_URL, (string) parse_url((string) $url, PHP_URL_PATH) . '?');
}

/** The message given when mitDevAllows refuses. */
const MIT_DEV_READ_ONLY = 'Development mode - integrations are read-only on this server (nothing is changed in other systems).';
