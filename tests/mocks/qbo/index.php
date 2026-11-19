<?php
/*
 * Mountaineer IT - test stand-in for Intuit's QuickBooks Online (served as <install>/qbo-mock/index.php, see tests/mocks/install.php).
 * Never a real company. What functions/mit_qbo*.php and admin/mit_qbo_callback.php call:
 *   GET  /connect/oauth2                    Intuit's sign-in: straight back to redirect_uri with code, state and realmId 9130355
 *   POST /oauth2/v1/tokens/bearer           authorization_code / refresh_token -> at-... / rt-... (a new refresh token every time)
 *   POST /oauth2/v1/tokens/revoke           200
 *   /v3/company/{realm}/...                 the API (Bearer token needed): companyinfo/{id}; query?query=SELECT * FROM <Entity>
 *                                           [WHERE a = 'x' AND b IN (...) AND Active = true] [STARTPOSITION n] [MAXRESULTS n];
 *                                           GET item|invoice|customer|term|account|taxcode/{id}; POST item|customer|term (create, or
 *                                           Id + SyncToken [+ sparse] update); POST invoice (create - works TotalAmt, tax and
 *                                           DueDate from a term out -, or ?operation=void); POST invoice/{id}/send[?sendTo=]
 * Test-only routes:
 *   /_reset                                 back to the starting company (below)
 *   /_state                                 everything it holds (invoices, items, terms, customers, accounts, tax_codes, sends ...)
 *   /_pay?doc=<DocNumber>|id=<Id>[&amount=] a payment there on the newest such invoice: amount off the balance (none = all)
 * Starting company "Sandbox Company_US_1": items 1 Widget, 2 Services, 3 Old router (inactive); income accounts 79 Sales of
 * Product Income, 80 Hardware Sales, 81 Labor Income, 82 Interest Earned (other income), expense / COGS accounts 83-85, bank 90
 * Checking, A/R 91; terms 1 Due on receipt, 2 Net 15, 3 Net 30, 4 Due on the 1st (date driven), 5 Net 45 (inactive); tax codes
 * TAX, NON, 2 California 8%, 5 State tax 7%.
 */
require __DIR__ . '/guard.php';

const QBO_MOCK = 'qbo';
const QBO_REALM = '9130355';
const QBO_COMPANY = 'Sandbox Company_US_1';

