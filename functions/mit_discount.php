<?php
/*
 * Mountaineer IT - percentage discounts (Nov 19, 26.11.8.9 - the user's request: a nonprofit discount on managed services, not on licensing,
 * that follows recurring invoices by itself).
 *
 * ITFlow's Discount (Edit Invoice / Quote / Recurring Invoice) is a dollar amount off the whole document. This adds a $ / % switch: as a
 * percentage it is that share of the document's discountable lines - every line but those of a product ticked "Never discounted" (a typed line,
 * ticket time or a ticket part counts as the product of the same name / the part's product) and lines of the discount's own product. The
 * dollars it comes to are written into ITFlow's own *_discount_amount just before ITFlow works the total out (one line at the start of
 * billingRecalc), so every change of lines, a recurring invoice's seat refresh, an invoice made from a recurring invoice or a quote, all follow
 * it; a Paid / Cancelled invoice or an accepted quote (lines closed) keeps what it had. The percentage is kept per document in
 * mit_billing_discounts (M064) and carried to what is made from it: a recurring invoice's invoices, a quote's invoice / new recurring invoice,
 * Copy Invoice, Make Recurring. Optionally a product (the "Nonprofit discount" one): QuickBooks then posts the discount to that product's
 * category account. The totals read "Discount (20%)".
 *
 * Hooks, one line each: billingRecalc (functions/billing.php, `mitDiscountBeforeRecalc`), recurringInvoiceGenerate / quoteConvert (twice) /
 * the Make Recurring function (functions/billing.php, `mitDiscountCopy`), agent/post/invoice.php copy, the Discount field of
 * agent/modals/billing/billing_form.php (`mitDiscountField`), the six add / edit handlers (`mitDiscountSave`), the totals' label in
 * includes/billing_paper.php and functions/billing_pdf.php (`mitDiscountLabel`), agent/modals/product/product_form.php ("Never discounted",
 * `mitDiscountProductField`; saved from our mitProductSave). QuickBooks: `mitQboDiscountLine` asks `mitDiscountQboAccount`.
 */

const MIT_DISCOUNT_TYPES = ['invoice', 'quote', 'recurring'];

/** Whether M064 has run. */
function mitDiscountReady() {
    static $ready = null;
    if ($ready === null) {
        global $mysqli;
        $ready = (bool) mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_product_no_discount'"));
    }
    return $ready;
}

/** A document's percentage discount - [percent, product_id] - or null (a dollar amount, ITFlow's own). */
function mitDiscountRule($type, $doc_id) {
    global $mysqli;
    if (!in_array($type, MIT_DISCOUNT_TYPES, true) || !mitDiscountReady()) {
        return null;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT percent, product_id FROM mit_billing_discounts WHERE doc_type = '$type' AND doc_id = " . intval($doc_id)));
    return $row && floatval($row['percent']) > 0 ? ['percent' => round(floatval($row['percent']), 3), 'product_id' => intval($row['product_id'])] : null;
}

/** Products ticked "Never discounted": id => true. */
function mitDiscountExcludedProducts() {
    global $mysqli;
    $ids = [];
    if (mitDiscountReady()) {
        $result = mysqli_query($mysqli, "SELECT product_id FROM mit_product_no_discount");
        while ($row = mysqli_fetch_row($result)) {
            $ids[intval($row[0])] = true;
        }
    }
    return $ids;
}

/** Whether a product is ticked "Never discounted". */
function mitDiscountProductExcluded($product_id) {
    return isset(mitDiscountExcludedProducts()[intval($product_id)]);
}

/** The product a line counts as: its own, a ticket part's (lines billed from parts carry none), else the one unarchived product of its name. */
function mitDiscountLineProduct($type, array $item, array $by_name, array $part_products) {
    $product_id = intval($item['item_product_id'] ?? 0);
    if ($product_id) {
        return $product_id;
    }
    if ($type === 'invoice' && isset($part_products[intval($item['item_id'])])) {
        return $part_products[intval($item['item_id'])];
    }
    $name = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) ($item['item_name'] ?? ''))));
    return $by_name[$name] ?? 0;
}

/*
 * What a document's discountable lines come to before tax, and how many lines were left out - [base, left_out]. Lines of a "Never discounted"
 * product, and of the discount's own product (a "Nonprofit discount" line on it), are left out.
 */
