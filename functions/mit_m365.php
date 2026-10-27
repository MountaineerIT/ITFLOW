<?php

/*
 * Mountaineer IT - Microsoft 365 connection.
 *
 * ITFlow can send and receive mail through Microsoft 365, but needs an app registration made
 * by hand with a client secret pasted in. This creates that app in the tenant instead:
 *
 *  1. An admin signs in once with a code (device code flow, using Microsoft's own public
 *     "Microsoft Graph Command Line Tools" client - a self-hosted server has no app of its own
 *     to sign in with yet). That sign-in is used only to create the app and is then dropped.
 *  2. The app is created with this server's sign-in addresses, the delegated permissions for
 *     the mailbox (IMAP/SMTP) and each person's Outlook calendar, admin consent for the whole
 *     organization, and a certificate (no client secret).
 *  3. ITFlow's own mail settings are pointed at it. The client secret field holds a marker
 *     (MIT_M365_CERT_SECRET) so ITFlow's checks pass; every token request to Microsoft goes
 *     through mitMicrosoftTokenFields(), which swaps the marker for a signed certificate
 *     assertion.
 *  4. Mail goes through Microsoft Graph rather than SMTP/IMAP (no "Authenticated SMTP" switch
 *     needed on the mailbox): cron/mail_queue.php hands each message to mitM365GraphSendMail(),
 *     and cron/ticket_email_parser.php reads the mailbox through mitM365GraphMailbox()
 *     (classes in functions/mit_m365_mailbox.php). Without the Mail permissions it stays on SMTP/IMAP.
 *  5. The certificate lasts a year and is replaced 30 days before it runs out - the app adds
 *     its new key itself (Graph addKey, which needs no extra permission).
 *
 * The app only gets delegated permissions: it can reach the mailbox that is connected and the
 * calendars of people who connect their own - never everyone's mail or calendars.
 *
 * Settings live in mit_settings (database update M003), prefixed m365_.
 */

defined('MIT_M365_LOGIN') || define('MIT_M365_LOGIN', 'https://login.microsoftonline.com');
defined('MIT_M365_GRAPH') || define('MIT_M365_GRAPH', 'https://graph.microsoft.com/v1.0');

/** Microsoft's public "Microsoft Graph Command Line Tools" client - used only for the one-time setup sign-in. */
const MIT_M365_BOOTSTRAP_CLIENT = '14d82eec-204b-4c2f-b7e8-296a70dab67e';
const MIT_M365_BOOTSTRAP_SCOPES = 'https://graph.microsoft.com/Application.ReadWrite.All https://graph.microsoft.com/DelegatedPermissionGrant.ReadWrite.All https://graph.microsoft.com/User.Read';

/** What the client secret field holds when the app signs in with its certificate. */
const MIT_M365_CERT_SECRET = 'mit-m365-certificate';

const MIT_M365_APP_NAME = 'Mountaineer IT ITFlow email and calendars';
const MIT_M365_GRAPH_APP_ID = '00000003-0000-0000-c000-000000000000';
const MIT_M365_EXCHANGE_APP_ID = '00000002-0000-0ff1-ce00-000000000000';

/** Delegated permissions the app is given and consented for, per resource. */
const MIT_M365_GRAPH_SCOPES = ['User.Read', 'Calendars.ReadWrite', 'Mail.ReadWrite', 'Mail.ReadWrite.Shared', 'Mail.Send', 'Mail.Send.Shared',
    'offline_access', 'openid', 'profile', 'email'];
const MIT_M365_EXCHANGE_SCOPES = ['IMAP.AccessAsUser.All', 'SMTP.Send'];
/**
 * The calendar-only app for people's Outlook calendars (functions/mit_outlook.php): their saved sign-ins can only reach
 * their calendar - not their mail, as they could through the main app, which has the Mail permissions for ITFlow's mailbox.
 * Same tenant and the same certificate as the main app; settings m365_cal_client_id / m365_cal_object_id.
 */
const MIT_M365_CAL_APP_NAME = 'Mountaineer IT ITFlow Outlook calendars';
const MIT_M365_CAL_SCOPES = ['User.Read', 'Calendars.ReadWrite', 'offline_access', 'openid', 'profile', 'email'];
/** Bumped when MIT_M365_CAL_SCOPES changes - setting m365_cal_permissions_version; an older calendar app is offered "Update permissions". */
const MIT_M365_CAL_PERMISSIONS_VERSION = 2;

/** Bumped when the permissions above change - an app set up with fewer is offered "Update permissions". */
const MIT_M365_PERMISSIONS_VERSION = 2;

const MIT_M365_CERT_DAYS = 365;
const MIT_M365_RENEW_DAYS = 30;

/*
 * ---------- our settings (mit_settings) ----------
 */

/** Every row of mit_settings - name => value ([] when the table is not there yet). Use mitSetting() for one. */
function mitSettingsAll() {
    global $mysqli;
    $all = [];
    try {
        $result = mysqli_query($mysqli, "SELECT setting_name, setting_value FROM mit_settings");
        while ($result && $row = mysqli_fetch_assoc($result)) {
            $all[$row['setting_name']] = $row['setting_value'];
        }
    } catch (Throwable $e) {
        // mit_settings not there yet (code updated, database update still to run): nothing is set up,
        // so mail and everything else carry on exactly as stock ITFlow
    }
    return $all;
}

/** Keep the request's copy of mit_settings in step with a write: $value null removes the name(s). */
function mitSettingsRemember($name, $value, $prefix = false) {
    $cache = &mitSettingsCacheRef();
    if ($prefix) {
        foreach (array_keys($cache) as $key) {
            if (strpos($key, $name) === 0) {
                unset($cache[$key]);
            }
        }
    } elseif ($value === null) {
        unset($cache[$name]);
    } else {
        $cache[$name] = (string) $value;
    }
}

/**
 * The request's copy of mit_settings, read once (the table is small) and kept in step by our setters.
 * $reload reads it again - the cron dispatcher runs every job in one process, so each job starts with this.
 */
function &mitSettingsCacheRef($reload = false) {
    static $ref = null;
    if ($ref === null || $reload) {
        $ref = mitSettingsAll();
    }
    return $ref;
}

/** One of our settings (mit_settings), or $default when it is not set. */
function mitSetting($name, $default = null) {
    $cache = &mitSettingsCacheRef();
    return array_key_exists((string) $name, $cache) ? $cache[(string) $name] : $default;
}

/** Set one of our settings. */
function mitSetSetting($name, $value) {
    global $mysqli;
    // Not escapeSql(): it strips tags and trims, and values here (certificates, encrypted tokens, addresses) must come back exactly
    $name_sql = mysqli_real_escape_string($mysqli, (string) $name);
    $value_sql = mysqli_real_escape_string($mysqli, (string) $value);
    mysqli_query($mysqli, "INSERT INTO mit_settings SET setting_name = '$name_sql', setting_value = '$value_sql' ON DUPLICATE KEY UPDATE setting_value = '$value_sql'");
    mitSettingsRemember((string) $name, $value);
}

/** Remove one of our settings, by its exact name. */
function mitDeleteSetting($name) {
    global $mysqli;
    $name_sql = mysqli_real_escape_string($mysqli, (string) $name);
    mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name = '$name_sql'");
    mitSettingsRemember((string) $name, null);
}

/** Remove every one of our settings whose name STARTS WITH $prefix (e.g. 'm365_' - the whole app). For one name use mitDeleteSetting(). */
function mitDeleteSettings($prefix) {
    global $mysqli;
    $prefix_sql = mysqli_real_escape_string($mysqli, addcslashes((string) $prefix, '%_\\'));
    mysqli_query($mysqli, "DELETE FROM mit_settings WHERE setting_name LIKE '$prefix_sql%'");
    mitSettingsRemember((string) $prefix, null, true);
}

