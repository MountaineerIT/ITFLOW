<?php

/*
 * Mountaineer IT - development mode, for a copy of production used to try out changes (the beta server). Required from
 * functions/mit_common.php. Kept in config.php ($config_mit_dev_mode, $config_mit_dev_mail_to), not the database, so
 * restoring production's backup over it can't switch it off. While on, nothing reaches the outside world:
 *   - every email goes to the test inbox instead, the real recipients in its subject (cron/mail_queue.php hook)
 *   - the ticket mailbox is not read (cron/ticket_email_parser.php hook) - it would take production's mail
 *   - Outlook: busy times are still read, nothing is written to the techs' calendars (mitOutlookSyncLocked); the Microsoft
 *     365 certificate is not renewed (it would change production's app)
 *   - QuickBooks Online works only with an Intuit sandbox company connected on this server (mitQboDevProblem) - production's
 *     connection from the backup is never used or revoked; its emails go to the test inbox
 *   - Stripe refuses every call - no card is charged, no customer made (includes/stripe_init.php hook)
 *   - ITFlow's custom actions do not run (functions/logging.php triggerCustomAction hook)
 *   - every other integration is read-only: mitHttp (Microsoft 365 / Outlook / QuickBooks / Bitwarden / Ingram) and
 *     mitNinjaHttp (NinjaOne) refuse anything but GET / HEAD and sign-in token requests (mitDevAllows) - so nothing is created,
 *     changed or deleted in another system, also from a button pressed by hand (Microsoft 365 card, Outlook disconnect ...)
 * NinjaOne, Bitwarden and Ingram keep reading (the user chose to keep them). A red DEVELOPMENT badge shows in
 * the top bar (includes/mit_timers_nav.php). Set in Admin > Backup (admin/post/mit_backup_restore.php), or by hand in config.php.
 */

/** Is this install in development mode? */
function mitDevMode() {
    global $config_mit_dev_mode;
    return !empty($config_mit_dev_mode);
}

/** Where development mode sends every email. */
function mitDevMailTo() {
    global $config_mit_dev_mail_to;
    return filter_var((string) ($config_mit_dev_mail_to ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $config_mit_dev_mail_to : '';
}

/**
 * cron/mail_queue.php, before a message is sent (one line): in development mode it goes to the test inbox only, the real
 * recipients in front of the subject. Returns false when it must not be sent at all (no test inbox set) - the caller
 * treats it as done.
 */
function mitDevMailRedirect($mail) {
    if (!mitDevMode()) {
        return true;
    }
    $to = mitDevMailTo();
    $real = [];
    foreach (array_merge($mail->getToAddresses(), $mail->getCcAddresses(), $mail->getBccAddresses()) as $address) {
        $real[] = $address[0];
    }
    $mail->clearAllRecipients();
    if ($to === '') {
        return false;
    }
    $mail->addAddress($to);
    $mail->Subject = '[DEV to ' . implode(', ', $real) . '] ' . $mail->Subject;
    $GLOBALS['mit_dev_redirected_mail'][spl_object_id($mail)] = true;
    return true;
}

/** Sign-in token addresses: Microsoft, NinjaOne, Bitwarden, Ingram (a token request changes nothing there). */
const MIT_DEV_TOKEN_URL = '#/(oauth2/v2\.0/token|connect/token|ws/oauth/token|token)/?(\?.*)?$#i';

/**
 * In development mode only reads go out: GET / HEAD, and POSTs for a sign-in token - or the Graph calls that send a message
 * mitDevMailRedirect has already sent to the test inbox (mitDevSendingRedirected). Everything else is refused.
 */
function mitDevAllows($method, $url) {
    if (!mitDevMode()) {
        return true;
    }
    $method = strtoupper((string) $method);
    if (in_array($method, ['GET', 'HEAD'], true) || !empty($GLOBALS['mit_dev_sending_redirected']) || !empty($GLOBALS['mit_dev_qbo_ok'])) {
        return true;
    }
    return $method === 'POST' && preg_match(MIT_DEV_TOKEN_URL, (string) parse_url((string) $url, PHP_URL_PATH) . '?');
}

/** The message given when mitDevAllows refuses. */
const MIT_DEV_READ_ONLY = 'Development mode - integrations are read-only on this server (nothing is changed in other systems).';

/** functions/mit_m365.php mitM365GraphSendMail: is this message one that development mode sent to the test inbox? */
function mitDevSendingRedirected($mail) {
    return mitDevMode() && !empty($GLOBALS['mit_dev_redirected_mail'][spl_object_id($mail)]);
}

/** includes/stripe_init.php (one line): in development mode every Stripe call is refused - nothing is charged or created. */
function mitDevBlockStripe() {
    if (!mitDevMode() || !class_exists('\Stripe\ApiRequestor')) {
        return;
    }
    \Stripe\ApiRequestor::setHttpClient(new class implements \Stripe\HttpClient\ClientInterface {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null) {
            throw new \Stripe\Exception\ApiConnectionException('Development mode - card payments and Stripe are switched off on this server.');
        }
    });
}

/** Set a variable in config.php (development mode's two settings). Returns '' or why it could not. */
function mitConfigSet($name, $value) {
    $file = dirname(__DIR__) . '/config.php';
    if (!preg_match('/^config_mit_[a-z_]+$/', $name)) {
        return 'Unknown setting.';
    }
    if (!is_writable($file)) {
        return "config.php is not writable - add \$$name = " . var_export($value, true) . "; to it by hand.";
    }
    $text = (string) file_get_contents($file);
    $line = "\$$name = " . var_export($value, true) . ";";
    $pattern = '/^\$' . $name . '\s*=.*;[ \t]*$/m';
    $text = preg_match($pattern, $text) ? preg_replace($pattern, $line, $text) : rtrim($text) . "\n$line\n";
    if (file_put_contents($file, $text, LOCK_EX) === false) {
        return 'config.php could not be written.';
    }
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($file, true); // PHP's code cache would keep the old config.php for a few seconds
    }
    $GLOBALS[$name] = $value;
    return '';
}

/** The top-bar badge (from includes/mit_timers_nav.php, every agent page). */
function mitDevBadge() {
    if (!mitDevMode()) {
        return '';
    }
    $to = mitDevMailTo();
    return '<li class="nav-item d-flex align-items-center me-2"><span class="badge text-bg-danger" data-mit-dev-mode title="'
        . escapeHtml('Development mode: ' . ($to !== '' ? "every email goes to $to" : 'no email is sent') . '; the ticket mailbox, Stripe and custom actions are switched off; QuickBooks only with a sandbox company; other integrations only read')
        . '"><i class="fas fa-flask me-1"></i>DEVELOPMENT</span></li>';
}
