<?php
/*
 * Usage billing (functions/mit_usage.php) through ITFlow's own recurringInvoiceGenerate (what Invoice Now and the nightly job call):
 *  - a manual line and a contact-tag line take their counts at the snapshot day; a fixed line is left alone; totals and tax are
 *    ITFlow's (billingRecalc); the invoice stays a Draft and is not emailed though the recurring invoice auto-sends;
 *  - flags: big change, minimum, stale, missing; the previous quantity is what the last invoice billed (hand override included);
 *  - override: reason needed, totals follow, refused once the invoice is no longer a Draft;
 *  - the API (api/v1/mit_usage/create.php, read.php) with a key: recorded as source api, bad values and tag: names refused,
 *    a client-limited key refused for another client; products API never shows our cost.
 */
require __DIR__ . '/bootstrap.php';
$client_id = scenarioClient();
$q1 = function ($sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
$made = ['recurring' => [], 'invoices' => [], 'tags' => [], 'contacts' => [], 'taxes' => [], 'keys' => []];
$was_day = mitSetting('usage_snapshot_day', '1');
$was_user10 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_status FROM users WHERE user_id = 10"));
$api_host = (string) $GLOBALS['config_base_url'];
$api = function ($method, $path, $key, array $fields = []) use ($api_host) {
    $ch = curl_init("http://$api_host/api/v1/" . $path . ($method === 'GET' && $fields ? '?' . http_build_query($fields) : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$api_host:80:127.0.0.1"], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ["X-API-Key: $key", "Content-Type: application/json"], CURLOPT_USERAGENT => "scenario", CURLOPT_TIMEOUT => 20]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true), $body];
};
try {
    mitSetSetting('usage_snapshot_day', '1');
    $snapshot = mitUsageSnapshotDate(date('Y-m-d'));
    mysqli_query($mysqli, "INSERT INTO taxes SET tax_name = 'ZZ scen usage tax', tax_percent = 10");
    $tax = $made['taxes'][] = mysqli_insert_id($mysqli);
    taxForget();
    mysqli_query($mysqli, "INSERT INTO tags SET tag_name = 'ZZ scen licensed', tag_type = 3, tag_color = '#000'");
    $tag = $made['tags'][] = mysqli_insert_id($mysqli);
    foreach (['ZZ scen user A', 'ZZ scen user B', 'ZZ scen user C'] as $i => $name) {
        mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = '$name', contact_email = '', contact_client_id = $client_id" . ($i === 2 ? ', contact_archived_at = NOW()' : ''));
        $cid = $made['contacts'][] = mysqli_insert_id($mysqli);
        mysqli_query($mysqli, "INSERT INTO contact_tags SET contact_id = $cid, tag_id = $tag");
    }
    $rn = intval($q1("SELECT COALESCE(MAX(recurring_invoice_number), 0) + 1 FROM recurring_invoices"));
    mysqli_query($mysqli, "INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'REC-', recurring_invoice_number = $rn, recurring_invoice_scope = 'ZZ scenario usage',
        recurring_invoice_frequency = 'month', recurring_invoice_next_date = CURDATE(), recurring_invoice_anchor_day = 1, recurring_invoice_status = 1, recurring_invoice_discount_amount = 0,
        recurring_invoice_amount = 0, recurring_invoice_currency_code = 'USD', recurring_invoice_auto_send = 1, recurring_invoice_email_notify = 1, recurring_invoice_category_id = 0,
        recurring_invoice_quote_id = 0, recurring_invoice_client_id = $client_id");
    $rid = $made['recurring'][] = mysqli_insert_id($mysqli);
    $line = function ($name, $qty, $price, $tax_id) use ($rid) {
        $error = '';
        $id = billingItemSave('recurring', $rid, ['name' => $name, 'description' => '', 'qty' => $qty, 'price' => $price, 'tax_id' => $tax_id], 0, $error);
        if (!$id) {
            throw new RuntimeException($error);
        }
        return intval($id);
    };
    $l_ws = $line('ZZ scen workstations', '10', '50', $tax);
    $l_fixed = $line('ZZ scen flat fee', '1', '200', 0);
    $l_users = $line('ZZ scen licensed users', '5', '12', 0);
    billingRecalc('recurring', $rid);
    scenarioCheck('manual usage line saved', mitUsageSaveLine($l_ws, 'manual', 'ZZ scen workstations', 0, '3'), '');
    scenarioCheck('tag usage line saved', mitUsageSaveLine($l_users, 'tag', '', $tag, ''), '');
    scenarioCheck('a manual name starting tag: refused', mitUsageSaveLine($l_fixed, 'manual', 'tag:1', 0, '') !== '', true);
    $metric = mitUsageCleanMetric('ZZ scen workstations');
    [$c1, $why] = mitUsageRecordCount($client_id, $metric, '14', $snapshot);
    scenarioCheck("count kept ($why)", $c1 > 0, true);
    mitUsageRecordCount($client_id, $metric, '99', date('Y-m-d', strtotime("$snapshot +1 day")) > date('Y-m-d', strtotime('+1 day')) ? $snapshot : date('Y-m-d', strtotime("$snapshot +1 day")));
    [, $why] = mitUsageRecordCount($client_id, $metric, '-1', $snapshot);
    scenarioCheck('negative count refused', $why !== '', true);
    [, $why] = mitUsageRecordCount($client_id, $metric, '5', date('Y-m-d', strtotime('+3 days')));
    scenarioCheck('future count refused', $why !== '', true);

    // Invoice Now (early) - ITFlow's recurringInvoiceGenerate with our hook
    $mail_before = intval($q1("SELECT COALESCE(MAX(email_id), 0) FROM email_queue"));
    $inv = recurringInvoiceGenerate($rid, 'Scenario', true);
    $made['invoices'][] = $inv;
    scenarioCheck('invoice made', $inv > 0, true);
    $items = mysqli_fetch_all(mysqli_query($mysqli, "SELECT item_id, item_name, item_quantity, item_subtotal, item_tax, item_total FROM invoice_items WHERE item_invoice_id = $inv ORDER BY item_order, item_id"), MYSQLI_ASSOC);
    $by = array_column($items, null, 'item_name');
    $same_day = date('Y-m-d', strtotime("$snapshot +1 day")) <= date('Y-m-d');
    scenarioCheck('workstations: the count at the snapshot day (14), not the later one', (float) $by['ZZ scen workstations']['item_quantity'], 14.0);
    scenarioCheck('... 700 + 10% tax', [(float) $by['ZZ scen workstations']['item_subtotal'], (float) $by['ZZ scen workstations']['item_tax']], [700.0, 70.0]);
    scenarioCheck('flat fee left alone', (float) $by['ZZ scen flat fee']['item_quantity'], 1.0);
    // The tag count is recorded today; snapshot day is the 1st - only taken when the invoice's snapshot is today, else the last tag count on or before it (none: missing -> base 5)
    $tag_qty = (float) $by['ZZ scen licensed users']['item_quantity'];
    scenarioCheck('licensed users: 2 tagged (archived contact not counted) on a snapshot day, else the base 5', $tag_qty, $snapshot === date('Y-m-d') ? 2.0 : 5.0);
    scenarioCheck('invoice amount = 770 + 200 + users', (float) $q1("SELECT invoice_amount FROM invoices WHERE invoice_id = $inv"), round(770 + 200 + 12 * $tag_qty, 2));
    scenarioCheck('kept a Draft', $q1("SELECT invoice_status FROM invoices WHERE invoice_id = $inv"), 'Draft');
    scenarioCheck('not emailed though the recurring invoice auto-sends', intval($q1("SELECT COUNT(*) FROM email_queue WHERE email_id > $mail_before AND email_subject LIKE '%" . escapeSql($q1("SELECT CONCAT(invoice_prefix, invoice_number) FROM invoices WHERE invoice_id = $inv")) . "%'")), 0);
    $applied = mysqli_fetch_all(mysqli_query($mysqli, "SELECT * FROM mit_usage_applied WHERE invoice_id = $inv ORDER BY applied_id"), MYSQLI_ASSOC);
    scenarioCheck('two lines applied (not the fixed one)', count($applied), 2);
    $ws_applied = array_values(array_filter($applied, function ($a) use ($by) { return intval($a['invoice_item_id']) === intval($by['ZZ scen workstations']['item_id']); }))[0] ?? [];
    scenarioCheck('workstations flagged a big change (10 -> 14 is 40%)', strpos((string) ($ws_applied['flags'] ?? ''), 'big_change') !== false, true);

    // Override: needs a reason, totals follow
    scenarioCheck('override without a reason refused', mitUsageOverride($ws_applied['applied_id'], '12', '') !== '', true);
    scenarioCheck('override', mitUsageOverride($ws_applied['applied_id'], '12', 'Two were returned'), '');
    scenarioCheck('override: line 600 + 60 tax', [(float) $q1("SELECT item_subtotal FROM invoice_items WHERE item_id = " . intval($ws_applied['invoice_item_id'])), (float) $q1("SELECT item_tax FROM invoice_items WHERE item_id = " . intval($ws_applied['invoice_item_id']))], [600.0, 60.0]);
    scenarioCheck('override: invoice amount follows', (float) $q1("SELECT invoice_amount FROM invoices WHERE invoice_id = $inv"), round(660 + 200 + 12 * $tag_qty, 2));

    // Second run: minimum, previous = what the last invoice billed (12, the override)
    mitUsageRecordCount($client_id, $metric, '2', date('Y-m-d', strtotime("$snapshot")));
    mysqli_query($mysqli, "UPDATE recurring_invoices SET recurring_invoice_next_date = CURDATE() WHERE recurring_invoice_id = $rid");
    $inv2 = recurringInvoiceGenerate($rid, 'Scenario', true);
    $made['invoices'][] = $inv2;
    $a2 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT a.*, ii.item_quantity FROM mit_usage_applied a JOIN invoice_items ii ON ii.item_id = a.invoice_item_id WHERE a.invoice_id = $inv2 AND a.metric = '" . escapeSql($metric) . "'"));
    scenarioCheck('count 2 under the minimum 3 -> 3', (float) ($a2['item_quantity'] ?? 0), 3.0);
    scenarioCheck('... flagged minimum', strpos((string) ($a2['flags'] ?? ''), 'minimum') !== false, true);
    scenarioCheck('previous = the last invoice\'s billed qty (12, overridden)', (float) ($a2['previous_qty'] ?? 0), 12.0);

    // Stale and missing
    mysqli_query($mysqli, "DELETE FROM mit_usage_counts WHERE client_id = $client_id AND metric = '" . escapeSql($metric) . "'");
    mitUsageRecordCount($client_id, $metric, '11', date('Y-m-d', strtotime("$snapshot -30 days")));
    mysqli_query($mysqli, "UPDATE recurring_invoices SET recurring_invoice_next_date = CURDATE() WHERE recurring_invoice_id = $rid");
    $inv3 = recurringInvoiceGenerate($rid, 'Scenario', true);
    $made['invoices'][] = $inv3;
    $a3 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_usage_applied WHERE invoice_id = $inv3 AND metric = '" . escapeSql($metric) . "'"));
    scenarioCheck('a count 30 days before the snapshot: used, flagged stale', [(float) $a3['applied_qty'], strpos($a3['flags'], 'stale') !== false], [11.0, true]);
    mysqli_query($mysqli, "DELETE FROM mit_usage_counts WHERE client_id = $client_id AND metric = '" . escapeSql($metric) . "'");
    mysqli_query($mysqli, "UPDATE recurring_invoices SET recurring_invoice_next_date = CURDATE() WHERE recurring_invoice_id = $rid");
    $inv4 = recurringInvoiceGenerate($rid, 'Scenario', true);
    $made['invoices'][] = $inv4;
    $a4 = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_usage_applied WHERE invoice_id = $inv4 AND metric = '" . escapeSql($metric) . "'"));
    scenarioCheck('no count: the recurring line\'s own qty (10), flagged missing', [(float) $a4['applied_qty'], strpos($a4['flags'], 'missing') !== false], [10.0, true]);

    // Override refused once not a Draft
    mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Sent' WHERE invoice_id = $inv4");
    scenarioCheck('override refused on a Sent invoice', mitUsageOverride($a4['applied_id'], '1', 'x') !== '', true);

    // The API
    $key = 'zzscen' . bin2hex(random_bytes(10));
    mysqli_query($mysqli, "INSERT INTO api_keys SET api_key_name = 'ZZ scen key', api_key_secret = '$key', api_key_decrypt_hash = '', api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = " . intval($session_user_id));
    $made['keys'][] = mysqli_insert_id($mysqli);
    [$code, $json, $body] = $api('POST', 'mit_usage/create.php', $key, ['client_id' => $client_id, 'metric' => 'ZZ scen workstations', 'quantity' => '21', 'note' => 'from rmm']);
    scenarioCheck("API create: 200 ($body)", $code, 200);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM mit_usage_counts WHERE client_id = $client_id AND metric = '" . escapeSql($metric) . "' ORDER BY count_id DESC LIMIT 1"));
    scenarioCheck('API count recorded as source api, today', [(float) ($row['quantity'] ?? 0), $row['count_source'] ?? '', $row['as_of'] ?? ''], [21.0, 'api', date('Y-m-d')]);
    [$code] = $api('POST', 'mit_usage/create.php', $key, ['client_id' => $client_id, 'metric' => 'ZZ scen workstations', 'quantity' => 'lots']);
    scenarioCheck('API: bad quantity -> 400', $code, 400);
    [$code] = $api('POST', 'mit_usage/create.php', $key, ['client_id' => $client_id, 'metric' => "tag:$tag", 'quantity' => '1']);
    scenarioCheck('API: tag: name refused', $code, 400);
    [$code, $json] = $api('GET', 'mit_usage/read.php', $key, ['client_id' => $client_id]);
    $mine = array_values(array_filter($json['data'] ?? [], function ($r) use ($metric) { return ($r['metric'] ?? '') === $metric; }));
    scenarioCheck('API read: the line with its latest count (21)', (float) ($mine[0]['latest_quantity'] ?? 0), 21.0);
    [$code, $json, $body] = $api('GET', 'products/read.php', $key);
    $first = $json['data'][0] ?? [];
    scenarioCheck('products API answers', $code === 200 && $first, true);
    scenarioCheck('products API never shows our cost', array_intersect(array_keys($first), ['product_cost', 'product_price_method', 'product_price_percent']), []);
    // A client-limited user (allowed client 2 only) for another client
    $limited = intval($q1("SELECT user_id FROM user_client_permissions WHERE permission_type = 'allow' AND user_id IN (SELECT user_id FROM users WHERE user_type = 1) LIMIT 1"));
    $allowed = intval($q1("SELECT client_id FROM user_client_permissions WHERE user_id = $limited LIMIT 1"));
    $other = intval($q1("SELECT client_id FROM clients WHERE client_id != $allowed ORDER BY client_id LIMIT 1"));
    if ($limited) {
        mysqli_query($mysqli, "UPDATE users SET user_status = 1 WHERE user_id = $limited");
        $role = intval($q1("SELECT user_role_id FROM users WHERE user_id = $limited"));
        $sales = intval($q1("SELECT user_role_permission_level FROM user_role_permissions urp JOIN modules m ON m.module_id = urp.module_id WHERE urp.user_role_id = $role AND m.module_name = 'module_sales'"));
        $key2 = 'zzscen' . bin2hex(random_bytes(10));
        mysqli_query($mysqli, "INSERT INTO api_keys SET api_key_name = 'ZZ scen limited', api_key_secret = '$key2', api_key_decrypt_hash = '', api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = $limited");
        $made['keys'][] = mysqli_insert_id($mysqli);
        [$code] = $api('POST', 'mit_usage/create.php', $key2, ['client_id' => $other, 'metric' => 'ZZ scen workstations', 'quantity' => '1']);
        scenarioCheck("client-limited key (Sales $sales) refused for a client it can't see", $code, 403);
        [$code, $json] = $api('GET', 'mit_usage/read.php', $key2);
        scenarioCheck('client-limited key reads only its clients', array_filter($json['data'] ?? [], function ($r) use ($allowed) { return intval($r['client_id']) !== $allowed; }), []);
    }
} finally {
    mitSetSetting('usage_snapshot_day', $was_day);
    if ($was_user10) {
        mysqli_query($mysqli, "UPDATE users SET user_status = " . intval($was_user10['user_status']) . " WHERE user_id = 10");
    }
    foreach ($made['keys'] as $id) {
        mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id = " . intval($id));
    }
    mysqli_query($mysqli, "DELETE FROM mit_usage_counts WHERE metric LIKE 'zz scen%' OR metric LIKE 'ZZ scen%'");
    foreach ($made['tags'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_usage_counts WHERE metric = 'tag:" . intval($id) . "'");
        mysqli_query($mysqli, "DELETE FROM contact_tags WHERE tag_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM tags WHERE tag_id = " . intval($id));
    }
    foreach ($made['contacts'] as $id) {
        mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_id = " . intval($id));
    }
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['recurring'] as $id) {
        mysqli_query($mysqli, "DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM recurring_invoices WHERE recurring_invoice_id = " . intval($id));
    }
    foreach ($made['taxes'] as $id) {
        mysqli_query($mysqli, "DELETE FROM taxes WHERE tax_id = " . intval($id));
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
