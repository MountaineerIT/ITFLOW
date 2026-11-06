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
 *   source               optional - Only lines counted this way: usage (ITFlow's "Counted by an integration") | manual | api | tag (old card)
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

if (!function_exists('mitUsageReady') || !mitUsageReady()) {
    $return_arr['success'] = "False";
    $return_arr['message'] = "Usage billing needs the database update.";
    echo json_encode($return_arr);
    exit();
}

$source = in_array($_GET['source'] ?? '', ['manual', 'api', 'tag', 'usage'], true) ? $_GET['source'] : '';
$scope_sql = apiClientScopeSql('recurring_invoice_client_id');

// Lines counted by an integration in the line's own "Quantity from" (source usage), and any left from the old Usage billing card
$sql = mysqli_query($mysqli,
    "SELECT client_id, client_name, metric, source, item_name, recurring_invoice_id,
        (SELECT quantity FROM mit_usage_counts c WHERE c.client_id = usage_lines.client_id AND c.metric = usage_lines.metric ORDER BY as_of DESC, count_id DESC LIMIT 1) AS latest_quantity,
        (SELECT as_of FROM mit_usage_counts c WHERE c.client_id = usage_lines.client_id AND c.metric = usage_lines.metric ORDER BY as_of DESC, count_id DESC LIMIT 1) AS latest_as_of
     FROM (
        SELECT recurring_invoice_client_id AS client_id, l.line_metric AS metric, l.line_source AS source, item_name, l.recurring_invoice_id
        FROM mit_usage_lines l
        JOIN recurring_invoice_items ON recurring_invoice_items.item_id = l.recurring_item_id
        JOIN recurring_invoices ON recurring_invoices.recurring_invoice_id = l.recurring_invoice_id
        WHERE recurring_invoice_archived_at IS NULL $scope_sql
        UNION ALL
        SELECT recurring_invoice_client_id, JSON_VALUE(item_seat_filter, '$.metric'), 'usage', item_name, item_recurring_invoice_id
        FROM recurring_invoice_items
        JOIN recurring_invoices ON recurring_invoices.recurring_invoice_id = item_recurring_invoice_id
        WHERE item_seat_source = 'usage' AND recurring_invoice_archived_at IS NULL $scope_sql
     ) usage_lines
     JOIN clients USING (client_id)
     WHERE 1 = 1" . ($source !== '' ? " AND source = '$source'" : '') . "
     ORDER BY client_name, item_name
     LIMIT $limit OFFSET $offset"
);

require_once "../read_output.php";
