<?php

require_once '../validate_api_key.php';

require_once '../require_post_method.php';

// Default
$delete_count = false;

// Mountaineer IT: not while its invoices are in QuickBooks batches (the delete then fails as not found)
if (!empty($client_id) && function_exists('mitQboClientLock') && mitQboClientLock($client_id) !== '') {
    $client_id = 0;
}
if (!empty($client_id) && function_exists('mitTsDeleteBlocked') && mitTsDeleteBlocked(0, $client_id) !== '') { $client_id = 0; } // Mountaineer IT: its ticket time is on a submitted timesheet

if (!empty($client_id)) {
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name FROM clients WHERE client_id = $client_id AND client_archived_at IS NOT NULL LIMIT 1"));
    $client_name = escapeSql($row['client_name'] ?? '');

    // Their payments and other income go with them - not once any has cleared on a reconciled statement
    if (!empty($client_name) && reconcileClientLock($client_id)) {
        echo json_encode(['success' => 'False', 'message' => 'Money from this client cleared on a reconciled bank statement - keep the client archived, or undo that reconciliation in ITFlow first.']);
        exit();
    }

    if (!empty($client_name)) {
        // Everything that was only theirs, as Clients > Delete does (functions/client.php)
        $delete_count = clientDeleteForGood($client_id);

        if ($delete_count) {

            // Logging
            logAudit("Client", "Delete", "$client_name and all associated data via API ($api_key_name)");
        }
    }
}

// Output
require_once '../delete_output.php';