function mitDiscountBase($type, $doc_id, $discount_product_id = 0) {
    global $mysqli;
    $excluded = mitDiscountExcludedProducts();
    if ($discount_product_id) {
        $excluded[intval($discount_product_id)] = true;
    }
    $by_name = [];
    $result = mysqli_query($mysqli, "SELECT product_id, product_name FROM products WHERE product_archived_at IS NULL");
    $seen = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $name = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $row['product_name'])));
        $seen[$name] = ($seen[$name] ?? 0) + 1;
        $by_name[$name] = intval($row['product_id']);
    }
    foreach ($seen as $name => $count) {
        if ($count > 1) {
            unset($by_name[$name]); // two products of that name - neither is guessed
        }
    }
    $part_products = [];
    if ($type === 'invoice' && mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_parts'"))) {
        $result = mysqli_query($mysqli, "SELECT invoice_item_id, product_id FROM mit_ticket_parts WHERE invoice_id = " . intval($doc_id) . " AND invoice_item_id > 0 AND product_id > 0");
        while ($row = mysqli_fetch_row($result)) {
            $part_products[intval($row[0])] = intval($row[1]);
        }
    }
    $base = 0.0;
    $left_out = 0;
    $minimums = function_exists('mitMinimumLines') ? mitMinimumLines($type, $doc_id) : [];
    foreach (billingItems($type, $doc_id) as $item) {
        if (isset($minimums[intval($item['item_id'])])) {
            continue; // a minimum commit line is not discounted
        }
        $product_id = mitDiscountLineProduct($type, $item, $by_name, $part_products);
        if ($product_id && isset($excluded[$product_id])) {
            $left_out++;
            continue;
        }
        $base += floatval($item['item_subtotal']);
    }
    return [round($base, 2), $left_out];
}

/** The dollars a percentage of a document's discountable lines comes to (never below 0). */
function mitDiscountAmount($type, $doc_id, array $rule) {
    [$base] = mitDiscountBase($type, $doc_id, $rule['product_id']);
    return max(0.0, round($base * $rule['percent'] / 100, 2));
}

/*
 * billingRecalc's hook (its first line): a document with a percentage discount gets the dollars it comes to now in ITFlow's own
 * *_discount_amount, before ITFlow reads it - only while its lines can change (billingLinesOpen), so a paid invoice keeps its discount.
 */
function mitDiscountBeforeRecalc($type, $doc_id) {
    global $mysqli;
    if (function_exists('mitMinimumApply')) {
        mitMinimumApply($type, $doc_id); // a minimum commit line's price first - it is measured before the discount (functions/mit_minimum.php)
    }
    $rule = mitDiscountRule($type, $doc_id);
    if (!$rule) {
        return;
    }
    $t = billingType($type);
    $c = $t['col'];
    $status_column = $type === 'recurring' ? "''" : "{$c}_status";
    $doc = mysqli_fetch_row(mysqli_query($mysqli, "SELECT $status_column FROM {$t['table']} WHERE {$c}_id = " . intval($doc_id)));
    if (!$doc || !billingLinesOpen($type, $doc[0])) {
        return;
    }
    $amount = mitDiscountAmount($type, $doc_id, $rule);
    mysqli_query($mysqli, "UPDATE {$t['table']} SET {$c}_discount_amount = " . sprintf('%.2f', $amount) . " WHERE {$c}_id = " . intval($doc_id));
}

