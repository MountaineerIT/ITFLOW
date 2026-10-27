<?php

/*
 * API - Usage counts - Create (Mountaineer IT, functions/mit_usage.php)
 * POST /api/v1/mit_usage/create.php
 *
 * Reports a usage count for a client - how many workstations, Bitwarden users, Microsoft 365 licences ... it has. Recurring
 * invoice lines billed from this usage name take the count on or before the snapshot day when the invoice is made.
 *
 * Parameters (POST, JSON body):
 *   api_key / X-API-Key  required - Your API key (its user needs Sales edit and access to the client)
 *   client_id            required - The client
 *   metric               required - The usage name, as set on the recurring invoice's Usage billing card (e.g. "workstations")
 *   quantity             required - The count (0 or more)
 *   as_of                optional - The date counted, YYYY-MM-DD (default today)
 *   note                 optional - Up to 255 characters kept with the count
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

if (!function_exists('mitUsageReady') || !mitUsageReady()) {
    $return_arr['success'] = "False";
    $return_arr['message'] = "Usage billing needs the database update.";
    echo json_encode($return_arr);
    exit();
}

[$insert_id, $why] = mitUsageRecordCount($client_id, (string) ($_POST['metric'] ?? ''), $_POST['quantity'] ?? '', (string) ($_POST['as_of'] ?? ''), 'api',
    mb_substr('API key ' . stripslashes((string) $api_key_name) . (trim((string) ($_POST['note'] ?? '')) !== '' ? ': ' . trim((string) $_POST['note']) : ''), 0, 255));

if ($why !== '') {
    header("HTTP/1.1 400 Bad Request");
    $return_arr['success'] = "False";
    $return_arr['message'] = $why;
    echo json_encode($return_arr);
    exit();
}

require_once '../create_output.php';
