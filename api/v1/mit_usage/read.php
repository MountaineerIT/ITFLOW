<?php

/*
 * API - Usage lines - Read (Mountaineer IT, functions/mit_usage.php)
 * GET /api/v1/mit_usage/read.php
 *
 * Lists the usage lines of the clients the key can see - which usage names (metric) to report counts for, with the latest count.
 *
 * Parameters (GET):
 *   api_key / X-API-Key  required - Your API key
 *   client_id            optional - Only this client
 *   source               optional - Only lines counted this way: manual | api | tag
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

if (!function_exists('mitUsageReady') || !mitUsageReady()) {
    $return_arr['success'] = "False";
    $return_arr['message'] = "Usage billing needs the database update.";
    echo json_encode($return_arr);
    exit();
}

$source_sql = in_array($_GET['source'] ?? '', ['manual', 'api', 'tag'], true) ? " AND l.line_source = '" . $_GET['source'] . "'" : '';

$sql = mysqli_query($mysqli,
    "SELECT recurring_invoice_client_id AS client_id, client_name, l.line_metric AS metric, l.line_source AS source, item_name, l.recurring_invoice_id,
        (SELECT quantity FROM mit_usage_counts c WHERE c.client_id = recurring_invoice_client_id AND c.metric = l.line_metric ORDER BY as_of DESC, count_id DESC LIMIT 1) AS latest_quantity,
        (SELECT as_of FROM mit_usage_counts c WHERE c.client_id = recurring_invoice_client_id AND c.metric = l.line_metric ORDER BY as_of DESC, count_id DESC LIMIT 1) AS latest_as_of
     FROM mit_usage_lines l
     JOIN recurring_invoice_items ON recurring_invoice_items.item_id = l.recurring_item_id
     JOIN recurring_invoices ON recurring_invoices.recurring_invoice_id = l.recurring_invoice_id
     JOIN clients ON clients.client_id = recurring_invoice_client_id
     WHERE recurring_invoice_archived_at IS NULL$source_sql " . apiClientScopeSql('recurring_invoice_client_id') . "
     ORDER BY client_name, item_name
     LIMIT $limit OFFSET $offset"
);

require_once "../read_output.php";
