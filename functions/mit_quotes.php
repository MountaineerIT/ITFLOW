<?php

/*
 * Mountaineer IT - quote pricing and line images (database update M007).
 *
 *  - Each quote line can carry OUR cost and how its customer price comes from it: a markup or
 *    margin % or a set price. Cost, method, % and profit are for staff only - never on the quote
 *    page's print, the PDF, the guest link or the client portal. Invoices are made from the
 *    customer price only, as before.
 *  - Products remember a cost, method, % and image, so picking one fills the line in.
 *  - Each line (and product) can have one image - uploaded, or pulled from a link (an image, or a
 *    web page's main picture). ITFlow keeps its own resized copy under uploads/mit_images/, with an
 *    unguessable name; every line and product owns its own copy.
 *  - Defaults (method, %, presets, show images) live in mit_settings, prefixed quote_.
 *
 * Hooks in ITFlow's files are one line each (CLAUDE.md lists them).
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
    static $settings = null;
    if ($settings === null) {
        $method = mitSetting('quote_price_method', 'markup');
        $presets = array_values(array_filter(array_map('floatval', explode(',', (string) mitSetting('quote_price_presets', '15,20,25,35'))), function ($p) {
            return $p > 0 && $p < 1000;
        }));
        $settings = [
            'method' => isset(MIT_PRICE_METHODS[$method]) && $method !== 'price' ? $method : 'markup',
            'percent' => (float) mitSetting('quote_price_percent', '20'),
            'presets' => $presets ?: [15, 20, 25, 35],
            'show_images' => mitSetting('quote_show_images', '1') === '1',
        ];
    }
    return $settings;
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

/**
 * The cost, method and % a form posted (fields <prefix>_cost, _price_method, _price_percent), made
 * sane: a negative cost or % is dropped, a margin of 100% or more (no price can give it) is dropped,
 * and a set price has no %. Returns [cost|null, method|'', percent|null].
 */
function mitPricingPosted(array $post, $prefix) {
    $cost = mitPostedNumber($post[$prefix . '_cost'] ?? '');
    $method = mitPriceMethod($post[$prefix . '_price_method'] ?? '');
    $percent = mitPostedNumber($post[$prefix . '_price_percent'] ?? '');
    if ($cost !== null && $cost < 0) {
        $cost = null;
    }
    if ($method === 'price' || ($percent !== null && ($percent < 0 || $percent >= 100000))
        || ($method === 'margin' && $percent !== null && $percent >= 100)) {
        $percent = null;
    }
    return [$cost, $method, $percent];
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

/** Store image bytes as our own resized copy; returns its relative path. Throws when it is not an image. */
function mitImageStore($bytes) {
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
    $scale = min(1, MIT_IMAGE_MAX_SIDE / max($width, $height));
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

    $dir = mitImagePath(MIT_IMAGE_DIR);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        throw new RuntimeException('Could not create the image folder in uploads.');
    }
    $relative = MIT_IMAGE_DIR . '/' . bin2hex(random_bytes(16)) . ($alpha ? '.png' : '.jpg');
    $ok = $alpha ? imagepng($image, mitImagePath($relative), 6) : imagejpeg($image, mitImagePath($relative), 85);
    if (!$ok) {
        throw new RuntimeException('Could not save the image.');
    }
    return $relative;
}

/** An uploaded file ($_FILES entry) stored; '' when none was sent. Throws on a bad one. */
function mitImageFromUpload($file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The image upload did not come through - try again (up to 8 MB).');
    }
    return mitImageStore((string) file_get_contents($file['tmp_name']));
}

/** A copy of one of our images (so each line and product owns its file); '' when there is none. */
function mitImageCopy($relative) {
    if (!mitImageIsOurs($relative) || !is_file(mitImagePath($relative))) {
        return '';
    }
    $copy = MIT_IMAGE_DIR . '/' . bin2hex(random_bytes(16)) . '.' . pathinfo($relative, PATHINFO_EXTENSION);
    return @copy(mitImagePath($relative), mitImagePath($copy)) ? $copy : '';
}

