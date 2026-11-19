<?php
/*
 * Mountaineer IT - test stand-in for MSP Align's REST API, read side (functions/mit_align.php). No constant: the tests type
 * http://127.0.0.1/align-mock/index.php into Admin > Integrations > MSP Align, so calls arrive as index.php/api/v1/...
 *   API (Authorization: Bearer msa_... - anything else 401): GET /api/v1 (the key: name, scopes), /clients (paged: page, per_page,
 *   meta.has_more; include_excluded), /clients/{id} (summary: devices, alignment, next_meeting - each only with its scope),
 *   /clients/{id}/health, /devices, /licenses. A missing scope is 403 {"error": {...}}; too_many (in /_set) makes the next n calls 429.
 *   Every API call is logged as "<METHOD> <path>" for /_calls; anything but GET is refused (405) and logged too.
 *   Control (JSON): /_reset, /_calls, POST /_set {scopes, clients: [{id, name, psa_id, health, band, devices, healthy, alignment,
 *   alignment_band, meeting, meeting_at, excluded}], devices: [{id, name, psa_asset_id, status, label, health, warranty, end_of_life,
 *   replace_by, planned, os_eos}], licenses: [{id, client_id, psa_id, name, expire, contract_end, renegotiate, auto_renew (default true), annual,
 *   billing_cycle}], too_many: n}.
 * Test: smoke 27-align.
 */
require __DIR__ . '/guard.php';

$empty = ['scopes' => ['clients:read', 'devices:read', 'licenses:read', 'health:read', 'alignment:read', 'meetings:read'],
    'clients' => [], 'devices' => [], 'licenses' => [], 'calls' => [], 'too_many' => 0];
$state = mockLoad('align', $empty) + $empty;
$path = mockPath();
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($path === '/_reset') {
    mockSave('align', $empty);
    mockJson(['ok' => true]);
}
if ($path === '/_calls') {
    mockJson($state['calls']);
}
if ($path === '/_set') {
    $body = mockBody();
    foreach (['scopes', 'clients', 'devices', 'licenses'] as $part) {
        if (isset($body[$part]) && is_array($body[$part])) {
            $state[$part] = array_values($body[$part]);
        }
    }
    $state['too_many'] = intval($body['too_many'] ?? 0);
    mockSave('align', $state);
    mockJson(['ok' => true]);
}
if (strpos($path, '/api/v1') !== 0) {
    mockJson(['error' => ['code' => 'not_found', 'message' => 'Not found']], 404);
}

// ---------- the API ----------
$route = rtrim(substr($path, 7), '/');
$state['calls'][] = "$method /api/v1$route" . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$state['calls'] = array_slice($state['calls'], -500);
$fail = function ($code, $status, $message) use (&$state) {
    mockSave('align', $state);
    mockJson(['error' => ['code' => $code, 'message' => $message]], $status);
};
// mod_php leaves Authorization out of $_SERVER - read it from the request's headers
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
foreach (function_exists('getallheaders') ? (getallheaders() ?: []) : [] as $name => $value) {
    if (strtolower($name) === 'authorization') {
        $auth = (string) $value;
    }
}
if (!preg_match('/^Bearer\s+(msa_\S+)$/i', trim($auth))) {
    $fail('unauthorized', 401, 'Missing or invalid API key');
}
if ($method !== 'GET' && $method !== 'HEAD') {
    $fail('method_not_allowed', 405, 'This stand-in only reads');
}
if ($state['too_many'] > 0) {
    $state['too_many']--;
    header('Retry-After: 1');
    $fail('rate_limited', 429, 'Too many requests');
}
mockSave('align', $state);
$scopes = (array) $state['scopes'];
$need = function ($scope) use ($scopes, $fail) {
    if (!in_array($scope, $scopes, true)) {
        $fail('forbidden', 403, "This key does not have the $scope scope");
    }
};
$page = function (array $rows) {
    $per = max(1, min(200, intval($_GET['per_page'] ?? 50)));
    $p = max(1, intval($_GET['page'] ?? 1));
    $data = array_slice($rows, ($p - 1) * $per, $per);
    mockJson(['data' => array_values($data), 'meta' => ['page' => $p, 'per_page' => $per, 'total' => count($rows), 'has_more' => $p * $per < count($rows)]]);
};
$client_row = fn($c) => ['id' => intval($c['id']), 'name' => (string) ($c['name'] ?? ''), 'psa_id' => (string) ($c['psa_id'] ?? ''),
    'itflow_client_id' => intval($c['psa_id'] ?? 0), 'excluded' => !empty($c['excluded'])];
