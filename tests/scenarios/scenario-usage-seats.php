<?php
/*
 * Usage counts in ITFlow's own "Quantity from" (functions/mit_usage.php, 26.11.3.7) through ITFlow's seat code and recurringInvoiceGenerate:
 *  - a line saved by ITFlow's billingItemSave with seat_source 'usage' + a usage name keeps {"metric": ...}; ITFlow counts it (the latest
 *    count for the client - Bitwarden's here), describes it ("Bitwarden users"), refreshes it (history) and bills it when the invoice is made;
 *    the minimum wins over a smaller count; a name with no count can't be saved and a line whose count is gone keeps its quantity;
 *  - no review hold: the invoice follows the recurring invoice's send setting, nothing in the old Usage Review;
 *  - "Not tagged ..." on Users / Devices lines (26.11.3.8): placeholder contacts left out, kept as {"not_tag": id}, follows a new tag;
 *  - M058 moves the old Usage billing card's lines: a usage-name line -> 'usage' with its minimum, a contact-tag line -> ITFlow's
 *    Users tagged ...; the moved rows leave mit_usage_lines; running it twice changes nothing.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitUsageSeatSources') || !mitUsageReady() || !mitColumnReady('recurring_invoice_items', 'item_seat_source')) {
    echo "  skip - usage billing or ITFlow's seat lines not here\n";
    exit(0);
}
$client_id = scenarioClient();
$q1 = function ($sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
$made = ['recurring' => [], 'invoices' => [], 'tags' => [], 'contacts' => []];
$stamp = date('His');
$bw = 'bitwarden:users';
try {
    // Counts as the connectors record them
    mysqli_query($mysqli, "DELETE FROM mit_usage_counts WHERE client_id = $client_id AND metric IN ('$bw', 'zz scen seats api')");
    scenarioCheck('a Bitwarden count recorded', mitUsageRecordCount($client_id, $bw, 14, date('Y-m-d'), 'bitwarden', 'Bitwarden members not revoked') > 0, true);
    mitUsageRecordCount($client_id, 'zz scen seats api', 3, date('Y-m-d'), 'api');

    $rn = intval($q1("SELECT COALESCE(MAX(recurring_invoice_number), 0) + 1 FROM recurring_invoices"));
    mysqli_query($mysqli, "INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'REC-', recurring_invoice_number = $rn, recurring_invoice_scope = 'ZZ scenario usage seats',
        recurring_invoice_frequency = 'month', recurring_invoice_next_date = CURDATE(), recurring_invoice_anchor_day = 1, recurring_invoice_status = 1, recurring_invoice_discount_amount = 0,
        recurring_invoice_amount = 0, recurring_invoice_currency_code = 'USD', recurring_invoice_auto_send = 0, recurring_invoice_category_id = 0,
        recurring_invoice_quote_id = 0, recurring_invoice_client_id = $client_id");
    $rid = $made['recurring'][] = mysqli_insert_id($mysqli);
    $save = function (array $in, $item_id = 0) use ($rid) {
        $error = '';
        $id = billingItemSave('recurring', $rid, $in + ['description' => '', 'tax_id' => 0], $item_id, $error);
        return [$id ? intval($id) : 0, $error];
    };

    // 1. ITFlow's line form with our source
    scenarioCheck('ITFlow offers "Counted by an integration"', isset(recurringSeatSources()['usage']), true);
    [$l_bw, $error] = $save(['name' => 'ZZ scen Bitwarden users', 'qty' => '1', 'price' => '4', 'seat_source' => 'usage', 'seat_metric' => $bw, 'seat_minimum' => '0']);
    scenarioCheck("saved through ITFlow ($error)", $l_bw > 0, true);
    scenarioCheck('the line keeps the usage name', [$q1("SELECT item_seat_source FROM recurring_invoice_items WHERE item_id = $l_bw"),
        json_decode((string) $q1("SELECT item_seat_filter FROM recurring_invoice_items WHERE item_id = $l_bw"), true)], ['usage', ['metric' => $bw]]);
    scenarioCheck('its quantity is the count now', floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_bw")), 14.0);
    [$l_min] = $save(['name' => 'ZZ scen API with a minimum', 'qty' => '1', 'price' => '10', 'seat_source' => 'usage', 'seat_metric' => 'zz scen seats api', 'seat_minimum' => '5']);
    scenarioCheck('a count under the minimum bills the minimum', floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_min")), 5.0);
    [$none, $error] = $save(['name' => 'ZZ scen nothing reported', 'qty' => '1', 'price' => '1', 'seat_source' => 'usage', 'seat_metric' => 'zz scen never reported']);
    scenarioCheck('a name with no count yet is refused', [$none, $error !== ''], [0, true]);
    $seats = recurringSeatLines($rid, $client_id);
    scenarioCheck('described in words', $seats[$l_bw]['seat_label'] ?? '', 'Bitwarden users');

    // 2. The count changes: ITFlow's refresh and the invoice follow
    mitUsageRecordCount($client_id, $bw, 16, date('Y-m-d'), 'bitwarden', 'Bitwarden members not revoked');
    scenarioCheck('the Seats card sees it drift', (bool) (recurringSeatLines($rid, $client_id)[$l_bw]['seat_drift'] ?? false), true);
    $changes = recurringInvoiceRefresh($rid, 'Scenario');
    scenarioCheck('refresh moves the line and says so', [floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_bw")), count($changes)], [16.0, 1]);
    $inv = $made['invoices'][] = intval(recurringInvoiceGenerate($rid, 'Scenario', true));
    scenarioCheck('invoice made', $inv > 0, true);
    scenarioCheck('billed at the count: 16 x 4 + 5 x 10', floatval($q1("SELECT invoice_amount FROM invoices WHERE invoice_id = $inv")), 114.0);
    scenarioCheck('no review hold - the old Usage Review has nothing for it', intval($q1("SELECT COUNT(*) FROM mit_usage_applied WHERE invoice_id = $inv")), 0);

    // 3. A count that goes away: the line keeps its quantity (as ITFlow does for a license that's gone)
    mysqli_query($mysqli, "DELETE FROM mit_usage_counts WHERE client_id = $client_id AND metric = 'zz scen seats api'");
    recurringInvoiceRefresh($rid, 'Scenario');
    scenarioCheck('no count: quantity kept', floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_min")), 5.0);

    // 4. M058 moves the old card's lines
    mysqli_query($mysqli, "INSERT INTO tags SET tag_name = 'ZZ scen seats tag $stamp', tag_type = 3, tag_color = '#000'");
    $tag = $made['tags'][] = mysqli_insert_id($mysqli);
    [$old_api] = $save(['name' => 'ZZ scen old API line', 'qty' => '2', 'price' => '1']);
    [$old_tag] = $save(['name' => 'ZZ scen old tag line', 'qty' => '2', 'price' => '1']);
    scenarioCheck('an old-style usage line', mitUsageSaveLine($old_api, 'api', 'zz scen old metric', 0, '4'), '');
    scenarioCheck('an old-style tag line', mitUsageSaveLine($old_tag, 'tag', '', $tag, ''), '');
    $run = function () use ($mysqli) {
        if (!defined('ITFLOW_MIT_DB_UPDATES')) {
            define('ITFLOW_MIT_DB_UPDATES', true);
        }
        include dirname(__DIR__, 2) . '/admin/database_updates/mountaineerit/M058.php';
    };
    $run();
    scenarioCheck('usage line -> "Counted by an integration" with its minimum', [$q1("SELECT item_seat_source FROM recurring_invoice_items WHERE item_id = $old_api"),
        json_decode((string) $q1("SELECT item_seat_filter FROM recurring_invoice_items WHERE item_id = $old_api"), true),
        floatval($q1("SELECT item_seat_minimum FROM recurring_invoice_items WHERE item_id = $old_api"))], ['usage', ['metric' => 'zz scen old metric'], 4.0]);
    scenarioCheck('tag line -> ITFlow\'s Users tagged ...', [$q1("SELECT item_seat_source FROM recurring_invoice_items WHERE item_id = $old_tag"),
        recurringSeatFilter('contacts', $q1("SELECT item_seat_filter FROM recurring_invoice_items WHERE item_id = $old_tag"))], ['contacts', ['tag' => $tag]]);
    scenarioCheck('the old rows are gone', intval($q1("SELECT COUNT(*) FROM mit_usage_lines WHERE recurring_item_id IN ($old_api, $old_tag)")), 0);
    $run();
    scenarioCheck('running it twice changes nothing', $q1("SELECT item_seat_source FROM recurring_invoice_items WHERE item_id = $old_api"), 'usage');

    // 4b. "Not tagged ..." (26.11.3.8): placeholder contacts tagged so they are not billed
    mysqli_query($mysqli, "INSERT INTO tags SET tag_name = 'ZZ scen placeholder $stamp', tag_type = 3, tag_color = '#000'");
    $ph = $made['tags'][] = mysqli_insert_id($mysqli);
    $all = intval($q1("SELECT COUNT(*) FROM contacts WHERE contact_client_id = $client_id AND contact_archived_at IS NULL"));
    foreach (['ZZ scen real user', 'ZZ scen shared mailbox'] as $i => $name) {
        mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = '$name $stamp', contact_client_id = $client_id");
        $cid = $made['contacts'][] = mysqli_insert_id($mysqli);
        if ($i === 1) {
            mysqli_query($mysqli, "INSERT INTO contact_tags SET contact_id = $cid, tag_id = $ph");
        }
    }
    scenarioCheck('the picker\'s "Not tagged" (a negative tag) is kept as not_tag', recurringSeatFilter('contacts', ['tag' => -$ph]), ['not_tag' => $ph]);
    scenarioCheck('stored not_tag reads back', recurringSeatFilter('contacts', json_encode(['not_tag' => $ph])), ['not_tag' => $ph]);
    scenarioCheck('counts every contact but the tagged one', recurringSeatCount($client_id, 'contacts', ['not_tag' => $ph]), $all + 1);
    [$l_not, $error] = $save(['name' => 'ZZ scen users not placeholders', 'qty' => '1', 'price' => '3', 'seat_source' => 'contacts', 'seat_tag' => (string) -$ph]);
    scenarioCheck("saved through ITFlow ($error)", [json_decode((string) $q1("SELECT item_seat_filter FROM recurring_invoice_items WHERE item_id = $l_not"), true),
        floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_not"))], [['not_tag' => $ph], floatval($all + 1)]);
    scenarioCheck('described in words', recurringSeatLines($rid, $client_id)[$l_not]['seat_label'] ?? '', "Contacts not tagged ZZ scen placeholder $stamp");
    mysqli_query($mysqli, "INSERT INTO contact_tags SET contact_id = " . intval($made['contacts'][0]) . ", tag_id = $ph");
    recurringInvoiceRefresh($rid, 'Scenario');
    scenarioCheck('tagging one more takes it off at the next refresh', floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_not")), floatval($all));
    scenarioCheck('devices: not_tag kept too', recurringSeatFilter('assets', ['tag' => -$ph, 'types' => ['Laptop']]), ['types' => ['Laptop'], 'not_tag' => $ph]);
    scenarioCheck('devices described', recurringSeatDescribe('assets', ['types' => ['Laptop'], 'not_tag' => $ph]), "Laptop not tagged ZZ scen placeholder $stamp in use");
    // "Not tagged at all" (26.11.3.9): any tag leaves a contact out
    $untagged_before = intval($q1("SELECT COUNT(*) FROM contacts c WHERE contact_client_id = $client_id AND contact_archived_at IS NULL
        AND contact_id NOT IN (SELECT ct.contact_id FROM contact_tags ct JOIN tags t ON t.tag_id = ct.tag_id WHERE t.tag_archived_at IS NULL)"));
    scenarioCheck('"Not tagged at all" is kept as untagged', recurringSeatFilter('contacts', ['tag' => 'untagged']), ['untagged' => 1]);
    [$l_none, $error] = $save(['name' => 'ZZ scen users with no tags', 'qty' => '1', 'price' => '3', 'seat_source' => 'contacts', 'seat_tag' => 'untagged']);
    scenarioCheck("saved through ITFlow ($error)", [json_decode((string) $q1("SELECT item_seat_filter FROM recurring_invoice_items WHERE item_id = $l_none"), true),
        floatval($q1("SELECT item_quantity FROM recurring_invoice_items WHERE item_id = $l_none"))], [['untagged' => 1], floatval($untagged_before)]);
    scenarioCheck('described in words', recurringSeatLines($rid, $client_id)[$l_none]['seat_label'] ?? '', 'Contacts with no tags');
    mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = 'ZZ scen untagged $stamp', contact_client_id = $client_id");
    $plain = $made['contacts'][] = mysqli_insert_id($mysqli);
    scenarioCheck('a new contact with no tag counts', recurringSeatCount($client_id, 'contacts', ['untagged' => 1]), $untagged_before + 1);
    mysqli_query($mysqli, "INSERT INTO contact_tags SET contact_id = $plain, tag_id = $tag");
    scenarioCheck('any tag at all leaves it out', recurringSeatCount($client_id, 'contacts', ['untagged' => 1]), $untagged_before);
    mysqli_query($mysqli, "UPDATE tags SET tag_archived_at = NOW() WHERE tag_id = $tag");
    scenarioCheck('an archived tag no longer counts as tagged', recurringSeatCount($client_id, 'contacts', ['untagged' => 1]), $untagged_before + 1);
    mysqli_query($mysqli, "UPDATE tags SET tag_archived_at = NULL WHERE tag_id = $tag");
    scenarioCheck('devices with no tags described', recurringSeatDescribe('assets', ['types' => ['Laptop'], 'untagged' => 1]), 'Laptop with no tags in use');
    scenarioCheck('a usage line keeps no not_tag', recurringSeatFilter('usage', ['metric' => $bw, 'tag' => -$ph]), ['metric' => $bw]);

    // 5. The client's Usage card lists the line with its count
    $lines = array_values(array_filter(mitUsageClientLines($client_id), function ($l) use ($l_bw) { return intval($l['recurring_item_id']) === $l_bw; }));
    scenarioCheck('the client card lists it with the latest count', [$lines[0]['line_source'] ?? '', floatval($lines[0]['latest']['quantity'] ?? 0)], ['usage', 16.0]);
} finally {
    mysqli_query($mysqli, "DELETE FROM mit_usage_counts WHERE client_id = $client_id AND (metric = '$bw' OR metric LIKE 'zz scen%')");
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['recurring'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_usage_lines WHERE recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_recurring_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM recurring_invoices WHERE recurring_invoice_id = " . intval($id));
    }
    foreach ($made['contacts'] as $id) {
        mysqli_query($mysqli, "DELETE FROM contact_tags WHERE contact_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_id = " . intval($id));
    }
    foreach ($made['tags'] as $id) {
        mysqli_query($mysqli, "DELETE FROM tags WHERE tag_id = " . intval($id));
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
