<?php

/*
 * Mountaineer IT - usage connectors (required from functions/mit_usage.php): counts other systems hold, recorded once a day as
 * usage counts (mit_usage_counts) for recurring invoice lines to bill from. Admin > Integrations > Usage connectors
 * (admin/mit_usage_connectors.php); run by cron job mit_usage (daily) or "Run now".
 * - NinjaOne (mit_settings usage_ninjaone_on): devices the NinjaOne sync saw in the last 7 days, by the client their organization
 *   is mapped to (mit_ninja_orgs / mit_ninja_devices) - ninjaone:workstations, ninjaone:servers, ninjaone:devices.
 * - Bitwarden: the Provider portal has no API, so each client organization's own API key is kept (mit_usage_links, M033; the
 *   secret encrypted with mitEncryptSecret) - bitwarden:users (members not revoked, invited included, as Bitwarden bills seats)
 *   and bitwarden:confirmed. Region us | eu (usage_bitwarden_region).
 * - Ingram Micro Cloud Marketplace (Xvantage): one API login (usage_ingram_*), each ITFlow client linked to an Ingram customer
 *   (mit_usage_links) - ingram:<part number> = the quantity on its active subscriptions, the product's name kept as the count's
 *   note; a product counted before and gone now is recorded as 0.
 * Each connector keeps at most one count a day per client and usage name (a second run the same day updates it). A test install
 * may point at stand-ins with MIT_BITWARDEN_IDENTITY_URL / MIT_BITWARDEN_API_URL / MIT_INGRAM_API_BASE in config.php (never on
 * a real one).
 */

const MIT_USAGE_CONNECTORS = [
    'ninjaone' => 'NinjaOne',
    'bitwarden' => 'Bitwarden',
    'ingram' => 'Ingram Micro',
];
// Agent-managed NinjaOne classes counted (network-only NMS_* devices, VM guests and phones are left out - ninjaone:all has them)
const MIT_NINJA_WORKSTATION_CLASSES = ['WINDOWS_WORKSTATION', 'MAC', 'LINUX_WORKSTATION'];
const MIT_NINJA_SERVER_CLASSES = ['WINDOWS_SERVER', 'LINUX_SERVER', 'MAC_SERVER', 'VMWARE_VM_HOST', 'HYPERV_VMM_HOST'];

/** Has M033 run? */
function mitUsageLinksReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_usage_links'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Connector settings, with defaults. */
function mitUsageConnectorSettings() {
    $region = mitSetting('usage_bitwarden_region', 'us') === 'eu' ? 'eu' : 'us';
    $ingram_base = (string) mitSetting('usage_ingram_base', '');
    return [
        'ninjaone_on' => mitSetting('usage_ninjaone_on', '0') === '1',
        'bitwarden_region' => $region,
        'bitwarden_identity' => defined('MIT_BITWARDEN_IDENTITY_URL') ? MIT_BITWARDEN_IDENTITY_URL : ($region === 'eu' ? 'https://identity.bitwarden.eu' : 'https://identity.bitwarden.com'),
        'bitwarden_api' => defined('MIT_BITWARDEN_API_URL') ? MIT_BITWARDEN_API_URL : ($region === 'eu' ? 'https://api.bitwarden.eu' : 'https://api.bitwarden.com'),
        'ingram_base_custom' => $ingram_base,
        'ingram_marketplace' => (string) mitSetting('usage_ingram_marketplace', 'us'),
        'ingram_login' => (string) mitSetting('usage_ingram_login', ''),
        'ingram_has_password' => (string) mitSetting('usage_ingram_password', '') !== '',
        'ingram_has_key' => (string) mitSetting('usage_ingram_key', '') !== '',
        'ingram_base' => defined('MIT_INGRAM_API_BASE') ? rtrim(MIT_INGRAM_API_BASE, '/')
            : rtrim($ingram_base !== '' ? $ingram_base : 'https://api.cloud.im/marketplaces/' . rawurlencode((string) mitSetting('usage_ingram_marketplace', 'us')), '/'),
    ];
}

