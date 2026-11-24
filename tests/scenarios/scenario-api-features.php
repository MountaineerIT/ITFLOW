<?php
/*
 * Our features through the REST API (functions/mit_api.php, api/v1/mit_*): real HTTP calls with throw-away keys, as an admin and as
 * a Technician (Support edit, no Timesheets access) - service boards (read, a ticket's board on ITFlow's tickets/read, move and
 * escalate with the page's rules and history), ticket parts (add / change / remove, never our cost, closed tickets refused, another
 * client's ticket out of reach), timesheets (codes, own timesheet only for a technician, payroll refused without Timesheets view),
 * and our fields on ITFlow's invoices / contracts reads (percentage discount, minimum lines, unlimited hours).
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitApiExtras')) {
    echo "  skip - no mit_api.php here\n";
    exit(0);
}
$api_host = (string) $GLOBALS['config_base_url'];
$call = function ($key, $method, $path, array $body = []) use ($api_host) {
    $ch = curl_init("http://$api_host/api/v1/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$api_host:80:127.0.0.1"], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ["X-API-Key: $key", 'Content-Type: application/json'], CURLOPT_USERAGENT => 'scenario', CURLOPT_TIMEOUT => 30]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $text = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = json_decode($text, true);
    if ($json === null) {
        echo "    (not JSON: " . substr($text, 0, 300) . ")\n";
    }
    return [$code, $json ?? []];
};
// A row with the columns given - every other NOT NULL column without a default gets a plain value
$insert = function ($table, array $set) use ($mysqli) {
    $columns = mysqli_fetch_all(mysqli_query($mysqli, "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = '$table' AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'"), MYSQLI_ASSOC);
    foreach ($columns as $c) {
        if (!isset($set[$c['COLUMN_NAME']])) {
            $set[$c['COLUMN_NAME']] = in_array($c['DATA_TYPE'], ['date', 'datetime', 'timestamp'], true) ? date('Y-m-d')
                : (preg_match('/int|decimal|float|double/', $c['DATA_TYPE']) ? 0 : ($c['DATA_TYPE'] === 'enum' ? trim(explode(',', substr($c['COLUMN_TYPE'], 5))[0], "')") : 'zz'));
        }
    }
    $parts = [];
    foreach ($set as $k => $v) {
        $parts[] = "`$k` = '" . escapeSql((string) $v) . "'";
    }
    mysqli_query($mysqli, "INSERT INTO `$table` SET " . implode(', ', $parts));
    return mysqli_insert_id($mysqli);
};
$key_ids = [];
$make_key = function ($user_id, $name) use ($mysqli, &$key_ids) {
    $key = 'zzscen' . bin2hex(random_bytes(12));
    mysqli_query($mysqli, "INSERT INTO api_keys SET api_key_name = '" . escapeSql($name) . "', api_key_secret = '$key', api_key_decrypt_hash = '',
        api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = " . intval($user_id));
    $key_ids[] = mysqli_insert_id($mysqli);
    return $key;
};
$admin_id = intval(scenarioValue("SELECT user_id FROM users JOIN user_roles ON role_id = user_role_id WHERE role_is_admin = 1 AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_id LIMIT 1"));
// A Technician-like role: Support edit, no Timesheets - made here so the test install's roles don't matter
mysqli_query($mysqli, "INSERT INTO user_roles SET role_name = 'ZZ API tech', role_description = 'scenario', role_is_admin = 0");
$role_id = mysqli_insert_id($mysqli);
foreach (['module_support' => 2, 'module_client' => 1, 'module_sales' => 1] as $module => $level) {
    $module_id = intval(scenarioValue("SELECT module_id FROM modules WHERE module_name = '$module'"));
    mysqli_query($mysqli, "INSERT INTO user_role_permissions SET user_role_id = $role_id, module_id = $module_id, user_role_permission_level = $level");
}
$stamp = date('His');
mysqli_query($mysqli, "INSERT INTO users SET user_name = 'ZZ API Tech $stamp', user_email = 'zz-api-tech-$stamp@example.invalid', user_password = 'x', user_type = 1,
    user_status = 1, user_role_id = $role_id");
$tech_id = mysqli_insert_id($mysqli);

[$client, $contact] = scenarioMakeClient("ZZ API client $stamp");
[$other, $other_contact] = scenarioMakeClient("ZZ API other $stamp");
mysqli_query($mysqli, "INSERT INTO user_client_permissions SET user_id = $tech_id, client_id = $client, permission_type = 'allow'");
$ticket = scenarioMakeTicket($client, $contact);
$other_ticket = scenarioMakeTicket($other, $other_contact);
$invoice = 0;
$contract = 0;
try {
    $admin = $make_key($admin_id, 'ZZ scen API admin');
    $tech = $make_key($tech_id, 'ZZ scen API tech');

    if (mitBoardsReady() && count(mitBoards()) >= 2) {
        echo "service boards\n";
        [$code, $out] = $call($admin, 'GET', 'mit_boards/read.php');
        scenarioCheck('boards read: success', $out['success'] ?? null, 'True');
        $default = mitBoardDefaultId();
        $listed = array_column($out['data'] ?? [], null, 'board_id');
        scenarioCheck('the default board is listed, marked default, with its members', isset($listed[$default]) && $listed[$default]['board_is_default'] === true && is_array($listed[$default]['members']), true);
        [$code, $out] = $call($admin, 'GET', "tickets/read.php?ticket_id=$ticket");
        scenarioCheck("ITFlow's tickets/read names the ticket's board", $out['data'][0]['mit_board_id'] ?? null, $default);
        $target = array_values(array_diff(array_keys(mitBoards()), [$default]))[0];
        [$code, $out] = $call($tech, 'POST', 'mit_boards/move.php', ['ticket_id' => $ticket, 'board_id' => $target, 'escalate' => 1, 'client_id' => $client]);
        scenarioCheck('escalating with no reason: 400', $code, 400);
        [$code, $out] = $call($tech, 'POST', 'mit_boards/move.php', ['ticket_id' => $ticket, 'board_id' => $target, 'escalate' => 1, 'reason' => 'Needs a server person', 'client_id' => $client]);
        scenarioCheck('escalated by the technician: success', $out['success'] ?? null, 'True');
        scenarioCheck('... the ticket is on the new board', mitBoardOf($ticket), $target);
        scenarioCheck('... with an internal note giving the reason', intval(scenarioValue("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket AND ticket_reply_type = 'Internal' AND ticket_reply LIKE '%Needs a server person%'")), 1);
        [$code, $out] = $call($admin, 'GET', "mit_boards/read.php?ticket_id=$ticket");
        $moves = $out['data'][0]['moves'] ?? [];
        scenarioCheck("mit_boards/read with ticket_id: its board and the escalation", [$out['data'][0]['board_id'] ?? null, end($moves)['how'] ?? null], [$target, 'escalated']);
        [$code, $out] = $call($tech, 'POST', 'mit_boards/move.php', ['ticket_id' => $other_ticket, 'board_id' => $default, 'client_id' => $client]);
        scenarioCheck("another client's ticket is out of the technician's reach: 404", $code, 404);
    } else {
        echo "  skip boards - fewer than two boards\n";
    }

    if (mitProcReady() && mitTicketPartsReady() && mitModuleOn('procurement')) {
        echo "ticket parts\n";
        [$code, $out] = $call($tech, 'POST', 'mit_ticket_parts/create.php', ['ticket_id' => $ticket, 'name' => 'ZZ API cable', 'quantity' => 2, 'unit_price' => 12.5,
            'unit_cost' => 4, 'source' => 'other', 'client_id' => $client]);
        scenarioCheck('a part added: success', $out['success'] ?? null, 'True');
        $part_id = intval($out['data'][0]['part_id'] ?? 0);
        scenarioCheck('... on the ticket, with an internal note', intval(scenarioValue("SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = $ticket AND ticket_reply LIKE 'Part added:%ZZ API cable%'")), 1);
        scenarioCheck('... our cost never in the answer', array_key_exists('unit_cost', $out['data'][0] ?? []), false);
        [$code, $out] = $call($admin, 'GET', "mit_ticket_parts/read.php?ticket_id=$ticket");
        scenarioCheck('read: the part, without our cost', [count($out['data'] ?? []), $out['data'][0]['name'] ?? null, array_key_exists('unit_cost', $out['data'][0] ?? [])], [1, 'ZZ API cable', false]);
        [$code, $out] = $call($tech, 'POST', 'mit_ticket_parts/update.php', ['part_id' => $part_id, 'name' => 'ZZ API cable', 'quantity' => 3, 'unit_price' => 12.5, 'source' => 'other', 'client_id' => $client]);
        scenarioCheck('changed to 3', [$out['success'] ?? null, floatval(scenarioValue("SELECT quantity FROM mit_ticket_parts WHERE part_id = $part_id"))], ['True', 3.0]);
        [$code, $out] = $call($tech, 'POST', 'mit_ticket_parts/create.php', ['ticket_id' => $other_ticket, 'name' => 'ZZ nope', 'quantity' => 1, 'source' => 'other', 'client_id' => $client]);
        scenarioCheck("another client's ticket: 404", $code, 404);
        [$code, $out] = $call($tech, 'POST', 'mit_ticket_parts/remove.php', ['part_id' => $part_id, 'client_id' => $client]);
        scenarioCheck('removed', [$out['success'] ?? null, intval(scenarioValue("SELECT COUNT(*) FROM mit_ticket_parts WHERE part_id = $part_id"))], ['True', 0]);
        mysqli_query($mysqli, "UPDATE tickets SET ticket_closed_at = NOW(), ticket_resolved_at = NOW() WHERE ticket_id = $ticket");
        [$code, $out] = $call($tech, 'POST', 'mit_ticket_parts/create.php', ['ticket_id' => $ticket, 'name' => 'ZZ late', 'quantity' => 1, 'source' => 'other', 'client_id' => $client]);
        scenarioCheck('a closed ticket takes no parts: 409', $code, 409);
        if (mitBoardsReady() && count(mitBoards()) >= 2) {
            [$code, $out] = $call($admin, 'POST', 'mit_boards/move.php', ['ticket_id' => $ticket, 'board_id' => mitBoardDefaultId()]);
            scenarioCheck('a closed ticket is not moved between boards: 409', $code, 409);
        }
    } else {
        echo "  skip parts - Purchasing off\n";
    }

    if (mitTsOn()) {
        echo "timesheets\n";
        [$code, $out] = $call($tech, 'GET', 'mit_charge_codes/read.php');
        scenarioCheck('charge codes: anyone reads them', [$out['success'] ?? null, count($out['data'] ?? []) === count(mitTsCodes())], ['True', true]);
        [$code, $out] = $call($admin, 'GET', 'mit_timesheets/read.php?period=' . date('Y-m-d'));
        scenarioCheck('timesheets this period (admin): success', $out['success'] ?? null, 'True');
        $first = $out['data'][0] ?? [];
        scenarioCheck('... with a status and hours', isset($first['status'], $first['paid_hours'], $first['period_start']), $out['data'] ? true : isset($out['count']));
        [$code, $out] = $call($tech, 'GET', "mit_timesheets/read.php?period=" . date('Y-m-d') . "&user_id=$admin_id");
        scenarioCheck("someone else's timesheet without Timesheets view: 403", $code, 403);
        [$code, $out] = $call($tech, 'GET', 'mit_timesheets/read.php?period=' . date('Y-m-d'));
        scenarioCheck('... their own list holds only themselves', array_values(array_unique(array_column($out['data'] ?? [], 'user_id'))) === [] || array_values(array_unique(array_column($out['data'], 'user_id'))) === [$tech_id], true);
        [$code, $out] = $call($tech, 'GET', 'mit_payroll/read.php');
        scenarioCheck('payroll without Timesheets view: 403', $code, 403);
        [$code, $out] = $call($admin, 'GET', 'mit_payroll/read.php');
        scenarioCheck('payroll as admin: success', $out['success'] ?? null, 'True');
        [$code, $out] = $call($admin, 'GET', 'mit_timesheets/read.php?from=2026-02-30');
        scenarioCheck('a date that is not real: 400', $code, 400);
    } else {
        echo "  skip timesheets - off\n";
    }

    echo "our fields on ITFlow's reads\n";
    if (mitDiscountReady()) {
        $invoice = $insert('invoices', ['invoice_client_id' => $client, 'invoice_prefix' => 'ZZ-', 'invoice_number' => 900000 + intval($stamp), 'invoice_status' => 'Draft',
            'invoice_date' => date('Y-m-d'), 'invoice_due' => date('Y-m-d'), 'invoice_currency_code' => 'USD', 'invoice_url_key' => randomString(32)]);
        mitDiscountSet('invoice', $invoice, 15);
        [$code, $out] = $call($admin, 'GET', "invoices/read.php?invoice_id=$invoice");
        scenarioCheck('invoices/read: the percentage discount', $out['data'][0]['mit_discount_percent'] ?? null, 15.0);
        scenarioCheck('... and its minimum lines (none)', $out['data'][0]['mit_minimum_lines'] ?? null, []);
    }
    if (function_exists('mitContractUnlimited') && mitColumnReady('contracts', 'contract_monthly_hours')) {
        $contract = $insert('contracts', ['contract_client_id' => $client, 'contract_name' => 'ZZ API unlimited', 'contract_monthly_hours' => MIT_CONTRACT_UNLIMITED_HOURS]);
        [$code, $out] = $call($admin, 'GET', "contracts/read.php?contract_id=$contract");
        scenarioCheck('contracts/read: unlimited hours', $out['data'][0]['mit_unlimited_hours'] ?? null, true);
    }
} finally {
    if ($invoice) {
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = $invoice");
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = $invoice");
    }
    if ($contract) {
        mysqli_query($mysqli, "DELETE FROM contracts WHERE contract_id = $contract");
    }
    mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE ticket_id IN ($ticket, $other_ticket)");
    $keys = implode(',', $key_ids ?: [0]);
    mysqli_query($mysqli, "DELETE FROM api_key_usage WHERE api_key_usage_key_id IN ($keys)");
    mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id IN ($keys)");
    mysqli_query($mysqli, "DELETE FROM user_client_permissions WHERE user_id = $tech_id");
    mysqli_query($mysqli, "DELETE FROM user_settings WHERE user_id = $tech_id");
    mysqli_query($mysqli, "DELETE FROM users WHERE user_id = $tech_id");
    mysqli_query($mysqli, "DELETE FROM user_role_permissions WHERE user_role_id = $role_id");
    mysqli_query($mysqli, "DELETE FROM user_roles WHERE role_id = $role_id");
    mysqli_query($mysqli, "DELETE FROM logs WHERE log_type = 'API' AND log_user_agent = 'scenario'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
