<?php

/*
 * Mountaineer IT - QuickBooks Online income accounts by category (M051; required from functions/mit_qbo.php).
 *
 * Every invoice line goes to QuickBooks with an item, and the item decides the income account (GL). Each line finds its ITFlow
 * Income category (mitQboLineCategory):
 *   a product's line - the product's category;
 *   a ticket part billed from a ticket / To Bill (no product on the line) - the part's product's category;
 *   a line with a category of its own - picked in Edit Line (mitQboLineCategoryField), or ticket time (mitQboTagTimeLines -
 *     mit_settings qbo_time_category);
 *   else the invoice's category.
 * The category's QuickBooks income account comes from mit_qbo_category_accounts, else mit_settings qbo_default_account.
 *
 * The line's item (mitQboLineItem): its product's mapped item; an unmapped product links the QuickBooks item with its name or
 * gets one made (Non-inventory for a physical product, else Service - never Inventory: ITFlow keeps the stock) with its
 * category's account; a line without a product takes the QuickBooks item with its name, else its category's own item
 * ("<category> (ITFlow)", made once, kept in mit_qbo_category_accounts), else the fallback item, else "Sales (ITFlow)" with the
 * default account (mit_settings qbo_default_item). Items are made only when mit_settings qbo_create_items is on (default), when
 * the batch is sent - the batch page lists them first (mitQboBatchItemPlan). Items already mapped keep their own account.
 */

const MIT_QBO_ITEM_SUFFIX = ' (ITFlow)';

/** Has M051 run? (one query per request) */
function mitQboAccountsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_invoice_item_categories'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The settings of this part, with defaults. */
function mitQboAccountSettings() {
    return [
        'default_account' => (string) mitSetting('qbo_default_account', ''),
        'default_account_name' => (string) mitSetting('qbo_default_account_name', ''),
        'default_item' => (string) mitSetting('qbo_default_item', ''),
        'time_category' => intval(mitSetting('qbo_time_category', 0)),
        'create_items' => mitSetting('qbo_create_items', '1') === '1',
    ];
}

/** ITFlow's Income categories (the ones products and invoices use): id => name. Archived ones too when $all. */
function mitQboIncomeCategories($all = false) {
    global $mysqli;
    static $cache = [];
    $key = $all ? 'all' : 'active';
    if (!isset($cache[$key])) {
        $cache[$key] = [];
        $result = mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Income'"
            . ($all ? '' : ' AND category_archived_at IS NULL') . " ORDER BY category_name");
        while ($row = mysqli_fetch_assoc($result)) {
            $cache[$key][intval($row['category_id'])] = (string) $row['category_name'];
        }
    }
    return $cache[$key];
}

/** category id => [account id, account name, item id, item name]. */
function mitQboCategoryAccounts($fresh = false) {
    global $mysqli;
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        if (mitQboAccountsReady()) {
            $result = mysqli_query($mysqli, "SELECT * FROM mit_qbo_category_accounts");
            while ($row = mysqli_fetch_assoc($result)) {
                $cache[intval($row['category_id'])] = $row;
            }
        }
    }
    return $cache;
}

/**
 * Map a category to a QuickBooks income account ('' clears it). Its own item, when made already, follows: its account is changed
 * in QuickBooks too (it is ours - made for that category). Returns '' or a warning.
 */
