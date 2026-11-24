<?php

/*
 * API - Ticket parts - Read (Mountaineer IT, functions/mit_procurement_parts.php - the ticket page's Parts box)
 * GET /api/v1/mit_ticket_parts/read.php
 *
 * Parts used on tickets - from stock or to order - with where each one is: waiting to order, on a purchase order, received, billed
 * (invoice). Our cost of a part never goes out through the API. Needs Support view; only clients the key can reach.
 *
 * Parameters (GET):
 *   ticket_id  optional - Only this ticket's parts
 *   client_id  optional - Only this client's
 *   unbilled   optional - 1 = only parts not billed yet (what To Bill lists)
 */

require_once '../validate_api_key.php';

require_once '../require_get_method.php';

mitApiPartsRequire();

$where = ['1 = 1'];
if (isset($_GET['ticket_id'])) {
    $where[] = 'parts.ticket_id = ' . intval(mitApiTicket($_GET['ticket_id'])['ticket_id']);
}
if (!empty($_GET['unbilled'])) {
    $where[] = '(parts.invoice_id = 0 OR invoices.invoice_id IS NULL) AND ' . MIT_PART_BILLABLE_SQL;
}
$rows = [];
foreach (mitTicketPartsWhere(implode(' AND ', $where) . apiClientScopeSql(MIT_PART_CLIENT_SQL), 'parts.ticket_id, parts.part_id') as $part) {
    $rows[] = mitApiPart($part);
}
mitApiList($rows);
