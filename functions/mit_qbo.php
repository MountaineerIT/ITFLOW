<?php

/*
 * Mountaineer IT - QuickBooks Online. ITFlow keeps making the invoices; QuickBooks Online emails them and takes the payments.
 * An invoice is approved in ITFlow (functions/mit_qbo_billing.php), gathered into a batch, and the batch is sent to QuickBooks.
 * Once in a batch an invoice is locked in ITFlow; payments recorded in QuickBooks come back as ITFlow payments (cron job
 * mit_qbo_sync, hourly), so balances, the client portal and reports stay right.
 *
 * This file: the connection (Intuit sign-in - OAuth 2.0, authorization code; tokens encrypted with mitEncryptSecret), the API
 * (v3, JSON), the mappings (ITFlow client / product / tax -> QuickBooks customer / item / tax code, tables M031) and the
 * QuickBooks invoice an ITFlow invoice becomes. Settings in mit_settings, qbo_*:
 *   qbo_client_id, qbo_client_secret (the Intuit app, from developer.intuit.com), qbo_env (sandbox | production),
 *   qbo_realm_id, qbo_company_name, qbo_access_token, qbo_access_expires, qbo_refresh_token, qbo_refresh_expires,
 *   qbo_connected_by, qbo_connected_at, qbo_tax_style (us = lines TAX / NON and one tax code for the invoice; line = a tax code
 *   on each line), qbo_no_tax_code (line style: the code for untaxed lines), qbo_fallback_item (the item for lines with no
 *   matching product), qbo_payment_account (the ITFlow account payments from QuickBooks are recorded in), qbo_email_default,
 *   qbo_create_customers, qbo_last_sync / qbo_last_error.
 * A test install can point at a stand-in with MIT_QBO_AUTH_URL / MIT_QBO_TOKEN_URL / MIT_QBO_API_BASE in config.php (never on
 * a real one).
 */

require_once __DIR__ . '/mit_qbo_billing.php';

const MIT_QBO_SCOPE = 'com.intuit.quickbooks.accounting';
const MIT_QBO_MINOR_VERSION = 75;

/** Has M031 run? (one query per request) */
function mitQboReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_qbo_batch_invoices'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The settings, with defaults. */
function mitQboSettings() {
    $env = mitSetting('qbo_env', 'production') === 'sandbox' ? 'sandbox' : 'production';
    return [
        'client_id' => (string) mitSetting('qbo_client_id', ''),
        'has_secret' => (string) mitSetting('qbo_client_secret', '') !== '',
        'env' => $env,
        'realm_id' => (string) mitSetting('qbo_realm_id', ''),
        'company_name' => (string) mitSetting('qbo_company_name', ''),
        'connected_at' => (string) mitSetting('qbo_connected_at', ''),
        'connected_by' => intval(mitSetting('qbo_connected_by', 0)),
        'refresh_expires' => intval(mitSetting('qbo_refresh_expires', 0)),
        'tax_style' => mitSetting('qbo_tax_style', 'us') === 'line' ? 'line' : 'us',
        'no_tax_code' => (string) mitSetting('qbo_no_tax_code', ''),
        'fallback_item' => (string) mitSetting('qbo_fallback_item', ''),
        'payment_account' => intval(mitSetting('qbo_payment_account', 0)),
        'email_default' => mitSetting('qbo_email_default', '1') === '1',
        'create_customers' => mitSetting('qbo_create_customers', '1') === '1',
        'last_sync' => (string) mitSetting('qbo_last_sync', ''),
        'last_error' => (string) mitSetting('qbo_last_error', ''),
        'auth_url' => defined('MIT_QBO_AUTH_URL') ? MIT_QBO_AUTH_URL : 'https://appcenter.intuit.com/connect/oauth2',
        'token_url' => defined('MIT_QBO_TOKEN_URL') ? MIT_QBO_TOKEN_URL : 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        'revoke_url' => defined('MIT_QBO_REVOKE_URL') ? MIT_QBO_REVOKE_URL : 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke',
        'api_base' => defined('MIT_QBO_API_BASE') ? rtrim(MIT_QBO_API_BASE, '/')
            : ($env === 'sandbox' ? 'https://sandbox-quickbooks.api.intuit.com' : 'https://quickbooks.api.intuit.com'),
    ];
}