function mitQboSetCategoryAccount($category_id, $account_id, $account_name = '') {
    global $mysqli, $session_user_id;
    $category_id = intval($category_id);
    $account_id = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $account_id);
    $current = mitQboCategoryAccounts(true)[$category_id] ?? null;
    if (($current['qbo_account_id'] ?? '') === $account_id) {
        return '';
    }
    $by = intval($session_user_id ?? 0);
    $name = escapeSql(mb_substr((string) $account_name, 0, 255));
    if ($account_id === '' && (!$current || $current['qbo_item_id'] === '')) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_category_accounts WHERE category_id = $category_id");
    } else {
        mysqli_query($mysqli, "INSERT INTO mit_qbo_category_accounts SET category_id = $category_id, qbo_account_id = '$account_id', qbo_account_name = '$name',
            updated_by = $by, updated_at = NOW()
            ON DUPLICATE KEY UPDATE qbo_account_id = '$account_id', qbo_account_name = '$name', updated_by = $by, updated_at = NOW()");
    }
    mitQboCategoryAccounts(true);
    $warning = '';
    if ($current && $current['qbo_item_id'] !== '' && $account_id !== '') {
        try {
            mitQboItemSetAccount($current['qbo_item_id'], $account_id);
        } catch (Throwable $e) {
            $warning = 'The account is saved, but QuickBooks item ' . $current['qbo_item_name'] . ' kept its old one: ' . $e->getMessage();
        }
    }
    return $warning;
}

/** The QuickBooks income account for a category: [id, name], else the default account, else null. */
function mitQboAccountFor($category_id) {
    $mapped = mitQboCategoryAccounts()[intval($category_id)] ?? null;
    if ($mapped && $mapped['qbo_account_id'] !== '') {
        return [$mapped['qbo_account_id'], $mapped['qbo_account_name']];
    }
    $s = mitQboAccountSettings();
    return $s['default_account'] !== '' ? [$s['default_account'], $s['default_account_name']] : null;
}

/*
 * ---------- a line's category ----------
 */

/** The category recorded for invoice lines without a product: item id => category id (only the ids asked for). */
function mitQboLineCategoryRows(array $item_ids) {
    global $mysqli;
    $ids = array_filter(array_map('intval', $item_ids));
    if (!$ids || !mitQboAccountsReady()) {
        return [];
    }
    $rows = [];
    $result = mysqli_query($mysqli, "SELECT item_id, category_id FROM mit_invoice_item_categories WHERE item_id IN (" . implode(',', $ids) . ")");
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[intval($row['item_id'])] = intval($row['category_id']);
    }
    return $rows;
}

/**
 * Each line's Income category and where it came from: item id => [category id, how] - how = product | part | line | invoice | ''.
 * $lines are invoice_items rows of one invoice.
 */