function mitImageDelete($relative) {
    if (mitImageIsOurs($relative)) {
        @unlink(mitImagePath($relative));
    }
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

/**
 * Fetch a web address for an image, safely: http(s) only, public addresses only (never this server
 * or the internal network - checked for every address the name resolves to, and the connection is
 * pinned to the checked one), at most 3 redirects, a size cap and a time limit for the whole fetch
 * (redirects included). Returns [body, content type].
 */
function mitFetchPublic($url, $max_bytes = MIT_IMAGE_MAX_BYTES, $deadline = null) {
    $deadline = $deadline ?? microtime(true) + 20;
    for ($hop = 0; $hop < 4; $hop++) {
        $time_left = (int) floor($deadline - microtime(true));
        if ($time_left < 2) {
            throw new RuntimeException('The website took too long to answer.');
        }
        $parts = parse_url((string) $url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Use a normal http:// or https:// web address.');
        }
        $port = intval($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443, 8080, 8443], true)) {
            throw new RuntimeException('That web address uses a port ITFlow will not fetch from.');
        }
        $host_plain = trim($host, '[]');
        $ips = filter_var($host_plain, FILTER_VALIDATE_IP) ? [$host_plain] : array_merge(
            (array) @gethostbynamel($host_plain),
            array_column((array) @dns_get_record($host_plain, DNS_AAAA), 'ipv6')
        );
        $ips = array_values(array_filter($ips));
        if (!$ips) {
            throw new RuntimeException("Could not find $host.");
        }
        foreach ($ips as $ip) {
            if (!mitIpIsPublic($ip)) {
                throw new RuntimeException('That address is on a private or local network - ITFlow only fetches public web addresses.');
            }
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => min(15, $time_left),
            CURLOPT_CONNECTTIMEOUT => min(8, $time_left),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '', // no proxy from the environment - it would look the name up again itself
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => ["$host_plain:$port:" . (strpos($ips[0], ':') !== false ? "[{$ips[0]}]" : $ips[0])],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ITFlow image fetch)',
            CURLOPT_HTTPHEADER => ['Accept: image/*,text/html;q=0.9,*/*;q=0.5'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $max_bytes) {
                $body .= $chunk;
                return strlen($body) > $max_bytes ? 0 : strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
        $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error = curl_error($ch);
        curl_close($ch);
        if (strlen($body) > $max_bytes) {
            throw new RuntimeException('That file is bigger than ' . ($max_bytes / 1048576) . ' MB.');
        }
        if ($status >= 300 && $status < 400 && $redirect !== '') {
            // curl works out relative Location headers against the address it fetched
            $url = $redirect;
            continue;
        }
        if ($status !== 200) {
            throw new RuntimeException('The website answered ' . ($status ?: $error ?: 'nothing') . '.');
        }
        return [$body, $type];
    }
    throw new RuntimeException('That web address redirects too many times.');
}

/**
 * A link found on a page, made absolute against the page's address: "//cdn/x.jpg", "/x.jpg" and
 * "img/x.jpg" (relative to the page's folder) all work. Only http(s) results come back; '' otherwise.
 */
function mitUrlResolve($base, $relative) {
    $relative = trim($relative);
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }
    $b = parse_url($base);
    if (empty($b['scheme']) || empty($b['host'])) {
        return '';
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative)) {
        return ''; // data:, javascript: and the like
    }
    if (strpos($relative, '//') === 0) {
        return $b['scheme'] . ':' . $relative;
    }
    $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (strpos($relative, '/') === 0) {
        return $origin . $relative;
    }
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    if (strpos($relative, '?') === 0) {
        return $origin . ($b['path'] ?? '/') . $relative;
    }
    // Fold "./" and "../" so the address is a clean one
    $segments = [];
    foreach (explode('/', $dir . $relative) as $segment) {
        if ($segment === '..') {
            array_pop($segments);
        } elseif ($segment !== '.') {
            $segments[] = $segment;
        }
    }
    return $origin . '/' . ltrim(implode('/', $segments), '/');
}

