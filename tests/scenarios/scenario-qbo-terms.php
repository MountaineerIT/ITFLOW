<?php
/*
 * QuickBooks Online invoice terms (functions/mit_qbo_terms.php, M052) against the test install's stand-in:
 *  - each invoice goes with the QuickBooks term of its days (due date - invoice date): 0 -> "Due on receipt", 30 -> "Net 30",
 *    45 -> the inactive "Net 45" made active again (not a second one), 20 -> "Net 20" made; ITFlow's due date is sent too;
 *  - a client with its own term ("Due on the 1st", a date-driven one): no DueDate sent, QuickBooks works it out, ITFlow's
 *    invoice takes it (history line) and is not "changed since approval".
 */
require __DIR__ . '/bootstrap.php';
const MOCK = 'http://127.0.0.1/qbo-mock/index.php';
$mock = function ($path) {
    return json_decode((string) @file_get_contents(MOCK . $path), true);
};
if (!defined('MIT_QBO_API_BASE') || !$mock('/_state') || !function_exists('mitQboTermsReady') || !mitQboTermsReady()) {
    echo "  skip - no QuickBooks stand-in on this install, or M052 not run\n";
    exit(0);
}
$q1 = function ($sql) use ($mysqli) {
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
};
$keys = ['qbo_realm_id', 'qbo_refresh_token', 'qbo_access_token', 'qbo_fallback_item', 'qbo_tax_style', 'qbo_payment_account', 'qbo_client_id', 'qbo_client_secret', 'qbo_env'];
$saved = [];
foreach ($keys as $key) {
    $saved[$key] = mitSetting($key);
}
$made = ['invoices' => [], 'batches' => []];
$stamp = date('His');
[$client_id] = scenarioMakeClient("ZZ QBO terms $stamp");
[$client_1st] = scenarioMakeClient("ZZ QBO terms 1st $stamp");
$invoice = function ($client, $days) use ($mysqli, &$made) {
    $id = billingInvoiceCreate($client, date('Y-m-d'), 0, 'ZZ scenario terms', 'Scenario');
    $made['invoices'][] = $id;
    $error = '';
    billingItemSave('invoice', $id, ['name' => 'Services', 'description' => '', 'qty' => '1', 'price' => '100', 'tax_id' => 0], 0, $error);
    mysqli_query($mysqli, "UPDATE invoices SET invoice_currency_code = 'USD', invoice_date = CURDATE(), invoice_due = CURDATE() + INTERVAL " . intval($days) . " DAY WHERE invoice_id = $id");
    billingRecalc('invoice', $id);
    return $id;
};
$send = function ($id) use (&$made) {
    mitInvoiceApprove([$id]);
    $batch = $made['batches'][] = mitQboBatchCreate('ZZ scenario terms', false);
    mitQboBatchAdd($batch, [$id]);
    return mitQboBatchSend($batch);
};
$sent = function ($id) use ($mysqli, $mock) {
    $doc = mitQboDocNumber(mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM invoices WHERE invoice_id = $id")));
    foreach ($mock('/_state')['invoices'] as $qbo) {
        if ($qbo['DocNumber'] === $doc) {
            return $qbo;
        }
    }
    return [];
};
try {
    $mock('/_reset');
    mitSetSetting('qbo_client_id', 'qbo-client');
    mitSetSetting('qbo_client_secret', mitEncryptSecret('qbo-secret'));
    mitSetSetting('qbo_env', 'sandbox');
    scenarioCheck('connected to the stand-in', mitQboConnect('code-scenario', '9130355'), 'Sandbox Company_US_1');
    mitSetSetting('qbo_payment_account', intval($q1("SELECT account_id FROM accounts ORDER BY account_id LIMIT 1")));
    mitSetSetting('qbo_tax_style', 'us');
    mitSetSetting('qbo_fallback_item', '2');

    echo "terms by the invoice's days\n";
    foreach ([[0, 'Due on receipt'], [30, 'Net 30'], [45, 'Net 45'], [20, 'Net 20']] as [$days, $name]) {
        $id = $invoice($client_id, $days);
        [$count, $failed] = $send($id);
        $qbo = $sent($id);
        scenarioCheck("$days days: sent (" . json_encode($failed) . ')', $count, 1);
        scenarioCheck("$days days: terms $name", $qbo['SalesTermRef']['name'] ?? '', $name);
        scenarioCheck("$days days: ITFlow's due date sent", $qbo['DueDate'] ?? '', date('Y-m-d', strtotime("+$days days")));
    }
    $terms = $mock('/_state')['terms'];
    scenarioCheck('the inactive "Net 45" made active again, not a second one', count(array_filter($terms, fn($t) => $t['Name'] === 'Net 45')) . '/' . json_encode(array_values(array_filter($terms, fn($t) => $t['Name'] === 'Net 45'))[0]['Active']), '1/true');
    scenarioCheck('"Net 20" made with 20 days', array_values(array_filter($terms, fn($t) => $t['Name'] === 'Net 20'))[0]['DueDays'] ?? null, 20);

    echo "a client's own term (Due on the 1st)\n";
    mitQboSetClientTerm($client_1st, '4', 'Due on the 1st');
    $id = $invoice($client_1st, 10); // not 30 - on Nov 1 that lands on the 1st too
    [$count, $failed] = $send($id);
    $qbo = $sent($id);
    $first = date('Y-m-01', strtotime('first day of next month'));
    scenarioCheck('sent (' . json_encode($failed) . ')', $count, 1);
    scenarioCheck('terms Due on the 1st, due date worked out by QuickBooks', [$qbo['SalesTermRef']['name'] ?? '', $qbo['DueDate'] ?? ''], ['Due on the 1st', $first]);
    scenarioCheck('ITFlow\'s due date follows', $q1("SELECT invoice_due FROM invoices WHERE invoice_id = $id"), $first);
    scenarioCheck('... with a line in its history', (bool) $q1("SELECT 1 FROM history WHERE history_invoice_id = $id AND history_description LIKE 'Due date set to $first by QuickBooks Online%'"), true);
    scenarioCheck('... and not "changed since approval"', mitInvoiceApprovalState($id), 'approved');
    mitQboSetClientTerm($client_1st, '');
    scenarioCheck('cleared', mitQboClientTerms()[$client_1st] ?? null, null);
} finally {
    foreach ($made['invoices'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batch_invoices WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM mit_invoice_approvals WHERE invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($id));
    }
    foreach ($made['batches'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_batches WHERE batch_id = " . intval($id));
    }
    foreach ($saved as $key => $value) {
        $value === null ? mitDeleteSetting($key) : mitSetSetting($key, $value);
    }
    $mock('/_reset');
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
