<?php

/*
 * Mountaineer IT - what stays ours of quote pricing and pictures. Since 26.12 ITFlow has them itself (functions/quote_pricing.php,
 * functions/quote_images.php: a quote line's cost, Markup / Margin / Set price, its picture, a product's picture - built from ours, the same
 * quote_items / products columns; M067 moved the settings to settings.config_quote_*). Kept here:
 *  - the add row on the billing page (mitPaperAddRow, js/mit_billing_add.js - the user's choice of ITFlow 26.11.7's row over 26.11.8's one
 *    box), posting ITFlow's own pricing fields (pricing, cost, price_method, price_percent) to its billing_item_add;
 *  - the staff-only profit box on a quote (mitQuoteProfitBox - takes the quote's discount off, counts set-price lines with no cost);
 *  - staff-only notes under each line (mitPaperLineExtras: QuickBooks category, minimum commit, procurement status);
 *  - the products API without our cost (mitProductApiColumns);
 *  - shared helpers: mitImageStore (feedback screenshots), mitIpIsPublic, mitPostedNumber, mitQuoteColumnsReady, mitQuoteItemExtra.
 */

const MIT_PRICE_METHODS = ['markup' => 'Markup', 'margin' => 'Margin', 'price' => 'Set price'];
const MIT_IMAGE_DIR = 'uploads/mit_images';
const MIT_IMAGE_MAX_BYTES = 8 * 1024 * 1024;
const MIT_IMAGE_MAX_SIDE = 800;

/*
 * ---------- settings and the sums ----------
 */

/** [method, percent, presets [..], show_images bool] */
function mitQuoteSettings() {
    $settings = function_exists('quotePricingSettings') ? quotePricingSettings() : ['method' => 'markup', 'percent' => 20.0, 'presets' => [15, 20, 25, 35]];
    return $settings + ['show_images' => function_exists('quoteImagesShown') && quoteImagesShown()];
}

function mitPriceMethod($method) {
    $method = strtolower(trim((string) $method));
    return isset(MIT_PRICE_METHODS[$method]) ? $method : '';
}

/** The customer price from our cost: markup 20% on 100 = 120; margin 20% on 100 = 125. Null when it cannot be worked out. */
function mitPriceFromCost($cost, $method, $percent) {
    $cost = (float) $cost;
    $percent = (float) $percent;
    if ($cost <= 0) {
        return null;
    }
    if ($method === 'markup') {
        return round($cost * (1 + $percent / 100), 2);
    }
    if ($method === 'margin' && $percent < 100) {
        return round($cost / (1 - $percent / 100), 2);
    }
    return null;
}

/** Have our columns been added yet (database update M007)? */
function mitQuoteColumnsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM quote_items LIKE 'item_cost'")) > 0
                && mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM products LIKE 'product_cost'")) > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** A posted money / percent value, or null when left blank. */
function mitPostedNumber($value) {
    $value = trim(str_replace([',', '$', '%'], '', (string) $value));
    return $value === '' || !is_numeric($value) ? null : round((float) $value, 2);
}

/*
 * ---------- images ----------
 */

function mitImagePath($relative) {
    return dirname(__DIR__) . '/' . $relative;
}

/** Is this one of our image files (and nothing else)? */
function mitImageIsOurs($relative) {
    return is_string($relative) && preg_match('#^' . preg_quote(MIT_IMAGE_DIR, '#') . '/[a-f0-9]{32}\.(jpg|png)$#', $relative) === 1;
}

/** The URL a browser shows it from. */
function mitImageUrl($relative) {
    return mitImageIsOurs($relative) ? '/' . $relative : '';
}

/** Store image bytes as our own resized copy; returns its relative path. Throws when it is not an image. $dir / $max_side: where
 * and how big (bug report screenshots go to their own closed folder - functions/mit_feedback.php). */
