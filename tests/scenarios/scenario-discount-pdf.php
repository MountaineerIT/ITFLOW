<?php
/*
 * The percentage discount's label in the PDFs (mitDiscountLabel - two lines in ITFlow's functions/billing_pdf.php billingPdfRender): an invoice's
 * totals and a quote's per-frequency totals say "Discount (20%)", read back from the PDF with pdftotext. Written after the hook proof (Nov 23) found
 * nothing looked at the PDFs.
 */
require __DIR__ . '/bootstrap.php';
if (is_file($root . '/functions/billing_pdf.php')) {
    require_once $root . '/libs/TCPDF/tcpdf.php';         // ITFlow loads both only where a PDF is made
    require_once $root . '/functions/billing_pdf.php';
}
if (!function_exists('mitDiscountLabel') || !function_exists('billingPdfRender') || !mitDiscountReady()) {
    echo "  skip - no percentage discounts here\n";
    exit(0);
}
if (trim((string) shell_exec('command -v pdftotext')) === '') {
    echo "  skip - pdftotext is not installed\n";
    exit(0);
}
$client = scenarioClient();
$made = ['invoice' => 0, 'quote' => 0];
$text = function ($type, $id) {
    $pdf = billingPdfRender($type, billingRow($type, $id, false));
    $file = tempnam(sys_get_temp_dir(), 'zzpdf');
    file_put_contents($file, $pdf['data']);
    $out = (string) shell_exec('pdftotext -layout ' . escapeshellarg($file) . ' - 2>/dev/null');
    @unlink($file);
    return $out;
};
try {
    $n = intval(scenarioValue("SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices"));
    mysqli_query($mysqli, "INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = $n, invoice_scope = 'ZZ pdf discount', invoice_date = CURDATE(), invoice_due = CURDATE(),
        invoice_amount = 0, invoice_currency_code = 'USD', invoice_status = 'Draft', invoice_url_key = 'zzpdf', invoice_client_id = $client, invoice_category_id = 0");
    $made['invoice'] = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO invoice_items SET item_name = 'ZZ pdf line', item_description = '', item_quantity = 1, item_price = 100, item_subtotal = 100, item_tax = 0,
        item_total = 100, item_order = 1, item_tax_id = 0, item_product_id = 0, item_invoice_id = {$made['invoice']}");
    mysqli_query($mysqli, "INSERT INTO mit_billing_discounts SET doc_type = 'invoice', doc_id = {$made['invoice']}, percent = 20, product_id = 0");
    billingRecalc('invoice', $made['invoice']);
    scenarioCheck('the invoice PDF says "Discount (20%)"', strpos($text('invoice', $made['invoice']), 'Discount (20%)') !== false, true);

    $q = intval(scenarioValue("SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes"));
    mysqli_query($mysqli, "INSERT INTO quotes SET quote_prefix = 'QUO-', quote_number = $q, quote_scope = 'ZZ pdf discount', quote_date = CURDATE(), quote_expire = CURDATE(),
        quote_amount = 0, quote_currency_code = 'USD', quote_status = 'Draft', quote_url_key = 'zzpdf', quote_client_id = $client, quote_category_id = 0");
    $made['quote'] = mysqli_insert_id($mysqli);
    foreach ([['ZZ pdf setup', ''], ['ZZ pdf monthly', 'month']] as $i => [$name, $frequency]) {
        mysqli_query($mysqli, "INSERT INTO quote_items SET item_name = '$name', item_description = '', item_quantity = 1, item_price = 100, item_subtotal = 100, item_tax = 0,
            item_total = 100, item_order = " . ($i + 1) . ", item_tax_id = 0, item_product_id = 0, item_frequency = '$frequency', item_quote_id = {$made['quote']}");
    }
    mysqli_query($mysqli, "INSERT INTO mit_billing_discounts SET doc_type = 'quote', doc_id = {$made['quote']}, percent = 20, product_id = 0");
    billingRecalc('quote', $made['quote']);
    $quote_text = $text('quote', $made['quote']);
    scenarioCheck('the quote PDF, totals by how often they are billed, says "Discount (20%)"', strpos($quote_text, 'Discount (20%)') !== false, true);
} finally {
    if ($made['invoice']) {
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = 'invoice' AND doc_id = {$made['invoice']}");
        mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = {$made['invoice']}");
        mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = {$made['invoice']}");
        mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = {$made['invoice']}");
    }
    if ($made['quote']) {
        mysqli_query($mysqli, "DELETE FROM mit_billing_discounts WHERE doc_type = 'quote' AND doc_id = {$made['quote']}");
        mysqli_query($mysqli, "DELETE FROM quote_items WHERE item_quote_id = {$made['quote']}");
        mysqli_query($mysqli, "DELETE FROM history WHERE history_quote_id = {$made['quote']}");
        mysqli_query($mysqli, "DELETE FROM quotes WHERE quote_id = {$made['quote']}");
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