/** The app as set up, or null: tenant_id, client_id, object_id, name, created_at, created_by, cert_expires, ... */
function mitM365App() {
    $client_id = mitSetting('m365_client_id');
    if (empty($client_id)) {
        return null;
    }
    $app = ['client_id' => $client_id];
    foreach (['tenant_id', 'object_id', 'name', 'created_at', 'created_by', 'cert_expires', 'cert_thumbprint', 'setup', 'renew_error', 'renewed_at', 'permissions_version'] as $key) {
        $app[$key] = mitSetting("m365_$key", '');
    }
    return $app;
}

/*
 * ---------- secrets at rest ----------
 */

/**
 * The key secrets are encrypted with: $config_mit_secret_key in config.php - random, made and appended on
 * first use the way the backup key is (functions/backup.php backupEncryptionKey), never stored in the
 * database, so a copy of the database alone opens nothing. Returns '' when config.php cannot be written;
 * mitEncryptSecret then falls back to the older derived key so nothing breaks, and the card says so.
 */
function mitSecretKey() {
    global $config_mit_secret_key;
    if (!empty($config_mit_secret_key)) {
        return hash('sha256', 'mit-secret-v2|' . $config_mit_secret_key, true);
    }
    $config_file = dirname(__DIR__) . '/config.php';
    if (!is_writable($config_file)) {
        return '';
    }
    // Re-read rather than trust the global: another request may have just made one, and two keys would orphan the first
    $existing = @file_get_contents($config_file);
    if ($existing !== false && preg_match('/\$config_mit_secret_key\s*=\s*[\'"]([^\'"]+)[\'"]/', $existing, $match)) {
        $config_mit_secret_key = $match[1];
    } else {
        $key = randomString(48);
        $line = "\n// Mountaineer IT: key for the Microsoft 365 certificate and Outlook sign-ins - keep it with the backup key\n"
            . "\$config_mit_secret_key = '" . $key . "';\n";
        if (@file_put_contents($config_file, $line, FILE_APPEND | LOCK_EX) === false) {
            return '';
        }
        $config_mit_secret_key = $key;
    }
    return hash('sha256', 'mit-secret-v2|' . $config_mit_secret_key, true);
}

/**
 * The older keys, for reading what was encrypted before $config_mit_secret_key existed: derived from
 * $installation_id (which is not secret - it is shown in Admin > Telemetry), and the first Outlook build's.
 * Values found under them are re-encrypted by mitUpgradeSecrets().
 */
function mitSecretLegacyKeys() {
    global $installation_id, $dbpassword;
    $seed = !empty($installation_id) ? $installation_id : (string) ($dbpassword ?? '');
    return [hash('sha256', 'mit-secret|' . $seed, true), hash('sha256', 'mit-outlook|' . ($installation_id ?? '') . '|' . ($dbpassword ?? ''), true)];
}

/**
 * Encrypt a secret for the database: AES-256-GCM with a random 96-bit nonce. "v2:" + base64(nonce, tag,
 * ciphertext) under $config_mit_secret_key - or "v1:" under the legacy key if config.php could not be written.
 */
function mitEncryptSecret($plain) {
    $key = mitSecretKey();
    $version = $key === '' ? 'v1' : 'v2';
    $key = $key === '' ? mitSecretLegacyKeys()[0] : $key;
    $iv = random_bytes(12);
    $cipher = openssl_encrypt((string) $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return "$version:" . base64_encode($iv . $tag . $cipher);
}

/** The secret, or '' when it cannot be read (wrong key, damaged, or not encrypted by mitEncryptSecret). */
function mitDecryptSecret($stored) {
    $stored = (string) $stored;
    $version = substr($stored, 0, 3);
    if ($version !== 'v1:' && $version !== 'v2:') {
        return '';
    }
    $raw = base64_decode(substr($stored, 3));
    if (strlen($raw) <= 28) {
        return '';
    }
    $keys = $version === 'v2:' ? [mitSecretKey()] : mitSecretLegacyKeys();
    foreach (array_filter($keys) as $key) {
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain !== false) {
            return $plain;
        }
    }
    return '';
}

/** Is this an encrypted value (either version)? */
function mitIsEncrypted($stored) {
    return in_array(substr((string) $stored, 0, 3), ['v1:', 'v2:'], true);
}

/**
 * Re-encrypt what is still under a legacy key with $config_mit_secret_key: the app certificate, a pending
 * one, the cached mail token, the NinjaOne API secret and token, and every Outlook sign-in. Cheap when there is
 * nothing to do; run from the Outlook cron job, the cron watch job (once a day) and the mail settings page.
 * Returns how many values were upgraded.
 */
function mitUpgradeSecrets() {
    global $mysqli;
    if (mitSecretKey() === '') {
        return 0;
    }
    $done = 0;
    foreach (['m365_key_pem', 'm365_pending_cert', 'm365_mail_graph_token', 'ninja_client_secret', 'ninja_token'] as $name) {
        $stored = (string) mitSetting($name, '');
        if (strpos($stored, 'v1:') === 0 && ($plain = mitDecryptSecret($stored)) !== '') {
            mitSetSetting($name, mitEncryptSecret($plain));
            $done++;
        }
    }
    try {
        $result = mysqli_query($mysqli, "SELECT user_id, refresh_token, access_token FROM mit_outlook_links WHERE refresh_token LIKE 'v1:%' OR access_token LIKE 'v1:%'");
        while ($result && $row = mysqli_fetch_assoc($result)) {
            $set = [];
            foreach (['refresh_token', 'access_token'] as $column) {
                if (strpos((string) $row[$column], 'v1:') === 0 && ($plain = mitDecryptSecret($row[$column])) !== '') {
                    $set[] = "$column = '" . mysqli_real_escape_string($mysqli, mitEncryptSecret($plain)) . "'";
                }
            }
            if ($set) {
                mysqli_query($mysqli, "UPDATE mit_outlook_links SET " . implode(', ', $set) . " WHERE user_id = " . intval($row['user_id']));
                $done++;
            }
        }
    } catch (Throwable $e) {
        // Database update M004 not run yet - no Outlook sign-ins to upgrade
    }
    return $done;
}

/** The app certificate's private key - kept encrypted (an older build kept it as it was; it is encrypted on first use). */
function mitM365KeyPem() {
    $stored = (string) mitSetting('m365_key_pem', '');
    if ($stored !== '' && !mitIsEncrypted($stored)) {
        mitSetSetting('m365_key_pem', mitEncryptSecret($stored));
        return $stored;
    }
    return mitDecryptSecret($stored);
}

/*
 * ---------- HTTP ----------
 */