function qboStart() {
    $account = function ($id, $name, $type) {
        return ['Id' => (string) $id, 'Name' => $name, 'FullyQualifiedName' => $name, 'AccountType' => $type, 'Active' => true, 'SyncToken' => '0'];
    };
    $item = function ($id, $name, $type, $account, $active = true, $price = 0) {
        return ['Id' => (string) $id, 'Name' => $name, 'FullyQualifiedName' => $name, 'Type' => $type, 'Active' => $active, 'UnitPrice' => $price,
            'IncomeAccountRef' => ['value' => (string) $account], 'SyncToken' => '0'];
    };
    $term = function ($id, $name, $days, $active = true) {
        return ['Id' => (string) $id, 'Name' => $name, 'Type' => 'STANDARD', 'DueDays' => $days, 'Active' => $active, 'SyncToken' => '0'];
    };
    $customer = function ($id, $name, $email = '') {
        $row = ['Id' => (string) $id, 'DisplayName' => $name, 'CompanyName' => $name, 'FullyQualifiedName' => $name, 'Active' => true, 'SyncToken' => '0'];
        if ($email !== '') {
            $row['PrimaryEmailAddr'] = ['Address' => $email];
        }
        return $row;
    };
    return [
        'next_id' => 200,
        'accounts' => [
            $account(79, 'Sales of Product Income', 'Income'),
            $account(80, 'Hardware Sales', 'Income'),
            $account(81, 'Labor Income', 'Income'),
            $account(82, 'Interest Earned', 'Other Income'),
            $account(83, 'Discounts given', 'Expense'),
            $account(84, 'Cost of Goods Sold', 'Cost of Goods Sold'),
            $account(85, 'Bank Charges', 'Other Expense'),
            $account(90, 'Checking', 'Bank'),
            $account(91, 'Accounts Receivable (A/R)', 'Accounts Receivable'),
        ],
        'items' => [
            $item(1, 'Widget', 'NonInventory', 79, true, 50),
            $item(2, 'Services', 'Service', 79, true, 100),
            $item(3, 'Old router', 'NonInventory', 80, false, 40),
        ],
        'terms' => [
            $term(1, 'Due on receipt', 0),
            $term(2, 'Net 15', 15),
            $term(3, 'Net 30', 30),
            ['Id' => '4', 'Name' => 'Due on the 1st', 'Type' => 'DATE_DRIVEN', 'DayOfMonthDue' => 1, 'DueNextMonthDays' => 0, 'Active' => true, 'SyncToken' => '0'],
            $term(5, 'Net 45', 45, false),
        ],
        'tax_codes' => [
            ['Id' => 'TAX', 'Name' => 'TAX', 'Description' => 'TAX', 'Active' => true, 'Taxable' => true, 'rate' => 0],
            ['Id' => 'NON', 'Name' => 'NON', 'Description' => 'NON', 'Active' => true, 'Taxable' => false, 'rate' => 0],
            ['Id' => '2', 'Name' => 'California', 'Description' => 'California sales tax', 'Active' => true, 'Taxable' => true, 'rate' => 8],
            ['Id' => '5', 'Name' => 'State tax 7%', 'Description' => 'State tax', 'Active' => true, 'Taxable' => true, 'rate' => 7],
        ],
        'customers' => [
            $customer(1, "Amy's Bird Sanctuary", 'birds@example.invalid'),
            $customer(2, 'Bill\'s Windsurf Shop', 'surf@example.invalid'),
            $customer(3, 'Cool Cars'),
        ],
        'invoices' => [],
        'sends' => [],
        'tokens' => [],
        'revoked' => [],
        'codes' => [],
    ];
}

/** A QuickBooks-style fault answer. */
function qboFault($status, $message, $detail = '', $code = '6000') {
    mockJson(['Fault' => ['Error' => [['Message' => $message, 'Detail' => $detail !== '' ? $detail : $message, 'code' => $code]], 'type' => 'ValidationFault'],
        'time' => date('c')], $status);
}

/** Every request in turn (the state is one file). */
$lock = fopen(mockStateFile(QBO_MOCK) . '.lock', 'c');
@chmod(mockStateFile(QBO_MOCK) . '.lock', 0666);
flock($lock, LOCK_EX);
$state = mockLoad(QBO_MOCK, []);
if (!$state || !isset($state['items'])) {
    $state = qboStart();
    mockSave(QBO_MOCK, $state);
}
$save = function () use (&$state) {
    mockSave(QBO_MOCK, $state);
};
$path = mockPath();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- test routes ---------- */

if ($path === '/_reset') {
    $state = qboStart();
    $save();
    mockJson(['ok' => true]);
}
if ($path === '/_state') {
    mockJson($state);
}
if ($path === '/_pay') {
    // The newest with that number (a voided one keeps its number - ITFlow sends the invoice again as a new one)
    foreach (array_reverse($state['invoices'], true) as $i => $invoice) {
        if ((isset($_GET['id']) && $invoice['Id'] === (string) $_GET['id']) || (isset($_GET['doc']) && $invoice['DocNumber'] === (string) $_GET['doc'])) {
            $balance = (float) $invoice['Balance'];
            $amount = isset($_GET['amount']) && $_GET['amount'] !== '' ? (float) $_GET['amount'] : $balance;
            $state['invoices'][$i]['Balance'] = round(max(0, $balance - $amount), 2);
            $state['invoices'][$i]['SyncToken'] = (string) (intval($invoice['SyncToken']) + 1);
            $state['payments'][] = ['InvoiceId' => $invoice['Id'], 'Amount' => round(min($amount, $balance), 2), 'TxnDate' => date('Y-m-d')];
            $save();
            mockJson(['ok' => true, 'Invoice' => $state['invoices'][$i]]);
        }
    }
    mockJson(['ok' => false, 'error' => 'no such invoice'], 404);
}

