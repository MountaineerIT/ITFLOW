<?php
/*
 * Mountaineer IT - NinjaOne stand-in for TEST installs only (tests/mocks/install.php copies it to <install>/ninja-mock/).
 * Never a real account: functions/mit_ninjaone.php reaches it through MIT_NINJA_API_BASE in config.php.
 *
 * The API (what mit_ninjaone.php calls):
 *   POST /ws/oauth/token                 client credentials (client_id ninja-client, secret ninja-secret, scope monitoring)
 *   GET  /v2/organizations               pageSize / after (= last id seen)
 *   GET  /v2/devices-detailed            pageSize / after
 *   GET  /v2/device/{id}[?expand=warranty]
 * Every API call needs the bearer token. Organizations: 1 Acme Ninja, 2 Beta Ninja, 3 Gamma Ninja (device 107 - left unmapped by
 * tests/smoke/22-ninjaone-sync.spec.js). Device 103 "Front Desk" carries serial SER-303.
 *
 * Controls (for the tests):
 *   /_calls            {"calls": ["GET /v2/devices-detailed?pageSize=500", ...]} - the API requests since the last /_clear
 *   /_clear            forget the calls
 *   /_slow?seconds=N   devices-detailed answers N seconds late (0 = at once)
 *   /_extra?on=1|0     two more Acme devices with no asset: 108 "Front Desk" (SER-808 - named like 103's asset) and 109 ACME-NEW (SER-909)
 *   /_reset            all of the above back to the start
 */
require __DIR__ . '/guard.php';

const NINJA_CLIENT_ID = 'ninja-client';
const NINJA_SECRET = 'ninja-secret';
const NINJA_TOKEN = 'ninja-mock-token';

$state = mockLoad('ninja', ['calls' => [], 'slow' => 0, 'extra' => false]);
$path = mockPath();
$query = $_GET;

// ---------- controls ----------
if ($path === '/_calls') {
    mockJson(['calls' => $state['calls']]);
}
if ($path === '/_clear') {
    $state['calls'] = [];
    mockSave('ninja', $state);
    mockJson(['ok' => true]);
}
if ($path === '/_slow') {
    $state['slow'] = max(0, min(120, intval($query['seconds'] ?? 0)));
    mockSave('ninja', $state);
    mockJson(['ok' => true, 'slow' => $state['slow']]);
}
if ($path === '/_extra') {
    $state['extra'] = !empty($query['on']);
    mockSave('ninja', $state);
    mockJson(['ok' => true, 'extra' => $state['extra']]);
}
if ($path === '/_reset') {
    mockSave('ninja', ['calls' => [], 'slow' => 0, 'extra' => false]);
    mockJson(['ok' => true]);
}

// ---------- the API ----------
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
$state['calls'][] = "$method $path" . ($qs !== '' ? "?$qs" : '');
$state['calls'] = array_slice($state['calls'], -500);
mockSave('ninja', $state);

if ($path === '/ws/oauth/token') {
    $form = mockBody();
    if ($method !== 'POST' || ($form['grant_type'] ?? '') !== 'client_credentials') {
        mockJson(['error' => 'unsupported_grant_type', 'error_description' => 'client_credentials only'], 400);
    }
    if (($form['client_id'] ?? '') !== NINJA_CLIENT_ID || ($form['client_secret'] ?? '') !== NINJA_SECRET) {
        mockJson(['error' => 'invalid_client', 'error_description' => 'Invalid client credentials'], 401);
    }
    mockJson(['access_token' => NINJA_TOKEN, 'token_type' => 'Bearer', 'expires_in' => 3600, 'scope' => 'monitoring']);
}

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strtolower($name) === 'authorization') {
            $auth = (string) $value;
        }
    }
}
if ($auth !== 'Bearer ' . NINJA_TOKEN) {
    mockJson(['error' => 'unauthorized'], 401);
}
if ($method !== 'GET') {
    mockJson(['error' => 'read only'], 405);
}

$orgs = [
    ['id' => 1, 'name' => 'Acme Ninja', 'description' => 'Stand-in organization', 'nodeApprovalMode' => 'AUTOMATIC'],
    ['id' => 2, 'name' => 'Beta Ninja', 'description' => 'Stand-in organization', 'nodeApprovalMode' => 'AUTOMATIC'],
    ['id' => 3, 'name' => 'Gamma Ninja', 'description' => 'Stand-in organization', 'nodeApprovalMode' => 'AUTOMATIC'],
];