/** Keep (percent > 0) or drop a document's percentage. */
function mitDiscountSet($type, $doc_id, $percent, $product_id = 0) {
    global $mysqli, $session_user_id;
    if (!in_array($type, MIT_DISCOUNT_TYPES, true) || !mitDiscountReady() || !intval($doc_id)) {
        return;
    }
    $doc_id = intval($doc_id);
    $percent = round(floatval($percent), 3);
    if ($percent <= 0) {
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = '$type' AND doc_id = $doc_id");
        return;
    }
    $percent = min(100.0, $percent);
    mysqli_query($mysqli, "INSERT INTO mit_billing_discounts SET doc_type = '$type', doc_id = $doc_id, percent = $percent, product_id = " . intval($product_id) . ",
        updated_by = " . intval($session_user_id ?? 0) . " ON DUPLICATE KEY UPDATE percent = VALUES(percent), product_id = VALUES(product_id), updated_by = VALUES(updated_by)");
}

/** A product id that may be a discount's product - an unarchived product, else 0. */
function mitDiscountProductId($product_id) {
    global $mysqli;
    $product_id = intval($product_id);
    return $product_id && mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM products WHERE product_id = $product_id AND product_archived_at IS NULL")) ? $product_id : 0;
}

/*
 * The add / edit handlers' hook, after ITFlow saved the document: the window's $ / % choice (posted with mit_discount_present). Percent keeps
 * the rule and works the dollars out again; $ drops it (ITFlow's amount, as typed, stays). Returns a problem, or ''.
 */
function mitDiscountSave($type, $doc_id, array $post) {
    if (empty($post['mit_discount_present']) || !mitDiscountReady() || !intval($doc_id)) {
        return '';
    }
    $mode = (string) ($post['mit_discount_mode'] ?? 'amount');
    if ($mode === 'percent') {
        $text = trim(str_replace('%', '', (string) ($post['mit_discount_percent'] ?? '')));
        if ($text === '' || !is_numeric($text) || floatval($text) < 0 || floatval($text) > 100) {
            return 'A percentage discount is a number from 0 to 100.';
        }
        mitDiscountSet($type, $doc_id, floatval($text), mitDiscountProductId($post['mit_discount_product'] ?? 0));
    } else {
        mitDiscountSet($type, $doc_id, 0);
    }
    billingRecalc($type, $doc_id);
    return '';
}

/** Carry a percentage to a document made from another (recurring -> invoice, quote -> invoice / recurring, copies) - before its billingRecalc. */
function mitDiscountCopy($from_type, $from_id, $to_type, $to_id) {
    if (function_exists('mitMinimumCopy')) {
        mitMinimumCopy($from_type, $from_id, $to_type, $to_id); // and its minimum commit lines (functions/mit_minimum.php)
    }
    $rule = mitDiscountRule($from_type, $from_id);
    if ($rule && intval($to_id)) {
        mitDiscountSet($to_type, $to_id, $rule['percent'], $rule['product_id']);
    }
}

/** The totals' "Discount" label - "Discount (20%)" for a percentage. */
function mitDiscountLabel($type, $doc_id) {
    $rule = mitDiscountRule($type, $doc_id);
    if (!$rule) {
        return 'Discount';
    }
    return 'Discount (' . rtrim(rtrim(number_format($rule['percent'], 3, '.', ''), '0'), '.') . '%)';
}

/*
 * The Edit window's $ / % switch, beside ITFlow's Discount box (billing_form.php). % hides ITFlow's dollar box (posted empty - the server
 * works the dollars out) and shows the percentage, the product it posts as, and what it comes to now.
 */
function mitDiscountField($type, $doc_id) {
    global $mysqli;
    if (!mitDiscountReady() || !in_array($type, MIT_DISCOUNT_TYPES, true)) {
        return '';
    }
    $rule = $doc_id ? mitDiscountRule($type, $doc_id) : null;
    $percent = $rule ? rtrim(rtrim(number_format($rule['percent'], 3, '.', ''), '0'), '.') : '';
    $product_id = $rule['product_id'] ?? 0;
    $note = '';
    if ($rule) {
        [$base, $left_out] = mitDiscountBase($type, $doc_id, $product_id);
        $note = 'Comes to ' . number_format(round($base * $rule['percent'] / 100, 2), 2) . ' now - ' . $percent . '% of ' . number_format($base, 2)
            . ($left_out ? " ($left_out line" . ($left_out === 1 ? '' : 's') . ' never discounted left out)' : '');
    }
    $options = '<option value="0">- Just a discount -</option>';
    $result = mysqli_query($mysqli, "SELECT product_id, product_name FROM products WHERE product_archived_at IS NULL OR product_id = " . intval($product_id) . " ORDER BY product_price < 0 DESC, product_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $options .= '<option value="' . intval($row['product_id']) . '"' . (intval($row['product_id']) === intval($product_id) ? ' selected' : '') . '>' . escapeHtml($row['product_name']) . '</option>';
    }
    $id = 'mitDiscount' . bin2hex(random_bytes(4));
    $html = '<div class="mt-2" data-mit-discount id="' . $id . '">'
        . '<input type="hidden" name="mit_discount_present" value="1">'
        . '<div class="btn-group btn-group-sm" role="group" aria-label="Discount as">'
        . '<input type="radio" class="btn-check" name="mit_discount_mode" value="amount" id="' . $id . 'A"' . ($rule ? '' : ' checked') . '>'
        . '<label class="btn btn-outline-secondary" for="' . $id . 'A">$ amount</label>'
        . '<input type="radio" class="btn-check" name="mit_discount_mode" value="percent" id="' . $id . 'P"' . ($rule ? ' checked' : '') . '>'
        . '<label class="btn btn-outline-secondary" for="' . $id . 'P">% of lines</label></div>'
        . '<div class="mt-2" data-mit-discount-percent' . ($rule ? '' : ' hidden') . '>'
        . '<div class="input-group"><span class="input-group-text"><i class="fa fa-fw fa-percent"></i></span>'
        . '<input type="text" class="form-control text-end" inputmode="decimal" name="mit_discount_percent" placeholder="20" value="' . escapeHtml($percent) . '">'
        . '<span class="input-group-text">%</span></div>'
        . '<div class="form-text">Of every line but products ticked "Never discounted" - worked out again whenever the lines change'
        . ($type === 'recurring' ? ', and on every invoice it makes' : '') . '.</div>'
        . ($note !== '' ? '<div class="form-text fw-semibold" data-mit-discount-now>' . escapeHtml($note) . '</div>' : '')
        . '<label class="form-label mt-2 mb-1 small">Discount product <span class="text-body-secondary">(QuickBooks posts it to this product\'s category account)</span></label>'
        . '<select class="form-select form-select-sm" name="mit_discount_product">' . $options . '</select></div></div>';
    $html .= '<script>(function () {'
        . 'var box = document.getElementById(' . json_encode($id) . '); if (!box) { return; }'
        . 'var col = box.parentElement, amount = col.querySelector(".input-group input[name$=\'_discount\']"), group = amount ? amount.closest(".input-group") : null;'
        . 'var hint = group ? group.nextElementSibling : null, pct = box.querySelector("[data-mit-discount-percent]");'
        . 'function sync() { var on = box.querySelector("[name=mit_discount_mode][value=percent]").checked;'
        . ' pct.hidden = !on; if (group) { group.hidden = on; } if (hint && hint.classList.contains("form-text")) { hint.hidden = on; }'
        . ' if (amount) { if (on) { if (amount.dataset.mitWas === undefined) { amount.dataset.mitWas = amount.value; } amount.value = ""; }'
        . ' else if (amount.dataset.mitWas !== undefined) { amount.value = amount.dataset.mitWas; delete amount.dataset.mitWas; } } }'
        . 'box.querySelectorAll("[name=mit_discount_mode]").forEach(function (r) { r.addEventListener("change", sync); });'
        . 'sync();'
        . '})();</script>';
    return $html;
}

/** "Never discounted" on the product window (product_form.php). */
function mitDiscountProductField($product_id) {
    if (!mitDiscountReady()) {
        return '';
    }
    $on = $product_id && mitDiscountProductExcluded($product_id);
    $id = 'mitNoDiscount' . bin2hex(random_bytes(4));
    return '<input type="hidden" name="mit_no_discount_present" value="1">'
        . '<div class="form-check form-switch" data-mit-no-discount>'
        . '<input class="form-check-input" type="checkbox" role="switch" name="mit_no_discount" value="1" id="' . $id . '"' . ($on ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="' . $id . '">Never discounted - a percentage discount (a nonprofit\'s, say) leaves this product\'s lines out</label></div>';
}

/** Save "Never discounted" (from mitProductSave, on both product saves). */
function mitDiscountProductSave($product_id, array $post) {
    global $mysqli;
    $product_id = intval($product_id);
    if (!$product_id || empty($post['mit_no_discount_present']) || !mitDiscountReady()) {
        return;
    }
    if (!empty($post['mit_no_discount'])) {
        mysqli_query($mysqli, "INSERT IGNORE INTO mit_product_no_discount SET product_id = $product_id");
    } else {
        mysqli_query($mysqli, "DELETE FROM mit_product_no_discount WHERE product_id = $product_id");
    }
}

/** QuickBooks: the account an invoice's discount posts to when its percentage names a product - that product's category account, else ''. */
function mitDiscountQboAccount($invoice_id) {
    global $mysqli;
    $rule = mitDiscountRule('invoice', $invoice_id);
    if (!$rule || !$rule['product_id'] || !function_exists('mitQboCategoryAccounts')) {
        return '';
    }
    $category = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT product_category_id FROM products WHERE product_id = " . intval($rule['product_id'])))[0] ?? 0);
    return (string) (mitQboCategoryAccounts()[$category]['qbo_account_id'] ?? '');
}
