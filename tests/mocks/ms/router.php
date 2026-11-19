<?php
/*
 * Mountaineer IT - test stand-in for Microsoft's sign-in (login.microsoftonline.com) and Microsoft Graph (graph.microsoft.com/v1.0),
 * for functions/mit_m365.php, mit_m365_mailbox.php and mit_outlook.php. Its own server: php -S 127.0.0.1:8765 router.php (start.sh),
 * state in /srv/mockms/state.json, which the scenarios also read and change directly (under state.json.lock, swapped in whole):
 *   mail    - the mailbox: [{id, folder ('inbox' or a folder id), isRead, flag ('flagged' | 'notFlagged'), received, mime}]
 *   sent    - every message sent through Graph, as MIME text (newest last)
 *   events  - the Outlook calendar (one, whoever signs in)
 *   apps / servicePrincipals / grants - what the setup sign-in made; token requests are signed with an app's certificate
 *            (client assertion, checked against the keys on the app) - a client secret is refused
 *   codes / refresh / folders / drafts / uploads - bookkeeping
 * Login paths: /{tenant}/oauth2/v2.0/{devicecode|token|authorize}; Graph paths: /v1.0/...; ours: /_known, /_reset, /_upload/<id>.
 * Test installs only - never a real tenant.
 */
require __DIR__ . '/guard.php';

const MS_STATE = '/srv/mockms/state.json';

function msDefault() {
    return ['tenant' => msGuid(), 'mail' => [], 'sent' => [], 'events' => [], 'folders' => [], 'apps' => [], 'servicePrincipals' => [], 'grants' => [],
        'codes' => [], 'refresh' => [], 'drafts' => [], 'uploads' => []];
}