/** An image from a link - the image itself, or a web page's main picture (og:image and the like). Stored; returns its path. */
function mitImageFromUrl($url) {
    $url = trim((string) $url);
    $deadline = microtime(true) + 25; // the page and its picture together
    [$body, $type] = mitFetchPublic($url, MIT_IMAGE_MAX_BYTES, $deadline);
    if (strpos($type, 'text/html') !== false || preg_match('/^\s*<(!doctype|html)/i', substr($body, 0, 200))) {
        $image_url = '';
        foreach (['og:image:secure_url', 'og:image', 'twitter:image', 'twitter:image:src'] as $name) {
            if (preg_match('/<meta[^>]+(?:property|name)=["\']' . preg_quote($name, '/') . '["\'][^>]*content=["\']([^"\']+)["\']/i', $body, $m)
                || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']' . preg_quote($name, '/') . '["\']/i', $body, $m)) {
                $image_url = html_entity_decode($m[1], ENT_QUOTES);
                break;
            }
        }
        if ($image_url === '' && preg_match('/<link[^>]+rel=["\']image_src["\'][^>]*href=["\']([^"\']+)["\']/i', $body, $m)) {
            $image_url = html_entity_decode($m[1], ENT_QUOTES);
        }
        if ($image_url === '') {
            throw new RuntimeException('That page does not name a main picture - right-click the picture you want, copy its image address, and use that instead.');
        }
        $image_url = mitUrlResolve($url, $image_url);
        if ($image_url === '') {
            throw new RuntimeException('That page\'s main picture is not a normal web address - copy the image address instead.');
        }
        [$body] = mitFetchPublic($image_url, MIT_IMAGE_MAX_BYTES, $deadline);
    }
    return mitImageStore($body);
}

/**
 * The image a form asks for: a new upload, else a link to pull from. Returns the new path, '' for
 * "nothing new", and keeps $error for the screen when the image could not be had.
 */