/** A request to Microsoft. $body: array = form fields, string = JSON (or as its Content-Type header says). Returns [status, json, body, error]. */
function mitHttp($method, $url, array $headers = [], $body = null, $timeout = 30) {
    if (!mitDevAllows($method, $url)) {
        return ['status' => 0, 'json' => [], 'body' => '', 'error' => MIT_DEV_READ_ONLY];
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if (is_array($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body, '', '&'));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    } elseif ($body === null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');  // Microsoft wants a Content-Length even with nothing to send
    } elseif (is_string($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if (!preg_grep('/^Content-Type:/i', $headers)) {
            $headers[] = 'Content-Type: application/json';
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : [], 'body' => (string) $raw, 'error' => $error];
}

/** A Graph call with a bearer token. $body array is sent as JSON. */
function mitGraph($method, $path, $token, $body = null) {
    return mitHttp($method, MIT_M365_GRAPH . $path, ['Authorization: Bearer ' . $token, 'Accept: application/json'], $body === null ? null : json_encode($body));
}

/** Microsoft's own explanation of a failed call, for the screen. */
function mitM365Error(array $response, $what) {
    $json = $response['json'];
    $message = $json['error_description'] ?? ($json['error']['message'] ?? ($json['error'] ?? ''));
    if (is_array($message)) {
        $message = json_encode($message);
    }
    if ($message === '' && $response['error'] !== '') {
        $message = $response['error'];
    }
    return trim("$what failed" . ($response['status'] ? " (HTTP {$response['status']})" : '') . ($message !== '' ? ": $message" : ''));
}

/*
 * ---------- certificate and signed assertions ----------
 */

function mitBase64Url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** A new self-signed certificate and key: [cert_pem, key_pem, der_base64, thumbprint (x5t), expires 'Y-m-d H:i:s']. */
function mitM365NewCertificate() {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if (!$key) {
        throw new RuntimeException('Could not create a key: ' . openssl_error_string());
    }
    // A minimal OpenSSL config, so the system one's sample defaults (Some-State, Internet Widgits...) stay out of it
    $config_file = tempnam(sys_get_temp_dir(), 'mitcert');
    file_put_contents($config_file, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = ITFlow Mountaineer IT\n");
    $config = ['digest_alg' => 'sha256', 'config' => $config_file];
    $csr = openssl_csr_new(['commonName' => 'ITFlow Mountaineer IT', 'organizationName' => 'Mountaineer IT'], $key, $config);
    $serial = random_int(1, PHP_INT_MAX);
    $cert = $csr ? openssl_csr_sign($csr, null, $key, MIT_M365_CERT_DAYS, $config, $serial) : false;
    @unlink($config_file);
    if (!$cert) {
        throw new RuntimeException('Could not create a certificate: ' . openssl_error_string());
    }
    openssl_x509_export($cert, $cert_pem);
    openssl_pkey_export($key, $key_pem);
    $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $cert_pem));
    $info = openssl_x509_parse($cert);
    return [
        'cert_pem' => $cert_pem,
        'key_pem' => $key_pem,
        'der_base64' => base64_encode($der),
        'thumbprint' => mitBase64Url(sha1($der, true)),
        'expires' => date('Y-m-d H:i:s', $info['validTo_time_t']),
    ];
}

/** A JWT signed (RS256) with the app's current certificate key. */
function mitM365SignedJwt(array $claims, $key_pem = null, $thumbprint = null) {
    $key_pem = $key_pem ?? mitM365KeyPem();
    $thumbprint = $thumbprint ?? mitSetting('m365_cert_thumbprint', '');
    $header = ['alg' => 'RS256', 'typ' => 'JWT', 'x5t' => $thumbprint];
    $input = mitBase64Url(json_encode($header)) . '.' . mitBase64Url(json_encode($claims));
    $key = openssl_pkey_get_private($key_pem);
    if (!$key || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign with the Microsoft 365 certificate.');
    }
    return $input . '.' . mitBase64Url($signature);
}

/** The client assertion that stands in for a client secret on a token request to $token_url. */
function mitM365ClientAssertion($token_url, $client_id) {
    $now = time();
    return mitM365SignedJwt([
        'aud' => $token_url,
        'iss' => $client_id,
        'sub' => $client_id,
        'jti' => bin2hex(random_bytes(16)),
        'nbf' => $now - 60,
        'iat' => $now - 60,
        'exp' => $now + 600,
    ]);
}

/**
 * Hook for every token request ITFlow makes to Microsoft (functions/request.php httpFormPost,
 * admin/oauth_microsoft_mail_callback.php, admin/post/settings_mail.php): when the client secret
 * is our marker and the client is our app, sign in with the certificate instead.
 */
function mitMicrosoftTokenFields($url, array $fields) {
    if (($fields['client_secret'] ?? null) !== MIT_M365_CERT_SECRET) {
        return $fields;
    }
    $login_host = parse_url(MIT_M365_LOGIN, PHP_URL_HOST);
    $client_id = (string) ($fields['client_id'] ?? '');
    // Our two apps (the main one and the calendar-only one) sign with the same certificate
    if (parse_url((string) $url, PHP_URL_HOST) !== $login_host || $client_id === ''
        || !in_array($client_id, array_filter([(string) mitSetting('m365_client_id', ''), (string) mitSetting('m365_cal_client_id', '')]), true)) {
        return $fields;
    }

    // While here: replace the certificate if it is close to running out
    mitM365MaybeRenewCertificate();

    try {
        $assertion = mitM365ClientAssertion((string) $url, $client_id);
    } catch (Throwable $e) {
        // Callers expect an answer from Microsoft, not an exception - it will refuse the marker as a secret
        error_log('Mountaineer IT: could not sign with the Microsoft 365 certificate: ' . $e->getMessage());
        return $fields;
    }
    unset($fields['client_secret']);
    $fields['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    $fields['client_assertion'] = $assertion;
    return $fields;
}

/**
 * Hook for ITFlow's mailbox Connect (admin/post/settings_mail.php): it always asks Microsoft for the
 * consent screen, which only an admin may accept - so a plain mailbox like service@ gets "Need admin
 * approval". Our app is already approved for the whole organization, so just let them pick the account.
 */
function mitM365ConnectPrompt($client_id) {
    $app = mitM365App();
    return ($app && $client_id !== '' && $client_id === $app['client_id']) ? 'select_account' : 'consent';
}

/*
 * ---------- one-time setup: admin signs in with a code ----------
 */

/** Start the sign-in: [user_code, verification_uri, device_code, interval, expires_in] or ['error' => ...]. */
function mitM365DeviceCodeStart() {
    $response = mitHttp('POST', MIT_M365_LOGIN . '/organizations/oauth2/v2.0/devicecode', [], [
        'client_id' => MIT_M365_BOOTSTRAP_CLIENT,
        'scope' => MIT_M365_BOOTSTRAP_SCOPES,
    ]);
    if ($response['status'] !== 200 || empty($response['json']['device_code'])) {
        return ['error' => mitM365Error($response, 'Starting the Microsoft sign-in')];
    }
    return $response['json'];
}

/** Has the admin finished signing in? ['pending' => true], ['token' => ...] or ['error' => ..., 'blocked' => bool]. */
function mitM365DeviceCodePoll($device_code) {
    $response = mitHttp('POST', MIT_M365_LOGIN . '/organizations/oauth2/v2.0/token', [], [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'client_id' => MIT_M365_BOOTSTRAP_CLIENT,
        'device_code' => $device_code,
    ]);
    $error = $response['json']['error'] ?? '';
    if ($error === 'authorization_pending' || $error === 'slow_down') {
        return ['pending' => true, 'slow_down' => $error === 'slow_down'];
    }
    if (!empty($response['json']['access_token'])) {
        return ['token' => $response['json']['access_token']];
    }
    $description = (string) ($response['json']['error_description'] ?? '');
    // AADSTS53003: blocked by Conditional Access (Microsoft's "block device code flow" policy among others)
    $blocked = strpos($description, 'AADSTS53003') !== false || strpos($description, 'AADSTS50199') !== false;
    return ['error' => mitM365Error($response, 'Signing in'), 'blocked' => $blocked];
}

/** Answer an Admin > Settings > Mail setup step in JSON and stop (admin/post/mit_m365.php). */
function mitM365Json(array $data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit();
}

/** The tenant id from an access token. */
function mitM365TokenTenant($token) {
    $parts = explode('.', (string) $token);
    $claims = isset($parts[1]) ? json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) : null;
    return is_array($claims) ? (string) ($claims['tid'] ?? '') : '';
}

/** This server's address, e.g. https://itflow.example.com */
function mitM365BaseUrl() {
    global $config_base_url;
    return (defined('BASE_URL') && !empty(BASE_URL)) ? rtrim((string) BASE_URL, '/') : 'https://' . rtrim((string) $config_base_url, '/');
}

/** The addresses Microsoft may send people back to on this server. */
function mitM365RedirectUris() {
    $base = mitM365BaseUrl();
    return [
        $base . '/admin/oauth_microsoft_mail_callback.php',  // ITFlow's mailbox Connect
        $base . '/agent/user/mit_outlook_callback.php',      // each person's Outlook calendar (Phase 4)
    ];
}

/** A resource's service principal id and its delegated permission ids by name. */
function mitM365ResourceScopes($token, $app_id) {
    $response = mitGraph('GET', "/servicePrincipals?\$filter=" . rawurlencode("appId eq '$app_id'") . "&\$select=id,appId,oauth2PermissionScopes", $token);
    $sp = $response['json']['value'][0] ?? null;
    if ($response['status'] !== 200 || !$sp) {
        throw new RuntimeException(mitM365Error($response, "Looking up Microsoft's permissions"));
    }
    $scopes = [];
    foreach ($sp['oauth2PermissionScopes'] ?? [] as $scope) {
        $scopes[$scope['value']] = $scope['id'];
    }
    return ['id' => $sp['id'], 'scopes' => $scopes];
}

/**
 * Create the app in the tenant with the admin's setup token, and point ITFlow's mail settings
 * at it. Returns the app (mitM365App()) or throws with what went wrong.
 */
function mitM365Provision($token, $created_by) {
    $tenant_id = mitM365TokenTenant($token);
    if ($tenant_id === '') {
        throw new RuntimeException('Could not tell which Microsoft 365 organization that sign-in belongs to.');
    }

    [$access, $graph, $exchange] = mitM365RequiredAccess($token);

    $cert = mitM365NewCertificate();

    // The app itself
    $created = mitGraph('POST', '/applications', $token, [
        'displayName' => MIT_M365_APP_NAME,
        'signInAudience' => 'AzureADMyOrg',
        'notes' => 'Created by ITFlow (Mountaineer IT) for email and Outlook calendars. Signs in with a certificate that ITFlow replaces before it expires.',
        'web' => ['redirectUris' => mitM365RedirectUris()],
        'requiredResourceAccess' => $access,
        'keyCredentials' => [[
            'type' => 'AsymmetricX509Cert',
            'usage' => 'Verify',
            'key' => $cert['der_base64'],
            'displayName' => 'ITFlow certificate ' . date('Y-m-d'),
        ]],
    ]);
    if ($created['status'] !== 201 || empty($created['json']['appId'])) {
        throw new RuntimeException(mitM365Error($created, 'Creating the app'));
    }
    $client_id = $created['json']['appId'];
    $object_id = $created['json']['id'];

    try {
        // Its service principal - a newly created app can take a moment to be visible
        $sp = null;
        for ($attempt = 0; $attempt < 5 && !$sp; $attempt++) {
            if ($attempt) {
                sleep(3);
            }
            $response = mitGraph('POST', '/servicePrincipals', $token, ['appId' => $client_id]);
            if ($response['status'] === 201) {
                $sp = $response['json']['id'];
            }
        }
        if (!$sp) {
            throw new RuntimeException(mitM365Error($response, 'Creating the app\'s service principal'));
        }

        mitM365GrantConsent($token, $sp, $graph, $exchange);
    } catch (Throwable $e) {
        // Nothing was saved here, so take the half-made app back out rather than leave one behind on every retry
        mitGraph('DELETE', '/applications/' . rawurlencode($object_id), $token);
        throw $e;
    }

    mitM365SaveApp($tenant_id, $client_id, $object_id, $cert, $created_by, 'automatic');
    mitSetSetting('m365_permissions_version', MIT_M365_PERMISSIONS_VERSION);
    return mitM365App();
}

/** The app's requiredResourceAccess for MIT_M365_*_SCOPES: [access, graph resource, exchange resource]. */
function mitM365RequiredAccess($token) {
    $graph = mitM365ResourceScopes($token, MIT_M365_GRAPH_APP_ID);
    $exchange = mitM365ResourceScopes($token, MIT_M365_EXCHANGE_APP_ID);
    $access = [];
    foreach ([[MIT_M365_GRAPH_APP_ID, $graph, MIT_M365_GRAPH_SCOPES], [MIT_M365_EXCHANGE_APP_ID, $exchange, MIT_M365_EXCHANGE_SCOPES]] as [$app_id, $resource, $wanted]) {
        $entries = [];
        foreach ($wanted as $scope) {
            if (empty($resource['scopes'][$scope])) {
                throw new RuntimeException("Microsoft did not list the $scope permission in this organization.");
            }
            $entries[] = ['id' => $resource['scopes'][$scope], 'type' => 'Scope'];
        }
        $access[] = ['resourceAppId' => $app_id, 'resourceAccess' => $entries];
    }
    return [$access, $graph, $exchange];
}

/** Admin consent for the whole organization, so nobody is asked to approve it - adds to or replaces earlier consent. */
function mitM365GrantConsent($token, $sp, array $graph, array $exchange) {
    foreach ([[$graph['id'], MIT_M365_GRAPH_SCOPES], [$exchange['id'], MIT_M365_EXCHANGE_SCOPES]] as [$resource_id, $scopes]) {
        mitM365GrantScopes($token, $sp, $resource_id, $scopes);
    }
}

/** Organization-wide consent for one app ($sp) to one resource: exactly these delegated scopes (made or replaced). Throws on failure. */
function mitM365GrantScopes($token, $sp, $resource_id, array $scopes) {
    $existing = mitGraph('GET', '/oauth2PermissionGrants?$filter=' . rawurlencode("clientId eq '$sp' and resourceId eq '$resource_id' and consentType eq 'AllPrincipals'"), $token);
    $grant_id = $existing['json']['value'][0]['id'] ?? '';
    if ($grant_id !== '') {
        $grant = mitGraph('PATCH', '/oauth2PermissionGrants/' . rawurlencode($grant_id), $token, ['scope' => implode(' ', $scopes)]);
        $ok = $grant['status'] === 204 || $grant['status'] === 200;
    } else {
        $grant = mitGraph('POST', '/oauth2PermissionGrants', $token, [
            'clientId' => $sp,
            'consentType' => 'AllPrincipals',
            'resourceId' => $resource_id,
            'scope' => implode(' ', $scopes),
        ]);
        $ok = $grant['status'] === 201;
    }
    if (!$ok) {
        throw new RuntimeException(mitM365Error($grant, 'Approving the app\'s permissions'));
    }
}

/**
 * Bring an app set up earlier up to today's permissions (MIT_M365_PERMISSIONS_VERSION), with an
 * admin's sign-in token: the app's permission list and the organization-wide consent.
 */
function mitM365UpdatePermissions($token) {
    $app = mitM365App();
    if (!$app || $app['object_id'] === '') {
        throw new RuntimeException('There is no Microsoft 365 app to update.');
    }
    if (mitM365TokenTenant($token) !== $app['tenant_id']) {
        throw new RuntimeException('That sign-in belongs to a different Microsoft 365 organization than the app.');
    }
    [$access, $graph, $exchange] = mitM365RequiredAccess($token);

    // The sign-in addresses too, in case ITFlow's address has changed since the app was made
    $patched = mitGraph('PATCH', '/applications/' . rawurlencode($app['object_id']), $token, ['requiredResourceAccess' => $access, 'web' => ['redirectUris' => mitM365RedirectUris()]]);
    if ($patched['status'] !== 204 && $patched['status'] !== 200) {
        throw new RuntimeException(mitM365Error($patched, 'Updating the app\'s permissions'));
    }
    $found = mitGraph('GET', '/servicePrincipals?$filter=' . rawurlencode("appId eq '{$app['client_id']}'") . '&$select=id', $token);
    $sp = $found['json']['value'][0]['id'] ?? '';
    if ($sp === '') {
        throw new RuntimeException(mitM365Error($found, 'Finding the app in your organization'));
    }
    mitM365GrantConsent($token, $sp, $graph, $exchange);

    mitSetSetting('m365_permissions_version', MIT_M365_PERMISSIONS_VERSION);
    mitDeleteSettings('m365_mail_graph_');  // prefix: the token, its expiry and scopes - the next one picks up the new permissions
    return mitM365App();
}

/**
 * Keep the app's details and point ITFlow's Microsoft mail settings at it: the client and tenant, and the
 * certificate marker in place of a client secret. Clears the mailbox's refresh token - the mailbox is
 * connected again under the new app.
 */
function mitM365SaveApp($tenant_id, $client_id, $object_id, array $cert, $created_by, $setup) {
    global $mysqli;
    mitSetSetting('m365_tenant_id', $tenant_id);
    mitSetSetting('m365_client_id', $client_id);
    mitSetSetting('m365_object_id', $object_id);
    mitSetSetting('m365_name', MIT_M365_APP_NAME);
    mitSetSetting('m365_created_at', date('Y-m-d H:i:s'));
    mitSetSetting('m365_created_by', $created_by);
    mitSetSetting('m365_setup', $setup);
    mitM365StoreCertificate($cert);

    $client_id_sql = mysqli_real_escape_string($mysqli, $client_id);
    $tenant_id_sql = mysqli_real_escape_string($mysqli, $tenant_id);
    $marker = mysqli_real_escape_string($mysqli, MIT_M365_CERT_SECRET);
    // A new app means a new mailbox connection - the old refresh token belonged to another app
    mysqli_query($mysqli, "UPDATE settings SET config_mail_oauth_client_id = '$client_id_sql', config_mail_oauth_tenant_id = '$tenant_id_sql',
        config_mail_oauth_client_secret = '$marker', config_mail_oauth_refresh_token = NULL, config_mail_oauth_access_token = NULL,
        config_mail_oauth_access_token_expires_at = NULL WHERE company_id = 1");
}

/** Keep a new certificate: its private key encrypted (mitEncryptSecret), thumbprint and expiry. */
function mitM365StoreCertificate(array $cert) {
    mitSetSetting('m365_cert_pem', $cert['cert_pem']);
    mitSetSetting('m365_key_pem', mitEncryptSecret($cert['key_pem']));
    mitSetSetting('m365_cert_thumbprint', $cert['thumbprint']);
    mitSetSetting('m365_cert_expires', $cert['expires']);
}

/*
 * ---------- manual setup (when the tenant blocks the code sign-in) ----------
 */

/** A certificate waiting to be uploaded to a hand-made app registration - made once, kept until used. */
function mitM365PendingCertificate() {
    $stored = (string) mitSetting('m365_pending_cert', '');
    $pending = json_decode(mitIsEncrypted($stored) ? mitDecryptSecret($stored) : $stored, true);
    if (!is_array($pending) || empty($pending['cert_pem'])) {
        $pending = mitM365NewCertificate();
        mitSetSetting('m365_pending_cert', mitEncryptSecret(json_encode($pending)));
    }
    return $pending;
}

/**
 * Use a hand-made app registration (its tenant, client and object ids) with the pending certificate.
 * The object id is needed to replace the certificate later, and the app may not look it up itself.
 */
function mitM365UseManualApp($tenant_id, $client_id, $object_id, $created_by) {
    $guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    if (!preg_match($guid, $tenant_id) || !preg_match($guid, $client_id) || !preg_match($guid, $object_id)) {
        throw new RuntimeException('The tenant ID, application (client) ID and object ID are all GUIDs like 00000000-0000-0000-0000-000000000000.');
    }
    $cert = mitM365PendingCertificate();

    // Prove the certificate is on that app before switching ITFlow over to it
    $token_url = MIT_M365_LOGIN . '/' . rawurlencode($tenant_id) . '/oauth2/v2.0/token';
    $assertion = mitM365SignedJwt(['aud' => $token_url, 'iss' => $client_id, 'sub' => $client_id, 'jti' => bin2hex(random_bytes(16)),
        'nbf' => time() - 60, 'iat' => time() - 60, 'exp' => time() + 600], $cert['key_pem'], $cert['thumbprint']);
    $response = mitHttp('POST', $token_url, [], [
        'client_id' => $client_id, 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer', 'client_assertion' => $assertion,
    ]);
    if (empty($response['json']['access_token'])) {
        throw new RuntimeException(mitM365Error($response, 'Signing in with the certificate on that app'));
    }
    // The object ID cannot be checked with this token (it has no Graph permissions of its own); a wrong one only shows
    // when the certificate is renewed, as a renewal error on the card. The permissions version is not set here either:
    // it is recorded once the mailbox's token shows the Mail permissions (mitM365MailGraphToken).
    mitM365SaveApp($tenant_id, $client_id, $object_id, $cert, $created_by, 'manual');
    mitDeleteSetting('m365_pending_cert');
    return mitM365App();
}

/*
 * ---------- certificate renewal ----------
 */

/** Replace the certificate when it has MIT_M365_RENEW_DAYS left - tried at most every six hours. */
function mitM365MaybeRenewCertificate() {
    if (mitDevMode()) {
        return; // production renews it; a renewal from here would change production's app
    }
    $expires = mitSetting('m365_cert_expires', '');
    if ($expires === '' || strtotime($expires) - time() > MIT_M365_RENEW_DAYS * 86400) {
        return;
    }
    global $mysqli;
    // One renewal at a time - two cron jobs in the same minute would each add a certificate
    $got = mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('mit_m365_renew', 0)"));
    if (!$got || intval($got[0]) !== 1) {
        return;
    }
    try {
        $last_try = mitSetting('m365_renew_tried_at', '');
        if ($last_try !== '' && time() - strtotime($last_try) < 6 * 3600) {
            return;
        }
        mitSetSetting('m365_renew_tried_at', date('Y-m-d H:i:s'));
        mitM365RenewCertificate();
    } catch (Throwable $e) {
        mitSetSetting('m365_renew_error', $e->getMessage());
        error_log('Mountaineer IT: Microsoft 365 certificate renewal failed: ' . $e->getMessage());
    } finally {
        mysqli_query($mysqli, "DO RELEASE_LOCK('mit_m365_renew')");
    }
}

/** The calendar-only app, or null while Outlook sign-ins still go through the main app. */
function mitM365CalendarApp() {
    $client_id = (string) mitSetting('m365_cal_client_id', '');
    return $client_id === '' ? null : ['client_id' => $client_id, 'object_id' => (string) mitSetting('m365_cal_object_id', ''),
        'permissions_version' => intval(mitSetting('m365_cal_permissions_version', 1))];
}

/** The calendar app's requiredResourceAccess (MIT_M365_CAL_SCOPES on Graph): [access, graph resource]. */
function mitM365CalendarAccess($token) {
    $graph = mitM365ResourceScopes($token, MIT_M365_GRAPH_APP_ID);
    $entries = [];
    foreach (MIT_M365_CAL_SCOPES as $scope) {
        if (empty($graph['scopes'][$scope])) {
            throw new RuntimeException("Microsoft did not list the $scope permission in this organization.");
        }
        $entries[] = ['id' => $graph['scopes'][$scope], 'type' => 'Scope'];
    }
    return [[['resourceAppId' => MIT_M365_GRAPH_APP_ID, 'resourceAccess' => $entries]], $graph];
}

/**
 * Bring the calendar-only app up to MIT_M365_CAL_SCOPES (MIT_M365_CAL_PERMISSIONS_VERSION) with an admin's sign-in token:
 * its permission list, sign-in address and the organization-wide consent. Existing Outlook connections keep working.
 */
function mitM365UpdateCalendarPermissions($token) {
    $app = mitM365App();
    $calendar = mitM365CalendarApp();
    if (!$app || !$calendar || $calendar['object_id'] === '') {
        throw new RuntimeException('There is no calendar-only app to update.');
    }
    if (mitM365TokenTenant($token) !== $app['tenant_id']) {
        throw new RuntimeException('That sign-in belongs to a different Microsoft 365 organization than ITFlow\'s app.');
    }
    [$access, $graph] = mitM365CalendarAccess($token);
    $patched = mitGraph('PATCH', '/applications/' . rawurlencode($calendar['object_id']), $token, ['requiredResourceAccess' => $access, 'web' => ['redirectUris' => [mitM365RedirectUris()[1]]]]);
    if ($patched['status'] !== 204 && $patched['status'] !== 200) {
        throw new RuntimeException(mitM365Error($patched, 'Updating the calendar app\'s permissions'));
    }
    $found = mitGraph('GET', '/servicePrincipals?$filter=' . rawurlencode("appId eq '{$calendar['client_id']}'") . '&$select=id', $token);
    $sp = $found['json']['value'][0]['id'] ?? '';
    if ($sp === '') {
        throw new RuntimeException(mitM365Error($found, 'Finding the calendar app in your organization'));
    }
    mitM365GrantScopes($token, $sp, $graph['id'], MIT_M365_CAL_SCOPES);
    mitSetSetting('m365_cal_permissions_version', MIT_M365_CAL_PERMISSIONS_VERSION);
    return mitM365CalendarApp();
}

/** The current certificate as Graph wants it in keyCredentials (base64 DER). */
function mitM365CertDerBase64() {
    $pem = (string) mitSetting('m365_cert_pem', '');
    return preg_replace('/-----[^-]+-----|\s+/', '', $pem);
}

/**
 * Make the calendar-only app with an admin's setup token (Admin > Settings > Mail, the main app set up first): only
 * User.Read and Calendars.ReadWrite (plus the sign-in scopes offline_access openid profile email), approved for the
 * organization, signing in with the main app's certificate. Every Outlook connection made through the main app is set to
 * Reconnect. Returns the app or throws.
 */
function mitM365ProvisionCalendar($token) {
    global $mysqli;
    $app = mitM365App();
    if (!$app) {
        throw new RuntimeException('Set up the main Microsoft 365 app first.');
    }
    if (mitM365CalendarApp()) {
        throw new RuntimeException('The calendar-only app is already set up.');
    }
    if (mitM365TokenTenant($token) !== $app['tenant_id']) {
        throw new RuntimeException('That sign-in belongs to a different Microsoft 365 organization than ITFlow\'s app.');
    }
    $der = mitM365CertDerBase64();
    if ($der === '') {
        throw new RuntimeException('ITFlow has no certificate for its Microsoft 365 app - set the app up again first.');
    }
    [$access, $graph] = mitM365CalendarAccess($token);
    $created = mitGraph('POST', '/applications', $token, [
        'displayName' => MIT_M365_CAL_APP_NAME,
        'signInAudience' => 'AzureADMyOrg',
        'notes' => 'Created by ITFlow (Mountaineer IT) for staff Outlook calendars only - no mail. Signs in with the same certificate as the email app.',
        'web' => ['redirectUris' => [mitM365RedirectUris()[1]]],
        'requiredResourceAccess' => $access,
        'keyCredentials' => [['type' => 'AsymmetricX509Cert', 'usage' => 'Verify', 'key' => $der, 'displayName' => 'ITFlow certificate ' . date('Y-m-d')]],
    ]);
    if ($created['status'] !== 201 || empty($created['json']['appId'])) {
        throw new RuntimeException(mitM365Error($created, 'Creating the calendar app'));
    }
    $client_id = $created['json']['appId'];
    $object_id = $created['json']['id'];
    try {
        $sp = null;
        for ($attempt = 0; $attempt < 5 && !$sp; $attempt++) {
            if ($attempt) {
                sleep(3);
            }
            $response = mitGraph('POST', '/servicePrincipals', $token, ['appId' => $client_id]);
            if ($response['status'] === 201) {
                $sp = $response['json']['id'];
            }
        }
        if (!$sp) {
            throw new RuntimeException(mitM365Error($response, 'Creating the calendar app\'s service principal'));
        }
        mitM365GrantScopes($token, $sp, $graph['id'], MIT_M365_CAL_SCOPES);
    } catch (Throwable $e) {
        mitGraph('DELETE', '/applications/' . rawurlencode($object_id), $token);
        throw $e;
    }
    mitSetSetting('m365_cal_client_id', $client_id);
    mitSetSetting('m365_cal_object_id', $object_id);
    mitSetSetting('m365_cal_permissions_version', MIT_M365_CAL_PERMISSIONS_VERSION);
    // Sign-ins made through the main app can still reach mail - they go, and each person reconnects once
    try {
        mysqli_query($mysqli, "UPDATE mit_outlook_links SET refresh_token = '', access_token = '', access_expires_at = NULL,
            last_error = 'ITFlow now connects Outlook calendars through a calendar-only app - click Reconnect once.'");
    } catch (Throwable $e) {
        // Outlook tables not there yet
    }
    return mitM365CalendarApp();
}

/**
 * Give the app a new certificate. The app adds the key itself (Graph application addKey, which an
 * app may always do for its own keys, proven with a token signed by its current key).
 */
function mitM365RenewCertificate() {
    if (mitDevMode()) {
        throw new RuntimeException('Development mode - the Microsoft 365 certificate is renewed by production only.');
    }
    $app = mitM365App();
    if (!$app || $app['object_id'] === '') {
        throw new RuntimeException('There is no Microsoft 365 app to renew.');
    }
    $token_url = MIT_M365_LOGIN . '/' . rawurlencode($app['tenant_id']) . '/oauth2/v2.0/token';
    $response = mitHttp('POST', $token_url, [], [
        'client_id' => $app['client_id'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => mitM365ClientAssertion($token_url, $app['client_id']),
    ]);
    if (empty($response['json']['access_token'])) {
        throw new RuntimeException(mitM365Error($response, 'Signing in to renew the certificate'));
    }

    $cert = mitM365NewCertificate();

    // The calendar-only app shares the certificate: it gets the new key first (with its own token, signed by the current
    // key), so a failure there leaves both apps on the old certificate rather than splitting them
    $calendar = mitM365CalendarApp();
    if ($calendar && $calendar['object_id'] !== '') {
        $cal_token = mitHttp('POST', $token_url, [], [
            'client_id' => $calendar['client_id'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => mitM365ClientAssertion($token_url, $calendar['client_id']),
        ]);
        if (empty($cal_token['json']['access_token'])) {
            throw new RuntimeException(mitM365Error($cal_token, 'Signing in to renew the calendar app\'s certificate'));
        }
        $cal_added = mitGraph('POST', '/applications/' . rawurlencode($calendar['object_id']) . '/addKey', $cal_token['json']['access_token'], [
            'keyCredential' => ['type' => 'AsymmetricX509Cert', 'usage' => 'Verify', 'key' => $cert['der_base64'], 'displayName' => 'ITFlow certificate ' . date('Y-m-d')],
            'passwordCredential' => null,
            'proof' => mitM365SignedJwt(['aud' => '00000002-0000-0000-c000-000000000000', 'iss' => $calendar['object_id'], 'nbf' => time() - 60, 'exp' => time() + 600]),
        ]);
        if ($cal_added['status'] !== 200) {
            throw new RuntimeException(mitM365Error($cal_added, 'Adding the new certificate to the calendar app'));
        }
    }

    $proof = mitM365SignedJwt(['aud' => '00000002-0000-0000-c000-000000000000', 'iss' => $app['object_id'],
        'nbf' => time() - 60, 'exp' => time() + 600]);
    $added = mitGraph('POST', '/applications/' . rawurlencode($app['object_id']) . '/addKey', $response['json']['access_token'], [
        'keyCredential' => ['type' => 'AsymmetricX509Cert', 'usage' => 'Verify', 'key' => $cert['der_base64'], 'displayName' => 'ITFlow certificate ' . date('Y-m-d')],
        'passwordCredential' => null,
        'proof' => $proof,
    ]);
    if ($added['status'] !== 200) {
        throw new RuntimeException(mitM365Error($added, 'Adding the new certificate'));
    }
    $old_keys = [[$app['object_id'], (string) mitSetting('m365_cert_key_id', ''), $response['json']['access_token']]];
    if (!empty($cal_added)) {
        $old_keys[] = [$calendar['object_id'], (string) mitSetting('m365_cal_cert_key_id', ''), $cal_token['json']['access_token']];
    }
    mitM365StoreCertificate($cert);
    mitSetSetting('m365_renewed_at', date('Y-m-d H:i:s'));
    mitDeleteSetting('m365_renew_error');
    mitSetSetting('m365_cert_key_id', (string) ($added['json']['keyId'] ?? ''));
    if (!empty($cal_added)) {
        mitSetSetting('m365_cal_cert_key_id', (string) ($cal_added['json']['keyId'] ?? ''));
    }
    // The old certificate comes off the apps (proven with the new one), so expired keys don't pile up there. Best effort:
    // a key left behind does no harm, it just runs out.
    foreach ($old_keys as [$object_id, $key_id, $token]) {
        if ($key_id === '') {
            continue;  // made before ITFlow kept key ids - left to run out
        }
        $removed = mitGraph('POST', '/applications/' . rawurlencode($object_id) . '/removeKey', $token, [
            'keyId' => $key_id,
            'proof' => mitM365SignedJwt(['aud' => '00000002-0000-0000-c000-000000000000', 'iss' => $object_id, 'nbf' => time() - 60, 'exp' => time() + 600]),
        ]);
        if ($removed['status'] !== 204 && $removed['status'] !== 200) {
            error_log('Mountaineer IT: could not take the old certificate off the Microsoft 365 app - ' . mitM365Error($removed, 'removeKey'));
        }
    }
}

/**
 * Forget the app here, stop ITFlow's mail using it, and drop every Outlook calendar connection made through it
 * (their events stay in Outlook until people delete them). The app registration itself stays in the tenant.
 */
function mitM365Forget() {
    global $mysqli;
    $client_id = mysqli_real_escape_string($mysqli, (string) mitSetting('m365_client_id', ''));
    mysqli_query($mysqli, "UPDATE settings SET config_mail_oauth_client_secret = NULL, config_mail_oauth_refresh_token = NULL,
        config_mail_oauth_access_token = NULL, config_mail_oauth_access_token_expires_at = NULL
        WHERE company_id = 1 AND config_mail_oauth_client_id = '$client_id'");
    mitDeleteSettings('m365_');
    // Outlook sign-ins were for this app - they are no use without it
    try {
        mysqli_query($mysqli, "DELETE FROM mit_outlook_links");
        mysqli_query($mysqli, "DELETE FROM mit_outlook_events");
        mysqli_query($mysqli, "DELETE FROM mit_outlook_busy");
    } catch (Throwable $e) {
        // Outlook tables not there yet (database updates M004/M005)
    }
}

/*
 * ---------- mail through Microsoft Graph ----------
 */

/**
 * A Graph token for the connected mailbox that carries $permission (Mail.Send, Mail.ReadWrite), or
 * null when ITFlow's mail is not on our app or the app does not have that permission yet - the
 * caller then stays on SMTP/IMAP. Throws when the mailbox is on our app but Microsoft refuses.
 */
function mitM365MailGraphToken($permission) {
    global $mysqli;
    $app = mitM365App();
    if (!$app) {
        return null;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_mail_oauth_client_id, config_mail_oauth_client_secret, config_mail_oauth_tenant_id,
        config_mail_oauth_refresh_token FROM settings WHERE company_id = 1"));
    if (!$row || $row['config_mail_oauth_client_id'] !== $app['client_id'] || $row['config_mail_oauth_client_secret'] !== MIT_M365_CERT_SECRET
        || empty($row['config_mail_oauth_refresh_token'])) {
        return null;
    }

    $token = mitDecryptSecret(mitSetting('m365_mail_graph_token', ''));  // '' for an older, unencrypted copy - a new one is fetched
    $expires = mitSetting('m365_mail_graph_expires', '');
    // A token for the refresh token on file - a newly connected mailbox gets a new one
    $for = sha1($row['config_mail_oauth_refresh_token']);
    if ($token === '' || $expires === '' || strtotime($expires) - 120 <= time() || mitSetting('m365_mail_graph_for', '') !== $for) {
        // The mailbox's refresh token is good for Graph as well as Exchange
        $token_url = MIT_M365_LOGIN . '/' . rawurlencode($row['config_mail_oauth_tenant_id']) . '/oauth2/v2.0/token';
        $response = mitHttp('POST', $token_url, [], mitMicrosoftTokenFields($token_url, [
            'client_id' => $app['client_id'],
            'client_secret' => MIT_M365_CERT_SECRET,
            'grant_type' => 'refresh_token',
            'refresh_token' => $row['config_mail_oauth_refresh_token'],
            'scope' => 'https://graph.microsoft.com/.default offline_access',
        ]));
        if (empty($response['json']['access_token'])) {
            throw new RuntimeException(mitM365Error($response, 'Signing in to Microsoft 365 as the mailbox'));
        }
        $token = $response['json']['access_token'];
        mitSetSetting('m365_mail_graph_token', mitEncryptSecret($token));
        mitSetSetting('m365_mail_graph_expires', date('Y-m-d H:i:s', time() + intval($response['json']['expires_in'] ?? 3600)));
        mitSetSetting('m365_mail_graph_scopes', (string) ($response['json']['scope'] ?? ''));
        if (!empty($response['json']['refresh_token'])) {
            $for = sha1($response['json']['refresh_token']);
            $refresh = mysqli_real_escape_string($mysqli, $response['json']['refresh_token']);
            mysqli_query($mysqli, "UPDATE settings SET config_mail_oauth_refresh_token = '$refresh' WHERE company_id = 1");
        }
        mitSetSetting('m365_mail_graph_for', $for);
    }

    $scopes = mitM365MailGraphScopes();
    if (in_array('Mail.Send', $scopes, true) && in_array('Mail.ReadWrite', $scopes, true) && intval($app['permissions_version']) !== MIT_M365_PERMISSIONS_VERSION) {
        // Permissions added by hand in Entra count as an update too
        mitSetSetting('m365_permissions_version', MIT_M365_PERMISSIONS_VERSION);
    }
    return in_array($permission, $scopes, true) ? $token : null;
}

/** The Graph permissions on the mailbox's last token, without the resource prefix. */
function mitM365MailGraphScopes() {
    $scopes = [];
    foreach (preg_split('/\s+/', trim((string) mitSetting('m365_mail_graph_scopes', ''))) as $scope) {
        if ($scope !== '') {
            $scopes[] = preg_replace('#^https://graph\.microsoft\.com/#i', '', $scope);
        }
    }
    return $scopes;
}

/** A message bigger than this goes to Graph as a draft with its attachments uploaded, not in one request (Graph takes 4 MB). */
const MIT_M365_MIME_LIMIT = 3000000;
/** Upload-session chunk size - Microsoft wants a multiple of 320 KiB. */
const MIT_M365_UPLOAD_CHUNK = 327680 * 12;

/**
 * Hook in cron/mail_queue.php: does Graph send ITFlow's mail? (Then the SMTP sign-in is skipped.)
 * Throws PHPMailer's exception when the mailbox is on our app but Microsoft refuses the sign-in,
 * so the queue records why instead of quietly trying SMTP.
 */
function mitM365GraphSendReady() {
    try {
        return mitM365MailGraphToken('Mail.Send') !== null;
    } catch (Throwable $e) {
        throw new PHPMailer\PHPMailer\Exception($e->getMessage());
    }
}

/**
 * Hook in cron/mail_queue.php: send a message PHPMailer has put together through Graph - as its finished
 * MIME (attachments and calendar invites as built), or, when too big for one request, as a draft with
 * its attachments uploaded. Throws PHPMailer's exception on failure.
 */
function mitM365GraphSendMail($mail) {
    $GLOBALS['mit_dev_sending_redirected'] = mitDevSendingRedirected($mail); // development mode: only to the test inbox
    try {
        $token = mitM365MailGraphToken('Mail.Send');
        if (!$token) {
            throw new RuntimeException('ITFlow\'s Microsoft 365 app cannot send for this mailbox (Mail.Send).');
        }
        $mail->preSend();
        $mime = $mail->getSentMIMEMessage();
        if (strlen($mime) > MIT_M365_MIME_LIMIT) {
            mitM365GraphSendLarge($token, $mail);
            return true;
        }
        $response = mitHttp('POST', MIT_M365_GRAPH . '/me/sendMail', ['Authorization: Bearer ' . $token, 'Content-Type: text/plain'],
            base64_encode($mime), 120);
        if ($response['status'] !== 202) {
            throw new RuntimeException(mitM365Error($response, 'Sending through Microsoft 365'));
        }
        return true;
    } catch (PHPMailer\PHPMailer\Exception $e) {
        throw $e;
    } catch (Throwable $e) {
        throw new PHPMailer\PHPMailer\Exception($e->getMessage());
    } finally {
        $GLOBALS['mit_dev_sending_redirected'] = false;
    }
}

/** A big message: a draft, its attachments (uploaded in chunks when large), then send. */
function mitM365GraphSendLarge($token, $mail) {
    $people = function (array $list) {
        $out = [];
        foreach ($list as $person) {
            $out[] = ['emailAddress' => ['address' => $person[0], 'name' => $person[1] ?? '']];
        }
        return $out;
    };
    $draft = mitGraph('POST', '/me/messages', $token, [
        'subject' => $mail->Subject,
        'body' => ['contentType' => $mail->ContentType === 'text/plain' ? 'Text' : 'HTML', 'content' => $mail->Body],
        'from' => ['emailAddress' => ['address' => $mail->From, 'name' => $mail->FromName]],
        'toRecipients' => $people($mail->getToAddresses()),
        'ccRecipients' => $people($mail->getCcAddresses()),
        'replyTo' => $people(array_values($mail->getReplyToAddresses())),
    ]);
    if ($draft['status'] !== 201 || empty($draft['json']['id'])) {
        throw new RuntimeException(mitM365Error($draft, 'Sending through Microsoft 365 (creating the message)'));
    }
    $message = '/me/messages/' . rawurlencode($draft['json']['id']);
    $attachments = $mail->getAttachments();
    if (!empty($mail->Ical)) {
        $attachments[] = [$mail->Ical, 'invite.ics', 'invite.ics', 'base64', 'text/calendar', true, 'attachment', 'invite.ics'];
    }
    try {
        foreach ($attachments as $attachment) {
            $content = $attachment[5] ? $attachment[0] : (string) @file_get_contents($attachment[0]);
            $item = ['name' => $attachment[2], 'contentType' => $attachment[4] ?: 'application/octet-stream',
                'isInline' => $attachment[6] === 'inline', 'contentId' => $attachment[7]];
            if (strlen($content) < MIT_M365_MIME_LIMIT) {
                $added = mitGraph('POST', "$message/attachments", $token, ['@odata.type' => '#microsoft.graph.fileAttachment',
                    'contentBytes' => base64_encode($content)] + $item);
                if ($added['status'] !== 201) {
                    throw new RuntimeException(mitM365Error($added, 'Sending through Microsoft 365 (attaching ' . $attachment[2] . ')'));
                }
                continue;
            }
            $session = mitGraph('POST', "$message/attachments/createUploadSession", $token,
                ['AttachmentItem' => ['attachmentType' => 'file', 'size' => strlen($content)] + $item]);
            $upload_url = $session['json']['uploadUrl'] ?? '';
            if ($session['status'] !== 201 || $upload_url === '') {
                throw new RuntimeException(mitM365Error($session, 'Sending through Microsoft 365 (uploading ' . $attachment[2] . ')'));
            }
            $total = strlen($content);
            for ($offset = 0; $offset < $total; $offset += MIT_M365_UPLOAD_CHUNK) {
                $chunk = substr($content, $offset, MIT_M365_UPLOAD_CHUNK);
                // The upload address carries its own authorisation - no bearer token
                $put = mitHttp('PUT', $upload_url, ['Content-Type: application/octet-stream',
                    'Content-Range: bytes ' . $offset . '-' . ($offset + strlen($chunk) - 1) . '/' . $total], $chunk, 300);
                if ($put['status'] !== 200 && $put['status'] !== 201) {
                    throw new RuntimeException(mitM365Error($put, 'Sending through Microsoft 365 (uploading ' . $attachment[2] . ')'));
                }
            }
        }
        $sent = mitGraph('POST', "$message/send", $token);
        if ($sent['status'] !== 202) {
            throw new RuntimeException(mitM365Error($sent, 'Sending through Microsoft 365'));
        }
    } catch (Throwable $e) {
        mitGraph('DELETE', $message, $token);  // no half-built draft left in the mailbox
        throw $e;
    }
}

/**
 * Hook for Admin > Settings > Mail > Tests "Test IMAP": when the parser reads the mailbox through
 * Graph, test that instead. ['ok' => bool, 'message' => ...], or null to run ITFlow's IMAP test.
 */
function mitM365GraphMailboxTest($username) {
    try {
        $token = mitM365MailGraphToken('Mail.ReadWrite');
        if (!$token) {
            return null;
        }
        $base = trim((string) $username) !== '' ? '/users/' . rawurlencode(trim($username)) : '/me';
        $inbox = mitGraph('GET', "$base/mailFolders/inbox?\$select=unreadItemCount,totalItemCount", $token);
        if ($inbox['status'] !== 200) {
            throw new RuntimeException(mitM365Error($inbox, 'Opening the Inbox of ' . ($username ?: 'the mailbox')));
        }
        return ['ok' => true, 'message' => 'Connected through Microsoft Graph to ' . ($username ?: 'the mailbox') . ' - '
            . intval($inbox['json']['unreadItemCount'] ?? 0) . ' unread of ' . intval($inbox['json']['totalItemCount'] ?? 0) . ' in the Inbox.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Microsoft Graph mailbox test failed: ' . $e->getMessage()];
    }
}

/**
 * Hook for the OAuth tab's save and Connect (admin/post/settings_mail.php): while the client is our
 * app, its secret stays the certificate marker - a browser filling in an old saved secret, or someone
 * pasting one, would otherwise quietly stop it signing in.
 */
function mitM365KeepCertificateSecret($client_id, $secret) {
    $app = mitM365App();
    return ($app && trim((string) $client_id) === $app['client_id']) ? MIT_M365_CERT_SECRET : (string) $secret;
}

/** Hook in cron/ticket_email_parser.php: will Graph read the mailbox? (Then the IMAP sign-in is skipped.) */
function mitM365GraphMailboxReady() {
    try {
        return mitM365MailGraphToken('Mail.ReadWrite') !== null;
    } catch (Throwable $e) {
        return true;  // mitM365GraphMailbox() logs Microsoft's reason and stops the run
    }
}

/**
 * Hook in cron/ticket_email_parser.php: the mailbox as an ImapEngine-like object read through Graph,
 * or null when it stays on IMAP. $username is the IMAP username - the signed-in mailbox or a shared one.
 */
function mitM365GraphMailbox($provider, $username) {
    if ($provider !== 'microsoft_oauth') {
        return null;
    }
    try {
        $token = mitM365MailGraphToken('Mail.ReadWrite');
    } catch (Throwable $e) {
        logApp("Cron-Email-Parser", "error", "Microsoft 365: " . $e->getMessage());
        cronJobStop('', 1);
    }
    if (!$token) {
        return null;
    }
    require_once __DIR__ . '/mit_m365_mailbox.php';
    return new MitGraphMailbox($token, (string) $username);
}

// Each person's Outlook calendar, on the same app
require_once __DIR__ . '/mit_outlook.php';
