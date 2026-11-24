<?php
/*
 * Quote pricing and pictures are ITFlow's own since 26.12 (functions/quote_pricing.php, quote_images.php - built from ours). Ours on top
 * (functions/mit_quotes.php):
 *  - the billing page's add row (mitPaperAddRow) posts ITFlow's own pricing fields, and ITFlow's billingItemSave prices the line from them
 *    (cost, markup / margin / set price) - a line picked from a product with nothing typed takes the product's cost;
 *  - the API never hands out what we pay: the products and quote lines read over HTTP with an API key carry no cost / markup fields
 *    (mitProductApiColumns, mitApiHideCost in api/v1/read_output.php) - while the rows in the database have them (the control);
 *  - the staff-only profit box, per billing frequency, the quote's discount taken off the one-time group, set-price labour as all profit.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('quotePricingReady') || !quotePricingReady()) {
    echo "  FAIL ITFlow's quote pricing (quote_items.item_cost) is not here\n";
    exit(1);
}
$client_id = scenarioClient();
$made = ['quotes' => [], 'products' => []];
$quote = function () use ($mysqli, $client_id, &$made) {
    $qn = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes"))[0]);
    mysqli_query($mysqli, "INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = $qn, quote_scope = 'ZZ scenario pricing', quote_date = CURDATE(), quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY),
        quote_amount = 0, quote_currency_code = 'USD', quote_status = 'Draft', quote_url_key = 'scenario', quote_client_id = $client_id, quote_category_id = 0");
    return $made['quotes'][] = mysqli_insert_id($mysqli);
};
$save = function ($quote_id, array $post, $item_id = 0) {
    $error = '';
    $saved = billingItemSave('quote', $quote_id, $post, $item_id, $error);
    if (!$saved) {
        throw new RuntimeException("billingItemSave: $error");
    }
    billingRecalc('quote', $quote_id);
    return intval($saved);
};
$row = function ($item_id) use ($mysqli) {
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM quote_items WHERE item_id = " . intval($item_id)));
};
$key = 'zzqp' . bin2hex(random_bytes(12));
mysqli_query($mysqli, "INSERT INTO api_keys SET api_key_name = 'ZZ scen pricing key', api_key_secret = '$key', api_key_decrypt_hash = '', api_key_expire = DATE_ADD(NOW(), INTERVAL 1 DAY), api_key_user_id = 1");
$key_id = mysqli_insert_id($mysqli);
$api = function ($path) use ($key) {
    $host = (string) $GLOBALS['config_base_url'];
    $ch = curl_init("http://$host/api/v1/$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_RESOLVE => ["$host:80:127.0.0.1"], CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*',
        CURLOPT_HTTPHEADER => ["X-API-Key: $key"], CURLOPT_TIMEOUT => 20]);
    $text = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, json_decode($text, true), $text];
};
try {
    // 1. The add row posts ITFlow's pricing fields
    $qid = $quote();
    $paper = ['type' => 'quote', 'row' => billingRow('quote', $qid, false), 'edit' => true, 'items' => [], 'mit_staff' => true];
    $html = function_exists('mitPaperAddRow') ? mitPaperAddRow($paper) : '';
    $fields = [];
    preg_match_all('/name=["\']([a-z_]+)["\']/', $html, $m);
    $fields = array_values(array_intersect(['pricing', 'cost', 'price_method', 'price_percent'], $m[1]));
    scenarioCheck("the quote's add row posts ITFlow's pricing fields", $fields, ['pricing', 'cost', 'price_method', 'price_percent']);

    // ... and ITFlow prices the line from them
    $line = $save($qid, ['name' => 'ZZ scenario switch', 'description' => '', 'qty' => '3', 'price' => '120', 'tax_id' => 0, 'product_id' => 0, 'frequency' => '',
        'pricing' => '1', 'cost' => '100', 'price_method' => 'markup', 'price_percent' => '20']);
    $r = $row($line);
    scenarioCheck('a line from the add row: cost, markup and % kept', [(float) $r['item_cost'], $r['item_price_method'], (float) $r['item_price_percent']], [100.0, 'markup', 20.0]);
    // A product picked, nothing typed: its cost as a set price
    mysqli_query($mysqli, "INSERT INTO products SET product_name = 'ZZ scenario quote router', product_price = 180, product_cost = 150, product_type = 'product', product_track_stock = 0,
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0");
    $pid = $made['products'][] = mysqli_insert_id($mysqli);
    $line1 = $save($qid, ['name' => 'ZZ scenario quote router', 'description' => '', 'qty' => '2', 'price' => '180', 'tax_id' => 0, 'product_id' => $pid, 'frequency' => '']);
    $r = $row($line1);
    scenarioCheck("a product's line takes its cost", [(float) $r['item_cost'], $r['item_price_method']], [150.0, 'price']);

    // 2. The API: no cost, no markup - while the rows have them
    [$code, $out, $text] = $api("products/read.php?product_id=$pid");
    scenarioCheck('products API answers', [$code, $out['success'] ?? null], [200, 'True']);
    $product = $out['data'][0] ?? [];
    scenarioCheck('products API: the product, without product_cost / price method / %', [$product['product_name'] ?? '', array_intersect(array_keys($product), ['product_cost', 'product_price_method', 'product_price_percent'])],
        ['ZZ scenario quote router', []]);
    [$code, $out] = $api("quote_items/read.php?quote_id=$qid");
    $lines = $out['data'] ?? [];
    $keys = [];
    foreach ($lines as $l) {
        $keys = array_merge($keys, array_intersect(array_keys((array) $l), ['item_cost', 'item_price_method', 'item_price_percent']));
    }
    scenarioCheck('quote lines API: both lines, without item_cost / method / %', [$code, count($lines), $keys], [200, 2, []]);
    scenarioCheck('control - the database rows have the cost', (float) $row($line1)['item_cost'], 150.0);

    // 3. Profit box: labour at a set price with no cost; a monthly line with a cost; a discount
    $save($qid, ['name' => 'ZZ scenario switch', 'description' => '', 'qty' => '4', 'price' => '120', 'tax_id' => 0, 'pricing' => '1', 'cost' => '100', 'price_method' => 'margin', 'price_percent' => '25'], $line);
    $save($qid, ['name' => 'ZZ scenario labour', 'description' => '', 'qty' => '2', 'price' => '50', 'tax_id' => 0, 'product_id' => 0, 'frequency' => '',
        'pricing' => '1', 'cost' => '', 'price_method' => 'price', 'price_percent' => '']);
    $save($qid, ['name' => 'ZZ scenario monitoring', 'description' => '', 'qty' => '5', 'price' => '15', 'tax_id' => 0, 'product_id' => 0, 'frequency' => 'month',
        'pricing' => '1', 'cost' => '10', 'price_method' => 'markup', 'price_percent' => '50']);
    mysqli_query($mysqli, "UPDATE quotes SET quote_discount_amount = 60 WHERE quote_id = $qid");
    billingRecalc('quote', $qid);
    // One-time: cost 2x150 + 4x100 = 700; price 2x180 + 4x120 + 2x50 = 940 - 60 = 880; profit 180. Monthly: cost 50, price 75, profit 25
    $box = mitQuoteProfitBox($qid, numfmt_create('en_US', NumberFormatter::CURRENCY), 'USD');
    $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</div>', '</span>'], [' </div>', ' </span>'], $box)))));
    scenarioCheck('profit box: one-time group', strpos($text, 'One-time Our cost $700.00 Customer price (after $60.00 discount) $880.00 Profit $180.00 Margin 20.5%') !== false, true);
    scenarioCheck('profit box: monthly group on its own', strpos($text, 'Each month Our cost $50.00 Customer price $75.00 Profit $25.00 Margin 33.3%') !== false, true);
    if (strpos($text, 'One-time Our cost $700.00') === false) {
        echo "       box: $text\n";
    }
} finally {
    mysqli_query($mysqli, "DELETE FROM api_key_usage WHERE api_key_usage_key_id = $key_id");
    mysqli_query($mysqli, "DELETE FROM api_keys WHERE api_key_id = $key_id");
    foreach ($made['quotes'] as $id) {
        mysqli_query($mysqli, "DELETE FROM quote_items WHERE item_quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM quotes WHERE quote_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
