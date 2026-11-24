<?php

/*
 * API - Ticket parts - Create (Mountaineer IT, functions/mit_procurement_parts.php mitTicketPartSave - the Parts box's Add part)
 * POST /api/v1/mit_ticket_parts/create.php
 *
 * Adds a part to an open ticket. From stock, it comes off the product's stock at once; to order, it goes on Purchasing > To Order.
 * An internal note and the ticket's history say so. Needs Support edit and access to the ticket's client.
 *
 * Parameters (POST, JSON body):
 *   ticket_id    required - The ticket
 *   name         required - What it is (up to 200 characters)
 *   quantity     required - How many (more than 0)
 *   source       optional - stock (default) | order | other (already on hand - no stock taken, nothing ordered)
 *   product_id   optional - The product it is (stock is taken from it)
 *   unit_price   optional - What the client is charged for one (default 0)
 *   unit_cost    optional - What one costs us (only kept when the key's user may see costs; never read back)
 *   description  optional
 *   tax_id       optional
 *   asset_id     optional - The client's asset it is for
 *   vendor_id    optional - Where to order it from (to order)
 *   client_id    optional - The ticket's client (needed when the key's user is limited to certain clients)
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

$ticket = mitApiPartsTicket($_POST['ticket_id'] ?? 0);
[$ok, $message, $part_id] = array_pad(mitTicketPartSave($ticket, $_POST), 3, 0);
if (!$ok) {
    mitApiFail(400, $message);
}
mitApiDone($message, mitApiPart(mitTicketParts($ticket['ticket_id'])[intval($part_id)] ?? ['part_id' => $part_id]));