/**
 * May the Ingram Micro API address be called? https, and a public address (never this server or the internal network) - the
 * login and keys go to it. The test install's MIT_INGRAM_API_BASE stand-in is not checked.
 */
function mitUsageIngramBaseOk($url) {
    if (defined('MIT_INGRAM_API_BASE') && rtrim($url, '/') === rtrim(MIT_INGRAM_API_BASE, '/')) {
        return true;
    }
    $parts = parse_url((string) $url);
    if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return false;
    }
    $host = trim($parts['host'], '[]');
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(gethostbynamel($host) ?: [],
        array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!mitIpIsPublic($ip)) {
            return false;
        }
    }
    return true;
}

/** Is Ingram Micro set up (login, password, subscription key)? */
function mitUsageIngramConfigured() {
    $s = mitUsageConnectorSettings();
    return $s['ingram_login'] !== '' && $s['ingram_has_password'] && $s['ingram_has_key'];
}

/** A connector's links: link rows with the client's name. */
function mitUsageLinks($connector) {
    global $mysqli;
    if (!mitUsageLinksReady()) {
        return [];
    }
    return mysqli_fetch_all(mysqli_query($mysqli, "SELECT l.*, client_name FROM mit_usage_links l JOIN clients ON clients.client_id = l.client_id
        WHERE l.connector = '" . escapeSql($connector) . "' ORDER BY client_name"), MYSQLI_ASSOC);
}

/**
 * Record a connector's count for today - one a day per client, usage name and source (a later run updates it). The name is
 * cleaned the way it is stored (mitUsageCleanMetric). Returns '' or the problem.
 */
function mitUsageRecordDaily($client_id, $metric, $quantity, $source, $note = '') {
    global $mysqli;
    $client_id = intval($client_id);
    $metric = mitUsageCleanMetric($metric);
    $quantity = floatval($quantity);
    if ($metric === '' || $quantity < 0 || $quantity >= 1e9) {
        return "A count for client $client_id could not be kept (" . ($metric === '' ? 'no usage name' : "quantity $quantity") . ').';
    }
    $metric_sql = escapeSql($metric);
    $source_sql = escapeSql($source);
    $today = mysqli_fetch_row(mysqli_query($mysqli, "SELECT count_id FROM mit_usage_counts WHERE client_id = $client_id AND metric = '$metric_sql' AND as_of = CURDATE()
        AND count_source = '$source_sql' ORDER BY count_id LIMIT 1"));
    if ($today) {
        mysqli_query($mysqli, "UPDATE mit_usage_counts SET quantity = " . round($quantity, 2) . ", count_note = '" . escapeSql(mb_substr((string) $note, 0, 255)) . "',
            reported_at = NOW() WHERE count_id = " . intval($today[0]));
        return '';
    }
    return mitUsageRecordCount($client_id, $metric, $quantity, date('Y-m-d'), $source, $note)[1];
}

/** Note how a link's run went. */
function mitUsageLinkResult($link_id, $ok, $message) {
    global $mysqli;
    mysqli_query($mysqli, "UPDATE mit_usage_links SET last_run_at = NOW(), last_status = '" . ($ok ? 'ok' : 'error') . "', last_message = '"
        . escapeSql(mb_substr((string) $message, 0, 500)) . "' WHERE link_id = " . intval($link_id));
}

/*
 * ---------- NinjaOne ----------
 */

/**
 * Per mapped client, from what the NinjaOne sync saw in the last 7 days: workstations, servers (agent-managed, hypervisor hosts
 * included), devices (the two together - managed endpoints) and all (everything NinjaOne lists: network devices, VMs, phones).
 */
function mitUsageNinjaCounts() {
    global $mysqli;
    if (!function_exists('mitNinjaReady') || !mitNinjaReady()) {
        return [];
    }
    $workstations = "'" . implode("','", MIT_NINJA_WORKSTATION_CLASSES) . "'";
    $servers = "'" . implode("','", MIT_NINJA_SERVER_CLASSES) . "'";
    $result = mysqli_query($mysqli, "SELECT o.client_id, COUNT(*) AS everything, SUM(d.node_class IN ($workstations)) AS workstations,
            SUM(d.node_class IN ($servers)) AS servers
        FROM mit_ninja_devices d JOIN mit_ninja_orgs o ON o.org_id = d.org_id
        WHERE o.client_id > 0 AND o.status = 'mapped' AND d.seen_at > NOW() - INTERVAL 7 DAY GROUP BY o.client_id");
    $counts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $counts[intval($row['client_id'])] = ['workstations' => intval($row['workstations']), 'servers' => intval($row['servers']),
            'devices' => intval($row['workstations']) + intval($row['servers']), 'all' => intval($row['everything'])];
    }
    return $counts;
}

/** When the NinjaOne sync last ran through ('' = not in the last 2 days - its device list may be out of date). */
function mitUsageNinjaFreshSync() {
    global $mysqli;
    return (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT MAX(finished_at) FROM mit_ninja_runs WHERE outcome IN ('ok', 'warnings', 'problems')
        AND trigger_kind <> 'preview' AND finished_at > NOW() - INTERVAL 2 DAY"))[0] ?? '');
}

/** Record NinjaOne's counts. Returns [clients counted, problems]. */
function mitUsageRunNinja() {
    global $mysqli;
    if (!function_exists('mitNinjaReady') || !mitNinjaReady()) {
        return [0, ['NinjaOne is not set up (Admin > Integrations > NinjaOne).']];
    }
    // A stopped sync leaves devices unseen - counting then would bill every client for none
    if (mitUsageNinjaFreshSync() === '') {
        return [0, ['The NinjaOne sync has not run successfully in the last 2 days - nothing counted (Admin > Integrations > NinjaOne > Sync runs).']];
    }
    $counts = mitUsageNinjaCounts();
    // Clients mapped but with nothing seen this week count 0, so a client whose devices all went is billed for none
    $result = mysqli_query($mysqli, "SELECT DISTINCT client_id FROM mit_ninja_orgs WHERE client_id > 0 AND status = 'mapped'");
    while ($row = mysqli_fetch_assoc($result)) {
        $counts[intval($row['client_id'])] = $counts[intval($row['client_id'])] ?? ['workstations' => 0, 'servers' => 0, 'devices' => 0, 'all' => 0];
    }
    $problems = [];
    foreach ($counts as $client_id => $by_kind) {
        foreach ($by_kind as $kind => $qty) {
            if (($why = mitUsageRecordDaily($client_id, "ninjaone:$kind", $qty, 'ninjaone', 'NinjaOne devices seen in the last 7 days')) !== '') {
                $problems[] = $why;
            }
        }
    }
    return [count($counts), $problems];
}

/*
 * ---------- Bitwarden ----------
 */

/** A client organization's members, with its API key. Returns ['users' => n, 'confirmed' => n]; throws with the reason. */
function mitUsageBitwardenMembers($client_id_value, $secret) {
    $s = mitUsageConnectorSettings();
    $client_id_value = trim((string) $client_id_value);
    $token = mitHttp('POST', $s['bitwarden_identity'] . '/connect/token', ['Accept: application/json'], [
        'grant_type' => 'client_credentials', 'scope' => 'api.organization', 'client_id' => $client_id_value, 'client_secret' => $secret,
    ], 20);
    if ($token['status'] !== 200 || empty($token['json']['access_token'])) {
        throw new RuntimeException('Bitwarden refused the API key: ' . ($token['json']['error_description'] ?? $token['json']['error'] ?? ($token['error'] ?: 'HTTP ' . $token['status'])));
    }
    $members = mitHttp('GET', $s['bitwarden_api'] . '/public/members', ['Authorization: Bearer ' . $token['json']['access_token'], 'Accept: application/json'], null, 30);
    if ($members['status'] !== 200 || !is_array($members['json']['data'] ?? null)) {
        throw new RuntimeException('Bitwarden did not list the members: HTTP ' . $members['status']);
    }
    $users = 0;
    $confirmed = 0;
    foreach ($members['json']['data'] as $member) {
        // Status: 0 invited, 1 accepted, 2 confirmed, -1 revoked - Bitwarden bills a seat for every member not revoked
        $status = intval($member['status'] ?? 0);
        if ($status >= 0) {
            $users++;
        }
        if ($status === 2) {
            $confirmed++;
        }
    }
    return ['users' => $users, 'confirmed' => $confirmed];
}

/** Link a client to its Bitwarden organization (by the organization's API key) - the key is tried first. Returns '' or the problem. */
function mitUsageBitwardenLink($client_id, $api_client_id, $api_secret, $name = '') {
    global $mysqli, $session_user_id;
    $client_id = intval($client_id);
    $api_client_id = trim((string) $api_client_id);
    $api_secret = trim((string) $api_secret);
    if (!preg_match('/^organization\.([0-9a-f-]{36})$/i', $api_client_id, $m)) {
        return 'The client ID looks like organization.xxxxxxxx-xxxx-... (Bitwarden: the organization > Settings > Organization info > API key).';
    }
    if ($api_secret === '' || !mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM clients WHERE client_id = $client_id"))) {
        return 'Pick the client and paste the client secret.';
    }
    try {
        $counts = mitUsageBitwardenMembers($api_client_id, $api_secret);
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    $external = escapeSql(strtolower($m[1]));
    $secret = escapeSql(mitEncryptSecret(json_encode(['client_id' => $api_client_id, 'client_secret' => $api_secret])));
    $name_sql = escapeSql(mb_substr(trim((string) $name), 0, 255));
    mysqli_query($mysqli, "INSERT INTO mit_usage_links SET connector = 'bitwarden', client_id = $client_id, external_id = '$external', external_name = '$name_sql',
            secret = '$secret', created_by = " . intval($session_user_id) . "
        ON DUPLICATE KEY UPDATE client_id = $client_id, external_name = '$name_sql', secret = '$secret'");
    $link_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT link_id FROM mit_usage_links WHERE connector = 'bitwarden' AND external_id = '$external'"))[0]);
    mitUsageLinkResult($link_id, true, "Linked - {$counts['users']} users ({$counts['confirmed']} confirmed)");
    return '';
}

/** Record every linked organization's members. Returns [organizations counted, problems]. */
function mitUsageRunBitwarden() {
    $done = 0;
    $problems = [];
    foreach (mitUsageLinks('bitwarden') as $link) {
        $key = json_decode(mitDecryptSecret((string) $link['secret']), true) ?: [];
        try {
            $counts = mitUsageBitwardenMembers($key['client_id'] ?? '', $key['client_secret'] ?? '');
            foreach (['users' => 'Bitwarden members not revoked', 'confirmed' => 'Bitwarden confirmed members'] as $kind => $note) {
                if (($why = mitUsageRecordDaily($link['client_id'], "bitwarden:$kind", $counts[$kind], 'bitwarden', $note)) !== '') {
                    throw new RuntimeException($why);
                }
            }
            mitUsageLinkResult($link['link_id'], true, "{$counts['users']} users ({$counts['confirmed']} confirmed)");
            $done++;
        } catch (Throwable $e) {
            mitUsageLinkResult($link['link_id'], false, $e->getMessage());
            $problems[] = $link['client_name'] . ': ' . $e->getMessage();
        }
    }
    return [$done, $problems];
}

/*
 * ---------- Ingram Micro Cloud Marketplace ----------
 */

/** A token for the Cloud Marketplace API (kept until 5 minutes before it runs out). Throws with the reason. */
function mitUsageIngramToken($fresh = false) {
    $s = mitUsageConnectorSettings();
    $cached = mitDecryptSecret((string) mitSetting('usage_ingram_token', ''));
    if (!$fresh && $cached !== '' && intval(mitSetting('usage_ingram_token_expires', 0)) > time() + 300) {
        return $cached;
    }
    if (!mitUsageIngramConfigured()) {
        throw new RuntimeException('Ingram Micro is not set up - add the API login, password and subscription key.');
    }
    if (!mitUsageIngramBaseOk($s['ingram_base'])) {
        throw new RuntimeException('The Ingram Micro API address must be a public https address.');
    }
    $response = mitHttp('POST', $s['ingram_base'] . '/token', ['Accept: application/json', 'Content-Type: application/json',
        'X-Subscription-Key: ' . mitDecryptSecret((string) mitSetting('usage_ingram_key', ''))], json_encode([
        'marketplace' => $s['ingram_marketplace'], 'login' => $s['ingram_login'], 'password' => mitDecryptSecret((string) mitSetting('usage_ingram_password', '')),
    ]), 30);
    $token = (string) ($response['json']['token'] ?? $response['json']['access_token'] ?? '');
    if ($response['status'] < 200 || $response['status'] >= 300 || $token === '') {
        $why = $response['json']['message'] ?? $response['json']['error_description'] ?? $response['json']['error'] ?? ($response['error'] ?: 'HTTP ' . $response['status']);
        throw new RuntimeException('Ingram Micro refused the login: ' . (is_string($why) ? $why : json_encode($why)));
    }
    mitSetSetting('usage_ingram_token', mitEncryptSecret($token));
    mitSetSetting('usage_ingram_token_expires', time() + max(300, intval($response['json']['expires_in'] ?? 3600)));
    return $token;
}

/** A GET on the Cloud Marketplace API, every page (100 a time). Returns the rows. Throws with the reason. */
function mitUsageIngramList($path, array $query = []) {
    $s = mitUsageConnectorSettings();
    $key = mitDecryptSecret((string) mitSetting('usage_ingram_key', ''));
    $rows = [];
    for ($offset = 0; $offset < 20000; $offset += 100) {
        $url = $s['ingram_base'] . $path . '?' . http_build_query($query + ['limit' => 100, 'offset' => $offset], '', '&', PHP_QUERY_RFC3986);
        $call = function ($token) use ($url, $key) {
            return mitHttp('GET', $url, ['Accept: application/json', 'Authorization: Bearer ' . $token, 'X-Subscription-Key: ' . $key], null, 60);
        };
        $response = $call(mitUsageIngramToken());
        if ($response['status'] === 401) {
            $response = $call(mitUsageIngramToken(true));
        }
        if ($response['status'] !== 200 || !is_array($response['json'])) {
            throw new RuntimeException("Ingram Micro $path: " . ($response['json']['message'] ?? ($response['error'] ?: 'HTTP ' . $response['status'])));
        }
        $page = isset($response['json']['data']) && is_array($response['json']['data']) ? $response['json']['data'] : (array_is_list($response['json']) ? $response['json'] : []);
        // Rows by id: an API that ignored the offset would hand the same page back - stop instead of counting it again
        $new = 0;
        foreach ($page as $row) {
            $key = is_array($row) && isset($row['id']) ? 'id:' . $row['id'] : 'row:' . md5(json_encode($row));
            if (!isset($rows[$key])) {
                $rows[$key] = $row;
                $new++;
            }
        }
        if (count($page) < 100 || $new === 0) {
            break;
        }
    }
    return array_values($rows);
}

/** Ingram's customers (read from the API and kept for the mapping page): id => name. */
function mitUsageIngramCustomers($refresh = false) {
    if ($refresh) {
        $customers = [];
        foreach (mitUsageIngramList('/customers') as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $customers[$id] = (string) ($row['name'] ?? $row['companyName'] ?? $id);
            }
        }
        asort($customers, SORT_NATURAL | SORT_FLAG_CASE);
        mitSetSetting('usage_ingram_customers', json_encode($customers));
        return $customers;
    }
    return json_decode((string) mitSetting('usage_ingram_customers', '[]'), true) ?: [];
}

/** Link (or unlink, $customer_id '') an ITFlow client to an Ingram customer. */
function mitUsageIngramSetLink($client_id, $customer_id, $name = '') {
    global $mysqli, $session_user_id;
    $client_id = intval($client_id);
    mysqli_query($mysqli, "DELETE FROM mit_usage_links WHERE connector = 'ingram' AND client_id = $client_id");
    $customer_id = mb_substr(trim((string) $customer_id), 0, 100);
    if ($customer_id === '') {
        return;
    }
    $external = escapeSql($customer_id);
    mysqli_query($mysqli, "DELETE FROM mit_usage_links WHERE connector = 'ingram' AND external_id = '$external'");
    mysqli_query($mysqli, "INSERT INTO mit_usage_links SET connector = 'ingram', client_id = $client_id, external_id = '$external',
        external_name = '" . escapeSql(mb_substr((string) $name, 0, 255)) . "', created_by = " . intval($session_user_id ?? 0));
}

/** Link unlinked clients to Ingram customers of the same name. Returns how many. */
function mitUsageIngramAutoMatch() {
    global $mysqli;
    $by_name = [];
    foreach (mitUsageIngramCustomers() as $id => $name) {
        $by_name[mb_strtolower(trim($name))] = [$id, $name];
    }
    $linked_clients = array_map('intval', array_column(mitUsageLinks('ingram'), 'client_id'));
    $linked_customers = array_column(mitUsageLinks('ingram'), 'external_id');
    $matched = 0;
    $result = mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL");
    while ($row = mysqli_fetch_assoc($result)) {
        $found = $by_name[mb_strtolower(trim((string) $row['client_name']))] ?? null;
        if ($found && !in_array(intval($row['client_id']), $linked_clients, true) && !in_array((string) $found[0], $linked_customers, true)) {
            mitUsageIngramSetLink($row['client_id'], $found[0], $found[1]);
            $linked_customers[] = (string) $found[0];
            $matched++;
        }
    }
    return $matched;
}

/** One customer's active subscriptions as usage name (ingram:<part number>, as stored) => [quantity, name]. */
function mitUsageIngramProducts($customer_id) {
    $products = [];
    foreach (mitUsageIngramList('/subscriptions', ['customerId' => $customer_id, 'status' => 'active']) as $subscription) {
        if (isset($subscription['status']) && strtolower((string) $subscription['status']) !== 'active') {
            continue;
        }
        // Only this customer's - should the filter ever be ignored, other customers' subscriptions are not added in
        $owner = $subscription['customerId'] ?? ($subscription['customer']['id'] ?? null);
        if ($owner !== null && (string) $owner !== (string) $customer_id) {
            continue;
        }
        foreach ((array) ($subscription['products'] ?? []) as $product) {
            $mpn = trim((string) ($product['mpn'] ?? ''));
            $metric = mitUsageCleanMetric("ingram:$mpn");
            if ($mpn === '' || $metric === 'ingram:') {
                continue;
            }
            $products[$metric]['quantity'] = ($products[$metric]['quantity'] ?? 0) + floatval($product['quantity'] ?? 0);
            $products[$metric]['name'] = (string) ($product['name'] ?? $product['title'] ?? $subscription['name'] ?? $mpn);
        }
    }
    return $products;
}

/** Record every linked customer's subscriptions. Returns [customers counted, problems]. */
function mitUsageRunIngram() {
    global $mysqli;
    if (!mitUsageIngramConfigured()) {
        return [0, mitUsageLinks('ingram') ? ['Ingram Micro is not set up.'] : []];
    }
    $done = 0;
    $problems = [];
    foreach (mitUsageLinks('ingram') as $link) {
        try {
            $products = mitUsageIngramProducts($link['external_id']);
            $client_id = intval($link['client_id']);
            foreach ($products as $metric => $product) {
                if (($why = mitUsageRecordDaily($client_id, $metric, $product['quantity'], 'ingram', $product['name'])) !== '') {
                    throw new RuntimeException($why);
                }
            }
            // A product counted in the last 60 days and gone now is 0 - not its old count left standing
            $seen = mysqli_query($mysqli, "SELECT DISTINCT metric, count_note FROM mit_usage_counts WHERE client_id = $client_id AND count_source = 'ingram'
                AND as_of > CURDATE() - INTERVAL 60 DAY AND as_of < CURDATE()");
            while ($row = mysqli_fetch_assoc($seen)) {
                if (!isset($products[(string) $row['metric']])) {
                    mitUsageRecordDaily($client_id, $row['metric'], 0, 'ingram', $row['count_note']);
                }
            }
            mitUsageLinkResult($link['link_id'], true, count($products) . ' product' . (count($products) === 1 ? '' : 's') . ' on active subscriptions');
            $done++;
        } catch (Throwable $e) {
            mitUsageLinkResult($link['link_id'], false, $e->getMessage());
            $problems[] = $link['client_name'] . ': ' . $e->getMessage();
        }
    }
    return [$done, $problems];
}

/*
 * ---------- all of them ----------
 */

/** Run the connectors that are set up ($only = one of them). Returns connector => [counted, problems]; kept as the last run. */
function mitUsageRunConnectors($only = '') {
    $report = [];
    if (!mitUsageReady()) {
        return $report;
    }
    // One run at a time (the daily job and Run now), so a day never gets two counts
    if (!mitLock('usage_connectors', 30)) {
        throw new RuntimeException('The usage connectors are already running - try again in a minute.');
    }
    try {
        $runs = [];
        if (($only === '' || $only === 'ninjaone') && (mitUsageConnectorSettings()['ninjaone_on'] || $only === 'ninjaone')) {
            $runs['ninjaone'] = 'mitUsageRunNinja';
        }
        if (mitUsageLinksReady() && ($only === '' || $only === 'bitwarden') && mitUsageLinks('bitwarden')) {
            $runs['bitwarden'] = 'mitUsageRunBitwarden';
        }
        if (mitUsageLinksReady() && ($only === '' || $only === 'ingram') && mitUsageLinks('ingram')) {
            $runs['ingram'] = 'mitUsageRunIngram';
        }
        // Each on its own: one failing does not stop the others
        foreach ($runs as $connector => $run) {
            try {
                $report[$connector] = $run();
            } catch (Throwable $e) {
                $report[$connector] = [0, [$e->getMessage()]];
            }
        }
    } finally {
        mitUnlock('usage_connectors');
    }
    $last = json_decode((string) mitSetting('usage_connectors_last', '{}'), true) ?: [];
    foreach ($report as $connector => [$counted, $problems]) {
        $last[$connector] = ['at' => date('Y-m-d H:i:s'), 'counted' => $counted, 'problems' => array_slice($problems, 0, 10)];
    }
    mitSetSetting('usage_connectors_last', json_encode($last));
    return $report;
}

/** Usage names already reported for a client (for the usage line picker): metric => label. */
function mitUsageKnownMetrics($client_id) {
    global $mysqli;
    $metrics = [];
    $client_id = intval($client_id);
    $result = mysqli_query($mysqli, "SELECT c.metric, c.count_source, c.count_note FROM mit_usage_counts c
        JOIN (SELECT MAX(count_id) AS count_id FROM mit_usage_counts WHERE client_id = $client_id GROUP BY metric) latest ON latest.count_id = c.count_id
        ORDER BY c.metric");
    while ($row = mysqli_fetch_assoc($result)) {
        if (strpos($row['metric'], 'tag:') === 0) {
            continue;
        }
        $label = $row['count_source'] === 'ingram' && $row['count_note'] !== '' ? $row['count_note'] : (MIT_USAGE_CONNECTORS[$row['count_source']] ?? $row['count_source']);
        $metrics[$row['metric']] = $label;
    }
    return $metrics;
}
