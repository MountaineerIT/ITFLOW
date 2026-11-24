<?php
/*
 * Mountaineer IT - set a TEST install up against the Microsoft 365 / Graph stand-in (127.0.0.1:8765), the way an admin would on a real
 * tenant, through our own code: the app made with the setup sign-in (mitM365DeviceCodeStart / Poll, mitM365Provision - certificate and
 * all), the mailbox service@mountaineerit.test connected to it (the code exchange ITFlow's callback makes - signed with the certificate),
 * ITFlow's mail sending and receiving on Microsoft 365 with email-to-ticket parsing on, and the admin's (user 1) Outlook calendar linked
 * (mitOutlookConnect). Each step only when it is missing - or no longer known to the stand-in (its state was lost): then the app is
 * forgotten here and made again.
 *
 *   php tests/mocks/ms/setup.php /var/www/itflow_tN      (as root; run by start.sh - refuses an install that is not *.test)
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$root = rtrim($argv[1] ?? '/var/www/itflow', '/');
chdir($root);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'mit ms stand-in setup';
require_once 'config.php';
if (!preg_match('/\.test$/', (string) ($config_base_url ?? ''))) {
    fwrite(STDERR, "  ms setup: not a test install ($config_base_url) - refusing.\n");
    exit(1);
}
// The stand-in's addresses: from config.php, or (the first run - install.php writes them after start.sh) from constants.php
foreach ((array) (include __DIR__ . '/constants.php') as $constant => $value) {
    defined($constant) || define($constant, $value);
}
if (strpos(MIT_M365_LOGIN, '127.0.0.1:8765') === false) {
    fwrite(STDERR, "  ms setup: config.php points MIT_M365_LOGIN elsewhere (" . MIT_M365_LOGIN . ") - refusing.\n");
    exit(1);
}
require_once 'functions.php';
require_once 'includes/load_global_settings.php';
$session_user_id = 1;
$session_name = 'Microsoft 365 stand-in setup';
$session_ip = '127.0.0.1';
$session_user_agent = 'mit ms stand-in setup';
if (function_exists('mitDevMode') && mitDevMode()) {
    echo "  ms setup: development mode is on here - left alone\n";
    exit(0);
}

const MS_MAILBOX = 'service@mountaineerit.test';
$known = function (array $what) {
    $ch = curl_init('http://127.0.0.1:8765/_known?' . http_build_query($what));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '']);
    $json = json_decode((string) curl_exec($ch), true);
    return is_array($json) ? $json : null;
};
/** A sign-in code from the stand-in's authorize page (what Microsoft hands the redirect address). */
$code = function ($client_id, $redirect, $scope, $user) {
    $url = MIT_M365_LOGIN . '/' . rawurlencode(mitM365App()['tenant_id']) . '/oauth2/v2.0/authorize?' . http_build_query(['client_id' => $client_id,
        'response_type' => 'code', 'redirect_uri' => $redirect, 'scope' => $scope, 'state' => 'setup', 'login_hint' => $user], '', '&', PHP_QUERY_RFC3986);
    $page = mitHttp('GET', $url);
    if (!preg_match('/data-code="([^"]+)"/', $page['body'], $m)) {
        throw new RuntimeException('no code from the stand-in: ' . substr($page['body'] . $page['error'], 0, 200));
    }
    return $m[1];
};

try {
    if ($known([]) === null) {
        throw new RuntimeException('the stand-in does not answer at 127.0.0.1:8765');
    }

    // 1. The app
    $app = mitM365App();
    if ($app && !($known(['app' => $app['client_id']])['app'] ?? false)) {
        echo "  ms setup: the stand-in does not know this install's app - made again\n";
        mitM365Forget();
        $app = null;
    }
    if (!$app) {
        $start = mitM365DeviceCodeStart();
        if (!empty($start['error'])) {
            throw new RuntimeException($start['error']);
        }
        $poll = mitM365DeviceCodePoll($start['device_code']);
        if (empty($poll['token'])) {
            throw new RuntimeException($poll['error'] ?? 'no setup token');
        }
        $app = mitM365Provision($poll['token'], 1);
        echo "  ms setup: app {$app['client_id']} made\n";
    }

    // 2. The mailbox, and ITFlow's mail on Microsoft 365 (parser on)
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_mail_oauth_refresh_token FROM settings WHERE company_id = 1"));
    $refresh = (string) ($row['config_mail_oauth_refresh_token'] ?? '');
    if ($refresh === '' || !($known(['refresh' => $refresh])['refresh'] ?? false)) {
        $redirect = mitM365RedirectUris()[0];
        $token_url = MIT_M365_LOGIN . '/' . rawurlencode($app['tenant_id']) . '/oauth2/v2.0/token';
        $scope = 'offline_access openid profile https://outlook.office.com/IMAP.AccessAsUser.All https://outlook.office.com/SMTP.Send';
        $response = mitHttp('POST', $token_url, [], mitMicrosoftTokenFields($token_url, ['client_id' => $app['client_id'], 'client_secret' => MIT_M365_CERT_SECRET,
            'grant_type' => 'authorization_code', 'code' => $code($app['client_id'], $redirect, $scope, MS_MAILBOX), 'redirect_uri' => $redirect, 'scope' => $scope]));
        if (empty($response['json']['refresh_token'])) {
            throw new RuntimeException(mitM365Error($response, 'Connecting the mailbox'));
        }
        $refresh_sql = mysqli_real_escape_string($mysqli, $response['json']['refresh_token']);
        $access_sql = mysqli_real_escape_string($mysqli, $response['json']['access_token']);
        mysqli_query($mysqli, "UPDATE settings SET config_mail_oauth_refresh_token = '$refresh_sql', config_mail_oauth_access_token = '$access_sql',
            config_mail_oauth_access_token_expires_at = '" . date('Y-m-d H:i:s', time() + 3600) . "' WHERE company_id = 1");
        if (function_exists('mailGraphForget')) { mailGraphForget(); }
        echo "  ms setup: mailbox " . MS_MAILBOX . " connected\n";
    }
    mysqli_query($mysqli, "UPDATE settings SET config_smtp_provider = 'microsoft_oauth', config_imap_provider = 'microsoft_oauth', config_smtp_username = '" . MS_MAILBOX . "',
        config_imap_username = '" . MS_MAILBOX . "', config_ticket_email_parse = 1 WHERE company_id = 1");
    if (function_exists('mailGraphForget')) { mailGraphForget(); }
    if (mailGraphToken('Mail.Send', true) === null || mailGraphToken('Mail.ReadWrite') === null) {
        throw new RuntimeException('the mailbox token has no Mail.Send / Mail.ReadWrite');
    }

    // 3. The admin's Outlook calendar
    $link = mitOutlookLink(1);
    $outlook_refresh = $link ? mitDecryptSecret((string) $link['refresh_token']) : '';
    if ($outlook_refresh === '' || !($known(['refresh' => $outlook_refresh])['refresh'] ?? false)) {
        $email = (string) mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_email FROM users WHERE user_id = 1"))[0];
        mitOutlookConnect(1, $code(mitOutlookClientId(), mitM365RedirectUris()[1], MIT_OUTLOOK_SCOPES, $email));
        echo "  ms setup: Outlook calendar of user 1 ($email) linked\n";
    }
    echo "  ms setup: ready (app " . mitM365App()['client_id'] . ")\n";
} catch (Throwable $e) {
    fwrite(STDERR, '  ms setup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