/* ---------- sign-in ---------- */

if ($path === '/connect/oauth2') {
    $redirect = (string) ($_GET['redirect_uri'] ?? '');
    if ($redirect === '' || !preg_match('~^https?://~', $redirect)) {
        http_response_code(400);
        echo 'redirect_uri missing';
        exit;
    }
    // ITFlow builds the redirect address with https (mitM365BaseUrl); a *.test install the tests use over http (config_https_only
    // off) would lose its session there - send the browser back over http when that install's sign-in page answers over http
    $to = parse_url($redirect);
    $host = (string) ($to['host'] ?? '');
    if (($to['scheme'] ?? '') === 'https' && preg_match('/^[a-z0-9.-]+\.test$/i', $host)) {
        $ch = curl_init("http://$host/login.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*']);
        $page = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && stripos($page, 'HTTPS-only') === false) {
            $redirect = 'http://' . substr($redirect, strlen('https://'));
        }
    }
    $query = ['state' => (string) ($_GET['state'] ?? '')];
    if ((string) ($_GET['client_id'] ?? '') === '' || ($_GET['response_type'] ?? '') !== 'code') {
        $query += ['error' => 'invalid_request', 'error_description' => 'client_id and response_type=code are needed'];
    } else {
        $code = 'code-' . bin2hex(random_bytes(6));
        $state['codes'][] = $code;
        $state['codes'] = array_slice($state['codes'], -20);
        $save();
        $query += ['code' => $code, 'realmId' => QBO_REALM];
    }
    header('Location: ' . $redirect . (strpos($redirect, '?') === false ? '?' : '&') . http_build_query($query));
    http_response_code(302);
    exit;
}

$basic = function () {
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($auth === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $auth = $value;
            }
        }
    }
    if (!preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
        return null;
    }
    $pair = explode(':', (string) base64_decode($m[1]), 2);
    return count($pair) === 2 && $pair[0] !== '' && $pair[1] !== '' ? $pair : null;
};

if ($path === '/oauth2/v1/tokens/bearer') {
    if ($method !== 'POST') {
        mockJson(['error' => 'invalid_request'], 405);
    }
    if (!$basic()) {
        mockJson(['error' => 'invalid_client', 'error_description' => 'client id and secret needed (Basic auth)'], 401);
    }
    $body = mockBody();
    $grant = (string) ($body['grant_type'] ?? '');
    if ($grant === 'authorization_code') {
        if ((string) ($body['code'] ?? '') === '' || (string) ($body['redirect_uri'] ?? '') === '') {
            mockJson(['error' => 'invalid_grant', 'error_description' => 'code and redirect_uri needed'], 400);
        }
    } elseif ($grant === 'refresh_token') {
        $refresh = (string) ($body['refresh_token'] ?? '');
        if ($refresh === '' || in_array($refresh, $state['revoked'], true)) {
            mockJson(['error' => 'invalid_grant', 'error_description' => 'Incorrect or invalid refresh token'], 400);
        }
    } else {
        mockJson(['error' => 'unsupported_grant_type'], 400);
    }
    $tokens = ['token_type' => 'bearer', 'access_token' => 'at-' . bin2hex(random_bytes(12)), 'expires_in' => 3600,
        'refresh_token' => 'rt-' . bin2hex(random_bytes(12)), 'x_refresh_token_expires_in' => 8726400];
    $state['tokens'][] = ['grant' => $grant, 'at' => date('c')];
    $state['tokens'] = array_slice($state['tokens'], -50);
    $save();
    mockJson($tokens);
}

if ($path === '/oauth2/v1/tokens/revoke') {
    $body = mockBody();
    if (!empty($body['token'])) {
        $state['revoked'][] = (string) $body['token'];
        $state['revoked'] = array_slice($state['revoked'], -50);
        $save();
    }
    mockJson([]);
}

/* ---------- the API ---------- */