/** Is a QuickBooks company connected? When it is, QuickBooks emails the invoices and takes the payments (not ITFlow). */
function mitQboConnected() {
    static $connected = null;
    if ($connected === null) {
        $connected = mitQboReady() && (string) mitSetting('qbo_realm_id', '') !== '' && (string) mitSetting('qbo_refresh_token', '') !== '';
    }
    return $connected;
}

/** The address Intuit sends people back to after signing in - add it to the Intuit app's Redirect URIs. */
function mitQboRedirectUri() {
    return mitM365BaseUrl() . '/admin/mit_qbo_callback.php';
}

/** Intuit's sign-in page for connecting a company. */
function mitQboAuthorizeUrl($state) {
    $s = mitQboSettings();
    return $s['auth_url'] . '?' . http_build_query([
        'client_id' => $s['client_id'],
        'response_type' => 'code',
        'scope' => MIT_QBO_SCOPE,
        'redirect_uri' => mitQboRedirectUri(),
        'state' => $state,
    ], '', '&', PHP_QUERY_RFC3986);
}

/**
 * Development mode (functions/mit_dev_mode.php): QuickBooks Online works only with an Intuit sandbox company connected on this
 * server (`qbo_dev_realm`, set by mitQboConnect in development mode) - never the real books whose connection came with
 * production's backup. Returns '' when the call may go, else why not. $connecting: signing in to a company.
 */
function mitQboDevProblem($connecting = false) {
    if (!mitDevMode()) {
        return '';
    }
    $s = mitQboSettings();
    if ($s['env'] !== 'sandbox') {
        return 'Development mode - QuickBooks Online works only with an Intuit sandbox company on this server: disconnect, set Environment to Sandbox and connect your sandbox company.';
    }
    if (!$connecting && ($s['realm_id'] === '' || (string) mitSetting('qbo_dev_realm', '') !== $s['realm_id'])) {
        return 'Development mode - connect your QuickBooks sandbox company on this server first (the connection that came with production\'s backup is not used here).';
    }
    return '';
}

/** A request to Intuit that mitQboDevProblem allowed - let through development mode's read-only gate (mitDevAllows). */
function mitQboHttp(...$args) {
    $GLOBALS['mit_dev_qbo_ok'] = true;
    try {
        return mitHttp(...$args);
    } finally {
        $GLOBALS['mit_dev_qbo_ok'] = false;
    }
}

/** A call to Intuit's token endpoint (client id + secret as Basic auth). Returns the decoded answer; throws on failure. */
function mitQboTokenCall(array $fields) {
    $problem = mitQboDevProblem(($fields['grant_type'] ?? '') === 'authorization_code');
    if ($problem !== '') {
        throw new RuntimeException($problem);
    }
    $s = mitQboSettings();
    $secret = mitDecryptSecret((string) mitSetting('qbo_client_secret', ''));
    if ($s['client_id'] === '' || $secret === '') {
        throw new RuntimeException('QuickBooks Online is not set up - add the Intuit app\'s client ID and secret first.');
    }
    $response = mitQboHttp('POST', $s['token_url'], [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($s['client_id'] . ':' . $secret),
    ], $fields);
    if ($response['status'] !== 200 || empty($response['json']['access_token'])) {
        $why = $response['json']['error_description'] ?? ($response['json']['error'] ?? ($response['error'] ?: 'HTTP ' . $response['status']));
        throw new RuntimeException('Intuit refused: ' . mb_substr((string) $why, 0, 200));
    }
    return $response['json'];
}

