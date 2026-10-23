<?php

/*
 * Mountaineer IT - NinjaOne RMM: devices become ITFlow assets, and a NinjaOne button (with the device's live status)
 * sits next to each asset on tickets and assets. Replaces the "NinjaOne -> ITFlow asset sync" n8n workflow, with the
 * same rules, so assets it made are picked up as they are.
 *
 * Settings (admin/mit_ninjaone.php - tabs Settings / Mapping / Sync runs; handlers admin/post/mit_ninjaone.php), in
 * mit_settings: ninja_instance (the NinjaOne region's host), ninja_client_id / ninja_client_secret (API client app,
 * client credentials, scope monitoring - the secret encrypted, mitEncryptSecret), ninja_web_url (your own NinjaOne
 * address for the buttons, e.g. https://yourcompany.rmmservice.com), ninja_sync_on, ninja_brake (most new assets one
 * run may make), ninja_misplaced (report | create). Tables (M019): mit_ninja_orgs (each NinjaOne organization and the
 * ITFlow client it goes to - mapped / ignored / unmapped), mit_ninja_devices (each device, its asset, status, warranty),
 * mit_ninja_runs (each sync and its report).
 *
 * The sync (mitNinjaSync - cron job mit_ninjaone_sync hourly, or Run now) finds a device's asset by, in order:
 *   1. its link (mit_ninja_devices.asset_id), or "NinjaOne Device ID: n" / "UID: x" in the asset's notes (n8n's block)
 *   2. the same serial in the mapped client, when that asset is not another live device's
 * and never by name - an asset that only shares a name is left alone and a new one is made (and reported).
 * An asset found under another client is reported (or, with ninja_misplaced = create, a new one is made), an archived
 * one in the mapped client is left archived. Only the fields the sync owns change: name, type (when it was generic),
 * make, model, OS, serial, primary IP / MAC, purchase and warranty dates, and the [NinjaOne Sync] block in the notes -
 * the rest of the notes, status, contact, location and the rest stay as people set them. Over ninja_brake new assets
 * in one run, nothing is written. Placeholder serials ("To Be Filled By O.E.M.", "Default string"...) are ignored.
 */

/** NinjaOne's regions: setting => [label, host]. */
const MIT_NINJA_INSTANCES = [
    'app' => ['United States (app.ninjarmm.com)', 'app.ninjarmm.com'],
    'us2' => ['United States 2 (us2.ninjarmm.com)', 'us2.ninjarmm.com'],
    'ca' => ['Canada (ca.ninjarmm.com)', 'ca.ninjarmm.com'],
    'eu' => ['Europe (eu.ninjarmm.com)', 'eu.ninjarmm.com'],
    'oc' => ['Oceania (oc.ninjarmm.com)', 'oc.ninjarmm.com'],
];

/** NinjaOne's device classes as ITFlow asset types (laptops are told apart by chassis or model). */
const MIT_NINJA_TYPES = [
    'WINDOWS_WORKSTATION' => 'Desktop', 'LINUX_WORKSTATION' => 'Desktop', 'MAC' => 'Desktop', 'NMS_COMPUTER' => 'Desktop',
    'WINDOWS_SERVER' => 'Server', 'LINUX_SERVER' => 'Server', 'MAC_SERVER' => 'Server', 'NMS_SERVER' => 'Server',
    'VMWARE_VM_HOST' => 'Server', 'HYPERV_VMM_HOST' => 'Server', 'NMS_VM_HOST' => 'Server',
    'VMWARE_VM_GUEST' => 'Virtual Machine', 'HYPERV_VMM_GUEST' => 'Virtual Machine', 'NMS_VIRTUAL_MACHINE' => 'Virtual Machine',
    'NMS_SWITCH' => 'Switch', 'NMS_WAP' => 'Access Point', 'NMS_PRINTER' => 'Printer', 'NMS_PHONE' => 'Phone',
    'NMS_ROUTER' => 'Firewall/Router', 'NMS_FIREWALL' => 'Firewall/Router', 'NMS_PRIVATE_NETWORK_GATEWAY' => 'Firewall/Router',
    'APPLE_IOS' => 'Mobile Phone', 'ANDROID' => 'Mobile Phone', 'APPLE_IPADOS' => 'Tablet',
];

/** Asset types the sync may correct on an existing asset (anything else was picked by a person). */
const MIT_NINJA_OVERRIDABLE_TYPES = ['', 'Other', 'Cloud', 'Network Device'];

/** Serials that are not serials. */
const MIT_NINJA_PLACEHOLDERS = [
    'to be filled by o.e.m.', 'to be filled by o.e.m', 'to be filled by oem', 'default string', 'not specified', 'not applicable',
    'not available', 'system serial number', 'system serial', 'chassis serial number', 'base board serial number', 'serial number',
    'serial', 'none', 'null', 'undefined', 'n/a', 'na', 'unknown', 'invalid', 'oem', 'o.e.m.', 'empty', 'not set', 'default',
    '0123456789', '123456789', '1234567890', '12345678', '1234567', 'system manufacturer', 'system product name', 'system version',
    'type1productconfigid', 'sku',
];

/** The block the sync keeps in an asset's notes (the n8n workflow's, so its assets are recognised). */
const MIT_NINJA_BLOCK_START = '[NinjaOne Sync]';
const MIT_NINJA_BLOCK_END = '[/NinjaOne Sync]';

/** Has M019 run? (one query per request) */
function mitNinjaReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ninja_devices'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The settings, with defaults. */
function mitNinjaSettings() {
    $instance = (string) mitSetting('ninja_instance', 'app');
    $instance = isset(MIT_NINJA_INSTANCES[$instance]) ? $instance : 'app';
    $host = MIT_NINJA_INSTANCES[$instance][1];
    $web = trim((string) mitSetting('ninja_web_url', ''));
    return [
        'instance' => $instance,
        // A test install can point the API at a stand-in (MIT_NINJA_API_BASE in config.php); never set on a real one
        'api_base' => defined('MIT_NINJA_API_BASE') ? rtrim(MIT_NINJA_API_BASE, '/') : "https://$host",
        'client_id' => (string) mitSetting('ninja_client_id', ''),
        'has_secret' => (string) mitSetting('ninja_client_secret', '') !== '',
        'web_url' => $web !== '' ? $web : "https://$host",
        'web_url_custom' => $web,
        'sync_on' => mitSetting('ninja_sync_on', '0') === '1',
        'brake' => max(1, intval(mitSetting('ninja_brake', 100))),
        'misplaced' => mitSetting('ninja_misplaced', 'report') === 'create' ? 'create' : 'report',
    ];
}

/** Is NinjaOne set up (API keys saved)? */
function mitNinjaConfigured() {
    if (!mitNinjaReady()) {
        return false;
    }
    $s = mitNinjaSettings();
    return $s['client_id'] !== '' && $s['has_secret'];
}

/** A web address for the buttons: https only, no path / query (cleaned), or '' when it is not one. */
function mitNinjaCleanWebUrl($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = "https://$url";
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || !preg_match('/^[a-z0-9.-]+$/i', $parts['host']) || strtolower($parts['scheme']) !== 'https') {
        return '';
    }
    return 'https://' . strtolower($parts['host']) . (!empty($parts['port']) ? ':' . intval($parts['port']) : '');
}

