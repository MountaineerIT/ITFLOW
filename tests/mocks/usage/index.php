<?php
/*
 * Mountaineer IT - the usage connectors' stand-in for TEST installs only (tests/mocks/install.php copies it to <install>/usage-mock/).
 * Never real accounts: functions/mit_usage_connectors.php reaches it through MIT_BITWARDEN_IDENTITY_URL / MIT_BITWARDEN_API_URL /
 * MIT_INGRAM_API_BASE in config.php (tests/mocks/usage/constants.php).
 *
 * Bitwarden (Public API, organization key):
 *   POST /bitwarden/identity/connect/token   client_credentials, scope api.organization, client_id organization.<uuid>, secret bw-secret
 *   GET  /bitwarden/api/public/members        {"object": "list", "data": [...]} - 4 members: 2 confirmed, 1 invited, 1 revoked
 *                                             (3 users, 2 confirmed)
 * Ingram Micro Cloud Marketplace:
 *   POST /ingram/token                        JSON {marketplace, login im-user, password im-pass} + X-Subscription-Key im-key
 *   GET  /ingram/customers                    2 customers: C-1001 "Smoke Ingram Customer", C-1002 "Usage Stand-in Other Customer"
 *   GET  /ingram/subscriptions?customerId=&status=active   C-1001: CFQ7TTC0LH18:0001 Microsoft 365 Business Basic 12 + 3 on two
 *                                             subscriptions (a suspended one and C-1002's are left out); limit / offset paging
 * Anything else is a JSON 404 (tests/smoke/12-connectors.spec.js tells the stand-in from Apache's HTML 404 that way).
 *
 * Controls: /_calls (requests since /_clear, secrets left out), /_clear.
 */
require __DIR__ . '/guard.php';

const BW_SECRET = 'bw-secret';
const BW_TOKEN = 'bw-mock-token';
const IM_LOGIN = 'im-user';
const IM_PASSWORD = 'im-pass';
const IM_KEY = 'im-key';
const IM_TOKEN = 'im-mock-token';

$state = mockLoad('usage', ['calls' => []]);
$path = mockPath();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/_calls') {
    mockJson(['calls' => $state['calls']]);
}
if ($path === '/_clear' || $path === '/_reset') {
    mockSave('usage', ['calls' => []]);
    mockJson(['ok' => true]);
}

$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
$state['calls'][] = "$method $path" . ($qs !== '' ? "?$qs" : '');
$state['calls'] = array_slice($state['calls'], -500);
mockSave('usage', $state);

/** A request header, '' when not sent. */
function usageHeader($name) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }
    if (isset($_SERVER["REDIRECT_$key"])) {
        return (string) $_SERVER["REDIRECT_$key"];
    }
    foreach (function_exists('getallheaders') ? getallheaders() : [] as $header => $value) {
        if (strcasecmp($header, $name) === 0) {
            return (string) $value;
        }
    }
    return '';
}

