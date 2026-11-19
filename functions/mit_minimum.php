<?php
/*
 * Mountaineer IT - minimum commits (Nov 19, 26.11.8.9 - the user's request: a client with a $300 minimum starts on two workstations and two users,
 * so a "Minimum commit" line makes up the rest; as they grow the line shrinks, and once the lines pass $300 it is $0.00).
 *
 * A line of an invoice, quote or recurring invoice can "top the document up to a minimum" (Edit Line window). Its quantity is 1 and its price
 * is the minimum less what the other lines come to before tax and before any discount (the user's choice) - every other line counts, never below
 * 0, and at $0.00 it stays on the document (it comes back by itself if they shrink). On a quote it is the lines billed as often as it is (its
 * group). Worked out just before ITFlow totals the document (from mitDiscountBeforeRecalc, the one line at the start of billingRecalc), so line
 * edits, ITFlow's seat refresh before each recurring run and every invoice made from a recurring invoice follow it; a Paid / Cancelled invoice
 * or an answered quote keeps what it had. A percentage discount leaves minimum lines out (they are not discounted).
 *
 * The minimum is kept per line in mit_billing_minimums (M065) and carried to the lines of what is made from the document - a recurring
 * invoice's invoices, a quote's invoice / recurring invoices, Copy Invoice / Quote, Make Recurring - by mitMinimumCopy (from mitDiscountCopy,
 * whose lines are already at those places, and one line in quoteConvert for lines added to an existing recurring invoice). The line itself is an
 * ordinary line: QuickBooks gets it as one, on its product's category account.
 *
 * Known gap: a quote invoiced with "first period on the invoice" copies a monthly minimum line onto the invoice, where it is measured against
 * every line of the invoice, one-time ones included.
 */

const MIT_MINIMUM_TYPES = ['invoice', 'quote', 'recurring'];

/** Whether M065 has run. */
function mitMinimumReady() {
    static $ready = null;
    if ($ready === null) {
        global $mysqli;
        $ready = (bool) mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_billing_minimums'"));
    }
    return $ready;
}