if (!preg_match('~^/v3/company/([^/]+)(/.*)?$~', $path, $m)) {
    mockJson(['error' => 'not found', 'path' => $path], 404);
}
$realm = $m[1];
$api = $m[2] ?? '/';
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
if ($auth === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $auth = $value;
        }
    }
}
if (!preg_match('/^Bearer\s+\S+/i', $auth)) {
    mockJson(['fault' => ['error' => [['message' => 'message=AuthenticationFailed; errorCode=003200; statusCode=401', 'detail' => 'Token missing', 'code' => '3200']], 'type' => 'AUTHENTICATION']], 401);
}
if ($realm !== QBO_REALM) {
    qboFault(403, 'ApplicationAuthorizationFailed', "No company $realm for this token", '003100');
}

const QBO_ENTITIES = ['item' => 'items', 'invoice' => 'invoices', 'customer' => 'customers', 'term' => 'terms', 'account' => 'accounts', 'taxcode' => 'tax_codes'];
const QBO_NAMES = ['Item' => 'items', 'Invoice' => 'invoices', 'Customer' => 'customers', 'Term' => 'terms', 'Account' => 'accounts', 'TaxCode' => 'tax_codes'];

/** A row as QuickBooks answers it (our own bookkeeping fields left out). */
function qboOut(array $row) {
    unset($row['rate']);
    return $row;
}

function qboFind(array $rows, $id) {
    foreach ($rows as $i => $row) {
        if ((string) $row['Id'] === (string) $id) {
            return $i;
        }
    }
    return null;
}

/** "Net 15" etc. by id, for the name QuickBooks puts on a reference. */
function qboName(array $rows, $id, $field = 'Name') {
    $i = qboFind($rows, $id);
    return $i === null ? null : ($rows[$i][$field] ?? ($rows[$i]['Name'] ?? null));
}

/** The due date a term gives an invoice dated $date. */
function qboTermDue(array $term, $date) {
    $date = $date ?: date('Y-m-d');
    if (($term['Type'] ?? 'STANDARD') === 'DATE_DRIVEN') {
        $day = max(1, min(28, intval($term['DayOfMonthDue'] ?? 1)));
        $txn = strtotime($date);
        $due = strtotime(date('Y-m-', $txn) . sprintf('%02d', $day));
        if ($due <= $txn) {
            $due = strtotime(date('Y-m-01', $txn) . ' +1 month');
            $due = strtotime(date('Y-m-', $due) . sprintf('%02d', $day));
        }
        $next_days = intval($term['DueNextMonthDays'] ?? 0);
        if ($next_days > 0 && ($due - $txn) / 86400 < $next_days) {
            $due = strtotime(date('Y-m-d', $due) . ' +1 month');
        }
        return date('Y-m-d', $due);
    }
    return date('Y-m-d', strtotime($date . ' +' . intval($term['DueDays'] ?? 0) . ' days'));
}

/** Work an invoice's lines, tax, total and balance out (as QuickBooks does on save). */
function qboWorkOut(array $invoice, array $state) {
    $sum = 0.0;
    $taxable = 0.0;
    $discount = 0.0;
    $n = 0;
    foreach ($invoice['Line'] as $i => $line) {
        $line['Id'] = (string) ++$n;
        $line['LineNum'] = $n;
        if (($line['DetailType'] ?? '') === 'SalesItemLineDetail') {
            $detail = $line['SalesItemLineDetail'] ?? [];
            if (!isset($line['Amount'])) {
                $line['Amount'] = round(floatval($detail['Qty'] ?? 1) * floatval($detail['UnitPrice'] ?? 0), 2);
            }
            $item_ref = (string) ($detail['ItemRef']['value'] ?? '');
            $name = qboName($state['items'], $item_ref, 'FullyQualifiedName');
            if ($name !== null) {
                $line['SalesItemLineDetail']['ItemRef']['name'] = $name;
            }
            $sum += floatval($line['Amount']);
            $code = (string) ($detail['TaxCodeRef']['value'] ?? 'NON');
            if ($code !== 'NON' && $code !== '') {
                $taxable += floatval($line['Amount']);
            }
        } elseif (($line['DetailType'] ?? '') === 'DiscountLineDetail') {
            $discount += floatval($line['Amount'] ?? 0);
        }
        $invoice['Line'][$i] = $line;
    }
    $tax = 0.0;
    $code = (string) ($invoice['TxnTaxDetail']['TxnTaxCodeRef']['value'] ?? '');
    if ($code !== '') {
        $tax_i = qboFind($state['tax_codes'], $code);
        $rate = $tax_i === null ? 0 : floatval($state['tax_codes'][$tax_i]['rate'] ?? 0);
        $base = $taxable;
        if ($discount > 0 && $sum > 0 && ($invoice['ApplyTaxAfterDiscount'] ?? false)) {
            $base -= $discount * $taxable / $sum;
        }
        $tax = round($base * $rate / 100, 2);
        $invoice['TxnTaxDetail']['TotalTax'] = $tax;
    }
    $invoice['TotalAmt'] = round($sum - $discount + $tax, 2);
    return $invoice;
}

