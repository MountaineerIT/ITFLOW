<?php
/*
 * QuickBooks accounts for nonprofit discounts (functions/mit_qbo_accounts.php, 26.11.8.5 - the user's request): the account pickers on Admin >
 * Integrations > QuickBooks Online > Income accounts offer cost of goods sold and expense accounts after the income ones (a discount or a
 * pass-through cost posts against an expense account), never bank / asset / liability / equity ones; the invoice's discount line carries the
 * "Discounts go to" account (DiscountAccountRef) when one is picked, else none (QuickBooks' own default). And the way the user does it: a
 * "Nonprofit discount" product with a negative price in its own Income category - ITFlow keeps the negative price, the line on an invoice
 * takes the product's category, and the category posts to the expense account mapped to it. No QuickBooks stand-in needed.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitQboLineAccountsFrom') || !function_exists('mitQboDiscountLine')) {
    echo "  skip - QuickBooks accounts not loaded\n";
    exit(0);
}
$rows = [
    ['Id' => '1', 'Name' => 'Services', 'AccountType' => 'Income'],
    ['Id' => '2', 'Name' => 'Bank Checking', 'AccountType' => 'Bank'],
    ['Id' => '3', 'Name' => 'Discounts given', 'AccountType' => 'Expense'],
    ['Id' => '4', 'Name' => 'Accounts Receivable (A/R)', 'AccountType' => 'Accounts Receivable'],
    ['Id' => '5', 'Name' => 'Cost of Goods Sold', 'AccountType' => 'Cost of Goods Sold'],
    ['Id' => '6', 'Name' => 'Hardware Sales', 'AccountType' => 'Income'],
    ['Id' => '7', 'Name' => 'Interest earned', 'AccountType' => 'Other Income'],
    ['Id' => '8', 'Name' => 'Opening Balance Equity', 'AccountType' => 'Equity'],
    ['Id' => '9', 'Name' => 'Bank charges', 'AccountType' => 'Other Expense'],
    ['Id' => '10', 'Name' => 'Old expense', 'AccountType' => 'Expense', 'Active' => false],
    ['Id' => '11', 'Name' => 'Advertising', 'AccountType' => 'Expense'],
];
$list = mitQboLineAccountsFrom($rows);
scenarioCheck('income, other income, cost of goods sold and expense accounts offered; bank / A/R / equity / inactive not', array_map('strval', array_keys($list)), ['6', '1', '7', '5', '11', '3', '9']);
scenarioCheck('each keeps its type for the picker\'s groups', $list['3']['type'], 'Expense');
scenarioCheck('every type has a group label', count(array_diff(array_unique(array_column($list, 'type')), array_keys(MIT_QBO_LINE_ACCOUNT_TYPES))), 0);

$saved = [mitSetting('qbo_discount_account', null), mitSetting('qbo_discount_account_name', null)];
try {
    mitDeleteSetting('qbo_discount_account');
    $line = mitQboDiscountLine(125.5);
    scenarioCheck('no discount account picked: QuickBooks\' own default (no DiscountAccountRef)', [$line['DetailType'], $line['Amount'], isset($line['DiscountLineDetail']['DiscountAccountRef'])], ['DiscountLineDetail', 125.5, false]);
    mitSetSetting('qbo_discount_account', '3');
    scenarioCheck('a discount account picked: the line posts to it', mitQboDiscountLine(40)['DiscountLineDetail']['DiscountAccountRef'] ?? null, ['value' => '3']);
    scenarioCheck('... and stays an amount off, not a percentage', mitQboDiscountLine(40)['DiscountLineDetail']['PercentBased'], false);
} finally {
    $saved[0] === null ? mitDeleteSetting('qbo_discount_account') : mitSetSetting('qbo_discount_account', $saved[0]);
    $saved[1] === null ? mitDeleteSetting('qbo_discount_account_name') : mitSetSetting('qbo_discount_account_name', $saved[1]);
}

// A "Nonprofit discount" product, negative price, in its own category mapped to the expense account
if (function_exists('mitQboAccountsReady') && mitQboAccountsReady()) {
    $stamp = date('His') . rand(10, 99);
    [$client_id] = scenarioMakeClient("ZZ Nonprofit $stamp");
    mysqli_query($mysqli, "INSERT INTO categories SET category_name = 'ZZ Nonprofit discount $stamp', category_type = 'Income', category_color = '#999999'");
    $category_id = mysqli_insert_id($mysqli);
    $product_id = productSave(['name' => "ZZ Nonprofit discount $stamp", 'description' => '20% off for nonprofits', 'price' => '-50', 'category' => $category_id, 'type' => 'service'], 0, $error);
    $invoice_id = 0;
    try {
        scenarioCheck('ITFlow keeps a product with a negative price' . ($error ? " ($error)" : ''), (float) scenarioValue("SELECT product_price FROM products WHERE product_id = " . intval($product_id)), -50.0);
        mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'ZZN-', invoice_number = 990001, invoice_scope = 'scenario', invoice_date = CURDATE(), invoice_due = CURDATE(),
            invoice_amount = 150, invoice_currency_code = 'USD', invoice_status = 'Draft', invoice_url_key = 'scenario', invoice_client_id = $client_id, invoice_category_id = 0");
        $invoice_id = mysqli_insert_id($mysqli);
        mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = 'Managed services', item_quantity = 1, item_price = 200, item_subtotal = 200, item_total = 200, item_invoice_id = $invoice_id, item_order = 1");
        mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = 'ZZ Nonprofit discount $stamp', item_quantity = 1, item_price = -50, item_subtotal = -50, item_total = -50, item_invoice_id = $invoice_id, item_product_id = " . intval($product_id) . ", item_order = 2");
        $line_id = mysqli_insert_id($mysqli);
        $invoice = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_id = $invoice_id"));
        $lines = mysqli_fetch_all(mysqli_query($mysqli, "SELECT * FROM invoice_items WHERE item_invoice_id = $invoice_id ORDER BY item_order"), MYSQLI_ASSOC);
        scenarioCheck('the discount line takes its product\'s category', mitQboLineCategories($invoice, $lines)[$line_id] ?? null, [$category_id, 'product']);
        mysqli_query($mysqli, "INSERT INTO mit_qbo_category_accounts SET category_id = $category_id, qbo_account_id = '3', qbo_account_name = 'Discounts given'");
        mitQboCategoryAccounts(true);
        scenarioCheck('... and the category posts to the expense account mapped to it', mitQboAccountFor($category_id), ['3', 'Discounts given']);
    } finally {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_category_accounts WHERE category_id = $category_id");
        if ($invoice_id) {
            mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = $invoice_id");
            mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = $invoice_id");
        }
        mysqli_query($mysqli, "DELETE FROM products WHERE product_id = " . intval($product_id));
        mysqli_query($mysqli, "DELETE FROM categories WHERE category_id = $category_id");
        scenarioCleanup();
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
