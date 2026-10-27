<?php

/*
 * Mountaineer IT - Intuit sends the admin back here after connecting a QuickBooks Online company (Admin > Integrations >
 * QuickBooks Online > Connect, functions/mit_qbo.php). Add this address to the Intuit app's Redirect URIs.
 */

require_once "../config.php";
require_once "../functions.php";
require_once "../includes/check_login.php";

if (empty($session_is_admin)) {
    flashAlert("Admin access required.", 'error');
    redirect('/agent/dashboard.php');
}

$state = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['mit_qbo_state'] ?? '');
$fresh = time() <= intval($_SESSION['mit_qbo_state_expires'] ?? 0);
unset($_SESSION['mit_qbo_state'], $_SESSION['mit_qbo_state_expires']);

if ($expected === '' || !$fresh || !hash_equals($expected, $state)) {
    flashAlert("That QuickBooks sign-in had expired or did not start here - click Connect again.", 'error');
    redirect('mit_qbo.php');
}

if (!empty($_GET['error'])) {
    flashAlert("Intuit did not connect QuickBooks: " . escapeHtml($_GET['error_description'] ?? $_GET['error']), 'error');
    redirect('mit_qbo.php');
}

try {
    $company = mitQboConnect((string) ($_GET['code'] ?? ''), (string) ($_GET['realmId'] ?? ''));
} catch (Throwable $e) {
    flashAlert("Could not connect QuickBooks Online: " . escapeHtml($e->getMessage()), 'error');
    redirect('mit_qbo.php');
}
logAudit("Settings", "Edit", escapeSql("$session_name connected QuickBooks Online ($company)"));
flashAlert("QuickBooks Online connected: " . escapeHtml($company) . ". Map the customers, items and tax codes next."
    . (!empty($GLOBALS['mit_qbo_mappings_cleared']) ? ' (A different company than before - the old mappings were cleared.)' : ''));
redirect('mit_qbo.php?tab=customers');