$body = mockBody();
$query_args = $_GET;

// Company info
if (preg_match('~^/companyinfo/(.+)$~', $api)) {
    mockJson(['CompanyInfo' => ['Id' => '1', 'CompanyName' => QBO_COMPANY, 'LegalName' => QBO_COMPANY, 'Country' => 'US', 'SyncToken' => '1'], 'time' => date('c')]);
}

// Queries
if ($api === '/query') {
    $sql = trim((string) ($query_args['query'] ?? ''));
    if (!preg_match('/^SELECT\s+\*\s+FROM\s+(\w+)(.*)$/is', $sql, $q) || !isset(QBO_NAMES[$q[1]])) {
        qboFault(400, 'QueryParserError', "Encountered an error while parsing the query: $sql", '4000');
    }
    $entity = $q[1];
    $rest = trim($q[2]);
    $start = 1;
    $max = 100;
    if (preg_match('/\s*MAXRESULTS\s+(\d+)\s*$/i', $rest, $mm)) {
        $max = min(1000, intval($mm[1]));
        $rest = trim(substr($rest, 0, -strlen($mm[0])));
    }
    if (preg_match('/\s*STARTPOSITION\s+(\d+)\s*$/i', $rest, $mm)) {
        $start = max(1, intval($mm[1]));
        $rest = trim(substr($rest, 0, -strlen($mm[0])));
    }
    $conditions = [];
    if ($rest !== '') {
        if (!preg_match('/^WHERE\s+(.+)$/is', $rest, $w)) {
            qboFault(400, 'QueryParserError', "Encountered an error while parsing the query: $sql", '4000');
        }
        // value: 'quoted' (\' and \\ inside), true / false, a number
        $value = "'(?:[^'\\\\]|\\\\.)*'|true|false|-?[0-9.]+";
        $pattern = "/^\s*(\w+)\s*(?:(=|!=|<>|<=|>=|<|>|LIKE)\s*($value)|IN\s*\(\s*((?:$value)(?:\s*,\s*(?:$value))*)\s*\))\s*(?:AND\s+|$)/is";
        $where = $w[1];
        while (trim($where) !== '') {
            if (!preg_match($pattern, $where, $c)) {
                qboFault(400, 'QueryParserError', "Encountered an error while parsing the query: $sql", '4000');
            }
            $parse = function ($v) {
                $v = trim($v);
                if ($v === 'true' || $v === 'false') {
                    return $v === 'true';
                }
                if ($v !== '' && $v[0] === "'") {
                    return preg_replace('/\\\\(.)/s', '$1', substr($v, 1, -1));
                }
                return $v;
            };
            if (!empty($c[4])) {
                preg_match_all("/$value/i", $c[4], $all);
                $conditions[] = [$c[1], 'IN', array_map($parse, $all[0])];
            } else {
                $conditions[] = [$c[1], strtoupper($c[2]), $parse($c[3])];
            }
            $where = substr($where, strlen($c[0]));
        }
    }
    $rows = [];
    foreach ($state[QBO_NAMES[$entity]] as $row) {
        $keep = true;
        $has_active = false;
        foreach ($conditions as [$field, $op, $want]) {
            if (strcasecmp($field, 'Active') === 0) {
                $has_active = true;
            }
            $have = $row[$field] ?? (strcasecmp($field, 'Active') === 0 ? true : null);
            $same = function ($a, $b) {
                if (is_bool($a) || is_bool($b)) {
                    return (bool) $a === (bool) $b;
                }
                return strcasecmp((string) $a, (string) $b) === 0;
            };
            if ($op === 'IN') {
                $hit = false;
                foreach ($want as $one) {
                    $hit = $hit || $same($have, $one);
                }
                $keep = $keep && $hit;
            } elseif ($op === '=') {
                $keep = $keep && $same($have, $want);
            } elseif ($op === '!=' || $op === '<>') {
                $keep = $keep && !$same($have, $want);
            } elseif ($op === 'LIKE') {
                $regex = '/^' . str_replace('%', '.*', preg_quote((string) $want, '/')) . '$/i';
                $keep = $keep && preg_match($regex, (string) $have);
            } else {
                $a = is_numeric($have) ? floatval($have) : (string) $have;
                $b = is_numeric($want) ? floatval($want) : (string) $want;
                $keep = $keep && (($op === '<' && $a < $b) || ($op === '>' && $a > $b) || ($op === '<=' && $a <= $b) || ($op === '>=' && $a >= $b));
            }
        }
        // As QuickBooks does: list entities without an Active condition give active ones only
        if (!$has_active && $entity !== 'Invoice' && ($row['Active'] ?? true) === false) {
            $keep = false;
        }
        if ($keep) {
            $rows[] = qboOut($row);
        }
    }
    $page = array_slice($rows, $start - 1, $max);
    $response = ['startPosition' => $start, 'maxResults' => count($page)];
    if ($page) {
        $response[$entity] = $page;
    }
    mockJson(['QueryResponse' => $response, 'time' => date('c')]);
}

