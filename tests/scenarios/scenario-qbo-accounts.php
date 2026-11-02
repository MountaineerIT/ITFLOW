<?php
/*
 * QuickBooks Online income accounts by category (functions/mit_qbo_accounts.php, M051) against the test install's stand-in:
 * one invoice with every kind of line, each going to QuickBooks with the item - and so the income account - of its own category:
 *  - an unmapped product (Hardware): a Non-inventory item made with Hardware's account and remembered as the product's mapping;
 *  - an unmapped product named like an inactive QuickBooks item: that item made active again and linked, not duplicated;
 *  - a ticket part (no product on the line - its part's product is Hardware): the "<Hardware> (ITFlow)" item;
 *  - ticket time billed by ITFlow's billingTimeInvoice (hook: mitQboTagTimeLines) - the Labor category -> "<Labor> (ITFlow)";
 *  - a line typed by hand with Hardware picked (Edit Line - mitQboLineCategorySave) and one taking the invoice's category;
 *  - a line named like a QuickBooks item: that item.
 * The batch lists the items it will make before the send; a second invoice makes none; changing a category's account moves its
 * own item; with "make items" off a product without an item is refused (no fallback item).
 */
require __DIR__ . '/bootstrap.php';
const MOCK = 'http://127.0.0.1/qbo-mock/index.php';
$mock = function ($path) {
    return json_decode((string) @file_get_contents(MOCK . $path), true);
};
if (!defined('MIT_QBO_API_BASE') || !$mock('/_state') || !function_exists('mitQboAccountsReady') || !mitQboAccountsReady()) {
    echo "  skip - no QuickBooks stand-in on this install, or M051 not run\n";
    exit(0);
}
$q1 = function ($sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
$keys = ['qbo_realm_id', 'qbo_refresh_token', 'qbo_access_token', 'qbo_fallback_item', 'qbo_tax_style', 'qbo_payment_account', 'qbo_default_account',
    'qbo_default_account_name', 'qbo_default_item', 'qbo_time_category', 'qbo_create_items', 'qbo_client_id', 'qbo_client_secret', 'qbo_env'];
$saved = [];
foreach ($keys as $key) {
    $saved[$key] = mitSetting($key);
}
$made = ['invoices' => [], 'batches' => [], 'products' => [], 'categories' => [], 'tickets' => []];
$stamp = date('His');
[$client_id, $contact_id] = scenarioMakeClient("ZZ QBO accounts $stamp");
$category = function ($name) use ($mysqli, &$made) {
    mysqli_query($mysqli, "INSERT INTO categories SET category_name = '" . escapeSql($name) . "', category_type = 'Income', category_color = '#000'");
    return $made['categories'][] = mysqli_insert_id($mysqli);
};
$product = function ($name, $category_id, $type = 'product') use ($mysqli, &$made) {
    mysqli_query($mysqli, "INSERT INTO products SET product_name = '" . escapeSql($name) . "', product_price = 25, product_track_stock = 0, product_type = '$type',
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = " . intval($category_id));
    return $made['products'][] = mysqli_insert_id($mysqli);
};
$line = function ($invoice_id, array $in) {
    $error = '';
    $id = billingItemSave('invoice', $invoice_id, $in + ['description' => '', 'qty' => '1', 'price' => '10', 'tax_id' => 0], 0, $error);
    if (!$id) {
        throw new RuntimeException("line: $error");
    }
    return intval($id);
};
$send = function (array $ids) use (&$made) {
    mitInvoiceApprove($ids);
    $batch = $made['batches'][] = mitQboBatchCreate('ZZ scenario accounts', false);
    [$added, $refused] = mitQboBatchAdd($batch, $ids);
    return [$batch, $added, $refused];
};
$qbo_lines = function ($doc) use ($mock) {
    foreach ($mock('/_state')['invoices'] as $invoice) {
        if ($invoice['DocNumber'] === $doc) {
            return array_values(array_filter($invoice['Line'], fn($l) => $l['DetailType'] === 'SalesItemLineDetail'));
        }
    }
    return [];
};
$qbo_item = function ($id) use ($mock) {
    foreach ($mock('/_state')['items'] ?? [] as $item) {
        if ($item['Id'] === (string) $id) {
            return $item;
        }
    }
    return null;
};
try {
    $mock('/_reset');
    mitSetSetting('qbo_client_id', 'qbo-client');
    mitSetSetting('qbo_client_secret', mitEncryptSecret('qbo-secret'));
    mitSetSetting('qbo_env', 'sandbox');
    scenarioCheck('connected to the stand-in', mitQboConnect('code-scenario', '9130355'), 'Sandbox Company_US_1');
    mitSetSetting('qbo_payment_account', intval($q1("SELECT account_id FROM accounts ORDER BY account_id LIMIT 1")));
    mitSetSetting('qbo_tax_style', 'us');
    mitSetSetting('qbo_fallback_item', '');
    mitDeleteSetting('qbo_default_item');

    echo "the Income accounts tab\n";
    $hardware = $category("ZZ Hardware $stamp");
    $labor = $category("ZZ Labor $stamp");
    $projects = $category("ZZ Projects $stamp"); // no account of its own - the default
    $accounts = mitQboIncomeAccounts();
    scenarioCheck('QuickBooks income accounts listed (not the bank)', [isset($accounts['80']), isset($accounts['82']), isset($accounts['90'])], [true, true, false]);
    scenarioCheck('saved', mitQboAccountsSave(['qbo_default_account' => '79', 'qbo_time_category' => $labor, 'qbo_create_items' => '1',
        'account' => [$hardware => '80', $labor => '81']]), []);
    scenarioCheck('Hardware -> Hardware Sales', mitQboAccountFor($hardware), ['80', 'Hardware Sales']);
    scenarioCheck('a category without one -> the default', mitQboAccountFor(0), ['79', 'Sales of Product Income']);

    echo "an invoice with every kind of line\n";
    $switch = $product("ZZ scen switch $stamp", $hardware);
    $router = $product('Old router', $hardware); // the stand-in has an inactive item with this name
    $cable = $product("ZZ scen cable $stamp", $hardware);
    $invoice = billingInvoiceCreate($client_id, date('Y-m-d'), $projects, 'ZZ scenario accounts', 'Scenario');
    $made['invoices'][] = $invoice;
    mysqli_query($mysqli, "UPDATE invoices SET invoice_currency_code = 'USD' WHERE invoice_id IN ($invoice)");
    $l_switch = $line($invoice, ['name' => "ZZ scen switch $stamp", 'product_id' => $switch, 'price' => '25']);
    $l_router = $line($invoice, ['name' => 'Old router', 'product_id' => $router, 'price' => '40']);
    // A ticket part: the line has no product, its part row does
    $ticket = $made['tickets'][] = scenarioMakeTicket($client_id, $contact_id, ['ticket_billable' => 1]);
    $l_part = $line($invoice, ['name' => "ZZ scen cable $stamp", 'price' => '5']);
    mysqli_query($mysqli, "INSERT INTO mit_ticket_parts SET ticket_id = $ticket, client_id = $client_id, product_id = $cable, name = 'ZZ scen cable', quantity = 1,
        unit_price = 5, source = 'stock', invoice_id = $invoice, invoice_item_id = $l_part, billed_at = NOW(), created_by = " . intval($session_user_id));
    // Ticket time through ITFlow's own billing
    $error = '';
    $time = billingTimeInvoice($client_id, [$ticket => '2'], ['invoice_id' => $invoice, 'include_open' => true, 'price' => '100', 'item_name' => "ZZ scen support $stamp", 'by' => 'Scenario'], $error);
    scenarioCheck('ticket time billed (' . $error . ')', $time !== null, true);
    $l_time = intval($q1("SELECT item_id FROM invoice_items WHERE item_invoice_id = $invoice AND item_name = 'ZZ scen support $stamp'"));
    // Typed by hand: one with Hardware picked in Edit Line, one left to the invoice's category; one named like a QuickBooks item
    $l_typed = $line($invoice, ['name' => 'ZZ scen typed hardware', 'price' => '7']);
    mitQboLineCategorySave('invoice', $l_typed, ['mit_line_category_present' => '1', 'mit_line_category' => $hardware]);
    $l_plain = $line($invoice, ['name' => 'ZZ scen typed other', 'price' => '3']);
    $l_named = $line($invoice, ['name' => 'Services', 'price' => '50']);
    billingRecalc('invoice', $invoice);

    $lines = mysqli_fetch_all(mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_invoice_id = $invoice"), MYSQLI_ASSOC);
    $cats = mitQboLineCategories(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_id = $invoice")), $lines);
    scenarioCheck('categories: product, part, time, picked, invoice', [$cats[$l_switch], $cats[$l_part], $cats[$l_time], $cats[$l_typed], $cats[$l_plain]],
        [[$hardware, 'product'], [$hardware, 'part'], [$labor, 'line'], [$hardware, 'line'], [$projects, 'invoice']]);

    [$batch, $added] = $send([$invoice]);
    scenarioCheck('batched', $added, 1);
    $plan = mitQboBatchItemPlan($batch);
    ksort($plan);
    $want_plan = ["category:$hardware", "category:$labor", "category:$projects", "product:$router", "product:$switch"];
    sort($want_plan);
    scenarioCheck('the batch lists the items it will make', array_keys($plan), $want_plan);
    scenarioCheck('... with their accounts', [$plan["product:$switch"]['account'], $plan["product:$switch"]['type'], $plan["category:$labor"]['account']],
        ['Hardware Sales', 'NonInventory', 'Labor Income']);
    scenarioCheck('nothing made yet', count(array_filter($mock('/_state')['items'] ?? [], fn($i) => strpos($i['Name'], $stamp) !== false)), 0);

    [$sent, $failed] = mitQboBatchSend($batch);
    scenarioCheck('sent (' . json_encode($failed) . ')', $sent, 1);
    $doc = mitQboDocNumber(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_id = $invoice")));
    $refs = [];
    foreach ($qbo_lines($doc) as $l) {
        $refs[] = $l['SalesItemLineDetail']['ItemRef']['value'];
    }
    scenarioCheck('seven lines (' . $doc . ')', count($refs), 7);
    $switch_item = mitQboMap('items')[$switch]['qbo_id'] ?? '';
    $hw_item = mitQboCategoryAccounts(true)[$hardware]['qbo_item_id'] ?? '';
    $labor_item = mitQboCategoryAccounts()[$labor]['qbo_item_id'] ?? '';
    $projects_item = mitQboCategoryAccounts()[$projects]['qbo_item_id'] ?? '';
    scenarioCheck('Projects\' own item on the default account', $qbo_item($projects_item)['IncomeAccountRef']['value'] ?? '', '79');
    scenarioCheck('product item made and mapped', $switch_item !== '' && ($qbo_item($switch_item)['Type'] ?? '') === 'NonInventory', true);
    scenarioCheck('... on Hardware Sales', $qbo_item($switch_item)['IncomeAccountRef']['value'] ?? '', '80');
    scenarioCheck('inactive "Old router" made active and linked', [mitQboMap('items')[$router]['qbo_id'] ?? '', $qbo_item('3')['Active'] ?? null], ['3', true]);
    scenarioCheck('Hardware\'s own item on Hardware Sales', [$qbo_item($hw_item)['Name'] ?? '', $qbo_item($hw_item)['IncomeAccountRef']['value'] ?? ''], ["ZZ Hardware $stamp (ITFlow)", '80']);
    scenarioCheck('Labor\'s own item on Labor Income', [$qbo_item($labor_item)['Name'] ?? '', $qbo_item($labor_item)['IncomeAccountRef']['value'] ?? ''], ["ZZ Labor $stamp (ITFlow)", '81']);
    $order = [];
    foreach ($lines as $row) {
        $order[] = intval($row['item_id']);
    }
    sort($order);
    $want = [$l_switch => $switch_item, $l_router => '3', $l_part => $hw_item, $l_time => $labor_item, $l_typed => $hw_item, $l_plain => $projects_item, $l_named => '2'];
    $by_line = [];
    foreach ($order as $i => $item_id) {
        $by_line[$item_id] = $refs[$i] ?? '';
    }
    scenarioCheck('each line on its own item', $by_line, $want);

    echo "a second invoice\n";
    $items_before = count($mock('/_state')['items']);
    $second = billingInvoiceCreate($client_id, date('Y-m-d'), $projects, 'ZZ scenario accounts 2', 'Scenario');
    $made['invoices'][] = $second;
    mysqli_query($mysqli, "UPDATE invoices SET invoice_currency_code = 'USD' WHERE invoice_id = $second");
    $line($second, ['name' => "ZZ scen switch $stamp", 'product_id' => $switch]);
    $line($second, ['name' => 'ZZ scen more typing']);
    billingRecalc('invoice', $second);
    [$batch2] = $send([$second]);
    scenarioCheck('nothing more to make', mitQboBatchItemPlan($batch2), []);
    [$sent, $failed2] = mitQboBatchSend($batch2);
    if ($failed2) { echo json_encode($failed2), "\n"; }
    scenarioCheck('sent, no new items', [$sent, count($mock('/_state')['items'])], [1, $items_before]);

    echo "changing an account\n";
    scenarioCheck('Hardware -> Interest Earned', mitQboAccountsSave(['qbo_default_account' => '79', 'qbo_time_category' => $labor, 'qbo_create_items' => '1',
        'account' => [$hardware => '82', $labor => '81']]), []);
    scenarioCheck('Hardware\'s own item follows', $qbo_item($hw_item)['IncomeAccountRef']['value'] ?? '', '82');
    scenarioCheck('a mapped product\'s item keeps its account', $qbo_item($switch_item)['IncomeAccountRef']['value'] ?? '', '80');

    echo "the category's item made inactive in QuickBooks\n";
    $hw_now = $qbo_item($hw_item);
    mitQboApi('POST', '/item', ['Id' => $hw_item, 'SyncToken' => (string) $hw_now['SyncToken'], 'sparse' => true, 'Active' => false]);
    $fourth = billingInvoiceCreate($client_id, date('Y-m-d'), $projects, 'ZZ scenario accounts 4', 'Scenario');
    $made['invoices'][] = $fourth;
    mysqli_query($mysqli, "UPDATE invoices SET invoice_currency_code = 'USD' WHERE invoice_id = $fourth");
    $typed = $line($fourth, ['name' => 'ZZ scen typed hardware again']);
    mitQboLineCategorySave('invoice', $typed, ['mit_line_category_present' => '1', 'mit_line_category' => $hardware]);
    billingRecalc('invoice', $fourth);
    [$batch4] = $send([$fourth]);
    [$sent4, $failed4] = mitQboBatchSend($batch4);
    scenarioCheck('sent (' . json_encode($failed4) . ')', $sent4, 1);
    scenarioCheck('... the same item, made active again (not a second one)', [$qbo_item($hw_item)['Active'] ?? null,
        count(array_filter($mock('/_state')['items'], fn($i) => $i['Name'] === "ZZ Hardware $stamp (ITFlow)"))], [true, 1]);

    echo "making items switched off\n";
    mitSetSetting('qbo_create_items', '0');
    $gadget = $product("ZZ scen gadget $stamp", $hardware, 'service');
    $third = billingInvoiceCreate($client_id, date('Y-m-d'), 0, 'ZZ scenario accounts 3', 'Scenario');
    $made['invoices'][] = $third;
    mysqli_query($mysqli, "UPDATE invoices SET invoice_currency_code = 'USD' WHERE invoice_id = $third");
    $line($third, ['name' => "ZZ scen gadget $stamp", 'product_id' => $gadget]);
    [, $problems] = mitQboInvoicePayload($third, mitQboList('items'));
    scenarioCheck('refused: no item, none made', (bool) array_filter($problems, fn($p) => strpos($p, 'has no QuickBooks item') !== false), true);
    scenarioCheck('... and none planned', mitQboAccountSettings()['create_items'], false);

    echo "Edit Line\n";
    $html = mitQboLineCategoryField('invoice', $invoice, ['item_id' => $l_typed, 'item_product_id' => 0]);
    scenarioCheck('the picker shows the picked category', strpos($html, 'value="' . $hardware . '" selected') !== false, true);
    mitQboLineCategorySave('invoice', $l_typed, ['mit_line_category_present' => '1', 'mit_line_category' => '0']);
    scenarioCheck('cleared', mitQboLineCategoryRows([$l_typed]), []);
    mitQboLineCategorySave('invoice', $l_typed, ['mit_line_category' => $labor]);
    scenarioCheck('a form without the picker changes nothing', mitQboLineCategoryRows([$l_typed]), []);
} finally {
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batch_invoices WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM mit_invoice_approvals WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM mit_ticket_parts WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "UPDATE tickets SET ticket_invoice_id = 0 WHERE ticket_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['batches'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_items WHERE product_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
    foreach ($made['categories'] as $id) {
        mysqli_query($mysqli, "DELETE FROM categories WHERE category_id = " . intval($id));
    }
    foreach ($saved as $key => $value) {
        $value === null ? mitDeleteSetting($key) : mitSetSetting($key, $value);
    }
    $mock('/_reset');
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
