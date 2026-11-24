<?php

/*
 * API - Ticket parts - Update (Mountaineer IT, functions/mit_procurement_parts.php mitTicketPartSave - the Parts box's Change)
 * POST /api/v1/mit_ticket_parts/update.php
 *
 * Changes a part not billed yet, on an open ticket. Send the whole part (the same fields as create.php) with its part_id - a field
 * left out is read as empty, as the page's form would send it. Stock follows (only the difference moves); a part already on a
 * purchase order can't change its quantity here. Needs Support edit and access to the ticket's client.
 *
 * Parameters (POST, JSON body): part_id (required), then as create.php.
 */

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

$part = mitApiPartRow($_POST['part_id'] ?? 0);
$ticket = mitApiPartsTicket($part['ticket_id']);
[$ok, $message] = mitTicketPartSave($ticket, $_POST, intval($part['part_id']));
if (!$ok) {
    mitApiFail(400, $message);
}
mitApiDone($message, mitApiPart(mitTicketParts($ticket['ticket_id'])[intval($part['part_id'])] ?? $part));
