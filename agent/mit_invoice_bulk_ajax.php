<?php
/*
 * Mountaineer IT - the invoices ticked for a bulk action, kept in the session (functions/mit_invoice_bulk.php mitInvoiceBulkRemember), JSON:
 *   POST csrf_token ids=<comma-separated invoice ids>  -> {token}
 * The window (agent/modals/mit_invoice/bulk.php?set=<token>) and its form carry the token instead of every id.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !function_exists('mitInvoiceBulkRemember') || lookupUserPermission('module_sales') < 2) {
    http_response_code(403);
    exit(json_encode(['error' => 'Not available.']));
}
validateCSRFToken();
$ids = array_filter(array_map('intval', explode(',', (string) ($_POST['ids'] ?? ''))));
if (!$ids) {
    http_response_code(422);
    exit(json_encode(['error' => 'No invoices picked.']));
}
echo json_encode(['token' => mitInvoiceBulkRemember($ids)]);