/** A device as /v2/devices-detailed lists it. */
function ninjaDevice($id, $org, $name, $class, $serial, $make, $model, $os, $ip, $chassis = 'desktop') {
    return [
        'id' => $id, 'uid' => sprintf('0000%04d-aaaa-bbbb-cccc-%012d', $id, $id), 'organizationId' => $org, 'locationId' => $org,
        'nodeClass' => $class, 'nodeRoleId' => 1, 'approvalStatus' => 'APPROVED', 'offline' => $id === 104,
        'displayName' => $name, 'systemName' => $name, 'dnsName' => str_replace(' ', '-', strtolower($name)) . '.example.test',
        'created' => 1700000000.0 + $id, 'lastContact' => time() - 120 - $id, 'lastUpdate' => time() - 60,
        'publicIP' => '203.0.113.' . ($id - 100), 'ipAddresses' => ['169.254.1.' . ($id - 100), $ip, 'fe80::' . $id],
        'macAddresses' => ['00:00:00:00:00:00', sprintf('02:00:00:00:%02X:%02X', intdiv($id, 256), $id % 256)],
        'os' => ['name' => $os, 'buildNumber' => strpos($os, 'Windows') === 0 ? '22631' : '', 'architecture' => '64-bit'],
        'system' => ['manufacturer' => $make, 'model' => $model, 'serialNumber' => $serial, 'biosSerialNumber' => $serial, 'chassisType' => $chassis],
        'memory' => ['capacity' => 17179869184], 'processors' => [['name' => 'Stand-in CPU @ 3.00GHz', 'cores' => 8]],
    ];
}

$devices = [
    ninjaDevice(101, 1, 'ACME-DC01', 'WINDOWS_SERVER', 'SER-101', 'Dell Inc.', 'PowerEdge R250', 'Windows Server 2022 Standard', '10.1.0.10'),
    ninjaDevice(102, 1, 'ACME-WS01', 'WINDOWS_WORKSTATION', 'SER-102', 'Dell Inc.', 'OptiPlex 7010', 'Windows 11 Pro', '10.1.0.21'),
    ninjaDevice(103, 1, 'Front Desk', 'WINDOWS_WORKSTATION', 'SER-303', 'HP', 'EliteDesk 800 G6', 'Windows 11 Pro', '10.1.0.22'),
    ninjaDevice(104, 1, 'ACME-MBP01', 'MAC', 'SER-104', 'Apple Inc.', 'MacBook Pro', 'macOS 14.5', '10.1.0.23', 'laptop'),
    ninjaDevice(105, 2, 'BETA-WS01', 'WINDOWS_WORKSTATION', 'SER-105', 'Lenovo', 'ThinkCentre M70q', 'Windows 11 Pro', '10.2.0.21'),
    ninjaDevice(106, 2, 'BETA-SRV01', 'LINUX_SERVER', 'SER-106', 'Supermicro', 'SYS-5019', 'Ubuntu 24.04', '10.2.0.10'),
    ninjaDevice(107, 3, 'GAMMA-WS01', 'WINDOWS_WORKSTATION', 'SER-107', 'Dell Inc.', 'Latitude 5440', 'Windows 11 Pro', '10.3.0.21', 'laptop'),
];
if (!empty($state['extra'])) {
    $devices[] = ninjaDevice(108, 1, 'Front Desk', 'WINDOWS_WORKSTATION', 'SER-808', 'HP', 'EliteDesk 800 G9', 'Windows 11 Pro', '10.1.0.28');
    $devices[] = ninjaDevice(109, 1, 'ACME-NEW', 'WINDOWS_WORKSTATION', 'SER-909', 'Dell Inc.', 'OptiPlex 7020', 'Windows 11 Pro', '10.1.0.29');
}

/** One page of a list: ids after "after", at most pageSize. */
function ninjaPage(array $rows, array $query) {
    usort($rows, function ($a, $b) {
        return $a['id'] <=> $b['id'];
    });
    $after = isset($query['after']) ? intval($query['after']) : null;
    $size = max(1, min(1000, intval($query['pageSize'] ?? 1000)));
    $rows = array_values(array_filter($rows, function ($row) use ($after) {
        return $after === null || $row['id'] > $after;
    }));
    return array_slice($rows, 0, $size);
}

if ($path === '/v2/organizations') {
    mockJson(ninjaPage($orgs, $query));
}
if ($path === '/v2/devices-detailed') {
    if (!empty($state['slow'])) {
        sleep(intval($state['slow']));
    }
    mockJson(ninjaPage($devices, $query));
}
if (preg_match('#^/v2/device/(\d+)$#', $path, $m)) {
    foreach ($devices as $device) {
        if ($device['id'] === intval($m[1])) {
            if (($query['expand'] ?? '') === 'warranty') {
                // Epoch seconds, as NinjaOne gives them; a fixed warranty per device
                $start = gmmktime(0, 0, 0, 3, 1 + ($device['id'] % 20), 2024);
                $device['references']['warranty'] = ['startDate' => $start, 'endDate' => $start + 3 * 365 * 86400,
                    'manufacturerFulfillmentDate' => $start - 5 * 86400];
            }
            mockJson($device);
        }
    }
    mockJson(['error' => 'device not found'], 404);
}

mockJson(['error' => 'not found', 'path' => $path], 404);