/** Keep a token answer (the refresh token changes now and then - always keep the newest). */
function mitQboStoreTokens(array $tokens) {
    mitSetSetting('qbo_access_token', mitEncryptSecret($tokens['access_token']));
    mitSetSetting('qbo_access_expires', time() + max(60, intval($tokens['expires_in'] ?? 3600)));
    if (!empty($tokens['refresh_token'])) {
        mitSetSetting('qbo_refresh_token', mitEncryptSecret($tokens['refresh_token']));
        mitSetSetting('qbo_refresh_expires', time() + max(86400, intval($tokens['x_refresh_token_expires_in'] ?? 8640000)));
    }
}

/** Back from Intuit with a code: keep the tokens and the company's name. Throws with what went wrong. */
function mitQboConnect($code, $realm_id) {
    global $session_user_id;
    $realm_id = preg_replace('/[^0-9]/', '', (string) $realm_id);
    if ($code === '' || $realm_id === '') {
        throw new RuntimeException('Intuit did not say which company was connected.');
    }
    // Another company than the one the mappings and batches belong to (e.g. sandbox, then production): its customer, item and
    // invoice ids mean nothing here - refused while invoices are in QuickBooks batches, else the old mappings are cleared
    $mapped_realm = (string) mitSetting('qbo_mapped_realm', '');
    $cleared = false;
    if ($mapped_realm !== '' && $mapped_realm !== $realm_id) {
        global $mysqli;
        if (mitDevMode()) {
            // Development mode: the batches came with production's backup and belong to the real company - this copy forgets them
            $GLOBALS['mit_qbo_dev_forget_batches'] = true;
        } elseif (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_qbo_batch_invoices WHERE entry_status != 'removed' LIMIT 1"))) {
            throw new RuntimeException('That is a different QuickBooks company from the one your batches went to - take every invoice out of its batch first, '
                . 'or connect the same company again.');
        }
        $cleared = true;
    }
    $tokens = mitQboTokenCall(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => mitQboRedirectUri()]);
    if ($cleared) {
        foreach (MIT_QBO_MAP_TABLES as [$table]) {
            mysqli_query($mysqli, "DELETE FROM $table");
        }
        if (!empty($GLOBALS['mit_qbo_dev_forget_batches'])) {
            mysqli_query($mysqli, "DELETE FROM mit_qbo_batch_invoices");
            mysqli_query($mysqli, "DELETE FROM mit_qbo_batches");
        }
        foreach (['qbo_no_tax_code', 'qbo_fallback_item'] as $setting) {
            mitSetSetting($setting, '');
        }
    }
    mitSetSetting('qbo_realm_id', $realm_id);
    mitSetSetting('qbo_mapped_realm', $realm_id);
    mitSetSetting('qbo_dev_realm', mitDevMode() ? $realm_id : '');
    $GLOBALS['mit_qbo_mappings_cleared'] = $cleared;
    mitQboStoreTokens($tokens);
    mitSetSetting('qbo_connected_by', intval($session_user_id));
    mitSetSetting('qbo_connected_at', date('Y-m-d H:i:s'));
    mitSetSetting('qbo_last_error', '');
    $info = mitQboApi('GET', '/companyinfo/' . $realm_id);
    $name = (string) ($info['CompanyInfo']['CompanyName'] ?? '');
    mitSetSetting('qbo_company_name', $name);
    return $name;
}

/** Disconnect: tell Intuit to drop the tokens (best effort) and forget them. The mappings, batches and approvals stay. */
function mitQboDisconnect() {
    $refresh = mitDecryptSecret((string) mitSetting('qbo_refresh_token', ''));
    // Development mode: production's connection (from its backup) is only forgotten here, never revoked at Intuit
    if ($refresh !== '' && mitQboDevProblem() === '') {
        try {
            $s = mitQboSettings();
            $secret = mitDecryptSecret((string) mitSetting('qbo_client_secret', ''));
            mitQboHttp('POST', $s['revoke_url'], ['Accept: application/json', 'Authorization: Basic ' . base64_encode($s['client_id'] . ':' . $secret)],
                json_encode(['token' => $refresh]), 15);
        } catch (Throwable $e) {
            // already gone at Intuit's end
        }
    }
    foreach (['qbo_realm_id', 'qbo_company_name', 'qbo_access_token', 'qbo_access_expires', 'qbo_refresh_token', 'qbo_refresh_expires', 'qbo_dev_realm'] as $name) {
        mitSetSetting($name, '');
    }
}