/** A document's minimum lines: item_id => minimum. */
function mitMinimumLines($type, $doc_id) {
    global $mysqli;
    $lines = [];
    $t = in_array($type, MIT_MINIMUM_TYPES, true) ? billingType($type) : null;
    if (!$t || !mitMinimumReady() || !intval($doc_id)) {
        return $lines;
    }
    $result = mysqli_query($mysqli, "SELECT m.item_id, m.minimum FROM mit_billing_minimums m JOIN {$t['items']} i ON i.item_id = m.item_id
        WHERE m.doc_type = '$type' AND i.{$t['item_fk']} = " . intval($doc_id));
    while ($row = mysqli_fetch_row($result)) {
        $lines[intval($row[0])] = round(floatval($row[1]), 2);
    }
    return $lines;
}

/** One line's minimum, or null. */
function mitMinimumOf($type, $item_id) {
    global $mysqli;
    if (!in_array($type, MIT_MINIMUM_TYPES, true) || !mitMinimumReady() || !intval($item_id)) {
        return null;
    }
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT minimum FROM mit_billing_minimums WHERE doc_type = '$type' AND item_id = " . intval($item_id)));
    return $row ? round(floatval($row[0]), 2) : null;
}

/** Keep (minimum > 0) or drop a line's minimum. */
function mitMinimumSet($type, $item_id, $minimum) {
    global $mysqli, $session_user_id;
    if (!in_array($type, MIT_MINIMUM_TYPES, true) || !mitMinimumReady() || !intval($item_id)) {
        return;
    }
    $item_id = intval($item_id);
    $minimum = round(floatval($minimum), 2);
    if ($minimum <= 0) {
        mysqli_query($mysqli, "DELETE FROM mit_billing_minimums WHERE doc_type = '$type' AND item_id = $item_id");
        return;
    }
    mysqli_query($mysqli, "INSERT INTO mit_billing_minimums SET doc_type = '$type', item_id = $item_id, minimum = " . sprintf('%.2f', $minimum) . ",
        updated_by = " . intval($session_user_id ?? 0) . " ON DUPLICATE KEY UPDATE minimum = VALUES(minimum), updated_by = VALUES(updated_by)");
}

/*
 * What each minimum line tops up now: item_id => [minimum, others, top_up]. "others" = the other lines (not minimum lines) before tax, on a
 * quote only those billed as often as the minimum line.
 */
function mitMinimumFigures($type, $doc_id, ?array $items = null) {
    $lines = mitMinimumLines($type, $doc_id);
    if (!$lines) {
        return [];
    }
    $items = $items ?? billingItems($type, $doc_id);
    $group = function ($item) use ($type) {
        return $type === 'quote' ? quoteFrequency($item['item_frequency'] ?? '') : '';
    };
    $others = [];
    foreach ($items as $item) {
        if (!isset($lines[intval($item['item_id'])])) {
            $others[$group($item)] = ($others[$group($item)] ?? 0.0) + floatval($item['item_subtotal']);
        }
    }
    $figures = [];
    foreach ($items as $item) {
        $item_id = intval($item['item_id']);
        if (isset($lines[$item_id])) {
            $other = round($others[$group($item)] ?? 0.0, 2);
            $figures[$item_id] = ['minimum' => $lines[$item_id], 'others' => $other, 'top_up' => max(0.0, round($lines[$item_id] - $other, 2))];
        }
    }
    return $figures;
}

/*
 * Before ITFlow totals a document (called first thing from mitDiscountBeforeRecalc): each minimum line gets quantity 1 and the price that tops
 * the document up, its tax at its rate - only while its lines can change.
 */
function mitMinimumApply($type, $doc_id) {
    global $mysqli;
    if (!in_array($type, MIT_MINIMUM_TYPES, true) || !mitMinimumReady()) {
        return;
    }
    $t = billingType($type);
    $c = $t['col'];
    $doc_id = intval($doc_id);
    $items = billingItems($type, $doc_id);
    $figures = mitMinimumFigures($type, $doc_id, $items);
    if (!$figures) {
        return;
    }
    $status_column = $type === 'recurring' ? "''" : "{$c}_status";
    $doc = mysqli_fetch_row(mysqli_query($mysqli, "SELECT $status_column FROM {$t['table']} WHERE {$c}_id = $doc_id"));
    if (!$doc || !billingLinesOpen($type, $doc[0])) {
        return;
    }
    foreach ($items as $item) {
        $item_id = intval($item['item_id']);
        if (!isset($figures[$item_id])) {
            continue;
        }
        $price = $figures[$item_id]['top_up'];
        if (round(floatval($item['item_quantity']), 2) == 1.0 && round(floatval($item['item_price']), 2) == $price) {
            continue;
        }
        if (!intval($item['item_tax_id']) || taxRow($item['item_tax_id'])) {
            [$subtotal, $tax, $total] = billingLineTax(1, $price, $item['item_tax_id']);
        } else {
            $percent = floatval($item['item_subtotal']) != 0 ? floatval($item['item_tax']) / floatval($item['item_subtotal']) * 100 : 0;
            [$subtotal, $tax, $total] = billingLineAmounts(1, $price, $percent);
        }
        mysqli_query($mysqli, "UPDATE {$t['items']} SET item_quantity = 1, item_price = " . sprintf('%.2f', $price) . ", item_subtotal = $subtotal, item_tax = $tax, item_total = $total
            WHERE item_id = $item_id");
    }
}

/*
 * Carry the minimums of one document's lines to the lines of a document made from it (after its lines were copied). Lines are matched in
 * order: the first line not yet a minimum with the same product (Copy Quote leaves products off) and the same name or the same place (a recurring invoice's names change -
 * {period}).
 */
function mitMinimumCopy($from_type, $from_id, $to_type, $to_id) {
    $from = mitMinimumLines($from_type, $from_id);
    if (!$from || !intval($to_id) || !in_array($to_type, MIT_MINIMUM_TYPES, true)) {
        return;
    }
    $taken = mitMinimumLines($to_type, $to_id);
    $targets = billingItems($to_type, $to_id);
    foreach (billingItems($from_type, $from_id) as $source) {
        $source_id = intval($source['item_id']);
        if (!isset($from[$source_id])) {
            continue;
        }
        foreach ([true, false] as $by_name) {
            foreach ($targets as $target) {
                $target_id = intval($target['item_id']);
                $products = [intval($target['item_product_id']), intval($source['item_product_id'])];
                if (isset($taken[$target_id]) || ($products[0] && $products[1] && $products[0] !== $products[1])) {
                    continue;
                }
                $same = $by_name ? (string) $target['item_name'] === (string) $source['item_name'] : intval($target['item_order']) === intval($source['item_order']);
                if ($same) {
                    mitMinimumSet($to_type, $target_id, $from[$source_id]);
                    $taken[$target_id] = $from[$source_id];
                    continue 3;
                }
            }
        }
    }
}

/** The Edit Line window's switch (billing_item.php): "Tops this up to a minimum of $___". */
function mitMinimumField($type, $doc_id, $item) {
    if (!mitMinimumReady() || !in_array($type, MIT_MINIMUM_TYPES, true)) {
        return '';
    }
    $item = is_array($item) ? $item : [];
    $item_id = intval($item['item_id'] ?? 0);
    $minimum = $item_id ? mitMinimumOf($type, $item_id) : null;
    $note = '';
    if ($minimum !== null) {
        $figure = mitMinimumFigures($type, $doc_id)[$item_id] ?? null;
        if ($figure) {
            $note = 'The other lines come to ' . number_format($figure['others'], 2) . ' - this line is ' . number_format($figure['top_up'], 2) . ' now.';
        }
    }
    $id = 'mitMinimum' . bin2hex(random_bytes(4));
    ob_start(); ?>
    <div class="mt-3 pt-3 border-top" data-mit-minimum id="<?= $id ?>">
        <input type="hidden" name="mit_minimum_present" value="1">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="mit_minimum_on" value="1" id="<?= $id ?>On"<?= $minimum !== null ? ' checked' : '' ?>>
            <label class="form-check-label" for="<?= $id ?>On">Minimum commit - this line tops the <?= $type === 'quote' ? 'quote' : 'invoice' ?> up to a minimum</label>
        </div>
        <div class="mt-2" data-mit-minimum-amount<?= $minimum === null ? ' hidden' : '' ?>>
            <div class="input-group" style="max-width: 16rem">
                <span class="input-group-text"><i class="fa fa-fw fa-dollar-sign"></i></span>
                <input type="text" class="form-control text-end" inputmode="decimal" name="mit_minimum" placeholder="300.00" value="<?= $minimum !== null ? number_format($minimum, 2, '.', '') : '' ?>">
            </div>
            <div class="form-text">Its price is the minimum less every other line<?= $type === 'quote' ? ' billed as often as it is' : '' ?> (before tax and discount), never below 0.00 - worked out again whenever the lines change<?= $type === 'recurring' ? ', and on every invoice it makes' : '' ?>. Its quantity is 1.</div>
            <?php if ($note !== '') { ?><div class="form-text fw-semibold" data-mit-minimum-now><?= escapeHtml($note) ?></div><?php } ?>
        </div>
    </div>
    <script>(function () {
        var box = document.getElementById(<?= json_encode($id) ?>); if (!box) { return; }
        var form = box.closest('form'), on = box.querySelector('[name=mit_minimum_on]'), amount = box.querySelector('[data-mit-minimum-amount]');
        var qty = form ? form.querySelector('[name=qty]') : null, price = form ? form.querySelector('[name=price]') : null;
        function sync() {
            amount.hidden = !on.checked;
            [qty, price].forEach(function (input) { if (input) { input.readOnly = on.checked; } });
            if (on.checked && qty) { qty.value = '1'; }
        }
        on.addEventListener('change', sync);
        sync();
    })();</script>
    <?php
    return ob_get_clean();
}

/*
 * billing_item_save's hook, after ITFlow saved the line: the switch (posted with mit_minimum_present). Returns a problem, or ''. A recurring line
 * whose quantity is counted from the client can't be a minimum.
 */
function mitMinimumSave($type, $item_id, array $post) {
    if (empty($post['mit_minimum_present']) || !mitMinimumReady() || !intval($item_id) || !in_array($type, MIT_MINIMUM_TYPES, true)) {
        return '';
    }
    $t = billingType($type);
    global $mysqli;
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT {$t['item_fk']} FROM {$t['items']} WHERE item_id = " . intval($item_id)));
    $doc_id = intval($row[0] ?? 0);
    if (!$doc_id) {
        return '';
    }
    if (!empty($post['mit_minimum_on'])) {
        if ($type === 'recurring' && trim((string) ($post['seat_source'] ?? '')) !== '') {
            return 'A line whose quantity is counted from the client can\'t also be a minimum commit - the minimum was not kept.';
        }
        $text = trim(str_replace([',', '$'], '', (string) ($post['mit_minimum'] ?? '')));
        if ($text === '' || !is_numeric($text) || floatval($text) <= 0 || floatval($text) >= 1e9) {
            return 'A minimum commit is an amount above 0.00 - the minimum was not kept.';
        }
        mitMinimumSet($type, $item_id, floatval($text));
    } else {
        mitMinimumSet($type, $item_id, 0);
    }
    billingRecalc($type, $doc_id);
    return '';
}

/** Under a minimum line on the document page (staff): "Minimum 300.00 - other lines 200.00, topped up 100.00" / "met". */
function mitMinimumLineNote(array $paper, array $item) {
    $type = (string) ($paper['type'] ?? '');
    if ((empty($paper['edit']) && empty($paper['mit_staff'])) || !in_array($type, MIT_MINIMUM_TYPES, true)) {
        return '';
    }
    $item_id = intval($item['item_id'] ?? 0);
    $minimum = mitMinimumOf($type, $item_id);
    if ($minimum === null) {
        return '';
    }
    $t = billingType($type);
    $doc_id = intval($paper['row'][$t['col'] . '_id'] ?? 0);
    $figure = mitMinimumFigures($type, $doc_id)[$item_id] ?? ['others' => 0.0, 'top_up' => $minimum];
    $text = 'Minimum ' . number_format($minimum, 2) . ' - other lines ' . number_format($figure['others'], 2)
        . ($figure['top_up'] > 0 ? ', topped up ' . number_format($figure['top_up'], 2) : ', met');
    return '<div class="small d-print-none mt-1" data-mit-minimum-note><span class="badge rounded-pill bg-info-subtle text-info-emphasis fw-normal">'
        . '<i class="fas fa-fw fa-arrow-up me-1"></i>' . escapeHtml($text) . '</span></div>';
}