function mitQboLineCategories(array $invoice, array $lines) {
    global $mysqli;
    $income = mitQboIncomeCategories(true);
    $own = mitQboLineCategoryRows(array_column($lines, 'item_id'));
    $product_ids = array_filter(array_map('intval', array_column($lines, 'item_product_id')));
    $product_cats = [];
    if ($product_ids) {
        $result = mysqli_query($mysqli, "SELECT product_id, product_category_id FROM products WHERE product_id IN (" . implode(',', array_unique($product_ids)) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $product_cats[intval($row['product_id'])] = intval($row['product_category_id']);
        }
    }
    // Ticket parts lose the product on the invoice line (the stock was taken when the part was used) - their part row keeps it
    $part_cats = [];
    $item_ids = array_filter(array_map('intval', array_column($lines, 'item_id')));
    if ($item_ids && mitQboPartsTable()) {
        $result = mysqli_query($mysqli, "SELECT tp.invoice_item_id, p.product_category_id FROM mit_ticket_parts tp JOIN products p ON p.product_id = tp.product_id
            WHERE tp.invoice_item_id IN (" . implode(',', $item_ids) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $part_cats[intval($row['invoice_item_id'])] = intval($row['product_category_id']);
        }
    }
    $invoice_cat = intval($invoice['invoice_category_id'] ?? 0);
    $out = [];
    foreach ($lines as $line) {
        $item_id = intval($line['item_id']);
        $product_id = intval($line['item_product_id']);
        $pick = [0, ''];
        foreach ([
            [$product_id ? ($product_cats[$product_id] ?? 0) : 0, 'product'],
            [$part_cats[$item_id] ?? 0, 'part'],
            [$own[$item_id] ?? 0, 'line'],
            [$invoice_cat, 'invoice'],
        ] as [$category_id, $how]) {
            if ($category_id && isset($income[$category_id])) {
                $pick = [$category_id, $how];
                break;
            }
        }
        $out[$item_id] = $pick;
    }
    return $out;
}

/** Is procurement's mit_ticket_parts there? (one query per request) */
function mitQboPartsTable() {
    global $mysqli;
    static $there = null;
    if ($there === null) {
        $there = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_ticket_parts'")) === 1;
    }
    return $there;
}

/** Record (or clear, with 0) the category of a line without a product. */
function mitQboLineCategorySet($item_id, $category_id) {
    global $mysqli, $session_user_id;
    $item_id = intval($item_id);
    $category_id = intval($category_id);
    if (!$item_id || !mitQboAccountsReady()) {
        return;
    }
    if (!$category_id || !isset(mitQboIncomeCategories(true)[$category_id])) {
        mysqli_query($mysqli, "DELETE FROM mit_invoice_item_categories WHERE item_id = $item_id");
        return;
    }
    $by = intval($session_user_id ?? 0);
    mysqli_query($mysqli, "INSERT INTO mit_invoice_item_categories SET item_id = $item_id, category_id = $category_id, set_by = $by, set_at = NOW()
        ON DUPLICATE KEY UPDATE category_id = $category_id, set_by = $by, set_at = NOW()");
}

/**
 * Ticket time just billed (ITFlow's billingTimeInvoice - one line after its billingRecalc): its lines (no product, named the time
 * item) take the "Ticket time" category of the QuickBooks settings when they have none yet.
 */
function mitQboTagTimeLines($invoice_id, $item_name) {
    global $mysqli;
    $category_id = mitQboAccountSettings()['time_category'];
    $invoice_id = intval($invoice_id);
    if (!$category_id || !$invoice_id || !mitQboAccountsReady() || !isset(mitQboIncomeCategories()[$category_id])) {
        return;
    }
    $name = escapeSql((string) $item_name);
    mysqli_query($mysqli, "INSERT IGNORE INTO mit_invoice_item_categories (item_id, category_id, set_by, set_at)
        SELECT item_id, $category_id, 0, NOW() FROM invoice_items WHERE item_invoice_id = $invoice_id AND item_product_id = 0 AND item_name = '$name'"
        . (mitQboPartsTable() ? " AND item_id NOT IN (SELECT invoice_item_id FROM mit_ticket_parts WHERE invoice_item_id IS NOT NULL)" : ''));
}

/** Show the category picker on invoice lines? (QuickBooks set up - a client ID saved - and M051 run) */
function mitQboLineCategoriesOn() {
    return mitQboAccountsReady() && function_exists('mitQboSettings') && mitQboSettings()['client_id'] !== '';
}

/**
 * The Category picker in ITFlow's Edit Line window (agent/modals/billing/billing_item.php, one line) - invoices only; for a line
 * with a product it says the product's category is used. Posts mit_line_category (with mit_line_category_present).
 */
function mitQboLineCategoryField($type, $doc_id, $item) {
    global $mysqli;
    if ($type !== 'invoice' || !mitQboLineCategoriesOn()) {
        return '';
    }
    $item = is_array($item) ? $item : [];
    $item_id = intval($item['item_id'] ?? 0);
    $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_id, invoice_category_id FROM invoices WHERE invoice_id = " . intval($doc_id))) ?: [];
    $income = mitQboIncomeCategories();
    $current = $item_id ? (mitQboLineCategoryRows([$item_id])[$item_id] ?? 0) : 0;
    $invoice_cat = intval($invoice['invoice_category_id'] ?? 0);
    $default = isset($income[$invoice_cat]) ? 'the invoice\'s: ' . $income[$invoice_cat] : 'none';
    $product_note = intval($item['item_product_id'] ?? 0) ? '<div class="form-text">Lines with a product use the product\'s category - this is only for lines without one.</div>' : '';
    ob_start(); ?>
    <div class="mb-3" data-mit-line-category>
        <input type="hidden" name="mit_line_category_present" value="1">
        <label class="form-label">Category <span class="text-body-secondary small">(QuickBooks income account)</span></label>
        <select class="form-select" name="mit_line_category">
            <option value="0">- <?= escapeHtml($default) ?> -</option>
            <?php foreach ($income as $id => $name) { ?>
                <option value="<?= $id ?>" <?= $current === $id ? 'selected' : '' ?>><?= escapeHtml($name) ?></option>
            <?php } ?>
        </select>
        <?= $product_note ?>
    </div>
    <?php return ob_get_clean();
}

/** Save the picker (agent/post/billing.php billing_item_save, one line after the line is saved). */
function mitQboLineCategorySave($type, $item_id, array $post) {
    if ($type !== 'invoice' || empty($post['mit_line_category_present']) || !mitQboAccountsReady()) {
        return;
    }
    mitQboLineCategorySet($item_id, intval($post['mit_line_category'] ?? 0));
}

/** A small "Hardware Sales" tag under an invoice line on the staff page (from mitPaperLineExtras). */
function mitQboLineCategoryTag(array $invoice, array $item) {
    if (!mitQboLineCategoriesOn()) {
        return '';
    }
    [$category_id, $how] = mitQboLineCategories($invoice, [$item])[intval($item['item_id'])] ?? [0, ''];
    $income = mitQboIncomeCategories(true);
    $from = ['product' => 'from its product', 'part' => 'from its part', 'line' => 'picked for the line', 'invoice' => 'the invoice\'s'][$how] ?? '';
    $label = $category_id ? $income[$category_id] : 'No category';
    return '<span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis fw-normal d-print-none" data-mit-line-category-tag="' . intval($category_id)
        . '" title="QuickBooks category' . ($from !== '' ? ' - ' . escapeHtml($from) : '') . '"><i class="fas fa-book me-1"></i>' . escapeHtml($label) . '</span>';
}

/*
 * ---------- QuickBooks items ----------
 */

/** A QuickBooks item name: no colon (it separates sub-items), 100 characters at most. */
function mitQboItemName($name) {
    $name = trim(preg_replace('/\s+/', ' ', str_replace(':', '-', (string) $name)));
    return mb_substr($name !== '' ? $name : 'Item', 0, 100);
}

/** The QuickBooks item with this exact name, active or not (QuickBooks keeps inactive names taken), or null. */
function mitQboFindItem($name) {
    $rows = mitQboQueryAll('Item', 'Name = ' . mitQboQuote($name) . ' AND Active IN (true, false)');
    foreach ($rows as $row) {
        if (mb_strtolower((string) ($row['Name'] ?? '')) === mb_strtolower($name)) {
            return $row;
        }
    }
    return null;
}

/**
 * Make (or take) a QuickBooks item: the one already there with that name - made active again when it was not -, else a new
 * $type ('Service' | 'NonInventory') item on $account_id. Returns [id, name, made].
 */
function mitQboMakeItem($name, $type, $account_id, $price = null, $description = '') {
    $name = mitQboItemName($name);
    $found = mitQboFindItem($name);
    if ($found) {
        if (($found['Active'] ?? true) === false) {
            mitQboApi('POST', '/item', ['Id' => (string) $found['Id'], 'SyncToken' => (string) ($found['SyncToken'] ?? '0'), 'sparse' => true, 'Active' => true]);
        }
        return [(string) $found['Id'], (string) ($found['FullyQualifiedName'] ?? $found['Name'] ?? $name), false];
    }
    $body = ['Name' => $name, 'Type' => $type === 'NonInventory' ? 'NonInventory' : 'Service', 'IncomeAccountRef' => ['value' => (string) $account_id]];
    if ($price !== null) {
        $body['UnitPrice'] = round(floatval($price), 2);
    }
    if (trim((string) $description) !== '') {
        $body['Description'] = mb_substr(trim(strip_tags((string) $description)), 0, 4000);
    }
    $answer = mitQboApi('POST', '/item', $body);
    $item = $answer['Item'] ?? [];
    if ((string) ($item['Id'] ?? '') === '') {
        throw new RuntimeException('QuickBooks did not return the new item.');
    }
    return [(string) $item['Id'], (string) ($item['FullyQualifiedName'] ?? $item['Name'] ?? $name), true];
}

/** Point one of our QuickBooks items at another income account. */
function mitQboItemSetAccount($item_id, $account_id) {
    $item = mitQboApi('GET', '/item/' . rawurlencode((string) $item_id))['Item'] ?? null;
    if (!$item) {
        throw new RuntimeException('QuickBooks no longer has that item.');
    }
    mitQboApi('POST', '/item', ['Id' => (string) $item['Id'], 'SyncToken' => (string) ($item['SyncToken'] ?? '0'), 'sparse' => true,
        'IncomeAccountRef' => ['value' => (string) $account_id]]);
}

/**
 * The QuickBooks item for one invoice line: [item id, problem]. $ctx: items_by_name (lower name => id), item_map (mitQboMap('items')),
 * products (product id => row), settings, create (make missing items) and plan (filled with the items that would be / were made:
 * key => [name, type, account, for]). With create off a missing item is only planned (the batch page's list).
 */
function mitQboLineItem(array $line, $category_id, array &$ctx) {
    $s = $ctx['settings'];
    $a = $ctx['accounts'];
    $income = mitQboIncomeCategories(true);
    $product_id = intval($line['item_product_id']);
    $line_name = mb_strtolower(trim((string) $line['item_name']));
    $make = function ($key, $name, $type, $account, $for, callable $store) use (&$ctx) {
        if (isset($ctx['made'][$key])) {
            return [$ctx['made'][$key], ''];
        }
        $ctx['plan'][$key] = ['name' => mitQboItemName($name), 'type' => $type, 'account' => $account[1] !== '' ? $account[1] : $account[0], 'for' => $for];
        if (!$ctx['create']) {
            return ['planned', ''];
        }
        try {
            [$id, $qbo_name] = mitQboMakeItem($name, $type, $account[0], $ctx['price'] ?? null);
        } catch (Throwable $e) {
            return ['', 'Could not make the QuickBooks item "' . mitQboItemName($name) . '": ' . $e->getMessage()];
        }
        $store($id, $qbo_name);
        $ctx['made'][$key] = $id;
        $ctx['active_ids'][$id] = true;
        return [$id, ''];
    };

    // A product: its mapped item; else the item with its name (linked); else one made for it
    if ($product_id) {
        if (isset($ctx['item_map'][$product_id])) {
            return [$ctx['item_map'][$product_id]['qbo_id'], ''];
        }
        $product = $ctx['products'][$product_id] ?? null;
        $product_name = $product ? (string) $product['product_name'] : (string) $line['item_name'];
        $by_name = $ctx['items_by_name'][mb_strtolower(mitQboItemName($product_name))] ?? ($ctx['items_by_name'][mb_strtolower(trim($product_name))] ?? '');
        if ($by_name !== '') {
            if ($ctx['create']) {
                mitQboSetMap('items', $product_id, $by_name, $product_name);
                $ctx['item_map'][$product_id] = ['qbo_id' => $by_name, 'qbo_name' => $product_name];
            }
            return [$by_name, ''];
        }
        if ($a['create_items']) {
            $account = mitQboAccountFor($category_id);
            if (!$account) {
                return ['', 'Product "' . $product_name . '" has no QuickBooks item, and ' . ($category_id ? 'its category ' . $income[$category_id] . ' has no income account' : 'it has no category')
                    . ' and no default income account is set - map one under Income accounts.'];
            }
            $type = $product && ($product['product_type'] ?? '') === 'product' ? 'NonInventory' : 'Service';
            $ctx['price'] = $product ? floatval($product['product_price']) : null;
            $result = $make("product:$product_id", $product_name, $type, $account, 'Product ' . $product_name,
                function ($id, $qbo_name) use ($product_id, &$ctx) {
                    mitQboSetMap('items', $product_id, $id, $qbo_name);
                    $ctx['item_map'][$product_id] = ['qbo_id' => $id, 'qbo_name' => $qbo_name];
                });
            unset($ctx['price']);
            return $result;
        }
    }

    // No product (or items are not made): the QuickBooks item with the line's name
    if (isset($ctx['items_by_name'][$line_name])) {
        return [$ctx['items_by_name'][$line_name], ''];
    }
    // Its category's own item
    if ($category_id && $a['create_items']) {
        $mapped = mitQboCategoryAccounts()[$category_id] ?? null;
        // Its item, while QuickBooks still has it active (deleted or made inactive there: made / made active again below)
        if ($mapped && $mapped['qbo_item_id'] !== '' && isset($ctx['active_ids'][$mapped['qbo_item_id']])) {
            return [$mapped['qbo_item_id'], ''];
        }
        $account = mitQboAccountFor($category_id);
        if ($account) {
            $category_name = $income[$category_id];
            return $make("category:$category_id", $category_name . MIT_QBO_ITEM_SUFFIX, 'Service', $account, 'Lines in ' . $category_name,
                function ($id, $qbo_name) use ($category_id, $account) {
                    global $mysqli;
                    mysqli_query($mysqli, "INSERT INTO mit_qbo_category_accounts SET category_id = $category_id, qbo_account_id = '', qbo_account_name = '',
                        qbo_item_id = '" . escapeSql($id) . "', qbo_item_name = '" . escapeSql($qbo_name) . "'
                        ON DUPLICATE KEY UPDATE qbo_item_id = '" . escapeSql($id) . "', qbo_item_name = '" . escapeSql($qbo_name) . "'");
                    mitQboCategoryAccounts(true);
                });
        }
    }
    if ($s['fallback_item'] !== '') {
        return [$s['fallback_item'], ''];
    }
    // Nothing else: "Sales (ITFlow)" on the default account
    if ($a['create_items'] && $a['default_account'] !== '') {
        if ($a['default_item'] !== '' && isset($ctx['active_ids'][$a['default_item']])) {
            return [$a['default_item'], ''];
        }
        return $make('default', 'Sales' . MIT_QBO_ITEM_SUFFIX, 'Service', [$a['default_account'], $a['default_account_name']], 'Lines with no category',
            function ($id) {
                mitSetSetting('qbo_default_item', $id);
            });
    }
    return ['', '"' . $line['item_name'] . '" has no QuickBooks item - map its product, give it a category with an income account, or set a fallback item.'];
}

/** The context mitQboLineItem works in, for one run (a payload, a send, a plan). */
function mitQboLineContext(array $items, $create) {
    $by_name = [];
    foreach ($items as $item) {
        $by_name[mb_strtolower(trim($item['name']))] = $item['id'];
        if (($item['short'] ?? '') !== '') {
            $by_name[mb_strtolower(trim($item['short']))] = $by_name[mb_strtolower(trim($item['short']))] ?? $item['id'];
        }
    }
    return ['items_by_name' => $by_name, 'active_ids' => array_fill_keys(array_map('strval', array_keys($items)), true), 'item_map' => mitQboMap('items'), 'products' => [], 'settings' => mitQboSettings(), 'accounts' => mitQboAccountSettings(),
        'create' => (bool) $create, 'plan' => [], 'made' => []];
}

/** Product rows for the lines (name, price, type) - one query. */
function mitQboLineProducts(array $lines) {
    global $mysqli;
    $ids = array_unique(array_filter(array_map('intval', array_column($lines, 'item_product_id'))));
    $rows = [];
    if ($ids) {
        $result = mysqli_query($mysqli, "SELECT product_id, product_name, product_price, product_type FROM products WHERE product_id IN (" . implode(',', $ids) . ")");
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[intval($row['product_id'])] = $row;
        }
    }
    return $rows;
}

/**
 * The QuickBooks items sending a batch would make: key => [name, type, account, for, invoices]. Reads QuickBooks' items once.
 * Only for entries still to send.
 */
function mitQboBatchItemPlan($batch_id) {
    global $mysqli;
    if (!mitQboAccountsReady() || !mitQboAccountSettings()['create_items']) {
        return [];
    }
    $ctx = mitQboLineContext(mitQboList('items', true), false); // fresh: items made since this request started count
    $plan = [];
    $result = mysqli_query($mysqli, "SELECT e.invoice_id, i.* FROM mit_qbo_batch_invoices e JOIN invoices i ON i.invoice_id = e.invoice_id
        WHERE e.batch_id = " . intval($batch_id) . " AND e.entry_status IN ('queued', 'failed') AND COALESCE(e.qbo_invoice_id, '') = ''" . clientScopeSql('i.invoice_client_id'));
    while ($invoice = mysqli_fetch_assoc($result)) {
        $lines = mysqli_fetch_all(mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_invoice_id = " . intval($invoice['invoice_id']) . " AND item_archived_at IS NULL"), MYSQLI_ASSOC);
        $ctx['products'] = mitQboLineProducts($lines);
        $categories = mitQboLineCategories($invoice, $lines);
        foreach ($lines as $line) {
            $ctx['plan'] = [];
            mitQboLineItem($line, $categories[intval($line['item_id'])][0] ?? 0, $ctx);
            foreach ($ctx['plan'] as $key => $row) {
                $plan[$key] = $plan[$key] ?? $row + ['invoices' => []];
                $plan[$key]['invoices'][$invoice['invoice_prefix'] . $invoice['invoice_number']] = true;
            }
        }
    }
    foreach ($plan as &$row) {
        $row['invoices'] = array_keys($row['invoices']);
    }
    return $plan;
}

/** QuickBooks' income accounts (Income and Other Income), active ones: id => [id, name, type]. */
function mitQboIncomeAccounts() {
    static $list = null;
    if ($list === null) {
        $list = [];
        foreach (mitQboQueryAll('Account', 'Active = true') as $row) {
            if (in_array($row['AccountType'] ?? '', ['Income', 'Other Income'], true)) {
                $list[(string) $row['Id']] = ['id' => (string) $row['Id'], 'name' => (string) ($row['FullyQualifiedName'] ?? $row['Name'] ?? ''), 'type' => (string) $row['AccountType']];
            }
        }
        uasort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    }
    return $list;
}

/** Save the Income accounts tab: default account, ticket-time category, make items, each category's account. Returns warnings. */
function mitQboAccountsSave(array $post) {
    $accounts = mitQboIncomeAccounts();
    $name = function ($id) use ($accounts) {
        return $accounts[$id]['name'] ?? '';
    };
    $warnings = [];
    $default = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($post['qbo_default_account'] ?? ''));
    if ($default !== mitQboAccountSettings()['default_account']) {
        mitSetSetting('qbo_default_account', $default);
        mitSetSetting('qbo_default_account_name', $name($default));
        $item = mitQboAccountSettings()['default_item'];
        if ($item !== '' && $default !== '') {
            try {
                mitQboItemSetAccount($item, $default);
            } catch (Throwable $e) {
                $warnings[] = 'Sales' . MIT_QBO_ITEM_SUFFIX . ' kept its old account: ' . $e->getMessage();
            }
        }
    }
    $time = intval($post['qbo_time_category'] ?? 0);
    mitSetSetting('qbo_time_category', isset(mitQboIncomeCategories()[$time]) ? (string) $time : '0');
    mitSetSetting('qbo_create_items', empty($post['qbo_create_items']) ? '0' : '1');
    foreach ((array) ($post['account'] ?? []) as $category_id => $account_id) {
        if (!isset(mitQboIncomeCategories(true)[intval($category_id)])) {
            continue;
        }
        $account_id = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $account_id);
        $warning = mitQboSetCategoryAccount($category_id, $account_id, $name($account_id));
        if ($warning !== '') {
            $warnings[] = $warning;
        }
    }
    return $warnings;
}