/** An access token, refreshed when it is within five minutes of running out (once at a time). Throws on failure. */
function mitQboAccessToken($fresh = false) {
    $token = mitDecryptSecret((string) mitSetting('qbo_access_token', ''));
    if (!$fresh && $token !== '' && intval(mitSetting('qbo_access_expires', 0)) > time() + 300) {
        return $token;
    }
    if (!mitLock('qbo_token', 20)) {
        throw new RuntimeException('QuickBooks Online is busy refreshing its sign-in - try again in a moment.');
    }
    try {
        // Another request may have refreshed it while this one waited
        mitSettingsCacheRef(true);
        $token = mitDecryptSecret((string) mitSetting('qbo_access_token', ''));
        if (!$fresh && $token !== '' && intval(mitSetting('qbo_access_expires', 0)) > time() + 300) {
            return $token;
        }
        $refresh = mitDecryptSecret((string) mitSetting('qbo_refresh_token', ''));
        if ($refresh === '') {
            throw new RuntimeException('QuickBooks Online is not connected - Admin > Integrations > QuickBooks Online > Connect.');
        }
        try {
            $tokens = mitQboTokenCall(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        } catch (RuntimeException $e) {
            throw new RuntimeException($e->getMessage() . ' - reconnect QuickBooks Online (Admin > Integrations > QuickBooks Online).');
        }
        mitQboStoreTokens($tokens);
        return $tokens['access_token'];
    } finally {
        mitUnlock('qbo_token');
    }
}

/** QuickBooks' own explanation of a failed call. */
function mitQboFault(array $response) {
    $json = $response['json'];
    $errors = $json['Fault']['Error'] ?? ($json['fault']['error'] ?? []);
    $parts = [];
    foreach ((array) $errors as $error) {
        $parts[] = trim(($error['Message'] ?? $error['message'] ?? '') . (isset($error['Detail']) || isset($error['detail']) ? ': ' . ($error['Detail'] ?? $error['detail']) : ''));
    }
    $text = implode('; ', array_filter($parts));
    if ($text === '') {
        $text = $response['error'] !== '' ? $response['error'] : mb_substr(strip_tags($response['body']), 0, 200);
    }
    return trim(($response['status'] ? "HTTP {$response['status']} - " : '') . $text);
}

/**
 * One API call on the connected company: $path after /v3/company/{realm} (e.g. /invoice, /query). $body array is sent as JSON.
 * A 401 gets one fresh token and one more try. Returns the decoded answer; throws RuntimeException with QuickBooks' reason.
 */
function mitQboApi($method, $path, $body = null, array $query = []) {
    $problem = mitQboDevProblem();
    if ($problem !== '') {
        throw new RuntimeException($problem);
    }
    $s = mitQboSettings();
    if ($s['realm_id'] === '') {
        throw new RuntimeException('QuickBooks Online is not connected.');
    }
    $url = $s['api_base'] . '/v3/company/' . rawurlencode($s['realm_id']) . $path . '?'
        . http_build_query($query + ['minorversion' => MIT_QBO_MINOR_VERSION], '', '&', PHP_QUERY_RFC3986);
    $payload = $body === null ? null : json_encode($body);
    $send = function ($token) use ($method, $url, $payload) {
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        if ($payload === null && $method === 'POST') {
            // A POST with nothing in it (send an invoice by email): Intuit wants this type, not a form
            $headers[] = 'Content-Type: application/octet-stream';
        }
        return mitQboHttp($method, $url, $headers, $payload, 60);
    };
    $response = $send(mitQboAccessToken());
    if ($response['status'] === 401) {
        $response = $send(mitQboAccessToken(true));
    }
    if ($response['status'] < 200 || $response['status'] >= 300 || !$response['json'] || isset($response['json']['Fault'])) {
        throw new RuntimeException('QuickBooks Online: ' . mitQboFault($response));
    }
    return $response['json'];
}

/** A query (QuickBooks' own SQL-like language) - every row, 500 at a time. */
function mitQboQueryAll($entity, $where = '') {
    $rows = [];
    for ($start = 1; $start < 100000; $start += 500) {
        $answer = mitQboApi('GET', '/query', null, ['query' => "SELECT * FROM $entity" . ($where !== '' ? " WHERE $where" : '') . " STARTPOSITION $start MAXRESULTS 500"]);
        $batch = $answer['QueryResponse'][$entity] ?? [];
        $rows = array_merge($rows, $batch);
        if (count($batch) < 500) {
            break;
        }
    }
    return $rows;
}

/** A value quoted for a QuickBooks query. */
function mitQboQuote($value) {
    return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
}

/** QuickBooks' customers / items / tax codes, active ones, read once per request: id => [id, name, ...]. */
function mitQboList($kind) {
    static $lists = [];
    if (!isset($lists[$kind])) {
        $list = [];
        if ($kind === 'customers') {
            foreach (mitQboQueryAll('Customer', 'Active = true') as $row) {
                $list[(string) $row['Id']] = ['id' => (string) $row['Id'], 'name' => (string) ($row['DisplayName'] ?? ''),
                    'email' => (string) ($row['PrimaryEmailAddr']['Address'] ?? '')];
            }
        } elseif ($kind === 'items') {
            foreach (mitQboQueryAll('Item', 'Active = true') as $row) {
                if (in_array($row['Type'] ?? '', ['Service', 'NonInventory', 'Inventory'], true)) {
                    $list[(string) $row['Id']] = ['id' => (string) $row['Id'], 'name' => (string) ($row['FullyQualifiedName'] ?? $row['Name'] ?? ''),
                        'short' => (string) ($row['Name'] ?? ''), 'type' => (string) $row['Type']];
                }
            }
        } elseif ($kind === 'tax_codes') {
            foreach (mitQboQueryAll('TaxCode', 'Active = true') as $row) {
                $list[(string) $row['Id']] = ['id' => (string) $row['Id'], 'name' => (string) ($row['Name'] ?? ''), 'description' => (string) ($row['Description'] ?? '')];
            }
        }
        uasort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        $lists[$kind] = $list;
    }
    return $lists[$kind];
}

/*
 * ---------- mappings ----------
 */

/** ITFlow id => [qbo id, qbo name] for 'customers' (clients), 'items' (products) or 'tax_codes' (taxes). */
function mitQboMap($kind) {
    global $mysqli;
    [$table, $key, $qbo] = MIT_QBO_MAP_TABLES[$kind];
    $map = [];
    $result = mysqli_query($mysqli, "SELECT $key AS id, $qbo AS qbo_id, qbo_name FROM $table");
    while ($row = mysqli_fetch_assoc($result)) {
        $map[intval($row['id'])] = ['qbo_id' => (string) $row['qbo_id'], 'qbo_name' => (string) $row['qbo_name']];
    }
    return $map;
}

const MIT_QBO_MAP_TABLES = [
    'customers' => ['mit_qbo_customers', 'client_id', 'qbo_customer_id'],
    'items' => ['mit_qbo_items', 'product_id', 'qbo_item_id'],
    'tax_codes' => ['mit_qbo_tax_codes', 'tax_id', 'qbo_tax_code_id'],
];

/** Set (or clear, with $qbo_id '') one mapping. */
function mitQboSetMap($kind, $itflow_id, $qbo_id, $qbo_name = '') {
    global $mysqli, $session_user_id;
    [$table, $key, $qbo] = MIT_QBO_MAP_TABLES[$kind];
    $itflow_id = intval($itflow_id);
    $qbo_id = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $qbo_id);
    if ($qbo_id === '') {
        mysqli_query($mysqli, "DELETE FROM $table WHERE $key = $itflow_id");
        return;
    }
    $name = escapeSql(mb_substr((string) $qbo_name, 0, 255));
    $by = intval($session_user_id ?? 0);
    mysqli_query($mysqli, "INSERT INTO $table SET $key = $itflow_id, $qbo = '$qbo_id', qbo_name = '$name', updated_by = $by, updated_at = NOW()
        ON DUPLICATE KEY UPDATE $qbo = '$qbo_id', qbo_name = '$name', updated_by = $by, updated_at = NOW()");
}

/** Match unmapped clients / products / taxes to QuickBooks by name (no case, trimmed). Returns how many were matched. */
function mitQboAutoMatch($kind) {
    global $mysqli;
    $by_name = [];
    foreach (mitQboList($kind) as $row) {
        $by_name[mb_strtolower(trim($row['name']))] = $row;
    }
    // Items inside a QuickBooks category are "Category:Item" - their own name matches too (when it is not taken)
    foreach (mitQboList($kind) as $row) {
        if (($row['short'] ?? '') !== '') {
            $by_name[mb_strtolower(trim($row['short']))] = $by_name[mb_strtolower(trim($row['short']))] ?? $row;
        }
    }
    $map = mitQboMap($kind);
    $sources = [
        'customers' => "SELECT client_id AS id, client_name AS name FROM clients WHERE client_archived_at IS NULL",
        'items' => "SELECT product_id AS id, product_name AS name FROM products WHERE product_archived_at IS NULL",
        'tax_codes' => "SELECT tax_id AS id, tax_name AS name FROM taxes WHERE tax_archived_at IS NULL",
    ];
    $matched = 0;
    $result = mysqli_query($mysqli, $sources[$kind]);
    while ($row = mysqli_fetch_assoc($result)) {
        $found = $by_name[mb_strtolower(trim((string) $row['name']))] ?? null;
        if ($found && !isset($map[intval($row['id'])])) {
            mitQboSetMap($kind, $row['id'], $found['id'], $found['name']);
            $matched++;
        }
    }
    return $matched;
}

/** The email addresses QuickBooks sends a client's invoices to: billing contacts, else the primary contact. */
function mitQboClientEmails($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    $emails = [];
    $result = mysqli_query($mysqli, "SELECT contact_email FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL
        AND contact_email != '' AND (contact_billing = 1 OR contact_primary = 1) ORDER BY contact_billing DESC, contact_primary DESC, contact_name");
    $billing = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $email = trim((string) $row['contact_email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $billing[] = $email;
        }
    }
    // Billing contacts when there are any (they sort first), else the primary; QuickBooks takes a comma list up to 100 characters
    foreach (array_unique($billing) as $email) {
        if (mb_strlen(implode(',', array_merge($emails, [$email]))) > 100) {
            break;
        }
        $emails[] = $email;
    }
    return $emails;
}

/** Make a QuickBooks customer for a client (or link the one already there with its name). Returns the QuickBooks id. */
function mitQboCreateCustomer($client_id) {
    global $mysqli;
    $client_id = intval($client_id);
    $client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $client_id"));
    if (!$client) {
        throw new RuntimeException('That client does not exist.');
    }
    // QuickBooks' display names are unique and may not hold a colon
    $name = mb_substr(trim(str_replace(':', '-', (string) $client['client_name'])), 0, 100);
    // Inactive ones too: QuickBooks keeps their names taken
    $existing = mitQboQueryAll('Customer', 'DisplayName = ' . mitQboQuote($name) . ' AND Active IN (true, false)');
    if ($existing) {
        $id = (string) $existing[0]['Id'];
        if (($existing[0]['Active'] ?? true) === false) {
            mitQboApi('POST', '/customer', ['Id' => $id, 'SyncToken' => (string) ($existing[0]['SyncToken'] ?? '0'), 'sparse' => true, 'Active' => true]);
        }
    } else {
        $body = ['DisplayName' => $name, 'CompanyName' => $name];
        $emails = mitQboClientEmails($client_id);
        if ($emails) {
            $body['PrimaryEmailAddr'] = ['Address' => implode(',', $emails)];
        }
        $answer = mitQboApi('POST', '/customer', $body);
        $id = (string) $answer['Customer']['Id'];
    }
    mitQboSetMap('customers', $client_id, $id, $name);
    return $id;
}

/*
 * ---------- the invoice QuickBooks gets ----------
 */

/** The invoice number QuickBooks shows: ITFlow's prefix and number (QuickBooks allows 21 characters). */
function mitQboDocNumber(array $invoice) {
    return mb_substr($invoice['invoice_prefix'] . $invoice['invoice_number'], 0, 21);
}

/**
 * The QuickBooks invoice for an ITFlow invoice: [payload, problems]. Problems (a missing customer / tax code / item) stop the
 * send. $items is mitQboList('items') - names are matched when a product has no mapping, then the fallback item is used.
 */
function mitQboInvoicePayload($invoice_id, array $items, $batch_id = 0) {
    global $mysqli;
    $invoice_id = intval($invoice_id);
    $s = mitQboSettings();
    $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_id = $invoice_id"));
    if (!$invoice) {
        return [null, ['The invoice no longer exists.']];
    }
    $problems = [];
    $client_id = intval($invoice['invoice_client_id']);
    $customer = mitQboMap('customers')[$client_id] ?? null;
    if (!$customer && $s['create_customers']) {
        try {
            mitQboCreateCustomer($client_id);
            $customer = mitQboMap('customers')[$client_id] ?? null;
        } catch (Throwable $e) {
            $problems[] = 'Could not make the QuickBooks customer: ' . $e->getMessage();
        }
    }
    if (!$customer) {
        $problems[] = 'The client has no QuickBooks customer - map it in Admin > Integrations > QuickBooks Online > Customers.';
    }
    $item_map = mitQboMap('items');
    $tax_map = mitQboMap('tax_codes');
    $items_by_name = [];
    foreach ($items as $item) {
        $items_by_name[mb_strtolower(trim($item['name']))] = $item['id'];
        if (($item['short'] ?? '') !== '') {
            $items_by_name[mb_strtolower(trim($item['short']))] = $items_by_name[mb_strtolower(trim($item['short']))] ?? $item['id'];
        }
    }

    $lines = [];
    $taxes_used = [];
    $result = mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_invoice_id = $invoice_id AND item_archived_at IS NULL ORDER BY item_order, item_id");
    while ($line = mysqli_fetch_assoc($result)) {
        $item_ref = '';
        $product_id = intval($line['item_product_id']);
        if ($product_id && isset($item_map[$product_id])) {
            $item_ref = $item_map[$product_id]['qbo_id'];
        } elseif (isset($items_by_name[mb_strtolower(trim((string) $line['item_name']))])) {
            $item_ref = $items_by_name[mb_strtolower(trim((string) $line['item_name']))];
        } elseif ($s['fallback_item'] !== '') {
            $item_ref = $s['fallback_item'];
        } else {
            $problems[] = '"' . $line['item_name'] . '" has no QuickBooks item - map its product, or set a fallback item.';
        }
        $tax_id = intval($line['item_tax_id']);
        if ($s['tax_style'] === 'us') {
            $tax_code = $tax_id ? 'TAX' : 'NON';
            if ($tax_id) {
                $taxes_used[$tax_id] = true;
            }
        } elseif ($tax_id) {
            $tax_code = $tax_map[$tax_id]['qbo_id'] ?? '';
            if ($tax_code === '') {
                $problems[] = 'The tax on "' . $line['item_name'] . '" has no QuickBooks tax code - map it under Tax codes.';
            }
        } else {
            $tax_code = $s['no_tax_code'];
            if ($tax_code === '') {
                $problems[] = 'Untaxed lines need a QuickBooks tax code - set "Tax code for untaxed lines".';
            }
        }
        $description = trim((string) $line['item_name']);
        if (trim((string) $line['item_description']) !== '') {
            $description .= "\n" . trim(strip_tags((string) $line['item_description']));
        }
        $detail = ['ItemRef' => ['value' => $item_ref], 'Qty' => floatval($line['item_quantity']), 'UnitPrice' => floatval($line['item_price'])];
        if ($tax_code !== '') {
            $detail['TaxCodeRef'] = ['value' => $tax_code];
        }
        $lines[] = [
            'DetailType' => 'SalesItemLineDetail',
            'Amount' => round(floatval($line['item_quantity']) * floatval($line['item_price']), 2),
            'Description' => mb_substr($description, 0, 4000),
            'SalesItemLineDetail' => $detail,
        ];
    }
    if (!$lines) {
        $problems[] = 'The invoice has no lines.';
    }
    // ITFlow's discount and any credit applied come off as one discount line (QuickBooks takes one)
    $discount = round(floatval($invoice['invoice_discount_amount']) + floatval($invoice['invoice_credit_amount'] ?? 0), 2);
    if ($discount > 0) {
        $lines[] = ['DetailType' => 'DiscountLineDetail', 'Amount' => $discount, 'DiscountLineDetail' => ['PercentBased' => false]];
    }

    // QuickBooks makes the invoice in the customer's currency (the company's own unless multicurrency is on there) - an invoice in
    // another currency would go over with the same numbers in the wrong money
    $home = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT company_currency FROM companies WHERE company_id = 1"))[0] ?? '');
    if ($home !== '' && strtoupper((string) $invoice['invoice_currency_code']) !== strtoupper($home)) {
        $problems[] = 'The invoice is in ' . $invoice['invoice_currency_code'] . ', not ' . $home . ' - enter it in QuickBooks by hand and mark it "Already in QuickBooks".';
    }

    $payload = [
        'CustomerRef' => ['value' => (string) ($customer['qbo_id'] ?? '')],
        'DocNumber' => mitQboDocNumber($invoice),
        'TxnDate' => (string) $invoice['invoice_date'],
        'DueDate' => (string) $invoice['invoice_due'],
        'Line' => $lines,
        'PrivateNote' => mb_substr('From ITFlow invoice ' . mitQboDocNumber($invoice) . ($batch_id ? " (batch $batch_id)" : ''), 0, 4000),
    ];
    if (trim((string) $invoice['invoice_note']) !== '') {
        $payload['CustomerMemo'] = ['value' => mb_substr(trim(strip_tags((string) $invoice['invoice_note'])), 0, 1000)];
    }
    $emails = mitQboClientEmails($client_id);
    if ($emails && mitDevMode()) {
        $emails = mitDevMailTo() !== '' ? [mitDevMailTo()] : []; // development mode: QuickBooks' invoice emails go to the test inbox
    }
    if ($emails) {
        $payload['BillEmail'] = ['Address' => implode(',', $emails)];
    }
    // US companies: taxed lines are marked TAX and the invoice carries one tax code (QuickBooks works the tax out)
    if ($s['tax_style'] === 'us' && $taxes_used) {
        if (count($taxes_used) > 1) {
            $problems[] = 'The lines use different taxes - QuickBooks (US) takes one tax code per invoice.';
        } else {
            $tax_id = array_key_first($taxes_used);
            $code = $tax_map[$tax_id]['qbo_id'] ?? '';
            if ($code === '') {
                $problems[] = 'The invoice\'s tax has no QuickBooks tax code - map it under Tax codes.';
            } else {
                $payload['TxnTaxDetail'] = ['TxnTaxCodeRef' => ['value' => $code]];
                // Tax on the discounted amount or the full one, as ITFlow worked it out (Admin > Taxes), not QuickBooks' own default
                if ($discount > 0 && function_exists('taxSettings')) {
                    $payload['ApplyTaxAfterDiscount'] = taxSettings()['discount_before'];
                }
            }
        }
    }
    return [$payload, array_values(array_unique($problems))];
}
