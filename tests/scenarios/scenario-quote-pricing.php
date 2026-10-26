<?php
/*
 * Quote pricing and images (functions/mit_quotes.php) worked through on real rows:
 *  - the price maths (markup / margin / set price) and what a form may post;
 *  - a line saved through ITFlow 26.11's billingItemSave + our mitQuoteItemSave hook, as agent/post/billing.php does it:
 *    product's cost taken when none typed, typed cost kept, an edit without our fields keeps them, product image copied;
 *  - copy keeps cost + its own image file, delete removes the file;
 *  - the profit box per billing frequency, discount on the one-time group, labour (set price, no cost) as all profit;
 *  - images: re-encoded (metadata / trailing bytes dropped), SVG and private / local links refused (SSRF guard);
 *  - the quote PDF carries the picture.
 */
require __DIR__ . '/bootstrap.php';
$client_id = scenarioClient();
$made = ['quotes' => [], 'products' => [], 'files' => []];
$png = function ($w = 40, $h = 30) {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    ob_start();
    imagepng($im);
    return ob_get_clean();
};
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
    mitQuoteItemSave($saved, $post, [], !$item_id);
    billingRecalc('quote', $quote_id);
    return intval($saved);
};
$row = function ($item_id) use ($mysqli) {
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM quote_items WHERE item_id = " . intval($item_id)));
};
try {
    // 1. Maths
    scenarioCheck('markup 20% on 100', mitPriceFromCost(100, 'markup', 20), 120.0);
    scenarioCheck('margin 20% on 100', mitPriceFromCost(100, 'margin', 20), 125.0);
    scenarioCheck('margin 100% has no price', mitPriceFromCost(100, 'margin', 100), null);
    scenarioCheck('no cost, no price', mitPriceFromCost(0, 'markup', 20), null);
    scenarioCheck('posted: negative cost dropped, margin >= 100 dropped', mitPricingPosted(['x_cost' => '-5', 'x_price_method' => 'margin', 'x_price_percent' => '150'], 'x'), [null, 'margin', null]);
    scenarioCheck('posted: "$1,234.50" read', mitPricingPosted(['x_cost' => '$1,234.50', 'x_price_method' => 'MARKUP', 'x_price_percent' => '15%'], 'x'), [1234.5, 'markup', 15.0]);
    scenarioCheck('posted: set price has no %', mitPricingPosted(['x_cost' => '10', 'x_price_method' => 'price', 'x_price_percent' => '15'], 'x'), [10.0, 'price', null]);

    // 2. A product with a cost and an image; a line picked from it, nothing typed in our fields (the page's own add row)
    $image = mitImageStore($png());
    $made['files'][] = $image;
    mysqli_query($mysqli, "INSERT INTO products SET product_name = 'ZZ scenario quote router', product_price = 180, product_cost = 150, product_type = 'product', product_track_stock = 0,
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0, product_image = '" . escapeSql($image) . "'");
    $pid = $made['products'][] = mysqli_insert_id($mysqli);
    $qid = $quote();
    $line1 = $save($qid, ['name' => 'ZZ scenario quote router', 'description' => '', 'qty' => '2', 'price' => '180', 'tax_id' => 0, 'product_id' => $pid, 'frequency' => '']);
    $r = $row($line1);
    scenarioCheck('line keeps its product', intval($r['item_product_id']), $pid);
    scenarioCheck('line takes the product cost', (float) $r['item_cost'], 150.0);
    scenarioCheck('... priced as typed (set price)', $r['item_price_method'], 'price');
    scenarioCheck('line gets its own copy of the product image', mitImageIsOurs($r['item_image']) && $r['item_image'] !== $image && is_file(mitImagePath($r['item_image'])), true);
    $made['files'][] = $r['item_image'];

    // 3. Our fields posted (Edit Line window): typed cost and markup kept
    $line2 = $save($qid, ['name' => 'ZZ scenario switch', 'description' => '', 'qty' => '3', 'price' => '120', 'tax_id' => 0, 'product_id' => 0, 'frequency' => '',
        'mit_pricing_present' => '1', 'item_cost' => '100', 'item_price_method' => 'markup', 'item_price_percent' => '20']);
    $r = $row($line2);
    scenarioCheck('typed cost kept', [(float) $r['item_cost'], $r['item_price_method'], (float) $r['item_price_percent']], [100.0, 'markup', 20.0]);
    // An edit on the page without our fields (qty change) keeps them
    $save($qid, ['name' => 'ZZ scenario switch', 'description' => '', 'qty' => '4', 'price' => '120', 'tax_id' => 0], $line2);
    $r = $row($line2);
    scenarioCheck('edit without our fields keeps cost', (float) $r['item_cost'], 100.0);
    scenarioCheck('... and the new quantity', (float) $r['item_quantity'], 4.0);
    // Clearing the cost with our fields present clears it (and its % / markup)
    $save($qid, ['name' => 'ZZ scenario switch', 'description' => '', 'qty' => '4', 'price' => '120', 'tax_id' => 0, 'mit_pricing_present' => '1', 'item_cost' => '', 'item_price_method' => 'markup', 'item_price_percent' => '20'], $line2);
    $r = $row($line2);
    scenarioCheck('cost cleared -> no method', [$r['item_cost'], $r['item_price_method']], [null, null]);
    $save($qid, ['name' => 'ZZ scenario switch', 'description' => '', 'qty' => '4', 'price' => '120', 'tax_id' => 0, 'mit_pricing_present' => '1', 'item_cost' => '100', 'item_price_method' => 'margin', 'item_price_percent' => '25'], $line2);

    // 4. Labour at a set price, no cost; a monthly line with a cost; a discount
    $save($qid, ['name' => 'ZZ scenario labour', 'description' => '', 'qty' => '2', 'price' => '50', 'tax_id' => 0, 'product_id' => 0, 'frequency' => '',
        'mit_pricing_present' => '1', 'item_cost' => '', 'item_price_method' => 'price', 'item_price_percent' => '']);
    $save($qid, ['name' => 'ZZ scenario monitoring', 'description' => '', 'qty' => '5', 'price' => '15', 'tax_id' => 0, 'product_id' => 0, 'frequency' => 'month',
        'mit_pricing_present' => '1', 'item_cost' => '10', 'item_price_method' => 'markup', 'item_price_percent' => '50']);
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
    scenarioCheck('ITFlow quote total = one-time after discount', (float) mysqli_fetch_row(mysqli_query($mysqli, "SELECT quote_amount FROM quotes WHERE quote_id = $qid"))[0], 880.0);

    // 5. Copy: cost and its own image
    $qid2 = $quote();
    mysqli_query($mysqli, "INSERT INTO quote_items (item_name, item_description, item_quantity, item_price, item_subtotal, item_tax, item_total, item_order, item_tax_id, item_quote_id)
        SELECT item_name, item_description, item_quantity, item_price, item_subtotal, item_tax, item_total, item_order, item_tax_id, $qid2 FROM quote_items WHERE item_id = $line1");
    $copy = mysqli_insert_id($mysqli);
    mitQuoteItemCopy($line1, $copy);
    $c = $row($copy);
    scenarioCheck('copy: product, cost, method', [intval($c['item_product_id']), (float) $c['item_cost'], $c['item_price_method']], [$pid, 150.0, 'price']);
    scenarioCheck('copy: its own image file', $c['item_image'] !== $row($line1)['item_image'] && is_file(mitImagePath($c['item_image'])), true);
    $made['files'][] = $c['item_image'];
    $file = mitImagePath($c['item_image']);
    mitQuoteItemDelete($copy);
    scenarioCheck('line delete removes its image file', is_file($file), false);

    // 6. Images: metadata / trailing data dropped, SVG refused, private links refused
    $tainted = $png() . '<?php echo "x"; ?>';
    $stored = mitImageStore($tainted);
    $made['files'][] = $stored;
    scenarioCheck('re-encoded: trailing bytes gone', strpos((string) file_get_contents(mitImagePath($stored)), '<?php') === false, true);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    $error = '';
    try { mitImageStore($svg); } catch (Throwable $e) { $error = $e->getMessage(); }
    scenarioCheck('SVG refused', $error !== '', true);
    foreach (['http://127.0.0.1/qbo-mock/index.php/_state', 'http://10.1.2.3/x.png', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/x.png',
                 'file:///etc/passwd', 'http://localhost/x.png', 'http://127.0.0.1:22/'] as $url) {
        $error = '';
        $got = mitImageFromForm(['item_image_url' => $url], [], 'item_image', $error);
        scenarioCheck("link refused: $url", $got === '' && $error !== '', true);
    }

    // 7. PDF: the picture goes into the quote PDF (TCPDF embeds it as an image XObject)
    $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: getcwd(); // as under Apache - TCPDF puts it in front of /uploads/...
    $quote_row = billingRow('quote', $qid, false);
    $pdf = billingPdf('quote', $quote_row);
    scenarioCheck('quote PDF made', strlen($pdf['data'] ?? '') > 1000, true);
    scenarioCheck('quote PDF has the line image', strpos($pdf['data'] ?? '', '/Subtype /Image') !== false, true);
} finally {
    foreach ($made['quotes'] as $id) {
        mitQuoteImagesDelete($id);
        mysqli_query($mysqli, "DELETE FROM quote_items WHERE item_quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_quote_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM quotes WHERE quote_id = " . intval($id));
    }
    foreach ($made['products'] as $id) {
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($id));
    }
    foreach ($made['files'] as $f) {
        mitImageDelete($f);
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