function mitImageStore($bytes, $dir_relative = MIT_IMAGE_DIR, $max_side = MIT_IMAGE_MAX_SIDE) {
    if (strlen($bytes) === 0 || strlen($bytes) > MIT_IMAGE_MAX_BYTES) {
        throw new RuntimeException('The image is empty or bigger than ' . (MIT_IMAGE_MAX_BYTES / 1048576) . ' MB.');
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('That is not a JPG, PNG, GIF or WebP image.');
    }
    // GD holds the whole bitmap in memory (about 4 bytes a pixel), so keep it to ~25 megapixels
    if ($info[0] * $info[1] > 25000000) {
        throw new RuntimeException('That image is too large to work with.');
    }
    $source = @imagecreatefromstring($bytes);
    if (!$source) {
        throw new RuntimeException('That image could not be read.');
    }
    // Re-drawing it keeps only the pixels: metadata and anything hidden after the image data are
    // dropped. SVG is never accepted (it can carry script), so only the four bitmap types get here.
    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, $max_side / max($width, $height));
    $new_width = max(1, (int) round($width * $scale));
    $new_height = max(1, (int) round($height * $scale));
    $alpha = in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true);
    $image = imagecreatetruecolor($new_width, $new_height);
    if ($alpha) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 255, 255, 255, 127));
    } else {
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    }
    imagecopyresampled($image, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    $dir = mitImagePath($dir_relative);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException('Could not create the image folder in uploads.');
    }
    $relative = $dir_relative . '/' . bin2hex(random_bytes(16)) . ($alpha ? '.png' : '.jpg');
    $ok = $alpha ? imagepng($image, mitImagePath($relative), 6) : imagejpeg($image, mitImagePath($relative), 85);
    if (!$ok) {
        throw new RuntimeException('Could not save the image.');
    }
    return $relative;
}

/**
 * Is this IP address on the public internet? Not private, loopback, link-local, reserved, carrier-
 * grade NAT (100.64.0.0/10) or any other special range.
 */
function mitIpIsPublic($ip) {
    $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
        $flags |= FILTER_FLAG_GLOBAL_RANGE; // PHP 8.2+: also CGNAT, documentation and benchmark ranges
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
        return false;
    }
    // Older PHP: carrier-grade NAT by hand, and IPv4-mapped IPv6 (::ffff:10.0.0.1) by its IPv4 part
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (ip2long($ip) & 0xFFC00000) === (ip2long('100.64.0.0') & 0xFFC00000)) {
        return false;
    }
    if (stripos($ip, '::ffff:') === 0) {
        return mitIpIsPublic(substr($ip, 7));
    }
    // IPv6 that carries an IPv4 address through a gateway (NAT64 64:ff9b::/96 and 64:ff9b:1::/48, 6to4 2002::/16,
    // Teredo 2001::/32) could reach the inside network that way - not fetched at all
    $bytes = @inet_pton($ip);
    if ($bytes !== false && strlen($bytes) === 16) {
        $hex = bin2hex($bytes);
        foreach (['0064ff9b0000000000000000', '0064ff9b0001', '2002', '20010000'] as $prefix) {
            if (strpos($hex, $prefix) === 0) {
                return false;
            }
        }
    }
    return true;
}

/*
 * ---------- quote lines ----------
 */