/** The device's page in NinjaOne, at your own web address. */
function mitNinjaDeviceUrl($device_id) {
    return rtrim(mitNinjaSettings()['web_url'], '/') . '/#/deviceDashboard/' . intval($device_id) . '/overview';
}

/*
 * ---------- the API ----------
 */

/** An API access token (client credentials), kept until a minute before it runs out. Throws on failure. */
function mitNinjaToken($fresh = false) {
    $s = mitNinjaSettings();
    $cached = mitDecryptSecret((string) mitSetting('ninja_token', ''));
    if (!$fresh && $cached !== '' && intval(mitSetting('ninja_token_expires', 0)) > time() + 60) {
        return $cached;
    }
    $secret = mitDecryptSecret((string) mitSetting('ninja_client_secret', ''));
    if ($s['client_id'] === '' || $secret === '') {
        throw new RuntimeException('NinjaOne is not set up - add the API client ID and secret in Admin > Integrations > NinjaOne.');
    }
    [$status, $json, $raw] = mitNinjaHttp('POST', $s['api_base'] . '/ws/oauth/token', [
        'grant_type' => 'client_credentials', 'client_id' => $s['client_id'], 'client_secret' => $secret, 'scope' => 'monitoring',
    ], null);
    if ($status !== 200 || empty($json['access_token'])) {
        $why = $json['error_description'] ?? ($json['error'] ?? ($status ? "HTTP $status" : $raw));
        throw new RuntimeException('NinjaOne refused the API keys: ' . mb_substr((string) $why, 0, 200));
    }
    mitSetSetting('ninja_token', mitEncryptSecret($json['access_token']));
    mitSetSetting('ninja_token_expires', time() + max(60, intval($json['expires_in'] ?? 3600)));
    return $json['access_token'];
}

/**
 * One HTTP request: [status, decoded JSON or null, raw body / error]. $form is sent as a form, $token as a bearer.
 * $GLOBALS['mit_ninja_timeout'] (seconds) shortens the wait for page requests (default 60).
 */
function mitNinjaHttp($method, $url, ?array $form, $token) {
    if (!mitDevAllows($method, $url)) {
        return [0, null, MIT_DEV_READ_ONLY];
    }
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => intval($GLOBALS['mit_ninja_timeout'] ?? 60), CURLOPT_CONNECTTIMEOUT => min(10, intval($GLOBALS['mit_ninja_timeout'] ?? 60)), CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return [0, null, $error];
    }
    $json = json_decode($body, true);
    return [$status, is_array($json) ? $json : null, $body];
}

/** GET from the API (v2 paths, e.g. /v2/organizations), with a new token on a 401. Returns the decoded JSON; throws on failure. */
function mitNinjaGet($path, array $query = []) {
    $url = mitNinjaSettings()['api_base'] . $path . ($query ? '?' . http_build_query($query) : '');
    [$status, $json, $raw] = mitNinjaHttp('GET', $url, null, mitNinjaToken());
    if ($status === 401) {
        [$status, $json, $raw] = mitNinjaHttp('GET', $url, null, mitNinjaToken(true));
    }
    if ($status !== 200 || $json === null) {
        throw new RuntimeException("NinjaOne $path: " . ($status ? "HTTP $status" : 'no answer') . ' - ' . mb_substr(strip_tags((string) $raw), 0, 200));
    }
    return $json;
}

/** Every row of a list, page by page (NinjaOne pages by "after" = the last id seen). */
function mitNinjaGetAll($path, array $query = [], $page_size = 500) {
    $rows = [];
    $after = null;
    for ($page = 0; $page < 200; $page++) {
        $batch = mitNinjaGet($path, $query + ['pageSize' => $page_size] + ($after !== null ? ['after' => $after] : []));
        if (!is_array($batch) || !$batch) {
            break;
        }
        $rows = array_merge($rows, $batch);
        $last = end($batch);
        if (count($batch) < $page_size || !isset($last['id'])) {
            break;
        }
        $after = $last['id'];
    }
    return $rows;
}