$find_client = function ($id) use ($state, $fail) {
    foreach ($state['clients'] as $c) {
        if (intval($c['id']) === intval($id)) {
            return $c;
        }
    }
    $fail('not_found', 404, 'Client not found');
};

if ($route === '') {
    mockJson(['data' => ['name' => 'Smoke key', 'version' => '2.25.0', 'scopes' => $scopes]]);
}
if ($route === '/clients') {
    $need('clients:read');
    $rows = $state['clients'];
    if (!in_array(strtolower((string) ($_GET['include_excluded'] ?? '')), ['1', 'true'], true)) {
        $rows = array_filter($rows, fn($c) => empty($c['excluded']));
    }
    $page(array_map($client_row, array_values($rows)));
}
if (preg_match('~^/clients/(\d+)$~', $route, $m)) {
    $need('clients:read');
    $c = $find_client($m[1]);
    $summary = [];
    if (in_array('devices:read', $scopes, true) && isset($c['devices'])) {
        $summary['devices'] = ['total' => intval($c['devices']), 'healthy' => intval($c['healthy'] ?? $c['devices'])];
    }
    if (in_array('alignment:read', $scopes, true) && isset($c['alignment'])) {
        $summary['alignment'] = ['score' => intval($c['alignment']), 'band' => (string) ($c['alignment_band'] ?? '')];
    }
    if (in_array('meetings:read', $scopes, true)) {
        $summary['next_meeting'] = isset($c['meeting_at']) ? ['title' => (string) ($c['meeting'] ?? ''), 'starts_at' => (string) $c['meeting_at']] : null;
    }
    mockJson(['data' => $client_row($c) + ['summary' => $summary ?: new stdClass()]]);
}
if (preg_match('~^/clients/(\d+)/health$~', $route, $m)) {
    $need('health:read');
    $c = $find_client($m[1]);
    mockJson(['data' => ['client_id' => intval($c['id']), 'score' => isset($c['health']) ? intval($c['health']) : null,
        'band' => $c['band'] ?? null, 'areas' => []]]);
}
if ($route === '/devices') {
    $need('devices:read');
    $page(array_map(fn($d) => ['id' => intval($d['id']), 'name' => (string) ($d['name'] ?? ''), 'psa_asset_id' => (string) ($d['psa_asset_id'] ?? ''),
        'itflow_asset_id' => intval($d['psa_asset_id'] ?? 0),
        'warranty' => ['end' => $d['warranty'] ?? null],
        'os' => ['support_ends' => $d['os_eos'] ?? null],
        'lifecycle' => ['status' => (string) ($d['status'] ?? 'ok'), 'status_label' => (string) ($d['label'] ?? ''), 'health' => (string) ($d['health'] ?? 'ok'),
            'end_of_life' => $d['end_of_life'] ?? null, 'replace_by' => $d['replace_by'] ?? null,
            'planned_replacement' => isset($d['planned']) ? ['label' => (string) $d['planned']] : null]], $state['devices']));
}
if ($route === '/licenses') {
    $need('licenses:read');
    $page(array_map(fn($l) => ['id' => intval($l['id']), 'client_id' => intval($l['client_id'] ?? 0), 'psa_id' => (string) ($l['psa_id'] ?? ''),
        'name' => (string) ($l['name'] ?? ''), 'expire_date' => $l['expire'] ?? null, 'contract_end' => $l['contract_end'] ?? null,
        'renegotiate_date' => $l['renegotiate'] ?? null, 'auto_renew' => (bool) ($l['auto_renew'] ?? true), 'annual' => $l['annual'] ?? null,
        'billing_cycle' => $l['billing_cycle'] ?? 'annual'], $state['licenses']));
}
$fail('not_found', 404, 'Not found');