// One record
if ($method === 'GET' && preg_match('~^/(\w+)/([^/]+)$~', $api, $g) && isset(QBO_ENTITIES[strtolower($g[1])])) {
    $kind = QBO_ENTITIES[strtolower($g[1])];
    $i = qboFind($state[$kind], rawurldecode($g[2]));
    if ($i === null) {
        qboFault(400, 'Object Not Found', 'Object Not Found : Something you\'re trying to use has been made inactive. Check the fields with accounts, customers, items, vendors or employees.', '610');
    }
    mockJson([array_search($kind, QBO_NAMES, true) => qboOut($state[$kind][$i]), 'time' => date('c')]);
}

// Email an invoice
if ($method === 'POST' && preg_match('~^/invoice/([^/]+)/send$~', $api, $g)) {
    $i = qboFind($state['invoices'], rawurldecode($g[1]));
    if ($i === null) {
        qboFault(400, 'Object Not Found', 'Object Not Found', '610');
    }
    $to = (string) ($query_args['sendTo'] ?? ($state['invoices'][$i]['BillEmail']['Address'] ?? ''));
    if ($to === '') {
        qboFault(400, 'Invalid Email Address', 'No email address to send to', '2050');
    }
    $state['invoices'][$i]['EmailStatus'] = 'EmailSent';
    $state['invoices'][$i]['DeliveryInfo'] = ['DeliveryType' => 'Email', 'DeliveryTime' => date('c')];
    $state['sends'][] = ['Id' => $state['invoices'][$i]['Id'], 'DocNumber' => $state['invoices'][$i]['DocNumber'], 'to' => $to, 'at' => date('c')];
    $save();
    mockJson(['Invoice' => $state['invoices'][$i], 'time' => date('c')]);
}