/** Can we reach NinjaOne with these keys? [ok, message]. */
function mitNinjaTest() {
    try {
        mitNinjaToken(true);
        $orgs = mitNinjaGet('/v2/organizations', ['pageSize' => 1000]);
        return [true, 'Connected - ' . count($orgs) . ' organization' . (count($orgs) === 1 ? '' : 's') . ' in NinjaOne.'];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/*
 * ---------- organizations and the mapping ----------
 */

/** Read NinjaOne's organizations into mit_ninja_orgs (new ones unmapped; names and device counts refreshed). Returns them by id. */
function mitNinjaRefreshOrgs(array $devices = null) {
    global $mysqli;
    $orgs = mitNinjaGetAll('/v2/organizations', [], 1000);
    $counts = [];
    foreach ((array) $devices as $device) {
        $counts[intval($device['organizationId'] ?? 0)] = ($counts[intval($device['organizationId'] ?? 0)] ?? 0) + 1;
    }
    $by_id = [];
    foreach ($orgs as $org) {
        $id = intval($org['id'] ?? 0);
        if (!$id) {
            continue;
        }
        $by_id[$id] = $org;
        $name = mysqli_real_escape_string($mysqli, mb_substr(trim(strip_tags((string) ($org['name'] ?? ''))), 0, 200));
        $count = $devices === null ? 'device_count' : intval($counts[$id] ?? 0);
        mysqli_query($mysqli, "INSERT INTO mit_ninja_orgs SET org_id = $id, name = '$name', device_count = " . ($devices === null ? 0 : $count) . ", seen_at = NOW()
            ON DUPLICATE KEY UPDATE name = '$name', device_count = $count, seen_at = NOW()");
    }
    return $by_id;
}

/** The mapping: org_id => row (name, client_id, client_name, status, device_count, seen_at). */
function mitNinjaOrgs() {
    global $mysqli;
    $rows = [];
    $result = mysqli_query($mysqli, "SELECT o.org_id, o.name, o.client_id, o.status, o.device_count, o.seen_at, o.updated_at, c.client_name, c.client_archived_at
        FROM mit_ninja_orgs o LEFT JOIN clients c ON c.client_id = o.client_id ORDER BY o.name");
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[intval($row['org_id'])] = $row;
    }
    return $rows;
}

/** Save one org's choice: a client id, 'ignore', or '' (not mapped). */
function mitNinjaSetMapping($org_id, $choice) {
    global $mysqli, $session_user_id;
    $org_id = intval($org_id);
    if ($choice === 'ignore') {
        $set = "client_id = NULL, status = 'ignored'";
    } elseif (intval($choice) > 0 && getFieldById('clients', intval($choice), 'client_id')) {
        $set = 'client_id = ' . intval($choice) . ", status = 'mapped'";
    } else {
        $set = "client_id = NULL, status = 'unmapped'";
    }
    mysqli_query($mysqli, "UPDATE mit_ninja_orgs SET $set, updated_by = " . intval($session_user_id ?? 0) . ", updated_at = NOW() WHERE org_id = $org_id");
}

/**
 * Take the mapping from the n8n workflow's JSON (its Plan Sync code: ORG_TO_CLIENT and IGNORED_ORGS). Orgs not yet read
 * from NinjaOne are added by id. Only clients that exist here are used. Returns [mapped, ignored, skipped client ids].
 */
function mitNinjaImportN8n($json_text) {
    global $mysqli, $session_user_id;
    $workflow = json_decode((string) $json_text, true);
    $code = '';
    foreach ((array) ($workflow['nodes'] ?? []) as $node) {
        if (isset($node['parameters']['jsCode']) && strpos($node['parameters']['jsCode'], 'ORG_TO_CLIENT') !== false) {
            $code = $node['parameters']['jsCode'];
            break;
        }
    }
    if ($code === '' || !preg_match('/ORG_TO_CLIENT\s*=\s*\{(.*?)\}/s', $code, $map_match)) {
        return [0, 0, [], 'That file is not the NinjaOne sync workflow - no ORG_TO_CLIENT list in it.'];
    }
    preg_match_all('/(\d+)\s*:\s*(\d+)/', preg_replace('#//[^\n]*#', '', $map_match[1]), $pairs, PREG_SET_ORDER);
    $ignored = [];
    if (preg_match('/IGNORED_ORGS\s*=\s*\[(.*?)\]/s', $code, $ignored_match)) {
        preg_match_all('/\d+/', $ignored_match[1], $ignored_ids);
        $ignored = array_map('intval', $ignored_ids[0]);
    }
    $mapped = 0;
    $skipped = [];
    $by = intval($session_user_id ?? 0);
    foreach ($pairs as [, $org_id, $client_id]) {
        $org_id = intval($org_id);
        $client_id = intval($client_id);
        if (!getFieldById('clients', $client_id, 'client_id')) {
            $skipped[] = $client_id;
            continue;
        }
        mysqli_query($mysqli, "INSERT INTO mit_ninja_orgs SET org_id = $org_id, client_id = $client_id, status = 'mapped', updated_by = $by, updated_at = NOW()
            ON DUPLICATE KEY UPDATE client_id = $client_id, status = 'mapped', updated_by = $by, updated_at = NOW()");
        $mapped++;
    }
    foreach ($ignored as $org_id) {
        mysqli_query($mysqli, "INSERT INTO mit_ninja_orgs SET org_id = $org_id, status = 'ignored', updated_by = $by, updated_at = NOW()
            ON DUPLICATE KEY UPDATE client_id = NULL, status = 'ignored', updated_by = $by, updated_at = NOW()");
    }
    return [$mapped, count($ignored), $skipped, ''];
}

/*
 * ---------- the sync ----------
 */

/** A value as ITFlow keeps it (escapeSql strips tags and trims), at most $max characters. */
function mitNinjaClean($value, $max = 200) {
    $text = trim(strip_tags(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string) $value)));
    return mb_substr($text, 0, $max);
}

/** Is this a serial / make / model that says nothing ("To Be Filled By O.E.M.", "0000000")? */
function mitNinjaIsPlaceholder($value) {
    $text = strtolower(preg_replace('/\s+/', ' ', mitNinjaClean($value)));
    if ($text === '' || in_array($text, MIT_NINJA_PLACEHOLDERS, true)) {
        return true;
    }
    $compact = preg_replace('/[\s.\-_:]/', '', $text);
    return strlen($compact) < 3 || preg_match('/^(.)\1+$/', $compact);
}

/** A NinjaOne date (epoch seconds or ms, or text) as Y-m-d, or null when it is not a sensible one. */
function mitNinjaDate($value) {
    if ($value === null || $value === '' || $value === 0) {
        return null;
    }
    if (is_numeric($value)) {
        $n = (float) $value;
        if ($n <= 0) {
            return null;
        }
        $time = (int) ($n > 1e11 ? $n / 1000 : $n);
    } else {
        $time = strtotime((string) $value);
    }
    if (!$time) {
        return null;
    }
    $year = intval(gmdate('Y', $time));
    return $year < 1990 || $year > 2100 ? null : gmdate('Y-m-d', $time);
}

/** Notes without the sync's block (and the old n8n workflow's loose lines) - what people wrote. */
function mitNinjaManualNotes($notes) {
    $text = str_replace(["\r\n", "\r"], "\n", (string) $notes);
    // The first n8n workflow stored literal "\n"
    if (strpos($text, 'NinjaOne Device ID:') !== false && strpos($text, '\\n') !== false) {
        $text = preg_replace('/\\\\r\\\\n|\\\\n/', "\n", $text);
    }
    $start = strpos($text, MIT_NINJA_BLOCK_START);
    $end = strpos($text, MIT_NINJA_BLOCK_END);
    if ($start !== false && $end !== false && $end > $start) {
        $text = substr($text, 0, $start) . substr($text, $end + strlen(MIT_NINJA_BLOCK_END));
    }
    if (strpos($text, 'NinjaOne Device ID:') !== false) {
        $text = implode("\n", array_filter(explode("\n", $text), function ($line) {
            return !preg_match('/^(NinjaOne Device ID|UID|Organization ID|Last Contact|Status|Public IP|Processor|Memory):/', trim($line));
        }));
    }
    return trim(preg_replace("/\n{3,}/", "\n\n", $text));
}

/** What a device's asset should look like. */
function mitNinjaDesired(array $device, $client_id, $org_name) {
    $system = (array) ($device['system'] ?? []);
    $os = (array) ($device['os'] ?? []);
    $name = mitNinjaClean($device['displayName'] ?? '') ?: (mitNinjaClean($device['systemName'] ?? '') ?: (mitNinjaClean($device['dnsName'] ?? '') ?: 'Unknown Device'));
    $make = mitNinjaIsPlaceholder($system['manufacturer'] ?? '') ? '' : mitNinjaClean($system['manufacturer']);
    $model = mitNinjaIsPlaceholder($system['model'] ?? '') ? '' : mitNinjaClean($system['model']);
    $type = MIT_NINJA_TYPES[$device['nodeClass'] ?? ''] ?? 'Other';
    if ($type === 'Desktop' && (preg_match('/laptop|notebook|portable|convertible|detachable/i', (string) ($system['chassisType'] ?? ''))
        || preg_match('/macbook|\b(latitude|thinkpad|elitebook|probook|surface (laptop|book|pro|go))\b/i', $model))) {
        $type = 'Laptop';
    }
    // A placeholder serial falls back to the NinjaOne UID (as the n8n workflow did, so its assets still match)
    $serial = '';
    foreach ([$system['serialNumber'] ?? '', $system['biosSerialNumber'] ?? ''] as $candidate) {
        if (!mitNinjaIsPlaceholder($candidate)) {
            $serial = mitNinjaClean($candidate);
            break;
        }
    }
    $serial = $serial !== '' ? $serial : mitNinjaClean($device['uid'] ?? '');
    $ips = array_values(array_filter(array_map('trim', (array) ($device['ipAddresses'] ?? []))));
    $v4 = function ($ip) {
        return (bool) preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $ip);
    };
    $ip = '';
    foreach ($ips as $candidate) {
        if ($v4($candidate) && strpos($candidate, '169.254.') !== 0 && strpos($candidate, '127.') !== 0) {
            $ip = $candidate;
            break;
        }
    }
    if ($ip === '') {
        $ip = current(array_filter($ips, $v4)) ?: ($ips[0] ?? mitNinjaClean($device['publicIP'] ?? ''));
    }
    $mac = '';
    foreach ((array) ($device['macAddresses'] ?? []) as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && !preg_match('/^(00[:-]?){5}00$/i', $candidate)) {
            $mac = $candidate;
            break;
        }
    }
    $os_name = mitNinjaClean($os['name'] ?? '');
    $os_full = $os_name !== '' && !empty($os['buildNumber']) ? $os_name . ' (Build ' . mitNinjaClean($os['buildNumber']) . ')' : $os_name;
    $warranty = (array) ($device['references']['warranty'] ?? ($device['warranty'] ?? []));
    $real = !empty($warranty['startDate']) && !empty($warranty['endDate']) && $warranty['startDate'] !== $warranty['endDate'];
    $memory = !empty($device['memory']['capacity']) ? round($device['memory']['capacity'] / 1024 / 1024 / 1024) : null;
    $cpu = mitNinjaClean($device['processors'][0]['name'] ?? '');
    $block = implode("\n", [
        MIT_NINJA_BLOCK_START,
        'NinjaOne Device ID: ' . intval($device['id']),
        'UID: ' . (mitNinjaClean($device['uid'] ?? '') ?: 'N/A'),
        'Organization ID: ' . intval($device['organizationId'] ?? 0) . ($org_name !== '' ? " ($org_name)" : ''),
        'Public IP: ' . (mitNinjaClean($device['publicIP'] ?? '') ?: 'N/A'),
        'Processor: ' . ($cpu ?: 'Unknown'),
        'Memory: ' . ($memory ? "$memory GB" : 'Unknown'),
        MIT_NINJA_BLOCK_END,
    ]);
    return [
        'client_id' => intval($client_id), 'name' => $name, 'type' => $type, 'make' => $make, 'model' => $model, 'serial' => $serial,
        'os' => mitNinjaClean($os_full), 'ip' => mitNinjaClean($ip), 'mac' => mitNinjaClean($mac),
        'purchase' => $real ? mitNinjaDate($warranty['manufacturerFulfillmentDate'] ?? $warranty['startDate']) : null,
        'warranty_end' => $real ? mitNinjaDate($warranty['endDate']) : null,
        'block' => $block,
    ];
}

/** Every ITFlow asset (archived too) with its primary interface and its NinjaOne link from the notes. */
function mitNinjaAssets() {
    global $mysqli;
    $assets = [];
    $result = mysqli_query($mysqli, "SELECT a.asset_id, a.asset_name, a.asset_type, a.asset_make, a.asset_model, a.asset_serial, a.asset_os, a.asset_notes,
            a.asset_purchase_date, a.asset_warranty_expire, a.asset_archived_at, a.asset_client_id, i.interface_id, i.interface_ip, i.interface_mac,
            d.device_id AS linked_device
        FROM assets a
        LEFT JOIN asset_interfaces i ON i.interface_asset_id = a.asset_id AND i.interface_primary = 1
        LEFT JOIN mit_ninja_devices d ON d.asset_id = a.asset_id
        ORDER BY a.asset_id");
    while ($row = mysqli_fetch_assoc($result)) {
        $id = intval($row['asset_id']);
        if (isset($assets[$id])) {
            continue;
        }
        $notes = str_replace('\\n', "\n", (string) $row['asset_notes']);
        $row['ninja_id'] = $row['linked_device'] !== null ? intval($row['linked_device'])
            : (preg_match('/NinjaOne Device ID:\s*(\d+)/i', $notes, $m) ? intval($m[1]) : null);
        $row['uid'] = preg_match('/\bUID:\s*([^\s\\\\]+)/i', $notes, $m) && $m[1] !== 'N/A' ? strtolower($m[1]) : null;
        $row['serial_key'] = strtolower(mitNinjaClean($row['asset_serial']));
        $row['active'] = $row['asset_archived_at'] === null;
        $row['client'] = intval($row['asset_client_id']);
        $assets[$id] = $row;
    }
    return $assets;
}

/**
 * Run the sync. $trigger 'cron' | 'manual'. Returns the run's report (also kept in mit_ninja_runs). Only one runs at a
 * time; a run that cannot reach NinjaOne writes nothing.
 */
function mitNinjaSync($trigger = 'cron') {
    global $mysqli, $session_user_id;
    if (!mitNinjaConfigured()) {
        return ['outcome' => 'failed', 'errors' => ['NinjaOne is not set up - add the API client ID and secret.']];
    }
    if (intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT GET_LOCK('mit_ninja_sync', 0)"))[0] ?? 0) !== 1) {
        return ['outcome' => 'busy', 'errors' => ['A sync is already running.']];
    }
    mysqli_query($mysqli, "INSERT INTO mit_ninja_runs SET trigger_kind = '" . ($trigger === 'manual' ? 'manual' : 'cron') . "', started_by = " . intval($session_user_id ?? 0));
    $run_id = mysqli_insert_id($mysqli);
    try {
        $report = mitNinjaSyncRun();
    } catch (Throwable $e) {
        $report = ['outcome' => 'failed', 'errors' => [$e->getMessage()], 'warnings' => [], 'totals' => []];
    }
    $totals = $report['totals'] ?? [];
    mysqli_query($mysqli, "UPDATE mit_ninja_runs SET finished_at = NOW(), outcome = '" . escapeSql($report['outcome']) . "',
        created = " . intval($totals['created'] ?? 0) . ", updated = " . intval($totals['updated'] ?? 0) . ", unchanged = " . intval($totals['unchanged'] ?? 0) . ",
        problems = " . (count($report['errors'] ?? []) + count($report['warnings'] ?? [])) . ",
        report = '" . mysqli_real_escape_string($mysqli, json_encode($report)) . "' WHERE run_id = $run_id");
    // Keep a year of runs
    mysqli_query($mysqli, "DELETE FROM mit_ninja_runs WHERE started_at < NOW() - INTERVAL 1 YEAR");
    mysqli_query($mysqli, "SELECT RELEASE_LOCK('mit_ninja_sync')");
    $report['run_id'] = $run_id;
    return $report;
}

/** The sync itself (mitNinjaSync wraps it): read both sides, plan, write, report. */
function mitNinjaSyncRun() {
    global $mysqli;
    $settings = mitNinjaSettings();
    $devices = mitNinjaGetAll('/v2/devices-detailed', [], 500);
    if (!$devices) {
        throw new RuntimeException('NinjaOne returned no devices - nothing to sync (check the API client has the Monitoring scope).');
    }
    $org_rows = mitNinjaRefreshOrgs($devices);
    $org_names = [];
    foreach ($org_rows as $id => $org) {
        $org_names[$id] = mitNinjaClean($org['name'] ?? '');
    }
    $mapping = mitNinjaOrgs();
    $live = [];
    foreach ($devices as $device) {
        $live[intval($device['id'])] = true;
    }

    // Warranty is a call per device - at most 150 a run, each device again after a week, and only for devices of mapped
    // organizations (the others are never written to an asset, so looking theirs up only made a run slow)
    $warranty = [];
    $known = [];
    $result = mysqli_query($mysqli, "SELECT device_id, warranty_start, warranty_end, warranty_checked_at FROM mit_ninja_devices");
    while ($row = mysqli_fetch_assoc($result)) {
        $known[intval($row['device_id'])] = $row;
    }
    $warranty_calls = 0;
    $warranty_failures = 0;
    foreach ($devices as $device) {
        $id = intval($device['id']);
        $device_org = $mapping[intval($device['organizationId'] ?? 0)] ?? null;
        if (!$device_org || $device_org['status'] !== 'mapped' || !intval($device_org['client_id'])) {
            continue;
        }
        $row = $known[$id] ?? null;
        $fresh = $row && $row['warranty_checked_at'] && strtotime($row['warranty_checked_at']) > time() - 7 * 86400;
        if ($fresh) {
            $warranty[$id] = ['startDate' => $row['warranty_start'], 'endDate' => $row['warranty_end']];
        } elseif ($warranty_calls < 150) {
            $warranty_calls++;
            try {
                $detail = mitNinjaGet("/v2/device/$id", ['expand' => 'warranty']);
                $w = (array) ($detail['references']['warranty'] ?? ($detail['warranty'] ?? []));
                $warranty[$id] = $w;
                $start = mitNinjaDate($w['manufacturerFulfillmentDate'] ?? ($w['startDate'] ?? null));
                $end = mitNinjaDate($w['endDate'] ?? null);
                mysqli_query($mysqli, "INSERT INTO mit_ninja_devices SET device_id = $id, warranty_start = " . ($start ? "'$start'" : 'NULL') . ", warranty_end = " . ($end ? "'$end'" : 'NULL') . ", warranty_checked_at = NOW()
                    ON DUPLICATE KEY UPDATE warranty_start = VALUES(warranty_start), warranty_end = VALUES(warranty_end), warranty_checked_at = NOW()");
            } catch (Throwable $e) {
                $warranty_failures++;
            }
        } elseif ($row) {
            $warranty[$id] = ['startDate' => $row['warranty_start'], 'endDate' => $row['warranty_end']];
        }
    }

    $clients = [];
    $result = mysqli_query($mysqli, "SELECT client_id, client_name, client_archived_at FROM clients");
    while ($row = mysqli_fetch_assoc($result)) {
        $clients[intval($row['client_id'])] = $row;
    }
    $client_label = function ($id) use ($clients) {
        return isset($clients[$id]) ? $clients[$id]['client_name'] . " (#$id)" : "#$id";
    };

    // Index ITFlow's assets
    $assets = mitNinjaAssets();
    $by_ninja = $by_uid = $by_serial = $by_name = [];
    foreach ($assets as $id => $asset) {
        if ($asset['ninja_id'] !== null) {
            $by_ninja[$asset['ninja_id']][] = $id;
        }
        if ($asset['uid']) {
            $by_uid[$asset['uid']][] = $id;
        }
        if ($asset['serial_key'] !== '') {
            $by_serial[$asset['serial_key']][] = $id;
        }
        if ($asset['active']) {
            $by_name[$asset['client'] . '|' . strtolower(mitNinjaClean($asset['asset_name']))][] = $id;
        }
    }
    $brief = function ($id) use ($assets, $client_label) {
        return ['asset_id' => $id, 'name' => $assets[$id]['asset_name'], 'client' => $client_label($assets[$id]['client']), 'archived' => !$assets[$id]['active']];
    };

    $plan = [];
    $claimed = [];
    $report = [
        'unmapped' => [], 'client_problems' => [], 'misplaced' => [], 'archived' => [], 'duplicates' => [], 'serial_conflicts' => [],
        'same_name' => [], 'orphans' => [], 'deleted' => [], 'per_org' => [],
    ];
    // Devices that were linked to an asset which has since been deleted in ITFlow (the link was cleared by the foreign key):
    // deleting it was a choice, so the sync doesn't make it again
    $gone = [];
    $result = mysqli_query($mysqli, "SELECT device_id FROM mit_ninja_devices WHERE asset_id IS NULL AND synced_at IS NOT NULL");
    while ($row = mysqli_fetch_row($result)) {
        $gone[intval($row[0])] = true;
    }
    $count = ['ignored' => 0, 'unchanged' => 0];
    $org_tally = function ($org_id, $what) use (&$report) {
        $report['per_org'][$org_id][$what] = ($report['per_org'][$org_id][$what] ?? 0) + 1;
    };
    usort($devices, function ($a, $b) {
        return intval($a['id']) <=> intval($b['id']);
    });

    foreach ($devices as $device) {
        $device_id = intval($device['id']);
        $org_id = intval($device['organizationId'] ?? 0);
        $map = $mapping[$org_id] ?? null;
        $label = mitNinjaClean($device['displayName'] ?? '') ?: (mitNinjaClean($device['systemName'] ?? '') ?: "device $device_id");
        if ($map && $map['status'] === 'ignored') {
            $count['ignored']++;
            continue;
        }
        $client_id = $map && $map['status'] === 'mapped' ? intval($map['client_id']) : 0;
        if (!$client_id) {
            $report['unmapped'][$org_id]['org'] = ($org_names[$org_id] ?? '') . " (#$org_id)";
            $report['unmapped'][$org_id]['devices'] = ($report['unmapped'][$org_id]['devices'] ?? 0) + 1;
            continue;
        }
        $client = $clients[$client_id] ?? null;
        if (!$client || $client['client_archived_at'] !== null) {
            $key = "$org_id-$client_id";
            $report['client_problems'][$key] = ['org' => ($org_names[$org_id] ?? '') . " (#$org_id)", 'client' => $client_label($client_id),
                'problem' => !$client ? 'the ITFlow client no longer exists' : 'the ITFlow client is archived', 'devices' => ($report['client_problems'][$key]['devices'] ?? 0) + 1];
            $org_tally($org_id, 'skipped');
            continue;
        }

        if (isset($warranty[$device_id])) {
            $device['references']['warranty'] = $warranty[$device_id];
        }
        $want = mitNinjaDesired($device, $client_id, $org_names[$org_id] ?? '');
        $uid = strtolower(mitNinjaClean($device['uid'] ?? ''));
        $identity = [];
        foreach (array_merge($by_ninja[$device_id] ?? [], $uid !== '' ? $by_uid[$uid] ?? [] : []) as $id) {
            if (isset($claimed[$id]) || in_array($id, $identity, true)) {
                continue;
            }
            if ($assets[$id]['ninja_id'] !== null && $assets[$id]['ninja_id'] !== $device_id) {
                continue; // a UID clash with another device's asset
            }
            $identity[] = $id;
        }
        sort($identity);
        $here = array_values(array_filter($identity, function ($id) use ($assets, $client_id) {
            return $assets[$id]['active'] && $assets[$id]['client'] === $client_id;
        }));
        $elsewhere = array_values(array_filter($identity, function ($id) use ($assets, $client_id) {
            return $assets[$id]['active'] && $assets[$id]['client'] !== $client_id;
        }));
        $archived = array_values(array_filter($identity, function ($id) use ($assets, $client_id) {
            return !$assets[$id]['active'] && $assets[$id]['client'] === $client_id;
        }));
        $serial_hits = $want['serial'] !== '' ? array_values(array_filter($by_serial[strtolower($want['serial'])] ?? [], function ($id) use ($claimed, $identity) {
            return !isset($claimed[$id]) && !in_array($id, $identity, true);
        })) : [];
        // A same-serial asset is taken over unless it belongs to another device NinjaOne still has
        $adopt = array_values(array_filter($serial_hits, function ($id) use ($assets, $live, $client_id) {
            return $assets[$id]['active'] && $assets[$id]['client'] === $client_id && ($assets[$id]['ninja_id'] === null || !isset($live[$assets[$id]['ninja_id']]));
        }));
        sort($adopt);
        $entry = ['device_id' => $device_id, 'name' => $want['name'], 'org' => ($org_names[$org_id] ?? '') . " (#$org_id)", 'serial' => $want['serial']];

        $target = null;
        if ($here) {
            $target = $here[0];
            if (count($here) > 1) {
                $report['duplicates'][] = $entry + ['keep' => $brief($target), 'extra' => array_map($brief, array_slice($here, 1))];
            }
            if ($elsewhere) {
                $report['misplaced'][] = $entry + ['expected' => $client_label($client_id), 'found' => array_map($brief, $elsewhere), 'action' => 'synced in the mapped client - archive the stale copy'];
            }
        } elseif ($adopt) {
            $target = $adopt[0];
            if (count($adopt) > 1) {
                $report['duplicates'][] = $entry + ['keep' => $brief($target), 'extra' => array_map($brief, array_slice($adopt, 1))];
            }
        } elseif ($elsewhere) {
            foreach ($elsewhere as $id) {
                $claimed[$id] = true;
            }
            $report['misplaced'][] = $entry + ['expected' => $client_label($client_id), 'found' => array_map($brief, $elsewhere),
                'action' => $settings['misplaced'] === 'create' ? 'made a new asset in the mapped client - archive the old one' : 'not synced - move the asset to the mapped client (Transfer to Client) or fix the mapping'];
            if ($settings['misplaced'] !== 'create') {
                $org_tally($org_id, 'skipped');
                // Its link is left as it is (the asset keeps its NinjaOne button while it is sorted out)
                $plan[] = ['action' => 'link', 'device' => $device, 'asset_id' => false];
                continue;
            }
        } elseif ($archived) {
            $report['archived'][] = $entry + ['client' => $client_label($client_id), 'assets' => array_map($brief, $archived)];
            $org_tally($org_id, 'skipped');
            $plan[] = ['action' => 'link', 'device' => $device, 'asset_id' => $archived[0]];
            continue;
        }

        if ($target !== null) {
            $claimed[$target] = true;
            $changes = mitNinjaChanges($assets[$target], $want);
            $plan[] = ['action' => $changes ? 'update' : 'link', 'device' => $device, 'asset_id' => $target, 'want' => $want, 'changes' => $changes, 'org_id' => $org_id];
            if ($changes) {
                $org_tally($org_id, 'updated');
            } else {
                $count['unchanged']++;
                $org_tally($org_id, 'unchanged');
            }
        } else {
            $others = array_values(array_filter($serial_hits, function ($id) use ($assets, $client_id) {
                return $assets[$id]['active'] && $assets[$id]['client'] !== $client_id;
            }));
            if ($others) {
                $report['serial_conflicts'][] = $entry + ['client' => $client_label($client_id), 'elsewhere' => array_map($brief, $others)];
            }
            if (isset($gone[$device_id])) {
                $report['deleted'][] = $entry + ['client' => $client_label($client_id)];
                $org_tally($org_id, 'skipped');
                $plan[] = ['action' => 'link', 'device' => $device, 'asset_id' => false];
                continue;
            }
            // Never taken over by its name: a separate asset is made, and the clash reported
            $named = $by_name[$client_id . '|' . strtolower($want['name'])] ?? [];
            if ($named) {
                $report['same_name'][] = $entry + ['client' => $client_label($client_id), 'existing' => array_map($brief, $named)];
            }
            $plan[] = ['action' => 'create', 'device' => $device, 'asset_id' => null, 'want' => $want, 'org_id' => $org_id];
            $org_tally($org_id, 'created');
        }
    }

    foreach ($assets as $id => $asset) {
        if ($asset['active'] && $asset['ninja_id'] !== null && !isset($live[$asset['ninja_id']]) && !isset($claimed[$id])) {
            $report['orphans'][] = $brief($id) + ['device_id' => $asset['ninja_id'], 'type' => $asset['asset_type']];
        }
    }

    $creates = count(array_filter($plan, function ($p) {
        return $p['action'] === 'create';
    }));
    if ($creates > $settings['brake']) {
        throw new RuntimeException("Safety brake: this run would make $creates new assets (the limit is {$settings['brake']}), so nothing was written. "
            . 'If that is expected - a big new client - raise the limit in Settings for one run; otherwise check the mapping.');
    }

    // Write
    $done = ['created' => 0, 'updated' => 0, 'failed' => []];
    foreach ($plan as $step) {
        $device = $step['device'];
        $asset_id = $step['asset_id'];
        try {
            if ($step['action'] === 'create') {
                $asset_id = mitNinjaCreateAsset($step['want']);
                $done['created']++;
            } elseif ($step['action'] === 'update') {
                mitNinjaUpdateAsset($assets[$asset_id], $step['want'], $step['changes']);
                $done['updated']++;
            }
        } catch (Throwable $e) {
            $done['failed'][] = ['device_id' => intval($device['id']), 'name' => $step['want']['name'] ?? '', 'error' => $e->getMessage()];
            $asset_id = $step['asset_id'];
        }
        // A create that failed is not recorded as synced (asset_id NULL + synced_at would read as "deleted in ITFlow"),
        // so the next run tries again
        mitNinjaRememberDevice($device, $asset_id ?: false);
    }
    // Devices of ignored / unmapped orgs are remembered too (status for the buttons, counts for the mapping)
    $planned = [];
    foreach ($plan as $step) {
        $planned[intval($step['device']['id'])] = true;
    }
    foreach ($devices as $device) {
        if (!isset($planned[intval($device['id'])])) {
            mitNinjaRememberDevice($device, false);
        }
    }

    $report['unmapped'] = array_values($report['unmapped']);
    $report['client_problems'] = array_values($report['client_problems']);
    $errors = $warnings = $notes = [];
    // Organizations not mapped yet are a choice still to make, not a fault (mapping a few to start with is normal) - a note,
    // listed in full on the report, that does not mark the run as needing attention
    if ($report['unmapped']) {
        $notes[] = count($report['unmapped']) . ' NinjaOne organization(s) are not mapped yet (' . array_sum(array_column($report['unmapped'], 'devices'))
            . ' device(s)) - their devices were left alone. Map them on the Mapping tab, or set them to not sync.';
    }
    if ($report['client_problems']) {
        $errors[] = count($report['client_problems']) . ' mapping(s) point at an ITFlow client that is gone or archived';
    }
    $unsynced = count(array_filter($report['misplaced'], function ($m) {
        return strpos($m['action'], 'not synced') === 0;
    }));
    if ($unsynced) {
        $errors[] = "$unsynced device(s) were not synced: their asset is under another client than the mapping says";
    }
    if ($done['failed']) {
        $errors[] = count($done['failed']) . ' asset(s) could not be written';
    }
    if ($report['same_name']) {
        $warnings[] = count($report['same_name']) . ' new asset(s) have the same name as an asset already in the client that is not linked to NinjaOne - both were kept; check whether they are the same machine';
    }
    if ($report['archived']) {
        $warnings[] = count($report['archived']) . ' device(s) are archived in ITFlow but still in NinjaOne - unarchive them if they are in use';
    }
    if ($report['duplicates']) {
        $warnings[] = count($report['duplicates']) . ' device(s) have more than one active asset in the same client';
    }
    if (count($report['misplaced']) - $unsynced) {
        $warnings[] = (count($report['misplaced']) - $unsynced) . ' device(s) have a stale copy of their asset under another client - archive it';
    }
    if ($report['serial_conflicts']) {
        $warnings[] = count($report['serial_conflicts']) . ' device(s) share a serial with an asset in another client';
    }
    if ($report['deleted']) {
        $warnings[] = count($report['deleted']) . ' device(s) had their asset deleted in ITFlow and were not made again - to sync one again, add an asset with its serial number in its client';
    }
    if ($report['orphans']) {
        $warnings[] = count($report['orphans']) . ' asset(s) are linked to NinjaOne devices that no longer exist (nothing was deleted - review them)';
    }
    if ($warranty_failures) {
        $warnings[] = "$warranty_failures warranty lookup(s) failed - tried again next run";
    }
    $report['failed'] = $done['failed'];
    $report['errors'] = $errors;
    $report['warnings'] = $warnings;
    $report['notes'] = $notes;
    $report['totals'] = [
        'devices' => count($devices), 'assets_read' => count($assets), 'created' => $done['created'], 'updated' => $done['updated'],
        'unchanged' => $count['unchanged'], 'ignored' => $count['ignored'],
        'unmapped' => array_sum(array_column($report['unmapped'], 'devices')), 'warranty_calls' => $warranty_calls,
    ];
    $report['outcome'] = $errors ? 'problems' : ($warnings ? 'warnings' : 'ok');
    foreach (['misplaced', 'archived', 'duplicates', 'serial_conflicts', 'same_name', 'orphans', 'deleted', 'failed'] as $list) {
        $report[$list] = array_slice($report[$list], 0, 200);
    }
    return $report;
}

/** What would change on this asset: list of field names (empty = nothing). */
function mitNinjaChanges(array $asset, array $want) {
    $changes = [];
    $same = function ($a, $b) {
        return mitNinjaClean($a) === mitNinjaClean($b);
    };
    if (!$same($asset['asset_name'], $want['name'])) {
        $changes[] = 'name';
    }
    $type = mitNinjaClean($asset['asset_type']);
    if ($want['type'] !== $type && (in_array($type, MIT_NINJA_OVERRIDABLE_TYPES, true) || ($type === 'Desktop' && $want['type'] === 'Laptop'))) {
        $changes[] = 'type';
    }
    foreach (['asset_make' => 'make', 'asset_model' => 'model', 'asset_os' => 'os'] as $field => $key) {
        if ($want[$key] !== '' && !$same($asset[$field], $want[$key])) {
            $changes[] = $key;
        }
    }
    if ($want['serial'] !== '' && strtolower(mitNinjaClean($asset['asset_serial'])) !== strtolower($want['serial'])) {
        $changes[] = 'serial';
    }
    if ($want['ip'] !== '' && mitNinjaClean($asset['interface_ip']) !== $want['ip']) {
        $changes[] = 'ip';
    }
    $mac = function ($m) {
        return str_replace('-', ':', strtoupper(trim((string) $m)));
    };
    if ($want['mac'] !== '' && $mac($asset['interface_mac']) !== $mac($want['mac'])) {
        $changes[] = 'mac';
    }
    if ($want['purchase'] && substr((string) $asset['asset_purchase_date'], 0, 10) !== $want['purchase']) {
        $changes[] = 'purchase date';
    }
    if ($want['warranty_end'] && substr((string) $asset['asset_warranty_expire'], 0, 10) !== $want['warranty_end']) {
        $changes[] = 'warranty';
    }
    if (mitNinjaNotes($asset['asset_notes'], $want['block']) !== trim(str_replace(["\r\n", "\r"], "\n", (string) $asset['asset_notes']))) {
        $changes[] = 'notes';
    }
    return $changes;
}

/** The notes with the sync's block on top and what people wrote below. */
function mitNinjaNotes($existing, $block) {
    $manual = mitNinjaManualNotes($existing);
    return $manual !== '' ? "$block\n\n$manual" : $block;
}

/** Make an asset for a device (with its primary interface). Returns its id. */
function mitNinjaCreateAsset(array $want) {
    global $mysqli;
    $q = function ($value) use ($mysqli) {
        return "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    };
    $ok = mysqli_query($mysqli, "INSERT INTO assets SET asset_name = " . $q($want['name']) . ", asset_type = " . $q($want['type']) . ", asset_make = " . $q($want['make']) . ",
        asset_model = " . $q($want['model']) . ", asset_serial = " . $q($want['serial']) . ", asset_os = " . $q($want['os']) . ", asset_status = 'Deployed',
        asset_purchase_date = " . ($want['purchase'] ? $q($want['purchase']) : 'NULL') . ", asset_warranty_expire = " . ($want['warranty_end'] ? $q($want['warranty_end']) : 'NULL') . ",
        asset_notes = " . $q($want['block']) . ", asset_client_id = " . intval($want['client_id']));
    if (!$ok) {
        throw new RuntimeException('the database refused the new asset');
    }
    $asset_id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO asset_interfaces SET interface_name = '1', interface_mac = " . $q($want['mac']) . ", interface_ip = " . $q($want['ip']) . ",
        interface_primary = 1, interface_asset_id = $asset_id");
    logAudit('Asset', 'Create', escapeSql($want['name'] . ' from NinjaOne'), intval($want['client_id']), $asset_id);
    return $asset_id;
}

/** Bring an asset in line with its device - only the fields that changed. */
function mitNinjaUpdateAsset(array $asset, array $want, array $changes) {
    global $mysqli;
    $q = function ($value) use ($mysqli) {
        return "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    };
    $set = [];
    $map = ['name' => ['asset_name', 'name'], 'type' => ['asset_type', 'type'], 'make' => ['asset_make', 'make'], 'model' => ['asset_model', 'model'],
        'os' => ['asset_os', 'os'], 'serial' => ['asset_serial', 'serial'], 'purchase date' => ['asset_purchase_date', 'purchase'], 'warranty' => ['asset_warranty_expire', 'warranty_end']];
    foreach ($changes as $change) {
        if (isset($map[$change])) {
            $set[] = $map[$change][0] . ' = ' . $q($want[$map[$change][1]]);
        }
    }
    if (in_array('notes', $changes, true)) {
        $set[] = 'asset_notes = ' . $q(mitNinjaNotes($asset['asset_notes'], $want['block']));
    }
    $asset_id = intval($asset['asset_id']);
    if ($set) {
        mysqli_query($mysqli, 'UPDATE assets SET ' . implode(', ', $set) . " WHERE asset_id = $asset_id");
    }
    if (in_array('ip', $changes, true) || in_array('mac', $changes, true)) {
        $ip = in_array('ip', $changes, true) ? $want['ip'] : (string) $asset['interface_ip'];
        $mac = in_array('mac', $changes, true) ? $want['mac'] : (string) $asset['interface_mac'];
        if ($asset['interface_id'] !== null) {
            mysqli_query($mysqli, 'UPDATE asset_interfaces SET interface_ip = ' . $q($ip) . ', interface_mac = ' . $q($mac) . ' WHERE interface_id = ' . intval($asset['interface_id']));
        } else {
            mysqli_query($mysqli, "INSERT INTO asset_interfaces SET interface_name = '1', interface_ip = " . $q($ip) . ', interface_mac = ' . $q($mac) . ", interface_primary = 1, interface_asset_id = $asset_id");
        }
    }
    logAudit('Asset', 'Edit', escapeSql($want['name'] . ' updated from NinjaOne (' . implode(', ', $changes) . ')'), intval($asset['client']), $asset_id);
}

/** Keep a device's row: what it is, its status, and its asset ($asset_id false = leave the link as it is). */
function mitNinjaRememberDevice(array $device, $asset_id) {
    global $mysqli;
    $id = intval($device['id']);
    $uid = mysqli_real_escape_string($mysqli, mb_substr((string) ($device['uid'] ?? ''), 0, 64));
    $name = mysqli_real_escape_string($mysqli, mitNinjaClean($device['displayName'] ?? ($device['systemName'] ?? '')));
    $class = mysqli_real_escape_string($mysqli, mb_substr((string) ($device['nodeClass'] ?? ''), 0, 50));
    $contact = isset($device['lastContact']) ? mitNinjaDateTime($device['lastContact']) : null;
    $fields = "uid = '$uid', org_id = " . intval($device['organizationId'] ?? 0) . ", name = '$name', node_class = '$class', offline = " . (!array_key_exists('offline', $device) || $device['offline'] === null ? 'NULL' : (empty($device['offline']) ? 0 : 1))
        . ', last_contact = ' . ($contact ? "'$contact'" : 'NULL') . ', status_checked_at = NOW(), seen_at = NOW()'
        . ($asset_id !== false ? ', asset_id = ' . ($asset_id ? intval($asset_id) : 'NULL') . ', synced_at = NOW()' : '');
    mysqli_query($mysqli, "INSERT INTO mit_ninja_devices SET device_id = $id, $fields ON DUPLICATE KEY UPDATE $fields");
}

/** A NinjaOne time (epoch seconds, possibly with a fraction, or ms) as Y-m-d H:i:s, or null. */
function mitNinjaDateTime($value) {
    if (!is_numeric($value) || (float) $value <= 0) {
        return null;
    }
    $n = (float) $value;
    return date('Y-m-d H:i:s', (int) ($n > 1e11 ? $n / 1000 : $n));
}

/** The last runs (newest first). */
function mitNinjaRuns($limit = 20) {
    global $mysqli;
    return mysqli_fetch_all(mysqli_query($mysqli, "SELECT r.run_id, r.started_at, r.finished_at, r.trigger_kind, r.outcome, r.created, r.updated, r.unchanged, r.problems, u.user_name
        FROM mit_ninja_runs r LEFT JOIN users u ON u.user_id = r.started_by ORDER BY r.run_id DESC LIMIT " . intval($limit)), MYSQLI_ASSOC);
}

/** One run's report, decoded, or null. */
function mitNinjaRunReport($run_id) {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT run_id, started_at, finished_at, outcome, report FROM mit_ninja_runs WHERE run_id = " . intval($run_id)));
    if (!$row) {
        return null;
    }
    $row['report'] = json_decode((string) $row['report'], true) ?: [];
    return $row;
}

/*
 * ---------- on tickets and assets: the NinjaOne button and the device's status ----------
 */

/**
 * The NinjaOne devices of these assets that the signed-in agent may see (Support view and the asset's client):
 * asset_id => [device_id, url, name, offline, last_contact, checked]. With $refresh, a status older than 5 minutes
 * is asked of NinjaOne again (at most 10 a call).
 */
function mitNinjaAssetDevices(array $asset_ids, $refresh = false) {
    global $mysqli;
    $asset_ids = array_values(array_filter(array_unique(array_map('intval', $asset_ids))));
    if (!$asset_ids || !mitNinjaReady() || lookupUserPermission('module_support') < 1) {
        return [];
    }
    $out = [];
    $result = mysqli_query($mysqli, "SELECT d.device_id, d.asset_id, d.name, d.offline, d.last_contact, d.status_checked_at, a.asset_client_id
        FROM mit_ninja_devices d JOIN assets a ON a.asset_id = d.asset_id WHERE d.asset_id IN (" . implode(',', $asset_ids) . ")" . clientScopeSql('a.asset_client_id'));
    $asked = 0;
    $started = microtime(true);
    $ninja_down = false;
    while ($row = mysqli_fetch_assoc($result)) {
        $stale = !$row['status_checked_at'] || strtotime($row['status_checked_at']) < time() - 300;
        // At most 10 asks and about 10 seconds a call; one that gets no answer stops the rest
        if ($refresh && $stale && $asked < 10 && !$ninja_down && microtime(true) - $started < 10 && mitNinjaConfigured()) {
            $asked++;
            try {
                $device = mitNinjaGet('/v2/device/' . intval($row['device_id']));
                // Same rule as mitNinjaRememberDevice: no offline field = not known
                $row['offline'] = !array_key_exists('offline', $device) || $device['offline'] === null ? null : (empty($device['offline']) ? 0 : 1);
                $row['last_contact'] = isset($device['lastContact']) ? mitNinjaDateTime($device['lastContact']) : $row['last_contact'];
                $row['status_checked_at'] = date('Y-m-d H:i:s');
                mysqli_query($mysqli, "UPDATE mit_ninja_devices SET offline = " . ($row['offline'] === null ? 'NULL' : intval($row['offline'])) . ", last_contact = " . ($row['last_contact'] ? "'" . escapeSql($row['last_contact']) . "'" : 'NULL')
                    . ", status_checked_at = NOW() WHERE device_id = " . intval($row['device_id']));
            } catch (Throwable $e) {
                // NinjaOne did not answer - show what we last knew
                $ninja_down = true;
            }
        }
        $out[intval($row['asset_id'])] = [
            'device_id' => intval($row['device_id']),
            'url' => mitNinjaDeviceUrl($row['device_id']),
            'name' => (string) $row['name'],
            'offline' => $row['offline'] === null ? null : (bool) intval($row['offline']),
            'last_contact' => $row['last_contact'] ? timeAgo($row['last_contact']) : '',
            'checked' => $row['status_checked_at'] ? timeAgo($row['status_checked_at']) : '',
        ];
    }
    return $out;
}

/** The script that adds the NinjaOne button and status beside asset links on the page ('' when NinjaOne is not in use). */
function mitNinjaPageScript() {
    if (!mitNinjaReady() || !mitNinjaSettings()['client_id'] || lookupUserPermission('module_support') < 1) {
        return '';
    }
    return '<script src="' . mitAsset('/agent/js/mit_ninjaone.js') . '" defer></script>';
}

/** The NinjaOne button for one asset, server-drawn (the asset page, $small in its window) - '' when it has no device. */
function mitNinjaAssetButton($asset_id, $small = false) {
    $device = mitNinjaAssetDevices([$asset_id])[intval($asset_id)] ?? null;
    if (!$device) {
        return '';
    }
    return '<a href="' . escapeHtml($device['url']) . '" target="_blank" rel="noopener" class="btn ' . ($small ? 'btn-sm ' : '') . 'btn-outline-primary text-nowrap" data-mit-ninja-asset="' . intval($asset_id) . '" title="Open in NinjaOne">'
        . '<i class="fas fa-fw fa-external-link-alt me-1"></i>NinjaOne</a>' . mitNinjaPageScript();
}
