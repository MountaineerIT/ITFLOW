<?php

/*
 * API - Ticket parts - Remove (Mountaineer IT, functions/mit_procurement_parts.php mitTicketPartRemove - the Parts box's Remove)
 * POST /api/v1/mit_ticket_parts/remove.php
 *
 * Takes a part not billed yet off a ticket: a part from stock goes back on the shelf, one still waiting to order comes off To Order;
 * one already on a purchase order must come off the PO first. Needs Support edit (as the Parts box - so it is remove.php, not
 * delete.php, which ITFlow's API holds to Full) and access to the ticket's client.
 *
 * Parameters (POST, JSON body):
 *   part_id    required - The part
 *   client_id  optional - The ticket's client (needed when the key's user is limited to certain clients)
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

$part = mitApiPartRow($_POST['part_id'] ?? 0);
$ticket = mitApiPartsTicket($part['ticket_id']);
[$ok, $message] = mitTicketPartRemove($ticket, intval($part['part_id']));
if (!$ok) {
    mitApiFail(409, $message);
}
mitApiDone($message, ['part_id' => intval($part['part_id']), 'ticket_id' => intval($ticket['ticket_id'])]);