// Create / update
if ($method === 'POST' && preg_match('~^/(\w+)$~', $api, $g) && isset(QBO_ENTITIES[strtolower($g[1])])) {
    $kind = QBO_ENTITIES[strtolower($g[1])];
    $entity = array_search($kind, QBO_NAMES, true);
    if (!is_array($body) || !$body) {
        qboFault(400, 'Request has invalid or unsupported property', 'No body', '2010');
    }
    $operation = (string) ($query_args['operation'] ?? '');
    if (isset($body['Id']) && (string) $body['Id'] !== '') {
        // Update (sparse or full) or void
        $i = qboFind($state[$kind], $body['Id']);
        if ($i === null) {
            qboFault(400, 'Object Not Found', 'Object Not Found', '610');
        }
        $row = $state[$kind][$i];
        if ((string) ($body['SyncToken'] ?? '') !== (string) $row['SyncToken']) {
            qboFault(400, 'Stale Object Error', 'Stale Object Error : You and ' . QBO_COMPANY . ' were working on this at the same time.', '5010');
        }
        if ($operation === 'void') {
            if ($kind !== 'invoices') {
                qboFault(400, 'Operation void is not supported', 'void', '2020');
            }
            foreach ($row['Line'] as $l => $line) {
                $row['Line'][$l]['Amount'] = 0;
                if (isset($line['SalesItemLineDetail'])) {
                    $row['Line'][$l]['SalesItemLineDetail']['Qty'] = 0;
                }
            }
            $row['TotalAmt'] = 0;
            $row['Balance'] = 0;
            if (isset($row['TxnTaxDetail'])) {
                $row['TxnTaxDetail']['TotalTax'] = 0;
            }
            $row['PrivateNote'] = 'Voided';
        } elseif ($operation !== '') {
            qboFault(400, "Operation $operation is not supported", $operation, '2020');
        } else {
            $sparse = !empty($body['sparse']);
            $changes = $body;
            unset($changes['sparse'], $changes['Id'], $changes['SyncToken']);
            $row = $sparse ? array_replace($row, $changes) : array_replace(['Id' => $row['Id']], $changes);
            if (isset($changes['Name']) && $kind === 'items') {
                $row['FullyQualifiedName'] = $changes['Name'];
            }
            if (isset($changes['DisplayName']) && $kind === 'customers') {
                $row['FullyQualifiedName'] = $changes['DisplayName'];
            }
            if ($kind === 'items' && isset($row['IncomeAccountRef']['value'])) {
                $row['IncomeAccountRef']['name'] = qboName($state['accounts'], $row['IncomeAccountRef']['value']);
            }
            if ($kind === 'invoices') {
                $paid = round(floatval($state[$kind][$i]['TotalAmt'] ?? 0) - floatval($state[$kind][$i]['Balance'] ?? 0), 2);
                $row = qboWorkOut($row, $state);
                $row['Balance'] = round(max(0, $row['TotalAmt'] - $paid), 2);
            }
        }
        $row['SyncToken'] = (string) (intval($state[$kind][$i]['SyncToken']) + 1);
        $row['MetaData']['LastUpdatedTime'] = date('c');
        $state[$kind][$i] = $row;
        $save();
        mockJson([$entity => qboOut($row), 'time' => date('c')]);
    }

    // Create
    $id = (string) $state['next_id']++;
    $row = $body;
    $row['Id'] = $id;
    $row['SyncToken'] = '0';
    $row['MetaData'] = ['CreateTime' => date('c'), 'LastUpdatedTime' => date('c')];
    if ($kind === 'items' || $kind === 'terms' || $kind === 'accounts') {
        $name = trim((string) ($body['Name'] ?? ''));
        if ($name === '') {
            qboFault(400, 'Required param missing, need to supply the required value for the API', 'Required parameter Name is missing in the request', '2020');
        }
        foreach ($state[$kind] as $other) {
            if (strcasecmp($other['Name'], $name) === 0) {
                qboFault(400, 'Duplicate Name Exists Error', 'The name supplied already exists. : Id=' . $other['Id'], '6240');
            }
        }
        $row['Active'] = $body['Active'] ?? true;
    }
    if ($kind === 'items') {
        if (!in_array($body['Type'] ?? '', ['Service', 'NonInventory', 'Inventory'], true)) {
            qboFault(400, 'Invalid Enumeration', 'Type', '2130');
        }
        $account = (string) ($body['IncomeAccountRef']['value'] ?? '');
        if ($account === '' || qboFind($state['accounts'], $account) === null) {
            qboFault(400, 'Invalid Reference Id', 'Invalid Reference Id : Accounts element id ' . $account . ' not found', '2500');
        }
        $row['IncomeAccountRef']['name'] = qboName($state['accounts'], $account);
        $row['FullyQualifiedName'] = $name;
    } elseif ($kind === 'terms') {
        $row['Type'] = $body['Type'] ?? 'STANDARD';
        if ($row['Type'] === 'STANDARD') {
            $row['DueDays'] = intval($body['DueDays'] ?? 0);
        }
    } elseif ($kind === 'customers') {
        $name = trim((string) ($body['DisplayName'] ?? ''));
        if ($name === '' || strpos($name, ':') !== false) {
            qboFault(400, 'Invalid DisplayName', 'DisplayName is missing or holds a colon', '2050');
        }
        foreach ($state['customers'] as $other) {
            if (strcasecmp($other['DisplayName'], $name) === 0) {
                qboFault(400, 'Duplicate Name Exists Error', 'The name supplied already exists. : Id=' . $other['Id'], '6240');
            }
        }
        $row['FullyQualifiedName'] = $name;
        $row['Active'] = $body['Active'] ?? true;
        $row['Balance'] = 0;
    } elseif ($kind === 'invoices') {
        if ((string) ($body['CustomerRef']['value'] ?? '') === '') {
            qboFault(400, 'Required param missing, need to supply the required value for the API', 'Required parameter CustomerRef is missing in the request', '2020');
        }
        if (empty($body['Line']) || !is_array($body['Line'])) {
            qboFault(400, 'Required param missing, need to supply the required value for the API', 'Required parameter Line is missing in the request', '2020');
        }
        foreach ($body['Line'] as $line) {
            if (($line['DetailType'] ?? '') === 'SalesItemLineDetail') {
                $item_ref = (string) ($line['SalesItemLineDetail']['ItemRef']['value'] ?? '');
                $i = qboFind($state['items'], $item_ref);
                if ($item_ref === '' || $i === null) {
                    qboFault(400, 'Invalid Reference Id', 'Invalid Reference Id : Item elements id ' . $item_ref . ' not found', '2500');
                }
                if (($state['items'][$i]['Active'] ?? true) === false) {
                    qboFault(400, 'Object Not Found', 'Object Not Found : Something you\'re trying to use has been made inactive. Check the fields with accounts, customers, items, vendors or employees.', '610');
                }
            }
        }
        $customer_name = qboName($state['customers'], $body['CustomerRef']['value'], 'DisplayName');
        if ($customer_name !== null) {
            $row['CustomerRef']['name'] = $customer_name;
        }
        $row['TxnDate'] = (string) ($body['TxnDate'] ?? date('Y-m-d'));
        if (isset($body['SalesTermRef']['value'])) {
            $t = qboFind($state['terms'], $body['SalesTermRef']['value']);
            if ($t === null || ($state['terms'][$t]['Active'] ?? true) === false) {
                qboFault(400, 'Invalid Reference Id', 'Invalid Reference Id : Term elements id ' . $body['SalesTermRef']['value'] . ' not found', '2500');
            }
            $row['SalesTermRef']['name'] = $state['terms'][$t]['Name'];
            if (empty($body['DueDate'])) {
                $row['DueDate'] = qboTermDue($state['terms'][$t], $row['TxnDate']);
            }
        }
        if (empty($row['DueDate'])) {
            $row['DueDate'] = $row['TxnDate'];
        }
        $row = qboWorkOut($row, $state);
        $row['Balance'] = $row['TotalAmt'];
        $row['CurrencyRef'] = ['value' => 'USD', 'name' => 'United States Dollar'];
        $row['EmailStatus'] = 'NotSet';
    }
    $state[$kind][] = $row;
    $save();
    mockJson([$entity => qboOut($row), 'time' => date('c')]);
}

qboFault(400, 'Unsupported Operation', "$method $api is not something this stand-in knows", '500');