/** Our extras for a quote's lines: item_id => [cost, method, percent, image]. */
function mitQuoteItemExtras($quote_id) {
    global $mysqli;
    static $cache = [];
    $quote_id = intval($quote_id);
    if (!mitQuoteColumnsReady()) {
        return [];
    }
    if (!isset($cache[$quote_id])) {
        $cache[$quote_id] = [];
        $frequency = function_exists('mitColumnReady') && mitColumnReady('quote_items', 'item_frequency') ? 'item_frequency' : "'' AS item_frequency";
        $result = mysqli_query($mysqli, "SELECT item_id, item_cost, item_price_method, item_price_percent, item_image, item_quantity, item_price, $frequency
            FROM quote_items WHERE item_quote_id = $quote_id");
        while ($row = mysqli_fetch_assoc($result)) {
            $cache[$quote_id][intval($row['item_id'])] = $row;
        }
    }
    return $cache[$quote_id];
}

function mitQuoteItemExtra($quote_id, $item_id) {
    return mitQuoteItemExtras($quote_id)[intval($item_id)] ?? null;
}

/**
 * Column list for the API's products read (api/v1/products/read.php): every products column except
 * our cost and markup ones, so an API key with Sales access does not see what we pay or our margin.
 */
function mitProductApiColumns() {
    global $mysqli;
    $hidden = ['product_cost', 'product_price_method', 'product_price_percent'];
    $columns = [];
    $result = mysqli_query($mysqli, "SHOW COLUMNS FROM products");
    while ($result && $row = mysqli_fetch_assoc($result)) {
        if (!in_array($row['Field'], $hidden, true)) {
            $columns[] = '`' . $row['Field'] . '`';
        }
    }
    return $columns ? implode(', ', $columns) : '*';
}

/** The API's read answers (api/v1/read_output.php, one line): what things cost us left out - quote lines', products' (a rule of ours). */
const MIT_API_HIDDEN = ['item_cost', 'item_price_method', 'item_price_percent', 'product_cost', 'product_price_method', 'product_price_percent'];
function mitApiHideCost(array $rows) {
    foreach ($rows as &$row) {
        if (is_array($row)) {
            $row = array_diff_key($row, array_flip(MIT_API_HIDDEN));
        }
    }
    unset($row);
    return $rows;
}

/** Products' pricing and images for the quote add-item row: {product_id: {cost, method, percent, image}}. */
function mitProductsPricingJson() {
    global $mysqli;
    $out = new stdClass();
    if (!mitQuoteColumnsReady()) {
        return json_encode($out);
    }
    $result = mysqli_query($mysqli, "SELECT product_id, product_cost, product_price_method, product_price_percent, product_image FROM products
        WHERE product_archived_at IS NULL AND (product_cost > 0 OR product_image IS NOT NULL)");
    while ($row = mysqli_fetch_assoc($result)) {
        $out->{intval($row['product_id'])} = [
            'cost' => (float) $row['product_cost'] > 0 ? number_format((float) $row['product_cost'], 2, '.', '') : '',
            'method' => mitPriceMethod($row['product_price_method']),
            'percent' => $row['product_price_percent'] === null ? '' : rtrim(rtrim(number_format((float) $row['product_price_percent'], 2, '.', ''), '0'), '.'),
            'image' => mitImageUrl($row['product_image'] ?? ''),
        ];
    }
    return json_encode($out);
}

/**
 * "Markup 20%" and what that is as the other measure. A line saved as markup / margin whose price
 * was later changed by hand (so it no longer comes from the cost) shows as a set price.
 */
function mitPricingBadge($cost, $method, $percent, $price) {
    $cost = (float) $cost;
    $price = (float) $price;
    $method = mitPriceMethod($method);
    if (($method === 'markup' || $method === 'margin') && $cost > 0
        && abs((float) mitPriceFromCost($cost, $method, $percent) - $price) >= 0.01) {
        $method = 'price';
    }
    if ($cost <= 0 || $price <= 0) {
        return $method === 'price' ? '<span class="badge text-bg-light border">Set price</span>' : '';
    }
    $markup = ($price - $cost) / $cost * 100;
    $margin = ($price - $cost) / $price * 100;
    if ($method === 'markup' || $method === 'margin') {
        $badge = '<span class="badge ' . ($method === 'markup' ? 'text-bg-primary' : 'text-bg-success') . '">' . MIT_PRICE_METHODS[$method] . ' ' . rtrim(rtrim(number_format((float) $percent, 2), '0'), '.') . '%</span>';
    } else {
        $badge = '<span class="badge text-bg-light border">Set price</span>';
    }
    $other = $method === 'margin' ? number_format($markup, 1) . '% markup' : number_format($margin, 1) . '% margin';
    return $badge . '<div class="small ' . ($margin < 0 ? 'text-danger' : 'text-body-secondary') . '">' . $other . '</div>';
}

/**
 * Quote page: the staff-only profit box (not printed). The customer price is the lines' total less
 * the quote's discount (before tax - tax is not ours to keep).
 */
function mitQuoteProfitBox($quote_id, $currency_format, $currency_code) {
    global $mysqli;
    if (!mitQuoteColumnsReady()) {
        return '';
    }
    // ITFlow 26.11 quotes bill lines once or each month / quarter / year - each is summed on its own (never added together)
    $labels = ['' => 'One-time', 'month' => 'Each month', 'quarter' => 'Each quarter', 'year' => 'Each year'];
    $groups = [];
    $missing = 0;
    foreach (mitQuoteItemExtras($quote_id) as $extra) {
        $frequency = function_exists('quoteFrequency') ? quoteFrequency($extra['item_frequency'] ?? '') : '';
        $groups[$frequency] = $groups[$frequency] ?? ['cost' => 0.0, 'priced' => 0.0, 'discount' => 0.0];
        $line = (float) $extra['item_price'] * (float) $extra['item_quantity'];
        if ($extra['item_cost'] === null) {
            if (mitPriceMethod($extra['item_price_method']) !== 'price') {
                $missing++;
            }
            $groups[$frequency]['priced'] += $line;  // set-price lines (labour) count as all profit
            continue;
        }
        $groups[$frequency]['cost'] += (float) $extra['item_cost'] * (float) $extra['item_quantity'];
        $groups[$frequency]['priced'] += $line;
    }
    if (!$groups) {
        return '';
    }
    $groups = array_intersect_key(array_replace(array_fill_keys(array_keys($labels), null), $groups), $groups);
    // The quote's discount comes off the one-time lines, else the first group billed again (as ITFlow's quote totals)
    $discount = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT quote_discount_amount FROM quotes WHERE quote_id = " . intval($quote_id)));
    $discount = (float) ($discount['quote_discount_amount'] ?? 0);
    if ($discount > 0) {
        $first = array_key_first($groups);
        $groups[$first]['discount'] = $discount;
        $groups[$first]['priced'] -= $discount;
    }
    $money = function ($n) use ($currency_format, $currency_code) {
        return numfmt_format_currency($currency_format, $n, $currency_code);
    };
    $html = '';
    foreach ($groups as $frequency => $g) {
        if ($g['priced'] <= 0) {
            continue;
        }
        $profit = $g['priced'] - $g['cost'];
        $tone = $profit < 0 ? 'text-danger' : 'text-success-emphasis';
        $html .= (count($groups) > 1 ? '<div class="fw-semibold mt-2" data-mit-profit-group="' . escapeHtml($frequency ?: 'once') . '">' . $labels[$frequency] . '</div>' : '')
            . '<div class="d-flex justify-content-between"><span>Our cost</span><span>' . $money($g['cost']) . '</span></div>'
            . '<div class="d-flex justify-content-between"><span>Customer price' . ($g['discount'] > 0 ? ' (after ' . $money($g['discount']) . ' discount)' : '') . '</span><span>' . $money($g['priced']) . '</span></div>'
            . '<div class="d-flex justify-content-between fw-bold border-top mt-1 pt-1 ' . $tone . '"><span>Profit</span><span>' . $money($profit) . '</span></div>'
            . '<div class="d-flex justify-content-between ' . $tone . '"><span>Margin</span><span>' . number_format($profit / $g['priced'] * 100, 1) . '%</span></div>';
    }
    if ($html === '') {
        return '';
    }
    // A card of the side column, under the Quote card (26.11.1.8 - was under the quote itself), tinted as staff-only
    return '<div class="card mb-3 border-warning-subtle d-print-none" data-mit-profit-box>'
        . '<div class="card-header bg-warning-subtle"><h3 class="card-title mb-0"><i class="fas fa-fw fa-chart-line text-warning-emphasis me-2"></i>Profit'
        . ' <span class="small fw-normal text-warning-emphasis ms-1">only staff see this</span></h3></div>'
        . '<div class="card-body">' . $html
        . ($missing ? '<div class="small text-body-secondary mt-2">' . $missing . ' line' . ($missing === 1 ? ' has' : 's have') . ' no cost entered - counted as all profit.</div>' : '')
        . '</div></div>';
}

/**
 * Under a line's name on the agent page only (the paper being edited - never the client's copy, never print): a quote line's
 * cost and how it was priced; an invoice line's ordering (procurement). '' otherwise.
 */
function mitPaperLineExtras(array $paper, array $item) {
    return mitPaperLineExtrasOwn($paper, $item) . (function_exists('mitMinimumLineNote') ? mitMinimumLineNote($paper, $item) : ''); // and a minimum commit line's figures
}

/** mitPaperLineExtras without the minimum commit note. */
function mitPaperLineExtrasOwn(array $paper, array $item) {
    if (empty($paper['edit']) && empty($paper['mit_staff'])) {
        return '';
    }
    $item_id = intval($item['item_id']);
    if (($paper['type'] ?? '') === 'quote' && mitQuoteColumnsReady()) {
        $extra = mitQuoteItemExtra(intval($paper['row']['quote_id'] ?? 0), $item_id);
        $cost = $extra['item_cost'] ?? null;
        if ($cost === null || $cost === '') {
            return '';
        }
        return '<div class="small d-print-none mt-1" data-mit-line-cost><span class="badge rounded-pill bg-warning-subtle text-warning-emphasis fw-normal">Our cost '
            . escapeHtml(billingMoney((float) $cost, (string) ($paper['row']['quote_currency_code'] ?? ''))) . '</span> '
            . mitPricingBadge($cost, $extra['item_price_method'] ?? '', $extra['item_price_percent'] ?? '', $item['item_price']) . '</div>';
    }
    if (($paper['type'] ?? '') === 'invoice') {
        $status = function_exists('mitProcInvoiceLineStatus') ? mitProcInvoiceLineStatus(intval($paper['row']['invoice_id'] ?? 0), $item_id) : '';
        $category = function_exists('mitQboLineCategoryTag') && !empty($paper['mit_staff']) ? mitQboLineCategoryTag((array) $paper['row'], $item) : '';
        return $status . $category !== '' ? '<div class="d-print-none">' . $status . ($category !== '' ? '<div class="small mt-1">' . $category . '</div>' : '') . '</div>' : '';
    }
    return '';
}

/**
 * The add row on an invoice, quote or recurring invoice the agent is editing, as it was in ITFlow 26.11.7 (includes/billing_paper.php, in
 * place of ITFlow 26.11.8's one "type a product" field - the user's choice): Item with the description under it (the item box searches
 * the products - a pick fills the row in, adds nothing), Qty, Billed (quote) / Counted (recurring), on a quote our cost, Price by and %,
 * Price, Tax, the line's total and Add. js/mit_billing_add.js sends it to ITFlow's own ajax.php billing_item_add and redraws through
 * ITFlow's js/billing_items.js (no reload). A line's picture is added in the Edit Line window, as before. '' when not being edited.
 */
function mitPaperAddRow(array $paper) {
    if (empty($paper['edit'])) {
        return '';
    }
    $type = (string) ($paper['type'] ?? '');
    $row = (array) ($paper['row'] ?? []);
    $client_id = intval($row['client_id'] ?? 0);
    $col = (string) (billingType($type)['col'] ?? '');
    $quote = $type === 'quote';
    $seats = in_array($type, ['recurring', 'quote'], true);
    // ITFlow 26.11.7's add row, as it was (its markup and classes; their styles went in 26.11.8, so they come with it below)
    $html = '<form data-mit-add-row data-type="' . escapeHtml($type) . '" autocomplete="off" novalidate>'
        . '<input type="hidden" name="csrf_token" value="' . escapeHtml((string) ($paper['csrf'] ?? ($_SESSION['csrf_token'] ?? ''))) . '">'
        . '<input type="hidden" name="type" value="' . escapeHtml($type) . '"><input type="hidden" name="doc_id" value="' . intval($row[$col . '_id'] ?? 0) . '">'
        . '<input type="hidden" name="product_id" value="0">'
        . '<div class="itflow-paper-add-grid' . ($seats ? ' itflow-paper-add-grid-seats' : '') . '">'
        . '<div class="itflow-paper-add-item">'
        . '<input type="text" class="form-control form-control-sm mb-1" name="name" placeholder="' . (!empty($paper['items']) ? 'Add a line - type a product, or anything' : 'First line - type a product, or anything') . '" maxlength="200" data-billing-add-name aria-label="Item">'
        . '<textarea class="form-control form-control-sm" name="description" rows="1" placeholder="Description (optional)" aria-label="Description" data-mit-add-description></textarea></div>'
        . '<div class="itflow-paper-add-qty"><label class="form-label small text-body-secondary mb-0">Qty</label>'
        . '<input type="text" class="form-control form-control-sm text-end" name="qty" inputmode="decimal" placeholder="1" value="1"></div>';
    if ($type === 'recurring') {
        $html .= '<div class="itflow-paper-add-seat"><label class="form-label small text-body-secondary mb-0">Counted</label>'
            . '<select class="form-select form-select-sm" name="seat_source" title="Bill per user or per device - counted again for each invoice">'
            . '<option value="">As typed</option><option value="contacts">Per user</option><option value="assets">Per device</option></select></div>';
    } elseif ($quote) {
        $html .= '<div class="itflow-paper-add-seat"><label class="form-label small text-body-secondary mb-0">Billed</label>'
            . '<select class="form-select form-select-sm" name="frequency" title="Billed once, or each month, quarter or year - those go on a recurring invoice when the quote is invoiced">';
        foreach (quoteFrequencies() as $value => $label) {
            $html .= '<option value="' . escapeHtml($value) . '">' . escapeHtml($label) . '</option>';
        }
        $html .= '</select></div>';
    }
    if ($quote && mitQuoteColumnsReady()) {
        // Staff only - our cost and markup (as on 26.11.7's row)
        $settings = mitQuoteSettings();
        $currency = escapeHtml($row['quote_currency_code'] ?? 'USD');
        $html .= '<input type="hidden" name="pricing" value="1">'
            . '<div class="itflow-paper-add-price" data-mit-currency="' . $currency . '"><label class="form-label small text-warning-emphasis mb-0" title="Only staff see this">Our cost</label>'
            . '<input type="text" class="form-control form-control-sm text-end border-warning-subtle bg-warning-subtle" name="cost" inputmode="decimal" placeholder="0.00" data-mit-cost data-mit-price-field="price"></div>'
            . '<div class="itflow-paper-add-seat"><label class="form-label small text-warning-emphasis mb-0">Price by</label>'
            . '<select class="form-select form-select-sm border-warning-subtle bg-warning-subtle" name="price_method" data-mit-method>';
        foreach (MIT_PRICE_METHODS as $value => $label) {
            $html .= '<option value="' . $value . '"' . ($value === $settings['method'] ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select></div><div class="itflow-paper-add-qty"><label class="form-label small text-warning-emphasis mb-0">%</label>'
            . '<input type="text" class="form-control form-control-sm text-end border-warning-subtle bg-warning-subtle" name="price_percent" inputmode="decimal" value="'
            . escapeHtml(rtrim(rtrim(number_format((float) $settings['percent'], 2, '.', ''), '0'), '.')) . '" data-mit-pct></div>';
    }
    $html .= '<div class="itflow-paper-add-price"><label class="form-label small text-body-secondary mb-0">Price</label>'
        . '<input type="text" class="form-control form-control-sm text-end" name="price" inputmode="decimal" placeholder="0.00"></div>'
        . '<div class="itflow-paper-add-tax"><label class="form-label small text-body-secondary mb-0">Tax</label>'
        . '<select class="form-select form-select-sm" name="tax_id">' . taxOptionsHtml($paper['taxes'] ?? [], taxDefaultFor($client_id)) . '</select></div>'
        . '<div class="itflow-paper-add-go"><span class="small text-body-secondary text-nowrap" data-line-preview></span>'
        . '<button type="submit" class="btn btn-sm btn-primary text-nowrap" title="Add the line" data-mit-add-go><i class="fas fa-plus me-1"></i>Add</button></div>';
    if ($quote && mitQuoteColumnsReady()) {
        $html .= '<div class="small text-body-secondary" style="order: 99; flex-basis: 100%" data-mit-hint data-mit-quiet data-mit-currency="' . escapeHtml($row['quote_currency_code'] ?? 'USD') . '"></div>'
            . '<script>window.mitProductPricing = ' . mitProductsPricingJson() . ';</script><script src="' . mitAsset('/js/mit_quote_pricing.js') . '"></script>';
    }
    return $html . '</div></form><style>'
        . '.itflow-paper-add-grid{display:flex;flex-wrap:wrap;align-items:flex-end;gap:.5rem}.itflow-paper-add-item{flex:1 1 15rem}'
        . '.itflow-paper-add-qty{flex:0 0 4.5rem}.itflow-paper-add-price{flex:0 0 7rem}.itflow-paper-add-seat{flex:0 0 7.5rem}'
        . '.itflow-paper-add-grid-seats .itflow-paper-add-item{flex-basis:100%}.itflow-paper-add-tax{flex:0 1 9rem}'
        . '.itflow-paper-add-go{display:flex;align-items:center;gap:.5rem;margin-left:auto}'
        . '</style><script src="' . mitAsset('/js/mit_billing_add.js') . '"></script>';
}