/** limit / offset paging as the Cloud Marketplace API does it. */
function usagePage(array $rows) {
    $limit = max(1, min(1000, intval($_GET['limit'] ?? 100)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    return ['data' => array_slice(array_values($rows), $offset, $limit), 'pagination' => ['total' => count($rows), 'limit' => $limit, 'offset' => $offset]];
}

// ---------- Bitwarden ----------
if ($path === '/bitwarden/identity/connect/token') {
    $form = mockBody();
    if ($method !== 'POST' || ($form['grant_type'] ?? '') !== 'client_credentials') {
        mockJson(['error' => 'unsupported_grant_type'], 400);
    }
    if (($form['scope'] ?? '') !== 'api.organization') {
        mockJson(['error' => 'invalid_scope'], 400);
    }
    if (!preg_match('/^organization\.[0-9a-f-]{36}$/i', (string) ($form['client_id'] ?? '')) || ($form['client_secret'] ?? '') !== BW_SECRET) {
        mockJson(['error' => 'invalid_client'], 400);
    }
    mockJson(['access_token' => BW_TOKEN, 'expires_in' => 3600, 'token_type' => 'Bearer', 'scope' => 'api.organization']);
}
if ($path === '/bitwarden/api/public/members') {
    if (usageHeader('Authorization') !== 'Bearer ' . BW_TOKEN) {
        mockJson(['object' => 'error', 'message' => 'Unauthorized'], 401);
    }
    $member = function ($n, $status) {
        return ['object' => 'member', 'id' => sprintf('aaaaaaaa-0000-0000-0000-%012d', $n), 'userId' => $status === 0 ? null : sprintf('bbbbbbbb-0000-0000-0000-%012d', $n),
            'name' => "Stand-in Member $n", 'email' => "member$n@example.invalid", 'twoFactorEnabled' => $n % 2 === 0, 'status' => $status, 'type' => 2,
            'accessAll' => false, 'externalId' => null, 'resetPasswordEnrolled' => false, 'collections' => []];
    };
    mockJson(['object' => 'list', 'data' => [$member(1, 2), $member(2, 2), $member(3, 0), $member(4, -1)], 'continuationToken' => null]);
}

// ---------- Ingram Micro ----------
if ($path === '/ingram/token') {
    $body = mockBody();
    if ($method !== 'POST') {
        mockJson(['message' => 'POST only'], 405);
    }
    if (usageHeader('X-Subscription-Key') !== IM_KEY) {
        mockJson(['statusCode' => 401, 'message' => 'Access denied due to invalid subscription key.'], 401);
    }
    if (($body['login'] ?? '') !== IM_LOGIN || ($body['password'] ?? '') !== IM_PASSWORD) {
        mockJson(['message' => 'Invalid login or password'], 401);
    }
    mockJson(['token' => IM_TOKEN, 'expires_in' => 3600]);
}
if (strpos($path, '/ingram/') === 0) {
    if (usageHeader('X-Subscription-Key') !== IM_KEY) {
        mockJson(['statusCode' => 401, 'message' => 'Access denied due to invalid subscription key.'], 401);
    }
    if (usageHeader('Authorization') !== 'Bearer ' . IM_TOKEN) {
        mockJson(['message' => 'Unauthorized'], 401);
    }
    if ($method !== 'GET') {
        mockJson(['message' => 'read only'], 405);
    }
    if ($path === '/ingram/customers') {
        mockJson(usagePage([
            ['id' => 'C-1001', 'name' => 'Smoke Ingram Customer', 'externalId' => 'ext-1001', 'status' => 'active'],
            ['id' => 'C-1002', 'name' => 'Usage Stand-in Other Customer', 'externalId' => 'ext-1002', 'status' => 'active'],
        ]));
    }
    if ($path === '/ingram/subscriptions') {
        $basic = function ($qty) {
            return ['mpn' => 'CFQ7TTC0LH18:0001', 'name' => 'Microsoft 365 Business Basic', 'quantity' => $qty, 'unit' => 'license'];
        };
        $all = [
            ['id' => 'S-2001', 'customerId' => 'C-1001', 'name' => 'Microsoft 365 (annual)', 'status' => 'active', 'products' => [$basic(12)]],
            ['id' => 'S-2002', 'customerId' => 'C-1001', 'name' => 'Microsoft 365 (monthly)', 'status' => 'active', 'products' => [$basic(3)]],
            ['id' => 'S-2003', 'customerId' => 'C-1001', 'name' => 'Old Exchange plan', 'status' => 'suspended',
                'products' => [['mpn' => 'CFQ7TTC0LH16:0001', 'name' => 'Exchange Online (Plan 1)', 'quantity' => 40]]],
            ['id' => 'S-2004', 'customerId' => 'C-1002', 'name' => 'Microsoft 365 (annual)', 'status' => 'active', 'products' => [$basic(25)]],
        ];
        $customer = (string) ($_GET['customerId'] ?? '');
        $status = (string) ($_GET['status'] ?? '');
        mockJson(usagePage(array_filter($all, function ($row) use ($customer, $status) {
            return ($customer === '' || $row['customerId'] === $customer) && ($status === '' || $row['status'] === $status);
        })));
    }
}

mockJson(['error' => 'not found', 'path' => $path], 404);