function msGuid() {
    $h = bin2hex(random_bytes(16));
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

function msLoad() {
    $state = json_decode((string) @file_get_contents(MS_STATE), true);
    return is_array($state) ? $state + msDefault() : msDefault();
}

/** Change the state under the same lock the scenarios take; returns what $change returned. */
function msEdit(callable $change) {
    $lock = fopen(MS_STATE . '.lock', 'c');
    @chmod(MS_STATE . '.lock', 0666);
    flock($lock, LOCK_EX);
    try {
        $state = msLoad();
        $result = $change($state);
        foreach (['sent' => 300, 'events' => 2000] as $list => $max) {
            if (count($state[$list]) > $max) {
                $state[$list] = array_slice($state[$list], -$max);
            }
        }
        file_put_contents(MS_STATE . '.tmp', json_encode($state, JSON_PRETTY_PRINT));
        @chmod(MS_STATE . '.tmp', 0666);
        rename(MS_STATE . '.tmp', MS_STATE);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function msOut($status, $data = null, $type = 'application/json') {
    http_response_code($status);
    if ($data === null) {
        exit;
    }
    header("Content-Type: $type");
    echo is_string($data) ? $data : json_encode($data);
    exit;
}

function msGraphError($status, $code, $message) {
    return [$status, ['error' => ['code' => $code, 'message' => $message]]];
}

function msB64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function msB64urlDecode($data) {
    return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/** An access token: a JWT the stand-in does not sign (callers only read its claims - tid, upn). */
function msAccessToken(array $state, $client_id, $user, $scope) {
    return msB64url(json_encode(['typ' => 'JWT', 'alg' => 'none'])) . '.' . msB64url(json_encode(['tid' => $state['tenant'], 'appid' => $client_id,
        'upn' => $user, 'scp' => $scope, 'iat' => time(), 'exp' => time() + 3600, 'jti' => bin2hex(random_bytes(6))])) . '.mock';
}

/** The person a bearer token was given to (the default mailbox for one the stand-in did not make, e.g. a scenario's "USERTOKEN"). */
function msTokenUser() {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    $parts = explode('.', preg_replace('/^Bearer\s+/i', '', $auth));
    $claims = isset($parts[1]) ? json_decode(msB64urlDecode($parts[1]), true) : null;
    return is_array($claims) && !empty($claims['upn']) ? (string) $claims['upn'] : 'admin@mountaineerit.test';
}

/** Graph scope names without the resource prefix. */
function msScopes($scope) {
    $out = [];
    foreach (preg_split('/\s+/', trim((string) $scope)) as $s) {
        if ($s !== '') {
            $out[] = preg_replace('#^https://graph\.microsoft\.com/#i', '', $s);
        }
    }
    return $out;
}

/** What a token for $client_id is granted when asked for $scope: .default = the app's organisation-wide Graph consent. */
function msGrantedScope(array $state, $client_id, $scope) {
    $wanted = msScopes($scope);
    if (!in_array('.default', $wanted, true)) {
        return implode(' ', $wanted);
    }
    $sp = '';
    foreach ($state['servicePrincipals'] as $row) {
        if ($row['appId'] === $client_id) {
            $sp = $row['id'];
        }
    }
    $graph_sp = msResourceSp('00000003-0000-0000-c000-000000000000')['id'];
    foreach ($state['grants'] as $grant) {
        if ($grant['clientId'] === $sp && $grant['resourceId'] === $graph_sp) {
            return $grant['scope'];
        }
    }
    return 'User.Read';
}

/** Microsoft's own resources (Graph, Exchange Online) with the delegated permissions the setup asks for. */
function msResourceSp($app_id) {
    $names = [
        '00000003-0000-0000-c000-000000000000' => ['User.Read', 'Calendars.ReadWrite', 'Mail.ReadWrite', 'Mail.ReadWrite.Shared', 'Mail.Send', 'Mail.Send.Shared',
            'offline_access', 'openid', 'profile', 'email', 'Application.ReadWrite.All', 'DelegatedPermissionGrant.ReadWrite.All'],
        '00000002-0000-0ff1-ce00-000000000000' => ['IMAP.AccessAsUser.All', 'SMTP.Send', 'EWS.AccessAsUser.All'],
    ];
    if (!isset($names[$app_id])) {
        return null;
    }
    $scopes = [];
    foreach ($names[$app_id] as $name) {
        $h = md5("$app_id/$name");
        $scopes[] = ['id' => substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12), 'value' => $name];
    }
    $h = md5("sp/$app_id");
    return ['id' => substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12),
        'appId' => $app_id, 'oauth2PermissionScopes' => $scopes];
}

/** A JWT signed with one of an app's certificates (client assertion, addKey proof): null when good, else why not. */
function msCheckSigned(array $app, $jwt, $issuer) {
    $parts = explode('.', (string) $jwt);
    if (count($parts) !== 3) {
        return 'not a JWT';
    }
    $header = json_decode(msB64urlDecode($parts[0]), true);
    $claims = json_decode(msB64urlDecode($parts[1]), true);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256') {
        return 'not an RS256 JWT';
    }
    if (($claims['iss'] ?? '') !== $issuer) {
        return 'wrong issuer';
    }
    if (intval($claims['exp'] ?? 0) < time()) {
        return 'expired';
    }
    foreach ($app['keyCredentials'] ?? [] as $key) {
        $der = base64_decode((string) $key['key']);
        if (!empty($header['x5t']) && msB64url(sha1($der, true)) !== $header['x5t']) {
            continue;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        $public = openssl_pkey_get_public($pem);
        if ($public && openssl_verify("$parts[0].$parts[1]", msB64urlDecode($parts[2]), $public, OPENSSL_ALGO_SHA256) === 1) {
            return null;
        }
    }
    return 'not signed with a certificate of this app';
}

function msFindApp(array $state, $field, $value) {
    foreach ($state['apps'] as $i => $app) {
        if (($app[$field] ?? null) === $value) {
            return $i;
        }
    }
    return null;
}

/*
 * ---------- sign-in ----------
 */

function msLogin($path) {
    if (!preg_match('#^/([^/]+)/oauth2/v2\.0/(devicecode|token|authorize)$#', $path, $m)) {
        msOut(404, ['error' => 'not_found', 'error_description' => "No such sign-in address: $path"]);
    }
    $what = $m[2];
    $in = $what === 'authorize' ? $_GET : mockBody();

    if ($what === 'devicecode') {
        $code = 'DC-' . bin2hex(random_bytes(12));
        msEdit(function (&$state) use ($code, $in) {
            $state['codes'][$code] = ['client_id' => (string) ($in['client_id'] ?? ''), 'user' => 'admin@mountaineerit.test', 'scope' => (string) ($in['scope'] ?? ''), 'at' => time()];
        });
        msOut(200, ['device_code' => $code, 'user_code' => 'MOCK' . rand(1000, 9999), 'verification_uri' => 'https://microsoft.com/devicelogin',
            'interval' => 1, 'expires_in' => 900, 'message' => 'Test stand-in: signed in already.']);
    }

    if ($what === 'authorize') {
        // A sign-in page (no automatic redirect, so a browser test that clicks Connect stays here); the code is on the page
        $code = 'AC-' . bin2hex(random_bytes(12));
        msEdit(function (&$state) use ($code, $in) {
            $state['codes'][$code] = ['client_id' => (string) ($in['client_id'] ?? ''), 'user' => (string) ($in['login_hint'] ?? 'admin@mountaineerit.test'),
                'scope' => (string) ($in['scope'] ?? ''), 'redirect_uri' => (string) ($in['redirect_uri'] ?? ''), 'at' => time()];
        });
        $back = (string) ($in['redirect_uri'] ?? '');
        $back .= (strpos($back, '?') === false ? '?' : '&') . http_build_query(['code' => $code, 'state' => (string) ($in['state'] ?? '')]);
        msOut(200, '<!doctype html><title>Microsoft sign-in (test stand-in)</title><p>Test stand-in sign-in for ' . htmlspecialchars((string) ($in['client_id'] ?? ''))
            . ' (prompt ' . htmlspecialchars((string) ($in['prompt'] ?? '')) . ').</p><p><a id="continue" data-code="' . htmlspecialchars($code) . '" href="'
            . htmlspecialchars($back) . '">Continue</a></p>', 'text/html');
    }

    // token
    $grant = (string) ($in['grant_type'] ?? '');
    $client_id = (string) ($in['client_id'] ?? '');
    $bad = function ($status, $error, $description) {
        msOut($status, ['error' => $error, 'error_description' => $description]);
    };
    $state = msLoad();
    if ($grant === 'urn:ietf:params:oauth:grant-type:device_code') {
        $row = $state['codes'][(string) ($in['device_code'] ?? '')] ?? null;
        if (!$row) {
            $bad(400, 'expired_token', 'AADSTS70019: the device code has expired (test stand-in).');
        }
        msEdit(function (&$state) use ($in) {
            unset($state['codes'][(string) $in['device_code']]);
        });
        msOut(200, ['token_type' => 'Bearer', 'scope' => implode(' ', msScopes($row['scope'])), 'expires_in' => 3600,
            'access_token' => msAccessToken($state, $client_id, $row['user'], implode(' ', msScopes($row['scope'])))]);
    }

    // Every other grant: a confidential client, proven with its certificate
    $index = msFindApp($state, 'appId', $client_id);
    if ($index === null) {
        $bad(400, 'unauthorized_client', "AADSTS700016: Application with identifier '$client_id' was not found in the directory (test stand-in).");
    }
    if (isset($in['client_secret'])) {
        $bad(401, 'invalid_client', 'AADSTS7000215: Invalid client secret provided (test stand-in - this app signs in with its certificate).');
    }
    if (($in['client_assertion_type'] ?? '') !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer' || empty($in['client_assertion'])) {
        $bad(401, 'invalid_client', 'AADSTS7000218: The request body must contain client_assertion or client_secret (test stand-in).');
    }
    $why = msCheckSigned($state['apps'][$index], $in['client_assertion'], $client_id);
    if ($why !== null) {
        $bad(401, 'invalid_client', "AADSTS700027: Client assertion failed signature validation - $why (test stand-in).");
    }

    if ($grant === 'client_credentials') {
        msOut(200, ['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => msAccessToken($state, $client_id, 'app:' . $client_id, '')]);
    }
    if ($grant === 'authorization_code') {
        $row = $state['codes'][(string) ($in['code'] ?? '')] ?? null;
        if (!$row || $row['client_id'] !== $client_id) {
            $bad(400, 'invalid_grant', 'AADSTS70000: The provided authorization code is not valid (test stand-in).');
        }
        $scope = implode(' ', msScopes($in['scope'] ?? $row['scope']));
        $refresh = 'RT-' . bin2hex(random_bytes(16));
        msEdit(function (&$state) use ($in, $refresh, $row, $client_id) {
            unset($state['codes'][(string) $in['code']]);
            $state['refresh'][$refresh] = ['client_id' => $client_id, 'user' => $row['user'], 'at' => time()];
        });
        msOut(200, ['token_type' => 'Bearer', 'scope' => $scope, 'expires_in' => 3600, 'refresh_token' => $refresh,
            'access_token' => msAccessToken($state, $client_id, $row['user'], $scope)]);
    }
    if ($grant === 'refresh_token') {
        $row = $state['refresh'][(string) ($in['refresh_token'] ?? '')] ?? null;
        if (!$row || $row['client_id'] !== $client_id) {
            $bad(400, 'invalid_grant', 'AADSTS9002313: Invalid request - the refresh token is not known (test stand-in).');
        }
        $scope = msGrantedScope($state, $client_id, $in['scope'] ?? 'https://graph.microsoft.com/.default');
        // The refresh token is not rotated: tests put settings back to an earlier one
        msOut(200, ['token_type' => 'Bearer', 'scope' => $scope, 'expires_in' => 3600, 'access_token' => msAccessToken($state, $client_id, $row['user'], $scope)]);
    }
    $bad(400, 'unsupported_grant_type', "AADSTS70003: grant_type $grant is not supported (test stand-in).");
}

/*
 * ---------- Graph ----------
 */

/** Graph's "$filter=a eq 'x' and b eq 'y'" as [a => x, b => y]. */
function msFilter($filter) {
    preg_match_all("/(\w+)\s+eq\s+'((?:[^']|'')*)'/", (string) $filter, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $one) {
        $out[$one[1]] = str_replace("''", "'", $one[2]);
    }
    return $out;
}

function msMatches(array $row, array $filter) {
    foreach ($filter as $field => $value) {
        if ((string) ($row[$field] ?? '') !== $value) {
            return false;
        }
    }
    return true;
}

function msBase() {
    return 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8765') . '/v1.0';
}

/** One page of $rows with an @odata.nextLink to the rest ($top / $skip). */
function msPage(array $rows, $path, array $query, $default_top = 10) {
    $top = max(1, intval($query['$top'] ?? $default_top));
    $skip = max(0, intval($query['$skip'] ?? 0));
    $out = ['value' => array_values(array_slice($rows, $skip, $top))];
    if ($skip + $top < count($rows)) {
        $query['$skip'] = $skip + $top;
        $out['@odata.nextLink'] = msBase() . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $out;
}

/** A Graph event time in UTC, as Graph gives it with Prefer: outlook.timezone="UTC". */
function msUtc(array $time) {
    $raw = substr((string) ($time['dateTime'] ?? ''), 0, 19);
    $zone = (string) ($time['timeZone'] ?? 'UTC');
    try {
        $dt = new DateTime($raw, new DateTimeZone($zone === '' ? 'UTC' : $zone));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return ['dateTime' => $dt->format('Y-m-d\TH:i:s') . '.0000000', 'timeZone' => 'UTC'];
    } catch (Throwable $e) {
        return ['dateTime' => $raw . '.0000000', 'timeZone' => 'UTC'];
    }
}

function msMessageOut(array $m) {
    return ['id' => $m['id'], 'receivedDateTime' => $m['received'] ?? '', 'isRead' => !empty($m['isRead']),
        'flag' => ['flagStatus' => is_array($m['flag'] ?? null) ? ($m['flag']['flagStatus'] ?? 'notFlagged') : ($m['flag'] ?? 'notFlagged')],
        'parentFolderId' => $m['folder'] ?? 'inbox'];
}

/** A big message built as a draft, written out as text for 'sent'. */
function msDraftText(array $draft) {
    $people = function ($list) {
        return implode(', ', array_map(function ($p) { return trim(($p['emailAddress']['name'] ?? '') . ' <' . ($p['emailAddress']['address'] ?? '') . '>'); }, $list ?? []));
    };
    $text = 'From: ' . $people([$draft['from'] ?? []]) . "\r\nTo: " . $people($draft['toRecipients'] ?? []) . "\r\n";
    if (!empty($draft['ccRecipients'])) {
        $text .= 'Cc: ' . $people($draft['ccRecipients']) . "\r\n";
    }
    $text .= 'Subject: ' . ($draft['subject'] ?? '') . "\r\nX-Mock-Sent-As: draft\r\n\r\n" . ($draft['body']['content'] ?? '') . "\r\n";
    foreach ($draft['attachments'] ?? [] as $a) {
        $text .= "\r\n[attachment " . ($a['name'] ?? '') . ' ' . intval($a['size'] ?? 0) . ' bytes' . (!empty($a['isInline']) ? ' inline' : '') . ']';
    }
    return $text;
}

/**
 * A Graph request: [status, data] (data null = no body; ['__raw' => text] = a non-JSON body). /users/<anyone> is the same mailbox and
 * calendar as /me.
 */
function msGraph($method, $path, array $query, $raw, array $headers) {
    $body = json_decode((string) $raw, true);
    $body = is_array($body) ? $body : [];
    $path = preg_replace('#^/users/[^/]+#', '/me', $path);
    $p = explode('/', ltrim($path, '/'));
    $p = array_map('rawurldecode', $p);

    // --- $batch
    if ($path === '/$batch' && $method === 'POST') {
        $responses = [];
        foreach ($body['requests'] ?? [] as $request) {
            $url = (string) ($request['url'] ?? '');
            $sub_path = (string) parse_url($url, PHP_URL_PATH);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $sub_query);
            $sub_body = isset($request['body']) ? json_encode($request['body']) : '';
            [$status, $data] = msGraph(strtoupper((string) ($request['method'] ?? 'GET')), '/' . ltrim($sub_path, '/'), $sub_query, $sub_body, (array) ($request['headers'] ?? []));
            $responses[] = ['id' => (string) ($request['id'] ?? ''), 'status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => $data];
        }
        return [200, ['responses' => $responses]];
    }

    // --- the directory (setup and certificate renewal)
    if ($p[0] === 'servicePrincipals') {
        if ($method === 'GET' && count($p) === 1) {
            $filter = msFilter($query['$filter'] ?? '');
            $own = msResourceSp((string) ($filter['appId'] ?? ''));
            if ($own) {
                return [200, ['value' => [$own]]];
            }
            $state = msLoad();
            return [200, ['value' => array_values(array_filter($state['servicePrincipals'], function ($row) use ($filter) { return msMatches($row, $filter); }))]];
        }
        if ($method === 'POST' && count($p) === 1) {
            return msEdit(function (&$state) use ($body) {
                if (msFindApp($state, 'appId', (string) ($body['appId'] ?? '')) === null) {
                    return msGraphError(400, 'Request_BadRequest', 'No application with that appId (test stand-in).');
                }
                foreach ($state['servicePrincipals'] as $row) {
                    if ($row['appId'] === $body['appId']) {
                        return msGraphError(409, 'Request_MultipleObjectsWithSameKeyValue', 'The service principal already exists.');
                    }
                }
                $row = ['id' => msGuid(), 'appId' => (string) $body['appId']];
                $state['servicePrincipals'][] = $row;
                return [201, $row];
            });
        }
    }
    if ($p[0] === 'oauth2PermissionGrants') {
        if ($method === 'GET' && count($p) === 1) {
            $filter = msFilter($query['$filter'] ?? '');
            $state = msLoad();
            return [200, ['value' => array_values(array_filter($state['grants'], function ($row) use ($filter) { return msMatches($row, $filter); }))]];
        }
        return msEdit(function (&$state) use ($method, $p, $body) {
            if ($method === 'POST' && count($p) === 1) {
                $row = ['id' => msGuid(), 'clientId' => (string) ($body['clientId'] ?? ''), 'consentType' => (string) ($body['consentType'] ?? ''),
                    'resourceId' => (string) ($body['resourceId'] ?? ''), 'scope' => (string) ($body['scope'] ?? '')];
                $state['grants'][] = $row;
                return [201, $row];
            }
            foreach ($state['grants'] as $i => $row) {
                if ($row['id'] === ($p[1] ?? '')) {
                    if ($method === 'PATCH') {
                        $state['grants'][$i]['scope'] = (string) ($body['scope'] ?? $row['scope']);
                        return [204, null];
                    }
                    if ($method === 'DELETE') {
                        array_splice($state['grants'], $i, 1);
                        return [204, null];
                    }
                }
            }
            return msGraphError(404, 'Request_ResourceNotFound', 'No such grant.');
        });
    }
    if ($p[0] === 'applications') {
        return msEdit(function (&$state) use ($method, $p, $body) {
            if ($method === 'POST' && count($p) === 1) {
                $app = ['id' => msGuid(), 'appId' => msGuid(), 'displayName' => (string) ($body['displayName'] ?? ''), 'web' => $body['web'] ?? [],
                    'requiredResourceAccess' => $body['requiredResourceAccess'] ?? [], 'keyCredentials' => []];
                foreach ($body['keyCredentials'] ?? [] as $key) {
                    $app['keyCredentials'][] = ['keyId' => msGuid(), 'key' => (string) ($key['key'] ?? ''), 'displayName' => (string) ($key['displayName'] ?? '')];
                }
                $state['apps'][] = $app;
                $shown = $app;
                $shown['keyCredentials'] = array_map(function ($k) { return ['keyId' => $k['keyId'], 'displayName' => $k['displayName']]; }, $app['keyCredentials']);
                return [201, $shown];
            }
            $index = msFindApp($state, 'id', (string) ($p[1] ?? ''));
            if ($index === null) {
                return msGraphError(404, 'Request_ResourceNotFound', 'No such application.');
            }
            $app = $state['apps'][$index];
            if ($method === 'GET' && count($p) === 2) {
                return [200, $app];
            }
            if ($method === 'PATCH' && count($p) === 2) {
                foreach (['requiredResourceAccess', 'web', 'displayName'] as $field) {
                    if (array_key_exists($field, $body)) {
                        $state['apps'][$index][$field] = $body[$field];
                    }
                }
                return [204, null];
            }
            if ($method === 'DELETE' && count($p) === 2) {
                array_splice($state['apps'], $index, 1);
                $state['servicePrincipals'] = array_values(array_filter($state['servicePrincipals'], function ($row) use ($app) { return $row['appId'] !== $app['appId']; }));
                return [204, null];
            }
            if ($method === 'POST' && in_array($p[2] ?? '', ['addKey', 'removeKey'], true)) {
                // An app changes its own keys, proven with a JWT signed by a key it has now
                $why = msCheckSigned($app, (string) ($body['proof'] ?? ''), $app['id']);
                if ($why !== null) {
                    return msGraphError(401, 'Authorization_RequestDenied', "The proof is not valid - $why (test stand-in).");
                }
                if ($p[2] === 'addKey') {
                    $key = ['keyId' => msGuid(), 'key' => (string) ($body['keyCredential']['key'] ?? ''), 'displayName' => (string) ($body['keyCredential']['displayName'] ?? '')];
                    $state['apps'][$index]['keyCredentials'][] = $key;
                    return [200, ['keyId' => $key['keyId'], 'displayName' => $key['displayName'], 'type' => 'AsymmetricX509Cert', 'usage' => 'Verify']];
                }
                $before = count($app['keyCredentials']);
                $state['apps'][$index]['keyCredentials'] = array_values(array_filter($app['keyCredentials'], function ($k) use ($body) { return $k['keyId'] !== ($body['keyId'] ?? ''); }));
                return $before === count($state['apps'][$index]['keyCredentials']) ? msGraphError(404, 'Request_ResourceNotFound', 'No such key.') : [204, null];
            }
            return msGraphError(400, 'BadRequest', 'Not supported by the test stand-in.');
        });
    }

    if ($p[0] !== 'me') {
        return msGraphError(400, 'BadRequest', "Not supported by the test stand-in: $method $path");
    }
    $what = $p[1] ?? '';

    // --- the signed-in person
    if ($what === '' && $method === 'GET') {
        $user = msTokenUser();
        $name = ucwords(str_replace(['.', '-', '_'], ' ', strtok($user, '@')));
        return [200, ['id' => md5($user), 'displayName' => $name, 'mail' => $user, 'userPrincipalName' => $user]];
    }

    // --- mail folders
    if ($what === 'mailFolders') {
        $state = msLoad();
        $folder = $p[2] ?? '';
        if ($method === 'GET' && $folder !== '' && count($p) === 3) {
            if (strcasecmp($folder, 'inbox') !== 0 && !in_array($folder, array_column($state['folders'], 'id'), true)) {
                return msGraphError(404, 'ErrorItemNotFound', 'The specified folder could not be found in the store.');
            }
            $in = array_filter($state['mail'], function ($m) use ($folder) { return strcasecmp((string) ($m['folder'] ?? 'inbox'), $folder) === 0; });
            return [200, ['id' => $folder, 'displayName' => strcasecmp($folder, 'inbox') === 0 ? 'Inbox' : $folder, 'totalItemCount' => count($in),
                'unreadItemCount' => count(array_filter($in, function ($m) { return empty($m['isRead']); }))]];
        }
        // Folder lists: /mailFolders (top level) or /mailFolders/<id>/childFolders
        if (count($p) === 2 || ($p[3] ?? '') === 'childFolders') {
            $parent = count($p) === 2 ? '' : (strcasecmp($folder, 'inbox') === 0 ? 'inbox' : $folder);
            if ($method === 'GET') {
                $filter = msFilter($query['$filter'] ?? '');
                $rows = array_filter($state['folders'], function ($f) use ($parent, $filter) {
                    return $f['parentId'] === $parent && (!isset($filter['displayName']) || strcasecmp($f['displayName'], $filter['displayName']) === 0);
                });
                if ($parent === '' && (!isset($filter['displayName']) || strcasecmp($filter['displayName'], 'Inbox') === 0)) {
                    array_unshift($rows, ['id' => 'inbox', 'displayName' => 'Inbox', 'parentId' => '']);
                }
                return [200, ['value' => array_values($rows)]];
            }
            if ($method === 'POST') {
                return msEdit(function (&$state) use ($parent, $body) {
                    foreach ($state['folders'] as $f) {
                        if ($f['parentId'] === $parent && strcasecmp($f['displayName'], (string) ($body['displayName'] ?? '')) === 0) {
                            return msGraphError(409, 'ErrorFolderExists', 'A folder with the specified name already exists.');
                        }
                    }
                    $row = ['id' => 'F-' . bin2hex(random_bytes(6)), 'displayName' => (string) ($body['displayName'] ?? ''), 'parentId' => $parent];
                    $state['folders'][] = $row;
                    return [201, $row];
                });
            }
        }
        // Messages in a folder: unread ones oldest first (the parser's query), paged
        if (($p[3] ?? '') === 'messages' && $method === 'GET') {
            $unread_only = strpos((string) ($query['$filter'] ?? ''), 'isRead eq false') !== false;
            $rows = array_values(array_filter($state['mail'], function ($m) use ($folder, $unread_only) {
                return strcasecmp((string) ($m['folder'] ?? 'inbox'), $folder) === 0 && (!$unread_only || empty($m['isRead']));
            }));
            $order = array_keys($rows);
            usort($order, function ($a, $b) use ($rows) { return [(string) ($rows[$a]['received'] ?? ''), $a] <=> [(string) ($rows[$b]['received'] ?? ''), $b]; });
            if (stripos((string) ($query['$orderby'] ?? ''), 'desc') !== false) {
                $order = array_reverse($order);
            }
            $rows = array_map(function ($i) use ($rows) { return msMessageOut($rows[$i]); }, $order);
            return [200, msPage($rows, $path, $query)];
        }
    }

    // --- messages
    if ($what === 'messages') {
        $id = $p[2] ?? '';
        $action = $p[3] ?? '';
        if ($method === 'GET' && $action === '$value') {
            foreach (msLoad()['mail'] as $m) {
                if ($m['id'] === $id) {
                    return [200, ['__raw' => (string) $m['mime'], '__type' => 'message/rfc822']];
                }
            }
            return msGraphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.');
        }
        return msEdit(function (&$state) use ($method, $id, $action, $body, $p) {
            // A draft (a message too big to send in one go)
            if ($method === 'POST' && $id === '') {
                $draft_id = 'D-' . bin2hex(random_bytes(8));
                $state['drafts'][$draft_id] = $body + ['attachments' => []];
                return [201, ['id' => $draft_id] + $body];
            }
            if (isset($state['drafts'][$id])) {
                if ($method === 'POST' && $action === 'attachments' && !isset($p[4])) {
                    $content = base64_decode((string) ($body['contentBytes'] ?? ''));
                    $state['drafts'][$id]['attachments'][] = ['name' => $body['name'] ?? '', 'size' => strlen($content), 'isInline' => !empty($body['isInline'])];
                    return [201, ['id' => 'A-' . bin2hex(random_bytes(4)), 'name' => $body['name'] ?? '']];
                }
                if ($method === 'POST' && $action === 'attachments' && ($p[4] ?? '') === 'createUploadSession') {
                    $item = $body['AttachmentItem'] ?? [];
                    $upload = 'U-' . bin2hex(random_bytes(8));
                    $state['uploads'][$upload] = ['draft' => $id, 'name' => $item['name'] ?? '', 'size' => intval($item['size'] ?? 0), 'got' => 0, 'isInline' => !empty($item['isInline'])];
                    return [201, ['uploadUrl' => 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8765') . "/_upload/$upload", 'expirationDateTime' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]];
                }
                if ($method === 'POST' && $action === 'send') {
                    $state['sent'][] = msDraftText($state['drafts'][$id]);
                    unset($state['drafts'][$id]);
                    return [202, null];
                }
                if ($method === 'DELETE' && $action === '') {
                    unset($state['drafts'][$id]);
                    return [204, null];
                }
            }
            foreach ($state['mail'] as $i => $m) {
                if ($m['id'] !== $id) {
                    continue;
                }
                if ($method === 'GET' && $action === '') {
                    return [200, msMessageOut($m)];
                }
                if ($method === 'PATCH' && $action === '') {
                    if (array_key_exists('isRead', $body)) {
                        $state['mail'][$i]['isRead'] = (bool) $body['isRead'];
                    }
                    if (isset($body['flag']['flagStatus'])) {
                        $state['mail'][$i]['flag'] = (string) $body['flag']['flagStatus'];
                    }
                    return [200, msMessageOut($state['mail'][$i])];
                }
                if ($method === 'POST' && ($action === 'move' || $action === 'copy')) {
                    $to = (string) ($body['destinationId'] ?? '');
                    if (strcasecmp($to, 'inbox') !== 0 && !in_array($to, array_column($state['folders'], 'id'), true)) {
                        return msGraphError(404, 'ErrorItemNotFound', 'The destination folder could not be found.');
                    }
                    if ($action === 'copy') {
                        $copy = $m;
                        $copy['id'] = $m['id'] . '-copy-' . bin2hex(random_bytes(3));
                        $copy['folder'] = $to;
                        $state['mail'][] = $copy;
                        return [201, msMessageOut($copy)];
                    }
                    $state['mail'][$i]['folder'] = $to;  // the id is kept (the scenarios find their messages by it)
                    return [201, msMessageOut($state['mail'][$i])];
                }
                if ($method === 'DELETE' && $action === '') {
                    array_splice($state['mail'], $i, 1);
                    return [204, null];
                }
            }
            return msGraphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.');
        });
    }

    // --- sending a finished message (its MIME, base64)
    if ($what === 'sendMail' && $method === 'POST') {
        $mime = base64_decode(trim((string) $raw), true);
        if ($mime === false || strpos($mime, "\n") === false) {
            return msGraphError(400, 'ErrorMimeContentInvalidBase64String', 'Invalid base64 string for MIME content (test stand-in).');
        }
        if (strlen($raw) > 4 * 1024 * 1024) {
            return msGraphError(413, 'ErrorRequestEntityTooLarge', 'The request is larger than 4 MB.');
        }
        return msEdit(function (&$state) use ($mime) {
            $state['sent'][] = $mime;
            return [202, null];
        });
    }

    // --- the calendar
    if ($what === 'calendarView' && $method === 'GET') {
        $from = gmdate('Y-m-d\TH:i:s', strtotime((string) ($query['startDateTime'] ?? '1970-01-01T00:00:00Z')));
        $to = gmdate('Y-m-d\TH:i:s', strtotime((string) ($query['endDateTime'] ?? '2100-01-01T00:00:00Z')));
        $rows = array_values(array_filter(msLoad()['events'], function ($e) use ($from, $to) {
            return substr($e['start']['dateTime'], 0, 19) < $to && substr($e['end']['dateTime'], 0, 19) > $from;
        }));
        usort($rows, function ($a, $b) { return strcmp($a['start']['dateTime'], $b['start']['dateTime']); });
        return [200, msPage($rows, $path, $query, 10)];
    }
    if ($what === 'events') {
        $id = $p[2] ?? '';
        if ($method === 'GET' && $id !== '') {
            foreach (msLoad()['events'] as $e) {
                if ($e['id'] === $id) {
                    return [200, $e];
                }
            }
            return msGraphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.');
        }
        if ($method === 'GET') {
            return [200, msPage(msLoad()['events'], $path, $query)];
        }
        return msEdit(function (&$state) use ($method, $id, $body) {
            if ($method === 'POST' && $id === '') {
                if (empty($body['start']['dateTime']) || empty($body['end']['dateTime'])) {
                    return msGraphError(400, 'ErrorInvalidRequest', 'An event needs a start and an end.');
                }
                if (!empty($body['transactionId'])) {
                    foreach ($state['events'] as $e) {
                        if (($e['transactionId'] ?? '') === $body['transactionId']) {
                            return [201, $e];  // a retry: the same event, not a second one
                        }
                    }
                }
                $event = ['id' => 'EV-' . bin2hex(random_bytes(10))] + $body;
                $event['start'] = msUtc($body['start']);
                $event['end'] = msUtc($body['end']);
                $event += ['showAs' => 'busy', 'isAllDay' => false, 'isCancelled' => false, 'categories' => [], 'sensitivity' => 'normal',
                    'iCalUId' => 'mock-' . bin2hex(random_bytes(8)), 'location' => ['displayName' => ''], 'subject' => ''];
                $state['events'][] = $event;
                return [201, $event];
            }
            foreach ($state['events'] as $i => $e) {
                if ($e['id'] !== $id) {
                    continue;
                }
                if ($method === 'PATCH') {
                    foreach ($body as $field => $value) {
                        $state['events'][$i][$field] = in_array($field, ['start', 'end'], true) ? msUtc((array) $value) : $value;
                    }
                    return [200, $state['events'][$i]];
                }
                if ($method === 'DELETE') {
                    array_splice($state['events'], $i, 1);
                    return [204, null];
                }
            }
            return msGraphError(404, 'ErrorItemNotFound', 'The specified object was not found in the store.');
        });
    }
    return msGraphError(400, 'BadRequest', "Not supported by the test stand-in: $method $path");
}

/*
 * ---------- the request ----------
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$raw = (string) file_get_contents('php://input');

// Ours: which apps / refresh tokens the stand-in knows (tests/mocks/ms/setup.php), a reset, upload sessions
if ($path === '/_known') {
    $state = msLoad();
    msOut(200, ['tenant' => $state['tenant'], 'app' => msFindApp($state, 'appId', (string) ($_GET['app'] ?? '')) !== null,
        'refresh' => isset($state['refresh'][(string) ($_GET['refresh'] ?? '')]), 'apps' => count($state['apps'])]);
}
if ($path === '/_reset' && $method === 'POST') {
    msEdit(function (&$state) {
        $state['mail'] = [];
        $state['sent'] = [];
        $state['events'] = [];
        $state['drafts'] = [];
        $state['uploads'] = [];
    });
    msOut(200, ['ok' => true]);
}
if (preg_match('#^/_upload/([A-Za-z0-9-]+)$#', $path, $m) && $method === 'PUT') {
    [$status, $data] = msEdit(function (&$state) use ($m, $raw) {
        $upload = $state['uploads'][$m[1]] ?? null;
        if (!$upload || !isset($state['drafts'][$upload['draft']])) {
            return msGraphError(404, 'ItemNotFound', 'No such upload session.');
        }
        if (!preg_match('#bytes (\d+)-(\d+)/(\d+)#', (string) ($_SERVER['HTTP_CONTENT_RANGE'] ?? ''), $range) || intval($range[1]) !== $upload['got']
            || intval($range[2]) - intval($range[1]) + 1 !== strlen($raw)) {
            return msGraphError(416, 'InvalidRange', 'The Content-Range does not follow on (test stand-in).');
        }
        $state['uploads'][$m[1]]['got'] += strlen($raw);
        if ($state['uploads'][$m[1]]['got'] < $upload['size']) {
            return [200, ['nextExpectedRanges' => [$state['uploads'][$m[1]]['got'] . '-']]];
        }
        $state['drafts'][$upload['draft']]['attachments'][] = ['name' => $upload['name'], 'size' => $upload['size'], 'isInline' => $upload['isInline']];
        unset($state['uploads'][$m[1]]);
        return [201, null];
    });
    msOut($status, $data);
}

if (strpos($path, '/v1.0/') === 0 || $path === '/v1.0') {
    if (!preg_match('/^Bearer\s+\S+/i', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))) {
        msOut(401, ['error' => ['code' => 'InvalidAuthenticationToken', 'message' => 'Access token is empty.']]);
    }
    $query = [];
    // Graph's $-parameters as sent ("$filter", "$top") - PHP's own parse would keep them, but read the raw query to be sure
    foreach (explode('&', (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY)) as $pair) {
        if ($pair !== '') {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $query[rawurldecode($k)] = rawurldecode(str_replace('+', ' ', $v));
        }
    }
    [$status, $data] = msGraph($method, substr($path, 5) ?: '/', $query, $raw, []);
    if (is_array($data) && isset($data['__raw'])) {
        msOut($status, $data['__raw'], $data['__type'] ?? 'text/plain');
    }
    msOut($status, $data);
}

msLogin($path);