function mitImageFromForm(array $post, array $files, $field, &$error) {
    $error = '';
    try {
        $upload = mitImageFromUpload($files[$field] ?? null);
        if ($upload !== '') {
            return $upload;
        }
        $url = trim((string) ($post[$field . '_url'] ?? ''));
        return $url !== '' ? mitImageFromUrl($url) : '';
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return '';
    }
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
 * Hook after a quote line is added or edited (agent/post/quote.php): save our cost, method, % and
 * image. A new line picked from a product gets the product's image when no other was given.
 */
function mitQuoteItemSave($item_id, array $post, array $files, $is_new = false) {
    global $mysqli;
    $item_id = intval($item_id);
    // A new line picked from a product keeps it in ITFlow's own quote_items.item_product_id (ITFlow's add_quote_item
    // leaves it 0), so quote -> invoice "Send to procurement" knows the product: its vendor, and hardware ticked
    $product_id = intval($post['product_id'] ?? 0);
    if ($item_id && $is_new && $product_id && getFieldById('products', $product_id, 'product_id')) {
        mysqli_query($mysqli, "UPDATE quote_items SET item_product_id = $product_id WHERE item_id = $item_id");
    }
    if (!$item_id || !mitQuoteColumnsReady()) {
        return;
    }
    if (!empty($post['mit_pricing_present'])) {
        // Our fields were on the form (ITFlow 26.11's Edit Line window - mitQuoteLineFields)
        [$cost, $method, $percent] = mitPricingPosted($post, 'item');
        if ($cost === null && $is_new && $product_id) {
            // The add row left blank on a product: the product's cost, priced as typed
            $product_cost = (float) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT product_cost FROM products WHERE product_id = $product_id"))[0] ?? 0);
            if ($product_cost > 0) {
                [$cost, $method, $percent] = [$product_cost, 'price', null];
            }
        }
        if ($cost === null && $method !== 'price') {
            $method = '';
            $percent = null;
        }
        $set = "item_cost = " . ($cost === null ? 'NULL' : $cost)
            . ", item_price_method = " . ($method === '' ? 'NULL' : "'$method'")
            . ", item_price_percent = " . ($percent === null ? 'NULL' : $percent);
    } elseif ($is_new && $product_id) {
        // A line added on the page from a product: the product's cost (ITFlow's products.product_cost, 0 = none), priced as typed
        $product_cost = (float) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT product_cost FROM products WHERE product_id = $product_id"))[0] ?? 0);
        $set = $product_cost > 0 ? "item_cost = $product_cost, item_price_method = 'price', item_price_percent = NULL" : "item_id = item_id";
    } else {
        // Changed without our fields (the line on the page) - its cost and pricing stay as they were
        $set = "item_id = item_id";
    }

    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_image FROM quote_items WHERE item_id = $item_id"));
    $old_image = (string) ($row['item_image'] ?? '');
    $error = '';
    $new_image = mitImageFromForm($post, $files, 'item_image', $error);
    if ($new_image === '' && $is_new && intval($post['product_id'] ?? 0)) {
        $product = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT product_image FROM products WHERE product_id = " . intval($post['product_id'])));
        $new_image = mitImageCopy($product['product_image'] ?? '');
    }
    if ($new_image !== '') {
        $set .= ", item_image = '" . mysqli_real_escape_string($mysqli, $new_image) . "'";
        mitImageDelete($old_image);
    } elseif (!empty($post['item_image_remove'])) {
        $set .= ", item_image = NULL";
        mitImageDelete($old_image);
    }
    mysqli_query($mysqli, "UPDATE quote_items SET $set WHERE item_id = $item_id");
    if ($error !== '') {
        $_SESSION['mit_image_error'] = $error;
    }
}

/** ITFlow 26.11's Edit Line window on a quote (agent/modals/billing/billing_item.php): our cost, markup / margin and the line's image. */
function mitQuoteLineFields($quote_id, array $item) {
    if (!mitQuoteColumnsReady()) {
        return '';
    }
    $extra = mitQuoteItemExtra(intval($quote_id), intval($item['item_id']));
    return '<div class="mt-3"><input type="hidden" name="mit_pricing_present" value="1">'
        . mitPricingFields(['item_cost', 'item_price_method', 'item_price_percent'], $extra['item_cost'] ?? null, $extra['item_price_method'] ?? null, $extra['item_price_percent'] ?? null)
        . (mitQuoteSettings()['show_images'] ? mitImageField('item_image', $extra['item_image'] ?? '') : '') . '</div>';
}

/**
 * ITFlow 26.11's add-a-line row on a quote (includes/billing_paper.php): our cost, price by and % beside the Price box, on
 * the row's own form (billingAddLine). js/mit_quote_pricing.js works the price out and fills them from a picked product.
 */
function mitPaperAddFields(array $paper) {
    if (!mitQuoteColumnsReady() || empty($paper['edit'])) {
        return '';
    }
    $settings = mitQuoteSettings();
    $fmt = rtrim(rtrim(number_format((float) $settings['percent'], 2, '.', ''), '0'), '.');
    global $session_company_currency;
    $html = '<input type="hidden" name="mit_pricing_present" value="1" form="billingAddLine">'
        . '<div class="itflow-paper-add-price" data-mit-add-pricing data-mit-currency="' . escapeHtml($session_company_currency ?? 'USD') . '">'
        . '<label class="form-label small text-warning-emphasis mb-0" title="Only staff see this">Our cost</label>'
        . '<input type="text" class="form-control form-control-sm text-end border-warning-subtle bg-warning-subtle" name="item_cost" form="billingAddLine" inputmode="decimal" placeholder="0.00" data-mit-cost></div>'
        . '<div class="itflow-paper-add-seat"><label class="form-label small text-warning-emphasis mb-0">Price by</label>'
        . '<select class="form-select form-select-sm border-warning-subtle bg-warning-subtle" name="item_price_method" form="billingAddLine" data-mit-method>';
    foreach (MIT_PRICE_METHODS as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($value === $settings['method'] ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select></div>'
        . '<div class="itflow-paper-add-qty"><label class="form-label small text-warning-emphasis mb-0">%</label>'
        . '<input type="text" class="form-control form-control-sm text-end border-warning-subtle bg-warning-subtle" name="item_price_percent" form="billingAddLine" inputmode="decimal" value="' . escapeHtml($fmt) . '" data-mit-pct></div>'
        . '<div class="small text-body-secondary" style="order: 99; flex-basis: 100%" data-mit-hint data-mit-quiet data-mit-currency="' . escapeHtml($session_company_currency ?? 'USD') . '"></div>'
        . '<script>window.mitProductPricing = ' . mitProductsPricingJson() . ';</script>'
        . '<script src="' . mitAsset('/js/mit_quote_pricing.js') . '"></script>';
}

/** Hook in a quote copy: the new line gets the old one's product, cost, method, % and its own copy of the image. */
function mitQuoteItemCopy($from_item_id, $to_item_id) {
    global $mysqli;
    // The product too (ITFlow's copy leaves item_product_id out)
    $product_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT item_product_id FROM quote_items WHERE item_id = " . intval($from_item_id)))[0] ?? 0);
    if ($product_id) {
        mysqli_query($mysqli, "UPDATE quote_items SET item_product_id = $product_id WHERE item_id = " . intval($to_item_id));
    }
    if (!mitQuoteColumnsReady()) {
        return;
    }
    $from = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_cost, item_price_method, item_price_percent, item_image FROM quote_items WHERE item_id = " . intval($from_item_id)));
    if (!$from) {
        return;
    }
    $image = mitImageCopy($from['item_image'] ?? '');
    mysqli_query($mysqli, "UPDATE quote_items SET item_cost = " . ($from['item_cost'] === null ? 'NULL' : floatval($from['item_cost']))
        . ", item_price_method = " . (mitPriceMethod($from['item_price_method']) === '' ? 'NULL' : "'" . mitPriceMethod($from['item_price_method']) . "'")
        . ", item_price_percent = " . ($from['item_price_percent'] === null ? 'NULL' : floatval($from['item_price_percent']))
        . ", item_image = " . ($image === '' ? 'NULL' : "'" . mysqli_real_escape_string($mysqli, $image) . "'")
        . " WHERE item_id = " . intval($to_item_id));
}

/** Hook before a quote line is deleted: its image file goes with it. */
function mitQuoteItemDelete($item_id) {
    global $mysqli;
    if (!mitQuoteColumnsReady()) {
        return;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT item_image FROM quote_items WHERE item_id = " . intval($item_id)));
    mitImageDelete($row['item_image'] ?? '');
}

/** Hook before all of a quote's lines are deleted at once (a client being deleted). */
function mitQuoteImagesDelete($quote_id) {
    foreach (mitQuoteItemExtras($quote_id) as $extra) {
        mitImageDelete($extra['item_image'] ?? '');
    }
}

/** The image-could-not-be-had message from the last save, once, for flashAlert. */
function mitImageErrorFlash() {
    if (!empty($_SESSION['mit_image_error'])) {
        flashAlert('Saved, but the image could not be added: ' . escapeHtml($_SESSION['mit_image_error']), 'warning');
        unset($_SESSION['mit_image_error']);
    }
}

/*
 * ---------- products ----------
 */

/** The image field in ITFlow's product form (agent/modals/product/product_form.php; $product = productRow() or null). */
function mitProductImageField($product) {
    if (!mitQuoteColumnsReady()) {
        return '';
    }
    return mitImageField('product_image', $product['product_image'] ?? '');
}

/**
 * Hook after a product is added or edited (agent/post/product.php): its image. Cost and vendor are ITFlow's own fields since
 * 26.11 (product_cost / product_vendor_id, saved by productSave); a product's price method / % (M007) is no longer asked for -
 * a quote line from it starts with the company's default (Admin > Settings > Quotes).
 */
function mitProductSave($product_id, array $post, array $files) {
    global $mysqli;
    $product_id = intval($product_id);
    if (!$product_id || !mitQuoteColumnsReady()) {
        return;
    }
    $set = "product_id = product_id";
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT product_image FROM products WHERE product_id = $product_id"));
    $old_image = (string) ($row['product_image'] ?? '');
    $error = '';
    $new_image = mitImageFromForm($post, $files, 'product_image', $error);
    if ($new_image !== '') {
        $set .= ", product_image = '" . mysqli_real_escape_string($mysqli, $new_image) . "'";
        mitImageDelete($old_image);
    } elseif (!empty($post['product_image_remove'])) {
        $set .= ", product_image = NULL";
        mitImageDelete($old_image);
    }
    mysqli_query($mysqli, "UPDATE products SET $set WHERE product_id = $product_id");
    if ($error !== '') {
        $_SESSION['mit_image_error'] = $error;
    }
}

/** Hook before a product is deleted: its image file goes with it. */
function mitProductDelete($product_id) {
    global $mysqli;
    if (!mitQuoteColumnsReady()) {
        return;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT product_image FROM products WHERE product_id = " . intval($product_id)));
    mitImageDelete($row['product_image'] ?? '');
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

/*
 * ---------- screen pieces (staff) ----------
 */

/**
 * The pricing block for a form (quote line edit, product): our cost, price by, %, presets and a
 * hint; js/mit_quote_pricing.js works the price field out from them. $names: field names for
 * cost / method / percent; $price_name: the form's price field.
 */
function mitPricingFields(array $names, $cost, $method, $percent, $price_name = 'price') {
    $settings = mitQuoteSettings();
    $method = mitPriceMethod($method) ?: ($cost === null || $cost === '' ? 'price' : $settings['method']);
    $percent = $percent === null || $percent === '' ? ($method === 'price' ? '' : $settings['percent']) : $percent;
    $fmt = function ($n) {
        return $n === null || $n === '' ? '' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    };
    global $session_company_currency;
    // Staff-only blocks share one look: Bootstrap's warning-subtle colors, which follow dark mode
    $html = '<div class="border border-warning-subtle bg-warning-subtle rounded p-3 mb-3" data-mit-pricing data-price="' . escapeHtml($price_name) . '" data-mit-currency="' . escapeHtml($session_company_currency ?? 'USD') . '">'
        . '<div class="small fw-bold mb-2 text-warning-emphasis">PRICING - ONLY STAFF SEE THIS</div>'
        . '<div class="row g-2 align-items-end">'
        . '<div class="col-sm-4"><label class="form-label mb-1">Our cost (each)</label><div class="input-group"><span class="input-group-text"><i class="fa fa-fw fa-dollar-sign"></i></span>'
        . '<input type="text" class="form-control text-end" inputmode="decimal" name="' . escapeHtml($names[0]) . '" value="' . escapeHtml($cost === null || $cost === '' ? '' : number_format((float) $cost, 2, '.', '')) . '" placeholder="0.00" data-mit-cost></div></div>'
        . '<div class="col-sm-4"><label class="form-label mb-1">Price by</label><select class="form-select" name="' . escapeHtml($names[1]) . '" data-mit-method>';
    foreach (MIT_PRICE_METHODS as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($value === $method ? ' selected' : '') . '>' . $label . '</option>';
    }
    $html .= '</select></div>'
        . '<div class="col-sm-4" data-mit-pct-wrap' . ($method === 'price' ? ' hidden' : '') . '><label class="form-label mb-1">%</label>'
        . '<input type="text" class="form-control text-end" inputmode="decimal" name="' . escapeHtml($names[2]) . '" value="' . escapeHtml($fmt($percent)) . '" data-mit-pct></div>'
        . '</div><div class="d-flex flex-wrap gap-2 mt-2" data-mit-presets' . ($method === 'price' ? ' hidden' : '') . '>';
    foreach ($settings['presets'] as $preset) {
        $html .= '<button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" data-mit-preset="' . escapeHtml($fmt($preset)) . '" aria-label="Use ' . escapeHtml($fmt($preset)) . '%">' . escapeHtml($fmt($preset)) . '%</button>';
    }
    return $html . '</div><div class="small text-body-secondary mt-2" data-mit-hint></div></div>'
        . '<script src="' . mitAsset('/js/mit_quote_pricing.js') . '"></script>';
}

/** The image field: the current picture, upload, pull from a link, remove. $name = the file field's name. */
function mitImageField($name, $current) {
    $url = mitImageUrl($current);
    return '<div class="mb-3" data-mit-image-box><label class="form-label">Image</label><div class="d-flex gap-3 align-items-start">'
        . '<div style="width:110px;height:110px;flex-shrink:0" class="border rounded bg-body-tertiary d-flex align-items-center justify-content-center overflow-hidden">'
        . '<img alt="" data-mit-image-preview src="' . escapeHtml($url) . '" style="max-width:100%;max-height:100%;' . ($url ? '' : 'display:none') . '">'
        . '<i class="fas fa-image fa-2x text-body-secondary" data-mit-image-empty' . ($url ? ' hidden' : '') . '></i></div>'
        . '<div class="flex-grow-1">'
        . '<input type="file" class="form-control mb-2" name="' . escapeHtml($name) . '" accept="image/jpeg,image/png,image/gif,image/webp" data-mit-image-input>'
        . '<input type="url" class="form-control mb-1" name="' . escapeHtml($name) . '_url" data-mit-image-url placeholder="Or paste a link - an image, or the product\'s web page">'
        . '<div class="form-text">From a product page it takes the page\'s main picture. ITFlow keeps its own copy.</div>'
        . ($url ? '<div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="' . escapeHtml($name) . '_remove" value="1" id="' . escapeHtml($name) . '_remove"><label class="form-check-label" for="' . escapeHtml($name) . '_remove">Remove the image</label></div>' : '')
        . '</div></div></div>';
}

/*
 * The quote page's extra cells (agent/quote.php). Each returns '' until database update M007 has
 * run, all together, so the table keeps the same number of columns in every row.
 */

/** Quote page (agent/quote.php): the Image header cell. */
function mitQuoteHeadImage() {
    return mitQuoteColumnsReady() && mitQuoteSettings()['show_images'] ? '<th style="width:72px">Image</th>' : '';
}

/** Quote page: the staff-only Our cost header cell (cost, and the pricing under it - not printed). */
function mitQuoteHeadPricing() {
    return mitQuoteColumnsReady() ? '<th class="d-print-none bg-warning-subtle text-warning-emphasis">Our cost</th>' : '';
}

function mitQuoteThumb($relative, $size = 56) {
    $url = mitImageUrl($relative);
    return $url ? '<img src="' . escapeHtml($url) . '" alt="" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:contain" class="border rounded bg-white">' : '';
}

/** Quote page: a line's image cell. */
function mitQuoteCellImage($quote_id, $item_id) {
    if (!mitQuoteColumnsReady() || !mitQuoteSettings()['show_images']) {
        return '';
    }
    $extra = mitQuoteItemExtra($quote_id, $item_id);
    return '<td>' . mitQuoteThumb($extra['item_image'] ?? '') . '</td>';
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

/** Quote page: a line's staff-only cell - our cost, with the pricing under it. */
function mitQuoteCellPricing($quote_id, $item_id, $currency_format, $currency_code) {
    if (!mitQuoteColumnsReady()) {
        return '';
    }
    $extra = mitQuoteItemExtra($quote_id, $item_id);
    $cost = $extra['item_cost'] ?? null;
    return '<td class="d-print-none bg-warning-subtle">' . ($cost === null ? '<span class="text-body-secondary">-</span>' : numfmt_format_currency($currency_format, (float) $cost, $currency_code))
        . '<div>' . mitPricingBadge($cost, $extra['item_price_method'] ?? '', $extra['item_price_percent'] ?? '', $extra['item_price'] ?? 0) . '</div></td>';
}

/**
 * Quote page add-item row: the image cell - a box that opens a small menu to upload a picture or
 * paste a link (an image or a product page, fetched when the line is added). A product's image
 * comes along by itself.
 */
function mitQuoteAddCellImage() {
    if (!mitQuoteColumnsReady() || !mitQuoteSettings()['show_images']) {
        return '';
    }
    return '<td><div class="dropdown" data-mit-image-box>'
        . '<button type="button" class="d-block p-0 bg-transparent border border-primary-subtle rounded text-center small text-primary" style="width:56px;height:56px;border-style:dashed!important;overflow:hidden" title="Add an image"'
        . ' data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-popper-config=\'{"strategy":"fixed"}\' aria-expanded="false">'
        . '<img alt="" data-mit-image-preview style="width:100%;height:100%;object-fit:contain;display:none">'
        . '<span data-mit-image-empty style="display:block">+ Image</span></button>'
        . '<div class="dropdown-menu p-3" style="width:320px">'
        . '<label class="btn btn-sm btn-outline-primary w-100 mb-3"><i class="fas fa-fw fa-upload me-1"></i>Upload from this computer'
        . '<input type="file" name="item_image" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none" data-mit-image-input></label>'
        . '<label class="form-label small mb-1">Or paste a link - an image, or the product\'s web page</label>'
        . '<input type="url" class="form-control form-control-sm" name="item_image_url" placeholder="https://" data-mit-image-url>'
        . '<div class="form-text">ITFlow fetches it and keeps its own copy when you add the line.</div>'
        . '</div></div></td>';
}

/** Quote page add-item row: our cost with the pricing under it, wired to the row's price field. */
function mitQuoteAddCellPricing() {
    global $quote_currency_code, $session_company_currency;
    if (!mitQuoteColumnsReady()) {
        return '';
    }
    $settings = mitQuoteSettings();
    $currency = escapeHtml($quote_currency_code ?? $session_company_currency ?? 'USD'); // agent/quote.php's own variable
    $html = '<td class="d-print-none bg-warning-subtle" style="min-width:150px" data-mit-currency="' . $currency . '"><input type="text" class="form-control text-end mb-1" inputmode="decimal" name="item_cost" placeholder="Our cost" data-mit-cost data-mit-price-field="price">'
        . '<div class="input-group flex-nowrap"><select class="form-select" style="padding-left:.5rem;padding-right:1.5rem;background-position:right .35rem center" name="item_price_method" data-mit-method>';
    foreach (MIT_PRICE_METHODS as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($value === $settings['method'] ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select><input type="text" class="form-control text-end" style="max-width:52px" inputmode="decimal" name="item_price_percent" value="' . escapeHtml(rtrim(rtrim(number_format($settings['percent'], 2, '.', ''), '0'), '.')) . '" placeholder="%" title="%" data-mit-pct></div>'
        . '<div class="small text-body-secondary" data-mit-hint data-mit-quiet></div>'
        // The extra columns would squeeze the row's own fields to nothing on a narrower screen - keep
        // them usable (the table scrolls sideways instead)
        . '<style>tr:has([data-mit-cost]) #name{min-width:100px}tr:has([data-mit-cost]) #desc{min-width:100px}'
        . 'tr:has([data-mit-cost]) #qty{min-width:60px}tr:has([data-mit-cost]) #price{min-width:80px}tr:has([data-mit-cost]) #tax{min-width:90px}</style>'
        . '<script>window.mitProductPricing = ' . mitProductsPricingJson() . ';</script><script src="' . mitAsset('/js/mit_quote_pricing.js') . '"></script></td>';
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
    return '<div class="border border-warning-subtle bg-warning-subtle rounded p-3 mt-3 small d-print-none" data-mit-profit-box>'
        . '<div class="small fw-bold mb-2 text-warning-emphasis">PROFIT - ONLY STAFF SEE THIS</div>' . $html
        . ($missing ? '<div class="small text-body-secondary mt-1">' . $missing . ' line' . ($missing === 1 ? ' has' : 's have') . ' no cost entered - counted as all profit.</div>' : '')
        . '</div>';
}

/*
 * ---------- what the customer sees ----------
 */

/** The guest link / portal quote: a line's picture, or ''. */
function mitQuoteCustomerImage($quote_id, $item_id) {
    if (!mitQuoteSettings()['show_images']) {
        return '';
    }
    $extra = mitQuoteItemExtra($quote_id, $item_id);
    $thumb = mitQuoteThumb($extra['item_image'] ?? '', 64);
    return $thumb ? '<span class="me-2">' . $thumb . '</span>' : '';
}

/**
 * ITFlow 26.11's shared document (includes/billing_paper.php - agent, portal and guest pages, and print): a quote line's picture
 * before its name, when quote images are shown. $paper is the page's $paper, $item a billingItems() row.
 */
function mitPaperLineImage(array $paper, array $item) {
    if (($paper['type'] ?? '') !== 'quote' || !mitQuoteColumnsReady()) {
        return '';
    }
    $quote_id = intval($paper['row']['quote_id'] ?? 0);
    $image = mitQuoteCustomerImage($quote_id, intval($item['item_id']));
    return $image !== '' ? '<div class="float-start">' . $image . '</div>' : '';
}

/**
 * Under a line's name on the agent page only (the paper being edited - never the client's copy, never print): a quote line's
 * cost and how it was priced; an invoice line's ordering (procurement). '' otherwise.
 */
function mitPaperLineExtras(array $paper, array $item) {
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
    if (($paper['type'] ?? '') === 'invoice' && function_exists('mitProcInvoiceLineStatus')) {
        return '<div class="d-print-none">' . mitProcInvoiceLineStatus(intval($paper['row']['invoice_id'] ?? 0), $item_id) . '</div>';
    }
    return '';
}

/** The quote PDF (TCPDF): a line's picture above its name, or ''. */
function mitQuotePdfImage($quote_id, $item_id) {
    if (!mitQuoteSettings()['show_images']) {
        return '';
    }
    $extra = mitQuoteItemExtra($quote_id, $item_id);
    $relative = $extra['item_image'] ?? '';
    if (!mitImageIsOurs($relative) || !is_file(mitImagePath($relative))) {
        return '';
    }
    // A web-root path, as ITFlow's own PDFs use - TCPDF puts the document root in front
    return '<img src="/' . htmlspecialchars($relative) . '" height="40"><br>';
}
