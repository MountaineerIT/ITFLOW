<?php
/*
 * QuickBooks' invoice email (mitQboClientEmails, 26.11.8.5): a client's billing contacts (ITFlow's Billing tick) only; a client with
 * no billing contact with a good address gets it at the primary contact. ITFlow's own invoice emails are left as they are (primary +
 * billing). No QuickBooks stand-in needed - this only reads contacts.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitQboClientEmails')) {
    echo "  skip - QuickBooks not loaded\n";
    exit(0);
}
$stamp = date('His') . rand(10, 99);
[$client_id, $primary_id] = scenarioMakeClient("ZZ QBO billing email $stamp");
$primary = scenarioValue("SELECT contact_email FROM contacts WHERE contact_id = $primary_id");
$add = function ($name, $email, $billing) use ($mysqli, $client_id) {
    mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = '$name', contact_email = '$email', contact_billing = $billing, contact_client_id = $client_id");
    return mysqli_insert_id($mysqli);
};
try {
    scenarioCheck('no billing contact: the primary contact', mitQboClientEmails($client_id), [$primary]);
    $add('ZZ Billing bad address', 'not-an-address', 1);
    scenarioCheck('a billing contact without a good address: still the primary', mitQboClientEmails($client_id), [$primary]);
    $ap = "zz-ap-$stamp@example.invalid";
    $add('ZZ Accounts payable', $ap, 1);
    $add('ZZ Someone else', "zz-other-$stamp@example.invalid", 0);
    scenarioCheck('a billing contact: only them, not the primary', mitQboClientEmails($client_id), [$ap]);
    $ap2 = "zz-ap2-$stamp@example.invalid";
    $add('ZZ Accounts payable 2', $ap2, 1);
    scenarioCheck('two billing contacts: both', count(array_intersect(mitQboClientEmails($client_id), [$ap, $ap2])), 2);
    scenarioCheck('... still not the primary', in_array($primary, mitQboClientEmails($client_id), true), false);
    mysqli_query($mysqli, "UPDATE contacts SET contact_archived_at = NOW() WHERE contact_client_id = $client_id AND contact_billing = 1");
    scenarioCheck('billing contacts archived: back to the primary', mitQboClientEmails($client_id), [$primary]);
    mysqli_query($mysqli, "UPDATE contacts SET contact_archived_at = NULL WHERE contact_client_id = $client_id");
    $itflow = array_column(billingEmailRecipients($client_id), 'email');
    scenarioCheck('ITFlow\'s own invoice email is unchanged (primary and billing)', in_array($primary, $itflow, true) && in_array($ap, $itflow, true), true);
} finally {
    mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_client_id = $client_id");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
